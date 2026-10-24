<?php
require_once '../database/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['admin', 'staff'], true)) {
    header('Location: ../login.php');
    exit;
}
require_once __DIR__ . '/../includes/workspace_notifications.php';
$workspaceNotifications = workspaceNotificationSnapshot($conn ?? null, $_SESSION['user_role']);

$pageTitle = 'Notifications | Queuezy Staff';
$pageHeading = 'Notifications';
require_once '../includes/header.php';
?>

<style>
    body.dark-mode .notification-card {
        background: #1e293b !important;
        border-color: #334155 !important;
    }
    body.dark-mode .notification-card .bg-red-50 {
        background: #451a1a !important;
        color: #fca5a5 !important;
    }
    body.dark-mode .notification-card .bg-blue-50 {
        background: #172554 !important;
        color: #93c5fd !important;
    }
    body.dark-mode .notification-card p.text-slate-500,
    body.dark-mode .notification-card p.text-slate-600 { color: #cbd5e1 !important; }
</style>

<div class="mb-8">
    <h1 class="text-3xl font-black text-slate-900 tracking-tight">Notifications</h1>
    <p class="text-sm text-slate-500 mt-1">Stay updated on the current queue.</p>
</div>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
    <section class="notification-card bg-white border border-red-200 rounded-2xl p-6 shadow-sm">
        <div class="flex items-start gap-4">
            <div class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-2xl bg-red-50 text-red-600">
                <i class="fa-solid fa-bullhorn text-xl" aria-hidden="true"></i>
            </div>
            <div>
                <h2 class="text-lg font-black text-slate-900">Latest active ticket</h2>
                <p data-notification-field="call_message" class="mt-2 text-sm text-slate-600"><?= htmlspecialchars($workspaceNotifications['call_message'] ?? 'Notifications are temporarily unavailable.', ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        </div>
    </section>

    <section class="notification-card bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
        <div class="flex items-start gap-4">
            <div class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-2xl bg-blue-50 text-blue-600">
                <i class="fa-solid fa-list-ol text-xl" aria-hidden="true"></i>
            </div>
            <div>
                <h2 class="text-lg font-black text-slate-900">Waiting queue</h2>
                <p data-notification-field="waiting_message" class="mt-2 text-sm text-slate-600"><?= htmlspecialchars($workspaceNotifications['waiting_message'] ?? 'Notifications are temporarily unavailable.', ENT_QUOTES, 'UTF-8') ?></p>
                <?php if (($_SESSION['user_role'] ?? '') === 'staff'): ?>
                    <a href="monitor.php" class="mt-3 inline-flex text-sm font-bold text-purple-600 hover:text-purple-700">Open queue monitor</a>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>

<?php require_once '../includes/footer.php'; ?>
