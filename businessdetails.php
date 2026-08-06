<?php
session_start();
$logged_in = isset($_SESSION['user_id']);
$userName  = $_SESSION['user_name'] ?? $_SESSION['user_id'] ?? 'User';
$role      = $_SESSION['account_role'] ?? '';
$initials  = strtoupper(substr($userName, 0, 2));

include_once 'config/db_connection.php';

require_once __DIR__ . '/includes/site_config.php';
$siteSettings = site_config_load($conn);

$l = null;
$listingId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($listingId > 0) {
    $stmt = $conn->prepare("SELECT * FROM tbl_busaptlisting WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $listingId);
        $stmt->execute();
        $l = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

if (!$l) {
    header('Location: busaptListing.php');
    exit;
}

function esc(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function parseArr($v): array {
    if (!$v) return [];
    if (is_array($v)) return $v;
    $d = json_decode($v, true);
    return is_array($d) ? $d : [];
}
function labelMap(string $key, array $map): string {
    return $map[$key] ?? ucfirst(str_replace(['-','_'], ' ', $key));
}

$APT_TYPE  = ['bed-spacer'=>'Bed Spacer','studio'=>'Studio Type','solo-room'=>'Solo Room','1br'=>'1-Bedroom','2br'=>'2-Bedroom','whole-unit'=>'Whole Unit'];
$APT_STAT  = ['available'=>'Available','occupied'=>'Fully Occupied','inquire'=>'Inquire First'];
$BIZ_STAT  = ['open'=>'Open / Operating','new'=>'Newly Opened','temp-closed'=>'Temporarily Closed','for-rent'=>'Space for Rent'];
$BIZ_CAT   = ['food'=>'Food & Dining','water'=>'Water Station','sari-sari'=>'Sari-Sari Store','salon'=>'Salon / Barber','laundry'=>'Laundry Shop','pharmacy'=>'Pharmacy','printing'=>'Printing / Computer Shop','bakery'=>'Bakery / Caf�','hardware'=>'Hardware','other'=>'Other'];
$INC_LBL   = ['electric'=>'Electricity','water'=>'Water','wifi'=>'WiFi','cable'=>'Cable TV'];
$AMN_LBL   = ['aircon'=>'Aircon','fan'=>'Electric Fan','parking'=>'Parking','laundry'=>'Laundry Area','cctv'=>'CCTV','security'=>'Security','kitchen'=>'Shared Kitchen','gate'=>'Gated Compound'];
$RULES_LBL = ['no-smoking'=>'No Smoking','no-pets'=>'No Pets','no-visitors'=>'No Overnight Visitors','curfew'=>'Curfew Policy','no-cooking'=>'No Cooking Inside'];
$FEAT_LBL  = ['delivery'=>'Delivery','pickup'=>'Pick-up','dine-in'=>'Dine-in','parking'=>'Parking','gcash'=>'GCash','maya'=>'Maya','wifi'=>'Free WiFi','aircon'=>'Aircon'];
$DAYS_LBL  = ['mon'=>'Monday','tue'=>'Tuesday','wed'=>'Wednesday','thu'=>'Thursday','fri'=>'Friday','sat'=>'Saturday','sun'=>'Sunday','holiday'=>'Holidays'];
$YEARS_LBL = ['new'=>'Just opened','1'=>'1 year','2-5'=>'2-5 years','5-10'=>'5-10 years','10+'=>'10+ years'];

if ($l) {
    $isApt   = ($l['listingType'] === 'apt' || $l['listingType'] === 'apartment');
    $name    = $isApt ? ($l['aptTitle'] ?: 'Apartment Listing') : ($l['bussName'] ?: 'Business Listing');
    $type    = $isApt ? 'apartment' : 'business';
    $status  = $isApt ? ($l['aptStatus'] ?? 'available') : ($l['bussStatus'] ?? 'open');
    $price   = $isApt
        ? ($l['aptPrice'] ? '?' . number_format((float)$l['aptPrice'], 0) . ' / month' : 'Price on inquiry')
        : ($l['bussPrice'] ? '?' . $l['bussPrice'] : 'See details');
    $location  = $isApt ? ($l['aptAddress'] ?? '') : ($l['bussAddress'] ?? '');
    $contact   = $l['contact'] ?? '0999-999-9999';
    $email     = $l['email']   ?? '';
    $mapsLink  = $isApt ? ($l['aptMapsLink'] ?? '') : ($l['bussMapsLink'] ?? '');
    $category  = $isApt ? labelMap($l['aptType'] ?? '', $APT_TYPE) : labelMap($l['bussCat'] ?? '', $BIZ_CAT);
    $statusLbl = $isApt ? labelMap($status, $APT_STAT) : labelMap($status, $BIZ_STAT);
    $photos    = array_map(fn($p) => 'uploads/listings/' . $p, parseArr($l['photos']));
    $aptType     = $l['aptType']     ?? '';
    $aptFloor    = $l['aptFloor']    ?? '';
    $aptRooms    = $l['aptRooms']    ?? '';
    $aptOccupants= $l['aptOccupants']?? '';
    $aptBath     = $l['aptBath']     ?? '';
    $aptSlots    = $l['slotsAvailable'] ?? '';
    $aptDesc     = $l['aptDesc']     ?? '';
    $aptInc      = parseArr($l['aptIncluded']);
    $aptAmn      = parseArr($l['aptAmenities']);
    $aptRules    = parseArr($l['aptRules']);
    $bussCat     = $l['bussCat']      ?? '';
    $bussYears   = $l['bussYears']    ?? '';
    $bussOpen    = $l['bussOpen']     ?? '';
    $bussClose   = $l['bussClose']    ?? '';
    $bussFeatures= parseArr($l['bussFeatures']);
    $bussDays    = parseArr($l['bussDays']);
    $bussDesc    = $l['bussDesc']     ?? '';
    $houseNum  = $l['houseNum'] ?? '';
    $street    = $l['street']   ?? '';
    $barangay  = $l['barangay'] ?? 'Sumacab Este';
    $city      = $l['city']     ?? 'Cabanatuan City';
    $fullAddr  = trim("$houseNum $street, $barangay, $city");
    if (!$location) $location = $fullAddr;
    $datePosted = !empty($l['createdAt']) ? date('F j, Y', strtotime($l['createdAt'])) : '-';
} else {
    $name      = isset($_GET['name'])     ? htmlspecialchars(urldecode($_GET['name']))     : 'Unknown Listing';
    $type      = isset($_GET['type'])     ? htmlspecialchars(urldecode($_GET['type']))     : 'business';
    $status    = isset($_GET['status'])   ? htmlspecialchars(urldecode($_GET['status']))   : 'available';
    $price     = isset($_GET['price'])    ? htmlspecialchars(urldecode($_GET['price']))    : 'N/A';
    $location  = isset($_GET['location']) ? htmlspecialchars(urldecode($_GET['location'])) : 'Sumacab Este, Cabanatuan';
    $contact   = isset($_GET['contact'])  ? htmlspecialchars(urldecode($_GET['contact']))  : '0999-999-9999';
    $email     = isset($_GET['email'])    ? htmlspecialchars(urldecode($_GET['email']))    : '';
    $category  = isset($_GET['category']) ? htmlspecialchars(urldecode($_GET['category'])) : 'General';
    $mapsLink  = isset($_GET['maps'])     ? htmlspecialchars(urldecode($_GET['maps']))     : '';
    $isApt     = ($type === 'apartment');
    $statusLbl = $status === 'available' ? 'Available' : ($status === 'occupied' ? 'Fully Occupied' : 'Inquire');
    $photos    = [];
    $datePosted = '-';
}

// ?? Role display ??
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
// respective profile pages. (Previously this was hardcoded to profile.php
// for everyone, and settings.php for admin - neither page exists.)
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

function fmt12($t) {
    if (!$t) return null;
    [$h, $m] = explode(':', $t) + [0, '00'];
    $h = (int)$h; $ampm = $h >= 12 ? 'PM' : 'AM'; $hr = $h % 12 ?: 12;
    return "$hr:$m $ampm";
}
function tagBadges(array $vals, array $map): string {
    if (empty($vals)) return '<span class="text-gray-400 text-sm italic">None specified</span>';
    return implode('', array_map(fn($v) => '<span class="tag-badge">' . esc($map[$v] ?? ucfirst($v)) . '</span>', $vals));
}

$statusColor = match($status) {
    'available','open','new' => 'bg-emerald-500',
    'occupied','temp-closed' => 'bg-rose-500',
    default => 'bg-amber-500',
};
$statusPillClass = match($status) {
    'available','open','new' => 'pill available',
    'occupied','temp-closed' => 'pill occupied',
    default => 'pill inquire',
};

$heroImage = !empty($photos) ? $photos[0] : (isset($_GET['image']) ? htmlspecialchars(urldecode($_GET['image'])) : 'assets/bghero2.jpg');
$fixedLocation = '075 Purok 3, Sumacab Este';
$location = $fixedLocation;
$openMapsUrl = $mapsLink ?: ('https://www.google.com/maps/search/?api=1&query=' . urlencode($fixedLocation));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="assets/responsive-global.css">
  <title><?= esc($name) ?> - <?= e($siteSettings['site_title']) ?></title>
  <link rel="icon" href="<?= e(site_config_logo_url($siteSettings, '')) ?>" type="image/png">
  <?= site_config_css_vars($siteSettings) ?>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="/tailwind/input.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body { font-family: 'DM Sans', sans-serif; background: var(--site-primary-pale); margin: 0; }

    .nav-link { position: relative; transition: color 0.2s ease; }
    .nav-link::after { content: ''; position: absolute; bottom: -2px; left: 0; width: 0; height: 2px; background: var(--site-primary); transition: width 0.3s; }
    .nav-link:hover::after { width: 100%; }
    .nav-link:hover { color: var(--site-primary-dark); }

    .pill { display: inline-flex; align-items: center; padding: 0.3rem 0.85rem; border-radius: 999px; font-size: 0.72rem; font-weight: 700; border: 1px solid transparent; }
    .pill.available { background: rgba(22,163,74,0.11); color: #065f46; border-color: rgba(22,163,74,0.3); }
    .pill.occupied  { background: rgba(239,68,68,0.11); color: #991b1b; border-color: rgba(239,68,68,0.3); }
    .pill.inquire   { background: rgba(234,179,8,0.12); color: #92400e; border-color: rgba(234,179,8,0.3); }

    .tag-badge { display: inline-flex; align-items: center; margin: 3px; padding: 4px 12px; background: var(--site-primary-pale); color: var(--site-primary-dark); border: 1px solid color-mix(in srgb, var(--site-primary-light) 40%, white); border-radius: 999px; font-size: 0.72rem; font-weight: 600; }
    .tag-badge.biz { background: #eff6ff; color: #1d4ed8; border-color: #93c5fd; }

    .detail-section { background: #fff; border-radius: 18px; border: 1px solid #e5e7eb; box-shadow: 0 4px 16px rgba(0,0,0,0.05); padding: 20px; margin-bottom: 16px; transition: box-shadow 0.2s; }
    .detail-section:hover { box-shadow: 0 8px 24px rgba(0,0,0,0.09); }
    .section-hdr { display: flex; align-items: center; gap: 10px; font-size: 0.8rem; font-weight: 800; color: #374151; text-transform: uppercase; letter-spacing: 0.07em; margin-bottom: 14px; }
    .section-icon { width: 28px; height: 28px; background: color-mix(in srgb, var(--site-primary) 20%, white); border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .section-icon.biz { background: #dbeafe; }

    .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    @media (max-width: 480px) { .info-grid { grid-template-columns: 1fr; } }
    .info-row { display: flex; flex-direction: column; gap: 2px; }
    .info-label { font-size: 0.68rem; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.06em; }
    .info-value { font-size: 0.875rem; color: #1f2937; font-weight: 500; }
    .info-value.empty { color: #d1d5db; font-style: italic; }

    .stat-card { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px; text-align: center; }
    .stat-label { font-size: 0.7rem; color: #6b7280; }
    .stat-val   { font-size: 1.1rem; font-weight: 700; color: #111827; margin-top: 4px; }

    .gallery-thumb { width: 100%; height: 80px; object-fit: cover; border-radius: 10px; cursor: pointer; transition: transform 0.2s, opacity 0.2s; border: 2px solid transparent; }
    .gallery-thumb:hover { transform: scale(1.03); }
    .gallery-thumb.active { border-color: var(--site-primary); }
    .photo-placeholder { height: 80px; border: 1.5px dashed #d1d5db; border-radius: 10px; background: #f3f4f6; display: flex; align-items: center; justify-content: center; color: #9ca3af; font-size: 0.72rem; }

    .lightbox { position: fixed; inset: 0; z-index: 1000; background: rgba(0,0,0,0.88); display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity 0.2s; padding: 20px; }
    .lightbox.open { opacity: 1; pointer-events: auto; }
    .lightbox img { max-width: 90vw; max-height: 88vh; border-radius: 12px; object-fit: contain; }
    .lightbox-close { position: absolute; top: 16px; right: 20px; background: rgba(255,255,255,0.12); border: none; color: #fff; font-size: 1.1rem; width: 36px; height: 36px; border-radius: 10px; cursor: pointer; display: flex; align-items: center; justify-content: center; }
    .lightbox-close:hover { background: rgba(255,255,255,0.22); }

    .no-photo { width: 100%; height: 300px; background: linear-gradient(135deg, color-mix(in srgb, var(--site-primary) 20%, white), color-mix(in srgb, var(--site-primary-light) 40%, white)); display: flex; align-items: center; justify-content: center; border-radius: 14px; }

    /* Mobile sidebar */
    #mobile-sidebar { overflow-y: auto; }

    @media (max-width: 640px) {
      .hero-img { height: 240px !important; }
      .no-photo { height: 220px; }
    }

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
    .hover\:bg-green-50:hover { background-color: var(--site-primary-pale) !important; }
    .hover\:border-green-300:hover { border-color: var(--site-primary-light) !important; }
    .focus\:ring-green-400:focus { --tw-ring-color: var(--site-primary-light) !important; }
    .from-green-50 { --tw-gradient-from: var(--site-primary-pale) !important; }

    footer .text-green-300 { color: rgba(255,255,255,0.75) !important; }
    footer .text-green-400 { color: rgba(255,255,255,0.65) !important; }
    footer .text-green-500 { color: rgba(255,255,255,0.45) !important; }
    footer .hover\:text-white:hover { color: #ffffff !important; }
    footer .border-green-800 { border-color: rgba(255,255,255,0.12) !important; }
  </style>
</head>
<body>

<!-- ?????????????????? HEADER ?????????????????? -->
<header class="w-full h-[68px] border-b border-green-100 flex items-center px-4 sm:px-6 lg:px-8 bg-white shadow-sm sticky top-0 z-50">
  <div class="flex items-center gap-3 flex-shrink-0">
    <a href="<?= $logged_in ? 'resident/residentLanding.php' : 'landing.php' ?>" class="flex items-center gap-3">
      <div class="w-9 h-9 rounded-full flex items-center justify-center shadow overflow-hidden flex-shrink-0" style="background: var(--site-primary)">
        <img src="<?= e(site_config_logo_url($siteSettings, '')) ?>" alt="Logo" class="w-full h-full object-contain" />
      </div>
      <div class="sm:block">
        <h3 class="font-bold text-sm leading-tight" style="color:var(--site-primary-darker)"><?= e($siteSettings['site_title']) ?></h3>
        <p class="text-[9px] tracking-widest uppercase" style="color:var(--site-primary)"><?= e($siteSettings['barangay_name']) ?></p>
      </div>
    </a>
  </div>

  <nav class="ml-auto flex items-center gap-3 md:gap-6 text-gray-600 text-sm font-medium">
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
    <button id="mobile-menu-btn" class="md:hidden flex items-center justify-center p-2 text-gray-600 hover:text-green-700 hover:bg-green-50 rounded-lg transition" aria-label="Toggle menu">
      <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
      </svg>
    </button>
  </nav>
</header>

<!-- ?????????????????? MOBILE SIDEBAR ?????????????????? -->
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
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
    </button>
  </div>
  <div class="flex-1 overflow-y-auto py-3 px-3 space-y-0.5">
    <?php if ($showMyPanel): ?>
      <a href="resident/residentPanel.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 font-medium hover:bg-[var(--site-primary-pale)] hover:text-[var(--site-primary-dark)] active:scale-[0.97] active:bg-[var(--site-primary-pale)] transition-all duration-150">
        <i class="fa-solid fa-gauge-high w-4 text-[var(--site-primary)]"></i> My Panel
      </a>
    <?php endif; ?>
    <?php if ($isAdminLike): ?>
      <a href="admin/adminDashboard.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 font-medium hover:bg-[var(--site-primary-pale)] hover:text-[var(--site-primary-dark)] active:scale-[0.97] active:bg-[var(--site-primary-pale)] transition-all duration-150">
        <i class="fa-solid fa-shield-halved w-4 text-[var(--site-primary)]"></i> Dashboard
      </a>
    <?php endif; ?>
    <a href="landing.php#announcements" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 font-medium hover:bg-[var(--site-primary-pale)] hover:text-[var(--site-primary-dark)] active:scale-[0.97] active:bg-[var(--site-primary-pale)] transition-all duration-150">
      <i class="fa-solid fa-bullhorn w-4 text-[var(--site-primary)]"></i> Announcements
    </a>
    <a href="busaptListing.php?type=business" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 font-medium hover:bg-[var(--site-primary-pale)] hover:text-[var(--site-primary-dark)] active:scale-[0.97] active:bg-[var(--site-primary-pale)] transition-all duration-150">
      <i class="fa-solid fa-store w-4 text-[var(--site-primary)]"></i> Business
    </a>
    <a href="busaptListing.php?type=apartment" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 font-medium hover:bg-[var(--site-primary-pale)] hover:text-[var(--site-primary-dark)] active:scale-[0.97] active:bg-[var(--site-primary-pale)] transition-all duration-150">
      <i class="fa-solid fa-building w-4 text-[var(--site-primary)]"></i> Apartment
    </a>
    <?php if (str_contains($roleLower, 'non-resident,business/apartment owner') || str_contains($roleLower, 'business') && !str_contains($roleLower, 'resident')): ?>
      <a href="nonResident/manageList.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 font-medium hover:bg-[var(--site-primary-pale)] hover:text-[var(--site-primary-dark)] transition">
        <i class="fa-solid fa-plus w-4 text-[var(--site-primary)]"></i> Post Listing
      </a>
    <?php endif; ?>
    <?php if ($logged_in): ?>
    <div class="pt-2 border-t border-gray-100 mt-2 space-y-0.5">
      <?php if ($profileUrl !== null): ?>
      <a href="<?= e($profileUrl) ?>" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 font-medium hover:bg-[var(--site-primary-pale)] hover:text-[var(--site-primary-dark)] active:scale-[0.97] active:bg-[var(--site-primary-pale)] transition-all duration-150">
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

<!-- ?????????????????? MAIN ?????????????????? -->
<main class="max-w-6xl mx-auto px-4 sm:px-6 py-8 sm:py-10">

  <!-- Breadcrumb -->
  <div class="flex items-center gap-2 text-sm text-gray-500 mb-6 flex-wrap">
    <a href="busaptListing.php" class="hover:text-[var(--site-primary)] transition font-medium flex items-center gap-1">
      <i class="fa-solid fa-arrow-left text-xs"></i> Back to Directory
    </a>
    <span>�</span>
    <span class="text-gray-400"><?= esc($isApt ? 'Apartment' : 'Business') ?></span>
    <span>�</span>
    <span class="text-green-700 font-semibold truncate max-w-[160px] sm:max-w-none"><?= esc($name) ?></span>
  </div>

  <?php if (!$l && $listingId > 0): ?>
  <div class="bg-red-50 border border-red-200 rounded-xl p-6 text-center text-red-700 mb-8">
    <i class="fa-solid fa-circle-exclamation text-2xl mb-2 block"></i>
    <p class="font-semibold">Listing not found.</p>
    <p class="text-sm mt-1 text-red-500">It may have been removed or the link is invalid.</p>
    <a href="busaptListing.php" class="mt-4 inline-block px-5 py-2 bg-red-600 text-white rounded-lg text-sm font-semibold hover:bg-red-700 transition">Back to Directory</a>
  </div>
  <?php else: ?>

  <div class="bg-white rounded-2xl shadow-xl border border-gray-100 p-4 sm:p-6 md:p-8">
    <div class="flex flex-col lg:flex-row gap-6 lg:gap-8">

      <!-- LEFT: Photos + Info -->
      <div class="w-full lg:w-3/5 min-w-0">
        <?php if (!empty($photos)): ?>
          <img id="heroImage" src="<?= esc($photos[0]) ?>" alt="<?= esc($name) ?>"
               class="hero-img rounded-xl w-full object-cover shadow-md cursor-pointer"
               style="height:300px;"
               onclick="openLightbox(this.src)"
               onerror="this.style.display='none';document.getElementById('heroFallback').style.display='flex'">
          <div id="heroFallback" class="no-photo" style="display:none;">
            <i class="fa-solid <?= $isApt ? 'fa-building' : 'fa-store' ?> text-5xl text-green-400 opacity-30"></i>
          </div>
        <?php else: ?>
          <div class="no-photo">
            <i class="fa-solid <?= $isApt ? 'fa-building' : 'fa-store' ?> text-5xl text-green-400 opacity-30"></i>
          </div>
        <?php endif; ?>

        <?php if (count($photos) > 1): ?>
        <div class="mt-3 grid grid-cols-4 gap-2">
          <?php foreach ($photos as $i => $p): ?>
          <img src="<?= esc($p) ?>" data-src="<?= esc($p) ?>"
               class="gallery-thumb <?= $i === 0 ? 'active' : '' ?>"
               alt="Photo <?= $i+1 ?>"
               onerror="this.style.display='none'"
               onclick="switchPhoto(this)">
          <?php endforeach; ?>
        </div>
        <?php elseif (empty($photos)): ?>
        <div class="mt-3 grid grid-cols-4 gap-2">
          <?php for($i=0;$i<4;$i++): ?>
          <div class="photo-placeholder"><i class="fa-solid fa-image text-lg"></i></div>
          <?php endfor; ?>
        </div>
        <?php endif; ?>

        <!-- About -->
        <div class="detail-section mt-5">
          <div class="section-hdr">
            <div class="section-icon <?= $isApt ? '' : 'biz' ?>">
              <i class="fa-solid fa-list text-<?= $isApt ? 'green' : 'blue' ?>-700 text-xs"></i>
            </div>
            About this listing
          </div>
          <p class="text-gray-700 leading-relaxed text-sm">
            <?= !empty($isApt ? $aptDesc : $bussDesc)
                ? nl2br(esc($isApt ? $aptDesc : $bussDesc))
                : '<em class="text-gray-400">No description provided.</em>' ?>
          </p>

          <?php if ($isApt): ?>
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-5">
            <div class="stat-card"><p class="stat-label">Rooms</p><p class="stat-val"><?= esc($aptRooms ?: '-') ?></p></div>
            <div class="stat-card"><p class="stat-label">Max Occupants</p><p class="stat-val"><?= esc($aptOccupants ?: '-') ?></p></div>
            <div class="stat-card"><p class="stat-label">Bathroom</p><p class="stat-val"><?= esc($aptBath ? ucfirst($aptBath) : '-') ?></p></div>
            <div class="stat-card"><p class="stat-label">Slots Open</p><p class="stat-val"><?= esc($aptSlots !== '' ? $aptSlots : '-') ?></p></div>
          </div>
          <?php if (!empty($aptInc)): ?><div class="mt-4"><p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Included in Rent</p><div><?= tagBadges($aptInc, $INC_LBL) ?></div></div><?php endif; ?>
          <?php if (!empty($aptAmn)): ?><div class="mt-4"><p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Amenities</p><div><?= tagBadges($aptAmn, $AMN_LBL) ?></div></div><?php endif; ?>
          <?php if (!empty($aptRules)): ?><div class="mt-4"><p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">House Rules</p><div><?= tagBadges($aptRules, $RULES_LBL) ?></div></div><?php endif; ?>

          <?php else: ?>
          <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 mt-5">
            <div class="stat-card"><p class="stat-label">Category</p><p class="stat-val" style="font-size:0.82rem;"><?= esc(labelMap($bussCat, $BIZ_CAT)) ?></p></div>
            <div class="stat-card"><p class="stat-label">Open Hours</p><p class="stat-val" style="font-size:0.82rem;"><?= ($bussOpen && $bussClose) ? esc(fmt12($bussOpen) . ' - ' . fmt12($bussClose)) : '-' ?></p></div>
            <div class="stat-card"><p class="stat-label">Years in Business</p><p class="stat-val" style="font-size:0.82rem;"><?= esc(labelMap($bussYears, $YEARS_LBL) ?: '-') ?></p></div>
          </div>
          <?php if (!empty($bussDays)): ?><div class="mt-4"><p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Days Open</p><div><?= tagBadges($bussDays, $DAYS_LBL) ?></div></div><?php endif; ?>
          <?php if (!empty($bussFeatures)): ?><div class="mt-4"><p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Features &amp; Services</p><div><?= tagBadges($bussFeatures, $FEAT_LBL) ?></div></div><?php endif; ?>
          <?php endif; ?>
        </div>

        <!-- Map -->
        <div class="detail-section">
          <div class="section-hdr">
            <div class="section-icon"><i class="fa-solid fa-map-pin text-green-700 text-xs"></i></div>
            Location
          </div>
          <div class="rounded-xl overflow-hidden border border-gray-200 mb-3" style="height:220px;">
            <iframe src="https://www.google.com/maps?q=<?= urlencode($fixedLocation) ?>&output=embed"
              width="100%" height="100%" style="border:0;display:block;" allowfullscreen loading="lazy"></iframe>
          </div>
          <a href="<?= esc($openMapsUrl) ?>" target="_blank" rel="noopener"
             class="inline-flex items-center gap-2 text-green-700 text-sm font-bold hover:underline">
            <i class="fa-solid fa-arrow-up-right-from-square text-xs"></i> Open in Google Maps
          </a>
        </div>
      </div>

      <!-- RIGHT: Sidebar -->
      <div class="w-full lg:w-2/5 min-w-0 space-y-4">

        <!-- Title card -->
        <div class="p-5 bg-gradient-to-br from-green-50 to-white rounded-xl border border-green-100 shadow-sm">
          <span class="text-xs font-bold text-gray-400 uppercase tracking-widest"><?= esc($isApt ? 'Apartment / Room' : 'Business') ?></span>
          <h2 class="text-xl sm:text-2xl font-bold text-gray-800 mt-1 leading-tight" style="font-family:'Playfair Display',serif;"><?= esc($name) ?></h2>
          <p class="text-xs text-gray-400 mt-0.5"><?= esc($isApt ? 'Apartment � ' . labelMap($aptType, $APT_TYPE) : 'Business � ' . labelMap($bussCat, $BIZ_CAT)) ?></p>
          <p class="mt-3 text-xl sm:text-2xl font-bold <?= $isApt ? 'text-green-700' : 'text-blue-700' ?>"><?= esc($price) ?></p>
          <div class="mt-3 flex items-center gap-2 flex-wrap">
            <span class="<?= $statusPillClass ?>">
              <span class="inline-block w-1.5 h-1.5 rounded-full <?= $statusColor ?> mr-1.5"></span>
              <?= esc($statusLbl) ?>
            </span>
            <?php if ($isApt && $aptSlots): ?>
              <span class="pill available"><i class="fa-solid fa-circle-check mr-1 text-xs"></i> <?= (int)$aptSlots ?> slot<?= $aptSlots != 1 ? 's' : '' ?> open</span>
            <?php endif; ?>
            <?php if ($datePosted !== '-'): ?>
              <span class="text-xs text-gray-400">Posted <?= esc($datePosted) ?></span>
            <?php endif; ?>
          </div>
          <p class="mt-3 text-sm text-gray-600 flex items-start gap-2">
            <i class="fa-solid fa-location-dot text-green-600 mt-0.5 flex-shrink-0"></i>
            <span><?= esc($fixedLocation) ?></span>
          </p>
        </div>

        <!-- Contact -->
        <div class="detail-section">
          <div class="section-hdr">
            <div class="section-icon"><i class="fa-solid fa-phone text-green-700 text-xs"></i></div>
            Contact Information
          </div>
          <div class="space-y-2">
            <?php if ($contact): ?>
            <a href="tel:<?= esc(preg_replace('/\D/', '', $contact)) ?>"
               class="flex items-center gap-3 p-3 bg-green-50 rounded-lg hover:bg-green-100 transition group">
              <div class="w-9 h-9 bg-green-100 group-hover:bg-green-200 rounded-full flex items-center justify-center flex-shrink-0">
                <i class="fa-solid fa-phone text-green-700 text-xs"></i>
              </div>
              <div>
                <p class="text-xs text-gray-500">Phone</p>
                <p class="text-sm font-semibold text-green-700"><?= esc($contact) ?></p>
              </div>
            </a>
            <?php endif; ?>
            <?php if ($email): ?>
            <a href="mailto:<?= esc($email) ?>"
               class="flex items-center gap-3 p-3 bg-blue-50 rounded-lg hover:bg-blue-100 transition group">
              <div class="w-9 h-9 bg-blue-100 group-hover:bg-blue-200 rounded-full flex items-center justify-center flex-shrink-0">
                <i class="fa-solid fa-envelope text-blue-700 text-xs"></i>
              </div>
              <div>
                <p class="text-xs text-gray-500">Email</p>
                <p class="text-sm font-semibold text-blue-700 break-all"><?= esc($email) ?></p>
              </div>
            </a>
            <?php endif; ?>
            <?php if (!$contact && !$email): ?>
              <p class="text-sm text-gray-400 italic">Contact information not provided.</p>
            <?php endif; ?>
          </div>
        </div>

        <!-- Details -->
        <div class="detail-section">
          <div class="section-hdr">
            <div class="section-icon <?= $isApt ? '' : 'biz' ?>">
              <i class="fa-solid <?= $isApt ? 'fa-building text-green-700' : 'fa-store text-blue-700' ?> text-xs"></i>
            </div>
            <?= $isApt ? 'Room Details' : 'Business Info' ?>
          </div>
          <?php if ($isApt): ?>
          <div class="info-grid">
            <div class="info-row"><span class="info-label">Room Type</span><span class="info-value <?= $aptType?'':'empty' ?>"><?= $aptType ? esc(labelMap($aptType, $APT_TYPE)) : '-' ?></span></div>
            <div class="info-row"><span class="info-label">Floor / Level</span><span class="info-value <?= $aptFloor?'':'empty' ?>"><?= $aptFloor ? esc($aptFloor) : '-' ?></span></div>
            <div class="info-row"><span class="info-label">Rooms</span><span class="info-value <?= $aptRooms?'':'empty' ?>"><?= $aptRooms ? esc($aptRooms) : '-' ?></span></div>
            <div class="info-row"><span class="info-label">Max Occupants</span><span class="info-value <?= $aptOccupants?'':'empty' ?>"><?= $aptOccupants ? esc($aptOccupants) : '-' ?></span></div>
            <div class="info-row"><span class="info-label">Bathroom</span><span class="info-value <?= $aptBath?'':'empty' ?>"><?= $aptBath ? esc(ucfirst($aptBath)) : '-' ?></span></div>
            <div class="info-row"><span class="info-label">Slots Available</span><span class="info-value <?= $aptSlots!==''?'':'empty' ?>"><?= $aptSlots !== '' ? esc($aptSlots) : '-' ?></span></div>
          </div>
          <?php else: ?>
          <div class="info-grid">
            <div class="info-row"><span class="info-label">Category</span><span class="info-value"><?= esc(labelMap($bussCat, $BIZ_CAT)) ?></span></div>
            <div class="info-row"><span class="info-label">Status</span><span class="info-value"><?= esc(labelMap($status, $BIZ_STAT)) ?></span></div>
            <div class="info-row"><span class="info-label">Opens</span><span class="info-value <?= $bussOpen?'':'empty' ?>"><?= $bussOpen ? esc(fmt12($bussOpen)) : '-' ?></span></div>
            <div class="info-row"><span class="info-label">Closes</span><span class="info-value <?= $bussClose?'':'empty' ?>"><?= $bussClose ? esc(fmt12($bussClose)) : '-' ?></span></div>
            <div class="info-row"><span class="info-label">Starting Price</span><span class="info-value <?= ($l['bussPrice']??'')?'':'empty' ?>"><?= ($l['bussPrice']??'') ? '?'.esc($l['bussPrice']) : '-' ?></span></div>
            <div class="info-row"><span class="info-label">Years Operating</span><span class="info-value <?= $bussYears?'':'empty' ?>"><?= $bussYears ? esc(labelMap($bussYears, $YEARS_LBL)) : '-' ?></span></div>
          </div>
          <?php endif; ?>
        </div>

        <a href="busaptListing.php" class="flex items-center justify-center gap-2 w-full px-5 py-3 bg-gray-100 text-gray-700 rounded-xl hover:bg-gray-200 transition font-semibold text-sm">
          <i class="fa-solid fa-arrow-left text-xs"></i> Back to Directory
        </a>
      </div>
    </div>
  </div>
  <?php endif; ?>
</main>

<!-- Lightbox -->
<div class="lightbox" id="lightbox" onclick="closeLightbox()">
  <button class="lightbox-close" onclick="closeLightbox()"><i class="fa-solid fa-xmark"></i></button>
  <img id="lightboxImg" src="" alt="">
</div>

<!-- ?????????????????? FOOTER ?????????????????? -->
<footer class="bg-green-950 text-white pt-14 pb-6 px-4 sm:px-6 mt-8">
  <div class="max-w-6xl mx-auto">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-10 pb-10 border-b border-green-800">
      <div>
        <div class="flex items-center gap-3 mb-4">
          <div class="w-12 h-12 rounded-full bg-green-700 flex items-center justify-center overflow-hidden flex-shrink-0">
            <img src="<?= e(site_config_logo_url($siteSettings, '')) ?>" alt="Logo" class="w-full h-full object-contain" />
          </div>
          <div><h3 class="text-lg font-bold"><?= e($siteSettings['site_title']) ?></h3><p class="text-green-400 text-xs tracking-widest uppercase"><?= e($siteSettings['barangay_name']) ?></p></div>
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
            <?php if ($showMyPanel): ?>
            <a href="resident/residentPanel.php" class="block text-sm text-green-400 hover:text-white transition">Services</a>
          <?php else: ?>
            <a href="busaptListing.php" class="block text-sm text-green-400 hover:text-white transition">Directory</a>
          <?php endif; ?>
          <a href="infoSecurity/dataProtection.php" class="block text-sm text-green-400 hover:text-white transition">Privacy Policy</a>
          <a href="infoSecurity/terms.php" class="block text-sm text-green-400 hover:text-white transition">Terms of Service</a>
        </div>
      </div>
      <div>
        <h4 class="font-semibold text-green-300 text-xs uppercase tracking-widest mb-4">Quick Links</h4>
        <div class="space-y-2">
          <a href="busaptListing.php" class="block text-sm text-green-400 hover:text-white transition">Directory</a>
          <a href="busaptListing.php?type=apartment" class="block text-sm text-green-400 hover:text-white transition">Apartments</a>
          <a href="busaptListing.php?type=business" class="block text-sm text-green-400 hover:text-white transition">Business Directory</a>
        </div>
      </div>
    </div>
    <div class="text-center mt-6 text-green-500 text-sm">� 2026 <?= e($siteSettings['site_title']) ?>. All Rights Reserved. <?= e($siteSettings['barangay_name']) ?>.</div>
  </div>
</footer>

<script>
  function switchPhoto(thumb) {
    const src = thumb.getAttribute('data-src'); if (!src) return;
    const hero = document.getElementById('heroImage'); if (hero) hero.src = src;
    document.querySelectorAll('.gallery-thumb').forEach(t => t.classList.remove('active'));
    thumb.classList.add('active');
  }
  function openLightbox(src) {
    if (!src) return;
    document.getElementById('lightboxImg').src = src;
    document.getElementById('lightbox').classList.add('open');
    document.body.style.overflow = 'hidden';
  }
  function closeLightbox() {
    document.getElementById('lightbox').classList.remove('open');
    document.getElementById('lightboxImg').src = '';
    document.body.style.overflow = '';
  }
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeLightbox(); });

  function toggleProfileMenu() {
    document.getElementById('profile-dropdown')?.classList.toggle('hidden');
  }
  document.addEventListener('click', e => {
    const pd = document.getElementById('profile-dropdown');
    const pb = document.getElementById('profile-btn');
    if (pd && pb && !pd.contains(e.target) && !pb.contains(e.target)) pd.classList.add('hidden');
  });

  // Mobile sidebar
  const overlay  = document.getElementById('mobile-sidebar-overlay');
  const sidebar  = document.getElementById('mobile-sidebar');
  const openBtn  = document.getElementById('mobile-menu-btn');
  const closeBtn = document.getElementById('mobile-menu-close');
  function openSidebar() {
    overlay.classList.remove('hidden','opacity-0'); overlay.classList.add('opacity-80');
    sidebar.classList.remove('translate-x-full'); document.body.style.overflow = 'hidden';
  }
  function closeSidebar() {
    overlay.classList.add('opacity-0'); sidebar.classList.add('translate-x-full');
    document.body.style.overflow = '';
    setTimeout(() => overlay.classList.add('hidden'), 250);
  }
  openBtn?.addEventListener('click', openSidebar);
  closeBtn?.addEventListener('click', closeSidebar);
  overlay?.addEventListener('click', closeSidebar);
</script>
</body>
</html>