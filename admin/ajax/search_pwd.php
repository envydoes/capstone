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

// Was hardcoded to admin-only with no permission system at all. Gated on
// manage_beneficiaries since PWD status/ID lives in tbl_beneficiary.
if (($_SESSION['account_role'] ?? '') !== 'admin') {
    require_permission_ajax($conn, 'manage_beneficiaries');
}

$q = trim($_GET['q'] ?? '');

$sql = "
    SELECT b.pwd_id_number, b.status AS beneficiary_status,
           u.userID, u.firstname, u.lastname, u.middlename, u.suffix, u.street
    FROM tbl_beneficiary b
    JOIN tbl_userinfo u ON b.userId = u.userID
    WHERE b.is_pwd = 1
";
$params = [];
$types  = '';

if ($q !== '') {
    $sql .= " AND (u.firstname LIKE ? OR u.lastname LIKE ? OR u.middlename LIKE ? OR b.pwd_id_number LIKE ?)";
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