<?php
// config.php - Centralized Database Connection for Railway
// Prioritize Railway official variables (MYSQL...), fall back to custom (DB_...)

// 1. Get Environment Variables
$host = getenv('MYSQLHOST') ?: getenv('DB_HOST') ?: 'mainline.proxy.rlwy.net';
$user = getenv('MYSQLUSER') ?: getenv('DB_USER') ?: 'root';
$password = getenv('MYSQLPASSWORD') ?: getenv('DB_PASSWORD') ?: 'jPNMrNeqkNvQtnQNRKkeaMTsrcIkYfxj';
$database = getenv('MYSQLDATABASE') ?: getenv('DB_NAME') ?: 'railway';
$port = getenv('MYSQLPORT') ?: getenv('DB_PORT') ?: 3306;

// 2. Convert port to integer
$port = intval($port);

// 3. Connect using MySQLi
$conn = new mysqli($host, $user, $password, $database, $port);

// 4. Check Connection
if ($conn->connect_error) {
    // Log error securely
    error_log("DB Connection Failed: " . $conn->connect_error);
    
    // Return JSON error if this is an API call
    if (strpos($_SERVER['SCRIPT_NAME'], 'api') !== false || strpos($_SERVER['SCRIPT_NAME'], 'callback') !== false) {
        header('Content-Type: application/json');
        echo json_encode(["success" => false, "message" => "Database connection error"]);
        exit();
    }
    die("Database connection failed.");
}

// 5. Set Charset
$conn->set_charset("utf8mb4");
?>