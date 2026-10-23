<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['admin', 'staff'], true)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Authentication required']);
    exit;
}

require_once __DIR__ . '/../database/db.php';
require_once __DIR__ . '/../includes/user_presence.php';
require_once __DIR__ . '/../includes/workspace_notifications.php';
if (!$conn) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'message' => 'Database unavailable']);
    exit;
}

$snapshot = workspaceNotificationSnapshot($conn, $_SESSION['user_role']);
if (!$snapshot['ok']) http_response_code(503);

recordUserActivity($conn, (int)$_SESSION['user_id']);

echo json_encode($snapshot);
