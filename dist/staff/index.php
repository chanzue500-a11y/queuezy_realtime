<?php
require_once __DIR__ . '/../includes/auth_check.php';
requireAuth(['staff']);

header('Location: monitor.php');
exit;
