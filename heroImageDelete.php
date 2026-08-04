<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['account_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

require_once __DIR__ . '/config/db_connection.php';

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid image id.']);
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT filename FROM tbl_hero_images WHERE id = ?");
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Image not found.']);
    exit;
}

$deleteStmt = mysqli_prepare($conn, "DELETE FROM tbl_hero_images WHERE id = ?");
mysqli_stmt_bind_param($deleteStmt, 'i', $id);

if (mysqli_stmt_execute($deleteStmt)) {
    $filePath = __DIR__ . '/uploads/hero/' . basename($row['filename']);
    if (is_file($filePath)) {
        unlink($filePath);
    }
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to delete image record.']);
}

mysqli_stmt_close($deleteStmt);
mysqli_close($conn);