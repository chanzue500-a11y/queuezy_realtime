<?php
session_start();
if (($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}
require_once __DIR__ . '/../database/db.php';
require_once __DIR__ . '/../includes/queue_report.php';
require_once __DIR__ . '/../includes/daily_sales.php';
require_once __DIR__ . '/../includes/queue_transactions.php';
$conn = $conn ?? null;

$filters = queueReportFilters($_GET);
$dailyFilters = dailySalesFilters($_GET);
$transactionCsrf = $_SESSION['queue_transaction_csrf'] ??= bin2hex(random_bytes(32));
$transactionNotice = $_SESSION['queue_transaction_notice'] ?? '';
unset($_SESSION['queue_transaction_notice']);
$transactionError = '';
$transactionReturnQuery = array_merge(array_diff_key($filters, ['errors' => true]), [
    'sales_from' => $dailyFilters['date_from'], 'sales_to' => $dailyFilters['date_to'], 'sales_page' => $dailyFilters['page'],
]);
foreach (['sales_open', 'sales_breakdown'] as $flag) {
    if (($_GET[$flag] ?? '') === '1') $transactionReturnQuery[$flag] = '1';
}
$transactionReturnUrl = 'reports.php?' . http_build_query($transactionReturnQuery) . '#queue-transactions';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_transaction') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($transactionCsrf, $token)) {
        http_response_code(403);
        $transactionError = 'Your session could not be verified. Refresh the report and try again.';
    } elseif (!$conn) {
        $transactionError = 'The database is unavailable. Please try again.';
    } else {
        $queueId = filter_var($_POST['queue_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $result = deleteQueueTransaction($conn, $queueId === false ? 0 : $queueId);
        if ($result['ok']) {
            $_SESSION['queue_transaction_notice'] = $result['message'];
            header('Location: ' . $transactionReturnUrl);
            exit;
        }
        $transactionError = $result['message'];
    }
}
$dailySales = null;
$dailySalesError = $dailyFilters['errors'] ? implode(' ', $dailyFilters['errors']) : '';
if ($conn && $dailySalesError === '') {
    try {
        $dailySales = dailySalesData($conn, $dailyFilters);
    } catch (Throwable $error) {
        error_log('Daily sales report failed: ' . $error->getMessage());
        $dailySalesError = 'Unable to load daily sales. Please try again.';
    }
} elseif (!$conn) {
    $dailySalesError = 'Daily sales are temporarily unavailable while the database is offline.';
}
$report = null;
$reportError = '';
if (!$conn) {
    $reportError = 'Reports are temporarily unavailable. Please try again when the database connection is restored.';
} else {
    try {
        $report = queueReportData($conn, $filters);
    } catch (Throwable $error) {
        error_log('Queue report failed: ' . $error->getMessage());
        $reportError = 'Unable to load transaction records. Please try again.';
    }
}
$departmentCounts = array_column($report['department_counts'] ?? [], 'ticket_count');
$totalDepartmentTickets = array_sum($departmentCounts);
$departmentTickStep = max(1, (int)ceil(max($departmentCounts ?: [0]) / 4));
$departmentAxisMax = $departmentTickStep * 4;
$rows = $report['rows'] ?? [];
$page = $report['page'] ?? 1;
$pages = $report['pages'] ?? 1;
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$salesPageUrl = static function (int $page) use ($dailyFilters, $filters): string {
    $query = array_diff_key($filters, ['errors' => true]);
    $query['sales_from'] = $dailyFilters['date_from'];
    $query['sales_to'] = $dailyFilters['date_to'];
    $query['sales_page'] = $page;
    $query['sales_open'] = '1';
    $query['sales_breakdown'] = '1';
    return 'reports.php?' . http_build_query($query) . '#daily-sales';
};
$salesToday = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
$salesYesterday = $salesToday->modify('-1 day')->format('Y-m-d');
$salesWeekStart = $salesToday->modify('-6 days')->format('Y-m-d');
$salesPresetUrl = static function (string $from, string $to) use ($filters): string {
    return 'reports.php?' . http_build_query(array_merge(array_diff_key($filters, ['errors' => true]),
        ['sales_from' => $from, 'sales_to' => $to, 'sales_open' => '1'])) . '#daily-sales';
};
$salesSingleDay = $dailyFilters['date_from'] === $dailyFilters['date_to'];
$salesPreset = $salesSingleDay && $dailyFilters['date_from'] === $salesYesterday ? 'yesterday'
    : ($salesSingleDay && $dailyFilters['date_from'] === $salesToday->format('Y-m-d') ? 'today'
    : ($dailyFilters['date_from'] === $salesWeekStart && $dailyFilters['date_to'] === $salesToday->format('Y-m-d') ? 'week' : 'custom'));
$salesPeriodTitle = match ($salesPreset) { 'yesterday' => 'Yesterday', 'today' => 'Today', 'week' => 'Last 7 days', default => $salesSingleDay ? 'Selected day' : 'Selected dates' };
$salesPeriodLabel = (new DateTimeImmutable($dailyFilters['date_from']))->format('M j, Y')
    . ($salesSingleDay ? '' : ' – ' . (new DateTimeImmutable($dailyFilters['date_to']))->format('M j, Y'));
$dateLabel = static function ($value): string {
    if (!$value) return '—';
    $timestamp = strtotime($value);
    return $timestamp === false ? '—' : date('M j, Y · g:i A', $timestamp);
};
$pageUrl = static function (int $target) use ($filters, $dailyFilters): string {
    $query = array_diff_key($filters, ['errors' => true]);
    $query['page'] = $target;
    $query['sales_from'] = $dailyFilters['date_from'];
    $query['sales_to'] = $dailyFilters['date_to'];
    return 'reports.php?' . http_build_query(array_filter($query, static fn($value) => $value !== '' && $value !== 0));
};
$appliedFilters = [];
if ($filters['search'] !== '') $appliedFilters[] = 'Search: ' . $filters['search'];
foreach (['department_id' => ['departments', 'dept_name'], 'service_id' => ['services', 'service_name']] as $key => $lookup) {
    if ($filters[$key] > 0) {
        $choices = array_column($report[$lookup[0]] ?? [], $lookup[1], 'id');
        $appliedFilters[] = ($key === 'department_id' ? 'Department: ' : 'Service: ') . ($choices[$filters[$key]] ?? '#' . $filters[$key]);
    }
}
if ($filters['status'] !== '') $appliedFilters[] = 'Queue: ' . queueReportStatuses()[$filters['status']];
if ($filters['payment_status'] !== '') $appliedFilters[] = 'Payment: ' . ucfirst($filters['payment_status']);
if ($filters['date_from'] !== '' || $filters['date_to'] !== '') {
    $appliedFilters[] = 'Created: ' . ($filters['date_from'] ?: 'earliest') . ' through ' . ($filters['date_to'] ?: 'latest');
}
$exportQuery = array_diff_key($filters, ['errors' => true, 'page' => true]);
$exportQuery['scope'] = 'filtered';
$filteredExportUrl = 'export_reports.php?' . http_build_query(array_filter($exportQuery, static fn($value) => $value !== '' && $value !== 0));

$pageTitle = 'Reports | Queuezy';
$pageHeading = 'Reports';
require_once '../includes/header.php';
?>

<style>
    .report-shell {
        padding: 10px 0 24px;
    }

    .report-header {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 18px;
        padding: 12px 4px 18px;
        margin-bottom: 18px;
    }

    .report-eyebrow {
        margin: 0 0 8px;
        font-size: 11px;
        font-weight: 800;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: #8b9bb7;
    }

    .report-header h1 {
        margin: 0;
        font-size: clamp(2.5rem, 2.8vw, 4rem);
        line-height: 0.96;
        letter-spacing: -0.06em;
        font-weight: 900;
        color: #f8fafc;
    }

    .report-subtitle {
        margin: 14px 0 0;
        font-size: 1.08rem;
        color: #b8c5d6;
    }

    .report-tools {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    .date-field {
        display: flex;
        align-items: center;
        gap: 12px;
        min-height: 52px;
        padding: 8px 14px 8px 10px;
        border-radius: 14px;
        border: 1px solid rgba(148, 163, 184, 0.22);
        background: rgba(15, 23, 42, 0.18);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
    }

    .date-field .label-text {
        font-size: 10px;
        font-weight: 800;
        color: #aebed4;
        text-transform: uppercase;
        letter-spacing: 0.08em;
    }

    .date-field input {
        border: none;
        background: transparent;
        color: #f8fafc;
        font: 700 14px/1.2 'Inter', sans-serif;
        min-width: 118px;
        outline: none;
        padding: 0;
        margin: 0;
    }

    .export-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 0.8rem 1.1rem;
        border: 1px solid rgba(167, 139, 250, 0.5);
        border-radius: 12px;
        background: linear-gradient(135deg, rgba(124, 58, 237, 0.24), rgba(168, 85, 247, 0.16));
        color: #f5f3ff;
        font-size: 0.82rem;
        font-weight: 800;
        letter-spacing: 0.02em;
        transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
        cursor: pointer;
    }

    .export-btn:hover {
        transform: translateY(-1px);
        border-color: rgba(196, 181, 253, 0.9);
        box-shadow: 0 12px 25px rgba(124, 58, 237, 0.18);
    }

    .report-kpis {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 18px;
        margin-bottom: 22px;
    }

    .kpi-card {
        position: relative;
        overflow: hidden;
        padding: 20px 20px 18px;
        border-radius: 22px;
        border: 1px solid rgba(148, 163, 184, 0.18);
        background: linear-gradient(180deg, rgba(19, 28, 43, 0.9), rgba(15, 23, 42, 0.95));
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04), 0 16px 30px rgba(15, 23, 42, 0.15);
    }

    .kpi-card::before {
        content: '';
        position: absolute;
        inset: 0 auto auto 0;
        width: 100%;
        height: 3px;
        background: linear-gradient(90deg, rgba(124, 58, 237, 0.9), rgba(168, 85, 247, 0.5));
        opacity: 0.85;
    }

    .kpi-top {
        display: flex;
        align-items: center;
        justify-content: flex-start;
        margin-bottom: 18px;
    }

    .kpi-label {
        color: #dfe9f6;
        font-size: 0.9rem;
        font-weight: 700;
        letter-spacing: -0.02em;
        line-height: 1.3;
    }

    .kpi-value {
        display: flex;
        align-items: flex-end;
        gap: 8px;
        margin-bottom: 6px;
        min-height: 52px;
    }

    .kpi-value strong {
        font-size: clamp(2rem, 2vw, 2.75rem);
        line-height: 0.9;
        letter-spacing: -0.06em;
        color: #f8fafc;
        font-weight: 900;
    }

    .kpi-value span {
        font-size: 0.72rem;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: #7ea0c2;
        padding-bottom: 6px;
        white-space: nowrap;
    }

    .kpi-meta {
        font-size: 0.82rem;
        color: #b2bfd1;
        margin: 0;
        line-height: 1.5;
    }

    .kpi-meta.positive {
        color: #8fe6c5;
        font-weight: 700;
    }

    .financial-summary {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 18px;
        margin: 8px 0 24px;
    }

    .financial-summary__card {
        padding: 18px 20px;
        border-radius: 18px;
        border: 1px solid rgba(148, 163, 184, 0.18);
        background: rgba(15, 23, 42, 0.6);
        box-shadow: inset 0 1px 0 rgba(255,255,255,0.03);
    }

    .financial-summary__label {
        display: block;
        margin-bottom: 8px;
        font-size: 0.72rem;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #9fb0c7;
    }

    .financial-summary__value {
        font-size: clamp(1.6rem, 2vw, 2.2rem);
        font-weight: 900;
        color: #f8fafc;
        letter-spacing: -0.04em;
    }

    .financial-summary__value small {
        font-size: 0.72rem;
        color: #a9b7cc;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        margin-left: 6px;
    }

    .chart-panel {
        margin-top: 12px;
        background: linear-gradient(180deg, rgba(19, 28, 43, 0.9), rgba(15, 23, 42, 0.96));
        border: 1px solid rgba(148, 163, 184, 0.18);
        border-radius: 24px;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04), 0 18px 32px rgba(15, 23, 42, 0.14);
        overflow: hidden;
    }

    .chart-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 24px 26px 18px;
        border-bottom: 1px solid rgba(148, 163, 184, 0.12);
    }

    .chart-title {
        margin: 0;
        font-size: 1.1rem;
        font-weight: 900;
        color: #f8fafc;
    }

    .chart-subtitle {
        margin: 6px 0 0;
        color: #9fb0c7;
        font-size: 0.9rem;
    }

    .status-pill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 7px 12px;
        border-radius: 999px;
        background: rgba(16, 185, 129, 0.12);
        border: 1px solid rgba(52, 211, 153, 0.25);
        color: #a7f3d0;
        font-size: 0.72rem;
        font-weight: 800;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    .status-pill::before {
        content: '';
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: #34d399;
        box-shadow: 0 0 0 6px rgba(52, 211, 153, 0.15);
    }

    .chart-wrap {
        height: 420px;
        padding: 18px 18px 18px;
    }

    @media print {
        body {
            background: #fff !important;
            color: #111827 !important;
        }

        .report-tools,
        .report-eyebrow,
        .status-pill,
        .chart-head .chart-subtitle,
        .chart-wrap {
            display: none !important;
        }

        .report-shell {
            padding: 0;
        }

        .report-header {
            margin-bottom: 16px;
        }

        .report-header h1 {
            font-size: 28px !important;
            color: #111827 !important;
        }

        .report-subtitle {
            color: #374151 !important;
        }

        .kpi-card,
        .financial-summary__card,
        .chart-panel {
            background: #fff !important;
            color: #111827 !important;
            box-shadow: none !important;
            border-color: #d1d5db !important;
            break-inside: avoid;
        }

        .kpi-label,
        .kpi-meta,
        .financial-summary__label,
        .financial-summary__value,
        .chart-title,
        .kpi-value strong,
        .kpi-value span {
            color: #111827 !important;
        }

        .kpi-meta.positive {
            color: #1f9d68 !important;
        }
    }

    @media (max-width: 1100px) {
        .report-kpis {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 640px) {
        .report-header {
            flex-direction: column;
            align-items: stretch;
        }

        .report-tools {
            justify-content: stretch;
        }

        .date-field {
            flex: 1 1 100%;
            justify-content: space-between;
        }

        .report-kpis {
            grid-template-columns: 1fr;
        }

        .chart-head {
            flex-direction: column;
            align-items: flex-start;
        }
    }

    .report-shell { padding: 8px 0 28px; }
    .report-header { align-items: center; padding: 8px 0 18px; margin-bottom: 16px; }
    .report-eyebrow { margin-bottom: 6px; color: #94a3b8; font-size: 10px; letter-spacing: 0; }
    .report-header h1 { color: #f8fafc; font-size: 28px; line-height: 1.2; letter-spacing: 0; font-weight: 800; }
    .report-subtitle { margin-top: 5px; color: #94a3b8; font-size: 13px; }
    .report-tools { gap: 8px; }
    .export-btn { min-height: 38px; padding: 8px 12px; border-color: #475569; border-radius: 8px; background: #1e293b; color: #e2e8f0; letter-spacing: 0; box-shadow: none; }
    .export-btn:hover { transform: none; border-color: #64748b; box-shadow: none; }
    .report-kpis { gap: 0; margin-bottom: 20px; border: 1px solid #334155; border-radius: 8px; background: #1e293b; overflow: hidden; }
    .kpi-card { min-height: 112px; padding: 16px 18px; border: 0; border-right: 1px solid #334155; border-radius: 0; background: transparent; box-shadow: none; }
    .kpi-card:last-child { border-right: 0; }
    .kpi-card::before { display: none; }
    .kpi-top { margin-bottom: 12px; }
    .kpi-label { color: #94a3b8; font-size: 11px; text-transform: uppercase; letter-spacing: 0; }
    .kpi-value { min-height: 0; margin-bottom: 5px; }
    .kpi-value strong { color: #f8fafc; font-size: 24px; line-height: 1.1; letter-spacing: 0; font-weight: 800; }
    .kpi-value span { padding-bottom: 2px; color: #94a3b8; font-size: 11px; letter-spacing: 0; }
    .kpi-meta { color: #94a3b8; font-size: 11px; }
    .kpi-meta.positive { color: #6ee7b7; }
    .financial-summary { gap: 0; margin: 0 0 20px; border: 1px solid #334155; border-radius: 8px; background: #1e293b; overflow: hidden; }
    .financial-summary__card { padding: 15px 18px; border: 0; border-right: 1px solid #334155; border-radius: 0; background: transparent; box-shadow: none; }
    .financial-summary__card:last-child { border-right: 0; }
    .financial-summary__label { margin-bottom: 5px; color: #94a3b8; font-size: 10px; letter-spacing: 0; }
    .financial-summary__value { color: #f8fafc; font-size: 18px; letter-spacing: 0; font-weight: 800; }
    .financial-summary__value small { color: #94a3b8; font-size: 10px; letter-spacing: 0; }
    .chart-panel { margin-top: 0; border: 1px solid #334155; border-radius: 8px; background: #1e293b; box-shadow: none; }
    .chart-head { padding: 16px 18px; border-bottom-color: #334155; }
    .chart-title { color: #f8fafc; font-size: 15px; font-weight: 800; }
    .chart-subtitle { margin-top: 4px; color: #94a3b8; font-size: 12px; }
    .report-data-section { margin-top: 20px; }
    .department-volume-summary { flex-shrink: 0; padding: 7px 11px; border: 1px solid #475569; border-radius: 9px; color: #e2e8f0; font-size: 12px; font-weight: 700; }
    .department-chart-scroll { padding: 22px 24px; overflow-x: auto; scrollbar-width: thin; scrollbar-color: #64748b #1e293b; }
    .department-chart { min-width: var(--chart-min-width); }
    .department-chart-axis-title { margin: 0 0 28px; color: #94a3b8; font-size: 11px; }
    .department-chart-body { display: grid; grid-template-columns: 40px minmax(0, 1fr); }
    .department-chart-y-axis { height: 240px; position: relative; color: #94a3b8; font-size: 11px; font-variant-numeric: tabular-nums; }
    .department-chart-y-axis span { position: absolute; right: 12px; transform: translateY(50%); }
    .department-chart-columns { display: grid; grid-template-columns: repeat(var(--departments), minmax(0, 1fr)); list-style: none; margin: 0; padding: 0; position: relative; }
    .department-chart-columns::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 240px; border-left: 1px solid #64748b; border-bottom: 1px solid #64748b; background-image: linear-gradient(to top, #334155 1px, transparent 1px); background-size: 100% 25%; pointer-events: none; }
    .department-chart-column { min-width: 0; position: relative; }
    .department-chart-plot { height: 240px; display: flex; align-items: flex-end; justify-content: center; }
    .department-chart-bar { display: block; position: relative; width: 52%; max-width: 64px; background: #a78bfa; border-radius: 3px 3px 0 0; }
    .department-volume-count { position: absolute; bottom: calc(100% + 8px); left: 50%; transform: translateX(-50%); color: #f8fafc; font-size: 13px; font-weight: 800; font-variant-numeric: tabular-nums; }
    .department-volume-name { display: block; padding: 12px 8px 0; color: #cbd5e1; font-size: 11px; line-height: 1.5; overflow-wrap: anywhere; text-align: center; }
    .department-chart-x-title { margin: 18px 0 0 40px; color: #94a3b8; font-size: 11px; text-align: center; }
    .department-volume-head { gap: 12px; flex-wrap: wrap; }
    .department-empty { padding: 20px 18px; color: #94a3b8; font-size: 13px; }
    .report-table-wrap { overflow-x: auto; border: 1px solid #334155; border-radius: 8px; background: #1e293b; }
    .report-table { width: 100%; min-width: 620px; border-collapse: collapse; text-align: left; }
    .report-table th { padding: 11px 14px; border-bottom: 1px solid #334155; color: #94a3b8; font-size: 10px; font-weight: 800; text-transform: uppercase; }
    .report-table td { padding: 12px 14px; border-bottom: 1px solid #293548; color: #e2e8f0; font-size: 12px; }
    .report-table tbody tr:last-child td { border-bottom: 0; }
    .report-table .ticket-status { color: #6ee7b7; font-weight: 700; text-transform: capitalize; }
    .report-empty { padding: 20px 14px !important; color: #94a3b8 !important; text-align: center; }
    @media (max-width: 760px) {
        .report-header { align-items: flex-start; }
        .report-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .kpi-card:nth-child(2) { border-right: 0; }
        .kpi-card:nth-child(-n+2) { border-bottom: 1px solid #334155; }
        .financial-summary { grid-template-columns: 1fr; }
        .financial-summary__card { border-right: 0; border-bottom: 1px solid #334155; }
        .financial-summary__card:last-child { border-bottom: 0; }
        .department-chart-scroll { padding: 18px 12px; }
    }
    @media print {
        @page { size: landscape; margin: 12mm; }

        html, body {
            width: auto !important;
            min-width: 0 !important;
            min-height: 0 !important;
            height: auto !important;
            overflow: visible !important;
            background: #ffffff !important;
            color: #111827 !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .report-tools, .queue-sidebar, .queue-topbar { display: none !important; }
        .queue-layout { display: block !important; width: 100% !important; min-height: 0 !important; overflow: visible !important; }
        .queue-main, .queue-content {
            display: block !important;
            width: 100% !important;
            max-width: none !important;
            min-width: 0 !important;
            min-height: 0 !important;
            height: auto !important;
            margin: 0 !important;
            padding: 0 !important;
            overflow: visible !important;
            flex: none !important;
            transform: none !important;
            animation: none !important;
        }
        .queue-footer, .logout-modal { display: none !important; }
        .report-shell { width: 100% !important; max-width: none !important; padding: 0 !important; margin: 0 !important; }
        .report-header { padding: 0 0 12px !important; margin: 0 0 12px !important; }
        .report-kpis, .financial-summary { break-inside: avoid; }
        .report-kpis { grid-template-columns: repeat(4, minmax(0, 1fr)) !important; }
        .financial-summary { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
        .chart-panel, .report-data-section { break-inside: avoid; }
        .report-table-wrap { overflow: visible !important; }
        .report-table { width: 100% !important; min-width: 0 !important; table-layout: auto !important; }
        .report-table thead { display: table-header-group; }
        .report-table tr { break-inside: avoid; }
        .report-header h1, .kpi-value strong, .financial-summary__value, .chart-title { color: #111827 !important; }
        .report-kpis, .financial-summary, .kpi-card, .financial-summary__card, .chart-panel, .report-table-wrap { background: #fff !important; border-color: #cbd5e1 !important; box-shadow: none !important; }
        .kpi-card, .financial-summary__card { border-right: 1px solid #cbd5e1 !important; }
        .kpi-card:last-child, .financial-summary__card:last-child { border-right: 0 !important; }
        .kpi-label, .kpi-meta, .financial-summary__label, .department-volume-name, .department-chart-y-axis, .department-chart-axis-title, .department-chart-x-title, .department-volume-summary, .department-volume-count, .chart-subtitle, .report-table td { color: #334155 !important; }
        .department-chart-scroll { overflow: visible; }
        .department-chart { min-width: 0; }
        .report-table th, .report-table td { border-color: #cbd5e1 !important; }
    }
</style>
<link rel="stylesheet" href="../assets/css/queue-report.css">

<div class="report-shell">
    <header class="report-header">
        <div>
            <p class="report-eyebrow">Operations</p>
            <h1>Reports</h1>
            <p class="report-subtitle">Review queue transactions, selected services, dates, and payments.</p>
        </div>
    </header>

    <details class="chart-panel daily-sales-section" id="daily-sales" <?= (($_GET['sales_open'] ?? '') === '1' || $dailySalesError !== '') ? 'open' : '' ?>>
        <summary class="chart-head daily-sales-toggle">
            <div>
                <h2 id="daily-sales-title" class="chart-title">Daily sales</h2>
                <p class="chart-subtitle">Sales and completed tickets for each business day</p>
            </div>
            <span class="daily-sales-toggle-label" aria-hidden="true"></span>
            <span class="daily-sales-chevron" aria-hidden="true">›</span>
        </summary>
        <div class="daily-sales-content">
        <div class="daily-sales-toolbar">
        <nav class="daily-sales-presets" aria-label="Choose sales period">
            <a href="<?= $escape($salesPresetUrl($salesYesterday, $salesYesterday)) ?>" <?= $salesPreset === 'yesterday' ? 'aria-current="page"' : '' ?>>Yesterday</a>
            <a href="<?= $escape($salesPresetUrl($salesToday->format('Y-m-d'), $salesToday->format('Y-m-d'))) ?>" <?= $salesPreset === 'today' ? 'aria-current="page"' : '' ?>>Today</a>
            <a href="<?= $escape($salesPresetUrl($salesWeekStart, $salesToday->format('Y-m-d'))) ?>" <?= $salesPreset === 'week' ? 'aria-current="page"' : '' ?>>Last 7 days</a>
        </nav>
        <details class="daily-sales-custom" <?= $dailySalesError !== '' ? 'open' : '' ?>>
        <summary>Choose dates <span aria-hidden="true">▾</span></summary>
        <form class="daily-sales-controls" method="get" action="reports.php#daily-sales" aria-label="Daily sales date range">
            <input type="hidden" name="sales_open" value="1">
            <?php foreach (array_diff_key($filters, ['errors' => true]) as $key => $value): ?>
            <input type="hidden" name="<?= $escape($key) ?>" value="<?= $escape($value) ?>">
            <?php endforeach; ?>
            <label>Start date<input type="date" name="sales_from" required min="1000-01-01" max="<?= $salesToday->format('Y-m-d') ?>" value="<?= $escape($dailyFilters['date_from']) ?>"></label>
            <label>End date<input type="date" name="sales_to" required min="1000-01-01" max="<?= $salesToday->format('Y-m-d') ?>" value="<?= $escape($dailyFilters['date_to']) ?>"></label>
            <button type="submit" class="export-btn">Show sales</button>
        </form>
        </details>
        </div>
        <?php if ($dailySalesError !== ''): ?>
        <p class="report-error" role="alert"><?= $escape($dailySalesError) ?></p>
        <?php elseif ($dailySales !== null): ?>
        <div class="daily-sales-period">
            <div><h3><?= $escape($salesPeriodTitle) ?></h3><p><?= $escape($salesPeriodLabel) ?></p></div>
            <span class="daily-sales-hours"><i class="fa-regular fa-clock" aria-hidden="true"></i> 7:30 AM–5:00 PM <small>Philippine time</small></span>
        </div>
        <div class="daily-sales-totals" aria-label="Sales totals for the selected date range">
            <div class="daily-sales-money"><span>Sales during business hours</span><strong>&#8369;<?= number_format($dailySales['total_sales_cents'] / 100, 2) ?></strong><small>Paid tickets completed from 7:30 AM to 5:00 PM</small></div>
            <div><span>Completed during business hours</span><strong><?= number_format($dailySales['total_tickets']) ?></strong><small>Finished from 7:30 AM to 5:00 PM</small></div>
        </div>
        <?php if ($dailySales['outside_tickets'] > 0): ?>
        <div class="daily-sales-outside" role="note">
            <i class="fa-regular fa-clock" aria-hidden="true"></i>
            <div><strong><?= number_format($dailySales['outside_tickets']) ?> paid <?= $dailySales['outside_tickets'] === 1 ? 'ticket was' : 'tickets were' ?> completed outside business hours</strong>
            <p>&#8369;<?= number_format($dailySales['outside_sales_cents'] / 100, 2) ?> is excluded from the totals above. <?= $dailySales['outside_tickets'] === 1 ? 'Completed' : 'Most recent completion' ?>: <?= $escape((new DateTimeImmutable($dailySales['latest_outside_at'], new DateTimeZone('Asia/Manila')))->format('M j, Y · g:i A')) ?>, Philippine time.</p></div>
        </div>
        <?php elseif ($dailySales['total_tickets'] === 0): ?>
        <p class="daily-sales-empty"><i class="fa-regular fa-calendar" aria-hidden="true"></i> No paid tickets were completed during these hours<?= $salesSingleDay ? ' on this date' : ' in this period' ?>. Try another date.</p>
        <?php elseif ($salesPreset === 'today'): ?>
        <p class="daily-sales-note">Today's sales are a running total until the 5:00 PM cutoff.</p>
        <?php endif; ?>
        <?php if (!$salesSingleDay): ?>
        <details class="daily-sales-breakdown" <?= ($_GET['sales_breakdown'] ?? '') === '1' ? 'open' : '' ?>>
        <summary>View daily breakdown <span><?= number_format($dailySales['days']) ?> days <span aria-hidden="true">▾</span></span></summary>
        <div class="daily-sales-table-wrap">
            <table class="daily-sales-table">
                <thead><tr><th scope="col">Date</th><th scope="col">Completed tickets</th><th scope="col">Sales</th></tr></thead>
                <tbody>
                    <?php foreach ($dailySales['rows'] as $salesDay): ?>
                    <tr>
                        <th scope="row"><?= $escape((new DateTimeImmutable($salesDay['date']))->format('D, M j, Y')) ?><?php if ($salesDay['date'] === $salesToday->format('Y-m-d')): ?> <span class="daily-sales-today">Today · running total</span><?php endif; ?></th>
                        <td><?= number_format($salesDay['completed_tickets']) ?></td>
                        <td>&#8369;<?= number_format($salesDay['sales_cents'] / 100, 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($dailySales['pages'] > 1): ?>
        <nav class="report-pagination" aria-label="Daily sales pagination">
            <span><?= number_format($dailySales['days']) ?> days · 10 per page · Page <?= $dailySales['page'] ?> of <?= $dailySales['pages'] ?></span>
            <div>
                <?php if ($dailySales['page'] > 1): ?><a class="export-btn" href="<?= $escape($salesPageUrl($dailySales['page'] - 1)) ?>">&lt; Previous</a><?php endif; ?>
                <?php if ($dailySales['page'] < $dailySales['pages']): ?><a class="export-btn" href="<?= $escape($salesPageUrl($dailySales['page'] + 1)) ?>">Next &gt;</a><?php endif; ?>
            </div>
        </nav>
        <?php endif; ?>
        </details>
        <?php endif; ?>
        <?php endif; ?>
        </div>
    </details>

    <form class="report-filters" method="get" action="reports.php" aria-label="Filter queue transactions">
        <input type="hidden" name="sales_from" value="<?= $escape($dailyFilters['date_from']) ?>">
        <input type="hidden" name="sales_to" value="<?= $escape($dailyFilters['date_to']) ?>">
        <label class="report-search" for="report-search">Search records
            <input type="search" id="report-search" name="search" maxlength="120" value="<?= $escape($filters['search']) ?>" placeholder="Ticket, customer, service, or department">
        </label>
        <label for="report-department">Department
            <select id="report-department" name="department_id">
                <option value="">All departments</option>
                <?php foreach ($report['departments'] ?? [] as $department): ?>
                <option value="<?= (int)$department['id'] ?>" <?= $filters['department_id'] === (int)$department['id'] ? 'selected' : '' ?>><?= $escape($department['dept_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label for="report-service">Selected service
            <select id="report-service" name="service_id">
                <option value="">All services</option>
                <?php foreach ($report['services'] ?? [] as $service): ?>
                <option value="<?= (int)$service['id'] ?>" data-department="<?= (int)$service['dept_id'] ?>" <?= $filters['service_id'] === (int)$service['id'] ? 'selected' : '' ?>><?= $escape($service['service_name'] . ' — ' . ($service['dept_name'] ?? 'Unassigned')) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label for="report-status">Queue status
            <select id="report-status" name="status">
                <option value="">All statuses</option>
                <?php foreach (queueReportStatuses() as $value => $label): ?>
                <option value="<?= $escape($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= $escape($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label for="report-date-from">Created from
            <input type="date" id="report-date-from" name="date_from" min="1000-01-01" max="9999-12-31" value="<?= $escape($filters['date_from']) ?>">
        </label>
        <label for="report-date-to">Created through
            <input type="date" id="report-date-to" name="date_to" min="1000-01-01" max="9999-12-31" value="<?= $escape($filters['date_to']) ?>">
        </label>
        <label for="report-payment">Payment status
            <select id="report-payment" name="payment_status">
                <option value="">All payments</option>
                <option value="pending" <?= $filters['payment_status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="paid" <?= $filters['payment_status'] === 'paid' ? 'selected' : '' ?>>Paid</option>
            </select>
        </label>
        <label for="report-sort">Sort by
            <select id="report-sort" name="sort">
                <?php foreach (queueReportSorts() as $value => $sort): ?>
                <option value="<?= $escape($value) ?>" <?= $filters['sort'] === $value ? 'selected' : '' ?>><?= $escape($sort[0]) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="report-filter-actions">
            <button class="export-btn" type="submit">Apply filters</button>
            <a class="report-reset" href="reports.php">Reset</a>
        </div>
    </form>
    <?php if ($reportError !== '' || $filters['errors']): ?>
    <div class="report-error" role="alert"><?= $escape($reportError !== '' ? $reportError : implode(' ', $filters['errors'])) ?></div>
    <?php endif; ?>
    <?php if ($reportError === '' && !$filters['errors']): ?>
    <details class="chart-panel department-volume-panel">
        <summary class="chart-head department-volume-head daily-sales-toggle">
            <div>
                <h2 id="department-report-title" class="chart-title">Completed tickets by department</h2>
            </div>
            <span class="department-volume-summary"><?= number_format(count($report['departments'])) ?> departments · <?= number_format($totalDepartmentTickets) ?> completed <?= $totalDepartmentTickets === 1 ? 'ticket' : 'tickets' ?></span>
            <span class="daily-sales-toggle-label" aria-hidden="true"></span>
            <span class="daily-sales-chevron" aria-hidden="true">›</span>
        </summary>
        <?php if ($report['department_counts']): ?>
        <div class="department-chart-scroll" tabindex="0" role="region" aria-label="Department bar graph; scroll horizontally to see all departments">
        <div class="department-chart" role="group" aria-labelledby="department-report-title" aria-describedby="department-volume-description" style="--departments: <?= count($report['department_counts']) ?>; --chart-min-width: <?= max(480, count($report['department_counts']) * 90 + 40) ?>px;">
            <p class="department-chart-axis-title" aria-hidden="true">Completed tickets</p>
            <div class="department-chart-body">
                <div class="department-chart-y-axis" aria-hidden="true">
                    <?php for ($tick = 0; $tick <= 4; $tick++): ?><span style="bottom: <?= $tick * 25 ?>%;"><?= number_format($tick * $departmentTickStep) ?></span><?php endfor; ?>
                </div>
                <ul class="department-chart-columns">
            <?php foreach ($report['department_counts'] as $department): ?>
            <?php $barHeight = ((int)$department['ticket_count'] / $departmentAxisMax) * 100; ?>
            <li class="department-chart-column">
                <div class="department-chart-plot">
                    <span class="department-chart-bar" style="height: <?= number_format($barHeight, 2, '.', '') ?>%;">
                        <strong class="department-volume-count" aria-label="<?= (int)$department['ticket_count'] ?> completed tickets"><?= number_format((int)$department['ticket_count']) ?></strong>
                    </span>
                </div>
                <span class="department-volume-name"><?= $escape($department['dept_name']) ?></span>
            </li>
            <?php endforeach; ?>
                </ul>
            </div>
        </div>
        </div>
        <?php else: ?>
        <p class="department-empty">No department ticket data is available.</p>
        <?php endif; ?>
    </details>

    <section class="report-data-section" id="queue-transactions" aria-labelledby="queue-transactions-title">
        <div class="chart-head">
            <div>
                <h2 id="queue-transactions-title" class="chart-title">Queue transactions</h2>
            </div>
            <div class="report-export-tools">
                <?php if ($appliedFilters): ?>
                <a class="export-btn" href="<?= $escape($filteredExportUrl) ?>">Export filtered</a>
                <?php else: ?>
                <a class="export-btn report-excel-btn" href="export_reports.php?scope=all" aria-label="Export all transactions to Excel">
                    <i data-feather="download" aria-hidden="true"></i><span>Export Excel</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($transactionNotice !== ''): ?><p class="report-transaction-notice" role="status"><?= $escape($transactionNotice) ?></p><?php endif; ?>
        <?php if ($transactionError !== ''): ?><p class="report-error" role="alert"><?= $escape($transactionError) ?></p><?php endif; ?>
        <div class="report-table-wrap">
            <table class="report-table">
                <thead><tr><th scope="col">Ticket</th><th scope="col">Customer</th><th scope="col">Selected service</th><th scope="col">Queue dates</th><th scope="col">Queue status</th><th scope="col">Payment details</th><th scope="col" class="report-transaction-action">Action</th></tr></thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><strong><?= $escape($row['ticket_number']) ?></strong><small>Queue #<?= (int)$row['id'] ?></small></td>
                        <td><?= $escape($row['fullname'] ?? 'Unknown customer') ?></td>
                        <td><strong><?= $escape($row['service_name'] ?? 'Unavailable service') ?></strong><small><?= $escape($row['dept_name'] ?? 'Unassigned') ?></small></td>
                        <td class="report-dates">
                            <span><b>Created</b> <?= $escape($dateLabel($row['created_at'])) ?></span>
                            <span><b>Completed</b> <?= $escape($dateLabel($row['completed_at'])) ?></span>
                        </td>
                        <td><span class="report-badge" data-status="<?= $escape($row['status']) ?>"><?= $escape(queueReportStatuses()[$row['status']] ?? ucfirst(str_replace('_', ' ', $row['status']))) ?></span></td>
                        <td class="report-payment">
                            <?php if (in_array($row['status'], ['cancelled', 'no_show'], true)): ?>
                            <strong>--</strong>
                            <?php else: ?>
                            <strong>&#8369;<?= number_format((float)$row['amount'], 2) ?></strong>
                            <span class="report-badge" data-status="<?= $escape($row['payment_status']) ?>"><?= $row['payment_status'] === 'paid' ? 'Paid' : 'Pending' ?></span>
                            <?php if ($row['payment_id'] === null): ?>
                            <small>Current fee estimate · No payment recorded</small>
                            <?php else: ?>
                            <small>Payment #<?= (int)$row['payment_id'] ?></small>
                            <?php endif; ?>
                            <small>Paid at: <?= $escape($dateLabel($row['paid_at'])) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="report-transaction-action">
                            <form method="post" action="<?= $escape($transactionReturnUrl) ?>" data-ticket="<?= $escape($row['ticket_number']) ?>" onsubmit="return confirm('Delete ticket #' + this.dataset.ticket + '? Its payment and rating records will also be deleted.');">
                                <input type="hidden" name="action" value="delete_transaction">
                                <input type="hidden" name="csrf_token" value="<?= $escape($transactionCsrf) ?>">
                                <input type="hidden" name="queue_id" value="<?= (int)$row['id'] ?>">
                                <button class="report-transaction-delete" type="submit" aria-label="Delete transaction <?= $escape($row['ticket_number']) ?>"><i data-feather="trash-2" aria-hidden="true"></i><span>Delete</span></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?><tr><td colspan="7" class="report-empty">No transactions match these filters. Adjust the filters or reset to view all records.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pages > 1): ?>
        <nav class="report-pagination" aria-label="Transaction pages">
            <div>
                <?php if ($page > 1): ?><a class="export-btn" href="<?= $escape($pageUrl($page - 1)) ?>">Previous</a><?php endif; ?>
                <?php if ($page < $pages): ?><a class="export-btn" href="<?= $escape($pageUrl($page + 1)) ?>">Next</a><?php endif; ?>
            </div>
        </nav>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>

<script>
if (typeof feather !== 'undefined') feather.replace();

const departmentFilter = document.getElementById('report-department');
const serviceFilter = document.getElementById('report-service');
const updateServiceChoices = () => {
    const department = departmentFilter.value;
    Array.from(serviceFilter.options).forEach((option) => {
        const excluded = Boolean(department && option.value && option.dataset.department !== department);
        option.hidden = excluded;
        option.disabled = excluded;
    });
};
departmentFilter.addEventListener('change', () => {
    const selected = serviceFilter.selectedOptions[0];
    if (departmentFilter.value && selected?.value && selected.dataset.department !== departmentFilter.value) {
        serviceFilter.value = '';
    }
    updateServiceChoices();
});
updateServiceChoices();

const queueMenu = document.getElementById('queueMenu');
if (queueMenu) {
    queueMenu.addEventListener('click', function() {
        const sidebar = document.getElementById('queueSidebar');
        if (sidebar) sidebar.classList.toggle('is-open');
    });
}
</script>

</div>
</main>
</div>
</body>
</html>
