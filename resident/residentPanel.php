<?php 
require_once __DIR__ . '/../config/db_connection.php';
session_start();

require_once __DIR__ . '/../includes/site_config.php';
$siteSettings = site_config_load($conn);

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// Check role and redirect if not resident
$role = $_SESSION['account_role'] ?? '';
if (!str_contains($role, 'resident') || str_contains($role, 'non-resident') || str_contains($role, 'non_resident')) {
    switch ($role) {
        case 'admin':
            header('Location: ../admin/adminLanding.php');
            break;
        case 'non-resident':
            header('Location: ../nonResident/nonresidentLanding.php');
            break;
        default:
            header('Location: ../landing.php');
    }
    exit;
}

$logged_in = isset($_SESSION['user_id']);

// Profile dropdown variables
$role           = $_SESSION['account_role'] ?? '';
$userEmail      = $_SESSION['user_id']      ?? '';
$accId          = $_SESSION['acc_id']       ?? '';

$userName = $userEmail; // fallback to email
$userStatus = 'pending'; // default status
$stmt = $conn->prepare('SELECT firstname, userStatus FROM tbl_userinfo WHERE accID = ? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('s', $accId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    if ($row) {
        if (!empty($row['firstname'])) {
            $userName = $row['firstname'];
        }
        $userStatus = strtolower($row['userStatus'] ?? 'pending');
    }
    $stmt->close();
}

// Check user account status for service access
$canAccessServices = ($userStatus === 'approved' || $userStatus === 'active');

$roleLabel = match($role) {
    'admin'        => 'Admin',
    'resident'     => 'Resident',
    'resident,business/apartment owner'     => 'Resident & Owner',
    'non-resident' => 'Non-Resident',
    'non-resident,business/apartment owner' => 'Non-Resident & Owner',
    'business/apartment owner' => 'Owner',
    default        => 'User',
};

$roleBadgeClass = match($role) {
    'admin'        => 'bg-purple-100 text-purple-700 border border-purple-200',
    'resident'     => 'bg-green-100 text-green-700 border border-green-200',
    'resident,business/apartment owner' => 'bg-green-100 text-green-700 border border-green-200',
    'non-resident' => 'bg-blue-100 text-blue-700 border border-blue-200',
    'non-resident,business/apartment owner' => 'bg-blue-100 text-blue-700 border border-blue-200',
    'business/apartment owner' => 'bg-blue-100 text-blue-700 border border-blue-200',
    default        => 'bg-gray-100 text-gray-600 border border-gray-200',
};

$initials = strtoupper(substr($userName, 0, 2));

// Get user ID for queries
$userId = 0;
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

// Query separate requests for each tab
$documentRequests = [];
$equipmentRequests = [];
$beneficiaryRequests = [];

function prettyDocumentType($type) {
    $map = [
        'barangay_clearance'    => 'Barangay Clearance',
        'brangay_clearance'     => 'Barangay Clearance',
        'certificate_indigency' => 'Certificate of Indigency',
        'indigency'             => 'Certificate of Indigency',
        'certificate_residency' => 'Certificate of Residency',
        'residency'             => 'Certificate of Residency',
        'business_permit'       => 'Barangay Business Permit',
        'id'                    => 'Barangay ID',
        'jobseeker'             => 'Jobseeker Certificate',
        'first_time_jobseeker'  => 'First-Time Jobseeker',
    ];
    $lower = strtolower(trim((string)$type));
    return $map[$lower] ?? ucwords(str_replace(['_','-'],' ',$lower));
}

if ($userId > 0) {
    // 1. Document requests
    $docStmt = $conn->prepare('SELECT id, document_type, num_copies, purpose, notes, status, submitted_at FROM tbl_requestdocs WHERE userId = ? ORDER BY submitted_at DESC');
    if ($docStmt) {
        $docStmt->bind_param('i', $userId);
        $docStmt->execute();
        $result = $docStmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $documentRequests[] = $row;
        }
        $docStmt->close();
    }

    // 2. Equipment requests
    $equipStmt = $conn->prepare('
        SELECT er.id, er.equipmentId, er.quantityRequested, er.status, er.requestDate as submitted_at, er.returnDate, er.notes,
               e.equipmentName as equipment_name
        FROM tbl_equipmentrequest er
        JOIN tbl_equipmentlist e ON er.equipmentId = e.equipmentId
        WHERE er.userId = ? ORDER BY er.requestDate DESC
    ');
    if ($equipStmt) {
        $equipStmt->bind_param('i', $userId);
        $equipStmt->execute();
        $result = $equipStmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $equipmentRequests[] = $row;
        }
        $equipStmt->close();
    }

    // 3. Beneficiary requests
    $benefStmt = $conn->prepare('SELECT id, housing_status, house_material, electricity, water_source, toilet_type, pregnant_or_children, is_pwd, pwd_id_number, is_solo_parent, is_indigenous, pension_status, health_hypertension, health_diabetes, health_asthma, health_other, health_other_specify, health_none, requires_medicine, medicine_name, school_name, course, year_level, gwa_gpa, prio_score, status, submitted_at, (SELECT TIMESTAMPDIFF(YEAR, birthday, CURDATE()) FROM tbl_userinfo WHERE userID = ?) AS _age, (SELECT monthly_income FROM tbl_userinfo WHERE userID = ?) AS monthly_income FROM tbl_beneficiary WHERE userId = ? ORDER BY submitted_at DESC');
    if ($benefStmt) {
        $benefStmt->bind_param('iii', $userId, $userId, $userId);
        $benefStmt->execute();
        $result = $benefStmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $beneficiaryRequests[] = $row;
        }
        $benefStmt->close();
    }
}

// ────────────────────────────────────────────────────────────────────────────
// ELIGIBILITY ENGINE
// ────────────────────────────────────────────────────────────────────────────
function getEligiblePrograms(array $row): array {
    $age = (int)($row['_age'] ?? 0);
    $score = (int)($row['prio_score'] ?? 0);
    $eligible = [];

    // ─── 4Ps: specific housing, utility, pregnant, income conditions
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

    // ─── Senior Citizen: age >= 60
    if ($age >= 60) {
        $eligible[] = 'senior';
    }

    // ─── Scholarship: not empty school, have year level, gpa 1.00 - 1.75
    $school = trim($row['school_name'] ?? '');
    $yrLvl = trim($row['year_level'] ?? '');
    $gwaStr = trim($row['gwa_gpa'] ?? '');
    $gwaFloat = $gwaStr !== '' ? (float)$gwaStr : null;
    
    if ($school !== '' && $yrLvl !== '' && $gwaFloat !== null && $gwaFloat >= 1.00 && $gwaFloat <= 1.75) {
        $eligible[] = 'scholarship';
    }

    // ─── PWD: is_pwd = 1 AND has valid ID number
    if (!empty($row['is_pwd']) && $row['is_pwd'] == 1 && !empty($row['pwd_id_number'])) {
        $eligible[] = 'pwd';
    }

    // ─── Kabataan/SK: age 15–30
    if ($age >= 15 && $age <= 30) {
        $eligible[] = 'kabataan';
    }

    // ─── Registered Voters: age >= 18
    if ($age >= 18) {
        $eligible[] = 'voters';
    }

    return $eligible;
}

// Calculate eligible programs for the resident
$eligiblePrograms = [];
$eligibilityStatus = 'not eligible';
if (!empty($beneficiaryRequests)) {
    $latestBenef = $beneficiaryRequests[0];
    $eligiblePrograms = getEligiblePrograms($latestBenef);
    $eligibilityStatus = empty($eligiblePrograms) ? 'not eligible' : 'eligible';
}

// Calculate overall statistics
$totalRequests = count($documentRequests) + count($equipmentRequests) + count($beneficiaryRequests);
$pendingCount = 0;
$approvedCount = 0;
$rejectedCount = 0;

foreach (array_merge($documentRequests, $equipmentRequests, $beneficiaryRequests) as $request) {
    $status = strtolower($request['status'] ?? 'pending');
    if ($status === 'pending') $pendingCount++;
    elseif ($status === 'approved' || $status === 'active') $approvedCount++;
    elseif ($status === 'rejected' || $status === 'cancelled') $rejectedCount++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Services  –  <?php echo htmlspecialchars($siteSettings['site_title']); ?></title>
  <link rel="icon" href="<?php echo htmlspecialchars(site_config_logo_url($siteSettings, '../')); ?>" type="image/png">
  <?= site_config_css_vars($siteSettings) ?>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    * { box-sizing: border-box; }
    body { font-family: 'DM Sans', sans-serif; background: var(--site-primary-pale); margin: 0; }

    /* Navbar */
    .nav-link { position: relative; transition: color 0.2s; }
    .nav-link::after { content: ''; position: absolute; bottom: -2px; left: 0; width: 0; height: 2px; background: var(--site-primary); transition: width 0.3s; }
    .nav-link:hover::after { width: 100%; }
    .nav-link:hover { color: var(--site-primary-dark); }

    /* Navbar & Footer — dynamic theme color overrides (scoped so the rest of the page keeps its fixed accent colors) */
    :root {
      --site-primary-dark:   color-mix(in srgb, var(--site-primary) 55%, black);
      --site-primary-darker: color-mix(in srgb, var(--site-primary) 75%, black);
      --site-primary-light:  color-mix(in srgb, var(--site-primary) 55%, white);
      --site-primary-pale:   color-mix(in srgb, var(--site-primary) 12%, white);
    }
    header .bg-green-700 { background-color: var(--site-primary) !important; }
    header .hover\:bg-green-800:hover { background-color: var(--site-primary-dark) !important; }
    header .hover\:border-green-300:hover { border-color: var(--site-primary-light) !important; }
    header .hover\:bg-green-50:hover { background-color: var(--site-primary-pale) !important; }
    header .hover\:text-green-800:hover { color: var(--site-primary-darker) !important; }
    header .focus\:ring-green-400:focus { --tw-ring-color: var(--site-primary-light) !important; }
    footer.bg-green-950 { background-color: var(--site-primary-darker) !important; }
    footer .bg-green-700 { background-color: var(--site-primary) !important; }
    footer .border-green-800 { border-color: rgba(255,255,255,0.12) !important; }
    footer .text-green-300 { color: rgba(255,255,255,0.75) !important; }
    footer .text-green-400 { color: rgba(255,255,255,0.65) !important; }
    footer .text-green-500 { color: rgba(255,255,255,0.45) !important; }
    footer .hover\:text-white:hover { color: #ffffff !important; }

    /* Section card */
    .section-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 18px; padding: 28px; box-shadow: 0 2px 12px rgba(21,128,61,0.05); }
    .section-icon-wrap { width: 40px; height: 40px; border-radius: 10px; background: #dcfce7; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }

    /* Account status banner */
    .account-status-banner { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 20px; box-shadow: 0 2px 12px rgba(21,128,61,0.05); }
    .account-status-banner.account-status-rejected { background: #fef2f2; border-color: #fecaca; box-shadow: 0 2px 12px rgba(220, 38, 38, 0.08); }
    .status-icon { width: 40px; height: 40px; border-radius: 10px; background: #f0fdf4; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .status-icon.status-icon-rejected { background: #fee2e2; }
    .status-badge { flex-shrink: 0; }

    /* Service cards */
    .service-card {
      border: 1.5px solid #e5e7eb;
      border-radius: 14px;
      padding: 24px 20px;
      background: #fff;
      text-align: center;
      transition: border-color 0.2s, box-shadow 0.2s, transform 0.2s;
      cursor: pointer;
      text-decoration: none;
      display: block;
      position: relative;
    }
    .service-card:hover {
      border-color: var(--site-primary);
      box-shadow: 0 8px 24px rgba(21,128,61,0.1);
      transform: translateY(-3px);
    }

    .service-card-icon { width: 64px; height: 64px; margin: 0 auto 14px; display: flex; align-items: center; justify-content: center; }

    /* Stat cards */
    .stat-card { border: 1.5px solid #e5e7eb; border-radius: 12px; padding: 20px; text-align: center; background: #fff; }

    /* Tabs */
    .tabs-bar { display: flex; border-bottom: 1px solid #e5e7eb; overflow-x: auto; gap: 0; }
    .tab-btn {
      padding: 10px 20px; font-size: 0.84rem; font-weight: 600;
      color: #6b7280; border: none; background: none; cursor: pointer;
      border-bottom: 2px solid transparent; white-space: nowrap;
      transition: color 0.18s, border-color 0.18s;
    }
    .tab-btn.active { color: var(--site-primary-dark); border-bottom-color: var(--site-primary); background: var(--site-primary-pale); }
    .tab-btn:hover:not(.active) { color: #374151; }
    .tab-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; font-size: 0.65rem; font-weight: 700; background: #e5e7eb; color: #6b7280; }

    /* Table */
    .req-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
    .req-table thead th { background: #f9fafb; padding: 10px 14px; text-align: left; font-size: 0.72rem; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb; }
    .req-table tbody tr { border-bottom: 1px solid #f3f4f6; transition: background 0.12s; }
    .req-table tbody tr:hover { background: #f0fdf4; }
    .req-table tbody td { padding: 13px 14px; color: #374151; vertical-align: middle; }

    /* Status badges */
    .badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 12px; border-radius: 999px; font-size: 0.72rem; font-weight: 700; border: 1px solid; }
    .badge-pending  { background: #fef9c3; color: #a16207; border-color: #fde047; }
    .badge-approved { background: #dcfce7; color: #15803d; border-color: #86efac; }
    .badge-active   { background: #dbeafe; color: #1d4ed8; border-color: #93c5fd; }
    .badge-borrowed { background: #e0f2fe; color: #0c4a6e; border-color: #7dd3fc; }
    .badge-returned { background: #dcfce7; color: #14532d; border-color: #86efac; }

    /* Eligible programs banner */
    .eligible-programs-banner { background: #f0fdf4; border: 1px solid #86efac; border-radius: 14px; padding: 20px; box-shadow: 0 2px 12px rgba(21,128,61,0.05); margin-bottom: 24px; }
    .eligible-programs-banner.not-eligible { background: #fef2f2; border-color: #fecaca; }
    .programs-container { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
    .program-badge { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; background: #dbeafe; color: #1d4ed8; border-radius: 999px; font-size: 0.85rem; font-weight: 600; border: 1px solid #93c5fd; }
    .program-badge.badge-4ps { background: #fef08a; color: #a16207; border-color: #fde047; }
    .program-badge.badge-senior { background: #e0e7ff; color: #4f46e5; border-color: #c7d2fe; }
    .program-badge.badge-scholarship { background: #e9d5ff; color: #9333ea; border-color: #f3d8ff; }
    .program-badge.badge-pwd { background: #fce7f3; color: #be185d; border-color: #fbcfe8; }
    .program-badge.badge-kabataan { background: #cffafe; color: #0891b2; border-color: #a5f3fc; }
    .program-badge.badge-voters { background: #dcfce7; color: #15803d; border-color: #86efac; }
    .program-badge i { font-size: 0.9rem; }
    .badge-cancelled{ background: #f3f4f6; color: #6b7280; border-color: #d1d5db; }
    .badge-listed   { background: #f0fdf4; color: #15803d; border-color: #86efac; }
    .badge-na       { background: #f9fafb; color: #9ca3af; border-color: #e5e7eb; }
    .badge-eligible { background: #dcfce7; color: #15803d; border-color: #86efac; }

    /* Action links */
    .act-view   { color: var(--site-primary-dark); font-weight: 600; font-size: 0.82rem; background: none; border: none; cursor: pointer; text-decoration: underline; text-underline-offset: 2px; transition: color 0.15s; }
    .act-view:hover   { color: var(--site-primary-darker); }
    .act-delete { color: #dc2626; font-weight: 600; font-size: 0.82rem; background: none; border: none; cursor: pointer; text-decoration: underline; text-underline-offset: 2px; transition: color 0.15s; }
    .act-delete:hover { color: #b91c1c; }

    /* Tab panels */
    .tab-panel { display: block; }
    .tab-panel.hidden { display: none; }

    /* Application details card */
    .app-detail-card { border: 1px solid #e5e7eb; border-radius: 14px; padding: 22px; background: #fff; }
    .app-remark { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; padding: 14px 16px; font-size: 0.84rem; color: #15803d; margin-top: 14px; display: flex; gap: 10px; align-items: flex-start; }

    /* Eligibility tags */
    .eligibility-tags { display: flex; flex-wrap: wrap; gap: 6px; }
    .eligibility-tag { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 16px; font-size: 0.75rem; font-weight: 600; border: 1px solid; }
    .eligibility-tag.eligible { background: #dcfce7; color: #15803d; border-color: #86efac; }
    .eligibility-tag.not-eligible { background: #fef2f2; color: #dc2626; border-color: #fca5a5; }

    /* Access denied alert */
    .access-denied-alert { position: fixed; top: 20px; right: 20px; z-index: 1000; max-width: 400px; }
    .alert-content { background: #dc2626; color: white; border-radius: 12px; padding: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); display: flex; gap: 12px; align-items: flex-start; }
    .alert-icon { font-size: 1.25rem; flex-shrink: 0; margin-top: 2px; }
    .alert-text h4 { font-size: 1rem; font-weight: 700; margin: 0 0 4px 0; }
    .alert-text p { font-size: 0.85rem; margin: 0; line-height: 1.4; }
    .alert-close { background: none; border: none; color: white; cursor: pointer; padding: 4px; opacity: 0.8; transition: opacity 0.2s; flex-shrink: 0; }
    .alert-close:hover { opacity: 1; }

    /* ─── Application Details Modal ─── */
    .modal-overlay {
      position: fixed; inset: 0; z-index: 500;
      background: rgba(5,46,22,0.5); backdrop-filter: blur(4px);
      display: flex; align-items: center; justify-content: center;
      padding: 20px; overflow-y: auto;
      opacity: 0; pointer-events: none; transition: opacity 0.22s;
    }
    .modal-overlay.open { opacity: 1; pointer-events: auto; }
    .modal-box {
      background: #fff; border-radius: 20px;
      width: 100%; max-width: 540px;
      box-shadow: 0 24px 60px rgba(5,46,22,0.22);
      transform: translateY(20px);
      transition: transform 0.25s cubic-bezier(0.4,0,0.2,1);
      overflow: hidden;
    }
    .modal-overlay.open .modal-box { transform: translateY(0); }
    .modal-header {
      background: #f9fafb; border-bottom: 1px solid #d7d8d8;
      padding: 18px 22px; display: flex; align-items: center; justify-content: space-between;
    }

    .modal-body { padding: 22px; max-height: 75vh; overflow-y: auto; }
    .modal-body::-webkit-scrollbar { width: 4px; }
    .modal-body::-webkit-scrollbar-thumb { background: #d1fae5; border-radius: 4px; }
    .detail-row { display: flex; flex-direction: column; gap: 2px; margin-bottom: 14px; }
    .detail-label { font-size: 0.72rem; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.06em; }
    .detail-val   { font-size: 0.88rem; color: #374151; font-weight: 500; }

    @keyframes fadeUp { from { opacity:0; transform:translateY(14px); } to { opacity:1; transform:translateY(0); } }
    .f1 { animation: fadeUp 0.45s 0.05s ease both; }
    .f2 { animation: fadeUp 0.45s 0.12s ease both; }
    .f3 { animation: fadeUp 0.45s 0.19s ease both; }
    .f4 { animation: fadeUp 0.45s 0.26s ease both; }
    .f5 { animation: fadeUp 0.45s 0.33s ease both; }
  </style>
    <link rel="stylesheet" href="dist/output.css">
    <script src="https://cdn.tailwindcss.com/3.4.16"></script>
</head>
<body>
<div class="min-h-screen">
  <link rel="stylesheet" href="../assets/responsive-global.css">

  <div class="w-full">

<!-- NAVBAR -->
<header class="w-full h-[68px] border-b border-green-100 flex items-center px-8 bg-white shadow-sm sticky top-0 z-50">
  <div class="flex items-center gap-3">
      <a href="residentLanding.php" class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-full bg-green-700 flex items-center justify-center shadow overflow-hidden">
          <img src="<?php echo htmlspecialchars(site_config_logo_url($siteSettings, '../')); ?>" alt="Logo" class="w-full h-full object-contain" />
        </div>
        <div>
          <h3 class="font-bold text-base leading-tight" style="font-family:'DM Sans',sans-serif;color:var(--site-primary-dark)"><?php echo htmlspecialchars($siteSettings['site_title']); ?></h3>
          <p class="text-[10px] tracking-widest uppercase" style="color:var(--site-primary)"><?php echo htmlspecialchars($siteSettings['barangay_name']); ?></p>
        </div>
      </a>
  </div>
  <nav class="ml-auto flex items-center gap-3 md:gap-6 text-gray-600 text-sm font-medium">

    <!-- Desktop Nav -->
    <div class="hidden md:flex items-center gap-5 lg:gap-8">
      <a href="residentLanding.php#announcements" class="nav-link">Announcements</a>
      <a href="../busaptListing.php?type=business" class="nav-link">Business</a>
      <a href="../busaptListing.php?type=apartment" class="nav-link">Apartments</a>
      <?php if ($logged_in): ?>
        <div class="relative" id="profile-menu-wrapper">
          <button id="profile-btn" onclick="toggleProfileMenu()"
            class="flex items-center gap-2 px-3 py-1.5 rounded-xl border border-gray-200 hover:border-green-300 hover:bg-green-50 transition focus:outline-none focus:ring-2 focus:ring-green-400"
            aria-haspopup="true" aria-expanded="false">
            <span class="w-8 h-8 rounded-full bg-green-700 text-white flex items-center justify-center text-xs font-bold select-none"><?= htmlspecialchars($initials) ?></span>
            <span class="hidden lg:block text-gray-700 text-sm max-w-[140px] truncate"><?= htmlspecialchars($userName) ?></span>
            <svg id="profile-chevron" class="w-4 h-4 text-gray-400 transition-transform duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
            </svg>
          </button>
          <div id="profile-dropdown" class="hidden absolute right-0 mt-2 w-64 bg-white rounded-2xl shadow-xl border border-gray-100 overflow-hidden z-50" role="menu">
            <div class="px-4 py-3 bg-gradient-to-br from-green-50 to-emerald-50 border-b border-gray-100">
              <div class="flex items-center gap-3">
                <span class="w-10 h-10 rounded-full bg-green-700 text-white flex items-center justify-center text-sm font-bold select-none flex-shrink-0"><?= htmlspecialchars($initials) ?></span>
                <div class="min-w-0">
                  <p class="text-sm font-semibold text-gray-800 truncate"><?= htmlspecialchars($userName) ?></p>
                  <span class="inline-block mt-0.5 px-2 py-0.5 rounded-full text-[11px] font-semibold <?= $roleBadgeClass ?>"><?= htmlspecialchars($roleLabel) ?></span>
                </div>
              </div>
            </div>
            <div class="py-1">
              <a href="myProfile.php" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-green-50 hover:text-green-800 transition"><i class="fa-solid fa-user w-4 text-gray-400"></i> My Profile</a>
            </div>
            <div class="border-t border-gray-100 py-1">
              <a href="../logout.php" class="flex items-center gap-3 px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition"><i class="fa-solid fa-arrow-right-from-bracket w-4"></i> Logout</a>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <!-- Mobile hamburger -->
    <button id="mobile-menu-btn"
      class="md:hidden flex items-center justify-center p-2 text-gray-600 hover:text-[var(--site-primary-dark)] hover:bg-[var(--site-primary-pale)] rounded-lg transition"
      aria-label="Toggle menu">
      <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
      </svg>
    </button>
  </nav>
</header>

<!-- ──────────── MOBILE SIDEBAR ──────────── -->
<div id="mobile-sidebar-overlay" class="fixed inset-0 bg-black/50 z-[60] hidden opacity-0 transition-opacity duration-300"></div>
<div id="mobile-sidebar" class="fixed inset-y-0 right-0 w-72 max-w-[85vw] bg-white shadow-2xl transform translate-x-full transition-transform duration-300 z-[70] flex flex-col">
  <div class="p-4 border-b border-gray-100 flex items-center justify-between">
    <div class="flex items-center gap-2 min-w-0">
      <?php if ($logged_in): ?>
        <span class="w-8 h-8 rounded-full text-white flex items-center justify-center text-xs font-bold" style="background:var(--site-primary)"><?= htmlspecialchars($initials) ?></span>
        <div class="min-w-0">
          <p class="text-sm font-semibold text-gray-800 truncate max-w-[140px]"><?= htmlspecialchars($userName) ?></p>
          <span class="text-[10px] font-semibold <?= $roleBadgeClass ?> px-1.5 py-0.5 rounded-full"><?= htmlspecialchars($roleLabel) ?></span>
        </div>
      <?php else: ?>
        <h3 class="font-bold text-green-900" style="font-family:'DM Sans',sans-serif;">Menu</h3>
      <?php endif; ?>
    </div>
    <button id="mobile-menu-close" class="p-2 text-gray-500 hover:text-red-500 rounded-full hover:bg-red-50 transition" aria-label="Close menu">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
      </svg>
    </button>
  </div>

  <div class="flex-1 overflow-y-auto py-3 px-3 space-y-0.5">
    <a href="residentLanding.php#announcements" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 font-medium hover:bg-[var(--site-primary-pale)] hover:text-[var(--site-primary-dark)] transition">
      <i class="fa-solid fa-bullhorn w-4 text-[var(--site-primary)]"></i> Announcements
    </a>
    <a href="../busaptListing.php?type=business" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 font-medium hover:bg-[var(--site-primary-pale)] hover:text-[var(--site-primary-dark)] transition">
      <i class="fa-solid fa-store w-4 text-[var(--site-primary)]"></i> Business
    </a>
    <a href="../busaptListing.php?type=apartment" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 font-medium hover:bg-[var(--site-primary-pale)] hover:text-[var(--site-primary-dark)] transition">
      <i class="fa-solid fa-building w-4 text-[var(--site-primary)]"></i> Apartments
    </a>

    <?php if ($logged_in): ?>
      <div class="pt-2 border-t border-gray-100 mt-2 space-y-0.5">
        <a href="myProfile.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 font-medium hover:bg-[var(--site-primary-pale)] hover:text-[var(--site-primary-dark)] transition">
          <i class="fa-solid fa-user w-4 text-[var(--site-primary)]"></i> My Profile
        </a>
        <a href="../logout.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-red-600 font-medium hover:bg-red-50 transition">
          <i class="fa-solid fa-arrow-right-from-bracket w-4"></i> Logout
        </a>
      </div>
    <?php endif; ?>
  </div>
</div>

<main class="max-w-4xl mx-auto px-4 py-12 space-y-10">

  <!-- ──────────── SERVICES ──────────── -->
  <div class="f1">
    <h1 class="text-3xl font-bold text-center mb-8" style="font-family:'Playfair Display',serif;color:var(--site-primary-dark)">Services</h1>

    <!-- 3-col service cards matching wireframe -->
    <?php 
      $roleLower = strtolower($role);

      // Default: 3 columns
      $gridClass = "sm:grid-cols-3";

      // If role matches — use 4 columns
      if (str_contains($roleLower, 'resident,business/apartment owner')) {
          $gridClass = "sm:grid-cols-4";
      }
      ?>

      <div class="grid grid-cols-1 <?= $gridClass ?> gap-5">

      <a href="beneficiaryForm.php" class="service-card">
        <div class="w-14 h-14 mx-auto rounded-2xl bg-blue-50 group-hover:bg-blue-100 flex items-center justify-center transition mb-4">
        <i class="fa-solid fa-handshake text-2xl text-blue-600"></i>
      </div>
        <p class="font-bold text-gray-800 text-sm mb-2">Apply for Barangay Beneficiary</p>
        <p class="text-xs text-gray-500 leading-relaxed">barangay assistance programs, including support for senior citizens, PWDs, 4Ps, Kabataan, voters.</p>
      </a>

      <a href="documentsForm.php" class="service-card">
        <div class="w-14 h-14 mx-auto rounded-2xl bg-green-50 group-hover:bg-green-100 flex items-center justify-center transition mb-4">
          <i class="fa-solid fa-file-lines text-2xl text-green-600"></i>
        </div>
        <p class="font-bold text-gray-800 text-sm mb-2">Apply / Request Documents</p>
        <p class="text-xs text-gray-500 leading-relaxed">apply and request official barangay documents such as certificates, clerance, and other forms.</p>
      </a>

      <a href="borrowEquipment.php" class="service-card">
        <div class="w-14 h-14 mx-auto rounded-2xl bg-purple-50 group-hover:bg-purple-100 flex items-center justify-center transition mb-4">
          <i class="fa-solid fa-right-left text-2xl text-purple-600"></i>
        </div>
        <p class="font-bold text-gray-800 text-sm mb-2">Borrow Equipments</p>
        <p class="text-xs text-gray-500 leading-relaxed">apply and request official barangay equipment for community use.</p>
      </a>

      <?php $roleLower = strtolower($role); ?>
      <?php if (str_contains($roleLower, 'resident,business/apartment owner') || str_contains($roleLower, 'business/apartment owner')): ?>
        <a href="manageList.php" class="service-card">
        <div class="w-14 h-14 mx-auto rounded-2xl bg-orange-50 group-hover:bg-orange-100 flex items-center justify-center transition mb-4">
          <i class="fa-solid fa-list text-2xl text-orange-500"></i>
        </div>
        <p class="font-bold text-gray-800 text-sm mb-2">Post Listing</p>
        <p class="text-xs text-gray-500 leading-relaxed">post and manage business or apartment listing.</p>
      </a>
      <?php endif; ?>

    </div>
  </div>

  <!-- ──────────── MY REQUESTS ──────────── -->
  <div class="f2">
    <h2 class="text-2xl font-bold text-center mb-6" style="font-family:'Playfair Display',serif;color:var(--site-primary-dark)">My Requests</h2>

    <!-- Account Status Banner -->
    <div class="account-status-banner mb-6<?php if ($userStatus === 'rejected') echo ' account-status-rejected'; ?>">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <div class="status-icon<?php if ($userStatus === 'rejected') echo ' status-icon-rejected'; ?>">
            <?php
            $statusIcon = match($userStatus) {
                'approved' => 'fa-circle-check',
                'active' => 'fa-circle-check',
                'pending' => 'fa-clock',
                'disabled' => 'fa-circle-xmark',
                'rejected' => 'fa-circle-xmark',
                default => 'fa-question'
            };
            $statusColor = match($userStatus) {
                'approved' => 'text-green-600',
                'active' => 'text-green-600',
                'pending' => 'text-yellow-600',
                'disabled' => 'text-red-600',
                'rejected' => 'text-red-600',
                default => 'text-gray-600'
            };
            ?>
            <i class="fa-solid <?php echo $statusIcon; ?> <?php echo $statusColor; ?> text-lg"></i>
          </div>
          <div>
            <p class="font-semibold text-gray-800">Account Status: <span class="capitalize"><?php echo $userStatus; ?></span></p>
            <p class="text-sm text-gray-600">
              <?php
              $statusDescription = match($userStatus) {
                  'approved' => 'Your account is approved. You can access all barangay services and submit requests.',
                  'active' => 'Your account is active. You can access all barangay services and submit requests.',
                  'pending' => 'Your account is pending verification. Service access is restricted until approval.',
                  'disabled' => 'Your account is disabled. Please contact barangay administration for assistance.',
                  'rejected' => 'Your account application has been rejected. Please update your profile information and resubmit for review.',
                  default => 'Your account status is being reviewed. Some services may be restricted.'
              };
              echo $statusDescription;
              ?>
            </p>
          </div>
        </div>
        <?php if ($userStatus !== 'approved' && $userStatus !== 'active'): ?>
        <div class="status-badge">
          <span class="badge badge-pending">
            <i class="fa-solid fa-exclamation-triangle text-[10px]"></i> Limited Access
          </span>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- 4 stat boxes matching wireframe exactly -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
      <div class="stat-card">
        <p class="text-4xl font-bold text-gray-800 leading-none mb-1"><?php echo $totalRequests; ?></p>
        <p class="text-sm text-gray-500 font-medium">Total Requests</p>
      </div>
      <div class="stat-card">
        <p class="text-4xl font-bold text-gray-800 leading-none mb-1"><?php echo $pendingCount; ?></p>
        <p class="text-sm text-gray-500 font-medium">Pending</p>
      </div>
      <div class="stat-card">
        <p class="text-4xl font-bold text-gray-800 leading-none mb-1"><?php echo $approvedCount; ?></p>
        <p class="text-sm text-gray-500 font-medium">Approved/Active</p>
      </div>
      <div class="stat-card">
        <p class="text-4xl font-bold text-gray-800 leading-none mb-1"><?php echo $rejectedCount; ?></p>
        <p class="text-sm text-gray-500 font-medium">Rejected</p>
      </div>
    </div>

    <!-- Tab Bar -->
    <div class="tabs-bar mb-6">
      <button class="tab-btn active" onclick="switchTab(this, 'documents')">
        <i class="fa-solid fa-file-lines text-sm"></i> Documents <span class="tab-badge"><?php echo count($documentRequests); ?></span>
      </button>
      <button class="tab-btn" onclick="switchTab(this, 'borrowing')">
        <i class="fa-solid fa-tools text-sm"></i> Borrowing <span class="tab-badge"><?php echo count($equipmentRequests); ?></span>
      </button>
      <button class="tab-btn" onclick="switchTab(this, 'beneficiary')">
        <i class="fa-solid fa-hand-holding-heart text-sm"></i> Beneficiary <span class="tab-badge"><?php echo count($beneficiaryRequests); ?></span>
      </button>
    </div>

    <!-- Tab Panels -->
    <div id="tab-documents" class="tab-panel">
      <div class="section-card p-0 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100">
          <p class="font-bold text-gray-800 text-sm">Document Requests</p>
          <p class="text-gray-500 text-xs mt-1">View your document request history</p>
        </div>
        <table class="req-table">
          <thead>
            <tr>
              <th>Document Type</th>
              <th>Copies</th>
              <th>Date Submitted</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($documentRequests)): ?>
            <tr>
              <td colspan="5" class="text-center py-10 text-gray-400 text-sm">No document requests submitted yet.</td>
            </tr>
            <?php else: ?>
              <?php foreach ($documentRequests as $request): ?>
              <tr>
                <td><?php echo htmlspecialchars(prettyDocumentType($request['document_type'])); ?></td>
                <td><?php echo htmlspecialchars($request['num_copies']); ?></td>
                <td><?php echo htmlspecialchars(date('m/d/Y', strtotime($request['submitted_at']))); ?></td>
                <td>
                  <?php
                  $status = $request['status'] ?? 'pending';
                  $statusClass = match($status) {
                      'pending' => 'badge-pending',
                      'approved' => 'badge-approved',
                      'active' => 'badge-active',
                      'rejected' => 'badge-cancelled',
                      'cancelled' => 'badge-cancelled',
                      default => 'badge-pending'
                  };
                  $statusIcon = match($status) {
                      'pending' => 'fa-clock',
                      'approved' => 'fa-circle-check',
                      'active' => 'fa-circle-check',
                      'rejected' => 'fa-xmark',
                      'cancelled' => 'fa-xmark',
                      default => 'fa-clock'
                  };
                  $statusText = ucfirst($status);
                  ?>
                  <span class="badge <?php echo $statusClass; ?>">
                    <i class="fa-solid <?php echo $statusIcon; ?> text-[10px]"></i> <?php echo $statusText; ?>
                  </span>
                </td>
                <td>
                  <button class="act-view" onclick="openModal({
                    name: '<?php echo htmlspecialchars($userName); ?>',
                    updated: '<?php echo htmlspecialchars(date('F j, Y', strtotime($request['submitted_at']))); ?>',
                    type: '<?php echo htmlspecialchars(prettyDocumentType($request['document_type'])); ?>',
                    requestType: 'document',
                    purpose: '<?php echo htmlspecialchars($request['purpose']); ?>',
                    requirements: ['Valid Government ID'],
                    status: '<?php echo $request['status']; ?>',
                    remark: 'Your document request is <?php echo $request['status']; ?>. Please check back later for updates.'
                  })">View</button>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div id="tab-borrowing" class="tab-panel hidden">
      <div class="section-card p-0 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100">
          <p class="font-bold text-gray-800 text-sm">Equipment Borrowing Requests</p>
          <p class="text-gray-500 text-xs mt-1">View your equipment borrowing request history</p>
        </div>
        <table class="req-table">
          <thead>
            <tr>
              <th>Equipment Name</th>
              <th>Quantity</th>
              <th>Return Date</th>
              <th>Date Submitted</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($equipmentRequests)): ?>
            <tr>
              <td colspan="6" class="text-center py-10 text-gray-400 text-sm">No equipment requests submitted yet.</td>
            </tr>
            <?php else: ?>
              <?php foreach ($equipmentRequests as $request): ?>
              <tr>
                <td><?php echo htmlspecialchars($request['equipment_name']); ?></td>
                <td><?php echo htmlspecialchars($request['quantityRequested']); ?></td>
                <td><?php echo $request['returnDate'] ? htmlspecialchars(date('m/d/Y', strtotime($request['returnDate']))) : ' – '; ?></td>
                <td><?php echo htmlspecialchars(date('m/d/Y', strtotime($request['submitted_at']))); ?></td>
                <td>
                  <?php
                  $status = strtolower(trim((string)($request['status'] ?? 'pending')));
                  $statusClass = match($status) {
                      'pending' => 'badge-pending',
                      'approved' => 'badge-approved',
                      'active' => 'badge-active',
                      'borrowed' => 'badge-borrowed',
                      'returned' => 'badge-returned',
                      'rejected' => 'badge-cancelled',
                      'cancelled' => 'badge-cancelled',
                      default => 'badge-pending'
                  };
                  $statusIcon = match($status) {
                      'pending' => 'fa-clock',
                      'approved' => 'fa-circle-check',
                      'active' => 'fa-circle-check',
                      'borrowed' => 'fa-box-open',
                      'returned' => 'fa-rotate-left',
                      'rejected' => 'fa-xmark',
                      'cancelled' => 'fa-xmark',
                      default => 'fa-clock'
                  };
                  $statusText = ucfirst($status);
                  ?>
                  <span class="badge <?php echo $statusClass; ?>">
                    <i class="fa-solid <?php echo $statusIcon; ?> text-[10px]"></i> <?php echo $statusText; ?>
                  </span>
                </td>
                <td>
                  <button class="act-view" onclick="openModal({
                    name: '<?php echo htmlspecialchars($userName); ?>',
                    updated: '<?php echo htmlspecialchars(date('F j, Y', strtotime($request['submitted_at']))); ?>',
                    type: '<?php echo htmlspecialchars($request['equipment_name']); ?>',
                    requestType: 'equipment',
                    purpose: '<?php echo htmlspecialchars($request['notes'] ?: 'Equipment borrowing request'); ?>',
                    requirements: ['Valid Government ID'],
                    status: '<?php echo $request['status']; ?>',
                    remark: 'Your equipment request is <?php echo $request['status']; ?>. Please check back later for updates.'
                  })">View</button>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div id="tab-beneficiary" class="tab-panel hidden">
      <div class="section-card p-0 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100">
          <p class="font-bold text-gray-800 text-sm">Beneficiary Applications</p>
          <p class="text-gray-500 text-xs mt-1">View your beneficiary application history</p>
        </div>
        <table class="req-table">
          <thead>
            <tr>
              <th>Application Type</th>
              <th>Priority Score</th>
              <th>Date Submitted</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($beneficiaryRequests)): ?>
            <tr>
              <td colspan="5" class="text-center py-10 text-gray-400 text-sm">No beneficiary applications submitted yet.</td>
            </tr>
            <?php else: ?>
              <?php foreach ($beneficiaryRequests as $request): ?>
              <tr>
                <td>
                  <?php
                  $description = '';
                  if ($request['is_pwd']) $description .= 'PWD ';
                  if ($request['is_solo_parent']) $description .= 'Solo Parent ';
                  if ($request['is_indigenous']) $description .= 'Indigenous ';
                  if ($request['pregnant_or_children']) $description .= 'Pregnant/Children ';
                  if ($request['school_name']) $description .= 'Student ';
                  if (!$description) $description = 'Beneficiary Application';
                  echo htmlspecialchars(trim($description));
                  ?>
                </td>
                <td><?php echo htmlspecialchars($request['prio_score'] ?? ' – '); ?></td>
                <td><?php echo htmlspecialchars(date('m/d/Y', strtotime($request['submitted_at']))); ?></td>
                <td>
                  <?php
                  $status = $request['status'] ?? 'pending';
                  $statusClass = match($status) {
                      'pending' => 'badge-pending',
                      'approved' => 'badge-approved',
                      'active' => 'badge-active',
                      'rejected' => 'badge-cancelled',
                      'cancelled' => 'badge-cancelled',
                      default => 'badge-pending'
                  };
                  $statusIcon = match($status) {
                      'pending' => 'fa-clock',
                      'approved' => 'fa-circle-check',
                      'active' => 'fa-circle-check',
                      'rejected' => 'fa-xmark',
                      'cancelled' => 'fa-xmark',
                      default => 'fa-clock'
                  };
                  $statusText = ucfirst($status);
                  ?>
                  <span class="badge <?php echo $statusClass; ?>">
                    <i class="fa-solid <?php echo $statusIcon; ?> text-[10px]"></i> <?php echo $statusText; ?>
                  </span>
                </td>
                <td>
                  <button class="act-view" onclick="openModal({
                    name: '<?php echo htmlspecialchars($userName); ?>',
                    updated: '<?php echo htmlspecialchars(date('F j, Y', strtotime($request['submitted_at']))); ?>',
                    type: 'Beneficiary Application',
                    requestType: 'beneficiary',
                    purpose: '<?php echo htmlspecialchars(trim($description)); ?>',
                    requirements: ['Valid Government ID', 'Proof of Income', 'Barangay Clearance'],
                    status: '<?php echo $request['status']; ?>',
                    remark: 'Your beneficiary application is <?php echo $request['status']; ?>. Please check back later for updates.',
                    eligibility: {
                      housing_status: '<?php echo htmlspecialchars($request['housing_status'] ?: ''); ?>',
                      house_material: '<?php echo htmlspecialchars($request['house_material'] ?: ''); ?>',
                      electricity: '<?php echo htmlspecialchars($request['electricity'] ?: ''); ?>',
                      water_source: '<?php echo htmlspecialchars($request['water_source'] ?: ''); ?>',
                      toilet_type: '<?php echo htmlspecialchars($request['toilet_type'] ?: ''); ?>',
                      pregnant_or_children: <?php echo $request['pregnant_or_children'] ? 'true' : 'false'; ?>,
                      is_pwd: <?php echo $request['is_pwd'] ? 'true' : 'false'; ?>,
                      pwd_id_number: '<?php echo htmlspecialchars($request['pwd_id_number'] ?: ''); ?>',
                      school_name: '<?php echo htmlspecialchars($request['school_name'] ?: ''); ?>',
                      year_level: '<?php echo htmlspecialchars($request['year_level'] ?: ''); ?>',
                      gwa_gpa: '<?php echo htmlspecialchars($request['gwa_gpa'] ?: ''); ?>',
                      monthly_income: <?php echo floatval($request['monthly_income'] ?? 0); ?>,
                      prio_score: <?php echo intval($request['prio_score'] ?: 0); ?>,
                      _age: <?php echo intval($request['_age'] ?? 0); ?>
                    }
                  })">View</button>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>


</main>

<!-- APPLICATION DETAILS MODAL -->
<div class="modal-overlay" id="appModal" onclick="closeModalOnOverlay(event)">
  <div class="modal-box">

    <!-- Modal header -->
    <div class="modal-header">
      <div class="flex items-center gap-3">
        <div class="w-9 h-9 rounded-xl bg-green-700 flex items-center justify-center flex-shrink-0">
          <i class="fa-solid fa-file-lines text-white text-sm"></i>
        </div>
        <div>
          <p class="text-green-600 font-bold text-base leading-tight">Application Details</p>
          <p class="text-green-600 text-xs mt-0.5">Review your request information</p>
        </div>
      </div>
    </div>

    <!-- Modal body -->
    <div class="modal-body">

      <!-- Top row: name + last updated -->
      <div class="flex flex-wrap justify-between gap-2 pb-4 mb-4 border-b border-gray-100">
        <div class="detail-row mb-0">
          <span class="detail-label">Applicant Name</span>
          <span class="detail-val font-bold text-gray-900" id="mName"> – </span>
        </div>
        <div class="detail-row mb-0 text-right">
          <span class="detail-label">Last Updated</span>
          <span class="detail-val text-gray-500" id="mUpdated"> – </span>
        </div>
      </div>

      <!-- Application type -->
      <div class="detail-row">
        <span class="detail-label">Application Type</span>
        <span class="detail-val" id="mType"> – </span>
      </div>

      <!-- Purpose -->
      <div class="detail-row">
        <span class="detail-label">Purpose of Application</span>
        <span class="detail-val" id="mPurpose"> – </span>
      </div>

      <!-- Submitted requirements -->
      <div class="detail-row">
        <span class="detail-label mb-1">Submitted Requirements</span>
        <ul class="list-disc list-inside ml-1 space-y-1 text-sm text-gray-600" id="mRequirements"></ul>
      </div>

      <!-- Status -->
      <div class="detail-row">
        <span class="detail-label">Application Status</span>
        <span id="mStatus"></span>
      </div>

      <!-- Eligibility (only show for beneficiary applications) -->
      <div class="detail-row" id="eligibilityRow" style="display: none;">
        <span class="detail-label mb-1">Eligibility</span>
        <div class="eligibility-tags" id="mEligibility"></div>
      </div>

      <!-- Remark box -->
      <div class="app-remark mt-2" id="mRemark">
        <i class="fa-solid fa-circle-info text-green-600 flex-shrink-0 mt-0.5"></i>
        <p class="leading-relaxed text-green-800 text-xs italic" id="mRemarkText"> – </p>
      </div>

    </div>

    <!-- Modal footer -->
    <div class="px-5 py-4 border-t border-gray-100 flex justify-end">
      <button onclick="closeModal()" class="px-6 py-2.5 bg-green-700 hover:bg-green-800 text-white text-sm font-semibold rounded-xl transition">
        Close
      </button>
    </div>

  </div>
</div>

<!-- FOOTER -->
<footer class="mt-16 bg-green-950 text-white pt-14 pb-6 px-4">
  <div class="max-w-6xl mx-auto">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-10 pb-10 border-b border-green-800">
      <div>
        <div class="flex items-center gap-3 mb-4">
          <div class="w-12 h-12 rounded-full bg-green-700 flex items-center justify-center overflow-hidden"><img src="<?php echo htmlspecialchars(site_config_logo_url($siteSettings, '../')); ?>" alt="Logo" class="w-full h-full object-contain" /></div>
          <div><h3 class="text-lg font-bold"><?php echo htmlspecialchars($siteSettings['site_title']); ?></h3><p class="text-green-400 text-xs tracking-widest uppercase"><?php echo htmlspecialchars($siteSettings['barangay_name']); ?></p></div>
        </div>
        <div class="space-y-2 text-sm text-green-300">
          <p><i class="fa-solid fa-location-dot mr-2 text-green-500"></i><?php echo htmlspecialchars($siteSettings['barangay_name']); ?>, <?php echo htmlspecialchars($siteSettings['municipality']); ?></p>
          <p><i class="fa-solid fa-envelope mr-2 text-green-500"></i><?php echo htmlspecialchars($siteSettings['email']); ?></p>
          <p><i class="fa-solid fa-phone mr-2 text-green-500"></i><?php echo htmlspecialchars($siteSettings['contact_number']); ?></p>
        </div>
        <a href="<?php echo htmlspecialchars($siteSettings['facebook_link'] ?: '#'); ?>" target="_blank" rel="noopener noreferrer" class="mt-4 inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded-lg text-sm transition"><i class="fab fa-facebook"></i> Facebook Page</a>
      </div>
      <div>
        <h4 class="font-semibold text-green-300 text-xs uppercase tracking-widest mb-4">Legal</h4>
        <div class="space-y-2">
          <a href="../infoSecurity/dataProtection.php" class="block text-sm text-green-400 hover:text-white transition">Privacy Policy</a>
          <a href="../infoSecurity/terms.php" class="block text-sm text-green-400 hover:text-white transition">Terms of Service</a>
          <a href="../infoSecurity/dataProtection.php" class="block text-sm text-green-400 hover:text-white transition">Data Protection Notice</a>
        </div>
      </div>
      <div>
        <h4 class="font-semibold text-green-300 text-xs uppercase tracking-widest mb-4">Quick Links</h4>
        <div class="space-y-2">
          <a href="residentPanel.php" class="block text-sm text-green-400 hover:text-white transition">Services</a>
          <a href="../busaptListing.php?type=apartment" class="block text-sm text-green-400 hover:text-white transition">Apartments</a>
          <a href="../busaptListing.php?type=business" class="block text-sm text-green-400 hover:text-white transition">Business Directory</a>
        </div>
      </div>
    </div>
    <div class="text-center mt-6 text-green-500 text-sm">© 2026 <?php echo htmlspecialchars($siteSettings['site_title']); ?>. All Rights Reserved. Made with ❤️ for <?php echo htmlspecialchars($siteSettings['barangay_name']); ?>.</div>
  </div>
</footer>

<script>
  // Pass PHP variables to JavaScript
  const userStatus = '<?php echo $userStatus; ?>';
  const canAccessServices = <?php echo $canAccessServices ? 'true' : 'false'; ?>;
  const showAccessDenied = <?php echo isset($_SESSION['access_denied']) && $_SESSION['access_denied'] ? 'true' : 'false'; ?>;
  const accessDeniedStatus = '<?php echo $_SESSION['access_denied_status'] ?? ''; ?>';

  // Clear the session variable
  <?php unset($_SESSION['access_denied'], $_SESSION['access_denied_status']); ?>

  document.querySelectorAll('[data-nav]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const target = btn.getAttribute('data-nav');
      if (target) window.location.href = target;
    });
  });

  // Show access denied alert if redirected from service page
  if (showAccessDenied) {
    setTimeout(() => {
      showAccessDeniedAlert(accessDeniedStatus);
    }, 500);
  }

  function showAccessDeniedAlert(overrideStatus = null) {
    const statusToShow = overrideStatus || userStatus;
    // Create alert element
    const alert = document.createElement('div');
    alert.className = 'access-denied-alert';
    alert.innerHTML = `
      <div class="alert-content">
        <i class="fa-solid fa-lock alert-icon"></i>
        <div class="alert-text">
          <h4>Access Restricted</h4>
          <p>Your account status is <strong>${statusToShow.charAt(0).toUpperCase() + statusToShow.slice(1)}</strong>. 
             Service access requires account approval. Please contact barangay administration for assistance.</p>
        </div>
        <button class="alert-close" onclick="closeAccessAlert()">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </div>
    `;

    document.body.appendChild(alert);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
      if (alert.parentNode) {
        alert.parentNode.removeChild(alert);
      }
    }, 5000);
  }

  function closeAccessAlert() {
    const alert = document.querySelector('.access-denied-alert');
    if (alert) {
      alert.parentNode.removeChild(alert);
    }
  }

  /* ─── Tab switching ─── */
  function switchTab(btn, id) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.add('hidden'));
    document.getElementById('tab-' + id).classList.remove('hidden');
  }

  /* ─── Modal ─── */
  function openModal(data) {
    // Name + updated
    document.getElementById('mName').textContent    = data.name    || ' – ';
    document.getElementById('mUpdated').textContent = data.updated || ' – ';
    document.getElementById('mType').textContent    = data.type    || ' – ';
    document.getElementById('mPurpose').textContent = data.purpose || ' – ';

    // Requirements list
    const ul = document.getElementById('mRequirements');
    ul.innerHTML = '';
    (data.requirements || []).forEach(r => {
      const li = document.createElement('li');
      li.textContent = r;
      ul.appendChild(li);
    });

    // Status badge
    const statusMap = {
      approved:  { cls: 'badge-approved', icon: 'fa-circle-check',    label: 'Approved'    },
      pending:   { cls: 'badge-pending',  icon: 'fa-clock',           label: 'Pending'     },
      active:    { cls: 'badge-active',   icon: 'fa-circle-dot',      label: 'Active'      },
      cancelled: { cls: 'badge-cancelled',icon: 'fa-circle-xmark',    label: 'Cancelled'   },
    };
    const s = statusMap[data.status] || { cls: 'badge-na', icon: 'fa-circle', label: data.status || ' – ' };
    document.getElementById('mStatus').innerHTML =
      `<span class="badge ${s.cls}"><i class="fa-solid ${s.icon} text-[10px]"></i> ${s.label}</span>`;

    // Eligibility (only for beneficiary applications)
    const eligibilityRow = document.getElementById('eligibilityRow');
    const eligibilityDiv = document.getElementById('mEligibility');
    if (data.requestType === 'beneficiary' && data.eligibility) {
      const eligiblePrograms = [];
      
      const age = parseInt(data.eligibility._age || 0);
      const score = parseInt(data.eligibility.prio_score || 0);
      
      // ─── 4Ps: specific housing, utility, pregnant, income conditions
      const bad_house = ['informal_settler', 'shared', 'government_housing'].includes((data.eligibility.housing_status || '').toLowerCase());
      const bad_mat = ['light_materials', 'makeshift', 'wood'].includes((data.eligibility.house_material || '').toLowerCase());
      const bad_elec = ['shared', 'no_electricity'].includes((data.eligibility.electricity || '').toLowerCase());
      const bad_water = (data.eligibility.water_source || '').toLowerCase() === 'shared_well';
      const bad_toilet = ['none_pit', 'shared_public'].includes((data.eligibility.toilet_type || '').toLowerCase());
      const preg_child = data.eligibility.pregnant_or_children == 1;
      const income = parseFloat(data.eligibility.monthly_income || 0);
      
      if (bad_house && bad_mat && bad_elec && bad_water && bad_toilet && preg_child && income < 14000) {
          eligiblePrograms.push({ name: "4P's", eligible: true });
      }
      
      // ─── Senior Citizen: age >= 60
      if (age >= 60) {
          eligiblePrograms.push({ name: "Senior Citizen", eligible: true });
      }
      
      // ─── Scholarship: not empty school, have year level, gpa 1.00 - 1.75
      const school = (data.eligibility.school_name || '').trim();
      const yrLvl = (data.eligibility.year_level || '').trim();
      const gwaStr = (data.eligibility.gwa_gpa || '').trim();
      const gwaFloat = gwaStr !== '' ? parseFloat(gwaStr) : null;
      
      if (school !== '' && yrLvl !== '' && gwaFloat !== null && gwaFloat >= 1.00 && gwaFloat <= 1.75) {
          eligiblePrograms.push({ name: "Scholarship", eligible: true });
      }
      
      // ─── PWD: is_pwd = 1 AND has valid ID number
      if (data.eligibility.is_pwd == 1 && data.eligibility.pwd_id_number) {
          eligiblePrograms.push({ name: "PWD Program", eligible: true });
      }
      
      // ─── Kabataan/SK: age 15–30
      if (age >= 15 && age <= 30) {
          eligiblePrograms.push({ name: "Kabataan/SK", eligible: true });
      }
      
      // ─── Registered Voters: age >= 18
      if (age >= 18) {
          eligiblePrograms.push({ name: "Registered Voters", eligible: true });
      }

      // Display eligibility tags
      eligibilityDiv.innerHTML = '';
      if (eligiblePrograms.length > 0) {
        eligiblePrograms.forEach(program => {
          const tag = document.createElement('span');
          tag.className = `eligibility-tag ${program.eligible ? 'eligible' : 'not-eligible'}`;
          tag.innerHTML = `<i class="fa-solid ${program.eligible ? 'fa-check' : 'fa-xmark'} text-[8px]"></i> ${program.name}`;
          eligibilityDiv.appendChild(tag);
        });
      } else {
        const tag = document.createElement('span');
        tag.className = 'eligibility-tag not-eligible';
        tag.innerHTML = '<i class="fa-solid fa-question text-[8px]"></i> Eligibility being reviewed';
        eligibilityDiv.appendChild(tag);
      }
      
      eligibilityRow.style.display = 'block';
    } else {
      eligibilityRow.style.display = 'none';
    }

    // Remark
    document.getElementById('mRemarkText').textContent = data.remark || '';
    document.getElementById('mRemark').style.display   = data.remark ? 'flex' : 'none';

    // Open
    document.getElementById('appModal').classList.add('open');
    document.body.style.overflow = 'hidden';
  }

  function closeModal() {
    document.getElementById('appModal').classList.remove('open');
    document.body.style.overflow = '';
  }

  function closeModalOnOverlay(e) {
    if (e.target === document.getElementById('appModal')) closeModal();
  }

  // Close on Escape
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

  function toggleProfileMenu() {
    const dropdown = document.getElementById('profile-dropdown');
    const btn      = document.getElementById('profile-btn');
    const chevron  = document.getElementById('profile-chevron');
    const isOpen   = !dropdown.classList.contains('hidden');
    dropdown.classList.toggle('hidden', isOpen);
    btn.setAttribute('aria-expanded', String(!isOpen));
    chevron.style.transform = isOpen ? '' : 'rotate(180deg)';
  }
  document.addEventListener('click', function (e) {
    const wrapper = document.getElementById('profile-menu-wrapper');
    if (wrapper && !wrapper.contains(e.target)) {
      document.getElementById('profile-dropdown').classList.add('hidden');
      document.getElementById('profile-btn').setAttribute('aria-expanded', 'false');
      document.getElementById('profile-chevron').style.transform = '';
    }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      document.getElementById('profile-dropdown').classList.add('hidden');
      document.getElementById('profile-btn').setAttribute('aria-expanded', 'false');
      document.getElementById('profile-chevron').style.transform = '';
    }
  });

  
  /* ─── Mobile sidebar ─── */
  const mobileOverlay = document.getElementById('mobile-sidebar-overlay');
  const mobileSidebar = document.getElementById('mobile-sidebar');
  const mobileOpenBtn = document.getElementById('mobile-menu-btn');
  const mobileCloseBtn = document.getElementById('mobile-menu-close');

  function openMobileSidebar() {
    mobileOverlay.classList.remove('hidden', 'opacity-0');
    mobileOverlay.classList.add('opacity-80');
    mobileSidebar.classList.remove('translate-x-full');
    document.body.style.overflow = 'hidden';
  }
  function closeMobileSidebar() {
    mobileOverlay.classList.add('opacity-0');
    mobileSidebar.classList.add('translate-x-full');
    document.body.style.overflow = '';
    setTimeout(() => mobileOverlay.classList.add('hidden'), 250);
  }

  mobileOpenBtn?.addEventListener('click', openMobileSidebar);
  mobileCloseBtn?.addEventListener('click', closeMobileSidebar);
  mobileOverlay?.addEventListener('click', closeMobileSidebar);
</script>
</div>
</div>
</body>
</html>
