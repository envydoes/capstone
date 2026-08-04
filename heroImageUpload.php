<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['account_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

require_once __DIR__ . '/config/db_connection.php';

const HERO_MAX_IMAGES = 5;
const HERO_MAX_BYTES = 5 * 1024 * 1024; // 5 MB
$uploadDir = __DIR__ . '/uploads/hero/';

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Enforce max count
$countResult = mysqli_query($conn, "SELECT COUNT(*) AS total FROM tbl_hero_images");
$currentCount = (int) (mysqli_fetch_assoc($countResult)['total'] ?? 0);
if ($currentCount >= HERO_MAX_IMAGES) {
    echo json_encode(['success' => false, 'message' => 'Maximum of ' . HERO_MAX_IMAGES . ' hero images reached.']);
    exit;
}

if (!isset($_FILES['hero_image']) || $_FILES['hero_image']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error occurred.']);
    exit;
}

$file = $_FILES['hero_image'];

if ($file['size'] > HERO_MAX_BYTES) {
    echo json_encode(['success' => false, 'message' => 'File too large (max 5MB).']);
    exit;
}

$allowedMime = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!isset($allowedMime[$mime])) {
    echo json_encode(['success' => false, 'message' => 'Only JPG and PNG files are allowed.']);
    exit;
}

$ext = $allowedMime[$mime];
$filename = 'hero_' . bin2hex(random_bytes(8)) . '.' . $ext;
$destination = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $destination)) {
    echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file.']);
    exit;
}

$sortOrder = $currentCount; // append to end
$stmt = mysqli_prepare($conn, "INSERT INTO tbl_hero_images (filename, sort_order) VALUES (?, ?)");
mysqli_stmt_bind_param($stmt, 'si', $filename, $sortOrder);

if (mysqli_stmt_execute($stmt)) {
    $newId = mysqli_insert_id($conn);
    echo json_encode(['success' => true, 'data' => ['id' => $newId, 'filename' => $filename]]);
} else {
    unlink($destination);
    echo json_encode(['success' => false, 'message' => 'Failed to save image record.']);
}

mysqli_stmt_close($stmt);
mysqli_close($conn);