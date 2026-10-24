<?php
// A forged delete request must stop before connecting to the database.
session_id('staff-queue-permission-' . bin2hex(random_bytes(8)));
session_start(['use_cookies' => false, 'cache_limiter' => '', 'save_path' => sys_get_temp_dir()]);
$_SESSION = ['user_id' => 0, 'user_role' => 'staff'];
session_write_close();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['action' => 'delete', 'queue_id' => '1'];
ob_start();
register_shutdown_function(static function (): void {
    global $conn;
    $response = ob_get_clean();
    $passed = http_response_code() === 403
        && $response === 'Only admins can delete queue tickets.'
        && $conn === null;
    session_destroy();
    if (!$passed) {
        fwrite(STDERR, "FAIL: Staff queue deletion was not blocked before database access.\n");
        exit(1);
    }
    echo "PASS: Staff queue deletion returns 403 before database access.\n";
});
chdir(__DIR__ . '/../dist/staff');
require 'monitor.php';
