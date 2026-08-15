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
    $oldRoles = [];
    if (!empty($_POST['residency_type'])) $oldRoles[] = $_POST['residency_type'];
    if (!empty($_POST['is_owner']))       $oldRoles[] = $_POST['is_owner'];
    $_SESSION['reg_old_role'] = $oldRoles;
    header('Location: accountCreation.php');
    exit;
}

// ?? Helper: commonly used / frequently-breached passwords ??????????????????????
// Kept in sync with the COMMON_PASSWORDS set in accountCreation.php's client-side JS.
// This is the check that actually matters, since the JS one can be bypassed.
function isCommonPassword(string $pw): bool {
    // Kept byte-for-byte in sync with COMMON_PASSWORDS in accountCreation.php's
    // client-side JS — same list, same order, so client and server never disagree.
    static $commonPasswords = [
        'password','12345678','123456789','1234567890','123456','1234567','12345',
        'qwerty','qwerty123','qwertyuiop','qazwsx','1q2w3e4r','1qaz2wsx','zxcvbnm','zxcvbn',
        'abc123','abc12345','password1','password12','password123','password1234','passw0rd','p@ssw0rd',
        'letmein','letmein123','welcome','welcome1','welcome123',
        'admin','admin123','admin1234','root','toor','login','default','changeme','changeme123',
        'iloveyou','iloveyou1','monkey','dragon','dragon123',
        'football','football1','football123','baseball','baseball1','basketball','soccer','hockey',
        'starwars','superman','master','sunshine','princess','shadow','freedom','trustno1',
        'whatever','solo','1q2w3e4r5t','asdf1234','asdfghjkl',
        '123123','111111','000000','666666','696969','654321','987654321','121212','112233',
        '11111111','00000000','87654321','12341234','1122334455','aaaaaaaa','abcdefgh','abcd1234',
        'mypassword','loveme','ashley','jennifer','jessica','michael','michelle','charlie','donald',
        'jordan','hunter2','access','yankees','mustang','ninja','azerty',
        'test1234','temp1234','tinkerbell','liverpool','chelsea','arsenal','flower','hottie','biteme',
        'q1w2e3r4','1q2w3e4r5t6y','google','facebook','instagram','snapchat','tinder',
        '123qwe','000000000','111111111',
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

// ?? Helper: check password against Have I Been Pwned's breached-password DB ????
// Uses k-anonymity: only the first 5 hex chars of the SHA-1 hash are ever sent
// over the network, never the password itself and never the full hash.
// Fails OPEN (returns false / "not flagged") if the API can't be reached, so an
// outage on HIBP's end never blocks someone from registering. The local
// isCommonPassword() list above still applies regardless of this check.
function isPwnedPassword(string $pw): bool {
    $sha1   = strtoupper(sha1($pw));
    $prefix = substr($sha1, 0, 5);
    $suffix = substr($sha1, 5);

    $ch = curl_init("https://api.pwnedpasswords.com/range/{$prefix}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_HTTPHEADER     => ['User-Agent: BarangayApp-PasswordCheck'],
    ]);
    $response = curl_exec($ch);
    $hadError = curl_errno($ch) !== 0;
    curl_close($ch);

    if ($hadError || $response === false || $response === '') {
        return false; // API unreachable — don't block registration on this check
    }

    foreach (explode("\r\n", trim($response)) as $line) {
        $parts = explode(':', $line);
        if (isset($parts[0]) && $parts[0] === $suffix) {
            return true;
        }
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

// The form sends residency status as a single radio ("residency_type") and
// business/apartment ownership as a separate optional checkbox ("is_owner") —
// combine them into the same $rawRoles shape the rest of this script expects.
$rawRoles = [];
if (!empty($_POST['residency_type'])) {
    $rawRoles[] = $_POST['residency_type'];
}
if (!empty($_POST['is_owner'])) {
    $rawRoles[] = $_POST['is_owner'];
}

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

// ?? 5b. Reject passwords found in known data breaches (Have I Been Pwned) ??????
if (isPwnedPassword($rawPassword))
    failBack('password', 'This password has appeared in known data breaches. Please choose a different password.');

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