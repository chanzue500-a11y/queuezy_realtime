<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Authentication required']);
    exit;
}

require_once __DIR__ . '/../../database/db.php';
if (!$conn) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'message' => 'Database unavailable']);
    exit;
}

if (!ensureQueueCallCountColumn($conn)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to check ticket status']);
    exit;
}

$stmt = $conn->prepare("SELECT q.ticket_number, q.status, q.call_count, d.dept_name, s.service_name FROM queue q LEFT JOIN services s ON s.id = q.service_id LEFT JOIN departments d ON d.id = s.dept_id WHERE q.user_id = ? AND q.status IN ('waiting', 'called', 'now_serving', 'completed', 'cancelled', 'no_show') ORDER BY q.id DESC LIMIT 1");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to check ticket status']);
    exit;
}

$stmt->bind_param('i', $userId);
$stmt->execute();
$ticket = $stmt->get_result()->fetch_assoc();
$stmt->close();

$transactions = [];
$transactionsStmt = $conn->prepare("SELECT q.ticket_number, q.status, q.created_at, q.completed_at, s.service_name, d.dept_name, qp.status AS payment_status, qp.amount, qp.paid_at FROM queue q LEFT JOIN services s ON s.id = q.service_id LEFT JOIN departments d ON d.id = s.dept_id LEFT JOIN queue_payments qp ON qp.queue_id = q.id WHERE q.user_id = ? ORDER BY q.id DESC LIMIT 50");
if ($transactionsStmt) {
    $transactionsStmt->bind_param('i', $userId);
    $transactionsStmt->execute();
    $transactionsResult = $transactionsStmt->get_result();
    while ($transaction = $transactionsResult->fetch_assoc()) {
        $transactions[] = [
            'ticket' => $transaction['ticket_number'],
            'status' => $transaction['status'],
            'created_at' => $transaction['created_at'],
            'completed_at' => $transaction['completed_at'],
            'service' => $transaction['service_name'],
            'department' => $transaction['dept_name'],
            'payment_status' => $transaction['payment_status'],
            'amount' => $transaction['amount'],
            'paid_at' => $transaction['paid_at'],
        ];
    }
    $transactionsStmt->close();
}

$payment = null;
$paymentStmt = $conn->prepare("SELECT q.ticket_number, qp.amount, qp.paid_at FROM queue_payments qp JOIN queue q ON q.id = qp.queue_id WHERE q.user_id = ? AND qp.status = 'paid' AND q.status NOT IN ('completed', 'cancelled', 'no_show') ORDER BY qp.paid_at DESC, qp.queue_id DESC LIMIT 1");
if ($paymentStmt) {
    $paymentStmt->bind_param('i', $userId);
    $paymentStmt->execute();
    $payment = $paymentStmt->get_result()->fetch_assoc();
    $paymentStmt->close();
}

echo json_encode([
    'ok' => true,
    'ticket' => $ticket['ticket_number'] ?? null,
    'status' => $ticket['status'] ?? null,
    'call_count' => (int)($ticket['call_count'] ?? 0),
    'department' => $ticket['dept_name'] ?? null,
    'service' => $ticket['service_name'] ?? null,
    'transactions' => $transactions,
    'paid_ticket' => $payment['ticket_number'] ?? null,
    'paid_amount' => $payment['amount'] ?? null,
    'paid_at' => $payment['paid_at'] ?? null
]);
