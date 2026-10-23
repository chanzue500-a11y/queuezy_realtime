<?php

function workspaceNotificationSnapshot(?mysqli $connection, string $role): array
{
    $unavailable = ['ok' => false, 'message' => 'Notifications are temporarily unavailable.', 'key' => '', 'has_activity' => false,
        'ticket' => null, 'status' => null, 'department' => null, 'waiting' => 0, 'called' => 0, 'serving' => 0,
        'updated_services' => 0, 'new_customers' => 0];
    if (!$connection) return $unavailable;

    $queries = [
        'queue' => "SELECT COALESCE(SUM(status = 'waiting'), 0) AS waiting,
            COALESCE(SUM(status = 'called'), 0) AS called,
            COALESCE(SUM(status = 'now_serving'), 0) AS serving FROM queue",
        'ticket' => "SELECT q.id, q.ticket_number, q.status, q.started_at, d.dept_name
            FROM queue q LEFT JOIN services s ON s.id = q.service_id
            LEFT JOIN departments d ON d.id = s.dept_id
            WHERE q.status IN ('called', 'now_serving')
            ORDER BY q.started_at DESC, q.id DESC LIMIT 1",
    ];
    if ($role === 'admin') {
        $queries['fees'] = 'SELECT COUNT(*) AS total, MAX(updated_at) AS latest FROM service_fees WHERE updated_at >= CURDATE()';
        $queries['customers'] = "SELECT COUNT(*) AS total, MAX(id) AS latest FROM users WHERE role = 'customer' AND created_at >= CURDATE()";
    }
    $rows = [];
    foreach ($queries as $name => $sql) {
        $result = $connection->query($sql);
        if (!$result) return $unavailable;
        $rows[$name] = $result->fetch_assoc() ?? [];
        $result->free();
    }
    $snapshot = $unavailable;
    $snapshot['ok'] = true;
    foreach (['waiting', 'called', 'serving'] as $status) {
        $snapshot[$status] = (int)$rows['queue'][$status];
    }
    $snapshot['ticket'] = $rows['ticket']['ticket_number'] ?? null;
    $snapshot['status'] = $rows['ticket']['status'] ?? null;
    $snapshot['department'] = $rows['ticket']['dept_name'] ?? null;
    $snapshot['updated_services'] = (int)($rows['fees']['total'] ?? 0);
    $snapshot['new_customers'] = (int)($rows['customers']['total'] ?? 0);
    $snapshot['queue_message'] = "{$snapshot['waiting']} waiting, {$snapshot['called']} called, {$snapshot['serving']} being served.";
    $snapshot['waiting_message'] = $snapshot['waiting'] . ($snapshot['waiting'] === 1 ? ' customer is' : ' customers are') . ' waiting to be served.';
    $snapshot['service_message'] = $snapshot['updated_services'] . ($snapshot['updated_services'] === 1 ? ' service fee' : ' service fees') . ' updated today.';
    $snapshot['customer_message'] = $snapshot['new_customers'] . ($snapshot['new_customers'] === 1 ? ' new customer' : ' new customers') . ' registered today.';
    $snapshot['call_message'] = 'No ticket is currently called or being served.';
    if ($snapshot['ticket'] !== null) {
        $state = $snapshot['status'] === 'called' ? 'has been called' : 'is being served';
        $department = $snapshot['department'] ? ' (' . $snapshot['department'] . ')' : '';
        $snapshot['call_message'] = 'Ticket #' . $snapshot['ticket'] . ' ' . $state . $department . '.';
    }
    $snapshot['has_activity'] = $snapshot['waiting'] + $snapshot['called'] + $snapshot['serving']
        + $snapshot['updated_services'] + $snapshot['new_customers'] > 0;
    if ($role === 'admin') {
        $snapshot['message'] = $snapshot['queue_message'];
        if ($snapshot['updated_services'] > 0) $snapshot['message'] .= ' ' . $snapshot['service_message'];
        if ($snapshot['new_customers'] > 0) $snapshot['message'] .= ' ' . $snapshot['customer_message'];
        if (!$snapshot['has_activity']) $snapshot['message'] = 'No active queues or new updates today.';
    } else {
        $snapshot['message'] = $snapshot['ticket'] !== null ? $snapshot['call_message'] : $snapshot['waiting_message'];
    }
    $snapshot['key'] = hash('sha256', $role . '|' . date('Y-m-d') . '|' . json_encode($rows));
    return $snapshot;
}
