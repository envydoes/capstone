<?php
// nonresidentRoleChangeAction.php
// Saves profile data. If a new role is requested:
//   - IMMEDIATELY updates account_role_csv in tbl_userinfo AND account_role in tbl_useracc
//   - sets tbl_userinfo.userStatus = 'pending' (awaiting admin approval)
//   - stores the desired role in pending_role for audit trail
// If no role change is requested, profile fields are saved normally (status unchanged).

session_start();
require_once __DIR__ . '/../config/db_connection.php';

/* ─── Auth guard ─── */
if (!isset($_SESSION['user_id'], $_SESSION['acc_id'])) {
    header('Location: ../login.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: nonresidentEditProfile.php');
    exit;
}

$accId = $_SESSION['acc_id'];

/* ============================================================
   HELPERS
   ============================================================ */
function sanitizeText(string $v, int $max = 512): string {
    return mb_substr(preg_replace('/\s+/', ' ', trim(strip_tags($v))), 0, $max, 'UTF-8');
}
function isValidEmail(string $v): bool {
    return (bool) filter_var($v, FILTER_VALIDATE_EMAIL);
}
function isValidPHPhoneLoose(string $p): bool {
    return (bool) preg_match('/^(\+63|0)9\d{9}$/', preg_replace('/[\s\-()]/', '', $p));
}
function isValidIncome(string $v): bool {
    return $v === '' || (is_numeric($v) && (float)$v >= 0 && (float)$v <= 9_999_999);
}
function isValidYearsResident(string $v): bool {
    return $v === '' || (ctype_digit($v) && (int)$v >= 0 && (int)$v <= 120);
}
function redirect(string $status, string $msg = '', string $dest = 'nonresidentEditProfile.php'): never {
    $url = $dest . '?status=' . urlencode($status);
    if ($msg !== '') $url .= '&msg=' . urlencode($msg);
    header('Location: ' . $url);
    exit;
}

/* ============================================================
   ENSURE COLUMNS EXIST
   ============================================================ */
$conn->query("ALTER TABLE tbl_userinfo ADD COLUMN IF NOT EXISTS pending_role VARCHAR(100) DEFAULT '' AFTER account_role_csv");
$conn->query("ALTER TABLE tbl_userinfo ADD COLUMN IF NOT EXISTS emergency_contact_relationship VARCHAR(255) DEFAULT '' AFTER emergency_contact");
$conn->query("ALTER TABLE tbl_userinfo ADD COLUMN IF NOT EXISTS frontID VARCHAR(500) DEFAULT '' AFTER resident_birth");
$conn->query("ALTER TABLE tbl_userinfo ADD COLUMN IF NOT EXISTS backID  VARCHAR(500) DEFAULT '' AFTER frontID");

/* ============================================================
   READ CURRENT ROLE & STATUS FROM DB (source of truth)
   ============================================================ */
$currentRoleCsv = 'non-resident';
$currentStatus  = 'Active';
$curStmt = $conn->prepare('SELECT account_role_csv, userStatus FROM tbl_userinfo WHERE accID = ? LIMIT 1');
if ($curStmt) {
    $curStmt->bind_param('s', $accId);
    $curStmt->execute();
    $curStmt->bind_result($currentRoleCsv, $currentStatus);
    $curStmt->fetch();
    $curStmt->close();
}
$currentRoleCsv   = strtolower(trim($currentRoleCsv ?: 'non-resident'));
// Normalize role parts to handle whitespace
$currentRoleParts = array_map('trim', explode(',', $currentRoleCsv));
$currentRoleCsv   = implode(',', $currentRoleParts);
$isAlreadyPending = (strtolower($currentStatus) === 'pending');

/* ============================================================
   COLLECT & SANITIZE POST
   ============================================================ */
$selectedRole = strtolower(sanitizeText($_POST['selectedRole'] ?? 'non-resident', 100));
// Extra normalization: trim each role part in case of whitespace issues
$selectedRoleParts = array_map('trim', explode(',', $selectedRole));
$selectedRole = implode(',', $selectedRoleParts);

$allowedRoles = [
    'non-resident',
    'resident',
    'business/apartment owner',
    'non-resident,business/apartment owner',
    'resident,business/apartment owner',
];
if (!in_array($selectedRole, $allowedRoles, true)) {
    redirect('error', 'Invalid role selection: "' . htmlspecialchars($selectedRole) . '"');
}

/* ─── Determine primary role ─── */
$requestedParts    = array_map('trim', explode(',', $selectedRole));
$requestIsResident = in_array('resident', $requestedParts, true);
$isRoleChange      = ($selectedRole !== $currentRoleCsv);

// Debug: Log role change detection
error_log("Role Change Debug: Current='$currentRoleCsv' | Selected='$selectedRole' | IsChange=" . ($isRoleChange ? 'true' : 'false'));

/* ─── Always-present fields ─── */
$familyRole  = sanitizeText($_POST['family_role']  ?? '', 50);
$civilStatus = sanitizeText($_POST['civil_status'] ?? '', 50);
$religion    = sanitizeText($_POST['religion']     ?? '', 100);
$email       = sanitizeText($_POST['email']        ?? '', 255);
$phone       = sanitizeText($_POST['phone']        ?? '', 30);

$emContact      = sanitizeText($_POST['emergency_contact']              ?? '', 255);
$emRelationship = sanitizeText($_POST['emergency_contact_relationship'] ?? '', 100);
$emPhone        = sanitizeText($_POST['emergency_phone']               ?? '', 30);
$healthCond     = sanitizeText($_POST['health_conditions']             ?? '', 1000);

/* ─── Resident-specific fields (always save so data isn't lost) ─── */
$street         = sanitizeText($_POST['street']            ?? '', 255);
$barangay       = sanitizeText($_POST['barangay']          ?? '', 100);
$city           = sanitizeText($_POST['city']              ?? '', 100);
$province       = sanitizeText($_POST['province']          ?? '', 100);
$zip            = sanitizeText($_POST['zip']               ?? '', 20);
$employmentStat = sanitizeText($_POST['employment_status'] ?? '', 50);
$jobTitle       = sanitizeText($_POST['job_title']         ?? '', 100);
$monthlyIncome  = sanitizeText($_POST['monthly_income']    ?? '', 20);
$yearsResident  = sanitizeText($_POST['years_resident']    ?? '', 5);
$voterId        = sanitizeText($_POST['voter_id']          ?? '', 100);
$precinct       = sanitizeText($_POST['precinct']          ?? '', 100);
$residentBirth  = isset($_POST['resident_birth']) ? '1' : '0';

/* ============================================================
   VALIDATION
   ============================================================ */

/* ─── Always required ─── */
if (empty($familyRole))  redirect('error', 'Family role is required.');
if (empty($civilStatus)) redirect('error', 'Civil status is required.');
if (!isValidEmail($email)) redirect('error', 'A valid email address is required.');
if (!empty($phone)   && !isValidPHPhoneLoose($phone))   redirect('error', 'Phone must be a valid PH number (e.g. 09XXXXXXXXX).');
if (!empty($emPhone) && !isValidPHPhoneLoose($emPhone)) redirect('error', 'Emergency phone must be a valid PH number.');

/* ─── Resident-specific required fields ─── */
if ($requestIsResident) {
    if (empty($street))   redirect('error', 'Street address is required for Resident role.');
    if (empty($barangay)) redirect('error', 'Barangay is required for Resident role.');
    if (empty($city))     redirect('error', 'City / Municipality is required for Resident role.');
    if (empty($province)) redirect('error', 'Province is required for Resident role.');
    if (empty($zip))      redirect('error', 'ZIP code is required for Resident role.');
    if (empty($employmentStat)) redirect('error', 'Employment status is required for Resident role.');
    if (!empty($yearsResident) && !isValidYearsResident($yearsResident))
        redirect('error', 'Years as resident must be between 0 and 120.');
    if (!isValidIncome($monthlyIncome))
        redirect('error', 'Monthly income must be between 0 and 9,999,999.');
}

/* ============================================================
   FILE UPLOAD - ID images (required when requesting resident role)
   ============================================================ */
$frontIDPath = '';
$backIDPath  = '';

// Fetch existing IDs from DB so we can check if they're already present
$existingFrontID = '';
$existingBackID  = '';
$idStmt = $conn->prepare('SELECT frontID, backID FROM tbl_userinfo WHERE accID = ? LIMIT 1');
if ($idStmt) {
    $idStmt->bind_param('s', $accId);
    $idStmt->execute();
    $idStmt->bind_result($existingFrontID, $existingBackID);
    $idStmt->fetch();
    $idStmt->close();
}

if ($requestIsResident) {
    $uploadDir = __DIR__ . '/../uploads/id_verification/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $allowedMime = ['image/jpeg', 'image/png', 'application/pdf'];
    $maxBytes    = 5 * 1024 * 1024;

    foreach (['id_front' => 'frontIDPath', 'id_back' => 'backIDPath'] as $field => $var) {
        if (!empty($_FILES[$field]['tmp_name']) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
            $tmp  = $_FILES[$field]['tmp_name'];
            $size = $_FILES[$field]['size'];
            $mime = mime_content_type($tmp);

            if (!in_array($mime, $allowedMime, true)) redirect('error', 'ID images must be JPG, PNG, or PDF.');
            if ($size > $maxBytes)                    redirect('error', 'Each ID file must be under 5 MB.');

            $ext      = ($mime === 'application/pdf') ? 'pdf' : 'jpg';
            $filename = $accId . '_' . $field . '_' . time() . '.' . $ext;
            if (!move_uploaded_file($tmp, $uploadDir . $filename)) redirect('error', 'Failed to save uploaded ID file.');
            $$var = $filename;
        }
    }

    // If requesting resident role for the first time, both IDs are required
    if ($isRoleChange) {
        $hasFront = !empty($frontIDPath) || !empty($existingFrontID);
        $hasBack  = !empty($backIDPath)  || !empty($existingBackID);
        if (!$hasFront) redirect('error', 'Front ID image is required when requesting Resident role.');
        if (!$hasBack)  redirect('error', 'Back ID image is required when requesting Resident role.');
    }
}

/* ============================================================
   DETERMINE REDIRECT DESTINATION
   ============================================================ */
// After save: go to residentProfile if new/current role is resident, else nonResidentProfile
$primaryRole  = $requestIsResident ? 'resident' : 'non-resident';
$redirectDest = $requestIsResident
    ? '../resident/residentProfile.php'
    : 'nonresidentProfile.php';

/* ============================================================
   BUILD UPDATE FIELDS FOR tbl_userinfo
   ============================================================ */
$fields = [
    'family_role'                    => $familyRole,
    'civil_status'                   => $civilStatus,
    'religion'                       => $religion,
    'email'                          => $email,
    'phone'                          => $phone,
    'emergency_contact'              => $emContact,
    'emergency_contact_relationship' => $emRelationship,
    'emergency_phone'                => $emPhone,
    'health_conditions'              => $healthCond,
    'street'                         => $street,
    'barangay'                       => $barangay,
    'city'                           => $city,
    'province'                       => $province,
    'zip'                            => $zip,
    'employment_status'              => $employmentStat,
    'job_title'                      => $jobTitle,
    'monthly_income'                 => $monthlyIncome,
    'years_resident'                 => $yearsResident,
    'voter_id'                       => $voterId,
    'precinct'                       => $precinct,
    'resident_birth'                 => $residentBirth,
];

if ($frontIDPath !== '') $fields['frontID'] = $frontIDPath;
if ($backIDPath  !== '') $fields['backID']  = $backIDPath;

if ($isRoleChange) {
    // IMMEDIATELY update account_role_csv to the new role
    // but keep status as 'pending' so admin still reviews the change
    $fields['account_role_csv'] = $selectedRole;
    $fields['pending_role']     = $selectedRole;  // audit trail
    $fields['userStatus']       = 'pending';
} else {
    // Plain profile save - clear stale pending_role if not already pending
    if (!$isAlreadyPending) {
        $fields['pending_role'] = '';
    }
}

/* ─── Build parameterized UPDATE for tbl_userinfo ─── */
$setClauses = implode(' = ?, ', array_keys($fields)) . ' = ?, last_verified_at = NOW()';
$values     = array_values($fields);
$types      = str_repeat('s', count($values)) . 's'; // +1 for accID WHERE clause
$values[]   = $accId;

$sql  = "UPDATE tbl_userinfo SET {$setClauses} WHERE accID = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) redirect('error', 'DB prepare error: ' . $conn->error);

if (!$stmt->bind_param($types, ...$values)) {
    redirect('error', 'Failed to bind parameters: ' . $stmt->error);
}
if (!$stmt->execute()) {
    redirect('error', 'Failed to update profile: ' . $stmt->error);
}
$stmt->close();

/* ============================================================
   IF ROLE CHANGED: also update tbl_useracc.account_role immediately
   ============================================================ */
if ($isRoleChange) {
    // Store the FULL combined role string as-is — do not collapse it.
    // $selectedRole is already lowercased, trimmed, and validated against
    // $allowedRoles above, so it's safe to use directly.
    //
    // Previously this was passed through an $accRoleMap that collapsed
    // combined roles like "non-resident,business/apartment owner" down to
    // just "non-resident", silently dropping the Owner status from
    // tbl_useracc AND from the live session. That meant anywhere reading
    // $_SESSION['account_role'] (e.g. the "Post Listing" nav link check)
    // never saw the requested Owner role, even though tbl_userinfo's
    // account_role_csv correctly stored the full combination.
    $newAccRole = $selectedRole;

    $accStmt = $conn->prepare('UPDATE tbl_useracc SET account_role = ? WHERE accID = ?');
    if ($accStmt) {
        $accStmt->bind_param('ss', $newAccRole, $accId);
        $accStmt->execute();
        $accStmt->close();
    }

    // Update session to reflect the new role immediately
    $_SESSION['account_role']   = $newAccRole;
    $_SESSION['account_status'] = 'pending';
}

/* ============================================================
   DETERMINE SUCCESS STATUS & REDIRECT
   ============================================================ */
if ($isRoleChange) {
    // Redirect to the appropriate profile page with a toast param
    $profileDest = $requestIsResident
        ? '../resident/residentProfile.php?toast=role_changed'
        : 'nonresidentProfile.php?toast=role_changed';
    header('Location: ' . $profileDest);
    exit;
} else {
    // Same role, just profile data saved - go back to edit page with success
    redirect('profile_saved');
}