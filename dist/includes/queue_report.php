<?php

function queueReportStatuses(): array
{
    return [
        'waiting' => 'Waiting',
        'called' => 'Called',
        'now_serving' => 'Now serving',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'no_show' => 'No show',
    ];
}

function queueReportSorts(): array
{
    return [
        'newest' => ['Newest first', 'q.created_at DESC, q.id DESC'],
        'oldest' => ['Oldest first', 'q.created_at ASC, q.id ASC'],
        'service' => ['Service A–Z', 's.service_name ASC, q.id DESC'],
        'status' => ['Queue status A–Z', 'q.status ASC, q.id DESC'],
        'amount_high' => ['Amount: high to low', 'COALESCE(qp.amount, sf.amount, 0) DESC, q.id DESC'],
        'amount_low' => ['Amount: low to high', 'COALESCE(qp.amount, sf.amount, 0) ASC, q.id DESC'],
    ];
}

function queueReportFilters(array $input): array
{
    $filters = ['errors' => []];
    $read = static function (string $key) use ($input): string {
        return isset($input[$key]) && is_scalar($input[$key]) ? trim((string)$input[$key]) : '';
    };
    $filters['search'] = mb_substr($read('search'), 0, 120);
    foreach (['department_id', 'service_id'] as $key) {
        $value = $read($key);
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $filters[$key] = $id === false ? 0 : $id;
        if ($value !== '' && $value !== '0' && $id === false) {
            $filters['errors'][] = 'Choose a valid department or service.';
        }
    }
    foreach (['status' => queueReportStatuses(), 'payment_status' => ['pending' => 'Pending', 'paid' => 'Paid']] as $key => $choices) {
        $value = $read($key);
        $filters[$key] = array_key_exists($value, $choices) ? $value : '';
        if ($value !== '' && !array_key_exists($value, $choices)) {
            $filters['errors'][] = 'Choose a valid queue or payment status.';
        }
    }
    foreach (['date_from', 'date_to'] as $key) {
        $value = $read($key);
        $date = preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value)
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        $valid = $date && $date->format('Y-m-d') === $value && $value >= '1000-01-01' && $value <= '9999-12-31';
        $filters[$key] = $valid ? $value : '';
        if ($value !== '' && !$valid) {
            $filters['errors'][] = 'Enter valid dates in YYYY-MM-DD format.';
        }
    }
    if ($filters['date_from'] !== '' && $filters['date_to'] !== '' && $filters['date_from'] > $filters['date_to']) {
        $filters['errors'][] = 'The start date must be on or before the end date.';
    }
    $sort = $read('sort');
    $filters['sort'] = array_key_exists($sort, queueReportSorts()) ? $sort : 'newest';
    $page = filter_var($read('page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $filters['page'] = $page === false ? 1 : $page;
    $filters['errors'] = array_values(array_unique($filters['errors']));
    return $filters;
}

function queueReportQuery(mysqli $connection, string $sql, string $types = '', array $parameters = []): array
{
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new RuntimeException($connection->error);
    }
    try {
        if ($types !== '') {
            $statement->bind_param($types, ...$parameters);
        }
        if (!$statement->execute()) {
            throw new RuntimeException($statement->error);
        }
        $result = $statement->get_result();
        if (!$result) {
            throw new RuntimeException($statement->error);
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    } finally {
        $statement->close();
    }
}

function queueReportSelection(array $filters): array
{
    if ($filters['errors']) {
        throw new InvalidArgumentException(implode(' ', $filters['errors']));
    }
    $joins = ' FROM queue q
        LEFT JOIN users u ON u.id = q.user_id
        LEFT JOIN services s ON s.id = q.service_id
        LEFT JOIN departments d ON d.id = s.dept_id
        LEFT JOIN queue_payments qp ON qp.queue_id = q.id
        LEFT JOIN service_fees sf ON sf.service_id = q.service_id';
    $conditions = [];
    $parameters = [];
    $types = '';
    foreach (['department_id' => 's.dept_id', 'service_id' => 'q.service_id', 'status' => 'q.status', 'payment_status' => "COALESCE(qp.status, 'pending')"] as $key => $column) {
        if ($filters[$key] !== '' && $filters[$key] !== 0) {
            $conditions[] = $column . ' = ?';
            $parameters[] = $filters[$key];
            $types .= in_array($key, ['department_id', 'service_id'], true) ? 'i' : 's';
        }
    }
    if ($filters['search'] !== '') {
        // Escape LIKE wildcards so ticket/customer searches are literal substrings.
        $pattern = '%' . strtr($filters['search'], ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $conditions[] = "(q.ticket_number LIKE ? ESCAPE '!' OR u.fullname LIKE ? ESCAPE '!' OR s.service_name LIKE ? ESCAPE '!' OR d.dept_name LIKE ? ESCAPE '!')";
        array_push($parameters, $pattern, $pattern, $pattern, $pattern);
        $types .= 'ssss';
    }
    if ($filters['date_from'] !== '') {
        $conditions[] = 'q.created_at >= ?';
        $parameters[] = $filters['date_from'] . ' 00:00:00';
        $types .= 's';
    }
    if ($filters['date_to'] !== '') {
        $conditions[] = 'q.created_at <= ?';
        $parameters[] = $filters['date_to'] . ' 23:59:59';
        $types .= 's';
    }
    $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
    $order = queueReportSorts()[$filters['sort']][1];
    return compact('joins', 'where', 'types', 'parameters', 'order');
}

function queueReportColumns(): string
{
    return "SELECT q.id, q.ticket_number, q.status, q.created_at, q.started_at, q.completed_at,
        u.fullname, s.service_name, d.dept_name, qp.id AS payment_id,
        COALESCE(qp.amount, sf.amount, 0) AS amount,
        COALESCE(qp.status, 'pending') AS payment_status, qp.paid_at";
}

/** Stream every matching transaction without the on-screen page limit. */
function queueReportExportRows(mysqli $connection, array $filters): Generator
{
    $selection = queueReportSelection($filters);
    $statement = $connection->prepare(queueReportColumns() . $selection['joins'] . $selection['where'] . ' ORDER BY ' . $selection['order']);
    if (!$statement) {
        throw new RuntimeException($connection->error);
    }
    try {
        if ($selection['types'] !== '') {
            $statement->bind_param($selection['types'], ...$selection['parameters']);
        }
        if (!$statement->execute()) {
            throw new RuntimeException($statement->error);
        }
        $statement->bind_result($id, $ticket, $status, $createdAt, $startedAt, $completedAt,
            $customer, $service, $department, $paymentId, $amount, $paymentStatus, $paidAt);
        while (true) {
            $fetched = $statement->fetch();
            if ($fetched === null) break;
            if ($fetched === false) throw new RuntimeException($statement->error);
            yield [
                'id' => $id, 'ticket_number' => $ticket, 'status' => $status,
                'created_at' => $createdAt, 'started_at' => $startedAt, 'completed_at' => $completedAt,
                'fullname' => $customer, 'service_name' => $service, 'dept_name' => $department,
                'payment_id' => $paymentId, 'amount' => $amount,
                'payment_status' => $paymentStatus, 'paid_at' => $paidAt,
            ];
        }
    } finally {
        $statement->close();
    }
}

function queueReportData(mysqli $connection, array $filters): array
{
    $data = [
        'departments' => [], 'services' => [], 'rows' => [], 'department_counts' => [],
        'summary' => [
            'total_count' => 0, 'completed_count' => 0, 'waiting_count' => 0,
            'serving_count' => 0, 'cancelled_count' => 0, 'no_show_count' => 0,
            'paid_count' => 0, 'total_income' => 0, 'pending_count' => 0,
            'pending_amount' => 0, 'unrecorded_count' => 0,
        ],
        'page' => 1, 'pages' => 1, 'page_size' => 35,
    ];
    $data['departments'] = queueReportQuery($connection, 'SELECT id, dept_name FROM departments ORDER BY dept_name');
    $data['services'] = queueReportQuery($connection, 'SELECT s.id, s.dept_id, s.service_name, d.dept_name FROM services s LEFT JOIN departments d ON d.id = s.dept_id ORDER BY d.dept_name, s.service_name');
    if ($filters['errors']) {
        return $data;
    }
    $selection = queueReportSelection($filters);
    $joins = $selection['joins'];
    $where = $selection['where'];
    $types = $selection['types'];
    $parameters = $selection['parameters'];
    $data['summary'] = queueReportQuery($connection, "SELECT COUNT(*) AS total_count,
        COALESCE(SUM(q.status = 'completed'), 0) AS completed_count,
        COALESCE(SUM(q.status = 'waiting'), 0) AS waiting_count,
        COALESCE(SUM(q.status IN ('called', 'now_serving')), 0) AS serving_count,
        COALESCE(SUM(q.status = 'cancelled'), 0) AS cancelled_count,
        COALESCE(SUM(q.status = 'no_show'), 0) AS no_show_count,
        COALESCE(SUM(qp.status = 'paid'), 0) AS paid_count,
        COALESCE(SUM(CASE WHEN qp.status = 'paid' THEN qp.amount ELSE 0 END), 0) AS total_income,
        COALESCE(SUM(qp.status = 'pending'), 0) AS pending_count,
        COALESCE(SUM(CASE WHEN qp.status = 'pending' THEN qp.amount ELSE 0 END), 0) AS pending_amount,
        COALESCE(SUM(qp.id IS NULL), 0) AS unrecorded_count" . $joins . $where, $types, $parameters)[0];
    $departmentWhere = $where . ($where === '' ? ' WHERE ' : ' AND ') . "q.status = 'completed'";
    $matchingDepartmentCounts = queueReportQuery($connection, "SELECT d.id AS dept_id, COALESCE(d.dept_name, 'Unassigned') AS dept_name, COUNT(*) AS ticket_count" . $joins . $departmentWhere . ' GROUP BY d.id, d.dept_name', $types, $parameters);
    $countsByDepartment = array_column($matchingDepartmentCounts, 'ticket_count', 'dept_id');
    // Keep every configured department visible, even without services or matching tickets.
    foreach ($data['departments'] as $department) {
        $data['department_counts'][] = [
            'dept_id' => (int)$department['id'],
            'dept_name' => $department['dept_name'],
            'ticket_count' => (int)($countsByDepartment[$department['id']] ?? 0),
        ];
    }
    foreach ($matchingDepartmentCounts as $department) {
        if ($department['dept_id'] === null) $data['department_counts'][] = $department;
    }
    usort($data['department_counts'], static fn($a, $b) =>
        (int)$b['ticket_count'] <=> (int)$a['ticket_count'] ?: strcasecmp($a['dept_name'], $b['dept_name']));
    $data['pages'] = max(1, (int)ceil((int)$data['summary']['total_count'] / $data['page_size']));
    $data['page'] = min($filters['page'], $data['pages']);
    $offset = ($data['page'] - 1) * $data['page_size'];
    $data['rows'] = queueReportQuery($connection, queueReportColumns() . $joins . $where . ' ORDER BY ' . $selection['order'] . ' LIMIT ? OFFSET ?', $types . 'ii', array_merge($parameters, [$data['page_size'], $offset]));
    return $data;
}
