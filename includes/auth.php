<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: " . BASE_URL . "index.php");
        exit();
    }
}

function requireAdmin() {
    requireLogin();
    if ($_SESSION['role'] !== 'admin') {
        header("Location: " . BASE_URL . "advisor/dashboard.php");
        exit();
    }
}

function requireAdvisor() {
    requireLogin();
    if ($_SESSION['role'] !== 'advisor') {
        header("Location: " . BASE_URL . "admin/dashboard.php");
        exit();
    }
}

function currentUser() {
    return [
        'id'   => $_SESSION['user_id']   ?? null,
        'name' => $_SESSION['full_name'] ?? null,
        'role' => $_SESSION['role']      ?? null,
    ];
}
?>
