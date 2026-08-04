<?php
// resident-settings.php
// This page displays resident profile settings and allows password changes

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

// Prepare default user/session display data
$user = [
    'name' => 'Resident User',
    'role' => 'Resident',
    'user_id' => 'N/A',
    'status' => 'Active',
    'member_since' => 'N/A',
    'last_login' => 'N/A'
];

if ($accId) {
    $userStmt = $conn->prepare('SELECT userID, firstname, lastname, userStatus, dateRegistered FROM tbl_userinfo WHERE accID = ? LIMIT 1');
    if ($userStmt) {
        $userStmt->bind_param('s', $accId);
        $userStmt->execute();
        $result = $userStmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $user['user_id'] = $row['userID'] ?? 'N/A';
            $user['name'] = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? '')) ?: $user['name'];
            $user['status'] = $row['userStatus'] ?? $user['status'];
            $user['member_since'] = !empty($row['dateRegistered']) ? date('F j, Y', strtotime($row['dateRegistered'])) : $user['member_since'];
        }
        $userStmt->close();
    }

    // Load real last_login from user account table if column exists
    $lastLoginRaw = null;
    $columnExist = $conn->query("SHOW COLUMNS FROM tbl_useracc LIKE 'last_login'");
    if ($columnExist && $columnExist->num_rows > 0) {
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

    if (empty($lastLoginRaw) && !empty($_SESSION['last_login'])) {
        $lastLoginRaw = $_SESSION['last_login'];
    }

    if (!empty($lastLoginRaw)) {
        $user['last_login'] = date('F j, Y', strtotime($lastLoginRaw));
    }
}

if (isset($_SESSION['account_role'])) {
    $user['role'] = $_SESSION['account_role'];
}

$password_error = 'UKkJ05DHQDMMMOxFEUI5f1HJGVj8Vb5gfJAEvAESTGCVWDtFEGb42qX67AxGUXvj';
$password_success = 'UKkJ05DHQDMMMOxFEUI5f1HJGVj8Vb5gfJAEvAESTGCVWDtFEGb42qX67AxGUXvj';

if (!$accId) {
    $password_error = 'Session expired or not logged in. Please log in again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password']) && $accId) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $password_error = 'All fields are required.';
    } elseif ($new_password !== $confirm_password) {
        $password_error = 'New password and confirm password do not match.';
    } elseif (strlen($new_password) < 8) {
        $password_error = 'Password must be at least 8 characters long.';
    } elseif (!preg_match('/[A-Z]/', $new_password)) {
        $password_error = 'Password must contain at least one uppercase letter.';
    } elseif (!preg_match('/[0-9]/', $new_password)) {
        $password_error = 'Password must contain at least one number.';
    } elseif ($current_password === $new_password) {
        $password_error = 'Current password and new password cannot be the same.';
    } else {
        // Verify current password from tbl_useracc
        $stmt = $conn->prepare('SELECT password FROM tbl_useracc WHERE accID = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $accId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();

            if (!$row || !isset($row['password']) || !password_verify($current_password, $row['password'])) {
                $password_error = 'Current password does not match.';
            } else {
                $newHash = password_hash($new_password, PASSWORD_DEFAULT);
                $update = $conn->prepare('UPDATE tbl_useracc SET password = ? WHERE accID = ?');
                if ($update) {
                    $update->bind_param('ss', $newHash, $accId);
                    if ($update->execute()) {
                        $password_success = 'Password changed successfully!';
                    } else {
                        $password_error = 'Unable to update password. Please try again later.';
                    }
                    $update->close();
                } else {
                    $password_error = 'Unable to prepare update query. Please try again later.';
                }
            }
        } else {
            $password_error = 'Unable to verify current password. Please try again later.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Edit Password - <?= e($siteSettings['site_title']) ?></title>
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

        .seg { flex: 1; height: 6px; border-radius: 4px; background: #e5e7eb; transition: background 0.3s; }
        .submit-btn { display: inline-flex; align-items: center; gap: 8px; padding: 12px 28px; background: var(--site-primary); color: #fff; border-radius: 10px; font-weight: 600; font-size: 0.95rem; transition: background 0.2s, transform 0.15s, box-shadow 0.2s; box-shadow: 0 4px 12px rgba(var(--site-primary-rgb),0.25); }
        .submit-btn:hover:not(:disabled) { background: var(--site-primary-dark); transform: translateY(-1px); box-shadow: 0 6px 18px rgba(var(--site-primary-rgb),0.3); }
        .submit-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

        @keyframes fadeUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .fade-up { animation: fadeUp 0.55s ease both; }
        .fade-up-1 { animation-delay: 0.05s; }
        .fade-up-2 { animation-delay: 0.15s; }
        .fade-up-3 { animation-delay: 0.25s; }

        :root {
          --site-primary-dark:   color-mix(in srgb, var(--site-primary) 55%, black);
          --site-primary-darker: color-mix(in srgb, var(--site-primary) 75%, black);
          --site-primary-light:  color-mix(in srgb, var(--site-primary) 55%, white);
          --site-primary-pale:   color-mix(in srgb, var(--site-primary) 12%, white);
        }

        /* Tailwind-green â†’ theme color overrides */
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

        /* Tailwind-emerald â†’ theme color overrides (role cards / focus rings) */
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
            <h3 class="font-bold text-green-900 text-base leading-tight" style="font-family:'DM Sans',sans-serif;"><?= e($siteSettings['site_title']) ?></h3>
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
            <h1 class="text-3xl md:text-4xl font-bold text-slate-900">Resident Settings</h1>
            <p class="text-sm text-slate-500 mt-1">Manage your profile and preferences</p>
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
    <div class="rounded-2xl flex gap-3 mb-6 -mx-4 md:mx-0 px-4 md:px-8 bg-white py-4">
      <a href="residentEditProfile.php" class="flex-1 text-center px-5 py-2 bg-white text-slate-700 font-semibold rounded-lg border border-slate-200 inline-flex items-center justify-center gap-2 hover:bg-slate-50 transition">
        <i class="fas fa-user-circle"></i>
        Profile
      </a>
      <a href="residentEditPassword.php" class="flex-1 text-center px-5 py-2 bg-emerald-600 text-white font-semibold rounded-lg inline-flex items-center justify-center gap-2 shadow-sm hover:bg-emerald-700 transition">
        <i class="fas fa-lock"></i>
        Account
      </a>
    </div>


      <div class="w-full max-w-6xl mx-auto">
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-8">
            <!-- Left Sidebar -->
            <aside class="lg:col-span-1 space-y-6">
                <!-- Profile Card -->
                <div class="bg-white rounded-2xl border border-green-100 p-6 mb-6 shadow-sm">
                    <!-- Avatar -->
                    <div class="flex justify-center mb-6">
                        <div class="w-28 h-28 rounded-full border-4 border-emerald-100 flex items-center justify-center bg-emerald-50">
                            <i class="fas fa-id-badge text-4xl text-emerald-700"></i>
                        </div>
                    </div>

                    <!-- User Info -->
                    <h3 class="text-xl font-bold text-center text-slate-900"><?php echo $user['name']; ?></h3>
                    <div class="flex justify-center mt-3 mb-4">
                        <span class="inline-block bg-emerald-100 text-emerald-700 rounded-full px-3 py-1 text-sm font-semibold">
                            <?php echo $user['role']; ?>
                        </span>
                    </div>
                    <p class="text-center text-slate-700">User ID: <?php echo $user['user_id']; ?></p>
                </div>

                <!-- Account Status Card -->
                <div class="bg-white rounded-2xl border border-green-100 p-6 shadow-sm">
                    <div class="flex items-center justify-between mb-4">
                        <h4 class="text-lg font-bold text-slate-900">Account Status</h4>
                        <i class="fas fa-circle-check text-emerald-500"></i>
                    </div>
                    <div class="space-y-3 text-sm text-slate-700">
                        <div class="flex justify-between items-center">
                            <span class="text-slate-500">Status</span>
                            <span class="font-semibold text-emerald-600"><?php echo $user['status']; ?></span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-slate-500">Member Since</span>
                            <span class="font-semibold text-slate-700"><?php echo $user['member_since']; ?></span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-slate-500">Last Login</span>
                            <span class="font-semibold text-slate-700"><?php echo $user['last_login']; ?></span>
                        </div>
                    </div>
                </div>
            </aside>

            <section class="lg:col-span-3 space-y-6">
                <!-- Main Content Card -->
                <div class="bg-white rounded-lg border border-gray-300 p-8">
                    <h2 class="text-2xl font-bold text-gray-900 mb-2">Change Password</h2>
                    <p class="text-gray-600 mb-8">Update your password to keep your account secure</p>

                    <?php if ($password_error): ?>
                        <div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded">
                            <?php echo htmlspecialchars($password_error); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($password_success): ?>
                        <div class="mb-6 p-4 bg-green-100 border border-green-400 text-green-700 rounded">
                            <?php echo htmlspecialchars($password_success); ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" id="changePasswordForm" class="space-y-6">
                        <!-- Current Password -->
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Current Password</label>
                            <div class="relative">
                                <input 
                                    id="current_password"
                                    type="password" 
                                    name="current_password" 
                                    placeholder="Enter Current Password"
                                    class="w-full px-4 py-3 bg-gray-200 rounded border border-gray-300 text-gray-900 placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-400"
                                >
                                <p id="current-password-error" class="text-red-500 text-xs mt-1 hidden"></p>
                                <button type="button" data-target="current_password" class="show-password-btn absolute right-3 top-3 text-gray-600 hover:text-gray-800" aria-label="Toggle current password visibility">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <!-- New Password -->
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">New Password</label>
                            <div class="relative">
                                <input 
                                    id="new_password"
                                    type="password" 
                                    name="new_password" 
                                    placeholder="Enter New Password"
                                    class="w-full px-4 py-3 bg-gray-200 rounded border border-gray-300 text-gray-900 placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-400"
                                >
                                <p id="new-password-error" class="text-red-500 text-xs mt-1 hidden"></p>
                                <!-- Strength bar -->
                                <div class="flex gap-1.5 mt-2.5">
                                    <div class="seg" id="seg1"></div>
                                    <div class="seg" id="seg2"></div>
                                    <div class="seg" id="seg3"></div>
                                    <div class="seg" id="seg4"></div>
                                </div>
                                <p id="strength-label" class="text-xs mt-1 text-gray-500"></p>
                                <button type="button" data-target="new_password" class="show-password-btn absolute right-3 top-3 text-gray-600 hover:text-gray-800" aria-label="Toggle new password visibility">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <!-- Confirm Password -->
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Confirm Password</label>
                            <div class="relative">
                                <input 
                                    id="confirm_password"
                                    type="password" 
                                    name="confirm_password" 
                                    placeholder="Confirm Password"
                                    class="w-full px-4 py-3 bg-gray-200 rounded border border-gray-300 text-gray-900 placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-400"
                                >
                                <p id="confirm-password-error" class="text-red-500 text-xs mt-1 hidden"></p>
                                <button type="button" data-target="confirm_password" class="show-password-btn absolute right-3 top-3 text-gray-600 hover:text-gray-800" aria-label="Toggle confirm password visibility">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                    </svg>
                                </button>
                            </div>
                            <p id="confirm-strength-label" class="text-xs mt-1 text-gray-500"></p>
                        </div>

                        <!-- Password Requirements -->
                        <div class="bg-gray-100 rounded-lg p-6">
                            <h4 class="font-bold text-gray-900 mb-3">Password Requirements:</h4>
                            <ul class="space-y-2 text-gray-900">
                                <li class="flex items-center">
                                    <span class="mr-3">â€¢</span>
                                    <span>Minimum 8 characters</span>
                                </li>
                                <li class="flex items-center">
                                    <span class="mr-3">â€¢</span>
                                    <span>At least one uppercase letter</span>
                                </li>
                                <li class="flex items-center">
                                    <span class="mr-3">â€¢</span>
                                    <span>At least one number</span>
                                </li>
                            </ul>
                        </div>

                        <!-- Submit Button -->
                        <div class="flex justify-end">
                            <button 
                                type="submit" 
                                name="change_password"
                                class="bg-emerald-600 text-white font-medium px-8 py-3 rounded-lg hover:bg-emerald-700 transition flex items-center gap-2"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"></path>
                                </svg>
                                Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </section>
        </div>
    </div>
  </main>

  <script>
    function validatePassword(pw) {
      const errors = [];
      if (pw.length < 8) errors.push('at least 8 characters');
      if (pw.length > 128) errors.push('no more than 128 characters');
      if (!/[A-Z]/.test(pw)) errors.push('an uppercase letter');
      if (!/[a-z]/.test(pw)) errors.push('a lowercase letter');
      if (!/[0-9]/.test(pw)) errors.push('a number');
      return errors;
    }

    const segEls = [1,2,3,4].map(i => document.getElementById('seg' + i));
    const segColors = [
      ['bg-red-400',    'bg-red-400',    'bg-gray-200', 'bg-gray-200'],
      ['bg-orange-400', 'bg-orange-400', 'bg-gray-200', 'bg-gray-200'],
      ['bg-yellow-400', 'bg-yellow-400', 'bg-yellow-400','bg-gray-200'],
      ['bg-lime-500',   'bg-lime-500',   'bg-lime-500', 'bg-lime-500'],
    ];
    const strengthTexts = ['Weak','Fair','Good','Strong'];

    function passwordStrengthScore(pw) {
      let score = 0;
      if (pw.length >= 8) score++;
      if (pw.length >= 12) score++;
      if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) score++;
      if (/[0-9]/.test(pw)) score++;
      return Math.min(score, 4);
    }

    function updateStrengthMeterFor(pw, segElsList, labelId) {
      const score = passwordStrengthScore(pw);
      const idx = Math.max(0, score - 1);
      const colors = pw.length ? segColors[idx] : Array(4).fill('bg-gray-200');

      segElsList.forEach((seg, index) => {
        if (!seg) return;
        seg.className = 'seg ' + colors[index];
      });

      const labelEl = document.getElementById(labelId);
      if (!labelEl) return;

      if (!pw.length) {
        labelEl.textContent = '';
        labelEl.className = 'text-xs mt-1 text-gray-500';
      } else {
        labelEl.textContent = strengthTexts[idx];
        labelEl.className = 'text-xs mt-1 ' + ['text-red-500','text-orange-500','text-yellow-600','text-lime-600'][idx];
      }
    }

    function updateStrengthMeter(pw) {
      updateStrengthMeterFor(pw, segEls, 'strength-label');
    }

    function clearError(id) {
      const el = document.getElementById(id);
      if (el) {
        el.textContent = '';
        el.classList.add('hidden');
      }
    }

    function showError(id, msg) {
      const el = document.getElementById(id);
      if (el) {
        el.textContent = msg;
        el.classList.remove('hidden');
      }
    }

    document.querySelectorAll('.show-password-btn').forEach(button => {
      button.addEventListener('click', () => {
        const targetId = button.getAttribute('data-target');
        const input = document.getElementById(targetId);
        if (!input) return;

        const isPassword = input.type === 'password';
        input.type = isPassword ? 'text' : 'password';
      });
    });

    const confirmSegEls = [1,2,3,4].map(i => document.getElementById('segc' + i));

    document.getElementById('new_password').addEventListener('input', function () {
      updateStrengthMeter(this.value);
    });

    document.getElementById('confirm_password').addEventListener('input', function () {
      updateStrengthMeterFor(this.value, confirmSegEls, 'confirm-strength-label');
    });

    document.getElementById('changePasswordForm').addEventListener('submit', function(e) {
      clearError('current-password-error');
      clearError('new-password-error');
      clearError('confirm-password-error');

      let valid = true;
      const currentPw = document.getElementById('current_password').value.trim();
      const newPw     = document.getElementById('new_password').value.trim();
      const confirmPw = document.getElementById('confirm_password').value.trim();

      if (!currentPw) {
        showError('current-password-error', 'Current password is required.');
        valid = false;
      }

      if (!newPw) {
        showError('new-password-error', 'New password is required.');
        valid = false;
      } else {
        const pwErrors = validatePassword(newPw);
        if (pwErrors.length) {
          showError('new-password-error', 'Must include: ' + pwErrors.join(', ') + '.');
          valid = false;
        }
      }

      if (!confirmPw) {
        showError('confirm-password-error', 'Please confirm your new password.');
        valid = false;
      } else if (newPw !== confirmPw) {
        showError('confirm-password-error', 'New password and confirm password do not match.');
        valid = false;
      }

      if (currentPw && newPw && (currentPw === newPw)) {
        showError('new-password-error', 'Current password and new password cannot be the same.');
        valid = false;
      }

      if (!valid) e.preventDefault();
    });
  </script>
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