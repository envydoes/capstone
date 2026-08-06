<?php
/**
 * residentAction.php
 * Handles archive / unarchive actions for residents.
 * Expects POST: userID (int), action ('archive' | 'unarchive')
 */
session_start();
header('Content-Type: application/json');

// �f¢â�,�â�?s¬�f¢â�,�â�?s¬ Auth guard �f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// �f¢â�,�â�?s¬�f¢â�,�â�?s¬ DB connection �f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬
require_once __DIR__ . '/../config/db_connection.php';
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}

// �f¢â�,�â�?s¬�f¢â�,�â�?s¬ Permission check �f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬
require_once __DIR__ . '/../includes/check_permissions.php';
require_permission_ajax($conn, 'manage_residents');

// �f¢â�,�â�?s¬�f¢â�,�â�?s¬ Read & validate POST �f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬
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

// �f¢â�,�â�?s¬�f¢â�,�â�?s¬ Map action �f¢â�,� â�,��"� new status �f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬
$newStatus     = $action === 'archive' ? 'archived' : 'approved';
$currentStatus = $action === 'archive' ? 'approved'  : 'archived';

// �f¢â�,�â�?s¬�f¢â�,�â�?s¬ Prepared UPDATE �f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬
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

// �f¢â�,�â�?s¬�f¢â�,�â�?s¬ Log action to session �f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬�f¢â�,�â�?s¬
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