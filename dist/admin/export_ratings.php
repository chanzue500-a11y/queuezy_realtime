<?php
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}
session_write_close();
ignore_user_abort(true);

require_once __DIR__ . '/../database/db.php';
require_once __DIR__ . '/../includes/ratings_report.php';

$scope = $_GET['scope'] ?? 'all';
if (!in_array($scope, ['all', 'filtered'], true)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Choose a valid export scope.');
}
$filters = ratingsReportFilters($scope === 'filtered' ? $_GET : []);
if ($filters['errors']) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    exit(implode(' ', $filters['errors']));
}
if (!$conn) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Ratings are temporarily unavailable. Please try again when the database connection is restored.');
}

$file = null;
try {
    $file = ratingsReportExcelFile(ratingsReportRows($conn, $filters));
    $exportDate = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    $filename = 'service-ratings-' . $scope . '-' . $exportDate->format('Y-m-d') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($file));
    readfile($file);
} catch (Throwable $error) {
    error_log('Ratings export failed: ' . $error->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header_remove('Content-Disposition');
        header_remove('Content-Length');
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Unable to export ratings. Please return to Ratings and try again.';
    }
} finally {
    if ($file !== null && is_file($file)) {
        unlink($file);
    }
}
