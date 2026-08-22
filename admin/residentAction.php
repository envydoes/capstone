<?php
/**
 * residentAction.php
 * Handles archive / unarchive actions for residents.
 * Expects POST: userID (int), action ('archive' | 'unarchive')
 */
session_start();
header('Content-Type: application/json');

// ==== Auth guard ====
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// ==== DB connection ====
require_once __DIR__ . '/../config/db_connection.php';
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}

// ==== Permission check ====
require_once __DIR__ . '/../includes/check_permissions.php';
require_permission_ajax($conn, 'manage_residents');

// ==== Read & validate POST ====
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

// ==== Block archiving if the resident has anything pending ====
// (unarchive/restore is never blocked - only the archive action itself)
if ($action === 'archive') {
    require_once __DIR__ . '/../includes/pending_checks.php';
    $blockers = get_pending_blockers($conn, $userID);
    if (!empty($blockers)) {
        echo json_encode([
            'success' => false,
            'message' => 'Cannot archive - this resident still has ' . implode(', ', $blockers) . '. Resolve these first.',
        ]);
        mysqli_close($conn);
        exit;
    }
}

// ==== Map action  ->  new status ====
$newStatus     = $action === 'archive' ? 'archived' : 'approved';
$currentStatus = $action === 'archive' ? 'approved'  : 'archived';

// ==== Prepared UPDATE ====
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

// ==== Log action to session ====
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
