<?php

// DECIMAL(10, 2), stored as integer cents while calculating fee adjustments.
const BILLING_MAX_FEE_CENTS = 9999999999;

function billingFeeCents($value): int
{
    if (!is_string($value) && !is_int($value)) {
        throw new InvalidArgumentException('Enter a valid amount with up to two decimal places.');
    }
    $value = str_replace(',', '', trim((string)$value));
    if (!preg_match('/^([0-9]+)(?:\.([0-9]{1,2}))?$/D', $value, $parts)) {
        throw new InvalidArgumentException('Enter a valid non-negative amount with up to two decimal places.');
    }
    $whole = ltrim($parts[1], '0') ?: '0';
    if (strlen($whole) > 8) {
        throw new InvalidArgumentException('Fees cannot exceed PHP 99,999,999.99.');
    }
    return (int)$whole * 100 + (int)str_pad($parts[2] ?? '', 2, '0');
}

function billingFeeDecimal(int $cents): string
{
    return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
}

function billingAdjustedFee(int $current, string $direction, string $unit, int $adjustment): int
{
    if (!in_array($direction, ['increase', 'decrease'], true)
        || !in_array($unit, ['amount', 'percent'], true) || $adjustment <= 0) {
        throw new InvalidArgumentException('Choose an adjustment and enter an amount greater than zero.');
    }
    if ($unit === 'percent') {
        if ($adjustment > 1000000) {
            throw new InvalidArgumentException('Percentage adjustments cannot exceed 10,000%.');
        }
        if ($direction === 'decrease' && $adjustment > 10000) {
            throw new InvalidArgumentException('A percentage decrease cannot exceed 100%.');
        }
        $factor = 10000 + ($direction === 'increase' ? $adjustment : -$adjustment);
        // Round the final fee to the nearest cent, including half-cent results.
        $next = intdiv($current * $factor + 5000, 10000);
    } else {
        $next = $current + ($direction === 'increase' ? $adjustment : -$adjustment);
    }
    if ($next < 0) {
        throw new InvalidArgumentException('This adjustment would make a service fee negative. Enter a smaller decrease.');
    }
    if ($next > BILLING_MAX_FEE_CENTS) {
        throw new InvalidArgumentException('This adjustment would exceed the maximum service fee of PHP 99,999,999.99.');
    }
    return $next;
}

function billingDepartmentFees(mysqli $connection, int $departmentId, bool $lock = false): array
{
    $statement = $connection->prepare('SELECT s.id, s.service_name, d.dept_name,
        sf.id AS fee_id, COALESCE(sf.amount, 0) AS amount
        FROM services s INNER JOIN departments d ON d.id = s.dept_id
        LEFT JOIN service_fees sf ON sf.service_id = s.id
        WHERE s.dept_id = ? ORDER BY s.id' . ($lock ? ' FOR UPDATE' : ''));
    if (!$statement) {
        throw new RuntimeException('Unable to load department fees. Please try again.');
    }
    try {
        $statement->bind_param('i', $departmentId);
        if (!$statement->execute()) {
            throw new RuntimeException('Unable to load department fees. Please try again.');
        }
        return $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    } finally {
        $statement->close();
    }
}

function billingBulkPreview(mysqli $connection, int $departmentId, string $direction, string $unit, int $adjustment): array
{
    $services = billingDepartmentFees($connection, $departmentId);
    if (!$services) {
        throw new InvalidArgumentException('Choose a department with services to update.');
    }
    $rows = [];
    $changed = 0;
    foreach ($services as $service) {
        $current = billingFeeCents($service['amount']);
        $next = billingAdjustedFee($current, $direction, $unit, $adjustment);
        $rows[] = [
            'id' => (int)$service['id'],
            'service_name' => $service['service_name'],
            'fee_id' => $service['fee_id'] === null ? null : (int)$service['fee_id'],
            'current' => $current,
            'next' => $next,
        ];
        if ($current !== $next) $changed++;
    }
    if ($changed === 0) {
        throw new InvalidArgumentException('No fees would change with this adjustment. Try a different amount.');
    }
    return [
        'department_id' => $departmentId,
        'department_name' => $services[0]['dept_name'],
        'direction' => $direction,
        'unit' => $unit,
        'adjustment' => $adjustment,
        'rows' => $rows,
        'changed' => $changed,
        'token' => bin2hex(random_bytes(32)),
    ];
}

function billingApplyBulkPreview(mysqli $connection, array $preview): int
{
    if (!$connection->begin_transaction()) {
        throw new RuntimeException('Unable to save fees. Please try again.');
    }
    try {
        $current = billingDepartmentFees($connection, $preview['department_id'], true);
        if (count($current) !== count($preview['rows'])) {
            throw new RuntimeException('The department services have changed. Generate a new preview before applying.');
        }
        foreach ($current as $index => $service) {
            $expected = $preview['rows'][$index];
            $feeId = $service['fee_id'] === null ? null : (int)$service['fee_id'];
            if ((int)$service['id'] !== $expected['id'] || $feeId !== $expected['fee_id']
                || billingFeeCents($service['amount']) !== $expected['current']) {
                throw new RuntimeException('Some fees have changed since this preview. Generate a new preview before applying.');
            }
        }
        $statement = $connection->prepare('INSERT INTO service_fees (service_id, amount)
            VALUES (?, ?) ON DUPLICATE KEY UPDATE amount = VALUES(amount)');
        if (!$statement) {
            throw new RuntimeException('Unable to save fees. No bulk changes were saved.');
        }
        try {
            $changed = 0;
            foreach ($preview['rows'] as $row) {
                if ($row['current'] === $row['next']) continue;
                $serviceId = $row['id'];
                $amount = billingFeeDecimal($row['next']);
                $statement->bind_param('is', $serviceId, $amount);
                if (!$statement->execute()) {
                    throw new RuntimeException('Unable to save fees. No bulk changes were saved.');
                }
                $changed++;
            }
        } finally {
            $statement->close();
        }
        if (!$connection->commit()) {
            throw new RuntimeException('Unable to save fees. Please try again.');
        }
        return $changed;
    } catch (Throwable $error) {
        $connection->rollback();
        throw $error;
    }
}
