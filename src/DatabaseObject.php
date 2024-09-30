<?php
declare(strict_types=1);

namespace AyelaORM;

use DateTime;
use Exception;
use PDO;
use PDOException;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

/**
 * DatabaseObject
 * This class should be inherited by classes intended to be stored in the database.
 * Any class that inherits from it should define database-stored properties prefixed with db_
 */
class DatabaseObject
{
    #[SQLType("INT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY")]
    public int $db_id = 0;

    // Public
    #[SQLIgnore]
    public ?Throwable $last_error = null;

    // Private
    private bool $populated = false;

    public function __construct()
    {
        if (!Database::$frozen) {
            $this->checkSchema();
        }
    }

    public static function register(): void
    {
        if (!Database::$frozen) {
            $class = new static();
            $class->checkSchema();
        }
    }

    private function checkSchema(): void
    {
        $reflection = new ReflectionClass($this);
        $properties = $reflection->getProperties();
        $newSchema = [];

        foreach ($properties as $property) {
            $attributes = $property->getAttributes(SQLIgnore::class);
            if (empty($attributes)) {
                $type = $this->getSQLType($property);
                $columnName = $this->getColumnName($property->getName());
                $newSchema[$columnName] = $type;
            }
        }

        $schemaHash = md5(json_encode($newSchema));
        $tableName = self::cleanName($reflection->getName());

        if (!$this->tableExists($tableName)) {
            $this->createTable($newSchema);
            $this->saveSchemaHash($schemaHash);
        } elseif ($this->isSchemaChanged($schemaHash)) {
            // Get existing schema
            $existingSchema = $this->getExistingTableSchema($tableName);

            // Compare and generate ALTER statements if needed
            $alterStatements = $this->generateAlterTableStatements($tableName, $existingSchema, $newSchema);

            // Execute ALTER statements if there are any
            if (!empty($alterStatements)) {
                foreach ($alterStatements as $sql) {
                    try {
                        Database::getConnection()->exec($sql);
                        error_log("Executed: $sql\n");
                    } catch (PDOException $e) {
                        error_log("Failed to execute: $sql\nError: " . $e->getMessage());
                    }
                }
            }
            $this->saveSchemaHash($schemaHash);
        }
    }

    private function tableExists(string $tableName): bool
    {
        // Check if the table exists in the database
        $stmt = Database::getConnection()->prepare("SHOW TABLES LIKE :table_name");
        $stmt->execute([':table_name' => $tableName]);
        return $stmt->fetch() !== false;
    }

    private function getSQLType(ReflectionProperty $property): string
    {
        // Check if SQLType attribute is present
        $attributes = $property->getAttributes(SQLType::class);
        if (!empty($attributes)) {
            $sqlType = $attributes[0]->newInstance()->type;
        } else {
            // Infer SQL type based on declared PHP type
            $declaredType = $property->getType()?->getName();
            if ($declaredType === null) {
                // Handle untyped properties
                $sqlType = 'TEXT';
            } elseif ($this->isDatabaseObject($declaredType)) {
                // If it's a DatabaseObject, store as foreign key (INT)
                $sqlType = 'INT';
            } else {
                $sqlType = match ($declaredType) {
                    'int' => 'INT',
                    'string' => 'VARCHAR(255)',
                    'float', 'double' => 'FLOAT',
                    'bool' => 'BOOLEAN',
                    DateTime::class => 'DATETIME',
                    default => 'TEXT', // Default to TEXT for other types
                };
            }
        }
        return $sqlType;
    }

    private function isDatabaseObject(?string $className): bool
    {
        if ($className === null) {
            return false;
        }
        if (!class_exists($className)) {
            return false;
        }
        $reflection = new ReflectionClass($className);
        return $reflection->isSubclassOf(DatabaseObject::class);
    }

    private function isScalarType(?string $typeName): bool
    {
        return in_array($typeName, ['int', 'float', 'string', 'bool'], true);
    }

    private function isSerializableObject($value): bool
    {
        return is_object($value) && !$value instanceof DateTime && !$value instanceof DatabaseObject;
    }

    private function isSchemaChanged(string $newHash): bool
    {
        try {
            $reflection = new ReflectionClass($this);
            $stmt = Database::getConnection()->prepare("SELECT schema_hash FROM schema_info WHERE table_name = ?");
            $stmt->execute([self::cleanName($reflection->getName())]);
            $existingHash = $stmt->fetchColumn();

            return $existingHash !== $newHash;
        } catch (PDOException $e) {
            // Schema info table may not exist
            return true;
        }
    }

    private function createTable(array $schema): void
    {
        $tableName = $this->cleanName(static::class);
        $columns = [];

        foreach ($schema as $columnName => $sqlType) {
            $columns[] = "`$columnName` $sqlType";
        }

        $columnDefinitions = implode(', ', $columns);

        $sql = "CREATE TABLE IF NOT EXISTS `$tableName` ($columnDefinitions)";
        Database::getConnection()->exec($sql);
    }

    private function getExistingTableSchema(string $tableName): array
    {
        $stmt = Database::getConnection()->query("DESCRIBE `$tableName`");
        $existingSchema = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $existingSchema[$row['Field']] = $row['Type'];
        }

        return $existingSchema;
    }

    private function generateAlterTableStatements(string $tableName, array $existingSchema, array $newSchema): array
    {
        $alterStatements = [];

        // Add new columns
        foreach ($newSchema as $column => $type) {
            if (!isset($existingSchema[$column])) {
                $alterStatements[] = "ALTER TABLE `$tableName` ADD `$column` $type";
            }
        }

        // Modify existing columns
        foreach ($newSchema as $column => $type) {
            if (isset($existingSchema[$column]) && strtolower($existingSchema[$column]) !== strtolower($type)) {
                $alterStatements[] = "ALTER TABLE `$tableName` MODIFY `$column` $type";
            }
        }

        // Optionally drop columns no longer in the class (commented out)
        /*
        foreach ($existingSchema as $column => $type) {
            if (!isset($newSchema[$column])) {
                $alterStatements[] = "ALTER TABLE `$tableName` DROP COLUMN `$column`";
            }
        }
        */

        return $alterStatements;
    }

    private function saveSchemaHash(string $hash): void
    {
        $tableName = self::cleanName(static::class);

        // Check if the schema_info table exists, create if not
        Database::getConnection()->exec("
            CREATE TABLE IF NOT EXISTS schema_info (
                table_name VARCHAR(255) PRIMARY KEY,
                schema_hash VARCHAR(32)
            )
        ");

        // Insert or update the schema hash
        $stmt = Database::getConnection()->prepare("
            INSERT INTO schema_info (table_name, schema_hash)
            VALUES (:table_name, :schema_hash)
            ON DUPLICATE KEY UPDATE schema_hash = :schema_hash
        ");
        $stmt->execute([
            ':table_name' => $tableName,
            ':schema_hash' => $hash,
        ]);
    }

    /**
     * Save a record to the database.
     */
    public function save(): bool
    {
        $reflection = new ReflectionClass($this);
        $properties = $reflection->getProperties();

        try {
            $table = self::cleanName($reflection->getName());
            $columns = [];
            $values = [];
            $placeholders = [];

            $idValue = null;

            foreach ($properties as $property) {
                $propertyName = $property->getName();
                $attributes = $property->getAttributes(SQLIgnore::class);
                if (count($attributes) == 0) {
                    $columnName = $this->getColumnName($propertyName);
                    $property->setAccessible(true);

                    $value = $property->isInitialized($this) ? $property->getValue($this) : null;

                    $declaredType = $property->getType()?->getName();

                    if ($columnName === 'id') {
                        $idValue = $value;
                        continue;
                    }

                    if ($value instanceof DateTime) {
                        $value = $value->format('Y-m-d H:i:s');
                    } elseif ($this->isDatabaseObject($declaredType) && $value instanceof DatabaseObject) {
                        // Store the db_id of the related object
                        // Ensure the related object is saved
                        if ($value->db_id == 0) {
                            $value->save();
                        }
                        $value = $value->db_id;
                    } elseif ($this->isSerializableObject($value)) {
                        // Serialize other objects
                        $value = json_encode($value);
                    } elseif (is_array($value)) {
                        // Serialize arrays
                        $value = json_encode($value);
                    }

                    $columns[] = "`$columnName`";
                    $values[] = $value;
                    $placeholders[] = "?";
                }
            }

            if (empty($idValue)) {
                // Insert logic
                $sql = sprintf(
                    "INSERT INTO `%s` (%s) VALUES (%s)",
                    $table,
                    implode(", ", $columns),
                    implode(", ", $placeholders)
                );

                $stmt = Database::getConnection()->prepare($sql);
                $stmt->execute($values);

                // Set the inserted ID
                $this->db_id = (int) Database::getConnection()->lastInsertId();

            } else {
                // Update logic
                $setClauses = [];
                foreach ($columns as $column) {
                    $setClauses[] = "$column = ?";
                }

                $sql = sprintf(
                    "UPDATE `%s` SET %s WHERE id = ?",
                    $table,
                    implode(", ", $setClauses)
                );

                // Append the ID value for the WHERE clause
                $values[] = $idValue;
                $stmt = Database::getConnection()->prepare($sql);
                $stmt->execute($values);
            }

            return true;
        } catch (Throwable $th) {
            $this->last_error = $th;
            return false;
        }
    }

    /**
     * Retrieves records.
     * @return static[]
     */
    public static function list(int $page = 1, int $maxPerPage = 10): array
    {
        $reflection = self::getReflection();
        $start = ($page - 1) * $maxPerPage;

        $tableName = self::cleanName($reflection->getName());
        $sql = sprintf(
            "SELECT * FROM `%s` LIMIT :start, :maxPerPage",
            $tableName
        );

        $stmt = Database::getConnection()->prepare($sql);
        $stmt->bindValue(':start', $start, PDO::PARAM_INT);
        $stmt->bindValue(':maxPerPage', $maxPerPage, PDO::PARAM_INT);
        $stmt->execute();

        $allData = [];
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($results as $data) {
            $allData[] = (new static())->populate($data);
        }

        return $allData;
    }

    /**
     * Retrieves the first record.
     * @return static|null
     */
    public static function first(): ?self
    {
        $reflection = self::getReflection();
        $tableName = self::cleanName($reflection->getName());
        $sql = sprintf(
            "SELECT * FROM `%s` ORDER BY id ASC LIMIT 1",
            $tableName
        );

        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute();

        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        return $data ? (new static())->populate($data) : null;
    }

    /**
     * Retrieves a record by ID.
     * @return static|null
     */
    public static function getById(int $id): ?self
    {
        $reflection = self::getReflection();
        $tableName = self::cleanName($reflection->getName());
        $sql = sprintf(
            "SELECT * FROM `%s` WHERE id = :id",
            $tableName
        );

        $stmt = Database::getConnection()->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        return $data ? (new static())->populate($data) : null;
    }

    /**
     * Retrieves records by field and value.
     * @return static[]
     */
    public static function findBy(string $field, $value, int $page = 1, int $maxResults = 10): array
    {
        return static::findWhere([[$field, '=', $value]], $page, $maxResults);
    }

    /**
     * Advanced find function without writing raw SQL.
     * Supports both simple and advanced conditions.
     *
     * @param array $conditions Conditions in one of the following formats:
     *                          - Simple: ['field' => value, ...]
     *                          - Advanced: [['field', 'operator', 'value'], ...]
     * @param int $page Page number for pagination
     * @param int $maxResults Maximum results per page
     * @return static[]
     */
    public static function findWhere(array $conditions = [], int $page = 1, int $maxResults = 10): array
    {
        $reflection = self::getReflection();
        $start = ($page - 1) * $maxResults;

        $tableName = self::cleanName($reflection->getName());
        $allowedFields = self::getAllowedFields();

        $whereClauses = [];
        $params = [];

        if (!empty($conditions)) {
            // Determine if conditions are simple or advanced
            $isAdvanced = isset($conditions[0]) && is_array($conditions[0]) && isset($conditions[0][1]);
            if ($isAdvanced) {
                // Advanced conditions
                foreach ($conditions as $condition) {
                    if (!is_array($condition) || count($condition) !== 3) {
                        throw new Exception("Each condition must be an array with [field, operator, value]");
                    }
                    [$field, $operator, $value] = $condition;

                    if (!in_array($field, $allowedFields, true)) {
                        throw new Exception("Invalid field: $field");
                    }

                    if (!in_array($operator, ['=', '<', '>', '<=', '>=', '<>', '!=', 'LIKE'], true)) {
                        throw new Exception("Invalid operator: $operator");
                    }

                    $paramKey = ':' . str_replace('.', '_', $field) . '_' . count($params);
                    $whereClauses[] = "`$field` $operator $paramKey";
                    $params[$paramKey] = $value;
                }
            } else {
                // Simple conditions
                foreach ($conditions as $field => $value) {
                    if (!in_array($field, $allowedFields, true)) {
                        throw new Exception("Invalid field: $field");
                    }
                    $paramKey = ':' . str_replace('.', '_', $field);
                    $whereClauses[] = "`$field` = $paramKey";
                    $params[$paramKey] = $value;
                }
            }
        }

        $whereSQL = '';
        if (!empty($whereClauses)) {
            $whereSQL = 'WHERE ' . implode(' AND ', $whereClauses);
        }

        $sql = sprintf(
            "SELECT * FROM `%s` %s LIMIT :start, :maxResults",
            $tableName,
            $whereSQL
        );

        $stmt = Database::getConnection()->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $stmt->bindValue(':start', $start, PDO::PARAM_INT);
        $stmt->bindValue(':maxResults', $maxResults, PDO::PARAM_INT);
        $stmt->execute();

        $allData = [];
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($results as $data) {
            $allData[] = (new static())->populate($data);
        }

        return $allData;
    }

    /**
     * Retrieves the first record matching conditions without writing raw SQL.
     * Supports both simple and advanced conditions.
     *
     * @param array $conditions Conditions in one of the following formats:
     *                          - Simple: ['field' => value, ...]
     *                          - Advanced: [['field', 'operator', 'value'], ...]
     * @return static|null
     */
    public static function firstWhere(array $conditions = []): ?self
    {
        $results = static::findWhere($conditions, 1, 1);
        return $results[0] ?? null;
    }

    /**
     * Updates a field by ID.
     * @return bool
     */
    public static function update(int $id, string $field, $value): bool
    {
        $reflection = self::getReflection();
        $tableName = self::cleanName($reflection->getName());

        $allowedFields = self::getAllowedFields();
        if (!in_array($field, $allowedFields, true)) {
            throw new Exception("Invalid field: $field");
        }

        $sql = sprintf(
            "UPDATE `%s` SET `%s` = :value WHERE id = :id",
            $tableName,
            $field
        );

        $stmt = Database::getConnection()->prepare($sql);
        $stmt->bindValue(':value', $value);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Deletes a single record by ID.
     * @return bool
     */
    public static function delete(int $id): bool
    {
        $reflection = self::getReflection();
        $tableName = self::cleanName($reflection->getName());

        $sql = sprintf(
            "DELETE FROM `%s` WHERE id = :id",
            $tableName
        );

        $stmt = Database::getConnection()->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Deletes multiple records by IDs.
     * @return bool
     */
    public static function deleteGroup(array $ids): bool
    {
        $reflection = self::getReflection();
        $tableName = self::cleanName($reflection->getName());

        if (empty($ids)) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $sql = sprintf(
            "DELETE FROM `%s` WHERE id IN (%s)",
            $tableName,
            $placeholders
        );

        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute($ids);

        return $stmt->rowCount() > 0;
    }

    /**
     * Gets column names and types from the database table.
     * @return array
     */
    public static function getColumnsAndTypes(): array
    {
        $reflection = self::getReflection();
        $tableName = self::cleanName($reflection->getName());

        $sql = "DESCRIBE `$tableName`";
        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function getReflection(): ReflectionClass
    {
        return new ReflectionClass(static::class);
    }

    private static function cleanName(string $name): string
    {
        // Get the class name without the namespace
        $className = strrchr($name, '\\') ? substr(strrchr($name, '\\'), 1) : $name;

        // Remove any non-alphanumeric characters and underscores
        $cleaned = preg_replace('/[^a-zA-Z0-9_]/', '', $className);

        // Convert the result to lowercase
        return strtolower($cleaned);
    }

    private function populate(array $data): self
    {
        $reflection = new ReflectionClass($this);
        $properties = $reflection->getProperties();

        foreach ($properties as $property) {
            $propertyName = $property->getName();
            $attributes = $property->getAttributes(SQLIgnore::class);
            if (count($attributes) == 0) {
                $columnName = $this->getColumnName($propertyName);
                $property->setAccessible(true);

                if (isset($data[$columnName])) {
                    $declaredType = $property->getType()?->getName();

                    if ($declaredType === DateTime::class) {
                        $property->setValue($this, new DateTime($data[$columnName]));
                    } elseif ($this->isDatabaseObject($declaredType)) {
                        // It's a DatabaseObject, we need to fetch the related object
                        $relatedClass = $declaredType;
                        $relatedId = $data[$columnName];
                        $relatedObject = $relatedClass::getById((int)$relatedId);
                        if($relatedObject){
                            $property->setValue($this, $relatedObject);
                        }
                        
                    } elseif ($this->isSerializableType($declaredType)) {
                        // Deserialize JSON data
                        $property->setValue($this, json_decode($data[$columnName], true));
                    } elseif ($declaredType === 'array') {
                        // Deserialize arrays
                        $property->setValue($this, json_decode($data[$columnName], true));
                    } else {
                        // For scalar types
                        $property->setValue($this, $data[$columnName]);
                    }
                }
            }
        }
        $this->populated = true;
        return $this;
    }


    private function isSerializableType(?string $typeName): bool
    {
        $nonSerializableTypes = ['int', 'float', 'string', 'bool', 'array', DateTime::class];
        if ($typeName === null || in_array($typeName, $nonSerializableTypes, true)) {
            return false;
        }

        if ($this->isDatabaseObject($typeName)) {
            return false;
        }

        return true;
    }

    private function getColumnName(string $propertyName): string
    {
        if (strpos($propertyName, 'db_') === 0) {
            return substr($propertyName, 3);
        }
        return $propertyName;
    }

    private static function getAllowedFields(): array
    {
        $reflection = self::getReflection();
        $properties = $reflection->getProperties();
        $allowedFields = [];

        foreach ($properties as $property) {
            $attributes = $property->getAttributes(SQLIgnore::class);
            if (empty($attributes)) {
                $columnName = (new static())->getColumnName($property->getName());
                $allowedFields[] = $columnName;
            }
        }

        return $allowedFields;
    }
}
?>
