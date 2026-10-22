<?php
/**
 * Run: C:/xampp/php/php.exe tests/queue_report_test.php
 *
 * Integration fixtures exist only in connection-local TEMPORARY tables. These
 * shadow the application's tables without changing its real records or schema.
 */
declare(strict_types=1);

require_once __DIR__ . '/../dist/database/db.php';
require_once __DIR__ . '/../dist/includes/queue_report.php';

if (!$conn instanceof mysqli) {
    fwrite(STDERR, "Cannot connect to the configured database. Start MySQL or set DB_HOST/DB_USER/DB_PASS/DB_NAME.\n");
    exit(2);
}

$testCount = 0;
$assertionCount = 0;
$failures = [];

function reportAssertSame($expected, $actual, string $message): void
{
    global $assertionCount;
    $assertionCount++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . '; expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function reportAssert(bool $condition, string $message): void
{
    reportAssertSame(true, $condition, $message);
}

function reportTest(string $name, callable $test): void
{
    global $testCount, $failures;
    $testCount++;
    try {
        $test();
        echo "PASS: {$name}\n";
    } catch (Throwable $error) {
        $failures[] = $name . ': ' . $error->getMessage();
        echo "FAIL: {$name}\n";
    }
}

function reportData(array $input = []): array
{
    global $conn;
    return queueReportData($conn, queueReportFilters($input));
}

function reportIds(array $data): array
{
    return array_map('intval', array_column($data['rows'], 'id'));
}

function reportExportRows(array $input = []): array
{
    global $conn;
    return iterator_to_array((static function () use ($conn, $input): Generator {
        yield from queueReportExportRows($conn, queueReportFilters($input));
    })(), false);
}

function reportExportIds(array $input = []): array
{
    return array_map('intval', array_column(reportExportRows($input), 'id'));
}

function reportRowsById(array $data): array
{
    $rows = [];
    foreach ($data['rows'] as $row) {
        $rows[(int)$row['id']] = $row;
    }
    return $rows;
}

function reportInsert(string $sql, string $types, array $values): void
{
    global $conn;
    $statement = $conn->prepare($sql);
    if (!$statement) {
        throw new RuntimeException($conn->error);
    }
    try {
        $statement->bind_param($types, ...$values);
        if (!$statement->execute()) {
            throw new RuntimeException($statement->error);
        }
    } finally {
        $statement->close();
    }
}

function reportWithExtraPageRows(callable $test): void
{
    global $conn;
    try {
        // These rows exist only inside the connection-local temporary queue table.
        for ($id = 36; $id <= 45; $id++) {
            reportInsert('INSERT INTO queue (id, user_id, service_id, ticket_number, status, created_at, started_at, completed_at) VALUES (?, ?, ?, ?, ?, ?, NULL, NULL)', 'iiisss', [$id, 3, 3, sprintf('EXTRA-%03d', $id), 'completed', '2026-02-01 08:00:00']);
        }
        $test();
    } finally {
        if (!$conn->query('DELETE FROM queue WHERE id BETWEEN 36 AND 45')) {
            throw new RuntimeException($conn->error);
        }
    }
}

try {
    if (!$conn->set_charset('utf8mb4')) {
        throw new RuntimeException($conn->error);
    }
    $definitions = [
        'departments' => 'id INT PRIMARY KEY, dept_name VARCHAR(120)',
        'users' => 'id INT PRIMARY KEY, fullname VARCHAR(150)',
        'services' => 'id INT PRIMARY KEY, dept_id INT, service_name VARCHAR(120)',
        'queue' => 'id INT PRIMARY KEY, user_id INT, service_id INT, ticket_number VARCHAR(80), status VARCHAR(20), created_at DATETIME, started_at DATETIME NULL, completed_at DATETIME NULL',
        'service_fees' => 'id INT PRIMARY KEY, service_id INT UNIQUE, amount DECIMAL(10,2)',
        'queue_payments' => 'id INT PRIMARY KEY, queue_id INT UNIQUE, amount DECIMAL(10,2), status VARCHAR(20), paid_at DATETIME NULL',
    ];
    foreach ($definitions as $table => $definition) {
        if (!$conn->query("CREATE TEMPORARY TABLE `{$table}` ({$definition}) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
            throw new RuntimeException('Could not create temporary fixture table: ' . $conn->error);
        }
    }

    foreach ([[1, 'Admissions'], [2, 'Registrar']] as $department) {
        reportInsert('INSERT INTO departments (id, dept_name) VALUES (?, ?)', 'is', $department);
    }
    foreach ([[1, "Alice O'Brien"], [2, 'Customer 100%_Done!'], [3, 'Ordinary Customer']] as $user) {
        reportInsert('INSERT INTO users (id, fullname) VALUES (?, ?)', 'is', $user);
    }
    foreach ([[1, 1, 'Alpha Clearance'], [2, 1, 'Beta Enrollment'], [3, 2, 'Gamma Transcript'], [4, 2, 'Zero Fee Service']] as $service) {
        reportInsert('INSERT INTO services (id, dept_id, service_name) VALUES (?, ?, ?)', 'iis', $service);
    }
    foreach ([[1, 1, 999], [2, 2, 200], [3, 3, 50]] as $fee) {
        reportInsert('INSERT INTO service_fees (id, service_id, amount) VALUES (?, ?, ?)', 'iid', $fee);
    }

    $queues = [
        [1, 1, 1, 'ADM-001', 'waiting', '2026-01-01 00:00:00'],
        [2, 2, 1, 'LITERAL_%!', 'called', '2026-01-01 23:59:59'],
        [3, 3, 2, 'ADM-003', 'now_serving', '2026-01-02 00:00:00'],
        [4, 1, 2, 'ADM-004', 'completed', '2026-01-02 23:59:59'],
        [5, 3, 3, 'REG-005', 'cancelled', '2026-01-03 00:00:00'],
        [6, 2, 3, 'REG-006', 'no_show', '2025-12-31 23:59:59'],
        [7, 3, 1, 'ADM-007', 'completed', '2026-01-01 12:00:00'],
        [8, 1, 1, 'ADM-008', 'waiting', '2026-01-01 12:01:00'],
        [9, 3, 4, 'REG-009', 'waiting', '2026-01-01 12:02:00'],
    ];
    for ($id = 10; $id <= 35; $id++) {
        $queues[] = [$id, 3, 3, sprintf('BULK-%03d', $id), 'completed', '2026-01-10 08:00:00'];
    }
    foreach ($queues as $queue) {
        reportInsert('INSERT INTO queue (id, user_id, service_id, ticket_number, status, created_at, started_at, completed_at) VALUES (?, ?, ?, ?, ?, ?, NULL, NULL)', 'iiisss', $queue);
    }
    $payments = [
        [1, 1, 100, 'paid', '2026-01-01 00:01:00'],
        [2, 2, 0, 'pending', null],
        [3, 4, 250, 'paid', '2026-01-03 10:00:00'],
        [4, 5, 50, 'pending', null],
        [5, 6, 75, 'paid', '2026-01-01 10:00:00'],
        [6, 8, 0, 'paid', '2026-01-01 12:01:01'],
    ];
    for ($id = 10; $id <= 35; $id++) {
        $payments[] = [$id, $id, 10, 'paid', '2026-01-10 08:01:00'];
    }
    foreach ($payments as $payment) {
        reportInsert('INSERT INTO queue_payments (id, queue_id, amount, status, paid_at) VALUES (?, ?, ?, ?, ?)', 'iidss', $payment);
    }

    reportTest('filters normalize defaults and valid values', static function (): void {
        $filters = queueReportFilters([]);
        reportAssertSame([], $filters['errors'], 'Empty input is valid');
        reportAssertSame(0, $filters['department_id'], 'No department constraint by default');
        reportAssertSame('newest', $filters['sort'], 'Default sort');
        reportAssertSame(1, $filters['page'], 'Default page');
        $filters = queueReportFilters(['search' => '  Alice  ', 'department_id' => '1', 'service_id' => '2', 'status' => 'completed', 'payment_status' => 'paid', 'date_from' => '2026-01-01', 'date_to' => '2026-01-02', 'sort' => 'oldest', 'page' => '2']);
        reportAssertSame([], $filters['errors'], 'Valid input has no errors');
        reportAssertSame('Alice', $filters['search'], 'Search is trimmed');
        reportAssertSame(1, $filters['department_id'], 'Department is an integer');
        reportAssertSame(2, $filters['service_id'], 'Service is an integer');
        reportAssertSame(2, $filters['page'], 'Page is an integer');
        reportAssertSame(120, mb_strlen(queueReportFilters(['search' => str_repeat('ñ', 140)])['search']), 'Search length is limited by characters');
    });

    reportTest('invalid filters are rejected safely', static function (): void {
        foreach ([['department_id' => '-1'], ['service_id' => '1 OR 1=1'], ['status' => 'unknown'], ['payment_status' => 'refunded'], ['date_from' => '2026-02-30'], ['date_to' => '2026-1-1'], ['date_from' => '0999-12-31'], ['date_from' => "2026-01-\x0001"], ['date_to' => "2026-01-\x0001"], ['date_from' => str_repeat('2026-', 10000)], ['date_to' => str_repeat('2026-', 10000)], ['date_from' => '2026-01-02', 'date_to' => '2026-01-01']] as $input) {
            $filters = queueReportFilters($input);
            reportAssert(count($filters['errors']) > 0, 'Invalid input reports validation errors');
            $data = reportData($input);
            reportAssertSame([], $data['rows'], 'Invalid input does not expose unfiltered transactions');
            reportAssertSame(0, (int)$data['summary']['total_count'], 'Invalid input has an empty summary');
        }
        $filters = queueReportFilters(['search' => ['bad'], 'department_id' => ['bad'], 'status' => ['bad'], 'date_from' => ['bad'], 'sort' => 'q.id; DROP TABLE queue', 'page' => '-5']);
        reportAssertSame('', $filters['search'], 'Array search is safely ignored');
        reportAssertSame(0, $filters['department_id'], 'Array ID is safely ignored');
        reportAssertSame('newest', $filters['sort'], 'Unrecognized sort uses the allowlisted default');
        reportAssertSame(1, $filters['page'], 'Negative page uses page one');
        reportAssertSame(1, queueReportFilters(['page' => str_repeat('9', 100)])['page'], 'Overflowing page uses page one');
    });

    reportTest('summary includes every queue status and stored payment totals', static function (): void {
        $data = reportData();
        $summary = $data['summary'];
        foreach (['total_count' => 35, 'completed_count' => 28, 'waiting_count' => 3, 'serving_count' => 2, 'cancelled_count' => 1, 'no_show_count' => 1, 'paid_count' => 30, 'pending_count' => 2, 'unrecorded_count' => 3] as $key => $expected) {
            reportAssertSame($expected, (int)$summary[$key], $key);
        }
        reportAssertSame(685.0, (float)$summary['total_income'], 'Income uses recorded paid amounts');
        reportAssertSame(50.0, (float)$summary['pending_amount'], 'Pending total includes recorded pending amounts only');
        reportAssertSame(2, count($data['departments']), 'Department options are loaded');
        reportAssertSame(4, count($data['services']), 'Service options are loaded');
        $counts = array_column($data['department_counts'], 'ticket_count', 'dept_name');
        reportAssertSame(29, (int)$counts['Registrar'], 'Registrar department count');
        reportAssertSame(6, (int)$counts['Admissions'], 'Admissions department count');
    });

    reportTest('each queue status can be filtered independently', static function (): void {
        foreach (['waiting' => 3, 'called' => 1, 'now_serving' => 1, 'completed' => 28, 'cancelled' => 1, 'no_show' => 1] as $status => $count) {
            $data = reportData(['status' => $status]);
            reportAssertSame($count, (int)$data['summary']['total_count'], $status . ' total');
            reportAssertSame([$status], array_values(array_unique(array_column($data['rows'], 'status'))), $status . ' rows');
        }
    });

    reportTest('payment rows preserve stored fees including zero and missing records', static function (): void {
        $rows = reportRowsById(reportData(['date_to' => '2026-01-03']));
        reportAssertSame(100.0, (float)$rows[1]['amount'], 'Stored amount survives a changed current service fee');
        reportAssertSame('paid', $rows[1]['payment_status'], 'Recorded payment status');
        reportAssertSame('2026-01-01 00:01:00', $rows[1]['paid_at'], 'Payment timestamp is available');
        reportAssertSame(0.0, (float)$rows[2]['amount'], 'A recorded zero pending amount stays zero');
        reportAssertSame(0.0, (float)$rows[8]['amount'], 'A recorded zero paid amount stays zero');
        reportAssertSame(200.0, (float)$rows[3]['amount'], 'Missing payment falls back to current service fee');
        reportAssertSame(null, $rows[3]['payment_id'], 'Missing payment is identifiable');
        reportAssertSame('pending', $rows[3]['payment_status'], 'Missing payment uses pending status');
        reportAssertSame(null, $rows[3]['paid_at'], 'Missing payment has no payment date');
        reportAssertSame(999.0, (float)$rows[7]['amount'], 'Second missing payment falls back to its own service fee');
        reportAssertSame(0.0, (float)$rows[9]['amount'], 'Missing payment and service fee have a zero display amount');
        reportAssertSame('Alpha Clearance', $rows[1]['service_name'], 'Selected service is included');
        reportAssertSame('Admissions', $rows[1]['dept_name'], 'Department is included');
        reportAssertSame("Alice O'Brien", $rows[1]['fullname'], 'Customer is included');
    });

    reportTest('date range includes both whole-day boundaries and uses queue creation date', static function (): void {
        $dayOne = reportData(['date_from' => '2026-01-01', 'date_to' => '2026-01-01', 'sort' => 'oldest']);
        reportAssertSame([1, 7, 8, 9, 2], reportIds($dayOne), 'Midnight and 23:59:59 are included; adjacent dates are excluded');
        $dayTwo = reportData(['date_from' => '2026-01-02', 'date_to' => '2026-01-02', 'sort' => 'oldest']);
        reportAssertSame([3, 4], reportIds($dayTwo), 'Creation date determines inclusion even if paid on another day');
        reportAssertSame([6], reportIds(reportData(['date_to' => '2025-12-31'])), 'Upper date may be used alone');
        reportAssertSame(27, (int)reportData(['date_from' => '2026-01-03'])['summary']['total_count'], 'Lower date may be used alone');
    });

    reportTest('department, service, status, payment, search and date filters combine', static function (): void {
        $filters = ['department_id' => '1', 'service_id' => '1', 'status' => 'waiting', 'payment_status' => 'paid', 'search' => " O'Brien ", 'date_from' => '2026-01-01', 'date_to' => '2026-01-01', 'sort' => 'oldest'];
        $data = reportData($filters);
        reportAssertSame([1, 8], reportIds($data), 'All selected constraints apply together');
        reportAssertSame(2, (int)$data['summary']['paid_count'], 'Filtered summary matches rows');
        reportAssertSame(100.0, (float)$data['summary']['total_income'], 'Filtered income uses matching payments');
        $filters['department_id'] = '2';
        reportAssertSame([], reportData($filters)['rows'], 'A mismatched department and service yield no records');
        $pending = reportData(['payment_status' => 'pending', 'sort' => 'oldest']);
        reportAssertSame([7, 9, 2, 3, 5], reportIds($pending), 'Pending filter includes explicit pending and unrecorded payments');
        reportAssertSame(2, (int)$pending['summary']['pending_count'], 'Recorded pending count remains distinct');
        reportAssertSame(3, (int)$pending['summary']['unrecorded_count'], 'Unrecorded count remains visible');
    });

    reportTest('search handles literal wildcard characters, apostrophes and every searchable field', static function (): void {
        foreach (['%' => [6, 2], '_' => [6, 2], '!' => [6, 2], '100%_Done!' => [6, 2], 'LITERAL_%!' => [2], "O'Brien" => [1, 8, 4], "' OR 1=1 --" => []] as $search => $expectedIds) {
            reportAssertSame($expectedIds, reportIds(reportData(['search' => $search, 'sort' => 'oldest'])), 'Literal customer/ticket search: ' . $search);
        }
        reportAssertSame([1, 7, 8, 2], reportIds(reportData(['search' => 'Alpha Clearance', 'sort' => 'oldest'])), 'Search includes selected service');
        reportAssertSame(6, (int)reportData(['search' => 'Admissions'])['summary']['total_count'], 'Search includes department');
        reportAssertSame([5], reportIds(reportData(['search' => 'REG-005'])), 'Search includes ticket number');
    });

    reportTest('pagination uses 35 rows, preserves totals and clamps out-of-range pages', static function (): void {
        reportAssertSame(1, reportData()['pages'], 'Exactly 35 records fit on one page');
        reportWithExtraPageRows(static function (): void {
            $first = reportData();
            $second = reportData(['page' => '2']);
            reportAssertSame(35, count($first['rows']), 'First page has 35 rows');
            reportAssertSame(10, count($second['rows']), 'Second page has remaining rows');
            reportAssertSame(2, $first['pages'], 'Total page count');
            reportAssertSame(2, $second['page'], 'Selected second page');
            reportAssertSame(45, (int)$second['summary']['total_count'], 'Summary covers all matching rows');
            reportAssertSame([], array_values(array_intersect(reportIds($first), reportIds($second))), 'Pages do not overlap');
            reportAssertSame(45, count(array_unique(array_merge(reportIds($first), reportIds($second)))), 'Pages cover all matching records');
            $last = reportData(['page' => '999999']);
            reportAssertSame(2, $last['page'], 'Page beyond last is clamped');
            reportAssertSame(reportIds($second), reportIds($last), 'Clamped page returns last-page records');
            reportAssertSame(reportIds($first), reportIds(reportData(['page' => 'invalid'])), 'Invalid page returns first page');
        });
    });

    reportTest('all sort choices give deterministic order', static function (): void {
        $expected = [
            'newest' => array_merge(range(35, 10), [5, 4, 3, 2, 9, 8, 7, 1, 6]),
            'oldest' => array_merge([6, 1, 7, 8, 9, 2, 3, 4, 5], range(10, 35)),
            'service' => array_merge([8, 7, 2, 1, 4, 3], range(35, 10), [6, 5, 9]),
            'status' => array_merge([2, 5], range(35, 10), [7, 4, 6, 3, 9, 8, 1]),
            'amount_high' => array_merge([7, 4, 3, 1, 6, 5], range(35, 10), [9, 8, 2]),
            'amount_low' => array_merge([9, 8, 2], range(35, 10), [5, 6, 1, 3, 4, 7]),
        ];
        foreach ($expected as $sort => $ids) {
            $actual = reportIds(reportData(['sort' => $sort]));
            reportAssertSame($ids, $actual, $sort . ' order and tie breaker');
        }
    });

    reportTest('Excel query exports all matching records regardless of page', static function (): void {
        reportWithExtraPageRows(static function (): void {
            $expectedIds = array_merge(range(45, 10), [5, 4, 3, 2, 9, 8, 7, 1, 6]);
            reportAssertSame(35, count(reportData()['rows']), 'Screen remains paginated');
            reportAssertSame($expectedIds, reportExportIds(), 'Export contains all 45 records in order');
            reportAssertSame($expectedIds, reportExportIds(['page' => '2']), 'Second screen page does not limit the export');
            reportAssertSame($expectedIds, reportExportIds(['page' => '999999']), 'Out-of-range page does not limit the export');
            $rows = array_column(reportExportRows(['date_to' => '2026-01-03']), null, 'id');
            reportAssertSame('Alpha Clearance', $rows[1]['service_name'], 'Selected service is exported');
            reportAssertSame('2026-01-01 00:00:00', $rows[1]['created_at'], 'Queue date is exported');
            reportAssertSame('waiting', $rows[1]['status'], 'Queue status is exported');
            reportAssertSame(100.0, (float)$rows[1]['amount'], 'Stored payment amount is exported');
            reportAssertSame(0.0, (float)$rows[8]['amount'], 'Zero payment is preserved');
            reportAssertSame(null, $rows[3]['payment_id'], 'Unrecorded payment remains identifiable');
            reportAssertSame(200.0, (float)$rows[3]['amount'], 'Missing payment uses the same fee fallback as the screen');
        });
    });

    reportTest('Excel query shares every report filter and literal search behavior', static function (): void {
        $cases = [
            [['department_id' => '1'], [1, 7, 8, 2, 3, 4]],
            [['service_id' => '2'], [3, 4]],
            [['status' => 'waiting'], [1, 8, 9]],
            [['payment_status' => 'pending'], [7, 9, 2, 3, 5]],
            [['date_from' => '2026-01-01', 'date_to' => '2026-01-01'], [1, 7, 8, 9, 2]],
            [['date_to' => '2025-12-31'], [6]],
            [['search' => '100%_Done!'], [6, 2]],
            [['search' => 'Alpha Clearance'], [1, 7, 8, 2]],
            [['search' => "' OR 1=1 --"], []],
            [['search' => " O'Brien ", 'department_id' => '1', 'service_id' => '1', 'status' => 'waiting', 'payment_status' => 'paid', 'date_from' => '2026-01-01', 'date_to' => '2026-01-01'], [1, 8]],
            [['department_id' => '2', 'service_id' => '1'], []],
        ];
        foreach ($cases as [$input, $expectedIds]) {
            $input['sort'] = 'oldest';
            $input['page'] = '2';
            reportAssertSame($expectedIds, reportExportIds($input), 'Export constraints: ' . json_encode($input));
        }
        foreach (['waiting', 'called', 'now_serving', 'completed', 'cancelled', 'no_show'] as $status) {
            $rows = reportExportRows(['status' => $status]);
            reportAssertSame([$status], array_values(array_unique(array_column($rows, 'status'))), 'Export supports queue status ' . $status);
            reportAssertSame((int)reportData(['status' => $status])['summary']['total_count'], count($rows), 'Export includes every matching ' . $status . ' queue');
        }
        reportAssertSame(30, count(reportExportRows(['payment_status' => 'paid'])), 'Paid export includes all matching records');
    });

    reportTest('Excel query shares all deterministic sort choices', static function (): void {
        $expected = [
            'newest' => array_merge(range(35, 10), [5, 4, 3, 2, 9, 8, 7, 1, 6]),
            'oldest' => array_merge([6, 1, 7, 8, 9, 2, 3, 4, 5], range(10, 35)),
            'service' => array_merge([8, 7, 2, 1, 4, 3], range(35, 10), [6, 5, 9]),
            'status' => array_merge([2, 5], range(35, 10), [7, 4, 6, 3, 9, 8, 1]),
            'amount_high' => array_merge([7, 4, 3, 1, 6, 5], range(35, 10), [9, 8, 2]),
            'amount_low' => array_merge([9, 8, 2], range(35, 10), [5, 6, 1, 3, 4, 7]),
        ];
        foreach ($expected as $sort => $ids) {
            reportAssertSame($ids, reportExportIds(['sort' => $sort]), $sort . ' export order and tie breaker');
        }
    });

    reportTest('Excel query rejects invalid filters instead of exporting unfiltered records', static function (): void {
        foreach ([['department_id' => '-1'], ['service_id' => '1 OR 1=1'], ['status' => 'unknown'], ['payment_status' => 'refunded'], ['date_from' => '2026-02-30'], ['date_to' => "2026-01-\x0001"], ['date_from' => '2026-01-02', 'date_to' => '2026-01-01']] as $input) {
            $caught = false;
            try {
                reportExportRows($input);
            } catch (InvalidArgumentException $error) {
                $caught = true;
            }
            reportAssert($caught, 'Export rejects invalid constraints: ' . json_encode($input));
        }
    });

    reportTest('no matching rows produce a stable empty report', static function (): void {
        $data = reportData(['search' => 'does-not-exist', 'page' => '7']);
        reportAssertSame([], $data['rows'], 'Empty result rows');
        reportAssertSame([], $data['department_counts'], 'Empty department breakdown');
        reportAssertSame(1, $data['pages'], 'Empty report has one page');
        reportAssertSame(1, $data['page'], 'Empty report returns page one');
        foreach ($data['summary'] as $key => $value) {
            reportAssertSame(0.0, (float)$value, 'Empty summary ' . $key);
        }
        reportAssertSame(2, count($data['departments']), 'Filters remain available for an empty result');
    });

    reportTest('query wrapper binds values and surfaces SQL errors', static function (): void {
        global $conn;
        $rows = queueReportQuery($conn, 'SELECT id FROM users WHERE fullname = ?', 's', ["Alice O'Brien"]);
        reportAssertSame([1], array_map('intval', array_column($rows, 'id')), 'Prepared value handles an apostrophe');
        $threw = false;
        try {
            queueReportQuery($conn, 'SELECT definitely_missing_column FROM queue');
        } catch (Throwable $error) {
            $threw = true;
        }
        reportAssert($threw, 'Invalid query raises an error for the caller');
    });
} catch (Throwable $error) {
    fwrite(STDERR, 'Fixture setup failed: ' . $error->getMessage() . "\n");
    $conn->close();
    exit(2);
}

// Closing this connection also removes every fixture table and fixture record.
$conn->close();
foreach ($failures as $failure) {
    fwrite(STDERR, $failure . "\n");
}
echo sprintf("\n%d tests, %d assertions, %d failures.\n", $testCount, $assertionCount, count($failures));
exit($failures ? 1 : 0);
