<?php
/**
 * Database Configuration
 * Connect to MySQL Database
 */

// Database connection parameters
$db_host = getenv('DB_HOST') ?: 'localhost';
$db_name = getenv('DB_NAME') ?: 'u649217041_rjm_architect';

$http_host = $_SERVER['HTTP_HOST'] ?? '';
$is_local_request = preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $http_host);

if ($is_local_request) {
    // XAMPP's default MySQL account.
    $db_username = getenv('DB_USER') ?: 'root';
    $db_password = getenv('DB_PASS') ?: '';
} else {
    $db_username = getenv('DB_USER') ?: 'u649217041_rjm_architect';
    $db_password = getenv('DB_PASS') ?: '072410Rjm';
}

// Create connection using MySQLi
$conn = new mysqli($db_host, $db_username, $db_password);

if (!$conn->connect_error) {
    $escaped_db_name = str_replace('`', '``', $db_name);
    $conn->query("CREATE DATABASE IF NOT EXISTS `{$escaped_db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $conn->select_db($db_name);
}

$mysqli = $conn;

// Check connection
if ($conn->connect_error || $conn->errno) {
    $error = $conn->connect_error ?: $conn->error;
    die(json_encode(['success' => false, 'message' => 'Database connection failed: ' . $error]));
}

// Set charset to UTF-8
$conn->set_charset("utf8");
?>
