<?php
require_once __DIR__ . '/../dist/database/db.php';
require_once __DIR__ . '/../dist/includes/daily_sales.php';
if (!$conn) throw new RuntimeException('Database unavailable');
function salesExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function salesQuery(mysqli $connection, string $sql): void
{
    if (!$connection->query($sql)) throw new RuntimeException($connection->error);
}
$now = new DateTimeImmutable('2026-10-23 18:00:00', new DateTimeZone('UTC'));
$defaults = dailySalesFilters([], $now);
salesExpect($defaults['date_from'] === '2026-10-18' && $defaults['date_to'] === '2026-10-24', 'Defaults must use Philippine dates');
foreach ([['sales_from' => '2026-02-30'], ['sales_to' => '2026-10-25'], ['sales_from' => []],
    ['sales_from' => '2026-10-24', 'sales_to' => '2026-10-23'], ['sales_from' => '2020-01-01']] as $invalid) {
    salesExpect(dailySalesFilters($invalid, $now)['errors'] !== [], 'Invalid range must be rejected');
}

// Connection-local fixtures never change real payments or queues.
salesQuery($conn, 'CREATE TEMPORARY TABLE queue (id INT PRIMARY KEY, status VARCHAR(20), created_at DATETIME, completed_at DATETIME NULL)');
salesQuery($conn, 'CREATE TEMPORARY TABLE queue_payments (queue_id INT PRIMARY KEY, status VARCHAR(20), amount DECIMAL(10,2), paid_at DATETIME)');
for ($id = 1; $id <= 25; $id++) {
    $time = $id === 1 ? '08:30:00' : ($id === 25 ? '17:00:00' : '12:00:00');
    salesQuery($conn, "INSERT INTO queue VALUES ($id,'completed','2026-10-15 09:00:00','2026-10-23 $time')");
    salesQuery($conn, "INSERT INTO queue_payments VALUES ($id,'paid',100.50,'2026-10-24 09:00:00')");
}
foreach ([
    [26, 'completed', '2026-10-23 08:29:59', 'paid'],
    [27, 'completed', '2026-10-23 17:00:01', 'paid'],
    [28, 'completed', '2026-10-23 12:00:00', 'pending'],
    [29, 'cancelled', '2026-10-23 12:00:00', 'paid'],
    [30, 'no_show', '2026-10-23 12:00:00', 'paid'],
    [31, 'waiting', '2026-10-23 12:00:00', 'paid'],
] as [$id, $status, $completed, $payment]) {
    salesQuery($conn, "INSERT INTO queue VALUES ($id,'$status','2026-10-23 09:00:00','$completed')");
    salesQuery($conn, "INSERT INTO queue_payments VALUES ($id,'$payment',999.99,'2026-10-23 12:00:00')");
}
salesQuery($conn, "INSERT INTO queue VALUES (32,'completed','2026-10-23 09:00:00',NULL), (33,'completed','2026-10-24 09:00:00','2026-10-24 10:00:00'), (34,'completed','2026-10-23 09:00:00','2026-10-23 12:00:00')");
salesQuery($conn, "INSERT INTO queue_payments VALUES (32,'paid',999.99,'2026-10-23 12:00:00'), (33,'paid',123.45,'2026-10-24 10:00:00')");
$yesterday = dailySalesData($conn, dailySalesFilters(['sales_from' => '2026-10-23', 'sales_to' => '2026-10-23'], $now));
salesExpect($yesterday['total_tickets'] === 25, 'Yesterday must have exactly 25 completed tickets within cutoff');
salesExpect($yesterday['total_sales_cents'] === 251250, 'Sales must sum actual paid amounts exactly');
salesExpect($yesterday['rows'][0]['date'] === '2026-10-23', 'Group by completion date, not creation/payment dates');
$range = dailySalesData($conn, dailySalesFilters(['sales_from' => '2026-10-22', 'sales_to' => '2026-10-24'], $now));
salesExpect(array_column($range['rows'], 'completed_tickets') === [1, 25, 0], 'Include today, yesterday, and zero-sale days');
salesExpect($range['total_tickets'] === 26 && $range['total_sales_cents'] === 263595, 'Period totals incorrect');
$lastPage = dailySalesData($conn, dailySalesFilters(['sales_from' => '2026-10-01', 'sales_to' => '2026-10-24', 'sales_page' => '99'], $now));
salesExpect($lastPage['page'] === 3 && $lastPage['pages'] === 3 && count($lastPage['rows']) === 4, 'Show ten days per page and clamp invalid pages');
salesExpect($lastPage['total_tickets'] === 26, 'Pagination must not change period totals');
echo "PASS: daily sales dates, Philippine timezone, 25-ticket day, exact cutoff boundaries, paid/completed scope, amounts, zero days, and pagination.\n";
