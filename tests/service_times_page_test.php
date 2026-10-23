<?php
require_once __DIR__ . '/../dist/database/db.php';
require_once __DIR__ . '/../dist/includes/service_times.php';
if (!$conn) {
    fwrite(STDERR, "Database unavailable.\n");
    exit(1);
}
set_error_handler(static function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});

// Page bootstrap and controller writes stay in connection-local temporary tables.
foreach ([
    'CREATE TEMPORARY TABLE departments (id INT AUTO_INCREMENT PRIMARY KEY, dept_name VARCHAR(120) UNIQUE, dept_code VARCHAR(10) UNIQUE,
        status VARCHAR(20) DEFAULT "active") ENGINE=InnoDB',
    'CREATE TEMPORARY TABLE services (id INT AUTO_INCREMENT PRIMARY KEY, dept_id INT, service_name VARCHAR(160),
        estimated_time_mins INT NOT NULL, UNIQUE KEY (dept_id, service_name)) ENGINE=InnoDB',
    'CREATE TEMPORARY TABLE service_fees (id INT PRIMARY KEY, service_id INT, amount DECIMAL(10,2), updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB',
    'CREATE TEMPORARY TABLE queue (id INT PRIMARY KEY, service_id INT, status VARCHAR(20), ticket_number VARCHAR(30)) ENGINE=InnoDB',
    'CREATE TEMPORARY TABLE users (id INT PRIMARY KEY, role VARCHAR(20), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB',
    "INSERT INTO departments (id, dept_name, dept_code) VALUES (1, 'Test & department', 'TEST'), (2, 'Other', 'OTHER')",
    "INSERT INTO services VALUES (101, 1, 'Test <service>', 30), (102, 1, 'Second service', 20), (201, 2, 'Other service', 60)",
] as $query) {
    if (!$conn->query($query)) throw new RuntimeException('Fixture setup failed: ' . $conn->error);
}

session_id('service-time-test-' . bin2hex(random_bytes(8)));
session_start(['use_cookies' => false, 'cache_limiter' => '', 'save_path' => sys_get_temp_dir()]);
$_SESSION = ['user_id' => 0, 'user_role' => 'admin', 'user_name' => 'Test admin', 'service_time_csrf' => 'test-csrf'];
$_SERVER['PHP_SELF'] = 'services.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['action' => 'preview_times', 'csrf_token' => 'test-csrf', 'department_id' => '1', 'service_ids' => ['101', '102'], 'minutes' => '45'];
$scenario = $argv[1] ?? 'render';
if (in_array($scenario, ['render-preview', 'apply', 'cancel', 'stale', 'bad-preview-token'], true)) {
    $_SESSION['service_bulk_time_preview'] = serviceTimePreview($conn, 1, [101, 102], 45);
    $_POST = ['action' => 'apply_times', 'csrf_token' => 'test-csrf', 'preview_token' => $_SESSION['service_bulk_time_preview']['token'],
        'department_id' => '2', 'service_ids' => ['201'], 'minutes' => '120'];
}
switch ($scenario) {
    case 'render':
    case 'render-preview': $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; break;
    case 'preview': break;
    case 'apply': break;
    case 'cancel': $_POST['action'] = 'cancel_times'; break;
    case 'stale': $conn->query('UPDATE services SET estimated_time_mins = 40 WHERE id = 101'); break;
    case 'bad-preview-token': $_POST['preview_token'] = 'invalid'; break;
    case 'bad-csrf': $_POST['csrf_token'] = 'invalid'; break;
    case 'invalid-minutes': $_POST['minutes'] = '1.5'; break;
    case 'no-selection': unset($_POST['service_ids']); break;
    case 'foreign-service': $_POST['service_ids'][] = '201'; break;
    case 'single-update':
    case 'single-bad-csrf':
        $_POST = ['action' => 'update_time', 'csrf_token' => $scenario === 'single-update' ? 'test-csrf' : 'invalid',
            'service_id' => '101', 'estimated_time_mins' => '55'];
        break;
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
        $expect(!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR], true), 'PHP page failed');
        $times = array_column($conn->query('SELECT id, estimated_time_mins FROM services WHERE id IN (101,102,201)')->fetch_all(MYSQLI_ASSOC), 'estimated_time_mins', 'id');
        $expect((int)$times[201] === 60, 'Other department was changed');
        if ($scenario === 'apply') {
            $expect((int)$times[101] === 45 && (int)$times[102] === 45, 'Apply did not use server-side preview values');
            $expect(!isset($_SESSION['service_bulk_time_preview']) && isset($_SESSION['service_time_success']), 'Apply did not consume the preview');
        } else {
            $expect((int)$times[101] === ($scenario === 'stale' ? 40 : 30) && (int)$times[102] === 20, 'Unexpected processing time write');
        }
        if ($scenario === 'preview') {
            $expect(($_SESSION['service_bulk_time_preview']['changed'] ?? 0) === 2, 'Preview not prepared');
        } elseif ($scenario === 'cancel') {
            $expect(!isset($_SESSION['service_bulk_time_preview']), 'Cancel left an actionable preview');
        } elseif (str_starts_with($scenario, 'render')) {
            $expect(str_contains($html, 'Bulk update processing time') && str_contains($html, 'Select all'), 'Bulk controls missing');
            $expect(!str_contains($html, 'Avg Processing Time') && !str_contains($html, 'System Status'), 'Old summary cards were rendered');
            $document = new DOMDocument();
            @$document->loadHTML($html);
            $xpath = new DOMXPath($document);
            $expect($xpath->query('//form[not(.//input[@name="csrf_token"])]')->length === 0, 'A service form is missing CSRF protection');
            $expect($xpath->query('//select[@id="bulkTimeDepartment"]/option[@value="1"]')->length === 1, 'Department option missing');
            $expect($xpath->query('//input[@name="service_ids[]" and @value="101"]')->length === 1, 'Service checkbox missing');
            $expect($xpath->query('//input[@id="bulkTimeMinutes" and @min="1" and @max="120"]')->length === 1, 'Bulk time input missing');
            $expect($xpath->query('//button[@id="bulkTimePreviewButton"]')->length === 1, 'Bulk preview control missing');
            $expect($xpath->query('//button[translate(normalize-space(.), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="edit"]')->length === 0, 'Individual Edit button was rendered');
            $expect($xpath->query('//*[@id="editTimeModal" or @id="confirmTimeModal"] | //input[@name="action" and @value="update_time"]')->length === 0, 'Individual time editor was rendered');
            $expect($xpath->query('//input[@id="serviceSearchInput" and @type="search" and @aria-label]')->length === 1, 'Accessible directory search missing');
            $expect($xpath->query('//*[@id="serviceSearchStatus" and @role="status"]')->length === 1, 'Search feedback missing');
            $expect($xpath->query('//*[@id="servicePagination"]')->length === 1, 'Directory pagination missing');
            $cards = $xpath->query('//*[@id="servicesGrid"]//article');
            $expect($cards->length >= 3, 'Service cards missing');
            foreach ($cards as $card) {
                $headings = $xpath->query('.//h3[@id]', $card);
                $expect($headings->length === 1, 'Service card needs one heading');
                $heading = $headings->item(0);
                $expect($card->getAttribute('aria-labelledby') === $heading->getAttribute('id'), 'Service card is not labelled by its heading');
                $duration = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " service-directory-duration ")]//strong', $card);
                $expect($duration->length === 1 && preg_match('/^\d+\s+min$/', trim($duration->item(0)->textContent)) === 1, 'Service processing duration missing');
                $delete = $xpath->query('.//form[.//input[@name="action" and @value="delete"]]//button[@type="submit"]', $card);
                $expect($delete->length === 1 && $delete->item(0)->getAttribute('aria-label') === 'Delete ' . $heading->textContent, 'Delete control must identify its service');
                $expect($xpath->query('.//*[@data-feather="trash-2" and @aria-hidden="true"]', $delete->item(0))->length === 1, 'Delete icon must be decorative');
            }
            $fixtureTitle = $xpath->query('//h3[@id="service-title-101"]');
            $expect($fixtureTitle->length === 1 && $fixtureTitle->item(0)->textContent === 'Test <service>', 'Service heading was not escaped correctly');
            if ($scenario === 'render-preview') {
                $expect(str_contains($html, 'Test &lt;service&gt;') && str_contains($html, 'Test &amp; department'), 'Service or department output was not escaped');
                $expect($xpath->query('//*[@id="bulk-time-preview"]//tbody/tr')->length === 2, 'Preview does not show selected services');
                $expect($xpath->query('//button[@id="bulkTimeApplyButton" and not(@disabled)]')->length === 1, 'Valid preview cannot be applied');
                $expect($xpath->query('//input[@name="service_ids[]" and @checked]')->length === 2, 'Preview selection was not preserved');
            }
        } elseif ($scenario !== 'apply') {
            $expect(str_contains($html, 'role="alert"'), 'Invalid submission did not show an error');
            if ($scenario === 'single-update') {
                $expect(str_contains($html, 'Use Bulk update processing time to review and apply service time changes.'), 'Legacy time update did not direct the user to the bulk editor');
            }
            $expect(!isset($_SESSION['service_bulk_time_preview']), 'Rejected submission kept an actionable preview');
        }
        fwrite(STDOUT, "PASS: service processing time page $scenario.\n");
    } catch (Throwable $failure) {
        fwrite(STDERR, "FAIL: service processing time page $scenario: " . $failure->getMessage() . "\n");
        session_destroy();
        exit(1);
    }
    session_destroy();
});
chdir(__DIR__ . '/../dist/admin');
require 'services.php';
