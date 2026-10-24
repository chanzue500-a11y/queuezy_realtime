<?php
require_once __DIR__ . '/../dist/database/db.php';
require_once __DIR__ . '/../dist/includes/queue_transactions.php';
if (!$conn) throw new RuntimeException('Database unavailable');
foreach ([
    'CREATE TEMPORARY TABLE queue (id INT PRIMARY KEY) ENGINE=InnoDB',
    'CREATE TEMPORARY TABLE queue_payments (id INT PRIMARY KEY, queue_id INT) ENGINE=InnoDB',
    'CREATE TEMPORARY TABLE queue_ratings (id INT PRIMARY KEY, queue_id INT) ENGINE=InnoDB',
    'INSERT INTO queue VALUES (1),(2)', 'INSERT INTO queue_payments VALUES (11,1),(12,2)',
    'INSERT INTO queue_ratings VALUES (21,1),(22,2)',
] as $sql) if (!$conn->query($sql)) throw new RuntimeException($conn->error);
if (!deleteQueueTransaction($conn, 1)['ok']) throw new RuntimeException('Deletion failed');
foreach (['queue' => 'id', 'queue_payments' => 'queue_id', 'queue_ratings' => 'queue_id'] as $table => $key) {
    if ($conn->query("SELECT * FROM $table WHERE $key = 1")->num_rows !== 0) throw new RuntimeException('Related record not removed');
    if ($conn->query("SELECT * FROM $table WHERE $key = 2")->num_rows !== 1) throw new RuntimeException('Other transaction changed');
}
foreach ([0, -1, 999, 1] as $id) if (deleteQueueTransaction($conn, $id)['ok']) throw new RuntimeException('Invalid or duplicate deletion succeeded');
// Force a failure after the rating deletion and verify that it is rolled back.
if (!$conn->query('ALTER TABLE queue_payments DROP COLUMN queue_id')) throw new RuntimeException($conn->error);
if (deleteQueueTransaction($conn, 2)['ok']) throw new RuntimeException('Partial deletion reported success');
if ($conn->query('SELECT * FROM queue_ratings WHERE queue_id = 2')->num_rows !== 1
    || $conn->query('SELECT * FROM queue WHERE id = 2')->num_rows !== 1) throw new RuntimeException('Partial deletion was not rolled back');
echo "PASS: transaction deletion, payment/rating cleanup, unrelated records, invalid IDs, and rollback.\n";
