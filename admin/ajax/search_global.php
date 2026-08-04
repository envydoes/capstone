<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../includes/check_permissions.php';
require_once __DIR__ . '/../../includes/global_list_query.php';

if (($_SESSION['account_role'] ?? '') !== 'admin') {
    require_permission_ajax($conn, 'manage_residents');
}

$myPermissions = get_my_permissions($conn);
$result = gf_run_global_list_query($conn, $_GET, $myPermissions);

echo json_encode([
    'success' => true,
    'count'   => $result['count'],
    'columns' => $result['columns'],
    'data'    => $result['data'],
]);