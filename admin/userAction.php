<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$host = "o7jpqmin0zgconui4xtnfju6";
$user = "root";
$password = "UKkJ05DHQDMMMOxFEUI5f1HJGVj8Vb5gfJAEvAESTGCVWDtFEGb42qX67AxGUXvj";
$database = "sumeste_db";
$conn = mysqli_connect($host, $user, $password, $database);
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

require_once __DIR__ . '/../includes/check_permissions.php';
require_permission_ajax($conn, 'manage_users');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$userID = trim($_POST['userID'] ?? '');
$action = trim($_POST['action'] ?? '');

// approve â†’ approved
// reject  â†’ rejected
// disable â†’ disabled
// revert  â†’ pending
if (empty($userID) || !in_array($action, ['approve', 'reject', 'disable', 'revert'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

$statusMap = [
    'approve' => 'approved',
    'reject'  => 'rejected',
    'disable' => 'disabled',
    'revert'  => 'pending',
];
$newStatus = $statusMap[$action];

$sql  = "UPDATE tbl_userinfo SET userStatus = ? WHERE userID = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}
$stmt->bind_param('si', $newStatus, $userID);
if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'User not found or status unchanged']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Update failed']);
}
$stmt->close();
$conn->close();
?>