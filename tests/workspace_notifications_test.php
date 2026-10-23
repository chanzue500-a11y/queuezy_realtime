<?php
require_once __DIR__ . '/../dist/database/db.php';
require_once __DIR__ . '/../dist/includes/workspace_notifications.php';
if (!$conn) {
    fwrite(STDERR, "Database unavailable.\n");
    exit(1);
}

function notificationExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function notificationQuery(mysqli $connection, string $sql): void
{
    if (!$connection->query($sql)) throw new RuntimeException($connection->error);
}

// Connection-local fixtures leave application data untouched.
foreach ([
    'CREATE TEMPORARY TABLE departments (id INT PRIMARY KEY, dept_name VARCHAR(120))',
    'CREATE TEMPORARY TABLE services (id INT PRIMARY KEY, dept_id INT)',
    'CREATE TEMPORARY TABLE queue (id INT PRIMARY KEY, service_id INT, ticket_number VARCHAR(30), status VARCHAR(20), started_at DATETIME)',
    'CREATE TEMPORARY TABLE service_fees (id INT PRIMARY KEY, updated_at DATETIME)',
    'CREATE TEMPORARY TABLE users (id INT PRIMARY KEY, role VARCHAR(20), created_at DATETIME)',
    "INSERT INTO departments VALUES (1, 'Body & Massage'), (2, 'Nails')",
    'INSERT INTO services VALUES (11, 1), (21, 2)',
    "INSERT INTO users VALUES (1, 'customer', DATE_SUB(CURDATE(), INTERVAL 1 DAY)), (2, 'admin', NOW())",
    'INSERT INTO service_fees VALUES (1, DATE_SUB(CURDATE(), INTERVAL 1 DAY))',
] as $sql) notificationQuery($conn, $sql);

$empty = workspaceNotificationSnapshot($conn, 'admin');
notificationExpect($empty['ok'] && !$empty['has_activity'], 'Historical records must not trigger new activity');
notificationExpect($empty['new_customers'] === 0 && $empty['updated_services'] === 0, 'Only count updates today');
notificationExpect($empty['message'] === 'No active queues or new updates today.', 'Empty message incorrect');

notificationQuery($conn, "INSERT INTO queue VALUES
    (100,11,'BMT-100','waiting',NULL),
    (99,21,'NCE-99','called',NOW()),
    (101,11,'BMT-101','now_serving',DATE_SUB(NOW(), INTERVAL 1 HOUR)),
    (102,11,'BMT-102','completed',NOW()),
    (103,11,'BMT-103','cancelled',NOW()),
    (104,11,'BMT-104','no_show',NOW())");
$admin = workspaceNotificationSnapshot($conn, 'admin');
notificationExpect([$admin['waiting'], $admin['called'], $admin['serving']] === [1, 1, 1], 'Incorrect live queue counts');
notificationExpect($admin['has_activity'] && $admin['key'] !== $empty['key'], 'Live queue must change activity key');
notificationExpect($admin['message'] === '1 waiting, 1 called, 1 being served.', 'Admin must receive a workspace summary');
$staff = workspaceNotificationSnapshot($conn, 'staff');
notificationExpect($staff['ticket'] === 'NCE-99', 'Choose latest status start time, not largest ticket ID');
notificationExpect(str_contains($staff['message'], 'has been called (Nails)'), 'Called status incorrect');
notificationExpect(!str_contains($staff['message'], 'being served'), 'Called ticket must not be labelled serving');
notificationExpect($staff['key'] !== $admin['key'], 'Activity must be scoped by role');
notificationQuery($conn, 'UPDATE queue SET started_at = DATE_ADD(started_at, INTERVAL 1 MINUTE) WHERE id = 99');
notificationExpect(workspaceNotificationSnapshot($conn, 'admin')['key'] === $admin['key'], 'Staff recalls must not re-alert the same admin overview');
notificationQuery($conn, "UPDATE queue SET status = 'completed' WHERE id = 100");
notificationQuery($conn, "INSERT INTO queue VALUES (105,11,'BMT-105','waiting',NULL)");
$replacement = workspaceNotificationSnapshot($conn, 'admin');
notificationExpect($replacement['waiting'] === $admin['waiting'] && $replacement['key'] !== $admin['key'], 'New waiting tickets must alert even if the waiting count stays the same');

notificationQuery($conn, "UPDATE queue SET status = 'now_serving' WHERE id = 99");
$serving = workspaceNotificationSnapshot($conn, 'staff');
notificationExpect(str_contains($serving['message'], 'is being served'), 'Serving ticket must use serving wording');
notificationExpect($serving['key'] !== $staff['key'], 'Status changes must refresh unread state');
notificationQuery($conn, "UPDATE queue SET status = 'completed' WHERE id IN (99,101)");
$finished = workspaceNotificationSnapshot($conn, 'staff');
notificationExpect($finished['ticket'] === null && $finished['waiting'] === 1, 'Completed tickets must disappear');
notificationExpect($finished['message'] === '1 customer is waiting to be served.', 'Waiting fallback incorrect');

notificationQuery($conn, "INSERT INTO users VALUES (3,'customer',NOW())");
notificationQuery($conn, 'INSERT INTO service_fees VALUES (2,NOW())');
$updates = workspaceNotificationSnapshot($conn, 'admin');
notificationExpect($updates['new_customers'] === 1 && $updates['updated_services'] === 1, 'Today counts incorrect');
notificationExpect(str_contains($updates['message'], '1 new customer registered today.'), 'New customer missing from admin summary');
notificationExpect(str_contains($updates['message'], '1 service fee updated today.'), 'Fee update missing from admin summary');
notificationExpect(workspaceNotificationSnapshot($conn, 'staff')['new_customers'] === 0, 'Staff must not receive admin updates');
notificationExpect(workspaceNotificationSnapshot($conn, 'admin')['key'] === $updates['key'], 'Unchanged state must retain read key');
notificationExpect(!workspaceNotificationSnapshot(null, 'admin')['ok'], 'Database outage must not report empty queues');

notificationQuery($conn, 'ALTER TABLE queue DROP COLUMN started_at');
notificationExpect(!workspaceNotificationSnapshot($conn, 'admin')['ok'], 'Query failure must not report success');
echo "PASS: notification roles, statuses, counts, today filters, activity keys, completion, and failure states.\n";
