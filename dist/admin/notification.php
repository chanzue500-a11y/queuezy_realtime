<?php
require_once '../database/db.php';
$conn = $conn ?? null;
session_start();

if (($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$pageTitle = 'Notifications | Queuezy Admin';
$pageHeading = 'Notifications';
require_once '../includes/header.php';

?>

<style>
    .admin-notifications {
        max-width: 1180px;
    }

    .admin-notifications__heading {
        margin-bottom: 26px;
    }

    .admin-notifications__heading h1 {
        margin: 0;
        color: #f8fafc;
        font-size: clamp(1.8rem, 3vw, 2.4rem);
        font-weight: 800;
        letter-spacing: -0.04em;
    }

    .admin-notifications__heading p {
        margin: 7px 0 0;
        color: #a9b7cc;
        font-size: 0.9rem;
    }

    .admin-notifications__grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 18px;
    }

    .admin-notification-card {
        min-height: 164px;
        padding: 22px;
        border: 1px solid rgba(100, 116, 139, 0.38);
        border-radius: 18px;
        background: #1d293d;
        box-shadow: 0 14px 28px rgba(2, 6, 23, 0.12);
    }

    .admin-notification-card__icon {
        display: grid;
        place-items: center;
        width: 40px;
        height: 40px;
        margin-bottom: 18px;
        border-radius: 12px;
        background: rgba(118, 82, 214, 0.16);
        color: #bda7ff;
    }

    .admin-notification-card__icon svg {
        width: 19px;
        height: 19px;
    }

    .admin-notification-card__icon i {
        font-size: 1.05rem;
    }

    .admin-notification-card h2 {
        margin: 0;
        color: #e5edf8;
        font-size: 0.9rem;
        font-weight: 700;
    }

    .admin-notification-card p {
        margin: 8px 0 0;
        color: #aebdd1;
        font-size: 0.8rem;
        line-height: 1.45;
    }

    .admin-notification-card strong {
        color: #f8fafc;
    }

    @media (max-width: 900px) {
        .admin-notifications__grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<section class="admin-notifications">
    <header class="admin-notifications__heading">
        <h1>Notifications</h1>
        <p>Stay informed about important updates across your workspace.</p>
    </header>

    <div class="admin-notifications__grid">
        <article class="admin-notification-card">
            <div class="admin-notification-card__icon"><i class="fa-solid fa-clock" aria-hidden="true"></i></div>
            <h2>Queue overview</h2>
            <p data-notification-field="queue_message"><?= htmlspecialchars($workspaceNotifications['queue_message'] ?? $workspaceNotifications['message'], ENT_QUOTES, 'UTF-8') ?></p>
        </article>

        <article class="admin-notification-card">
            <div class="admin-notification-card__icon"><i class="fa-solid fa-credit-card" aria-hidden="true"></i></div>
            <h2>Service changes</h2>
            <p data-notification-field="service_message"><?= htmlspecialchars($workspaceNotifications['service_message'] ?? $workspaceNotifications['message'], ENT_QUOTES, 'UTF-8') ?></p>
        </article>

        <article class="admin-notification-card">
            <div class="admin-notification-card__icon"><i class="fa-solid fa-user-plus" aria-hidden="true"></i></div>
            <h2>New customers</h2>
            <p data-notification-field="customer_message"><?= htmlspecialchars($workspaceNotifications['customer_message'] ?? $workspaceNotifications['message'], ENT_QUOTES, 'UTF-8') ?></p>
        </article>
    </div>
</section>

<script>feather.replace();</script>
<?php require_once '../includes/footer.php'; ?>
