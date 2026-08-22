<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['account_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

require_once __DIR__ . '/config/db_connection.php';

const LOGO_MAX_BYTES = 3 * 1024 * 1024; // 3 MB
$uploadDir = __DIR__ . '/uploads/site/';

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

if (!isset($_FILES['site_logo']) || $_FILES['site_logo']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error occurred.']);
    exit;
}

$file = $_FILES['site_logo'];

if ($file['size'] > LOGO_MAX_BYTES) {
    echo json_encode(['success' => false, 'message' => 'File too large (max 3MB).']);
    exit;
}

$allowedMime = ['image/png' => 'png', 'image/jpeg' => 'jpg'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!isset($allowedMime[$mime])) {
    echo json_encode(['success' => false, 'message' => 'Only PNG and JPG files are allowed.']);
    exit;
}

$ext = $allowedMime[$mime];
$filename = 'logo_' . bin2hex(random_bytes(8)) . '.' . $ext;
$destination = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $destination)) {
    echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file.']);
    exit;
}

// Fetch old logo so we can clean it up after a successful swap
$oldResult = mysqli_query($conn, "SELECT site_logo FROM tbl_settings WHERE id = 1");
$oldFilename = mysqli_fetch_assoc($oldResult)['site_logo'] ?? null;

$stmt = mysqli_prepare($conn, "UPDATE tbl_settings SET site_logo = ? WHERE id = 1");
mysqli_stmt_bind_param($stmt, 's', $filename);

if (mysqli_stmt_execute($stmt)) {
    if ($oldFilename) {
        $oldPath = $uploadDir . basename($oldFilename);
        if (is_file($oldPath)) {
            unlink($oldPath);
        }
    }
    echo json_encode(['success' => true, 'data' => ['filename' => $filename]]);
} else {
    unlink($destination);
    echo json_encode(['success' => false, 'message' => 'Failed to save logo.']);
}

mysqli_stmt_close($stmt);
mysqli_close($conn);
