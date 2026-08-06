<?php
// Non-Resident Edit Profile Page
session_start();
require_once __DIR__ . '/../config/db_connection.php';
require_once __DIR__ . '/../includes/site_config.php';
$siteSettings = site_config_load($conn);

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// If admin, redirect away
if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['account_role'] ?? '';
    if (is_array($role)) $role = implode(',', $role);
    $roleLower = strtolower(trim($role));
    if ($roleLower === 'admin') {
        header('Location: ../admin/adminLanding.php');
        exit;
    }
    $roleParts = array_map('trim', explode(',', $roleLower));
    if (in_array('resident', $roleParts, true) && !in_array('non-resident', $roleParts, true)) {
        header('Location: ../resident/residentLanding.php');
        exit;
    }
}

/* ============================================================
   VALIDATION HELPER FUNCTIONS
   ============================================================ */
function sanitizeText(string $value, int $maxLen = 512): string {
    return mb_substr(preg_replace('/\s+/', ' ', trim(strip_tags($value))), 0, $maxLen, 'UTF-8');
}
function isValidBirthdate(string $date): bool {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date && $d < new DateTime();
}
function isValidZip(string $zip): bool {
    return (bool) preg_match('/^[A-Z0-9]{4,10}$/i', $zip);
}
function isValidPHPhoneLoose(string $phone): bool {
    $cleaned = preg_replace('/[\s\-()]/', '', $phone);
    return (bool) preg_match('/^(\+63|0)9\d{9}$/', $cleaned);
}
function isValidIncome(string $val): bool {
    return $val === '' || (is_numeric($val) && (float)$val >= 0 && (float)$val <= 9999999);
}
function isValidYearsResident(string $val): bool {
    return ctype_digit($val) && (int)$val >= 0 && (int)$val <= 120;
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['acc_id'])) {
    header('Location: ../login.php');
    exit;
}

$accId = $_SESSION['acc_id'] ?? null;
$role  = $_SESSION['account_role'] ?? 'non-resident';
if (is_array($role)) $role = implode(',', $role);
$normalizedRole = strtolower(trim($role));
$roleParts      = array_map('trim', explode(',', $normalizedRole));

if (in_array('admin', $roleParts, true)) {
    header('Location: ../admin/adminLanding.php');
    exit;
}

// ─── Handle redirect status messages ───
$success_message = '';
$status = $_GET['status'] ?? '';
if ($status === 'profile_saved') {
    $success_message = 'profile_saved';
} elseif ($status === 'pending_submitted') {
    $success_message = 'pending_submitted';
} elseif ($status === 'error') {
    $success_message = 'error:' . htmlspecialchars($_GET['msg'] ?? 'An error occurred.');
}

$resident = [
    'name'                           => 'User',
    'role'                           => 'Non-Resident',
    'user_id'                        => $_SESSION['user_id'] ?? 'N/A',
    'firstname'                      => '',
    'middlename'                     => '',
    'lastname'                       => '',
    'suffix'                         => '',
    'family_role'                    => '',
    'gender'                         => '',
    'birthday'                       => '',
    'birthplace'                     => '',
    'civil_status'                   => '',
    'citizenship'                    => '',
    'religion'                       => '',
    'ethnicity'                      => '',
    'street'                         => '',
    'barangay'                       => '',
    'city'                           => '',
    'province'                       => '',
    'zip'                            => '',
    'phone'                          => '',
    'email'                          => '',
    'emergency_contact'              => '',
    'emergency_contact_relationship' => '',
    'emergency_phone'                => '',
    'health_conditions'              => '',
    'employment_status'              => '',
    'job_title'                      => '',
    'monthly_income'                 => '',
    'voter_id'                       => '',
    'precinct'                       => '',
    'years_resident'                 => '',
    'resident_birth'                 => '',
    'frontID'                        => '',
    'backID'                         => '',
    'account_role_csv'               => $normalizedRole,
    'userStatus'                     => 'Active',
    'pending_role'                   => '',
];

$account_status = [
    'status'       => 'Inactive',
    'member_since' => 'N/A',
    'last_login'   => $_SESSION['last_login'] ?? 'N/A',
];

if ($accId) {
    // Ensure columns exist
    $conn->query("ALTER TABLE tbl_userinfo ADD COLUMN IF NOT EXISTS emergency_contact_relationship VARCHAR(255) DEFAULT '' AFTER emergency_contact");
    $conn->query("ALTER TABLE tbl_userinfo ADD COLUMN IF NOT EXISTS frontID VARCHAR(500) DEFAULT '' AFTER resident_birth");
    $conn->query("ALTER TABLE tbl_userinfo ADD COLUMN IF NOT EXISTS backID  VARCHAR(500) DEFAULT '' AFTER frontID");
    $conn->query("ALTER TABLE tbl_userinfo ADD COLUMN IF NOT EXISTS pending_role VARCHAR(100) DEFAULT '' AFTER account_role_csv");

    // Fetch current profile
    $stmt = $conn->prepare(
        'SELECT userID, firstname, middlename, lastname, suffix, family_role, email, phone,
                birthday, birthplace, gender, civil_status, citizenship, religion, ethnicity,
                street, barangay, city, province, zip, emergency_contact,
                emergency_contact_relationship, emergency_phone, health_conditions,
                employment_status, job_title, monthly_income, voter_id, precinct,
                years_resident, resident_birth, frontID, backID,
                userStatus, dateRegistered, account_role_csv, pending_role
         FROM tbl_userinfo WHERE accID = ? LIMIT 1'
    );
    if ($stmt) {
        $stmt->bind_param('s', $accId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            foreach ($resident as $key => $_) {
                if (isset($row[$key])) $resident[$key] = $row[$key];
            }
            $resident['name']    = trim(($row['firstname'] ?? '') . ' ' . ($row['middlename'] ?? '') . ' ' . ($row['lastname'] ?? '')) ?: 'User';
            $resident['user_id'] = $row['userID'] ?? $resident['user_id'];
            $account_status['status']       = $row['userStatus'] ?? 'Inactive';
            $account_status['member_since'] = !empty($row['dateRegistered']) ? date('F j, Y', strtotime($row['dateRegistered'])) : 'N/A';
        }
        $stmt->close();
    }

    // Last login
    $lastLoginRaw = null;
    $checkColumn  = $conn->query("SHOW COLUMNS FROM tbl_useracc LIKE 'last_login'");
    if ($checkColumn && $checkColumn->num_rows > 0) {
        $loginStmt = $conn->prepare('SELECT last_login FROM tbl_useracc WHERE accID = ? LIMIT 1');
        if ($loginStmt) {
            $loginStmt->bind_param('s', $accId);
            $loginStmt->execute();
            $loginResult = $loginStmt->get_result();
            if ($loginRow = $loginResult->fetch_assoc()) $lastLoginRaw = $loginRow['last_login'] ?? null;
            $loginStmt->close();
        }
    }
    if (!empty($_SESSION['last_login'])) $lastLoginRaw = $_SESSION['last_login'];
    $account_status['last_login'] = !empty($lastLoginRaw) ? date('F j, Y', strtotime($lastLoginRaw)) : 'N/A';
}

// Determine which roles are currently active
$currentRoleCsv   = strtolower($resident['account_role_csv'] ?? $normalizedRole);
$currentRoleParts = array_map('trim', explode(',', $currentRoleCsv));
$hasResident      = in_array('resident', $currentRoleParts, true);
$hasNonResident   = in_array('non-resident', $currentRoleParts, true);
$hasOwner         = in_array('business/apartment owner', $currentRoleParts, true);

$defaultSelected = [];
if ($hasResident)    $defaultSelected[] = 'resident';
if ($hasNonResident) $defaultSelected[] = 'non-resident';
if ($hasOwner)       $defaultSelected[] = 'owner';
if (empty($defaultSelected)) $defaultSelected[] = 'non-resident';

// Is there a pending role change awaiting admin approval?
$isPending   = ($resident['userStatus'] === 'pending');
$pendingRole = $resident['pending_role'] ?? '';
?>
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
    <link rel="stylesheet" href="../assets/responsive-global.css">
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

        /* ─── Role cards ─── */
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

        /* ─── Sections ─── */
        .role-section { display: none; }

        /* ─── Upload zone ─── */
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

        /* ─── pending badge ─── */
        .badge-pending {
            display: inline-flex; align-items: center; gap: 5px;
            background: #fef3c7; color: #92400e; border: 1px solid #fde68a;
            font-size: 0.7rem; font-weight: 700; padding: 2px 10px;
            border-radius: 999px; text-transform: uppercase; letter-spacing: .05em;
        }

        /* ─── Submit button states ─── */
        #submitBtn:disabled {
            opacity: 0.45;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }
        #submitBtn:not(:disabled):hover {
            background-color: var(--site-primary);
        }

        /* ─── Toast ─── */
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

        /* ─── Required field indicator ─── */
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

        /* Tailwind-green ? theme color overrides */
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

        /* Tailwind-emerald ? theme color overrides (role cards / focus rings) */
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

<!-- ─── TOAST CONTAINER ─── -->
<div id="toast-container"></div>

<div class="min-h-screen">

    <!-- ─── HEADER ─── -->
    <header class="w-full h-[68px] border-b border-green-100 flex items-center px-6 md:px-8 bg-white shadow-sm sticky top-0 z-50">
        <div class="flex items-center gap-3">
            <a href="nonresidentLanding.php" class="flex items-center gap-3">
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
            <a href="nonresidentLanding.php#announcements" class="nav-link">Announcements</a>
            <a href="../busaptListing.php?type=business" class="nav-link">Business</a>
            <a href="../busaptListing.php?type=apartment" class="nav-link">Apartment</a>
            <?php $roleLower = strtolower($role); ?>
            <?php if (str_contains($roleLower, 'non-resident,business/apartment owner') || str_contains($roleLower, 'business/apartment owner') || str_contains($roleLower, 'business')): ?>
                <a href="manageList.php" class="nav-link">
                <i class="w-4 text-green-600"></i> Post Listing
                </a>
            <?php endif; ?>
        </nav>
        <button id="mobile-menu-btn" class="md:hidden ml-auto flex items-center justify-center p-2 text-gray-600 hover:text-green-700 hover:bg-green-50 rounded-lg transition">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
            </svg>
        </button>
    </header>

    <!-- Mobile sidebar -->
    <div id="mobile-sidebar-overlay" class="fixed inset-0 bg-black/50 z-[60] hidden opacity-0 transition-opacity duration-300"></div>
    <div id="mobile-sidebar" class="fixed inset-y-0 right-0 w-64 bg-white shadow-2xl transform translate-x-full transition-transform duration-300 z-[70] flex flex-col">
        <div class="p-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-bold text-green-900">Menu</h3>
            <button id="mobile-menu-close" class="p-2 text-gray-500 hover:text-red-500 rounded-full hover:bg-red-50 transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
        <div class="flex-1 overflow-y-auto py-4">
            <nav class="flex flex-col gap-2 px-4">
                <a href="nonresidentLanding.php#announcements" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 font-medium hover:bg-green-50 hover:text-green-700 transition"><i class="fa-solid fa-bullhorn w-4 text-green-500"></i>Announcements</a>
                <a href="../busaptListing.php?type=business" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 font-medium hover:bg-green-50 hover:text-green-700 transition"><i class="fa-solid fa-store w-4 text-green-500"></i>Business</a>
                <a href="../busaptListing.php?type=apartment" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 font-medium hover:bg-green-50 hover:text-green-700 transition"><i class="fa-solid fa-building w-4 text-green-500"></i>Apartment</a>
                <?php if (str_contains($roleLower, 'non-resident,business/apartment owner') || str_contains($roleLower, 'business') && !str_contains($roleLower, 'resident')): ?>
                <a href="manageList.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 font-medium hover:bg-[var(--site-primary-pale)] hover:text-[var(--site-primary-dark)] transition">
                    <i class="fa-solid fa-plus w-4 text-[var(--site-primary)]"></i> Post Listing
                </a>
                <?php endif; ?>
                <div class="h-px bg-gray-100 my-2"></div>
                <a href="../logout.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-red-600 font-medium hover:bg-red-50 transition"><i class="fa-solid fa-arrow-right-from-bracket w-4"></i>Logout</a>
            </nav>
        </div>
    </div>

    <main class="mx-auto max-w-6xl px-4 py-8 md:px-6 md:py-10 space-y-8">

        <!-- ─── STATUS MESSAGES (fallback for non-JS) ─── -->
        <?php if ($success_message === 'profile_saved'): ?>
            <div class="rounded-lg bg-emerald-100 border border-emerald-200 text-emerald-800 p-4">
                <p class="font-medium">? Profile updated successfully.</p>
            </div>
        <?php elseif ($success_message === 'pending_submitted'): ?>
            <div class="rounded-lg bg-blue-100 border border-blue-200 text-blue-800 p-4">
                <p class="font-bold">? Role change request submitted!</p>
                <p class="text-sm mt-1">Your profile has been saved and your account is now <strong>pending review</strong>. An admin will approve your role change shortly.</p>
            </div>
        <?php elseif (str_starts_with($success_message, 'error:')): ?>
            <div class="rounded-lg bg-red-100 border border-red-200 text-red-800 p-4">
                <p class="font-medium">? <?php echo htmlspecialchars(substr($success_message, 6)); ?></p>
            </div>
        <?php endif; ?>

        <!-- ─── PENDING NOTICE (already pending) ─── -->
        <?php if ($isPending && $pendingRole): ?>
            <div class="rounded-lg bg-amber-50 border-2 border-amber-300 p-5 shadow-sm">
                <div class="flex items-start gap-4">
                    <i class="fas fa-hourglass-half text-amber-500 text-2xl mt-0.5 flex-shrink-0"></i>
                    <div>
                        <h3 class="font-bold text-amber-900 text-base">Your account is pending admin approval</h3>
                        <p class="text-amber-800 text-sm mt-1">
                            You requested the <strong><?php echo htmlspecialchars(ucwords($pendingRole)); ?></strong> role.
                            Your role has been updated but requires admin confirmation.
                        </p>
                        <p class="text-amber-700 text-xs mt-2">You can still edit your profile information while waiting for approval.</p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- ─── PAGE HEADER ─── -->
        <section class="rounded-2xl border border-green-100 bg-white p-6 md:p-8 shadow-sm">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <h1 class="text-3xl md:text-4xl font-bold text-slate-900">Edit Profile</h1>
                    <p class="text-sm text-slate-500 mt-1">Update your information and manage your role</p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="nonresidentLanding.php" class="inline-flex items-center gap-2 rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-100">
                        <i class="fas fa-arrow-left"></i> Back to Portal
                    </a>
                    <a href="nonresidentProfile.php" class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        <i class="fas fa-user-circle"></i> View Profile
                    </a>
                </div>
            </div>
        </section>

        <!-- ─── TABS ─── -->
        <div class="w-full mx-auto">
            <div class="rounded-2xl flex w-full gap-3 mb-6 bg-white py-4 p-6 md:p-8">
                <a href="nonresidentEditProfile.php" class="flex-1 text-center px-5 py-2 bg-emerald-600 text-white font-semibold rounded-lg inline-flex items-center justify-center gap-2 shadow-sm hover:bg-emerald-700 transition">
                    <i class="fas fa-user-circle"></i> Profile
                </a>
                <a href="nonresidentEditPassword.php" class="flex-1 text-center px-5 py-2 bg-white text-slate-700 font-semibold rounded-lg border border-slate-200 inline-flex items-center justify-center gap-2 hover:bg-slate-50 transition">
                    <i class="fas fa-lock"></i> Account
                </a>
            </div>

            <!-- ════════════════════════════════════════════
                 ROLE SELECTOR
            ════════════════════════════════════════════ -->
            <div class="bg-white border border-green-100 rounded-2xl p-6 shadow-sm mb-6">
                <div class="flex items-center justify-between mb-1">
                    <h3 class="text-lg font-bold text-slate-900 flex items-center gap-2">
                        <i class="fas fa-id-card text-emerald-600"></i> Choose Your Role
                    </h3>
                    <?php if ($isPending): ?>
                        <span class="badge-pending"><i class="fas fa-clock"></i> Pending Approval</span>
                    <?php endif; ?>
                </div>
                <p class="text-sm text-slate-500 mb-5">
                    You can combine <strong>Owner</strong> with either <strong>Resident</strong> or <strong>Non-Resident</strong>,
                    but not both at the same time. Changing your role will immediately update your account and set it to <strong>pending review</strong>.
                </p>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4" id="roleCardGrid">

                    <!-- Non-Resident -->
                    <div class="role-card p-4 border-2 border-slate-200 rounded-lg"
                         data-role="non-resident"
                         onclick="toggleRole('non-resident')">
                        <div class="flex items-start gap-3">
                            <i class="fas fa-briefcase text-2xl text-blue-600 pt-1"></i>
                            <div class="flex-1">
                                <h4 class="font-bold text-slate-900">Non-Resident</h4>
                                <p class="text-xs text-slate-500 mt-1">Outside the barangay</p>
                            </div>
                            <div class="role-check items-center justify-center text-emerald-600">
                                <i class="fas fa-check-circle text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Resident -->
                    <div class="role-card p-4 border-2 border-slate-200 rounded-lg"
                         data-role="resident"
                         onclick="toggleRole('resident')">
                        <div class="flex items-start gap-3">
                            <i class="fas fa-home text-2xl text-green-600 pt-1"></i>
                            <div class="flex-1">
                                <h4 class="font-bold text-slate-900">Resident</h4>
                                <p class="text-xs text-slate-500 mt-1">Lives in <?= e($siteSettings['barangay_name']) ?></p>
                            </div>
                            <div class="role-check items-center justify-center text-emerald-600">
                                <i class="fas fa-check-circle text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Owner -->
                    <div class="role-card p-4 border-2 border-slate-200 rounded-lg"
                         data-role="owner"
                         onclick="toggleRole('owner')">
                        <div class="flex items-start gap-3">
                            <i class="fas fa-building text-2xl text-orange-500 pt-1"></i>
                            <div class="flex-1">
                                <h4 class="font-bold text-slate-900">Owner</h4>
                                <p class="text-xs text-slate-500 mt-1">Business / Apartment owner</p>
                            </div>
                            <div class="role-check items-center justify-center text-emerald-600">
                                <i class="fas fa-check-circle text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Conflict warning -->
                <div id="roleConflictNote" style="display:none;" class="mt-3 flex items-center gap-2 text-sm font-medium bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-lg px-4 py-3">
                    <i class="fas fa-triangle-exclamation text-yellow-500 flex-shrink-0"></i>
                    <span>You cannot be both <strong>Resident</strong> and <strong>Non-Resident</strong> at the same time.</span>
                </div>

                <!-- Role change warning banner -->
                <div id="roleChangeWarning" style="display:none;" class="mt-3 flex items-center gap-2 text-sm font-medium bg-amber-50 border border-amber-200 text-amber-800 rounded-lg px-4 py-3">
                    <i class="fas fa-exclamation-circle text-amber-500 flex-shrink-0"></i>
                    <span id="roleChangeWarningText"></span>
                </div>

                <input type="hidden" id="selectedRolesJson" value="">
                <input type="hidden" name="selectedRole" id="selectedRoleHidden" value="<?php echo htmlspecialchars($currentRoleCsv); ?>">
            </div>

            <!-- ════════════════════════════════════════════
                 MAIN GRID (sidebar + form)
            ════════════════════════════════════════════ -->
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">

                <!-- Sidebar -->
                <aside class="lg:col-span-1 space-y-6 top-24">
                    <div class="bg-white border border-green-100 rounded-2xl p-6 shadow-sm">
                        <div class="text-center">
                            <div class="w-28 h-28 mx-auto rounded-full border-4 border-emerald-100 bg-emerald-50 flex items-center justify-center mb-4">
                                <i class="fas fa-id-badge text-4xl text-emerald-700"></i>
                            </div>
                            <h2 class="text-xl font-bold text-slate-900"><?php echo htmlspecialchars($resident['name']); ?></h2>
                            <p class="text-sm text-emerald-600 font-semibold mt-1"><?php echo htmlspecialchars(ucwords($currentRoleCsv)); ?></p>
                            <p class="text-xs text-slate-500 mt-1">User ID: <?php echo htmlspecialchars($resident['user_id']); ?></p>
                        </div>
                    </div>
                    <div class="bg-white border border-green-100 rounded-2xl p-6 shadow-sm">
                        <h3 class="text-lg font-bold text-slate-900 mb-4">Account Status</h3>
                        <div class="space-y-3 text-sm text-slate-700">
                            <div class="flex justify-between items-center">
                                <span class="text-slate-500">Status</span>
                                <?php
                                $st = $account_status['status'];
                                $stColor = match(strtolower($st)) {
                                    'active'  => 'text-emerald-700 bg-emerald-50 border-emerald-200',
                                    'pending' => 'text-amber-700 bg-amber-50 border-amber-200',
                                    default   => 'text-slate-600 bg-slate-50 border-slate-200',
                                };
                                ?>
                                <span class="font-semibold text-xs border px-2 py-0.5 rounded-full <?php echo $stColor; ?>"><?php echo htmlspecialchars(ucfirst($st)); ?></span>
                            </div>
                            <div class="flex justify-between"><span class="text-slate-500">Member Since</span><span class="font-semibold"><?php echo htmlspecialchars($account_status['member_since']); ?></span></div>
                            <div class="flex justify-between"><span class="text-slate-500">Last Login</span><span class="font-semibold"><?php echo htmlspecialchars($account_status['last_login']); ?></span></div>
                        </div>
                    </div>
                </aside>

                <!-- FORM -->
                <section class="lg:col-span-3 space-y-6">
                    <form method="POST" action="nonresidentRoleChangeAction.php" enctype="multipart/form-data" id="profileForm">

                        <!-- ─── PERSONAL INFORMATION ─── -->
                        <div class="bg-white border border-green-100 rounded-2xl p-6 shadow-sm">
                            <div class="flex items-center justify-between mb-5">
                                <h2 class="text-2xl font-bold text-slate-900"><i class="fas fa-id-card text-emerald-500 mr-2"></i> Personal Information</h2>
                                <span class="text-xs uppercase tracking-wider text-emerald-600 font-semibold">Editable</span>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="text-sm font-semibold text-slate-700">First Name</label>
                                    <input type="text" name="firstname" value="<?php echo htmlspecialchars($resident['firstname']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-100" readonly required />
                                </div>
                                <div>
                                    <label class="text-sm font-semibold text-slate-700">Middle Name</label>
                                    <input type="text" name="middlename" value="<?php echo htmlspecialchars($resident['middlename']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-100" readonly />
                                </div>
                                <div>
                                    <label class="text-sm font-semibold text-slate-700">Last Name</label>
                                    <input type="text" name="lastname" value="<?php echo htmlspecialchars($resident['lastname']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-100" readonly required />
                                </div>
                            </div>

                            <div class="mt-5 grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="text-sm font-semibold text-slate-700">Suffix</label>
                                    <input type="text" name="suffix" value="<?php echo htmlspecialchars($resident['suffix']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-100" readonly />
                                </div>
                                <div>
                                    <label class="text-sm font-semibold text-slate-700">Family Role <span class="text-red-500">*</span></label>
                                    <select name="family_role" data-original="<?php echo htmlspecialchars($resident['family_role']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 focus:ring-emerald-500 focus:border-emerald-500 tracked-field" required>
                                        <option value="">Select Role</option>
                                        <?php foreach (['head' => 'Head of Family', 'spouse' => 'Spouse', 'child' => 'Child', 'parent' => 'Parent', 'other' => 'Other'] as $v => $l): ?>
                                            <option value="<?php echo $v; ?>" <?php echo $resident['family_role'] === $v ? 'selected' : ''; ?>><?php echo $l; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-sm font-semibold text-slate-700">Gender</label>
                                    <select name="gender_display" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-100" disabled>
                                        <option value="">Select Gender</option>
                                        <?php foreach (['male' => 'Male', 'female' => 'Female', 'other' => 'Other'] as $v => $l): ?>
                                            <option value="<?php echo $v; ?>" <?php echo $resident['gender'] === $v ? 'selected' : ''; ?>><?php echo $l; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="hidden" name="gender" value="<?php echo htmlspecialchars($resident['gender']); ?>" />
                                </div>
                            </div>

                            <div class="mt-5 grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="text-sm font-semibold text-slate-700">Birthday</label>
                                    <input type="date" name="birthday" value="<?php echo htmlspecialchars($resident['birthday']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-100" readonly required />
                                </div>
                                <div>
                                    <label class="text-sm font-semibold text-slate-700">Birthplace</label>
                                    <input type="text" name="birthplace" value="<?php echo htmlspecialchars($resident['birthplace']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-100" readonly />
                                </div>
                                <div>
                                    <label class="text-sm font-semibold text-slate-700">Civil Status <span class="text-red-500">*</span></label>
                                    <select name="civil_status" data-original="<?php echo htmlspecialchars($resident['civil_status']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 focus:ring-emerald-500 focus:border-emerald-500 tracked-field" required>
                                        <option value="">Select Civil Status</option>
                                        <?php foreach (['single' => 'Single', 'married' => 'Married', 'divorced' => 'Divorced', 'widowed' => 'Widowed', 'separated' => 'Separated'] as $v => $l): ?>
                                            <option value="<?php echo $v; ?>" <?php echo $resident['civil_status'] === $v ? 'selected' : ''; ?>><?php echo $l; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="mt-5 grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="text-sm font-semibold text-slate-700">Citizenship</label>
                                    <input type="text" name="citizenship" value="<?php echo htmlspecialchars($resident['citizenship']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-100" readonly />
                                </div>
                                <div>
                                    <label class="text-sm font-semibold text-slate-700">Religion</label>
                                    <input type="text" name="religion" value="<?php echo htmlspecialchars($resident['religion']); ?>" data-original="<?php echo htmlspecialchars($resident['religion']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 focus:ring-emerald-500 focus:border-emerald-500 tracked-field" />
                                </div>
                                <div>
                                    <label class="text-sm font-semibold text-slate-700">Ethnicity</label>
                                    <input type="text" name="ethnicity" value="<?php echo htmlspecialchars($resident['ethnicity']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-100" readonly />
                                </div>
                            </div>

                            <div class="mt-5 grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="text-sm font-semibold text-slate-700">Email Address <span class="text-red-500">*</span></label>
                                    <input type="email" name="email" value="<?php echo htmlspecialchars($resident['email']); ?>" data-original="<?php echo htmlspecialchars($resident['email']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 focus:ring-emerald-500 focus:border-emerald-500 tracked-field" required />
                                </div>
                                <div>
                                    <label class="text-sm font-semibold text-slate-700">Phone Number <span class="text-red-500">*</span></label>
                                    <input type="tel" name="phone" value="<?php echo htmlspecialchars($resident['phone']); ?>" data-original="<?php echo htmlspecialchars($resident['phone']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 focus:ring-emerald-500 focus:border-emerald-500 tracked-field" required />
                                </div>
                            </div>
                        </div>

                        <!-- ─── ADDRESS (resident only) ─── -->
                        <div class="bg-white border border-green-100 rounded-2xl p-6 shadow-sm role-section" id="address-section">
                            <div class="flex items-center justify-between mb-5">
                                <h2 class="text-2xl font-bold text-slate-900"><i class="fas fa-map-marker-alt text-emerald-500 mr-2"></i> Address Information</h2>
                                <span class="text-xs uppercase tracking-wider text-red-500 font-semibold">Required for Resident</span>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="text-sm font-semibold text-slate-700">Street <span class="text-red-500" id="street-required-star">*</span></label>
                                    <input type="text" name="street" value="<?php echo htmlspecialchars($resident['street']); ?>" data-original="<?php echo htmlspecialchars($resident['street']); ?>" data-resident-required="true" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 focus:ring-emerald-500 focus:border-emerald-500 tracked-field resident-required" />
                                </div>
                                <div>
                                    <label class="text-sm font-semibold text-slate-700">Barangay <span class="text-red-500">*</span></label>
                                    <input type="text" name="barangay" value="<?php echo htmlspecialchars($resident['barangay']); ?>" data-original="<?php echo htmlspecialchars($resident['barangay']); ?>" data-resident-required="true" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 focus:ring-emerald-500 focus:border-emerald-500 tracked-field resident-required" />
                                </div>
                            </div>
                            <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="text-sm font-semibold text-slate-700">City / Municipality <span class="text-red-500">*</span></label>
                                    <input type="text" name="city" value="<?php echo htmlspecialchars($resident['city']); ?>" data-original="<?php echo htmlspecialchars($resident['city']); ?>" data-resident-required="true" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 focus:ring-emerald-500 focus:border-emerald-500 tracked-field resident-required" />
                                </div>
                                <div>
                                    <label class="text-sm font-semibold text-slate-700">Province <span class="text-red-500">*</span></label>
                                    <input type="text" name="province" value="<?php echo htmlspecialchars($resident['province']); ?>" data-original="<?php echo htmlspecialchars($resident['province']); ?>" data-resident-required="true" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 focus:ring-emerald-500 focus:border-emerald-500 tracked-field resident-required" />
                                </div>
                            </div>
                            <div class="mt-4 md:w-1/3">
                                <label class="text-sm font-semibold text-slate-700">ZIP Code <span class="text-red-500">*</span></label>
                                <input type="text" name="zip" value="<?php echo htmlspecialchars($resident['zip']); ?>" data-original="<?php echo htmlspecialchars($resident['zip']); ?>" data-resident-required="true" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 focus:ring-emerald-500 focus:border-emerald-500 tracked-field resident-required" />
                            </div>
                        </div>

                        <!-- ─── EMERGENCY CONTACT (always visible) ─── -->
                        <div class="bg-white border border-green-100 rounded-2xl p-6 shadow-sm">
                            <div class="flex items-center justify-between mb-5">
                                <h2 class="text-2xl font-bold text-slate-900"><i class="fas fa-phone-alt text-emerald-500 mr-2"></i> Emergency Contact &amp; Health</h2>
                                <span class="text-xs uppercase tracking-wider text-emerald-600 font-semibold">Optional</span>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="text-sm font-semibold text-slate-700">Emergency Contact Name</label>
                                    <input type="text" name="emergency_contact" value="<?php echo htmlspecialchars($resident['emergency_contact']); ?>" data-original="<?php echo htmlspecialchars($resident['emergency_contact']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 focus:ring-emerald-500 focus:border-emerald-500 tracked-field" />
                                </div>
                                <div>
                                    <label class="text-sm font-semibold text-slate-700">Relationship</label>
                                    <input type="text" name="emergency_contact_relationship" value="<?php echo htmlspecialchars($resident['emergency_contact_relationship']); ?>" data-original="<?php echo htmlspecialchars($resident['emergency_contact_relationship']); ?>" placeholder="e.g. Spouse, Parent" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 focus:ring-emerald-500 focus:border-emerald-500 tracked-field" />
                                </div>
                            </div>
                            <div class="mt-4">
                                <label class="text-sm font-semibold text-slate-700">Emergency Contact Number</label>
                                <input type="tel" name="emergency_phone" value="<?php echo htmlspecialchars($resident['emergency_phone']); ?>" data-original="<?php echo htmlspecialchars($resident['emergency_phone']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 focus:ring-emerald-500 focus:border-emerald-500 tracked-field" />
                            </div>
                            <div class="mt-4">
                                <label class="text-sm font-semibold text-slate-700">Health Conditions / Allergies</label>
                                <textarea name="health_conditions" rows="3" data-original="<?php echo htmlspecialchars($resident['health_conditions']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 focus:ring-emerald-500 focus:border-emerald-500 tracked-field" placeholder="Any health conditions or allergies (optional)"><?php echo htmlspecialchars($resident['health_conditions']); ?></textarea>
                            </div>
                        </div>

                        <!-- ─── EMPLOYMENT (resident only) ─── -->
                        <div class="bg-white border border-green-100 rounded-2xl p-6 shadow-sm role-section resident-only" id="employment-section">
                            <div class="flex items-center justify-between mb-5">
                                <h2 class="text-2xl font-bold text-slate-900"><i class="fas fa-briefcase text-emerald-500 mr-2"></i> Employment &amp; Voter Information</h2>
                                <span class="text-xs uppercase tracking-wider text-red-500 font-semibold">Required for Resident</span>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="text-sm font-semibold text-slate-700">Employment Status <span class="text-red-500">*</span></label>
                                    <select name="employment_status" data-original="<?php echo htmlspecialchars($resident['employment_status']); ?>" data-resident-required="true" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 focus:ring-emerald-500 focus:border-emerald-500 tracked-field resident-required">
                                        <option value="">Select Employment Status</option>
                                        <?php foreach (['employed' => 'Employed', 'self-employed' => 'Self-Employed', 'unemployed' => 'Unemployed', 'student' => 'Student', 'retired' => 'Retired', 'other' => 'Other'] as $v => $l): ?>
                                            <option value="<?php echo $v; ?>" <?php echo $resident['employment_status'] === $v ? 'selected' : ''; ?>><?php echo $l; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-sm font-semibold text-slate-700">Job Title</label>
                                    <input type="text" name="job_title" value="<?php echo htmlspecialchars($resident['job_title']); ?>" data-original="<?php echo htmlspecialchars($resident['job_title']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 focus:ring-emerald-500 focus:border-emerald-500 tracked-field" />
                                </div>
                            </div>
                            <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="text-sm font-semibold text-slate-700">Monthly Income</label>
                                    <input type="number" name="monthly_income" value="<?php echo htmlspecialchars($resident['monthly_income']); ?>" data-original="<?php echo htmlspecialchars($resident['monthly_income']); ?>" min="0" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 focus:ring-emerald-500 focus:border-emerald-500 tracked-field" />
                                </div>
                                <div>
                                    <label class="text-sm font-semibold text-slate-700">Years as Resident</label>
                                    <input type="number" name="years_resident" value="<?php echo htmlspecialchars($resident['years_resident']); ?>" data-original="<?php echo htmlspecialchars($resident['years_resident']); ?>" min="0" max="120" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 focus:ring-emerald-500 focus:border-emerald-500 tracked-field" />
                                </div>
                            </div>
                            <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="text-sm font-semibold text-slate-700">Voter ID</label>
                                    <input type="text" name="voter_id" value="<?php echo htmlspecialchars($resident['voter_id']); ?>" data-original="<?php echo htmlspecialchars($resident['voter_id']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 focus:ring-emerald-500 focus:border-emerald-500 tracked-field" />
                                </div>
                                <div>
                                    <label class="text-sm font-semibold text-slate-700">Precinct Number</label>
                                    <input type="text" name="precinct" value="<?php echo htmlspecialchars($resident['precinct']); ?>" data-original="<?php echo htmlspecialchars($resident['precinct']); ?>" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 focus:ring-emerald-500 focus:border-emerald-500 tracked-field" />
                                </div>
                            </div>
                            <div class="mt-4">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" name="resident_birth" value="1" id="residentBirthCheck" data-original="<?php echo ($resident['resident_birth'] == '1' ? '1' : '0'); ?>" <?php echo ($resident['resident_birth'] == '1' ? 'checked' : ''); ?> class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500 tracked-checkbox" />
                                    <span class="text-sm font-semibold text-slate-700">Born in this barangay</span>
                                </label>
                            </div>
                        </div>

                        <!-- ─── ID VERIFICATION (resident only) ─── -->
                        <div class="bg-white border border-green-100 rounded-2xl p-6 shadow-sm role-section resident-only" id="id-verification-section">
                            <div class="flex items-center justify-between mb-5">
                                <h2 class="text-2xl font-bold text-slate-900"><i class="fas fa-id-card text-emerald-500 mr-2"></i> ID Verification</h2>
                                <span class="text-xs uppercase tracking-wider text-red-500 font-semibold">Required for Resident</span>
                            </div>

                            <!-- Notice when IDs already exist -->
                            <?php if (!empty($resident['frontID']) || !empty($resident['backID'])): ?>
                            <div class="mb-4 flex items-center gap-2 text-sm bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-lg px-4 py-3">
                                <i class="fas fa-circle-check text-emerald-500"></i>
                                <span>You have previously uploaded ID images. Upload new ones only if you want to replace them.</span>
                            </div>
                            <?php else: ?>
                            <div class="mb-4 flex items-center gap-2 text-sm bg-amber-50 border border-amber-200 text-amber-800 rounded-lg px-4 py-3">
                                <i class="fas fa-exclamation-circle text-amber-500"></i>
                                <span>Both front and back ID images are required when requesting Resident role.</span>
                            </div>
                            <?php endif; ?>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <!-- Front -->
                                <div>
                                    <div class="flex items-center gap-2 mb-3">
                                        <div class="w-7 h-7 rounded-lg bg-green-100 flex items-center justify-center">
                                            <i class="fas fa-id-card text-green-700 text-xs"></i>
                                        </div>
                                        <label class="font-bold text-slate-800 text-sm">ID Front Side</label>
                                    </div>
                                    <div class="upload-zone" id="frontZone"
                                         ondragover="dragOver(event,'frontZone')" ondragleave="dragLeave('frontZone')"
                                         ondrop="dropFile(event,'frontFile','frontZone','frontPreview','frontName')">
                                        <div id="frontPlaceholder" class="flex flex-col items-center gap-3 py-8 px-4 text-center">
                                            <div class="w-14 h-14 rounded-2xl bg-green-100 flex items-center justify-center">
                                                <i class="fas fa-cloud-arrow-up text-green-600 text-2xl"></i>
                                            </div>
                                            <p class="font-semibold text-slate-700 text-sm">Drag &amp; drop or click to upload</p>
                                            <p class="text-slate-400 text-xs">Front side of your government-issued ID</p>
                                        </div>
                                        <div id="frontPreview" class="absolute inset-0 hidden bg-cover bg-center rounded-[12px]"></div>
                                        <img id="frontExistingImg" class="absolute inset-0 object-cover hidden rounded-[12px]" style="width:100%;height:100%;" />
                                        <div id="frontName" class="absolute bottom-3 left-1/2 -translate-x-1/2 hidden bg-white/90 backdrop-blur-sm px-4 py-1.5 rounded-full shadow text-xs font-semibold text-green-800 max-w-[80%] truncate"></div>
                                        <input type="file" id="frontFile" name="id_front" accept=".jpg,.jpeg,.png,.pdf"
                                               class="absolute inset-0 opacity-0 cursor-pointer"
                                               data-existing="<?php echo htmlspecialchars($resident['frontID']); ?>"
                                               onchange="handleFile('frontFile','frontZone','frontPreview','frontName','frontPlaceholder','frontActions'); markFileChanged();">
                                    </div>
                                    <div id="frontActions" class="hidden flex gap-3 mt-3">
                                        <label for="frontFile" class="btn-upload"><i class="fas fa-arrow-up-from-bracket text-xs"></i> Replace</label>
                                        <button type="button" class="btn-remove" onclick="removeFile('frontFile','frontZone','frontPreview','frontName','frontPlaceholder','frontActions')">
                                            <i class="fas fa-trash text-xs"></i> Remove
                                        </button>
                                    </div>
                                </div>
                                <!-- Back -->
                                <div>
                                    <div class="flex items-center gap-2 mb-3">
                                        <div class="w-7 h-7 rounded-lg bg-green-100 flex items-center justify-center">
                                            <i class="fas fa-id-card-clip text-green-700 text-xs"></i>
                                        </div>
                                        <label class="font-bold text-slate-800 text-sm">ID Back Side</label>
                                    </div>
                                    <div class="upload-zone" id="backZone"
                                         ondragover="dragOver(event,'backZone')" ondragleave="dragLeave('backZone')"
                                         ondrop="dropFile(event,'backFile','backZone','backPreview','backName')">
                                        <div id="backPlaceholder" class="flex flex-col items-center gap-3 py-8 px-4 text-center">
                                            <div class="w-14 h-14 rounded-2xl bg-green-100 flex items-center justify-center">
                                                <i class="fas fa-cloud-arrow-up text-green-600 text-2xl"></i>
                                            </div>
                                            <p class="font-semibold text-slate-700 text-sm">Drag &amp; drop or click to upload</p>
                                            <p class="text-slate-400 text-xs">Back side of your government-issued ID</p>
                                        </div>
                                        <div id="backPreview" class="absolute inset-0 hidden bg-cover bg-center rounded-[12px]"></div>
                                        <img id="backExistingImg" class="absolute inset-0 object-cover hidden rounded-[12px]" style="width:100%;height:100%;" />
                                        <div id="backName" class="absolute bottom-3 left-1/2 -translate-x-1/2 hidden bg-white/90 backdrop-blur-sm px-4 py-1.5 rounded-full shadow text-xs font-semibold text-green-800 max-w-[80%] truncate"></div>
                                        <input type="file" id="backFile" name="id_back" accept=".jpg,.jpeg,.png,.pdf"
                                               class="absolute inset-0 opacity-0 cursor-pointer"
                                               data-existing="<?php echo htmlspecialchars($resident['backID']); ?>"
                                               onchange="handleFile('backFile','backZone','backPreview','backName','backPlaceholder','backActions'); markFileChanged();">
                                    </div>
                                    <div id="backActions" class="hidden flex gap-3 mt-3">
                                        <label for="backFile" class="btn-upload"><i class="fas fa-arrow-up-from-bracket text-xs"></i> Replace</label>
                                        <button type="button" class="btn-remove" onclick="removeFile('backFile','backZone','backPreview','backName','backPlaceholder','backActions')">
                                            <i class="fas fa-trash text-xs"></i> Remove
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ─── ACTIONS ─── -->
                        <div class="flex flex-col sm:flex-row gap-3 justify-end pt-2">
                            <a href="nonresidentProfile.php" class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white px-6 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                                <i class="fas fa-chevron-left mr-2"></i> Cancel
                            </a>
                            <button type="submit" id="submitBtn" disabled
                                    class="inline-flex items-center justify-center rounded-lg bg-emerald-600 px-6 py-3 text-sm font-semibold text-white transition-all duration-200">
                                <i class="fas fa-floppy-disk mr-2"></i>
                                <span id="submitBtnText">Save Changes</span>
                            </button>
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </main>
</div>

<script>
/* ════════════════════════════════════════════
   TOAST SYSTEM
════════════════════════════════════════════ */
function showToast(type, title, msg, duration = 5000) {
    const container = document.getElementById('toast-container');
    const id = 'toast-' + Date.now();

    const icons = {
        success: '<i class="fas fa-circle-check text-emerald-500 toast-icon"></i>',
        info:    '<i class="fas fa-circle-info text-blue-500 toast-icon"></i>',
        error:   '<i class="fas fa-circle-xmark text-red-500 toast-icon"></i>',
        warning: '<i class="fas fa-triangle-exclamation text-amber-500 toast-icon"></i>',
    };

    const toast = document.createElement('div');
    toast.id = id;
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `
        ${icons[type] || icons.info}
        <div class="toast-body">
            <div class="toast-title">${title}</div>
            ${msg ? `<div class="toast-msg">${msg}</div>` : ''}
        </div>
        <span class="toast-close" onclick="dismissToast('${id}')"><i class="fas fa-xmark"></i></span>
    `;
    container.appendChild(toast);

    setTimeout(() => dismissToast(id), duration);
}

function dismissToast(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.add('toast-hide');
    setTimeout(() => el.remove(), 320);
}

/* ─── Show PHP-generated toasts on load ─── */
<?php if ($success_message === 'profile_saved'): ?>
window.addEventListener('DOMContentLoaded', () => showToast('success', 'Profile Saved', 'Your profile information has been updated successfully.'));
<?php elseif ($success_message === 'pending_submitted'): ?>
window.addEventListener('DOMContentLoaded', () => showToast('info', 'Role Change Submitted', 'Your role has been updated and is pending admin approval.'));
<?php elseif (str_starts_with($success_message, 'error:')): ?>
window.addEventListener('DOMContentLoaded', () => showToast('error', 'Error', <?php echo json_encode(substr($success_message, 6)); ?>));
<?php endif; ?>

/* ════════════════════════════════════════════
   MOBILE MENU
════════════════════════════════════════════ */
const mobileMenuBtn        = document.getElementById('mobile-menu-btn');
const mobileSidebar        = document.getElementById('mobile-sidebar');
const mobileSidebarOverlay = document.getElementById('mobile-sidebar-overlay');
const mobileMenuClose      = document.getElementById('mobile-menu-close');

function openMobileMenu() {
    mobileSidebarOverlay.classList.remove('hidden');
    setTimeout(() => {
        mobileSidebarOverlay.classList.remove('opacity-0');
        mobileSidebarOverlay.classList.add('opacity-100');
        mobileSidebar.classList.remove('translate-x-full');
    }, 10);
}
function closeMobileMenu() {
    mobileSidebar.classList.add('translate-x-full');
    mobileSidebarOverlay.classList.remove('opacity-100');
    mobileSidebarOverlay.classList.add('opacity-0');
    setTimeout(() => mobileSidebarOverlay.classList.add('hidden'), 300);
}
mobileMenuBtn?.addEventListener('click', openMobileMenu);
mobileMenuClose?.addEventListener('click', closeMobileMenu);
mobileSidebarOverlay?.addEventListener('click', closeMobileMenu);

/* ════════════════════════════════════════════
   CHANGE DETECTION - enable Save only when
   something actually changed
════════════════════════════════════════════ */
const isPending     = <?php echo json_encode($isPending); ?>;
const currentRole   = <?php echo json_encode($currentRoleCsv); ?>;
const selectedRoles = new Set(<?php echo json_encode($defaultSelected); ?>);

let fileChanged    = false;  // set true when user picks an ID file
let formHasChanges = false;

function markFileChanged() {
    fileChanged = true;
    checkForChanges();
}

function checkForChanges() {
    // 1. Tracked input/select/textarea fields
    let fieldChanged = false;
    document.querySelectorAll('.tracked-field').forEach(el => {
        const original = el.getAttribute('data-original') ?? '';
        if (el.value.trim() !== original.trim()) fieldChanged = true;
    });

    // 2. Tracked checkboxes
    document.querySelectorAll('.tracked-checkbox').forEach(cb => {
        const original = cb.getAttribute('data-original') ?? '0';
        const current  = cb.checked ? '1' : '0';
        if (current !== original) fieldChanged = true;
    });

    // 3. Role change
    const roleChanged = (buildRoleString() !== currentRole);

    formHasChanges = fieldChanged || fileChanged || roleChanged;

    const btn = document.getElementById('submitBtn');
    btn.disabled = !formHasChanges;

    // Update button label
    const btnText = document.getElementById('submitBtnText');
    if (roleChanged && !isPending) {
        btnText.textContent = 'Submit & Request Role Change';
    } else {
        btnText.textContent = 'Save Changes';
    }
}

// Attach listeners to all tracked fields
document.querySelectorAll('.tracked-field, .tracked-checkbox').forEach(el => {
    el.addEventListener('input',  checkForChanges);
    el.addEventListener('change', checkForChanges);
});

/* ════════════════════════════════════════════
   ROLE SELECTION LOGIC
════════════════════════════════════════════ */
function toggleRole(role) {
    if (role === 'resident') {
        if (selectedRoles.has('resident')) {
            // Don't deselect if it's the only non-owner role
            if (!selectedRoles.has('owner') || selectedRoles.size > 2) {
                selectedRoles.delete('resident');
                if (selectedRoles.size === 0 || (selectedRoles.size === 1 && selectedRoles.has('owner'))) {
                    selectedRoles.add('non-resident');
                }
            }
        } else {
            selectedRoles.delete('non-resident');
            selectedRoles.add('resident');
        }
    } else if (role === 'non-resident') {
        if (selectedRoles.has('non-resident')) {
            if (!selectedRoles.has('owner') || selectedRoles.size > 2) {
                selectedRoles.delete('non-resident');
                if (selectedRoles.size === 0 || (selectedRoles.size === 1 && selectedRoles.has('owner'))) {
                    selectedRoles.add('resident');
                }
            }
        } else {
            selectedRoles.delete('resident');
            selectedRoles.add('non-resident');
        }
    } else if (role === 'owner') {
        if (selectedRoles.has('owner')) {
            if (selectedRoles.size > 1) selectedRoles.delete('owner');
        } else {
            selectedRoles.add('owner');
        }
    }

    renderRoleCards();
    updateFormState();
    checkForChanges();
}

function renderRoleCards() {
    document.querySelectorAll('.role-card').forEach(card => {
        const r = card.getAttribute('data-role');
        card.classList.toggle('selected', selectedRoles.has(r));
    });
}

function buildRoleString() {
    const parts = [];
    if (selectedRoles.has('resident'))     parts.push('resident');
    if (selectedRoles.has('non-resident')) parts.push('non-resident');
    if (selectedRoles.has('owner'))        parts.push('business/apartment owner');
    return parts.join(',');
}

function getPrimaryRole() {
    if (selectedRoles.has('resident'))     return 'resident';
    if (selectedRoles.has('non-resident')) return 'non-resident';
    return 'non-resident';
}

function updateFormState() {
    const isResident = (getPrimaryRole() === 'resident');
    const roleStr    = buildRoleString();
    const roleChanged = (roleStr !== currentRole);

    // Show/hide role-based sections
    document.getElementById('address-section').style.display         = isResident ? 'block' : 'none';
    document.getElementById('employment-section').style.display      = isResident ? 'block' : 'none';
    document.getElementById('id-verification-section').style.display = isResident ? 'block' : 'none';

    document.getElementById('selectedRoleHidden').value = roleStr;
    document.getElementById('selectedRolesJson').value  = JSON.stringify([...selectedRoles]);

    // Role change warning
    const warningEl   = document.getElementById('roleChangeWarning');
    const warningText = document.getElementById('roleChangeWarningText');
    if (roleChanged) {
        const newLabel = isResident ? 'Resident' : 'Non-Resident';
        warningText.innerHTML = `Changing to <strong>${newLabel}${selectedRoles.has('owner') ? ' + Owner' : ''}</strong> will immediately update your account role and set it to <strong>pending admin review</strong>.`;
        warningEl.style.display = 'flex';
    } else {
        warningEl.style.display = 'none';
    }

    if (isResident) loadExistingIdFiles();
}

// ─── Initial render ───
renderRoleCards();
updateFormState();
checkForChanges();

/* ─── Sync hidden input on submit ─── */
document.getElementById('profileForm').addEventListener('submit', function (e) {
    document.getElementById('selectedRoleHidden').value = buildRoleString();

    // Client-side validation for resident required fields
    const isResident = (getPrimaryRole() === 'resident');
    if (isResident) {
        const requiredFields = ['street', 'barangay', 'city', 'province', 'zip', 'employment_status'];
        let firstInvalid = null;
        let hasError = false;

        requiredFields.forEach(fieldName => {
            const el = document.querySelector(`[name="${fieldName}"]`);
            if (el && !el.value.trim()) {
                el.classList.add('required-field-indicator');
                hasError = true;
                if (!firstInvalid) firstInvalid = el;

                // Add or update error message
                let errMsg = el.parentElement.querySelector('.field-error-msg');
                if (!errMsg) {
                    errMsg = document.createElement('span');
                    errMsg.className = 'field-error-msg';
                    el.parentElement.appendChild(errMsg);
                }
                const labels = { street: 'Street', barangay: 'Barangay', city: 'City / Municipality', province: 'Province', zip: 'ZIP Code', employment_status: 'Employment Status' };
                errMsg.textContent = `${labels[fieldName] || fieldName} is required for Resident role.`;

                // Auto-clear on input
                el.addEventListener('input', function clearErr() {
                    el.classList.remove('required-field-indicator');
                    if (errMsg) errMsg.remove();
                    el.removeEventListener('input', clearErr);
                }, { once: true });
            }
        });

        if (hasError) {
            e.preventDefault();
            if (firstInvalid) firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
            showToast('error', 'Missing Required Fields', 'Please fill in all required fields for the Resident role.');
            return;
        }
    }

    // Disable button to prevent double submit
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Saving.';
});

/* ════════════════════════════════════════════
   ID FILE UPLOAD HANDLING
════════════════════════════════════════════ */
function loadExistingIdFiles() {
    const frontFile = document.getElementById('frontFile');
    const backFile  = document.getElementById('backFile');
    if (frontFile?.dataset.existing && frontFile.dataset.existing !== '') {
        displayExistingFile('frontZone','frontPreview','frontName','frontPlaceholder','frontActions','frontExistingImg', frontFile.dataset.existing);
    }
    if (backFile?.dataset.existing && backFile.dataset.existing !== '') {
        displayExistingFile('backZone','backPreview','backName','backPlaceholder','backActions','backExistingImg', backFile.dataset.existing);
    }
}

function displayExistingFile(zoneId, previewId, nameId, placeholderId, actionsId, existingImgId, filePath) {
    if (!filePath) return;
    const zone        = document.getElementById(zoneId);
    const existingImg = document.getElementById(existingImgId);
    const placeholder = document.getElementById(placeholderId);
    const actions     = document.getElementById(actionsId);
    const namePill    = document.getElementById(nameId);

    zone.classList.add('has-file');
    placeholder.classList.add('hidden');
    actions.classList.remove('hidden');

    let displayPath = filePath;
    if (!filePath.startsWith('../') && !filePath.startsWith('/')) {
        displayPath = '../uploads/id_verification/' + filePath;
    }

    if (/\.(jpg|jpeg|png|gif|webp)$/i.test(displayPath)) {
        existingImg.src = displayPath;
        existingImg.classList.remove('hidden');
        namePill.classList.add('hidden');
    } else {
        existingImg.classList.add('hidden');
        namePill.textContent = displayPath.split('/').pop();
        namePill.classList.remove('hidden');
    }
}

function handleFile(inputId, zoneId, previewId, nameId, placeholderId, actionsId) {
    const file = document.getElementById(inputId).files[0];
    if (!file) return;
    const zone     = document.getElementById(zoneId);
    const preview  = document.getElementById(previewId);
    const namePill = document.getElementById(nameId);
    const ph       = document.getElementById(placeholderId);
    const actions  = document.getElementById(actionsId);

    zone.classList.add('has-file');
    ph.classList.add('hidden');
    actions.classList.remove('hidden');

    if (file.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = e => {
            preview.style.backgroundImage = `url(${e.target.result})`;
            preview.classList.remove('hidden');
            namePill.classList.add('hidden');
        };
        reader.readAsDataURL(file);
    } else {
        preview.classList.add('hidden');
        namePill.textContent = file.name;
        namePill.classList.remove('hidden');
    }
}

function removeFile(inputId, zoneId, previewId, nameId, placeholderId, actionsId) {
    document.getElementById(inputId).value = '';
    document.getElementById(zoneId).classList.remove('has-file');
    const preview = document.getElementById(previewId);
    preview.style.backgroundImage = '';
    preview.classList.add('hidden');
    document.getElementById(nameId).classList.add('hidden');
    document.getElementById(placeholderId).classList.remove('hidden');
    document.getElementById(actionsId).classList.add('hidden');
    checkForChanges();
}

function dragOver(e, zoneId)  { e.preventDefault(); document.getElementById(zoneId).classList.add('drag-over'); }
function dragLeave(zoneId)    { document.getElementById(zoneId).classList.remove('drag-over'); }
function dropFile(e, inputId, zoneId, previewId, nameId) {
    e.preventDefault();
    document.getElementById(zoneId).classList.remove('drag-over');
    const file = e.dataTransfer.files[0];
    if (!file) return;
    const dt = new DataTransfer();
    dt.items.add(file);
    document.getElementById(inputId).files = dt.files;
    const actionsId     = inputId === 'frontFile' ? 'frontActions' : 'backActions';
    const placeholderId = inputId === 'frontFile' ? 'frontPlaceholder' : 'backPlaceholder';
    handleFile(inputId, zoneId, previewId, nameId, placeholderId, actionsId);
    markFileChanged();
}
</script>
</body>
</html>