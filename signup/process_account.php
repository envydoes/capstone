<?php

session_start();
include "../config/db_connection.php";

if (!isset($_SESSION['start_time'])) {
    $_SESSION['start_time'] = time();
}

// ── Helper: redirect back with error ─────────────────────────────────────────
function failBack(string $field, string $message): never {
    $_SESSION['reg_error_field']   = $field;
    $_SESSION['reg_error_message'] = $message;
    $_SESSION['reg_old_email']     = trim($_POST['email'] ?? '');
    $_SESSION['reg_old_role']      = $_POST['account_role'] ?? [];
    header('Location: accountCreation.php');
    exit;
}

// ── 1. Method guard ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    session_destroy();
    header('Location: accountCreation.php');
    exit;
}

// ── 2. Collect inputs ─────────────────────────────────────────────────────────
$rawEmail    = trim($_POST['email']           ?? '');
$rawPassword = $_POST['password']             ?? '';   // spaces valid in passwords
$rawConfirm  = $_POST['confirm_password']     ?? '';
$rawRoles    = (array)($_POST['account_role'] ?? []);

// ── 3. Email validation ───────────────────────────────────────────────────────
if (empty($rawEmail))
    failBack('email', 'Email address is required.');
if (strlen($rawEmail) > 254)
    failBack('email', 'Email address is too long.');
if (!filter_var($rawEmail, FILTER_VALIDATE_EMAIL))
    failBack('email', 'Please enter a valid email address.');

// ── 4. Password rules ─────────────────────────────────────────────────────────
if (empty($rawPassword))
    failBack('password', 'Password is required.');
if (strlen($rawPassword) < 8)
    failBack('password', 'Password must be at least 8 characters.');
if (strlen($rawPassword) > 128)
    failBack('password', 'Password must not exceed 128 characters.');
if (!preg_match('/[A-Z]/', $rawPassword))
    failBack('password', 'Password must include at least one uppercase letter.');
if (!preg_match('/[a-z]/', $rawPassword))
    failBack('password', 'Password must include at least one lowercase letter.');
if (!preg_match('/[0-9]/', $rawPassword))
    failBack('password', 'Password must include at least one number.');

// ── 5. Password must not match or be too similar to email ─────────────────────
if (strtolower($rawPassword) === strtolower($rawEmail))
    failBack('password', 'Your password must not be the same as your email address.');

$emailLocalPart = strtolower(explode('@', $rawEmail)[0]);
if (strlen($emailLocalPart) >= 4 && str_contains(strtolower($rawPassword), $emailLocalPart))
    failBack('password', 'Your password is too similar to your email. Please choose a different password.');

// ── 6. Confirm password ───────────────────────────────────────────────────────
if (empty($rawConfirm))
    failBack('confirm_password', 'Please confirm your password.');
if ($rawPassword !== $rawConfirm)
    failBack('confirm_password', 'Passwords do not match.');

// ── 7. Role validation ────────────────────────────────────────────────────────
$allowedRoles = ['resident', 'non-resident', 'business/apartment owner'];
$rawRoles     = array_values(array_filter($rawRoles, fn($r) => in_array($r, $allowedRoles, true)));

if (empty($rawRoles))
    failBack('role', 'Please select at least one account role.');
if (in_array('resident', $rawRoles, true) && in_array('non-resident', $rawRoles, true))
    failBack('role', 'You cannot be both a resident and a non-resident.');
if (count($rawRoles) > 2)
    failBack('role', 'Invalid role combination.');

// Map roles to normalized value for storage and redirect decisions
$roleMap = ['resident' => 'resident', 'non-resident' => 'non-resident', 'business/apartment owner' => 'business/apartment owner'];
$normalisedRoles = array_map(fn($r) => $roleMap[$r], $rawRoles);

// ── 8. Check email uniqueness in DB ──────────────────────────────────────────
// (finalizeRegistration.php will also check, but catching it early is better UX)
$stmt = $conn->prepare('SELECT 1 FROM tbl_useracc WHERE email = ? LIMIT 1');
if (!$stmt) failBack('email', 'A server error occurred. Please try again.');

$stmt->bind_param('s', $rawEmail);
$stmt->execute();
$stmt->store_result();
$alreadyExists = $stmt->num_rows > 0;
$stmt->close();

if ($alreadyExists)
    failBack('email', 'This email address is already registered. Please log in or use a different email.');

// ── 9. All checks passed — save to session (NO DB insert here) ────────────────
// finalizeRegistration.php reads these session values and does the actual INSERT.
$_SESSION['email']        = $rawEmail;
$_SESSION['password']     = $rawPassword;          // plain text — finalizeRegistration.php hashes it
$_SESSION['account_role'] = $normalisedRoles;      // array e.g. ['business']

// Clean up error state
unset($_SESSION['reg_error_field'], $_SESSION['reg_error_message'],
      $_SESSION['reg_old_email'],   $_SESSION['reg_old_role']);

// ── 10. Proceed to Step 2 ─────────────────────────────────────────────────────
if (in_array('resident', $normalisedRoles, true)) {
    header('Location: residentProfile.php');
} elseif (in_array('non-resident', $normalisedRoles, true) || in_array('business/apartment owner', $normalisedRoles, true)) {
    // Non-resident and business accounts both use this flow
    header('Location: nonResidentProfile.php');
} else {
    // No valid role, redirect back with error
    header('Location: accountCreation.php?error=no_role');
}
exit;