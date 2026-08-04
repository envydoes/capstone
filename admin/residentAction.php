<?php
/**
 * residentAction.php
 * Handles archive / unarchive actions for residents.
 * Expects POST: userID (int), action ('archive' | 'unarchive')
 */
session_start();
header('Content-Type: application/json');

// â”€â”€ Auth guard â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// â”€â”€ DB connection â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$conn = mysqli_connect('o7jpqmin0zgconui4xtnfju6', 'root', 'UKkJ05DHQDMMMOxFEUI5f1HJGVj8Vb5gfJAEvAESTGCVWDtFEGb42qX67AxGUXvj', 'sumeste_db');
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}

// â”€â”€ Permission check â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
require_once __DIR__ . '/../includes/check_permissions.php';
require_permission_ajax($conn, 'manage_residents');

// â”€â”€ Read & validate POST â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$userID = isset($_POST['userID']) ? (int)$_POST['userID'] : 0;
$action = trim($_POST['action'] ?? '');

if ($userID <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid user ID.']);
    exit;
}

if (!in_array($action, ['archive', 'unarchive'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    exit;
}

// â”€â”€ Map action â†’ new status â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$newStatus     = $action === 'archive' ? 'archived' : 'approved';
$currentStatus = $action === 'archive' ? 'approved'  : 'archived';

// â”€â”€ Prepared UPDATE â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$sql  = "UPDATE tbl_userinfo SET userStatus = ? WHERE userID = ? AND userStatus = ?";
$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

mysqli_stmt_bind_param($stmt, 'sis', $newStatus, $userID, $currentStatus);

if (!mysqli_stmt_execute($stmt)) {
    $err = mysqli_stmt_error($stmt);
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    echo json_encode(['success' => false, 'message' => 'Execute failed: ' . $err]);
    exit;
}

$affected = mysqli_stmt_affected_rows($stmt);
mysqli_stmt_close($stmt);
mysqli_close($conn);

if ($affected === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'No rows updated. The record may not exist or already has this status.'
    ]);
    exit;
}

// â”€â”€ Log action to session â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if (!isset($_SESSION['resident_actions'])) {
    $_SESSION['resident_actions'] = [];
}
$_SESSION['resident_actions'][] = [
    'userID'     => $userID,
    'action'     => $action,
    'new_status' => $newStatus,
    'done_at'    => date('Y-m-d H:i:s'),
    'done_by'    => $_SESSION['user_id'],
];

echo json_encode([
    'success'    => true,
    'message'    => ucfirst($action) . 'd successfully.',
    'userID'     => $userID,
    'new_status' => $newStatus,
]);