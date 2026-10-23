<?php 
require_once '../database/db.php';
$conn = $conn ?? null;
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
if (($_SESSION['user_role'] ?? '') !== 'admin') {
  header('Location: ../login.php');
  exit;
}
require_once __DIR__ . '/../includes/department_services.php';
if (empty($_SESSION['department_service_csrf'])) {
  $_SESSION['department_service_csrf'] = bin2hex(random_bytes(32));
}

$msg = '';
$msgType = 'success';
$showServiceForm = false;
$serviceForm = [
  'action' => 'add_service',
  'dept_id' => 0,
  'service_id' => 0,
  'service_name' => '',
  'service_price' => '',
  'estimated_time_mins' => '30',
];

if ($conn) {
  $statusColumn = $conn->query("SHOW COLUMNS FROM departments LIKE 'status'");
  if ($statusColumn && $statusColumn->num_rows === 0) {
    $conn->query("ALTER TABLE departments ADD COLUMN status ENUM('active', 'inactive', 'maintenance') NOT NULL DEFAULT 'active'");
  } else {
    $conn->query("ALTER TABLE departments MODIFY COLUMN status ENUM('active', 'inactive', 'maintenance') NOT NULL DEFAULT 'active'");
  }

  $oldDepartments = [
    'Hair Care & Styling',
    'Nail Care',
    'Skin & Facial',
    'Lash & Brow',
    'Hair Removal',
    'Makeup & Cosmetics',
    'Spa & Wellness',
    "Men's Grooming",
  ];
  $removeOldDepartment = $conn->prepare('DELETE FROM departments WHERE dept_name = ? AND NOT EXISTS (SELECT 1 FROM services WHERE services.dept_id = departments.id)');
  if ($removeOldDepartment) {
    foreach ($oldDepartments as $oldDepartment) {
      $removeOldDepartment->bind_param('s', $oldDepartment);
      $removeOldDepartment->execute();
    }
    $removeOldDepartment->close();
  }

  $defaultDepartments = [
    ['Hair Care & Hair Styling Department', 'HCHS'],
    ["Barbering & Men's Grooming Department", 'BMG'],
    ['Nail Care & Enhancements Department', 'NCE'],
    ['Skin Care & Esthetics Department', 'SCE'],
    ['Medical Aesthetics Department (MedSpa)', 'MED'],
    ['Lash, Brow & Semi-Permanent Makeup (PMU) Department', 'PMU'],
    ['Body & Massage Therapy Department', 'BMT'],
    ['Hair Removal Department', 'HR'],
    ['Wellness & Holistic Health Department', 'WHH'],
    ['Piercing & Body Modification Department', 'PBM'],
    ['Makeup & Glamour Department', 'MGP'],
    ['Retail & Boutique Department', 'RBT'],
  ];
  $departmentStmt = $conn->prepare('INSERT INTO departments (dept_name, dept_code, status) SELECT ?, ?, \'active\' WHERE NOT EXISTS (SELECT 1 FROM departments WHERE dept_name = ?)');
  if ($departmentStmt) {
    foreach ($defaultDepartments as [$departmentName, $departmentCode]) {
      $departmentStmt->bind_param('sss', $departmentName, $departmentCode, $departmentName);
      $departmentStmt->execute();
    }
    $departmentStmt->close();
  }
}


// Handle department and service changes.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  if (in_array($_POST['action'], ['add_service', 'rename_service'], true)) {
    $serviceForm['action'] = $_POST['action'];
    $serviceForm['dept_id'] = (int)($_POST['dept_id'] ?? 0);
    $serviceForm['service_id'] = (int)($_POST['service_id'] ?? 0);
    foreach (['service_name', 'service_price', 'estimated_time_mins'] as $field) {
      $serviceForm[$field] = is_string($_POST[$field] ?? null) ? trim($_POST[$field]) : '';
    }
    $csrf = $_POST['csrf_token'] ?? '';
    if (!is_string($csrf) || !hash_equals($_SESSION['department_service_csrf'], $csrf)) {
      $serviceResult = ['ok' => false, 'message' => 'Your session could not be verified. Please try again.'];
    } elseif ($serviceForm['action'] === 'add_service') {
      $minutes = filter_var($serviceForm['estimated_time_mins'], FILTER_VALIDATE_INT);
      $serviceResult = createDepartmentService($conn, $serviceForm['dept_id'], $serviceForm['service_name'], $serviceForm['service_price'], $minutes === false ? 0 : $minutes);
    } else {
      $serviceResult = renameDepartmentService($conn, $serviceForm['dept_id'], $serviceForm['service_id'], $serviceForm['service_name']);
    }
    if ($serviceResult['ok']) {
      $notice = $serviceForm['action'] === 'add_service' ? 'service_added' : 'service_renamed';
      $_SESSION['department_service_notice'] = $notice;
      header('Location: departments.php?department=' . $serviceForm['dept_id'] . '#department-' . $serviceForm['dept_id']);
      exit;
    }
    $msg = $serviceResult['message'];
    $msgType = 'error';
    $showServiceForm = true;
  }

  if ($_POST['action'] === 'update_status' && $conn) {
    $deptId = (int)($_POST['dept_id'] ?? 0);
    $deptStatus = $_POST['status'] ?? 'active';
    if ($deptId > 0 && in_array($deptStatus, ['active', 'inactive', 'maintenance'], true)) {
      $stmt = $conn->prepare('UPDATE departments SET status = ? WHERE id = ?');
      if ($stmt) {
        $stmt->bind_param('si', $deptStatus, $deptId);
        $stmt->execute();
        $stmt->close();
        header('Location: departments.php?status_updated=1');
        exit;
      }
    }
  }

    if ($_POST['action'] === 'create' && $conn) {
        $dept_name = trim($_POST['dept_name'] ?? '');
        $dept_code = strtoupper(trim($_POST['dept_code'] ?? ''));
        $dept_status = $_POST['status'] ?? 'active';
        $service_name = trim($_POST['service_name'] ?? '');
        $service_price = filter_var(str_replace(',', '', trim((string)($_POST['service_price'] ?? ''))), FILTER_VALIDATE_FLOAT);

        if ($dept_name !== '' && $dept_code !== '' && $service_name !== '' && $service_price !== false && $service_price >= 0 && in_array($dept_status, ['active', 'inactive', 'maintenance'], true)) {
          $conn->query("CREATE TABLE IF NOT EXISTS service_fees (
            id INT AUTO_INCREMENT PRIMARY KEY,
            service_id INT NOT NULL UNIQUE,
            amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_service_fees_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
          ) ENGINE=InnoDB");
          $conn->begin_transaction();
          $departmentStmt = $conn->prepare("INSERT INTO departments (dept_name, dept_code, status) VALUES (?, ?, ?)");
          $serviceStmt = null;
          $feeStmt = null;
          if ($departmentStmt) {
            $departmentStmt->bind_param('sss', $dept_name, $dept_code, $dept_status);
            if ($departmentStmt->execute()) {
              $departmentId = $conn->insert_id;
              $serviceStmt = $conn->prepare('INSERT INTO services (dept_id, service_name, estimated_time_mins) VALUES (?, ?, 30)');
              if ($serviceStmt) {
                $serviceStmt->bind_param('is', $departmentId, $service_name);
                if ($serviceStmt->execute()) {
                  $serviceId = $conn->insert_id;
                  $feeStmt = $conn->prepare('INSERT INTO service_fees (service_id, amount) VALUES (?, ?)');
                  if ($feeStmt) {
                    $feeStmt->bind_param('id', $serviceId, $service_price);
                  }
                }
              }
            }
          }
          if ($feeStmt && $feeStmt->execute()) {
            $conn->commit();
            $departmentStmt?->close();
            $serviceStmt?->close();
            $feeStmt->close();
            header("Location: departments.php?created=1");
            exit;
          }
          $conn->rollback();
          $departmentStmt?->close();
          $serviceStmt?->close();
          $feeStmt?->close();
          $msg = 'Could not create the department, service, and price.';
          $msgType = 'error';
        } else {
            $msg = "Enter a department, ticket code, service name, and valid non-negative price.";
            $msgType = "error";
        }
    }

    if ($_POST['action'] === 'delete' && $conn) {
        $deptId = (int)($_POST['dept_id'] ?? 0);
        if ($deptId > 0) {
            $stmt = $conn->prepare("DELETE FROM departments WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $deptId);
                $stmt->execute();
                $stmt->close();
                header("Location: departments.php?deleted=1");
                exit;
            }
        }
    }
}

// Fetch ONLY Departments that exist in MySQL
$deptList = [];
$servicesByDepartment = [];
if ($conn) {
    $res = $conn->query("SELECT d.*,
      (SELECT COUNT(*) FROM services s WHERE s.dept_id = d.id) AS service_count,
      COALESCE(qc.waiting_count, 0) AS waiting_count,
      COALESCE(qc.called_count, 0) AS called_count,
      COALESCE(qc.serving_count, 0) AS serving_count
      FROM departments d
      LEFT JOIN (
        SELECT s.dept_id,
          SUM(q.status = 'waiting') AS waiting_count,
          SUM(q.status = 'called') AS called_count,
          SUM(q.status = 'now_serving') AS serving_count
        FROM queue q JOIN services s ON s.id = q.service_id
        WHERE q.status IN ('waiting', 'called', 'now_serving')
        GROUP BY s.dept_id
      ) qc ON qc.dept_id = d.id
      ORDER BY d.id ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $deptList[] = $row;
        }
    }
    $serviceResult = $conn->query('SELECT id, dept_id, service_name FROM services ORDER BY service_name ASC, id ASC');
    if ($serviceResult) {
      while ($service = $serviceResult->fetch_assoc()) {
        $servicesByDepartment[(int)$service['dept_id']][] = $service;
      }
      $serviceResult->free();
    }
}
$serviceDepartmentName = '';
foreach ($deptList as $department) {
  if ((int)$department['id'] === $serviceForm['dept_id']) {
    $serviceDepartmentName = $department['dept_name'];
    break;
  }
}
$serviceNotice = $_SESSION['department_service_notice'] ?? '';
unset($_SESSION['department_service_notice']);
$editingService = $serviceForm['action'] === 'rename_service';

$pageTitle = 'Departments | Queuezy Admin'; 
$pageHeading = 'Departments Overview'; 
require_once '../includes/header.php'; 
?>

<!-- Embedded Custom CSS for Senior-Grade UI/UX & Micro-interactions -->
<style>
  .dept-container {
    max-width: 1320px;
    min-width: 0;
    margin: 0 auto;
    font-family: 'DM Sans', 'Inter', -apple-system, sans-serif;
  }

  .dept-header-ribbon {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 32px;
  }
  .dept-breadcrumb {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 700;
    color: #94a3b8;
    margin-bottom: 6px;
  }
  .dept-title {
    font-size: 26px;
    font-weight: 900;
    color: #0f172a;
    letter-spacing: -0.5px;
    margin: 0 0 4px 0;
  }
  .dept-subtitle {
    font-size: 13px;
    color: #64748b;
    margin: 0;
  }

  .metrics-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 20px;
    margin-bottom: 32px;
  }
  @media (max-width: 1100px) { .metrics-grid { grid-template-columns: repeat(2, 1fr); } }
  @media (max-width: 600px) { .metrics-grid { grid-template-columns: 1fr; } }

  .stat-card-luxury {
    background: #ffffff;
    border: 1px solid #ece9f4;
    border-radius: 20px;
    padding: 22px 24px;
    box-shadow: 0 4px 20px -2px rgba(57, 35, 97, 0.04);
    transition: all 0.28s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
  }
  .stat-card-luxury:hover {
    transform: translateY(-4px);
    box-shadow: 0 16px 36px -4px rgba(124, 58, 237, 0.12);
    border-color: #d8b4fe;
  }
  .stat-card-luxury::after {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 4px;
    background: linear-gradient(90deg, #7c3aed, #a855f7);
    opacity: 0;
    transition: opacity 0.25s ease;
  }
  .stat-card-luxury:hover::after { opacity: 1; }
    .metrics-grid .stat-card-luxury:nth-child(1)::after { background: #7c3aed; }
    .metrics-grid .stat-card-luxury:nth-child(2)::after { background: #4f46e5; }
    .metrics-grid .stat-card-luxury:nth-child(3)::after { background: #059669; }

  .dept-container { --dept-muted: #64748b; --dept-line: #e2e8f0; --dept-surface: #f8fafc; --dept-text: #334155; --dept-accent: #7c3aed; --dept-soft: #f3e8ff; }
  :is(html.dark-mode, body.dark-mode) .dept-container { --dept-muted: #94a3b8; --dept-line: #334155; --dept-surface: #172234; --dept-text: #e2e8f0; --dept-accent: #c4b5fd; --dept-soft: #2e1b52; }
  .dept-card-top { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 18px; }
  .dept-card-icon { display: grid; place-items: center; width: 44px; height: 44px; border-radius: 13px; background: var(--dept-soft); color: var(--dept-accent); font-size: 19px; }
  .dept-card-prefix { padding: 5px 10px; border: 1px solid var(--dept-line); border-radius: 8px; color: var(--dept-muted); font-size: 11px; font-weight: 700; }
  .dept-card-title { min-height: 4.5em; margin: 0 0 12px; font-size: 17px; font-weight: 800; line-height: 1.5; color: var(--dept-text); overflow-wrap: anywhere; }
  .dept-card-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; margin-bottom: 20px; color: var(--dept-muted); font-size: 11px; }
  .dept-status { display: inline-flex; align-items: center; gap: 5px; color: var(--dept-muted); font-weight: 700; }
  .dept-status::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
  .dept-status[data-status="active"] { color: #059669; }
  .dept-status[data-status="maintenance"] { color: #b45309; }
  .dept-card-queue { padding: 14px; border: 1px solid var(--dept-line); border-radius: 12px; background: var(--dept-surface); }
  .dept-queue-heading { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 12px; font-size: 11px; color: var(--dept-muted); font-weight: 700; }
  .dept-queue-total { color: var(--dept-accent); font-size: 12px; }
  .dept-queue-stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); margin: 0; }
  .dept-queue-stats div { display: flex; flex-direction: column; align-items: center; gap: 4px; }
  .dept-queue-stats div + div { border-left: 1px solid var(--dept-line); }
  .dept-queue-stats dt { order: 1; font-size: 10px; color: var(--dept-muted); }
  .dept-queue-stats dd { margin: 0; font-size: 23px; line-height: 1.2; font-weight: 800; color: var(--dept-text); font-variant-numeric: tabular-nums; }
  .dept-card-footer { margin-top: auto; padding-top: 16px; }
  :is(html.dark-mode, body.dark-mode) .dept-status[data-status="active"] { color: #6ee7b7; }
  :is(html.dark-mode, body.dark-mode) .dept-status[data-status="maintenance"] { color: #fbbf24; }

  .dept-cards-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    align-items: start;
    gap: 24px;
  }
  @media (max-width: 1200px) { .dept-cards-grid { grid-template-columns: repeat(2, 1fr); } }
  @media (max-width: 768px) { .dept-cards-grid { grid-template-columns: 1fr; } }

  .dept-card-lux {
    min-width: 0;
    background: #ffffff;
    border: 1px solid #ece9f4;
    border-radius: 22px;
    padding: 22px;
    display: flex;
    flex-direction: column;
    transition: all 0.28s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 4px 20px -2px rgba(57, 35, 97, 0.04);
    position: relative;
  }
  .dept-card-lux:hover {
    box-shadow: 0 12px 28px -8px rgba(124, 58, 237, 0.12);
    border-color: #a78bfa;
  }
  body.dark-mode .dept-card-lux {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #f8fafc;
  }
  body.dark-mode .dept-card-lux [style*="background: #f3e8ff"] {
    background: #2e1b52 !important;
    color: #d8b4fe !important;
  }
  body.dark-mode .dept-card-lux [style*="border: 1px solid #e9d5ff"],
  body.dark-mode .dept-card-lux[style*="border-color: #e9d5ff"] {
    border-color: #6d28d9 !important;
  }
  body.dark-mode .dept-card-lux select {
    background: #0f172a !important;
    border-color: #475569 !important;
    color: #f8fafc !important;
  }
  body.dark-mode .dept-card-lux .bg-red-50 {
    background: #451a1a !important;
    border-color: #991b1b !important;
    color: #fca5a5 !important;
  }
  body.dark-mode .metrics-grid .stat-card-luxury div[style*="background: #f3e8ff"] {
    background: #2e1b52 !important;
  }
  body.dark-mode .metrics-grid .stat-card-luxury div[style*="background: #e0e7ff"] {
    background: #1e1b4b !important;
  }
  body.dark-mode .metrics-grid .stat-card-luxury div[style*="background: #ecfdf5"] {
    background: #052e2b !important;
  }
  .dept-card-actions {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: center;
    gap: 8px;
    min-width: 0;
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid var(--dept-line);
  }
  .dept-card-actions form,
  .dept-card-actions select {
    min-width: 0;
  }
  .dept-card-actions select {
    width: 100%;
  }
  .dept-card-delete {
    white-space: nowrap;
    padding: 6px 10px !important;
    font-size: 11px !important;
    min-height: 36px;
  }
  .dept-service-list { margin: 16px 0 0; }
  .dept-service-list summary { padding: 8px 0; cursor: pointer; color: var(--dept-accent); font-size: 12px; font-weight: 700; }
  .dept-service-list ul { list-style: none; margin: 0; padding: 0; max-height: 250px; overflow-y: auto; scrollbar-width: none; -ms-overflow-style: none; }
  .dept-service-list ul::-webkit-scrollbar { display: none; }
  .dept-service-list li { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 0; border-top: 1px solid #e2e8f0; }
  .dept-service-name { min-width: 0; font-size: 12px; line-height: 1.5; overflow-wrap: anywhere; color: #334155; }
  .dept-service-edit { flex-shrink: 0; border: 1px solid #ddd6fe; border-radius: 8px; background: #f5f3ff; color: #6d28d9; padding: 5px 9px; font-size: 11px; font-weight: 700; cursor: pointer; }
  .dept-service-empty { margin: 0 0 12px; color: #64748b; font-size: 12px; }
  .dept-add-service { justify-content: center; width: 100%; }
  .dept-card-lux :is(button, select, summary):focus-visible { outline: 2px solid #a78bfa; outline-offset: 3px; }
  @media (prefers-reduced-motion: reduce) { .dept-card-lux, .stat-card-luxury, .btn-lux-primary { transition: none; transform: none; } }
  .department-service-dialog { width: min(480px, calc(100vw - 32px)); max-height: calc(100vh - 48px); overflow-y: auto; margin: auto; border: 1px solid #e2e8f0; border-radius: 22px; padding: 24px; background: #ffffff; color: #0f172a; box-shadow: 0 24px 70px rgba(15, 23, 42, 0.3); }
  .department-service-dialog::backdrop { background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(4px); }
  .department-service-dialog h2 { margin: 0; font-size: 22px; font-weight: 900; }
  .department-service-context { margin: 8px 0 22px; font-size: 13px; line-height: 1.5; color: #64748b; overflow-wrap: anywhere; }
  .department-service-dialog label { display: block; margin-bottom: 7px; font-size: 12px; font-weight: 700; }
  .department-service-dialog input:not([type="hidden"]) { box-sizing: border-box; width: 100%; padding: 11px 12px; border: 1px solid #cbd5e1; border-radius: 10px; background: #ffffff; color: #0f172a; font-size: 14px; }
  .department-service-dialog input:focus { outline: 2px solid #a78bfa; outline-offset: 2px; }
  .department-service-field { margin-bottom: 18px; }
  .department-service-create-fields[hidden] { display: none !important; }
  .department-service-actions { display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 10px; margin-top: 24px; }
  .department-service-error { margin: 0 0 18px; border-radius: 10px; padding: 12px; background: #fef2f2; color: #b91c1c; font-size: 13px; }
  body.dark-mode .dept-service-list, body.dark-mode .dept-service-list li { border-color: #334155; }
  body.dark-mode .dept-service-list summary { color: #c4b5fd; }
  body.dark-mode .dept-service-name { color: #e2e8f0; }
  body.dark-mode .dept-service-edit { background: #2e1b52; border-color: #6d28d9; color: #d8b4fe; }
  body.dark-mode .dept-service-empty, body.dark-mode .department-service-context { color: #94a3b8; }
  body.dark-mode .department-service-dialog { background: #1e293b; border-color: #475569; color: #f8fafc; }
  body.dark-mode .department-service-dialog input:not([type="hidden"]) { background: #0f172a; border-color: #475569; color: #f8fafc; }
  body.dark-mode .department-service-error { background: #451a1a; color: #fca5a5; }
  @media (max-width: 420px) {
    .dept-card-actions {
      grid-template-columns: minmax(0, 1fr) auto;
    }
    .dept-card-lux { padding: 18px; }
    .dept-card-title { min-height: 0; }
  }

  .btn-lux-primary {
    background: linear-gradient(135deg, #7c3aed 0%, #6366f1 100%);
    color: #ffffff;
    font-size: 13px;
    font-weight: 700;
    padding: 10px 20px;
    border-radius: 12px;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    box-shadow: 0 4px 14px rgba(124, 58, 237, 0.3);
    transition: all 0.2s ease;
  }
  .btn-lux-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(124, 58, 237, 0.4);
  }

  .btn-lux-secondary {
    background: #ffffff;
    color: #475569;
    font-size: 13px;
    font-weight: 700;
    padding: 10px 18px;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    transition: all 0.2s ease;
  }
  .btn-lux-secondary:hover {
    background: #f8fafc;
    color: #0f172a;
    border-color: #cbd5e1;
  }

  /* Inline form panel */
  .modal-backdrop {
    position: relative;
    display: block;
    background: transparent;
    backdrop-filter: none;
    z-index: 1;
    padding: 0;
    margin-top: 20px;
  }
  .modal-backdrop.hidden-modal { display: none !important; }
  .modal-dialog {
    background: rgba(15, 23, 42, 0.72);
    border: 1px solid rgba(148, 163, 184, 0.22);
    border-radius: 24px;
    max-width: 100%;
    width: 100%;
    padding: 24px;
    box-shadow: 0 18px 42px rgba(15, 23, 42, 0.28);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
  }
  .toast-container {
    position: fixed;
    bottom: 24px;
    right: 24px;
    z-index: 1000;
    display: flex;
    flex-direction: column;
    gap: 10px;
    pointer-events: none;
  }
  .toast-item {
    background: #1e1b4b;
    color: #ffffff;
    padding: 14px 20px;
    border-radius: 14px;
    font-size: 13px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.2);
    pointer-events: auto;
  }
</style>

<div class="dept-container">

  <!-- Top Breadcrumbs & Action Bar -->
  <div class="dept-header-ribbon">
    <div>
      
    
      <h1 class="dept-title">Departments Directory</h1>
      
    </div>

    <!-- Action Buttons -->
    <div style="display: flex; align-items: center; gap: 12px;">
      <a href="services.php" class="btn-lux-secondary">
        <i class="fa-solid fa-briefcase" style="font-size: 16px; color: #7c3aed;" aria-hidden="true"></i>
        <span>View Services</span>
      </a>

      <button type="button" onclick="openAddDeptModal()" class="btn-lux-primary">
        <i data-feather="plus-circle" style="width: 16px; height: 16px;"></i>
        <span>Add Department</span>
      </button>
    </div>
  </div>

  <?php if ($msg): ?>
  <div style="margin-bottom: 24px; padding: 16px; border-radius: 16px; border: 1px solid <?= $msgType === 'error' ? '#fecaca; background: #fef2f2; color: #b91c1c;' : '#a7f3d0; background: #ecfdf5; color: #047857;' ?> font-weight: 700; font-size: 13px; display: flex; align-items: center; gap: 10px;">
    <i data-feather="<?= $msgType === 'error' ? 'alert-circle' : 'check-circle' ?>" style="width: 18px; height: 18px;"></i>
    <span><?= htmlspecialchars($msg) ?></span>
  </div>
  <?php endif; ?>

  <!-- Executive Metric Cards -->
  <div class="metrics-grid">
    <div class="stat-card-luxury">
      <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
        <span style="font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.6px; color: #94a3b8;">Total Departments</span>
        <div style="width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; background: #f3e8ff; color: #7c3aed;">
          <i class="fa-solid fa-layer-group" style="font-size: 20px;" aria-hidden="true"></i>
        </div>
      </div>
      <h3 style="font-size: 28px; font-weight: 900; color: #0f172a; margin: 0 0 8px 0;"><?= count($deptList) ?></h3>
      <p style="font-size: 12px; color: #64748b; margin: 0; display: flex; align-items: center; gap: 6px;">
        <i data-feather="check-circle" style="width: 14px; height: 14px; color: #10b981;"></i>
        <span>Across all statuses</span>
      </p>
    </div>

    <div class="stat-card-luxury">
      <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
        <span style="font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.6px; color: #94a3b8;">Connected Services</span>
        <div style="width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; background: #e0e7ff; color: #4f46e5;">
          <i class="fa-solid fa-briefcase" style="font-size: 20px;" aria-hidden="true"></i>
        </div>
      </div>
      <h3 style="font-size: 28px; font-weight: 900; color: #4f46e5; margin: 0 0 8px 0;">
        <?= array_sum(array_column($deptList, 'service_count')) ?>
      </h3>
      <p style="font-size: 12px; color: #64748b; margin: 0;">Configured service lines</p>
    </div>

    <div class="stat-card-luxury">
      <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
        <span style="font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.6px; color: #94a3b8;">Active Queue</span>
        <div style="width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; background: #ecfdf5; color: #059669;">
          <i class="fa-solid fa-users" style="font-size: 20px;" aria-hidden="true"></i>
        </div>
      </div>
      <h3 style="font-size: 28px; font-weight: 900; color: #059669; margin: 0 0 8px 0;">
        <?= array_sum(array_column($deptList, 'waiting_count')) + array_sum(array_column($deptList, 'called_count')) + array_sum(array_column($deptList, 'serving_count')) ?>
      </h3>
      <p style="font-size: 12px; color: #059669; font-weight: 700; margin: 0;">Waiting, called &amp; serving</p>
    </div>

  </div>

  <!-- Search Bar -->
  <div style="margin: 0 0 24px auto; position: relative; max-width: 450px;">
    <i data-feather="search" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; color: #94a3b8;"></i>
    <input type="text" id="deptSearchInput" onkeyup="filterDeptCards()" placeholder="Search department name or code..." style="width: 100%; box-sizing: border-box; padding: 12px 16px 12px 42px; border-radius: 14px; border: 1px solid #ece9f4; background: #ffffff; font-size: 13px; outline: none; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
  </div>

  <!-- Department Cards Grid -->
  <?php if (empty($deptList)): ?>
  <div style="text-align: center; padding: 48px; background: #ffffff; border: 1px solid #ece9f4; border-radius: 20px;">
    <div style="width: 56px; height: 56px; border-radius: 50%; background: #f3e8ff; color: #7c3aed; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px auto;">
      <i data-feather="layers" style="width: 24px; height: 24px;"></i>
    </div>
    <h3 style="font-size: 18px; font-weight: 800; color: #0f172a; margin: 0 0 6px 0;">No departments found</h3>
    <p style="font-size: 13px; color: #64748b; margin: 0;">Click "Add Department" above to create one.</p>
  </div>
  <?php else: ?>
  <div class="dept-cards-grid" id="deptGrid">
    <?php foreach ($deptList as $d):
      $status = $d['status'] ?? 'active';
      $activeCount = (int)$d['waiting_count'] + (int)$d['called_count'] + (int)$d['serving_count'];
      $departmentName = strtolower(trim($d['dept_name']));
      $deptIcon = match (true) {
        str_contains($departmentName, 'hair') || str_contains($departmentName, 'barber') || str_contains($departmentName, 'grooming') => 'fa-scissors',
        str_contains($departmentName, 'nail') => 'fa-hand-sparkles',
        str_contains($departmentName, 'lash') || str_contains($departmentName, 'brow') || str_contains($departmentName, 'eye') => 'fa-eye',
        str_contains($departmentName, 'skin') || str_contains($departmentName, 'facial') || str_contains($departmentName, 'esthetic') => 'fa-spa',
        str_contains($departmentName, 'medical') || str_contains($departmentName, 'wellness') || str_contains($departmentName, 'health') => 'fa-heart-pulse',
        str_contains($departmentName, 'massage') || str_contains($departmentName, 'body') => 'fa-hands',
        str_contains($departmentName, 'removal') => 'fa-wand-magic-sparkles',
        str_contains($departmentName, 'makeup') || str_contains($departmentName, 'glamour') || str_contains($departmentName, 'cosmetic') => 'fa-palette',
        str_contains($departmentName, 'piercing') || str_contains($departmentName, 'modification') => 'fa-gem',
        str_contains($departmentName, 'retail') || str_contains($departmentName, 'boutique') => 'fa-bag-shopping',
        str_contains($departmentName, 'cash') => 'fa-cash-register',
        default => 'fa-layer-group'
      };
    ?>
    <article class="dept-card-lux dept-item-card" id="department-<?= (int)$d['id'] ?>" aria-labelledby="department-title-<?= (int)$d['id'] ?>" data-name="<?= htmlspecialchars(strtolower($d['dept_name'])) ?>" data-code="<?= htmlspecialchars(strtolower($d['dept_code'])) ?>">
      <div>
        <div class="dept-card-top">
          <div class="dept-card-icon">
            <i class="fa-solid <?= $deptIcon ?>" aria-hidden="true"></i>
          </div>
          <span class="dept-card-prefix">
            Prefix: <?= htmlspecialchars($d['dept_code']) ?>-
          </span>
        </div>

        <h3 class="dept-card-title" id="department-title-<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['dept_name']) ?></h3>
        <div class="dept-card-meta">
          <span class="dept-status" data-status-description data-status="<?= htmlspecialchars($status, ENT_QUOTES) ?>"><?= ucfirst($status) ?></span>
          <span><?= (int)$d['service_count'] ?> services</span>
          <span>ID #<?= (int)$d['id'] ?></span>
        </div>

        <div class="dept-card-queue" role="group" aria-label="Active queue">
          <div class="dept-queue-heading">
            <span>Active queue</span>
            <strong class="dept-queue-total"><?= $activeCount ?> <?= $activeCount === 1 ? 'ticket' : 'tickets' ?></strong>
          </div>
          <dl class="dept-queue-stats">
            <div><dt>Waiting</dt><dd><?= (int)$d['waiting_count'] ?></dd></div>
            <div><dt>Called</dt><dd><?= (int)$d['called_count'] ?></dd></div>
            <div><dt>Serving</dt><dd><?= (int)$d['serving_count'] ?></dd></div>
          </dl>
        </div>
        <details class="dept-service-list" <?= (int)($_GET['department'] ?? 0) === (int)$d['id'] ? 'open' : '' ?>>
          <summary>Manage services (<?= (int)$d['service_count'] ?>)</summary>
          <?php if (empty($servicesByDepartment[(int)$d['id']])): ?>
          <p class="dept-service-empty">No services yet. Add a service below.</p>
          <?php else: ?>
          <ul>
            <?php foreach ($servicesByDepartment[(int)$d['id']] as $service): ?>
            <li>
              <span class="dept-service-name"><?= htmlspecialchars($service['service_name']) ?></span>
              <button type="button" class="dept-service-edit" data-service-action="rename_service" data-dept-id="<?= (int)$d['id'] ?>" data-dept-name="<?= htmlspecialchars($d['dept_name'], ENT_QUOTES) ?>" data-service-id="<?= (int)$service['id'] ?>" data-service-name="<?= htmlspecialchars($service['service_name'], ENT_QUOTES) ?>" aria-label="Edit <?= htmlspecialchars($service['service_name'], ENT_QUOTES) ?>">Edit</button>
            </li>
            <?php endforeach; ?>
          </ul>
          <?php endif; ?>
        </details>
      </div>

      <div class="dept-card-footer">
      <button type="button" class="btn-lux-primary dept-add-service" data-service-action="add_service" data-dept-id="<?= (int)$d['id'] ?>" data-dept-name="<?= htmlspecialchars($d['dept_name'], ENT_QUOTES) ?>">
        <i class="fa-solid fa-plus" aria-hidden="true"></i><span>Add Service</span>
      </button>
      <div class="dept-card-actions">
        <form method="post" class="inline">
          <input type="hidden" name="action" value="update_status">
          <input type="hidden" name="dept_id" value="<?= $d['id'] ?>">
          <select name="status" aria-label="<?= htmlspecialchars($d['dept_name']) ?> status" data-department-name="<?= htmlspecialchars($d['dept_name'], ENT_QUOTES) ?>" data-previous-status="<?= htmlspecialchars($status, ENT_QUOTES) ?>" onchange="confirmStatusChange(this)" style="font-size: 12px; font-weight: 700; color: #334155; border: 1px solid #cbd5e1; background: #ffffff; border-radius: 8px; cursor: pointer; min-height: 36px; padding: 6px 28px 6px 9px;">
            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            <option value="maintenance" <?= $status === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
          </select>
        </form>

        <div style="display: flex; align-items: center; gap: 8px;">
          <form method="post" class="inline" onsubmit="return confirm('Delete department <?= htmlspecialchars($d['dept_name'], ENT_QUOTES) ?>?');">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="dept_id" value="<?= $d['id'] ?>">
            <button type="submit" class="dept-card-delete px-3 py-1.5 rounded-xl border border-red-200 bg-red-50 hover:bg-red-100 text-red-600 font-bold text-xs transition inline-flex items-center gap-1.5 shadow-sm" title="Delete Department">
              <i data-feather="trash-2" style="width: 14px; height: 14px;"></i>
              <span>Delete</span>
            </button>
          </form>
        </div>
      </div>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>

<dialog id="departmentServiceDialog" class="department-service-dialog" aria-labelledby="departmentServiceTitle" aria-describedby="departmentServiceContext">
  <h2 id="departmentServiceTitle"><?= $editingService ? 'Edit Service Name' : 'Add Service' ?></h2>
  <p id="departmentServiceContext" class="department-service-context"><?= htmlspecialchars($serviceDepartmentName) ?></p>
  <?php if ($showServiceForm): ?>
  <p class="department-service-error" id="departmentServiceError" role="alert"><?= htmlspecialchars($msg) ?></p>
  <?php endif; ?>
  <form method="post" id="departmentServiceForm" data-no-transition="true">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['department_service_csrf'], ENT_QUOTES) ?>">
    <input type="hidden" name="action" id="departmentServiceAction" value="<?= htmlspecialchars($serviceForm['action'], ENT_QUOTES) ?>">
    <input type="hidden" name="dept_id" id="departmentServiceDeptId" value="<?= $serviceForm['dept_id'] ?>">
    <input type="hidden" name="service_id" id="departmentServiceId" value="<?= $serviceForm['service_id'] ?>">
    <div class="department-service-field">
      <label for="departmentServiceName">Service Name</label>
      <input type="text" name="service_name" id="departmentServiceName" required maxlength="160" value="<?= htmlspecialchars($serviceForm['service_name'], ENT_QUOTES) ?>" placeholder="Enter service name">
    </div>
    <div id="departmentServiceCreateFields" class="department-service-create-fields" <?= $editingService ? 'hidden' : '' ?>>
      <div class="department-service-field">
        <label for="departmentServicePrice">Service Price (&#8369;)</label>
        <input type="number" name="service_price" id="departmentServicePrice" required min="0" max="99999999.99" step="0.01" inputmode="decimal" value="<?= htmlspecialchars($serviceForm['service_price'], ENT_QUOTES) ?>" placeholder="0.00" <?= $editingService ? 'disabled' : '' ?>>
      </div>
      <div class="department-service-field">
        <label for="departmentServiceMinutes">Estimated Time (Minutes)</label>
        <input type="number" name="estimated_time_mins" id="departmentServiceMinutes" required min="1" max="120" step="1" value="<?= htmlspecialchars($serviceForm['estimated_time_mins'], ENT_QUOTES) ?>" <?= $editingService ? 'disabled' : '' ?>>
      </div>
    </div>
    <div class="department-service-actions">
      <button type="button" class="btn-lux-secondary" onclick="document.getElementById('departmentServiceDialog').close()">Cancel</button>
      <button type="submit" class="btn-lux-primary" id="departmentServiceSubmit"><?= $editingService ? 'Save Name' : 'Add Service' ?></button>
    </div>
  </form>
</dialog>

<!-- Add Department Form (inline in page) -->
<div id="addDeptModal" class="modal-backdrop hidden-modal">
  <div class="modal-dialog">
    <div style="display: flex; align-items: center; justify-content: space-between; padding-bottom: 16px; border-bottom: 1px solid rgba(148, 163, 184, 0.2);">
      <div>
        <h3 style="font-size: 18px; font-weight: 900; color: #f8fafc; margin: 0;">Add Department</h3>
      </div>
    </div>

    <form method="post" style="margin-top: 20px; display: flex; flex-direction: column; gap: 16px;">
      <input type="hidden" name="action" value="create">

      <div>
        <label style="display: block; font-size: 11px; font-weight: 800; text-transform: uppercase; color: #cbd5e1; margin-bottom: 6px;">Department Name</label>
        <input type="text" id="departmentNameInput" name="dept_name" required placeholder="Enter department name" style="width: 100%; box-sizing: border-box; border-radius: 12px; border: 1px solid rgba(148, 163, 184, 0.28); background: rgba(15, 23, 42, 0.35); color: #f8fafc; padding: 10px 14px; font-size: 13px; outline: none; box-shadow: inset 0 1px 0 rgba(255,255,255,0.05);">
      </div>

      <div>
        <label style="display: block; font-size: 11px; font-weight: 800; text-transform: uppercase; color: #cbd5e1; margin-bottom: 6px;">Ticket Prefix Code</label>
        <input type="text" id="departmentCodeInput" name="dept_code" required maxlength="5" placeholder="Auto-filled from department name" style="width: 100%; box-sizing: border-box; border-radius: 12px; border: 1px solid rgba(148, 163, 184, 0.28); background: rgba(15, 23, 42, 0.35); color: #f8fafc; padding: 10px 14px; font-size: 13px; outline: none; text-transform: uppercase; box-shadow: inset 0 1px 0 rgba(255,255,255,0.05);">
      </div>

      <div>
        <label style="display: block; font-size: 11px; font-weight: 800; text-transform: uppercase; color: #cbd5e1; margin-bottom: 6px;">First Service</label>
        <input type="text" name="service_name" required placeholder="e.g. Haircut & Styling" style="width: 100%; box-sizing: border-box; border-radius: 12px; border: 1px solid rgba(148, 163, 184, 0.28); background: rgba(15, 23, 42, 0.35); color: #f8fafc; padding: 10px 14px; font-size: 13px; outline: none; box-shadow: inset 0 1px 0 rgba(255,255,255,0.05);">
      </div>

      <div>
        <label style="display: block; font-size: 11px; font-weight: 800; text-transform: uppercase; color: #cbd5e1; margin-bottom: 6px;">Service Price (&#8369;)</label>
        <input type="text" name="service_price" required inputmode="decimal" min="0" placeholder="e.g. 700.00" style="width: 100%; box-sizing: border-box; border-radius: 12px; border: 1px solid rgba(148, 163, 184, 0.28); background: rgba(15, 23, 42, 0.35); color: #f8fafc; padding: 10px 14px; font-size: 13px; outline: none; box-shadow: inset 0 1px 0 rgba(255,255,255,0.05);">
      </div>

      <div>
        <label style="display: block; font-size: 11px; font-weight: 800; text-transform: uppercase; color: #cbd5e1; margin-bottom: 6px;">Department Status</label>
        <select name="status" style="width: 100%; box-sizing: border-box; border-radius: 12px; border: 1px solid rgba(148, 163, 184, 0.28); background: rgba(15, 23, 42, 0.35); color: #f8fafc; padding: 10px 14px; font-size: 13px; outline: none; box-shadow: inset 0 1px 0 rgba(255,255,255,0.05);">
          <option value="active" selected>Active</option>
          <option value="inactive">Inactive</option>
          <option value="maintenance">Maintenance</option>
        </select>
      </div>

      <div style="padding-top: 16px; border-top: 1px solid rgba(148, 163, 184, 0.2); display: flex; justify-content: flex-end; gap: 10px;">
        <button type="button" onclick="closeAddDeptModal()" class="btn-lux-secondary">Cancel</button>
        <button type="submit" class="btn-lux-primary">Save</button>
      </div>
    </form>
  </div>
</div>

<div class="toast-container" id="toastBox"></div>

<script>
  function openDepartmentServiceDialog(button) {
    const editing = button.dataset.serviceAction === 'rename_service';
    document.getElementById('departmentServiceAction').value = button.dataset.serviceAction;
    document.getElementById('departmentServiceDeptId').value = button.dataset.deptId;
    document.getElementById('departmentServiceId').value = button.dataset.serviceId || '';
    document.getElementById('departmentServiceName').value = button.dataset.serviceName || '';
    document.getElementById('departmentServicePrice').value = '';
    document.getElementById('departmentServiceMinutes').value = '30';
    document.getElementById('departmentServiceTitle').textContent = editing ? 'Edit Service Name' : 'Add Service';
    document.getElementById('departmentServiceSubmit').textContent = editing ? 'Save Name' : 'Add Service';
    document.getElementById('departmentServiceContext').textContent = button.dataset.deptName || '';
    const fields = document.getElementById('departmentServiceCreateFields');
    fields.hidden = editing;
    fields.querySelectorAll('input').forEach(input => { input.disabled = editing; });
    document.getElementById('departmentServiceError')?.remove();
    document.getElementById('departmentServiceDialog').showModal();
    document.getElementById('departmentServiceName').focus();
  }

  function filterDeptCards() {
    const q = document.getElementById('deptSearchInput').value.toLowerCase().trim();
    const cards = document.querySelectorAll('.dept-item-card');
    cards.forEach(c => {
      const name = c.getAttribute('data-name') || '';
      const code = c.getAttribute('data-code') || '';
      if (!q || name.includes(q) || code.includes(q)) {
        c.style.display = '';
      } else {
        c.style.display = 'none';
      }
    });
  }

  function openAddDeptModal() {
    const panel = document.getElementById('addDeptModal');
    if (panel) {
      panel.classList.remove('hidden-modal');
      panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    if (typeof feather !== 'undefined') feather.replace();
  }

  function closeAddDeptModal() {
    const panel = document.getElementById('addDeptModal');
    if (panel) panel.classList.add('hidden-modal');
  }

  const departmentNameInput = document.getElementById('departmentNameInput');
  const departmentCodeInput = document.getElementById('departmentCodeInput');
  const departmentOptions = [...document.querySelectorAll('#departmentNameOptions option')];
  departmentNameInput?.addEventListener('input', () => {
    const value = departmentNameInput.value.trim();
    const selectedOption = departmentOptions.find((option) => option.value.toLowerCase() === value.toLowerCase());
    if (selectedOption?.dataset.code) {
      departmentCodeInput.value = selectedOption.dataset.code;
      return;
    }
    departmentCodeInput.value = value
      .replace(/&/g, ' ')
      .split(/\s+/)
      .filter(Boolean)
      .map((word) => word[0])
      .join('')
      .slice(0, 5)
      .toUpperCase();
  });

  function confirmStatusChange(select) {
    const previousStatus = select.dataset.previousStatus;
    const selectedLabel = select.options[select.selectedIndex].text;
    if (!window.confirm(`Change ${select.dataset.departmentName} to ${selectedLabel}?`)) {
      select.value = previousStatus;
      return;
    }
    const card = select.closest('.dept-item-card');
    fetch('departments.php', { method: 'POST', body: new FormData(select.form) })
      .then((response) => {
        if (!response.ok) throw new Error('Status update failed');
        select.dataset.previousStatus = select.value;
        const status = select.value;
        const statusDescription = card?.querySelector('[data-status-description]');
        if (statusDescription) {
          statusDescription.textContent = status.charAt(0).toUpperCase() + status.slice(1);
          statusDescription.dataset.status = status;
        }
        showToast('Department status updated.');
      })
      .catch(() => {
        select.value = previousStatus;
        showToast('Could not update department status.');
      });
  }

  function showToast(msg) {
    const toastBox = document.getElementById('toastBox');
    const toast = document.createElement('div');
    toast.className = 'toast-item';
    toast.innerHTML = `<i data-feather="check-circle" style="width: 18px; height: 18px; color: #34d399;"></i><span>${msg}</span>`;
    toastBox.appendChild(toast);
    if (typeof feather !== 'undefined') feather.replace();
    setTimeout(() => { toast.remove(); }, 3200);
  }

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-service-action]').forEach(button => {
      button.addEventListener('click', () => openDepartmentServiceDialog(button));
    });
    if (<?= $showServiceForm ? 'true' : 'false' ?>) {
      document.getElementById('departmentServiceDialog').showModal();
      document.getElementById('departmentServiceName').focus();
    }
    if (typeof feather !== 'undefined') {
      feather.replace();
    }
    const queueMenu = document.getElementById('queueMenu');
    const queueSidebar = document.getElementById('queueSidebar');
    if (queueMenu && queueSidebar) {
      queueMenu.addEventListener('click', () => queueSidebar.classList.toggle('is-open'));
    }

    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('created')) {
      showToast('Department created and saved in MySQL!');
    }
    if (urlParams.has('deleted')) {
      showToast('Department deleted from MySQL database.');
    }
    const serviceNotice = <?= json_encode($serviceNotice, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    if (serviceNotice === 'service_added') showToast('Service added.');
    if (serviceNotice === 'service_renamed') showToast('Service name updated.');
  });
</script>

<?php require_once '../includes/footer.php'; ?>
</div></main></div></body></html>
