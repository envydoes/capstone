<?php
session_start();
// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once '../config/db_connection.php';
require_once '../includes/check_permissions.php';
require_once '../includes/site_config.php';
require_once '../includes/analytics_report_items.php';

// Check role and redirect if not admin or active staff access
$role = $_SESSION['account_role'] ?? '';
$isAdminAccess = $role === 'admin' || !empty($_SESSION['staff_permissions']);
if (!$isAdminAccess && $role !== 'custom_admin') {
    switch ($role) {
        case 'resident':
        case 'resident,business/apartment owner':
            header('Location: ../resident/residentLanding.php');
            break;
        case 'non-resident':
        case 'non-resident,business/apartment owner':
            header('Location: ../nonResident/nonresidentLanding.php');
            break;
        default:
            header('Location: ../landing.php');
    }
    exit;
}

if ($role !== 'admin') {
    require_permission($conn, 'dashboard');
}

$siteSettings = site_config_load($conn);
$myPermissions = get_my_permissions($conn);
$canBeneficiary = in_array('manage_beneficiaries', $myPermissions, true);

/**
 * The database has no "province" column - the only place that info lives
 * is the free-text Nominatim address string saved in $siteSettings['map_query']
 * (Settings > Landing Page > Map Display), e.g.:
 *   "075, Purok 3, Sumacab Este, Cabanatuan City, Nueva Ecija, Central Luzon, 3100, Philippines"
 *   "Sumacab Este, Cabanatuan City, Nueva Ecija, Central Luzon, Philippines"  (no zip)
 *
 * Reading from the end: Country -> (optional) Zip Code -> Region -> Province.
 * Nominatim doesn't label which segment is the region vs. the province, so we
 * can't reliably tell them apart - we settle for the region, which is the
 * segment right after the country/zip are stripped off.
 */
function extract_region_from_map_query(string $mapQuery): string {
    $parts = array_map('trim', explode(',', $mapQuery));
    $parts = array_values(array_filter($parts, fn($p) => $p !== ''));
    if (empty($parts)) return '';

    // Drop trailing country (e.g. "Philippines")
    if (strtolower(end($parts)) === 'philippines') {
        array_pop($parts);
    }

    // Drop trailing postal/zip code, if present (PH zip codes are 3-4 digits)
    if (!empty($parts) && preg_match('/^\d{3,4}$/', end($parts))) {
        array_pop($parts);
    }

    // Whatever's left at the end is our best guess at "the region"
    return !empty($parts) ? end($parts) : '';
}

$mapRegion = extract_region_from_map_query($siteSettings['map_query'] ?? '');

// Total Registered Residents
$registeredResidents = (int) mysqli_fetch_assoc(
    mysqli_query($conn, "
        SELECT COUNT(*) AS total
        FROM tbl_userinfo
        WHERE LOWER(userStatus) = 'approved'
          AND account_role_csv LIKE '%resident%'
          AND NOT account_role_csv LIKE '%non-resident%'
    ")
)['total'];

// Active Borrowed Equipment 
$activeBorrowedEquipment = (int) mysqli_fetch_assoc(
    mysqli_query($conn, "
        SELECT COUNT(*) AS total
        FROM tbl_equipmentrequest
        WHERE LOWER(status) = 'borrowed'
    ")
)['total'];

// Active Listings
$activeListings = (int) mysqli_fetch_assoc(
    mysqli_query($conn, "
        SELECT COUNT(*) AS total
        FROM tbl_busaptlisting
    ")
)['total'];

// Non-resident users
$nonResidentUsers = (int) mysqli_fetch_assoc(
    mysqli_query($conn, "
        SELECT COUNT(*) AS total
        FROM tbl_userinfo
        WHERE account_role_csv LIKE '%non-resident%'
    ")
)['total'];

// Demographic distributions
$genderStats = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        SUM(CASE WHEN LOWER(TRIM(gender)) = 'male' THEN 1 ELSE 0 END) AS male_total,
        SUM(CASE WHEN LOWER(TRIM(gender)) = 'female' THEN 1 ELSE 0 END) AS female_total
    FROM tbl_userinfo
    WHERE gender IS NOT NULL AND TRIM(gender) <> ''
      AND LOWER(userStatus) = 'approved'
"));

$maleTotal = (int) ($genderStats['male_total'] ?? 0);
$femaleTotal = (int) ($genderStats['female_total'] ?? 0);

// Monthly Service Request from the last 6 months
$monthLabels = [];
for ($i = 5; $i >= 0; $i--) {
    $monthKey = date('Y-m', strtotime("-$i month"));
    $monthLabels[$monthKey] = date('M', strtotime("-$i month"));
}

$documentMonthly = [];
$documentRes = mysqli_query($conn, "
    SELECT DATE_FORMAT(created_at, '%Y-%m') AS month_key, COUNT(*) AS total
    FROM tbl_requestdocs
    WHERE created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
");
while ($row = mysqli_fetch_assoc($documentRes)) {
    $documentMonthly[$row['month_key']] = (int) $row['total'];
}

$beneficiaryMonthly = [];
$beneficiaryRes = mysqli_query($conn, "
    SELECT DATE_FORMAT(created_at, '%Y-%m') AS month_key, COUNT(*) AS total
    FROM tbl_beneficiary
    WHERE created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
");
while ($row = mysqli_fetch_assoc($beneficiaryRes)) {
    $beneficiaryMonthly[$row['month_key']] = (int) $row['total'];
}

$equipmentMonthly = [];
$equipmentRes = mysqli_query($conn, "
    SELECT DATE_FORMAT(createdAt, '%Y-%m') AS month_key, COUNT(*) AS total
    FROM tbl_equipmentrequest
    WHERE createdAt >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
    GROUP BY DATE_FORMAT(createdAt, '%Y-%m')
");
while ($row = mysqli_fetch_assoc($equipmentRes)) {
    $equipmentMonthly[$row['month_key']] = (int) $row['total'];
}

$chartRows = [];
foreach ($monthLabels as $monthKey => $monthLabel) {
    $chartRows[] = [
        $monthLabel,
        $documentMonthly[$monthKey] ?? 0,
        $beneficiaryMonthly[$monthKey] ?? 0,
        $equipmentMonthly[$monthKey] ?? 0,
    ];
}

// Request Status Overview
$requestStatusDoc = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        SUM(CASE WHEN LOWER(status) = 'approved' THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN LOWER(status) = 'pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN LOWER(status) = 'rejected' THEN 1 ELSE 0 END) AS rejected
    FROM tbl_requestdocs
"));

$requestStatusBeneficiary = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        SUM(CASE WHEN LOWER(status) = 'approved' THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN LOWER(status) = 'pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN LOWER(status) = 'rejected' THEN 1 ELSE 0 END) AS rejected
    FROM tbl_beneficiary
"));

$requestStatusEquipment = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        SUM(CASE WHEN LOWER(status) IN ('borrowed', 'returned') THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN LOWER(status) = 'pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN LOWER(status) = 'rejected' THEN 1 ELSE 0 END) AS rejected
    FROM tbl_equipmentrequest
"));

$approvedRequests = (int) ($requestStatusDoc['approved'] ?? 0)
                   + (int) ($requestStatusBeneficiary['approved'] ?? 0)
                   + (int) ($requestStatusEquipment['approved'] ?? 0);

$pendingRequests = (int) ($requestStatusDoc['pending'] ?? 0)
                 + (int) ($requestStatusBeneficiary['pending'] ?? 0)
                 + (int) ($requestStatusEquipment['pending'] ?? 0);

$rejectedRequests = (int) ($requestStatusDoc['rejected'] ?? 0)
                  + (int) ($requestStatusBeneficiary['rejected'] ?? 0)
                  + (int) ($requestStatusEquipment['rejected'] ?? 0);

$totalStatusRequests = $approvedRequests + $pendingRequests + $rejectedRequests;

$approvedPct = $totalStatusRequests > 0 ? (int) round(($approvedRequests / $totalStatusRequests) * 100) : 0;
$pendingPct  = $totalStatusRequests > 0 ? (int) round(($pendingRequests / $totalStatusRequests) * 100) : 0;
$rejectedPct = $totalStatusRequests > 0 ? (int) round(($rejectedRequests / $totalStatusRequests) * 100) : 0;

// Age Distribution
$ageStats = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        SUM(CASE WHEN TIMESTAMPDIFF(YEAR, birthday, CURDATE()) BETWEEN 0 AND 17 THEN 1 ELSE 0 END) AS age_0_17,
        SUM(CASE WHEN TIMESTAMPDIFF(YEAR, birthday, CURDATE()) BETWEEN 18 AND 30 THEN 1 ELSE 0 END) AS age_18_30,
        SUM(CASE WHEN TIMESTAMPDIFF(YEAR, birthday, CURDATE()) BETWEEN 31 AND 45 THEN 1 ELSE 0 END) AS age_31_45,
        SUM(CASE WHEN TIMESTAMPDIFF(YEAR, birthday, CURDATE()) BETWEEN 46 AND 59 THEN 1 ELSE 0 END) AS age_46_60,
        SUM(CASE WHEN TIMESTAMPDIFF(YEAR, birthday, CURDATE()) >= 60 THEN 1 ELSE 0 END) AS age_60_plus
    FROM tbl_userinfo
    WHERE birthday IS NOT NULL AND LOWER(userStatus) = 'approved'
"));

$age_0_17 = (int) ($ageStats['age_0_17'] ?? 0);
$age_18_30 = (int) ($ageStats['age_18_30'] ?? 0);
$age_31_45 = (int) ($ageStats['age_31_45'] ?? 0);
$age_46_60 = (int) ($ageStats['age_46_60'] ?? 0);
$age_60_plus = (int) ($ageStats['age_60_plus'] ?? 0);

// Income Bracket Distribution
$incomeBracketStats = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        SUM(CASE WHEN monthly_income IS NOT NULL AND monthly_income < 5000 THEN 1 ELSE 0 END) AS below_5k,
        SUM(CASE WHEN monthly_income IS NOT NULL AND monthly_income >= 5000 AND monthly_income < 10000 THEN 1 ELSE 0 END) AS from_5k_10k,
        SUM(CASE WHEN monthly_income IS NOT NULL AND monthly_income >= 10000 AND monthly_income < 20000 THEN 1 ELSE 0 END) AS from_10k_20k,
        SUM(CASE WHEN monthly_income IS NOT NULL AND monthly_income >= 20000 AND monthly_income < 40000 THEN 1 ELSE 0 END) AS from_20k_40k,
        SUM(CASE WHEN monthly_income IS NOT NULL AND monthly_income >= 40000 THEN 1 ELSE 0 END) AS above_40k
    FROM tbl_userinfo
    WHERE LOWER(userStatus) = 'approved'
      AND monthly_income IS NOT NULL
"));

// Income vs Age Group
$incomeAgeStats = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        SUM(CASE WHEN age BETWEEN 0 AND 17 AND monthly_income < 5000 THEN 1 ELSE 0 END) AS age0_17_below5k,
        SUM(CASE WHEN age BETWEEN 0 AND 17 AND monthly_income >= 5000 AND monthly_income < 10000 THEN 1 ELSE 0 END) AS age0_17_5k10k,
        SUM(CASE WHEN age BETWEEN 0 AND 17 AND monthly_income >= 10000 AND monthly_income < 20000 THEN 1 ELSE 0 END) AS age0_17_10k20k,
        SUM(CASE WHEN age BETWEEN 0 AND 17 AND monthly_income >= 20000 AND monthly_income < 40000 THEN 1 ELSE 0 END) AS age0_17_20k40k,
        SUM(CASE WHEN age BETWEEN 0 AND 17 AND monthly_income >= 40000 THEN 1 ELSE 0 END) AS age0_17_above40k,

        SUM(CASE WHEN age BETWEEN 18 AND 30 AND monthly_income < 5000 THEN 1 ELSE 0 END) AS age18_30_below5k,
        SUM(CASE WHEN age BETWEEN 18 AND 30 AND monthly_income >= 5000 AND monthly_income < 10000 THEN 1 ELSE 0 END) AS age18_30_5k10k,
        SUM(CASE WHEN age BETWEEN 18 AND 30 AND monthly_income >= 10000 AND monthly_income < 20000 THEN 1 ELSE 0 END) AS age18_30_10k20k,
        SUM(CASE WHEN age BETWEEN 18 AND 30 AND monthly_income >= 20000 AND monthly_income < 40000 THEN 1 ELSE 0 END) AS age18_30_20k40k,
        SUM(CASE WHEN age BETWEEN 18 AND 30 AND monthly_income >= 40000 THEN 1 ELSE 0 END) AS age18_30_above40k,

        SUM(CASE WHEN age BETWEEN 31 AND 45 AND monthly_income < 5000 THEN 1 ELSE 0 END) AS age31_45_below5k,
        SUM(CASE WHEN age BETWEEN 31 AND 45 AND monthly_income >= 5000 AND monthly_income < 10000 THEN 1 ELSE 0 END) AS age31_45_5k10k,
        SUM(CASE WHEN age BETWEEN 31 AND 45 AND monthly_income >= 10000 AND monthly_income < 20000 THEN 1 ELSE 0 END) AS age31_45_10k20k,
        SUM(CASE WHEN age BETWEEN 31 AND 45 AND monthly_income >= 20000 AND monthly_income < 40000 THEN 1 ELSE 0 END) AS age31_45_20k40k,
        SUM(CASE WHEN age BETWEEN 31 AND 45 AND monthly_income >= 40000 THEN 1 ELSE 0 END) AS age31_45_above40k,

        SUM(CASE WHEN age BETWEEN 46 AND 60 AND monthly_income < 5000 THEN 1 ELSE 0 END) AS age46_60_below5k,
        SUM(CASE WHEN age BETWEEN 46 AND 60 AND monthly_income >= 5000 AND monthly_income < 10000 THEN 1 ELSE 0 END) AS age46_60_5k10k,
        SUM(CASE WHEN age BETWEEN 46 AND 60 AND monthly_income >= 10000 AND monthly_income < 20000 THEN 1 ELSE 0 END) AS age46_60_10k20k,
        SUM(CASE WHEN age BETWEEN 46 AND 60 AND monthly_income >= 20000 AND monthly_income < 40000 THEN 1 ELSE 0 END) AS age46_60_20k40k,
        SUM(CASE WHEN age BETWEEN 46 AND 60 AND monthly_income >= 40000 THEN 1 ELSE 0 END) AS age46_60_above40k,

        SUM(CASE WHEN age >= 61 AND monthly_income < 5000 THEN 1 ELSE 0 END) AS age61_plus_below5k,
        SUM(CASE WHEN age >= 61 AND monthly_income >= 5000 AND monthly_income < 10000 THEN 1 ELSE 0 END) AS age61_plus_5k10k,
        SUM(CASE WHEN age >= 61 AND monthly_income >= 10000 AND monthly_income < 20000 THEN 1 ELSE 0 END) AS age61_plus_10k20k,
        SUM(CASE WHEN age >= 61 AND monthly_income >= 20000 AND monthly_income < 40000 THEN 1 ELSE 0 END) AS age61_plus_20k40k,
        SUM(CASE WHEN age >= 61 AND monthly_income >= 40000 THEN 1 ELSE 0 END) AS age61_plus_above40k
    FROM (
        SELECT
            TIMESTAMPDIFF(YEAR, birthday, CURDATE()) AS age,
            monthly_income
        FROM tbl_userinfo
        WHERE birthday IS NOT NULL
          AND monthly_income IS NOT NULL
          AND LOWER(userStatus) = 'approved'
    ) AS income_age
"));

$incomeCounts = [
    'Below ₱5k/mo' => (int) ($incomeBracketStats['below_5k'] ?? 0),
    '₱5k - ₱10k/mo' => (int) ($incomeBracketStats['from_5k_10k'] ?? 0),
    '₱10k - ₱20k/mo' => (int) ($incomeBracketStats['from_10k_20k'] ?? 0),
    '₱20k - ₱40k/mo' => (int) ($incomeBracketStats['from_20k_40k'] ?? 0),
    'Above ₱40k/mo' => (int) ($incomeBracketStats['above_40k'] ?? 0),
];

$totalIncomeCount = array_sum($incomeCounts);

$incomeAgeChartData = [
    ['Age Group', 'Below ₱5k', '₱5k-₱10k', '₱10k-₱20k', '₱20k-₱40k', 'Above ₱40k'],
    ['0-17',
        (int) ($incomeAgeStats['age0_17_below5k'] ?? 0),
        (int) ($incomeAgeStats['age0_17_5k10k'] ?? 0),
        (int) ($incomeAgeStats['age0_17_10k20k'] ?? 0),
        (int) ($incomeAgeStats['age0_17_20k40k'] ?? 0),
        (int) ($incomeAgeStats['age0_17_above40k'] ?? 0),
    ],
    ['18-30',
        (int) ($incomeAgeStats['age18_30_below5k'] ?? 0),
        (int) ($incomeAgeStats['age18_30_5k10k'] ?? 0),
        (int) ($incomeAgeStats['age18_30_10k20k'] ?? 0),
        (int) ($incomeAgeStats['age18_30_20k40k'] ?? 0),
        (int) ($incomeAgeStats['age18_30_above40k'] ?? 0),
    ],
    ['31-45',
        (int) ($incomeAgeStats['age31_45_below5k'] ?? 0),
        (int) ($incomeAgeStats['age31_45_5k10k'] ?? 0),
        (int) ($incomeAgeStats['age31_45_10k20k'] ?? 0),
        (int) ($incomeAgeStats['age31_45_20k40k'] ?? 0),
        (int) ($incomeAgeStats['age31_45_above40k'] ?? 0),
    ],
    ['46-60',
        (int) ($incomeAgeStats['age46_60_below5k'] ?? 0),
        (int) ($incomeAgeStats['age46_60_5k10k'] ?? 0),
        (int) ($incomeAgeStats['age46_60_10k20k'] ?? 0),
        (int) ($incomeAgeStats['age46_60_20k40k'] ?? 0),
        (int) ($incomeAgeStats['age46_60_above40k'] ?? 0),
    ],
    ['60+',
        (int) ($incomeAgeStats['age61_plus_below5k'] ?? 0),
        (int) ($incomeAgeStats['age61_plus_5k10k'] ?? 0),
        (int) ($incomeAgeStats['age61_plus_10k20k'] ?? 0),
        (int) ($incomeAgeStats['age61_plus_20k40k'] ?? 0),
        (int) ($incomeAgeStats['age61_plus_above40k'] ?? 0),
    ],
];

// ───────────────────────────────────────────────────────────────────
// ADDITIONAL REPORTS - Resident / Beneficiary / Operations
//
// Note: there is no dedicated "purok/zone" column in tbl_userinfo - the
// residentManagement.php add/edit form stores that under the `street`
// field (e.g. "Purok 5"), so "population by purok" is derived from that.
// There is also no "disability type" column anywhere (tbl_beneficiary
// only has is_pwd + pwd_id_number) - the PWD registry reflects exactly
// what's stored: whether someone is PWD-registered and their ID number,
// not a breakdown by type of disability.
//
// NOTE: The five searchable/filterable tables below (Senior Citizens,
// PWD Registry, Non-Beneficiaries, Owner Directory, Borrowed Items) are
// now fetched LIVE via AJAX (see admin/ajax/*.php) instead of being
// pre-loaded into JS arrays here. This lets the search/filter loading
// spinner reflect the real database round-trip time, not a fake delay.
// Only the chart-only aggregates below stay server-rendered.
// ───────────────────────────────────────────────────────────────────

// ---- RESIDENT: population by purok (derived from street field) ----
$purokRes = mysqli_query($conn, "
    SELECT COALESCE(NULLIF(TRIM(street), ''), 'Unspecified') AS purok, COUNT(*) AS total
    FROM tbl_userinfo
    WHERE LOWER(userStatus) = 'approved'
    GROUP BY purok
    ORDER BY purok
");
$purokData = [];
while ($r = mysqli_fetch_assoc($purokRes)) { $purokData[] = [$r['purok'], (int) $r['total']]; }

// ---- RESIDENT: age bracket breakdown (minor / working-age / senior) ----
$ageBracket = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        SUM(CASE WHEN TIMESTAMPDIFF(YEAR, birthday, CURDATE()) < 18 THEN 1 ELSE 0 END) AS minors,
        SUM(CASE WHEN TIMESTAMPDIFF(YEAR, birthday, CURDATE()) BETWEEN 18 AND 59 THEN 1 ELSE 0 END) AS working_age,
        SUM(CASE WHEN TIMESTAMPDIFF(YEAR, birthday, CURDATE()) >= 60 THEN 1 ELSE 0 END) AS seniors
    FROM tbl_userinfo
    WHERE birthday IS NOT NULL AND LOWER(userStatus) = 'approved'
"));
$bracketMinors     = (int) ($ageBracket['minors'] ?? 0);
$bracketWorkingAge = (int) ($ageBracket['working_age'] ?? 0);
$bracketSeniors    = (int) ($ageBracket['seniors'] ?? 0);

// ---- BENEFICIARY: approved beneficiaries by program category ----
// Mirrors the eligibility rules used in beneficiaryManagement.php
$benProgRes = mysqli_query($conn, "
    SELECT b.*, u.birthday
    FROM tbl_beneficiary b
    JOIN tbl_userinfo u ON b.userId = u.userID
    WHERE b.status = 'approved'
");
$progCounts = ['4ps' => 0, 'senior' => 0, 'scholarship' => 0, 'pwd' => 0, 'kabataan' => 0, 'voters' => 0];
while ($r = mysqli_fetch_assoc($benProgRes)) {
    $age = 0;
    if (!empty($r['birthday'])) {
        $bday = new DateTime($r['birthday']);
        $age  = (new DateTime())->diff($bday)->y;
    }
    $bad_house  = in_array(strtolower($r['housing_status'] ?? ''), ['informal_settler', 'shared', 'government_housing']);
    $bad_mat    = in_array(strtolower($r['house_material'] ?? ''), ['light_materials', 'makeshift', 'wood']);
    $bad_elec   = in_array(strtolower($r['electricity'] ?? ''), ['shared', 'no_electricity']);
    $bad_water  = (strtolower($r['water_source'] ?? '') === 'shared_well');
    $bad_toilet = in_array(strtolower($r['toilet_type'] ?? ''), ['none_pit', 'shared_public']);
    $preg_child = !empty($r['pregnant_or_children']) && $r['pregnant_or_children'] == 1;
    $income     = (float) ($r['monthly_income'] ?? 0);
    if ($bad_house && $bad_mat && $bad_elec && $bad_water && $bad_toilet && $preg_child && $income < 14000) {
        $progCounts['4ps']++;
    }
    if ($age >= 60) { $progCounts['senior']++; }
    $gwa = ($r['gwa_gpa'] ?? '') !== '' ? (float) $r['gwa_gpa'] : null;
    if (($r['school_name'] ?? '') !== '' && ($r['year_level'] ?? '') !== '' && $gwa !== null && $gwa >= 1.00 && $gwa <= 1.75) {
        $progCounts['scholarship']++;
    }
    if (!empty($r['is_pwd']) && !empty($r['pwd_id_number'])) { $progCounts['pwd']++; }
    if ($age >= 15 && $age <= 30) { $progCounts['kabataan']++; }
    if ($age >= 18) { $progCounts['voters']++; }
}

// ---- BUSINESS/APARTMENT: counts by type + occupancy ----
$listingTypeRow = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        SUM(CASE WHEN listingType = 'apartment' THEN 1 ELSE 0 END) AS apt_count,
        SUM(CASE WHEN listingType = 'business'  THEN 1 ELSE 0 END) AS biz_count
    FROM tbl_busaptlisting
"));
$aptCountTotal = (int) ($listingTypeRow['apt_count'] ?? 0);
$bizCountTotal = (int) ($listingTypeRow['biz_count'] ?? 0);

$aptStatusRow = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        SUM(CASE WHEN aptStatus = 'available' THEN 1 ELSE 0 END) AS available_units,
        SUM(CASE WHEN aptStatus = 'occupied'  THEN 1 ELSE 0 END) AS occupied_units
    FROM tbl_busaptlisting WHERE listingType = 'apartment'
"));
$availableUnits = (int) ($aptStatusRow['available_units'] ?? 0);
$occupiedUnits  = (int) ($aptStatusRow['occupied_units'] ?? 0);

// ---- EQUIPMENT: most-borrowed items (chart only, no search box) ----
$mostBorrowedRes = mysqli_query($conn, "
    SELECT e.equipmentName, COUNT(*) AS times_borrowed
    FROM tbl_equipmentrequest r
    JOIN tbl_equipmentlist e ON r.equipmentId = e.equipmentId
    WHERE LOWER(r.status) IN ('borrowed', 'returned')
    GROUP BY e.equipmentId
    ORDER BY times_borrowed DESC
    LIMIT 5
");
$mostBorrowed = [];
while ($r = mysqli_fetch_assoc($mostBorrowedRes)) { $mostBorrowed[] = $r; }

// ---- DOCUMENT REQUESTS: by type & status, avg turnaround, monthly volume ----
$docTypeStatusRes = mysqli_query($conn, "
    SELECT document_type, status, COUNT(*) AS total
    FROM tbl_requestdocs
    GROUP BY document_type, status
    ORDER BY document_type
");
$docTypeStatus = [];
while ($r = mysqli_fetch_assoc($docTypeStatusRes)) { $docTypeStatus[] = $r; }

$avgTurnaroundRow = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT AVG(TIMESTAMPDIFF(HOUR, submitted_at, updated_at)) AS avg_hours
    FROM tbl_requestdocs
    WHERE LOWER(status) IN ('approved', 'rejected') AND submitted_at IS NOT NULL
"));
$avgTurnaroundHours = round((float) ($avgTurnaroundRow['avg_hours'] ?? 0), 1);

$docMonthlyRes = mysqli_query($conn, "
    SELECT DATE_FORMAT(submitted_at, '%Y-%m') AS month_key, COUNT(*) AS total
    FROM tbl_requestdocs
    WHERE submitted_at IS NOT NULL
    GROUP BY month_key
    ORDER BY month_key ASC
");
$docMonthly = [];
while ($r = mysqli_fetch_assoc($docMonthlyRes)) { $docMonthly[] = [$r['month_key'], (int) $r['total']]; }

// ---- ACCOUNTS: by role, active/inactive, registration trend ----
$roleCountRes = mysqli_query($conn, "SELECT account_role, COUNT(*) AS total FROM tbl_useracc GROUP BY account_role");
$roleCounts = [];
while ($r = mysqli_fetch_assoc($roleCountRes)) { $roleCounts[] = [$r['account_role'], (int) $r['total']]; }

$statusCountRow = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        SUM(CASE WHEN LOWER(userStatus) = 'approved' THEN 1 ELSE 0 END) AS active_count,
        SUM(CASE WHEN LOWER(userStatus) IN ('pending', 'rejected', 'disabled') THEN 1 ELSE 0 END) AS inactive_count
    FROM tbl_userinfo
"));
$activeAccounts   = (int) ($statusCountRow['active_count'] ?? 0);
$inactiveAccounts = (int) ($statusCountRow['inactive_count'] ?? 0);

$regTrendRes = mysqli_query($conn, "
    SELECT DATE_FORMAT(dateRegistered, '%Y-%m') AS month_key, COUNT(*) AS total
    FROM tbl_userinfo
    GROUP BY month_key
    ORDER BY month_key ASC
");
$regTrend = [];
while ($r = mysqli_fetch_assoc($regTrendRes)) { $regTrend[] = [$r['month_key'], (int) $r['total']]; }

function sidebar_menu_allowed(string $key, string $role, array $myPermissions): bool
{
  if ($role === 'admin') {
    return true;
  }

  return in_array($key, $myPermissions, true);
}

$sidebarSections = [
  'Management' => [
    [
      'key' => 'dashboard',
      'label' => 'Dashboard',
      'icon' => 'fa-chart-bar',
      'href' => 'adminDashboard.php',
      'active' => true,
    ],
    [
      'key' => 'manage_users',
      'label' => 'User Management',
      'icon' => 'fa-user',
      'href' => 'userManagement.php',
      'admin_only' => true,
    ],
    [
      'key' => 'manage_residents',
      'label' => 'Resident Management',
      'icon' => 'fa-house-chimney-user',
      'href' => 'residentManagement.php',
    ],
    [
      'key' => 'manage_beneficiaries',
      'label' => 'Beneficiary Management',
      'icon' => 'fa-hand-holding-heart',
      'href' => 'beneficiaryManagement.php',
    ],
    [
      'key' => 'manage_documents',
      'label' => 'Document Request',
      'icon' => 'fa-file-lines',
      'href' => 'documentRequest.php',
    ],
    [
      'key' => 'manage_borrowing',
      'label' => 'Borrowing System',
      'icon' => 'fa-hammer',
      'href' => 'borrowingSystem.php',
    ],
  ],
  'Community' => [
    [
      'key' => 'manage_listings',
      'label' => 'Community Listings',
      'icon' => 'fa-building',
      'href' => 'communityListings.php',
    ],
    [
      'key' => 'manage_announcements',
      'label' => 'Announcements',
      'icon' => 'fa-pen-to-square',
      'href' => 'announcement.php',
    ],
  ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="../assets/responsive-global.css">
  <title>Dashboard - <?= e($siteSettings['site_title']) ?></title>
  <link rel="icon" href="<?= e(site_config_logo_url($siteSettings, '../')) ?>" type="image/png">
  <script src="https://cdn.tailwindcss.com/3.4.16"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <script src="https://www.gstatic.com/charts/loader.js"></script>
  <?= site_config_css_vars($siteSettings) ?>
  <style>
    :root {
      --site-primary-dark:   color-mix(in srgb, var(--site-primary) 55%, black);
      --site-primary-darker: color-mix(in srgb, var(--site-primary) 75%, black);
      --site-primary-light:  color-mix(in srgb, var(--site-primary) 55%, white);
      --site-primary-pale:   color-mix(in srgb, var(--site-primary) 12%, white);
    }
    * { box-sizing: border-box; }
    body { font-family: 'DM Sans', sans-serif; background: var(--site-primary-pale); margin: 0; }

    .sidebar {
      width: 260px;
      flex-shrink: 0;
      background: linear-gradient(180deg, var(--site-primary-dark) 0%, var(--site-primary-darker) 55%, var(--site-primary) 100%);
      display: flex;
      flex-direction: column;
      position: sticky;
      top: 0;
      height: 100vh;
      overflow: hidden;
      transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .sidebar.collapsed { width: 0; }
    .sidebar:not(.collapsed) { overflow-y: auto; }
    .sidebar::-webkit-scrollbar { width: 4px; }
    .sidebar::-webkit-scrollbar-thumb { background: rgba(134,239,172,0.2); border-radius: 4px; }

    /* inner wrapper stays at full 260px so content never squishes */
    .sidebar-inner {
      width: 260px;
      min-width: 260px;
      display: flex;
      flex-direction: column;
      height: 100%;
    }

    /* ?? Logo bar ?? */
    .sidebar-logo {
      padding: 20px 18px 16px;
      border-bottom: 1px solid rgba(134,239,172,0.12);
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-shrink: 0;
    }

    /* ?? Section labels ?? */
    .section-label {
      padding: 18px 18px 6px;
      font-size: 0.62rem;
      font-weight: 700;
      letter-spacing: 0.13em;
      text-transform: uppercase;
      color: rgba(255,255,255,0.45);
      white-space: nowrap;
    }

    /* ?? Menu items ?? */
    .menu-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      width: calc(100% - 16px);
      padding: 10px 14px;
      margin: 1px 8px;
      border-radius: 10px;
      color: rgba(255,255,255,0.72);
      font-size: 0.84rem;
      font-weight: 500;
      text-decoration: none;
      border: none;
      background: none;
      text-align: left;
      cursor: pointer;
      transition: background 0.18s, color 0.18s;
      white-space: nowrap;
    }
    .menu-item:hover  { background: rgba(255,255,255,0.07); color: #fff; }
    .menu-item.active { background: rgba(255,255,255,0.13); color: #fff; }
    .menu-left { display: flex; align-items: center; gap: 11px; }
    .menu-item .mi { width: 17px; text-align: center; font-size: 0.85rem; flex-shrink: 0; }
    .active-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--site-primary-light); flex-shrink: 0; }

    /* ?? Collapse button (inside header) ?? */
    .collapse-btn {
      width: 28px; height: 28px;
      border-radius: 8px;
      background: rgba(255,255,255,0.1);
      border: none;
      cursor: pointer;
      color: var(--site-primary-light);
      font-size: 0.72rem;
      display: flex; align-items: center; justify-content: center;
      flex-shrink: 0;
      transition: background 0.2s;
    }
    .collapse-btn:hover { background: rgba(255,255,255,0.22); }

    /* ?? Floating expand button (only visible when collapsed) ?? */
    .expand-btn {
      position: fixed;
      top: 18px;
      left: 12px;
      z-index: 200;
      width: 36px; height: 36px;
      border-radius: 10px;
      background: var(--site-primary-darker);
      border: 1px solid rgba(134,239,172,0.25);
      color: var(--site-primary-light);
      font-size: 0.82rem;
      cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      box-shadow: 0 4px 16px rgba(5,46,22,0.4);
      /* hidden by default */
      opacity: 0;
      pointer-events: none;
      transform: translateX(-8px);
      transition: opacity 0.25s, transform 0.25s, background 0.2s;
    }
    .expand-btn.visible {
      opacity: 1;
      pointer-events: auto;
      transform: translateX(0);
    }
    .expand-btn:hover { background: var(--site-primary-dark); }

    /* ?? Bottom section ?? */
    .sidebar-bottom { margin-top: auto; flex-shrink: 0; }
    .sidebar-bottom-links { padding: 0 16px 8px; }
    .sidebar-bottom-links .side-link {
      display: block;
      width: 100%;
      font-size: 0.84rem;
      padding: 8px 8px;
      border-radius: 8px;
      transition: color 0.15s, background 0.15s;
      text-decoration: none;
      white-space: nowrap;
      border: none;
      background: none;
      text-align: left;
      cursor: pointer;
    }

    /* ────────────────────────────────
       TOPBAR
    ──────────────────────────────── */
    .topbar {
      background: #fff;
      border-bottom: 1px solid #e5e7eb;
      padding: 14px 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
    }
    .topbar-title-block { transition: margin-left 0.25s ease; }
    body.sidebar-collapsed .topbar-title-block { margin-left: 46px; }

    /* ?? Stat cards ?? */
    .stat-card {
      background: #fff; border-radius: 14px; padding: 20px 22px;
      border: 1px solid #e5e7eb; box-shadow: 0 2px 12px rgba(21,128,61,0.05);
      display: flex; flex-direction: column; gap: 10px;
      transition: transform 0.2s, box-shadow 0.2s;
    }
    .stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(21,128,61,0.1); }
    .stat-label { font-size: 0.82rem; font-weight: 600; color: #6b7280; }
    .stat-row   { display: flex; align-items: center; gap: 14px; }
    .stat-ico   { font-size: 1.6rem; }
    .stat-num   { font-size: 2.4rem; font-weight: 800; color: #111827; line-height: 1; }

    /* ?? Panel ?? */
    .panel { background: #fff; border-radius: 14px; border: 1px solid #e5e7eb; box-shadow: 0 2px 12px rgba(21,128,61,0.05); overflow: hidden; }
    .panel-head { padding: 16px 18px 0; display: flex; align-items: center; justify-content: space-between; }
    .panel-title { font-weight: 700; color: #1a2e1a; font-size: 0.9rem; }

    /* ?? Info cards ?? */
    .info-card {
      background: #fff; border: 1px solid #e5e7eb; border-radius: 14px;
      padding: 12px 18px; display: flex; align-items: center; gap: 12px;
      box-shadow: 0 2px 10px rgba(21,128,61,0.05);
    }

    /* ?? Horizontal bars ?? */
    .hbar-row { margin-bottom: 14px; }
    .hbar-label-row { display: flex; justify-content: space-between; font-size: 0.75rem; color: #6b7280; margin-bottom: 5px; }
    .hbar-track { height: 8px; background: #f3f4f6; border-radius: 6px; overflow: hidden; }
    .hbar-fill  { height: 100%; border-radius: 6px; background: linear-gradient(90deg, var(--site-primary), var(--site-primary-light)); }

    /* ?? Status bars ?? */
    .status-row  { margin-bottom: 18px; }
    .status-top  { display: flex; align-items: baseline; gap: 8px; margin-bottom: 6px; }
    .status-pct  { font-size: 1.05rem; font-weight: 800; color: #111827; }
    .status-name { font-size: 0.72rem; color: #9ca3af; font-weight: 500; }
    .status-track { height: 7px; background: #f3f4f6; border-radius: 6px; overflow: hidden; }
    .s-approved   { background: var(--site-primary); }
    .s-processing { background: #3b82f6; }
    .s-pending    { background: #f59e0b; }
    .s-rejected   { background: #ef4444; }

    /* ?? Tag chip ?? */
    .tag-chip { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 999px; font-size: 0.68rem; font-weight: 700; }

    /* ?? Animations ?? */
    @keyframes fadeUp { from { opacity:0; transform:translateY(12px); } to { opacity:1; transform:translateY(0); } }
    .f1 { animation: fadeUp 0.4s 0.05s ease both; }
    .f2 { animation: fadeUp 0.4s 0.12s ease both; }
    .f3 { animation: fadeUp 0.4s 0.19s ease both; }

    /* ?? Extra Reports section ?? */
    .report-tab-bar { display: flex; gap: 6px; padding: 4px 18px 14px; flex-wrap: wrap; }
    .report-tab-btn {
      padding: 8px 16px; border-radius: 999px; font-size: 0.8rem; font-weight: 700;
      border: 1.5px solid #e5e7eb; background: #fff; color: #6b7280; cursor: pointer;
      transition: all 0.15s; white-space: nowrap;
    }
    .report-tab-btn:hover { border-color: var(--site-primary); color: var(--site-primary); }
    .report-tab-btn.active { background: var(--site-primary); border-color: var(--site-primary); color: #fff; }
    .report-pane { padding: 4px 18px 20px; }
    .report-pane.hidden { display: none; }
    .subpanel { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px; margin-bottom: 16px; }
    .subpanel:last-child { margin-bottom: 0; }
    .subpanel-title { font-weight: 700; color: #1a2e1a; font-size: 0.85rem; margin-bottom: 10px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .subpanel-note { font-size: 0.72rem; color: #9ca3af; font-weight: 400; }
    .mini-table-wrap { position: relative; overflow-x: auto; border: 1px solid #e5e7eb; border-radius: 10px; background: #fff; max-height: 320px; overflow-y: auto; }
    .mini-table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 480px; }
    .mini-table thead th { position: sticky; top: 0; z-index: 2; background: #f9fafb; padding: 8px 12px; text-align: left; font-size: 0.68rem; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb; white-space: nowrap; }
    .mini-table tbody td { padding: 9px 12px; font-size: 0.8rem; color: #374151; border-bottom: 1px solid #f3f4f6; }
    .mini-table tbody tr:last-child td { border-bottom: none; }
    .mini-table tbody tr:hover { background: #f0fdf4; }
    .mini-badge { display: inline-flex; align-items: center; padding: 2px 9px; border-radius: 999px; font-size: 0.66rem; font-weight: 700; }
    .mini-badge-overdue  { background: #fee2e2; color: #dc2626; }
    .mini-badge-ontime   { background: #dcfce7; color: #15803d; }
    .mini-badge-pending  { background: #fef3c7; color: #d97706; }
    .mini-badge-approved { background: #dcfce7; color: #15803d; }
    .mini-badge-rejected { background: #fee2e2; color: #dc2626; }
    .mini-filter-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 10px; }
    .mini-filter-row input, .mini-filter-row select {
      border: 1.5px solid #e5e7eb; border-radius: 8px; padding: 6px 10px; font-size: 0.78rem;
      font-family: inherit; outline: none; background: #fff; color: #374151;
    }
    .mini-filter-row input:focus, .mini-filter-row select:focus { border-color: var(--site-primary); }
    .mini-stat-inline { display: flex; align-items: baseline; gap: 6px; }
    .mini-stat-inline .num { font-size: 1.6rem; font-weight: 800; color: #111827; }
    .mini-empty { padding: 24px; text-align: center; color: #9ca3af; font-size: 0.8rem; }

    /* ?? Live-search loading overlay (tied to real fetch duration) ?? */
    .mini-loading-overlay {
      position: absolute; inset: 0;
      background: rgba(255,255,255,0.78);
      backdrop-filter: blur(1px);
      display: flex; align-items: center; justify-content: center; gap: 8px;
      z-index: 5;
      opacity: 0; pointer-events: none;
      transition: opacity 0.15s ease;
    }
    .mini-loading-overlay.show { opacity: 1; pointer-events: auto; }
    .mini-spinner {
      width: 20px; height: 20px;
      border: 3px solid #e5e7eb;
      border-top-color: var(--site-primary);
      border-radius: 50%;
      animation: mini-spin 0.6s linear infinite;
    }
    @keyframes mini-spin { to { transform: rotate(360deg); } }
    .mini-loading-text { font-size: 0.75rem; color: #6b7280; font-weight: 600; }
    .mini-row-fade { animation: mini-row-in 0.22s ease both; }
    @keyframes mini-row-in { from { opacity: 0; transform: translateY(3px); } to { opacity: 1; transform: translateY(0); } }
    .mini-search-spinner {
      position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
      width: 14px; height: 14px; border: 2px solid #e5e7eb; border-top-color: var(--site-primary);
      border-radius: 50%; animation: mini-spin 0.6s linear infinite;
      opacity: 0; transition: opacity 0.15s ease; pointer-events: none;
    }
    .mini-search-spinner.show { opacity: 1; }
    .mini-search-wrap { position: relative; }

    /* ?? Global List: filter button + modal ?? */
    .btn-set-conditions {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 8px 16px; background: var(--site-primary); color: #fff;
      border: none; border-radius: 9px; font-size: 0.8rem; font-weight: 700;
      cursor: pointer; transition: background 0.15s, transform 0.1s;
    }
    .btn-set-conditions:hover { background: var(--site-primary-dark); }
    .btn-set-conditions:active { transform: scale(0.97); }
    .btn-print-list {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 8px 16px; background: #fff; color: var(--site-primary-dark);
      border: 1.5px solid var(--site-primary); border-radius: 9px; font-size: 0.8rem; font-weight: 700;
      cursor: pointer; transition: background 0.15s, transform 0.1s;
    }
    .btn-print-list:hover { background: var(--site-primary-pale); }
    .btn-print-list:active { transform: scale(0.97); }

    .btn-print-report {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 10px 18px; background: var(--site-primary); color: #fff;
      border: none; border-radius: 10px; font-size: 0.82rem; font-weight: 700;
      cursor: pointer; transition: background 0.15s, transform 0.1s;
      box-shadow: 0 2px 10px rgba(var(--site-primary-rgb),0.25);
    }
    .btn-print-report:hover { background: var(--site-primary-dark); }
    .btn-print-report:active { transform: scale(0.97); }
    .btn-print-list:disabled {
      opacity: 0.5;
      cursor: not-allowed;
      pointer-events: none;
    }

    /* ?? Analytics report modal (checkbox picker) ?? */
    .ar-toolbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px; }
    .ar-toolbar-links { display: flex; gap: 14px; }
    .ar-toolbar-links a { font-size: 0.72rem; font-weight: 700; color: var(--site-primary-dark); cursor: pointer; text-decoration: underline; text-underline-offset: 2px; }

    /* ?? Per-module quick stats (Print Report picker) ?? */
    .ar-stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 8px; margin-bottom: 12px; }
    .ar-stat-card {
      background: #f9fafb; border: 1px solid #eef0f2; border-radius: 10px;
      padding: 9px 12px; display: flex; align-items: center; gap: 9px;
    }
    .ar-stat-ico {
      width: 30px; height: 30px; border-radius: 8px; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center; font-size: 0.78rem; color: #fff;
    }
    .ar-stat-text { min-width: 0; }
    .ar-stat-num { font-size: 1.05rem; font-weight: 800; color: #111827; line-height: 1.15; }
    .ar-stat-label { font-size: 0.66rem; font-weight: 600; color: #6b7280; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    .ar-check-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 10px; }
    .ar-check-item {
      display: flex; align-items: flex-start; gap: 10px; padding: 10px 12px;
      border: 1.5px solid #e5e7eb; border-radius: 10px; cursor: pointer; transition: border-color 0.15s, background 0.15s;
    }
    .ar-check-item:hover { border-color: var(--site-primary-light); background: var(--site-primary-pale); }
    .ar-check-item input[type="checkbox"] { margin-top: 3px; width: 15px; height: 15px; accent-color: var(--site-primary); flex-shrink: 0; cursor: pointer; }
    .ar-check-item .ar-item-title { font-size: 0.8rem; font-weight: 700; color: #1f2937; display: block; }
    .ar-check-item .ar-item-desc { font-size: 0.7rem; color: #9ca3af; display: block; margin-top: 2px; line-height: 1.4; }
    .filter-badge {
      display: inline-flex; align-items: center; justify-content: center;
      min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px;
      background: rgba(255,255,255,0.28); font-size: 0.68rem; font-weight: 800;
    }
    .filter-badge.hidden { display: none; }

    .gf-overlay {
      position: fixed; inset: 0; background: rgba(15,23,25,0.55);
      z-index: 200; display: flex; align-items: center; justify-content: center;
      padding: 20px; opacity: 0; pointer-events: none; transition: opacity 0.18s ease;
    }
    .gf-overlay.show { opacity: 1; pointer-events: auto; }
    .gf-modal {
      background: #fff; border-radius: 16px; width: 100%; max-width: 900px;
      max-height: 88vh; display: flex; flex-direction: column;
      box-shadow: 0 20px 60px rgba(0,0,0,0.25);
      transform: translateY(12px) scale(0.98); transition: transform 0.18s ease;
    }
    .gf-overlay.show .gf-modal { transform: translateY(0) scale(1); }
    .gf-modal-header {
      display: flex; align-items: flex-start; justify-content: space-between;
      padding: 18px 22px; border-bottom: 1px solid #e5e7eb; flex-shrink: 0;
    }
    .gf-modal-header h3 { font-size: 1rem; font-weight: 800; color: #1a2e1a; }
    .gf-modal-header p { font-size: 0.75rem; color: #9ca3af; margin-top: 2px; }
    .gf-modal-close {
      width: 32px; height: 32px; border-radius: 50%; border: none; background: #f3f4f6;
      color: #6b7280; cursor: pointer; display: flex; align-items: center; justify-content: center;
      transition: background 0.15s, color 0.15s; flex-shrink: 0;
    }
    .gf-modal-close:hover { background: #fee2e2; color: #dc2626; }
    .gf-modal-body { padding: 20px 22px; overflow-y: auto; flex: 1; }
    .gf-section { margin-bottom: 20px; }
    .gf-section:last-child { margin-bottom: 0; }
    .gf-section-title {
      font-size: 0.7rem; font-weight: 800; color: var(--site-primary-dark);
      text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 10px;
      padding-bottom: 6px; border-bottom: 1px dashed #e5e7eb;
    }
    .gf-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 12px 14px; }
    .gf-field { display: flex; flex-direction: column; gap: 5px; }
    .gf-field.gf-span-2 { grid-column: span 2; }
    .gf-field label { font-size: 0.72rem; font-weight: 600; color: #4b5563; }

    /* ?? Permission-locked conditions/charts ?? */
    .gf-lock-badge {
      display: inline-flex; align-items: center; gap: 4px;
      font-size: 0.62rem; font-weight: 700; color: #92400e;
      background: #fef3c7; border: 1px solid #fde68a;
      padding: 2px 8px; border-radius: 999px; margin-left: 8px;
      text-transform: none; letter-spacing: normal; vertical-align: middle;
    }
    .gf-section-locked .gf-grid,
    .gf-section-locked .ar-check-grid,
    .gf-section-locked .ar-stats-row { opacity: 0.5; pointer-events: none; }
    .gf-section-locked select,
    .gf-section-locked input { cursor: not-allowed; }
    .gf-field-locked { opacity: 0.5; }
    .gf-field-locked select,
    .gf-field-locked input { pointer-events: none; cursor: not-allowed; }
    .gf-locked-note {
      display: flex; align-items: center; gap: 6px;
      font-size: 0.72rem; color: #92400e; background: #fffbeb;
      border: 1px solid #fde68a; border-radius: 8px; padding: 6px 10px; margin-top: 10px;
    }
    .ar-check-item-locked { opacity: 0.5; cursor: not-allowed; }
    .ar-check-item-locked .ar-item-title,
    .ar-check-item-locked .ar-item-desc { pointer-events: none; }
    .gf-field input, .gf-field select {
      border: 1.5px solid #e5e7eb; border-radius: 8px; padding: 7px 10px; font-size: 0.8rem;
      font-family: inherit; outline: none; background: #fff; color: #374151; width: 100%;
    }
    .gf-field input:focus, .gf-field select:focus { border-color: var(--site-primary); }
    .gf-range { display: flex; align-items: center; gap: 6px; }
    .gf-range input { width: 100%; min-width: 0; }
    .gf-range span { font-size: 0.72rem; color: #9ca3af; flex-shrink: 0; }
    .gf-modal-footer {
      display: flex; align-items: center; justify-content: space-between; gap: 10px;
      padding: 14px 22px; border-top: 1px solid #e5e7eb; flex-shrink: 0; background: #f9fafb; border-radius: 0 0 16px 16px;
    }
    .gf-btn-reset {
      background: none; border: none; color: #6b7280; font-size: 0.78rem; font-weight: 700;
      cursor: pointer; padding: 8px 10px; border-radius: 8px; transition: background 0.15s, color 0.15s;
    }
    .gf-btn-reset:hover { background: #fee2e2; color: #dc2626; }
    .gf-btn-apply {
      background: var(--site-primary); color: #fff; border: none; border-radius: 9px;
      padding: 10px 22px; font-size: 0.82rem; font-weight: 700; cursor: pointer; transition: background 0.15s;
    }
    .gf-btn-apply:hover { background: var(--site-primary-dark); }
  </style>
</head>
<body>
<!-- Page Loader -->
<div id="pageLoader" class="fixed inset-0 z-[9999] flex flex-col items-center justify-center opacity-0 pointer-events-none transition-opacity duration-300" style="background: rgba(var(--site-primary-rgb), 0.4); backdrop-filter: blur(4px);">
  <div class="w-12 h-12 border-4 border-white/20 rounded-full animate-spin shadow-lg" style="border-top-color: var(--site-primary-light);"></div>
  <p class="text-white font-medium mt-4 tracking-wider text-sm shadow-sm">Loading...</p>
</div>
<div class="flex min-h-screen">

  <!-- Floating expand button (appears when sidebar is hidden) -->
  <button class="expand-btn" id="expandBtn" title="Open sidebar">
    <i class="fa-solid fa-bars"></i>
  </button>

  <!-- ────────── SIDEBAR ────────── -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-inner">

      <!-- Logo + collapse -->
      <div class="sidebar-logo">
        <button type="button" onclick="window.location.href='adminLanding.php'" style="text-decoration: none; color: inherit; border: none; background: none; padding: 0; text-align: left; cursor: pointer;">
          <div class="flex items-center gap-2.5">
            <div class="w-10 h-10 rounded-full flex items-center justify-center shadow overflow-hidden" style="background: var(--site-primary);">
              <img src="<?= e(site_config_logo_url($siteSettings, '../')) ?>" alt="Logo" class="w-full h-full object-cover" />
            </div>
            <div>
              <p class="text-white font-bold text-sm leading-tight"><?= e($siteSettings['site_title']) ?></p>
              <p class="text-[10px] tracking-widest uppercase" style="color: var(--site-primary-light);">Admin Panel</p>
            </div>
          </div>
        </button>
        <button class="collapse-btn" id="collapseBtn" title="Collapse sidebar">
          <i class="fa-solid fa-chevron-left"></i>
        </button>
      </div>

      <!-- Management -->
      <?php foreach ($sidebarSections as $sectionLabel => $items): ?>
      <div class="section-label"><?= e($sectionLabel) ?></div>
      <nav class="space-y-0.5 px-2">
        <?php foreach ($items as $item): ?>
          <?php
            $visible = !empty($item['admin_only'])
              ? $role === 'admin'
              : sidebar_menu_allowed($item['key'], $role, $myPermissions);
            if (!$visible) {
                continue;
            }
            $isActive = !empty($item['active']);
          ?>
          <button type="button" onclick="window.location.href='<?= e($item['href']) ?>'" class="menu-item<?= $isActive ? ' active' : '' ?>">
            <div class="menu-left"><i class="fa-solid <?= e($item['icon']) ?> mi"></i><?= e($item['label']) ?></div>
            <?php if ($isActive): ?><span class="active-dot"></span><?php endif; ?>
          </button>
        <?php endforeach; ?>
      </nav>
      <?php endforeach; ?>

      <!-- Bottom -->
      <div class="sidebar-bottom">
        <div class="sidebar-bottom-links">
          <?php if ($role === 'admin'): ?>
            <button type="button" onclick="window.location.href='../settings.php'" class="side-link" style="color: rgba(255,255,255,0.55);" onmouseover="this.style.color='var(--site-primary-light)'" onmouseout="this.style.color=' rgba(255,255,255,0.55)'">Settings</button>
          <?php endif; ?>
          <div class="h-px bg-white/10 my-1 mx-2"></div>
          <button type="button" onclick="window.location.href='../logout.php'" class="side-link text-red-400/70 hover:text-red-300 hover:bg-white/5">Logout</button>
        </div>
      </div>

    </div>
  </aside>

  <!-- ────────── MAIN ────────── -->
  <main class="flex-1 overflow-x-hidden flex flex-col min-w-0">

    <header class="topbar">
      <div class="topbar-title-block">
        <h2 class="font-bold text-2xl leading-tight" style="font-family:'Playfair Display',serif; color: var(--site-primary-darker);">
          <?= e($siteSettings['barangay_name']) . "," . " " . e($siteSettings['municipality']) ?>
        </h2>
        <p class="text-gray-500 text-sm mt-0.5 flex items-center gap-1.5">
          <i class="fa-solid fa-location-dot text-xs" style="color: var(--site-primary);"></i><?= e($mapRegion !== '' ? $mapRegion : $siteSettings['municipality']) ?>
        </p>
      </div>
      <div class="flex items-center gap-3">
        <button type="button" class="btn-print-report" onclick="openAnalyticsModal()">
          <i class="fa-solid fa-file-lines"></i> Print Report
        </button>
        <div class="info-card">
          <div>
            <p id="weatherSummary" class="text-xs text-gray-500 flex items-center gap-1.5 mb-1">
              <i class="fa-solid fa-cloud-sun text-amber-400"></i> Loading weather...
            </p>
            <p id="weatherTemp" class="text-3xl font-bold text-gray-800 leading-none">--°</p>
            <p class="text-xs text-gray-400 mt-1 flex items-center gap-1">
              <i class="fa-solid fa-location-dot text-[10px]" style="color: var(--site-primary);"></i>Cabanatuan City
            </p>
          </div>
        </div>
        <div class="info-card">
          <div>
            <p class="text-xs text-gray-400 mb-1"><i class="fa-regular fa-clock" style="color: var(--site-primary);"></i></p>
            <p class="leading-none">
              <span class="text-3xl font-bold text-gray-800" id="clock">12:00</span>
              <span class="text-sm font-semibold text-gray-500 ml-1" id="ampm">PM</span>
            </p>
            <p class="text-xs text-gray-400 mt-1" id="dateLabel">Saturday, March 14, 2026</p>
          </div>
        </div>
      </div>
    </header>

    <div class="p-5 flex-1 space-y-5">

      <!-- Stat cards -->
      <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 f1">
        <div class="stat-card">
          <p class="stat-label">Total Registered Residents</p>
          <div class="stat-row"><i class="fa-solid fa-users stat-ico text-blue-500"></i><span class="stat-num"><?= number_format($registeredResidents) ?></span></div>
        </div>
        <div class="stat-card">
          <p class="stat-label">Active Borrowed Equipment</p>
          <div class="stat-row"><i class="fa-solid fa-hammer stat-ico text-amber-500"></i><span class="stat-num"><?= number_format($activeBorrowedEquipment) ?></span></div>
        </div>
        <div class="stat-card">
          <p class="stat-label">Active Listings</p>
          <div class="stat-row"><i class="fa-solid fa-users stat-ico text-purple-500"></i><span class="stat-num"><?= number_format($activeListings) ?></span></div>
        </div>
        <div class="stat-card">
          <p class="stat-label">Non-Resident Users</p>
          <div class="stat-row"><i class="fa-solid fa-users stat-ico" style="color: var(--site-primary);"></i><span class="stat-num"><?= number_format($nonResidentUsers) ?></span></div>
        </div>
      </div>

      <!-- Global List -->
      <div class="panel">
        <div class="panel-head" style="flex-wrap:wrap; gap:10px; padding-bottom:12px;">
          <div>
            <p class="panel-title">Global List</p>
            <p class="text-xs text-gray-400 mt-0.5">Build a custom resident list - set conditions to filter and display exactly the fields you need.</p>
          </div>
          <div class="flex items-center gap-2 flex-wrap">
            <span class="mini-stat-inline"><span class="num" id="globalCount">0</span><span class="text-xs text-gray-400 ml-1">results</span></span>
            <button type="button" id="printGlobalListBtn" class="btn-print-list" onclick="printGlobalList()">
              <i class="fa-solid fa-print"></i> Print This List
            </button>
            <button type="button" class="btn-set-conditions" onclick="openGlobalFilterModal()">
              <i class="fa-solid fa-sliders"></i> Set Conditions
              <span id="globalFilterBadge" class="filter-badge hidden">0</span>
            </button>
          </div>
        </div>
        <div class="mini-table-wrap mx-4 mb-4" style="max-height:420px;">
          <div class="mini-loading-overlay" id="globalLoading"><div class="mini-spinner"></div><span class="mini-loading-text">Fetching...</span></div>
          <table class="mini-table">
            <thead><tr id="globalTableHead"><th>#</th><th>Name</th><th>Age</th><th>Birthdate</th><th>Contact Number</th><th>Address</th></tr></thead>
            <tbody id="globalTableBody"></tbody>
          </table>
        </div>
      </div>
      <!-- Row 2 -->

      <!-- Row 2 -->
      <div class="grid grid-cols-1 xl:grid-cols-5 gap-4 f2">
        <div class="panel xl:col-span-2">
          <div class="panel-head mb-1"><p class="panel-title">Demographic Distribution</p></div>
          <div id="donutchart" class="w-full h-[290px]"></div>
          <div class="flex justify-center gap-6 pb-4">
            <div class="flex items-center gap-1.5">
              <span class="w-3 h-3 rounded-full bg-blue-500 inline-block"></span>
              <span class="text-gray-600 text-xs">Male: <strong class="text-gray-800"><?= number_format($maleTotal) ?></strong></span>
            </div>
            <div class="flex items-center gap-1.5">
              <span class="w-3 h-3 rounded-full bg-pink-500 inline-block"></span>
              <span class="text-gray-600 text-xs">Female: <strong class="text-gray-800"><?= number_format($femaleTotal) ?></strong></span>
            </div>
          </div>
        </div>
        <div class="panel xl:col-span-3">
          <div class="panel-head mb-1">
            <p class="panel-title">Monthly Service Requests</p>
            <span class="tag-chip bg-gray-100 text-gray-500">Last 6 months</span>
          </div>
          <div id="barchart" class="w-full h-[290px]"></div>
          <div class="flex flex-wrap justify-center gap-4 px-4 pb-4 pt-1 border-t border-gray-100 text-xs text-gray-500">
            <span class="flex items-center gap-1.5"><span class="w-3 h-2.5 rounded-sm bg-blue-500 inline-block"></span>Document Request</span>
            <span class="flex items-center gap-1.5"><span class="w-3 h-2.5 rounded-sm bg-emerald-500 inline-block"></span>Beneficiary Applications</span>
            <span class="flex items-center gap-1.5"><span class="w-3 h-2.5 rounded-sm bg-amber-400 inline-block"></span>Equipment Borrowing</span>
          </div>
        </div>
      </div>

      <!-- Row 3 -->
      <div class="grid grid-cols-1 xl:grid-cols-5 gap-4 f3">
        <div class="panel xl:col-span-2 p-5">
          <p class="panel-title mb-5">Age Distribution</p>
          <div class="hbar-row">
            <div class="hbar-label-row"><span>0-17</span><span class="font-semibold text-gray-700"><?= number_format($age_0_17) ?></span></div>
            <div class="hbar-track"><div class="hbar-fill" style="width:<?= $age_0_17 > 0 ? min(100, ($age_0_17 / max(1, $age_0_17 + $age_18_30 + $age_31_45 + $age_46_60 + $age_60_plus)) * 100) : 0 ?>%"></div></div>
          </div>

          <div class="hbar-row">
            <div class="hbar-label-row"><span>18-30</span><span class="font-semibold text-gray-700"><?= number_format($age_18_30) ?></span></div>
            <div class="hbar-track"><div class="hbar-fill" style="width:<?= $age_18_30 > 0 ? min(100, ($age_18_30 / max(1, $age_0_17 + $age_18_30 + $age_31_45 + $age_46_60 + $age_60_plus)) * 100) : 0 ?>%"></div></div>
          </div>

          <div class="hbar-row">
            <div class="hbar-label-row"><span>31-45</span><span class="font-semibold text-gray-700"><?= number_format($age_31_45) ?></span></div>
            <div class="hbar-track"><div class="hbar-fill" style="width:<?= $age_31_45 > 0 ? min(100, ($age_31_45 / max(1, $age_0_17 + $age_18_30 + $age_31_45 + $age_46_60 + $age_60_plus)) * 100) : 0 ?>%"></div></div>
          </div>

          <div class="hbar-row">
            <div class="hbar-label-row"><span>46-60</span><span class="font-semibold text-gray-700"><?= number_format($age_46_60) ?></span></div>
            <div class="hbar-track"><div class="hbar-fill" style="width:<?= $age_46_60 > 0 ? min(100, ($age_46_60 / max(1, $age_0_17 + $age_18_30 + $age_31_45 + $age_46_60 + $age_60_plus)) * 100) : 0 ?>%"></div></div>
          </div>

          <div class="hbar-row" style="margin-bottom:0">
            <div class="hbar-label-row"><span>60+</span><span class="font-semibold text-gray-700"><?= number_format($age_60_plus) ?></span></div>
            <div class="hbar-track"><div class="hbar-fill" style="width:<?= $age_60_plus > 0 ? min(100, ($age_60_plus / max(1, $age_0_17 + $age_18_30 + $age_31_45 + $age_46_60 + $age_60_plus)) * 100) : 0 ?>%"></div></div>
          </div>
        </div>

        <div class="panel xl:col-span-3 p-5">
          <p class="panel-title mb-5">Request Status Overview</p>

          <div class="status-row">
            <div class="status-top">
              <span class="status-pct"><?= $approvedPct ?>%</span>
              <span class="status-name">Approved</span>
            </div>
            <div class="status-track">
              <div class="s-approved" style="height:100%;width:<?= $approvedPct ?>%;border-radius:6px;"></div>
            </div>
          </div>

          <div class="status-row">
            <div class="status-top">
              <span class="status-pct"><?= $pendingPct ?>%</span>
              <span class="status-name">Pending</span>
            </div>
            <div class="status-track">
              <div class="s-pending" style="height:100%;width:<?= $pendingPct ?>%;border-radius:6px;"></div>
            </div>
          </div>

          <div class="status-row" style="margin-bottom:0">
            <div class="status-top">
              <span class="status-pct"><?= $rejectedPct ?>%</span>
              <span class="status-name">Rejected</span>
            </div>
            <div class="status-track">
              <div class="s-rejected" style="height:100%;width:<?= $rejectedPct ?>%;border-radius:6px;"></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Row 4: Income Stats -->
      <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 f3 mb-5">
        <div class="panel p-5">
          <p class="panel-title mb-5">Income Bracket Distribution</p>
          <div class="flex flex-col gap-4" id="incomeBracketRows">
          <?php foreach ($incomeCounts as $label => $value): ?>
              <?php
                  $pct = $totalIncomeCount > 0 ? round(($value / $totalIncomeCount) * 100, 1) : 0;
                  $barColor = [
                      'Below ?5k/mo' => '#E24B4A',
                      '?5k - ?10k/mo' => '#BA7517',
                      '?10k - ?20k/mo' => '#639922',
                      '?20k - ?40k/mo' => '#1D9E75',
                      'Above ?40k/mo' => '#378ADD',
                  ][$label];
              ?>
              <div class="flex items-center justify-between">
                  <div class="w-[110px] text-sm text-gray-600 truncate" title="<?= e($label) ?>"><?= e($label) ?></div>
                  <div class="flex-1 px-4">
                      <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                          <div class="h-full rounded-full" style="width: <?= $pct ?>%; background-color: <?= $barColor ?>;"></div>
                      </div>
                  </div>
                  <div class="w-[40px] text-right font-bold text-gray-800"><?= number_format($value) ?></div>
                  <div class="w-[45px] text-right text-sm text-gray-500"><?= number_format($pct, 1) ?>%</div>
              </div>
          <?php endforeach; ?>
          </div>
        </div>
        <div class="panel p-5">
          <p class="panel-title mb-5">Income vs Age Group</p>
          <div id="incomeVsAgeChart" class="w-full h-[290px]"></div>
        </div>
      </div>

      <!-- ──────────────────────────────────────────────────────
           ADDITIONAL REPORTS
      ─────────────────────────────────────────────────────── -->
      <div class="panel f3">
        <div class="panel-head" style="padding-bottom:10px;">
          <p class="panel-title">Additional Reports</p>
        </div>

        <div class="report-tab-bar">
          <button class="report-tab-btn active" data-tab="resident"    onclick="switchReportTab('resident',this)">Resident Management</button>
          <button class="report-tab-btn"        data-tab="beneficiary" onclick="switchReportTab('beneficiary',this)">Beneficiary Management</button>
          <button class="report-tab-btn"        data-tab="business"    onclick="switchReportTab('business',this)">Business / Apartment</button>
          <button class="report-tab-btn"        data-tab="equipment"   onclick="switchReportTab('equipment',this)">Equipment</button>
          <button class="report-tab-btn"        data-tab="documents"   onclick="switchReportTab('documents',this)">Document Requests</button>
          <button class="report-tab-btn"        data-tab="accounts"    onclick="switchReportTab('accounts',this)">User / Accounts</button>
        </div>

        <!-- ?? RESIDENT MANAGEMENT ?? -->
        <div class="report-pane" id="pane-resident">

          <div class="subpanel">
            <p class="subpanel-title">Population by Purok / Zone</p>
            <div id="chartPurok" class="w-full h-[280px]"></div>
          </div>

          <div class="subpanel">
            <p class="subpanel-title">Age Bracket Breakdown</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div id="chartAgeBracket" class="w-full h-[220px]"></div>
              <div class="flex flex-col justify-center gap-3 text-sm">
                <div class="flex justify-between border-b border-gray-100 pb-2"><span>Minors (0-17)</span><strong id="bkMinors">0</strong></div>
                <div class="flex justify-between border-b border-gray-100 pb-2"><span>Working Age (18-59)</span><strong id="bkWorking">0</strong></div>
                <div class="flex justify-between"><span>Seniors (60+)</span><strong id="bkSeniors">0</strong></div>
              </div>
            </div>
          </div>

        </div>

        <!-- ?? BENEFICIARY MANAGEMENT ?? -->
        <div class="report-pane hidden" id="pane-beneficiary">

          <div class="subpanel">
            <p class="subpanel-title">Approved Beneficiaries by Category</p>
            <div id="chartBenPrograms" class="w-full h-[280px]"></div>
          </div>

     
          <div class="subpanel">
            <p class="subpanel-title">Residents NOT Yet Registered as Beneficiaries <span class="subpanel-note">(outreach list)</span></p>
            <div class="mini-filter-row">
              <div class="mini-search-wrap" style="flex:1;min-width:200px;">
                <input type="text" id="nonBenSearch" placeholder="Search name or purok..." style="width:100%;" oninput="nonBenTable.debounced()">
                <span class="mini-search-spinner" id="nonBenSearchSpinner"></span>
              </div>
              <span class="mini-stat-inline"><span class="num" id="nonBenCount">0</span><span class="text-xs text-gray-400 ml-1">residents</span></span>
              <button type="button" class="btn-print-list" onclick="printOutreachList()">
                <i class="fa-solid fa-print"></i> Print This List
              </button>
            </div>
            <div class="mini-table-wrap">
              <div class="mini-loading-overlay" id="nonBenLoading"><div class="mini-spinner"></div><span class="mini-loading-text">Fetching...</span></div>
            <table class="mini-table">
                <thead><tr><th>Name</th><th>Purok / Street</th><th>Phone</th><th>Email</th></tr></thead>
                <tbody id="nonBenTableBody"></tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- ?? BUSINESS / APARTMENT MANAGEMENT ?? -->

        <div class="report-pane hidden" id="pane-business">

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="subpanel">
              <p class="subpanel-title">Active Listings by Type</p>
              <div id="chartListingType" class="w-full h-[240px]"></div>
            </div>
            <div class="subpanel">
              <p class="subpanel-title">Apartment Occupancy</p>
              <div id="chartOccupancy" class="w-full h-[240px]"></div>
            </div>
          </div>

          <div class="subpanel">
            <p class="subpanel-title">Owner Directory <span class="subpanel-note">(who owns what)</span></p>
            <div class="mini-filter-row">
              <div class="mini-search-wrap" style="flex:1;min-width:200px;">
                <input type="text" id="ownerSearch" placeholder="Search owner..." style="width:100%;" oninput="ownerTable.debounced()">
                <span class="mini-search-spinner" id="ownerSearchSpinner"></span>
              </div>
              <span class="mini-stat-inline"><span class="num" id="ownerCount">0</span><span class="text-xs text-gray-400 ml-1">owners</span></span>
              <button type="button" class="btn-print-list" onclick="printOwnerDirectory()">
                <i class="fa-solid fa-print"></i> Print This List
              </button>
            </div>
            <div class="mini-table-wrap">
              <div class="mini-loading-overlay" id="ownerLoading"><div class="mini-spinner"></div><span class="mini-loading-text">Fetching...</span></div>
              <table class="mini-table">
                <thead><tr><th>Owner</th><th>Total Listings</th><th>Apartments</th><th>Businesses</th></tr></thead>
                <tbody id="ownerTableBody"></tbody>
              </table>
            </div>
          </div>

        </div>

        <!-- ?? EQUIPMENT ?? -->
        <div class="report-pane hidden" id="pane-equipment">

          <div class="subpanel">
            <p class="subpanel-title">Most-Borrowed Equipment <span class="subpanel-note">(top 5)</span></p>
            <div id="chartMostBorrowed" class="w-full h-[260px]"></div>
          </div>

          <div class="subpanel">
            <p class="subpanel-title">Currently Borrowed / Overdue Items <span class="subpanel-note">(borrowers list)</span></p>
            <div class="mini-filter-row">
              <div class="mini-search-wrap" style="flex:1;min-width:200px;">
                <input type="text" id="borrowedSearch" placeholder="Search item or borrower..." style="width:100%;" oninput="borrowedTable.debounced()">
                <span class="mini-search-spinner" id="borrowedSearchSpinner"></span>
              </div>
              <span class="mini-stat-inline"><span class="num" id="borrowedCount">0</span><span class="text-xs text-gray-400 ml-1">out</span></span>
              <button type="button" class="btn-print-list" onclick="printBorrowedList()">
                <i class="fa-solid fa-print"></i> Print This List
              </button>
            </div>
            <div class="mini-table-wrap">
              <div class="mini-loading-overlay" id="borrowedLoading"><div class="mini-spinner"></div><span class="mini-loading-text">Fetching...</span></div>
              <table class="mini-table">
                <thead><tr><th>Item</th><th>Qty</th><th>Borrower</th><th>Return Date</th><th>Status</th></tr></thead>
                <tbody id="borrowedTableBody"></tbody>
              </table>
            </div>
          </div>

        </div>

        <!-- ?? DOCUMENT REQUESTS ?? -->
        <div class="report-pane hidden" id="pane-documents">

          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="subpanel md:col-span-1" style="display:flex;flex-direction:column;justify-content:center;align-items:center;">
              <p class="subpanel-title">Average Turnaround</p>
              <p class="text-4xl font-extrabold text-gray-800" id="avgTurnaroundLbl">0h</p>
              <p class="text-xs text-gray-400 mt-1">approved/rejected requests</p>
            </div>
            <div class="subpanel md:col-span-2">
              <p class="subpanel-title">Requests by Type &amp; Status</p>
              <div class="mini-table-wrap">
                <table class="mini-table">
                  <thead><tr><th>Document Type</th><th>Status</th><th>Total</th></tr></thead>
                  <tbody id="docTypeStatusBody"></tbody>
                </table>
              </div>
            </div>
          </div>

          <div class="subpanel">
            <p class="subpanel-title">Volume Trend by Month</p>
            <div id="chartDocMonthly" class="w-full h-[260px]"></div>
          </div>

        </div>

        <!-- ?? USER / ACCOUNT MANAGEMENT ?? -->
        <div class="report-pane hidden" id="pane-accounts">

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="subpanel">
              <p class="subpanel-title">Accounts by Role</p>
              <div id="chartRoleCounts" class="w-full h-[260px]"></div>
            </div>
            <div class="subpanel" style="display:flex;flex-direction:column;justify-content:center;">
              <p class="subpanel-title">Active vs Inactive Accounts</p>
              <div id="chartAccountStatus" class="w-full h-[220px]"></div>
            </div>
          </div>

          <div class="subpanel">
            <p class="subpanel-title">Registration Trend Over Time</p>
            <div id="chartRegTrend" class="w-full h-[260px]"></div>
          </div>

        </div>

      </div>
      <!-- /Additional Reports -->

    </div>
  </main>
</div>

<script>
  /* ?? Sidebar toggle ?? */
  const sidebar     = document.getElementById('sidebar');
  const collapseBtn = document.getElementById('collapseBtn');
  const expandBtn   = document.getElementById('expandBtn');

  let collapsed = localStorage.getItem('sidebarCollapsed') === 'true';

  function applyState() {
    if (collapsed) {
      sidebar.classList.add('collapsed');
      expandBtn.classList.add('visible');
      document.body.classList.add('sidebar-collapsed');
    } else {
      sidebar.classList.remove('collapsed');
      expandBtn.classList.remove('visible');
      document.body.classList.remove('sidebar-collapsed');
    }
    setTimeout(draw, 320); // redraw charts after transition
  }

  applyState();

  collapseBtn.addEventListener('click', () => {
    collapsed = true;
    localStorage.setItem('sidebarCollapsed', 'true');
    applyState();
  });

  expandBtn.addEventListener('click', () => {
    collapsed = false;
    localStorage.setItem('sidebarCollapsed', 'false');
    applyState();
  });

  /* ?? Button navigation (no hover URL preview) ?? */
  document.querySelectorAll('[data-nav]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const target = btn.getAttribute('data-nav');
      if (target) window.location.href = target;
    });
  });

  /* ?? Live clock ?? */
  const manilaTimeFmt = new Intl.DateTimeFormat('en-US', {
    timeZone: 'Asia/Manila',
    hour: 'numeric',
    minute: '2-digit',
    hour12: true
  });
  const manilaDateFmt = new Intl.DateTimeFormat('en-US', {
    timeZone: 'Asia/Manila',
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric'
  });

  function updateClock() {
    const now = new Date();
    const timeParts = manilaTimeFmt.formatToParts(now);
    const hour = timeParts.find((p) => p.type === 'hour')?.value || '--';
    const minute = timeParts.find((p) => p.type === 'minute')?.value || '--';
    const dayPeriod = timeParts.find((p) => p.type === 'dayPeriod')?.value || '';

    document.getElementById('clock').textContent = `${hour}:${minute}`;
    document.getElementById('ampm').textContent  = dayPeriod.toUpperCase();
    document.getElementById('dateLabel').textContent = manilaDateFmt.format(now);
  }

  const weatherCodeMap = {
    0: 'Clear sky',
    1: 'Mainly clear',
    2: 'Partly cloudy',
    3: 'Overcast',
    45: 'Fog',
    48: 'Rime fog',
    51: 'Light drizzle',
    53: 'Moderate drizzle',
    55: 'Dense drizzle',
    61: 'Slight rain',
    63: 'Moderate rain',
    65: 'Heavy rain',
    80: 'Rain showers',
    95: 'Thunderstorm'
  };

  async function updateWeather() {
    const summaryEl = document.getElementById('weatherSummary');
    const tempEl = document.getElementById('weatherTemp');
    if (!summaryEl || !tempEl) return;

    try {
      const res = await fetch('https://api.open-meteo.com/v1/forecast?latitude=15.4900&longitude=120.9700&current=temperature_2m,relative_humidity_2m,apparent_temperature,weathercode&timezone=Asia%2FManila', { cache: 'no-store' });
      const data = await res.json();
      const current = data?.current;
      if (!current) return;

      const temp = Math.round(current.temperature_2m);
      const humidity = current.relative_humidity_2m;
      const code = current.weathercode ?? current.weather_code;
      const label = weatherCodeMap[code] || 'Current weather';

      summaryEl.innerHTML = `<i class="fa-solid fa-cloud-sun text-amber-400"></i> ${label}${typeof humidity === 'number' ? ` . ${humidity}% RH` : ''}`;
      tempEl.textContent = `${temp}°`;
    } catch (_) {
      summaryEl.innerHTML = '<i class="fa-solid fa-cloud-sun text-amber-400"></i> Weather unavailable';
    }
  }

  updateClock();
  setInterval(updateClock, 1000);
  updateWeather();
  setInterval(updateWeather, 600000);

  /* ?? Google Charts ?? */
  google.charts.load('current', { packages: ['corechart'] });
  google.charts.setOnLoadCallback(function () {
    draw();
    drawReportTab('resident');
  });

  /* Global List doesn't depend on Google Charts - load it independently so it
     always shows all approved residents by default, even if the charts
     library is slow to load or fails to load at all. Deferred to
     DOMContentLoaded because the filter modal's markup is defined further
     down the page (after this script tag) and needs to exist first. */
// Only fires when the Global List panel actually exists in the DOM
  // (i.e. main admin) - Secretary/Treasurer never get this markup, so
  // don't fire a fetch against elements that were never rendered.
  document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('globalTableBody')) applyGlobalFilters();
  });

  function draw() { drawBar(); drawDonut(); drawIncomeVsAgeChart(); }

  /* Registry of live chart objects, keyed by their container id - populated as
     each chart is drawn (including on redraw) so the Print Report feature can
     call .getImageURI() on whichever ones the admin selects. */
  const chartRegistry = {};

  /* Registry of each chart's underlying data, keyed the same way - so the
     printed report can include an actual data table alongside (or instead
     of, if the image capture fails) the chart image. Built generically from
     the Google DataTable every draw*Chart() function already constructs, so
     no chart needs its own bespoke extraction logic. */
  const CHART_TABLE_DATA = {};
  function registerChartTable(key, dataTable) {
    const numCols = dataTable.getNumberOfColumns();
    const numRows = dataTable.getNumberOfRows();
    const headers = [];
    for (let c = 0; c < numCols; c++) headers.push(dataTable.getColumnLabel(c) || ('Column ' + (c + 1)));
    const rows = [];
    for (let r = 0; r < numRows; r++) {
      const rowVals = [];
      for (let c = 0; c < numCols; c++) rowVals.push(dataTable.getFormattedValue(r, c));
      rows.push(rowVals);
    }
    CHART_TABLE_DATA[key] = { headers, rows };
  }

  function drawIncomeVsAgeChart() {
    const data = google.visualization.arrayToDataTable(
        <?= json_encode($incomeAgeChartData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
    );

    const options = {
        isStacked: true,
        backgroundColor: 'transparent',
        colors: ['#E24B4A', '#BA7517', '#639922', '#1D9E75', '#378ADD'],
        legend: { position: 'top', textStyle: { color: '#6b7280', fontSize: 12 } },
        chartArea: { left: 46, right: 20, top: 36, bottom: 46, width: '88%', height: '70%' },
        hAxis: { textStyle: { color: '#6b7280' }, baselineColor: '#e5e7eb', gridlines: { color: 'transparent' } },
        vAxis: {
            minValue: 0,
            textStyle: { color: '#6b7280' },
            baselineColor: '#e5e7eb',
            gridlines: { color: '#f3f4f6', count: 6 },
            minorGridlines: { color: '#fafafa' }
        },
        bar: { groupWidth: '72%' }
    };

    const chart = new google.visualization.ColumnChart(document.getElementById('incomeVsAgeChart'));
    chart.draw(data, options);
    chartRegistry['incomeVsAgeChart'] = chart;
    registerChartTable('incomeVsAgeChart', data);
}

  function drawBar() {
    const chartRows = <?= json_encode($chartRows, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

    const data = google.visualization.arrayToDataTable(
      [['Month', 'Document Request', 'Beneficiary Applications', 'Equipment Borrowing']].concat(chartRows)
    );

    const chart = new google.visualization.ColumnChart(document.getElementById('barchart'));
    chart.draw(data, {
      colors: ['#3b82f6', '#10b981', '#f59e0b'],
      legend: { position: 'none' },
      backgroundColor: 'transparent',
      bar: { groupWidth: '65%' },
      chartArea: { left: 46, right: 20, top: 20, bottom: 40, width: '90%', height: '75%' },
      hAxis: { textStyle: { color: '#6b7280', fontSize: 10 } },
      vAxis: {
        minValue: 0,
        textStyle: { color: '#6b7280' },
        gridlines: { color: '#f3f4f6' },
        minorGridlines: { color: 'transparent' }
      },
    });
    chartRegistry['barchart'] = chart;
    registerChartTable('barchart', data);
  }

  function drawDonut() {
    const data = google.visualization.arrayToDataTable([
      ['Gender','Total'],
      ['Male',  <?= number_format($maleTotal) ?>],
      ['Female',<?= number_format($femaleTotal) ?>],
    ]);
    const chart = new google.visualization.PieChart(document.getElementById('donutchart'));
    chart.draw(data, {
      pieHole: 0.6,
      colors: ['#3b82f6','#ec4899'],
      legend: { position: 'none' },
      pieSliceText: 'label',
      pieSliceTextStyle: { fontSize: 11, color: '#fff' },   
      chartArea: { width: '88%', height: '82%' },
      backgroundColor: 'transparent',
      pieSliceBorderColor: 'transparent',
    });
    chartRegistry['donutchart'] = chart;
    registerChartTable('donutchart', data);
  }

  /* ──────────────────────────────────────────
     ADDITIONAL REPORTS - CHART-ONLY DATA (from PHP)
     (No search boxes on these - kept server-rendered.)
  ────────────────────────────────────────── */
  const PUROK_DATA        = <?= json_encode($purokData ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
  const AGE_BRACKET        = { minors: <?= (int)($bracketMinors ?? 0) ?>, working: <?= (int)($bracketWorkingAge ?? 0) ?>, seniors: <?= (int)($bracketSeniors ?? 0) ?> };
  const GENDER_TOTALS       = { male: <?= (int)($maleTotal ?? 0) ?>, female: <?= (int)($femaleTotal ?? 0) ?> };
  const PROG_COUNTS         = <?= json_encode($progCounts ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
  const LISTING_TYPE_COUNTS = { apt: <?= (int)($aptCountTotal ?? 0) ?>, biz: <?= (int)($bizCountTotal ?? 0) ?> };
  const OCCUPANCY_COUNTS    = { available: <?= (int)($availableUnits ?? 0) ?>, occupied: <?= (int)($occupiedUnits ?? 0) ?> };
  const MOST_BORROWED       = <?= json_encode($mostBorrowed ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
  const DOC_TYPE_STATUS     = <?= json_encode($docTypeStatus ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
  const AVG_TURNAROUND      = <?= json_encode($avgTurnaroundHours ?? 0) ?>;
  const DOC_MONTHLY         = <?= json_encode($docMonthly ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
  const ROLE_COUNTS         = <?= json_encode($roleCounts ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
  const ACCOUNT_STATUS      = { active: <?= (int)($activeAccounts ?? 0) ?>, inactive: <?= (int)($inactiveAccounts ?? 0) ?> };
  const REG_TREND           = <?= json_encode($regTrend ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;

  /* ?? Print Report (analytics picker) ?? */
  const ANALYTICS_TAB_MAP  = <?= json_encode($ANALYTICS_TAB_MAP, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
  const ANALYTICS_ITEM_TYPES = <?= json_encode(array_map(function ($i) { return $i['type']; }, $ANALYTICS_REPORT_ITEMS), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;

  const PROG_LABELS = { '4ps':"4P's", senior:'Senior Citizen', scholarship:'Scholarship', pwd:'PWD', kabataan:'Kabataan (SK)', voters:'Registered Voters' };

  function escHtml2(s) { return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
  function fullName(u) { return [u.firstname, u.middlename ? u.middlename+'.' : '', u.lastname, u.suffix].filter(Boolean).join(' '); }

  /* ──────────────────────────────────────────
     LIVE AJAX SEARCH TABLES
     Real fetch() calls to admin/ajax/*.php - the loading
     overlay is shown for exactly as long as the network
     request takes (no fake setTimeout delay). Debounced
     300ms after the last keystroke, with AbortController
     so a fast typer never has a slow older request
     overwrite a newer, faster one.
  ────────────────────────────────────────── */
  function debounce(fn, delay = 300) {
    let t;
    return function (...args) {
      clearTimeout(t);
      t = setTimeout(() => fn.apply(this, args), delay);
    };
  }

  function makeAjaxTable({ overlayId, spinnerId, tbodyId, countId, url, buildParams, rowTemplate, emptyMessage, colspan }) {
    let controller = null;
    let inFlight = 0;

    async function render() {
      if (controller) controller.abort();
      controller = new AbortController();
      const thisController = controller;

      inFlight++;
      const overlay = document.getElementById(overlayId);
      const spinner = spinnerId ? document.getElementById(spinnerId) : null;
      if (overlay) overlay.classList.add('show');
      if (spinner) spinner.classList.add('show');

      const params = buildParams();
      const startedAt = performance.now();

      try {
        const res  = await fetch(url + '?' + params.toString(), { signal: thisController.signal });
        const json = await res.json();

        // If a newer request started after this one, drop this stale result
        if (thisController.signal.aborted) return;

        const rows = json.success ? json.data : [];

        const countEl = document.getElementById(countId);
        if (countEl) countEl.textContent = rows.length;

        const tbody = document.getElementById(tbodyId);
        tbody.innerHTML = rows.length
          ? rows.map((r, i) => rowTemplate(r, i)).join('')
          : `<tr><td colspan="${colspan}"><div class="mini-empty">${emptyMessage}</div></td></tr>`;
      } catch (err) {
        if (err.name === 'AbortError') return;
        const tbody = document.getElementById(tbodyId);
        tbody.innerHTML = `<tr><td colspan="${colspan}"><div class="mini-empty">Could not load data. Please try again.</div></td></tr>`;
      } finally {
        inFlight--;
        if (inFlight <= 0) {
          inFlight = 0;
          if (overlay) overlay.classList.remove('show');
          if (spinner) spinner.classList.remove('show');
        }
      }
    }

    return { render, debounced: debounce(render, 300) };
  }

  /* ?? RESIDENT: Global List (dynamic filter-driven table) ?? */
  const globalFilterParamMap = {
    gfAccountRole: 'account_role', gfDateFrom: 'date_from', gfDateTo: 'date_to',
    gfSex: 'sex', gfBirthMonth: 'birth_month', gfBirthYear: 'birth_year',
    gfAgeMin: 'age_min', gfAgeMax: 'age_max', gfAddress: 'address',
    gfFamilyRole: 'family_role', gfCivilStatus: 'civil_status', gfCitizenship: 'citizenship',
    gfReligion: 'religion', gfEthnicity: 'ethnicity', gfBloodType: 'blood_type',
    gfEmploymentStatus: 'employment_status', gfIncomeMin: 'income_min', gfIncomeMax: 'income_max',
    gfHousingStatus: 'housing_status', gfHouseMaterial: 'house_material', gfElectricity: 'electricity',
    gfWaterSource: 'water_source', gfToiletType: 'toilet_type', gfPregnantChildren: 'pregnant_children',
    gfIsPwd: 'is_pwd', gfIsSoloParent: 'is_solo_parent', gfIsIndigenous: 'is_indigenous',
    gfIs4ps: 'is_4ps', gfIsScholarship: 'is_scholarship', gfIsKabataan: 'is_kabataan',
    gfPensionStatus: 'pension_status', gfIsVoter: 'is_voter', gfResidentBirth: 'resident_birth',
    gfSchool: 'school', gfCourse: 'course', gfYearLevel: 'year_level', gfGwa: 'gwa'
  };
  const globalFilterFieldIds = Object.keys(globalFilterParamMap);

  function openGlobalFilterModal() {
    document.getElementById('gfOverlay')?.classList.add('show');
  }
  function closeGlobalFilterModal() {
    document.getElementById('gfOverlay')?.classList.remove('show');
  }
  function resetGlobalFilters() {
    globalFilterFieldIds.forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
    applyGlobalFilters();
  }

  function buildGlobalFilterParams() {
    const p = new URLSearchParams();
    globalFilterFieldIds.forEach(id => {
      const el = document.getElementById(id);
      if (el && el.value.trim() !== '') p.set(globalFilterParamMap[id], el.value.trim());
    });
    return p;
  }

  function updateGlobalFilterBadge() {
    const active = globalFilterFieldIds.filter(id => {
      const el = document.getElementById(id);
      return el && el.value.trim() !== '';
    }).length;
    const badge = document.getElementById('globalFilterBadge');
    if (active > 0) { badge.textContent = active; badge.classList.remove('hidden'); }
    else { badge.classList.add('hidden'); }
  }

  async function applyGlobalFilters() {
    updateGlobalFilterBadge();
    closeGlobalFilterModal();

    const overlay = document.getElementById('globalLoading');
    const thead   = document.getElementById('globalTableHead');
    const tbody   = document.getElementById('globalTableBody');
    const countEl = document.getElementById('globalCount');

    overlay.classList.add('show');
    try {
      const params = buildGlobalFilterParams();
      const res  = await fetch('ajax/search_global.php?' + params.toString());
      const json = await res.json();

      if (!json.success) throw new Error(json.message || 'Request failed');

      const cols = json.columns || [];
      countEl.textContent = json.count ?? json.data.length;

      const printBtn = document.getElementById('printGlobalListBtn');
      if (printBtn) {
        printBtn.disabled = (json.data.length === 0);
      }

      thead.innerHTML = '<th>#</th><th>Name</th><th>Age</th><th>Birthdate</th><th>Contact Number</th><th>Address</th>' + cols.map(c => `<th>${escHtml2(c.label)}</th>`).join('');

      tbody.innerHTML = json.data.length
        ? json.data.map((r, i) => `
            <tr class="mini-row-fade" style="animation-delay:${i * 15}ms">
              <td>${i + 1}</td>
              <td>${escHtml2(r.name)}</td>
              <td>${escHtml2(r.age ?? '-')}</td>
              <td>${escHtml2(r.birthdate ?? '-')}</td>
              <td>${escHtml2(r.contact_number ?? '-')}</td>
              <td>${escHtml2(r.address ?? '-')}</td>
              ${cols.map(c => `<td>${escHtml2(r[c.key] ?? '-')}</td>`).join('')}
            </tr>`).join('')
        : `<tr><td colspan="${6 + cols.length}"><div class="mini-empty">No residents match the selected conditions</div></td></tr>`;
    } catch (err) {
      thead.innerHTML = '<th>#</th><th>Name</th><th>Age</th><th>Birthdate</th><th>Contact Number</th><th>Address</th>';
      tbody.innerHTML = `<tr><td colspan="6"><div class="mini-empty">Could not load data. Please try again.</div></td></tr>`;
    
      const printBtn = document.getElementById('printGlobalListBtn');
      if (printBtn) printBtn.disabled = true;
    
    } finally {
      overlay.classList.remove('show');
    }
  }

  function printGlobalList() {
    const params = buildGlobalFilterParams();
    window.open('print_global_list.php?' + params.toString(), '_blank');
  }

  function printOutreachList() {
    const q = document.getElementById('nonBenSearch')?.value.trim() || '';
    window.open('print_global_list.php?list=outreach&q=' + encodeURIComponent(q), '_blank');
  }

  function printOwnerDirectory() {
    const q = document.getElementById('ownerSearch')?.value.trim() || '';
    window.open('print_global_list.php?list=owners&q=' + encodeURIComponent(q), '_blank');
  }

  function printBorrowedList() {
    const q = document.getElementById('borrowedSearch')?.value.trim() || '';
    window.open('print_global_list.php?list=borrowed&q=' + encodeURIComponent(q), '_blank');
  }

  /* ?? Print Report (analytics picker) ?? */
  function openAnalyticsModal() {
    document.getElementById('arOverlay')?.classList.add('show');
  }
  function closeAnalyticsModal() {
    document.getElementById('arOverlay')?.classList.remove('show');
  }
  function toggleAllAnalytics(checked) {
    document.querySelectorAll('#arOverlay input[type="checkbox"]').forEach(el => { el.checked = checked; });
  }

  function extractAgeDistributionBars() {
    return [...document.querySelectorAll('.hbar-row')].map(row => ({
      label: row.querySelector('.hbar-label-row span:first-child')?.textContent.trim() || '',
      value: row.querySelector('.hbar-label-row span:last-child')?.textContent.trim() || '',
      pct:   row.querySelector('.hbar-fill')?.style.width || '0%',
    }));
  }
  function extractRequestStatusBars() {
    return [...document.querySelectorAll('.status-row')].map(row => ({
      label: row.querySelector('.status-name')?.textContent.trim() || '',
      pct:   row.querySelector('.status-pct')?.textContent.trim() || '0%',
    }));
  }
  function extractIncomeBracketBars() {
    const wrap = document.getElementById('incomeBracketRows');
    if (!wrap) return [];
    return [...wrap.children].map(row => {
      const cells = row.querySelectorAll(':scope > div');
      const fill  = row.querySelector('.h-full.rounded-full');
      return {
        label: cells[0]?.textContent.trim() || '',
        color: fill?.style.backgroundColor || '#9ca3af',
        value: cells[2]?.textContent.trim() || '',
        pct:   cells[3]?.textContent.trim() || '0%',
      };
    });
  }
  const ANALYTICS_BAR_EXTRACTORS = {
    ageDistribution: extractAgeDistributionBars,
    requestStatus:   extractRequestStatusBars,
    incomeBracket:   extractIncomeBracketBars,
  };

  function generateAnalyticsReport() {
    const checked = [...document.querySelectorAll('#arOverlay input[type="checkbox"]:checked')].map(el => el.value);
    if (!checked.length) {
      alert('Select at least one chart or graph to include in the report.');
      return;
    }

    const originalBtn = document.querySelector('.report-tab-btn.active');
    const originalTab = originalBtn ? originalBtn.dataset.tab : null;

    // Make sure every tab a selected chart lives on has actually been drawn
    const neededTabs = new Set();
    checked.forEach(key => { if (ANALYTICS_TAB_MAP[key]) neededTabs.add(ANALYTICS_TAB_MAP[key]); });
    neededTabs.forEach(tab => {
      const btn = document.querySelector('.report-tab-btn[data-tab="' + tab + '"]');
      if (btn) switchReportTab(tab, btn);
    });

    // Restore whichever tab the admin was actually looking at
    if (originalTab) {
      const btn = document.querySelector('.report-tab-btn[data-tab="' + originalTab + '"]');
      if (btn) switchReportTab(originalTab, btn);
    }

    const charts = {};
    const bars   = {};
    const tables = {};

    checked.forEach(key => {
      if (ANALYTICS_ITEM_TYPES[key] === 'bars') {
        const extractor = ANALYTICS_BAR_EXTRACTORS[key];
        if (extractor) bars[key] = extractor();
      } else {
        const chart = chartRegistry[key];
        if (chart && typeof chart.getImageURI === 'function') {
          try { charts[key] = chart.getImageURI(); } catch (e) { /* left unset - printed report shows "unavailable" */ }
        }
        if (CHART_TABLE_DATA[key]) tables[key] = CHART_TABLE_DATA[key];
      }
    });

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'print_global_list.php';
    form.target = '_blank';

    const listInput = document.createElement('input');
    listInput.type = 'hidden'; listInput.name = 'list'; listInput.value = 'analytics';
    form.appendChild(listInput);

    const payloadInput = document.createElement('input');
    payloadInput.type = 'hidden'; payloadInput.name = 'payload';
    payloadInput.value = JSON.stringify({ selected: checked, charts, bars, tables });
    form.appendChild(payloadInput);

    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);

    closeAnalyticsModal();
  }

  document.addEventListener('click', (e) => {
    if (e.target.id === 'gfOverlay') closeGlobalFilterModal();
    if (e.target.id === 'arOverlay') closeAnalyticsModal();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { closeGlobalFilterModal(); closeAnalyticsModal(); }
  });

  /* ?? BENEFICIARY: Not-yet-registered residents ?? */
  const nonBenTable = makeAjaxTable({
    overlayId: 'nonBenLoading', spinnerId: 'nonBenSearchSpinner',
    tbodyId: 'nonBenTableBody', countId: 'nonBenCount',
    url: 'ajax/search_nonbeneficiaries.php',
    buildParams: () => {
      const p = new URLSearchParams();
      p.set('q', document.getElementById('nonBenSearch').value.trim());
      return p;
    },
    rowTemplate: (u, i) => `
      <tr class="mini-row-fade" style="animation-delay:${i * 20}ms">
        <td>${escHtml2(fullName(u))}</td><td>${escHtml2(u.street||'-')}</td><td>${escHtml2(u.phone||'-')}</td><td>${escHtml2(u.email||'-')}</td>
      </tr>`,
    emptyMessage: 'Every approved resident is a registered beneficiary',
    colspan: 4,
  });

  /* ?? BUSINESS/APARTMENT: Owner Directory ?? */
  const ownerTable = makeAjaxTable({
    overlayId: 'ownerLoading', spinnerId: 'ownerSearchSpinner',
    tbodyId: 'ownerTableBody', countId: 'ownerCount',
    url: 'ajax/search_owners.php',
    buildParams: () => {
      const p = new URLSearchParams();
      p.set('q', document.getElementById('ownerSearch').value.trim());
      return p;
    },
    rowTemplate: (o, i) => `
      <tr class="mini-row-fade" style="animation-delay:${i * 20}ms">
        <td>${escHtml2(o.owner_name)}</td><td>${escHtml2(o.listing_count)}</td><td>${escHtml2(o.apt_count)}</td><td>${escHtml2(o.biz_count)}</td>
      </tr>`,
    emptyMessage: 'No owners found',
    colspan: 4,
  });

  /* ?? EQUIPMENT: Currently borrowed / overdue ?? */
  const borrowedTable = makeAjaxTable({
    overlayId: 'borrowedLoading', spinnerId: 'borrowedSearchSpinner',
    tbodyId: 'borrowedTableBody', countId: 'borrowedCount',
    url: 'ajax/search_borrowed.php',
    buildParams: () => {
      const p = new URLSearchParams();
      p.set('q', document.getElementById('borrowedSearch').value.trim());
      return p;
    },
    rowTemplate: (r, i) => {
      const dt = r.returnDate ? new Date(r.returnDate).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) : '-';
      const overdue = r.is_overdue == 1 || r.is_overdue === true;
      const badge = overdue ? '<span class="mini-badge mini-badge-overdue">Overdue</span>' : '<span class="mini-badge mini-badge-ontime">On Time</span>';
      return `<tr class="mini-row-fade" style="animation-delay:${i * 20}ms">
        <td>${escHtml2(r.equipmentName)}</td><td>${escHtml2(r.quantityRequested)}</td><td>${escHtml2(r.borrower_name)}</td><td>${dt}</td><td>${badge}</td></tr>`;
    },
    emptyMessage: 'Nothing currently borrowed',
    colspan: 5,
  });

  /* ?? Tab switching (lazy-loads charts + first AJAX fetch per tab) ?? */
  const reportDrawn = {};
  function switchReportTab(tab, btn) {
    document.querySelectorAll('.report-pane').forEach(p => p.classList.add('hidden'));
    document.querySelectorAll('.report-tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('pane-' + tab).classList.remove('hidden');
    btn.classList.add('active');
    drawReportTab(tab);
  }
  function drawReportTab(tab) {
    if (reportDrawn[tab]) return;
    reportDrawn[tab] = true;
    if (tab === 'resident')    { drawPurokChart(); drawAgeBracketChart(); renderGender(); }
    if (tab === 'beneficiary') {
      drawBenProgramsChart();
      if (document.getElementById('nonBenTableBody')) nonBenTable.render();
    }
    if (tab === 'business')    { drawListingTypeChart(); drawOccupancyChart(); ownerTable.render(); }
    if (tab === 'equipment')   { drawMostBorrowedChart(); borrowedTable.render(); }
    if (tab === 'documents')   { renderDocTypeStatus(); drawDocMonthlyChart(); document.getElementById('avgTurnaroundLbl').textContent = AVG_TURNAROUND + 'h'; }
    if (tab === 'accounts')    { drawRoleCountsChart(); drawAccountStatusChart(); drawRegTrendChart(); }
  }

  /* ?? RESIDENT MANAGEMENT (chart-only pieces) ?? */
  function drawPurokChart() {
    if (!PUROK_DATA.length) { document.getElementById('chartPurok').innerHTML = '<div class="mini-empty">No data available</div>'; return; }
    const data = google.visualization.arrayToDataTable([['Purok/Zone','Residents']].concat(PUROK_DATA));
    const chart = new google.visualization.ColumnChart(document.getElementById('chartPurok'));
    chart.draw(data, {
      backgroundColor: 'transparent', legend: { position: 'none' }, colors: ['#15803d'],
      chartArea: { left: 46, right: 20, top: 20, bottom: 70, width: '90%', height: '68%' },
      hAxis: { textStyle: { color: '#6b7280', fontSize: 10 }, slantedText: true, slantedTextAngle: 45 },
      vAxis: { minValue: 0, textStyle: { color: '#6b7280' }, gridlines: { color: '#f3f4f6' } },
    });
    chartRegistry['chartPurok'] = chart;
    registerChartTable('chartPurok', data);
  }
  function drawAgeBracketChart() {
    document.getElementById('bkMinors').textContent  = AGE_BRACKET.minors.toLocaleString();
    document.getElementById('bkWorking').textContent = AGE_BRACKET.working.toLocaleString();
    document.getElementById('bkSeniors').textContent = AGE_BRACKET.seniors.toLocaleString();
    const data = google.visualization.arrayToDataTable([
      ['Bracket','Total'],
      ['Minors (0-17)', AGE_BRACKET.minors],
      ['Working Age (18-59)', AGE_BRACKET.working],
      ['Seniors (60+)', AGE_BRACKET.seniors],
    ]);
    const chart = new google.visualization.PieChart(document.getElementById('chartAgeBracket'));
    chart.draw(data, {
      pieHole: 0.55, colors: ['#3b82f6','#15803d','#f59e0b'], legend: { position: 'bottom', textStyle: { fontSize: 11 } },
      chartArea: { width: '92%', height: '80%' }, backgroundColor: 'transparent', pieSliceBorderColor: 'transparent',
    });
    chartRegistry['chartAgeBracket'] = chart;
    registerChartTable('chartAgeBracket', data);
  }
  function renderGender() {
    document.getElementById('genderMaleLbl').textContent = GENDER_TOTALS.male.toLocaleString();
    document.getElementById('genderFemaleLbl').textContent = GENDER_TOTALS.female.toLocaleString();
  }

  /* ?? BENEFICIARY MANAGEMENT (chart-only piece) ?? */
  function drawBenProgramsChart() {
    const rows = Object.entries(PROG_COUNTS).map(([k,v]) => [PROG_LABELS[k] || k, v]);
    const data = google.visualization.arrayToDataTable([['Program','Beneficiaries']].concat(rows));
    const chart = new google.visualization.ColumnChart(document.getElementById('chartBenPrograms'));
    chart.draw(data, {
      backgroundColor: 'transparent', legend: { position: 'none' }, colors: ['#15803d'],
      chartArea: { left: 46, right: 20, top: 20, bottom: 60, width: '90%', height: '70%' },
      hAxis: { textStyle: { color: '#6b7280', fontSize: 11 } },
      vAxis: { minValue: 0, textStyle: { color: '#6b7280' }, gridlines: { color: '#f3f4f6' } },
    });
    chartRegistry['chartBenPrograms'] = chart;
    registerChartTable('chartBenPrograms', data);
  }

  /* ?? BUSINESS / APARTMENT (chart-only pieces) ?? */
  function drawListingTypeChart() {
    const data = google.visualization.arrayToDataTable([
      ['Type','Total'], ['Apartments', LISTING_TYPE_COUNTS.apt], ['Businesses', LISTING_TYPE_COUNTS.biz],
    ]);
    const chart = new google.visualization.PieChart(document.getElementById('chartListingType'));
    chart.draw(data, {
      pieHole: 0.55, colors: ['#3b82f6','#f59e0b'], legend: { position: 'bottom', textStyle: { fontSize: 11 } },
      chartArea: { width: '92%', height: '78%' }, backgroundColor: 'transparent', pieSliceBorderColor: 'transparent',
    });
    chartRegistry['chartListingType'] = chart;
    registerChartTable('chartListingType', data);
  }
  function drawOccupancyChart() {
    const data = google.visualization.arrayToDataTable([
      ['Status','Units'], ['Available', OCCUPANCY_COUNTS.available], ['Occupied', OCCUPANCY_COUNTS.occupied],
    ]);
    const chart = new google.visualization.PieChart(document.getElementById('chartOccupancy'));
    chart.draw(data, {
      pieHole: 0.55, colors: ['#15803d','#dc2626'], legend: { position: 'bottom', textStyle: { fontSize: 11 } },
      chartArea: { width: '92%', height: '78%' }, backgroundColor: 'transparent', pieSliceBorderColor: 'transparent',
    });
    chartRegistry['chartOccupancy'] = chart;
    registerChartTable('chartOccupancy', data);
  }

  /* ?? EQUIPMENT (chart-only piece) ?? */
  function drawMostBorrowedChart() {
    if (!MOST_BORROWED.length) { document.getElementById('chartMostBorrowed').innerHTML = '<div class="mini-empty">No borrow history yet</div>'; return; }
    const rows = MOST_BORROWED.map(r => [r.equipmentName, parseInt(r.times_borrowed)]);
    const data = google.visualization.arrayToDataTable([['Equipment','Times Borrowed']].concat(rows));
    const chart = new google.visualization.BarChart(document.getElementById('chartMostBorrowed'));
    chart.draw(data, {
      backgroundColor: 'transparent', legend: { position: 'none' }, colors: ['#f59e0b'],
      chartArea: { left: 120, right: 30, top: 10, bottom: 30, width: '78%', height: '85%' },
      hAxis: { minValue: 0, textStyle: { color: '#6b7280' }, gridlines: { color: '#f3f4f6' } },
      vAxis: { textStyle: { color: '#6b7280', fontSize: 11 } },
    });
    chartRegistry['chartMostBorrowed'] = chart;
    registerChartTable('chartMostBorrowed', data);
  }

  /* ?? DOCUMENT REQUESTS ?? */
  function renderDocTypeStatus() {
    const tbody = document.getElementById('docTypeStatusBody');
    if (!DOC_TYPE_STATUS.length) { tbody.innerHTML = `<tr><td colspan="3"><div class="mini-empty">No document requests yet</div></td></tr>`; return; }
    tbody.innerHTML = DOC_TYPE_STATUS.map(r => {
      const st = (r.status||'').toLowerCase();
      const badgeCls = st === 'approved' ? 'mini-badge-approved' : st === 'rejected' ? 'mini-badge-rejected' : 'mini-badge-pending';
      const label = r.document_type.replace(/[_-]/g,' ').replace(/\b\w/g, c => c.toUpperCase());
      return `<tr><td>${escHtml2(label)}</td><td><span class="mini-badge ${badgeCls}">${escHtml2(r.status)}</span></td><td>${escHtml2(r.total)}</td></tr>`;
    }).join('');
  }
  function drawDocMonthlyChart() {
    if (!DOC_MONTHLY.length) { document.getElementById('chartDocMonthly').innerHTML = '<div class="mini-empty">No data available</div>'; return; }
    const data = google.visualization.arrayToDataTable([['Month','Requests']].concat(DOC_MONTHLY));
    const chart = new google.visualization.LineChart(document.getElementById('chartDocMonthly'));
    chart.draw(data, {
      backgroundColor: 'transparent', legend: { position: 'none' }, colors: ['#3b82f6'],
      chartArea: { left: 46, right: 20, top: 20, bottom: 40, width: '90%', height: '75%' },
      hAxis: { textStyle: { color: '#6b7280', fontSize: 10 } },
      vAxis: { minValue: 0, textStyle: { color: '#6b7280' }, gridlines: { color: '#f3f4f6' } },
      pointSize: 5, curveType: 'function',
    });
    chartRegistry['chartDocMonthly'] = chart;
    registerChartTable('chartDocMonthly', data);
  }

  /* ?? USER / ACCOUNT MANAGEMENT ?? */
  function drawRoleCountsChart() {
    if (!ROLE_COUNTS.length) { document.getElementById('chartRoleCounts').innerHTML = '<div class="mini-empty">No data available</div>'; return; }
    const data = google.visualization.arrayToDataTable([['Role','Accounts']].concat(ROLE_COUNTS));
    const chart = new google.visualization.PieChart(document.getElementById('chartRoleCounts'));
    chart.draw(data, {
      legend: { position: 'right', textStyle: { fontSize: 10 } },
      chartArea: { width: '90%', height: '85%' }, backgroundColor: 'transparent', pieSliceBorderColor: 'transparent',
    });
    chartRegistry['chartRoleCounts'] = chart;
    registerChartTable('chartRoleCounts', data);
  }
  function drawAccountStatusChart() {
    const data = google.visualization.arrayToDataTable([
      ['Status','Total'], ['Active', ACCOUNT_STATUS.active], ['Inactive / Pending / Rejected', ACCOUNT_STATUS.inactive],
    ]);
    const chart = new google.visualization.PieChart(document.getElementById('chartAccountStatus'));
    chart.draw(data, {
      pieHole: 0.55, colors: ['#15803d','#9ca3af'], legend: { position: 'bottom', textStyle: { fontSize: 11 } },
      chartArea: { width: '92%', height: '78%' }, backgroundColor: 'transparent', pieSliceBorderColor: 'transparent',
    });
    chartRegistry['chartAccountStatus'] = chart;
    registerChartTable('chartAccountStatus', data);
  }
  function drawRegTrendChart() {
    if (!REG_TREND.length) { document.getElementById('chartRegTrend').innerHTML = '<div class="mini-empty">No data available</div>'; return; }
    const data = google.visualization.arrayToDataTable([['Month','New Registrations']].concat(REG_TREND));
    const chart = new google.visualization.LineChart(document.getElementById('chartRegTrend'));
    chart.draw(data, {
      backgroundColor: 'transparent', legend: { position: 'none' }, colors: ['#15803d'],
      chartArea: { left: 46, right: 20, top: 20, bottom: 40, width: '90%', height: '75%' },
      hAxis: { textStyle: { color: '#6b7280', fontSize: 10 } },
      vAxis: { minValue: 0, textStyle: { color: '#6b7280' }, gridlines: { color: '#f3f4f6' } },
      pointSize: 5, curveType: 'function',
    });
    chartRegistry['chartRegTrend'] = chart;
    registerChartTable('chartRegTrend', data);
  }

  window.addEventListener('resize', () => {
    draw();
    const activeBtn = document.querySelector('.report-tab-btn.active');
    if (activeBtn) { reportDrawn[activeBtn.dataset.tab] = false; drawReportTab(activeBtn.dataset.tab); }
  });

  // Show loader on navigation
  document.querySelectorAll('[onclick^="window.location.href"], a[href]').forEach(btn => {
    btn.addEventListener('click', function(e) {
      if (this.tagName === 'A' && (this.target === '_blank' || this.href.includes('#') || this.href.startsWith('javascript:'))) return;
      const loader = document.getElementById('pageLoader');
      if(loader) {
        loader.classList.remove('opacity-0', 'pointer-events-none');
        loader.classList.add('opacity-100');
      }
    });
  });
</script>
<!-- ────────── GLOBAL LIST - SET CONDITIONS MODAL ────────── -->
<div class="gf-overlay" id="gfOverlay">
  <div class="gf-modal">
    <div class="gf-modal-header">
      <div>
        <h3>Set Conditions</h3>
        <p>Pick any combination of conditions below. Each one you set is added as a column in the results table.</p>
      </div>
      <button type="button" class="gf-modal-close" onclick="closeGlobalFilterModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <div class="gf-modal-body">

      <div class="gf-section">
        <p class="gf-section-title">Account &amp; Registration</p>
        <div class="gf-grid">
          <div class="gf-field">
            <label>Account Role</label>
            <select id="gfAccountRole">
              <option value="">Any</option>
              <option value="resident">Resident</option>
              <option value="non-resident">Non-Resident</option>
              <option value="business/apartment owner">Business/Apartment Owner</option>
            </select>
          </div>
          <div class="gf-field gf-span-2">
            <label>Date Created</label>
            <div class="gf-range">
              <input type="date" id="gfDateFrom"><span>to</span><input type="date" id="gfDateTo">
            </div>
          </div>
          <div class="gf-field">
            <label>Resident Since Birth</label>
            <select id="gfResidentBirth"><option value="">Any</option><option value="1">Yes</option><option value="0">No</option></select>
          </div>
        </div>
      </div>

      <div class="gf-section">
        <p class="gf-section-title">Personal &amp; Demographics</p>
        <div class="gf-grid">
          <div class="gf-field">
            <label>Sex</label>
            <select id="gfSex"><option value="">Any</option><option value="male">Male</option><option value="female">Female</option></select>
          </div>
          <div class="gf-field">
            <label>Birth Month</label>
            <select id="gfBirthMonth">
              <option value="">Any</option>
              <?php $gfMonths = ['January','February','March','April','May','June','July','August','September','October','November','December'];
                foreach ($gfMonths as $mi => $mn): ?>
              <option value="<?= $mi + 1 ?>"><?= $mn ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="gf-field">
            <label>Birth Year</label>
            <select id="gfBirthYear">
              <option value="">Any</option>
              <?php $gfCurYear = (int)date('Y'); for ($y = $gfCurYear; $y >= $gfCurYear - 100; $y--): ?>
              <option value="<?= $y ?>"><?= $y ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="gf-field">
            <label>Age Range</label>
            <div class="gf-range">
              <input type="number" id="gfAgeMin" min="0" placeholder="Min"><span>-</span><input type="number" id="gfAgeMax" min="0" placeholder="Max">
            </div>
          </div>
          <div class="gf-field">
            <label>Family Role</label>
            <select id="gfFamilyRole">
              <option value="">Any</option>
              <option value="head">Head</option>
              <option value="spouse">Spouse</option>
              <option value="child">Child</option>
              <option value="parent">Parent</option>
              <option value="sibling">Sibling</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div class="gf-field">
            <label>Civil Status</label>
            <select id="gfCivilStatus">
              <option value="">Any</option>
              <option value="single">Single</option>
              <option value="married">Married</option>
              <option value="widowed">Widowed</option>
              <option value="separated">Separated</option>
              <option value="divorced">Divorced</option>
            </select>
          </div>
          <div class="gf-field"><label>Citizenship</label><input type="text" id="gfCitizenship" placeholder="e.g. Filipino"></div>
          <div class="gf-field"><label>Religion</label><input type="text" id="gfReligion" placeholder="e.g. Catholic"></div>
          <div class="gf-field"><label>Ethnicity</label><input type="text" id="gfEthnicity" placeholder="e.g. Tagalog"></div>
          <div class="gf-field">
            <label>Blood Type</label>
            <select id="gfBloodType">
              <option value="">Any</option>
              <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bt): ?>
              <option value="<?= $bt ?>"><?= $bt ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>

      <div class="gf-section">
        <p class="gf-section-title">Address</p>
        <div class="gf-grid">
          <div class="gf-field gf-span-2"><label>Address (Purok / Street / Barangay / City)</label><input type="text" id="gfAddress" placeholder="Search address..."></div>
        </div>
      </div>

      <div class="gf-section">
        <p class="gf-section-title">Employment &amp; Income</p>
        <div class="gf-grid">
          <div class="gf-field">
            <label>Employment Status</label>
            <select id="gfEmploymentStatus">
              <option value="">Any</option>
              <option value="employed">Employed</option>
              <option value="self-employed">Self-Employed</option>
              <option value="unemployed">Unemployed</option>
              <option value="student">Student</option>
            </select>
          </div>
          <div class="gf-field gf-span-2">
            <label>Monthly Income Range</label>
            <div class="gf-range">
              <input type="number" id="gfIncomeMin" min="0" placeholder="Min ?"><span>-</span><input type="number" id="gfIncomeMax" min="0" placeholder="Max ?">
            </div>
          </div>
        </div>
      </div>

      <div class="gf-section<?= $canBeneficiary ? '' : ' gf-section-locked' ?>">
        <p class="gf-section-title">
          Housing &amp; Utilities
          <?php if (!$canBeneficiary): ?><span class="gf-lock-badge"><i class="fa-solid fa-lock"></i> Requires Beneficiary Management access</span><?php endif; ?>
        </p>
        <div class="gf-grid">
          <div class="gf-field">
            <label>Housing Status</label>
            <select id="gfHousingStatus" <?= $canBeneficiary ? '' : 'disabled' ?>>
              <option value="">Any</option>
              <option value="owned">Owned</option>
              <option value="renting">Renting</option>
              <option value="shared">Shared</option>
              <option value="informal_settler">Informal Settler</option>
              <option value="government_housing">Government Housing</option>
            </select>
          </div>
          <div class="gf-field">
            <label>House Material</label>
            <select id="gfHouseMaterial" <?= $canBeneficiary ? '' : 'disabled' ?>>
              <option value="">Any</option>
              <option value="concrete">Concrete</option>
              <option value="mixed">Mixed</option>
              <option value="light_materials">Light Materials</option>
              <option value="makeshift">Makeshift</option>
              <option value="wood">Wood</option>
            </select>
          </div>
          <div class="gf-field">
            <label>Electricity</label>
            <select id="gfElectricity" <?= $canBeneficiary ? '' : 'disabled' ?>>
              <option value="">Any</option>
              <option value="own_meter">Own Meter</option>
              <option value="shared">Shared</option>
              <option value="no_electricity">No Electricity</option>
            </select>
          </div>
          <div class="gf-field">
            <label>Water Source</label>
            <select id="gfWaterSource" <?= $canBeneficiary ? '' : 'disabled' ?>>
              <option value="">Any</option>
              <option value="piped_faucet">Piped Faucet</option>
              <option value="shared_well">Shared Well</option>
              <option value="own_well">Own Well</option>
              <option value="bottled_mineral">Bottled/Mineral</option>
            </select>
          </div>
          <div class="gf-field">
            <label>Toilet Type</label>
            <select id="gfToiletType" <?= $canBeneficiary ? '' : 'disabled' ?>>
              <option value="">Any</option>
              <option value="private_flush">Private Flush</option>
              <option value="shared_public">Shared/Public</option>
              <option value="none_pit">None / Pit</option>
            </select>
          </div>
        </div>
      </div>

      <div class="gf-section">
        <p class="gf-section-title">Social Programs &amp; Welfare</p>
        <div class="gf-grid">
          <div class="gf-field<?= $canBeneficiary ? '' : ' gf-field-locked' ?>"><label>Pregnant / Children &lt;5</label><select id="gfPregnantChildren" <?= $canBeneficiary ? '' : 'disabled' ?>><option value="">Any</option><option value="1">Yes</option><option value="0">No</option></select></div>
          <div class="gf-field<?= $canBeneficiary ? '' : ' gf-field-locked' ?>"><label>Is PWD</label><select id="gfIsPwd" <?= $canBeneficiary ? '' : 'disabled' ?>><option value="">Any</option><option value="1">Yes</option><option value="0">No</option></select></div>
          <div class="gf-field<?= $canBeneficiary ? '' : ' gf-field-locked' ?>"><label>Is Solo Parent</label><select id="gfIsSoloParent" <?= $canBeneficiary ? '' : 'disabled' ?>><option value="">Any</option><option value="1">Yes</option><option value="0">No</option></select></div>
          <div class="gf-field<?= $canBeneficiary ? '' : ' gf-field-locked' ?>"><label>Is Indigenous Person</label><select id="gfIsIndigenous" <?= $canBeneficiary ? '' : 'disabled' ?>><option value="">Any</option><option value="1">Yes</option><option value="0">No</option></select></div>
          <div class="gf-field<?= $canBeneficiary ? '' : ' gf-field-locked' ?>"><label>Is 4Ps Member</label><select id="gfIs4ps" <?= $canBeneficiary ? '' : 'disabled' ?>><option value="">Any</option><option value="1">Yes</option><option value="0">No</option></select></div>
          <div class="gf-field<?= $canBeneficiary ? '' : ' gf-field-locked' ?>"><label>Is Scholarship Recipient</label><select id="gfIsScholarship" <?= $canBeneficiary ? '' : 'disabled' ?>><option value="">Any</option><option value="1">Yes</option><option value="0">No</option></select></div>
          <div class="gf-field<?= $canBeneficiary ? '' : ' gf-field-locked' ?>"><label>Is Kabataan (15-30)</label><select id="gfIsKabataan" <?= $canBeneficiary ? '' : 'disabled' ?>><option value="">Any</option><option value="1">Yes</option><option value="0">No</option></select></div>
          <div class="gf-field<?= $canBeneficiary ? '' : ' gf-field-locked' ?>">
            <label>Pension Status</label>
            <select id="gfPensionStatus" <?= $canBeneficiary ? '' : 'disabled' ?>>
              <option value="">Any</option>
              <option value="none">None</option>
              <option value="sss">SSS</option>
              <option value="gsis">GSIS</option>
              <option value="social_pension">Social Pension</option>
            </select>
          </div>
          <div class="gf-field"><label>Is Voter</label><select id="gfIsVoter"><option value="">Any</option><option value="1">Yes</option><option value="0">No</option></select></div>
        </div>
        <?php if (!$canBeneficiary): ?><p class="gf-locked-note"><i class="fa-solid fa-lock"></i> Most of these fields require Beneficiary Management access - only "Is Voter" is available without it.</p><?php endif; ?>
      </div>

      <div class="gf-section<?= $canBeneficiary ? '' : ' gf-section-locked' ?>">
        <p class="gf-section-title">
          Education
          <?php if (!$canBeneficiary): ?><span class="gf-lock-badge"><i class="fa-solid fa-lock"></i> Requires Beneficiary Management access</span><?php endif; ?>
        </p>
        <div class="gf-grid">
          <div class="gf-field"><label>School</label><input type="text" id="gfSchool" placeholder="School name" <?= $canBeneficiary ? '' : 'disabled' ?>></div>
          <div class="gf-field"><label>Course</label><input type="text" id="gfCourse" placeholder="Course / program" <?= $canBeneficiary ? '' : 'disabled' ?>></div>
          <div class="gf-field">
            <label>Year Level</label>
            <select id="gfYearLevel" <?= $canBeneficiary ? '' : 'disabled' ?>>
              <option value="">Any</option>
              <option value="1st_year">1st Year</option>
              <option value="2nd_year">2nd Year</option>
              <option value="3rd_year">3rd Year</option>
              <option value="4th_year">4th Year</option>
              <option value="5th_year">5th Year</option>
            </select>
          </div>
          <div class="gf-field"><label>GWA / GPA</label><input type="text" id="gfGwa" placeholder="e.g. 1.75" <?= $canBeneficiary ? '' : 'disabled' ?>></div>
        </div>
      </div>

    </div>

    <div class="gf-modal-footer">
      <button type="button" class="gf-btn-reset" onclick="resetGlobalFilters()"><i class="fa-solid fa-rotate-left"></i> Reset all</button>
      <button type="button" class="gf-btn-apply" onclick="applyGlobalFilters()"><i class="fa-solid fa-check"></i> Apply Conditions</button>
    </div>
  </div>
</div>

<!-- ────────── PRINT REPORT - CHART/GRAPH PICKER MODAL ────────── -->
<?php
$arGroups = [];
foreach ($ANALYTICS_REPORT_ITEMS as $arKey => $arItem) {
    $arGroups[$arItem['group']][$arKey] = $arItem;
}

// Which permission module (if any) each chart group requires. Groups not
// listed here (currently just 'Overview') are always available to anyone
// who can reach this dashboard at all. 'User / Accounts' has no grantable
// module - account management stays exclusive to the founding admin.
$arGroupModuleMap = [
    'Resident Management'    => 'manage_residents',
    'Beneficiary Management' => 'manage_beneficiaries',
    'Business / Apartment'   => 'manage_listings',
    'Equipment'              => 'manage_borrowing',
    'Document Requests'      => 'manage_documents',
];

// Quick "at a glance" numbers shown above each module's chart checklist,
// so admins/staff can see the current scale of a module before deciding
// what to include in the report. Reuses the same figures already computed
// above for the dashboard's own charts/stat cards - no extra queries.
$arBeneficiaryApproved = (int) ($requestStatusBeneficiary['approved'] ?? 0);
$arBeneficiaryPending  = (int) ($requestStatusBeneficiary['pending']  ?? 0);
$arBeneficiaryRejected = (int) ($requestStatusBeneficiary['rejected'] ?? 0);

$arEquipmentApproved = (int) ($requestStatusEquipment['approved'] ?? 0);
$arEquipmentPending  = (int) ($requestStatusEquipment['pending']  ?? 0);
$arEquipmentRejected = (int) ($requestStatusEquipment['rejected'] ?? 0);

$arDocApproved = (int) ($requestStatusDoc['approved'] ?? 0);
$arDocPending  = (int) ($requestStatusDoc['pending']  ?? 0);
$arDocRejected = (int) ($requestStatusDoc['rejected'] ?? 0);

$arTotalAccounts = $activeAccounts + $inactiveAccounts;

$arGroupStats = [
    'Overview' => [
        ['icon' => 'fa-users',               'label' => 'Residents',        'value' => $registeredResidents,       'color' => '#3b82f6'],
        ['icon' => 'fa-hammer',               'label' => 'Borrowed Now',     'value' => $activeBorrowedEquipment,   'color' => '#f59e0b'],
        ['icon' => 'fa-building',             'label' => 'Listings',         'value' => $activeListings,            'color' => '#a855f7'],
        ['icon' => 'fa-user',                 'label' => 'Non-Residents',    'value' => $nonResidentUsers,          'color' => 'var(--site-primary)'],
    ],
    'Resident Management' => [
        ['icon' => 'fa-house-chimney-user',   'label' => 'Approved Residents','value' => $registeredResidents,      'color' => '#3b82f6'],
        ['icon' => 'fa-mars',                 'label' => 'Male',             'value' => $maleTotal,                 'color' => '#0ea5e9'],
        ['icon' => 'fa-venus',                'label' => 'Female',           'value' => $femaleTotal,               'color' => '#ec4899'],
        ['icon' => 'fa-child-reaching',       'label' => 'Minors',           'value' => $bracketMinors,             'color' => '#f59e0b'],
    ],
    'Beneficiary Management' => [
        ['icon' => 'fa-hand-holding-heart',   'label' => 'Total',            'value' => $arBeneficiaryApproved + $arBeneficiaryPending + $arBeneficiaryRejected, 'color' => 'var(--site-primary)'],
        ['icon' => 'fa-circle-check',         'label' => 'Approved',         'value' => $arBeneficiaryApproved,     'color' => '#15803d'],
        ['icon' => 'fa-hourglass-half',       'label' => 'Pending',          'value' => $arBeneficiaryPending,      'color' => '#d97706'],
        ['icon' => 'fa-circle-xmark',         'label' => 'Rejected',         'value' => $arBeneficiaryRejected,     'color' => '#dc2626'],
    ],
    'Business / Apartment' => [
        ['icon' => 'fa-building',             'label' => 'Total Listings',   'value' => $activeListings,            'color' => '#a855f7'],
        ['icon' => 'fa-store',                'label' => 'Business',         'value' => $bizCountTotal,             'color' => '#6366f1'],
        ['icon' => 'fa-door-open',             'label' => 'Apartment',        'value' => $aptCountTotal,             'color' => '#0ea5e9'],
        ['icon' => 'fa-circle-check',         'label' => 'Available Units',  'value' => $availableUnits,            'color' => '#15803d'],
    ],
    'Equipment' => [
        ['icon' => 'fa-hammer',               'label' => 'Borrowed Now',     'value' => $activeBorrowedEquipment,   'color' => '#f59e0b'],
        ['icon' => 'fa-circle-check',         'label' => 'Borrowed/Returned','value' => $arEquipmentApproved,       'color' => '#15803d'],
        ['icon' => 'fa-hourglass-half',       'label' => 'Pending',          'value' => $arEquipmentPending,        'color' => '#d97706'],
        ['icon' => 'fa-circle-xmark',         'label' => 'Rejected',         'value' => $arEquipmentRejected,       'color' => '#dc2626'],
    ],
    'Document Requests' => [
        ['icon' => 'fa-circle-check',         'label' => 'Approved',         'value' => $arDocApproved,             'color' => '#15803d'],
        ['icon' => 'fa-hourglass-half',       'label' => 'Pending',          'value' => $arDocPending,              'color' => '#d97706'],
        ['icon' => 'fa-circle-xmark',         'label' => 'Rejected',         'value' => $arDocRejected,             'color' => '#dc2626'],
        ['icon' => 'fa-clock',                'label' => 'Avg Turnaround',   'value' => $avgTurnaroundHours . 'h',  'color' => '#0ea5e9'],
    ],
    'User / Accounts' => [
        ['icon' => 'fa-users',                'label' => 'Total Accounts',   'value' => $arTotalAccounts,           'color' => '#3b82f6'],
        ['icon' => 'fa-user-check',           'label' => 'Active',           'value' => $activeAccounts,            'color' => '#15803d'],
        ['icon' => 'fa-user-slash',           'label' => 'Inactive',         'value' => $inactiveAccounts,          'color' => '#dc2626'],
    ],
];
?>
<div class="gf-overlay" id="arOverlay">
  <div class="gf-modal">
    <div class="gf-modal-header">
      <div>
        <h3>Print Report</h3>
        <p>Pick the charts and graphs to include. Each one comes with a short summary explaining what it shows.</p>
      </div>
      <button type="button" class="gf-modal-close" onclick="closeAnalyticsModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <div class="gf-modal-body">
      <div class="ar-toolbar">
        <span class="text-xs text-gray-400"></span>
        <div class="ar-toolbar-links">
          <a onclick="toggleAllAnalytics(true)">Select all</a>
          <a onclick="toggleAllAnalytics(false)">Clear all</a>
        </div>
      </div>

      <?php foreach ($arGroups as $arGroupName => $arItems):
        $arRequiredModule = $arGroupModuleMap[$arGroupName] ?? null;
        $arGroupLocked = ($arGroupName === 'User / Accounts')
            ? ($role !== 'admin')
            : ($arRequiredModule !== null && !in_array($arRequiredModule, $myPermissions, true));
      ?>
        <div class="gf-section<?= $arGroupLocked ? ' gf-section-locked' : '' ?>">
          <p class="gf-section-title">
            <?= e($arGroupName) ?>
            <?php if ($arGroupLocked): ?><span class="gf-lock-badge"><i class="fa-solid fa-lock"></i> No access</span><?php endif; ?>
          </p>
          <?php if (!empty($arGroupStats[$arGroupName])): ?>
          <div class="ar-stats-row">
            <?php foreach ($arGroupStats[$arGroupName] as $arStat): ?>
              <div class="ar-stat-card">
                <div class="ar-stat-ico" style="background:<?= e($arStat['color']) ?>;"><i class="fa-solid <?= e($arStat['icon']) ?>"></i></div>
                <div class="ar-stat-text">
                  <p class="ar-stat-num"><?= is_numeric($arStat['value']) ? number_format((float) $arStat['value']) : e($arStat['value']) ?></p>
                  <p class="ar-stat-label"><?= e($arStat['label']) ?></p>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <div class="ar-check-grid">
            <?php foreach ($arItems as $arKey => $arItem): ?>
              <label class="ar-check-item<?= $arGroupLocked ? ' ar-check-item-locked' : '' ?>">
                <input type="checkbox" value="<?= e($arKey) ?>" <?= $arGroupLocked ? 'disabled' : '' ?>>
                <span>
                  <span class="ar-item-title"><?= e($arItem['title']) ?></span>
                  <span class="ar-item-desc"><?= e($arItem['summary']) ?></span>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="gf-modal-footer">
      <button type="button" class="gf-btn-reset" onclick="closeAnalyticsModal()">Cancel</button>
      <button type="button" class="gf-btn-apply" onclick="generateAnalyticsReport()"><i class="fa-solid fa-print"></i> Generate Report</button>
    </div>
  </div>
</div>

</body>
</html>