<?php
require_once '../database/db.php';
$conn = $conn ?? null;
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$isAdmin = ($_SESSION['user_role'] ?? '') === 'admin';
if (!$isAdmin) {
    header('Location: ../login.php');
    exit;
}

$message = '';
$messageType = 'success';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_fee' && $conn && $isAdmin) {
    $serviceId = (int)($_POST['service_id'] ?? 0);
    $amount = filter_var(str_replace(',', '', trim((string)($_POST['amount'] ?? ''))), FILTER_VALIDATE_FLOAT);

    if ($serviceId > 0 && $amount !== false && $amount >= 0) {
        $stmt = $conn->prepare('INSERT INTO service_fees (service_id, amount) VALUES (?, ?) ON DUPLICATE KEY UPDATE amount = VALUES(amount)');
        if ($stmt) {
            $stmt->bind_param('id', $serviceId, $amount);
            if ($stmt->execute()) {
                header('Location: billing.php?saved=1');
                exit;
            }
            $stmt->close();
        }
    }

    $message = 'Enter a valid non-negative fee.';
    $messageType = 'error';
}

$serviceList = [];
if ($conn) {
    $result = $conn->query("SELECT s.id, s.service_name, d.dept_name, COALESCE(sf.amount, 0) AS amount,
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
foreach ($serviceList as $service) {
    $departmentName = $service['dept_name'] ?? 'Unassigned';
    $servicesByDepartment[$departmentName][] = $service;
}

$configuredCount = count(array_filter($serviceList, static fn($service) => (float)$service['amount'] > 0));
$totalFees = array_sum(array_map(static fn($service) => (float)$service['amount'], $serviceList));
$totalIncome = 0;
if ($conn) {
    $incomeResult = $conn->query("SELECT COALESCE(SUM(amount), 0) AS total_income FROM queue_payments WHERE status = 'paid'");
    if ($incomeResult && $incomeRow = $incomeResult->fetch_assoc()) {
        $totalIncome = (float) $incomeRow['total_income'];
    }
}
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

    .billing-stats {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 18px;
        margin-bottom: 30px;
    }

    .billing-page .queue-stat {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: center;
        position: relative;
        min-height: 112px;
        padding: 20px 22px;
        overflow: hidden;
        border: 1px solid rgba(100, 116, 139, 0.38);
        border-radius: 18px;
        background: #1d293d;
        box-shadow: 0 12px 26px rgba(2, 6, 23, 0.12);
    }

    .billing-page .queue-stat::before {
        content: '';
        position: absolute;
        inset: 0 auto auto 0;
        width: 100%;
        height: 2px;
        background: #7652d6;
    }

    .billing-page .queue-stat__top {
        min-width: 0;
    }

    .billing-page .queue-stat__top > span:first-child {
        display: block;
        max-width: 105px;
        color: #92a3bf;
        font-size: 0.72rem;
        font-weight: 600;
        line-height: 1.25;
        letter-spacing: 0.01em;
    }

    .billing-page .queue-stat h2 {
        grid-column: 2;
        grid-row: 1;
        margin: 0;
        color: #f8fafc;
        font-family: 'Inter', sans-serif;
        font-size: clamp(1.15rem, 1.7vw, 1.55rem);
        font-weight: 700;
        line-height: 1.05;
        letter-spacing: -0.035em;
        white-space: nowrap;
    }

    .billing-page .queue-stat--income h2,
    .billing-page .queue-stat--income .income-value {
        color: #72e0b7;
    }

    .billing-page .queue-stat--income .income-value {
        display: inline;
        font: inherit;
        letter-spacing: inherit;
    }

    .billing-page .queue-stat p {
        grid-column: 1 / -1;
        display: block;
        margin: 0;
        color: #c4d0e2;
        font-size: 0.74rem;
        line-height: 1.25;
    }

    .billing-panel {
        border: 1px solid rgba(100, 116, 139, 0.38);
        border-radius: 20px;
        background: #1d293d;
        box-shadow: 0 16px 32px rgba(2, 6, 23, 0.14);
    }

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
        .billing-stats {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 800px) {
        .billing-stats {
            grid-template-columns: 1fr;
        }

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

        .billing-page .queue-stat {
            min-height: 104px;
        }

        .billing-page .queue-stat h2 {
            font-size: 1.2rem;
        }

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
    <div class="billing-notice billing-notice--error" role="alert">
        <span class="billing-notice__icon"><i data-feather="alert-circle"></i></span>
        <span><?= htmlspecialchars($message) ?></span>
        <button class="billing-notice__dismiss" type="button" aria-label="Dismiss message">&times;</button>
    </div>
<?php endif; ?>

<div class="billing-stats">
    <div class="queue-stat"><div class="queue-stat__top"><span>Configured fees</span></div><h2><?= $configuredCount ?>/<?= count($serviceList) ?></h2></div>
    <div class="queue-stat"><div class="queue-stat__top"><span>Total listed fees</span></div><h2>&#8369;<?= number_format($totalFees, 2) ?></h2></div>
    <div class="queue-stat queue-stat--income"><div class="queue-stat__top"><span>Total income</span></div><h2><span class="income-value">&#8369;<?= number_format($totalIncome, 2) ?></span></h2></div>
</div>

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