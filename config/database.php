<?php
class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    public $conn;

    public function __construct() {
        // 使用環境變數或預設值
        $this->host = $_ENV['DB_HOST'] ?? 'weather-db.cjmwbsp7ueh4.us-east-1.rds.amazonaws.com';
        $this->db_name = $_ENV['DB_NAME'] ?? 'weather_db';
        $this->username = $_ENV['DB_USER'] ?? 'admin';
        $this->password = $_ENV['DB_PASS'] ?? '12345678';
    }

    public function getConnection() {
        $this->conn = null;
        
        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name, $this->username, $this->password);
            $this->conn->exec("set names utf8");
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $exception) {
            echo "連線錯誤: " . $exception->getMessage();
        }
        
        return $this->conn;
    }
}
?>
