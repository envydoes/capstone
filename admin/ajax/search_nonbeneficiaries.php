<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../includes/check_permissions.php';

if (($_SESSION['account_role'] ?? '') !== 'admin') {
    require_permission_ajax($conn, 'manage_beneficiaries');
}

$q = trim($_GET['q'] ?? '');

$sql = "
    SELECT u.userID, u.firstname, u.lastname, u.middlename, u.suffix, u.street, u.phone, u.email
    FROM tbl_userinfo u
    LEFT JOIN tbl_beneficiary b ON b.userId = u.userID
    WHERE b.id IS NULL AND LOWER(u.userStatus) = 'approved'
";
$params = [];
$types  = '';

if ($q !== '') {
    $sql .= " AND (u.firstname LIKE ? OR u.lastname LIKE ? OR u.middlename LIKE ? OR u.street LIKE ?)";
    $like = '%' . $q . '%';
    $params = [$like, $like, $like, $like];
    $types  = 'ssss';
}

$sql .= " ORDER BY u.lastname ASC";

$stmt = mysqli_prepare($conn, $sql);
if ($types !== '') {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$rows = [];
while ($r = mysqli_fetch_assoc($result)) {
    $rows[] = $r;
}
mysqli_stmt_close($stmt);

echo json_encode(['success' => true, 'data' => $rows]);