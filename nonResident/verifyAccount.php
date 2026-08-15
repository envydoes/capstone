<?php
/**
 * nonResident/verifyAccount.php
 * ------------------------------------------------------------
 * Handles the "Yes, still active" button from
 * includes/verification_modal.php - stamps
 * tbl_userinfo.last_verified_at = NOW() for the logged-in user,
 * then redirects back to whichever page the modal was shown on.
 *
 * This is a folder-local twin of resident/verifyAccount.php - the
 * modal's form always POSTs to a same-folder file, so each section
 * of the site keeps its own copy rather than reaching across folders.
 * ------------------------------------------------------------
 */

session_start();
require_once __DIR__ . '/../config/db_connection.php';

if (!isset($_SESSION['user_id'], $_SESSION['acc_id'])) {
    header('Location: ../login.php');
    exit;
}

$accId = $_SESSION['acc_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $conn->prepare('UPDATE tbl_userinfo SET last_verified_at = NOW() WHERE accID = ?');
    if ($stmt) {
        $stmt->bind_param('s', $accId);
        $stmt->execute();
        $stmt->close();
    }
}

// Only allow redirecting back to a plain same-folder filename - never
// an absolute/external URL - so this can't be turned into an open redirect.
$redirectTo = isset($_POST['redirect_to']) ? basename((string) $_POST['redirect_to']) : '';
if ($redirectTo === '' || !preg_match('/^[A-Za-z0-9_\-]+\.php$/', $redirectTo)) {
    $redirectTo = 'nonresidentLanding.php';
}

header('Location: ' . $redirectTo);
exit;