<?php
/**
 * recover_accounts.php
 * ------------------------------------------------------------
 * One-off CLI script to bulk-create the 20 capstone test accounts
 * from "NEW CAPSTONE PROFILES.txt".
 *
 * USAGE:
 *   1. Copy this file to the SAME directory level as your
 *      `signup/` folder (i.e. your project root, wherever
 *      `config/db_connection.php` resolves correctly from).
 *      If unsure, just place it inside your `signup/` folder
 *      alongside accountCreation.php — the require path below
 *      already assumes that location.
 *   2. Run it ONCE from the droplet terminal:
 *         php recover_accounts.php
 *   3. Delete this file afterwards — it contains plaintext
 *      passwords and should not stay on the server.
 *
 * WHAT IT DOES (per account):
 *   - Generates accID using the same locked/self-healing counter
 *     logic as session_data.php (safe to run alongside live traffic).
 *   - Hashes the password with password_hash() (PASSWORD_DEFAULT),
 *     identical to what session_data.php does on real signups.
 *   - Inserts into tbl_useracc and tbl_userinfo.
 *   - Marks the account as already verified (is_verified = 1,
 *     userStatus = 'active') since these are seed/test accounts on
 *     @example.com addresses that can never receive a real
 *     verification email.
 *
 * ASSUMPTIONS — CHECK BEFORE RUNNING:
 *   - Resident-required fields (street/barangay/city/province/zip/
 *     employment_status) are NOT in the source file, so placeholder
 *     defaults are used below (search "PLACEHOLDER DEFAULTS").
 *   - Name splitting was done by hand for the two 3-part names and
 *     the one compound surname (Delos Reyes) — double check those
 *     three rows in $accounts if it matters for your data.
 * ------------------------------------------------------------
 */

require_once __DIR__ . '/../config/db_connection.php'; // adjust path if needed

// ── PLACEHOLDER DEFAULTS for resident-required fields ──────────────
$defaultStreet    = 'Purok 1';
$defaultBarangay  = 'Sumacab Este';
$defaultCity      = 'Cabanatuan City';
$defaultProvince  = 'Nueva Ecija';
$defaultZip       = '3100';
$defaultEmployment = 'Employed';

// ── Role CSV values, matching process_account.php's $roleMap ───────
const ROLE_RESIDENT        = 'resident';
const ROLE_NONRESIDENT     = 'non-resident';
const ROLE_RESIDENT_OWNER  = 'resident,business/apartment owner';
const ROLE_NONRES_OWNER    = 'non-resident,business/apartment owner';

// ── The 20 accounts ─────────────────────────────────────────────
$accounts = [
    // RESIDENT
    ['first' => 'Camille', 'middle' => '', 'last' => 'Reyes',      'email' => 'camille.reyes1@example.com',     'pass' => 'd15B1dqFQ#Zq', 'role' => ROLE_RESIDENT],
    ['first' => 'Antonio', 'middle' => '', 'last' => 'Manalo',     'email' => 'antonio.manalo2@example.com',    'pass' => '0jHa3bGZ^cLR', 'role' => ROLE_RESIDENT],
    ['first' => 'Danica',  'middle' => '', 'last' => 'Salazar',    'email' => 'danica.salazar3@example.com',    'pass' => 'A0yJE7BC*TrF', 'role' => ROLE_RESIDENT],
    ['first' => 'Joaquin', 'middle' => '', 'last' => 'Manalo',     'email' => 'joaquin.manalo4@example.com',    'pass' => 'a&Nfrfz2luGU', 'role' => ROLE_RESIDENT],
    ['first' => 'Andrea',  'middle' => '', 'last' => 'Cruz',       'email' => 'andrea.cruz5@example.com',       'pass' => 'K5M5i@woxm2A', 'role' => ROLE_RESIDENT],

    // NON-RESIDENT
    ['first' => 'Julian',  'middle' => '', 'last' => 'Domingo',    'email' => 'julian.domingo6@example.com',    'pass' => 'eOdj@0kXWir2', 'role' => ROLE_NONRESIDENT],
    ['first' => 'Camille', 'middle' => '', 'last' => 'Fernandez',  'email' => 'camille.fernandez7@example.com', 'pass' => 'eX2!xK3cQkwP', 'role' => ROLE_NONRESIDENT],
    ['first' => 'Miguel',  'middle' => '', 'last' => 'Bautista',   'email' => 'miguel.bautista8@example.com',   'pass' => '9n8D@j8UEqLS', 'role' => ROLE_NONRESIDENT],
    ['first' => 'Faith',   'middle' => '', 'last' => 'Manalo',     'email' => 'faith.manalo9@example.com',      'pass' => '$HETc0rPQy8F', 'role' => ROLE_NONRESIDENT],
    ['first' => 'Juan',    'middle' => 'Miguel', 'last' => 'Villanueva', 'email' => 'miguel.villanueva10@example.com', 'pass' => 'tu0m#DbkRwax', 'role' => ROLE_NONRESIDENT],

    // RESIDENT + OWNER
    ['first' => 'Isabella','middle' => '', 'last' => 'Salazar',    'email' => 'isabella.salazar11@example.com', 'pass' => 'USSUAp$f7x2T', 'role' => ROLE_RESIDENT_OWNER],
    ['first' => 'Julian',  'middle' => 'Jun', 'last' => 'Reyes',   'email' => 'julian.reyes12@example.com',     'pass' => 'mF9g*vpMyALR', 'role' => ROLE_RESIDENT_OWNER],
    ['first' => 'Maria',   'middle' => '', 'last' => 'Fernandez',  'email' => 'andrea.fernandez13@example.com', 'pass' => 'Q404a3rG%BQS', 'role' => ROLE_RESIDENT_OWNER],
    ['first' => 'Diego',   'middle' => '', 'last' => 'Miranda',    'email' => 'diego.bautista14@example.com',   'pass' => '84^TctgUB3H3', 'role' => ROLE_RESIDENT_OWNER],
    ['first' => 'Beatriz', 'middle' => '', 'last' => 'Cruz',       'email' => 'beatriz.cruz15@example.com',     'pass' => 'sD3HRSfD*5i5', 'role' => ROLE_RESIDENT_OWNER],

    // NON-RESIDENT + OWNER
    ['first' => 'Rafael',  'middle' => '', 'last' => 'Delos Reyes','email' => 'rafael.bautista16@example.com',  'pass' => 'EEy%5FO6y2nO', 'role' => ROLE_NONRES_OWNER],
    ['first' => 'Kristine','middle' => '', 'last' => 'Padolina',   'email' => 'kristine.garcia17@example.com',  'pass' => 'VLO@eNKP9mgV', 'role' => ROLE_NONRES_OWNER],
    ['first' => 'Nathaniel','middle'=> '', 'last' => 'Reyes',      'email' => 'nathaniel.reyes18@example.com',  'pass' => 'lw*07vZLWSG3', 'role' => ROLE_NONRES_OWNER],
    ['first' => 'Sophia',  'middle' => '', 'last' => 'Pablo',      'email' => 'sophia.manalo19@example.com',    'pass' => 'itDbJwgqv@C7', 'role' => ROLE_NONRES_OWNER],
    ['first' => 'Lenard',  'middle' => '', 'last' => 'Madrid',     'email' => 'nathaniel.salazar20@example.com','pass' => 'H7Egqv#mB2Ms', 'role' => ROLE_NONRES_OWNER],
];

if (!date_default_timezone_get()) {
    date_default_timezone_set('Asia/Manila');
}
$dateRegistered = date('Y-m-d H:i:s');

// ── Placeholder ID image generator (GD) ─────────────────────────────
// Produces a plain "SAMPLE ID - TEST ACCOUNT" image so nothing in the
// app breaks if it tries to render an ID photo for these seed accounts.
// Returns the RELATIVE path to store in tbl_userinfo (same format
// session_data.php derives: "../uploads/id_verification/xxxx.jpg"),
// or '' if GD isn't available / the write failed.
function makePlaceholderIdImage(string $accID, string $side, string $label): string {
    if (!function_exists('imagecreatetruecolor')) {
        return ''; // GD not installed — leave blank rather than fail the whole run
    }

    $uploadDir = dirname(__DIR__) . '/uploads/id_verification';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        return '';
    }

    $filename = $accID . '_' . $side . '.jpg';
    $fullPath = $uploadDir . '/' . $filename;

    $img = imagecreatetruecolor(600, 380);
    $bg  = imagecolorallocate($img, 236, 253, 245); // light green
    $fg  = imagecolorallocate($img, 21, 128, 61);   // green text
    $border = imagecolorallocate($img, 187, 247, 208);
    imagefill($img, 0, 0, $bg);
    imagerectangle($img, 4, 4, 595, 375, $border);
    imagestring($img, 5, 30, 150, 'SAMPLE ID - TEST ACCOUNT', $fg);
    imagestring($img, 4, 30, 180, $label, $fg);
    imagestring($img, 3, 30, 210, 'accID: ' . $accID, $fg);
    imagejpeg($img, $fullPath, 85);
    imagedestroy($img);

    // Match the relative-path convention used by session_data.php
    $basePath = str_replace('\\', '/', dirname(__DIR__));
    $absSaved = str_replace('\\', '/', $fullPath);
    return str_replace($basePath . '/', '../', $absSaved);
}

$created = [];
$failed  = [];

foreach ($accounts as $acc) {
    $email          = $acc['email'];
    $hashedPassword = password_hash($acc['pass'], PASSWORD_DEFAULT);
    $accountRolesCsv = $acc['role'];
    $isResident     = str_starts_with($acc['role'], 'resident');

    $firstname  = $acc['first'];
    $middlename = $acc['middle'];
    $lastname   = $acc['last'];

    $maxAttempts = 3;
    $attempt = 0;
    $lastError = null;
    $done = false;

    while ($attempt < $maxAttempts && !$done) {
        $attempt++;
        mysqli_begin_transaction($conn);

        try {
            // Skip if email already exists (don't duplicate)
            $chk = $conn->prepare('SELECT 1 FROM tbl_useracc WHERE email = ? LIMIT 1');
            $chk->bind_param('s', $email);
            $chk->execute();
            $chk->store_result();
            $exists = $chk->num_rows > 0;
            $chk->close();
            if ($exists) {
                mysqli_rollback($conn);
                $failed[] = "$email — already exists, skipped";
                $done = true;
                break;
            }

            // Locked, self-healing accID counter (same logic as session_data.php)
            $countResult = mysqli_query($conn, "SELECT count FROM tbl_count LIMIT 1 FOR UPDATE");
            if (!$countResult) throw new Exception("Error reading tbl_count: " . mysqli_error($conn));
            $countRow = mysqli_fetch_assoc($countResult);
            $currentCount = (int) ($countRow['count'] ?? 0);

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
            if (!$updateResult) throw new Exception("Error updating count: " . mysqli_error($conn));

            // Insert into tbl_useracc — pre-verified, active
            $sqlAcc = "INSERT INTO tbl_useracc (accID, email, password, account_role, is_verified) VALUES (?, ?, ?, ?, 1)";
            $stmtAcc = $conn->prepare($sqlAcc);
            if (!$stmtAcc) throw new Exception("Error preparing account insert: " . $conn->error);
            $stmtAcc->bind_param("ssss", $accID, $email, $hashedPassword, $accountRolesCsv);
            $stmtAcc->execute();
            $stmtAcc->close();

            // Insert into tbl_userinfo
            $street   = $isResident ? $defaultStreet : '';
            $barangay = $isResident ? $defaultBarangay : '';
            $city     = $isResident ? $defaultCity : '';
            $province = $isResident ? $defaultProvince : '';
            $zip      = $isResident ? $defaultZip : '';
            $employmentStatus = $isResident ? $defaultEmployment : '';
            $familyRole  = 'Household Head';
            $civilStatus = 'Single';
            $userStatus  = 'active';

            // Placeholder front/back ID images — generated fresh per account
            // now that we have the real accID to label/name them with.
            $frontIdImage = makePlaceholderIdImage($accID, 'front', $firstname . ' ' . $lastname . ' - Front ID');
            $backIdImage  = makePlaceholderIdImage($accID, 'back',  $firstname . ' ' . $lastname . ' - Back ID');

            $sqlInfo = "INSERT INTO tbl_userinfo (
                accID, account_role_csv, firstname, lastname, middlename, family_role,
                civil_status, street, barangay, city, province, zip, email,
                employment_status, userStatus, frontID, backID, dateRegistered, last_verified_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmtInfo = $conn->prepare($sqlInfo);
            if (!$stmtInfo) throw new Exception("Error preparing profile insert: " . $conn->error);
            $stmtInfo->bind_param(
                "sssssssssssssssssss",
                $accID, $accountRolesCsv, $firstname, $lastname, $middlename, $familyRole,
                $civilStatus, $street, $barangay, $city, $province, $zip, $email,
                $employmentStatus, $userStatus, $frontIdImage, $backIdImage, $dateRegistered, $dateRegistered
            );
            $stmtInfo->execute();
            $stmtInfo->close();

            mysqli_commit($conn);
            $created[] = "$accID — $email";
            $done = true;

        } catch (mysqli_sql_exception $e) {
            mysqli_rollback($conn);
            $lastError = $e->getMessage();
            if (str_contains($lastError, 'Duplicate entry') && $attempt < $maxAttempts) {
                continue;
            }
            $failed[] = "$email — $lastError";
            $done = true;
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $failed[] = "$email — " . $e->getMessage();
            $done = true;
        }
    }
}

echo "=== DONE ===\n";
echo "Created (" . count($created) . "):\n";
foreach ($created as $line) echo "  $line\n";
echo "\nFailed/skipped (" . count($failed) . "):\n";
foreach ($failed as $line) echo "  $line\n";