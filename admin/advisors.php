<?php
session_start();
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireAdmin();

// Toggle advisor active status
if (isset($_GET['toggle'])) {
    $uid  = (int)$_GET['toggle'];
    $stmt = $pdo->prepare("SELECT is_active FROM users WHERE user_id=? AND role='advisor'");
    $stmt->execute([$uid]);
    $row  = $stmt->fetch();
    if ($row) {
        $new = $row['is_active'] ? 0 : 1;
        $pdo->prepare("UPDATE users SET is_active=? WHERE user_id=?")
            ->execute([$new, $uid]);
        logActivity($pdo, $_SESSION['user_id'], 'TOGGLE_ADVISOR',
            "Advisor ID $uid set to " . ($new ? 'Active' : 'Inactive'));
    }
    redirectTo('admin/dashboard.php');
}

redirectTo('admin/dashboard.php');
?>