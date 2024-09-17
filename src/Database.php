<?php
namespace AyelaORM;
/**
 * Initalize Database for Ayela ORM
 */
class Database {
    private static $pdo = null;
    public static bool $frozen ;
    public static function setup(string $host, string $db, string $username, string $password,bool $frozen) {
        try {
            self::$pdo = new \PDO("mysql:host=$host;dbname=$db;charset=utf8", $username, $password);
            self::$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            Database::$frozen = $frozen;
        } catch (\PDOException $e) {
            exit('Could not connect to the database: ' . $e->getMessage());
        }
    }

    public static function getConnection() {
        return self::$pdo;
    }
}
?>