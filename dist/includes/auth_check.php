<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function getCurrentUserRole(): string {
    $role = $_SESSION['user_role'] ?? $_SESSION['role'] ?? 'customer';
    return strtolower((string) $role);
}

function redirectToLogin(): void {
    header('Location: ../login.php');
    exit;
}

function requireAuth(array $allowedRoles = []): void {
    $isGuestAccess = !isset($_SESSION['user_id']) && !empty($_SESSION['guest_mode']);

    if (!$isGuestAccess && !isset($_SESSION['user_id'])) {
        redirectToLogin();
    }

    if ($isGuestAccess) {
        if ($allowedRoles !== [] && !in_array('customer', $allowedRoles, true)) {
            redirectToLogin();
        }
        return;
    }

    $role = getCurrentUserRole();
    if ($allowedRoles !== [] && !in_array($role, $allowedRoles, true)) {
        redirectToLogin();
    }
}

function redirectToRoleDashboard(string $role): void {
    $normalizedRole = strtolower($role);

    if ($normalizedRole === 'admin') {
        header('Location: admin/departments.php');
    } elseif ($normalizedRole === 'staff') {
        header('Location: staff/index.php');
    } else {
        header('Location: customer/customer_dashboard.php');
    }
    exit;
}

function checkRole(array $allowed_roles = []): void {
    requireAuth($allowed_roles);
}
