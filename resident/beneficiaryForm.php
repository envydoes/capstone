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

// Check user account status for service access
$accId = $_SESSION['acc_id'] ?? '';
$userStatus = 'pending';
if ($accId !== '') {
    $statusStmt = $conn->prepare('SELECT userStatus FROM tbl_userinfo WHERE accID = ? LIMIT 1');
    if ($statusStmt) {
        $statusStmt->bind_param('s', $accId);
        $statusStmt->execute();
        $result = $statusStmt->get_result();
        $row = $result->fetch_assoc();
        if ($row) {
            $userStatus = strtolower($row['userStatus'] ?? 'pending');
        }
        $statusStmt->close();
    }
}

$canAccessServices = ($userStatus === 'approved' || $userStatus === 'active');
if (!$canAccessServices) {
    // Set session variable for alert and redirect back
    $_SESSION['access_denied'] = true;
    $_SESSION['access_denied_status'] = $userStatus;
    header('Location: residentPanel.php');
    exit;
}

// Check if user already has a pending/approved/active beneficiary application
$userId = 0;
if ($accId !== '') {
    $userStmt = $conn->prepare('SELECT userID FROM tbl_userinfo WHERE accID = ? LIMIT 1');
    if ($userStmt) {
        $userStmt->bind_param('s', $accId);
        $userStmt->execute();
        $result = $userStmt->get_result();
        $row = $result->fetch_assoc();
        if ($row) {
            $userId = $row['userID'];
        }
        $userStmt->close();
    }
}

$existingApplication = null;
if ($userId > 0) {
    $checkStmt = $conn->prepare('SELECT id, status, submitted_at FROM tbl_beneficiary WHERE userId = ? ORDER BY submitted_at DESC LIMIT 1');
    if ($checkStmt) {
        $checkStmt->bind_param('i', $userId);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        $existingApplication = $result->fetch_assoc();
        $checkStmt->close();
    }
}

// Check if user can submit a new application
$canSubmitApplication = true;
$applicationMessage = '';

if ($existingApplication) {
    $existingStatus = strtolower($existingApplication['status']);
    if ($existingStatus !== 'rejected' && $existingStatus !== 'cancelled') {
        $canSubmitApplication = false;
        $statusText = ucfirst($existingStatus);
        $applicationMessage = "You already have a beneficiary application with status: <strong>$statusText</strong>. You can only submit a new application if your previous request was rejected.";
    }
}

// Handle form submission  –  save all fields to session
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if user can submit application
    if (!$canSubmitApplication) {
        $_SESSION['application_error'] = $applicationMessage;
        header('Location: beneficiaryForm.php');
        exit;
    }

    $_SESSION['beneficiary_form'] = [
        // Housing
        'housing_status'        => $_POST['housing_status']        ?? '',
        'house_material'        => $_POST['house_material']        ?? '',
        // Utility access
        'electricity'           => $_POST['electricity']           ?? '',
        'water_source'          => $_POST['water_source']          ?? '',
        'toilet_type'           => $_POST['toilet_type']           ?? '',
        // Pregnant / children
        'pregnant_or_children'  => $_POST['pregnant_or_children']  ?? '',
        // Specific classification
        'is_pwd'                => isset($_POST['is_pwd']) ? 1 : 0,
        'pwd_id_number'         => $_POST['pwd_id_number']         ?? '',
        'is_solo_parent'        => isset($_POST['is_solo_parent']) ? 1 : 0,
        'is_indigenous'         => isset($_POST['is_indigenous']) ? 1 : 0,
        // Pension
        'pension_status'        => $_POST['pension_status']        ?? '',
        // Health
        'health_hypertension'   => isset($_POST['health_hypertension']) ? 1 : 0,
        'health_diabetes'       => isset($_POST['health_diabetes']) ? 1 : 0,
        'health_asthma'         => isset($_POST['health_asthma']) ? 1 : 0,
        'health_other'          => isset($_POST['health_other']) ? 1 : 0,
        'health_other_specify'  => $_POST['health_other_specify']  ?? '',
        'health_none'           => isset($_POST['health_none']) ? 1 : 0,
        // Maintenance medicine
        'requires_medicine'     => $_POST['requires_medicine']     ?? '',
        'medicine_name'         => $_POST['medicine_name']         ?? '',
        // Student info
        'school_name'           => $_POST['school_name']           ?? '',
        'course'                => $_POST['course']                ?? '',
        'year_level'            => $_POST['year_level']            ?? '',
        'gwa_gpa'               => $_POST['gwa_gpa']               ?? '',
        // Meta
        'submitted_at'          => date('Y-m-d H:i:s'),
    ];

    // Redirect to save beneficiary script
    header('Location: saveBeneficiary.php');
    exit;
}

$logged_in = isset($_SESSION['user_id']);
$userEmail  = $_SESSION['user_id']  ?? '';
$accId      = $_SESSION['acc_id']   ?? '';
$userName   = $userEmail;
$stmt = $conn->prepare('SELECT firstname FROM tbl_userinfo WHERE accID = ? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('s', $accId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    if ($row && !empty($row['firstname'])) { $userName = $row['firstname']; }
    $stmt->close();
}

$roleLabel = match($role) {
    'admin'        => 'Admin',
    'resident'     => 'Resident',
    'resident,business/apartment owner' => 'Resident & Owner',
    'non-resident' => 'Non-Resident',
    'non-resident,business/apartment owner' => 'Non-Resident & Owner',
    'business/apartment owner' => 'Owner',
    default        => 'User',
};

// Toast status from saveBeneficiary endpoint
$toastStatus = $_SESSION['beneficiary_save_status'] ?? '';
$toastMessage = $_SESSION['beneficiary_save_msg'] ?? '';
unset($_SESSION['beneficiary_save_status'], $_SESSION['beneficiary_save_msg'], $_SESSION['beneficiary_prio_score']);

// Handle application error message
$applicationError = $_SESSION['application_error'] ?? '';
unset($_SESSION['application_error']);

// Status check and conditionally fetch score & eligibility if pending or approved
$showEligibilityBox = false;
$eligibilityPrograms = [];
if ($existingApplication && in_array(strtolower($existingApplication['status']), ['pending', 'approved'])) {
    $showEligibilityBox = true;
    $computedScore = 0;
    
    // Load existing application details to get score and eligibility
    $appQuery = $conn->prepare("
        SELECT b.*, ui.birthday, ui.monthly_income 
        FROM tbl_beneficiary b 
        JOIN tbl_userinfo ui ON b.userId = ui.userID 
        WHERE b.id = ? LIMIT 1
    ");
    if ($appQuery) {
        $appQuery->bind_param('i', $existingApplication['id']);
        $appQuery->execute();
        $appRow = $appQuery->get_result()->fetch_assoc();
        if ($appRow) {
            $computedScore = $appRow['prio_score'] ?? 0;
            
            // Re-run the exact eligibility logic from admin module
            $age = 0;
            if (!empty($appRow['birthday'])) {
                $bday = new DateTime($appRow['birthday']);
                $age  = (new DateTime())->diff($bday)->y;
            }
            
            $bad_house   = in_array(strtolower($appRow['housing_status'] ?? ''), ['informal_settler', 'shared', 'government_housing']);
            $bad_mat     = in_array(strtolower($appRow['house_material'] ?? ''), ['light_materials', 'makeshift', 'wood']);
            $bad_elec    = in_array(strtolower($appRow['electricity'] ?? ''), ['shared', 'no_electricity']);
            $bad_water   = (strtolower($appRow['water_source'] ?? '') === 'shared_well');
            $bad_toilet  = in_array(strtolower($appRow['toilet_type'] ?? ''), ['none_pit', 'shared_public']);
            $preg_child  = !empty($appRow['pregnant_or_children']) && $appRow['pregnant_or_children'] == 1;
            $income      = (float)($appRow['monthly_income'] ?? 0);
            if ($bad_house && $bad_mat && $bad_elec && $bad_water && $bad_toilet && $preg_child && $income < 14000) {
                $eligibilityPrograms[] = '4P\'s';
            }
            if ($age >= 60) $eligibilityPrograms[] = 'Senior Citizen';
            
            $school = trim($appRow['school_name'] ?? '');
            $yrLvl = trim($appRow['year_level'] ?? '');
            $gwaStr = trim($appRow['gwa_gpa'] ?? '');
            $gwaFloat = $gwaStr !== '' ? (float)$gwaStr : null;
            if ($school !== '' && $yrLvl !== '' && $gwaFloat !== null && $gwaFloat >= 1.00 && $gwaFloat <= 1.75) {
                $eligibilityPrograms[] = 'Scholarship';
            }
            if (!empty($appRow['is_pwd']) && $appRow['is_pwd'] == 1 && !empty($appRow['pwd_id_number'])) {
                $eligibilityPrograms[] = 'PWD';
            }
            if ($age >= 15 && $age <= 30) $eligibilityPrograms[] = 'Kabataan (SK)';
            if ($age >= 18) $eligibilityPrograms[] = 'For Voters';
        }
        $appQuery->close();
    }
}

$roleBadgeClass = match($role) {
    'admin'        => 'bg-purple-100 text-purple-700 border border-purple-200',
    'resident'     => 'bg-green-100 text-green-700 border border-green-200',
    'resident,business/apartment owner' => 'bg-green-100 text-green-700 border border-green-200',
    default        => 'bg-gray-100 text-gray-600 border border-gray-200',
};
$initials = strtoupper(substr($userName, 0, 2));

// Pre-fill from session if exists
$saved = $_SESSION['beneficiary_form'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="../assets/responsive-global.css">
  <title>Beneficiary Form  –  <?= e($siteSettings['site_title']) ?></title>
  <link rel="icon" href="<?= e(site_config_logo_url($siteSettings, '../')) ?>" type="image/png">
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

    /* Banner placeholder */
    .banner-placeholder {
      width: 100%; height: 160px;
      background: linear-gradient(135deg, var(--site-primary-dark) 0%, var(--site-primary) 50%, var(--site-primary-light) 100%);
      border-radius: 16px;
      display: flex; align-items: center; justify-content: center;
      overflow: hidden; position: relative;
    }
    .banner-placeholder::before {
      content: '';
      position: absolute; inset: 0;
      background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.06'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    }
    .banner-placeholder .banner-inner {
      display: flex; align-items: center; gap: 16px; z-index: 1;
    }
    .banner-icon {
      width: 64px; height: 64px; background: rgba(255,255,255,0.15);
      border-radius: 16px; display: flex; align-items: center; justify-content: center;
      border: 1.5px solid rgba(255,255,255,0.2);
    }

    /* Form card */
    .form-card {
      background: #fff; border: 1px solid #e5e7eb;
      border-radius: 18px; padding: 32px;
      box-shadow: 0 2px 20px rgba(var(--site-primary-rgb),0.06);
    }

    /* Section divider */
    .form-section-title {
      font-size: 0.72rem; font-weight: 700; color: #6b7280;
      text-transform: uppercase; letter-spacing: 0.08em;
      padding-bottom: 8px; border-bottom: 1px solid #e5e7eb;
      margin-bottom: 20px;
    }

    /* Labels */
    .field-label {
      display: block; font-size: 0.875rem; font-weight: 600;
      color: #374151; margin-bottom: 8px;
    }
    .field-label .req { color: #dc2626; margin-left: 2px; }

    /* Inputs / Selects */
    .form-input, .form-select {
      width: 100%; padding: 10px 14px;
      border: 1.5px solid #d1d5db; border-radius: 10px;
      font-size: 0.875rem; font-family: 'DM Sans', sans-serif;
      color: #374151; background: #fff;
      transition: border-color 0.18s, box-shadow 0.18s;
      outline: none;
    }
    .form-input:focus, .form-select:focus {
      border-color: var(--site-primary);
      box-shadow: 0 0 0 3px rgba(var(--site-primary-rgb),0.12);
    }
    .form-input::placeholder { color: #9ca3af; }
    .form-select { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; padding-right: 36px; cursor: pointer; }

    /* Radio & Checkbox groups */
    .radio-group, .check-group { display: flex; flex-wrap: wrap; gap: 6px 20px; }
    .radio-option, .check-option {
      display: flex; align-items: center; gap: 8px;
      cursor: pointer; font-size: 0.875rem; color: #374151;
      padding: 8px 14px; border-radius: 8px; border: 1.5px solid #e5e7eb;
      background: #f9fafb; transition: all 0.15s; user-select: none;
    }
    .radio-option:hover, .check-option:hover { border-color: var(--site-primary); background: var(--site-primary-pale); }
    .radio-option input[type="radio"],
    .check-option input[type="checkbox"] { accent-color: var(--site-primary); width: 15px; height: 15px; }

    /* Utility group box */
    .utility-box {
      border: 1.5px solid #e5e7eb; border-radius: 12px;
      padding: 14px 16px; background: #fafafa;
    }
    .utility-box-label { font-size: 0.8rem; font-weight: 600; color: #6b7280; margin-bottom: 10px; }

    /* PWD inline input */
    .pwd-inline { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .pwd-id-input {
      flex: 1; min-width: 160px;
      padding: 8px 12px; border: 1.5px solid #d1d5db; border-radius: 8px;
      font-size: 0.84rem; font-family: 'DM Sans', sans-serif; color: #374151;
      transition: border-color 0.18s, box-shadow 0.18s; outline: none;
    }
    .pwd-id-input:focus { border-color: var(--site-primary); box-shadow: 0 0 0 3px rgba(var(--site-primary-rgb),0.12); }
    .pwd-id-input::placeholder { color: #9ca3af; }
    .pwd-id-input:disabled { background: #f3f4f6; color: #9ca3af; cursor: not-allowed; }

    /* Medicine inline */
    .medicine-inline { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-top: 8px; }
    .medicine-input {
      flex: 1; min-width: 180px;
      padding: 9px 13px; border: 1.5px solid #d1d5db; border-radius: 8px;
      font-size: 0.84rem; font-family: 'DM Sans', sans-serif; color: #374151;
      transition: border-color 0.18s; outline: none;
    }
    .medicine-input:focus { border-color: var(--site-primary); box-shadow: 0 0 0 3px rgba(var(--site-primary-rgb),0.12); }
    .medicine-input::placeholder { color: #9ca3af; }
    .medicine-input:disabled { background: #f3f4f6; color: #9ca3af; cursor: not-allowed; }

    /* Submit button */
    .btn-submit {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 12px 32px; background: var(--site-primary-dark);
      color: #fff; font-size: 0.9rem; font-weight: 700;
      border: none; border-radius: 12px; cursor: pointer;
      transition: background 0.18s, transform 0.15s, box-shadow 0.18s;
      box-shadow: 0 4px 16px rgba(var(--site-primary-rgb),0.2);
    }
    .btn-submit:hover { background: var(--site-primary-darker); transform: translateY(-1px); box-shadow: 0 6px 20px rgba(var(--site-primary-rgb),0.28); }
    .btn-submit:active { transform: translateY(0); }

    /* Back link */
    .back-link {
      display: inline-flex; align-items: center; gap: 6px;
      color: #6b7280; font-size: 0.84rem; font-weight: 500;
      text-decoration: none; transition: color 0.15s;
    }
    .back-link:hover { color: var(--site-primary-dark); }

    /* Animations */
    @keyframes fadeUp { from { opacity:0; transform:translateY(16px); } to { opacity:1; transform:translateY(0); } }
    .f1 { animation: fadeUp 0.4s 0.05s ease both; }
    .f2 { animation: fadeUp 0.4s 0.12s ease both; }
    .f3 { animation: fadeUp 0.4s 0.19s ease both; }
    .f4 { animation: fadeUp 0.4s 0.26s ease both; }
    .f5 { animation: fadeUp 0.4s 0.33s ease both; }

    /* Error state */
    .field-error { border-color: #dc2626 !important; }
    .error-msg { font-size: 0.75rem; color: #dc2626; margin-top: 4px; display: none; }
    .error-msg.show { display: block; }

    #alertBanner { display: none; border-radius: 10px; margin-bottom: 1rem; }
    #alertBanner.show { display: flex; }
    .alert-inner { display: flex; align-items: center; gap: 10px; padding: 13px 16px; font-size: 0.85rem; font-weight: 600; border-radius: 10px; border: 1.5px solid transparent; width: 100%; flex-wrap: wrap; }
    .alert-success { background: var(--site-primary-pale); border-color: var(--site-primary-light); color: var(--site-primary-dark); }
    .alert-error { background: #fef2f2; border-color: #fecaca; color: #dc2626; }
    .alert-warning { background: #fefce8; border-color: #fde68a; color: #a16207; }
    .alert-close { margin-left: auto; background: none; border: none; cursor: pointer; font-size: 0.8rem; opacity: 0.6; color: inherit; padding: 2px 4px; }
    .alert-close:hover { opacity: 1; }

  </style>

  <style>
    :root {
      --site-primary-dark:   color-mix(in srgb, var(--site-primary) 55%, black);
      --site-primary-darker: color-mix(in srgb, var(--site-primary) 75%, black);
      --site-primary-light:  color-mix(in srgb, var(--site-primary) 55%, white);
      --site-primary-pale:   color-mix(in srgb, var(--site-primary) 12%, white);
    }

    /* Tailwind-green -> theme color overrides (matches adminLanding.php) */
    .text-green-400 { color: var(--site-primary-light) !important; }
    .bg-green-700 { background-color: var(--site-primary) !important; }
    .hover\:bg-green-800:hover { background-color: var(--site-primary-dark) !important; }
    .text-green-700, .text-green-600, .text-green-500 { color: var(--site-primary) !important; }
    .text-green-900, .text-green-950 { color: var(--site-primary-darker) !important; }
    .bg-green-950 { background-color: var(--site-primary-darker) !important; }
    .border-green-100 { border-color: color-mix(in srgb, var(--site-primary) 20%, white) !important; }
    .bg-green-50 { background-color: var(--site-primary-pale) !important; }
    .hover\:border-green-300:hover { border-color: var(--site-primary-light) !important; }
    .hover\:bg-green-50:hover { background-color: var(--site-primary-pale) !important; }
    .focus\:ring-green-400:focus { --tw-ring-color: var(--site-primary-light) !important; }
    .from-green-50 { --tw-gradient-from: var(--site-primary-pale) !important; }
    .hover\:text-green-700:hover { color: var(--site-primary) !important; }
    .hover\:text-green-800:hover { color: var(--site-primary-darker) !important; }

    footer .text-green-300 { color: rgba(255,255,255,0.75) !important; }
    footer .text-green-400 { color: rgba(255,255,255,0.65) !important; }
    footer .text-green-500 { color: rgba(255,255,255,0.45) !important; }
    footer .hover\:text-white:hover { color: #ffffff !important; }
    footer .border-green-800 { border-color: rgba(255,255,255,0.12) !important; }
  </style>
</head>
<body>
<div class="min-h-screen">

<!-- NAVBAR -->
<header class="w-full h-[68px] border-b border-green-100 flex items-center px-4 sm:px-6 lg:px-8 bg-white shadow-sm sticky top-0 z-50">
  <div class="flex items-center gap-3 flex-shrink-0">
    <a href="residentLanding.php" class="flex items-center gap-3">
      <div class="w-10 h-10 rounded-full bg-green-700 flex items-center justify-center shadow overflow-hidden">
        <img src="<?= e(site_config_logo_url($siteSettings, '../')) ?>" alt="Logo" class="w-full h-full object-contain" />
      </div>
      <div>
        <h3 class="font-bold text-green-900 text-base leading-tight" style="font-family:'DM Sans',sans-serif;"><?= e($siteSettings['site_title']) ?></h3>
        <p class="text-[10px] text-green-600 tracking-widest uppercase"><?= e($siteSettings['barangay_name']) ?></p>
      </div>
    </a>
  </div>
  <nav class="ml-auto flex items-center gap-3 md:gap-6 text-gray-600 text-sm font-medium">

    <!-- Desktop Nav -->
    <div class="hidden md:flex items-center gap-5 lg:gap-8">
      <a href="residentPanel.php" class="nav-link">My Panel</a>
      <a href="residentLanding.php#announcements" class="nav-link">Announcements</a>
      <a href="../busaptListing.php?type=business" class="nav-link">Business</a>
      <a href="../busaptListing.php?type=apartment" class="nav-link">Apartments</a>
      <?php if ($logged_in): ?>
        <div class="relative" id="profile-menu-wrapper">
          <button id="profile-btn" onclick="toggleProfileMenu()"
            class="flex items-center gap-2 px-3 py-1.5 rounded-xl border border-gray-200 hover:border-green-300 hover:bg-green-50 transition focus:outline-none focus:ring-2 focus:ring-green-400"
            aria-haspopup="true" aria-expanded="false">
            <span class="w-8 h-8 rounded-full bg-green-700 text-white flex items-center justify-center text-xs font-bold select-none">
              <?php echo htmlspecialchars($initials); ?>
            </span>
            <span class="hidden lg:block text-gray-700 text-sm max-w-[140px] truncate">
              <?php echo htmlspecialchars($userName); ?>
            </span>
            <svg id="profile-chevron" class="w-4 h-4 text-gray-400 transition-transform duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
            </svg>
          </button>
          <div id="profile-dropdown" class="hidden absolute right-0 mt-2 w-64 bg-white rounded-2xl shadow-xl border border-gray-100 overflow-hidden z-50" role="menu">
            <div class="px-4 py-3 bg-gradient-to-br from-green-50 to-emerald-50 border-b border-gray-100">
              <div class="flex items-center gap-3">
                <span class="w-10 h-10 rounded-full bg-green-700 text-white flex items-center justify-center text-sm font-bold select-none flex-shrink-0">
                  <?php echo htmlspecialchars($initials); ?>
                </span>
                <div class="min-w-0">
                  <p class="text-sm font-semibold text-gray-800 truncate"><?php echo htmlspecialchars($userName); ?></p>
                  <span class="inline-block mt-0.5 px-2 py-0.5 rounded-full text-[11px] font-semibold <?php echo $roleBadgeClass; ?>">
                    <?php echo htmlspecialchars($roleLabel); ?>
                  </span>
                </div>
              </div>
            </div>
            <div class="py-1">
              <a href="myProfile.php" role="menuitem" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-green-50 hover:text-green-800 transition">
                <i class="fa-solid fa-user w-4 text-gray-400"></i> My Profile
              </a>
            </div>
            <div class="border-t border-gray-100 py-1">
              <a href="../logout.php" role="menuitem" class="flex items-center gap-3 px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition">
                <i class="fa-solid fa-arrow-right-from-bracket w-4"></i> Logout
              </a>
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
        <span class="w-8 h-8 rounded-full text-white flex items-center justify-center text-xs font-bold" style="background:var(--site-primary)"><?php echo htmlspecialchars($initials); ?></span>
        <div class="min-w-0">
          <p class="text-sm font-semibold text-gray-800 truncate max-w-[140px]"><?php echo htmlspecialchars($userName); ?></p>
          <span class="text-[10px] font-semibold <?php echo $roleBadgeClass; ?> px-1.5 py-0.5 rounded-full"><?php echo htmlspecialchars($roleLabel); ?></span>
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
    <a href="residentPanel.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 font-medium hover:bg-[var(--site-primary-pale)] hover:text-[var(--site-primary-dark)] transition">
      <i class="fa-solid fa-gauge-high w-4 text-[var(--site-primary)]"></i> My Panel
    </a>
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

<main class="max-w-2xl mx-auto px-4 py-10 space-y-6">

  <!-- Back link -->
  <div class="f1">
    <a href="residentPanel" class="back-link">
      <i class="fa-solid fa-arrow-left text-xs"></i> Back
    </a>
  </div>

  <!-- Banner -->
  <div class="banner-placeholder f1">
    <div class="banner-inner">
      <div class="banner-icon">
        <svg viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg" class="w-9 h-9">
          <rect x="8" y="6" width="24" height="28" rx="3" stroke="white" stroke-width="2" fill="none"/>
          <rect x="14" y="3" width="12" height="6" rx="2" stroke="white" stroke-width="2" fill="none"/>
          <path d="M14 20 l4 4 8-8" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </div>
      <div>
        <p class="text-white font-bold text-lg leading-tight" style="font-family:'Playfair Display',serif;">Barangay Beneficiary</p>
        <p class="text-green-200 text-xs mt-1">Assistance programs for qualified residents</p>
      </div>
    </div>
  </div>

  <div id="alertBanner" data-toast-status="<?php echo htmlspecialchars($toastStatus); ?>" data-toast-message="<?php echo htmlspecialchars($toastMessage); ?>">
    <div class="alert-inner" id="alertInner">
      <i id="alertIcon" class="fa-solid fa-circle-check" style="font-size:1rem;flex-shrink:0;"></i>
      <div><span id="alertTitle" style="font-weight:700;"></span><span id="alertDesc" style="font-weight:400;margin-left:6px;opacity:.85;"></span></div>
      <button class="alert-close" onclick="dismissAlert()"><i class="fa-solid fa-xmark"></i></button>
    </div>
  </div>

  <!-- Form card -->
  <div class="form-card f2">
    <h2 class="text-xl font-bold text-green-950 text-center mb-1" style="font-family:'Playfair Display',serif;"><?= e($siteSettings['site_title']) ?> Beneficiary Listing</h2>
    <p class="text-xs text-gray-500 text-center mb-8">Fill out all required fields marked with <span class="text-red-500 font-bold">*</span></p>

    <?php if (!$canSubmitApplication): ?>
    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
      <div class="flex items-start gap-3">
        <i class="fa-solid fa-exclamation-triangle text-yellow-600 text-lg flex-shrink-0 mt-0.5"></i>
        <div>
          <h3 class="text-yellow-800 font-semibold text-sm mb-1">Application Submission Restricted</h3>
          <p class="text-yellow-700 text-sm"><?php echo $applicationMessage; ?></p>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($applicationError): ?>
    <div style="background-color: #fef2f2; border-left: 4px solid #ef4444; padding: 1rem; margin-bottom: 1.5rem; border-radius: 0.375rem;">
      <div style="display: flex;">
        <div style="flex-shrink: 0;"><i class="fa-solid fa-circle-xmark" style="color: #ef4444;"></i></div>
        <div style="margin-left: 0.75rem;"><p style="font-size: 0.875rem; color: #b91c1c;"><?php echo htmlspecialchars($applicationError); ?></p></div>
      </div>
    </div>
    <?php endif; ?>

    <form method="POST" action="" id="beneficiaryForm" novalidate <?php echo !$canSubmitApplication ? 'style="pointer-events: none; opacity: 0.6;"' : ''; ?>>

      <!-- ──────────── HOUSING ──────────── -->
      <p class="form-section-title">Housing Information</p>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mb-6">

        <!-- Housing Status -->
        <div>
          <label class="field-label" for="housing_status">
            Housing Status <span class="req">*</span>
          </label>
          <select class="form-select" id="housing_status" name="housing_status" required>
            <option value="" disabled <?= empty($saved['housing_status']) ? 'selected' : '' ?>>-- Select --</option>
            <option value="owned" <?= ($saved['housing_status'] ?? '') === 'owned' ? 'selected' : '' ?>>Owned</option>
            <option value="renting" <?= ($saved['housing_status'] ?? '') === 'renting' ? 'selected' : '' ?>>Renting</option>
            <option value="shared" <?= ($saved['housing_status'] ?? '') === 'shared' ? 'selected' : '' ?>>Shared / Living with Relatives</option>
            <option value="informal_settler" <?= ($saved['housing_status'] ?? '') === 'informal_settler' ? 'selected' : '' ?>>Informal Settler</option>
            <option value="government_housing" <?= ($saved['housing_status'] ?? '') === 'government_housing' ? 'selected' : '' ?>>Government Housing</option>
          </select>
          <p class="error-msg" id="err_housing_status">Please select a housing status.</p>
        </div>

        <!-- House Material -->
        <div>
          <label class="field-label" for="house_material">
            What is the primary material of your house? <span class="req">*</span>
          </label>
          <select class="form-select" id="house_material" name="house_material" required>
            <option value="" disabled <?= empty($saved['house_material']) ? 'selected' : '' ?>>-- Select --</option>
            <option value="concrete" <?= ($saved['house_material'] ?? '') === 'concrete' ? 'selected' : '' ?>>Concrete / Hollow Blocks</option>
            <option value="wood" <?= ($saved['house_material'] ?? '') === 'wood' ? 'selected' : '' ?>>Wood</option>
            <option value="mixed" <?= ($saved['house_material'] ?? '') === 'mixed' ? 'selected' : '' ?>>Mixed (Concrete + Wood)</option>
            <option value="light_materials" <?= ($saved['house_material'] ?? '') === 'light_materials' ? 'selected' : '' ?>>Light Materials (Bamboo, Cogon)</option>
            <option value="makeshift" <?= ($saved['house_material'] ?? '') === 'makeshift' ? 'selected' : '' ?>>Makeshift / Scrap Materials</option>
          </select>
          <p class="error-msg" id="err_house_material">Please select a house material.</p>
        </div>

      </div>

      <!-- ──────────── UTILITY ACCESS ──────────── -->
      <p class="form-section-title">Utility Access</p>

      <div class="space-y-4 mb-6">

        <!-- Electricity -->
        <div class="utility-box">
          <p class="utility-box-label">Electricity:</p>
          <div class="radio-group">
            <?php $elec = $saved['electricity'] ?? ''; ?>
            <label class="radio-option">
              <input type="radio" name="electricity" value="shared" <?= $elec === 'shared' ? 'checked' : '' ?> required>
              Shared
            </label>
            <label class="radio-option">
              <input type="radio" name="electricity" value="own_meter" <?= $elec === 'own_meter' ? 'checked' : '' ?>>
              Own Meter
            </label>
            <label class="radio-option">
              <input type="radio" name="electricity" value="no_electricity" <?= $elec === 'no_electricity' ? 'checked' : '' ?>>
              No Electricity
            </label>
          </div>
          <p class="error-msg" id="err_electricity">Please select an electricity option.</p>
        </div>

        <!-- Water Source -->
        <div class="utility-box">
          <p class="utility-box-label">Water Source:</p>
          <div class="radio-group">
            <?php $water = $saved['water_source'] ?? ''; ?>
            <label class="radio-option">
              <input type="radio" name="water_source" value="piped_faucet" <?= $water === 'piped_faucet' ? 'checked' : '' ?> required>
              Piped / Faucet
            </label>
            <label class="radio-option">
              <input type="radio" name="water_source" value="shared_well" <?= $water === 'shared_well' ? 'checked' : '' ?>>
              Shared Well
            </label>
            <label class="radio-option">
              <input type="radio" name="water_source" value="bought_mineral" <?= $water === 'bought_mineral' ? 'checked' : '' ?>>
              Bought / Mineral
            </label>
          </div>
          <p class="error-msg" id="err_water_source">Please select a water source.</p>
        </div>

        <!-- Toilet Type -->
        <div class="utility-box">
          <p class="utility-box-label">Toilet Type:</p>
          <div class="radio-group">
            <?php $toilet = $saved['toilet_type'] ?? ''; ?>
            <label class="radio-option">
              <input type="radio" name="toilet_type" value="private_flush" <?= $toilet === 'private_flush' ? 'checked' : '' ?> required>
              Private Flush
            </label>
            <label class="radio-option">
              <input type="radio" name="toilet_type" value="shared_public" <?= $toilet === 'shared_public' ? 'checked' : '' ?>>
              Shared / Public
            </label>
            <label class="radio-option">
              <input type="radio" name="toilet_type" value="none_pit" <?= $toilet === 'none_pit' ? 'checked' : '' ?>>
              None / Pit
            </label>
          </div>
          <p class="error-msg" id="err_toilet_type">Please select a toilet type.</p>
        </div>

      </div>

      <!-- ──────────── PREGNANT / CHILDREN ──────────── -->
      <p class="form-section-title">Household Composition</p>

      <div class="mb-6">
        <label class="field-label">
          Are there any pregnant women or children under 5 in the house? <span class="req">*</span>
        </label>
        <div class="radio-group">
          <?php $preg = $saved['pregnant_or_children'] ?? ''; ?>
          <label class="radio-option">
            <input type="radio" name="pregnant_or_children" value="yes" <?= $preg === 'yes' ? 'checked' : '' ?> required>
            Yes
          </label>
          <label class="radio-option">
            <input type="radio" name="pregnant_or_children" value="no" <?= $preg === 'no' ? 'checked' : '' ?>>
            No
          </label>
        </div>
        <p class="error-msg" id="err_pregnant">Please select an option.</p>
      </div>

      <!-- ──────────── SPECIFIC CLASSIFICATION ──────────── -->
      <p class="form-section-title">Specific Classification</p>

      <div class="space-y-3 mb-6">

        <!-- PWD -->
        <div class="pwd-inline">
          <label class="check-option" style="min-width:fit-content;">
            <input type="checkbox" id="is_pwd" name="is_pwd" value="1" onchange="togglePwdInput(this)" <?= !empty($saved['is_pwd']) ? 'checked' : '' ?>>
            Person with Disability (PWD)
          </label>
          <input
            type="text"
            class="pwd-id-input"
            id="pwd_id_number"
            name="pwd_id_number"
            placeholder="PWD ID Number"
            value="<?= htmlspecialchars($saved['pwd_id_number'] ?? '') ?>"
            <?= empty($saved['is_pwd']) ? 'disabled' : '' ?>
          >
        </div>

        <!-- Solo Parent -->
        <label class="check-option" style="width:fit-content;">
          <input type="checkbox" name="is_solo_parent" value="1" <?= !empty($saved['is_solo_parent']) ? 'checked' : '' ?>>
          Solo Parent
        </label>

        <!-- Indigenous -->
        <label class="check-option" style="width:fit-content;">
          <input type="checkbox" name="is_indigenous" value="1" <?= !empty($saved['is_indigenous']) ? 'checked' : '' ?>>
          Indigenous Person (IP)
        </label>

      </div>

      <!-- ──────────── PENSION STATUS ──────────── -->
      <p class="form-section-title">Pension Status</p>

      <div class="mb-6">
        <select class="form-select" id="pension_status" name="pension_status">
          <option value="" <?= empty($saved['pension_status']) ? 'selected' : '' ?>>-- Select --</option>
          <option value="sss" <?= ($saved['pension_status'] ?? '') === 'sss' ? 'selected' : '' ?>>SSS Pensioner</option>
          <option value="gsis" <?= ($saved['pension_status'] ?? '') === 'gsis' ? 'selected' : '' ?>>GSIS Pensioner</option>
          <option value="social_pension" <?= ($saved['pension_status'] ?? '') === 'social_pension' ? 'selected' : '' ?>>Social Pension (DSWD)</option>
          <option value="none" <?= ($saved['pension_status'] ?? '') === 'none' ? 'selected' : '' ?>>None</option>
        </select>
      </div>

      <!-- ──────────── HEALTH & MAINTENANCE ──────────── -->
      <p class="form-section-title">Health &amp; Maintenance Status</p>

      <div class="space-y-2 mb-3">
        <label class="check-option" style="width:fit-content;">
          <input type="checkbox" name="health_hypertension" value="1" <?= !empty($saved['health_hypertension']) ? 'checked' : '' ?>>
          Hypertension
        </label>
        <label class="check-option" style="width:fit-content;">
          <input type="checkbox" name="health_diabetes" value="1" <?= !empty($saved['health_diabetes']) ? 'checked' : '' ?>>
          Diabetes
        </label>
        <label class="check-option" style="width:fit-content;">
          <input type="checkbox" name="health_asthma" value="1" <?= !empty($saved['health_asthma']) ? 'checked' : '' ?>>
          Asthma
        </label>

        <!-- Other with specify input -->
        <div class="flex items-center gap-3 flex-wrap">
          <label class="check-option" style="width:fit-content;">
            <input type="checkbox" id="health_other" name="health_other" value="1" onchange="toggleOtherInput(this)" <?= !empty($saved['health_other']) ? 'checked' : '' ?>>
            Other
          </label>
          <input
            type="text"
            class="form-input"
            id="health_other_specify"
            name="health_other_specify"
            placeholder="Please specify"
            style="max-width:240px;"
            value="<?= htmlspecialchars($saved['health_other_specify'] ?? '') ?>"
            <?= empty($saved['health_other']) ? 'disabled style="max-width:240px; background:#f3f4f6;"' : '' ?>
          >
        </div>

        <label class="check-option" style="width:fit-content;">
          <input type="checkbox" name="health_none" value="1" <?= !empty($saved['health_none']) ? 'checked' : '' ?>>
          None
        </label>
      </div>

      <!-- ──────────── MAINTENANCE MEDICINE ──────────── -->
      <p class="form-section-title mt-6">Maintenance Medicine</p>

      <div class="mb-6">
        <label class="field-label">Do you require maintenance medicine?</label>
        <div class="medicine-inline">
          <?php $med = $saved['requires_medicine'] ?? ''; ?>
          <label class="radio-option">
            <input type="radio" name="requires_medicine" value="yes" id="med_yes" <?= $med === 'yes' ? 'checked' : '' ?> onchange="toggleMedicineInput()">
            Yes
          </label>
          <label class="radio-option">
            <input type="radio" name="requires_medicine" value="no" id="med_no" <?= $med === 'no' || empty($med) ? 'checked' : '' ?> onchange="toggleMedicineInput()">
            No
          </label>
          <input
            type="text"
            class="medicine-input"
            id="medicine_name"
            name="medicine_name"
            placeholder="Medicine name"
            value="<?= htmlspecialchars($saved['medicine_name'] ?? '') ?>"
            <?= ($saved['requires_medicine'] ?? 'no') !== 'yes' ? 'disabled' : '' ?>
          >
        </div>
      </div>

      <!-- ──────────── STUDENT INFORMATION ──────────── -->
      <p class="form-section-title">Student Information <span class="normal-case text-gray-400 font-normal">(if applicable)</span></p>

      <div class="mb-5">
        <label class="field-label" for="school_name">(For Student) Current School / University:</label>
        <input
          type="text"
          class="form-input"
          id="school_name"
          name="school_name"
          placeholder="Enter School Name"
          value="<?= htmlspecialchars($saved['school_name'] ?? '') ?>"
        >
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mb-5">
        <div>
          <label class="field-label" for="course">Course:</label>
          <input
            type="text"
            class="form-input"
            id="course"
            name="course"
            placeholder="Enter Course"
            value="<?= htmlspecialchars($saved['course'] ?? '') ?>"
          >
        </div>
        <div>
          <label class="field-label" for="year_level">Year Level:</label>
          <select class="form-select" id="year_level" name="year_level">
            <option value="" <?= empty($saved['year_level']) ? 'selected' : '' ?>>-- Select --</option>
            <option value="grade_1_6" <?= ($saved['year_level'] ?? '') === 'grade_1_6' ? 'selected' : '' ?>>Grade 1 – 6 (Elementary)</option>
            <option value="grade_7_10" <?= ($saved['year_level'] ?? '') === 'grade_7_10' ? 'selected' : '' ?>>Grade 7 – 10 (Junior High)</option>
            <option value="grade_11_12" <?= ($saved['year_level'] ?? '') === 'grade_11_12' ? 'selected' : '' ?>>Grade 11 – 12 (Senior High)</option>
            <option value="1st_year" <?= ($saved['year_level'] ?? '') === '1st_year' ? 'selected' : '' ?>>1st Year (College)</option>
            <option value="2nd_year" <?= ($saved['year_level'] ?? '') === '2nd_year' ? 'selected' : '' ?>>2nd Year</option>
            <option value="3rd_year" <?= ($saved['year_level'] ?? '') === '3rd_year' ? 'selected' : '' ?>>3rd Year</option>
            <option value="4th_year" <?= ($saved['year_level'] ?? '') === '4th_year' ? 'selected' : '' ?>>4th Year</option>
            <option value="5th_year_plus" <?= ($saved['year_level'] ?? '') === '5th_year_plus' ? 'selected' : '' ?>>5th Year +</option>
          </select>
        </div>
      </div>

      <div class="mb-8">
        <label class="field-label" for="gwa_gpa">Latest GWA / GPA:</label>
        <input
          type="text"
          class="form-input"
          id="gwa_gpa"
          name="gwa_gpa"
          placeholder="ex. 1.50"
          value="<?= htmlspecialchars($saved['gwa_gpa'] ?? '') ?>"
        >
      </div>

      <!-- ──────────── SUBMIT ──────────── -->
      <div class="flex justify-end pt-4 border-t border-gray-100">
        <button type="submit" class="btn-submit" <?php echo !$canSubmitApplication ? 'disabled' : ''; ?>>
          Submit &nbsp;<i class="fa-solid fa-arrow-right"></i>
        </button>
      </div>

    </form>
  </div>

</main>

<!-- FOOTER -->
<footer class="mt-16 bg-green-950 text-white pt-14 pb-6 px-4">
  <div class="max-w-6xl mx-auto">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-10 pb-10 border-b border-green-800">
      <div>
        <div class="flex items-center gap-3 mb-4">
          <div class="w-12 h-12 rounded-full bg-green-700 flex items-center justify-center overflow-hidden"><img src="<?= e(site_config_logo_url($siteSettings, '../')) ?>" alt="Logo" class="w-full h-full object-contain" /></div>
          <div><h3 class="text-lg font-bold"><?= e($siteSettings['site_title']) ?></h3><p class="text-green-400 text-xs tracking-widest uppercase"><?= e($siteSettings['barangay_name']) ?></p></div>
        </div>
        <div class="space-y-2 text-sm text-green-300">
          <p><i class="fa-solid fa-location-dot mr-2 text-green-500"></i><?= e($siteSettings['barangay_name']) ?>, <?= e($siteSettings['municipality']) ?></p>
          <p><i class="fa-solid fa-envelope mr-2 text-green-500"></i><?= e($siteSettings['email']) ?></p>
          <p><i class="fa-solid fa-phone mr-2 text-green-500"></i><?= e($siteSettings['contact_number']) ?></p>
        </div>
        <a href="<?= e($siteSettings['facebook_link'] ?: '#') ?>" target="_blank" rel="noopener noreferrer" class="mt-4 inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded-lg text-sm transition"><i class="fab fa-facebook"></i> Facebook Page</a>
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
    <div class="text-center mt-6 text-green-500 text-sm">© 2026 <?= e($siteSettings['site_title']) ?>. All Rights Reserved. Made with ❤️ for <?= e($siteSettings['barangay_name']) ?>.</div>
  </div>
</footer>

<script>
  /* ─── PWD ID toggle ─── */
  function togglePwdInput(cb) {
    const input = document.getElementById('pwd_id_number');
    input.disabled = !cb.checked;
    if (!cb.checked) { input.value = ''; input.style.background = '#f3f4f6'; }
    else { input.style.background = '#fff'; input.focus(); }
  }

  /* ─── Other health toggle ─── */
  function toggleOtherInput(cb) {
    const input = document.getElementById('health_other_specify');
    input.disabled = !cb.checked;
    if (!cb.checked) { input.value = ''; input.style.background = '#f3f4f6'; }
    else { input.style.background = '#fff'; input.focus(); }
  }

  /* ─── Medicine input toggle ─── */
  function toggleMedicineInput() {
    const yes   = document.getElementById('med_yes').checked;
    const input = document.getElementById('medicine_name');
    input.disabled = !yes;
    if (!yes) { input.value = ''; input.style.background = '#f3f4f6'; }
    else { input.style.background = '#fff'; input.focus(); }
  }

  /* ─── Init disabled states on load ─── */
  window.addEventListener('DOMContentLoaded', () => {
    // PWD
    const pwdCb  = document.getElementById('is_pwd');
    const pwdIn  = document.getElementById('pwd_id_number');
    if (!pwdCb.checked) { pwdIn.disabled = true; pwdIn.style.background = '#f3f4f6'; }

    // Other health
    const othCb  = document.getElementById('health_other');
    const othIn  = document.getElementById('health_other_specify');
    if (!othCb.checked) { othIn.disabled = true; othIn.style.background = '#f3f4f6'; }

    // Medicine
    const medIn  = document.getElementById('medicine_name');
    const medYes = document.getElementById('med_yes');
    if (!medYes.checked) { medIn.disabled = true; medIn.style.background = '#f3f4f6'; }

      // Display toast if status is set
      const alertBanner = document.getElementById('alertBanner');
      if (alertBanner) {
        const status = alertBanner.dataset.toastStatus;
        const message = alertBanner.dataset.toastMessage;
        if (status) {
          showToast(status === 'ok' ? 'success' : 'error',
            status === 'ok' ? 'Application Saved' : 'Submission Failed',
            message || (status === 'ok' ? 'Form submitted successfully.' : 'Unable to save your application.'));
        }
      }
  });

  let alertTimer = null;
  function showToast(type, title, desc) {
    const icons = { success: 'fa-circle-check', error: 'fa-circle-xmark', warning: 'fa-triangle-exclamation' };
    const map   = { success: 'alert-success', error: 'alert-error', warning: 'alert-warning' };
    document.getElementById('alertInner').className  = 'alert-inner ' + (map[type] || 'alert-success');
    document.getElementById('alertIcon').className   = 'fa-solid ' + (icons[type] || 'fa-circle-check');
    document.getElementById('alertTitle').textContent = title;
    document.getElementById('alertDesc').textContent  = desc || '';
    document.getElementById('alertBanner').classList.add('show');
    clearTimeout(alertTimer);
    alertTimer = setTimeout(dismissAlert, 4500);
  }
  function dismissAlert() { document.getElementById('alertBanner').classList.remove('show'); }

  /* ─── Form Validation ─── */
  document.getElementById('beneficiaryForm').addEventListener('submit', function(e) {
    let valid = true;

    // Helper functions for inline validation if needed
    const showErr = (id) => { 
        const el = document.getElementById(id);
        if(el) { el.classList.add('show'); valid = false; }
    };
    const clearErr = (id) => {
        const el = document.getElementById(id);
        if(el) el.classList.remove('show');
    };
    const markSel = (id, errId) => {
        const sel = document.getElementById(id);
        if (!sel || !sel.value) showErr(errId);
        else clearErr(errId);
    };

    // Required selects
    markSel('house_material',  'err_house_material');

    // Required radio groups
    const checkRadio = (name, errId) => {
      if (!document.querySelector(`input[name="${name}"]:checked`)) showErr(errId);
      else clearErr(errId);
    };
    checkRadio('electricity',         'err_electricity');
    checkRadio('water_source',        'err_water_source');
    checkRadio('toilet_type',         'err_toilet_type');
    checkRadio('pregnant_or_children','err_pregnant');

    // PWD Validation
    const pwdCb = document.getElementById('is_pwd');
    const pwdIn = document.getElementById('pwd_id_number');
    if (pwdCb && pwdCb.checked) {
      if (!pwdIn.value.trim()) {
        const errPwd = document.getElementById('err_pwd_id_number') || document.createElement('div');
        if(!errPwd.id) {
            errPwd.id = 'err_pwd_id_number';
            errPwd.className = 'error-msg show';
            errPwd.style.color = 'red';
            errPwd.style.fontSize = '0.8rem';
            errPwd.innerText = 'PWD ID Number is required.';
            pwdIn.parentNode.appendChild(errPwd);
        } else {
            errPwd.classList.add('show');
        }
        pwdIn.style.borderColor = 'red';
        valid = false;
      } else {
        const errPwd = document.getElementById('err_pwd_id_number');
        if(errPwd) errPwd.classList.remove('show');
        pwdIn.style.borderColor = '';
      }
    }

    // Health Other Validation
    const othCb = document.getElementById('health_other');
    const othIn = document.getElementById('health_other_specify');
    if (othCb && othCb.checked) {
      if (!othIn.value.trim()) {
        const errOth = document.getElementById('err_health_other_specify') || document.createElement('div');
        if(!errOth.id) {
            errOth.id = 'err_health_other_specify';
            errOth.className = 'error-msg show';
            errOth.style.color = 'red';
            errOth.style.fontSize = '0.8rem';
            errOth.innerText = 'Please specify your health condition.';
            othIn.parentNode.appendChild(errOth);
        } else {
            errOth.classList.add('show');
        }
        othIn.style.borderColor = 'red';
        valid = false;
      } else {
        const errOth = document.getElementById('err_health_other_specify');
        if(errOth) errOth.classList.remove('show');
        othIn.style.borderColor = '';
      }
    }

    if (!valid) {
      e.preventDefault();
      // Scroll to first error
      const firstErr = document.querySelector('.error-msg.show, .field-error, [style*="border-color: red"]');
      firstErr?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  });

  /* ─── Profile dropdown ─── */
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
      document.getElementById('profile-dropdown')?.classList.add('hidden');
      document.getElementById('profile-btn')?.setAttribute('aria-expanded', 'false');
      document.getElementById('profile-chevron').style.transform = '';
    }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      document.getElementById('profile-dropdown')?.classList.add('hidden');
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
</body>
</html>
