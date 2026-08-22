<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['account_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only the main admin can remove staff access.']);
    exit;
}

require_once __DIR__ . '/../includes/check_permissions.php';

require_once __DIR__ . '/../config/db_connection.php';

$userID = trim($_POST['userID'] ?? '');

if ($userID === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid user.']);
    exit;
}

$myAccID = $_SESSION['acc_id'] ?? '';
if ((string)$userID === (string)$myAccID) {
    echo json_encode(['success' => false, 'message' => 'You cannot remove your own access.']);
    exit;
}

$stmt = $conn->prepare('SELECT accID FROM tbl_admin_permissions WHERE accID = ? LIMIT 1');
$stmt->bind_param('s', $userID);
$stmt->execute();
$exists = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$exists) {
    echo json_encode(['success' => false, 'message' => 'No staff grant found for this account.']);
    exit;
}

$stmt2 = $conn->prepare("UPDATE tbl_admin_permissions SET permissions_csv = '', status = 'revoked', updated_at = NOW() WHERE accID = ?");
$stmt2->bind_param('s', $userID);

if (!$stmt2->execute()) {
    $stmt2->close();
    echo json_encode(['success' => false, 'message' => 'Could not remove access.']);
    exit;
}
$stmt2->close();
mysqli_close($conn);

echo json_encode(['success' => true, 'revoked' => true]);
