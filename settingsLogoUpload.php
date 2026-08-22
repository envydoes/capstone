<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['account_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

const LOGO_MAX_BYTES = 3 * 1024 * 1024; // 3 MB
$uploadDir = __DIR__ . '/uploads/site/';

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Which logo this upload is for - only used to prefix the stored filename.
$allowedFields = ['barangay_logo' => 'brgylogo', 'municipality_logo' => 'municilogo', 'site_logo' => 'sitelogo'];
$field = $_POST['field'] ?? '';
if (!isset($allowedFields[$field])) {
    echo json_encode(['success' => false, 'message' => 'Invalid logo field.']);
    exit;
}

if (!isset($_FILES['logo_file']) || $_FILES['logo_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error occurred.']);
    exit;
}

$file = $_FILES['logo_file'];

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
$filename = $allowedFields[$field] . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
$destination = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $destination)) {
    echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file.']);
    exit;
}

// Note: the database is intentionally NOT updated here. The filename is
// returned to the client, held in a hidden form field, and only written to
// tbl_settings when "Save Changes" is confirmed (see settingsSave.php).
// If the admin clicks Cancel instead, settingsLogoDelete.php removes this
// orphaned file from disk.
echo json_encode(['success' => true, 'data' => ['filename' => $filename]]);
