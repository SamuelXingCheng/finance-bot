<?php
// 引入設定檔
require_once __DIR__ . '/../config.php'; // 確保路徑指向根目錄的 config.php

class Database {
    private static $instance = null;
    private $conn;

    private function __construct() {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,    
            PDO::ATTR_EMULATE_PREPARES   => false,               
        ];
        
        try {
            $this->conn = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (\PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            die("Database connection failed. Please check logs.");
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->conn;
    }

    private function __clone() {}
    public function __wakeup() {}
}
?>