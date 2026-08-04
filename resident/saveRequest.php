<?php
/**
 * Document Request Save Script
 * Saves document requests from documentsForm.php to tbl_requestDocs
 */

// ── Guard: session must already be started by the parent page ────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Guard: user must be logged in ───────────────────────────────────────────
if (empty($_SESSION['user_id']) || empty($_SESSION['acc_id'])) {
    $_SESSION['document_save_status'] = 'error';
    $_SESSION['document_save_msg']    = 'Not authenticated.';
    header('Location: ../login.php');
    exit;
}

// ── Guard: form data must exist in session ──────────────────────────────────
if (empty($_SESSION['document_form']) || !is_array($_SESSION['document_form'])) {
    $_SESSION['document_save_status'] = 'error';
    $_SESSION['document_save_msg']    = 'No form data to save.';
    header('Location: documentsForm.php');
    exit;
}

// ── Database connection ──────────────────────────────────────────────────────
$host     = 'localhost';
$dbUser   = 'root';
$dbPass   = '';
$database = 'sumeste_db';

$conn = mysqli_connect($host, $dbUser, $dbPass, $database);
if (!$conn) {
    // Clean up any uploaded files on DB failure
    cleanUpFiles($_SESSION['document_form']['uploaded_files'] ?? []);
    $_SESSION['document_save_status'] = 'error';
    $_SESSION['document_save_msg']    = 'DB connection failed: ' . mysqli_connect_error();
    header('Location: documentsForm.php');
    exit;
}
mysqli_set_charset($conn, 'utf8mb4');

/**
 * Remove uploaded files from disk if save fails.
 */
function cleanUpFiles(array $files): void {
    $uploadDir = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/uploads/document_requests/';
    if (!is_dir($uploadDir)) {
        $uploadDir = dirname(__DIR__) . '/uploads/document_requests/';
    }
    foreach ($files as $fname) {
        $path = $uploadDir . basename($fname);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

// ── Pull & sanitise session data ─────────────────────────────────────────────
$f      = $_SESSION['document_form'];
$accId  = trim($_SESSION['acc_id'] ?? '');
$userId = 0;

// Resolve userId from accId (primary)
if ($accId !== '') {
    $accStmt = $conn->prepare('SELECT userID FROM tbl_userinfo WHERE accID = ? LIMIT 1');
    if ($accStmt) {
        $accStmt->bind_param('s', $accId);
        $accStmt->execute();
        $accStmt->bind_result($resolvedUserId);
        if ($accStmt->fetch()) {
            $userId = (int)$resolvedUserId;
        }
        $accStmt->close();
    }
}

// Fallback: resolve via email stored in session
if ($userId <= 0 && !empty($_SESSION['user_id'])) {
    $rawEmail  = trim($_SESSION['user_id']);
    $emailStmt = $conn->prepare('SELECT userID FROM tbl_userinfo WHERE email = ? LIMIT 1');
    if ($emailStmt) {
        $emailStmt->bind_param('s', $rawEmail);
        $emailStmt->execute();
        $emailStmt->bind_result($resolvedUserId);
        if ($emailStmt->fetch()) {
            $userId = (int)$resolvedUserId;
        }
        $emailStmt->close();
    }
}

if ($userId <= 0) {
    cleanUpFiles($f['uploaded_files'] ?? []);
    $_SESSION['document_save_status'] = 'error';
    $_SESSION['document_save_msg']    = 'Invalid user identity. Please log in again.';
    mysqli_close($conn);
    header('Location: documentsForm.php');
    exit;
}

// Verify user exists in tbl_userinfo
$checkStmt = $conn->prepare('SELECT 1 FROM tbl_userinfo WHERE userID = ? LIMIT 1');
if (!$checkStmt) {
    cleanUpFiles($f['uploaded_files'] ?? []);
    $_SESSION['document_save_status'] = 'error';
    $_SESSION['document_save_msg']    = 'Prepare failed (user check): ' . $conn->error;
    mysqli_close($conn);
    header('Location: documentsForm.php');
    exit;
}
$checkStmt->bind_param('i', $userId);
$checkStmt->execute();
$checkStmt->store_result();
if ($checkStmt->num_rows === 0) {
    cleanUpFiles($f['uploaded_files'] ?? []);
    $_SESSION['document_save_status'] = 'error';
    $_SESSION['document_save_msg']    = 'User account not found. Please contact support.';
    $checkStmt->close();
    mysqli_close($conn);
    header('Location: documentsForm.php');
    exit;
}
$checkStmt->close();

// ── Sanitise helper closures ──────────────────────────────────────────────────
$str = static function (string $key, int $maxLen = 255) use ($f): string {
    $val = isset($f[$key]) ? trim((string)$f[$key]) : '';
    return substr($val, 0, $maxLen);
};

$int = static function (string $key) use ($f): int {
    return max(0, (int)($f[$key] ?? 0));
};

// Validate submitted_at or fall back to NOW
$submittedAt = null;
if (!empty($f['submitted_at'])) {
    $ts = strtotime($f['submitted_at']);
    if ($ts !== false) {
        $submittedAt = date('Y-m-d H:i:s', $ts);
    }
}
$submittedAt = $submittedAt ?? date('Y-m-d H:i:s');

// ── Encode uploaded files as JSON ─────────────────────────────────────────────
// Store only filenames (not full paths) — path is always /uploads/document_requests/
$uploadedFilesJson = null;
if (!empty($f['uploaded_files']) && is_array($f['uploaded_files'])) {
    $cleanFiles = array_values(array_filter(array_map('basename', $f['uploaded_files'])));
    if (!empty($cleanFiles)) {
        $uploadedFilesJson = json_encode($cleanFiles, JSON_UNESCAPED_UNICODE);
    }
}

// ── Build data array ──────────────────────────────────────────────────────────
$data = [
    'document_type'   => $str('document_type', 50),
    'num_copies'      => $int('num_copies'),
    'purpose'         => $str('purpose', 100),
    'notes'           => $str('notes', 1000),
    'uploaded_files'  => $uploadedFilesJson,
    'status'          => 'pending',
    'submitted_at'    => $submittedAt,
];

// ── INSERT new document request ───────────────────────────────────────────────
$sqlInsert = '
    INSERT INTO tbl_requestDocs (
        userId,
        document_type,
        num_copies,
        purpose,
        notes,
        uploaded_files,
        status,
        submitted_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
';

$stmt = $conn->prepare($sqlInsert);
if (!$stmt) {
    cleanUpFiles($f['uploaded_files'] ?? []);
    $_SESSION['document_save_status'] = 'error';
    $_SESSION['document_save_msg']    = 'Prepare failed (insert): ' . $conn->error;
    mysqli_close($conn);
    header('Location: documentsForm.php');
    exit;
}

$stmt->bind_param(
    'isisssss',
    $userId,
    $data['document_type'],
    $data['num_copies'],
    $data['purpose'],
    $data['notes'],
    $data['uploaded_files'],
    $data['status'],
    $data['submitted_at']
);

// ── Execute & report ──────────────────────────────────────────────────────────
if ($stmt->execute()) {
    $_SESSION['document_save_status'] = 'ok';
    $_SESSION['document_save_msg']    = 'Document request submitted successfully.';

    // Clear form data — prevents double-save on page refresh
    unset($_SESSION['document_form']);

} else {
    // Save failed — remove uploaded files so they don't become orphans
    cleanUpFiles($f['uploaded_files'] ?? []);
    $_SESSION['document_save_status'] = 'error';
    $_SESSION['document_save_msg']    = 'Could not save your request. Please try again.';
}

$stmt->close();
mysqli_close($conn);

header('Location: documentsForm.php');
exit;