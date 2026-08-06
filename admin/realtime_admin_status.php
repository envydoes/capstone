<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!isset($_SESSION['user_id']) || (($_SESSION['account_role'] ?? '') !== 'admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}


require_once __DIR__ . '/../config/db_connection.php';
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

function scalarCount(mysqli $conn, string $sql): int {
    $res = mysqli_query($conn, $sql);
    if (!$res) return 0;
    $row = mysqli_fetch_row($res);
    mysqli_free_result($res);
    return isset($row[0]) ? (int)$row[0] : 0;
}

$pending = scalarCount($conn, "SELECT COUNT(*) FROM tbl_userinfo WHERE LOWER(userStatus) = 'pending'");
$activeResidents = scalarCount($conn, "SELECT COUNT(*) FROM tbl_userinfo WHERE LOWER(userStatus) = 'approved' AND account_role_csv LIKE '%resident%'");
$archivedResidents = scalarCount($conn, "SELECT COUNT(*) FROM tbl_userinfo WHERE LOWER(userStatus) = 'archived'");

mysqli_close($conn);

echo json_encode([
    'success' => true,
    'pending' => $pending,
    'active_residents' => $activeResidents,
    'archived_residents' => $archivedResidents,
    'ts' => time(),
]);
