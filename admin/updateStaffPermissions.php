<?php
/**
 * admin/updateStaffPermissions.php
 * ------------------------------------------------------------
 * Updates (or creates) a staff member's module grant + position
 * in tbl_admin_permissions. Founding admin only.
 * ------------------------------------------------------------
 */

error_reporting(E_ALL);
ini_set('display_errors', '0'); // never echo raw PHP errors into a JSON endpoint
ini_set('log_errors', '1');

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['account_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only the main admin can change permissions.']);
    exit;
}

require_once __DIR__ . '/../includes/check_permissions.php';
require_once __DIR__ . '/../config/db_connection.php';

$userID      = trim($_POST['userID'] ?? '');
$permissions = $_POST['permissions'] ?? [];
$fullName    = trim((string) ($_POST['fullName'] ?? ''));
$position    = trim((string) ($_POST['position'] ?? ''));

if ($userID === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid user.']);
    exit;
}

// Only accept a position that's actually one of the recognized barangay
// roles (see BARANGAY_POSITIONS in check_permissions.php). Anything else
// (blank included) is silently dropped rather than saved as free text.
if ($position !== '' && !array_key_exists($position, BARANGAY_POSITIONS)) {
    $position = '';
}
$fullName = mb_substr($fullName, 0, 150);

$permissions = array_values(array_intersect((array) $permissions, array_keys(PERMISSION_MODULES)));
$permCsv     = implode(',', $permissions);
$revoked     = count($permissions) === 0;
$status      = $revoked ? 'revoked' : 'active';

try {
    $stmt = $conn->prepare('SELECT accID, full_name, position FROM tbl_admin_permissions WHERE accID = ? LIMIT 1');
    if (!$stmt) {
        throw new Exception('Lookup query failed to prepare.');
    }
    $stmt->bind_param('s', $userID);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Editing an existing grant: keep the name already on file unless the
    // caller explicitly sent a new value.
    if ($exists && $fullName === '') {
        $fullName = $exists['full_name'] ?? '';
    }

    // Position: only fall back to the existing value when the caller didn't
    // send a position field at all (e.g. handleRemoveAdmin()'s revoke-only
    // request). If the field WAS sent — even as "" because the admin picked
    // "Select position" in the modal to intentionally clear it — respect that,
    // otherwise a deliberate clear silently reverted to the old value.
    if ($exists && !array_key_exists('position', $_POST)) {
        $position = $exists['position'] ?? '';
    }

    if ($exists) {
        $stmt2 = $conn->prepare('UPDATE tbl_admin_permissions SET permissions_csv = ?, status = ?, full_name = ?, position = ?, updated_at = NOW() WHERE accID = ?');
        if (!$stmt2) {
            throw new Exception('Update query failed to prepare.');
        }
        $stmt2->bind_param('sssss', $permCsv, $status, $fullName, $position, $userID);
    } else {
        $grantedBy = $_SESSION['acc_id'] ?? null;
        $stmt2 = $conn->prepare('INSERT INTO tbl_admin_permissions (accID, permissions_csv, status, granted_by, full_name, position, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
        if (!$stmt2) {
            throw new Exception('Insert query failed to prepare.');
        }
        $stmt2->bind_param('ssssss', $userID, $permCsv, $status, $grantedBy, $fullName, $position);
    }

    if (!$stmt2->execute()) {
        throw new Exception('Save query failed to execute.');
    }
    $stmt2->close();
} catch (Throwable $e) {
    error_log('updateStaffPermissions.php failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not save changes.']);
    exit;
}

mysqli_close($conn);

echo json_encode([
    'success'         => true,
    'revoked'         => $revoked,
    'full_name'       => $fullName,
    'position'        => $position,
    'position_label'  => get_position_label($position),
]);
