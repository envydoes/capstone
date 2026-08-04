<?php
/**
 * residentUpdate.php
 * Receives JSON POST, validates, updates tbl_userinfo,
 * and stores the updated record in $_SESSION['resident_updates'].
 */
session_start();
header('Content-Type: application/json');

// â”€â”€ Auth guard â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// â”€â”€ DB connection â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$conn = mysqli_connect('o7jpqmin0zgconui4xtnfju6', 'root', 'UKkJ05DHQDMMMOxFEUI5f1HJGVj8Vb5gfJAEvAESTGCVWDtFEGb42qX67AxGUXvj', 'sumeste_db');
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}

// â”€â”€ Permission check â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
require_once __DIR__ . '/../includes/check_permissions.php';
require_permission_ajax($conn, 'manage_residents');

// â”€â”€ Read JSON body â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || empty($data['userID'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid payload']);
    exit;
}

// â”€â”€ Sanitize helper â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function clean($v) {
    return trim(strip_tags($v ?? ''));
}

// â”€â”€ Whitelist & cast every expected field â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$userID            = (int) $data['userID'];
$firstname         = clean($data['firstname']         ?? '');
$lastname          = clean($data['lastname']          ?? '');
$middlename        = clean($data['middlename']        ?? '');
$suffix            = clean($data['suffix']            ?? '');
$family_role       = clean($data['family_role']       ?? '');
$gender            = clean($data['gender']            ?? '');
$birthday          = clean($data['birthday']          ?? '');
$birthplace        = clean($data['birthplace']        ?? '');
$civil_status      = clean($data['civil_status']      ?? '');
$citizenship       = clean($data['citizenship']       ?? '');
$religion          = clean($data['religion']          ?? '');
$ethnicity         = clean($data['ethnicity']         ?? '');
$street            = clean($data['street']            ?? '');
$barangay          = clean($data['barangay']          ?? '');
$city              = clean($data['city']              ?? '');
$province          = clean($data['province']          ?? '');
$zip               = clean($data['zip']               ?? '');
$phone             = clean($data['phone']             ?? '');
$email             = filter_var(trim($data['email'] ?? ''), FILTER_SANITIZE_EMAIL);
$emergency_contact = clean($data['emergency_contact'] ?? '');
$emergency_phone   = clean($data['emergency_phone']   ?? '');
$health_conditions = clean($data['health_conditions'] ?? '');
$employment_status = clean($data['employment_status'] ?? '');
$job_title         = clean($data['job_title']         ?? '');
$voter_id          = clean($data['voter_id']          ?? '');
$precinct          = clean($data['precinct']          ?? '');
$years_resident    = is_numeric($data['years_resident'] ?? '') ? (int)$data['years_resident'] : 0;
$resident_birth    = in_array((string)($data['resident_birth'] ?? ''), ['0','1']) ? (int)$data['resident_birth'] : 0;

$monthly_income = '';
if (isset($data['monthly_income']) && $data['monthly_income'] !== '' && is_numeric($data['monthly_income'])) {
    $monthly_income = (string)(float)$data['monthly_income'];
}

// â”€â”€ Basic required-field validation â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$required = compact(
    'firstname','lastname','family_role','gender','birthday','birthplace',
    'civil_status','citizenship','street','barangay','city','province','zip',
    'phone','employment_status'
);
foreach ($required as $field => $val) {
    if ($val === '' || $val === null) {
        echo json_encode(['success' => false, 'message' => "Field '{$field}' is required."]);
        exit;
    }
}
if ($years_resident < 0) {
    echo json_encode(['success' => false, 'message' => "Field 'years_resident' is required."]);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
    exit;
}

// â”€â”€ Prepared UPDATE â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$sql = "UPDATE tbl_userinfo SET
    firstname         = ?,
    lastname          = ?,
    middlename        = ?,
    suffix            = ?,
    family_role       = ?,
    gender            = ?,
    birthday          = ?,
    birthplace        = ?,
    civil_status      = ?,
    citizenship       = ?,
    religion          = ?,
    ethnicity         = ?,
    street            = ?,
    barangay          = ?,
    city              = ?,
    province          = ?,
    zip               = ?,
    phone             = ?,
    email             = ?,
    emergency_contact = ?,
    emergency_phone   = ?,
    health_conditions = ?,
    employment_status = ?,
    job_title         = ?,
    monthly_income    = ?,
    voter_id          = ?,
    precinct          = ?,
    years_resident    = ?,
    resident_birth    = ?
WHERE userID = ? AND userStatus = 'approved'";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

mysqli_stmt_bind_param($stmt,
    'sssssssssssssssssssssssssssiii',
    $firstname,
    $lastname,
    $middlename,
    $suffix,
    $family_role,
    $gender,
    $birthday,
    $birthplace,
    $civil_status,
    $citizenship,
    $religion,
    $ethnicity,
    $street,
    $barangay,
    $city,
    $province,
    $zip,
    $phone,
    $email,
    $emergency_contact,
    $emergency_phone,
    $health_conditions,
    $employment_status,
    $job_title,
    $monthly_income,
    $voter_id,
    $precinct,
    $years_resident,
    $resident_birth,
    $userID
);

if (!mysqli_stmt_execute($stmt)) {
    $err = mysqli_stmt_error($stmt);
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    echo json_encode(['success' => false, 'message' => 'Execute failed: ' . $err]);
    exit;
}

$affected = mysqli_stmt_affected_rows($stmt);
mysqli_stmt_close($stmt);
mysqli_close($conn);

if ($affected < 0) {
    echo json_encode(['success' => false, 'message' => 'No rows updated. Record may not exist or is not approved.']);
    exit;
}

if (!isset($_SESSION['resident_updates'])) {
    $_SESSION['resident_updates'] = [];
}

$sessionRecord = [
    'userID'            => $userID,
    'firstname'         => $firstname,
    'lastname'          => $lastname,
    'middlename'        => $middlename,
    'suffix'            => $suffix,
    'family_role'       => $family_role,
    'gender'            => $gender,
    'birthday'          => $birthday,
    'birthplace'        => $birthplace,
    'civil_status'      => $civil_status,
    'citizenship'       => $citizenship,
    'religion'          => $religion,
    'ethnicity'         => $ethnicity,
    'street'            => $street,
    'barangay'          => $barangay,
    'city'              => $city,
    'province'          => $province,
    'zip'               => $zip,
    'phone'             => $phone,
    'email'             => $email,
    'emergency_contact' => $emergency_contact,
    'emergency_phone'   => $emergency_phone,
    'health_conditions' => $health_conditions,
    'employment_status' => $employment_status,
    'job_title'         => $job_title,
    'monthly_income'    => $monthly_income,
    'voter_id'          => $voter_id,
    'precinct'          => $precinct,
    'years_resident'    => $years_resident,
    'resident_birth'    => $resident_birth
];

$_SESSION['resident_updates'][$userID] = $sessionRecord;

echo json_encode([
    'success' => true,
    'message' => 'Resident updated successfully.',
    'data'    => $sessionRecord,
]);