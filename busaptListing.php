<?php
session_start();
$logged_in = isset($_SESSION['user_id']);
$role = $_SESSION['account_role'] ?? '';
if (is_array($role)) {
    $role = implode(',', $role);
}
$userName = $_SESSION['user_name'] ?? $_SESSION['user_id'] ?? 'User';
$currentType = strtolower(trim($_GET['type'] ?? ''));

if ($logged_in && empty($_SESSION['user_name'])) {
    $accId = $_SESSION['acc_id'] ?? null;
    if ($accId) {
        include_once 'config/db_connection.php';
        $stmt = $conn->prepare('SELECT firstname FROM tbl_userinfo WHERE accID = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $accId);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $userName = !empty($row['firstname']) ? $row['firstname'] : $userName;
                $_SESSION['user_name'] = $userName;
            }
            $stmt->close();
        }
    }
}

if (!isset($conn)) {
    include_once 'config/db_connection.php';
}

require_once __DIR__ . '/includes/site_config.php';
$siteSettings = site_config_load($conn);

$listings = [];
$sql = "SELECT l.* FROM tbl_busaptlisting l
        JOIN tbl_userinfo u ON l.userId = u.accID
        WHERE LOWER(u.userStatus) = 'approved'
        ORDER BY l.createdAt DESC";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $row['photos_arr'] = json_decode($row['photos'] ?? '[]', true) ?: [];
        $isApt = ($row['listingType'] === 'apt' || $row['listingType'] === 'apartment');
        $row['is_apartment'] = $isApt;
        $row['display_name'] = $isApt
            ? ($row['aptTitle'] ?: ($row['aptType'] ? ucfirst($row['aptType']) . ' Unit' : 'Apartment'))
            : ($row['bussName'] ?: 'Business');
        $row['display_status'] = $isApt
            ? ($row['aptStatus'] ?? 'available')
            : ($row['bussStatus'] ?? 'open');
        $row['display_price'] = $isApt
            ? ($row['aptPrice'] ? '₱' . number_format((float)$row['aptPrice'], 0) . ' / month' : 'Price on inquiry')
            : ($row['bussPrice'] ? '₱' . $row['bussPrice'] : ($row['bussCat'] ? ucfirst(str_replace('-', ' ', $row['bussCat'])) : 'Business'));
        $row['display_category'] = $isApt
            ? ($row['aptType'] ?? 'apartment')
            : ($row['bussCat'] ?? 'business');
        $row['first_photo'] = !empty($row['photos_arr']) ? 'uploads/listings/' . $row['photos_arr'][0] : '';
        $row['days_ago'] = !empty($row['createdAt'])
            ? (int)((time() - strtotime($row['createdAt'])) / 86400)
            : 999;
        $listings[] = $row;
    }
}

$initials = strtoupper(substr($userName, 0, 2));
$roleLabel = match($role) {
    'admin'        => 'Admin',
    'resident'     => 'Resident',
    'resident,business/apartment owner'     => 'Resident & Owner',
    'non-resident' => 'Non-Resident',
    'non-resident,business/apartment owner' => 'Non-Resident & Owner',
    'business/apartment owner' => 'Owner',
    'business' => 'Owner',
    default        => 'User',
};

$roleBadgeClass = match($role) {
    'admin'        => 'bg-purple-100 text-purple-700 border border-purple-200',
    'resident'     => 'bg-green-100 text-green-700 border border-green-200',
    'resident,business/apartment owner' => 'bg-green-100 text-green-700 border border-green-200',
    'non-resident' => 'bg-blue-100 text-blue-700 border border-blue-200',
    'non-resident,business/apartment owner' => 'bg-blue-100 text-blue-700 border border-blue-200',
    'business/apartment owner' => 'bg-blue-100 text-blue-700 border border-blue-200',
    'business' => 'bg-blue-100 text-blue-700 border border-blue-200',
    default        => 'bg-gray-100 text-gray-600 border border-gray-200',
};

// Show My Panel only for pure resident or resident+owner (NOT non-resident variants)
$roleLower = strtolower(trim($role));
$showMyPanel = $logged_in && (
    $roleLower === 'resident' ||
    $roleLower === 'resident,business/apartment owner'
);

// Staff accounts (granted individual admin modules - see includes/check_permissions.php)
// are treated like admin here: they land in the admin area, not the public
// resident/non-resident profile pages.
$isAdminLike = in_array($roleLower, ['admin', 'staff'], true);

// "My Profile" link varies by role - shared by both the desktop dropdown
// and the mobile sidebar. Admins and staff don't get a profile link at all
// (they already have Dashboard); residents and non-residents get their own
// respective profile pages.
if ($isAdminLike) {
    $profileUrl = null;
} elseif ($showMyPanel) {
    $profileUrl   = 'resident/myProfile.php';
    $profileLabel = 'My Profile';
    $profileIcon  = 'fa-user';
} else {
    $profileUrl   = 'nonResident/nonresidentProfile.php';
    $profileLabel = 'My Profile';
    $profileIcon  = 'fa-user';
}

function getTypeIcon(string $type, bool $isApt): string {
    if ($isApt) {
        return match($type) {
            'bed-spacer'  => 'fa-bed',
            'studio'      => 'fa-couch',
            'solo-room'   => 'fa-door-closed',
            '1br'         => 'fa-house',
            '2br'         => 'fa-house-chimney',
            'whole-unit'  => 'fa-building',
            default       => 'fa-building',
        };
    }
    return match($type) {
        'food','bakery'  => 'fa-utensils',
        'water'          => 'fa-droplet',
        'sari-sari'      => 'fa-store',
        'salon'          => 'fa-scissors',
        'laundry'        => 'fa-shirt',
        'pharmacy'       => 'fa-pills',
        'printing'       => 'fa-print',
        'hardware'       => 'fa-screwdriver-wrench',
        default          => 'fa-store',
    };
}

function getCardGradient(bool $isApt, string $status): string {
    if ($status === 'occupied' || $status === 'temp-closed') {
        return 'linear-gradient(135deg,#fee2e2,#fecaca)';
    }
    if ($isApt) {
        return 'linear-gradient(135deg,var(--site-primary-pale),color-mix(in srgb, var(--site-primary) 25%, white))';
    }
    return 'linear-gradient(135deg,#dbeafe,#bfdbfe)';
}

function getStatusBadgeClass(string $status): string {
    return match($status) {
        'available','open','new' => 'b-avail',
        'occupied','temp-closed' => 'b-occ',
        default                  => 'b-biz',
    };
}

function getStatusLabel(string $status, bool $isApt): string {
    if ($isApt) {
        return match($status) {
            'available' => 'Available',
            'occupied'  => 'Fully Occupied',
            'inquire'   => 'Inquire First',
            default     => ucfirst($status),
        };
    }
    return match($status) {
        'open'        => 'Open',
        'new'         => 'Newly Opened',
        'temp-closed' => 'Temporarily Closed',
        'for-rent'    => 'Space for Rent',
        default       => ucfirst($status),
    };
}

function getBizCatLabel(string $cat): string {
    return match($cat) {
        'food'     => 'Food & Dining',
        'water'    => 'Water Station',
        'sari-sari'=> 'Sari-Sari Store',
        'salon'    => 'Salon / Barber',
        'laundry'  => 'Laundry Shop',
        'pharmacy' => 'Pharmacy',
        'printing' => 'Printing / Computer Shop',
        'bakery'   => 'Bakery / Café',
        'hardware' => 'Hardware',
        'other'    => 'Other',
        default    => ucfirst($cat),
    };
}

function getAptTypeLabel(string $type): string {
    return match($type) {
        'bed-spacer'  => 'Bed Spacer',
        'studio'      => 'Studio Type',
        'solo-room'   => 'Solo Room',
        '1br'         => '1-Bedroom',
        '2br'         => '2-Bedroom',
        'whole-unit'  => 'Whole Unit',
        default       => ucfirst($type),
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="assets/responsive-global.css">
  <title>Local Directory - <?= e($siteSettings['site_title']) ?></title>
  <link rel="icon" href="<?= e(site_config_logo_url($siteSettings)) ?>" type="image/png">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="/tailwind/input.css">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <?= site_config_css_vars($siteSettings) ?>
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    html { scroll-behavior: smooth; }
    body { font-family: 'DM Sans', sans-serif; background: linear-gradient(135deg, var(--site-primary-pale) 0%, color-mix(in srgb, var(--site-primary-pale) 70%, white) 48%, #eff6ff 100%); color: #134e4a; min-height: 100vh; }

    /* NAV */
    .nav-link { position: relative; transition: color 0.2s ease, transform 0.2s ease; }
    .nav-link::after { content: ''; position: absolute; bottom: -2px; left: 0; width: 0; height: 2px; background: var(--site-primary); transition: width 0.3s ease; }
    .nav-link:hover::after { width: 100%; }
    .nav-link:hover { color: var(--site-primary-dark); transform: translateY(-1px); }

    /* SEARCH */
    .search-wrap { display: flex; align-items: center; background: rgba(255,255,255,0.96); border: 1.5px solid #d1d5db; border-radius: 14px; overflow: visible; box-shadow: 0 8px 20px rgba(15,23,42,0.08); transition: border-color 0.2s, box-shadow 0.2s; position: relative; }
    .search-wrap:focus-within { border-color: var(--site-primary); box-shadow: 0 0 0 3px rgba(var(--site-primary-rgb),0.15); }
    .search-input { flex: 1; border: none; outline: none; padding: 14px 14px 14px 44px; font-size: 0.95rem; background: transparent; min-width: 0; width: 100%; }
    .sdiv { width: 1px; height: 26px; background: #e5e7eb; flex-shrink: 0; }
    .sact { padding: 0 14px; color: #6b7280; background: none; border: none; cursor: pointer; font-size: 0.95rem; flex-shrink: 0; }
    .sact:hover { color: var(--site-primary); }

    /* FILTER BUTTONS */
    .filter-btn { padding: 8px 18px; border-radius: 999px; font-size: 0.82rem; font-weight: 600; border: 1.5px solid #d1d5db; background: #fff; color: #4b5563; cursor: pointer; transition: all 0.2s; white-space: nowrap; }
    .filter-btn:hover { border-color: var(--site-primary); color: var(--site-primary-dark); }
    .filter-btn.active { border-color: var(--site-primary); background: var(--site-primary); color: #fff; box-shadow: 0 5px 16px rgba(var(--site-primary-rgb),0.25); }

    /* CARDS */
    .card { background: #fff; border-radius: 22px; border: 1px solid #e2e8f0; overflow: hidden; display: flex; flex-direction: column; transition: transform 0.25s ease, box-shadow 0.25s ease; }
    .card:hover { transform: translateY(-6px); box-shadow: 0 25px 38px rgba(15,23,42,0.15); }
    .card-img { width: 100%; height: 190px; display: flex; align-items: center; justify-content: center; position: relative; overflow: hidden; flex-shrink: 0; }
    .card-img::before { content: ''; position: absolute; inset: 0; background: linear-gradient(180deg, rgba(255,255,255,0) 0%, rgba(0,0,0,0.22) 100%); z-index: 1; }
    .card-img img { width: 100%; height: 100%; object-fit: cover; position: absolute; inset: 0; }
    .card-img .fallback-icon { position: relative; z-index: 0; }
    .card-body { padding: 16px; flex: 1; display: flex; flex-direction: column; }

    .sbadge { position: absolute; top: 12px; left: 12px; font-size: 0.68rem; font-weight: 700; padding: 5px 11px; border-radius: 999px; letter-spacing: 0.06em; text-transform: uppercase; box-shadow: 0 6px 16px rgba(15,23,42,0.15); z-index: 2; }
    .b-avail { background: var(--site-primary-pale); color: var(--site-primary-darker); }
    .b-occ   { background: #fee2e2; color: #b91c1c; }
    .b-biz   { background: #dbeafe; color: #1d4ed8; }

    .type-chip { position: absolute; top: 12px; right: 12px; width: 32px; height: 32px; border-radius: 10px; background: rgba(255,255,255,0.88); display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 10px rgba(0,0,0,0.10); z-index: 2; }

    .view-btn { display: flex; align-items: center; justify-content: center; gap: 7px; width: 100%; padding: 12px; background: linear-gradient(90deg, var(--site-primary), var(--site-primary-dark)); color: #fff; border-radius: 12px; font-weight: 700; font-size: 0.86rem; transition: background 0.25s ease, transform 0.14s ease; box-shadow: 0 5px 16px rgba(15,23,42,0.2); text-decoration: none; margin-top: auto; }
    .view-btn:hover { background: linear-gradient(90deg, var(--site-primary-dark), var(--site-primary-darker)); transform: translateY(-1px); }
    .view-btn.occ { background: linear-gradient(90deg, #6b7280, #4b5563); }
    .view-btn.occ:hover { background: linear-gradient(90deg, #4b5563, #374151); }

    .tag-pill { display: inline-flex; align-items: center; gap: 0.35rem; margin-top: 6px; font-size: 0.68rem; font-weight: 700; padding: 0.25rem 0.7rem; border-radius: 999px; background: color-mix(in srgb, var(--site-primary) 10%, transparent); color: var(--site-primary-darker); }
    .tag-pill.biz { background: rgba(29,78,216,0.08); color: #1d4ed8; }

    .count-badge { display: inline-flex; align-items: center; background: var(--site-primary-pale); color: var(--site-primary-dark); border: 1px solid color-mix(in srgb, var(--site-primary) 30%, white); font-size: 0.7rem; font-weight: 700; padding: 2px 8px; border-radius: 999px; margin-left: 6px; }

    #emptyState { display: none; }

    /* FILTER DROPDOWN */
    #filterDropdown { position: absolute; right: 0; top: calc(100% + 8px); width: min(288px, calc(100vw - 32px)); background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; box-shadow: 0 12px 32px rgba(15,23,42,0.12); padding: 16px; z-index: 100; }

    /* RESPONSIVE GRID */
    .cards-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(min(100%, 280px), 1fr));
      gap: 20px;
    }

    /* FILTER SCROLL CONTAINER (mobile) */
    .filter-scroll { display: flex; flex-wrap: wrap; gap: 8px; }
    @media (max-width: 640px) {
      .filter-scroll { flex-wrap: nowrap; overflow-x: auto; -webkit-overflow-scrolling: touch; padding-bottom: 4px; scrollbar-width: none; }
      .filter-scroll::-webkit-scrollbar { display: none; }
      .filter-btn { flex-shrink: 0; }
    }

    /* MOBILE SIDEBAR */
    #mobile-sidebar { overflow-y: auto; }

    /* HEADER */
    header { position: sticky; top: 0; z-index: 50; }

    /* FOOTER RESPONSIVE */
    @media (max-width: 768px) {
      .footer-grid { grid-template-columns: 1fr !important; gap: 32px !important; }
    }

    /* MAIN PADDING */
    @media (max-width: 480px) {
      main { padding-left: 16px; padding-right: 16px; padding-top: 32px; }
      .card-img { height: 170px; }
    }

    :root {
      --site-primary-dark:   color-mix(in srgb, var(--site-primary) 55%, black);
      --site-primary-darker: color-mix(in srgb, var(--site-primary) 75%, black);
      --site-primary-light:  color-mix(in srgb, var(--site-primary) 55%, white);
      --site-primary-pale:   color-mix(in srgb, var(--site-primary) 12%, white);
    }

    /* Tailwind-green -> theme color overrides (matches landing.php / adminLanding.php) */
    .text-green-400 { color: var(--site-primary-light) !important; }
    .text-green-200 { color: color-mix(in srgb, var(--site-primary-light) 60%, white) !important; }
    .text-green-100 { color: color-mix(in srgb, var(--site-primary-light) 40%, white) !important; }
    .bg-green-500 { background-color: var(--site-primary) !important; }
    .hover\:bg-green-400:hover { background-color: var(--site-primary-light) !important; }
    .bg-green-800\/80 { background-color: color-mix(in srgb, var(--site-primary-dark) 80%, transparent) !important; }
    .border-green-400\/30 { border-color: color-mix(in srgb, var(--site-primary-light) 30%, transparent) !important; }
    .border-green-400\/50 { border-color: color-mix(in srgb, var(--site-primary-light) 50%, transparent) !important; }
    .bg-green-700 { background-color: var(--site-primary) !important; }
    .hover\:bg-green-800:hover { background-color: var(--site-primary-dark) !important; }
    .text-green-700, .text-green-600, .text-green-500 { color: var(--site-primary) !important; }
    .text-green-900, .text-green-950 { color: var(--site-primary-darker) !important; }
    .bg-green-950 { background-color: var(--site-primary-darker) !important; }
    .border-green-100 { border-color: color-mix(in srgb, var(--site-primary) 20%, white) !important; }
    .hover\:border-green-300:hover { border-color: color-mix(in srgb, var(--site-primary-light) 60%, white) !important; }
    .hover\:bg-green-50:hover { background-color: var(--site-primary-pale) !important; }
    .hover\:text-green-700:hover { color: var(--site-primary) !important; }
    .hover\:text-green-800:hover { color: var(--site-primary-darker) !important; }
    .focus\:ring-green-400:focus { --tw-ring-color: var(--site-primary-light) !important; }
    .bg-green-50 { background-color: var(--site-primary-pale) !important; }
    .hover\:bg-green-100:hover { background-color: color-mix(in srgb, var(--site-primary) 18%, white) !important; }
    .accent-green-600 { accent-color: var(--site-primary) !important; }

    /* Footer text: always light/white regardless of theme hue (avoids low-contrast on dark bg) */
    footer .text-green-300 { color: rgba(255,255,255,0.75) !important; }
    footer .text-green-400 { color: rgba(255,255,255,0.65) !important; }
    footer .text-green-500 { color: rgba(255,255,255,0.45) !important; }
    footer .hover\:text-white:hover { color: #ffffff !important; }
    footer .border-green-800 { border-color: rgba(255,255,255,0.12) !important; }
  </style>
    <link rel="stylesheet" href="dist/output.css">
    <script src="https://cdn.tailwindcss.com/3.4.16"></script>
</head>
<body>

<!-- ================================ HEADER ================================ -->
<header class="w-full h-[68px] border-b border-green-100 flex items-center px-4 sm:px-6 lg:px-8 bg-white shadow-sm">
  <div class="flex items-center gap-3 flex-shrink-0">
    <a href="<?= $logged_in ? 'resident/residentLanding.php' : 'landing.php' ?>" class="flex items-center gap-3">
      <div class="w-9 h-9 rounded-full bg-green-700 flex items-center justify-center shadow overflow-hidden flex-shrink-0">
        <img src="<?= e(site_config_logo_url($siteSettings)) ?>" alt="Logo" class="w-full h-full object-contain" />
      </div>
      <div class="sm:block">
        <h3 class="font-bold text-sm leading-tight" style="font-family:'DM Sans',sans-serif;color:var(--site-primary-dark)"><?= e($siteSettings['site_title']) ?></h3>
        <p class="text-[9px] text-green-600 tracking-widest uppercase"><?= e($siteSettings['barangay_name']) ?></p>
      </div>
    </a>
  </div>

  <nav class="ml-auto flex items-center gap-3 md:gap-6 text-gray-600 text-sm font-medium">

    <!-- Desktop Nav -->
    <div class="hidden md:flex items-center gap-5 lg:gap-7">

      <?php if ($showMyPanel): ?>
        <a href="resident/residentPanel.php" class="nav-link">My Panel</a>
      <?php endif; ?>

      <?php if ($isAdminLike): ?>
        <a href="admin/adminDashboard.php" class="nav-link">Dashboard</a>
      <?php endif; ?>

      <a href="landing.php#announcements" class="nav-link">Announcements</a>
      <a href="busaptListing.php?type=business" class="nav-link">Business</a>
      <a href="busaptListing.php?type=apartment" class="nav-link">Apartment</a>
      <?php $roleLower = strtolower($role); ?>
      <?php if (str_contains($roleLower, 'non-resident,business/apartment owner') || str_contains($roleLower, 'business') && !str_contains($roleLower, 'resident')): ?>
        <a href="nonResident/manageList.php" class="nav-link">
          <i class="w-4 text-green-600"></i> Post Listing
        </a>
      <?php endif; ?>

      <?php if (!$logged_in): ?>
        <a href="login.php" class="px-4 py-2 bg-green-700 hover:bg-green-800 text-white rounded-lg transition text-sm font-semibold shadow">
          Login / Register
        </a>
      <?php else: ?>
        <div class="relative" id="profile-menu-wrapper">
          <button id="profile-btn" onclick="toggleProfileMenu()"
            class="flex items-center gap-2 px-3 py-1.5 rounded-xl border border-gray-200 hover:border-green-300 hover:bg-green-50 transition focus:outline-none focus:ring-2 focus:ring-green-400"
            aria-haspopup="true" aria-expanded="false">
            <span class="w-8 h-8 rounded-full bg-green-700 text-white flex items-center justify-center text-xs font-bold select-none">
              <?= htmlspecialchars($initials) ?>
            </span>
            <span class="hidden lg:block text-gray-700 text-sm max-w-[120px] truncate">
              <?= htmlspecialchars($userName) ?>
            </span>
            <svg id="profile-chevron" class="w-4 h-4 text-gray-400 transition-transform duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
            </svg>
          </button>

          <div id="profile-dropdown"
            class="hidden absolute right-0 mt-2 w-64 bg-white rounded-2xl shadow-xl border border-gray-100 overflow-hidden z-50"
            role="menu">
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
              <?php if ($profileUrl !== null): ?>
              <a href="<?= e($profileUrl) ?>" role="menuitem" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-[var(--site-primary-pale)] hover:text-[var(--site-primary-darker)] transition">
                <i class="fa-solid <?= e($profileIcon) ?> w-4 text-gray-400"></i> <?= e($profileLabel) ?>
              </a>
              <?php endif; ?>
              <?php if ($isAdminLike): ?>
              <a href="admin/adminDashboard.php" role="menuitem" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-purple-50 hover:text-purple-800 transition">
                <i class="fa-solid fa-shield-halved w-4 text-gray-400"></i> Admin Panel
              </a>
              <?php endif; ?>
            </div>
            <div class="border-t border-gray-100 py-1">
              <a href="logout.php" role="menuitem" class="flex items-center gap-3 px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition">
                <i class="fa-solid fa-arrow-right-from-bracket w-4"></i> Logout
              </a>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <!-- Mobile hamburger -->
    <button id="mobile-menu-btn"
      class="md:hidden flex items-center justify-center p-2 text-gray-600 hover:text-green-700 hover:bg-green-50 rounded-lg transition"
      aria-label="Toggle menu">
      <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
      </svg>
    </button>
  </nav>
</header>

<!-- ================================ MOBILE SIDEBAR ================================ -->
<div id="mobile-sidebar-overlay" class="fixed inset-0 bg-black/50 z-[60] hidden opacity-0 transition-opacity duration-300"></div>
<div id="mobile-sidebar" class="fixed inset-y-0 right-0 w-72 max-w-[85vw] bg-white shadow-2xl transform translate-x-full transition-transform duration-300 z-[70] flex flex-col">
  <div class="p-4 border-b border-gray-100 flex items-center justify-between">
    <div class="flex items-center gap-2">
      <?php if ($logged_in): ?>
        <span class="w-8 h-8 rounded-full bg-green-700 text-white flex items-center justify-center text-xs font-bold"><?= htmlspecialchars($initials) ?></span>
        <div>
          <p class="text-sm font-semibold text-gray-800 truncate max-w-[140px]"><?= htmlspecialchars($userName) ?></p>
          <span class="text-[10px] font-semibold <?= $roleBadgeClass ?> px-1.5 py-0.5 rounded-full"><?= htmlspecialchars($roleLabel) ?></span>
        </div>
      <?php else: ?>
        <h3 class="font-bold text-green-900">Menu</h3>
      <?php endif; ?>
    </div>
    <button id="mobile-menu-close" class="p-2 text-gray-500 hover:text-red-500 rounded-full hover:bg-red-50 transition">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
      </svg>
    </button>
  </div>
  <div class="flex-1 overflow-y-auto py-3 px-3 space-y-0.5">

    <?php if ($showMyPanel): ?>
      <a href="resident/residentPanel.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-[var(--site-primary-dark)] font-medium hover:bg-[var(--site-primary-pale)] active:scale-[0.97] active:bg-[var(--site-primary-pale)] transition-all duration-150">
        <i class="fa-solid fa-gauge-high w-4 text-[var(--site-primary)]"></i> My Panel
      </a>
    <?php endif; ?>

    <?php if ($isAdminLike): ?>
      <a href="admin/adminDashboard.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-[var(--site-primary-dark)] font-medium hover:bg-[var(--site-primary-pale)] active:scale-[0.97] active:bg-[var(--site-primary-pale)] transition-all duration-150">
        <i class="fa-solid fa-shield-halved w-4 text-[var(--site-primary)]"></i> Dashboard
      </a>
    <?php endif; ?>

    <?php
      if ($showMyPanel) {
          $annUrl = 'resident/residentLanding.php#announcements';
      } elseif ($logged_in) {
          $annUrl = 'nonResident/nonresidentLanding.php#announcements';
      } else {
          $annUrl = 'landing.php#announcements';
      }
    ?>
    <a href="<?= $annUrl ?>" class="flex items-center gap-3 px-4 py-3 rounded-xl text-[var(--site-primary-dark)] font-medium hover:bg-[var(--site-primary-pale)] active:scale-[0.97] active:bg-[var(--site-primary-pale)] transition-all duration-150">
      <i class="fa-solid fa-bullhorn w-4 text-[var(--site-primary)]"></i> Announcements
    </a>
    <a href="busaptListing.php?type=business" class="flex items-center gap-3 px-4 py-3 rounded-xl font-medium active:scale-[0.97] transition-all duration-150 <?= $currentType === 'business' ? 'text-[var(--site-primary-dark)] font-bold bg-[var(--site-primary-pale)]' : 'text-[var(--site-primary-dark)] hover:bg-[var(--site-primary-pale)] active:bg-[var(--site-primary-pale)]' ?>">
      <i class="fa-solid fa-store w-4 text-[var(--site-primary)]"></i> Business
    </a>
    <a href="busaptListing.php?type=apartment" class="flex items-center gap-3 px-4 py-3 rounded-xl font-medium active:scale-[0.97] transition-all duration-150 <?= $currentType === 'apartment' ? 'text-[var(--site-primary-dark)] font-bold bg-[var(--site-primary-pale)]' : 'text-[var(--site-primary-dark)] hover:bg-[var(--site-primary-pale)] active:bg-[var(--site-primary-pale)]' ?>">
      <i class="fa-solid fa-building w-4 text-[var(--site-primary)]"></i> Apartment
    </a>
    <a href="busaptListing.php" class="flex items-center gap-3 px-4 py-3 rounded-xl font-medium active:scale-[0.97] transition-all duration-150 <?= ($currentType !== 'business' && $currentType !== 'apartment') ? 'text-[var(--site-primary-dark)] font-bold bg-[var(--site-primary-pale)]' : 'text-[var(--site-primary-dark)] hover:bg-[var(--site-primary-pale)] active:bg-[var(--site-primary-pale)]' ?>">
      <i class="fa-solid fa-list w-4 text-[var(--site-primary)]"></i> Directory
    </a>

    <?php if ($logged_in): ?>
    <div class="pt-2 border-t border-gray-100 mt-2 space-y-0.5">
      <?php if ($profileUrl !== null): ?>
      <a href="<?= e($profileUrl) ?>" class="flex items-center gap-3 px-4 py-3 rounded-xl text-[var(--site-primary-dark)] font-medium hover:bg-[var(--site-primary-pale)] active:scale-[0.97] active:bg-[var(--site-primary-pale)] transition-all duration-150">
        <i class="fa-solid <?= e($profileIcon) ?> w-4 text-[var(--site-primary)]"></i> <?= e($profileLabel) ?>
      </a>
      <?php endif; ?>
      <a href="logout.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-red-600 font-medium hover:bg-red-50 active:scale-[0.97] active:bg-red-50 transition-all duration-150">
        <i class="fa-solid fa-arrow-right-from-bracket w-4"></i> Logout
      </a>
    </div>
    <?php else: ?>
    <div class="pt-3 px-1">
      <a href="login.php" class="block text-center px-5 py-3 bg-green-700 hover:bg-green-800 active:scale-[0.97] text-white rounded-xl font-semibold shadow transition-all duration-150">Login / Register</a>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ================================ MAIN ================================ -->
<main class="max-w-6xl mx-auto px-4 sm:px-6 py-10 sm:py-14">

  <!-- Back -->
  <div class="mb-6">
    <?php if ($logged_in): ?>
      <a href="resident/residentLanding.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-green-700 text-white text-sm font-semibold hover:bg-green-800 transition">
        <i class="fa-solid fa-arrow-left text-xs"></i> Back to Landing
      </a>
    <?php else: ?>
      <a href="landing.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-green-700 text-white text-sm font-semibold hover:bg-green-800 transition">
        <i class="fa-solid fa-arrow-left text-xs"></i> Back to Landing
      </a>
    <?php endif; ?>
  </div>

  <!-- Hero -->
  <div class="text-center mb-8 sm:mb-10">
    <p class="text-xs font-semibold text-green-600 uppercase tracking-widest mb-2"><?=  $siteSettings['barangay_name'] ?> Community</p>
    <h1 class="text-3xl sm:text-4xl font-bold text-green-950" style="font-family:'Playfair Display',serif;">Local Directory</h1>
    <p class="text-gray-400 text-sm mt-2">Discover businesses and apartments in our barangay</p>
    <p class="text-green-700 font-semibold text-sm mt-1">
      <?= count($listings) ?> listing<?= count($listings) !== 1 ? 's' : '' ?> found
    </p>
  </div>

  <!-- Search -->
  <div class="search-wrap mb-6 max-w-3xl mx-auto">
    <div class="relative flex-1 min-w-0">
      <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none"></i>
      <input type="text" id="searchInput"
        placeholder="Search by name, category, address."
        class="search-input"
        oninput="filterCards()">
    </div>
    <div class="sdiv"></div>
    <button class="sact" onclick="document.getElementById('searchInput').value='';filterCards();" title="Clear">
      <i class="fa-solid fa-xmark"></i>
    </button>
    <button class="sact" onclick="toggleFilterDropdown()" title="Filters">
      <i class="fa-solid fa-sliders"></i>
    </button>

    <!-- Filter Dropdown -->
    <div id="filterDropdown" class="hidden">
      <h4 class="text-sm font-semibold text-gray-700 mb-3">Filter by Category</h4>

      <p class="text-xs font-bold text-green-700 mb-1 uppercase tracking-wider">Apartment Type</p>
      <div class="space-y-1 mb-3 text-sm">
        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" value="bed-spacer"  class="filter-category accent-green-600"> Bed Spacer</label>
        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" value="studio"      class="filter-category accent-green-600"> Studio Type</label>
        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" value="solo-room"   class="filter-category accent-green-600"> Solo Room</label>
        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" value="1br"         class="filter-category accent-green-600"> 1-Bedroom</label>
        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" value="2br"         class="filter-category accent-green-600"> 2-Bedroom</label>
        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" value="whole-unit"  class="filter-category accent-green-600"> Whole Unit</label>
      </div>

      <p class="text-xs font-bold text-blue-700 mb-1 uppercase tracking-wider">Business Category</p>
      <div class="space-y-1 mb-3 text-sm">
        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" value="food"        class="filter-category accent-blue-600"> Food &amp; Dining</label>
        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" value="water"       class="filter-category accent-blue-600"> Water Station</label>
        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" value="sari-sari"   class="filter-category accent-blue-600"> Sari-Sari Store</label>
        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" value="salon"       class="filter-category accent-blue-600"> Salon / Barber</label>
        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" value="laundry"     class="filter-category accent-blue-600"> Laundry Shop</label>
        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" value="pharmacy"    class="filter-category accent-blue-600"> Pharmacy</label>
        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" value="printing"    class="filter-category accent-blue-600"> Printing / Computer Shop</label>
        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" value="bakery"      class="filter-category accent-blue-600"> Bakery / Café</label>
        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" value="hardware"    class="filter-category accent-blue-600"> Hardware</label>
      </div>

      <div class="flex gap-2">
        <button onclick="clearDropdownFilter()" class="flex-1 border border-gray-300 text-gray-600 text-sm py-2 rounded-lg hover:bg-gray-50 transition">Clear</button>
        <button onclick="applyDropdownFilter()" class="flex-1 bg-green-700 hover:bg-green-800 text-white text-sm py-2 rounded-lg transition">Apply</button>
      </div>
    </div>
  </div>

  <!-- Filter Buttons (horizontally scrollable on mobile) -->
  <div class="mb-7 overflow-hidden">
    <div class="filter-scroll">
      <button class="filter-btn active" onclick="setFilter(this,'all')">
        All <span class="count-badge" id="cnt-all"><?= count($listings) ?></span>
      </button>
      <button class="filter-btn" onclick="setFilter(this,'apartment')">Apartments</button>
      <button class="filter-btn" onclick="setFilter(this,'business')">Businesses</button>
      <button class="filter-btn" onclick="setFilter(this,'available')">Available / Open</button>
      <button class="filter-btn" onclick="setFilter(this,'new')">New Listings</button>
    </div>
  </div>

  <!-- Cards Grid -->
  <div class="cards-grid" id="cardsGrid">

    <?php foreach ($listings as $l): ?>
    <?php
      $isApt    = $l['is_apartment'];
      $status   = $l['display_status'];
      $category = $l['display_category'];
      $badgeCls = getStatusBadgeClass($status);
      $statusLbl= getStatusLabel($status, $isApt);
      $typeLabel= $isApt ? getAptTypeLabel($category) : getBizCatLabel($category);
      $icon     = getTypeIcon($category, $isApt);
      $gradient = getCardGradient($isApt, $status);
      $isOcc    = in_array($status, ['occupied', 'temp-closed']);
      $isNew    = ($l['days_ago'] <= 14);
      $photo    = $l['first_photo'];
      $addr     = $isApt ? ($l['aptAddress'] ?? '') : ($l['bussAddress'] ?? '');
      $detailParams = http_build_query(['id' => $l['id']]);
    ?>
    <div class="card"
         data-id="<?= (int)$l['id'] ?>"
         data-status="<?= htmlspecialchars($isOcc ? 'occupied' : ($status === 'available' || $status === 'open' || $status === 'new' ? 'available' : 'other')) ?>"
         data-type="<?= htmlspecialchars($isApt ? 'apartment' : 'business') ?>"
         data-category="<?= htmlspecialchars($category) ?>"
         data-name="<?= htmlspecialchars(strtolower($l['display_name'])) ?>"
         data-price="<?= htmlspecialchars(strtolower($l['display_price'])) ?>"
         data-address="<?= htmlspecialchars(strtolower($addr)) ?>"
         data-new="<?= $isNew ? 'true' : 'false' ?>">

      <div class="card-img" style="background:<?= $gradient ?>">
        <?php if ($photo): ?>
          <img src="<?= htmlspecialchars($photo) ?>" alt="<?= htmlspecialchars($l['display_name']) ?>" onerror="this.style.display='none'">
        <?php endif; ?>
        <i class="fa-solid <?= $icon ?> text-5xl opacity-20 fallback-icon <?= $isApt ? 'text-green-600' : 'text-blue-500' ?>"></i>
        <span class="sbadge <?= $badgeCls ?>"><?= $statusLbl ?></span>
        <div class="type-chip">
          <i class="fa-solid <?= $icon ?> <?= $isApt ? 'text-green-700' : 'text-blue-600' ?> text-xs"></i>
        </div>
      </div>

      <div class="card-body">
        <?php if ($isNew): ?>
          <span style="font-size:0.65rem;font-weight:800;color:var(--site-primary);text-transform:uppercase;letter-spacing:0.07em;margin-bottom:4px;display:block;">✨ New Listing</span>
        <?php endif; ?>
        <h3 class="font-bold text-gray-800 text-base mb-0.5 leading-snug"><?= htmlspecialchars($l['display_name']) ?></h3>
        <p class="<?= $isApt ? 'text-green-700' : 'text-blue-600' ?> font-semibold text-sm mb-0.5"><?= htmlspecialchars($l['display_price']) ?></p>

        <span class="tag-pill <?= $isApt ? '' : 'biz' ?>">
          <i class="fa-solid <?= $icon ?> text-[9px]"></i>
          <?= htmlspecialchars($typeLabel) ?>
          <?php if ($isApt && !empty($l['slotsAvailable']) && $l['slotsAvailable'] > 0): ?>
           <?= (int)$l['slotsAvailable'] ?> slot<?= $l['slotsAvailable'] > 1 ? 's' : '' ?> open
          <?php endif; ?>
        </span>

        <p class="text-gray-400 text-xs mt-2 mb-4 flex items-start gap-1">
          <i class="fa-solid fa-location-dot text-green-500 text-[10px] mt-0.5 flex-shrink-0"></i>
          <span class="line-clamp-2"><?= htmlspecialchars($addr ?: 'Sumacab Este, Cabanatuan') ?></span>
        </p>

        <a href="businessdetails.php?<?= $detailParams ?>" class="view-btn <?= $isOcc ? 'occ' : '' ?>">
          <i class="fa-solid fa-eye text-xs"></i> See Details
        </a>
      </div>
    </div>
    <?php endforeach; ?>

    <?php if (empty($listings)): ?>
    <div style="grid-column:1/-1;" class="text-center py-20 text-gray-400">
      <i class="fa-solid fa-folder-open text-5xl mb-4 opacity-25 block"></i>
      <p class="font-semibold text-lg">No listings yet</p>
      <p class="text-sm mt-1">Check back soon - listings from the community will appear here.</p>
    </div>
    <?php endif; ?>
  </div>

  <div id="emptyState" class="text-center py-24 text-gray-400">
    <i class="fa-solid fa-folder-open text-5xl mb-4 opacity-25 block"></i>
    <p class="font-semibold">No listings found</p>
    <p class="text-sm mt-1">Try adjusting your search or filter</p>
  </div>

</main>

<!-- ================================ FOOTER ================================ -->
<footer class="bg-green-950 text-white pt-14 pb-6 px-4 sm:px-6">
  <div class="max-w-6xl mx-auto">
    <div class="footer-grid grid grid-cols-1 md:grid-cols-3 gap-10 pb-10 border-b border-green-800">
      <div>
        <div class="flex items-center gap-3 mb-4">
          <div class="w-12 h-12 rounded-full bg-green-700 flex items-center justify-center overflow-hidden flex-shrink-0">
            <img src="<?= e(site_config_logo_url($siteSettings)) ?>" alt="Logo" class="w-full h-full object-contain" />
          </div>
          <div>
            <h3 class="text-lg font-bold"><?= e($siteSettings['site_title']) ?></h3>
            <p class="text-green-400 text-xs tracking-widest uppercase"><?= e($siteSettings['barangay_name']) ?></p>
          </div>
        </div>
        <div class="space-y-2 text-sm text-green-300">
          <p><i class="fa-solid fa-location-dot mr-2 text-green-500"></i><?= e($siteSettings['barangay_name']) ?>, <?= e($siteSettings['municipality']) ?></p>
          <p><i class="fa-solid fa-envelope mr-2 text-green-500"></i><?= e($siteSettings['email']) ?></p>
          <p><i class="fa-solid fa-phone mr-2 text-green-500"></i><?= e($siteSettings['contact_number']) ?></p>
        </div>
        <a href="<?= e($siteSettings['facebook_link'] ?: '#') ?>" target="_blank" rel="noopener noreferrer" class="mt-4 inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded-lg text-sm transition">
          <i class="fab fa-facebook"></i> Facebook Page
        </a>
      </div>
      <div>
        <h4 class="font-semibold text-green-300 text-xs uppercase tracking-widest mb-4">Legal</h4>
        <div class="space-y-2">
          <a href="infoSecurity/dataProtection.php" class="block text-sm text-green-400 hover:text-white transition">Privacy Policy</a>
          <a href="infoSecurity/terms.php" class="block text-sm text-green-400 hover:text-white transition">Terms of Service</a>
          <a href="infoSecurity/dataProtection.php" class="block text-sm text-green-400 hover:text-white transition">Data Protection Notice</a>
        </div>
      </div>
      <div>
        <h4 class="font-semibold text-green-300 text-xs uppercase tracking-widest mb-4">Quick Links</h4>
        <div class="space-y-2">
          <?php if ($showMyPanel): ?>
            <a href="resident/residentPanel.php" class="block text-sm text-green-400 hover:text-white transition">Services</a>
          <?php elseif ($isAdminLike): ?>
            <a href="admin/adminDashboard.php" class="block text-sm text-green-400 hover:text-white transition">Dashboard</a>
          <?php else: ?>
            <a href="busaptListing.php" class="block text-sm text-green-400 hover:text-white transition">Directory</a>
          <?php endif; ?>
          <a href="busaptListing.php?type=apartment" class="block text-sm text-green-400 hover:text-white transition">Apartments</a>
          <a href="busaptListing.php?type=business" class="block text-sm text-green-400 hover:text-white transition">Business Directory</a>
        </div>
      </div>
    </div>
    <div class="text-center mt-6 text-green-500 text-sm">
&copy; 2026 SumEste Portal. All Rights Reserved. Made for <?= e($siteSettings['barangay_name']) ?>.      </div>
    </div>
  </div>
</footer>

<script>
  /* State */
  let activeFilter = 'all';
  let activeCats   = [];

  /* Handle ?type= on load */
  (function () {
    const t = new URLSearchParams(window.location.search).get('type');
    if (t === 'apartment' || t === 'business') {
      activeFilter = t;
      document.querySelectorAll('.filter-btn').forEach(b => {
        b.classList.toggle('active', b.textContent.toLowerCase().includes(t));
      });
    }
  })();

  function setFilter(btn, filter) {
    activeFilter = filter;
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    filterCards();
  }

  function toggleFilterDropdown() {
    document.getElementById('filterDropdown').classList.toggle('hidden');
  }

  function applyDropdownFilter() {
    activeCats = Array.from(document.querySelectorAll('.filter-category:checked')).map(el => el.value);
    document.getElementById('filterDropdown').classList.add('hidden');
    filterCards();
  }

  function clearDropdownFilter() {
    document.querySelectorAll('.filter-category').forEach(c => c.checked = false);
    activeCats = [];
    filterCards();
  }

  function filterCards() {
    const q = (document.getElementById('searchInput').value || '').toLowerCase().trim();
    const cards = document.querySelectorAll('#cardsGrid .card');
    let visible = 0;

    cards.forEach(card => {
      const status   = (card.dataset.status   || '').toLowerCase();
      const type     = (card.dataset.type     || '').toLowerCase();
      const category = (card.dataset.category || '').toLowerCase();
      const name     = (card.dataset.name     || '').toLowerCase();
      const price    = (card.dataset.price    || '').toLowerCase();
      const address  = (card.dataset.address  || '').toLowerCase();
      const isNew    = card.dataset.new === 'true';

      let activeMatch = true;
      switch (activeFilter) {
        case 'apartment': activeMatch = type === 'apartment'; break;
        case 'business':  activeMatch = type === 'business';  break;
        case 'available': activeMatch = status === 'available'; break;
        case 'new':       activeMatch = isNew; break;
        default:          activeMatch = true;
      }

      const catMatch    = activeCats.length === 0 || activeCats.includes(category);
      const searchMatch = !q || name.includes(q) || price.includes(q) || address.includes(q) || category.includes(q);

      const show = activeMatch && catMatch && searchMatch;
      card.style.display = show ? '' : 'none';
      if (show) visible++;
    });

    document.getElementById('emptyState').style.display = visible === 0 ? 'block' : 'none';
  }

  /* Profile dropdown */
  function toggleProfileMenu() {
    const dd = document.getElementById('profile-dropdown');
    if (dd) dd.classList.toggle('hidden');
  }

  /* Close on outside click */
  document.addEventListener('click', function (e) {
    const fd = document.getElementById('filterDropdown');
    const pd = document.getElementById('profile-dropdown');
    const pb = document.getElementById('profile-btn');
    const sw = document.querySelector('.search-wrap');

    if (fd && !fd.classList.contains('hidden') && !fd.contains(e.target) && !(sw && sw.contains(e.target))) {
      fd.classList.add('hidden');
    }
    if (pd && pb && !pd.contains(e.target) && !pb.contains(e.target)) {
      pd.classList.add('hidden');
    }
  });

  /* Mobile sidebar */
  const overlay    = document.getElementById('mobile-sidebar-overlay');
  const sidebar    = document.getElementById('mobile-sidebar');
  const openBtn    = document.getElementById('mobile-menu-btn');
  const closeBtn   = document.getElementById('mobile-menu-close');

  function openSidebar() {
    overlay.classList.remove('hidden', 'opacity-0');
    overlay.classList.add('opacity-80');
    sidebar.classList.remove('translate-x-full');
    document.body.style.overflow = 'hidden';
  }
  function closeSidebar() {
    overlay.classList.add('opacity-0');
    sidebar.classList.add('translate-x-full');
    document.body.style.overflow = '';
    setTimeout(() => overlay.classList.add('hidden'), 250);
  }

  openBtn?.addEventListener('click', openSidebar);
  closeBtn?.addEventListener('click', closeSidebar);
  overlay?.addEventListener('click', closeSidebar);

  /* Initial filter */
  document.addEventListener('DOMContentLoaded', filterCards);
</script>
</body>
</html>
