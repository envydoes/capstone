<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$role = $_SESSION['account_role'] ?? '';
require_once __DIR__ . '/../includes/check_permissions.php';

// 1. Connect to database FIRST
$host = "o7jpqmin0zgconui4xtnfju6"; 
$dbuser = "root"; 
$password = "UKkJ05DHQDMMMOxFEUI5f1HJGVj8Vb5gfJAEvAESTGCVWDtFEGb42qX67AxGUXvj"; 
$database = "sumeste_db";

$conn = mysqli_connect($host, $dbuser, $password, $database);
if (!$conn) { 
    session_unset(); 
    session_destroy(); 
    die("Connection failed: " . mysqli_connect_error()); 
}

$myPerms = get_my_permissions($conn);
if ($role !== 'admin' && empty($myPerms)) {
    switch ($role) {
        case 'resident':
        case 'resident,business/apartment owner':
            header('Location: ../resident/residentLanding.php'); 
            break;
        case 'non-resident':
        case 'non-resident,business/apartment owner':
            header('Location: ../nonresident/nonresidentLanding.php'); 
            break;
        default:
            header('Location: ../landing.php');
    }
    exit;
}
require_permission($conn, 'manage_beneficiaries');

ob_start();

function e($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/**
 * Determine which programs a person is eligible for.
 * prio_score comes from DB â€” no recalculation.
 * Eligibility is determined purely from stored fields + age.
 *
 * Program   | Score threshold | Key conditions
 * ----------|-----------------|--------------------------------------------
 * 4Ps       | 70â€“100          | house_material, electricity/water, pregnant_or_children
 * Senior    | 60â€“100 / ageâ‰¥60 | age >= 60
 * Scholarship| 75â€“100         | gwa_gpa 1.00â€“1.75, low monthly_income
 * PWD       | 80â€“100          | is_pwd=1 + pwd_id_number, health conditions
 * Kabataan  | compliance      | age 15â€“30
 * Voters    | compliance      | age >= 18
 */
function getEligiblePrograms(array $row): array {
    // Compute age from birthday
    $age = 0;
    if (!empty($row['birthday'])) {
        $bday = new DateTime($row['birthday']);
        $age  = (new DateTime())->diff($bday)->y;
    }

    $score = (int)($row['prio_score'] ?? 0);
    $eligible = [];

    // â”€â”€ 4Ps: specific housing, utility, pregnant, income conditions
    $bad_house   = in_array(strtolower($row['housing_status'] ?? ''), ['informal_settler', 'shared', 'government_housing']);
    $bad_mat     = in_array(strtolower($row['house_material'] ?? ''), ['light_materials', 'makeshift', 'wood']);
    $bad_elec    = in_array(strtolower($row['electricity'] ?? ''), ['shared', 'no_electricity']);
    $bad_water   = (strtolower($row['water_source'] ?? '') === 'shared_well');
    $bad_toilet  = in_array(strtolower($row['toilet_type'] ?? ''), ['none_pit', 'shared_public']);
    $preg_child  = !empty($row['pregnant_or_children']) && $row['pregnant_or_children'] == 1;
    $income      = (float)($row['monthly_income'] ?? 0);
    
    if ($bad_house && $bad_mat && $bad_elec && $bad_water && $bad_toilet && $preg_child && $income < 14000) {
        $eligible[] = '4ps';
    }

    // â”€â”€ Senior Citizen: age >= 60
    if ($age >= 60) {
        $eligible[] = 'senior';
    }

    // â”€â”€ Scholarship: not empty school, have year level, gpa 1.00 - 1.75
    $school = trim($row['school_name'] ?? '');
    $yrLvl = trim($row['year_level'] ?? '');
    $gwaStr = trim($row['gwa_gpa'] ?? '');
    $gwaFloat = $gwaStr !== '' ? (float)$gwaStr : null;
    
    if ($school !== '' && $yrLvl !== '' && $gwaFloat !== null && $gwaFloat >= 1.00 && $gwaFloat <= 1.75) {
        $eligible[] = 'scholarship';
    }

    // â”€â”€ PWD: is_pwd = 1 AND has valid ID number
    if (!empty($row['is_pwd']) && $row['is_pwd'] == 1 && !empty($row['pwd_id_number'])) {
        $eligible[] = 'pwd';
    }

    // â”€â”€ Kabataan/SK: age 15â€“30
    if ($age >= 15 && $age <= 30) {
        $eligible[] = 'kabataan';
    }

    // â”€â”€ Registered Voters: age >= 18
    if ($age >= 18) {
        $eligible[] = 'voters';
    }

    return $eligible;
}

function buildRow(array $row): array {
    $age = 0;
    if (!empty($row['birthday'])) {
        $bday = new DateTime($row['birthday']);
        $age  = (new DateTime())->diff($bday)->y;
    }
    $row['_age']      = $age;
    $row['_name']     = trim(
        ($row['firstname'] ?? '') . ' '
        . ($row['middlename'] ? $row['middlename'] . ' ' : '')
        . ($row['lastname']  ?? '')
        . ($row['suffix']    ? ' ' . $row['suffix'] : '')
    );
    $row['_eligible'] = getEligiblePrograms($row);
    return $row;
}

require_once '../includes/site_config.php';
$siteSettings = site_config_load($conn);

function sidebar_menu_allowed(string $key, string $role, array $myPermissions): bool
{
  if ($role === 'admin') {
    return true;
  }

  return in_array($key, $myPermissions, true);
}

$myPermissions = get_my_permissions($conn);
$sidebarSections = [
  'Management' => [
    ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'fa-chart-bar', 'href' => 'adminDashboard.php'],
    ['key' => 'manage_users', 'label' => 'User Management', 'icon' => 'fa-user', 'href' => 'userManagement.php', 'admin_only' => true],
    ['key' => 'manage_residents', 'label' => 'Resident Management', 'icon' => 'fa-house-chimney-user', 'href' => 'residentManagement.php'],
    ['key' => 'manage_beneficiaries', 'label' => 'Beneficiary Management', 'icon' => 'fa-hand-holding-heart', 'href' => 'beneficiaryManagement.php', 'active' => true],
    ['key' => 'manage_documents', 'label' => 'Document Request', 'icon' => 'fa-file-lines', 'href' => 'documentRequest.php'],
    ['key' => 'manage_borrowing', 'label' => 'Borrowing System', 'icon' => 'fa-hammer', 'href' => 'borrowingSystem.php'],
  ],
  'Community' => [
    ['key' => 'manage_listings', 'label' => 'Community Listings', 'icon' => 'fa-building', 'href' => 'communityListings.php'],
    ['key' => 'manage_announcements', 'label' => 'Announcements', 'icon' => 'fa-pen-to-square', 'href' => 'announcement.php'],
  ],
];

/* â”€â”€ Fetch pending applications â”€â”€ */
$sql_req = "
    SELECT b.*, ui.firstname, ui.lastname, ui.middlename, ui.suffix,
           ui.birthday, ui.phone, ui.email, ui.street, ui.barangay, ui.city, ui.province
    FROM tbl_beneficiary b
    JOIN tbl_userinfo ui ON b.userID = ui.userID
    WHERE b.status = 'pending'
    ORDER BY b.submitted_at DESC
";
$res_req = mysqli_query($conn, $sql_req);
$pending_with_scores = [];
if ($res_req) {
    while ($r = mysqli_fetch_assoc($res_req)) {
        $pending_with_scores[] = buildRow($r);
    }
}
require_once '../includes/site_config.php';
$siteSettings = site_config_load($conn);

/* â”€â”€ Fetch approved beneficiaries (already ranked by prio_score DESC) â”€â”€ */
$sql_ben = "
    SELECT b.*, ui.firstname, ui.lastname, ui.middlename, ui.suffix,
           ui.birthday, ui.phone, ui.email, ui.street, ui.barangay, ui.city, ui.province
    FROM tbl_beneficiary b
    JOIN tbl_userinfo ui ON b.userID = ui.userID
    WHERE b.status = 'approved'
    ORDER BY b.prio_score DESC, b.submitted_at ASC
";
$res_ben = mysqli_query($conn, $sql_ben);
$beneficiaries_processed = [];
if ($res_ben) {
    while ($r = mysqli_fetch_assoc($res_ben)) {
        $beneficiaries_processed[] = buildRow($r);
    }
}

$total_pending = count($pending_with_scores);
$total_ben     = count($beneficiaries_processed);

// â”€â”€ Stat cards: Beneficiary Overview â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

// Assistance Requests This Month, vs Last Month (application volume, any status)
$reqTrendRow = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        SUM(CASE WHEN COALESCE(submitted_at, created_at) >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN 1 ELSE 0 END) AS this_month,
        SUM(CASE WHEN COALESCE(submitted_at, created_at) >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
                  AND COALESCE(submitted_at, created_at) <  DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN 1 ELSE 0 END) AS last_month
    FROM tbl_beneficiary
"));
$reqThisMonth = (int) ($reqTrendRow['this_month'] ?? 0);
$reqLastMonth = (int) ($reqTrendRow['last_month'] ?? 0);
if ($reqLastMonth > 0) {
    $reqTrendPct = (int) round((($reqThisMonth - $reqLastMonth) / $reqLastMonth) * 100);
} else {
    $reqTrendPct = $reqThisMonth > 0 ? 100 : 0;
}
$reqTrendDir = $reqThisMonth > $reqLastMonth ? 'up' : ($reqThisMonth < $reqLastMonth ? 'down' : 'flat');

// New Beneficiaries This Month (approved this month â€” uses updated_at as an
// "approved on" proxy, since updated_at auto-stamps on any row edit, not
// only approval, treat as an estimate)
$newBenThisMonth = (int) mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(*) AS total FROM tbl_beneficiary
    WHERE status = 'approved'
      AND updated_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
"))['total'];

// Beneficiary Coverage Rate: distinct approved beneficiaries / total registered residents
$totalResidents = (int) mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(*) AS total
    FROM tbl_userinfo
    WHERE LOWER(userStatus) = 'approved'
      AND account_role_csv LIKE '%resident%'
      AND NOT account_role_csv LIKE '%non-resident%'
"))['total'];

$approvedBeneficiaryResidents = (int) mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(DISTINCT b.userID) AS total
    FROM tbl_beneficiary b
    JOIN tbl_userinfo ui ON b.userID = ui.userID
    WHERE b.status = 'approved'
      AND LOWER(ui.userStatus) = 'approved'
      AND ui.account_role_csv LIKE '%resident%'
      AND NOT ui.account_role_csv LIKE '%non-resident%'
"))['total'];

$coverageRate = $totalResidents > 0 ? round(($approvedBeneficiaryResidents / $totalResidents) * 100) : 0;

// Average Processing Time: request submitted -> approved
// tbl_beneficiary.updated_at auto-stamps on any row change (ON UPDATE
// CURRENT_TIMESTAMP), so this is available with no schema change â€” same
// caveat as above: an edit after approval would nudge this up slightly.
$avgProcRow = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT AVG(TIMESTAMPDIFF(HOUR, COALESCE(submitted_at, created_at), updated_at)) AS avg_hours
    FROM tbl_beneficiary
    WHERE status = 'approved'
"));
$avgProcessingHours = ($avgProcRow && $avgProcRow['avg_hours'] !== null)
    ? round((float) $avgProcRow['avg_hours'], 1)
    : null;

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Beneficiary Management â€” <?= e($siteSettings['site_title']) ?></title>
  <link rel="icon" href="<?= e(site_config_logo_url($siteSettings, '../')) ?>" type="image/png">
  <?= site_config_css_vars($siteSettings) ?>
  <script src="https://cdn.tailwindcss.com/3.4.16"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    * { box-sizing: border-box; }
    body { font-family: 'DM Sans', sans-serif; background: var(--site-primary-pale); margin: 0; }

    /* â”€â”€ Sidebar (same as residentManagement.php) â”€â”€ */
    .sidebar {
       width:260px;
       flex-shrink:0; 
       background: linear-gradient(180deg, var(--site-primary-dark) 0%, var(--site-primary-darker) 55%, var(--site-primary) 100%); 
       display:flex; flex-direction:column; position:fixed; top:0; left:0; height:100vh; z-index:300; overflow:hidden; transition:width 0.3s cubic-bezier(0.4,0,0.2,1),transform 0.3s cubic-bezier(0.4,0,0.2,1); }
    .sidebar.collapsed { width:0; }
    .sidebar:not(.collapsed) { overflow-y:auto; }
    .sidebar::-webkit-scrollbar { width:4px; }
    .sidebar::-webkit-scrollbar-thumb { background:rgba(134,239,172,0.2); border-radius:4px; }
    .sidebar-inner { width:260px; min-width:260px; display:flex; flex-direction:column; height:100%; }
    .sidebar-logo { padding:20px 18px 16px; border-bottom:1px solid rgba(134,239,172,0.12); display:flex; align-items:center; justify-content:space-between; flex-shrink:0; }
    .section-label { padding:18px 18px 6px; font-size:.62rem; font-weight:700; letter-spacing:.13em; text-transform:uppercase; color:rgba(255,255,255,0.4); white-space:nowrap; }
    .menu-item { display:flex; align-items:center; justify-content:space-between; width:calc(100% - 16px); padding:10px 14px; margin:1px 8px; border-radius:10px; color:rgba(255,255,255,0.72); font-size:0.84rem; font-weight:500; text-decoration:none; border:none; background:none; text-align:left; cursor:pointer; transition:background 0.18s,color 0.18s; white-space:nowrap; }
    .menu-item:hover { background:rgba(255,255,255,0.07); color:#fff; }
    .menu-item.active { background:rgba(255,255,255,0.13); color:#fff; }
    .menu-left { display:flex; align-items:center; gap:11px; }
    .mi { width:17px; text-align:center; font-size:0.85rem; flex-shrink:0; }
    .active-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--site-primary-light); flex-shrink: 0; }
    .collapse-btn { width: 28px; height: 28px; border-radius: 8px; background: rgba(255,255,255,0.1); border: none; cursor: pointer; color: #fff; font-size: 0.72rem; display: flex; align-items: center; justify-content: center; flex-shrink: 0; transition: background 0.2s; }
    .collapse-btn:hover { background: rgba(255,255,255,0.22); }
    .expand-btn { position:fixed; top:18px; left:12px; z-index:200; width:36px; height:36px; border-radius:10px; background:var(--site-primary-darker); border:1px solid rgba(134,239,172,0.25); color:#fff; font-size:0.82rem; cursor:pointer; display:none; align-items:center; justify-content:center; box-shadow:0 4px 16px rgba(5,46,22,0.4); transition:background 0.2s; }
    .expand-btn.visible { display:flex; }
    .expand-btn:hover { background:var(--site-primary); }
    .sidebar-backdrop { display:none; position:fixed; inset:0; z-index:250; background:rgba(5,46,22,0.5); backdrop-filter:blur(2px); }
    .sidebar-backdrop.visible { display:block; }
    .sidebar-bottom { margin-top:auto; flex-shrink:0; }
    .sidebar-bottom-links { padding:0 16px 8px; }
    .side-link { display:block; width:100%; font-size:0.84rem; padding:8px 8px; border-radius:8px; transition:color 0.15s,background 0.15s; text-decoration:none; white-space:nowrap; border:none; background:none; text-align:left; cursor:pointer; }
    .main-wrapper { display:flex; min-height:100vh; }
    .main-content { flex:1; min-width:0; display:flex; flex-direction:column; margin-left:260px; transition:margin-left 0.3s cubic-bezier(0.4,0,0.2,1); overflow-x:hidden; }
    .main-content.sidebar-collapsed { margin-left:0; }

    /* Topbar */
    .topbar { background:#fff; border-bottom:1px solid #e5e7eb; padding:14px 28px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; position:sticky; top:0; z-index:100; }
    .topbar-title-block { transition:margin-left 0.25s ease; }

    /* Stat cards */
    .stat-card { background:#fff; border-radius:14px; padding:20px 22px; border:1px solid #e5e7eb; box-shadow:0 2px 12px rgba(21,128,61,0.05); display:flex; flex-direction:column; gap:10px; transition:transform .2s, box-shadow .2s; }
    .stat-card:hover { transform:translateY(-3px); box-shadow:0 8px 24px rgba(21,128,61,.1); }
    .stat-label { font-size:.82rem; font-weight:600; color:#6b7280; }
    .stat-row { display:flex; align-items:center; gap:14px; }
    .stat-ico { font-size:1.6rem; }
    .stat-num { font-size:2.4rem; font-weight:800; color:#111827; line-height:1; }
    .stat-sub { font-size:.75rem; font-weight:600; color:#9ca3af; }
    .stat-trend { display:inline-flex; align-items:center; gap:4px; font-size:.78rem; font-weight:700; }
    .stat-trend-up { color:#15803d; }
    .stat-trend-down { color:#dc2626; }
    .stat-trend-flat { color:#9ca3af; }

    /* Table */
    .tbl-wrap { background:#fff; border-radius:0 0 14px 14px; border:1px solid #e5e7eb; border-top:none; overflow-x:auto; -webkit-overflow-scrolling:touch; }
    table { width:100%; border-collapse:collapse; min-width:600px; }
    thead th { background:#f9fafb; padding:10px 16px; text-align:left; font-size:0.72rem; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:0.06em; border-bottom:1px solid #e5e7eb; white-space:nowrap; }
    tbody tr { border-bottom:1px solid #f3f4f6; transition:background 0.12s; }
    tbody tr:last-child { border-bottom:none; }
    tbody tr:hover { background:#f0fdf4; }
    tbody td { padding:14px 16px; font-size:0.84rem; color:#374151; vertical-align:middle; }

    /* Tabs */
    .tabs-bar { background:#fff; border:1px solid #e5e7eb; border-bottom:none; border-radius:14px 14px 0 0; display:grid; grid-template-columns:1fr 1fr; }
    .tab-btn { padding:13px; text-align:center; font-size:0.84rem; font-weight:600; color:#6b7280; cursor:pointer; border:none; background:none; transition:all 0.18s; border-bottom:2px solid transparent; }
.tab-btn.active {
    color: var(--site-primary);
    border-bottom-color: var(--site-primary);
    background: var(--site-primary-pale);
    font-weight: 600;
}    .tab-btn:first-child { border-radius:14px 0 0 0; }
    .tab-btn:last-child  { border-radius:0 14px 0 0; }

    /* Toolbar */
    .toolbar { background:#fff; border:1px solid #e5e7eb; border-top:none; border-bottom:none; padding:10px 16px; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .toolbar-divider { width:1px; height:22px; background:#e5e7eb; }

    /* Program chips */
    .prog-chip { display:inline-flex; align-items:center; padding:2px 8px; border-radius:999px; font-size:0.66rem; font-weight:700; border:1px solid; margin-right:3px; white-space:nowrap; }
    .chip-4ps    { background:#fef9c3; color:#a16207; border-color:#fde047; }
    .chip-senior { background:#ede9fe; color:#6d28d9; border-color:#c4b5fd; }
    .chip-scholar{ background:#eff6ff; color:#1d4ed8; border-color:#93c5fd; }
    .chip-pwd    { background:#fff7ed; color:#c2410c; border-color:#fdba74; }
    .chip-sk     { background:#ecfdf5; color:#065f46; border-color:#6ee7b7; }
    .chip-voters { background:#f0f9ff; color:#0369a1; border-color:#7dd3fc; }

    /* Score bar */
    .score-bar-track { width:80px; height:6px; background:#e5e7eb; border-radius:3px; display:inline-block; }
    .score-bar-fill  { height:6px; border-radius:3px; background:#16a34a; }
    
    /* Icon buttons */
    .icon-btn { width:30px; height:30px; border-radius:8px; border:none; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:0.8rem; transition:all 0.15s; flex-shrink:0; }
    .icon-btn-view    { background:#eff6ff; color:#1d4ed8; }
    .icon-btn-view:hover    { background:#dbeafe; }
    .icon-btn-approve { display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; border-radius: 8px; border: 1.5px solid #16a34a; color: #15803d; background: #f0fdf4; font-size: 0.75rem; font-weight: 700; cursor: pointer; transition: all 0.15s; white-space: nowrap; }
    .icon-btn-approve:hover { background: #16a34a; color: #fff; }
    .icon-btn-reject  { background:#fef2f2; color:#dc2626; }
    .icon-btn-reject:hover  { background:#fee2e2; }

    /* Pill action buttons (for table rows) */
    .pill-view { font-size: 0.78rem; font-weight: 600; color: #374151; text-decoration: underline; cursor: pointer; background: none; border: none; padding: 0; white-space: nowrap; }
    .pill-view:hover { color: #15803d; }
    .pill-approve { display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; border-radius: 8px; border: 1.5px solid #16a34a; color: #15803d; background: #f0fdf4; font-size: 0.75rem; font-weight: 700; cursor: pointer; transition: all 0.15s; white-space: nowrap; }
    .pill-approve:hover { background: #16a34a; color: #fff; }
    .pill-reject { display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; border-radius: 8px; border: 1.5px solid #ef4444; color: #dc2626; background: #fef2f2; font-size: 0.75rem; font-weight: 700; cursor: pointer; transition: all 0.15s; white-space: nowrap; }
    .pill-reject:hover { background: #ef4444; color: #fff; }

    /* Buttons */
    .btn-filter  { display:inline-flex; align-items:center; gap:6px; padding:7px 14px; border-radius:9px; border:1.5px solid #e5e7eb; background:#fff; font-size:0.83rem; font-weight:600; color:#374151; cursor:pointer; transition:all 0.15s; white-space:nowrap; }
    .btn-filter:hover { border-color:var(--site-primary); color:var(--site-primary); }
    .btn-filter.active { border-color:var(--site-primary); background:#f0fdf4; color:var(--site-primary); }
    .btn-export  { display:inline-flex; align-items:center; gap:7px; padding:8px 14px; border-radius:9px; background:#f0fdf4; color:var(--site-primary-dark); font-size:0.83rem; font-weight:700; border:1.5px solid #bbf7d0; cursor:pointer; transition:background 0.15s; white-space:nowrap; }
    .btn-export:hover { background:var(--site-primary-light); }
    .btn-refresh { width:30px; height:30px; border-radius:8px; border:1.5px solid #e5e7eb; background:#fff; color:#6b7280; font-size:0.82rem; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all 0.15s; flex-shrink:0; }
    .btn-refresh:hover { border-color:var(--site-primary); color:var(--site-primary); }
    .search-box { display:flex; align-items:center; gap:8px; border:1.5px solid #e5e7eb; border-radius:9px; padding:7px 12px; background:#fff; transition:border-color 0.15s; }
    .search-box:focus-within { border-color:var(--site-primary); }
    .search-box input { border:none; outline:none; font-size:0.83rem; color:#374151; font-family:inherit; width:100%; background:transparent; }

    /* Pagination */
    .page-btn { width:34px; height:34px; border-radius:8px; border:1.5px solid #e5e7eb; background:#fff; font-size:0.82rem; font-weight:600; color:#374151; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all 0.15s; }
    .page-btn:hover { border-color:var(--site-primary-light); color:var(--site-primary-light); }
    .page-btn.active { background:var(--site-primary); border-color:var(--site-primary); color:#fff; }
    .page-btn:disabled { opacity:0.35; cursor:default; }

    /* Empty state */
    .empty-state { padding:52px 24px; text-align:center; color:#9ca3af; }
    .empty-state i { font-size:2.2rem; margin-bottom:10px; display:block; color:#d1d5db; }

    /* Modal */
    .modal-overlay { position:fixed; inset:0; z-index:800; background:rgba(5,46,22,0.45); backdrop-filter:blur(4px); display:flex; align-items:flex-start; justify-content:center; padding:16px; overflow-y:auto; opacity:0; pointer-events:none; transition:opacity 0.22s; }
    .modal-overlay.open { opacity:1; pointer-events:auto; }
    .modal { background:#fff; border-radius:18px; width:100%; max-width:640px; box-shadow:0 24px 60px rgba(5,46,22,0.22); transform:translateY(16px); transition:transform 0.25s cubic-bezier(0.4,0,0.2,1); margin:auto; display:flex; flex-direction:column; }
    .modal-overlay.open .modal { transform:translateY(0); }
    .modal-header { display:flex; align-items:center; justify-content:space-between; padding:16px 18px 12px; border-bottom:1px solid #f3f4f6; flex-shrink:0; }
    .modal-close { width:28px; height:28px; border-radius:8px; border:none; background:#f3f4f6; cursor:pointer; display:flex; align-items:center; justify-content:center; color:#6b7280; transition:background 0.15s; }
    .modal-close:hover { background:#fee2e2; color:#dc2626; }
    .modal-body { padding:16px 18px; overflow-y:auto; max-height:calc(100vh - 180px); }

    /* Detail rows inside modal */
    .detail-row { display:flex; gap:8px; padding:8px 0; border-bottom:1px solid #f3f4f6; font-size:0.84rem; }
    .detail-row:last-child { border-bottom:none; }
    .detail-label { min-width:160px; color:#6b7280; font-weight:600; font-size:0.78rem; }
    .detail-val { color:#111827; font-weight:500; }
    .section-card { background:#f9fafb; border:1px solid #e5e7eb; border-radius:12px; padding:14px 16px; margin-bottom:14px; }
    .section-card:last-child { margin-bottom:0; }
    .section-title-m { font-size:0.72rem; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:0.07em; margin-bottom:10px; display:flex; align-items:center; gap:6px; }

    /* Priority card in modal */
    .priority-card { background:linear-gradient(135deg,#052e16,#15803d); border-radius:14px; padding:16px 20px; color:#fff; display:flex; align-items:center; gap:16px; margin-bottom:16px; }
    .priority-badge { font-size:2.2rem; font-weight:800; font-family:'Playfair Display',serif; line-height:1; }
    .priority-sub { font-size:0.72rem; opacity:0.75; margin-top:2px; }

    /* Alert banner */
    #alertBanner { display:none; border-radius:10px; margin-bottom:4px; }
    #alertBanner.show { display:flex; }
    .alert-inner { display:flex; align-items:center; gap:10px; padding:13px 16px; font-size:0.85rem; font-weight:600; border-radius:10px; border:1.5px solid transparent; width:100%; }
    .alert-success { background:#f0fdf4; border-color:#bbf7d0; color:#15803d; }
    .alert-error   { background:#fef2f2; border-color:#fecaca; color:#dc2626; }
    .alert-warning { background:#fefce8; border-color:#fde68a; color:#a16207; }
    .alert-close { margin-left:auto; background:none; border:none; cursor:pointer; font-size:0.8rem; opacity:0.6; color:inherit; }

    /* Confirm dialog */
    .dialog-overlay { position:fixed; inset:0; z-index:900; background:rgba(5,46,22,0.45); backdrop-filter:blur(4px); display:flex; align-items:center; justify-content:center; padding:20px; opacity:0; pointer-events:none; transition:opacity 0.2s; }
    .dialog-overlay.open { opacity:1; pointer-events:auto; }
    .dialog-box { background:#fff; border-radius:20px; width:100%; max-width:400px; box-shadow:0 24px 64px rgba(5,46,22,0.3); transform:scale(0.94) translateY(12px); transition:transform 0.25s cubic-bezier(0.34,1.56,0.64,1),opacity 0.2s; opacity:0; overflow:hidden; }
    .dialog-overlay.open .dialog-box { transform:scale(1) translateY(0); opacity:1; }
    .dialog-icon-wrap { width:64px; height:64px; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 16px; font-size:1.6rem; }
    .dialog-body-d { padding:28px 24px 20px; text-align:center; }
    .dialog-title-d { font-size:1.1rem; font-weight:800; color:#111827; margin-bottom:8px; font-family:'Playfair Display',serif; }
    .dialog-desc-d  { font-size:0.85rem; color:#6b7280; line-height:1.5; }
    .dialog-name-badge { display:inline-block; margin-top:10px; background:#f3f4f6; border-radius:8px; padding:6px 14px; font-size:0.82rem; font-weight:700; color:#374151; }
    .dialog-footer-d { padding:0 20px 20px; display:flex; gap:10px; }
    .dbtn { flex:1; padding:11px; border-radius:11px; border:none; font-size:0.86rem; font-weight:700; cursor:pointer; font-family:inherit; transition:all 0.15s; display:flex; align-items:center; justify-content:center; gap:6px; }
    .dbtn-cancel { background:#f3f4f6; color:#374151; }
    .dbtn-cancel:hover { background:#e5e7eb; }
    .dbtn-confirm-approve { background:#16a34a; color:#fff; box-shadow:0 4px 14px rgba(22,163,74,0.35); }
    .dbtn-confirm-approve:hover { background:#15803d; }
    .dbtn-confirm-reject  { background:#ef4444; color:#fff; box-shadow:0 4px 14px rgba(239,68,68,0.35); }
    .dbtn-confirm-reject:hover  { background:#dc2626; }

    /* Filter dropdown */
    .filter-dropdown { position:relative; }
    .filter-panel { position:absolute; top:calc(100% + 6px); right:0; z-index:200; background:#fff; border:1.5px solid #e5e7eb; border-radius:12px; padding:12px 14px; box-shadow:0 8px 24px rgba(0,0,0,0.10); min-width:200px; }
    .filter-panel label { display:flex; align-items:center; gap:8px; font-size:0.82rem; font-weight:600; color:#374151; padding:5px 0; cursor:pointer; }
    .filter-panel label input[type=checkbox] { accent-color:#16a34a; width:15px; height:15px; }

    @keyframes fadeUp { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
    .fade-up { animation:fadeUp 0.35s ease both; }

    @media (max-width:1024px) {
      .sidebar { transform:translateX(-100%); width:260px !important; }
      .sidebar.mobile-open { transform:translateX(0); }
      .main-content { margin-left:0 !important; }
      .topbar { padding:12px 16px; }
      .topbar-title-block { margin-left:46px !important; }
    }
    @media (max-width:640px) {
      .topbar h2 { font-size:1.2rem !important; }
      .page-pad { padding:14px !important; }
      .modal-overlay { padding:0; align-items:flex-end; }
      .modal { border-radius:20px 20px 0 0; max-width:100%; max-height:95vh; }
      .modal-body { max-height:calc(95vh - 140px); }
    }
    :root {
  --site-primary-dark:   color-mix(in srgb, var(--site-primary) 55%, black);
  --site-primary-darker: color-mix(in srgb, var(--site-primary) 75%, black);
  --site-primary-light:  color-mix(in srgb, var(--site-primary) 55%, white);
  --site-primary-pale:   color-mix(in srgb, var(--site-primary) 12%, white);
}
  </style>
</head>
<body>

<!-- Mobile sidebar backdrop -->
<div class="sidebar-backdrop" id="sidebarBackdrop" onclick="closeMobileSidebar()"></div>
<button class="expand-btn" id="expandBtn"><i class="fa-solid fa-bars"></i></button>

<div class="main-wrapper">

  <!-- â•â• SIDEBAR â•â• -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-inner">
      <div class="sidebar-logo">
        <button onclick="location.href='adminLanding'" style="border:none;background:none;padding:0;cursor:pointer;color:inherit;text-align:left;">
          <div class="flex items-center gap-2.5">
            <div class="w-10 h-10 rounded-full flex items-center justify-center shadow overflow-hidden" style="background: var(--site-primary);">
              <img src="<?= e(site_config_logo_url($siteSettings, '../')) ?>" alt="Logo" class="w-full h-full object-cover" />
            </div>
            <div>
              <p class="text-white font-bold text-sm leading-tight"><?= e($siteSettings['site_title']) ?></p>
              <p class="text-[10px] tracking-widest uppercase" style="color: var(--site-primary-light)">Admin Panel</p>
            </div>
          </div>
        </button>
        <button class="collapse-btn" id="collapseBtn"><i class="fa-solid fa-chevron-left"></i></button>
      </div>

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
          <button class="menu-item<?= $isActive ? ' active' : '' ?>" onclick="location.href='<?= e($item['href']) ?>'">
            <div class="menu-left"><i class="fa-solid <?= e($item['icon']) ?> mi"></i><?= e($item['label']) ?></div>
            <?php if ($isActive): ?><span class="active-dot"></span><?php endif; ?>
          </button>
        <?php endforeach; ?>
      </nav>
      <?php endforeach; ?>

      <div class="sidebar-bottom">
        <div class="sidebar-bottom-links">
          <?php if ($role === 'admin'): ?>
            <button type="button" onclick="window.location.href='../settings.php'" class="side-link" style="color: rgba(255,255,255,0.55);" onmouseover="this.style.color='var(--site-primary-light)'" onmouseout="this.style.color=' rgba(255,255,255,0.55)'">Settings</button>
          <?php endif; ?>
          <div class="h-px bg-white/10 my-1 mx-2"></div>
          <button class="side-link text-red-400/70 hover:text-red-300 hover:bg-white/5" onclick="location.href='../logout.php'">Logout</button>
        </div>
      </div>
    </div>
  </aside>

  <!-- â•â• MAIN â•â• -->
  <main class="main-content" id="mainContent">

    <header class="topbar">
      <div class="topbar-title-block">
        <h2 class="font-bold text-2xl leading-tight" style="font-family:'Playfair Display',serif;color: var(--site-primary-darker);">Beneficiary Management</h2>
        <p class="text-gray-500 text-sm mt-0.5">Review, verify, and approve resident applications for government programs.</p>
      </div>
    </header>

    <!-- REALTIME LOADER -->
    <div id="realtimeLoader" class="flex-1 flex flex-col items-center justify-center min-h-[50vh]">
      <i class="fa-solid fa-circle-notch fa-spin text-4xl text-green-600 mb-4"></i>
      <p class="text-sm font-semibold text-green-800 animate-pulse tracking-wide">Loading applications...</p>
    </div>

    <div id="mainDataContainer" class="p-6 page-pad flex-1 space-y-5 fade-up" style="display: none;">

      <!-- Stat cards -->
      <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        <div class="stat-card">
          <p class="stat-label">New Beneficiaries This Month</p>
          <div class="stat-row"><i class="fa-solid fa-hand-holding-heart stat-ico" style="color: var(--site-primary);"></i><span class="stat-num"><?= number_format($newBenThisMonth) ?></span></div>
          <span class="stat-sub">Approved this month</span>
        </div>
        <div class="stat-card">
          <p class="stat-label">Beneficiary Coverage Rate</p>
          <div class="stat-row"><i class="fa-solid fa-people-group stat-ico text-blue-500"></i><span class="stat-num"><?= $coverageRate ?>%</span></div>
          <span class="stat-sub"><?= number_format($approvedBeneficiaryResidents) ?> of <?= number_format($totalResidents) ?> residents</span>
        </div>
        <div class="stat-card">
          <p class="stat-label">Assistance Requests This Month</p>
          <div class="stat-row"><i class="fa-solid fa-file-circle-plus stat-ico text-purple-500"></i><span class="stat-num"><?= number_format($reqThisMonth) ?></span></div>
          <?php if ($reqTrendDir === 'up'): ?>
            <span class="stat-trend stat-trend-up"><i class="fa-solid fa-arrow-up"></i> <?= $reqTrendPct ?>% vs last month</span>
          <?php elseif ($reqTrendDir === 'down'): ?>
            <span class="stat-trend stat-trend-down"><i class="fa-solid fa-arrow-down"></i> <?= abs($reqTrendPct) ?>% vs last month</span>
          <?php else: ?>
            <span class="stat-trend stat-trend-flat"><i class="fa-solid fa-minus"></i> Same as last month</span>
          <?php endif; ?>
        </div>
        <div class="stat-card">
          <p class="stat-label">Average Processing Time</p>
          <?php if ($avgProcessingHours !== null): ?>
            <div class="stat-row"><i class="fa-solid fa-hourglass-half stat-ico text-amber-500"></i><span class="stat-num"><?= $avgProcessingHours < 48 ? number_format($avgProcessingHours, 1) . 'h' : number_format($avgProcessingHours / 24, 1) . 'd' ?></span></div>
            <span class="stat-sub">Submitted â†’ Approved</span>
          <?php else: ?>
            <div class="stat-row"><i class="fa-solid fa-hourglass-half stat-ico text-gray-300"></i><span class="stat-num text-gray-300">N/A</span></div>
            <span class="stat-sub">No approved requests yet</span>
          <?php endif; ?>
        </div>
      </div>

      <!-- Top row -->
      <div class="flex items-center justify-between gap-4 flex-wrap">
        <div class="flex items-baseline gap-2">
          <h3 class="font-bold text-gray-900 text-lg" id="tableLabel">All Applications</h3>
          <span class="font-bold text-lg" id="tableCount" style="color:var(--site-primary-dark)"><?= $total_pending ?></span>
        </div>
        <div class="flex items-center gap-3 flex-wrap">
          <div class="search-box" style="width:200px;">
            <i class="fa-solid fa-magnifying-glass text-gray-400 text-xs flex-shrink-0"></i>
            <input type="text" id="searchInput" placeholder="Search..." oninput="filterTable()">
          </div>

          <!-- Filter dropdown -->
          <div class="filter-dropdown" id="filterDropdown">
            <button class="btn-filter" id="filterBtn" onclick="toggleFilterPanel()">
              <i class="fa-solid fa-filter text-xs"></i> Filter
              <i class="fa-solid fa-caret-down text-xs"></i>
            </button>
            <div class="filter-panel" id="filterPanel" style="display:none;">
              <p style="font-size:0.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:8px;">Program Filter</p>
              <label><input type="checkbox" class="prog-filter" value="4ps" onchange="filterTable()"> 4P's</label>
              <label><input type="checkbox" class="prog-filter" value="kabataan" onchange="filterTable()"> Kabataan (SK)</label>
              <label><input type="checkbox" class="prog-filter" value="scholarship" onchange="filterTable()"> Scholarship</label>
              <label><input type="checkbox" class="prog-filter" value="pwd" onchange="filterTable()"> PWD</label>
              <label><input type="checkbox" class="prog-filter" value="voters" onchange="filterTable()"> Registered Voters</label>
              <label><input type="checkbox" class="prog-filter" value="senior" onchange="filterTable()"> Senior Citizen</label>
              <div style="border-top:1px solid #e5e7eb;margin:8px 0;"></div>
              <label style="color:#dc2626;" onclick="clearFilters()"><i class="fa-solid fa-xmark text-xs"></i> Clear Filters</label>
            </div>
          </div>

          <!-- Export (beneficiary list tab) -->
          <button class="btn-export" id="exportBtn" style="display:none;" onclick="exportList()">
            <i class="fa-solid fa-arrow-up-from-bracket text-xs"></i> Export this list
          </button>
        </div>
      </div>

      <!-- Alert -->
      <div id="alertBanner">
        <div class="alert-inner" id="alertInner">
          <i id="alertIcon" class="fa-solid fa-circle-check" style="font-size:1rem;flex-shrink:0;"></i>
          <div><span id="alertTitle" style="font-weight:700;"></span> <span id="alertDesc" style="font-weight:400;opacity:0.85;"></span></div>
          <button class="alert-close" onclick="dismissAlert()"><i class="fa-solid fa-xmark"></i></button>
        </div>
      </div>

      <!-- Tabs + Table -->
      <div>
        <div class="tabs-bar">
          <button class="tab-btn active" id="tabRequests"    onclick="switchTab('requests')">Resident Request</button>
          <button class="tab-btn"        id="tabBeneficiary" onclick="switchTab('beneficiary')">Beneficiary List</button>
        </div>

        <!-- Toolbar -->
        <div class="toolbar">
          <input type="checkbox" id="checkAll" class="rounded w-4 h-4 accent-green-600" onchange="toggleAll(this)">
          <button class="icon-btn icon-btn-approve" id="toolbarApproveBtn" title="Approve selected" onclick="bulkAction('approve', this)">
            <i class="fa-solid fa-check text-xs"></i>
          </button>
          <button class="icon-btn icon-btn-reject" id="toolbarRejectBtn" title="Reject selected" onclick="bulkAction('reject', this)">
            <i class="fa-solid fa-xmark text-xs"></i>
          </button>
          <div class="toolbar-divider"></div>
          <button class="btn-refresh" onclick="triggerRefresh()" title="Refresh"><i class="fa-solid fa-rotate-right text-xs"></i></button>
        </div>

        <div class="tbl-wrap" id="tableWrap">

          <!-- REQUESTS TABLE -->
          <table id="requestsTable">
            <thead>
              <tr>
                <th style="width:36px;"></th>
                <th>Name</th>
                <th>Date Applied</th>
                <th>Programs Eligible</th>
                <th style="text-align:right;">Action</th>
              </tr>
            </thead>
            <tbody id="requestsTableBody">
              <?php if (empty($pending_with_scores)): ?>
              <tr><td colspan="5"><div class="empty-state"><i class="fa-regular fa-folder-open"></i><p class="font-semibold text-sm">No pending applications.</p></div></td></tr>
              <?php else: foreach ($pending_with_scores as $app):
                $date_str = !empty($app['submitted_at']) ? date('F j, Y', strtotime($app['submitted_at'])) : 'â€”';
                $eligible = $app['_eligible'];
                $prog_labels = ['4ps'=>"4P's",'senior'=>'Senior Citizen','scholarship'=>'Scholarship','pwd'=>'PWD','kabataan'=>'Kabataan (SK)','voters'=>'Registered Voters'];
                $prog_cls    = ['4ps'=>'chip-4ps','senior'=>'chip-senior','scholarship'=>'chip-scholar','pwd'=>'chip-pwd','kabataan'=>'chip-sk','voters'=>'chip-voters'];
              ?>
              <tr data-id="<?= (int)$app['id'] ?>"
                  data-name="<?= e(strtolower($app['_name'])) ?>"
                  data-progs="<?= e(implode(',', $eligible)) ?>"
                  data-date="<?= e($app['submitted_at'] ?? '') ?>"
                  data-app='<?= htmlspecialchars(json_encode($app, JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS), ENT_QUOTES, 'UTF-8') ?>'>
                <td><input type="checkbox" class="row-check rounded w-4 h-4 accent-green-600"></td>
                <td>
                  <p class="font-bold text-gray-900 text-sm"><?= e($app['_name']) ?></p>
                  <p class="text-gray-400 text-xs"><?= e($app['email'] ?? '') ?></p>
                </td>
                <td class="text-gray-500 text-sm"><?= $date_str ?></td>
                <td>
                  <?php foreach ($eligible as $ep): ?>
                    <span class="prog-chip <?= $prog_cls[$ep] ?>"><?= $prog_labels[$ep] ?></span>
                  <?php endforeach; ?>
                  <?php if (empty($eligible)): ?><span class="text-gray-400 text-xs italic">None matched</span><?php endif; ?>
                </td>
                <td>
                  <div class="flex items-center justify-end gap-2">
                    <button class="pill-view" onclick="openViewModal(this.closest('tr'),'request',this)">View</button>
                    <button class="pill-approve" onclick="confirmAction(<?= (int)$app['id'] ?>,'approve','<?= e(addslashes($app['_name'])) ?>',this.closest('tr'),this)"><i class="fa-solid fa-check" style="font-size:0.7rem;"></i> Approve</button>
                    <button class="pill-reject"  onclick="confirmAction(<?= (int)$app['id'] ?>,'reject','<?= e(addslashes($app['_name'])) ?>',this.closest('tr'),this)"><i class="fa-solid fa-xmark" style="font-size:0.7rem;"></i> Reject</button>
                  </div>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>

          <!-- BENEFICIARY LIST TABLE -->
          <table id="beneficiaryTable" style="display:none;">
            <thead>
              <tr>
                <th style="width:36px;"></th>
                <th>Rank</th>
                <th>Name</th>
                <th>Programs</th>
                <th>Priority Score</th>
                <th style="text-align:right;">Action</th>
              </tr>
            </thead>
            <tbody id="beneficiaryTableBody">
              <?php if (empty($beneficiaries_processed)): ?>
              <tr><td colspan="6"><div class="empty-state"><i class="fa-regular fa-folder-open"></i><p class="font-semibold text-sm">No approved beneficiaries yet.</p></div></td></tr>
              <?php else:
                $rank = 0;
                $prog_labels = ['4ps'=>"4P's",'senior'=>'Senior Citizen','scholarship'=>'Scholarship','pwd'=>'PWD','kabataan'=>'Kabataan (SK)','voters'=>'Registered Voters'];
                $prog_cls    = ['4ps'=>'chip-4ps','senior'=>'chip-senior','scholarship'=>'chip-scholar','pwd'=>'chip-pwd','kabataan'=>'chip-sk','voters'=>'chip-voters'];
                foreach ($beneficiaries_processed as $b):
                  $rank++;
                  $score    = (int)($b['prio_score'] ?? 0);
                  $eligible = $b['_eligible'];
              ?>
              <tr data-id="<?= (int)$b['id'] ?>"
                  data-name="<?= e(strtolower($b['_name'])) ?>"
                  data-progs="<?= e(implode(',', $eligible)) ?>"
                  data-score="<?= $score ?>"
                  data-app='<?= htmlspecialchars(json_encode($b, JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS), ENT_QUOTES, 'UTF-8') ?>'>
                <td><input type="checkbox" class="row-check rounded w-4 h-4 accent-green-600"></td>
                <td><span class="font-bold text-green-700 text-base"><?= $rank ?></span></td>
                <td>
                  <p class="font-bold text-gray-900 text-sm"><?= e($b['_name']) ?></p>
                  <p class="text-gray-400 text-xs"><?= e($b['email'] ?? '') ?></p>
                </td>
                <td>
                  <?php foreach ($eligible as $ep): ?>
                    <span class="prog-chip <?= $prog_cls[$ep] ?>"><?= $prog_labels[$ep] ?></span>
                  <?php endforeach; ?>
                  <?php if (empty($eligible)): ?><span class="text-gray-400 text-xs italic">None matched</span><?php endif; ?>
                </td>
                <td>
                  <div class="flex items-center gap-2">
                    <div class="score-bar-track"><div class="score-bar-fill" style="width:<?= $score ?>%"></div></div>
                    <span class="text-sm font-bold text-gray-700"><?= $score ?></span>
                  </div>
                </td>
                <td>
                  <div class="flex items-center justify-end gap-2">
                    <button class="pill-view" onclick="openViewModal(this.closest('tr'),'beneficiary',this)">View</button>
                  </div>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

        <div class="flex items-center justify-center gap-2 pt-5 flex-wrap" id="paginationContainer"></div>
      </div>
    </div>
  </main>
</div>

<!-- â•â• VIEW MODAL â•â• -->
<div class="modal-overlay" id="viewModalOverlay" onclick="closeViewModalOnOverlay(event)">
  <div class="modal" id="viewModal">
    <div class="modal-header">
      <div class="flex items-center gap-3">
        <div style="width:36px;height:36px;background:#dcfce7;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <i class="fa-solid fa-hand-holding-heart text-green-700 text-sm"></i>
        </div>
        <div>
          <p class="font-bold text-gray-900 text-base">Beneficiary Application Details</p>
          <p class="text-gray-400 text-xs mt-0.5" id="viewModalSubtitle"></p>
        </div>
      </div>
      <button class="modal-close" onclick="closeViewModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body" id="viewModalBody"></div>
    <!-- Footer only shown for requests tab -->
    <div id="viewModalFooter" style="display:none;border-top:1px solid #f3f4f6;">
      <div style="display:grid;grid-template-columns:1fr 1fr;">
        <button style="padding:14px;border:none;font-size:0.88rem;font-weight:700;cursor:pointer;font-family:inherit;background:#fef2f2;color:#dc2626;border-radius:0 0 0 18px;display:flex;align-items:center;justify-content:center;gap:6px;transition:background 0.15s;"
          onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='#fef2f2'"
          id="viewRejectBtn"><i class="fa-solid fa-xmark"></i> Reject</button>
        <button style="padding:14px;border:none;font-size:0.88rem;font-weight:700;cursor:pointer;font-family:inherit;background:#15803d;color:#fff;border-radius:0 0 18px 0;display:flex;align-items:center;justify-content:center;gap:6px;transition:background 0.15s;"
          onmouseover="this.style.background='#166534'" onmouseout="this.style.background='#15803d'"
          id="viewApproveBtn"><i class="fa-solid fa-check"></i> Approve</button>
      </div>
    </div>
  </div>
</div>

<!-- â•â• CONFIRM DIALOG â•â• -->
<div class="dialog-overlay" id="dialogOverlay">
  <div class="dialog-box">
    <div class="dialog-body-d">
      <div class="dialog-icon-wrap" id="dialogIconWrap"></div>
      <p class="dialog-title-d" id="dialogTitle"></p>
      <p class="dialog-desc-d"  id="dialogDesc"></p>
      <span class="dialog-name-badge" id="dialogNameBadge" style="display:none;"></span>
    </div>
    <div class="dialog-footer-d">
      <button class="dbtn dbtn-cancel" onclick="closeDialog()"><i class="fa-solid fa-xmark"></i> Cancel</button>
      <button class="dbtn" id="dialogConfirmBtn"></button>
    </div>
  </div>
</div>

<script>
/* â•â•â•â• DATA FROM PHP â•â•â•â• */
const PENDING_APPS     = <?= json_encode(array_map(function($a){ return $a; }, $pending_with_scores), JSON_HEX_TAG) ?>;
const BENEFICIARY_LIST = <?= json_encode($beneficiaries_processed, JSON_HEX_TAG) ?>;

/* â•â•â•â• SIDEBAR â•â•â•â• */
const sidebar     = document.getElementById('sidebar');
const mainContent = document.getElementById('mainContent');
const expandBtn   = document.getElementById('expandBtn');
const backdrop    = document.getElementById('sidebarBackdrop');
const isMobile    = () => window.innerWidth <= 1024;
let collapsed = localStorage.getItem('sidebarCollapsed') === 'true';
function applyCollapse() {
  if (isMobile()) {
    sidebar.classList.remove('collapsed');
    mainContent.classList.remove('sidebar-collapsed');
    expandBtn.classList.add('visible');
    return;
  }
  sidebar.classList.remove('mobile-open');
  backdrop.classList.remove('visible');
  document.body.style.overflow = '';
  if (collapsed) {
    sidebar.classList.add('collapsed');
    mainContent.classList.add('sidebar-collapsed');
    expandBtn.classList.add('visible');
  } else {
    sidebar.classList.remove('collapsed');
    mainContent.classList.remove('sidebar-collapsed');
    expandBtn.classList.remove('visible');
  }
}
function openMobileSidebar()  { sidebar.classList.add('mobile-open'); backdrop.classList.add('visible'); document.body.style.overflow='hidden'; }
function closeMobileSidebar() { sidebar.classList.remove('mobile-open'); backdrop.classList.remove('visible'); document.body.style.overflow=''; }
document.getElementById('collapseBtn').addEventListener('click', () => {
  if (isMobile()) { closeMobileSidebar(); return; }
  collapsed = true; localStorage.setItem('sidebarCollapsed','true'); applyCollapse();
});
expandBtn.addEventListener('click', () => {
  if (isMobile()) { openMobileSidebar(); return; }
  collapsed = false; localStorage.setItem('sidebarCollapsed','false'); applyCollapse();
});
window.addEventListener('resize', applyCollapse);
applyCollapse();

/* Page loading state */
const realtimeLoader = document.getElementById('realtimeLoader');
const mainDataContainer = document.getElementById('mainDataContainer');
function finishLoading() {
  if (realtimeLoader) realtimeLoader.style.display = 'none';
  if (mainDataContainer) mainDataContainer.style.display = '';
}
setTimeout(finishLoading, 400);

function showPageLoader(message='Processing...') {
  if (mainDataContainer) mainDataContainer.style.display = 'none';
  if (realtimeLoader) {
    const txt = realtimeLoader.querySelector('p');
    if (txt) txt.textContent = message;
    realtimeLoader.style.display = 'flex';
  }
}
function hidePageLoader() {
  if (realtimeLoader) realtimeLoader.style.display = 'none';
  if (mainDataContainer) mainDataContainer.style.display = '';
}

/* â•â•â•â• ALERT â•â•â•â• */
let alertTimer;
function showToast(type, title, desc) {
  const icons = {success:'fa-circle-check',error:'fa-circle-xmark',warning:'fa-triangle-exclamation'};
  const cls   = {success:'alert-success',error:'alert-error',warning:'alert-warning'};
  document.getElementById('alertInner').className = 'alert-inner ' + (cls[type]||'alert-success');
  document.getElementById('alertIcon').className  = 'fa-solid ' + (icons[type]||'fa-circle-check');
  document.getElementById('alertTitle').textContent = title;
  document.getElementById('alertDesc').textContent  = desc || '';
  document.getElementById('alertBanner').classList.add('show');
  clearTimeout(alertTimer);
  alertTimer = setTimeout(dismissAlert, 4000);
}
function dismissAlert() { document.getElementById('alertBanner').classList.remove('show'); }

/* â•â•â•â• TAB SWITCHING â•â•â•â• */
let activeTab = 'requests';
function switchTab(tab) {
  activeTab = tab;
  document.getElementById('requestsTable').style.display   = tab==='requests'    ? '' : 'none';
  document.getElementById('beneficiaryTable').style.display = tab==='beneficiary' ? '' : 'none';
  document.getElementById('tabRequests').classList.toggle('active',    tab==='requests');
  document.getElementById('tabBeneficiary').classList.toggle('active', tab==='beneficiary');
  // Show approve/reject in toolbar only for requests
  document.getElementById('toolbarApproveBtn').style.display = tab==='requests' ? 'flex' : 'none';
  document.getElementById('toolbarRejectBtn').style.display  = tab==='requests' ? 'flex' : 'none';
  // Show export for beneficiary list
  document.getElementById('exportBtn').style.display = tab==='beneficiary' ? 'flex' : 'none';
  const counts = {requests: <?= $total_pending ?>, beneficiary: <?= $total_ben ?>};
  document.getElementById('tableLabel').textContent = tab==='requests' ? 'All Applications' : 'All Beneficiaries';
  document.getElementById('tableCount').textContent = counts[tab];
  document.getElementById('checkAll').checked = false;
  currentPage = 1; filterTable();
}
function toggleAll(cb) {
  const sel = activeTab==='requests' ? '#requestsTable' : '#beneficiaryTable';
  document.querySelectorAll(sel + ' .row-check').forEach(c => c.checked = cb.checked);
}

/* â•â•â•â• FILTER â•â•â•â• */
function toggleFilterPanel() {
  const panel = document.getElementById('filterPanel');
  const btn   = document.getElementById('filterBtn');
  const vis   = panel.style.display !== 'none';
  panel.style.display = vis ? 'none' : 'block';
  btn.classList.toggle('active', !vis);
}
document.addEventListener('click', e => {
  if (!document.getElementById('filterDropdown').contains(e.target)) {
    document.getElementById('filterPanel').style.display = 'none';
    document.getElementById('filterBtn').classList.remove('active');
  }
});
function clearFilters() {
  document.querySelectorAll('.prog-filter').forEach(c => c.checked = false);
  document.getElementById('searchInput').value = '';
  filterTable();
  document.getElementById('filterPanel').style.display = 'none';
  document.getElementById('filterBtn').classList.remove('active');
}

let searchTimer;
function filterTable() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    setTimeout(() => {
      const q = document.getElementById('searchInput').value.toLowerCase().trim();
      const checkedProgs = Array.from(document.querySelectorAll('.prog-filter:checked')).map(c => c.value);
      const sel = activeTab==='requests' ? '#requestsTable' : '#beneficiaryTable';
      document.querySelectorAll(sel + ' tbody tr[data-id]').forEach(row => {
        const nameMatch  = !q || row.dataset.name.includes(q);
        const progMatch  = checkedProgs.length === 0 || checkedProgs.some(p => (row.dataset.progs||'').includes(p));
        const ok = nameMatch && progMatch;
        if (ok) {
          row.dataset.filteredout = "false";
        } else {
          row.dataset.filteredout = "true";
          row.style.display = 'none';
        }
      });
      currentPage = 1; renderPagination();
    }, 10);
  }, 400);
}

/* â•â•â•â• PAGINATION â•â•â•â• */
const ROWS_PER_PAGE = 10; let currentPage = 1;
function getVisibleRows() {
  const sel = activeTab==='requests' ? '#requestsTable' : '#beneficiaryTable';
  return Array.from(document.querySelectorAll(sel + ' tbody tr[data-id]')).filter(r => r.dataset.filteredout !== 'true');
}
function renderPagination() {
  const rows = getVisibleRows(), total = rows.length, pages = Math.max(1, Math.ceil(total/ROWS_PER_PAGE));
  if (currentPage > pages) currentPage = pages;
  rows.forEach((r,i) => { r.style.display = (Math.floor(i/ROWS_PER_PAGE)+1 === currentPage) ? '' : 'none'; });
  const c = document.getElementById('paginationContainer'); c.innerHTML = '';
  const prev = document.createElement('button');
  prev.className = 'page-btn'; prev.disabled = currentPage===1;
  prev.innerHTML = '<i class="fa-solid fa-chevron-left text-xs"></i>';
  prev.onclick = () => { currentPage--; renderPagination(); };
  c.appendChild(prev);
  let s=Math.max(1,currentPage-2), e=Math.min(pages,s+4); if(e-s<4) s=Math.max(1,e-4);
  for (let p=s;p<=e;p++) {
    const b=document.createElement('button');
    b.className = 'page-btn'+(p===currentPage?' active':'');
    b.textContent=p; b.onclick=()=>{currentPage=p;renderPagination();};
    c.appendChild(b);
  }
  const next=document.createElement('button');
  next.className='page-btn'; next.disabled=currentPage===pages;
  next.innerHTML='<i class="fa-solid fa-chevron-right text-xs"></i>';
  next.onclick=()=>{currentPage++;renderPagination();};
  c.appendChild(next);
}
renderPagination();

/* â•â•â•â• VIEW MODAL â•â•â•â• */
const PROG_LABELS = {'4ps':"4P's", senior:'Senior Citizen', scholarship:'Scholarship', pwd:'PWD', kabataan:'Kabataan (SK)', voters:'Registered Voters'};
const PROG_COLORS = {'4ps':'chip-4ps', senior:'chip-senior', scholarship:'chip-scholar', pwd:'chip-pwd', kabataan:'chip-sk', voters:'chip-voters'};

/** Retrieve pre-calculated eligibility from the server output */
function getEligible(app) {
  return app._eligible || [];
}

let currentViewId = null, currentViewType = null;

function openViewModal(row, type, triggerBtn = null) {
  const resetBtn = setActionButtonLoading(triggerBtn, 'Loading...');
  const app = JSON.parse(row.getAttribute('data-app'));
  currentViewId   = app.id;
  currentViewType = type;

  const score    = parseInt(app.prio_score) || 0;
  const age      = app._age || 0;
  const eligible = getEligible(app);

  // Eligibility text
  const eligNames = eligible.map(p => PROG_LABELS[p] || p);
  const eligText  = eligNames.length ? `They are eligible for ${eligNames.join(', ')}` : 'No programs matched.';

  // Program chips
  let chips = eligible.map(p => `<span class="prog-chip ${PROG_COLORS[p]}">${PROG_LABELS[p]}</span>`).join(' ');
  if (!chips) chips = '<span class="text-gray-400 text-xs italic">None</span>';

  // Computed helper conditions for details
  const getS   = (v) => (v||'').toString().toLowerCase().trim();
  const is4Ps  = eligible.includes('4ps');
  const isSen  = eligible.includes('senior');
  const isSchol = eligible.includes('scholarship');
  const isPwd  = eligible.includes('pwd');
  const isKab  = eligible.includes('kabataan');
  const isVot  = eligible.includes('voters');

  // Eligibility condition rows (what each program requires and whether they meet it)
  const progDetails = [
    { key:'4ps',         label:"4P's",           met: is4Ps,   why: "Specific housing, materials, utilities, children under 5, and income < 14k." },
    { key:'senior',      label:'Senior Citizen', met: isSen,   why: "Age â‰¥60" },
    { key:'scholarship', label:'Scholarship',    met: isSchol, why: "Enrolled in school with valid year level and GWA 1.00â€“1.75" },
    { key:'pwd',         label:'PWD',            met: isPwd,   why: "Registered PWD with valid ID" },
    { key:'kabataan',    label:'Kabataan (SK)',  met: isKab,   why: "Age 15â€“30" },
    { key:'voters',      label:'For Voters',     met: isVot,   why: "Age â‰¥18" },
  ];
  let progRows = progDetails.map(p => `
    <div class="detail-row">
      <span class="detail-label">${p.label}</span>
      <span class="detail-val" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        ${p.met
          ? `<span style="background:#dcfce7;color:#15803d;padding:2px 10px;border-radius:99px;font-size:0.72rem;font-weight:700;border:1px solid #bbf7d0;">âœ“ Eligible</span>`
          : `<span style="background:#f3f4f6;color:#9ca3af;padding:2px 10px;border-radius:99px;font-size:0.72rem;font-weight:700;">âœ— Not Eligible</span>`}
        <span style="font-size:0.72rem;color:#9ca3af;">${p.why}</span>
      </span>
    </div>`).join('');

  const isPending = type === 'request';
  const isSC      = age >= 60;

  // Priority card: requests show Score, beneficiary list shows Rank
  const priorityCard = isPending
    ? `<div class="priority-card">
        <div style="width:48px;height:48px;background:rgba(255,255,255,0.15);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.6rem;">ðŸ…</div>
        <div style="flex:1;">
          <span style="font-size:0.72rem;opacity:0.8;">Priority Score</span><br>
          <span style="font-size:2.5rem;font-weight:800;font-family:'Playfair Display',serif;line-height:1.1;">${score}</span>
          <span style="font-size:0.8rem;opacity:0.75;"> out of 100</span>
          <p style="font-size:0.72rem;opacity:0.8;margin-top:4px;">Calculated based on multiple factors</p>
          <p style="font-size:0.72rem;opacity:0.8;margin-top:2px;">${eligText}</p>
        </div>
      </div>`
    : `<div class="priority-card">
        <div style="width:48px;height:48px;background:rgba(255,255,255,0.15);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.6rem;">ðŸ…</div>
        <div style="flex:1;">
          <span style="font-size:0.72rem;opacity:0.8;">Priority Rank</span><br>
          <span style="font-size:2.5rem;font-weight:800;font-family:'Playfair Display',serif;line-height:1.1;">${app.prio_rank||row.querySelector('td:nth-child(2)')?.textContent?.trim()||'â€”'}</span>
          <span style="font-size:0.72rem;opacity:0.65;display:block;margin-top:2px;">Calculated based on your filter</span>
          <p style="font-size:0.72rem;opacity:0.8;margin-top:4px;">${eligText}</p>
        </div>
      </div>`;

  document.getElementById('viewModalSubtitle').textContent = app._name || '';
  document.getElementById('viewModalBody').innerHTML = `
    ${priorityCard}

    <div class="section-card">
      <div class="section-title-m"><i class="fa-solid fa-user text-green-600"></i> Personal Information</div>
      <div class="detail-row"><span class="detail-label">Full Name</span><span class="detail-val">${esc(app._name)}</span></div>
      <div class="detail-row"><span class="detail-label">Contact Number</span><span class="detail-val">${esc(app.phone||'â€”')}</span></div>
      <div class="detail-row"><span class="detail-label">Age</span><span class="detail-val">${age}${isSC?' <span style="font-size:0.7rem;background:#ede9fe;color:#6d28d9;padding:2px 7px;border-radius:99px;border:1px solid #c4b5fd;font-weight:700;">Senior Citizen</span>':''}</span></div>
      <div class="detail-row"><span class="detail-label">Complete Address</span><span class="detail-val">${esc([app.street,app.barangay,app.city,app.province].filter(Boolean).join(', '))||'â€”'}</span></div>
    </div>

    <div class="section-card">
      <div class="section-title-m"><i class="fa-solid fa-house text-green-600"></i> Socioeconomic Details</div>
      <div class="detail-row"><span class="detail-label">Monthly Income</span><span class="detail-val">${esc(app.monthly_income||'â€”')}</span></div>
      <div class="detail-row"><span class="detail-label">Housing Status</span><span class="detail-val">${esc(app.housing_status||'â€”')}</span></div>
      <div class="detail-row"><span class="detail-label">House Material</span><span class="detail-val">${esc(app.house_material||'â€”')}</span></div>
      <div class="detail-row"><span class="detail-label">Electricity</span><span class="detail-val">${esc(app.electricity||'â€”')}</span></div>
      <div class="detail-row"><span class="detail-label">Water Source</span><span class="detail-val">${esc(app.water_source||'â€”')}</span></div>
      <div class="detail-row"><span class="detail-label">Toilet Type</span><span class="detail-val">${esc(app.toilet_type||'â€”')}</span></div>
      <div class="detail-row"><span class="detail-label">Pregnant / Children &lt;5</span><span class="detail-val">${app.pregnant_or_children==1?'Yes':'No'}</span></div>
    </div>

    <div class="section-card">
      <div class="section-title-m"><i class="fa-solid fa-tags text-green-600"></i> Specific Classification</div>
      <div class="detail-row"><span class="detail-label">PWD</span><span class="detail-val">${app.is_pwd==1?'Yes':'No'}${app.is_pwd==1&&app.pwd_id_number?' <span style="background:#fff7ed;color:#c2410c;padding:2px 8px;border-radius:99px;font-size:0.72rem;font-weight:700;border:1px solid #fdba74;">'+esc(app.pwd_id_number)+'</span>':''}</span></div>
      <div class="detail-row"><span class="detail-label">Solo Parent</span><span class="detail-val">${app.is_solo_parent==1?'Yes':'No'}</span></div>
      <div class="detail-row"><span class="detail-label">Indigenous Person</span><span class="detail-val">${app.is_indigenous==1?'Yes':'No'}</span></div>
      <div class="detail-row"><span class="detail-label">Pension Status</span><span class="detail-val">${esc(app.pension_status||'â€”')}</span></div>
    </div>

    <div class="section-card">
      <div class="section-title-m"><i class="fa-solid fa-heart-pulse text-green-600"></i> Health & Maintenance</div>
      <div class="detail-row"><span class="detail-label">Hypertension</span><span class="detail-val">${app.health_hypertension==1?'Yes':'No'}</span></div>
      <div class="detail-row"><span class="detail-label">Diabetes</span><span class="detail-val">${app.health_diabetes==1?'Yes':'No'}</span></div>
      <div class="detail-row"><span class="detail-label">Asthma</span><span class="detail-val">${app.health_asthma==1?'Yes':'No'}</span></div>
      <div class="detail-row"><span class="detail-label">Other</span><span class="detail-val">${app.health_other==1?(esc(app.health_other_specify)||'Yes'):'No'}</span></div>
      <div class="detail-row"><span class="detail-label">Requires Medicine</span><span class="detail-val">${app.requires_medicine==1?('Yes'+(app.medicine_name?' â€” '+esc(app.medicine_name):'')):'No'}</span></div>
    </div>

    ${(app.school_name||app.course) ? `<div class="section-card">
      <div class="section-title-m"><i class="fa-solid fa-graduation-cap text-green-600"></i> Student Information</div>
      <div class="detail-row"><span class="detail-label">School</span><span class="detail-val">${esc(app.school_name||'â€”')}</span></div>
      <div class="detail-row"><span class="detail-label">Course</span><span class="detail-val">${esc(app.course||'â€”')}</span></div>
      <div class="detail-row"><span class="detail-label">Year Level</span><span class="detail-val">${esc(app.year_level||'â€”')}</span></div>
      <div class="detail-row"><span class="detail-label">GWA/GPA</span><span class="detail-val">${esc(app.gwa_gpa||'â€”')}</span></div>
    </div>` : ''}

    <div class="section-card">
      <div class="section-title-m"><i class="fa-solid fa-chart-bar text-green-600"></i> Program Eligibility</div>
      ${progRows}
    </div>

    <div style="padding:10px 0 0;display:flex;align-items:center;flex-wrap:wrap;gap:6px;">
      <span style="font-size:0.72rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;margin-right:4px;">Eligible:</span>
      ${chips}
    </div>
  `;

  // Footer: show only for pending requests
  const footer = document.getElementById('viewModalFooter');
  if (isPending) {
    footer.style.display = 'block';
    const approveBtn = document.getElementById('viewApproveBtn');
    const rejectBtn  = document.getElementById('viewRejectBtn');
    approveBtn.onclick = () => { closeViewModal(); confirmAction(app.id,'approve',app._name,null,approveBtn); };
    rejectBtn.onclick  = () => { closeViewModal(); confirmAction(app.id,'reject',app._name,null,rejectBtn);  };
  } else {
    footer.style.display = 'none';
  }

  document.getElementById('viewModalOverlay').classList.add('open');
  document.body.style.overflow = 'hidden';
  if (resetBtn) setTimeout(resetBtn, 300);
}
function closeViewModal() { document.getElementById('viewModalOverlay').classList.remove('open'); document.body.style.overflow=''; }
function closeViewModalOnOverlay(e) { if(e.target===document.getElementById('viewModalOverlay')) closeViewModal(); }

/* â•â•â•â• CONFIRM DIALOG â•â•â•â• */
let dialogCallback = null;
function showDialog(title, desc, nameBadge, isApprove, onConfirm) {
  const iconWrap = document.getElementById('dialogIconWrap');
  const confirmBtn = document.getElementById('dialogConfirmBtn');
  iconWrap.innerHTML  = isApprove
    ? '<i class="fa-solid fa-check" style="color:#15803d;font-size:1.6rem;"></i>'
    : '<i class="fa-solid fa-xmark" style="color:#dc2626;font-size:1.6rem;"></i>';
  iconWrap.style.background = isApprove ? '#dcfce7' : '#fee2e2';
  confirmBtn.className = 'dbtn ' + (isApprove ? 'dbtn-confirm-approve' : 'dbtn-confirm-reject');
  confirmBtn.innerHTML = isApprove
    ? '<i class="fa-solid fa-check"></i> Yes, Approve'
    : '<i class="fa-solid fa-xmark"></i> Yes, Reject';
  document.getElementById('dialogTitle').textContent = title;
  document.getElementById('dialogDesc').textContent  = desc;
  const nb = document.getElementById('dialogNameBadge');
  if (nameBadge) { nb.textContent = nameBadge; nb.style.display='inline-block'; }
  else           { nb.style.display='none'; }
  dialogCallback = onConfirm;
  confirmBtn.onclick = () => { closeDialog(); if(dialogCallback) dialogCallback(); };
  document.getElementById('dialogOverlay').classList.add('open');
  document.body.style.overflow='hidden';
}
function closeDialog() { document.getElementById('dialogOverlay').classList.remove('open'); document.body.style.overflow=''; }
document.getElementById('dialogOverlay').addEventListener('click', function(e){ if(e.target===this) closeDialog(); });

function setActionButtonLoading(btn, label='Processing...') {
  if (!btn) return null;
  const original = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin text-[10px]"></i> ${label}`;
  return () => {
    btn.disabled = false;
    btn.innerHTML = original;
  };
}

/* â•â•â•â• APPROVE / REJECT ACTIONS â•â•â•â• */
function confirmAction(id, action, name, row, triggerBtn = null) {
  const isApprove = action === 'approve';
  showDialog(
    isApprove ? 'Approve Application' : 'Reject Application',
    isApprove ? 'This applicant will be added to the approved beneficiary list.'
              : 'This application will be rejected and removed from the pending list.',
    name, isApprove,
    () => doAction(id, action, row, name, triggerBtn)
  );
}
function doAction(id, action, row, name, triggerBtn = null, shouldReload = true) {
  const resetBtn = setActionButtonLoading(triggerBtn, action === 'approve' ? 'Approving...' : 'Rejecting...');
  showPageLoader(action === 'approve' ? 'Approving application...' : 'Rejecting application...');
  return fetch('beneficiaryAction.php', {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:`id=${id}&action=${action}`
  }).then(r=>r.json()).then(d=>{
    if (d.success) {
      if (row) row.remove();
      renderPagination();
      hidePageLoader();
      showToast(action==='approve'?'success':'warning',
        action==='approve'?'Application Approved':'Application Rejected',
        name ? name + (action==='approve'?' is now a verified beneficiary.':' has been rejected.') : '');
      if (shouldReload) setTimeout(()=>location.reload(), 1200);
      return true;
    } else {
      showToast('error','Action Failed', d.message||'Could not complete the action.');
      hidePageLoader();
      if (resetBtn) resetBtn();
      return false;
    }
  }).catch(()=>{
    showToast('error','Network Error','Please try again.');
    hidePageLoader();
    if (resetBtn) resetBtn();
    return false;
  });
}
function bulkAction(action, triggerBtn = null) {
  const rows = Array.from(document.querySelectorAll('#requestsTable .row-check:checked')).map(c=>c.closest('tr'));
  if (!rows.length) { showToast('warning','No selection','Please select at least one application.'); return; }
  const isApprove = action==='approve';
  showDialog(
    isApprove ? `Approve ${rows.length} Application(s)` : `Reject ${rows.length} Application(s)`,
    `Are you sure you want to ${action} ${rows.length} application(s)?`,
    null, isApprove,
    async () => {
      const resetBtn = setActionButtonLoading(triggerBtn, isApprove ? 'Approving...' : 'Rejecting...');
      let processed = 0;
      for (const r of rows) {
        const ok = await doAction(+r.dataset.id, action, r, '', null, false);
        if (ok) processed++;
      }
      showToast(isApprove?'success':'warning', `${processed} Application(s) ${isApprove?'Approved':'Rejected'}`, '');
      document.getElementById('checkAll').checked = false;
      if (processed > 0) setTimeout(()=>location.reload(), 800);
      if (resetBtn) resetBtn();
    }
  );
}

/* â•â•â•â• EXPORT â•â•â•â• */
function exportList() {
  const rows = Array.from(document.querySelectorAll('#beneficiaryTable tbody tr[data-id]')).filter(r=>r.style.display!=='none');
  let csv = 'Rank,Name,Email,Programs,Score\n';
  rows.forEach((r,i) => {
    const app = JSON.parse(r.getAttribute('data-app'));
    const scores = app._scores || {};
    const eligible = Object.entries(scores).filter(([p,sc])=>sc>=(PROG_THRESH[p]||0)).map(([p])=>({'4ps':"4P's",senior:'Senior Citizen',scholarship:'Scholarship',pwd:'PWD',kabataan:'Kabataan (SK)',voters:'Registered Voters'}[p]||p));
    csv += `"${i+1}","${app._name}","${app.email||''}","${eligible.join('; ')}","${Math.max(...Object.values(scores))}"\n`;
  });
  const blob = new Blob([csv],{type:'text/csv'});
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a'); a.href=url; a.download='beneficiaries.csv'; a.click();
}

/* â•â•â•â• REFRESH â•â•â•â• */
function triggerRefresh() { showPageLoader('Refreshing applications...'); setTimeout(() => location.reload(), 180); }

document.querySelectorAll('.sidebar button[onclick*="location.href"]').forEach(btn => {
  btn.addEventListener('click', function(e) {
    const raw = this.getAttribute('onclick') || '';
    const match = raw.match(/location\.href\s*=\s*['"]([^'"]+)['"]/);
    if (!match) return;
    e.preventDefault();
    e.stopPropagation();
    showPageLoader('Loading page...');
    setTimeout(() => { window.location.href = match[1]; }, 180);
  });
});

/* â•â•â•â• HTML ESCAPE â•â•â•â• */
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

/* Init toolbar state */
document.getElementById('toolbarApproveBtn').style.display = 'flex';
document.getElementById('toolbarRejectBtn').style.display  = 'flex';
document.getElementById('exportBtn').style.display         = 'none';
</script>
</body>
</html>