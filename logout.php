<?php
session_start();
require_once __DIR__ . '/dist/database/db.php';
require_once __DIR__ . '/dist/includes/user_presence.php';
markUserOffline($conn, (int)($_SESSION['user_id'] ?? 0));
$_SESSION = [];
session_destroy();
header("Location: dist/login.php");
exit(); 
?>
