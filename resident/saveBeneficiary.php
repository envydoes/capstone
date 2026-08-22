<?php
/**
 *
 * ─── SCORING RUBRIC ───
 *
 *  HOUSING & UTILITIES                                             Max = 45 pts
 *    Housing Status
 *      Informal Settler  → +10
 *      Renting           → +7
 *      Shared/Relatives  → +3
 *      Owned / Gov't     → +0
 *    Primary Material
 *      Light/Salvaged (Bamboo, Cogon, Makeshift/Scrap) → +15
 *      Mixed Materials                                 → +7
 *      Concrete / Wood                                 → +0
 *    Electricity  : No Electricity OR Shared           → +5
 *    Water Source : Shared Well OR Bought/Mineral      → +5
 *    Toilet Type  : None/Pit OR Shared/Public          → +5
 *
 *  FAMILY & SPECIAL CLASSIFICATION                                Max = 35 pts
 *    Pregnant or Child < 5 = Yes  → +10
 *    PWD checkbox                 → +10
 *    Solo Parent                  → +10
 *    Indigenous Person (IP)       → +5
 *
 *  HEALTH & PENSION                                               Max = 35 pts
 *    Any health condition (hypertension/diabetes/asthma/other) → +10
 *    Requires maintenance medicine = Yes                        → +5
 *    Pension = None OR Social Pension (DSWD)                   → +10
 *    Senior Citizen (age >= 60)                                 → +10
 *
 *  EDUCATION / SCHOLARSHIP                                        Max = 10 pts
 *    GWA/GPA 1.00-1.75 (Philippine honour range)               → +10
 *
 *  GRAND MAX = 100 pts  (hard-capped at 100)
 *
 * ─── PROGRAM PRIORITY BANDS ───
 *  4Ps                  70-100   (housing + utilities + pregnant)
 *  Senior Citizen       60-100   (no pension + age >= 60 [via birthdate] + health)
 *  Scholarship Programs 75-100   (GWA 1.00-1.75 + low monthly income)
 *  PWD                  80-100   (PWD checked + valid PWD ID + health)
 *  Kabataan/SK          compliance-based (ages 15-30, handled by admin layer)
 *  For Voters           compliance-based (ages 18+,  handled by admin layer)
 *
 * ─── SQL MIGRATION (run once) ───
 *
 *  CREATE TABLE IF NOT EXISTS `tbl_beneficiary` (
 *    `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *    `userId`                INT NOT NULL,
 *    `housing_status`        VARCHAR(50)   NOT NULL DEFAULT '',
 *    `house_material`        VARCHAR(50)   NOT NULL DEFAULT '',
 *    `electricity`           VARCHAR(30)   NOT NULL DEFAULT '',
 *    `water_source`          VARCHAR(30)   NOT NULL DEFAULT '',
 *    `toilet_type`           VARCHAR(30)   NOT NULL DEFAULT '',
 *    `pregnant_or_children`  TINYINT(1)    NOT NULL DEFAULT 0,
 *    `is_pwd`                TINYINT(1)    NOT NULL DEFAULT 0,
 *    `pwd_id_number`         VARCHAR(100)  NOT NULL DEFAULT '',
 *    `is_solo_parent`        TINYINT(1)    NOT NULL DEFAULT 0,
 *    `is_indigenous`         TINYINT(1)    NOT NULL DEFAULT 0,
 *    `pension_status`        VARCHAR(30)   NOT NULL DEFAULT '',
 *    `health_hypertension`   TINYINT(1)    NOT NULL DEFAULT 0,
 *    `health_diabetes`       TINYINT(1)    NOT NULL DEFAULT 0,
 *    `health_asthma`         TINYINT(1)    NOT NULL DEFAULT 0,
 *    `health_other`          TINYINT(1)    NOT NULL DEFAULT 0,
 *    `health_other_specify`  VARCHAR(255)  NOT NULL DEFAULT '',
 *    `health_none`           TINYINT(1)    NOT NULL DEFAULT 0,
 *    `requires_medicine`     TINYINT(1)    NOT NULL DEFAULT 0,
 *    `medicine_name`         VARCHAR(255)  NOT NULL DEFAULT '',
 *    `school_name`           VARCHAR(255)  NOT NULL DEFAULT '',
 *    `course`                VARCHAR(255)  NOT NULL DEFAULT '',
 *    `year_level`            VARCHAR(30)   NOT NULL DEFAULT '',
 *    `gwa_gpa`               VARCHAR(20)   NOT NULL DEFAULT '',
 *    `prio_score`            TINYINT UNSIGNED NOT NULL DEFAULT 0,
 *    `status`                VARCHAR(20)   NOT NULL DEFAULT 'pending',
 *    `submitted_at`          DATETIME,
 *    `created_at`            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *    `updated_at`            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
 *                                          ON UPDATE CURRENT_TIMESTAMP,
 *    UNIQUE KEY `uq_userId`  (`userId`),
 *    INDEX `idx_prio_score`  (`prio_score`),
 *    INDEX `idx_status`      (`status`),
 *    FOREIGN KEY (`userId`) REFERENCES `tbl_userinfo` (`userID`)
 *  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 *
 * ─── MONTHLY INCOME DROPDOWN VALUES (add to beneficiary form) ───
 *  <select name="monthly_income">
 *    <option value="">-- Select --</option>
 *    <option value="below_5000">Below ₱5,000</option>
 *    <option value="5000_9999">₱5,000 - ₱9,999</option>
 *    <option value="10000_14999">₱10,000 - ₱14,999</option>
 *    <option value="15000_19999">₱15,000 - ₱19,999</option>
 *    <option value="20000_above">₱20,000 and above</option>
 *  </select>
 *
 * ────────────────────────────────────────────────────────────────────────────
 */

// ─── Guard: session must already be started by the parent page ───
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ─── Guard: user must be logged in ───
if (empty($_SESSION['user_id']) || empty($_SESSION['acc_id'])) {
    $_SESSION['beneficiary_save_status'] = 'error';
    $_SESSION['beneficiary_save_msg']    = 'Not authenticated.';
    return;
}

// ─── Guard: form data must exist in session ───
if (empty($_SESSION['beneficiary_form']) || !is_array($_SESSION['beneficiary_form'])) {
    return; // Nothing to save - silently skip
}

// ─── Database connection ───

require_once __DIR__ . '/../config/db_connection.php';
if (!$conn) {
    $_SESSION['beneficiary_save_status'] = 'error';
    $_SESSION['beneficiary_save_msg']    = 'DB connection failed: ' . mysqli_connect_error();
    return;
}
mysqli_set_charset($conn, 'utf8mb4');

// ─── Pull & sanitise session data ───
$f = $_SESSION['beneficiary_form'];
$accId = trim($_SESSION['acc_id'] ?? '');
$userId = 0;

// Resolve userId from accId (primary), else fallback to user email string stored in session
if ($accId !== '') {
    $accStmt = $conn->prepare('SELECT userID FROM tbl_userinfo WHERE accID = ? LIMIT 1');
    if ($accStmt) {
        $accStmt->bind_param('s', $accId);
        $accStmt->execute();
        $accStmt->bind_result($resolvedUserId);
        if ($accStmt->fetch()) {
            $userId = (int)$resolvedUserId;
        }
        $accStmt->close();
    }
}

if ($userId <= 0 && !empty($_SESSION['user_id'])) {
    $rawEmail = trim($_SESSION['user_id']);
    $emailStmt = $conn->prepare('SELECT userID FROM tbl_userinfo WHERE email = ? LIMIT 1');
    if ($emailStmt) {
        $emailStmt->bind_param('s', $rawEmail);
        $emailStmt->execute();
        $emailStmt->bind_result($resolvedUserId);
        if ($emailStmt->fetch()) {
            $userId = (int)$resolvedUserId;
        }
        $emailStmt->close();
    }
}

if ($userId <= 0) {
    $_SESSION['beneficiary_save_status'] = 'error';
    $_SESSION['beneficiary_save_msg']    = 'Invalid user identity. Please log in again.';
    mysqli_close($conn);
    header('Location: ../resident/beneficiaryForm.php');
    exit;
}

// Ensure userId exists in tbl_userinfo before continuing
$checkUserStmt = $conn->prepare('SELECT 1 FROM tbl_userinfo WHERE userID = ? LIMIT 1');
if (!$checkUserStmt) {
    $_SESSION['beneficiary_save_status'] = 'error';
    $_SESSION['beneficiary_save_msg']    = 'Prepare failed (user existence check): ' . $conn->error;
    mysqli_close($conn);
    header('Location: ../resident/beneficiaryForm.php');
    exit;
}
$checkUserStmt->bind_param('i', $userId);
$checkUserStmt->execute();
$checkUserStmt->store_result();
if ($checkUserStmt->num_rows === 0) {
    $_SESSION['beneficiary_save_status'] = 'error';
    $_SESSION['beneficiary_save_msg']    = 'User account not found. Please contact support.';
    $checkUserStmt->close();
    mysqli_close($conn);
    header('Location: ../resident/beneficiaryForm.php');
    exit;
}
$checkUserStmt->close();

// Retrieve user info for score calculation
$userInfoStmt = $conn->prepare('SELECT birthday, monthly_income FROM tbl_userinfo WHERE userID = ? LIMIT 1');
if (!$userInfoStmt) {
    $_SESSION['beneficiary_save_status'] = 'error';
    $_SESSION['beneficiary_save_msg']    = 'Prepare failed (user info): ' . $conn->error;
    mysqli_close($conn);
    return;
}
$userInfoStmt->bind_param('i', $userId);
$userInfoStmt->execute();
$userInfoStmt->bind_result($birthday, $monthlyIncome);
$userInfoStmt->fetch();
$userInfoStmt->close();

// Calculate age
$age = null;
if ($birthday) {
    $birthDate = new DateTime($birthday);
    $today = new DateTime();
    $age = $today->diff($birthDate)->y;
}

/** Sanitise a string value - trims whitespace and caps length. */
$str = static function (string $key, int $maxLen = 255) use ($f): string {
    $val = isset($f[$key]) ? trim((string)$f[$key]) : '';
    return substr($val, 0, $maxLen);
};

/** Return 1 or 0 for checkbox / boolean values. */
$bool = static function (string $key) use ($f): int {
    return !empty($f[$key]) ? 1 : 0;
};

/** Map 'yes' / 'no' string → 1 / 0. */
$yesNo = static function (string $key) use ($f): int {
    return (isset($f[$key]) && strtolower(trim($f[$key])) === 'yes') ? 1 : 0;
};

// Validate submitted_at or fall back to NOW
$submittedAt = null;
if (!empty($f['submitted_at'])) {
    $ts = strtotime($f['submitted_at']);
    if ($ts !== false) {
        $submittedAt = date('Y-m-d H:i:s', $ts);
    }
}
$submittedAt = $submittedAt ?? date('Y-m-d H:i:s');

// ─── Build data array ───
$data = [
    // Housing
    'housing_status'       => $str('housing_status',       50),
    'house_material'       => $str('house_material',       50),
    // Utilities
    'electricity'          => $str('electricity',          30),
    'water_source'         => $str('water_source',         30),
    'toilet_type'          => $str('toilet_type',          30),
    // Household
    'pregnant_or_children' => $yesNo('pregnant_or_children'),
    // Classification
    'is_pwd'               => $bool('is_pwd'),
    'pwd_id_number'        => $str('pwd_id_number',        100),
    'is_solo_parent'       => $bool('is_solo_parent'),
    'is_indigenous'        => $bool('is_indigenous'),
    // Pension
    'pension_status'       => $str('pension_status',       30),
    // Health
    'health_hypertension'  => $bool('health_hypertension'),
    'health_diabetes'      => $bool('health_diabetes'),
    'health_asthma'        => $bool('health_asthma'),
    'health_other'         => $bool('health_other'),
    'health_other_specify' => $str('health_other_specify', 255),
    'health_none'          => $bool('health_none'),
    // Medicine
    'requires_medicine'    => $yesNo('requires_medicine'),
    'medicine_name'        => $str('medicine_name',        255),
    // Student
    'school_name'          => $str('school_name',          255),
    'course'               => $str('course',               255),
    'year_level'           => $str('year_level',           30),
    'gwa_gpa'              => $str('gwa_gpa',              20),
    // Income retrieved from tbl_userinfo
    // 'monthly_income'       => $str('monthly_income',       30),
    // Timestamps
    'submitted_at'         => $submittedAt,
];

// Conditional field cleanup
if (!$data['is_pwd'])            $data['pwd_id_number']        = '';
if (!$data['requires_medicine']) $data['medicine_name']        = '';
if (!$data['health_other'])      $data['health_other_specify'] = '';

// ────────────────────────────────────────────────────────────────────────────
//  PRIORITY SCORE CALCULATION
// ────────────────────────────────────────────────────────────────────────────
$score = 0;

// ─── Housing Status ───
$score += match(strtolower($data['housing_status'])) {
    'informal_settler'   => 10,
    'renting'            => 7,
    'shared'             => 5,
    'government_housing' => 2,
    default              => 0,   // owned
};

// ─── Primary Material ───
$score += match(strtolower($data['house_material'])) {
    'makeshift'       => 10,
    'light_materials' => 8,
    'wood'            => 7,
    'mixed'           => 5,
    default           => 0, // concrete
};

// ─── Utilities (10 pts combined) ───
$score += match(strtolower($data['electricity'])) {
    'no_electricity' => 3,
    'shared'         => 2,
    default          => 0, // own_meter
};

$score += match(strtolower($data['water_source'])) {
    'bought_mineral' => 3,
    'shared_well'    => 2,
    default          => 0, // pipe
};

$score += match(strtolower($data['toilet_type'])) {
    'none_pit'      => 4,
    'shared_public' => 2,
    default         => 0, // private
};

// ─── Household Composition ───
// No specific point explicitly in new rules for pregnant here but keeping cap limits or skipping it if omitted.
// The new points do not explicitly include "pregnant" in score directly, only in 4ps eligibility.
// But let's leave it out of score if not in rubric, or keep it? The prompt says "Prio-score computation 1... 2... 3... 4...". It doesn't mention pregnancy or children in the scoring rubric. So I will remove it from scoring.

// ─── Special Classification & Pension (Max 20 pts combined) ───
$socialScore = 0;
if ($data['is_pwd'])         $socialScore += 10;
if ($data['is_solo_parent']) $socialScore += 10;
if ($data['is_indigenous'])  $socialScore += 5;

$socialScore += match(strtolower((string)$data['pension_status'])) {
    'none', ''       => 10,
    'social_pension' => 5,
    default          => 0, // sss_gsis
};

$score += min($socialScore, 20);

// ─── Health Conditions (any one condition ticked) (Max 20 pts combined) ───
$healthScore = 0;
$anyHealth = $data['health_hypertension']
           || $data['health_diabetes']
           || $data['health_asthma']
           || $data['health_other'];

if ($anyHealth) {
    $healthScore += 10;
}

// ─── Maintenance Medicine ───
if ($data['requires_medicine']) {
    $healthScore += 10;
}

$score += min($healthScore, 20);

// ─── Economic Status (Monthly Income) ───
$incomeValue = trim((string)$monthlyIncome);
if ($incomeValue === '' || strtolower($incomeValue) === 'none' || (is_numeric($incomeValue) && (float)$incomeValue == 0)) {
    $score += 30; // None
} else {
    $incNumFloat = (float)$incomeValue;
    if ($incNumFloat > 84000) {
        $score += 0;
    } elseif ($incNumFloat >= 48001) {
        $score += 5;
    } elseif ($incNumFloat >= 24001) {
        $score += 10;
    } elseif ($incNumFloat >= 12001) {
        $score += 20;
    } else {
        $score += 25; // Below 12k
    }
}

// ─── Education / Scholarship: (Remove from score according to rubric or keep?) ───
// The new rubric doesn't list scholarship in priority points. It only lists it in Eligibility criteria.


// Hard-cap at 100
$data['prio_score'] = min((int)$score, 100);

// Status always resets to 'pending' so admin must re-review after re-submit
$data['status'] = 'pending';
// ────────────────────────────────────────────────────────────────────────────

// ─── Check if a record already exists for this userId ───
$existingId = null;
$chkStmt    = $conn->prepare('SELECT id FROM tbl_beneficiary WHERE userId = ? LIMIT 1');
if (!$chkStmt) {
    $_SESSION['beneficiary_save_status'] = 'error';
    $_SESSION['beneficiary_save_msg']    = 'Prepare failed (check): ' . $conn->error;
    mysqli_close($conn);
    return;
}
$chkStmt->bind_param('i', $userId);
$chkStmt->execute();
$chkStmt->bind_result($existingId);
$chkStmt->fetch();
$chkStmt->close();

// ─── INSERT or UPDATE ───
if ($existingId) {
    // ─── UPDATE ───
    // 26 SET values  (+1 WHERE = 27 total bind params)
    // Types (26 SET):
    //   s  housing_status
    //   s  house_material
    //   s  electricity
    //   s  water_source
    //   s  toilet_type
    //   i  pregnant_or_children
    //   i  is_pwd
    //   s  pwd_id_number
    //   i  is_solo_parent
    //   i  is_indigenous
    //   s  pension_status
    //   i  health_hypertension
    //   i  health_diabetes
    //   i  health_asthma
    //   i  health_other
    //   s  health_other_specify
    //   i  health_none
    //   i  requires_medicine
    //   s  medicine_name
    //   s  school_name
    //   s  course
    //   s  year_level
    //   s  gwa_gpa
    //   i  prio_score
    //   s  status
    //   s  submitted_at
    //   i  userId (WHERE)

    $sqlUpdate = '
        UPDATE tbl_beneficiary SET
            housing_status       = ?,
            house_material       = ?,
            electricity          = ?,
            water_source         = ?,
            toilet_type          = ?,
            pregnant_or_children = ?,
            is_pwd               = ?,
            pwd_id_number        = ?,
            is_solo_parent       = ?,
            is_indigenous        = ?,
            pension_status       = ?,
            health_hypertension  = ?,
            health_diabetes      = ?,
            health_asthma        = ?,
            health_other         = ?,
            health_other_specify = ?,
            health_none          = ?,
            requires_medicine    = ?,
            medicine_name        = ?,
            school_name          = ?,
            course               = ?,
            year_level           = ?,
            gwa_gpa              = ?,
            prio_score           = ?,
            status               = ?,
            submitted_at         = ?
        WHERE userId = ?
    ';

    $stmt = $conn->prepare($sqlUpdate);
    if (!$stmt) {
        $_SESSION['beneficiary_save_status'] = 'error';
        $_SESSION['beneficiary_save_msg']    = 'Prepare failed (update): ' . $conn->error;
        mysqli_close($conn);
        return;
    }

    $stmt->bind_param(
        'sssssiisiisiiiisiisssssissi',
        $data['housing_status'],        // s
        $data['house_material'],        // s
        $data['electricity'],           // s
        $data['water_source'],          // s
        $data['toilet_type'],           // s
        $data['pregnant_or_children'],  // i
        $data['is_pwd'],                // i
        $data['pwd_id_number'],         // s
        $data['is_solo_parent'],        // i
        $data['is_indigenous'],         // i
        $data['pension_status'],        // s
        $data['health_hypertension'],   // i
        $data['health_diabetes'],       // i
        $data['health_asthma'],         // i
        $data['health_other'],          // i
        $data['health_other_specify'],  // s
        $data['health_none'],           // i
        $data['requires_medicine'],     // i
        $data['medicine_name'],         // s
        $data['school_name'],           // s
        $data['course'],                // s
        $data['year_level'],            // s
        $data['gwa_gpa'],               // s
        $data['prio_score'],            // i
        $data['status'],                // s
        $data['submitted_at'],          // s
        $userId                         // i  WHERE
    );

} else {
    // ─── INSERT ───
    // 26 columns (userId + 25 fields)
    // Types:
    //   i  userId
    //   s  housing_status
    //   s  house_material
    //   s  electricity
    //   s  water_source
    //   s  toilet_type
    //   i  pregnant_or_children
    //   i  is_pwd
    //   s  pwd_id_number
    //   i  is_solo_parent
    //   i  is_indigenous
    //   s  pension_status
    //   i  health_hypertension
    //   i  health_diabetes
    //   i  health_asthma
    //   i  health_other
    //   s  health_other_specify
    //   i  health_none
    //   i  requires_medicine
    //   s  medicine_name
    //   s  school_name
    //   s  course
    //   s  year_level
    //   s  gwa_gpa
    //   i  prio_score
    //   s  status
    //   s  submitted_at

    $sqlInsert = '
        INSERT INTO tbl_beneficiary (
            userId,
            housing_status, house_material,
            electricity, water_source, toilet_type,
            pregnant_or_children,
            is_pwd, pwd_id_number,
            is_solo_parent, is_indigenous,
            pension_status,
            health_hypertension, health_diabetes, health_asthma,
            health_other, health_other_specify, health_none,
            requires_medicine, medicine_name,
            school_name, course, year_level, gwa_gpa,
            prio_score, status,
            submitted_at
        ) VALUES (
            ?,
            ?, ?,
            ?, ?, ?,
            ?,
            ?, ?,
            ?, ?,
            ?,
            ?, ?, ?,
            ?, ?, ?,
            ?, ?,
            ?, ?, ?, ?,
            ?, ?,
            ?
        )
    ';

    $stmt = $conn->prepare($sqlInsert);
    if (!$stmt) {
        $_SESSION['beneficiary_save_status'] = 'error';
        $_SESSION['beneficiary_save_msg']    = 'Prepare failed (insert): ' . $conn->error;
        mysqli_close($conn);
        return;
    }

    $stmt->bind_param(
        'isssssiisiisiiiisiisssssiss',
        $userId,
        $data['housing_status'],
        $data['house_material'],
        $data['electricity'],
        $data['water_source'],
        $data['toilet_type'],
        $data['pregnant_or_children'],
        $data['is_pwd'],
        $data['pwd_id_number'],
        $data['is_solo_parent'],
        $data['is_indigenous'],
        $data['pension_status'],
        $data['health_hypertension'],
        $data['health_diabetes'],
        $data['health_asthma'],
        $data['health_other'],
        $data['health_other_specify'],
        $data['health_none'],
        $data['requires_medicine'],
        $data['medicine_name'],
        $data['school_name'],
        $data['course'],
        $data['year_level'],
        $data['gwa_gpa'],
        $data['prio_score'],
        $data['status'],
        $data['submitted_at']
    );
}

// ─── Execute & report ───
if ($stmt->execute()) {
    $_SESSION['beneficiary_save_status'] = 'ok';
    $_SESSION['beneficiary_save_msg']    = $existingId
        ? 'Beneficiary record updated successfully.'
        : 'Beneficiary record saved successfully.';
    // Expose score to calling page (e.g. to show in a success banner)
    $_SESSION['beneficiary_prio_score']  = $data['prio_score'];

    // Clear form data - prevents double-save on page refresh
    unset($_SESSION['beneficiary_form']);

} else {
    $_SESSION['beneficiary_save_status'] = 'error';
    $_SESSION['beneficiary_save_msg']    = 'Execute failed: ' . $stmt->error;
}

$stmt->close();
mysqli_close($conn);

// Redirect back to beneficiary form with save result (show toast there)
header('Location: beneficiaryForm.php');
exit;
