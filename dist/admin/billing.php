<?php
require_once '../database/db.php';
require_once __DIR__ . '/../includes/billing_fees.php';
$conn = $conn ?? null;
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$isAdmin = ($_SESSION['user_role'] ?? '') === 'admin';
if (!$isAdmin) {
    header('Location: ../login.php');
    exit;
}

$billingCsrf = $_SESSION['billing_csrf'] ??= bin2hex(random_bytes(32));
$message = $_SESSION['billing_success'] ?? '';
unset($_SESSION['billing_success']);
$messageType = 'success';
$bulkValues = ['department_id' => '', 'direction' => 'increase', 'unit' => 'amount', 'adjustment' => ''];
if (isset($_SESSION['billing_bulk_preview'])) {
    $pending = $_SESSION['billing_bulk_preview'];
    $bulkValues = [
        'department_id' => (string)$pending['department_id'],
        'direction' => $pending['direction'],
        'unit' => $pending['unit'],
        'adjustment' => billingFeeDecimal($pending['adjustment']),
    ];
}

if ($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS service_fees (
        id INT AUTO_INCREMENT PRIMARY KEY,
        service_id INT NOT NULL UNIQUE,
        amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_service_fees_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    // Starter prices by department; saved custom fees are left unchanged.
    $conn->query("INSERT INTO service_fees (service_id, amount)
        SELECT s.id,
            CASE
                WHEN d.dept_name LIKE '%Medical Aesthetics%' THEN 2500.00
                WHEN d.dept_name LIKE '%Retail%' THEN 500.00
                WHEN d.dept_name LIKE '%Barbering%' THEN 350.00
                WHEN d.dept_name LIKE '%Nail%' THEN 500.00
                WHEN d.dept_name LIKE '%Skin Care%' THEN 1000.00
                WHEN d.dept_name LIKE '%Lash%' THEN 1200.00
                WHEN d.dept_name LIKE '%Body & Massage%' THEN 1200.00
                WHEN d.dept_name LIKE '%Hair Removal%' THEN 800.00
                WHEN d.dept_name LIKE '%Wellness%' THEN 1500.00
                WHEN d.dept_name LIKE '%Piercing%' THEN 800.00
                WHEN d.dept_name LIKE '%Makeup%' THEN 1800.00
                WHEN d.dept_name LIKE '%Hair Care%' THEN 700.00
                ELSE 500.00
            END
        FROM services s
        LEFT JOIN departments d ON d.id = s.dept_id
        LEFT JOIN service_fees sf ON sf.service_id = s.id
        WHERE sf.id IS NULL");
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if ($action === 'preview_bulk') {
        foreach ($bulkValues as $key => $value) {
            $bulkValues[$key] = is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
        }
    }
    try {
        if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($billingCsrf, $_POST['csrf_token'])) {
            throw new RuntimeException('Your session has expired. Refresh this page and try again.');
        }
        if ($action === 'cancel_bulk') {
            unset($_SESSION['billing_bulk_preview']);
            header('Location: billing.php#bulk-fees');
            exit;
        }
        if (!$conn) {
            throw new RuntimeException('Billing is temporarily unavailable. Please try again when the database connection is restored.');
        }
        if ($action === 'preview_bulk') {
            unset($_SESSION['billing_bulk_preview']);
            $departmentId = filter_var($bulkValues['department_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($departmentId === false) {
                throw new InvalidArgumentException('Choose a department to update.');
            }
            $_SESSION['billing_bulk_preview'] = billingBulkPreview($conn, $departmentId,
                $bulkValues['direction'], $bulkValues['unit'], billingFeeCents($bulkValues['adjustment']));
            header('Location: billing.php#bulk-preview');
            exit;
        } elseif ($action === 'apply_bulk') {
            $preview = $_SESSION['billing_bulk_preview'] ?? null;
            if (!$preview || !is_string($_POST['preview_token'] ?? null)
                || !hash_equals($preview['token'], $_POST['preview_token'])) {
                throw new RuntimeException('This preview is no longer available. Generate a new preview before applying.');
            }
            $changed = billingApplyBulkPreview($conn, $preview);
            unset($_SESSION['billing_bulk_preview']);
            $_SESSION['billing_success'] = 'Updated fees for ' . $changed . ' services in ' . $preview['department_name'] . '.';
            header('Location: billing.php#bulk-fees');
            exit;
        } elseif ($action === 'save_fee') {
            $serviceId = filter_var($_POST['service_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $amount = billingFeeDecimal(billingFeeCents($_POST['amount'] ?? null));
            if ($serviceId === false) {
                throw new InvalidArgumentException('Choose a valid service.');
            }
            $stmt = $conn->prepare('INSERT INTO service_fees (service_id, amount) VALUES (?, ?) ON DUPLICATE KEY UPDATE amount = VALUES(amount)');
            if (!$stmt) {
                throw new RuntimeException('Unable to save the service fee. Please try again.');
            }
            try {
                $stmt->bind_param('is', $serviceId, $amount);
                if (!$stmt->execute()) {
                    throw new RuntimeException('Unable to save the service fee. Please try again.');
                }
            } finally {
                $stmt->close();
            }
            $_SESSION['billing_success'] = 'Service fee saved.';
            header('Location: billing.php');
            exit;
        } else {
            throw new InvalidArgumentException('Choose a valid billing action.');
        }
    } catch (InvalidArgumentException | RuntimeException $error) {
        if ($action === 'apply_bulk') unset($_SESSION['billing_bulk_preview']);
        $message = $error->getMessage();
        $messageType = 'error';
    }
}

$serviceList = [];
if ($conn) {
    $result = $conn->query("SELECT s.id, s.dept_id, s.service_name, d.dept_name, COALESCE(sf.amount, 0) AS amount,
        sf.updated_at FROM services s
        LEFT JOIN departments d ON d.id = s.dept_id
        LEFT JOIN service_fees sf ON sf.service_id = s.id
        ORDER BY d.dept_name, s.service_name");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $serviceList[] = $row;
        }
    }
}

$servicesByDepartment = [];
$bulkDepartments = [];
foreach ($serviceList as $service) {
    $departmentName = $service['dept_name'] ?? 'Unassigned';
    $servicesByDepartment[$departmentName][] = $service;
    if ($service['dept_name'] !== null) $bulkDepartments[(int)$service['dept_id']] = $departmentName;
}

if (!$conn && $message === '') {
    $message = 'Billing is temporarily unavailable. Please try again when the database connection is restored.';
    $messageType = 'error';
}
$bulkPreview = $_SESSION['billing_bulk_preview'] ?? null;
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$pageTitle = 'Billing | Queuezy Admin';
$pageHeading = 'Billing';
require_once '../includes/header.php';
?>

<style>
    .billing-page {
        max-width: 1440px;
        margin: 0 auto;
        padding-bottom: 30px;
        color: #e7edf8;
        font-family: 'DM Sans', sans-serif;
    }

    .billing-page .queue-section-heading {
        align-items: flex-end;
        margin-bottom: 22px;
    }

    .billing-page .queue-section-heading h2 {
        margin: 0;
        color: #f8fafc;
        font-size: 1.45rem;
        font-weight: 800;
        letter-spacing: -0.03em;
    }

    .billing-page .queue-section-heading p {
        margin: 6px 0 0;
        color: #a9b7cc;
        font-size: 0.9rem;
    }



    .billing-notice {
        display: flex;
        align-items: center;
        gap: 12px;
        margin: 0 0 20px;
        padding: 13px 16px;
        border: 1px solid rgba(52, 211, 153, 0.3);
        border-radius: 12px;
        background: rgba(16, 185, 129, 0.12);
        color: #b6f5dd;
        font-size: 0.82rem;
        font-weight: 600;
    }

    .billing-notice--error {
        border-color: rgba(248, 113, 113, 0.3);
        background: rgba(239, 68, 68, 0.12);
        color: #fecaca;
    }

    .billing-notice__icon {
        display: grid;
        place-items: center;
        width: 28px;
        height: 28px;
        flex-shrink: 0;
        border-radius: 50%;
        background: rgba(52, 211, 153, 0.16);
        color: #6ee7b7;
    }

    .billing-notice--error .billing-notice__icon {
        background: rgba(248, 113, 113, 0.16);
        color: #fca5a5;
    }

    .billing-notice__icon svg {
        width: 16px;
        height: 16px;
    }

    .billing-notice__dismiss {
        margin-left: auto;
        border: 0;
        background: transparent;
        color: inherit;
        cursor: pointer;
        opacity: 0.75;
        font-size: 1.1rem;
        line-height: 1;
    }

    .billing-notice__dismiss:hover {
        opacity: 1;
    }

    .billing-panel {
        border: 1px solid rgba(100, 116, 139, 0.38);
        border-radius: 20px;
        background: #1d293d;
        box-shadow: 0 16px 32px rgba(2, 6, 23, 0.14);
    }

    .billing-bulk {
        margin-bottom: 28px;
        padding: 23px 25px;
        border-top: 2px solid #7652d6;
        scroll-margin-top: 20px;
    }

    .billing-bulk h3 {
        margin: 0 0 7px;
        color: #f8fafc;
        font-size: 1rem;
        font-weight: 700;
    }

    .billing-bulk p {
        margin: 0;
        color: #a9b7cc;
        font-size: 0.8rem;
        line-height: 1.6;
    }

    .billing-bulk-form {
        display: grid;
        grid-template-columns: minmax(180px, 1.7fr) repeat(3, minmax(105px, 1fr)) auto;
        align-items: end;
        gap: 14px;
        margin-top: 20px;
    }

    .billing-bulk-form label {
        display: grid;
        gap: 7px;
        min-width: 0;
        color: #c4d0e2;
        font-size: 0.75rem;
        font-weight: 600;
    }

    .billing-bulk-form select,
    .billing-bulk-form input {
        width: 100%;
        min-width: 0;
        height: 40px;
        padding: 0 10px;
        border: 1px solid rgba(100, 116, 139, 0.48);
        border-radius: 8px;
        background: #111b30;
        color: #f8fafc;
        font: inherit;
        font-weight: 400;
    }

    .billing-bulk-form select:focus-visible,
    .billing-bulk-form input:focus-visible,
    .billing-bulk button:focus-visible {
        outline: 2px solid #b8a3ff;
        outline-offset: 3px;
    }

    .billing-bulk .billing-bulk-button {
        justify-content: center;
        height: 40px;
        padding: 0 15px;
        white-space: nowrap;
    }

    .billing-bulk button:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    .billing-preview {
        margin-top: 24px;
        padding-top: 22px;
        border-top: 1px solid rgba(148, 163, 184, 0.2);
        scroll-margin-top: 20px;
    }

    .billing-preview-scroll {
        max-height: 330px;
        margin-top: 15px;
        overflow: auto;
        border: 1px solid rgba(148, 163, 184, 0.2);
        border-radius: 9px;
    }

    .billing-preview .billing-table {
        min-width: 520px;
    }

    .billing-preview .billing-table th {
        position: sticky;
        top: 0;
        background: #172238;
        border-color: #3b4960;
    }

    .billing-preview .billing-table td {
        padding: 13px 12px;
        border-color: #3b4960;
    }

    .billing-preview .billing-table th:not(:first-child),
    .billing-preview .billing-table td:not(:first-child) {
        text-align: right;
        white-space: nowrap;
        font-variant-numeric: tabular-nums;
    }

    .billing-preview .billing-new-fee {
        color: #bca7ff;
        font-weight: 700;
    }

    .billing-bulk .billing-preview-changed {
        margin-top: 14px;
        color: #fcd34d;
    }

    .billing-bulk [hidden] { display: none !important; }

    .billing-preview-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 18px;
    }

    .billing-bulk .billing-cancel-button {
        border: 1px solid #58657e;
        background: transparent;
        color: #dce5f3;
    }

    .billing-bulk .billing-cancel-button:hover { background: #29364d; }

    .billing-panel-head {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 20px;
        padding: 23px 25px 18px;
        border-bottom: 1px solid rgba(148, 163, 184, 0.16);
    }

    .billing-panel-head .queue-section-heading {
        margin: 0;
    }

    .billing-panel-head .queue-section-heading h2 {
        font-size: 1rem;
    }

    .billing-search {
        position: relative;
        width: min(290px, 100%);
        flex-shrink: 0;
    }

    .billing-search input {
        width: 100%;
        height: 38px;
        padding: 0 38px 0 13px;
        border: 1px solid rgba(100, 116, 139, 0.38);
        border-radius: 9px;
        outline: none;
        background: #111b30;
        color: #f8fafc;
        font-size: 0.78rem;
    }

    .billing-search input::placeholder {
        color: #8292ad;
    }

    .billing-search input:focus {
        border-color: #8060e2;
        box-shadow: 0 0 0 3px rgba(118, 82, 214, 0.16);
    }

    .billing-search svg {
        position: absolute;
        top: 11px;
        right: 12px;
        width: 16px;
        height: 16px;
        color: #8fa1be;
    }

    .billing-table-wrap {
        overflow-x: auto;
        padding: 0 25px 10px;
    }

    .billing-table {
        min-width: 660px;
        width: 100%;
        border-collapse: collapse;
        text-align: left;
    }

    .billing-table th {
        padding: 15px 12px 13px;
        border-bottom: 1px solid rgba(203, 213, 225, 0.7);
        color: #91a3bf;
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
    }

    .billing-table td {
        padding: 10px 12px;
        border-bottom: 1px solid rgba(203, 213, 225, 0.62);
        color: #dce5f3;
        font-size: 0.82rem;
        line-height: 1.2;
        vertical-align: middle;
    }

    .billing-table tbody tr:last-child td {
        border-bottom: 0;
    }

    .billing-table .billing-service-name {
        color: #e7edf8;
        font-weight: 700;
    }

    .billing-department-group {
        margin: 0 25px;
        border-bottom: 1px solid rgba(148, 163, 184, 0.2);
    }

    .billing-department-group:last-of-type {
        border-bottom: 0;
    }

    .billing-department-group > summary {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 17px 12px;
        color: #eadfff;
        font-size: 0.9rem;
        font-weight: 700;
        letter-spacing: 0.02em;
        cursor: pointer;
        list-style: none;
    }

    .billing-department-group > summary::-webkit-details-marker {
        display: none;
    }

    .billing-department-group > summary::after {
        content: '+';
        color: #b8a3ff;
        font-size: 1.25rem;
        line-height: 1;
    }

    .billing-department-group[open] > summary::after {
        content: '\2212';
    }

    .billing-department-group > summary:focus-visible {
        outline: 2px solid #a78bfa;
        outline-offset: -2px;
        border-radius: 6px;
    }

    .billing-department-count {
        margin-left: auto;
        color: #91a3bf;
        font-size: 0.75rem;
        font-weight: 500;
        letter-spacing: 0;
        white-space: nowrap;
    }

    .billing-empty-results {
        padding: 24px;
        color: #a9b7cc;
        text-align: center;
    }

    .billing-table .billing-updated {
        color: #b4c1d5;
        font-size: 0.74rem;
        text-align: right;
    }

    .billing-fee-form {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .billing-fee-form > span {
        color: #c7d2e3;
        font-weight: 700;
    }

    .billing-fee-input {
        width: 128px;
        height: 34px;
        padding: 0 9px;
        border: 1px solid rgba(100, 116, 139, 0.28);
        border-radius: 5px;
        outline: none;
        background: #111b30;
        color: #f8fafc;
        font-size: 0.8rem;
    }

    .billing-fee-input:focus {
        border-color: #8060e2;
    }

    .billing-save-button {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        height: 34px;
        padding: 0 11px;
        border: 0;
        border-radius: 7px;
        background: #7652d6;
        color: #fff;
        font-size: 0.72rem;
        font-weight: 700;
        cursor: pointer;
    }

    .billing-save-button:hover {
        background: #8668e2;
    }

    .billing-save-button svg {
        width: 14px;
        height: 14px;
    }

    @media (max-width: 1100px) {
        .billing-bulk-form {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .billing-bulk-form > button {
            grid-column: 1 / -1;
            justify-self: start;
        }
    }

    @media (max-width: 800px) {
        .billing-panel-head {
            align-items: stretch;
            flex-direction: column;
        }

        .billing-search {
            width: 100%;
        }
    }

    @media (max-width: 560px) {
        .billing-page .queue-section-heading h2 {
            font-size: 1.2rem;
        }

        .billing-bulk { padding: 20px 16px; }
        .billing-bulk-form { grid-template-columns: 1fr; }
        .billing-bulk-form > button { width: 100%; }

        .billing-panel-head {
            padding-left: 16px;
            padding-right: 16px;
        }

        .billing-department-group {
            margin-left: 16px;
            margin-right: 16px;
        }
    }
</style>

<div class="billing-page">
<div class="queue-section-heading">
    <div>
        <h2>Service billing</h2>
        <p>Set the fee charged for each service offered by your departments.</p>
    </div>
</div>

<?php if ($message !== ''): ?>
    <div class="billing-notice <?= $messageType === 'error' ? 'billing-notice--error' : '' ?>" role="<?= $messageType === 'error' ? 'alert' : 'status' ?>">
        <span class="billing-notice__icon"><i data-feather="<?= $messageType === 'error' ? 'alert-circle' : 'check-circle' ?>"></i></span>
        <span><?= $escape($message) ?></span>
        <button class="billing-notice__dismiss" type="button" aria-label="Dismiss message">&times;</button>
    </div>
<?php endif; ?>

<section class="billing-panel billing-bulk" id="bulk-fees" aria-labelledby="bulk-fees-title">
    <h3 id="bulk-fees-title">Bulk fee update</h3>
    <p id="bulk-fees-help">Adjust service fees for one department. Review the new prices before applying.</p>
    <form class="billing-bulk-form" id="bulkFeeForm" method="post" action="billing.php#bulk-fees" aria-describedby="bulk-fees-help">
        <input type="hidden" name="action" value="preview_bulk">
        <input type="hidden" name="csrf_token" value="<?= $escape($billingCsrf) ?>">
        <label for="bulkDepartment">Department
            <select id="bulkDepartment" name="department_id" required>
                <option value="">Select department</option>
                <?php foreach ($bulkDepartments as $departmentId => $departmentName): ?>
                <option value="<?= $departmentId ?>" <?= (string)$departmentId === $bulkValues['department_id'] ? 'selected' : '' ?>><?= $escape($departmentName) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label for="bulkDirection">Adjustment
            <select id="bulkDirection" name="direction">
                <option value="increase" <?= $bulkValues['direction'] === 'increase' ? 'selected' : '' ?>>Increase by</option>
                <option value="decrease" <?= $bulkValues['direction'] === 'decrease' ? 'selected' : '' ?>>Decrease by</option>
            </select>
        </label>
        <label for="bulkUnit">Type
            <select id="bulkUnit" name="unit">
                <option value="amount" <?= $bulkValues['unit'] === 'amount' ? 'selected' : '' ?>>Amount (&#8369;)</option>
                <option value="percent" <?= $bulkValues['unit'] === 'percent' ? 'selected' : '' ?>>Percentage (%)</option>
            </select>
        </label>
        <label for="bulkAdjustment"><span id="bulkValueLabel"><?= $bulkValues['unit'] === 'percent' ? 'Percentage (%)' : 'Amount (₱)' ?></span>
            <input id="bulkAdjustment" name="adjustment" type="number" min="0.01" max="99999999.99" step="0.01" inputmode="decimal" placeholder="0.00" value="<?= $escape($bulkValues['adjustment']) ?>" required>
        </label>
        <button class="billing-save-button billing-bulk-button" type="submit" <?= !$conn || !$bulkDepartments ? 'disabled' : '' ?>><i data-feather="eye" aria-hidden="true"></i> Preview changes</button>
    </form>
    <?php if ($bulkPreview): ?>
    <div class="billing-preview" id="bulk-preview" aria-labelledby="bulk-preview-title">
        <h3 id="bulk-preview-title">Review changes</h3>
        <p><?= $escape($bulkPreview['department_name']) ?> &middot; <?= $bulkPreview['direction'] === 'increase' ? 'Increase' : 'Decrease' ?> by <?= $bulkPreview['unit'] === 'amount' ? '&#8369;' : '' ?><?= number_format($bulkPreview['adjustment'] / 100, 2) ?><?= $bulkPreview['unit'] === 'percent' ? '%' : '' ?></p>
        <p><?= $bulkPreview['changed'] ?> of <?= count($bulkPreview['rows']) ?> service fees will change.</p>
        <div class="billing-preview-scroll" tabindex="0" role="region" aria-label="Service fee changes">
            <table class="billing-table">
                <thead><tr><th scope="col">Service</th><th scope="col">Current fee</th><th scope="col">New fee</th><th scope="col">Change</th></tr></thead>
                <tbody>
                    <?php foreach ($bulkPreview['rows'] as $change): ?>
                    <?php if ($change['current'] === $change['next']) continue; ?>
                    <tr>
                        <td><?= $escape($change['service_name']) ?></td>
                        <td>&#8369;<?= number_format($change['current'] / 100, 2) ?></td>
                        <td class="billing-new-fee">&#8369;<?= number_format($change['next'] / 100, 2) ?></td>
                        <td><?= $change['next'] > $change['current'] ? '+' : '&minus;' ?>&#8369;<?= number_format(abs($change['next'] - $change['current']) / 100, 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="billing-preview-changed" id="bulkPreviewChanged" role="status" hidden>Settings changed. Preview again before applying.</p>
        <form class="billing-preview-actions" method="post" action="billing.php#bulk-fees">
            <input type="hidden" name="csrf_token" value="<?= $escape($billingCsrf) ?>">
            <input type="hidden" name="preview_token" value="<?= $escape($bulkPreview['token']) ?>">
            <button class="billing-save-button billing-bulk-button" id="bulkApplyButton" type="submit" name="action" value="apply_bulk"><i data-feather="check" aria-hidden="true"></i> Apply changes</button>
            <button class="billing-save-button billing-bulk-button billing-cancel-button" type="submit" name="action" value="cancel_bulk">Cancel</button>
        </form>
    </div>
    <?php endif; ?>
</section>

<div class="billing-panel">
    <div class="billing-panel-head">
    <div class="queue-section-heading">
        <div><h2>Service fees</h2><p>Amounts are saved per service and can be changed at any time.</p></div>
    </div>
        <label class="billing-search" for="billingSearch">
            <span class="sr-only">Search services or departments</span>
            <i data-feather="search"></i>
            <input id="billingSearch" type="search" placeholder="Search services or departments..." autocomplete="off">
        </label>
    </div>
    <?php if (!$serviceList): ?>
        <p class="text-slate-500">No services found. Add a service before setting its billing amount.</p>
    <?php else: ?>
        <?php foreach ($servicesByDepartment as $departmentName => $departmentServices): ?>
            <details class="billing-department-group" data-department-group="<?= htmlspecialchars(strtolower($departmentName), ENT_QUOTES) ?>">
                <summary>
                    <span><?= htmlspecialchars($departmentName) ?></span>
                    <span class="billing-department-count"><?= count($departmentServices) ?> services</span>
                </summary>
                <div class="billing-table-wrap">
                    <table class="billing-table">
                        <thead><tr><th>Service</th><th>Fee</th><th class="text-right">Updated</th></tr></thead>
                        <tbody>
                        <?php foreach ($departmentServices as $service): ?>
                            <tr class="billing-row border-b border-slate-100" data-search="<?= htmlspecialchars(strtolower($service['service_name'] . ' ' . $departmentName), ENT_QUOTES) ?>">
                                <td class="billing-service-name"><?= htmlspecialchars($service['service_name']) ?></td>
                                <td>
                                    <?php if ($isAdmin): ?>
                                    <form method="post" class="billing-fee-form">
                                        <input type="hidden" name="action" value="save_fee">
                                        <input type="hidden" name="csrf_token" value="<?= $escape($billingCsrf) ?>">
                                        <input type="hidden" name="service_id" value="<?= (int)$service['id'] ?>">
                                        <label class="sr-only" for="amount-<?= (int)$service['id'] ?>">Fee for <?= htmlspecialchars($service['service_name']) ?></label>
                                        <span>&#8369;</span><input id="amount-<?= (int)$service['id'] ?>" class="billing-fee-input" type="text" name="amount" inputmode="decimal" data-money-input min="0" value="<?= htmlspecialchars(number_format((float)$service['amount'], 2, '.', ',')) ?>" required>
                                        <button class="billing-save-button" type="submit"><i data-feather="save"></i> Save</button>
                                    </form>
                                    <?php else: ?>
                                    <span>&#8369;<?= number_format((float)$service['amount'], 2) ?></span>
                                    <span>Admin only</span>
                                    <?php endif; ?>
                                </td>
                                <td class="billing-updated"><?= $service['updated_at'] ? 'Updated ' . htmlspecialchars($service['updated_at']) : 'Not configured' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        <?php endforeach; ?>
        <p id="billingNoResults" class="billing-empty-results hidden">No matching services found.</p>
    <?php endif; ?>
</div>
</div>
<script>feather.replace();</script>
<script>
document.querySelectorAll('.billing-notice__dismiss').forEach((button) => {
    button.addEventListener('click', () => button.closest('.billing-notice')?.remove());
});

const bulkFeeForm = document.getElementById('bulkFeeForm');
const bulkUnit = document.getElementById('bulkUnit');
const bulkDirection = document.getElementById('bulkDirection');
const bulkAdjustment = document.getElementById('bulkAdjustment');
const updateBulkAmountField = () => {
    const percentage = bulkUnit.value === 'percent';
    document.getElementById('bulkValueLabel').textContent = percentage ? 'Percentage (%)' : 'Amount (₱)';
    bulkAdjustment.max = percentage ? (bulkDirection.value === 'decrease' ? '100' : '10000') : '99999999.99';
};
bulkFeeForm.addEventListener('input', () => {
    updateBulkAmountField();
    const applyButton = document.getElementById('bulkApplyButton');
    if (applyButton) {
        applyButton.disabled = true;
        document.getElementById('bulkPreviewChanged').hidden = false;
    }
});
updateBulkAmountField();

document.querySelectorAll('[data-money-input]').forEach((input) => {
    input.addEventListener('input', () => {
        const parts = input.value.replace(/[^0-9.]/g, '').split('.');
        const whole = (parts.shift() || '').replace(/^0+(?=\d)/, '');
        const decimals = parts.join('').slice(0, 2);
        input.value = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (parts.length || input.value.includes('.') ? `.${decimals}` : '');
    });
});

document.querySelectorAll('.billing-fee-form').forEach((form) => {
    form.addEventListener('submit', (event) => {
        const serviceName = form.closest('.billing-row')?.querySelector('.billing-service-name')?.textContent.trim() || 'this service';
        const amount = form.querySelector('[data-money-input]')?.value || '0.00';
        const confirmed = window.confirm(`Save ₱${amount} as the billing fee for ${serviceName}?`);

        if (!confirmed) {
            event.preventDefault();
        }
    });
});

const billingSearch = document.getElementById('billingSearch');
const billingRows = [...document.querySelectorAll('.billing-row')];
const billingGroups = [...document.querySelectorAll('.billing-department-group')];
const billingNoResults = document.getElementById('billingNoResults');

billingSearch?.addEventListener('input', () => {
    const query = billingSearch.value.trim().toLowerCase();
    let visibleRows = 0;

    billingRows.forEach((row) => {
        const matches = !query || (row.dataset.search || '').includes(query);
        row.classList.toggle('hidden', !matches);
        if (matches) visibleRows += 1;
    });

    billingGroups.forEach((group) => {
        const hasVisibleService = [...group.querySelectorAll('.billing-row')].some((row) => !row.classList.contains('hidden'));
        group.classList.toggle('hidden', !hasVisibleService);
        group.open = Boolean(query && hasVisibleService);
    });

    billingNoResults?.classList.toggle('hidden', visibleRows !== 0);
});
</script>
</div></main></div></body></html>
