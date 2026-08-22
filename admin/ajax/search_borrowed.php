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

// Was hardcoded to account_role === 'admin' only, which meant a staff
// account granted manage_borrowing could never see this report. Now it
// follows the same module-based permission check as the other reports.
if (($_SESSION['account_role'] ?? '') !== 'admin') {
    require_permission_ajax($conn, 'manage_borrowing');
}

$q = trim($_GET['q'] ?? '');

$borrowedRes = mysqli_query($conn, "
    SELECT r.id, e.equipmentName, r.quantityRequested, r.returnDate,
           CONCAT(u.firstname, ' ', u.lastname) AS borrower_name
    FROM tbl_equipmentrequest r
    JOIN tbl_equipmentlist e ON r.equipmentId = e.equipmentId
    JOIN tbl_userinfo u ON r.userId = u.userID
    WHERE LOWER(r.status) = 'borrowed'
    ORDER BY r.returnDate ASC
");

$rows = [];
while ($r = mysqli_fetch_assoc($borrowedRes)) {
    $r['is_overdue'] = !empty($r['returnDate']) && strtotime($r['returnDate']) < strtotime('today');
    $rows[] = $r;
}

if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_values(array_filter($rows, function ($r) use ($needle) {
        $hay = mb_strtolower($r['equipmentName'] . ' ' . $r['borrower_name']);
        return mb_strpos($hay, $needle) !== false;
    }));
}

echo json_encode(['success' => true, 'data' => $rows]);
