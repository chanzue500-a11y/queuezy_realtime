<?php
require_once __DIR__ . '/../dist/database/db.php';
if (!$conn) throw new RuntimeException('Database unavailable');
// Fixture tables shadow production records for this connection only.
foreach ([
    'CREATE TEMPORARY TABLE queue (id INT PRIMARY KEY, user_id INT NULL, service_id INT, ticket_number VARCHAR(30), status VARCHAR(20), created_at DATETIME, started_at DATETIME NULL, completed_at DATETIME NULL) ENGINE=InnoDB',
    'CREATE TEMPORARY TABLE queue_payments (id INT PRIMARY KEY, queue_id INT UNIQUE, amount DECIMAL(10,2), status VARCHAR(20), paid_at DATETIME NULL) ENGINE=InnoDB',
    'CREATE TEMPORARY TABLE queue_ratings (id INT PRIMARY KEY, queue_id INT UNIQUE, user_id INT, rating INT, feedback VARCHAR(500)) ENGINE=InnoDB',
    "INSERT INTO queue (id,service_id,ticket_number,status,created_at,completed_at) VALUES (999999,1,'PERMISSION-TICKET','completed',NOW(),NOW())",
    "INSERT INTO queue_payments (id,queue_id,amount,status,paid_at) VALUES (999999,999999,12.34,'paid',NOW())",
    "INSERT INTO queue_ratings (id,queue_id,user_id,rating,feedback) VALUES (999999,999999,0,5,'Test only')",
] as $sql) if (!$conn->query($sql)) throw new RuntimeException($conn->error);
$scenario = $argv[1] ?? 'view';
session_id('report-delete-' . bin2hex(random_bytes(8)));
session_start(['use_cookies' => false, 'cache_limiter' => '', 'save_path' => sys_get_temp_dir()]);
$_SESSION = ['user_id' => 0, 'user_role' => $scenario === 'staff' ? 'staff' : 'admin', 'user_name' => 'Report test', 'queue_transaction_csrf' => 'test-token'];
session_write_close();
$today = date('Y-m-d');
$_GET = ['search' => 'PERMISSION-TICKET', 'status' => 'completed', 'sales_from' => $today, 'sales_to' => $today, 'sales_open' => '1'];
$_POST = $scenario === 'view' ? [] : ['action' => 'delete_transaction', 'queue_id' => '999999', 'csrf_token' => $scenario === 'bad-token' ? 'wrong-token' : 'test-token'];
$_SERVER['PHP_SELF'] = '/PWA/dist/admin/reports.php';
$_SERVER['REQUEST_METHOD'] = $scenario === 'view' ? 'GET' : 'POST';
set_error_handler(static function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});
ob_start();
register_shutdown_function(static function () use ($conn, $scenario, $today): void {
    $html = ob_get_clean();
    try {
        foreach (['queue', 'queue_payments', 'queue_ratings'] as $table) {
            $count = (int)$conn->query("SELECT COUNT(*) AS total FROM $table")->fetch_assoc()['total'];
            if ($count !== ($scenario === 'delete' ? 0 : 1)) throw new RuntimeException('Unauthorized deletion or incomplete cleanup');
        }
        if ($scenario === 'delete') {
            if (empty($_SESSION['queue_transaction_notice'])) throw new RuntimeException('Success notice missing');
            parse_str(parse_url($GLOBALS['transactionReturnUrl'], PHP_URL_QUERY), $query);
            if ($query['search'] !== 'PERMISSION-TICKET' || $query['status'] !== 'completed' || $query['sales_from'] !== $today || $query['sales_open'] !== '1') throw new RuntimeException('Filters were lost after deletion');
        } elseif ($scenario === 'bad-token') {
            if (http_response_code() !== 403) throw new RuntimeException('Invalid token must return HTTP 403');
        } elseif ($scenario === 'staff') {
            if (str_contains($html, 'delete_transaction')) throw new RuntimeException('Staff received delete controls');
        } else {
            $document = new DOMDocument(); @$document->loadHTML($html); $xpath = new DOMXPath($document);
            if ($xpath->query('//form[.//input[@value="delete_transaction"]]/input[@name="csrf_token"]')->length !== 1) throw new RuntimeException('Protected delete form missing');
            if ($xpath->query('//section[@id="queue-transactions"]//thead/tr/th')->length !== 7) throw new RuntimeException('Action column missing');
        }
        session_destroy();
        echo "PASS: report transaction $scenario.\n";
    } catch (Throwable $error) { fwrite(STDERR, 'FAIL: ' . $error->getMessage() . PHP_EOL); exit(1); }
});
chdir(__DIR__ . '/../dist/admin');
require 'reports.php';
