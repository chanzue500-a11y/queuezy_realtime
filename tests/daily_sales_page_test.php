<?php
$scenario = $argv[1] ?? 'default';
$today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
$_GET = [];
if ($scenario === 'today') {
    $_GET = ['sales_from' => $today->format('Y-m-d'), 'sales_to' => $today->format('Y-m-d'), 'sales_open' => '1'];
} elseif ($scenario === 'week') {
    $_GET = ['sales_from' => $today->modify('-6 days')->format('Y-m-d'), 'sales_to' => $today->format('Y-m-d'), 'sales_open' => '1'];
} elseif ($scenario === 'invalid') {
    $_GET = ['sales_from' => '2026-02-30', 'sales_open' => '1'];
} elseif ($scenario === 'yesterday') {
    $_GET = ['sales_from' => $today->modify('-1 day')->format('Y-m-d'), 'sales_to' => $today->modify('-1 day')->format('Y-m-d'), 'sales_open' => '1'];
} elseif ($scenario !== 'default') {
    throw new InvalidArgumentException('Unknown render scenario');
}
session_id('daily-sales-page-' . bin2hex(random_bytes(8)));
session_start(['use_cookies' => false, 'cache_limiter' => '', 'save_path' => sys_get_temp_dir()]);
$_SESSION = ['user_id' => 0, 'user_role' => 'admin', 'user_name' => 'Render test'];
session_write_close();
$_SERVER['PHP_SELF'] = '/PWA/dist/admin/reports.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
set_error_handler(static function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});
ob_start();
chdir(__DIR__ . '/../dist/admin');
require 'reports.php';
$html = ob_get_clean();
$document = new DOMDocument();
@$document->loadHTML($html);
$xpath = new DOMXPath($document);
$expect = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$expect($xpath->query('//form//form')->length === 0, 'Date controls must remain separate forms');
if ($scenario === 'invalid') {
    $expect($xpath->query('//details[contains(@class,"daily-sales-custom") and @open]')->length === 1, 'Invalid dates must expose the date controls');
    $expect($xpath->query('//details[@id="daily-sales"]//p[@role="alert"]')->length === 1, 'Date error missing');
    $expect($xpath->query('//div[contains(@class,"daily-sales-totals")]')->length === 0, 'Invalid range must not show misleading zero totals');
} else {
    $expect($xpath->query('//nav[@aria-label="Choose sales period"]/a')->length === 3, 'Quick periods missing');
    $expect($xpath->query('//nav[@aria-label="Choose sales period"]/a[@aria-current="page"]')->length === 1, 'Active period must be clear');
    $expect($xpath->query('//div[@class="daily-sales-totals"]/div')->length === 2, 'Show two summary cards');
    $expect($xpath->query('//details[contains(@class,"daily-sales-custom") and @open]')->length === 0, 'Custom dates should be hidden initially');
    $expect($xpath->query('//table[@class="daily-sales-table"]/tbody/tr')->length === ($scenario === 'week' ? 7 : 0), 'Single day must not repeat its totals in a table');
    $expect($xpath->query('//details[@class="daily-sales-breakdown" and @open]')->length === 0, 'Daily breakdown should be collapsed initially');
    if ($scenario === 'default') {
        $expect($xpath->query('//details[@id="daily-sales" and @open]')->length === 0, 'Keep daily sales collapsed by default');
        $expect($dailyFilters['date_from'] === $today->format('Y-m-d') && $dailyFilters['date_to'] === $today->format('Y-m-d'), 'Default period must be today');
        $expect($xpath->query('//nav[@aria-label="Choose sales period"]/a[@aria-current="page" and normalize-space(.)="Today"]')->length === 1, 'Today must be selected by default');
    }
}
session_destroy();
echo "PASS: daily sales page $scenario.\n";
