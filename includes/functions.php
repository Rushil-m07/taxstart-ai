<?php
// ============================================
// Helper Functions
// ============================================

function sanitize($input) {
    return htmlspecialchars(strip_tags(trim($input)));
}

function logActivity($pdo, $user_id, $action, $description = '') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $stmt = $pdo->prepare("
        INSERT INTO activity_logs (user_id, action, description, ip_address)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$user_id, $action, $description, $ip]);
}

function redirectTo($url) {
    header("Location: " . BASE_URL . $url);
    exit();
}

function formatDate($datetime) {
    return date('M d, Y h:i A', strtotime($datetime));
}
?>
