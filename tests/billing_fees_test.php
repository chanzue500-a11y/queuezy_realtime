<?php
require_once __DIR__ . '/../dist/includes/billing_fees.php';

$checks = 0;
function billingCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    $checks++;
}
function billingRejects(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (InvalidArgumentException | RuntimeException $error) {
        billingCheck(true, $message);
        return;
    }
    billingCheck(false, $message);
}

billingCheck(billingFeeCents('1,234.50') === 123450, 'Parse formatted amounts exactly');
billingCheck(billingFeeCents('0.01') === 1, 'Keep cents');
billingCheck(billingFeeCents('99999999.99') === BILLING_MAX_FEE_CENTS, 'Accept DECIMAL maximum');
billingCheck(billingFeeDecimal(5) === '0.05', 'Format cents without float conversion');
foreach (['-1', '1.001', '1e4', 'NaN', '100000000', '', [], null] as $invalid) {
    billingRejects(fn() => billingFeeCents($invalid), 'Reject invalid amount');
}
billingCheck(billingAdjustedFee(50000, 'increase', 'amount', 5000) === 55000, 'Fixed increase');
billingCheck(billingAdjustedFee(50000, 'decrease', 'amount', 5000) === 45000, 'Fixed decrease');
billingCheck(billingAdjustedFee(50000, 'increase', 'percent', 1000) === 55000, 'Percentage increase');
billingCheck(billingAdjustedFee(50000, 'decrease', 'percent', 1000) === 45000, 'Percentage decrease');
billingCheck(billingAdjustedFee(101, 'increase', 'percent', 5000) === 152, 'Round final percentage fee up at half cent');
billingCheck(billingAdjustedFee(1, 'decrease', 'percent', 5000) === 1, 'Round final fee rather than rounded discount');
billingCheck(billingAdjustedFee(50000, 'decrease', 'percent', 10000) === 0, 'Allow 100 percent discount');
billingCheck(billingAdjustedFee(0, 'increase', 'percent', 1000) === 0, 'Percentage increase keeps free services free');
foreach ([
    [100, 'decrease', 'amount', 101],
    [100, 'decrease', 'percent', 10001],
    [BILLING_MAX_FEE_CENTS, 'increase', 'amount', 1],
    [BILLING_MAX_FEE_CENTS, 'increase', 'percent', 1],
    [100, 'increase', 'percent', 1000001],
    [100, 'increase', 'amount', 0],
    [100, 'replace', 'amount', 100],
    [100, 'increase', 'invalid', 100],
] as $invalid) {
    billingRejects(fn() => billingAdjustedFee(...$invalid), 'Reject invalid adjustment');
}
echo "PASS: $checks fee calculation checks.\n";

require __DIR__ . '/../dist/database/db.php';
if (!$conn) {
    fwrite(STDERR, "Database integration checks need a running local database.\n");
    exit(1);
}

// Connection-local temporary tables shadow app tables; no real fees are changed.
$queries = [
    "CREATE TEMPORARY TABLE departments (id INT PRIMARY KEY, dept_name VARCHAR(120)) ENGINE=InnoDB",
    "CREATE TEMPORARY TABLE services (id INT PRIMARY KEY, dept_id INT, service_name VARCHAR(160)) ENGINE=InnoDB",
    "CREATE TEMPORARY TABLE service_fees (id INT AUTO_INCREMENT PRIMARY KEY, service_id INT UNIQUE,
        amount DECIMAL(10,2) NOT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB",
    "INSERT INTO departments VALUES (1, 'Test department'), (2, 'Other department'), (3, 'Free services')",
    "INSERT INTO services VALUES (101, 1, 'Test A'), (102, 1, 'Test B'), (103, 1, 'Test C'), (201, 2, 'Other'), (301, 3, 'Free')",
    "INSERT INTO service_fees (service_id, amount) VALUES (101, 100.00), (102, 0.00), (103, 200.00), (201, 300.00), (301, 0.00)",
    "SET SESSION sql_mode = 'STRICT_ALL_TABLES'",
];
foreach ($queries as $query) {
    if (!$conn->query($query)) throw new RuntimeException('Fixture setup failed: ' . $conn->error);
}
$fees = static function () use ($conn): array {
    return array_column($conn->query('SELECT service_id, amount FROM service_fees ORDER BY service_id')->fetch_all(MYSQLI_ASSOC), 'amount', 'service_id');
};
$before = $fees();
$preview = billingBulkPreview($conn, 1, 'increase', 'amount', 1000);
billingCheck($preview['changed'] === 3 && count($preview['rows']) === 3, 'Scope preview to chosen department');
billingCheck($fees() === $before, 'Preview must not save fees');
billingCheck(billingApplyBulkPreview($conn, $preview) === 3, 'Apply all previewed changes');
$after = $fees();
billingCheck($after[101] === '110.00' && $after[102] === '10.00' && $after[103] === '210.00', 'Save exact previewed values');
billingCheck($after[201] === '300.00' && $after[301] === '0.00', 'Keep other departments unchanged');
billingRejects(fn() => billingApplyBulkPreview($conn, $preview), 'Reject repeated stale preview');

$preview = billingBulkPreview($conn, 1, 'increase', 'percent', 1000);
$conn->query('UPDATE service_fees SET amount = 11.00 WHERE service_id = 102');
$before = $fees();
billingRejects(fn() => billingApplyBulkPreview($conn, $preview), 'Reject concurrent fee edits');
billingCheck($fees() === $before, 'Stale preview saves no changes');

$preview = billingBulkPreview($conn, 1, 'increase', 'amount', 1000);
$conn->query("INSERT INTO services VALUES (104, 1, 'Added after preview')");
billingRejects(fn() => billingApplyBulkPreview($conn, $preview), 'Reject changed department membership');
$conn->query('DELETE FROM services WHERE id = 104');

$preview = billingBulkPreview($conn, 1, 'increase', 'amount', 1000);
$preview['rows'][1]['next'] = BILLING_MAX_FEE_CENTS + 100;
$before = $fees();
billingRejects(fn() => billingApplyBulkPreview($conn, $preview), 'Simulate a failure partway through saving');
billingCheck($fees() === $before, 'Roll back earlier writes on partial failure');

billingRejects(fn() => billingBulkPreview($conn, 3, 'increase', 'percent', 1000), 'Reject preview with no fee changes');
billingRejects(fn() => billingBulkPreview($conn, 999, 'increase', 'amount', 1000), 'Reject missing department');
billingRejects(fn() => billingBulkPreview($conn, 1, 'decrease', 'amount', 20000), 'Reject department adjustment making any fee negative');
$conn->close();
echo "PASS: $checks total checks, including isolated database preview, scope, stale edits, and rollback.\n";
