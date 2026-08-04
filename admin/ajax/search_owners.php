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

// This endpoint powers the Business/Apartment "Owner Directory" report.
// Founder admin always passes (has_permission() short-circuits true for
// them); staff need the manage_listings module specifically — not
// manage_residents, which was copy-pasted in by mistake.
if (($_SESSION['account_role'] ?? '') !== 'admin') {
    require_permission_ajax($conn, 'manage_listings');
}

$q = trim($_GET['q'] ?? '');

$ownerDirRes = mysqli_query($conn, "
    SELECT userId,
           COUNT(*) AS listing_count,
           SUM(CASE WHEN listingType = 'apartment' THEN 1 ELSE 0 END) AS apt_count,
           SUM(CASE WHEN listingType = 'business'  THEN 1 ELSE 0 END) AS biz_count
    FROM tbl_busaptlisting
    GROUP BY userId
    ORDER BY listing_count DESC
");

$owners = [];
while ($r = mysqli_fetch_assoc($ownerDirRes)) {
    $owners[] = $r;
}

foreach ($owners as &$ownerRow) {
    $accId = $ownerRow['userId'];
    $stmt = mysqli_prepare($conn, "SELECT firstname, lastname FROM tbl_userinfo WHERE accID = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 's', $accId);
    mysqli_stmt_execute($stmt);
    $nameRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    $ownerRow['owner_name'] = $nameRow ? trim($nameRow['firstname'] . ' ' . $nameRow['lastname']) : $accId;
}
unset($ownerRow);

if ($q !== '') {
    $needle = mb_strtolower($q);
    $owners = array_values(array_filter($owners, function ($o) use ($needle) {
        return mb_strpos(mb_strtolower($o['owner_name']), $needle) !== false;
    }));
}

echo json_encode(['success' => true, 'data' => $owners]);