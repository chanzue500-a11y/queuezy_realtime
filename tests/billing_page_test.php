<?php
// Run each controller scenario in its own process because successful POSTs redirect and exit.
require_once __DIR__ . '/../dist/database/db.php';
require_once __DIR__ . '/../dist/includes/billing_fees.php';
if (!$conn) exit("Database unavailable.\n");
set_error_handler(static function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});

// All writes stay in connection-local temporary tables, including billing.php's starter fees.
foreach ([
    'CREATE TEMPORARY TABLE departments (id INT PRIMARY KEY, dept_name VARCHAR(120)) ENGINE=InnoDB',
    'CREATE TEMPORARY TABLE services (id INT PRIMARY KEY, dept_id INT, service_name VARCHAR(160)) ENGINE=InnoDB',
    'CREATE TEMPORARY TABLE service_fees (id INT AUTO_INCREMENT PRIMARY KEY, service_id INT UNIQUE, amount DECIMAL(10,2), updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB',
    "INSERT INTO departments VALUES (1, 'Test & department'), (2, 'Other')",
    "INSERT INTO services VALUES (101, 1, 'Test <service>'), (102, 1, 'Second'), (201, 2, 'Other service')",
    'INSERT INTO service_fees (service_id, amount) VALUES (101, 100.00), (102, 200.00), (201, 300.00)',
] as $query) {
    if (!$conn->query($query)) throw new RuntimeException($conn->error);
}

session_id('billing-test-' . bin2hex(random_bytes(8)));
session_start(['use_cookies' => false, 'cache_limiter' => '', 'save_path' => sys_get_temp_dir()]);
$_SESSION = ['user_id' => 0, 'user_role' => 'admin', 'user_name' => 'Test admin', 'billing_csrf' => 'test-csrf'];
$_SERVER['PHP_SELF'] = 'billing.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['action' => 'preview_bulk', 'csrf_token' => 'test-csrf', 'department_id' => '1',
    'direction' => 'increase', 'unit' => 'amount', 'adjustment' => '10.00'];
$scenario = $argv[1] ?? 'render';
if (in_array($scenario, ['render', 'apply', 'cancel', 'stale', 'bad-preview-token'], true)) {
    $_SESSION['billing_bulk_preview'] = billingBulkPreview($conn, 1, 'increase', 'amount', 1000);
    $_POST = ['action' => 'apply_bulk', 'csrf_token' => 'test-csrf', 'preview_token' => $_SESSION['billing_bulk_preview']['token']];
}
switch ($scenario) {
    case 'render': $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; break;
    case 'preview': break;
    case 'apply': break;
    case 'cancel': $_POST['action'] = 'cancel_bulk'; break;
    case 'bad-csrf': $_POST['csrf_token'] = 'invalid'; break;
    case 'bad-preview-token': $_POST['preview_token'] = 'invalid'; break;
    case 'stale': $conn->query('UPDATE service_fees SET amount = 150.00 WHERE service_id = 101'); break;
    case 'negative': $_POST['direction'] = 'decrease'; $_POST['adjustment'] = '150.00'; break;
    case 'save': $_POST = ['action' => 'save_fee', 'csrf_token' => 'test-csrf', 'service_id' => '101', 'amount' => '125.50']; break;
    default: throw new InvalidArgumentException('Unknown test scenario');
}

ob_start();
register_shutdown_function(static function () use ($conn, $scenario): void {
    $html = ob_get_clean();
    $expect = static function (bool $condition, string $message): void {
        if (!$condition) throw new RuntimeException($message);
    };
    try {
        $error = error_get_last();
        $expect(!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR], true), 'PHP rendering failed');
        $fees = array_column($conn->query('SELECT service_id, amount FROM service_fees')->fetch_all(MYSQLI_ASSOC), 'amount', 'service_id');
        $expect($fees[201] === '300.00', 'Other department was changed');
        if ($scenario === 'apply') {
            $expect($fees[101] === '110.00' && $fees[102] === '210.00', 'Apply did not save previewed fees');
            $expect(!isset($_SESSION['billing_bulk_preview']) && isset($_SESSION['billing_success']), 'Apply did not consume preview');
        } elseif ($scenario === 'save') {
            $expect($fees[101] === '125.50' && $fees[102] === '200.00', 'Individual fee save regressed');
        } else {
            $expect($fees[101] === ($scenario === 'stale' ? '150.00' : '100.00') && $fees[102] === '200.00', 'Unexpected fee write');
        }
        if ($scenario === 'preview') {
            $expect(($_SESSION['billing_bulk_preview']['changed'] ?? 0) === 2, 'Preview was not prepared');
        } elseif ($scenario === 'cancel') {
            $expect(!isset($_SESSION['billing_bulk_preview']), 'Cancel kept pending preview');
        } elseif ($scenario === 'render') {
            $expect(str_contains($html, 'Bulk fee update') && str_contains($html, 'Review changes') && str_contains($html, 'Apply changes'), 'Bulk workflow was not rendered');
            $expect(str_contains($html, 'Test &lt;service&gt;') && str_contains($html, 'Test &amp; department'), 'Service or department text was not escaped');
            $expect(!str_contains($html, 'Configured fees') && !str_contains($html, 'Total listed fees') && !str_contains($html, 'Total income'), 'Old summary cards were rendered');
            $document = new DOMDocument();
            @$document->loadHTML($html);
            $xpath = new DOMXPath($document);
            $expect($xpath->query('//form[not(.//input[@name="csrf_token"])]')->length === 0, 'Billing form missing CSRF protection');
            $expect($xpath->query('//*[@id="bulk-preview"]//tbody/tr')->length === 2, 'Preview does not show all affected services');
        } elseif (in_array($scenario, ['bad-csrf', 'bad-preview-token', 'stale', 'negative'], true)) {
            $expect(str_contains($html, 'role="alert"'), 'Invalid submission did not show an error');
            $expect(!isset($_SESSION['billing_bulk_preview']), 'Rejected submission kept an actionable preview');
        }
        fwrite(STDOUT, "PASS: billing page $scenario.\n");
    } catch (Throwable $failure) {
        fwrite(STDERR, "FAIL: billing page $scenario: " . $failure->getMessage() . "\n");
        session_destroy();
        exit(1);
    }
    session_destroy();
});
chdir(__DIR__ . '/../dist/admin');
require 'billing.php';
