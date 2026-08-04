<?php
session_start();

function redirectWithError(string $message): void
{
    header('Location: verification.php?status=error&message=' . urlencode($message));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    session_destroy();
    header('Location: accountCreation.php');
    exit;
}

$timeout = 30 * 60;
if (!isset($_SESSION['start_time']) || (time() - (int)$_SESSION['start_time']) > $timeout) {
    session_destroy();
    header('Location: accountCreation.php?error=session_expired');
    exit;
}

if (!isset($_FILES['id_front'], $_FILES['id_back'])) {
    redirectWithError('Please upload both front and back ID images.');
}

$allowedTypes = [
    'image/jpeg',
    'image/jpg',
    'image/pjpeg',
    'image/png',
    'image/x-png',
    'application/pdf',
];
$maxBytes = 5 * 1024 * 1024;

$targetDir = dirname(__DIR__) . '/uploads/id_verification';
if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
    redirectWithError('Unable to prepare upload storage.');
}

$safeSession = preg_replace('/[^a-zA-Z0-9_-]/', '', session_id()) ?: 'guest';
$savedFiles = [];

foreach (['front' => $_FILES['id_front'], 'back' => $_FILES['id_back']] as $side => $file) {
    $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        redirectWithError('Failed to upload ' . $side . ' ID file.');
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > $maxBytes) {
        redirectWithError('The ' . $side . ' ID file must be up to 5MB.');
    }

    $tmpPath = (string)($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        redirectWithError('The uploaded ' . $side . ' ID file is invalid.');
    }

    $mimeType = mime_content_type($tmpPath) ?: '';
    if (!in_array($mimeType, $allowedTypes, true)) {
        redirectWithError('Invalid ' . $side . ' ID format. Allowed: JPG, PNG, PDF.');
    }

    $originalName = (string)($file['name'] ?? ($side . '.bin'));
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension === '') {
        $extension = 'bin';
    }

    $targetPath = $targetDir . '/' . $safeSession . '_' . $side . '.' . $extension;
    if (!move_uploaded_file($tmpPath, $targetPath)) {
        redirectWithError('Failed to save uploaded ' . $side . ' ID file.');
    }

    $savedFiles[$side] = [
        'path' => $targetPath,
        'name' => basename($targetPath),
    ];
}

$_SESSION['saved_id_upload'] = $savedFiles;


header('Location: session_data.php');
exit;
