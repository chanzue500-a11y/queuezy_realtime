<?php

function departmentServiceNameError(string $name): ?string
{
    if ($name === '') {
        return 'Enter a service name.';
    }
    if (preg_match('//u', $name) !== 1) {
        return 'Enter a valid service name.';
    }
    $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : preg_match_all('/./us', $name);
    return $length > 160 ? 'Service names can contain up to 160 characters.' : null;
}

function ensureDepartmentServiceFeesTable(mysqli $conn): bool
{
    // Existing installations use both signed and unsigned service IDs.
    $result = $conn->query("SHOW COLUMNS FROM services LIKE 'id'");
    $column = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $idType = $column['Type'] ?? '';
    if (!preg_match('/\A(?:tinyint|smallint|mediumint|int|bigint)(?:\(\d+\))?(?: unsigned)?\z/i', $idType)) {
        return false;
    }
    return (bool)$conn->query("CREATE TABLE IF NOT EXISTS service_fees (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        service_id {$idType} NOT NULL UNIQUE,
        amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
}

function createDepartmentService(?mysqli $conn, int $departmentId, string $name, string $price, int $estimatedMinutes): array
{
    $name = trim($name);
    if ($error = departmentServiceNameError($name)) {
        return ['ok' => false, 'message' => $error];
    }
    if ($departmentId <= 0) {
        return ['ok' => false, 'message' => 'Select an existing department.'];
    }
    if ($estimatedMinutes < 1 || $estimatedMinutes > 120) {
        return ['ok' => false, 'message' => 'Enter an estimated time between 1 and 120 minutes.'];
    }
    $price = str_replace(',', '', trim($price));
    if (!preg_match('/\A(?:\d+(?:\.\d{0,2})?|\.\d{1,2})\z/', $price)) {
        return ['ok' => false, 'message' => 'Enter a non-negative price with up to two decimal places.'];
    }
    [$whole, $fraction] = array_pad(explode('.', $price, 2), 2, '');
    $whole = ltrim($whole, '0');
    if (strlen($whole) > 8) {
        return ['ok' => false, 'message' => 'The service price cannot exceed 99,999,999.99.'];
    }
    $price = ($whole === '' ? '0' : $whole) . '.' . str_pad($fraction, 2, '0');
    if (!$conn) {
        return ['ok' => false, 'message' => 'The database is unavailable. Please try again.'];
    }
    // DDL must run before beginning the service/fee transaction.
    if (!ensureDepartmentServiceFeesTable($conn) || !$conn->begin_transaction()) {
        return ['ok' => false, 'message' => 'We could not prepare to save the service. Please try again.'];
    }

    $departmentStmt = $duplicateStmt = $serviceStmt = $feeStmt = null;
    $committed = false;
    $message = 'We could not add the service. Please try again.';
    try {
        $departmentStmt = $conn->prepare('SELECT id FROM departments WHERE id = ? FOR UPDATE');
        if (!$departmentStmt) {
            return ['ok' => false, 'message' => $message];
        }
        $departmentStmt->bind_param('i', $departmentId);
        if (!$departmentStmt->execute()) {
            return ['ok' => false, 'message' => $message];
        }
        if (!$departmentStmt->get_result()->fetch_assoc()) {
            return ['ok' => false, 'message' => 'That department no longer exists.'];
        }

        $duplicateStmt = $conn->prepare('SELECT id FROM services WHERE dept_id = ? AND service_name = ? LIMIT 1');
        if (!$duplicateStmt) {
            return ['ok' => false, 'message' => $message];
        }
        $duplicateStmt->bind_param('is', $departmentId, $name);
        if (!$duplicateStmt->execute()) {
            return ['ok' => false, 'message' => $message];
        }
        if ($duplicateStmt->get_result()->fetch_assoc()) {
            return ['ok' => false, 'message' => 'A service with that name already exists in this department.'];
        }

        $serviceStmt = $conn->prepare('INSERT INTO services (dept_id, service_name, estimated_time_mins) VALUES (?, ?, ?)');
        if (!$serviceStmt) {
            return ['ok' => false, 'message' => $message];
        }
        $serviceStmt->bind_param('isi', $departmentId, $name, $estimatedMinutes);
        if (!$serviceStmt->execute()) {
            return ['ok' => false, 'message' => $serviceStmt->errno === 1062 ? 'A service with that name already exists in this department.' : $message];
        }
        $serviceId = (int)$conn->insert_id;
        $feeStmt = $conn->prepare('INSERT INTO service_fees (service_id, amount) VALUES (?, ?)');
        if (!$feeStmt) {
            return ['ok' => false, 'message' => $message];
        }
        $feeStmt->bind_param('is', $serviceId, $price);
        if (!$feeStmt->execute() || !$conn->commit()) {
            return ['ok' => false, 'message' => $message];
        }
        $committed = true;
        return ['ok' => true, 'message' => 'Service added successfully.', 'service_id' => $serviceId];
    } finally {
        if (!$committed) {
            $conn->rollback();
        }
        foreach ([$departmentStmt, $duplicateStmt, $serviceStmt, $feeStmt] as $statement) {
            if ($statement instanceof mysqli_stmt) {
                $statement->close();
            }
        }
    }
}

function renameDepartmentService(?mysqli $conn, int $departmentId, int $serviceId, string $name): array
{
    $name = trim($name);
    if ($error = departmentServiceNameError($name)) {
        return ['ok' => false, 'message' => $error];
    }
    if (!$conn) {
        return ['ok' => false, 'message' => 'The database is unavailable. Please try again.'];
    }
    if ($departmentId <= 0 || $serviceId <= 0) {
        return ['ok' => false, 'message' => 'Select an existing service and department.'];
    }
    if (!$conn->begin_transaction()) {
        return ['ok' => false, 'message' => 'We could not prepare to rename the service. Please try again.'];
    }
    $departmentStmt = $serviceStmt = $duplicateStmt = $updateStmt = null;
    $committed = false;
    $message = 'We could not rename the service. Please try again.';
    try {
        // Use the same lock order as creation so names stay unique without an index.
        $departmentStmt = $conn->prepare('SELECT id FROM departments WHERE id = ? FOR UPDATE');
        if (!$departmentStmt) {
            return ['ok' => false, 'message' => $message];
        }
        $departmentStmt->bind_param('i', $departmentId);
        if (!$departmentStmt->execute()) {
            return ['ok' => false, 'message' => $message];
        }
        if (!$departmentStmt->get_result()->fetch_assoc()) {
            return ['ok' => false, 'message' => 'That department no longer exists.'];
        }
        $serviceStmt = $conn->prepare('SELECT id FROM services WHERE id = ? AND dept_id = ? FOR UPDATE');
        if (!$serviceStmt) {
            return ['ok' => false, 'message' => $message];
        }
        $serviceStmt->bind_param('ii', $serviceId, $departmentId);
        if (!$serviceStmt->execute()) {
            return ['ok' => false, 'message' => $message];
        }
        if (!$serviceStmt->get_result()->fetch_assoc()) {
            return ['ok' => false, 'message' => 'That service was not found in this department.'];
        }
        $duplicateStmt = $conn->prepare('SELECT id FROM services WHERE dept_id = ? AND service_name = ? AND id <> ? LIMIT 1');
        if (!$duplicateStmt) {
            return ['ok' => false, 'message' => $message];
        }
        $duplicateStmt->bind_param('isi', $departmentId, $name, $serviceId);
        if (!$duplicateStmt->execute()) {
            return ['ok' => false, 'message' => $message];
        }
        if ($duplicateStmt->get_result()->fetch_assoc()) {
            return ['ok' => false, 'message' => 'A service with that name already exists in this department.'];
        }
        $updateStmt = $conn->prepare('UPDATE services SET service_name = ? WHERE id = ? AND dept_id = ?');
        if (!$updateStmt) {
            return ['ok' => false, 'message' => $message];
        }
        $updateStmt->bind_param('sii', $name, $serviceId, $departmentId);
        if (!$updateStmt->execute()) {
            return ['ok' => false, 'message' => $updateStmt->errno === 1062 ? 'A service with that name already exists in this department.' : $message];
        }
        if (!$conn->commit()) {
            return ['ok' => false, 'message' => $message];
        }
        $committed = true;
        return ['ok' => true, 'message' => 'Service name updated successfully.', 'service_id' => $serviceId];
    } finally {
        if (!$committed) {
            $conn->rollback();
        }
        foreach ([$departmentStmt, $serviceStmt, $duplicateStmt, $updateStmt] as $statement) {
            if ($statement instanceof mysqli_stmt) {
                $statement->close();
            }
        }
    }
}
