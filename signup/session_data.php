<?php
session_start();
require_once __DIR__ . '/../config/db_connection.php';
require_once __DIR__ . '/../includes/normalize_helpers.php';

// Retrieve account session data
$email = $_SESSION['email'] ?? '';
$rawPassword = $_SESSION['password'] ?? '';
$hashedPassword = password_hash($rawPassword, PASSWORD_DEFAULT);

// Account roles
$accountRoles = $_SESSION['account_role'] ?? [];
if (is_string($accountRoles)) $accountRoles = [$accountRoles];
$accountRolesCsv = implode(',', $accountRoles);

// Retrieve personal profile session data
//
// Names/phone/street are re-normalized here (on top of whatever
// residentProfile.php / nonresidentProfile.php already did) as a final
// safety net right before the actual DB insert — this is the one choke
// point every registration path goes through, so it's also the
// guarantee that admin listings and printed reports (which just read
// tbl_userinfo as-is) always see consistent formatting.
$firstname        = normalize_person_name((string) ($_SESSION['firstname'] ?? ''));
$lastname         = normalize_person_name((string) ($_SESSION['lastname'] ?? ''));
$middlename       = normalize_person_name((string) ($_SESSION['middlename'] ?? ''));
$suffix           = normalize_name_suffix((string) ($_SESSION['suffix'] ?? ''));
$familyRole       = $_SESSION['family_role'] ?? '';
$gender           = $_SESSION['gender'] ?? '';
$birthday         = !empty($_SESSION['birthday']) ? $_SESSION['birthday'] : null;
$birthplace       = $_SESSION['birthplace'] ?? '';
$civilStatus      = $_SESSION['civil_status'] ?? '';
$citizenship      = $_SESSION['citizenship'] ?? '';
$religion         = $_SESSION['religion'] ?? '';
$ethnicity        = $_SESSION['ethnicity'] ?? '';
$barangay         = $_SESSION['barangay'] ?? '';
$city             = $_SESSION['city'] ?? '';
$province         = $_SESSION['province'] ?? '';
$street           = normalize_street_address((string) ($_SESSION['street'] ?? ''), [$barangay, $city, $province]);
$zip              = $_SESSION['zip'] ?? '';
$phone            = normalize_ph_phone((string) ($_SESSION['phone'] ?? ''));
$emergencyContact = normalize_person_name((string) ($_SESSION['emergency_contact'] ?? ''));
$emergencyPhone   = normalize_ph_phone((string) ($_SESSION['emergency_phone'] ?? ''));
$healthConditions = $_SESSION['health_conditions'] ?? '';
$employmentStatus = $_SESSION['employment_status'] ?? '';
$jobTitle         = $_SESSION['job_title'] ?? '';
$monthlyIncome    = $_SESSION['monthly_income'] ?? 0;
$yearsResident    = $_SESSION['years_resident'] ?? 0;
$residentBirth    = isset($_SESSION['resident_birth']) ? 1 : 0;
$voterId          = $_SESSION['voter_id'] ?? '';
$precinct         = $_SESSION['precinct'] ?? '';
$userStatus       = 'pending';

// ID Image - store relative path for display
$frontIdImage = '';
if (!empty($_SESSION['saved_id_upload']['front']['path'])) {
    $frontPath = str_replace('\\', '/', $_SESSION['saved_id_upload']['front']['path']);
    $basePath = str_replace('\\', '/', dirname(__DIR__));
    $frontIdImage = str_replace($basePath . '/', '../', $frontPath);
}

$backIdImage = '';
if (!empty($_SESSION['saved_id_upload']['back']['path'])) {
    $backPath = str_replace('\\', '/', $_SESSION['saved_id_upload']['back']['path']);
    $basePath = str_replace('\\', '/', dirname(__DIR__));
    $backIdImage = str_replace($basePath . '/', '../', $backPath);
}

// DateRegistered - current date in local timezone (e.g., Manila)
if (!date_default_timezone_get()) {
    date_default_timezone_set('Asia/Manila');
}
$dateRegistered = date('Y-m-d H:i:s');

// ---------------------------------------------------------------------
// Account creation  –  wrapped in a single transaction so:
//   1. Two signups happening at the same moment can't compute the same
//      accID (the counter row is locked with FOR UPDATE for the
//      duration of the transaction).
//   2. If tbl_count ever falls behind reality (manual edit, a previous
//      failed run, etc.), the next ID self-heals to one past the
//      highest accID that actually exists, instead of colliding with it.
//   3. If the profile insert fails after the account insert succeeded,
//      everything rolls back together  –  no orphaned account row.
// ---------------------------------------------------------------------

$maxAttempts = 3;
$attempt = 0;
$accID = null;
$lastError = null;

while ($attempt < $maxAttempts) {
    $attempt++;
    mysqli_begin_transaction($conn);

    try {
        // Lock the counter row so concurrent requests queue instead of racing.
        $countResult = mysqli_query($conn, "SELECT count FROM tbl_count LIMIT 1 FOR UPDATE");
        if (!$countResult) {
            throw new Exception("Error reading tbl_count: " . mysqli_error($conn));
        }
        $countRow = mysqli_fetch_assoc($countResult);
        $currentCount = (int) ($countRow['count'] ?? 0);

        // Self-heal: never issue an ID lower than what's already in use.
        $maxResult = mysqli_query($conn, "
            SELECT MAX(CAST(SUBSTRING(accID, 4) AS UNSIGNED)) AS maxNum
            FROM tbl_useracc
            WHERE accID REGEXP '^Acc[0-9]+$'
        ");
        $maxRow = $maxResult ? mysqli_fetch_assoc($maxResult) : null;
        $maxExisting = (int) ($maxRow['maxNum'] ?? 0);

        $newCount = max($currentCount, $maxExisting) + 1;
        $accID = "Acc" . $newCount;

        $updateResult = mysqli_query($conn, "UPDATE tbl_count SET count = $newCount");
        if (!$updateResult) {
            throw new Exception("Error updating count: " . mysqli_error($conn));
        }

        // Insert into tbl_useracc
        $sqlAcc = "INSERT INTO tbl_useracc (accID, email, password, account_role) VALUES (?, ?, ?, ?)";
        $stmtAcc = $conn->prepare($sqlAcc);
        if (!$stmtAcc) {
            throw new Exception("Error preparing account insert: " . $conn->error);
        }
        $stmtAcc->bind_param("ssss", $accID, $email, $hashedPassword, $accountRolesCsv);
        $stmtAcc->execute();
        $stmtAcc->close();

        // Insert into tbl_userinfo
        $sqlInfo = "INSERT INTO tbl_userinfo (
            accID, account_role_csv, firstname, lastname, middlename, suffix, family_role, gender, birthday, birthplace,
            civil_status, citizenship, religion, ethnicity, street, barangay, city, province, zip, email,
            phone, emergency_contact, emergency_phone, health_conditions,
            employment_status, job_title, monthly_income,
            years_resident, resident_birth, voter_id, precinct, userStatus, frontID, backID, dateRegistered, last_verified_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmtInfo = $conn->prepare($sqlInfo);
        if (!$stmtInfo) {
            throw new Exception("Error preparing profile insert: " . $conn->error);
        }
        $stmtInfo->bind_param(
            "ssssssssssssssssssssssssssdissssssss",
            $accID, $accountRolesCsv, $firstname, $lastname, $middlename, $suffix, $familyRole, $gender, $birthday, $birthplace,
            $civilStatus, $citizenship, $religion, $ethnicity, $street, $barangay, $city, $province, $zip, $email,
            $phone, $emergencyContact, $emergencyPhone, $healthConditions,
            $employmentStatus, $jobTitle, $monthlyIncome,
            $yearsResident, $residentBirth, $voterId, $precinct, $userStatus, $frontIdImage, $backIdImage, $dateRegistered, $dateRegistered
        );
        $stmtInfo->execute();
        $stmtInfo->close();

        mysqli_commit($conn);

        // Success  –  clear all signup session data to prevent reuse.
        session_unset();
        session_destroy();
        header('Location: ../login.php?success=1');
        exit;

    } catch (mysqli_sql_exception $e) {
        mysqli_rollback($conn);
        $lastError = $e->getMessage();

        // Duplicate accID specifically means another request grabbed the
        // same number between our SELECT and INSERT  –  extremely unlikely
        // now that the counter row is locked, but retry once or twice
        // just in case (e.g. manual DB edits mid-run) before giving up.
        if (str_contains($lastError, 'Duplicate entry') && $attempt < $maxAttempts) {
            continue;
        }

        session_unset();
        session_destroy();
        die("Error creating account: " . $lastError);

    } catch (Exception $e) {
        mysqli_rollback($conn);
        session_unset();
        session_destroy();
        die("Error creating account: " . $e->getMessage());
    }
}

// If we exhausted all attempts without success or an early exit above.
session_unset();
session_destroy();
die("Error creating account after {$maxAttempts} attempts: " . ($lastError ?? 'unknown error'));
