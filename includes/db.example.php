<?php
require_once __DIR__ . '/../config.php';

// ============================================
// Database Connection
// Copy this file as db.php and fill in your credentials
// DO NOT commit db.php to version control
// ============================================
define('DB_HOST', 'localhost');
define('DB_USER', 'root');         // default XAMPP user
define('DB_PASS', '');             // default XAMPP password (blank)
define('DB_NAME', 'TAXSTART_db');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>
