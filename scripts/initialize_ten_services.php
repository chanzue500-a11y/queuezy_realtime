<?php
// One-time catalog cleanup. Never run this automatically from an application page.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../dist/database/db.php';
require_once __DIR__ . '/../dist/includes/service_catalog.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (!$conn) throw new RuntimeException('Database unavailable.');
$apply = in_array('--apply', $argv, true);
$snapshot = static function () use ($conn): array {
    $result = [];
    foreach (['queue', 'queue_payments', 'queue_ratings'] as $table) {
        $result[$table] = $conn->query("SELECT * FROM `$table` ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    }
    return $result;
};
$conn->begin_transaction();
try {
    $departments = $conn->query('SELECT * FROM departments ORDER BY id FOR UPDATE')->fetch_all(MYSQLI_ASSOC);
    $services = $conn->query('SELECT * FROM services ORDER BY id FOR UPDATE')->fetch_all(MYSQLI_ASSOC);
    $queue = $conn->query('SELECT service_id FROM queue FOR UPDATE')->fetch_all(MYSQLI_ASSOC);
    $used = array_fill_keys(array_column($queue, 'service_id'), true);
    $catalog = [];
    foreach (defaultServiceCatalog() as [$name, $code, $names]) $catalog[$code] = $names;
    $plan = [];
    foreach ($departments as $department) {
        $rows = array_values(array_filter($services, static fn($row) => $row['dept_id'] == $department['id']));
        $keep = array_values(array_filter($rows, static fn($row) => isset($used[$row['id']])));
        if (count($keep) > 10) throw new RuntimeException('More than ten used services in ' . $department['dept_name']);
        $keptIds = array_fill_keys(array_column($keep, 'id'), true);
        foreach ($rows as $row) {
            if (count($keep) >= 10) break;
            if (!isset($keptIds[$row['id']])) {
                $keep[] = $row;
                $keptIds[$row['id']] = true;
            }
        }
        $remove = array_values(array_filter($rows, static fn($row) => !isset($keptIds[$row['id']])));
        $add = [];
        $names = array_column($keep, 'service_name');
        foreach ($catalog[$department['dept_code']] ?? [] as $name) {
            if (count($keep) + count($add) >= 10) break;
            if (!in_array($name, $names, true)) $add[] = $name;
        }
        if (count($keep) + count($add) !== 10) throw new RuntimeException('Not enough starter names for ' . $department['dept_name']);
        $plan[] = ['department' => $department, 'remove' => $remove, 'add' => $add];
        echo $department['dept_code'] . ': ' . count($rows) . ' -> 10; remove ' . count($remove) . ', add ' . count($add) . PHP_EOL;
    }
    if (!$apply) {
        $conn->rollback();
        echo "Preview only. Use --apply to perform this one-time reset.\n";
        exit;
    }
    $before = $snapshot();
    $backup = tempnam(sys_get_temp_dir(), 'queuezy-services-');
    if ($backup === false || file_put_contents($backup, json_encode([
        'created_at' => date(DATE_ATOM), 'services' => $services,
        'service_fees' => $conn->query('SELECT * FROM service_fees ORDER BY id')->fetch_all(MYSQLI_ASSOC),
        'plan' => $plan,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('Backup failed.');
    $deleteFee = $conn->prepare('DELETE FROM service_fees WHERE service_id = ?');
    $delete = $conn->prepare('DELETE FROM services WHERE id = ? AND NOT EXISTS (SELECT 1 FROM queue WHERE service_id = ?)');
    $insert = $conn->prepare('INSERT INTO services (dept_id, service_name, estimated_time_mins) VALUES (?, ?, 30)');
    $fee = $conn->prepare('INSERT INTO service_fees (service_id, amount) VALUES (?, 0.00)');
    foreach ($plan as $change) {
        foreach ($change['remove'] as $row) {
            $id = (int)$row['id'];
            $deleteFee->bind_param('i', $id);
            $deleteFee->execute();
            $delete->bind_param('ii', $id, $id);
            $delete->execute();
            if ($delete->affected_rows !== 1) throw new RuntimeException('Service was not safely removed.');
        }
        $departmentId = (int)$change['department']['id'];
        foreach ($change['add'] as $name) {
            $insert->bind_param('is', $departmentId, $name);
            $insert->execute();
            $id = $conn->insert_id;
            $fee->bind_param('i', $id);
            $fee->execute();
        }
        $count = $conn->query('SELECT COUNT(*) AS total FROM services WHERE dept_id = ' . $departmentId)->fetch_assoc();
        if ((int)$count['total'] !== 10) throw new RuntimeException('Department count verification failed.');
    }
    if ($snapshot() !== $before) throw new RuntimeException('Transaction history changed; rolling back.');
    $conn->commit();
    echo "Applied. Transaction history unchanged. Service/fee backup: $backup\n";
} catch (Throwable $error) {
    $conn->rollback();
    throw $error;
}
