<?php
/**
 * includes/check_permissions.php
 * ------------------------------------------------------------
 * Central permission system for staff accounts layered on top of
 * the existing account_role gate.
 *
 * IMPORTANT: this does NOT replace account_role. A resident granted
 * module access keeps logging in with their normal credentials —
 * this only grants extra module access, tracked in
 * tbl_admin_permissions. There is no named "position" anymore —
 * the admin assigns individual modules directly.
 * ------------------------------------------------------------
 */

const PERMISSION_MODULES = [
    'dashboard'             => 'Admin Dashboard',
    'manage_residents'      => 'Resident Management',
    'manage_beneficiaries'  => 'Beneficiary Management',
    'manage_documents'      => 'Document Request',
    'manage_borrowing'      => 'Borrowing System',
    'manage_listings'       => 'Community Listings',
    'manage_announcements'  => 'Announcements',
];

// NOTE: 'manage_users' (account approvals) and permission-granting
// itself are intentionally NOT in PERMISSION_MODULES — they remain
// exclusive to the founding admin (account_role === 'admin') so a
// granted staff account can never approve new admins or self-promote.

function is_permission_grant_active(?array $grant): bool
{
    if (!$grant) return false;

    $status = strtolower(trim((string) ($grant['status'] ?? '')));
    if ($status === 'revoked' || $status === 'removed') {
        return false;
    }

    if ($status === 'active') {
        return true;
    }

    // Legacy rows from earlier app versions may have a blank status but still
    // carry permissions. Treat those as active unless they were explicitly
    // revoked/removed by the barangay admin.
    return trim((string) ($grant['permissions_csv'] ?? '')) !== '';
}

function get_staff_grant(mysqli $conn, string $accID): ?array
{
    $stmt = $conn->prepare('SELECT accID, permissions_csv, status, granted_by, created_at, updated_at, confirmed_at FROM tbl_admin_permissions WHERE accID = ? LIMIT 1');
    if (!$stmt) return null;
    $stmt->bind_param('s', $accID);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function get_staff_permissions(mysqli $conn, string $accID): array
{
    $row = get_staff_grant($conn, $accID);
    if (!$row || !is_permission_grant_active($row)) return [];
    return array_values(array_filter(array_map('trim', explode(',', $row['permissions_csv'] ?? ''))));
}

function has_permission(mysqli $conn, string $key): bool
{
    $role = $_SESSION['account_role'] ?? '';
    if ($role === 'admin') return true;

    $accID = $_SESSION['acc_id'] ?? ($_SESSION['user_id'] ?? '');
    if ($accID === '') return false;

    return in_array($key, get_staff_permissions($conn, $accID), true);
}

/**
 * Full list of permission keys the CURRENT session account can
 * access — used for building UI (e.g. sidebar menus).
 */
function get_my_permissions(mysqli $conn): array
{
    $role = $_SESSION['account_role'] ?? '';
    if ($role === 'admin') {
        return array_keys(PERMISSION_MODULES);
    }

    $accID = $_SESSION['acc_id'] ?? ($_SESSION['user_id'] ?? '');
    if ($accID === '') return [];

    return get_staff_permissions($conn, $accID);
}

function get_post_login_redirect(mysqli $conn, string $accID, string $accountRole): string
{
    if ($accountRole === 'admin') {
        return 'admin/adminDashboard.php';
    }

    $grant = get_staff_grant($conn, $accID);
    if ($grant && is_permission_grant_active($grant)) {
        $permissions = array_values(array_filter(array_map('trim', explode(',', $grant['permissions_csv'] ?? ''))));
        if (in_array('dashboard', $permissions, true)) {
            return 'admin/adminDashboard.php';
        }
    }

    return 'admin/adminLanding.php';

    switch ($accountRole) {
        case 'resident':
        case 'resident,business/apartment owner':
            return 'resident/residentLanding.php';
        case 'non-resident':
        case 'non-resident,business/apartment owner':
            return 'nonresident/nonresidentLanding.php';
        default:
            return 'landing.php';
    }
}

/**
 * AJAX-safe version of require_permission().
 *
 * require_permission() is meant for full page loads: on failure it
 * issues an HTTP redirect (header('Location: ...')) and exits. That
 * breaks any endpoint that's supposed to return JSON — a fetch()
 * call just follows the redirect, gets back HTML, JSON.parse()
 * throws, and the table silently stays empty. Every ajax/search_*.php
 * and report endpoint should call THIS instead.
 */
function require_permission_ajax(mysqli $conn, string $key): void
{
    if (has_permission($conn, $key)) return;

    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'You do not have access to this module.',
    ]);
    exit;
}

function require_permission(mysqli $conn, string $key): void
{
    if (has_permission($conn, $key)) return;

    $role = $_SESSION['account_role'] ?? '';
    $normalizedRole = strtolower(trim($role));

    // A directly-created "staff" account should stay in the admin flow,
    // but any module it was not granted should send it back to its admin
    // landing page instead of kicking it out to the public site or login.
    if ($normalizedRole === 'staff') {
        header('Location: ../admin/adminLanding.php');
        exit;
    }

    switch ($role) {
        case 'resident':
        case 'resident,business/apartment owner':
            header('Location: ../resident/residentLanding.php');
            break;
        case 'non-resident':
        case 'non-resident,business/apartment owner':
            header('Location: ../nonresident/nonresidentLanding.php');
            break;
        default:
            header('Location: ../landing.php');
    }
    exit;
}