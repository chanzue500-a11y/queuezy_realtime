<?php
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? 'customer') !== 'customer') {
    header('Location: ../login.php');
    exit;
}

header('Location: ../index.php#notifications');
exit;
