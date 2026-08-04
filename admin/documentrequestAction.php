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

// â”€â”€ Auth â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

// â”€â”€ Input â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

// â”€â”€ DB â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$conn = mysqli_connect('o7jpqmin0zgconui4xtnfju6', 'root', 'UKkJ05DHQDMMMOxFEUI5f1HJGVj8Vb5gfJAEvAESTGCVWDtFEGb42qX67AxGUXvj', 'sumeste_db');
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'DB connection failed.']);
    exit;
}
mysqli_set_charset($conn, 'utf8mb4');

require_once __DIR__ . '/../includes/check_permissions.php';
require_permission_ajax($conn, 'manage_documents');

// â”€â”€ Verify the record exists and is still pending â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

// â”€â”€ Update â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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