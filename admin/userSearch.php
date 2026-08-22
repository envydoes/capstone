<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['account_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

require_once __DIR__ . '/../config/db_connection.php';

$q = trim($_GET['q'] ?? '');

// Only staff accounts (those with a row in tbl_admin_permissions) are
// shown here — this section is exclusively for managing granted access.
$sql = "
    SELECT ua.accID AS userID, ua.email,
           COALESCE(
               NULLIF(TRIM(CONCAT(COALESCE(ui.firstname,''), ' ', COALESCE(ui.lastname,''))), ''),
               ap.full_name
           ) AS fullname,
                     ap.permissions_csv AS permissions,
                     ap.position AS position,
                     ap.granted_by AS granted_by,
                     TRIM(CONCAT(COALESCE(gui.firstname,''), ' ', COALESCE(gui.lastname,''))) AS granted_by_name,
                     gb.email AS granted_by_email
    FROM tbl_useracc ua
    JOIN tbl_admin_permissions ap ON ap.accID = ua.accID
    LEFT JOIN tbl_userinfo ui ON ui.accID = ua.accID
        LEFT JOIN tbl_useracc gb ON gb.accID = ap.granted_by
        LEFT JOIN tbl_userinfo gui ON gui.accID = gb.accID
    WHERE ap.status = 'active'
";

if ($q !== '') {
    $like = '%' . $q . '%';
    $sql .= " AND (ua.email LIKE ? OR ui.firstname LIKE ? OR ui.lastname LIKE ?) ";
}

$sql .= " ORDER BY ua.email ASC LIMIT 25";

$stmt = $conn->prepare($sql);
if ($q !== '') {
    $stmt->bind_param('sss', $like, $like, $like);
}
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($r = $res->fetch_assoc()) { $rows[] = $r; }
$stmt->close();
mysqli_close($conn);

echo json_encode(['success' => true, 'data' => $rows]);