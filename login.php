<?php ob_start(); ?>
<!DOCTYPE html>
<html lang="en">
<?php
session_start();

require_once __DIR__ . '/config/db_connection.php';
require_once __DIR__ . '/includes/site_config.php';
require_once __DIR__ . '/includes/check_permissions.php';   // <-- add this

$siteSettings = site_config_load($conn);

// Ã¢â€â‚¬Ã¢â€â‚¬ Helper: role-based redirect Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
function redirectByRole(string $role, bool $isStaff = false): void {
    $normalizedRole = strtolower(trim($role));
    $roleParts = array_map('trim', explode(',', $normalizedRole));
 
    // A granted Secretary/Treasurer keeps their real resident/non-resident
    // account_role in tbl_useracc Ã¢â‚¬â€ $isStaff (from tbl_admin_permissions)
    // is what actually routes them into the admin panel.
    if (in_array('admin', $roleParts, true) || in_array('custom_admin', $roleParts, true) || $isStaff) {
        header('Location: admin/adminLanding.php');
    } elseif (in_array('resident', $roleParts, true)) {
        header('Location: resident/residentLanding.php');
    } elseif (in_array('non-resident', $roleParts, true) || in_array('nonresident', $roleParts, true) || in_array('business/apartment owner', $roleParts, true) || in_array('business', $roleParts, true)) {
      header('Location: nonResident/nonresidentLanding.php');
    } else {
        error_log('Unknown account_role "' . $role . '" Ã¢â‚¬â€ sent to landing.php');
        header('Location: landing.php');
    }
    exit;
}

// Already logged in? Redirect immediately.
if (isset($_SESSION['user_id'])) {
    redirectByRole($_SESSION['account_role'] ?? '', !empty($_SESSION['staff_permissions']));
}


$error = null;

// Ã¢â€â‚¬Ã¢â€â‚¬ Rate Limiter Settings Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
$rateLimiterKey = 'login_rate_limit';

// Advanced cooldown logic
function getRateLimitCooldown($attempts) {
  if ($attempts < 5) return 0;
  if ($attempts < 10) return 60;         // 1 min
  if ($attempts < 20) return 300;        // 5 min
  if ($attempts < 30) return 600;        // 10 min
  if ($attempts < 40) return 1800;       // 30 min
  return 3600;                          // 1 hour
}
$rateLimiterKey = 'login_rate_limit';

// Helper: get client IP (basic)
function getClientIp() {
  if (!empty($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
  if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return $_SERVER['HTTP_X_FORWARDED_FOR'];
  return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

// Ã¢â€â‚¬Ã¢â€â‚¬ Rate Limiter Check Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
// --- Rate limiter check (runs on every page load for JS info) ---
$ip = getClientIp();
$email = strtolower(trim($_POST['username'] ?? $_GET['username'] ?? ''));
$now = time();
if (!isset($_SESSION[$rateLimiterKey])) {
    $_SESSION[$rateLimiterKey] = [];
}
$limiter =& $_SESSION[$rateLimiterKey];
$bucketKey = md5($ip . '|' . $email);
$bucket = $limiter[$bucketKey] ?? ['count' => 0, 'last' => 0, 'cooldown_until' => 0];

// Calculate current cooldown (if any)
$cooldown = 0;
if ($bucket['cooldown_until'] > $now) {
    $cooldown = $bucket['cooldown_until'] - $now;
    $error = 'Too many failed attempts. Please wait ' . ceil($cooldown/60) . ' minute(s) before trying again.';
}

// On POST, update limiter
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === null) {
    // ...existing code...
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === null) {
  // --- 1. Retrieve & trim raw inputs ---
  $rawEmail    = trim($_POST['username'] ?? '');
  $rawPassword = trim($_POST['password'] ?? '');

  // --- 2. Input length guards (before any DB work) ---
  if (strlen($rawEmail) > 255 || strlen($rawPassword) > 72) {
    $error = 'Invalid email or password.';
  }
  // --- 3. Validate email format ---
  elseif (!filter_var($rawEmail, FILTER_VALIDATE_EMAIL)) {
    $error = 'Invalid email or password.';
  }
  else {
    // --- 4. Database connection ---
    $db = new mysqli(getenv('DB_HOST'), getenv('DB_USER'), getenv('DB_PASSWORD'), getenv('DB_NAME'));

    if ($db->connect_error) {
      error_log('DB connect error: ' . $db->connect_error);
      $error = 'A server error occurred. Please try again later.';
    } else {
      $db->set_charset('utf8mb4');

      $hasLastLogin = false;
      $schemaResult = $db->query("SHOW COLUMNS FROM tbl_useracc LIKE 'last_login'");
      if ($schemaResult) {
        $hasLastLogin = $schemaResult->num_rows > 0;
      }

            // --- 5. Prepared statement ---
            $sql = 'SELECT accID, password, account_role' . ($hasLastLogin ? ', last_login' : '') .
                   ' FROM tbl_useracc' .
                   ' WHERE email = ?' .
                   ' LIMIT 1';
            $stmt = $db->prepare($sql);

            if ($stmt) {
                $stmt->bind_param('s', $rawEmail);
                $stmt->execute();
                $result = $stmt->get_result();
                $user   = $result->fetch_assoc();
                $stmt->close();

                // --- 6. Verify password ---
                if ($user && password_verify($rawPassword, $user['password'])) {
                  // --- SUCCESS: Reset limiter on success ---
                  unset($_SESSION[$rateLimiterKey][$bucketKey]);

                  // --- 6.5. Block login entirely for a directly-created
                  // "staff" account whose admin grant is no longer active.
                  // These accounts (made via Settings > Permissions > Add
                  // Admin Account) have no resident/non-resident/admin
                  // identity of their own in tbl_useracc Ã¢â‚¬â€ once their
                  // grant is revoked they have nowhere else to log into,
                  // so credentials are correct but login still fails,
                  // same as any other rejected attempt.
                  $normalizedAccountRole = strtolower(trim($user['account_role'] ?? ''));
                  $grantCheck = get_staff_grant($db, $user['accID']);
                  $grantIsActive = is_permission_grant_active($grantCheck);

                  if ($normalizedAccountRole === 'staff' && !$grantIsActive) {
                      $error = 'Your admin access has been removed by the barangay admin. You can no longer log in with this account.';

                      // Keep the revoked/removed flag only for explicitly revoked access.
                      // Legacy blank-status rows with saved permissions are still valid.
                      $explicitStatus = strtolower(trim((string) ($grantCheck['status'] ?? '')));
                      if ($explicitStatus === 'revoked' || $explicitStatus === 'removed') {
                          $close = $db->prepare("UPDATE tbl_admin_permissions SET status = 'removed' WHERE accID = ?");
                          if ($close) {
                              $close->bind_param('s', $user['accID']);
                              $close->execute();
                              $close->close();
                          }
                      }
                  } else {

                  // --- 7. Regenerate session ID (prevent session fixation) ---
                  session_regenerate_id(true);
                  $_SESSION['user_id']      = $rawEmail;
                  $_SESSION['acc_id']       = $user['accID'];
                  $_SESSION['account_role'] = $user['account_role'];
 
                  // Load any staff (Secretary/Treasurer) grant for this
                  // account. This does NOT change their real account_role Ã¢â‚¬â€
                  // it's a separate flag admin pages check alongside it.
                  // Load any staff (Secretary/Treasurer) grant for this
                  // account. This does NOT change their real account_role
                  // or credentials in tbl_useracc Ã¢â‚¬â€ the resident keeps
                  // logging in with the exact same email/password. This
                  // grant is a separate flag: if active, it routes them
                  // into the SAME admin panel the founder admin uses,
                  // but require_permission() on every admin page still
                  // restricts them to only the modules the admin assigned.
                  $_SESSION['staff_position']    = null;
                  $_SESSION['staff_permissions'] = [];
                  $grant = get_staff_grant($db, $user['accID']);
                  if ($grant && is_permission_grant_active($grant)) {
                      $_SESSION['staff_position']    = $grant['position'] ?? null;
                      $_SESSION['staff_permissions'] = array_values(array_filter(array_map('trim', explode(',', $grant['permissions_csv'] ?? ''))));
                  }
 
                  // Set current login time as last_login display value
                  $currentLoginTime = date('Y-m-d H:i:s');
                  $_SESSION['last_login'] = $currentLoginTime;


                  // Update last_login to now (real last login timestamp), if the column exists
                  if ($hasLastLogin) {
                    $updateLogin = $db->prepare('UPDATE tbl_useracc SET last_login = ? WHERE accID = ?');
                    if ($updateLogin) {
                      $updateLogin->bind_param('ss', $currentLoginTime, $user['accID']);
                      $updateLogin->execute();
                      $updateLogin->close();
                    }
                  }

                     // --- 8. Role-based redirect (handles combined roles) ---
                  redirectByRole($user['account_role'], !empty($_SESSION['staff_permissions']));
                  }

                } else {
                  // --- FAIL: Increment limiter ---
                  $bucket['count']++;
                  $bucket['last'] = $now;
                  $window = getRateLimitCooldown($bucket['count']);
                  if ($window > 0) {
                      $bucket['cooldown_until'] = $now + $window;
                      $cooldown = $window;
                      $error = 'Too many failed attempts. Please wait ' . ceil($window/60) . ' minute(s) before trying again.';
                  } else {
                      $error = 'Invalid email or password.';
                  }
                  $limiter[$bucketKey] = $bucket;
                }
            } else {
                error_log('DB prepare error: ' . $db->error);
                $error = 'A server error occurred. Please try again later.';
            }

            $db->close();
        }
    }
}
?>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="assets/responsive-global.css">
  <title>Login Ã¢â‚¬â€ <?= e($siteSettings['site_title']) ?></title>
  <link rel="icon" href="<?= e(site_config_logo_url($siteSettings, '')) ?>" type="image/png">
  <script src="https://cdn.tailwindcss.com/3.4.16"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="/tailwind/input.css">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <?= site_config_css_vars($siteSettings) ?>
  <style>
    body { font-family: 'DM Sans', sans-serif; }

    .hero-bg {
      background: linear-gradient(135deg, var(--site-primary-darker) 0%, var(--site-primary-dark) 45%, var(--site-primary-dark) 75%, var(--site-primary) 100%);
      position: relative; overflow: hidden;
    }
    .hero-bg::before {
      content: '';
      position: absolute; inset: 0;
      background: radial-gradient(ellipse at 30% 60%, rgba(255,255,255,0.07) 0%, transparent 60%);
    }
    .dot-grid {
      position: absolute; inset: 0; opacity: 0.05;
      background-image: radial-gradient(circle at 1px 1px, white 1px, transparent 0);
      background-size: 28px 28px;
    }
    .circle-deco {
      position: absolute; border-radius: 50%;
      border: 48px solid color-mix(in srgb, var(--site-primary-light) 20%, transparent);
    }

    .field-input {
      width: 100%; border: 1.5px solid #d1d5db; border-radius: 10px;
      padding: 11px 14px 11px 42px; background: #fff; font-size: 0.9rem;
      transition: border-color 0.2s, box-shadow 0.2s; outline: none; color: #1f2937;
    }
    .field-input:focus { border-color: var(--site-primary); box-shadow: 0 0 0 3px rgba(var(--site-primary-rgb),0.12); }

    .toggle-pw-btn {
      position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
      color: #9ca3af; transition: color 0.2s; background: none; border: none; cursor: pointer;
    }
    .toggle-pw-btn:hover { color: var(--site-primary); }

    /* Hide browser-native password reveal */
    input[type='password']::-ms-reveal,
    input[type='password']::-ms-clear { display: none; }

    .submit-btn {
      width: 100%; padding: 13px; background: var(--site-primary); color: #fff;
      border-radius: 10px; font-weight: 600; font-size: 0.95rem;
      transition: background 0.2s, transform 0.15s, box-shadow 0.2s;
      box-shadow: 0 4px 14px rgba(var(--site-primary-rgb),0.3);
      display: flex; align-items: center; justify-content: center; gap: 8px;
    }
    .submit-btn:hover { background: var(--site-primary-dark); transform: translateY(-1px); box-shadow: 0 6px 20px rgba(var(--site-primary-rgb),0.35); }

    @keyframes fadeUp { from { opacity:0; transform:translateY(20px); } to { opacity:1; transform:translateY(0); } }
    .fade-up   { animation: fadeUp 0.55s ease both; }
    .fade-up-1 { animation-delay: 0.05s; }
    .fade-up-2 { animation-delay: 0.15s; }

    /* Tailwind-green Ã¢â€ â€™ theme color overrides */
    .bg-green-700 { background-color: var(--site-primary) !important; }
    .bg-green-600 { background-color: var(--site-primary) !important; }
    .text-green-700 { color: var(--site-primary) !important; }
    .text-green-950 { color: var(--site-primary-darker) !important; }
    .border-green-600 { border-color: var(--site-primary) !important; }
    .hover\:bg-green-50:hover { background-color: var(--site-primary-pale) !important; }
    .bg-green-50 { background-color: var(--site-primary-pale) !important; }
    .border-green-100 { border-color: color-mix(in srgb, var(--site-primary) 20%, white) !important; }
    .border-green-200 { border-color: color-mix(in srgb, var(--site-primary) 30%, white) !important; }
    .from-green-600 { --tw-gradient-from: var(--site-primary) var(--tw-gradient-from-position) !important; --tw-gradient-to: rgb(0 0 0 / 0) var(--tw-gradient-to-position) !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to) !important; }
    .to-emerald-400 { --tw-gradient-to: var(--site-primary-light) var(--tw-gradient-to-position) !important; }

    /* hero-panel specific (on the dark themed gradient) */
    .text-green-300 { color: color-mix(in srgb, var(--site-primary-light) 70%, white) !important; }
    .text-green-400 { color: var(--site-primary-light) !important; }
    .text-green-200 { color: color-mix(in srgb, var(--site-primary-light) 50%, white) !important; }
    .text-green-600 { color: color-mix(in srgb, var(--site-primary-light) 55%, black) !important; }
    .bg-green-500\/20 { background-color: color-mix(in srgb, var(--site-primary-light) 20%, transparent) !important; }
    .border-green-500\/30 { border-color: color-mix(in srgb, var(--site-primary-light) 30%, transparent) !important; }
    .bg-green-800\/60 { background-color: color-mix(in srgb, var(--site-primary-darker) 60%, transparent) !important; }
  </style>
</head>

<body class="min-h-screen flex flex-col md:flex-row">

  <!-- Ã¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢Â LEFT PANEL Ã¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢Â -->
  <div class="hero-bg hidden md:flex flex-col justify-between w-1/2 p-12 relative">
    <div class="dot-grid"></div>
    <div class="circle-deco w-72 h-72 -top-20 -right-20"></div>
    <div class="circle-deco w-48 h-48 bottom-10 -left-12"></div>

    <!-- Logo -->
    <div class="relative z-10 flex items-center gap-3">
      <div class="w-11 h-11 rounded-xl bg-green-600 flex items-center justify-center shadow overflow-hidden">
        <img src="<?= e(site_config_logo_url($siteSettings, '')) ?>" alt="Logo" class="w-full h-full object-contain" />
      </div>
      <div>
        <p class="text-white font-bold text-base leading-tight"><?= e($siteSettings['site_title']) ?></p>
        <p class="text-green-400 text-[10px] tracking-widest uppercase"><?= e($siteSettings['barangay_name']) ?></p>
      </div>
    </div>

    <!-- Center text -->
    <div class="relative z-10">
      <span class="inline-block bg-green-500/20 text-green-300 border border-green-500/30 text-xs tracking-widest uppercase px-3 py-1 rounded-full mb-5">
        Barangay Integrated System
      </span>
      <h2 class="text-white text-4xl font-bold leading-tight mb-4" style="font-family:'Playfair Display',serif;">
        Welcome<br>Back to<br><span class="text-green-300"><?= e($siteSettings['site_title']) ?></span>
      </h2>
      <p class="text-green-200 text-sm leading-relaxed max-w-xs">
        Access barangay services, request documents, and stay updated with community announcements Ã¢â‚¬â€ all in one place.
      </p>

      <!-- Feature pills -->
      <div class="mt-8 flex flex-col gap-3">
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 rounded-lg bg-green-800/60 flex items-center justify-center">
            <i class="fa-solid fa-file-lines text-green-400 text-xs"></i>
          </div>
          <span class="text-green-200 text-sm">Request official documents online</span>
        </div>
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 rounded-lg bg-green-800/60 flex items-center justify-center">
            <i class="fa-solid fa-bullhorn text-green-400 text-xs"></i>
          </div>
          <span class="text-green-200 text-sm">Get real-time community announcements</span>
        </div>
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 rounded-lg bg-green-800/60 flex items-center justify-center">
            <i class="fa-solid fa-handshake text-green-400 text-xs"></i>
          </div>
          <span class="text-green-200 text-sm">Apply for beneficiary list</span>
        </div>
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 rounded-lg bg-green-800/60 flex items-center justify-center">
            <i class="fa-solid fa-toolbox text-green-400 text-xs"></i>
          </div>
          <span class="text-green-200 text-sm">Borrow Barangay equipment</span>
        </div>
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 rounded-lg bg-green-800/60 flex items-center justify-center">
            <i class="fa-solid fa-building text-green-400 text-xs"></i>
          </div>
          <span class="text-green-200 text-sm">Find business/apartment in <?= e($siteSettings['barangay_name']) ?></span>
        </div>
      </div>
    </div>

    <!-- Footer note -->
    <p class="relative z-10 text-green-600 text-xs">Ã‚Â© 2026 <?= e($siteSettings['site_title']) ?>. All Rights Reserved.</p>
  </div>

  <!-- Ã¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢Â RIGHT PANEL Ã¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢Â -->
  <div class="flex-1 flex flex-col bg-green-50 min-h-screen">

    <!-- Mobile top bar -->
    <div class="md:hidden flex items-center gap-3 px-6 py-4 bg-green-700 shadow">
      <div class="w-9 h-9 rounded-xl bg-green-600 flex items-center justify-center overflow-hidden">
        <img src="<?= e(site_config_logo_url($siteSettings, '')) ?>" alt="Logo" class="w-full h-full object-contain" />
      </div>
      <div>
        <p class="text-white font-bold text-sm"><?= e($siteSettings['site_title']) ?></p>
        <p class="text-green-300 text-[10px] tracking-widest uppercase"><?= e($siteSettings['barangay_name']) ?></p>
      </div>
    </div>

    <!-- Login card -->
    <div class="flex-1 flex items-center justify-center px-6 py-12">
      <div class="w-full max-w-md">

        <!-- Card heading -->
        <div class="fade-up fade-up-1 mb-8 text-center md:text-left">
          <h1 class="text-3xl font-bold text-green-950" style="font-family:'Playfair Display',serif;">Sign In</h1>
          <p class="text-gray-500 text-sm mt-1">Enter your credentials to access your account</p>
        </div>

        <div class="fade-up fade-up-2 bg-white rounded-2xl shadow-lg border border-green-100 overflow-hidden">

          <!-- Top accent bar -->
          <div class="h-1.5 bg-gradient-to-r from-green-600 to-emerald-400"></div>

          <div class="p-8">

            <!-- Success banners -->
            <?php if (isset($_GET['success']) && $_GET['success'] === '1'): ?>
            <div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-sm flex items-center gap-2">
              <i class="fa-solid fa-circle-check flex-shrink-0"></i>
              Account created successfully! Please log in with your credentials.
            </div>
            <?php endif; ?>

            <?php if (isset($_GET['reset']) && $_GET['reset'] === '1'): ?>
            <div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-sm flex items-center gap-2">
              <i class="fa-solid fa-circle-check flex-shrink-0"></i>
              Password reset successful. You can now log in with your new password.
            </div>
            <?php endif; ?>

            <?php if (isset($_GET['revoked']) && $_GET['revoked'] === '1'): ?>
            <div class="mb-4 p-3 bg-amber-50 border border-amber-200 text-amber-800 rounded-lg text-sm flex items-center gap-2">
              <i class="fa-solid fa-circle-info flex-shrink-0"></i>
              Your admin access has been removed by the barangay admin.
            </div>
            <?php endif; ?>

            <!-- Error banner -->
            <?php if ($error !== null): ?>
            <div class="mb-4 p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm flex items-center gap-2">
              <i class="fa-solid fa-circle-exclamation flex-shrink-0"></i>
              <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>


            <form action="login.php" method="post" class="space-y-5" id="loginForm">

              <!-- Email -->
              <div>
                <label for="username" class="block text-xs font-semibold text-gray-600 mb-2 uppercase tracking-wide">
                   Email
                </label>
                <div class="relative">
                  <i class="fa-solid fa-user absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                  <input
                    type="text" id="username" name="username" required
                    maxlength="255" autocomplete="email"
                    placeholder="Enter your email"
                    class="field-input">
                </div>
              </div>

              <!-- Password -->
              <div>
                <div class="flex items-center justify-between mb-2">
                  <label for="password" class="block text-xs font-semibold text-gray-600 uppercase tracking-wide">
                    Password
                  </label>
                  <a href="forgot_password.php" class="text-xs text-green-700 hover:underline font-medium">
                    Forgot password?
                  </a>
                </div>
                <div class="relative">
                  <i class="fa-solid fa-lock absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                  <input
                    type="password" id="password" name="password" required
                    maxlength="72" autocomplete="current-password"
                    placeholder="Enter your password"
                    class="field-input pr-10">
                  <button type="button" class="toggle-pw-btn" aria-label="Toggle password visibility">
                    <i class="fa fa-eye text-sm"></i>
                  </button>
                </div>
              </div>

              <!-- Submit -->
              <button type="submit" class="submit-btn" id="loginBtn">
                <i class="fa-solid fa-arrow-right-to-bracket"></i>
                Sign In
              </button>

            </form>

            <!-- Divider -->
            <div class="flex items-center gap-3 my-6">
              <div class="flex-1 h-px bg-gray-200"></div>
              <span class="text-xs text-gray-400 font-medium">NEW HERE?</span>
              <div class="flex-1 h-px bg-gray-200"></div>
            </div>

            <!-- Register CTA -->
            <a href="signup/accountCreation.php"
              class="flex items-center justify-center gap-2 w-full py-3 border-2 border-green-600 text-green-700 rounded-xl font-semibold text-sm hover:bg-green-50 transition">
              <i class="fa-solid fa-user-plus text-sm"></i>
              Create an Account
            </a>

          </div>
        </div>

        <!-- Back to home -->
        <div class="text-center mt-6">
          <a href="landing.php" class="text-xs text-gray-500 hover:text-green-700 transition flex items-center justify-center gap-1">
            <i class="fa-solid fa-arrow-left text-xs"></i> Back to <?= e($siteSettings['site_title']) ?>
          </a>
        </div>

      </div>
    </div>
  </div>

</body>

<script>
  document.querySelector('.toggle-pw-btn').addEventListener('click', function () {
    const input = document.getElementById('password');
    const icon  = this.querySelector('i');
    if (input.type === 'password') {
      input.type = 'text';
      icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
      input.type = 'password';
      icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
  });

  // Rate limiter: disable button if cooldown
  const cooldown = <?php echo json_encode($cooldown); ?>;
  if (cooldown && cooldown > 0) {
    const btn = document.getElementById('loginBtn');
    btn.disabled = true;
    btn.style.opacity = 0.6;
    btn.style.cursor = 'not-allowed';
    let timer = cooldown;
    const originalText = btn.innerHTML;
    function updateBtn() {
      if (timer > 0) {
        let min = Math.floor(timer / 60);
        let sec = timer % 60;
        let timeStr = '';
        if (min > 0) {
          timeStr = `${min}m` + (sec > 0 ? ` ${sec}s` : '');
        } else {
          timeStr = `${sec}s`;
        }
        btn.innerHTML = `<i class='fa-solid fa-hourglass-half'></i> Wait (${timeStr})`;
        timer--;
        setTimeout(updateBtn, 1000);
      } else {
        btn.disabled = false;
        btn.style.opacity = 1;
        btn.style.cursor = '';
        btn.innerHTML = originalText;
      }
    }
    updateBtn();
  }
</script>
</html>
