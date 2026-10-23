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
require_once __DIR__ . '/../includes/service_times.php';

$serviceTimeCsrf = $_SESSION['service_time_csrf'] ??= bin2hex(random_bytes(32));
$msg = $_SESSION['service_time_success'] ?? '';
unset($_SESSION['service_time_success']);
$msgType = 'success';
$bulkTimeError = '';
$bulkTimeDepartment = 0;
$bulkTimeMinutes = '';
$bulkTimeSelected = [];
if (isset($_SESSION['service_bulk_time_preview'])) {
    $pending = $_SESSION['service_bulk_time_preview'];
    $bulkTimeDepartment = $pending['department_id'];
    $bulkTimeMinutes = (string)$pending['minutes'];
    $bulkTimeSelected = array_column($pending['rows'], 'id');
}
$isActionPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action']);
if ($isActionPost && $_POST['action'] === 'preview_times') {
    $bulkTimeDepartment = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    $bulkTimeMinutes = is_string($_POST['minutes'] ?? null) ? $_POST['minutes'] : '';
    $bulkTimeSelected = [];
    foreach (is_array($_POST['service_ids'] ?? null) ? array_slice($_POST['service_ids'], 0, SERVICE_TIME_BATCH_LIMIT) : [] as $value) {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id !== false) $bulkTimeSelected[] = $id;
    }
}
$validActionToken = is_string($_POST['csrf_token'] ?? null) && hash_equals($serviceTimeCsrf, $_POST['csrf_token']);
if ($isActionPost && !$validActionToken) {
    $msg = 'Your session could not be verified. Refresh this page and try again.';
    $msgType = 'error';
}

// Handle Server-Side Actions (Create, Update & Delete Services in MySQL)
if ($isActionPost && $validActionToken) {
    if (in_array($_POST['action'], ['preview_times', 'apply_times', 'cancel_times'], true)) {
        try {
            if ($_POST['action'] === 'cancel_times') {
                unset($_SESSION['service_bulk_time_preview']);
                header('Location: services.php#bulk-service-times');
                exit;
            }
            if (!$conn) throw new RuntimeException('The database is unavailable. Please try again.');
            if ($_POST['action'] === 'preview_times') {
                unset($_SESSION['service_bulk_time_preview']);
                if ($bulkTimeDepartment <= 0) throw new InvalidArgumentException('Choose a department to update.');
                $_SESSION['service_bulk_time_preview'] = serviceTimePreview($conn, $bulkTimeDepartment,
                    $_POST['service_ids'] ?? null, $_POST['minutes'] ?? null);
                header('Location: services.php#bulk-time-preview');
                exit;
            }
            $preview = $_SESSION['service_bulk_time_preview'] ?? null;
            if (!$preview || !is_string($_POST['preview_token'] ?? null) || !hash_equals($preview['token'], $_POST['preview_token'])) {
                throw new RuntimeException('This preview is no longer available. Generate a new preview before applying.');
            }
            $changed = serviceTimeApply($conn, $preview);
            unset($_SESSION['service_bulk_time_preview']);
            $_SESSION['service_time_success'] = 'Updated ' . $changed . ($changed === 1 ? ' service' : ' services') . ' to '
                . $preview['minutes'] . ' minutes in ' . $preview['department_name'] . '.';
            header('Location: services.php#bulk-service-times');
            exit;
        } catch (InvalidArgumentException | RuntimeException $error) {
            if ($_POST['action'] === 'apply_times') unset($_SESSION['service_bulk_time_preview']);
            $bulkTimeError = $error->getMessage();
        }
    }
    if ($_POST['action'] === 'create' && $conn) {
        $service_name = trim($_POST['service_name'] ?? '');
        $dept_id = (int)($_POST['dept_id'] ?? 0);
        $est_time = (int)($_POST['estimated_time_mins'] ?? 10);

        if ($service_name !== '' && $dept_id > 0) {
            $stmt = $conn->prepare("INSERT INTO services (dept_id, service_name, estimated_time_mins) VALUES (?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("isi", $dept_id, $service_name, $est_time);
                if ($stmt->execute()) {
                    header("Location: services.php?created=1");
                    exit;
                } else {
                    $msg = "Error creating service in database.";
                    $msgType = "error";
                }
                $stmt->close();
            }
        } else {
            $msg = "Please select a department and enter a service name.";
            $msgType = "error";
        }
    }

    if ($_POST['action'] === 'update_time') {
      $msg = 'Use Bulk update processing time to review and apply service time changes.';
      $msgType = 'error';
    }

    if ($_POST['action'] === 'delete' && $conn) {
        $serviceId = (int)($_POST['service_id'] ?? 0);
        if ($serviceId > 0) {
            $stmt = $conn->prepare("DELETE FROM services WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $serviceId);
                $stmt->execute();
                $stmt->close();
                header("Location: services.php?deleted=1");
                exit;
            }
        }
    }
}

  if ($conn) {
    $catalog = [
      ['Hair Care & Hair Styling Department', 'HCHS', [
        'Women\'s Precision Cut & Style', 'Men\'s Classic Haircut', 'Children\'s Haircut (12 & under)', 'Bangs / Fringe Trim', 'Split-End Removal Treatment',
        'Signature Blowout', 'Wash, Blow-dry & Curling / Flat Iron', 'Formal Updo / Evening Styling', 'Bridal Hair Styling (Trial & Event Day)', 'Braids, Cornrows & Twists', 'Shampoo & Set',
        'Single-Process All-Over Color', 'Root Touch-Up & Gray Coverage', 'Traditional Highlights (Full Head / Half Head)', 'Balayage / Ombre / Color Melting', 'Baby Lights / Money Piece Highlights', 'Gloss / Toner / Shine Treatment', 'Color Correction', 'Creative / Pastel Fashion Colors',
        'Brazilian Blowout / Keratin Treatment', 'Thermal Rebonding / Hair Straightening', 'Nanoplasty Smoothing', 'Digital Perm / Cold Wave Perm', 'Chemical Hair Relaxer',
        'Olaplex Bond Building Treatment', 'Deep Conditioning Moisture Mask', 'Scalp Detox & Exfoliating Scrub', 'Hair Botox Therapy', 'Anti-Dandruff Intensive Scalp Care', 'Hair Loss & Thinning Therapy',
        'Tape-In Extensions Installation', 'K-Tip / I-Tip Extensions', 'Sew-In / Weave Extensions', 'Extension Removal, Re-fitting & Maintenance',
      ]],
      ["Barbering & Men's Grooming Department", 'BMG', [
        'Precision Fade (Skin Fade, Drop Fade, Taper Fade)', 'Men\'s Classic Scissors Cut', 'Buzz Cut & Line-Up', 'Hair Tattooing & Razor Designs', 'Hot Towel Straight Razor Shave', 'Beard Sculpting & Line-Up', 'Beard Trimming & Conditioning', 'Mustache Grooming & Waxing', 'Beard Color & Gray Blending', 'Scalp & Shoulder Massage', 'Men\'s Deep Cleanse Express Facial', 'Nose & Ear Waxing', 'Eyebrow Cleanup & Shaping',
      ]],
      ['Nail Care & Enhancements Department', 'NCE', [
        'Classic Manicure / Pedicure', 'Gel Manicure / Pedicure', 'Russian Dry Manicure', 'Express Nail Cleanup', 'Sports / Men\'s Nail Care', 'Paraffin Wax Treatment (Hands / Feet)', 'Hydrating Hand & Foot Spa', 'Intensive Callus Removal', 'Full Set Acrylic Extensions', 'Builder Gel / BIAB (Builder in a Bottle)', 'Polygel Extensions', 'Dip Powder (SNS) Nails', 'Hard Gel Overlay', 'Nail Extension Removal & Refill', 'Hand-Painted Nail Art', 'Chrome / Holographic Finish', 'French Tip Design', 'Rhinestone & 3D Embellishments', 'Cat-Eye Gel Polish', 'Single Nail Repair',
      ]],
      ['Skin Care & Esthetics Department', 'SCE', [
        'Deep Cleansing Facial', 'Hydrating / Moisture Surge Facial', 'Gentle Sensitive Skin Facial', 'European Relaxing Facial', 'HydraFacial', 'Microdermabrasion / Diamond Peel', 'Chemical Peels (Glycolic, Salicylic, TCA)', 'LED Light Therapy (Red/Blue Light)', 'Oxygen Infusion Facial', 'Back Facial (Back Acne Treatment)', 'Milia / Extraction Treatment', 'Chest & Neck Decongestant Treatment', 'Hyperpigmentation / Dark Spot Corrector',
      ]],
      ['Medical Aesthetics Department (MedSpa)', 'MED', [
        'Botox / Neurotoxin Injections', 'Dermal Fillers (Lips, Cheeks, Jawline, Under-Eye)', 'Lip Flip', 'Profhilo Skin Remodeling', 'Kybella Fat Dissolving (Double Chin)', 'CO2 Fractional Laser Resurfacing', 'Carbon Laser Peel (Hollywood Peel)', 'Laser Tattoo Removal', 'Spider Vein & Pigmentation Removal', 'Microneedling with Hyaluronic Acid', 'PRP Vampire Facial (Platelet-Rich Plasma)', 'Exosome Therapy', 'PDO Thread Lift', 'CoolSculpting (Cryolipolysis)', 'Emsculpt Muscle Toning', 'Ultrasonic Cavitation & RF Slimming', 'Endermologie Cellulite Reduction',
      ]],
      ['Lash, Brow & Semi-Permanent Makeup (PMU) Department', 'PMU', [
        'Classic Lash Extensions', 'Hybrid Lash Extensions', 'Volume & Mega Volume Lash Extensions', 'Lash Lift & Tint', 'Lash Extension Removal', 'Lash Nourishing Botox Treatment', 'Eyebrow Threading', 'Eyebrow Waxing & Tweezing', 'Brow Lamination', 'Brow Tinting', 'Henna Brows', 'Microblading / Hair-Stroke Brows', 'Powder / Ombre Brows', 'Combination Brows', 'Lip Blush Tattoo', 'Tightline Eyeliner Tattoo', 'PMU Color Correction or Removal',
      ]],
      ['Body & Massage Therapy Department', 'BMT', [
        'Swedish Massage', 'Deep Tissue Massage', 'Hot Stone Massage', 'Aromatherapy Massage', 'Prenatal / Pregnancy Massage', 'Foot Reflexology', 'Shiatsu Massage', 'Exfoliating Body Scrub / Polish', 'Detoxifying Body Wrap', 'Lymphatic Drainage Massage', 'Back Scrub & Massage Combo', 'Custom Airbrush Spray Tan', 'Express Rapid Spray Tan', 'Sunbed UV Tanning',
      ]],
      ['Hair Removal Department', 'HR', [
        'Full Body Waxing', 'Brazilian & Bikini Waxing', 'Underarm Waxing', 'Leg & Arm Waxing', 'Facial Waxing (Lip, Chin, Cheeks)', 'Full Face Threading', 'Organic Body Sugaring', 'Diode Laser Hair Removal', 'IPL (Intense Pulsed Light) Hair Removal',
      ]],
      ['Wellness & Holistic Health Department', 'WHH', [
        'Infrared Sauna Session', 'Cold Plunge Therapy', 'Salt Room / Halotherapy', 'Acupuncture', 'Cupping Therapy (Venting)', 'Reiki Energy Healing', 'Hydration IV Drip', 'Immunity / Vitamin C Drip', 'Glutathione Glow Drip', 'Vitamin B12 Shot', 'NAD+ Anti-Aging Injections',
      ]],
      ['Piercing & Body Modification Department', 'PBM', [
        'Ear Lobe Piercing', 'Cartilage / Helix / Tragus Piercing', 'Nose (Nostril / Septum) Piercing', 'Lip & Tongue Piercing', 'Navel Piercing', 'Nipple & Surface Piercings', 'Implant-Grade Titanium/Gold Jewelry Fitting', 'Post-Piercing Downsizing & Check-Up', 'Piercing Hole Tapering & Re-Opening',
      ]],
      ['Makeup & Glamour Department', 'MGP', [
        'Day / Natural Glam Makeup', 'Full Evening Glam Makeup', 'Airbrush Makeup', 'Special Effects (SFX) / Creative Makeup', 'Bridal Makeup (Trial & Wedding Day)', 'Bridesmaids & Entourage Makeup', 'On-Site Group Event Glam',
      ]],
      ['Retail & Boutique Department', 'RBT', [
        'Professional Shampoo & Conditioner Sets', 'Hair Masks, Oils & Leave-in Conditioners', 'Serums, Sunscreen & Skincare Products', 'Cuticle Oils & Hand Creams', 'Hot Hair Tools (Straighteners, Curling Wands)', 'Silk Pillowcases & Scrunchies', 'Scalp Massagers & Hair Brushes', 'Eyelash & Eyebrow Aftercare Kits',
      ]],
    ];

    $departmentStmt = $conn->prepare('INSERT INTO departments (dept_name, dept_code, status) SELECT ?, ?, \'active\' WHERE NOT EXISTS (SELECT 1 FROM departments WHERE dept_name = ?)');
    $serviceStmt = $conn->prepare('INSERT INTO services (dept_id, service_name, estimated_time_mins) SELECT ?, ?, ? WHERE NOT EXISTS (SELECT 1 FROM services WHERE dept_id = ? AND service_name = ?)');
    $serviceCountStmt = $conn->prepare('SELECT COUNT(*) AS service_count FROM services WHERE dept_id = ?');
    if ($departmentStmt && $serviceStmt && $serviceCountStmt) {
      foreach ($catalog as [$departmentName, $departmentCode, $serviceNames]) {
        $departmentStmt->bind_param('sss', $departmentName, $departmentCode, $departmentName);
        $departmentStmt->execute();
        $departmentResult = $conn->query("SELECT id FROM departments WHERE dept_name = '" . $conn->real_escape_string($departmentName) . "' LIMIT 1");
        $department = $departmentResult ? $departmentResult->fetch_assoc() : null;
        $departmentId = (int)($department['id'] ?? 0);
        if ($departmentId <= 0) {
          continue;
        }
        $serviceCountStmt->bind_param('i', $departmentId);
        if (!$serviceCountStmt->execute()) {
          continue;
        }
        $serviceCount = $serviceCountStmt->get_result()->fetch_assoc();
        // Initialize empty departments only; keep administrators' service names.
        if ((int)($serviceCount['service_count'] ?? 0) > 0) {
          continue;
        }
        foreach ($serviceNames as $serviceName) {
          $estimatedTime = 30;
          $serviceStmt->bind_param('isiis', $departmentId, $serviceName, $estimatedTime, $departmentId, $serviceName);
          $serviceStmt->execute();
        }
      }
    }
    foreach ([$departmentStmt, $serviceStmt, $serviceCountStmt] as $statement) {
      if ($statement instanceof mysqli_stmt) $statement->close();
    }
  }

// Fetch ONLY Services that exist in MySQL
$serviceList = [];
$deptList = [];

if ($conn) {
    // Fetch departments for dropdown picker & statistics
    $dRes = $conn->query("SELECT id, dept_name, dept_code FROM departments ORDER BY id ASC");
    if ($dRes) {
        while ($dRow = $dRes->fetch_assoc()) {
            $deptList[] = $dRow;
        }
    }

    // Fetch services joined with department details
    $sRes = $conn->query("SELECT s.*, d.dept_name, d.dept_code FROM services s LEFT JOIN departments d ON s.dept_id = d.id ORDER BY s.id ASC");
    if ($sRes) {
        while ($sRow = $sRes->fetch_assoc()) {
            $serviceList[] = $sRow;
        }
    }
}

$serviceGroups = [];
foreach ($serviceList as $service) {
    $departmentId = (int)$service['dept_id'];
    if (!isset($serviceGroups[$departmentId])) {
        $serviceGroups[$departmentId] = [
            'name' => $service['dept_name'] ?? 'General',
            'code' => $service['dept_code'] ?? 'ID ' . $departmentId,
            'services' => [],
        ];
    }
    $serviceGroups[$departmentId]['services'][] = $service;
}
$bulkTimePreview = $_SESSION['service_bulk_time_preview'] ?? null;
$bulkTimeDepartments = array_filter($deptList, static fn($department) => isset($serviceGroups[(int)$department['id']]));
$bulkTimeCheckedCount = count(array_filter($serviceList, static fn($service) => (int)$service['dept_id'] === $bulkTimeDepartment && in_array((int)$service['id'], $bulkTimeSelected, true)));
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$pageTitle = 'Services | Queuezy Admin'; 
$pageHeading = 'Services Catalog'; 
require_once '../includes/header.php'; 
?>

<link rel="stylesheet" href="../assets/css/admin-directory.css">
<link rel="stylesheet" href="../assets/css/service-bulk-times.css">

<!-- Embedded Custom CSS for Senior-Grade UI/UX & Micro-interactions -->
<style>
  .service-directory-card {
    display: flex;
    flex-direction: column;
    gap: 20px;
    min-width: 0;
    padding: 20px;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    background: #ffffff;
    color: #0f172a;
  }
  .service-directory-title {
    flex: 1;
    margin: 0;
    font-size: 16px;
    font-weight: 700;
    line-height: 1.5;
    overflow-wrap: anywhere;
  }
  .service-directory-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    padding-top: 16px;
    border-top: 1px solid #e2e8f0;
  }
  .service-directory-duration { display: grid; gap: 4px; }
  .service-directory-duration > span { display: flex; align-items: center; gap: 6px; color: #64748b; font-size: 11px; line-height: 1.4; }
  .service-directory-duration svg { width: 13px; height: 13px; }
  .service-directory-duration strong { color: #334155; font-size: 16px; font-weight: 700; line-height: 1.3; font-variant-numeric: tabular-nums; }
  .service-directory-duration strong span { font-size: 12px; font-weight: 500; }
  .service-directory-footer form { margin: 0; }
  .service-directory-card .service-directory-delete {
    --directory-surface: transparent;
    --directory-border: #e2e8f0;
    --directory-text: #b91c1c;
    --directory-hover: #fef2f2;
    --directory-hover-border: #fecaca;
    --directory-purple: #b91c1c;
    min-height: 44px;
    padding: 10px 12px;
  }
  html.dark-mode .service-directory-card,
  body.dark-mode .service-directory-card { background: #1e293b; border-color: #334155; color: #f8fafc; }
  html.dark-mode .service-directory-footer,
  body.dark-mode .service-directory-footer { border-color: #334155; }
  html.dark-mode .service-directory-duration > span,
  body.dark-mode .service-directory-duration > span { color: #94a3b8; }
  html.dark-mode .service-directory-duration strong,
  body.dark-mode .service-directory-duration strong { color: #e2e8f0; }
  html.dark-mode .service-directory-card .service-directory-delete,
  body.dark-mode .service-directory-card .service-directory-delete {
    --directory-border: #475569;
    --directory-text: #fca5a5;
    --directory-hover: #451a1a;
    --directory-hover-border: #991b1b;
    --directory-purple: #fca5a5;
  }
  .service-groups { display: grid; gap: 16px; }
  .service-group {
    min-width: 0;
    border: 1px solid #ece9f4;
    border-radius: 20px;
    background: #ffffff;
    overflow: hidden;
    transition: border-color 0.2s ease;
  }
  .service-group:hover { border-color: #d8b4fe; }
  .service-group-summary {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 20px 24px;
    cursor: pointer;
    list-style: none;
    color: #0f172a;
    transition: background-color 0.2s ease;
  }
  .service-group-summary::-webkit-details-marker { display: none; }
  .service-group-summary:hover { background: #faf5ff; }
  .service-group-summary:focus-visible { outline: 3px solid #a855f7; outline-offset: -3px; }
  .service-group-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 40px;
    height: 40px;
    border-radius: 12px;
    background: #f3e8ff;
    color: #7c3aed;
  }
  .service-group-heading { flex: 1; min-width: 0; }
  .service-group-name { display: block; font-size: 15px; font-weight: 800; overflow-wrap: anywhere; }
  .service-group-code { display: block; margin-top: 4px; color: #64748b; font-size: 12px; }
  .service-group-count { flex-shrink: 0; color: #7c3aed; font-size: 12px; font-weight: 700; }
  .service-group-chevron { flex-shrink: 0; width: 20px; height: 20px; transition: transform 0.2s ease; }
  .service-group[open] > .service-group-summary .service-group-chevron { transform: rotate(180deg); }
  .service-group-content {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 260px), 1fr));
    gap: 16px;
    align-items: stretch;
    padding: 20px;
    border-top: 1px solid #ece9f4;
  }
  html.dark-mode .service-group, body.dark-mode .service-group { background: #1e293b; border-color: #334155; }
  html.dark-mode .service-group:hover, body.dark-mode .service-group:hover { border-color: #8b5cf6; }
  html.dark-mode .service-group-summary, body.dark-mode .service-group-summary { color: #f8fafc; }
  html.dark-mode .service-group-summary:hover, body.dark-mode .service-group-summary:hover { background: #2a2240; }
  html.dark-mode .service-group-icon, body.dark-mode .service-group-icon { background: #2e1b52; color: #d8b4fe; }
  html.dark-mode .service-group-code, body.dark-mode .service-group-code { color: #cbd5e1; }
  html.dark-mode .service-group-count, body.dark-mode .service-group-count { color: #d8b4fe; }
  html.dark-mode .service-group-content, body.dark-mode .service-group-content { border-color: #334155; }
  @media (max-width: 640px) {
    .service-group-summary { padding: 16px; gap: 10px; }
    .service-group-content { padding: 16px; }
    .service-group-name { font-size: 14px; }
    .service-directory-card { padding: 16px; }
  }
  @media (prefers-reduced-motion: reduce) {
    .service-group,
    .service-group-summary,
    .service-group-chevron { transition: none; }
  }

  .hidden { display: none !important; }

  /* Toast Notification */
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
    border-radius: 16px;
    font-size: 13px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.2);
    pointer-events: auto;
    animation: toastSlideIn 0.3s cubic-bezier(0.4, 0, 0.2, 1) forwards;
  }
  @keyframes toastSlideIn {
    from { opacity: 0; transform: translateY(16px) scale(0.95); }
    to { opacity: 1; transform: translateY(0) scale(1); }
  }
</style>

<!-- Top Breadcrumbs & Action Bar -->
<div class="mb-8">
  <div class="flex flex-wrap items-center justify-between gap-4">
   
      <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">Services Directory</h1>
    

  </div>
</div>

<?php if ($msg): ?>
<div role="<?= $msgType === 'error' ? 'alert' : 'status' ?>" class="mb-6 p-4 rounded-2xl border <?= $msgType === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-emerald-50 border-emerald-200 text-emerald-700' ?> font-semibold text-xs flex items-center gap-2">
  <i data-feather="<?= $msgType === 'error' ? 'alert-circle' : 'check-circle' ?>" class="w-4 h-4"></i>
  <span><?= htmlspecialchars($msg) ?></span>
</div>
<?php endif; ?>

<section class="service-time-bulk" id="bulk-service-times" aria-labelledby="bulk-service-times-title">
  <h2 id="bulk-service-times-title">Bulk update processing time</h2>
  <p class="service-time-help" id="bulk-service-times-help">Select services from one department and review their new processing time before applying.</p>
  <?php if ($bulkTimeError !== '' || !$conn): ?>
  <p class="service-time-alert" role="alert"><?= $escape($bulkTimeError ?: 'The database is unavailable. Please try again.') ?></p>
  <?php endif; ?>
  <form id="bulkServiceTimesForm" method="post" action="services.php#bulk-service-times" data-no-transition="true" data-limit="<?= SERVICE_TIME_BATCH_LIMIT ?>" data-unavailable="<?= !$conn || !$bulkTimeDepartments ? 'true' : 'false' ?>" aria-describedby="bulk-service-times-help">
    <input type="hidden" name="action" value="preview_times">
    <input type="hidden" name="csrf_token" value="<?= $escape($serviceTimeCsrf) ?>">
    <div class="service-time-fields">
      <label for="bulkTimeDepartment">Department
        <select id="bulkTimeDepartment" name="department_id" required>
          <option value="">Select department</option>
          <?php foreach ($bulkTimeDepartments as $department): ?>
          <option value="<?= (int)$department['id'] ?>" <?= (int)$department['id'] === $bulkTimeDepartment ? 'selected' : '' ?>><?= $escape($department['dept_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label for="bulkTimeMinutes">New processing time (min)
        <input id="bulkTimeMinutes" name="minutes" type="number" min="1" max="120" step="1" inputmode="numeric" placeholder="1–120 minutes" value="<?= $escape($bulkTimeMinutes) ?>" required>
      </label>
    </div>
    <fieldset class="service-time-picker">
      <legend class="sr-only">Select services to update</legend>
      <div class="service-time-picker-head">
        <label><input type="checkbox" id="bulkTimeSelectAll" <?= !$bulkTimeDepartment ? 'disabled' : '' ?>> Select all</label>
        <span id="bulkTimeSelectedCount" role="status"><?= $bulkTimeCheckedCount ?> selected</span>
      </div>
      <div class="service-time-options" id="bulkTimeServiceOptions">
        <p id="bulkTimeEmpty" <?= isset($serviceGroups[$bulkTimeDepartment]) ? 'hidden' : '' ?>><?= $bulkTimeDepartments ? 'Choose a department to select services.' : 'No services are available to update.' ?></p>
        <?php foreach ($serviceList as $service): ?>
        <?php $inTimeDepartment = (int)$service['dept_id'] === $bulkTimeDepartment; ?>
        <label class="service-time-option" data-department="<?= (int)$service['dept_id'] ?>" <?= !$inTimeDepartment ? 'hidden' : '' ?>>
          <input type="checkbox" name="service_ids[]" value="<?= (int)$service['id'] ?>" <?= !$inTimeDepartment ? 'disabled' : '' ?> <?= $inTimeDepartment && in_array((int)$service['id'], $bulkTimeSelected, true) ? 'checked' : '' ?>>
          <span><?= $escape($service['service_name']) ?></span>
          <small><?= (int)$service['estimated_time_mins'] ?> min</small>
        </label>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <div class="service-time-form-actions">
      <button class="directory-button directory-button--primary" id="bulkTimePreviewButton" type="submit" <?= !$conn || !$bulkTimeCheckedCount ? 'disabled' : '' ?>>Preview changes</button>
    </div>
  </form>
  <?php if ($bulkTimePreview): ?>
  <div class="service-time-preview" id="bulk-time-preview" aria-labelledby="bulk-time-preview-title">
    <h3 id="bulk-time-preview-title">Review changes</h3>
    <p class="service-time-help"><?= $escape($bulkTimePreview['department_name']) ?> &middot; <?= count($bulkTimePreview['rows']) ?> selected &middot; <?= $bulkTimePreview['changed'] ?> will change</p>
    <div class="service-time-preview-scroll" tabindex="0" role="region" aria-label="Processing time changes">
      <table>
        <thead><tr><th scope="col">Service</th><th scope="col">Current time</th><th scope="col">New time</th></tr></thead>
        <tbody>
          <?php foreach ($bulkTimePreview['rows'] as $row): ?>
          <tr>
            <td><?= $escape($row['service_name']) ?></td>
            <td><?= $row['current'] ?> min</td>
            <td class="service-time-new"><?= $bulkTimePreview['minutes'] ?> min<?= $row['current'] === $bulkTimePreview['minutes'] ? ' (unchanged)' : '' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="service-time-preview-changed" id="bulkTimePreviewChanged" role="status" <?= !$isActionPost ? 'hidden' : '' ?>>Settings changed. Preview again before applying.</p>
    <form class="service-time-preview-actions" id="bulkTimeApplyForm" method="post" action="services.php#bulk-service-times" data-no-transition="true">
      <input type="hidden" name="csrf_token" value="<?= $escape($serviceTimeCsrf) ?>">
      <input type="hidden" name="preview_token" value="<?= $escape($bulkTimePreview['token']) ?>">
      <button class="directory-button directory-button--primary" id="bulkTimeApplyButton" type="submit" name="action" value="apply_times" <?= $isActionPost ? 'disabled' : '' ?>>Apply changes</button>
      <button class="directory-button" type="submit" name="action" value="cancel_times">Cancel</button>
    </form>
  </div>
  <?php endif; ?>
</section>

<!-- Toolbar & Search -->
<div class="bg-white p-4 sm:p-5 rounded-2xl border border-slate-200/80 shadow-sm mb-8 flex flex-col md:flex-row items-stretch md:items-center justify-between gap-4">
  <p class="text-sm text-slate-500">Open a department to browse its services.</p>
  <div class="relative flex-1 max-w-md md:ml-auto">
    <i data-feather="search" class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
    <input type="search" id="serviceSearchInput" oninput="filterServices()" aria-label="Search services or departments" placeholder="Search service name or department..." class="w-full pl-10 pr-4 py-2.5 text-sm bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-purple-500 focus:bg-white transition">
  </div>
</div>

<!-- Department dropdowns -->
<?php if (empty($serviceList)): ?>
<div class="py-16 text-center bg-white border border-[#ece9f4] rounded-2xl p-8">
  <div class="w-16 h-16 rounded-full bg-purple-50 text-purple-600 flex items-center justify-center mx-auto mb-4">
    <i data-feather="briefcase" class="w-7 h-7"></i>
  </div>
  <h3 class="font-extrabold text-lg text-slate-800">No services found</h3>
  <p class="text-xs text-slate-400 max-w-sm mx-auto mt-1">Services configured by administrators will appear here.</p>
</div>
<?php else: ?>
<div class="service-groups" id="servicesGrid">
  <?php foreach ($serviceGroups as $group): ?>
  <details class="service-group">
    <summary class="service-group-summary">
      <span class="service-group-icon"><i data-feather="layers" class="w-5 h-5" aria-hidden="true"></i></span>
      <span class="service-group-heading">
        <span class="service-group-name"><?= htmlspecialchars($group['name']) ?></span>
        <span class="service-group-code"><?= htmlspecialchars($group['code']) ?></span>
      </span>
      <span class="service-group-count" data-total="<?= count($group['services']) ?>"><?= count($group['services']) ?> <?= count($group['services']) === 1 ? 'service' : 'services' ?></span>
      <i data-feather="chevron-down" class="service-group-chevron" aria-hidden="true"></i>
    </summary>
    <div class="service-group-content">
  <?php foreach ($group['services'] as $s): ?>
  <article class="service-directory-card service-item" data-name="<?= $escape(strtolower($s['service_name'])) ?>" data-dept="<?= $escape(strtolower($group['name'] . ' ' . $group['code'])) ?>" aria-labelledby="service-title-<?= (int)$s['id'] ?>">
    <h3 class="service-directory-title" id="service-title-<?= (int)$s['id'] ?>"><?= $escape($s['service_name']) ?></h3>
    <div class="service-directory-footer">
      <div class="service-directory-duration">
        <span><i data-feather="clock" aria-hidden="true"></i> Estimated time</span>
        <strong><?= (int)$s['estimated_time_mins'] ?> <span>min</span></strong>
      </div>
      <form method="post" class="delete-service-form" data-service-name="<?= $escape($s['service_name']) ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="csrf_token" value="<?= $escape($serviceTimeCsrf) ?>">
        <input type="hidden" name="service_id" value="<?= (int)$s['id'] ?>">
        <button type="submit" class="directory-button service-directory-delete" aria-label="Delete <?= $escape($s['service_name']) ?>">
          <i data-feather="trash-2" class="w-3.5 h-3.5" aria-hidden="true"></i>
          <span>Delete</span>
        </button>
      </form>
    </div>
  </article>
  <?php endforeach; ?>
    </div>
  </details>
  <?php endforeach; ?>
</div>
<p id="serviceSearchStatus" role="status" class="text-sm text-slate-500 mt-4"></p>
<div id="servicePagination" class="directory-pagination"></div>
<?php endif; ?>

<div class="toast-container" id="toastBox"></div>

<script src="../assets/js/directory-pagination.js"></script>
<script src="../assets/js/service-bulk-times.js" defer></script>
<script>
  let servicePagination = null;

  function filterServices() {
    const q = document.getElementById('serviceSearchInput').value.toLowerCase().trim();
    let totalMatches = 0;
    const matchingGroups = [];
    document.querySelectorAll('.service-group').forEach(group => {
      // Restore the administrator's expanded groups when the search is cleared.
      if (q && !group.hasAttribute('data-open-before-search')) {
        group.dataset.openBeforeSearch = group.open ? 'true' : 'false';
      }
      let matches = 0;
      group.querySelectorAll('.service-item').forEach(item => {
        const name = item.getAttribute('data-name') || '';
        const dept = item.getAttribute('data-dept') || '';
        const visible = !q || name.includes(q) || dept.includes(q);
        item.classList.toggle('hidden', !visible);
        if (visible) matches += 1;
      });
      totalMatches += matches;
      group.classList.toggle('hidden', matches === 0);
      if (matches > 0) matchingGroups.push(group);
      if (q) {
        group.open = matches > 0;
      } else if (group.hasAttribute('data-open-before-search')) {
        group.open = group.dataset.openBeforeSearch === 'true';
        delete group.dataset.openBeforeSearch;
      }

      const count = group.querySelector('.service-group-count');
      const total = Number(count.dataset.total);
      count.textContent = q
        ? `${matches} of ${total} ${total === 1 ? 'service' : 'services'}`
        : `${total} ${total === 1 ? 'service' : 'services'}`;
    });
    const status = document.getElementById('serviceSearchStatus');
    if (status) {
      status.textContent = q
        ? (totalMatches > 0
          ? `${totalMatches} matching ${totalMatches === 1 ? 'service' : 'services'}.`
          : 'No services match your search. Try another service or department.')
        : '';
    }
    if (servicePagination) {
      servicePagination.setItems(matchingGroups, { reset: true });
    }
  }

  function showToast(msg) {
    const toastBox = document.getElementById('toastBox');
    const toast = document.createElement('div');
    toast.className = 'toast-item';
    toast.innerHTML = `<i data-feather="check" class="w-4 h-4 text-emerald-300"></i><span>${msg}</span>`;
    toastBox.appendChild(toast);
    if (typeof feather !== 'undefined') feather.replace();
    setTimeout(() => { toast.remove(); }, 3200);
  }

  document.addEventListener('DOMContentLoaded', () => {
    const paginationControls = document.getElementById('servicePagination');
    if (paginationControls) {
      servicePagination = new DirectoryPagination({
        items: Array.from(document.querySelectorAll('.service-group')),
        controls: paginationControls,
        pageSize: 5,
        pageSizes: [5, 10, 20],
        label: 'departments'
      });
      // Also apply a query restored by the browser's form history.
      filterServices();
    }
    if (typeof feather !== 'undefined') {
      feather.replace();
    }
    const queueMenu = document.getElementById('queueMenu');
    const queueSidebar = document.getElementById('queueSidebar');
    if (queueMenu && queueSidebar) {
      queueMenu.addEventListener('click', () => queueSidebar.classList.toggle('is-open'));
    }

    document.querySelectorAll('.delete-service-form').forEach((form) => {
      form.addEventListener('submit', (event) => {
        const serviceName = form.dataset.serviceName || 'this service';
        if (!window.confirm(`Permanently delete service ${serviceName}?`)) {
          event.preventDefault();
          return;
        }
        form.dataset.confirmed = 'true';
      });
    });

    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('created')) {
      showToast('Service added.');
    }
    if (urlParams.has('deleted')) {
      showToast('Service deleted.');
    }
  });
</script>

<?php require_once '../includes/footer.php'; ?>
</div></main></div></body></html>
