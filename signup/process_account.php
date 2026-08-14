<?php

session_start();
include "../config/db_connection.php";

if (!isset($_SESSION['start_time'])) {
    $_SESSION['start_time'] = time();
}

// ?? Helper: redirect back with error ?????????????????????????????????????????
function failBack(string $field, string $message): never {
    $_SESSION['reg_error_field']   = $field;
    $_SESSION['reg_error_message'] = $message;
    $_SESSION['reg_old_email']     = trim($_POST['email'] ?? '');
    $_SESSION['reg_old_role']      = $_POST['account_role'] ?? [];
    header('Location: accountCreation.php');
    exit;
}

// ?? Helper: commonly used / frequently-breached passwords ??????????????????????
// Kept in sync with the COMMON_PASSWORDS set in accountCreation.php's client-side JS.
// This is the check that actually matters, since the JS one can be bypassed.
function isCommonPassword(string $pw): bool {
    static $commonPasswords = [
        'password','12345678','123456789','1234567890','qwerty','qwerty123','qwertyuiop',
        'abc123','abc12345','password1','password12','password123','password1234',
        'letmein','letmein123','welcome','welcome123','admin','admin123','root','toor',
        'iloveyou','monkey','dragon','football','football1','baseball','basketball',
        'starwars','superman','master','sunshine','princess','shadow','freedom','trustno1',
        'whatever','solo','passw0rd','p@ssw0rd','1q2w3e4r','zxcvbnm','asdfghjkl',
        '123123','111111','000000','666666','696969','654321','987654321',
        'changeme','mypassword','loveme','ashley','jennifer','jessica','michael',
        'jordan','hunter2','access','yankees','mustang','ninja','azerty',
    ];

    $lower = strtolower($pw);
    if (in_array($lower, $commonPasswords, true)) {
        return true;
    }

    // Catch variants like "Password1234!" by stripping leading/trailing digits & symbols
    $stripped = preg_replace('/^[^a-z]+|[^a-z]+$/', '', $lower);
    if (strlen($stripped) >= 4 && in_array($stripped, $commonPasswords, true)) {
        return true;
    }

    return false;
}

// ?? 1. Method guard ???????????????????????????????????????????????????????????
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    session_destroy();
    header('Location: accountCreation.php');
    exit;
}

// ?? 2. Collect inputs ?????????????????????????????????????????????????????????
$rawEmail    = trim($_POST['email']           ?? '');
$rawPassword = $_POST['password']             ?? '';   // spaces valid in passwords
$rawConfirm  = $_POST['confirm_password']     ?? '';
$rawRoles    = (array)($_POST['account_role'] ?? []);

// ?? 3. Email validation ???????????????????????????????????????????????????????
if (empty($rawEmail))
    failBack('email', 'Email address is required.');
if (strlen($rawEmail) > 254)
    failBack('email', 'Email address is too long.');
if (!filter_var($rawEmail, FILTER_VALIDATE_EMAIL))
    failBack('email', 'Please enter a valid email address.');

// ?? 4. Password rules 
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
if (!preg_match('/[^A-Za-z0-9]/', $rawPassword))
    failBack('password', 'Password must include at least one special character (e.g. !@#$%^&*).');

// ?? 5. Reject commonly used / breached passwords 
// Enforced here (not just client-side) since the JS check is trivially bypassed.
if (isCommonPassword($rawPassword))
    failBack('password', 'This password is too common and not allowed (e.g. "password1234"). Please choose something more unique.');

// ?? 6. Password must not match or leak the email (PII) 
if (strtolower($rawPassword) === strtolower($rawEmail))
    failBack('password', 'Your password must not be the same as your email address.');

$emailParts     = explode('@', $rawEmail);
$emailLocalPart = strtolower($emailParts[0] ?? '');
$emailDomainRaw = $emailParts[1] ?? '';
$emailDomainPart = strtolower(explode('.', $emailDomainRaw)[0] ?? '');

if (strlen($emailLocalPart) >= 4 && str_contains(strtolower($rawPassword), $emailLocalPart))
    failBack('password', 'Your password is too similar to your email. Please choose a different password.');
if (strlen($emailDomainPart) >= 4 && str_contains(strtolower($rawPassword), $emailDomainPart))
    failBack('password', 'Your password should not contain parts of your email address.');

// ?? 7. Confirm password 
if (empty($rawConfirm))
    failBack('confirm_password', 'Please confirm your password.');
if ($rawPassword !== $rawConfirm)
    failBack('confirm_password', 'Passwords do not match.');

// ?? 8. Role validation ????????????????????????????????????????????????????????
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

// ?? 9. Check email uniqueness in DB ??????????????????????????????????????????
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

// ?? 10. All checks passed - save to session (NO DB insert here) ???????????????
// finalizeRegistration.php reads these session values and does the actual INSERT.
$_SESSION['email']        = $rawEmail;
$_SESSION['password']     = $rawPassword;          // plain text - finalizeRegistration.php hashes it
$_SESSION['account_role'] = $normalisedRoles;      // array e.g. ['business']

// Clean up error state
unset($_SESSION['reg_error_field'], $_SESSION['reg_error_message'],
      $_SESSION['reg_old_email'],   $_SESSION['reg_old_role']);

// ?? 11. Proceed to Step 2 ?????????????????????????????????????????????????????
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