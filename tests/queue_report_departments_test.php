<?php
require_once __DIR__ . '/../dist/database/db.php';
require_once __DIR__ . '/../dist/includes/queue_report.php';
if (!$conn) throw new RuntimeException('Database unavailable');

// Temporary tables shadow application data for this connection only.
foreach ([
    'CREATE TEMPORARY TABLE departments (id INT PRIMARY KEY, dept_name VARCHAR(120))',
    'CREATE TEMPORARY TABLE services (id INT PRIMARY KEY, dept_id INT, service_name VARCHAR(120))',
    'CREATE TEMPORARY TABLE users (id INT PRIMARY KEY, fullname VARCHAR(120))',
    'CREATE TEMPORARY TABLE queue (id INT PRIMARY KEY, user_id INT, service_id INT, ticket_number VARCHAR(30), status VARCHAR(20), created_at DATETIME, started_at DATETIME, completed_at DATETIME)',
    'CREATE TEMPORARY TABLE queue_payments (id INT PRIMARY KEY, queue_id INT, amount DECIMAL(10,2), status VARCHAR(20), paid_at DATETIME)',
    'CREATE TEMPORARY TABLE service_fees (service_id INT PRIMARY KEY, amount DECIMAL(10,2))',
    "INSERT INTO users VALUES (1, 'Test customer')",
    "INSERT INTO services VALUES (11, 1, 'First service'), (12, 1, 'Second service'), (21, 2, 'Other department service')",
    "INSERT INTO queue VALUES (1,1,11,'TEST-1','waiting',NOW(),NULL,NULL),
        (2,1,12,'TEST-2','completed',NOW(),NOW(),NOW()), (3,1,21,'TEST-3','waiting',NOW(),NULL,NULL)",
] as $sql) {
    if (!$conn->query($sql)) throw new RuntimeException($conn->error);
}
for ($id = 1; $id <= 12; $id++) {
    if (!$conn->query("INSERT INTO departments VALUES ($id, 'Department $id')")) throw new RuntimeException($conn->error);
}

foreach ([
    [[], [1 => 1], 1, 3],
    [['status' => 'completed'], [1 => 1], 1, 1],
    [['status' => 'waiting'], [], 0, 2],
    [['department_id' => '2'], [], 0, 1],
    [['search' => 'no matching tickets'], [], 0, 0],
    [['date_from' => date('Y-m-d', strtotime('+1 day'))], [], 0, 0],
] as [$input, $expected, $total, $reportTotal]) {
    $data = queueReportData($conn, queueReportFilters($input));
    if (count($data['department_counts']) !== 12) throw new RuntimeException('Not all 12 departments are shown');
    $counts = array_column($data['department_counts'], 'ticket_count', 'dept_id');
    for ($id = 1; $id <= 12; $id++) {
        if ($counts[$id] !== ($expected[$id] ?? 0)) throw new RuntimeException("Incorrect count for department $id");
    }
    if (array_sum($counts) !== $total || (int)$data['summary']['total_count'] !== $reportTotal) {
        throw new RuntimeException('Incorrect completed graph or filtered report total');
    }
}
if (!$conn->query("INSERT INTO queue VALUES (4,1,999,'UNKNOWN','completed',NOW(),NOW(),NOW())")) throw new RuntimeException($conn->error);
$orphaned = queueReportData($conn, queueReportFilters([]));
if (count($orphaned['department_counts']) !== 13 || array_sum(array_column($orphaned['department_counts'], 'ticket_count')) !== 2) {
    throw new RuntimeException('Unassigned tickets must remain visible without losing departments');
}
if (!$conn->query("INSERT INTO queue VALUES
    (5,1,11,'CANCELLED','cancelled',NOW(),NULL,NULL),
    (6,1,21,'NO-SHOW','no_show',NOW(),NULL,NULL),
    (7,1,11,'CALLED','called',NOW(),NOW(),NULL),
    (8,1,21,'SERVING','now_serving',NOW(),NOW(),NULL)")) throw new RuntimeException($conn->error);
$excluded = queueReportData($conn, queueReportFilters([]));
$counts = array_column($excluded['department_counts'], 'ticket_count', 'dept_id');
if ($counts[1] !== 1 || $counts[2] !== 0 || array_sum($counts) !== 2) {
    throw new RuntimeException('Graph must count only completed tickets');
}
foreach (['cancelled', 'no_show'] as $status) {
    $filtered = queueReportData($conn, queueReportFilters(['status' => $status]));
    if (array_sum(array_column($filtered['department_counts'], 'ticket_count')) !== 0
        || count($filtered['department_counts']) !== 12 || (int)$filtered['summary']['total_count'] !== 1) {
        throw new RuntimeException('Excluded-status filter must show zero graph counts while retaining report records');
    }
}
echo "PASS: completed-only counts, all departments, filters, zero counts, and unassigned tickets.\n";
