<?php
require_once __DIR__ . '/../config/db_connection.php';
session_start();

require_once __DIR__ . '/../includes/site_config.php';
$siteSettings = site_config_load($conn);

if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }

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

$borrow_success = false;
$borrow_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['borrow_items'])) {
    $items = json_decode($_POST['borrow_items'], true) ?? [];
    $returnDate = $_POST['return_date'] ?? '';

    // Validate return date
    if (empty($returnDate) || !strtotime($returnDate)) {
        $returnDate = null;
    }

    // Determine user ID from session acc_id or user_id
    $userId = 0;
    $accId  = trim($_SESSION['acc_id'] ?? '');
    if ($accId !== '') {
        $stmt = $conn->prepare('SELECT userID FROM tbl_userinfo WHERE accID = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $accId);
            $stmt->execute();
            $stmt->bind_result($foundId);
            if ($stmt->fetch()) {
                $userId = (int)$foundId;
            }
            $stmt->close();
        }
    }

    if ($userId <= 0 && !empty($_SESSION['user_id'])) {
        $rawEmail = trim($_SESSION['user_id']);
        $stmt = $conn->prepare('SELECT userID FROM tbl_userinfo WHERE email = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $rawEmail);
            $stmt->execute();
            $stmt->bind_result($foundId);
            if ($stmt->fetch()) {
                $userId = (int)$foundId;
            }
            $stmt->close();
        }
    }

    if ($userId > 0) {
        // INSERT now includes returnDate column
        $insertStmt = $conn->prepare(
            'INSERT INTO tbl_equipmentrequest (userId, equipmentId, quantityRequested, status, requestDate, returnDate, notes)
             VALUES (?, ?, ?, ?, NOW(), ?, ?)'
        );
        if (!$insertStmt) {
            $borrow_message = 'Could not prepare request save: ' . $conn->error;
        } else {
            foreach ($items as $item) {
                $equipmentId = (int)($item['id'] ?? 0);
                $qty = (int)($item['qty'] ?? 0);
                if ($equipmentId > 0 && $qty > 0) {
                    $status = 'pending';
                    $notes  = '';
                    $insertStmt->bind_param('iiisss', $userId, $equipmentId, $qty, $status, $returnDate, $notes);
                    $insertStmt->execute();
                }
            }
            $insertStmt->close();
            $borrow_success = true;
            $borrow_message = 'Borrow request submitted successfully.';
        }
    } else {
        $borrow_message = 'User not found; please log in again.';
    }

    $_SESSION['borrow_request'] = [
        'items'        => $items,
        'return_date'  => $returnDate,
        'submitted_at' => date('Y-m-d H:i:s'),
    ];
}


$equipments = [];

$query = "SELECT equipmentId AS id, equipmentName AS name, equipmentStock AS stock, equipmentImage AS image, description FROM tbl_equipmentlist ORDER BY createdAt DESC";
$result = mysqli_query($conn, $query);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $row['image'] = !empty($row['image']) ? '../uploads/equipment/' . $row['image'] : '../assets/equipment/default.jpg';
        $equipments[] = $row;
    }
    mysqli_free_result($result);
}

$logged_in = isset($_SESSION['user_id']);
$userEmail  = $_SESSION['user_id'] ?? '';
$accId      = $_SESSION['acc_id']  ?? '';
$userName   = $userEmail;
$stmt = $conn->prepare('SELECT firstname FROM tbl_userinfo WHERE accID = ? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('s', $accId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row && !empty($row['firstname'])) $userName = $row['firstname'];
    $stmt->close();
}
$roleLabel = match($role) {
    'admin'        => 'Admin',
    'resident'     => 'Resident',
    'resident,business/apartment owner' => 'Resident & Owner',
    'non-resident' => 'Non-Resident',
    'non-resident,business/apartment owner' => 'Non-Resident & Owner',
    'business/apartment owner' => 'Owner',
    default => 'User',
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
  <title>Borrow Equipment �?" <?= e($siteSettings['site_title']) ?></title>
  <link rel="icon" href="<?= e(site_config_logo_url($siteSettings, '../')) ?>" type="image/png">
  <?= site_config_css_vars($siteSettings) ?>
  <script src="https://cdn.tailwindcss.com/3.4.16"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    * { box-sizing: border-box; }
    body { font-family: 'DM Sans', sans-serif; background: var(--site-primary-pale); margin: 0; }

    /* �"?�"? Navbar �"?�"? */
    .nav-link { position: relative; transition: color 0.2s; }
    .nav-link::after { content: ''; position: absolute; bottom: -2px; left: 0; width: 0; height: 2px; background: var(--site-primary); transition: width 0.3s; }
    .nav-link:hover::after { width: 100%; }
    .nav-link:hover { color: var(--site-primary-dark); }

    /* �"?�"? Banner �"?�"? */
    .banner-placeholder {
      width: 100%; height: 160px;
      background: linear-gradient(135deg, var(--site-primary-dark) 0%, var(--site-primary) 50%, var(--site-primary-light) 100%);
      border-radius: 16px; display: flex; align-items: center; justify-content: center;
      overflow: hidden; position: relative;
    }
    .banner-placeholder::before {
      content: ''; position: absolute; inset: 0;
      background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.06'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    }
    .banner-inner { display: flex; align-items: center; gap: 16px; z-index: 1; }
    .banner-icon { width: 64px; height: 64px; background: rgba(255,255,255,0.15); border-radius: 16px; display: flex; align-items: center; justify-content: center; border: 1.5px solid rgba(255,255,255,0.2); }

    /* �"?�"? Section card �"?�"? */
    .section-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 18px; padding: 28px; box-shadow: 0 2px 12px rgba(var(--site-primary-rgb),0.05); }

    /* �"?�"? Search �"?�"? */
    .search-wrap { position: relative; }
    .search-wrap i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #9ca3af; font-size: 0.85rem; pointer-events: none; }
    .search-input {
      width: 100%; padding: 11px 14px 11px 40px;
      border: 1.5px solid #d1d5db; border-radius: 10px;
      font-size: 0.875rem; font-family: 'DM Sans', sans-serif;
      color: #374151; background: #fff; outline: none;
      transition: border-color 0.18s, box-shadow 0.18s;
      box-shadow: 0 1px 4px rgba(0,0,0,0.04);
    }
    .search-input:focus { border-color: var(--site-primary); box-shadow: 0 0 0 3px rgba(var(--site-primary-rgb),0.1); }
    .search-input::placeholder { color: #9ca3af; }

    /* �"?�"? Equipment card �"?�"? */
    .eq-card {
      background: #fff; border: 1.5px solid #e5e7eb; border-radius: 16px;
      overflow: hidden; transition: border-color 0.22s, box-shadow 0.22s, transform 0.22s;
      display: flex; flex-direction: column;
      box-shadow: 0 2px 10px rgba(var(--site-primary-rgb),0.04);
    }
    .eq-card.selectable:hover {
      border-color: var(--site-primary);
      box-shadow: 0 8px 28px rgba(var(--site-primary-rgb),0.13);
      transform: translateY(-3px);
    }
    .eq-card.selected {
      border-color: var(--site-primary);
      box-shadow: 0 0 0 3px rgba(var(--site-primary-rgb),0.18), 0 8px 28px rgba(var(--site-primary-rgb),0.13);
    }
    .eq-card.unavailable { opacity: 0.55; }

    /* Image placeholder */
    .eq-img-placeholder {
      width: 100%; aspect-ratio: 4/3;
      background: linear-gradient(135deg, color-mix(in srgb, var(--site-primary) 20%, white) 0%, color-mix(in srgb, var(--site-primary-light) 40%, white) 100%);
      display: flex; align-items: center; justify-content: center;
      position: relative; overflow: hidden;
    }
    .eq-img-placeholder::before,
    .eq-img-placeholder::after {
      content: ''; position: absolute;
      background: rgba(var(--site-primary-rgb),0.15); height: 1.5px; width: 142%;
    }
    .eq-img-placeholder::before { transform: rotate(35deg); }
    .eq-img-placeholder::after  { transform: rotate(-35deg); }
    .eq-img { width: 100%; aspect-ratio: 4/3; object-fit: cover; }

    /* �"?�"? Availability badge �"?�"? */
    .badge-avail { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 999px; font-size: 0.7rem; font-weight: 700; border: 1px solid; }
    .badge-avail.available   { background: color-mix(in srgb, var(--site-primary) 20%, white); color: var(--site-primary-dark); border-color: var(--site-primary-light); }
    .badge-avail.unavailable { background: #f3f4f6; color: #6b7280; border-color: #d1d5db; }

    /* �"?�"? Qty stepper �"?�"? */
    .qty-wrap {
      display: inline-flex; align-items: center;
      border: 1.5px solid #d1d5db; border-radius: 9px;
      overflow: hidden; background: #f9fafb;
    }
    .qty-btn {
      width: 34px; height: 34px; border: none;
      background: #f3f4f6; color: #374151;
      font-size: 1.05rem; font-weight: 700; line-height: 1;
      cursor: pointer; display: flex; align-items: center; justify-content: center;
      transition: background 0.14s, color 0.14s; flex-shrink: 0;
    }
    .qty-btn:hover:not(:disabled) { background: color-mix(in srgb, var(--site-primary) 20%, white); color: var(--site-primary-dark); }
    .qty-btn:disabled { color: #d1d5db; cursor: not-allowed; background: #f9fafb; }
    .qty-val {
      min-width: 38px; text-align: center; font-size: 0.9rem; font-weight: 700;
      color: #374151; background: #fff;
      border-left: 1.5px solid #e5e7eb; border-right: 1.5px solid #e5e7eb;
      height: 34px; display: flex; align-items: center; justify-content: center;
      padding: 0 6px;
    }

    /* �"?�"? Inline Borrow Button �"?�"? */
    .borrow-btn {
      width: 100%;
      display: flex; align-items: center; justify-content: center; gap: 10px;
      padding: 16px 28px;
      background: linear-gradient(135deg, var(--site-primary-dark) 0%, var(--site-primary) 100%);
      color: #fff; font-size: 1rem; font-weight: 700;
      border: none; border-radius: 14px; cursor: pointer;
      transition: opacity 0.18s, transform 0.15s, box-shadow 0.18s;
      box-shadow: 0 6px 24px rgba(var(--site-primary-rgb),0.32);
      letter-spacing: 0.01em;
    }
    .borrow-btn:hover:not(:disabled) { opacity: 0.92; transform: translateY(-1px); box-shadow: 0 10px 32px rgba(var(--site-primary-rgb),0.38); }
    .borrow-btn:disabled { background: #d1d5db; color: #9ca3af; cursor: not-allowed; transform: none; box-shadow: none; }
    .cart-badge {
      display: inline-flex; align-items: center; justify-content: center;
      background: rgba(255,255,255,0.25); color: #fff;
      font-size: 0.75rem; font-weight: 800;
      min-width: 22px; height: 22px; padding: 0 7px;
      border-radius: 999px;
    }

    /* �"?�"? Modal �"?�"? */
    .modal-overlay {
      position: fixed; inset: 0; z-index: 500;
      background: color-mix(in srgb, var(--site-primary-darker) 55%, transparent); backdrop-filter: blur(4px);
      display: flex; align-items: center; justify-content: center; padding: 20px;
      opacity: 0; pointer-events: none; transition: opacity 0.22s;
    }
    .modal-overlay.open { opacity: 1; pointer-events: auto; }
    .modal-box {
      background: #fff; border-radius: 20px; width: 100%; max-width: 500px;
      box-shadow: 0 24px 60px rgba(var(--site-primary-rgb),0.22);
      transform: translateY(22px); transition: transform 0.25s cubic-bezier(0.4,0,0.2,1);
      overflow: hidden;
    }
    .modal-overlay.open .modal-box { transform: translateY(0); }
    .modal-header {
      background: #f9fafb; border-bottom: 1px solid #d7d8d8;
      padding: 18px 22px; display: flex; align-items: center; justify-content: space-between;
    }
    .modal-body { padding: 22px; max-height: 70vh; overflow-y: auto; }
    .modal-body::-webkit-scrollbar { width: 4px; }
    .modal-body::-webkit-scrollbar-thumb { background: #d1fae5; border-radius: 4px; }
    .modal-footer { padding: 14px 22px; border-top: 1px solid #f3f4f6; display: flex; gap: 10px; }

    /* Items summary */
    .items-summary-box {
      border: 1.5px solid var(--site-primary-light); border-radius: 12px;
      padding: 14px 16px; background: var(--site-primary-pale);
    }
    .items-summary-box li {
      font-size: 0.875rem; color: #374151; padding: 4px 0;
      display: flex; align-items: center; gap: 8px;
    }
    .items-summary-box li::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: var(--site-primary); flex-shrink: 0; }
    .items-summary-box li strong { color: var(--site-primary-dark); font-weight: 800; }

    /* Date input */
    .date-input {
      width: 100%; padding: 11px 14px; border: 1.5px solid #d1d5db; border-radius: 10px;
      font-size: 0.875rem; font-family: 'DM Sans', sans-serif; color: #374151;
      outline: none; transition: border-color 0.18s, box-shadow 0.18s;
    }
    .date-input:focus { border-color: var(--site-primary); box-shadow: 0 0 0 3px rgba(var(--site-primary-rgb),0.12); }

    /* Modal buttons */
    .btn-cancel { flex: 1; padding: 12px; background: #f3f4f6; color: #374151; font-size: 0.875rem; font-weight: 600; border: none; border-radius: 10px; cursor: pointer; transition: background 0.15s; }
    .btn-cancel:hover { background: #e5e7eb; }
    .btn-confirm {
      flex: 1; padding: 12px;
      background: linear-gradient(135deg, var(--site-primary-dark) 0%, var(--site-primary) 100%);
      color: #fff; font-size: 0.875rem; font-weight: 700;
      border: none; border-radius: 10px; cursor: pointer;
      transition: opacity 0.15s, transform 0.12s;
      box-shadow: 0 3px 10px rgba(var(--site-primary-rgb),0.25);
    }
    .btn-confirm:hover { opacity: 0.9; transform: translateY(-1px); }

    /* Back link */
    .back-link { display: inline-flex; align-items: center; gap: 6px; color: #6b7280; font-size: 0.84rem; font-weight: 500; text-decoration: none; transition: color 0.15s; }
    .back-link:hover { color: var(--site-primary-dark); }

    /* Empty state */
    .no-results { grid-column: 1/-1; text-align: center; padding: 56px 20px; color: #9ca3af; }

    .error-msg { font-size: 0.75rem; color: #dc2626; margin-top: 5px; display: none; }
    .error-msg.show { display: block; }

    /* Animations */
    @keyframes fadeUp { from { opacity:0; transform:translateY(14px); } to { opacity:1; transform:translateY(0); } }
    .f1 { animation: fadeUp 0.4s 0.05s ease both; }
    .f2 { animation: fadeUp 0.4s 0.12s ease both; }
    .f3 { animation: fadeUp 0.4s 0.19s ease both; }
    .f4 { animation: fadeUp 0.4s 0.26s ease both; }
    .eq-card:nth-child(1) { animation: fadeUp 0.4s 0.10s ease both; }
    .eq-card:nth-child(2) { animation: fadeUp 0.4s 0.16s ease both; }
    .eq-card:nth-child(3) { animation: fadeUp 0.4s 0.22s ease both; }
    .eq-card:nth-child(4) { animation: fadeUp 0.4s 0.28s ease both; }
    .eq-card:nth-child(5) { animation: fadeUp 0.4s 0.34s ease both; }
    .eq-card:nth-child(6) { animation: fadeUp 0.4s 0.40s ease both; }

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

<!-- �"?�"? NAVBAR �"?�"? -->
<header class="w-full h-[68px] border-b border-green-100 flex items-center px-4 sm:px-6 lg:px-8 bg-white shadow-sm sticky top-0 z-50">
  <div class="flex items-center gap-3 flex-shrink-0">
    <a href="residentLanding.php" class="flex items-center gap-3">
      <div class="w-10 h-10 rounded-xl bg-green-700 flex items-center justify-center shadow overflow-hidden">
        <img src="<?= e(site_config_logo_url($siteSettings, '../')) ?>" alt="Logo" class="w-full h-full object-contain"/>
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

<!-- �.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.� MOBILE SIDEBAR �.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.� -->
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

<main class="max-w-3xl mx-auto px-4 py-10 space-y-6">

  <!-- Back -->
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
          <rect x="5" y="18" width="30" height="16" rx="3" stroke="white" stroke-width="2" fill="none"/>
          <path d="M13 18V13a7 7 0 0 1 14 0v5" stroke="white" stroke-width="2" stroke-linecap="round"/>
          <circle cx="20" cy="26" r="2.5" fill="white"/>
        </svg>
      </div>
      <div>
        <p class="text-white font-bold text-lg leading-tight" style="font-family:'Playfair Display',serif;">Borrow Equipment</p>
        <p class="text-green-200 text-xs mt-1">Request barangay equipment for community use</p>
      </div>
    </div>
  </div>

  <?php if (isset($borrow_message) && $borrow_message !== ''): ?>
    <div class="mb-4 p-3 rounded-lg <?= $borrow_success ? 'bg-green-100 text-green-800 border border-green-200' : 'bg-red-100 text-red-800 border border-red-200' ?>">
      <div class="flex items-center gap-2">
        <i class="fa-solid fa-info-circle"></i>
        <span><?= htmlspecialchars($borrow_message) ?></span>
      </div>
    </div>
  <?php endif; ?>

  <!-- Search card -->
  <div class="section-card f2" style="padding: 18px 22px;">
    <div class="search-wrap">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="text" class="search-input" id="searchInput" placeholder="Search equipment..." oninput="filterEquipments()">
    </div>
  </div>

  <!-- Equipment section -->
  <div class="f3">
    <div class="flex items-center justify-between mb-5">
      <h2 class="text-xl font-bold text-green-950" style="font-family:'Playfair Display',serif;">Available Equipments</h2>
      <span class="text-xs text-gray-400 font-medium bg-white border border-gray-200 px-3 py-1 rounded-full" id="resultCount"><?= count($equipments) ?> items</span>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-5" id="equipmentGrid">

      <?php foreach ($equipments as $eq):
        $available  = $eq['stock'] > 0;
        $cardClass  = $available ? 'selectable' : 'unavailable';
        $badgeClass = $available ? 'available'  : 'unavailable';
        $badgeLabel = $available ? 'Available'  : 'Unavailable';
        $badgeIcon  = $available ? 'fa-circle-check' : 'fa-circle-xmark';
      ?>
      <div
        class="eq-card <?= $cardClass ?>"
        data-id="<?= $eq['id'] ?>"
        data-name="<?= htmlspecialchars($eq['name']) ?>"
        data-stock="<?= $eq['stock'] ?>"
        data-available="<?= $available ? 'true' : 'false' ?>"
      >
        <?php if (!empty($eq['image']) && file_exists($eq['image'])): ?>
          <img src="<?= htmlspecialchars($eq['image']) ?>" alt="<?= htmlspecialchars($eq['name']) ?>" class="eq-img">
        <?php else: ?>
          <div class="eq-img-placeholder">
            <svg viewBox="0 0 64 48" fill="none" class="w-12 h-12 opacity-30">
              <rect x="1" y="1" width="62" height="46" rx="4" stroke="var(--site-primary-dark)" stroke-width="2"/>
              <line x1="1" y1="1" x2="63" y2="47" stroke="var(--site-primary-dark)" stroke-width="1.5"/>
              <line x1="63" y1="1" x2="1" y2="47" stroke="var(--site-primary-dark)" stroke-width="1.5"/>
            </svg>
          </div>
        <?php endif; ?>

        <div class="p-4 flex flex-col gap-3 flex-1">
          <div>
            <span class="badge-avail <?= $badgeClass ?>">
              <i class="fa-solid <?= $badgeIcon ?> text-[9px]"></i>
              <?= $badgeLabel ?>
            </span>
            <p class="font-bold text-gray-900 text-[15px] mt-2 leading-snug"><?= htmlspecialchars($eq['name']) ?></p>
            <p class="text-xs text-gray-400 mt-0.5 flex items-center gap-1.5">
              <i class="fa-solid fa-box-archive text-[9px]"></i>
              <?= $eq['stock'] ?> pcs in storage
            </p>
          </div>

          <div class="flex justify-end mt-auto pt-2 border-t border-gray-50">
            <div class="qty-wrap">
              <button type="button" class="qty-btn" onclick="changeQty(<?= $eq['id'] ?>, -1)" id="btn_minus_<?= $eq['id'] ?>" <?= !$available ? 'disabled' : '' ?>>�^'</button>
              <div class="qty-val" id="qty_<?= $eq['id'] ?>">0</div>
              <button type="button" class="qty-btn" onclick="changeQty(<?= $eq['id'] ?>, 1)"  id="btn_plus_<?= $eq['id'] ?>"  <?= !$available ? 'disabled' : '' ?>>+</button>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>

      <div id="noResults" class="no-results hidden">
        <div class="w-16 h-16 rounded-2xl bg-green-50 flex items-center justify-center mx-auto mb-4">
          <i class="fa-solid fa-box-open text-2xl text-green-300"></i>
        </div>
        <p class="font-semibold text-gray-500">No equipment found</p>
        <p class="text-sm text-gray-400 mt-1">Try a different search term</p>
      </div>

    </div>
  </div>

  <!-- �"?�"? BORROW BUTTON (inline, below grid) �"?�"? -->
  <div class="f4">
    <button class="borrow-btn" id="borrowBtn" onclick="openBorrowModal()" disabled>
      <i class="fa-solid fa-basket-shopping text-sm"></i>
      <span id="borrowBtnText">Borrow</span>
    </button>
  </div>

</main>

<!-- �"?�"? BORROW REQUEST MODAL �"?�"? -->
<div class="modal-overlay" id="borrowModal" onclick="closeModalOnOverlay(event)">
  <div class="modal-box">

    <div class="modal-header">
      <div class="flex items-center gap-3">
        <div class="w-9 h-9 rounded-xl bg-green-700 flex items-center justify-center flex-shrink-0">
          <i class="fa-solid fa-basket-shopping text-white text-sm"></i>
        </div>
        <div>
          <p class="font-bold text-gray-900 text-base leading-tight">Borrow Request</p>
          <p class="text-xs text-gray-400 mt-0.5">Review your selected items</p>
        </div>
      </div>
      <button onclick="closeModal()" class="w-8 h-8 rounded-full bg-gray-100 hover:bg-gray-200 flex items-center justify-center text-gray-500 transition text-sm">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>

    <div class="modal-body space-y-5">

      <div class="items-summary-box">
        <p class="text-[11px] font-bold text-green-700 uppercase tracking-widest mb-3">Selected Items</p>
        <ul id="modalItemsList" class="space-y-1"></ul>
      </div>

      <div>
        <label class="block text-sm font-semibold text-gray-700 mb-2">
          <span class="text-red-500 mr-0.5">*</span>Set Return Date
        </label>
        <input type="date" class="date-input" id="returnDate" min="">
        <p class="error-msg" id="err_return_date">Please set a return date.</p>
      </div>

      <div class="bg-green-50 border border-green-100 rounded-xl p-3 flex gap-2 items-start">
        <i class="fa-solid fa-circle-info text-green-500 text-xs mt-0.5 flex-shrink-0"></i>
        <p class="text-xs text-green-700 leading-relaxed">Please visit the barangay hall to pick up approved equipment on your scheduled date. Items must be returned in good condition by the set return date.</p>
      </div>

    </div>

    <div class="modal-footer">
      <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
      <button type="button" class="btn-confirm" onclick="submitBorrow()">
        <i class="fa-solid fa-check mr-1.5"></i> Confirm Borrow
      </button>
    </div>

  </div>
</div>

<!-- Hidden submit form -->
<form method="POST" action="" id="borrowSubmitForm" style="display:none;">
  <input type="hidden" name="borrow_items" id="hiddenItems">
  <input type="hidden" name="return_date"  id="hiddenDate">
</form>

<!-- �"?�"? FOOTER �"?�"? -->
<footer class="mt-16 bg-green-950 text-white pt-14 pb-6 px-4">
  <div class="max-w-6xl mx-auto">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-10 pb-10 border-b border-green-800">
      <div>
        <div class="flex items-center gap-3 mb-4">
          <div class="w-12 h-12 rounded-xl bg-green-700 flex items-center justify-center"><img src="<?= e(site_config_logo_url($siteSettings, '../')) ?>" alt="Logo" class="w-full h-full object-contain"/></div>
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
    <div class="text-center mt-6 text-green-500 text-sm">© 2026 <?= e($siteSettings['site_title']) ?>. All Rights Reserved. Made with �YO� for <?= e($siteSettings['barangay_name']) ?>.</div>
  </div>
</footer>

<script>
  const quantities = {};
  const stocks     = {};
  <?php foreach ($equipments as $eq): ?>
    stocks[<?= $eq['id'] ?>]     = <?= $eq['stock'] ?>;
    quantities[<?= $eq['id'] ?>] = 0;
  <?php endforeach; ?>

  function changeQty(id, delta) {
    const max = stocks[id] || 0;
    let val = (quantities[id] || 0) + delta;
    val = Math.max(0, Math.min(max, val));
    quantities[id] = val;
    document.getElementById(`qty_${id}`).textContent    = val;
    document.getElementById(`btn_minus_${id}`).disabled = val <= 0;
    document.getElementById(`btn_plus_${id}`).disabled  = val >= max;
    const card = document.querySelector(`.eq-card[data-id="${id}"]`);
    if (card) card.classList.toggle('selected', val > 0);
    updateBorrowBtn();
  }

  function updateBorrowBtn() {
    const total = Object.values(quantities).reduce((a, b) => a + b, 0);
    const btn   = document.getElementById('borrowBtn');
    const txt   = document.getElementById('borrowBtnText');
    btn.disabled = total === 0;
    if (total > 0) {
      txt.innerHTML = `Borrow &nbsp;<span class="cart-badge">${total} item${total !== 1 ? 's' : ''}</span>`;
    } else {
      txt.textContent = 'Borrow';
    }
  }

  function filterEquipments() {
    const q     = document.getElementById('searchInput').value.toLowerCase().trim();
    const cards = document.querySelectorAll('#equipmentGrid .eq-card');
    let shown   = 0;
    cards.forEach(card => {
      const match = !q || card.dataset.name.toLowerCase().includes(q);
      card.style.display = match ? '' : 'none';
      if (match) shown++;
    });
    document.getElementById('noResults').classList.toggle('hidden', shown > 0);
    document.getElementById('resultCount').textContent = shown + ' item' + (shown !== 1 ? 's' : '');
  }

  function openBorrowModal() {
    const ul = document.getElementById('modalItemsList');
    ul.innerHTML = '';
    let hasItems = false;
    document.querySelectorAll('#equipmentGrid .eq-card').forEach(card => {
      const id  = parseInt(card.dataset.id);
      const qty = quantities[id] || 0;
      if (qty > 0) {
        hasItems = true;
        const li = document.createElement('li');
        li.innerHTML = `<strong>${qty}�-</strong> ${card.dataset.name}`;
        ul.appendChild(li);
      }
    });
    if (!hasItems) return;

    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    const minDate  = tomorrow.toISOString().split('T')[0];
    const retInput = document.getElementById('returnDate');
    retInput.min   = minDate;
    if (!retInput.value || retInput.value < minDate) retInput.value = minDate;

    document.getElementById('err_return_date').classList.remove('show');
    document.getElementById('borrowModal').classList.add('open');
    document.body.style.overflow = 'hidden';
  }

  function closeModal() {
    document.getElementById('borrowModal').classList.remove('open');
    document.body.style.overflow = '';
  }

  function closeModalOnOverlay(e) {
    if (e.target === document.getElementById('borrowModal')) closeModal();
  }

  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

  function submitBorrow() {
    const returnDate = document.getElementById('returnDate').value;
    if (!returnDate) { document.getElementById('err_return_date').classList.add('show'); return; }
    document.getElementById('err_return_date').classList.remove('show');
    const items = [];
    document.querySelectorAll('#equipmentGrid .eq-card').forEach(card => {
      const id  = parseInt(card.dataset.id);
      const qty = quantities[id] || 0;
      if (qty > 0) items.push({ id, name: card.dataset.name, qty });
    });
    document.getElementById('hiddenItems').value = JSON.stringify(items);
    document.getElementById('hiddenDate').value  = returnDate;
    document.getElementById('borrowSubmitForm').submit();
  }

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

  /* �"?�"? Mobile sidebar �"?�"? */
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