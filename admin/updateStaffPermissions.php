<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['account_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only the main admin can change permissions.']);
    exit;
}

require_once __DIR__ . '/../includes/check_permissions.php';

$host = "o7jpqmin0zgconui4xtnfju6"; $dbuser = "root"; $password = "UKkJ05DHQDMMMOxFEUI5f1HJGVj8Vb5gfJAEvAESTGCVWDtFEGb42qX67AxGUXvj"; $database = "sumeste_db";
$conn = mysqli_connect($host, $dbuser, $password, $database);
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}

$userID      = trim($_POST['userID'] ?? '');
$permissions = $_POST['permissions'] ?? [];

if ($userID === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid user.']);
    exit;
}

$permissions = array_values(array_intersect((array)$permissions, array_keys(PERMISSION_MODULES)));
$permCsv     = implode(',', $permissions);
$revoked     = count($permissions) === 0;
$status      = $revoked ? 'revoked' : 'active';

$stmt = $conn->prepare('SELECT accID FROM tbl_admin_permissions WHERE accID = ? LIMIT 1');
$stmt->bind_param('s', $userID);
$stmt->execute();
$exists = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($exists) {
    $stmt2 = $conn->prepare('UPDATE tbl_admin_permissions SET permissions_csv = ?, status = ?, updated_at = NOW() WHERE accID = ?');
    $stmt2->bind_param('sss', $permCsv, $status, $userID);
} else {
    $grantedBy = $_SESSION['acc_id'] ?? null;
    $stmt2 = $conn->prepare('INSERT INTO tbl_admin_permissions (accID, permissions_csv, status, granted_by, created_at) VALUES (?, ?, ?, ?, NOW())');
    $stmt2->bind_param('ssss', $userID, $permCsv, $status, $grantedBy);
}

if (!$stmt2->execute()) {
    $stmt2->close();
    echo json_encode(['success' => false, 'message' => 'Could not save changes.']);
    exit;
}
$stmt2->close();
mysqli_close($conn);

echo json_encode(['success' => true, 'revoked' => $revoked]);