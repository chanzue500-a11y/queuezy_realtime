<?php 
require_once '../database/db.php';
$conn = $conn ?? null;
require_once '../includes/profile_photo.php';
require_once '../includes/user_presence.php';
require_once __DIR__ . '/../includes/user_accounts.php';
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
$isAdmin = ($_SESSION['user_role'] ?? '') === 'admin';
if (!$isAdmin) {
  header('Location: ../login.php');
  exit;
}

$usersCsrfToken = $_SESSION['users_csrf'] ??= bin2hex(random_bytes(32));
$initialFilter = in_array($_GET['view'] ?? '', ['all', 'admin', 'staff', 'customer'], true)
    ? $_GET['view'] : 'all';

// Handle server-side account actions.
$msg = '';
$msgType = 'success';

$validActionToken = is_string($_POST['csrf_token'] ?? null) && hash_equals($usersCsrfToken, $_POST['csrf_token']);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && !$validActionToken) {
    $msg = 'Your session has expired. Refresh this page and try again.';
    $msgType = 'error';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $validActionToken) {
    if ($_POST['action'] === 'create' && $conn && $isAdmin) {
        $fullname = trim($_POST['fullname'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = strtolower(trim($_POST['role'] ?? 'customer'));

        if ($fullname === '') {
            $msg = 'Full name is required.';
            $msgType = 'error';
        } elseif (!preg_match('/^[A-Za-z ]{1,35}$/', $fullname)) {
          $msg = 'Full name must contain letters and spaces only, with a maximum of 35 characters.';
            $msgType = 'error';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = 'Please enter a valid email address.';
            $msgType = 'error';
        } elseif (strlen($password) < 8 || strlen($password) > 15) {
          $msg = 'Password must be 8 to 15 characters long and include an uppercase letter and a symbol.';
          $msgType = 'error';
        } elseif (!preg_match('/[A-Z]/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
          $msg = 'Password must include at least one uppercase letter and one symbol.';
            $msgType = 'error';
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (fullname, email, password, role) VALUES (?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("ssss", $fullname, $email, $hashed, $role);
                if ($stmt->execute()) {
                    header("Location: users.php?created=1");
                    exit;
                } else {
                    $msg = "Error: Email might already be registered.";
                    $msgType = "error";
                }
                $stmt->close();
            }
        }
    }

    if ($_POST['action'] === 'update_role' && $conn && $isAdmin) {
        $userId = (int)($_POST['user_id'] ?? 0);
        $newRole = strtolower(trim($_POST['role'] ?? 'customer'));
        if ($userId > 0 && in_array($newRole, ['customer', 'staff', 'admin'], true)) {
            $stmt = $conn->prepare("UPDATE users SET role = ? WHERE id = ? AND role IN ('customer', 'staff', 'user')");
            if ($stmt) {
                $stmt->bind_param("si", $newRole, $userId);
                $saved = $stmt->execute();
                $changed = $stmt->affected_rows;
                $stmt->close();
                if ($saved && $changed > 0) {
                    header("Location: users.php?role_updated=1");
                    exit;
                }
                $msg = 'No role change was made. Admin accounts are protected.';
                $msgType = 'error';
            }
        }
    }

    if ($_POST['action'] === 'delete' && $conn && $isAdmin) {
        $userId = (int)($_POST['user_id'] ?? 0);
        $result = deleteDirectoryUser($conn, $userId);
        if ($result['ok']) {
            header('Location: users.php?deleted=1');
            exit;
        }
        $msg = $result['message'];
        $msgType = 'error';
    }
}

// Fetch users from database
$userList = [];
if ($conn) {
    $presenceAvailable = ensureUserPresenceColumns($conn);
    recordUserActivity($conn, (int)($_SESSION['user_id'] ?? 0));
    $presenceColumns = $presenceAvailable ? 'u.last_seen_at' : 'NULL AS last_seen_at';
    $result = $conn->query("SELECT u.id, u.fullname, u.email, u.role, u.created_at, $presenceColumns FROM users u ORDER BY u.id ASC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $userList[] = [
                'id' => (int)$row['id'],
                'name' => $row['fullname'] ?? 'User',
                'email' => $row['email'] ?? '',
                'role' => strtolower($row['role'] ?? 'customer'),
                'created_at' => $row['created_at'] ?? 'Just now',
                'presence' => getUserPresence($row['last_seen_at'] ?? null),
                'photo_url' => ($photoFilename = getUserProfilePhotoFilename((int)$row['id']))
                  ? '../uploads/profile/' . rawurlencode($photoFilename)
                  : null,
            ];
        }
    }
}

$activeUsers = $userList;
$adminCount = count(array_filter($activeUsers, fn($u) => in_array($u['role'], ['admin', 'superadmin'], true)));
$staffCount = count(array_filter($activeUsers, fn($u) => $u['role'] === 'staff'));
$customerCount = count(array_filter($activeUsers, fn($u) => in_array($u['role'], ['customer', 'user'], true)));
$directoryCount = count($activeUsers);
$totalCount = count($userList);

$pageTitle = 'Users Management | Queuezy Admin'; 
$pageHeading = 'Users Directory'; 
require_once '../includes/header.php'; 
?>
<link rel="stylesheet" href="../assets/css/admin-directory.css">

<!-- Embedded Custom CSS for Senior-Grade UI/UX & Micro-interactions -->
<style>
  .stat-card-luxury {
    position: relative;
    overflow: hidden;
    background: #ffffff;
    border: 1px solid #ece9f4;
    border-radius: 20px;
    padding: 24px;
    transition: background-color 0.2s ease, border-color 0.2s ease;
    box-shadow: 0 4px 20px -2px rgba(57, 35, 97, 0.04);
  }
  .stat-card-luxury:hover {
    background: #faf5ff;
    border-color: #d8b4fe;
  }
  body.dark-mode .queue-content .users-metrics .stat-card-luxury:hover {
    background: #2a2240 !important;
    border-color: #8b5cf6 !important;
  }

  .role-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 700;
    padding: 4px 12px;
    border-radius: 9999px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  .role-badge.admin { background: #fdf2f8; color: #db2777; border: 1px solid #fbcfe8; }
  .role-badge.staff { background: #f3e8ff; color: #7c3aed; border: 1px solid #e9d5ff; }
  .role-badge.customer { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
  body.dark-mode .users-metrics .bg-purple-50 { background: #2e1b52 !important; }
  body.dark-mode .users-metrics .bg-indigo-50 { background: #1e1b4b !important; }
  body.dark-mode .users-metrics .bg-emerald-50 { background: #052e2b !important; }
  body.dark-mode .users-metrics .bg-pink-50 { background: #4a1735 !important; }
  body.dark-mode .role-badge.admin,
  body.dark-mode .user-avatar.admin { background: #4a1735; color: #f9a8d4; border-color: #9d174d; }
  body.dark-mode .role-badge.staff,
  body.dark-mode .user-avatar.staff { background: #2e1b52; color: #d8b4fe; border-color: #6d28d9; }
  body.dark-mode .role-badge.customer,
  body.dark-mode .user-avatar.customer { background: #052e2b; color: #6ee7b7; border-color: #047857; }
  body.dark-mode .users-controls,
  body.dark-mode .users-table-card { background: #1e293b !important; border-color: #334155 !important; }
  body.dark-mode .users-controls input,
  body.dark-mode .users-table-card select { background: #0f172a !important; border-color: #475569 !important; color: #f8fafc !important; }
  body.dark-mode .users-table-card .bg-emerald-50 { background: #052e2b !important; color: #6ee7b7 !important; border-color: #047857 !important; }
  body.dark-mode .users-table-card .bg-red-50 { background: #451a1a !important; color: #fca5a5 !important; border-color: #991b1b !important; }

  .user-presence-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 2px 10px;
    border: 1px solid;
    border-radius: 9999px;
    font-size: 10px;
    font-weight: 700;
    white-space: nowrap;
  }
  .user-presence-badge.is-active { background: #ecfdf5; color: #047857; border-color: #a7f3d0; }
  .user-presence-badge.is-offline { background: #f1f5f9; color: #64748b; border-color: #cbd5e1; }
  .user-presence-dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
  .user-last-seen { display: block; margin-top: 5px; font-size: 10px; color: #64748b; white-space: nowrap; }
  body.dark-mode .user-presence-badge.is-active { background: #052e2b; color: #6ee7b7; border-color: #047857; }
  body.dark-mode .user-presence-badge.is-offline { background: #334155; color: #cbd5e1; border-color: #475569; }
  body.dark-mode .user-last-seen { color: #94a3b8; }

  .user-avatar {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 16px;
    flex-shrink: 0;
  }
  .user-avatar.admin { background: #fdf2f8; color: #db2777; }
  .user-avatar.staff { background: #f3e8ff; color: #7c3aed; }
  .user-avatar.customer { background: #ecfdf5; color: #059669; }
  img.user-avatar--photo { object-fit: cover; border: 1px solid rgba(148, 163, 184, 0.3); }

  #usersTable thead th {
    white-space: nowrap;
    font-size: 11px;
    letter-spacing: 0.08em;
  }
  html.dark-mode #usersTable thead,
  body.dark-mode #usersTable thead { background: #172033; }
  html.dark-mode #usersTable thead tr,
  body.dark-mode #usersTable thead tr { background: #172033 !important; border-color: #475569 !important; }
  html.dark-mode #usersTable thead th,
  body.dark-mode #usersTable thead th { color: #f8fafc !important; background: #172033 !important; }
  html.dark-mode #usersTable tbody td,
  body.dark-mode #usersTable tbody td { color: #f8fafc !important; }
  html.dark-mode #usersTable tbody .text-slate-400,
  html.dark-mode #usersTable tbody .text-slate-500,
  html.dark-mode #usersTable tbody .text-slate-600,
  body.dark-mode #usersTable tbody .text-slate-400,
  body.dark-mode #usersTable tbody .text-slate-500,
  body.dark-mode #usersTable tbody .text-slate-600 { color: #cbd5e1 !important; }

  .hidden { display: none !important; }
  #usersTable .user-row:hover,
  #usersTable .user-row:focus-within { background: #faf5ff; }
  body.dark-mode #usersTable .user-row:hover,
  body.dark-mode #usersTable .user-row:focus-within { background: #2a2240 !important; }
  .users-view-label { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; font-size: 12px; font-weight: 700; }
  .users-table-heading { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 20px 22px; border-bottom: 1px solid #334155; }
  .users-table-heading h2 { margin: 0; color: #f8fafc; font-size: 16px; font-weight: 800; }
  .users-table-heading p { margin: 5px 0 0; color: #94a3b8; font-size: 12px; line-height: 1.5; }
  .users-protected { display: inline-flex; align-items: center; justify-content: flex-end; gap: 7px; color: #94a3b8; font-size: 12px; font-weight: 600; white-space: nowrap; }
  .users-protected svg, .users-protected i { width: 14px; height: 14px; }
  #usersTable { min-width: 900px; }
  #usersTable thead th { padding: 13px 18px; letter-spacing: 0.03em; }
  #usersTable tbody td { padding: 18px; vertical-align: middle; }
  #usersTable th:first-child { width: 25%; }
  #usersTable td[data-user-field="email"] { font-family: inherit; font-size: 12px; }
  #usersTable td[data-user-field="created"] { white-space: nowrap; color: #cbd5e1; }
  #usersTable .role-badge { min-height: 34px; border-radius: 8px; text-transform: none; letter-spacing: 0; }
  #usersTable .directory-select { min-height: 36px; min-width: 112px; font-size: 12px; }
  #usersTable .user-delete-button { display: inline-flex; align-items: center; gap: 7px; min-height: 36px; padding: 8px 12px; border: 1px solid #8c4444; border-radius: 8px; background: rgba(127, 29, 29, 0.15); color: #fca5a5; font-size: 12px; font-weight: 600; cursor: pointer; transition: background 0.2s ease, border-color 0.2s ease; }
  #usersTable .user-delete-button:hover { background: #7f1d1d; border-color: #f87171; color: #fff; }
  #usersTable .user-delete-button:focus-visible { outline: 2px solid #fca5a5; outline-offset: 3px; }
  #usersTable .user-delete-button svg { width: 14px; height: 14px; }
  .users-row-meta { display: block; margin-top: 4px; color: #94a3b8; font-size: 11px; }
  @media (max-width: 640px) { .users-table-heading { padding: 16px; } }
  @media (prefers-reduced-motion: reduce) {
    .stat-card-luxury,
    #usersTable .user-row { transition: none; }
  }

  /* Fallback table & modal rules */
  table { width: 100%; border-collapse: collapse; }
  #addUserModal.hidden, #emptyState.hidden { display: none !important; }

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
    <div>
     
      <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">Users Directory</h1>
      
    </div>

    <!-- Top Action Buttons -->
    <div class="flex items-center gap-3">
      <button type="button" onclick="exportUsersTable()" class="directory-button">
        <i class="fa-solid fa-print w-4 h-4 text-slate-500" aria-hidden="true"></i>
        <span>Export View</span>
      </button>

      <?php if ($isAdmin): ?>
      <button type="button" onclick="openAddUserModal()" class="directory-button directory-button--primary">
        <i data-feather="user-plus" class="w-4 h-4"></i>
        <span>Add New User</span>
      </button>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($msg): ?>
<div class="mb-6 p-4 rounded-2xl border <?= $msgType === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-emerald-50 border-emerald-200 text-emerald-700' ?> font-semibold text-xs flex items-center gap-2">
  <i data-feather="<?= $msgType === 'error' ? 'alert-circle' : 'check-circle' ?>" class="w-4 h-4"></i>
  <span><?= htmlspecialchars($msg) ?></span>
</div>
<?php endif; ?>


<!-- Executive Metric Cards -->
<div class="users-metrics grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5 mb-8">
  <div class="stat-card-luxury">
    <div class="flex items-center justify-between">
      <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Total Users</span>
      <div class="w-10 h-10 rounded-2xl bg-purple-50 text-purple-600 flex items-center justify-center">
        <i class="fa-solid fa-users" style="font-size: 20px;" aria-hidden="true"></i>
      </div>
    </div>
    <div class="mt-3">
      <h3 class="text-3xl font-black text-slate-900"><?= $totalCount ?></h3>
      <p class="text-xs text-slate-500 mt-2 flex items-center gap-1">
        <i data-feather="check-circle" class="w-3.5 h-3.5 text-emerald-500"></i>
        <span>Registered accounts</span>
      </p>
    </div>
  </div>

  <div class="stat-card-luxury">
    <div class="flex items-center justify-between">
      <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Staff Officers</span>
      <div class="w-10 h-10 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
        <i class="fa-solid fa-award" style="font-size: 20px;" aria-hidden="true"></i>
      </div>
    </div>
    <div class="mt-3">
      <h3 class="text-3xl font-black text-indigo-600"><?= $staffCount ?></h3>
      <p class="text-xs text-slate-500 mt-2 flex items-center gap-1">
        <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
        <span>Desk call privileges</span>
      </p>
    </div>
  </div>

  <div class="stat-card-luxury">
    <div class="flex items-center justify-between">
      <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Customers</span>
      <div class="w-10 h-10 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
        <i class="fa-solid fa-mobile-screen-button" style="font-size: 20px;" aria-hidden="true"></i>
      </div>
    </div>
    <div class="mt-3">
      <h3 class="text-3xl font-black text-slate-900"><?= $customerCount ?></h3>
      <p class="text-xs text-emerald-600 font-semibold mt-2">Mobile queue users</p>
    </div>
  </div>

  <div class="stat-card-luxury">
    <div class="flex items-center justify-between">
      <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">System Admins</span>
      <div class="w-10 h-10 rounded-2xl bg-pink-50 text-pink-600 flex items-center justify-center">
        <i class="fa-solid fa-shield-halved" style="font-size: 20px;" aria-hidden="true"></i>
      </div>
    </div>
    <div class="mt-3">
      <h3 class="text-3xl font-black text-pink-600"><?= $adminCount ?></h3>
      <p class="text-xs text-slate-500 mt-2">Full system access</p>
    </div>
  </div>
</div>

<!-- Directory view and search -->
<div class="users-controls bg-white border border-[#ece9f4] rounded-2xl p-4 mb-8 shadow-sm flex flex-col lg:flex-row lg:items-center justify-between gap-4">
  <label class="users-view-label" for="userViewFilter">View
    <select id="userViewFilter" class="directory-select">
      <option value="all" <?= $initialFilter === 'all' ? 'selected' : '' ?>>All Users (<?= $directoryCount ?>)</option>
      <option value="customer" <?= $initialFilter === 'customer' ? 'selected' : '' ?>>Customers (<?= $customerCount ?>)</option>
      <option value="staff" <?= $initialFilter === 'staff' ? 'selected' : '' ?>>Staff Officers (<?= $staffCount ?>)</option>
      <option value="admin" <?= $initialFilter === 'admin' ? 'selected' : '' ?>>Admins (<?= $adminCount ?>)</option>
    </select>
  </label>

  <!-- Search Input -->
  <div class="relative w-full sm:w-72">
    <i data-feather="search" class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none"></i>
    <input type="search" id="userSearch" aria-label="Search users by name, email or role" placeholder="Search by name, email or role..." class="w-full bg-white border border-[#ece9f4] rounded-xl pl-10 pr-4 py-2 text-xs font-medium outline-none focus:border-purple-600 focus:ring-2 focus:ring-purple-100 transition">
  </div>
</div>


<!-- Users Table Card -->
<div class="users-table-card bg-white border border-[#ece9f4] rounded-2xl shadow-sm overflow-hidden">
  <div class="users-table-heading"><div><h2>User accounts</h2><p>Manage staff and customers. Admin accounts are protected.</p></div></div>
  <div class="overflow-x-auto">
    <table class="w-full text-left border-collapse text-xs" id="usersTable">
      <thead>
        <tr class="bg-slate-50/80 border-b border-slate-100 text-slate-400 font-bold uppercase tracking-wider text-[11px]">
          <th scope="col">User</th>
          <th scope="col">Role</th>
          <th scope="col">Email</th>
          <th scope="col">Joined</th>
          <th scope="col">Status</th>
          <th scope="col" class="text-right">Actions</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100 font-medium text-slate-700" id="usersTableBody">
        <?php foreach ($userList as $u): 
          $roleClass = in_array($u['role'], ['admin', 'superadmin'], true) ? 'admin' : ($u['role'] === 'staff' ? 'staff' : 'customer');
          $isCustomer = in_array($u['role'], ['customer', 'user'], true);
          $isProtected = in_array($u['role'], ['admin', 'superadmin'], true);
          $canDelete = in_array($u['role'], ['staff', 'customer'], true);
        ?>
        <tr class="user-row transition" data-user-id="<?= $u['id'] ?>" data-name="<?= htmlspecialchars($u['name'], ENT_QUOTES) ?>" data-email="<?= htmlspecialchars($u['email'], ENT_QUOTES) ?>" data-role="<?= $roleClass ?>">
          <td class="py-4 px-6" data-user-field="name">
            <div class="flex items-center gap-3">
              <?php if (!empty($u['photo_url'])): ?>
              <img class="user-avatar user-avatar--photo" src="<?= htmlspecialchars($u['photo_url'], ENT_QUOTES) ?>" alt="<?= htmlspecialchars($u['name']) ?> profile photo" loading="lazy">
              <?php else: ?>
              <span class="user-avatar <?= $roleClass ?>" aria-hidden="true"><i class="fa-solid fa-user"></i></span>
              <?php endif; ?>
              <div>
                <div class="flex items-center gap-2">
                  <strong class="text-slate-900 font-bold text-sm"><?= htmlspecialchars($u['name']) ?></strong>
                </div>
                <span class="users-row-meta">User #<?= (int)$u['id'] ?><?= (int)$u['id'] === (int)$_SESSION['user_id'] ? ' · You' : '' ?></span>
              </div>
            </div>
          </td>
          <td class="py-4 px-4" data-user-field="role">
            <?php if ($isProtected): ?>
            <span class="role-badge admin"><i data-feather="shield" style="width: 13px; height: 13px;" aria-hidden="true"></i> Admin</span>
            <?php elseif ($isAdmin): ?>
            <form method="post" class="inline-flex items-center gap-1.5">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($usersCsrfToken, ENT_QUOTES) ?>">
              <input type="hidden" name="action" value="update_role">
              <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
              <select name="role" onchange="this.form.submit()" aria-label="Role for <?= htmlspecialchars($u['name'], ENT_QUOTES) ?>" class="directory-select">
                <option value="customer" <?= $isCustomer ? 'selected' : '' ?>>Customer</option>
                <option value="staff" <?= $u['role'] === 'staff' ? 'selected' : '' ?>>Staff</option>
                <option value="admin" <?= in_array($u['role'], ['admin', 'superadmin'], true) ? 'selected' : '' ?>>Admin</option>
              </select>
            </form>
            <?php else: ?>
            <span class="role-badge customer">Customer</span>
            <?php endif; ?>
          </td>
          <td class="py-4 px-4 text-slate-600 font-medium font-mono text-[11px]" data-user-field="email">
            <?= htmlspecialchars($u['email']) ?>
          </td>
          <td class="py-4 px-4 text-slate-500" data-user-field="created">
            <?= htmlspecialchars($u['created_at'] !== 'Just now' ? date('M d, Y', strtotime($u['created_at'])) : 'Just now') ?>
          </td>
          <td class="py-4 px-4" data-user-field="status">
            <span class="user-presence-badge <?= $u['presence']['is_active'] ? 'is-active' : 'is-offline' ?>">
              <span class="user-presence-dot" aria-hidden="true"></span>
              <span data-presence-status><?= htmlspecialchars($u['presence']['status']) ?></span>
            </span>
            <span class="user-last-seen <?= $u['presence']['is_active'] ? 'hidden' : '' ?>" data-last-seen><?= htmlspecialchars($u['presence']['last_seen_label']) ?></span>
          </td>
          <td class="py-4 px-6 text-right">
            <?php if ($isAdmin && $canDelete): ?>
            <form method="post" class="inline delete-user-form" data-user-name="<?= htmlspecialchars($u['name'], ENT_QUOTES) ?>">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($usersCsrfToken, ENT_QUOTES) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
              <button type="submit" class="user-delete-button" aria-label="Delete <?= htmlspecialchars($u['name'], ENT_QUOTES) ?>">
                <i data-feather="trash-2" class="w-3.5 h-3.5"></i>
                <span>Delete</span>
              </button>
            </form>
            <?php else: ?>
            <span class="users-protected"><i data-feather="shield" aria-hidden="true"></i> Protected</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<div id="usersPagination" class="directory-pagination"></div>

<!-- Empty State Filter -->
<div id="emptyState" class="hidden py-16 text-center bg-white border border-[#ece9f4] rounded-2xl p-8 mt-6">
  <div class="w-16 h-16 rounded-full bg-purple-50 text-purple-600 flex items-center justify-center mx-auto mb-4">
    <i data-feather="users" class="w-7 h-7"></i>
  </div>
  <h3 class="font-extrabold text-lg text-slate-800">No matching users found</h3>
  <p class="text-xs text-slate-400 max-w-sm mx-auto mt-1">Try another search or directory view, or add a new user.</p>
</div>

<!-- Add User Modal -->
<div id="addUserModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4">
  <div class="bg-white rounded-3xl w-full max-w-md overflow-hidden shadow-2xl p-6">
    <div class="flex items-center justify-between pb-4 border-b border-slate-100">
      <div>
        <h3 class="font-black text-lg text-slate-900">Add New User</h3>
        <p class="text-xs text-slate-500">Create a new user account.</p>
      </div>
      <button type="button" onclick="closeAddUserModal()" class="directory-button" aria-label="Close add user dialog">
        <i data-feather="x" class="w-4 h-4"></i>
      </button>
    </div>

    <form method="post" class="space-y-4 mt-4">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($usersCsrfToken, ENT_QUOTES) ?>">
      <input type="hidden" name="action" value="create">

      <div>
        <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Full Name</label>
        <input type="text" name="fullname" required maxlength="35" pattern="[A-Za-z ]{1,35}" title="Use letters and spaces only, maximum 35 characters" placeholder="e.g. John Santos" class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-xs outline-none focus:border-purple-600 focus:ring-2 focus:ring-purple-100">
      </div>

      <div>
        <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Email Address</label>
        <input type="email" name="email" required placeholder="john@example.com" class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-xs outline-none focus:border-purple-600 focus:ring-2 focus:ring-purple-100">
      </div>

      <div>
        <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Account Role</label>
        <select name="role" required class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-xs font-semibold outline-none focus:border-purple-600 bg-white">
          <option value="customer">Customer</option>
          <option value="staff">Staff Desk Officer</option>
          <option value="admin">System Administrator</option>
        </select>
      </div>

      <div>
        <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Password</label>
        <input type="password" name="password" required minlength="8" maxlength="15" pattern="(?=.*[A-Z])(?=.*[^A-Za-z0-9]).{8,15}" title="Use 8 to 15 characters, including an uppercase letter and a symbol." placeholder="8 to 15 characters" class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-xs outline-none focus:border-purple-600 focus:ring-2 focus:ring-purple-100">
      </div>

      <div class="pt-3 flex justify-end gap-2 border-t border-slate-100">
        <button type="button" onclick="closeAddUserModal()" class="directory-button">Cancel</button>
        <button type="submit" class="directory-button directory-button--primary">Create User</button>
      </div>
    </form>
  </div>
</div>

<!-- Global Toast Container -->
<div class="toast-container" id="toastBox"></div>

<script src="../assets/js/directory-pagination.js"></script>
<script>
  const userViewFilter = document.getElementById('userViewFilter');
  const userRows = Array.from(document.querySelectorAll('#usersTableBody .user-row'));
  const userSearch = document.getElementById('userSearch');
  const emptyState = document.getElementById('emptyState');
  let filteredUserRows = [];

  const userPagination = new DirectoryPagination({
    items: userRows,
    controls: document.getElementById('usersPagination'),
    pageSize: 10,
    pageSizes: [10, 25, 50],
    label: 'users'
  });

  document.querySelectorAll('.delete-user-form').forEach(form => {
    form.addEventListener('submit', event => {
      if (!window.confirm(`Permanently delete user ${form.dataset.userName || 'this user'}?`)) event.preventDefault();
    });
  });

  let refreshingUserStatuses = false;
  async function refreshUserStatuses() {
    if (refreshingUserStatuses || document.hidden) return;
    refreshingUserStatuses = true;
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 10000);
    try {
      const response = await fetch('../api/user_status.php', { cache: 'no-store', signal: controller.signal });
      if (!response.ok) return;
      const data = await response.json();
      if (!data.ok || !Array.isArray(data.users)) return;
      const statuses = new Map(data.users.map(user => [String(user.id), user]));
      userRows.forEach(row => {
        const presence = statuses.get(row.dataset.userId);
        if (!presence) return;
        const badge = row.querySelector('.user-presence-badge');
        const lastSeen = row.querySelector('[data-last-seen]');
        badge.classList.toggle('is-active', presence.is_active);
        badge.classList.toggle('is-offline', !presence.is_active);
        row.querySelector('[data-presence-status]').textContent = presence.status;
        lastSeen.textContent = presence.last_seen_label;
        lastSeen.classList.toggle('hidden', presence.is_active);
      });
    } catch (error) {
      // Keep the last known status until the next successful refresh.
    } finally {
      window.clearTimeout(timeout);
      refreshingUserStatuses = false;
    }
  }

  refreshUserStatuses();
  window.setInterval(refreshUserStatuses, 5000);
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) refreshUserStatuses();
  });

  userViewFilter.addEventListener('change', filterUsers);
  userSearch.addEventListener('input', filterUsers);

  function filterUsers() {
    const view = userViewFilter.value;
    const term = userSearch.value.trim().toLowerCase();
    filteredUserRows = userRows.filter(row => {
      const role = row.dataset.role;
      const matchesView = view === 'all' || role === view;
      const matchesSearch = !term || `${row.dataset.name} ${row.dataset.email} ${role}`.toLowerCase().includes(term);
      const matches = matchesView && matchesSearch;
      row.classList.toggle('hidden', !matches);
      return matches;
    });
    userPagination.setItems(filteredUserRows, { reset: true });
    emptyState.classList.toggle('hidden', filteredUserRows.length > 0);
  }
  filterUsers();

  function openAddUserModal() {
    document.getElementById('addUserModal').classList.remove('hidden');
  }

  function closeAddUserModal() {
    document.getElementById('addUserModal').classList.add('hidden');
  }

  function exportUsersTable() {
    const rows = filteredUserRows;
    const printWindow = window.open('', '_blank', 'width=980,height=760');

    if (!printWindow) {
      alert('Please allow pop-ups to export the user table.');
      return;
    }

    const escapeHtml = value => value.replace(/[&<>"']/g, character => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[character]));

    const tableRows = rows.length ? rows.map(row => {
      const field = name => row.querySelector(`[data-user-field="${name}"]`);
      const name = field('name')?.querySelector('strong')?.textContent.trim() || '';
      const role = field('role')?.querySelector('select')?.selectedOptions[0]?.textContent.trim() || field('role')?.textContent.trim() || '';
      const email = field('email')?.textContent.trim() || '';
      const created = field('created')?.textContent.trim() || '';
      const statusLabel = field('status')?.querySelector('[data-presence-status]')?.textContent.trim() || '';
      const lastSeen = field('status')?.querySelector('[data-last-seen]:not(.hidden)')?.textContent.trim() || '';
      const status = [statusLabel, lastSeen].filter(Boolean).join(' - ');

      return `
        <tr>
          <td>${escapeHtml(name)}</td>
          <td>${escapeHtml(role)}</td>
          <td>${escapeHtml(email)}</td>
          <td>${escapeHtml(created)}</td>
          <td>${escapeHtml(status)}</td>
        </tr>
      `;
    }).join('') : `
      <tr>
        <td colspan="5" style="padding: 18px; text-align: center; color: #64748b;">No users available for export.</td>
      </tr>
    `;

    const html = `
      <!doctype html>
      <html>
        <head>
          <title>Users Directory Export</title>
          <style>
            body { font-family: Arial, sans-serif; margin: 32px; color: #0f172a; background: #ffffff; }
            h1 { font-size: 28px; margin: 0 0 8px; }
            .meta { font-size: 12px; color: #475569; margin-bottom: 20px; }
            table { width: 100%; border-collapse: collapse; margin-top: 16px; }
            th, td { border: 1px solid #e2e8f0; padding: 10px 12px; text-align: left; font-size: 12px; }
            th { background: #f8fafc; color: #334155; text-transform: uppercase; letter-spacing: 0.08em; }
            tr:nth-child(even) td { background: #fafafa; }
            @media print { body { margin: 0; } }
          </style>
        </head>
        <body>
          <h1>Users Directory</h1>
          <div class="meta">Exported on: ${new Date().toLocaleString()}</div>
          <table>
            <thead>
              <tr>
                <th>Name</th>
                <th>Role</th>
                <th>Email</th>
                <th>Created</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              ${tableRows}
            </tbody>
          </table>
        </body>
      </html>
    `;

    printWindow.document.open();
    printWindow.document.write(html);
    printWindow.document.close();
    printWindow.focus();
    setTimeout(() => printWindow.print(), 400);
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

  document.addEventListener('DOMContentLoaded', function() {
    if (typeof feather !== 'undefined') {
      feather.replace();
    }
    const queueMenu = document.getElementById('queueMenu');
    const queueSidebar = document.getElementById('queueSidebar');
    if (queueMenu && queueSidebar) {
      queueMenu.addEventListener('click', function() {
        queueSidebar.classList.toggle('is-open');
      });
    }

    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('created')) {
      showToast('User created successfully!');
    }
    if (urlParams.has('role_updated')) {
      showToast('User permission role updated successfully!');
    }
    if (urlParams.has('deleted')) {
      showToast('User deleted.');
    }
  });
</script>

<?php require_once '../includes/footer.php'; ?>
</div></main></div></body></html>
