<?php
require_once '../database/db.php';

if (!isset($conn) || !$conn) {
    http_response_code(500);
    exit('Database connection unavailable.');
}

$conn->query("CREATE TABLE IF NOT EXISTS queue_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    queue_id INT NOT NULL UNIQUE,
    amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    status ENUM('pending', 'paid') NOT NULL DEFAULT 'pending',
    paid_at DATETIME NULL,
    CONSTRAINT fk_queue_payments_queue FOREIGN KEY (queue_id) REFERENCES queue(id) ON DELETE CASCADE
) ENGINE=InnoDB");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ticket_id = filter_input(INPUT_POST, 'ticket_id', FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';

    if ($ticket_id === false || $ticket_id === null) {
        header('Location: monitor.php');
        exit();
    }

    if ($action === 'call_next') {
        $stmt = $conn->prepare("UPDATE queue SET status = 'now_serving', started_at = NOW() WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $ticket_id);
            $stmt->execute();
            $stmt->close();
        }
    } elseif ($action === 'complete') {
        $stmt = $conn->prepare("UPDATE queue SET status = 'completed', completed_at = NOW() WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $ticket_id);
            if ($stmt->execute()) {
                $paymentStmt = $conn->prepare("INSERT INTO queue_payments (queue_id, amount, status, paid_at)
                    SELECT q.id, COALESCE(sf.amount, 0), 'pending', NULL
                    FROM queue q LEFT JOIN service_fees sf ON sf.service_id = q.service_id WHERE q.id = ?
                    ON DUPLICATE KEY UPDATE status = 'pending', paid_at = NULL");
                if ($paymentStmt) {
                    $paymentStmt->bind_param('i', $ticket_id);
                    $paymentStmt->execute();
                    $paymentStmt->close();
                }
            }
            $stmt->close();
        }
    }

    header('Location: monitor.php');
    exit();
}
?>