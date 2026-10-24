<?php
require_once __DIR__ . '/../dist/database/db.php';
if (!$conn) throw new RuntimeException('Database unavailable');
// Only this connection sees and changes the fixture rating.
if (!$conn->query('CREATE TEMPORARY TABLE queue_ratings (id INT PRIMARY KEY, queue_id INT, user_id INT, rating INT, feedback VARCHAR(500), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)')) throw new RuntimeException($conn->error);
if (!$conn->query("INSERT INTO queue_ratings (id,queue_id,user_id,rating,feedback) VALUES (900001,0,0,5,'Permission test')")) throw new RuntimeException($conn->error);
$scenario = $argv[1] ?? 'staff-view';
$isStaff = str_starts_with($scenario, 'staff');
$isPost = !str_ends_with($scenario, 'view');
session_id('ratings-permission-' . bin2hex(random_bytes(8)));
session_start(['use_cookies' => false, 'cache_limiter' => '', 'save_path' => sys_get_temp_dir()]);
$_SESSION = ['user_id' => 0, 'user_role' => $isStaff ? 'staff' : 'admin', 'user_name' => 'Permission test', 'ratings_csrf' => 'test-token'];
session_write_close();
$_GET = [];
$_POST = $isPost ? ['action' => 'delete_rating', 'rating_id' => '900001', 'csrf_token' => 'test-token'] : [];
if ($scenario === 'admin-no-token') unset($_POST['csrf_token']);
$_SERVER['REQUEST_METHOD'] = $isPost ? 'POST' : 'GET';
$staffRoute = $isStaff && $scenario !== 'staff-direct-delete';
$_SERVER['PHP_SELF'] = $staffRoute ? '/PWA/dist/staff/rating_view.php' : '/PWA/dist/admin/ratings.php';
set_error_handler(static function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});
ob_start();
register_shutdown_function(static function () use ($conn, $scenario, $isStaff, $isPost): void {
    $html = ob_get_clean();
    $expect = static function (bool $condition, string $message): void {
        if (!$condition) throw new RuntimeException($message);
    };
    try {
        $count = (int)$conn->query('SELECT COUNT(*) AS total FROM queue_ratings')->fetch_assoc()['total'];
        $expect($count === ($scenario === 'admin-delete' ? 0 : 1), 'Unauthorized deletion or failed admin deletion');
        if ($isPost && $scenario !== 'admin-delete') {
            $expect(http_response_code() === 403, 'Unauthorized requests must receive HTTP 403');
        } elseif (!$isPost) {
            $document = new DOMDocument();
            @$document->loadHTML($html);
            $xpath = new DOMXPath($document);
            $expect($xpath->query('//table[@id="ratingsTable"]/thead/tr/th')->length === ($isStaff ? 6 : 7), 'Wrong table columns for role');
            $expect($xpath->query('//input[@name="action" and @value="delete_rating"]')->length === ($isStaff ? 0 : 1), 'Wrong delete controls for role');
            if (!$isStaff) $expect($xpath->query('//form[.//input[@value="delete_rating"]]/input[@name="csrf_token"]')->length === 1, 'Admin delete form must include CSRF token');
        }
        session_destroy();
        echo "PASS: ratings permissions $scenario.\n";
    } catch (Throwable $error) {
        fwrite(STDERR, 'FAIL: ' . $scenario . ': ' . $error->getMessage() . PHP_EOL);
        exit(1);
    }
});
chdir(__DIR__ . ($staffRoute ? '/../dist/staff' : '/../dist/admin'));
require $staffRoute ? 'rating_view.php' : 'ratings.php';
