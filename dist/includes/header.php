<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['admin', 'staff'], true)) {
    header('Location: ../login.php');
    exit;
}
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
require_once __DIR__ . '/user_presence.php';
recordUserActivity($conn ?? null, (int)$_SESSION['user_id']);
$currentPage = basename($_SERVER['PHP_SELF']);
$isAdmin = ($_SESSION['user_role'] ?? '') === 'admin';
$staffMonitorPath = $isAdmin ? '../staff/monitor.php' : 'monitor.php';
$staffNotificationsPath = $isAdmin ? 'notification.php' : 'notifications.php';
$staffProfilePath = 'profile.php';
require_once __DIR__ . '/profile_photo.php';
$headerProfilePhotoFilename = getUserProfilePhotoFilename((int)$_SESSION['user_id']);
$headerProfilePhotoUrl = $headerProfilePhotoFilename ? '../uploads/profile/' . rawurlencode($headerProfilePhotoFilename) : '';
require_once __DIR__ . '/workspace_notifications.php';
$workspaceNotifications = workspaceNotificationSnapshot($conn ?? null, $_SESSION['user_role']);
$adminWaitingCount = $workspaceNotifications['waiting'];
$adminNotificationKey = $workspaceNotifications['key'];
$hasAdminNotification = $workspaceNotifications['has_activity'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QUEUEZY</title>
    <link rel="icon" type="image/png" href="../assets/images/mascot.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../includes/style.css">
    <link rel="stylesheet" href="../assets/css/admin-queuezy.css">
    <style>
        .queue-layout { opacity: 1; transform: none; animation: none; }
        body.portal-leaving .queue-layout { opacity: 1; transform: none; transition: none; }
        .admin-notification-wrap { position: relative; }
        .admin-notification-panel { position: absolute; top: 52px; right: 0; z-index: 50; width: 280px; padding: 16px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 14px; box-shadow: 0 14px 30px rgba(15, 23, 42, 0.16); opacity: 0; visibility: hidden; pointer-events: none; transform: translateY(-6px); transition: opacity 180ms ease, transform 180ms ease, visibility 180ms ease; }
        .admin-notification-panel.is-visible { opacity: 1; visibility: visible; pointer-events: auto; transform: translateY(0); }
        .admin-notification-panel h3 { margin: 0; font-size: 14px; font-weight: 800; color: #0f172a; }
        .admin-notification-panel p { margin: 10px 0 0; font-size: 12px; line-height: 1.5; color: #64748b; }
        .admin-notification-panel strong { color: #0f172a; }
        .queue-content { animation: adminPageEnter 220ms ease both; }
        @keyframes adminPageEnter { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
        body.dark-mode .admin-notification-panel { background: #1e293b; border-color: #475569; }
        body.dark-mode .admin-notification-panel h3,
        body.dark-mode .admin-notification-panel strong { color: #f8fafc; }
        body.dark-mode .admin-notification-panel p { color: #cbd5e1; }
        @media (prefers-reduced-motion: reduce) { .admin-notification-panel { transition: none; } .queue-content { animation: none; } }
    </style>
    <script src="../assets/js/feather.min.js"></script>
    <script src="../assets/js/password-toggle.js"></script>
    <script>
        (() => {
            document.documentElement.classList.add('dark-mode');
            localStorage.setItem('queuezy-dark-mode', 'true');
        })();
    </script>
</head>
<body class="queue-app dark-mode">
<div class="queue-layout">
    <aside class="queue-sidebar" id="queueSidebar">
        <button class="queue-sidebar-toggle queue-sidebar-close" id="queueSidebarClose" type="button" aria-label="Close sidebar" aria-controls="queueSidebar"><span aria-hidden="true">&lt;</span></button>
        <div class="queue-brand-row">
            <div class="queue-brand" role="img" aria-label="Queuezy"><img class="queue-brand-logo queue-brand-logo--processing" id="queueBrandLogo" src="../assets/images/mascot.png" alt="" width="64" height="64" style="width: 64px !important; height: 64px !important; max-width: 64px; max-height: 64px;"><span class="queue-brand-name">QUEUE<span>ZY</span></span></div>
        </div>
        <script>
            (() => {
                const logo = document.getElementById('queueBrandLogo');
                const removeCheckerboard = () => {
                    if (!logo?.naturalWidth) return;
                    const canvas = document.createElement('canvas');
                    canvas.width = logo.naturalWidth;
                    canvas.height = logo.naturalHeight;
                    const context = canvas.getContext('2d');
                    context.drawImage(logo, 0, 0);
                    const image = context.getImageData(0, 0, canvas.width, canvas.height);
                    for (let index = 0; index < image.data.length; index += 4) {
                        const red = image.data[index];
                        const green = image.data[index + 1];
                        const blue = image.data[index + 2];
                        const brightness = (red + green + blue) / 3;
                        const saturation = Math.max(red, green, blue) - Math.min(red, green, blue);
                        if (saturation < 18 && brightness < 180) image.data[index + 3] = 0;
                    }
                    context.putImageData(image, 0, 0);
                    logo.src = canvas.toDataURL('image/png');
                    logo.classList.remove('queue-brand-logo--processing');
                };
                logo?.addEventListener('load', removeCheckerboard, { once: true });
                if (logo?.complete) removeCheckerboard();
            })();
        </script>
        <div class="queue-sidebar__label">Workspace</div>
        <nav class="queue-nav" aria-label="Main navigation">
            <?php if ($isAdmin): ?>
            <a class="<?= $currentPage === 'departments.php' ? 'is-active' : '' ?>" href="departments.php"><i data-feather="layers"></i><span>Departments</span></a>
            <a class="<?= $currentPage === 'services.php' ? 'is-active' : '' ?>" href="services.php"><i data-feather="briefcase"></i><span>Services</span></a>
            <?php endif; ?>
            <?php 
              $queueBadge = 0;
              if (isset($conn) && $conn) {
                  $qbRes = $conn->query("SELECT COUNT(*) AS cnt FROM queue WHERE status IN ('waiting','now_serving')");
                  if ($qbRes && $qbRow = $qbRes->fetch_assoc()) { $queueBadge = (int)$qbRow['cnt']; }
              }
            ?>
            <?php if (!$isAdmin): ?>
            <a class="<?= $currentPage === 'monitor.php' ? 'is-active' : '' ?>" href="<?= $staffMonitorPath ?>"><i data-feather="list"></i><span>Queues</span><?php if ($queueBadge > 0): ?><em><?= $queueBadge ?></em><?php endif; ?></a>
            <a class="<?= $currentPage === 'rating_view.php' ? 'is-active' : '' ?>" href="rating_view.php"><i data-feather="star"></i><span>Ratings</span></a>
            <?php endif; ?>
            <?php if ($isAdmin): ?>
            <a class="<?= $currentPage === 'users.php' ? 'is-active' : '' ?>" href="users.php"><i data-feather="users"></i><span>Users</span></a>
            <?php endif; ?>
            <a class="<?= in_array($currentPage, ['notifications.php', 'notification.php'], true) ? 'is-active' : '' ?>" href="<?= $staffNotificationsPath ?>" id="adminSidebarNotificationLink" data-notification-key="<?= htmlspecialchars($adminNotificationKey, ENT_QUOTES) ?>"><i data-feather="bell"></i><span>Notifications</span><?php if ($hasAdminNotification): ?><em>1</em><?php endif; ?></a>
            <a class="<?= $currentPage === 'profile.php' ? 'is-active' : '' ?>" href="<?= $staffProfilePath ?>"><i data-feather="settings"></i><span>Settings</span></a>
        </nav>
        <?php if ($isAdmin): ?>
        <div class="queue-sidebar__label queue-sidebar__label--lower">Manage</div>
        <nav class="queue-nav">
            <a class="<?= $currentPage === 'billing.php' ? 'is-active' : '' ?>" href="billing.php"><i data-feather="credit-card"></i><span>Billing</span></a>
            <a class="<?= $currentPage === 'ratings.php' ? 'is-active' : '' ?>" href="ratings.php"><i data-feather="star"></i><span>Ratings</span></a>
            <a class="<?= $currentPage === 'reports.php' ? 'is-active' : '' ?>" href="reports.php"><i data-feather="bar-chart-2"></i><span>Reports</span></a>
        </nav>
        <?php endif; ?>
        <div class="queue-sidebar__footer">
            <div class="profile-icon" aria-label="Profile">
                <?php if ($headerProfilePhotoUrl): ?><img src="<?= htmlspecialchars($headerProfilePhotoUrl, ENT_QUOTES) ?>" alt="">
                <?php else: ?><i data-feather="user"></i><?php endif; ?>
            </div>
            <div class="queue-sidebar__profile-details">
                <strong><?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?></strong>
                <small><?= htmlspecialchars(ucfirst($_SESSION['user_role'] ?? 'staff')) ?></small>
                <a href="../logout.php" class="logout-alert queue-sidebar__logout" aria-label="Log Out" title="Log Out" onclick="return confirm('Are you sure you want to log out?');"><i data-feather="log-out"></i><span>Log Out</span></a>
            </div>
        </div>
    </aside>
    <button class="queue-sidebar-backdrop" id="queueSidebarBackdrop" type="button" aria-label="Close sidebar" tabindex="-1" hidden></button>
    <script>if (typeof feather !== 'undefined') feather.replace();</script>
    <main class="queue-main">
        <header class="queue-topbar">
            <button class="queue-menu" id="queueSidebarOpen" type="button" aria-label="Open sidebar" aria-controls="queueSidebar" aria-expanded="false"><span aria-hidden="true">&gt;</span></button>
            <div class="queue-topbar__actions">
                <div class="admin-notification-wrap">
                    <button class="queue-icon-button" id="adminNotificationButton" type="button" aria-label="Notifications" aria-expanded="false"><i class="fa-solid fa-bell"></i><?php if ($hasAdminNotification): ?><b id="adminTopNotificationDot"></b><?php endif; ?></button>
                    <div class="admin-notification-panel" id="adminNotificationPanel" role="status" aria-live="polite">
                        <h3>Notifications</h3>
                        <p id="adminNotificationContent"><?= htmlspecialchars($workspaceNotifications['message'], ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </div>
                <a class="queue-user" href="<?= htmlspecialchars($staffProfilePath, ENT_QUOTES) ?>" aria-label="Open Settings" title="Settings">
                    <span class="profile-icon" aria-label="Profile">
                        <?php if ($headerProfilePhotoUrl): ?><img src="<?= htmlspecialchars($headerProfilePhotoUrl, ENT_QUOTES) ?>" alt="">
                        <?php else: ?><i class="fa-solid fa-user"></i><?php endif; ?>
                    </span>
                    <span><strong><?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?></strong><small><?= htmlspecialchars(ucfirst($_SESSION['user_role'] ?? 'staff')) ?></small></span>
                </a>
            </div>
            <script>
                (() => {
                    const button = document.getElementById('adminNotificationButton');
                    const panel = document.getElementById('adminNotificationPanel');
                    const sidebarLink = document.getElementById('adminSidebarNotificationLink');
                    let notificationKey = sidebarLink?.dataset.notificationKey || '';
                    let hasActivity = <?= $hasAdminNotification ? 'true' : 'false' ?>;
                    const storageKey = <?= json_encode('queuezy-viewed-notification-' . ($_SESSION['user_role'] ?? '') . '-' . (int)$_SESSION['user_id']) ?>;
                    const isNotificationsPage = <?= in_array($currentPage, ['notifications.php', 'notification.php'], true) ? 'true' : 'false' ?>;
                    const readViewedKey = () => { try { return localStorage.getItem(storageKey); } catch { return ''; } };
                    const syncNotificationBadge = () => {
                        const unread = hasActivity && notificationKey && notificationKey !== readViewedKey();
                        if (!unread) {
                            sidebarLink?.querySelector('em')?.remove();
                            document.getElementById('adminTopNotificationDot')?.remove();
                            return;
                        }
                        if (sidebarLink && !sidebarLink.querySelector('em')) {
                            const badge = document.createElement('em');
                            badge.textContent = '1';
                            sidebarLink.appendChild(badge);
                        }
                        if (button && !document.getElementById('adminTopNotificationDot')) {
                            const dot = document.createElement('b');
                            dot.id = 'adminTopNotificationDot';
                            button.appendChild(dot);
                        }
                    };
                    const clearNotificationBadge = () => {
                        if (!notificationKey) return;
                        try { localStorage.setItem(storageKey, notificationKey); } catch {}
                        sidebarLink?.querySelector('em')?.remove();
                        document.getElementById('adminTopNotificationDot')?.remove();
                    };
                    syncNotificationBadge();
                    button?.addEventListener('click', (event) => {
                        event.stopPropagation();
                        clearNotificationBadge();
                        const visible = panel?.classList.toggle('is-visible') === true;
                        button.setAttribute('aria-expanded', String(visible));
                    });
                    document.addEventListener('click', (event) => {
                        if (panel?.classList.contains('is-visible') && !panel.contains(event.target) && event.target !== button) {
                            panel.classList.remove('is-visible');
                            button?.setAttribute('aria-expanded', 'false');
                        }
                    });
                    sidebarLink?.addEventListener('click', clearNotificationBadge);
                    if (isNotificationsPage) clearNotificationBadge();
                    const updateAdminNotifications = async () => {
                        try {
                            const response = await fetch('../api/admin_notification.php', { cache: 'no-store' });
                            if (!response.ok) throw new Error('Notifications unavailable');
                            const data = await response.json();
                            if (!data?.ok) throw new Error('Notifications unavailable');
                            document.querySelectorAll('[data-notification-field]').forEach((element) => {
                                const value = data[element.dataset.notificationField];
                                if (typeof value === 'string') element.textContent = value;
                            });
                            document.getElementById('adminNotificationContent').textContent = data.message;
                            notificationKey = data.key;
                            hasActivity = data.has_activity;
                            if (sidebarLink) sidebarLink.dataset.notificationKey = notificationKey;
                            if (isNotificationsPage || panel?.classList.contains('is-visible')) clearNotificationBadge();
                            syncNotificationBadge();
                        } catch {
                            document.getElementById('adminNotificationContent').textContent = 'Notifications are temporarily unavailable. Retrying…';
                            document.querySelectorAll('[data-notification-field]').forEach((element) => {
                                element.textContent = 'Updates unavailable. Retrying…';
                            });
                        } finally {
                            window.setTimeout(updateAdminNotifications, 5000);
                        }
                    };
                    updateAdminNotifications();
                })();
            </script>
        </header>
        <div class="queue-content">
