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
// manage_residents since this only reads tbl_userinfo, not beneficiary data.
if (($_SESSION['account_role'] ?? '') !== 'admin') {
    require_permission_ajax($conn, 'manage_residents');
}

$minAge = isset($_GET['min_age']) && $_GET['min_age'] !== '' ? (int) $_GET['min_age'] : 60;
$maxAge = isset($_GET['max_age']) && $_GET['max_age'] !== '' ? (int) $_GET['max_age'] : 130;
$q      = trim($_GET['q'] ?? '');

$sql = "
    SELECT userID, firstname, lastname, middlename, suffix, birthday, street, phone,
           TIMESTAMPDIFF(YEAR, birthday, CURDATE()) AS age
    FROM tbl_userinfo
    WHERE birthday IS NOT NULL
      AND LOWER(userStatus) = 'approved'
      AND TIMESTAMPDIFF(YEAR, birthday, CURDATE()) BETWEEN ? AND ?
";
$params = [$minAge, $maxAge];
$types  = 'ii';

if ($q !== '') {
    $sql .= " AND (firstname LIKE ? OR lastname LIKE ? OR middlename LIKE ? OR street LIKE ?)";
    $like = '%' . $q . '%';
    $params = array_merge($params, [$like, $like, $like, $like]);
    $types .= 'ssss';
}

$sql .= " ORDER BY age DESC";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$rows = [];
while ($r = mysqli_fetch_assoc($result)) {
    $rows[] = $r;
}
mysqli_stmt_close($stmt);

echo json_encode(['success' => true, 'data' => $rows]);
