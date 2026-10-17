<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_GET['guest']) && $_GET['guest'] === '1') {
    $_SESSION['guest_mode'] = true;
    unset($_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['user_name'], $_SESSION['user_email']);
    header('Location: customer_dashboard.php');
    exit;
}

require_once __DIR__ . '/../includes/auth_check.php';
requireAuth(['customer']);

header('Location: customer_dashboard.php');
exit;
