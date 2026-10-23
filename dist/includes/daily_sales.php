<?php
require_once __DIR__ . '/queue_report.php';

function dailySalesFilters(array $input, ?DateTimeImmutable $now = null): array
{
    $today = ($now ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))
        ->setTimezone(new DateTimeZone('Asia/Manila'))->setTime(0, 0);
    $filters = ['date_from' => $today->modify('-6 days')->format('Y-m-d'), 'date_to' => $today->format('Y-m-d'), 'page' => 1, 'errors' => []];
    foreach (['sales_from' => 'date_from', 'sales_to' => 'date_to'] as $parameter => $field) {
        if (!isset($input[$parameter]) || $input[$parameter] === '') continue;
        $text = is_string($input[$parameter]) ? $input[$parameter] : '';
        $date = preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $text)
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $text, new DateTimeZone('Asia/Manila')) : false;
        if (!$date || $date->format('Y-m-d') !== $text || $text < '1000-01-01') {
            $filters['errors'][] = 'Choose valid daily sales dates.';
        } else {
            $filters[$field] = $text;
        }
    }
    if ($filters['date_from'] > $filters['date_to']) $filters['errors'][] = 'The sales start date must be on or before the end date.';
    if ($filters['date_to'] > $today->format('Y-m-d')) $filters['errors'][] = 'Daily sales dates cannot be in the future.';
    $start = new DateTimeImmutable($filters['date_from'], new DateTimeZone('Asia/Manila'));
    $end = new DateTimeImmutable($filters['date_to'], new DateTimeZone('Asia/Manila'));
    if ($start->diff($end)->days > 365) $filters['errors'][] = 'Choose a daily sales range of up to 366 days.';
    $page = filter_var($input['sales_page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $filters['page'] = $page === false ? 1 : $page;
    $filters['errors'] = array_values(array_unique($filters['errors']));
    return $filters;
}

function dailySalesData(mysqli $connection, array $filters): array
{
    if ($filters['errors']) throw new InvalidArgumentException(implode(' ', $filters['errors']));
    $results = queueReportQuery($connection, "SELECT DATE(q.completed_at) AS sales_date,
        COUNT(*) AS completed_tickets, CAST(SUM(qp.amount) * 100 AS SIGNED) AS sales_cents
        FROM queue q INNER JOIN queue_payments qp ON qp.queue_id = q.id AND qp.status = 'paid'
        WHERE q.status = 'completed' AND q.completed_at >= ? AND q.completed_at <= ?
        AND TIME(q.completed_at) BETWEEN '08:30:00' AND '17:00:00'
        GROUP BY DATE(q.completed_at) ORDER BY sales_date DESC", 'ss',
        [$filters['date_from'] . ' 08:30:00', $filters['date_to'] . ' 17:00:00']);
    $byDate = array_column($results, null, 'sales_date');
    $rows = [];
    $totalTickets = 0;
    $totalSales = 0;
    $start = new DateTimeImmutable($filters['date_from'], new DateTimeZone('Asia/Manila'));
    $end = new DateTimeImmutable($filters['date_to'], new DateTimeZone('Asia/Manila'));
    for ($day = $end; $day >= $start; $day = $day->modify('-1 day')) {
        $date = $day->format('Y-m-d');
        $tickets = (int)($byDate[$date]['completed_tickets'] ?? 0);
        $sales = (int)($byDate[$date]['sales_cents'] ?? 0);
        $rows[] = ['date' => $date, 'completed_tickets' => $tickets, 'sales_cents' => $sales];
        $totalTickets += $tickets;
        $totalSales += $sales;
    }
    $pages = max(1, (int)ceil(count($rows) / 10));
    $page = min($filters['page'], $pages);
    return ['rows' => array_slice($rows, ($page - 1) * 10, 10), 'days' => count($rows), 'page' => $page,
        'pages' => $pages, 'total_tickets' => $totalTickets, 'total_sales_cents' => $totalSales];
}
