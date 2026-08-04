<?php
/**
 * residentAdd.php
 */
session_start();
header('Content-Type: application/json');

// Catch ALL PHP errors and return as JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    echo json_encode(['success' => false, 'message' => "PHP Error [$errno]: $errstr on line $errline"]);
    exit;
});
register_shutdown_function(function() {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => "Fatal: {$e['message']} on line {$e['line']}"]);
    }
});

if (!isset($_SESSION['user_id'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

$conn = mysqli_connect('localhost', 'root', '', 'sumeste_db');
if (!$conn) { echo json_encode(['success'=>false,'message'=>'DB connection failed: '.mysqli_connect_error()]); exit; }

require_once __DIR__ . '/../includes/check_permissions.php';
require_permission_ajax($conn, 'manage_residents');

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data) { echo json_encode(['success'=>false,'message'=>'Invalid payload: '.json_last_error_msg()]); exit; }

function clean($v) { return trim(strip_tags($v ?? '')); }

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
$monthly_income    = '';
if (isset($data['monthly_income']) && $data['monthly_income'] !== '' && is_numeric($data['monthly_income'])) {
    $monthly_income = (string)(float)$data['monthly_income'];
}

$account_role_csv = 'resident';
$userStatus       = 'approved';
$frontID          = '';
$backID           = '';
$dateRegistered   = date('Y-m-d H:i:s');

$required = compact('firstname','lastname','family_role','gender','birthday','birthplace',
    'civil_status','citizenship','street','barangay','city','province','zip','phone','employment_status');
foreach ($required as $field => $val) {
    if ($val === '') { echo json_encode(['success'=>false,'message'=>"Field '{$field}' is required."]); exit; }
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success'=>false,'message'=>'Invalid email address.']); exit;
}

$conn = mysqli_connect('localhost', 'root', '', 'sumeste_db');
if (!$conn) { echo json_encode(['success'=>false,'message'=>'DB connection failed: '.mysqli_connect_error()]); exit; }

$chk = mysqli_prepare($conn, "SELECT userID FROM tbl_userinfo WHERE email = ? LIMIT 1");
mysqli_stmt_bind_param($chk, 's', $email);
mysqli_stmt_execute($chk);
mysqli_stmt_store_result($chk);
if (mysqli_stmt_num_rows($chk) > 0) {
    mysqli_stmt_close($chk); mysqli_close($conn);
    echo json_encode(['success'=>false,'message'=>'A resident with this email already exists.']); exit;
}
mysqli_stmt_close($chk);

// accID written as NULL directly in SQL — never bind null through bind_param
$sql = "INSERT INTO tbl_userinfo (
    accID, account_role_csv,
    firstname, lastname, middlename, suffix,
    family_role, gender, birthday, birthplace,
    civil_status, citizenship, religion, ethnicity,
    street, barangay, city, province, zip,
    email, phone,
    emergency_contact, emergency_phone, health_conditions,
    employment_status, job_title, monthly_income,
    years_resident, resident_birth,
    voter_id, precinct,
    userStatus, frontID, backID, dateRegistered
) VALUES (
    NULL, ?,
    ?, ?, ?, ?,
    ?, ?, ?, ?,
    ?, ?, ?, ?,
    ?, ?, ?, ?, ?,
    ?, ?,
    ?, ?, ?,
    ?, ?, ?,
    ?, ?,
    ?, ?,
    ?, ?, ?, ?
)";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    echo json_encode(['success'=>false,'message'=>'Prepare failed: '.mysqli_error($conn)]);
    mysqli_close($conn); exit;
}

// 34 params: 26s + 2i + 6s = 34
mysqli_stmt_bind_param($stmt,
    'ssssssssssssssssssssssssssii' . 'ssssss',
    $account_role_csv,
    $firstname, $lastname, $middlename, $suffix,
    $family_role, $gender, $birthday, $birthplace,
    $civil_status, $citizenship, $religion, $ethnicity,
    $street, $barangay, $city, $province, $zip,
    $email, $phone,
    $emergency_contact, $emergency_phone, $health_conditions,
    $employment_status, $job_title, $monthly_income,
    $years_resident, $resident_birth,
    $voter_id, $precinct,
    $userStatus, $frontID, $backID, $dateRegistered
);

if (!mysqli_stmt_execute($stmt)) {
    $err = mysqli_stmt_error($stmt);
    mysqli_stmt_close($stmt); mysqli_close($conn);
    echo json_encode(['success'=>false,'message'=>'Insert failed: '.$err]); exit;
}

$newUserID = mysqli_insert_id($conn);
mysqli_stmt_close($stmt);
mysqli_close($conn);

if (!isset($_SESSION['resident_additions'])) $_SESSION['resident_additions'] = [];
$_SESSION['resident_additions'][] = [
    'userID'    => $newUserID, 'firstname' => $firstname,
    'lastname'  => $lastname,  'email'     => $email,
    'added_at'  => $dateRegistered, 'added_by' => $_SESSION['user_id'],
];

echo json_encode([
    'success' => true,
    'message' => 'Resident added successfully.',
    'userID'  => $newUserID,
    'data'    => [
        'userID'            => $newUserID,   'accID'             => null,
        'account_role_csv'  => $account_role_csv,
        'firstname'         => $firstname,   'lastname'          => $lastname,
        'middlename'        => $middlename,  'suffix'            => $suffix,
        'family_role'       => $family_role, 'gender'            => $gender,
        'birthday'          => $birthday,    'birthplace'        => $birthplace,
        'civil_status'      => $civil_status,'citizenship'       => $citizenship,
        'religion'          => $religion,    'ethnicity'         => $ethnicity,
        'street'            => $street,      'barangay'          => $barangay,
        'city'              => $city,        'province'          => $province,
        'zip'               => $zip,         'email'             => $email,
        'phone'             => $phone,
        'emergency_contact' => $emergency_contact, 'emergency_phone' => $emergency_phone,
        'health_conditions' => $health_conditions,
        'employment_status' => $employment_status, 'job_title'       => $job_title,
        'monthly_income'    => $monthly_income,
        'years_resident'    => $years_resident,    'resident_birth'  => $resident_birth,
        'voter_id'          => $voter_id,    'precinct'          => $precinct,
        'userStatus'        => $userStatus,  'frontID'           => '',
        'backID'            => '',           'dateRegistered'    => $dateRegistered,
    ],
]);