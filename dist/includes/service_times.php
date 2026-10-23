<?php

const SERVICE_TIME_BATCH_LIMIT = 500;

function serviceTimeMinutes($value): int
{
    $minutes = filter_var($value, FILTER_VALIDATE_INT);
    if ($minutes === false || $minutes < 1 || $minutes > 120) {
        throw new InvalidArgumentException('Enter a whole number between 1 and 120 minutes.');
    }
    return $minutes;
}

function serviceTimeIds($values): array
{
    if (!is_array($values) || !$values || count($values) > SERVICE_TIME_BATCH_LIMIT) {
        throw new InvalidArgumentException('Select between 1 and ' . SERVICE_TIME_BATCH_LIMIT . ' services from one department.');
    }
    $ids = [];
    foreach ($values as $value) {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new InvalidArgumentException('Select valid services from the chosen department.');
        }
        $ids[$id] = $id;
    }
    sort($ids, SORT_NUMERIC);
    return $ids;
}

function serviceTimeRows(mysqli $connection, int $departmentId, array $ids, bool $lock = false): array
{
    // Lock the department first, matching service creation and rename operations.
    $statement = $connection->prepare('SELECT id, dept_name FROM departments WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
    if (!$statement) throw new RuntimeException('Unable to load the department. Please try again.');
    try {
        $statement->bind_param('i', $departmentId);
        if (!$statement->execute()) throw new RuntimeException('Unable to load the department. Please try again.');
        $department = $statement->get_result()->fetch_assoc();
        if (!$department) throw new InvalidArgumentException('Choose an existing department.');
    } finally {
        $statement->close();
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $statement = $connection->prepare('SELECT id, service_name, estimated_time_mins FROM services
        WHERE dept_id = ? AND id IN (' . $placeholders . ') ORDER BY id' . ($lock ? ' FOR UPDATE' : ''));
    if (!$statement) throw new RuntimeException('Unable to load service times. Please try again.');
    try {
        $parameters = array_merge([$departmentId], $ids);
        $statement->bind_param(str_repeat('i', count($parameters)), ...$parameters);
        if (!$statement->execute()) throw new RuntimeException('Unable to load service times. Please try again.');
        $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
        if (count($rows) !== count($ids)) {
            throw new InvalidArgumentException('Some selected services are unavailable in this department. Refresh the page and select them again.');
        }
        return ['department' => $department, 'services' => $rows];
    } finally {
        $statement->close();
    }
}

function serviceTimePreview(mysqli $connection, int $departmentId, $serviceIds, $newMinutes): array
{
    $minutes = serviceTimeMinutes($newMinutes);
    $ids = serviceTimeIds($serviceIds);
    $data = serviceTimeRows($connection, $departmentId, $ids);
    $rows = [];
    $changed = 0;
    foreach ($data['services'] as $service) {
        $current = (int)$service['estimated_time_mins'];
        $rows[] = ['id' => (int)$service['id'], 'service_name' => $service['service_name'], 'current' => $current];
        if ($current !== $minutes) $changed++;
    }
    if ($changed === 0) {
        throw new InvalidArgumentException('The selected services already have this processing time. Enter a different time.');
    }
    return [
        'department_id' => $departmentId,
        'department_name' => $data['department']['dept_name'],
        'minutes' => $minutes,
        'rows' => $rows,
        'changed' => $changed,
        'token' => bin2hex(random_bytes(32)),
    ];
}

function serviceTimeApply(mysqli $connection, array $preview): int
{
    if (!$connection->begin_transaction()) throw new RuntimeException('Unable to save processing times. Please try again.');
    try {
        $ids = array_column($preview['rows'], 'id');
        $data = serviceTimeRows($connection, $preview['department_id'], $ids, true);
        foreach ($data['services'] as $index => $service) {
            $expected = $preview['rows'][$index];
            if ((int)$service['id'] !== $expected['id'] || $service['service_name'] !== $expected['service_name']
                || (int)$service['estimated_time_mins'] !== $expected['current']) {
                throw new RuntimeException('Some services have changed since this preview. Generate a new preview before applying.');
            }
        }
        $statement = $connection->prepare('UPDATE services SET estimated_time_mins = ? WHERE id = ? AND dept_id = ?');
        if (!$statement) throw new RuntimeException('Unable to save processing times. No bulk changes were saved.');
        try {
            $changed = 0;
            $minutes = $preview['minutes'];
            $departmentId = $preview['department_id'];
            foreach ($preview['rows'] as $row) {
                if ($row['current'] === $minutes) continue;
                $serviceId = $row['id'];
                $statement->bind_param('iii', $minutes, $serviceId, $departmentId);
                if (!$statement->execute() || $statement->affected_rows !== 1) {
                    throw new RuntimeException('Unable to save processing times. No bulk changes were saved.');
                }
                $changed++;
            }
        } finally {
            $statement->close();
        }
        if (!$connection->commit()) throw new RuntimeException('Unable to save processing times. Please try again.');
        return $changed;
    } catch (Throwable $error) {
        $connection->rollback();
        throw $error;
    }
}
