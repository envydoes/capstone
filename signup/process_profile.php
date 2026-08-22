<?php
session_start();

// Check for session timeout (30 minutes)
$timeout = 30 * 60; // 30 minutes in seconds
if (!isset($_SESSION['start_time']) || (time() - $_SESSION['start_time']) > $timeout) {
    session_destroy();
    header('Location: accountCreation.php?error=session_expired');
    exit();
}

// Method guard - reset session if not POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    session_destroy();
    header('Location: accountCreation.php');
    exit();
}

// Append all POST data to session
$_SESSION = array_merge($_SESSION, $_POST);

// Redirect to verification
header('Location: verification.php');
exit();
?>
