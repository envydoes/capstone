<?php
require_once __DIR__ . '/../config/db_connection.php';
session_start();

require_once __DIR__ . '/../includes/site_config.php';
$siteSettings = site_config_load($conn);

// Auth check
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
    $_SESSION['access_denied'] = true;
    $_SESSION['access_denied_status'] = $userStatus;
    header('Location: residentPanel.php');
    exit;
}

// ─── Document — required supporting docs map ───
$docRequirements = [
    'barangay_clearance'       => 'Valid Government-Issued ID',
    'certificate_indigency'    => 'Valid Government-Issued ID, Proof of Residency',
    'certificate_residency'    => 'Valid Government-Issued ID',
    'business_permit'          => 'DTI / SEC Registration, Valid Government-Issued ID',
    'certificate_good_moral'   => 'Valid Government-Issued ID, School Credentials',
    'solo_parent_id'           => 'Birth Certificate of Child/ren, Valid Government-Issued ID',
    'certificate_livelihood'   => 'Valid Government-Issued ID, Proof of Livelihood',
    'certificate_no_property'  => 'Valid Government-Issued ID, Tax Declaration (if any)',
    'first_time_jobseeker'     => 'Valid Government-Issued ID, Proof of Unemployment',
];

// ─── Handle POST ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Determine upload directory (relative to project root)
    // Adjust this path to match your server setup
    $uploadDir = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/uploads/document_requests/';

    // Fallback: try relative path if DOCUMENT_ROOT approach fails
    if (!is_dir($uploadDir)) {
        $uploadDir = dirname(__DIR__) . '/uploads/document_requests/';
    }

    // Create directory if it doesn't exist
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $allowedExts   = ['jpg', 'jpeg', 'png', 'pdf'];
    $maxFileSize   = 5 * 1024 * 1024; // 5 MB per file
    $uploadedFiles = [];
    $uploadErrors  = [];

    if (!empty($_FILES['supporting_docs']['name'][0])) {
        foreach ($_FILES['supporting_docs']['name'] as $idx => $fname) {
            if ($_FILES['supporting_docs']['error'][$idx] !== UPLOAD_ERR_OK || $fname === '') {
                continue;
            }

            $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));

            if (!in_array($ext, $allowedExts)) {
                $uploadErrors[] = "$fname: invalid file type.";
                continue;
            }

            if ($_FILES['supporting_docs']['size'][$idx] > $maxFileSize) {
                $uploadErrors[] = "$fname: exceeds 5 MB limit.";
                continue;
            }

            // Generate unique filename to prevent collisions
            $safeName = uniqid('doc_', true) . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $fname);
            $dest     = $uploadDir . $safeName;

            if (move_uploaded_file($_FILES['supporting_docs']['tmp_name'][$idx], $dest)) {
                $uploadedFiles[] = $safeName;
            } else {
                $uploadErrors[] = "$fname: failed to save.";
            }
        }
    }

    if (!empty($uploadErrors)) {
        $_SESSION['document_save_status'] = 'error';
        $_SESSION['document_save_msg']    = 'Some files could not be uploaded: ' . implode(' ', $uploadErrors);
    }

    $_SESSION['document_form'] = [
        'document_type'   => $_POST['document_type']   ?? '',
        'num_copies'      => $_POST['num_copies']       ?? 1,
        'purpose'         => $_POST['purpose']          ?? '',
        'notes'           => $_POST['notes']            ?? '',
        'uploaded_files'  => $uploadedFiles,
        'submitted_at'    => date('Y-m-d H:i:s'),
    ];

    // Redirect to save request script
    header('Location: saveRequest.php');
    exit;
}

// Profile vars
$logged_in = isset($_SESSION['user_id']);
$userEmail  = $_SESSION['user_id']  ?? '';
$accId      = $_SESSION['acc_id']   ?? '';
$userName   = $userEmail;
$stmt = $conn->prepare('SELECT firstname FROM tbl_userinfo WHERE accID = ? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('s', $accId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row && !empty($row['firstname'])) $userName = $row['firstname'];
    $stmt->close();
}

// Toast status from saveRequest endpoint
$toastStatus  = $_SESSION['document_save_status'] ?? '';
$toastMessage = $_SESSION['document_save_msg']    ?? '';
unset($_SESSION['document_save_status'], $_SESSION['document_save_msg']);

$saved = $_SESSION['document_form'] ?? [];

$roleLabel = match($role) {
    'admin'        => 'Admin',
    'resident'     => 'Resident',
    'resident,business/apartment owner' => 'Resident & Owner',
    'non-resident' => 'Non-Resident',
    'non-resident,business/apartment owner' => 'Non-Resident & Owner',
    'business/apartment owner' => 'Owner',
    default        => 'User',
};
$roleBadgeClass = match($role) {
    'admin'        => 'bg-purple-100 text-purple-700 border border-purple-200',
    'resident'     => 'bg-green-100 text-green-700 border border-green-200',
    'resident,business/apartment owner' => 'bg-green-100 text-green-700 border border-green-200',
    default        => 'bg-gray-100 text-gray-600 border border-gray-200',
};
$initials = strtoupper(substr($userName, 0, 2));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="../assets/responsive-global.css">
  <title>Document Request  –  <?= e($siteSettings['site_title']) ?></title>
  <link rel="icon" href="<?= e(site_config_logo_url($siteSettings, '../')) ?>" type="image/png">
  <?= site_config_css_vars($siteSettings) ?>
  <script src="https://cdn.tailwindcss.com/3.4.16"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    * { box-sizing: border-box; }
    body { font-family: 'DM Sans', sans-serif; background: var(--site-primary-pale); margin: 0; }

    .nav-link { position: relative; transition: color 0.2s; }
    .nav-link::after { content: ''; position: absolute; bottom: -2px; left: 0; width: 0; height: 2px; background: var(--site-primary); transition: width 0.3s; }
    .nav-link:hover::after { width: 100%; }
    .nav-link:hover { color: var(--site-primary-dark); }

    /* Banner */
    .banner-placeholder {
      width: 100%; height: 160px;
      background: linear-gradient(135deg, var(--site-primary-dark) 0%, var(--site-primary) 50%, var(--site-primary-light) 100%);
      border-radius: 16px; display: flex; align-items: center; justify-content: center;
      overflow: hidden; position: relative;
    }
    .banner-placeholder::before {
      content: '';
      position: absolute; inset: 0;
      background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.06'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    }
    .banner-inner { display: flex; align-items: center; gap: 16px; z-index: 1; }
    .banner-icon { width: 64px; height: 64px; background: rgba(255,255,255,0.15); border-radius: 16px; display: flex; align-items: center; justify-content: center; border: 1.5px solid rgba(255,255,255,0.2); }

    /* Form card */
    .form-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 18px; padding: 32px; box-shadow: 0 2px 20px rgba(var(--site-primary-rgb),0.06); }

    .form-section-title { font-size: 0.72rem; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.08em; padding-bottom: 8px; border-bottom: 1px solid #e5e7eb; margin-bottom: 20px; }

    .field-label { display: block; font-size: 0.875rem; font-weight: 600; color: #374151; margin-bottom: 8px; }
    .field-label .req { color: #dc2626; margin-left: 2px; }

    .form-input, .form-select, .form-textarea {
      width: 100%; padding: 10px 14px;
      border: 1.5px solid #d1d5db; border-radius: 10px;
      font-size: 0.875rem; font-family: 'DM Sans', sans-serif;
      color: #374151; background: #fff;
      transition: border-color 0.18s, box-shadow 0.18s; outline: none;
    }
    .form-input:focus, .form-select:focus, .form-textarea:focus { border-color: var(--site-primary); box-shadow: 0 0 0 3px rgba(var(--site-primary-rgb),0.12); }
    .form-input::placeholder, .form-textarea::placeholder { color: #9ca3af; }
    .form-select { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; padding-right: 36px; cursor: pointer; }
    .form-textarea { resize: vertical; min-height: 120px; }

    /* Number spinner */
    .number-wrap { position: relative; }
    .number-wrap input[type="number"] { appearance: textfield; -moz-appearance: textfield; padding-right: 36px; }
    .number-wrap input[type="number"]::-webkit-outer-spin-button,
    .number-wrap input[type="number"]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    .spinner-btns { position: absolute; right: 0; top: 0; bottom: 0; width: 34px; display: flex; flex-direction: column; border-left: 1.5px solid #d1d5db; overflow: hidden; border-radius: 0 10px 10px 0; }
    .spin-btn { flex: 1; background: #f9fafb; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 10px; color: #6b7280; transition: background 0.12s; line-height: 1; }
    .spin-btn:first-child { border-bottom: 1px solid #e5e7eb; }
    .spin-btn:hover { background: var(--site-primary-pale); color: var(--site-primary-dark); }

    /* Upload area */
    .upload-hint { font-size: 0.8rem; color: #6b7280; font-style: italic; margin-bottom: 12px; }
    .upload-hint span { font-weight: 600; color: var(--site-primary-dark); }

    .upload-btn {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 9px 18px; background: #1f2937; color: #fff;
      font-size: 0.84rem; font-weight: 600; border-radius: 8px;
      cursor: pointer; border: none; transition: background 0.15s;
      user-select: none;
    }
    .upload-btn:hover { background: #111827; }

    /* File preview list */
    .file-list { margin-top: 12px; border: 1.5px solid #e5e7eb; border-radius: 10px; overflow: hidden; }
    .file-item { display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-bottom: 1px solid #f3f4f6; background: #fff; transition: background 0.12s; }
    .file-item:last-child { border-bottom: none; }
    .file-item:hover { background: #f9fafb; }
    .file-thumb { width: 48px; height: 48px; border-radius: 6px; object-fit: cover; border: 1px solid #e5e7eb; background: #f3f4f6; flex-shrink: 0; display: flex; align-items: center; justify-content: center; }
    .file-name { flex: 1; font-size: 0.82rem; color: #374151; font-weight: 500; word-break: break-all; }
    .file-size { font-size: 0.72rem; color: #9ca3af; white-space: nowrap; }
    .remove-file { width: 26px; height: 26px; border-radius: 50%; background: #fee2e2; color: #dc2626; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 11px; transition: background 0.12s; }
    .remove-file:hover { background: #fecaca; }

    /* Submit */
    .btn-submit { display: inline-flex; align-items: center; gap: 8px; padding: 12px 32px; background: var(--site-primary-dark); color: #fff; font-size: 0.9rem; font-weight: 700; border: none; border-radius: 12px; cursor: pointer; transition: background 0.18s, transform 0.15s, box-shadow 0.18s; box-shadow: 0 4px 16px rgba(var(--site-primary-rgb),0.2); }
    .btn-submit:hover { background: var(--site-primary-darker); transform: translateY(-1px); box-shadow: 0 6px 20px rgba(var(--site-primary-rgb),0.28); }
    .btn-submit:active { transform: translateY(0); }
    .btn-submit:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

    .back-link { display: inline-flex; align-items: center; gap: 6px; color: #6b7280; font-size: 0.84rem; font-weight: 500; text-decoration: none; transition: color 0.15s; }
    .back-link:hover { color: var(--site-primary-dark); }

    .error-msg { font-size: 0.75rem; color: #dc2626; margin-top: 4px; display: none; }
    .error-msg.show { display: block; }
    .field-error { border-color: #dc2626 !important; }

    @keyframes fadeUp { from { opacity:0; transform:translateY(16px); } to { opacity:1; transform:translateY(0); } }
    .f1 { animation: fadeUp 0.4s 0.05s ease both; }
    .f2 { animation: fadeUp 0.4s 0.12s ease both; }
    .f3 { animation: fadeUp 0.4s 0.19s ease both; }

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
        <h3 class="font-bold text-green-900 text-base leading-tight"><?= e($siteSettings['site_title']) ?></h3>
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
              <?= htmlspecialchars($initials) ?>
            </span>
            <span class="hidden lg:block text-gray-700 text-sm max-w-[140px] truncate">
              <?= htmlspecialchars($userName) ?>
            </span>
            <svg id="profile-chevron" class="w-4 h-4 text-gray-400 transition-transform duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
            </svg>
          </button>
          <div id="profile-dropdown" class="hidden absolute right-0 mt-2 w-64 bg-white rounded-2xl shadow-xl border border-gray-100 overflow-hidden z-50" role="menu">
            <div class="px-4 py-3 bg-gradient-to-br from-green-50 to-emerald-50 border-b border-gray-100">
              <div class="flex items-center gap-3">
                <span class="w-10 h-10 rounded-full bg-green-700 text-white flex items-center justify-center text-sm font-bold select-none flex-shrink-0">
                  <?= htmlspecialchars($initials) ?>
                </span>
                <div class="min-w-0">
                  <p class="text-sm font-semibold text-gray-800 truncate"><?= htmlspecialchars($userName) ?></p>
                  <span class="inline-block mt-0.5 px-2 py-0.5 rounded-full text-[11px] font-semibold <?= $roleBadgeClass ?>">
                    <?= htmlspecialchars($roleLabel) ?>
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

  <!-- Back -->
  <div class="f1">
    <a href="residentPanel.php" class="back-link">
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
          <line x1="14" y1="18" x2="26" y2="18" stroke="white" stroke-width="2" stroke-linecap="round"/>
          <line x1="14" y1="23" x2="26" y2="23" stroke="white" stroke-width="2" stroke-linecap="round"/>
          <line x1="14" y1="28" x2="20" y2="28" stroke="white" stroke-width="2" stroke-linecap="round"/>
        </svg>
      </div>
      <div>
        <p class="text-white font-bold text-lg leading-tight" style="font-family:'Playfair Display',serif;">Document Request</p>
        <p class="text-green-200 text-xs mt-1">Official barangay certificates &amp; clearances</p>
      </div>
    </div>
  </div>

  <!-- Toast -->
  <?php if ($toastStatus): ?>
  <div class="mb-4 p-3 rounded-lg <?= $toastStatus === 'ok' ? 'bg-green-100 text-green-800 border border-green-200' : 'bg-red-100 text-red-800 border border-red-200' ?>">
    <div class="flex items-center gap-2">
      <i class="fa-solid <?= $toastStatus === 'ok' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i>
      <span><?= htmlspecialchars($toastMessage ?: ($toastStatus === 'ok' ? 'Request submitted successfully.' : 'An error occurred.')) ?></span>
    </div>
  </div>
  <?php endif; ?>

  <!-- Form card -->
  <div class="form-card f2">
    <h2 class="text-xl font-bold text-green-950 text-center mb-1" style="font-family:'Playfair Display',serif;"><?= e($siteSettings['site_title']) ?> Document Request</h2>
    <p class="text-xs text-gray-500 text-center mb-8">Fill out all required fields marked with <span class="text-red-500 font-bold">*</span></p>

    <form method="POST" action="" id="documentForm" enctype="multipart/form-data" novalidate>

      <!-- ──────────── DOC TYPE + COPIES ──────────── -->
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mb-6">

        <div>
          <label class="field-label" for="document_type">Document Type <span class="req">*</span></label>
          <select class="form-select" id="document_type" name="document_type" required onchange="updateRequirements()">
            <option value="" disabled <?= empty($saved['document_type']) ? 'selected' : '' ?>>-- Select --</option>
            <option value="barangay_clearance"      <?= ($saved['document_type'] ?? '') === 'barangay_clearance'      ? 'selected' : '' ?>>Barangay Clearance</option>
            <option value="certificate_indigency"   <?= ($saved['document_type'] ?? '') === 'certificate_indigency'   ? 'selected' : '' ?>>Certificate of Indigency</option>
            <option value="certificate_residency"   <?= ($saved['document_type'] ?? '') === 'certificate_residency'   ? 'selected' : '' ?>>Certificate of Residency</option>
            <option value="business_permit"         <?= ($saved['document_type'] ?? '') === 'business_permit'         ? 'selected' : '' ?>>Barangay Business Permit</option>
          </select>
          <p class="error-msg" id="err_document_type">Please select a document type.</p>
        </div>

        <div>
          <label class="field-label" for="num_copies">Number of Copies <span class="req">*</span></label>
          <div class="number-wrap">
            <input
              type="number"
              class="form-input"
              id="num_copies"
              name="num_copies"
              min="1" max="10"
              value="<?= htmlspecialchars($saved['num_copies'] ?? 1) ?>"
              required
            >
            <div class="spinner-btns">
              <button type="button" class="spin-btn" onclick="spinCopies(1)">-</button>
              <button type="button" class="spin-btn" onclick="spinCopies(-1)">-</button>
            </div>
          </div>
          <p class="error-msg" id="err_num_copies">Please enter a valid number of copies (1 – 10).</p>
        </div>

      </div>

      <!-- ──────────── PURPOSE ──────────── -->
      <div class="mb-6">
        <label class="field-label" for="purpose">Purpose / Reason <span class="req">*</span></label>
        <select class="form-select" id="purpose" name="purpose" required>
          <option value="" disabled <?= empty($saved['purpose']) ? 'selected' : '' ?>>-- Select --</option>
          <option value="employment"           <?= ($saved['purpose'] ?? '') === 'employment'           ? 'selected' : '' ?>>Employment</option>
          <option value="scholarship"          <?= ($saved['purpose'] ?? '') === 'scholarship'          ? 'selected' : '' ?>>Scholarship Application</option>
          <option value="financial_assistance" <?= ($saved['purpose'] ?? '') === 'financial_assistance' ? 'selected' : '' ?>>Financial Assistance</option>
          <option value="loan_application"     <?= ($saved['purpose'] ?? '') === 'loan_application'     ? 'selected' : '' ?>>Loan Application</option>
          <option value="travel_abroad"        <?= ($saved['purpose'] ?? '') === 'travel_abroad'        ? 'selected' : '' ?>>Travel Abroad / OFW</option>
          <option value="business_permit"      <?= ($saved['purpose'] ?? '') === 'business_permit'      ? 'selected' : '' ?>>Business Permit Application</option>
          <option value="court_legal"          <?= ($saved['purpose'] ?? '') === 'court_legal'          ? 'selected' : '' ?>>Court / Legal Purpose</option>
          <option value="school_enrollment"    <?= ($saved['purpose'] ?? '') === 'school_enrollment'    ? 'selected' : '' ?>>School Enrollment</option>
          <option value="government_transact"  <?= ($saved['purpose'] ?? '') === 'government_transact'  ? 'selected' : '' ?>>Government Transaction</option>
          <option value="other"                <?= ($saved['purpose'] ?? '') === 'other'                ? 'selected' : '' ?>>Other</option>
        </select>
        <p class="error-msg" id="err_purpose">Please select a purpose.</p>
      </div>

      <!-- ──────────── UPLOAD SUPPORTING DOCUMENTS ──────────── -->
      <div class="mb-6">
        <p class="field-label">Upload Supporting Document</p>

        <!-- Dynamic requirement hint -->
        <p class="upload-hint" id="upload_hint">
          Select a document type above to see required supporting documents.
        </p>

        <!-- Hidden file input -->
        <input type="file" id="file_input" name="supporting_docs[]" multiple accept="image/jpeg,image/png,.pdf" class="hidden" onchange="handleFiles(this)">

        <!-- Upload button -->
        <label for="file_input" class="upload-btn">
          <i class="fa-solid fa-upload text-sm"></i> Upload Files
        </label>
        <p class="text-xs text-gray-400 mt-2">Accepted: JPG, PNG, PDF &nbsp;·&nbsp; Max 5 MB each</p>

        <!-- File preview list -->
        <div id="file_list" class="file-list" style="display:none;"></div>
        <p class="error-msg" id="err_files"></p>
      </div>

      <!-- ──────────── ADDITIONAL NOTES ──────────── -->
      <div class="mb-8">
        <label class="field-label" for="notes">Additional Notes / Special Instructions:</label>
        <textarea
          class="form-textarea"
          id="notes"
          name="notes"
          placeholder="Any additional information or instructions."
        ><?= htmlspecialchars($saved['notes'] ?? '') ?></textarea>
      </div>

      <!-- Submit -->
      <div class="flex justify-end pt-4 border-t border-gray-100">
        <button type="submit" class="btn-submit" id="submitBtn">
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
  // ─── Document type — required supporting docs ───
  const docReqs = <?= json_encode($docRequirements) ?>;

  function updateRequirements() {
    const sel  = document.getElementById('document_type').value;
    const hint = document.getElementById('upload_hint');
    if (sel && docReqs[sel]) {
      const selText = document.getElementById('document_type').options[document.getElementById('document_type').selectedIndex].text;
      hint.innerHTML = `<span>${selText}</span> requires: <span>${docReqs[sel]}</span>`;
    } else {
      hint.innerHTML = 'Select a document type above to see required supporting documents.';
    }
  }

  window.addEventListener('DOMContentLoaded', updateRequirements);

  // ─── Number spinner ───
  function spinCopies(dir) {
    const inp = document.getElementById('num_copies');
    let val = parseInt(inp.value) || 1;
    val = Math.min(10, Math.max(1, val + dir));
    inp.value = val;
  }

  // ─── File handling ───
  const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5 MB
  const ALLOWED_EXTS  = ['jpg', 'jpeg', 'png', 'pdf'];
  let selectedFiles   = [];

  function handleFiles(input) {
    const newFiles = Array.from(input.files);
    const errEl    = document.getElementById('err_files');
    errEl.classList.remove('show');

    newFiles.forEach(f => {
      const ext = f.name.split('.').pop().toLowerCase();
      if (!ALLOWED_EXTS.includes(ext)) {
        errEl.textContent = `"${f.name}" is not allowed. Use JPG, PNG, or PDF.`;
        errEl.classList.add('show');
        return;
      }
      if (f.size > MAX_FILE_SIZE) {
        errEl.textContent = `"${f.name}" exceeds the 5 MB size limit.`;
        errEl.classList.add('show');
        return;
      }
      if (!selectedFiles.find(x => x.name === f.name && x.size === f.size)) {
        selectedFiles.push(f);
      }
    });

    // Reset so same file can be re-added after removal
    input.value = '';
    renderFileList();
  }

  function removeFile(idx) {
    selectedFiles.splice(idx, 1);
    renderFileList();
    syncFileInput();
  }

  function renderFileList() {
    const list = document.getElementById('file_list');
    if (selectedFiles.length === 0) {
      list.style.display = 'none';
      list.innerHTML = '';
      return;
    }
    list.style.display = 'block';
    list.innerHTML = selectedFiles.map((f, i) => {
      const isImage = f.type.startsWith('image/');
      const isPdf   = f.type === 'application/pdf';
      const size    = f.size < 1024 * 1024 ? (f.size / 1024).toFixed(1) + ' KB' : (f.size / 1024 / 1024).toFixed(1) + ' MB';
      let thumbHtml;
      if (isImage) {
        thumbHtml = `<div class="file-thumb" style="overflow:hidden;padding:0;"><img src="" style="width:100%;height:100%;object-fit:cover;" id="img_${i}"></div>`;
      } else if (isPdf) {
        thumbHtml = `<div class="file-thumb"><i class="fa-solid fa-file-pdf text-red-500 text-lg"></i></div>`;
      } else {
        thumbHtml = `<div class="file-thumb"><i class="fa-solid fa-file text-gray-400 text-lg"></i></div>`;
      }
      return `
        <div class="file-item">
          <button type="button" class="remove-file" onclick="removeFile(${i})" title="Remove">
            <i class="fa-solid fa-xmark"></i>
          </button>
          ${thumbHtml}
          <div class="flex-1 min-w-0">
            <p class="file-name">${escHtml(f.name)}</p>
            <p class="file-size">${size}</p>
          </div>
        </div>`;
    }).join('');

    // Load image previews
    selectedFiles.forEach((f, i) => {
      if (f.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = e => {
          const img = document.getElementById(`img_${i}`);
          if (img) img.src = e.target.result;
        };
        reader.readAsDataURL(f);
      }
    });

    syncFileInput();
  }

  function syncFileInput() {
    const dt = new DataTransfer();
    selectedFiles.forEach(f => dt.items.add(f));
    document.getElementById('file_input').files = dt.files;
  }

  function escHtml(str) {
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  // ─── Form Validation + Submit ───
  document.getElementById('documentForm').addEventListener('submit', function (e) {
    let valid = true;

    const clearErr = id => document.getElementById(id)?.classList.remove('show');
    const showErr  = (id, msg) => {
      const el = document.getElementById(id);
      if (el) { el.textContent = msg || el.textContent; el.classList.add('show'); }
      valid = false;
    };

    // Document type
    const docType = document.getElementById('document_type').value;
    if (!docType) {
      document.getElementById('document_type').classList.add('field-error');
      showErr('err_document_type', 'Please select a document type.');
    } else {
      document.getElementById('document_type').classList.remove('field-error');
      clearErr('err_document_type');
    }

    // Number of copies
    const copies = parseInt(document.getElementById('num_copies').value);
    if (!copies || copies < 1 || copies > 10) {
      document.getElementById('num_copies').classList.add('field-error');
      showErr('err_num_copies', 'Please enter a valid number of copies (1 – 10).');
    } else {
      document.getElementById('num_copies').classList.remove('field-error');
      clearErr('err_num_copies');
    }

    // Purpose
    const purpose = document.getElementById('purpose').value;
    if (!purpose) {
      document.getElementById('purpose').classList.add('field-error');
      showErr('err_purpose', 'Please select a purpose.');
    } else {
      document.getElementById('purpose').classList.remove('field-error');
      clearErr('err_purpose');
    }

    if (!valid) {
      e.preventDefault();
      document.querySelector('.field-error, .error-msg.show')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }

    // Disable submit to prevent double submission
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> &nbsp;Submitting...';
  });

  // ─── Profile dropdown ───
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
      const chev = document.getElementById('profile-chevron');
      if (chev) chev.style.transform = '';
    }
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.getElementById('profile-dropdown')?.classList.add('hidden');
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