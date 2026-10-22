<?php
session_start();
if (($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}
require_once __DIR__ . '/../database/db.php';
require_once __DIR__ . '/../includes/queue_report.php';
$conn = $conn ?? null;

$filters = queueReportFilters($_GET);
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
$summary = $report['summary'] ?? [];
$totalQueueCount = (int)($summary['total_count'] ?? 0);
$completedQueueCount = (int)($summary['completed_count'] ?? 0);
$waitingQueueCount = (int)($summary['waiting_count'] ?? 0);
$servingQueueCount = (int)($summary['serving_count'] ?? 0);
$cancelledQueueCount = (int)($summary['cancelled_count'] ?? 0);
$noShowQueueCount = (int)($summary['no_show_count'] ?? 0);
$departmentCounts = array_column($report['department_counts'] ?? [], 'ticket_count');
$maxDepartmentTickets = max($departmentCounts ?: [0]);
$rows = $report['rows'] ?? [];
$page = $report['page'] ?? 1;
$pages = $report['pages'] ?? 1;
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$dateLabel = static function ($value): string {
    if (!$value) return '—';
    $timestamp = strtotime($value);
    return $timestamp === false ? '—' : date('M j, Y · g:i A', $timestamp);
};
$pageUrl = static function (int $target) use ($filters): string {
    $query = array_diff_key($filters, ['errors' => true]);
    $query['page'] = $target;
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
    .department-bars { display: grid; gap: 13px; padding: 18px; }
    .department-bar-row { display: grid; grid-template-columns: minmax(110px, 220px) minmax(0, 1fr) 48px; align-items: center; gap: 12px; }
    .department-bar-name { overflow: hidden; color: #cbd5e1; font-size: 12px; text-overflow: ellipsis; white-space: nowrap; }
    .department-bar-track { height: 8px; overflow: hidden; border-radius: 99px; background: #334155; }
    .department-bar-fill { display: block; height: 100%; min-width: 2px; border-radius: inherit; background: #34d399; }
    .department-bar-count { color: #f8fafc; font-size: 12px; font-weight: 700; text-align: right; }
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
        .department-bar-row { grid-template-columns: minmax(90px, 135px) minmax(0, 1fr) 34px; gap: 8px; }
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
        .kpi-label, .kpi-meta, .financial-summary__label, .department-bar-name, .chart-subtitle, .report-table td { color: #334155 !important; }
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

    <form class="report-filters" method="get" action="reports.php" aria-label="Filter queue transactions">
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
    <section class="report-kpis" aria-label="Performance metrics">
        <article class="kpi-card">
            <div class="kpi-top">
                <span class="kpi-label">Total Queues</span>
            </div>
            <div class="kpi-value">
                <strong><?= number_format($totalQueueCount) ?></strong>
                <span>tickets</span>
            </div>
            <p class="kpi-meta"><?= number_format($waitingQueueCount) ?> waiting · <?= number_format($servingQueueCount) ?> called / serving</p>
        </article>

        <article class="kpi-card completed-kpi">
            <div class="kpi-top">
                <span class="kpi-label">Completed</span>
            </div>
            <div class="kpi-value">
                <strong><?= number_format($completedQueueCount) ?></strong>
                <span>served</span>
            </div>
            <p class="kpi-meta positive"><?= $totalQueueCount > 0 ? number_format($completedQueueCount / $totalQueueCount * 100, 1) : '0.0' ?>% of matching tickets</p>
        </article>

        <article class="kpi-card">
            <div class="kpi-top">
                <span class="kpi-label">Cancelled</span>
            </div>
            <div class="kpi-value">
                <strong><?= number_format($cancelledQueueCount) ?></strong>
                <span>tickets</span>
            </div>
            <p class="kpi-meta">canceled tickets</p>
        </article>

        <article class="kpi-card">
            <div class="kpi-top">
                <span class="kpi-label">No Show</span>
            </div>
            <div class="kpi-value">
                <strong><?= number_format($noShowQueueCount) ?></strong>
                <span>tickets</span>
            </div>
            <p class="kpi-meta">marked as no show</p>
        </article>
    </section>

    <section class="financial-summary" aria-label="Financial summary">
        <div class="financial-summary__card">
            <span class="financial-summary__label">Collected income</span>
            <div class="financial-summary__value">&#8369;<?= number_format((float)$summary['total_income'], 2) ?></div>
            <p class="kpi-meta"><?= number_format((int)$summary['paid_count']) ?> paid payments</p>
        </div>
        <div class="financial-summary__card">
            <span class="financial-summary__label">Pending recorded payments</span>
            <div class="financial-summary__value">&#8369;<?= number_format((float)$summary['pending_amount'], 2) ?></div>
            <p class="kpi-meta"><?= number_format((int)$summary['pending_count']) ?> pending payments</p>
        </div>
    </section>

    <section class="chart-panel" aria-labelledby="department-report-title">
        <div class="chart-head">
            <div>
                <h2 id="department-report-title" class="chart-title">Queues by department</h2>
                <p class="chart-subtitle">Ticket volume for the applied filters</p>
            </div>
        </div>
        <?php if ($report['department_counts']): ?>
        <div class="department-bars">
            <?php foreach ($report['department_counts'] as $department): ?>
            <?php $barWidth = $maxDepartmentTickets > 0 ? ((int)$department['ticket_count'] / $maxDepartmentTickets) * 100 : 0; ?>
            <div class="department-bar-row">
                <span class="department-bar-name" title="<?= $escape($department['dept_name']) ?>"><?= $escape($department['dept_name']) ?></span>
                <span class="department-bar-track"><span class="department-bar-fill" style="width: <?= number_format($barWidth, 2, '.', '') ?>%"></span></span>
                <strong class="department-bar-count"><?= number_format((int)$department['ticket_count']) ?></strong>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="department-empty">No department ticket data is available.</p>
        <?php endif; ?>
    </section>

    <section class="report-data-section" aria-labelledby="queue-transactions-title">
        <div class="chart-head">
            <div>
                <h2 id="queue-transactions-title" class="chart-title">Queue transactions</h2>
            </div>
            <div class="report-export-tools">
                <a class="export-btn report-excel-btn" href="export_reports.php?scope=all" aria-label="Export all transactions to Excel">
                    <i data-feather="download" aria-hidden="true"></i><span>Export Excel</span>
                </a>
                <?php if ($appliedFilters): ?>
                <a class="export-btn" href="<?= $escape($filteredExportUrl) ?>">Export filtered</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="report-table-wrap">
            <table class="report-table">
                <thead><tr><th scope="col">Ticket</th><th scope="col">Customer</th><th scope="col">Selected service</th><th scope="col">Queue dates</th><th scope="col">Queue status</th><th scope="col">Payment details</th></tr></thead>
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
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?><tr><td colspan="6" class="report-empty">No transactions match these filters. Adjust the filters or reset to view all records.</td></tr><?php endif; ?>
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
