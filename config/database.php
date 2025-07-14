<?php
// config/database.php

// Veritabanı ayarları
define('DB_HOST', 'localhost');
define('DB_NAME', 'zimmet_system');
define('DB_USER', 'root');
define('DB_PASS', ''); // WAMP varsayılan şifre boş

// Veritabanı bağlantısı
class Database {
    private $host = DB_HOST;
    private $db_name = DB_NAME;
    private $username = DB_USER;
    private $password = DB_PASS;
    private $conn;

    public function connect() {
        $this->conn = null;
        
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8",
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            die("Veritabanı bağlantı hatası: " . $e->getMessage());
        }
        
        return $this->conn;
    }
    
    public function disconnect() {
        $this->conn = null;
    }
}

// Global veritabanı bağlantısı
function getDB() {
    static $db = null;
    if ($db === null) {
        $database = new Database();
        $db = $database->connect();
    }
    return $db;
}

// Test bağlantısı
function testConnection() {
    try {
        $db = getDB();
        echo "Veritabanı bağlantısı başarılı!";
        return true;
    } catch(Exception $e) {
        echo "Bağlantı hatası: " . $e->getMessage();
        return false;
    }
}
?>