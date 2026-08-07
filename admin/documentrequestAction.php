<?php
/**
 * documentrequestAction.php
 * Handles approve / reject for document requests in tbl_requestdocs.
 * Receives: requestId (= tbl_requestdocs.id), action (approve|reject)
 */

session_start();

// Prevent any output before JSON header
error_reporting(0);
@ini_set('display_errors', 0);

header('Content-Type: application/json');

// f¢â₱â?s¬f¢â₱â?s¬ Auth f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬
if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

// f¢â₱â?s¬f¢â₱â?s¬ Input f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬
$requestId = isset($_POST['requestId']) ? (int) $_POST['requestId'] : 0;
$action    = isset($_POST['action'])    ? trim($_POST['action'])     : '';

if ($requestId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid request ID.']);
    exit;
}
if (!in_array($action, ['approve', 'reject'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    exit;
}

$newStatus = $action === 'approve' ? 'approved' : 'rejected';

// f¢â₱â?s¬f¢â₱â?s¬ DB f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬
require_once __DIR__ . '/../config/db_connection.php';
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'DB connection failed.']);
    exit;
}
mysqli_set_charset($conn, 'utf8mb4');

require_once __DIR__ . '/../includes/check_permissions.php';
require_permission_ajax($conn, 'manage_documents');

// f¢â₱â?s¬f¢â₱â?s¬ Verify the record exists and is still pending f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬
$check = $conn->prepare("SELECT id FROM tbl_requestdocs WHERE id = ? LIMIT 1");
if (!$check) {
    echo json_encode(['success' => false, 'message' => 'Prepare failed (check): ' . $conn->error]);
    mysqli_close($conn);
    exit;
}
$check->bind_param('i', $requestId);
$check->execute();
$check->store_result();
if ($check->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Request not found (id=' . $requestId . ').']);
    $check->close();
    mysqli_close($conn);
    exit;
}
$check->close();

// f¢â₱â?s¬f¢â₱â?s¬ Update f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬f¢â₱â?s¬
$stmt = $conn->prepare("
    UPDATE tbl_requestdocs
    SET    status = ?
    WHERE  id     = ?
    AND    LOWER(status) = 'pending'
");

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
    mysqli_close($conn);
    exit;
}

$stmt->bind_param('si', $newStatus, $requestId);

if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        echo json_encode(['success' => true, 'status' => $newStatus]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Request is no longer pending (already processed).']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Execute failed: ' . $stmt->error]);
}

$stmt->close();
mysqli_close($conn);
exit;