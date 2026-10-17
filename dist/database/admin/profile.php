<?php
session_start();

if (($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}

require_once '../staff/profile.php';
