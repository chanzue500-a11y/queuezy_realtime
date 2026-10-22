<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Administrator access required']);
    exit;
}
session_write_close();

require_once __DIR__ . '/../database/db.php';
require_once __DIR__ . '/../includes/user_presence.php';

if (!$conn || !ensureUserPresenceColumns($conn)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'message' => 'User activity is temporarily unavailable']);
    exit;
}

$result = $conn->query('SELECT id, last_seen_at FROM users');
if (!$result) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'message' => 'Unable to check user activity']);
    exit;
}

$users = [];
$now = time();
while ($row = $result->fetch_assoc()) {
    $users[] = ['id' => (int)$row['id']] + getUserPresence($row['last_seen_at'], $now);
}
$result->free();

echo json_encode(['ok' => true, 'users' => $users]);
