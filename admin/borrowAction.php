<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$host = "localhost"; $dbuser = "root"; $password = ""; $database = "sumeste_db";
$conn = mysqli_connect($host, $dbuser, $password, $database);
if (!$conn) { echo json_encode(['success' => false, 'message' => 'DB connection failed']); exit; }

require_once __DIR__ . '/../includes/check_permissions.php';
require_permission_ajax($conn, 'manage_borrowing');

$requestID = (int)($_POST['requestID'] ?? 0);
$action    = $_POST['action'] ?? '';

if (!$requestID || !in_array($action, ['approve','reject','return'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

$statusMap = ['approve' => 'Borrowed', 'reject' => 'Rejected', 'return' => 'Returned'];
$newStatus = $statusMap[$action];

$stmt = mysqli_prepare($conn, "UPDATE tbl_equipmentRequest SET status = ?, updatedAt = NOW() WHERE id = ?");
mysqli_stmt_bind_param($stmt, 'si', $newStatus, $requestID);
$ok = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

// If approved, decrease qty. If returned, increase qty.
if ($ok && in_array($action, ['approve', 'return'])) {
    $dir = $action === 'approve' ? '-' : '+';
    $qtyStmt = mysqli_prepare($conn, "
        UPDATE tbl_equipmentList e
        JOIN tbl_equipmentRequest br ON br.equipmentId = e.equipmentId
        SET e.equipmentStock = e.equipmentStock {$dir} br.quantityRequested
        WHERE br.id = ?
    ");
    mysqli_stmt_bind_param($qtyStmt, 'i', $requestID);
    mysqli_stmt_execute($qtyStmt);
    mysqli_stmt_close($qtyStmt);
}

mysqli_close($conn);
echo json_encode(['success' => $ok, 'message' => $ok ? 'Done' : 'Update failed']);