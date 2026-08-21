<?php
/**
 * admin/createStaffAccount.php
 * ------------------------------------------------------------
 * Creates a brand-new staff login (tbl_useracc, account_role =
 * 'staff') and grants it module access (tbl_admin_permissions),
 * exactly like updateStaffPermissions.php does for an existing
 * account - except this also has to invent a fresh accID and
 * set a password, since the account doesn't exist yet.
 * ------------------------------------------------------------
 */

error_reporting(E_ALL);
ini_set('display_errors', '0'); // never echo raw PHP errors into a JSON endpoint
ini_set('log_errors', '1');

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['account_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only the main admin can create staff accounts.']);
    exit;
}

require_once __DIR__ . '/../includes/check_permissions.php';
require_once __DIR__ . '/../config/db_connection.php';

$fullName = trim((string) ($_POST['fullName'] ?? ''));
$position = trim((string) ($_POST['position'] ?? ''));
$email    = trim((string) ($_POST['email'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$permissions = $_POST['permissions'] ?? [];

// ---- validation (mirrors the JS checks in settings.php, but
// enforced server-side since the client can't be trusted) ----

if ($fullName === '') {
    echo json_encode(['success' => false, 'message' => 'Full name is required.']);
    exit;
}
$fullName = mb_substr($fullName, 0, 150);

if ($position === '' || !array_key_exists($position, BARANGAY_POSITIONS)) {
    echo json_encode(['success' => false, 'message' => 'Please select a valid barangay position.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}
$email = mb_substr($email, 0, 254);

if (strlen($password) < 8) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters.']);
    exit;
}

$permissions = array_values(array_intersect((array) $permissions, array_keys(PERMISSION_MODULES)));
if (count($permissions) === 0) {
    echo json_encode(['success' => false, 'message' => 'Select at least one module to grant access to.']);
    exit;
}
$permCsv = implode(',', $permissions);

// ---- email must not already be in use ----

$stmt = $conn->prepare('SELECT accID FROM tbl_useracc WHERE email = ? LIMIT 1');
$stmt->bind_param('s', $email);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existing) {
    echo json_encode(['success' => false, 'message' => 'An account with that email already exists.']);
    exit;
}

// ---- generate a real, unique accID -----------------------------------
// NOT mysqli insert_id (accID is a varchar PK, not AUTO_INCREMENT - that's
// what was producing the '' / '0' accounts seen in the DB backup). Use a
// random hex id, same shape as the accounts that already work correctly
// (e.g. '261fff222b7b11fd'), and confirm it's actually free before using it.

function generate_unique_acc_id(mysqli $conn): ?string
{
    for ($i = 0; $i < 5; $i++) {
        $candidate = bin2hex(random_bytes(8)); // 16 hex chars
        $check = $conn->prepare('SELECT 1 FROM tbl_useracc WHERE accID = ? LIMIT 1');
        $check->bind_param('s', $candidate);
        $check->execute();
        $taken = $check->get_result()->fetch_assoc();
        $check->close();
        if (!$taken) {
            return $candidate;
        }
    }
    return null; // exceedingly unlikely, but don't loop forever
}

$accID = generate_unique_acc_id($conn);
if ($accID === null) {
    echo json_encode(['success' => false, 'message' => 'Could not generate a unique account ID. Please try again.']);
    exit;
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);
$grantedBy    = $_SESSION['acc_id'] ?? ($_SESSION['user_id'] ?? null);

// ---- insert both rows atomically --------------------------------------

$conn->begin_transaction();

try {
    $stmt1 = $conn->prepare('INSERT INTO tbl_useracc (accID, email, password, account_role) VALUES (?, ?, ?, ?)');
    $role = 'staff';
    $stmt1->bind_param('ssss', $accID, $email, $passwordHash, $role);
    if (!$stmt1->execute()) {
        throw new Exception('Failed to create the login account.');
    }
    $stmt1->close();

    $status = 'active';
    $stmt2 = $conn->prepare('INSERT INTO tbl_admin_permissions (accID, permissions_csv, status, granted_by, full_name, position, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
    $stmt2->bind_param('ssssss', $accID, $permCsv, $status, $grantedBy, $fullName, $position);
    if (!$stmt2->execute()) {
        throw new Exception('Failed to grant module access.');
    }
    $stmt2->close();

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    error_log('createStaffAccount.php failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not create the account. Please try again.']);
    exit;
}

mysqli_close($conn);

echo json_encode([
    'success'        => true,
    'accID'          => $accID,
    'full_name'      => $fullName,
    'position'       => $position,
    'position_label' => get_position_label($position),
]);