<?php
declare(strict_types =1);
namespace AyelaORM;
use AyelaORM\SQLType;
use AyelaORM\Database;



/**
 * DatabaseObject
 * This class should be inherited from by classes intended to be stored in the database.
 * Any class that inherits from it should define database-stored properties prefixed with db_
 */
class DatabaseObject {
    #[SQLType("INT(20) UNSIGNED AUTO_INCREMENT")]
    public int $db_id = 0;

    //Public
    public ?\Throwable $last_error = null;
    //Private
    private bool $populated = false;

    public function __construct()
    {
        if(!Database::$frozen){
           $this->checkSchema(); 
        }
        
    }


    private function checkSchema(): void
    {
        $reflection = new \ReflectionClass($this);
        $properties = $reflection->getProperties();
        $newSchema = [];
        


        foreach ($properties as $property) {
            $type = $this->getSQLType($property);
            $newSchema[$property->getName()] = $type;
        }

        $schemaHash = md5(json_encode($newSchema));
        if (!$this->tableExists(self::cleanName($reflection->getName()))){
            $this->createTable($newSchema);
            $this->saveSchemaHash($schemaHash);
        }
        
        if ($this->isSchemaChanged($schemaHash)) {
            $tableName = self::cleanName($reflection->getName());

            // Get existing schema
            $existingSchema = $this->getExistingTableSchema($tableName);

            // Compare and generate ALTER statements if needed
            $alterStatements = $this->generateAlterTableStatements($tableName, $existingSchema, $newSchema);

            // Execute ALTER statements if there are any
            if (!empty($alterStatements)) {
                foreach ($alterStatements as $sql) {
                    try {
                        Database::getConnection()->exec($sql);
                        echo "Executed: $sql\n";
                    } catch (\PDOException $th) {
                        echo "Failed To Execute: $sql\n";
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

    private function getSQLType(\ReflectionProperty $property): string
    {
        // Check if SQLType attribute is present
        $attributes = $property->getAttributes(SQLType::class);
        if (!empty($attributes)) {
            $sqlType = $attributes[0]->newInstance()->type;
        } else {
            // Infer SQL type based on declared PHP type
            $declaredType = $property->getType()?->getName();
            $sqlType = match ($declaredType) {
                'int' => 'INT',
                'string' => 'VARCHAR(255)',
                'float', 'double' => 'FLOAT',
                'bool' => 'BOOLEAN',
                default => 'VARCHAR(255)', // Dynamic types default to VARCHAR(255)
            };
        }
        return $sqlType;
    }

    private function isSchemaChanged(string $newHash): bool
    {
        try{
            $reflection = self::getReflection();
            $stmt = Database::getConnection()->prepare("SELECT schema_hash FROM schema_info WHERE table_name = ?");
            $stmt->execute([self::cleanName($reflection->getName())]);
            $existingHash = $stmt->fetchColumn();

            return $existingHash !== $newHash;
        }catch(\PDOException $e){
            // Error no Schema
            //printf($e);
            return true;
        }
        
    }

    private function createTable(array $schema): void
    {
        $tableName = static::class;
        $columns = [];
        
        foreach ($schema as $property => $sqlType) {
            $property = str_replace("db_","",$property);
            if($property == "id"){
                $sqlType.=" PRIMARY KEY";
            }
            $columns[] = "`$property` $sqlType";
        }

        $columnDefinitions = implode(', ', $columns);

        $sql = "CREATE TABLE IF NOT EXISTS `$tableName` ($columnDefinitions)";
        Database::getConnection()->exec($sql);

        //echo "Table '$tableName' created or updated successfully.\n";
    }

    private function getExistingTableSchema(string $tableName): array
    {
        $stmt = Database::getConnection()->query("DESCRIBE `$tableName`");
        $existingSchema = [];

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $existingSchema[$row['Field']] = $row['Type'];
        }

        return $existingSchema;
    }

    private function generateAlterTableStatements(string $tableName, array $existingSchema, array $newSchema): array
    {
        $alterStatements = [];

        // Add new columns
        foreach ($newSchema as $column => $type) {
            $column = str_replace("db_","",$column);
            if (!isset($existingSchema[$column])) {
                $alterStatements[] = "ALTER TABLE `$tableName` ADD `$column` $type";
            }
        }

        // Modify existing columns
        foreach ($newSchema as $column => $type) {
            $column = str_replace("db_","",$column);
            if (isset($existingSchema[$column]) && $existingSchema[$column] !== $type) {
                $alterStatements[] = "ALTER TABLE `$tableName` MODIFY `$column` $type";
            }
        }

        // Drop columns no longer in the class (optional, handle carefully)
        foreach ($existingSchema as $column => $type) {
            if (!isset($newSchema[$column])) {
                // Optionally drop columns that are no longer present in the class
                // $alterStatements[] = "ALTER TABLE `$tableName` DROP COLUMN `$column`";
            }
        }

        return $alterStatements;
    }

    private function saveSchemaHash(string $hash): void
    {
        $tableName = static::class;

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
     * 
     */
    public function save() : bool {
        $reflection = new \ReflectionClass($this);
        $properties = $reflection->getProperties();
        
        try {
            $table = self::cleanName($reflection->getName());
            $columns = [];
            $values = [];
            $placeholders = [];
    
            foreach ($properties as $property) {
                $propertyName = $property->getName();
                if (strpos($propertyName, 'db_') === 0) {
                    $columnName = substr($propertyName, 3);
                    $property->setAccessible(true);
                    $value = $property->getValue($this);
    
                    if ($columnName == 'id') {
                        $idValue = $value;  // Store the primary key value for the WHERE clause in case of update
                        continue;
                    }
    
                    $columns[] = $columnName;
                    $values[] = $value;
                    $placeholders[] = "?";
                }
            }
    
            if(!$this->populated) {
                // Insert logic
                $sql = sprintf(
                    "INSERT INTO %s (%s) VALUES (%s)",
                    $table,
                    implode(", ", $columns),
                    implode(", ", $placeholders)
                );
    
                $stmt = Database::getConnection()->prepare($sql);
                $stmt->execute($values);
    
            } else {
                // Update logic
                $setClauses = [];
                foreach ($columns as $column) {
                    $setClauses[] = "$column = ?";
                }
                
                $sql = sprintf(
                    "UPDATE %s SET %s WHERE id = ?",
                    $table,
                    implode(", ", $setClauses)
                );
                
                // Append the ID value for the WHERE clause
                $values[] = $idValue;
                $stmt = Database::getConnection()->prepare($sql);
                $stmt->execute($values);
            }
    
            return true;
        } catch (\Throwable $th) {
            $this->last_error = $th;
            return false;
        }
    }

    /**
     * Retreves Records
     * @return static[]
     */
    public static function list($page = 1, $maxPerPage = 10): array {
        $reflection = self::getReflection();
        $start = $page * $maxPerPage;

        $sql = sprintf(
            "SELECT * FROM %s LIMIT :page, :maxPerPage",
            self::cleanName($reflection->getName())
        );

        $stmt = Database::getConnection()->prepare($sql);
        $stmt->bindParam(':page', $page, \PDO::PARAM_INT);    
        $stmt->bindParam(':maxPerPage', $maxPerPage, \PDO::PARAM_INT);    
        $stmt->execute();
        $allData = [];
        foreach($stmt->fetchAll(\PDO::FETCH_ASSOC) as $data){
            array_push($allData, $reflection->newInstance()->populate($data));
        }
        return ($allData);
    }

    /**
     * Retreves First Record
     * @return static
     */
    public static function first()  {
        $reflection = self::getReflection();
        $sql = sprintf(
            "SELECT * FROM %s ORDER BY id ASC LIMIT 1",
            self::cleanName($reflection->getName())
        );

        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute();

        return ($reflection->newInstance())->populate($stmt->fetch(\PDO::FETCH_ASSOC));
    }

    /**
     * Retreves Record by id
     * @return static
     */
    public static function getById($id) {
        $reflection = self::getReflection();
        $sql = sprintf(
            "SELECT * FROM %s WHERE id = ?",
            self::cleanName($reflection->getName())
        );

        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute([$id]);

        return ($reflection->newInstance())->populate($stmt->fetch(\PDO::FETCH_ASSOC));
    }

    /**
     * Retreves Record by field TODO
     * @return static
     */
    public static function getBy($field, $value, $page = 1, $maxResults = 10) {
        $reflection = self::getReflection();
        $start = ($page - 1) * $maxResults;

        $sql = sprintf(
            "SELECT * FROM %s WHERE %s = ? LIMIT ?, ?",
            self::cleanName($reflection->getName()),
            $field
        );

        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute([$value, $start, $maxResults]);

        return ($reflection->newInstance())->populate($stmt->fetch(\PDO::FETCH_ASSOC));
    }
    /**
     * Updates Field by id 
     * @return bool
     */
    public static function update($id, $field, $value) {
        $reflection = self::getReflection();

        $sql = sprintf(
            "UPDATE %s SET %s = ? WHERE id = ?",
            self::cleanName($reflection->getName()),
            $field
        );

        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute([$value, $id]);

        return $stmt->rowCount() > 0;
    }
    /**
     * Delete Single Record by id
     * @return bool
     */
    public static function delete(int $id):bool {
        $reflection = self::getReflection();

        $sql = sprintf(
            "DELETE FROM %s WHERE id = ?",
            self::cleanName($reflection->getName())
        );

        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }
    /**
     * Deletes Group Data
     * @return bool
     */
    public static function deleteGroup(array $ids) {
        $reflection = self::getReflection();
        $inClause = implode(",", array_fill(0, count($ids), "?"));

        $sql = sprintf(
            "DELETE FROM %s WHERE id IN (%s)",
            self::cleanName($reflection->getName()),
            $inClause
        );

        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute($ids);
    }

    public static function getColumnsAndTypes() {
        $reflection = self::getReflection();
        $table = self::cleanName($reflection->getName());

        $sql = "DESCRIBE $table";
        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private static function getReflection() {
        return new \ReflectionClass(get_called_class());
    }

    private static function cleanName(string $name) {
        // Get the class name without the namespace
        $className = strrchr($name, '\\') ? substr(strrchr($name, '\\'), 1) : $name;

        // Remove any non-alphanumeric characters and underscores
        $cleaned = preg_replace('/[^a-zA-Z0-9_]/', '', $className);

        // Convert the result to lowercase
        return strtolower($cleaned);
    }

    private function populate(array $data):self{
        $reflection = new \ReflectionClass($this);
        $properties = $reflection->getProperties();

        foreach ($properties as $key => $property) {
            $propertyName = $property->getName();
            if (strpos($propertyName, 'db_') === 0) {
                $columnName = substr($propertyName, 3);
                $property->setAccessible(true);
                if(isset($data[$columnName])){
                    $property->setValue($this ,$data[$columnName]);
                }
            }
        }
        $this->populated = true;
        return $this;
    }
}





?>
