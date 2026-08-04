<?php
session_start();
require_once __DIR__ . '/../config/db_connection.php';
require_once __DIR__ . '/../includes/site_config.php';
$siteSettings = site_config_load($conn);

$accId = $_SESSION['acc_id'] ?? null;
$userName = $_SESSION['user_name'] ?? '';
$role = $_SESSION['account_role'] ?? 'Resident';

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

$userData = [
    'firstname' => '',
    'middlename' => '',
    'lastname' => '',
    'email' => '',
    'phone' => '',
    'birthday' => '',
    'gender' => '',
    'civil_status' => '',
    'street' => '',
    'barangay' => '',
    'city' => '',
    'province' => '',
    'zip' => '',
    'emergency_contact' => '',
    'emergency_phone' => '',
    'userStatus' => 'Inactive',
    'dateRegistered' => '',
    'last_login' => ''
];

$accountStatus = [
    'status' => 'Inactive',
    'member_since' => 'N/A',
    'last_login' => 'N/A'
];

if ($accId) {
    $stmt = $conn->prepare('SELECT userID, firstname, middlename, lastname, email, phone, birthday, gender, civil_status, street, barangay, city, province, zip, emergency_contact, emergency_phone, userStatus, dateRegistered FROM tbl_userinfo WHERE accID = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('s', $accId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $userData = array_merge($userData, $row);
            $userName = trim(($row['firstname'] ?? '') . ' ' . ($row['middlename'] ?? '') . ' ' . ($row['lastname'] ?? '')) ?: $userName;
            $role = $_SESSION['account_role'] ?? 'Resident';

            $lastLoginRaw = null;
            $hasLastLogin = false;
            $colResult = $conn->query("SHOW COLUMNS FROM tbl_useracc LIKE 'last_login'");
            if ($colResult) {
                $hasLastLogin = $colResult->num_rows > 0;
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

            // Prefer session value when available (most recent login time)
            if (!empty($_SESSION['last_login'])) {
                $lastLoginRaw = $_SESSION['last_login'];
            } elseif (empty($lastLoginRaw) && !empty($row['last_login'] ?? null)) {
                $lastLoginRaw = $row['last_login'];
            }

            $accountStatus = [
                'status' => $row['userStatus'] ?? 'Inactive',
                'member_since' => !empty($row['dateRegistered']) ? date('F j, Y', strtotime($row['dateRegistered'])) : 'N/A',
                'last_login' => !empty($lastLoginRaw) ? date('F j, Y', strtotime($lastLoginRaw)) : 'N/A'
            ];
        }
        $stmt->close();
    }
}

$fullName = $userName ?: trim($userData['firstname'] . ' ' . $userData['middlename'] . ' ' . $userData['lastname']);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="../assets/responsive-global.css">
    <title>Edit Profile - <?= e($siteSettings['site_title']) ?></title>
    <link rel="icon" href="<?= e(site_config_logo_url($siteSettings, '../')) ?>" type="image/png" />
    <script src="https://cdn.tailwindcss.com/3.4.16"></script>
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
      <section class="rounded-2xl border border-green-100 bg-white p-6 md:p-8 shadow-sm">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
          <div>
            <h1 class="text-3xl md:text-4xl font-bold text-slate-900">My Profile</h1>
            <p class="text-sm text-slate-500 mt-1">View and manage your profile information</p>
          </div>
          <div class="flex items-center gap-2">
             <a href="residentLanding.php" class="inline-flex items-center gap-2 text-sm font-semibold text-emerald-700 hover:text-emerald-900 hover:bg-emerald-100 rounded-lg border border-emerald-100 bg-emerald-50 px-3 py-2">
            <i class="fa-solid fa-arrow-left text-xs"></i>
            <span>Back to Portal</span>
          </a>
            <span class="rounded-full bg-green-100 px-3 py-1 text-xs font-semibold text-green-700">Resident</span>
          </div>
        </div>
      </section>

      <section class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-1 space-y-6" style="position: static;">
          <div class="bg-white border border-green-100 rounded-2xl p-6 text-center shadow-sm">
            <div class="w-28 h-28 rounded-full border-4 border-emerald-100 bg-emerald-50 mx-auto mb-4 flex items-center justify-center">
              <i class="fas fa-id-badge text-5xl text-emerald-700"></i>
            </div>
            <h2 class="text-xl font-bold text-slate-900 mb-1"><?php echo htmlspecialchars($fullName ?: 'Resident User'); ?></h2>
            <span class="inline-flex items-center gap-2 border border-emerald-100 bg-emerald-50 rounded-full px-3 py-1 text-xs font-semibold text-emerald-700 mb-3">
              <i class="fas fa-id-badge text-[10px]"></i>
              <?php echo htmlspecialchars(ucfirst($role)); ?>
            </span>
            <p class="text-sm text-slate-600 mb-6">User ID: <span class="font-semibold text-slate-900"><?php echo htmlspecialchars($userData['userID'] ?? $_SESSION['user_id'] ?? 'N/A'); ?></span></p>
            <a href="residentEditProfile.php" class="w-full inline-flex items-center justify-center bg-green-700 text-white py-3 rounded-lg font-semibold hover:bg-green-800 transition">Edit Profile</a>
          </div>

          <div class="bg-white border border-green-100 rounded-2xl p-6 shadow-sm">
            <h3 class="font-semibold text-slate-900 mb-4">Account Status</h3>
            <dl class="grid gap-3 text-sm">
              <div class="flex justify-between items-center rounded-lg bg-emerald-50 px-3 py-2">
                <dt class="text-slate-600">Status</dt>
                <dd class="font-semibold text-emerald-700"><?php echo htmlspecialchars($accountStatus['status']); ?></dd>
              </div>
              <div class="flex justify-between items-center rounded-lg bg-slate-50 px-3 py-2">
                <dt class="text-slate-600">Member Since</dt>
                <dd class="font-semibold text-slate-800"><?php echo htmlspecialchars($accountStatus['member_since']); ?></dd>
              </div>
              <div class="flex justify-between items-center rounded-lg bg-slate-50 px-3 py-2">
                <dt class="text-slate-600">Last Login</dt>
                <dd class="font-semibold text-slate-800"><?php echo htmlspecialchars($accountStatus['last_login']); ?></dd>
              </div>
            </dl>
          </div>
        </div>

        <div class="lg:col-span-2 space-y-6">
          <div class="bg-white border border-green-100 rounded-2xl p-6 shadow-sm">
            <div class="flex items-center gap-3 mb-6"><i class="fa-solid fa-id-card text-lg text-green-700"></i><h3 class="text-xl font-bold text-slate-900">Personal Information</h3></div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
              <div><p class="text-xs text-slate-400 mb-1">Full Name</p><p class="text-sm font-semibold text-slate-900"><?php echo htmlspecialchars($fullName); ?></p></div>
              <div><p class="text-xs text-slate-400 mb-1">Date of Birth</p><p class="text-sm font-semibold text-slate-900"><?php echo htmlspecialchars($userData['birthday'] ?? 'N/A'); ?></p></div>
              <div><p class="text-xs text-slate-400 mb-1">Gender</p><p class="text-sm font-semibold text-slate-900"><?php echo htmlspecialchars($userData['gender'] ?? 'N/A'); ?></p></div>
              <div><p class="text-xs text-slate-400 mb-1">Civil Status</p><p class="text-sm font-semibold text-slate-900"><?php echo htmlspecialchars($userData['civil_status'] ?? 'N/A'); ?></p></div>
              <div><p class="text-xs text-slate-400 mb-1">Citizenship</p><p class="text-sm font-semibold text-slate-900">Filipino</p></div>
              <div><p class="text-xs text-slate-400 mb-1">Occupation</p><p class="text-sm font-semibold text-slate-900">-</p></div>
            </div>
          </div>

          <div class="bg-white border border-green-100 rounded-2xl p-6 shadow-sm">
            <div class="flex items-center gap-3 mb-6"><i class="fa-solid fa-phone-alt text-lg text-green-700"></i><h3 class="text-xl font-bold text-slate-900">Contact Information</h3></div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
              <div><p class="text-xs text-slate-400 mb-1">Email Address</p><a href="mailto:<?php echo htmlspecialchars($userData['email']); ?>" class="text-sm font-semibold text-slate-900 hover:text-green-700"><?php echo htmlspecialchars($userData['email'] ?: 'N/A'); ?></a></div>
              <div><p class="text-xs text-slate-400 mb-1">Phone Number</p><p class="text-sm font-semibold text-slate-900"><?php echo htmlspecialchars($userData['phone'] ?: 'N/A'); ?></p></div>
            </div>
          </div>

          <div class="bg-white border border-green-100 rounded-2xl p-6 shadow-sm">
            <div class="flex items-center gap-3 mb-6"><i class="fa-solid fa-map-marker-alt text-lg text-green-700"></i><h3 class="text-xl font-bold text-slate-900">Address Information</h3></div>
            <div class="mb-6"><p class="text-xs text-slate-400 mb-1">Complete Address</p><p class="text-sm font-semibold text-slate-900"><?php echo htmlspecialchars(($userData['street'] ?? '') . ', ' . ($userData['barangay'] ?? '') . ', ' . ($userData['city'] ?? '') . ', ' . ($userData['province'] ?? '') . ' ' . ($userData['zip'] ?? '')); ?></p></div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 pt-6 border-t border-gray-100">
              <div><p class="text-xs text-slate-400 mb-1">Barangay</p><p class="text-sm font-semibold text-slate-900"><?php echo htmlspecialchars($userData['barangay'] ?: 'N/A'); ?></p></div>
              <div><p class="text-xs text-slate-400 mb-1">City</p><p class="text-sm font-semibold text-slate-900"><?php echo htmlspecialchars($userData['city'] ?: 'N/A'); ?></p></div>
              <div><p class="text-xs text-slate-400 mb-1">Province</p><p class="text-sm font-semibold text-slate-900"><?php echo htmlspecialchars($userData['province'] ?: 'N/A'); ?></p></div>
            </div>
          </div>

          <div class="bg-white border border-green-100 rounded-2xl p-6 shadow-sm">
            <div class="flex items-center gap-3 mb-6"><i class="fa-solid fa-phone-alt text-lg text-green-700"></i><h3 class="text-xl font-bold text-slate-900">Emergency Contact</h3></div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
              <div><p class="text-xs text-slate-400 mb-1">Name</p><p class="text-sm font-semibold text-slate-900"><?php echo htmlspecialchars($userData['emergency_contact'] ?: 'N/A'); ?></p></div>
              <div><p class="text-xs text-slate-400 mb-1">Relationship</p><p class="text-sm font-semibold text-slate-900"><?php echo htmlspecialchars($userData['emergency_relation'] ?? 'N/A'); ?></p></div>
              <div><p class="text-xs text-slate-400 mb-1">Contact Number</p><p class="text-sm font-semibold text-slate-900"><?php echo htmlspecialchars($userData['emergency_phone'] ?: 'N/A'); ?></p></div>
            </div>
          </div>
        </div>
      </section>
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