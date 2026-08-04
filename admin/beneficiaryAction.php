<?php
/**
 * beneficiaryAction.php
 * Approve or reject a beneficiary application.
 * prio_score is already stored in DB — no recalculation needed.
 */
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$conn = mysqli_connect("localhost", "root", "", "sumeste_db");
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'DB connection failed']);
    exit;
}

require_once __DIR__ . '/../includes/check_permissions.php';
require_permission_ajax($conn, 'manage_beneficiaries');

$id     = (int)($_POST['id']    ?? 0);
$action = trim($_POST['action'] ?? '');

if (!$id || !in_array($action, ['approve', 'reject'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$new_status = ($action === 'approve') ? 'approved' : 'rejected';

$stmt = mysqli_prepare($conn, "UPDATE tbl_beneficiary SET status = ?, updated_at = NOW() WHERE id = ?");
mysqli_stmt_bind_param($stmt, 'si', $new_status, $id);
$ok = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);
mysqli_close($conn);

echo json_encode(['success' => (bool)$ok]);