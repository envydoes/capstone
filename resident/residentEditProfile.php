<?php
// Resident Edit Profile Page
session_start();
require_once __DIR__ . '/../config/db_connection.php';
require_once __DIR__ . '/../includes/site_config.php';
$siteSettings = site_config_load($conn);

$accId = $_SESSION['acc_id'] ?? null;

// Check role and redirect if not resident
$role = $_SESSION['account_role'] ?? '';
if (!str_contains($role, 'resident') || str_contains($role, 'non-resident') || str_contains($role, 'non_resident')) {
    switch ($role) {
        case 'admin':
            header('Location: ../admin/adminLanding.php');
            break;
        case 'non-resident':
            header('Location: ../nonresident/nonresidentLanding.php');
            break;
        default:
            header('Location: ../landing.php');
    }
    exit;
}

$resident = [
    'name' => 'Resident User',
    'role' => 'Resident',
    'user_id' => $_SESSION['user_id'] ?? 'N/A',
    'firstname' => '',
    'middlename' => '',
    'lastname' => '',
    'suffix' => '',
    'family_role' => '',
    'gender' => '',
    'birthday' => '',
    'birthplace' => '',
    'civil_status' => '',
    'citizenship' => '',
    'religion' => '',
    'ethnicity' => '',
    'street' => '',
    'barangay' => '',
    'city' => '',
    'province' => '',
    'zip' => '',
    'phone' => '',
    'email' => '',
    'emergency_contact' => '',
    'emergency_contact_relationship' => '',
    'emergency_phone' => '',
    'health_conditions' => '',
    'employment_status' => '',
    'job_title' => '',
    'monthly_income' => '',
    'voter_id' => '',
    'precinct' => '',
    'years_resident' => '',
    'resident_birth' => ''
];

$account_status = [
    'status' => 'Inactive',
    'member_since' => 'N/A',
    'last_login' => $_SESSION['last_login'] ?? 'N/A'
];

$success_message = '';

if ($accId) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $updateData = [
            'firstname' => $_POST['firstname'] ?? '',
            'middlename' => $_POST['middlename'] ?? '',
            'lastname' => $_POST['lastname'] ?? '',
            'suffix' => $_POST['suffix'] ?? '',
            'family_role' => $_POST['family_role'] ?? '',
            'gender' => $_POST['gender'] ?? '',
            'birthday' => $_POST['birthday'] ?? '',
            'birthplace' => $_POST['birthplace'] ?? '',
            'civil_status' => $_POST['civil_status'] ?? '',
            'citizenship' => $_POST['citizenship'] ?? '',
            'religion' => $_POST['religion'] ?? '',
            'ethnicity' => $_POST['ethnicity'] ?? '',
            'street' => $_POST['street'] ?? '',
            'barangay' => $_POST['barangay'] ?? '',
            'city' => $_POST['city'] ?? '',
            'province' => $_POST['province'] ?? '',
            'zip' => $_POST['zip'] ?? '',
            'phone' => $_POST['phone'] ?? '',
            'email' => $_POST['email'] ?? '',
            'emergency_contact' => $_POST['emergency_contact'] ?? '',
            'emergency_contact_relationship' => $_POST['emergency_contact_relationship'] ?? '',
            'emergency_phone' => $_POST['emergency_phone'] ?? '',
            'health_conditions' => $_POST['health_conditions'] ?? '',
            'employment_status' => $_POST['employment_status'] ?? '',
            'job_title' => $_POST['job_title'] ?? '',
            'monthly_income' => $_POST['monthly_income'] ?? '',
            'voter_id' => $_POST['voter_id'] ?? '',
            'precinct' => $_POST['precinct'] ?? '',
            'years_resident' => $_POST['years_resident'] ?? '',
            'resident_birth' => isset($_POST['resident_birth']) ? '1' : '0'
        ];

        $stmt = $conn->prepare('UPDATE tbl_userinfo SET email=?, phone=?, street=?, barangay=?, city=?, province=?, zip=?, emergency_contact=?, emergency_phone=?, health_conditions=?, employment_status=?, job_title=?, monthly_income=?, voter_id=?, precinct=?, years_resident=?, resident_birth=? WHERE accID=?');
        if ($stmt) {
            $stmt->bind_param('ssssssssssssssssss',
                $updateData['email'],
                $updateData['phone'],
                $updateData['street'],
                $updateData['barangay'],
                $updateData['city'],
                $updateData['province'],
                $updateData['zip'],
                $updateData['emergency_contact'],
                $updateData['emergency_phone'],
                $updateData['health_conditions'],
                $updateData['employment_status'],
                $updateData['job_title'],
                $updateData['monthly_income'],
                $updateData['voter_id'],
                $updateData['precinct'],
                $updateData['years_resident'],
                $updateData['resident_birth'],
                $accId
            );
            if ($stmt->execute()) {
                $success_message = 'Your profile has been updated successfully.';
            } else {
                $success_message = 'Unable to update profile: ' . htmlspecialchars($stmt->error);
            }
            $stmt->close();
        }
    }

    $stmt = $conn->prepare('SELECT userID, firstname, middlename, lastname, suffix, family_role, email, phone, birthday, birthplace, gender, civil_status, citizenship, religion, ethnicity, street, barangay, city, province, zip, emergency_contact, emergency_phone, health_conditions, employment_status, job_title, monthly_income, voter_id, precinct, years_resident, resident_birth, userStatus, dateRegistered FROM tbl_userinfo WHERE accID = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('s', $accId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $resident['name'] = trim(($row['firstname'] ?? '') . ' ' . ($row['middlename'] ?? '') . ' ' . ($row['lastname'] ?? '')) ?: $resident['name'];
            $resident['user_id'] = $row['userID'] ?? $resident['user_id'];
            $resident['firstname'] = $row['firstname'] ?? '';
            $resident['middlename'] = $row['middlename'] ?? '';
            $resident['lastname'] = $row['lastname'] ?? '';
            $resident['suffix'] = $row['suffix'] ?? '';
            $resident['family_role'] = $row['family_role'] ?? '';
            $resident['email'] = $row['email'] ?? '';
            $resident['phone'] = $row['phone'] ?? '';
            $resident['birthday'] = $row['birthday'] ?? '';
            $resident['birthplace'] = $row['birthplace'] ?? '';
            $resident['gender'] = $row['gender'] ?? '';
            $resident['civil_status'] = $row['civil_status'] ?? '';
            $resident['citizenship'] = $row['citizenship'] ?? '';
            $resident['religion'] = $row['religion'] ?? '';
            $resident['ethnicity'] = $row['ethnicity'] ?? '';
            $resident['street'] = $row['street'] ?? '';
            $resident['barangay'] = $row['barangay'] ?? '';
            $resident['city'] = $row['city'] ?? '';
            $resident['province'] = $row['province'] ?? '';
            $resident['zip'] = $row['zip'] ?? '';
            $resident['emergency_contact'] = $row['emergency_contact'] ?? '';
            $resident['emergency_phone'] = $row['emergency_phone'] ?? '';
            $resident['health_conditions'] = $row['health_conditions'] ?? '';
            $resident['employment_status'] = $row['employment_status'] ?? '';
            $resident['job_title'] = $row['job_title'] ?? '';
            $resident['monthly_income'] = $row['monthly_income'] ?? '';
            $resident['voter_id'] = $row['voter_id'] ?? '';
            $resident['precinct'] = $row['precinct'] ?? '';
            $resident['years_resident'] = $row['years_resident'] ?? '';
            $resident['resident_birth'] = $row['resident_birth'] ?? '';
            $account_status['status'] = $row['userStatus'] ?? 'Inactive';
            $account_status['member_since'] = !empty($row['dateRegistered']) ? date('F j, Y', strtotime($row['dateRegistered'])) : 'N/A';

            $lastLoginRaw = null;
            $hasLastLogin = false;
            $checkColumn = $conn->query("SHOW COLUMNS FROM tbl_useracc LIKE 'last_login'");
            if ($checkColumn) {
                $hasLastLogin = $checkColumn->num_rows > 0;
            }

            if ($hasLastLogin) {
                $loginStmt = $conn->prepare('SELECT last_login FROM tbl_useracc WHERE accID = ? LIMIT 1');
                if ($loginStmt) {
                    $loginStmt->bind_param('s', $accId);
                    $loginStmt->execute();
                    $loginResult = $loginStmt->get_result();
                    if ($loginRow = $loginResult->fetch_assoc()) {
                        $lastLoginRaw = $loginRow['last_login'] ?? null;
                    }
                    $loginStmt->close();
                }
            }

            if (!empty($_SESSION['last_login'])) {
                $lastLoginRaw = $_SESSION['last_login'];
            } elseif (empty($lastLoginRaw) && !empty($row['last_login'] ?? null)) {
                $lastLoginRaw = $row['last_login'];
            }

            $account_status['last_login'] = !empty($lastLoginRaw) ? date('F j, Y', strtotime($lastLoginRaw)) : 'N/A';
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Edit Profile - <?= e($siteSettings['site_title']) ?></title>
    <link rel="icon" href="<?= e(site_config_logo_url($siteSettings, '../')) ?>" type="image/png" />
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <?= site_config_css_vars($siteSettings) ?>
    <style>
        body { font-family: 'DM Sans', sans-serif; }
        .page-bg { background: var(--site-primary-pale); }
        .nav-link { position: relative; transition: color 0.2s; }
        .nav-link::after { content: ''; position: absolute; bottom: -2px; left: 0; width: 0; height: 2px; background: var(--site-primary); transition: width 0.3s ease; }
        .nav-link:hover::after { width: 100%; }
        .nav-link:hover { color: var(--site-primary-dark); }

        input[readonly], select[disabled] {
            background-color: #f1f5f9;
            color: #64748b;
            cursor: not-allowed;
            box-shadow: inset 0 0 0 1px #cbd5e1;
        }

        /* ── Role cards ── */
        .role-card {
            transition: all 0.25s ease;
            background-color: #ffffff;
            cursor: pointer;
            user-select: none;
        }
        .role-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.1); transform: translateY(-2px); }
        .role-card.selected { border-color: var(--site-primary) !important; background-color: var(--site-primary-pale); }
        .role-card.selected .role-check { display: flex !important; }
        .role-card.locked { opacity: 0.55; pointer-events: none; cursor: not-allowed; }
        .role-check { display: none; }

        /* ── Sections ── */
        .role-section { display: none; }

        /* ── Upload zone ── */
        .upload-zone {
            border: 2px dashed color-mix(in srgb, var(--site-primary-light) 70%, white);
            border-radius: 14px;
            background: var(--site-primary-pale);
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            cursor: pointer; transition: border-color 0.2s, background 0.2s;
            position: relative; overflow: hidden;
            min-height: 180px;
        }
        .upload-zone:hover { border-color: var(--site-primary); background: color-mix(in srgb, var(--site-primary) 15%, white); }
        .upload-zone.has-file { border-style: solid; border-color: var(--site-primary); background: #fff; }
        .upload-zone.drag-over { border-color: var(--site-primary-dark); background: color-mix(in srgb, var(--site-primary) 25%, white); }
        .btn-upload {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 9px 20px; background: var(--site-primary); color: #fff;
            border-radius: 10px; font-weight: 600; font-size: 0.83rem;
            transition: background 0.2s, transform 0.15s; cursor: pointer; border: none;
        }
        .btn-upload:hover { background: var(--site-primary-dark); transform: translateY(-1px); }
        .btn-remove {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 9px 20px; background: #fee2e2; color: #dc2626;
            border-radius: 10px; font-weight: 600; font-size: 0.83rem;
            transition: background 0.2s; cursor: pointer; border: none;
        }
        .btn-remove:hover { background: #fecaca; }

        /* ── pending badge ── */
        .badge-pending {
            display: inline-flex; align-items: center; gap: 5px;
            background: #fef3c7; color: #92400e; border: 1px solid #fde68a;
            font-size: 0.7rem; font-weight: 700; padding: 2px 10px;
            border-radius: 999px; text-transform: uppercase; letter-spacing: .05em;
        }

        /* ── Submit button states ── */
        #submitBtn:disabled {
            opacity: 0.45;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }
        #submitBtn:not(:disabled):hover {
            background-color: var(--site-primary);
        }

        /* ── Toast ── */
        #toast-container {
            position: fixed;
            top: 80px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            pointer-events: none;
        }
        .toast {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 18px;
            border-radius: 14px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
            min-width: 300px;
            max-width: 380px;
            pointer-events: all;
            animation: toastIn 0.4s cubic-bezier(0.34, 1.56, 0.64, 1) both;
        }
        .toast.toast-hide {
            animation: toastOut 0.3s ease forwards;
        }
        .toast-success { background: var(--site-primary-pale); border: 1.5px solid color-mix(in srgb, var(--site-primary) 40%, white); color: var(--site-primary-darker); }
        .toast-info    { background: #eff6ff; border: 1.5px solid #93c5fd; color: #1e3a5f; }
        .toast-error   { background: #fef2f2; border: 1.5px solid #fca5a5; color: #7f1d1d; }
        .toast-warning { background: #fffbeb; border: 1.5px solid #fde68a; color: #78350f; }
        .toast-icon { font-size: 1.1rem; flex-shrink: 0; margin-top: 1px; }
        .toast-body { flex: 1; }
        .toast-title { font-weight: 700; font-size: 0.875rem; margin-bottom: 2px; }
        .toast-msg   { font-size: 0.8rem; opacity: 0.85; line-height: 1.4; }
        .toast-close { cursor: pointer; opacity: 0.5; font-size: 0.85rem; margin-top: 1px; flex-shrink: 0; transition: opacity 0.15s; }
        .toast-close:hover { opacity: 1; }

        @keyframes toastIn {
            from { opacity: 0; transform: translateX(40px) scale(0.9); }
            to   { opacity: 1; transform: translateX(0) scale(1); }
        }
        @keyframes toastOut {
            from { opacity: 1; transform: translateX(0) scale(1); }
            to   { opacity: 0; transform: translateX(40px) scale(0.9); }
        }

        /* ── Required field indicator ── */
        .required-field-indicator {
            border-color: #f87171 !important;
            box-shadow: 0 0 0 2px rgba(248,113,113,0.2) !important;
        }
        .field-error-msg {
            color: #dc2626;
            font-size: 0.72rem;
            margin-top: 3px;
            display: block;
        }

        :root {
          --site-primary-dark:   color-mix(in srgb, var(--site-primary) 55%, black);
          --site-primary-darker: color-mix(in srgb, var(--site-primary) 75%, black);
          --site-primary-light:  color-mix(in srgb, var(--site-primary) 55%, white);
          --site-primary-pale:   color-mix(in srgb, var(--site-primary) 12%, white);
        }

        /* Tailwind-green → theme color overrides */
        .bg-green-100 { background-color: color-mix(in srgb, var(--site-primary) 18%, white) !important; }
        .bg-green-700 { background-color: var(--site-primary) !important; }
        .text-green-500 { color: var(--site-primary) !important; }
        .text-green-600 { color: var(--site-primary) !important; }
        .text-green-700 { color: var(--site-primary) !important; }
        .text-green-800 { color: var(--site-primary-darker) !important; }
        .text-green-900 { color: var(--site-primary-darker) !important; }
        .border-green-100 { border-color: color-mix(in srgb, var(--site-primary) 20%, white) !important; }
        .hover\:bg-green-50:hover { background-color: var(--site-primary-pale) !important; }
        .hover\:text-green-700:hover { color: var(--site-primary) !important; }

        /* Tailwind-emerald → theme color overrides (role cards / focus rings) */
        .bg-emerald-50  { background-color: var(--site-primary-pale) !important; }
        .bg-emerald-100 { background-color: color-mix(in srgb, var(--site-primary) 22%, white) !important; }
        .bg-emerald-600 { background-color: var(--site-primary) !important; }
        .border-emerald-100 { border-color: color-mix(in srgb, var(--site-primary) 25%, white) !important; }
        .border-emerald-200 { border-color: color-mix(in srgb, var(--site-primary) 30%, white) !important; }
        .border-emerald-500 { border-color: var(--site-primary) !important; }
        .text-emerald-500 { color: var(--site-primary) !important; }
        .text-emerald-600 { color: var(--site-primary) !important; }
        .text-emerald-700 { color: var(--site-primary-dark) !important; }
        .text-emerald-800 { color: var(--site-primary-darker) !important; }
        .hover\:bg-emerald-100:hover { background-color: color-mix(in srgb, var(--site-primary) 22%, white) !important; }
        .hover\:bg-emerald-700:hover { background-color: var(--site-primary-dark) !important; }
        .focus\:ring-emerald-500:focus { --tw-ring-color: var(--site-primary) !important; }
    </style>
</head>
<body class="page-bg text-slate-800">
  <div class="min-h-screen">
  <link rel="stylesheet" href="../assets/responsive-global.css">
    <header class="w-full h-[68px] border-b border-green-100 flex items-center px-6 md:px-8 bg-white shadow-sm sticky top-0 z-50">
      <div class="flex items-center gap-3">
        <a href="residentLanding.php" class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-full bg-green-700 flex items-center justify-center shadow overflow-hidden">
            <img src="<?= e(site_config_logo_url($siteSettings, '../')) ?>" alt="Logo" class="w-full h-full object-contain" />            
          </div>
          <div>
            <h3 class="font-bold text-green-900 text-base leading-tight"><?= e($siteSettings['site_title']) ?></h3>
            <p class="text-[10px] text-green-600 tracking-widest uppercase"><?= e($siteSettings['barangay_name']) ?></p>
          </div>
        </a>
      </div>
      <nav class="ml-auto hidden md:flex items-center gap-4 text-gray-600 text-sm font-medium">
        <a href="residentPanel.php" class="nav-link">My Panel</a>
        <a href="residentLanding.php#announcements" class="nav-link">Announcements</a>
        <a href="../busaptListing.php?type=business" class="nav-link">Business</a>
        <a href="../busaptListing.php?type=apartment" class="nav-link">Apartment</a>
      </nav>
      <button id="mobile-menu-btn" class="md:hidden ml-auto flex items-center justify-center p-2 text-gray-600 hover:text-green-700 hover:bg-green-50 rounded-lg transition" aria-label="Toggle menu">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
        </svg>
      </button>
    </header>

    <div id="mobile-sidebar-overlay" class="fixed inset-0 bg-black/50 z-[60] hidden opacity-0 transition-opacity duration-300"></div>
    <div id="mobile-sidebar" class="fixed inset-y-0 right-0 w-64 bg-white shadow-2xl transform translate-x-full transition-transform duration-300 z-[70] flex flex-col">
      <div class="p-4 border-b border-gray-100 flex items-center justify-between">
        <h3 class="font-bold text-green-900" style="font-family:'DM Sans',sans-serif;">Menu</h3>
        <button id="mobile-menu-close" class="p-2 text-gray-500 hover:text-red-500 rounded-full hover:bg-red-50 transition" aria-label="Close menu">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
          </svg>
        </button>
      </div>
      <div class="flex-1 overflow-y-auto py-4">
        <nav class="flex flex-col gap-2 px-4">
          <a href="residentPanel.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 font-medium hover:bg-green-50 hover:text-green-700 transition"><i class="fa-solid fa-gauge-high w-4 text-green-600"></i>My Panel</a>
          <a href="residentLanding.php#announcements" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 font-medium hover:bg-green-50 hover:text-green-700 transition"><i class="fa-solid fa-bullhorn w-4 text-green-500"></i>Announcements</a>
          <a href="../busaptListing.php?type=business" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 font-medium hover:bg-green-50 hover:text-green-700 transition"><i class="fa-solid fa-store w-4 text-green-500"></i>Business</a>
          <a href="../busaptListing.php?type=apartment" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 font-medium hover:bg-green-50 hover:text-green-700 transition"><i class="fa-solid fa-building w-4 text-green-500"></i>Apartment</a>
          <div class="h-px bg-gray-100 my-2"></div>
          <a href="../logout.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-red-600 font-medium hover:bg-red-50 transition"><i class="fa-solid fa-arrow-right-from-bracket w-4"></i>Logout</a>
        </nav>
      </div>
    </div>

    <main class="mx-auto max-w-6xl px-4 py-8 md:px-6 md:py-10 space-y-8">
      <?php if (!empty($success_message)): ?>
      <div class="rounded-lg bg-emerald-100 border border-emerald-200 text-emerald-800 p-4">
        <p class="font-medium"><?php echo htmlspecialchars($success_message); ?></p>
      </div>
      <?php endif; ?>

      <section class="rounded-2xl border border-green-100 bg-white p-6 md:p-8 shadow-sm">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
          <div>
            <h1 class="text-3xl md:text-4xl font-bold text-slate-900">Edit Profile</h1>
            <p class="text-sm text-slate-500 mt-1">Update your resident information</p>
          </div>
          <div class="flex items-center gap-2">
            <a href="residentLanding.php" class="inline-flex items-center gap-2 rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-100">
              <i class="fas fa-arrow-left"></i> Back to Portal
            </a>
            <a href="myProfile.php" class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
              <i class="fas fa-user-circle"></i> View Profile
            </a>
          </div>
        </div>
      </section>

      <!-- Right Content Area -->
<div class="w-full mx-auto px-4 md:px-0">
  <div class="lg:col-span-3">
    <!-- Tabs - Full Width -->
    <div class="rounded-2xl flex w-full gap-3 mb-6 bg-white py-4 p-6 md:p-8">
      <a href="residentEditProfile.php" class="flex-1 text-center px-5 py-2 bg-emerald-600 text-white font-semibold rounded-lg inline-flex items-center justify-center gap-2 shadow-sm hover:bg-emerald-700 transition">
        <i class="fas fa-user-circle"></i>
        Profile
      </a>
      <a href="residentEditPassword.php" class="flex-1 text-center px-5 py-2 bg-white text-slate-700 font-semibold rounded-lg border border-slate-200 inline-flex items-center justify-center gap-2 hover:bg-slate-50 transition">
        <i class="fas fa-lock"></i>
        Account
      </a>
    </div>

      <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
        <aside class="lg:col-span-1 space-y-6">
          <div class="bg-white border border-green-100 rounded-2xl p-6 shadow-sm">
            <div class="text-center">
              <div class="w-28 h-28 mx-auto rounded-full border-4 border-emerald-100 bg-emerald-50 flex items-center justify-center mb-4">
                <i class="fas fa-id-badge text-4xl text-emerald-700"></i>
              </div>
              <h2 class="text-xl font-bold text-slate-900"><?php echo htmlspecialchars($resident['name']); ?></h2>
              <p class="text-sm text-emerald-600 font-semibold mt-1"><?php echo htmlspecialchars($resident['role']); ?></p>
              <p class="text-xs text-slate-500 mt-1">User ID: <?php echo htmlspecialchars($resident['user_id']); ?></p>
            </div>
          </div>

          <div class="bg-white border border-green-100 rounded-2xl p-6 shadow-sm">
            <h3 class="text-lg font-bold text-slate-900 mb-4">Account Status</h3>
            <div class="space-y-3 text-sm text-slate-700">
              <div class="flex justify-between"><span class="text-slate-500">Status</span><span class="font-semibold"><?php echo htmlspecialchars($account_status['status']); ?></span></div>
              <div class="flex justify-between"><span class="text-slate-500">Member Since</span><span class="font-semibold"><?php echo htmlspecialchars($account_status['member_since']); ?></span></div>
              <div class="flex justify-between"><span class="text-slate-500">Last Login</span><span class="font-semibold text-slate-700"><?php echo htmlspecialchars($account_status['last_login']); ?></span></div>
            </div>
          </div>
        </aside>

        <section class="lg:col-span-3 space-y-6">
          <form method="POST" action="">
            <div class="bg-white border border-green-100 rounded-2xl p-6 shadow-sm">
              <div class="flex items-center justify-between mb-5">
                <h2 class="text-2xl font-bold text-slate-900"><i class="fas fa-id-card text-emerald-500 mr-2"></i> Personal Information</h2>
                <span class="text-xs uppercase tracking-wider text-emerald-600 font-semibold">Editable</span>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                  <label class="text-sm font-semibold text-slate-700">First Name</label>
                  <input type="text" name="firstname" value="<?php echo htmlspecialchars($resident['firstname']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-100 focus:ring-emerald-500 focus:border-emerald-500" readonly />
                </div>
                <div>
                  <label class="text-sm font-semibold text-slate-700">Middle Name</label>
                  <input type="text" name="middlename" value="<?php echo htmlspecialchars($resident['middlename']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-100 focus:ring-emerald-500 focus:border-emerald-500" readonly />
                </div>
                <div>
                  <label class="text-sm font-semibold text-slate-700">Last Name</label>
                  <input type="text" name="lastname" value="<?php echo htmlspecialchars($resident['lastname']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-100 focus:ring-emerald-500 focus:border-emerald-500" readonly />
                </div>
              </div>

              <div class="mt-5 grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                  <label class="text-sm font-semibold text-slate-700">Suffix</label>
                  <input type="text" name="suffix" value="<?php echo htmlspecialchars($resident['suffix']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-100 focus:ring-emerald-500 focus:border-emerald-500" readonly />
                </div>
                <div>
                  <label class="text-sm font-semibold text-slate-700">Family Role</label>
                  <select name="family_role" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-100 focus:ring-emerald-500 focus:border-emerald-500" disabled>
                    <option value="">Select Role</option>
                    <?php foreach (['head'=>'Head of Family','spouse'=>'Spouse','child'=>'Child','parent'=>'Parent','other'=>'Other'] as $v => $l): ?>
                      <option value="<?php echo htmlspecialchars($v); ?>" <?php echo ($resident['family_role'] === $v ? 'selected' : ''); ?>><?php echo htmlspecialchars($l); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label class="text-sm font-semibold text-slate-700">Gender</label>
                  <select name="gender" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-100 focus:ring-emerald-500 focus:border-emerald-500" disabled>
                    <option value="">Select Gender</option>
                    <?php foreach (['male'=>'Male','female'=>'Female','other'=>'Other'] as $v => $l): ?>
                      <option value="<?php echo htmlspecialchars($v); ?>" <?php echo ($resident['gender'] === $v ? 'selected' : ''); ?>><?php echo htmlspecialchars($l); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>

              <div class="mt-5 grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                  <label class="text-sm font-semibold text-slate-700">Birthday</label>
                  <input type="date" name="birthday" value="<?php echo htmlspecialchars($resident['birthday']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-100 focus:ring-emerald-500 focus:border-emerald-500" max="<?php echo date('Y-m-d'); ?>" readonly />
                </div>
                <div>
                  <label class="text-sm font-semibold text-slate-700">Birthplace</label>
                  <input type="text" name="birthplace" value="<?php echo htmlspecialchars($resident['birthplace']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-100 focus:ring-emerald-500 focus:border-emerald-500" readonly />
                </div>
                <div>
                  <label class="text-sm font-semibold text-slate-700">Civil Status</label>
                  <select name="civil_status" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 focus:ring-emerald-500 focus:border-emerald-500">
                    <option value="">Select Civil Status</option>
                    <?php foreach (['single'=>'Single','married'=>'Married','divorced'=>'Divorced','widowed'=>'Widowed','separated'=>'Separated'] as $v => $l): ?>
                      <option value="<?php echo htmlspecialchars($v); ?>" <?php echo ($resident['civil_status'] === $v ? 'selected' : ''); ?>><?php echo htmlspecialchars($l); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>

              <div class="mt-5 grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                  <label class="text-sm font-semibold text-slate-700">Citizenship</label>
                  <input type="text" name="citizenship" value="<?php echo htmlspecialchars($resident['citizenship']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-100 focus:ring-emerald-500 focus:border-emerald-500" readonly />
                </div>
                <div>
                  <label class="text-sm font-semibold text-slate-700">Religion</label>
                  <input type="text" name="religion" value="<?php echo htmlspecialchars($resident['religion']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 focus:ring-emerald-500 focus:border-emerald-500" />
                </div>
                <div>
                  <label class="text-sm font-semibold text-slate-700">Ethnicity</label>
                  <input type="text" name="ethnicity" value="<?php echo htmlspecialchars($resident['ethnicity']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-100 focus:ring-emerald-500 focus:border-emerald-500" readonly />
                </div>
              </div>

              <div class="mt-5 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label class="text-sm font-semibold text-slate-700">Email Address</label>
                  <input type="email" name="email" value="<?php echo htmlspecialchars($resident['email']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 focus:ring-emerald-500 focus:border-emerald-500" required />
                </div>
                <div>
                  <label class="text-sm font-semibold text-slate-700">Phone Number</label>
                  <input type="tel" name="phone" value="<?php echo htmlspecialchars($resident['phone']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 focus:ring-emerald-500 focus:border-emerald-500" required />
                </div>
              </div>

              <div class="mt-5 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label class="text-sm font-semibold text-slate-700">Employment Status</label>
                  <select name="employment_status" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 focus:ring-emerald-500 focus:border-emerald-500">
                    <option value="">Select Employment Status</option>
                    <?php foreach (['employed'=>'Employed','self-employed'=>'Self-Employed','unemployed'=>'Unemployed','student'=>'Student','retired'=>'Retired','other'=>'Other'] as $v => $l): ?>
                      <option value="<?php echo htmlspecialchars($v); ?>" <?php echo ($resident['employment_status'] === $v ? 'selected' : ''); ?>><?php echo htmlspecialchars($l); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label class="text-sm font-semibold text-slate-700">Job Title</label>
                  <input type="text" name="job_title" value="<?php echo htmlspecialchars($resident['job_title']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 focus:ring-emerald-500 focus:border-emerald-500" />
                </div>
              </div>

              <div class="mt-5 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label class="text-sm font-semibold text-slate-700">Monthly Income (PHP)</label>
                  <input type="number" min="0" max="9999999" name="monthly_income" value="<?php echo htmlspecialchars($resident['monthly_income']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 focus:ring-emerald-500 focus:border-emerald-500" />
                </div>
                <div>
                  <label class="text-sm font-semibold text-slate-700">Health Conditions / Blood Type</label>
                  <input type="text" name="health_conditions" value="<?php echo htmlspecialchars($resident['health_conditions']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-100 focus:ring-emerald-500 focus:border-emerald-500" readonly />
                </div>
              </div>

              <div class="mt-5 grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                  <label class="text-sm font-semibold text-slate-700">Voter ID</label>
                  <input type="text" name="voter_id" value="<?php echo htmlspecialchars($resident['voter_id']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-100 focus:ring-emerald-500 focus:border-emerald-500" readonly />
                </div>
                <div>
                  <label class="text-sm font-semibold text-slate-700">Precinct</label>
                  <input type="text" name="precinct" value="<?php echo htmlspecialchars($resident['precinct']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-100 focus:ring-emerald-500 focus:border-emerald-500" readonly />
                </div>
                <div>
                  <label class="text-sm font-semibold text-slate-700">Years as Resident</label>
                  <input type="number" name="years_resident" min="0" max="120" value="<?php echo htmlspecialchars($resident['years_resident']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 focus:ring-emerald-500 focus:border-emerald-500" />
                </div>
              </div>

              <div class="mt-5">
                <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                  <input type="checkbox" name="resident_birth" value="1" <?php echo ($resident['resident_birth'] === '1' ? 'checked' : ''); ?> class="accent-emerald-600" />
                  Resident by Birth
                </label>
              </div>
            </div>

            <div class="bg-white border border-green-100 rounded-2xl p-6 shadow-sm">
              <div class="flex items-center justify-between mb-5">
                <h2 class="text-2xl font-bold text-slate-900"><i class="fas fa-map-marker-alt text-emerald-500 mr-2"></i> Address Information</h2>
                <span class="text-xs uppercase tracking-wider text-emerald-600 font-semibold">Current</span>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label class="text-sm font-semibold text-slate-700">Street</label>
                  <input type="text" name="street" value="<?php echo htmlspecialchars($resident['street']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 focus:ring-emerald-500 focus:border-emerald-500" />
                </div>
                <div>
                  <label class="text-sm font-semibold text-slate-700">Barangay</label>
                  <input type="text" name="barangay" value="<?php echo htmlspecialchars($resident['barangay']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 focus:ring-emerald-500 focus:border-emerald-500" />
                </div>
              </div>

              <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label class="text-sm font-semibold text-slate-700">City / Municipality</label>
                  <input type="text" name="city" value="<?php echo htmlspecialchars($resident['city']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 focus:ring-emerald-500 focus:border-emerald-500" />
                </div>
                <div>
                  <label class="text-sm font-semibold text-slate-700">Province</label>
                  <input type="text" name="province" value="<?php echo htmlspecialchars($resident['province']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 focus:ring-emerald-500 focus:border-emerald-500" />
                </div>
              </div>

              <div class="mt-4 md:w-1/3">
                <label class="text-sm font-semibold text-slate-700">ZIP Code</label>
                <input type="text" name="zip" value="<?php echo htmlspecialchars($resident['zip']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 focus:ring-emerald-500 focus:border-emerald-500" />
              </div>
            </div>

            <div class="bg-white border border-green-100 rounded-2xl p-6 shadow-sm">
              <div class="flex items-center justify-between mb-5">
                <h2 class="text-2xl font-bold text-slate-900"><i class="fas fa-phone-alt text-emerald-500 mr-2"></i> Emergency Contact</h2>
                <span class="text-xs uppercase tracking-wider text-emerald-600 font-semibold">Important</span>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label class="text-sm font-semibold text-slate-700">Name</label>
                  <input type="text" name="emergency_contact" value="<?php echo htmlspecialchars($resident['emergency_contact']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 focus:ring-emerald-500 focus:border-emerald-500" />
                </div>
                <div>
                  <label class="text-sm font-semibold text-slate-700">Contact Number</label>
                  <input type="tel" name="emergency_phone" value="<?php echo htmlspecialchars($resident['emergency_phone']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 focus:ring-emerald-500 focus:border-emerald-500" />
                </div>
              </div>
            </div>

            <div class="flex flex-col md:flex-row md:justify-between gap-3 mt-5">
              <a href="myProfile.php" class="w-full md:w-auto inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white px-6 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                <i class="fas fa-chevron-left mr-2"></i> Cancel
              </a>
              <button type="submit" class="w-full md:w-auto inline-flex items-center justify-center rounded-lg bg-emerald-600 px-6 py-3 text-sm font-semibold text-white hover:bg-emerald-700">
                <i class="fas fa-save mr-2"></i> Save Changes
              </button>
            </div>
          </form>
        </section>
      </div>
    </main>
  </div>
<script>
  const mobileMenuBtn = document.getElementById('mobile-menu-btn');
  const mobileSidebar = document.getElementById('mobile-sidebar');
  const mobileSidebarOverlay = document.getElementById('mobile-sidebar-overlay');
  const mobileMenuClose = document.getElementById('mobile-menu-close');

  function openMobileMenu() {
    if (!mobileSidebar || !mobileSidebarOverlay) return;
    mobileSidebarOverlay.classList.remove('hidden');
    setTimeout(() => {
      mobileSidebarOverlay.classList.remove('opacity-0');
      mobileSidebarOverlay.classList.add('opacity-100');
      mobileSidebar.classList.remove('translate-x-full');
    }, 10);
  }

  function closeMobileMenu() {
    if (!mobileSidebar || !mobileSidebarOverlay) return;
    mobileSidebar.classList.add('translate-x-full');
    mobileSidebarOverlay.classList.remove('opacity-100');
    mobileSidebarOverlay.classList.add('opacity-0');
    setTimeout(() => {
      mobileSidebarOverlay.classList.add('hidden');
    }, 300);
  }

  mobileMenuBtn?.addEventListener('click', openMobileMenu);
  mobileMenuClose?.addEventListener('click', closeMobileMenu);
  mobileSidebarOverlay?.addEventListener('click', closeMobileMenu);
</script>
</body>

</html>