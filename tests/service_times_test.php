<?php
require_once __DIR__ . '/../dist/includes/service_times.php';

$checks = 0;
function timeCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    $checks++;
}
function timeRejects(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (InvalidArgumentException | RuntimeException $error) {
        timeCheck(true, $message);
        return;
    }
    timeCheck(false, $message);
}
timeCheck(serviceTimeMinutes('1') === 1 && serviceTimeMinutes('120') === 120, 'Accept valid processing time boundaries');
foreach (['0', '-1', '121', '1.5', '1e2', '', [], null] as $invalid) {
    timeRejects(fn() => serviceTimeMinutes($invalid), 'Reject invalid minutes');
}
timeCheck(serviceTimeIds(['3', '1', '3', '2']) === [1, 2, 3], 'Sort and deduplicate selected services');
foreach ([[], '1', [0], [-1], ['1.5'], [['1']], array_fill(0, SERVICE_TIME_BATCH_LIMIT + 1, '1')] as $invalid) {
    timeRejects(fn() => serviceTimeIds($invalid), 'Reject invalid or excessive selections');
}

require_once __DIR__ . '/../dist/database/db.php';
if (!$conn) {
    fwrite(STDERR, "Database integration checks need a running local database.\n");
    exit(1);
}
// These connection-local tables shadow application tables; real service times remain untouched.
foreach ([
    'CREATE TEMPORARY TABLE departments (id INT PRIMARY KEY, dept_name VARCHAR(120)) ENGINE=InnoDB',
    'CREATE TEMPORARY TABLE services (id INT PRIMARY KEY, dept_id INT, service_name VARCHAR(160), estimated_time_mins INT NOT NULL,
        CHECK (service_name <> "Reject update" OR estimated_time_mins <= 30)) ENGINE=InnoDB',
    "INSERT INTO departments VALUES (1, 'Test department'), (2, 'Other department')",
    "INSERT INTO services VALUES (101, 1, 'Test A', 30), (102, 1, 'Test B', 20), (103, 1, 'Already 45', 45), (104, 1, 'Reject update', 30), (201, 2, 'Other', 60)",
] as $query) {
    if (!$conn->query($query)) throw new RuntimeException('Fixture setup failed: ' . $conn->error);
}
$snapshot = static fn(): array => $conn->query('SELECT * FROM services ORDER BY id')->fetch_all(MYSQLI_ASSOC);
$times = static fn(): array => array_column($snapshot(), 'estimated_time_mins', 'id');
$before = $snapshot();
$preview = serviceTimePreview($conn, 1, ['103', '101'], '45');
timeCheck(count($preview['rows']) === 2 && $preview['changed'] === 1, 'Include selected unchanged services in preview');
timeCheck($snapshot() === $before, 'Preview never saves processing times');
timeCheck(serviceTimeApply($conn, $preview) === 1, 'Apply only changed selected services');
$saved = $times();
timeCheck((int)$saved[101] === 45 && (int)$saved[103] === 45, 'Save the exact previewed time');
timeCheck((int)$saved[102] === 20 && (int)$saved[201] === 60, 'Keep unselected and other department services unchanged');
timeRejects(fn() => serviceTimeApply($conn, $preview), 'Reject a replayed stale preview');
timeRejects(fn() => serviceTimePreview($conn, 1, [101, 103], 45), 'Reject a preview that changes nothing');
timeRejects(fn() => serviceTimePreview($conn, 999, [101], 50), 'Reject a missing department');
timeRejects(fn() => serviceTimePreview($conn, 1, [101, 201], 50), 'Reject service selection from another department');
timeRejects(fn() => serviceTimePreview($conn, 1, [999], 50), 'Reject missing services');

$preview = serviceTimePreview($conn, 1, [101, 102], 50);
$conn->query('UPDATE services SET estimated_time_mins = 40 WHERE id = 101');
$before = $snapshot();
timeRejects(fn() => serviceTimeApply($conn, $preview), 'Detect concurrent processing time edits');
timeCheck($snapshot() === $before, 'Stale preview saves no partial changes');

$preview = serviceTimePreview($conn, 1, [101, 102], 50);
$conn->query("UPDATE services SET service_name = 'Renamed' WHERE id = 101");
timeRejects(fn() => serviceTimeApply($conn, $preview), 'Detect renamed services after preview');

$preview = serviceTimePreview($conn, 1, [101, 102], 50);
$conn->query('UPDATE services SET dept_id = 2 WHERE id = 102');
timeRejects(fn() => serviceTimeApply($conn, $preview), 'Reject selected services moved to another department');

$preview = serviceTimePreview($conn, 1, [101, 103], 50);
$conn->query('DELETE FROM services WHERE id = 103');
timeRejects(fn() => serviceTimeApply($conn, $preview), 'Reject selected services removed after preview');

$preview = serviceTimePreview($conn, 1, [101, 104], 35);
$before = $snapshot();
timeRejects(fn() => serviceTimeApply($conn, $preview), 'Simulate a database failure after the first service update');
timeCheck($snapshot() === $before, 'Roll back the full batch on a partial failure');

$preview = serviceTimePreview($conn, 1, [101], 55);
$conn->query('UPDATE services SET estimated_time_mins = 25 WHERE id = 102');
timeCheck(serviceTimeApply($conn, $preview) === 1, 'Allow independent edits to unselected services');
$conn->close();
echo "PASS: $checks processing time checks, including preview, selection scope, stale edits, and rollback.\n";
