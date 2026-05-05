<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) {
    logActivity($pdo, $_SESSION['user_id'], 'LOGOUT', 'User logged out');
}

session_unset();
session_destroy();

header("Location: http://localhost/taxstart/index.php");
exit();
?>
