<?php
session_start();
include "../config/db_connection.php";

// Must be logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../includes/check_permissions.php';
require_once __DIR__ . '/../includes/site_config.php';

// This landing page is the shared entry hub for BOTH full 'admin' and
// limited 'custom_admin' accounts - it just shows tiles linking to
// whichever sections exist. It is NOT gated behind any single feature
// permission (that check happens on each individual page, e.g.
// residentManagement.php checks 'manage_residents').
$role = $_SESSION['account_role'] ?? '';
$adminRoles = ['admin', 'custom_admin'];
$myPermissions = get_my_permissions($conn);
$isAdminAccess = $role === 'admin' || $role === 'custom_admin' || !empty($myPermissions);
if (!$isAdminAccess) {
    switch ($role) {
        case 'resident':
        case 'resident,business/apartment owner':
            header('Location: ../resident/residentLanding.php');
            break;
        case 'non-resident':
        case 'non-resident,business/apartment owner':
            header('Location: ../nonResident/nonresidentLanding.php');
            break;
        default:
            header('Location: ../landing.php');
    }
    exit;
}

$siteSettings = site_config_load($conn);
$heroImages   = site_config_hero_images($conn);
$heroTotalSlides = 1 + count($heroImages);

$logged_in = true;

// Profile dropdown variables
$userEmail = $_SESSION['user_id'] ?? '';
$accId     = $_SESSION['acc_id']  ?? '';

$roleLabel = match($role) {
    'admin'        => 'Admin',
    'custom_admin' => 'Limited Admin',
    'staff'        => 'Admin',
    'resident'     => 'Resident',
    'non-resident' => 'Non-Resident',
    default        => 'User',
};

$roleBadgeClass = match($role) {
    'admin'        => 'bg-purple-100 text-purple-700 border border-purple-200',
    'custom_admin' => 'bg-fuchsia-100 text-fuchsia-700 border border-fuchsia-200',
    'staff'        => 'bg-fuchsia-100 text-fuchsia-700 border border-fuchsia-200',
    'resident'     => 'bg-green-100 text-green-700 border border-green-200',
    'non-resident' => 'bg-blue-100 text-blue-700 border border-blue-200',
    default        => 'bg-gray-100 text-gray-600 border border-gray-200',
};

$initials = strtoupper(substr($userEmail, 0, 2));

// Load the granted modules so the Community section can be shown only
// when that account actually has community access.

function tileAllowed(string $permissionKey, string $role, array $myPermissions): bool
{
    if ($role === 'admin') {
        return true;
    }
    return in_array($permissionKey, $myPermissions, true);
}

$showCommunitySection = $role === 'admin'
  || in_array('manage_listings', $myPermissions, true)
  || in_array('manage_announcements', $myPermissions, true);

  // Residents Served
$approvedUsersCount = (int) mysqli_fetch_assoc(
    mysqli_query($conn, "
        SELECT COUNT(*) AS total
        FROM tbl_userinfo
        WHERE LOWER(userStatus) = 'approved'
    ")
)['total'];

// --- Fetch latest 10 announcements, newest first ---
$announcements = [];
$stmt = $conn->prepare(
    'SELECT announcementID, announcementTitle, announcementDesc,
            announcementPost, announcementStart, announcementTag, announcementImg
       FROM tbl_announcement
      ORDER BY announcementPost DESC
      LIMIT 10'
);
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $announcements[] = $row;
    }
    $stmt->close();
}

// --- Tag ? color/icon helper ---
function tagColor(string $tag): array {
    return match(strtolower(trim($tag))) {
        'health'     => ['bg'=>'bg-green-100',  'text'=>'text-green-700',  'icon'=>'fa-heartbeat',          'icon_bg'=>'bg-green-100',  'icon_color'=>'text-green-600'],
        'assistance' => ['bg'=>'bg-blue-100',   'text'=>'text-blue-700',   'icon'=>'fa-hand-holding-heart', 'icon_bg'=>'bg-blue-50',    'icon_color'=>'text-blue-500'],
        'community'  => ['bg'=>'bg-orange-100', 'text'=>'text-orange-700', 'icon'=>'fa-people-group',       'icon_bg'=>'bg-orange-50',  'icon_color'=>'text-orange-500'],
        'education'  => ['bg'=>'bg-purple-100', 'text'=>'text-purple-700', 'icon'=>'fa-graduation-cap',     'icon_bg'=>'bg-purple-50',  'icon_color'=>'text-purple-500'],
        'safety'     => ['bg'=>'bg-red-100',    'text'=>'text-red-700',    'icon'=>'fa-shield-halved',      'icon_bg'=>'bg-red-50',     'icon_color'=>'text-red-500'],
        'event'      => ['bg'=>'bg-yellow-100', 'text'=>'text-yellow-700', 'icon'=>'fa-calendar-star',      'icon_bg'=>'bg-yellow-50',  'icon_color'=>'text-yellow-500'],
        default      => ['bg'=>'bg-gray-100',   'text'=>'text-gray-600',   'icon'=>'fa-bullhorn',           'icon_bg'=>'bg-gray-100',   'icon_color'=>'text-gray-500'],
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="../assets/responsive-global.css">
   <title><?= e($siteSettings['site_title']) ?></title> 
  <link rel="icon" href="<?= e(site_config_logo_url($siteSettings, '../')) ?>" type="image/png">
  <?= site_config_css_vars($siteSettings) ?>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script src="https://cdn.tailwindcss.com/3.4.16"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'DM Sans', sans-serif; }
    h1, h2 { font-family: 'Playfair Display', serif; }
    .hero-slider-section {
      position: relative;
      overflow: hidden;
      min-height: 480px;
    }
    @media (max-width: 768px) {
      .hero-slider-section { min-height: 320px; }
    }
    .hero-slider {
      position: absolute;
      top: 0;
      left: 0;
      width: 400%;
      height: 100%;
      display: flex;
      transition: transform 1s cubic-bezier(.77,0,.18,1);
      z-index: 0;
    }
    .hero-slide {
      flex: 0 0 25%;
      height: 100%;
      position: relative;
    }
    .hero-slide-green {
      background: linear-gradient(135deg, var(--site-primary-darker) 0%, var(--site-primary-dark) 40%, var(--site-primary-dark) 70%, var(--site-primary) 100%);
    }
    .hero-slide-green::before {
      content: '';
      position: absolute;
      inset: 0;
      background:
        radial-gradient(ellipse at 70% 50%, rgba(134,239,172,0.08) 0%, transparent 60%),
        radial-gradient(ellipse at 20% 80%, rgba(20,83,45,0.6) 0%, transparent 50%);
      pointer-events: none;
    }
    .hero-slide-green::after {
      content: '';
      position: absolute;
      top: -80px;
      right: -80px;
      width: 420px;
      height: 420px;
      border-radius: 50%;
      border: 70px solid rgba(134,239,172,0.07);
      pointer-events: none;
    }
    .hero-slide-green .circle-bl {
      position: absolute;
      bottom: -60px;
      left: -60px;
      width: 300px;
      height: 300px;
      border-radius: 50%;
      border: 50px solid rgba(134,239,172,0.05);
      pointer-events: none;
    }
    .hero-slide-photo {
      background-size: cover;
      background-position: center;
      background-repeat: no-repeat;
    }
    .hero-slide-photo .photo-overlay {
      position: absolute;
      inset: 0;
      background: rgba(0,0,0,0.40);
      z-index: 1;
    }
    .grain { position: absolute; inset: 0; background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E"); opacity: 0.4; pointer-events: none; }
    .nav-link { position: relative; transition: color 0.2s; }
    .nav-link::after { content: ''; position: absolute; bottom: -2px; left: 0; width: 0; height: 2px; background: var(--site-primary); transition: width 0.3s ease; }
    .nav-link:hover::after { width: 100%; }
    .nav-link:hover { color: var(--site-primary-dark); }
    .service-card { transition: transform 0.25s ease, box-shadow 0.25s ease; background: white; border-bottom: 3px solid transparent; }
    .service-card:hover { transform: translateY(-6px); box-shadow: 0 20px 40px rgba(var(--site-primary-rgb),0.12); }
    .impact-card { transition: transform 0.25s ease, background 0.25s ease; }
    .impact-card:hover { transform: translateY(-4px); background: var(--site-primary-darker); }
    .impact-card:hover p { color: var(--site-primary-light) !important; }
    .announcement-card { transition: background 0.2s; }
    .announcement-card:hover { background: var(--site-primary-pale); }
    .platform-item { border-left: 3px solid var(--site-primary); transition: background 0.2s, transform 0.2s; }
    .platform-item:hover { background: var(--site-primary-pale); transform: translateX(4px); }
    .scroll-area::-webkit-scrollbar { width: 6px; }
    .scroll-area::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 6px; }
    .scroll-area::-webkit-scrollbar-thumb { background: var(--site-primary-light); border-radius: 6px; }
    @keyframes fadeUp { from { opacity: 0; transform: translateY(24px); } to { opacity: 1; transform: translateY(0); } }
    .fade-up { animation: fadeUp 0.7s ease both; }
    .fade-up-1 { animation-delay: 0.1s; }
    .fade-up-2 { animation-delay: 0.25s; }
    .badge { display: inline-block; background: color-mix(in srgb, var(--site-primary-light) 15%, transparent); color: var(--site-primary-light); border: 1px solid color-mix(in srgb, var(--site-primary-light) 30%, transparent); font-size: 0.7rem; letter-spacing: 0.12em; text-transform: uppercase; padding: 3px 12px; border-radius: 999px; }.section-label { color: var(--site-primary); }
    .section-label { font-size: 0.75rem; letter-spacing: 0.15em; text-transform: uppercase; color: var(--site-primary); font-weight: 600; }
    .slider-dots { position: absolute; bottom: 80px; left: 50%; transform: translateX(-50%); display: flex; gap: 8px; z-index: 20; }
    .slider-dot { width: 8px; height: 8px; border-radius: 50%; background: rgba(255,255,255,0.4); border: none; cursor: pointer; transition: background 0.3s, transform 0.3s; padding: 0; }
    .slider-dot.active { background: var(--site-primary-light); transform: scale(1.3); }

    /* Tailwind-green ? theme color overrides (matches landing.php) */
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

    /* Footer text: always light/white regardless of theme hue (avoids low-contrast on dark bg) */
    footer .text-green-300 { color: rgba(255,255,255,0.75) !important; }
    footer .text-green-400 { color: rgba(255,255,255,0.65) !important; }
    footer .text-green-500 { color: rgba(255,255,255,0.45) !important; }
    footer .hover\:text-white:hover { color: #ffffff !important; }
    footer .border-green-800 { border-color: rgba(255,255,255,0.12) !important; }

    /* Mission callout box */
    .bg-green-50 { background-color: var(--site-primary-pale) !important; }
    .border-green-500 { border-color: var(--site-primary) !important; }
    .text-green-800 { color: var(--site-primary-darker) !important; }

    /* "Our Reach" dark section (on --site-primary-darker bg) */
    .text-green-300 { color: color-mix(in srgb, var(--site-primary-light) 70%, white) !important; }
    .w-px.bg-green-700 { background-color: color-mix(in srgb, var(--site-primary-light) 40%, transparent) !important; }

    :root {
      --site-primary-dark:   color-mix(in srgb, var(--site-primary) 55%, black);
      --site-primary-darker: color-mix(in srgb, var(--site-primary) 75%, black);
      --site-primary-light:  color-mix(in srgb, var(--site-primary) 55%, white);
      --site-primary-pale:   color-mix(in srgb, var(--site-primary) 12%, white);
    }
  </style>
  <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
</head>
<body class="bg-gray-50">

  <!-- Page Loader -->
  <div id="pageLoader" class="fixed inset-0 bg-green-900/40 backdrop-blur-sm z-[9999] flex flex-col items-center justify-center opacity-0 pointer-events-none transition-opacity duration-300">
    <div class="w-12 h-12 border-4 border-white/20 border-t-green-400 rounded-full animate-spin shadow-lg"></div>
    <p class="text-white font-medium mt-4 tracking-wider text-sm shadow-sm">Loading...</p>
  </div>

  <!-- NAVBAR -->
  <header class="w-full h-[68px] border-b border-green-100 flex items-center px-8 bg-white shadow-sm sticky top-0 z-50">
    <div class="flex items-center gap-3">
      <a href="adminLanding.php" class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-full bg-green-700 flex items-center justify-center shadow overflow-hidden">
          <img src="<?= e(site_config_logo_url($siteSettings, '../')) ?>" alt="Logo" class="w-full h-full object-contain" />
        </div>
        <div>
          <h3 class="font-bold text-base leading-tight" style="font-family:'DM Sans',sans-serif;color:var(--site-primary-dark)"><?= e($siteSettings['site_title']) ?></h3>
          <p class="text-[10px] tracking-widest uppercase" style="color:var(--site-primary)"><?= e($siteSettings['barangay_name']) ?></p>
        </div>
      </a>
    </div>

    <nav class="ml-auto flex items-center gap-4 md:gap-8 text-gray-600 text-sm font-medium">
      <!-- Desktop Menu -->
      <div class="hidden md:flex gap-8 items-center">
        <a href="adminDashboard.php" class="nav-link">Dashboard</a>
        <a href="#announcements"     class="nav-link">Announcements</a>
        <a href="../busaptListing.php?type=business"          class="nav-link">Business</a>
        <a href="../busaptListing.php?type=apartment"         class="nav-link">Apartment</a>

        <div class="relative" id="profile-menu-wrapper">
          <button id="profile-btn" onclick="toggleProfileMenu()"
            class="flex items-center gap-2 px-3 py-1.5 rounded-xl border border-gray-200 hover:bg-[var(--site-primary)] hover:bg-[var(--site-primary-pale)] transition focus:outline-none focus:ring-2 focus:ring-[var(--site-primary)]"
            aria-haspopup="true" aria-expanded="false">
            <span class="w-8 h-8 rounded-full text-white flex items-center justify-center text-xs font-bold select-none" style="background: var(--site-primary)">
              <?php echo htmlspecialchars($initials); ?>
            </span>
            <span class="hidden sm:block text-gray-700 text-sm max-w-[140px] truncate">
              <?php echo htmlspecialchars($userEmail); ?>
            </span>
            <svg id="profile-chevron" class="w-4 h-4 text-gray-400 transition-transform duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
            </svg>
          </button>

          <div id="profile-dropdown" class="hidden absolute right-0 mt-2 w-64 bg-white rounded-2xl shadow-xl border border-gray-100 overflow-hidden z-50" role="menu">
            <!-- Dropdown header -->
            <div class="px-4 py-3 bg-gradient-to-br from-green-50 to-emerald-50 border-b border-gray-100">
              <div class="flex items-center gap-3">
                <span class="w-10 h-10 rounded-full text-white flex items-center justify-center text-sm font-bold select-none flex-shrink-0" style="background:var(--site-primary)">
                  <?php echo htmlspecialchars($initials); ?>
                </span>
                <div class="min-w-0">
                  <p class="text-sm font-semibold text-gray-800 truncate"><?php echo htmlspecialchars($userEmail); ?></p>
                  <span class="inline-block mt-0.5 px-2 py-0.5 rounded-full text-[11px] font-semibold <?php echo $roleBadgeClass; ?>">
                    <?php echo htmlspecialchars($roleLabel); ?>
                  </span>
                </div>
              </div>
            </div>

            <!-- Menu items -->
            <div class="py-1">
              <?php if ($role === 'admin' | $role === 'staff'): ?>
              <a href="adminDashboard.php" role="menuitem" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-purple-50 hover:text-purple-800 transition">
                <i class="fa-solid fa-shield-halved w-4 text-gray-400"></i> Admin Panel
              </a>
              <?php endif; ?>
            </div>

            <!-- Divider + Logout -->
            <div class="border-t border-gray-100 py-1">
              <a href="../logout.php" role="menuitem" class="flex items-center gap-3 px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition">
                <i class="fa-solid fa-arrow-right-from-bracket w-4"></i> Logout
              </a>
            </div>
          </div>
        </div>
      </div>

      <!-- Mobile Menu Button -->
      <button id="mobile-menu-btn" class="md:hidden flex items-center justify-center p-2 text-gray-600 hover:text-green-700 hover:bg-green-50 rounded-lg transition" aria-label="Toggle menu">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
        </svg>
      </button>
    </nav>
  </header>

  <!-- Mobile Sidebar & Overlay -->
  <div id="mobile-sidebar-overlay" class="fixed inset-0 bg-black/50 z-[60] hidden opacity-0 transition-opacity duration-300"></div>
  <div id="mobile-sidebar" class="fixed inset-y-0 right-0 w-64 bg-white shadow-2xl transform translate-x-full transition-transform duration-300 z-[70] flex flex-col">
    <div class="p-4 border-b border-gray-100 flex items-center justify-between">
        <div class="flex items-center gap-2 min-w-0">
          <?php if ($logged_in): ?>
            <span class="w-8 h-8 rounded-full text-white flex items-center justify-center text-xs font-bold" style="background:var(--site-primary)"><?php echo htmlspecialchars($initials); ?></span>
            <div class="min-w-0">
              <p class="text-sm font-semibold text-gray-800 truncate max-w-[140px]"><?php echo htmlspecialchars($userEmail); ?></p>
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
    
    <div class="flex-1 overflow-y-auto py-4">
      <nav class="flex flex-col gap-2 px-4">
        <a href="adminDashboard.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-[var(--site-primary-dark)] font-medium hover:bg-[var(--site-primary-pale)] transition"><i class="fa-solid fa-shield-halved w-4 text-[var(--site-primary)]"></i>Admin Panel</a>
        <a href="#announcements"     class="flex items-center gap-3 px-4 py-3 rounded-xl text-[var(--site-primary-dark)] font-medium hover:bg-[var(--site-primary-pale)] transition"><i class="fa-solid fa-bullhorn w-4 text-[var(--site-primary)]"></i>Announcements</a>
        <a href="../busaptListing.php?type=business"          class="flex items-center gap-3 px-4 py-3 rounded-xl text-[var(--site-primary-dark)] font-medium hover:bg-[var(--site-primary-pale)] transition"><i class="fa-solid fa-store w-4 text-[var(--site-primary)]"></i>Business</a>
        <a href="../busaptListing.php?type=apartment"         class="flex items-center gap-3 px-4 py-3 rounded-xl text-[var(--site-primary-dark)] font-medium hover:bg-[var(--site-primary-pale)] transition"><i class="fa-solid fa-building w-4 text-[var(--site-primary)]"></i>Apartment</a>
        
        <div class="h-px bg-gray-100 my-2"></div>
        <a href="../logout.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-red-600 font-medium hover:bg-red-50 transition">
            <i class="fa-solid fa-arrow-right-from-bracket w-4"></i> Logout
        </a>
      </nav>
    </div>
  </div>


  <!-- HERO SECTION -->
  <section class="hero-slider-section text-white py-20 px-6 relative overflow-hidden" id="hero">
   <div class="hero-slider" id="heroSlider" style="width: <?= $heroTotalSlides * 100 ?>%;">
      <div class="hero-slide hero-slide-green" style="flex-basis: <?= 100 / $heroTotalSlides ?>%;">
        <div class="circle-bl"></div>
        <div class="grain"></div>
      </div>
      <?php foreach ($heroImages as $img): ?>
      <div class="hero-slide hero-slide-photo" style="flex-basis: <?= 100 / $heroTotalSlides ?>%; background-image: url('../uploads/hero/<?= e($img['filename']) ?>');">
        <div class="photo-overlay"></div>
        <div class="grain"></div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="relative z-10 max-w-6xl mx-auto flex flex-col md:flex-row items-center gap-12 pt-10 pb-10">
      <div class="flex-1" data-aos="fade-up">
        <span class="badge mb-4 inline-block">Barangay Integrated System</span>
        <h1 class="text-4xl md:text-5xl font-bold leading-tight mb-4">
          Welcome<?= $role === 'custom_admin' ? '' : ' Admin' ?>!<br>
          <span class="text-green-300">How can we</span><br>serve you today?
        </h1>
        <p class="text-green-200 text-base max-w-md leading-relaxed">
          Manage residents, documents, and services with ease. Access the latest updates, review requests, and oversee community programs all in one place. Your dashboard for efficient barangay governance starts here.
        </p>
      </div>

      <!-- Stats -->
      <div class="flex-1 grid grid-cols-2 gap-4 w-full max-w-sm" data-aos="fade-up" data-aos-delay="200">
        <div class="bg-black/40 backdrop-blur-md border border-white/20 rounded-2xl p-5 text-center shadow-lg"><p class="text-3xl font-bold text-green-400"><?= number_format($approvedUsersCount) . '+' ?></p><p class="text-xs text-green-200 mt-1 uppercase tracking-wider">Residents Served</p></div>
        <div class="bg-black/40 backdrop-blur-md border border-white/20 rounded-2xl p-5 text-center shadow-lg"><p class="text-3xl font-bold text-green-400">6</p><p class="text-xs text-green-200 mt-1 uppercase tracking-wider">Digital Services</p></div>
        <div class="bg-black/40 backdrop-blur-md border border-white/20 rounded-2xl p-5 text-center shadow-lg"><p class="text-3xl font-bold text-green-400">24/7</p><p class="text-xs text-green-200 mt-1 uppercase tracking-wider">Online Access</p></div>
        <div class="bg-black/40 backdrop-blur-md border border-white/20 rounded-2xl p-5 text-center shadow-lg"><p class="text-3xl font-bold text-green-400">100%</p><p class="text-xs text-green-200 mt-1 uppercase tracking-wider">Free to Use</p></div>
      </div>
    </div>
<div class="slider-dots" id="sliderDots" style="<?= $heroTotalSlides <= 1 ? 'display:none;' : '' ?>">
      <?php for ($i = 0; $i < $heroTotalSlides; $i++): ?>
        <button class="slider-dot<?= $i === 0 ? ' active' : '' ?>" data-index="<?= $i ?>" aria-label="Slide <?= $i + 1 ?>"></button>
      <?php endfor; ?>
    </div>

    <div class="absolute bottom-0 left-0 right-0 overflow-hidden leading-none">
      <svg viewBox="0 0 1440 60" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg" class="w-full h-[60px]">
        <path d="M0,60 C360,0 1080,60 1440,0 L1440,60 Z" fill="#f9fafb"/>
      </svg>
    </div>
  </section>

<!-- ADMIN SERVICES GRID -->
<section class="max-w-6xl mx-auto px-4 py-16">
  <div class="text-center mb-10">
    <p class="section-label">Admin Panel</p>
    <h2 class="text-3xl font-bold text-green-950 mt-2">Management Services</h2>
    <p class="text-gray-500 mt-2 text-sm max-w-xl mx-auto">
      Administrative tools to manage barangay services, records, and community updates.
    </p>
  </div>

  <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-6">

    <a href="beneficiaryManagement.php" class="service-card rounded-2xl p-7 shadow-sm block text-center group" data-aos="fade-up" data-aos-delay="100">
      <div class="w-14 h-14 mx-auto rounded-2xl bg-blue-50 group-hover:bg-blue-100 flex items-center justify-center transition mb-4">
        <i class="fa-solid fa-handshake text-2xl text-blue-600"></i>
      </div>
      <p class="font-bold text-gray-800 text-base">Manage Beneficiary Programs</p>
      <p class="text-gray-400 text-xs mt-2 leading-relaxed">
        Review, approve, and monitor barangay assistance programs
      </p>
    </a>

    <a href="documentRequest.php" class="service-card rounded-2xl p-7 shadow-sm block text-center group" data-aos="fade-up" data-aos-delay="200">
      <div class="w-14 h-14 mx-auto rounded-2xl bg-green-50 group-hover:bg-green-100 flex items-center justify-center transition mb-4">
        <i class="fa-solid fa-file-lines text-2xl text-green-600"></i>
      </div>
      <p class="font-bold text-gray-800 text-base">Manage Document Requests</p>
      <p class="text-gray-400 text-xs mt-2 leading-relaxed">
        Process and approve barangay clearances and certificates
      </p>
    </a>

    <a href="borrowingSystem.php" class="service-card rounded-2xl p-7 shadow-sm block text-center group" data-aos="fade-up" data-aos-delay="300">
      <div class="w-14 h-14 mx-auto rounded-2xl bg-purple-50 group-hover:bg-purple-100 flex items-center justify-center transition mb-4">
        <i class="fa-solid fa-right-left text-2xl text-purple-600"></i>
      </div>
      <p class="font-bold text-gray-800 text-base">Manage Equipment Borrowing</p>
      <p class="text-gray-400 text-xs mt-2 leading-relaxed">
        Approve and monitor barangay equipment borrowing requests
      </p>
    </a>

    <a href="communityListings.php" class="service-card rounded-2xl p-7 shadow-sm block text-center group" data-aos="fade-up" data-aos-delay="400">
      <div class="w-14 h-14 mx-auto rounded-2xl bg-orange-50 group-hover:bg-orange-100 flex items-center justify-center transition mb-4">
        <i class="fa-solid fa-list text-2xl text-orange-500"></i>
      </div>
      <p class="font-bold text-gray-800 text-base">Manage Listings</p>
      <p class="text-gray-400 text-xs mt-2 leading-relaxed">
        Review and manage barangay property and business listings
      </p>
    </a>

    <a href="announcement.php" class="service-card rounded-2xl p-7 shadow-sm block text-center group" data-aos="fade-up" data-aos-delay="500">
      <div class="w-14 h-14 mx-auto rounded-2xl bg-red-50 group-hover:bg-red-100 flex items-center justify-center transition mb-4">
        <i class="fa-solid fa-bullhorn text-2xl text-red-500"></i>
      </div>
      <p class="font-bold text-gray-800 text-base">Manage Announcements</p>
      <p class="text-gray-400 text-xs mt-2 leading-relaxed">
        Post and update barangay announcements and alerts
      </p>
    </a>

    <a href="announcement.php" class="service-card rounded-2xl p-7 shadow-sm block text-center group" data-aos="fade-up" data-aos-delay="600">
      <div class="w-14 h-14 mx-auto rounded-2xl bg-teal-50 group-hover:bg-teal-100 flex items-center justify-center transition mb-4">
        <i class="fa-solid fa-newspaper text-2xl text-teal-600"></i>
      </div>
      <p class="font-bold text-gray-800 text-base">Manage Community Updates</p>
      <p class="text-gray-400 text-xs mt-2 leading-relaxed">
        Publish and monitor barangay news and community updates
      </p>
    </a>

  </div>

  <?php if ($role === 'custom_admin' && empty($myPermissions)): ?>
  <div class="mt-8 p-5 bg-yellow-50 border border-yellow-200 rounded-xl text-center">
    <p class="text-yellow-800 text-sm font-semibold">No sections have been assigned to your account yet.</p>
    <p class="text-yellow-700 text-xs mt-1">Please contact the main administrator for access.</p>
  </div>
  <?php endif; ?>
</section>

  <!-- OUR REACH -->
  <section class="bg-green-950 text-white py-16 px-4 relative overflow-hidden">
    <div class="absolute inset-0 opacity-5" style="background-image: radial-gradient(circle at 1px 1px, white 1px, transparent 0); background-size: 32px 32px;"></div>
    <div class="max-w-6xl mx-auto flex flex-col md:flex-row items-center gap-12 relative z-10">
      <div class="flex-1" data-aos="fade-right">
        <p class="section-label text-green-400 mb-2">Coverage</p>
        <h2 class="text-3xl font-bold mb-4">Our Reach</h2>
        <p class="text-green-200 leading-relaxed mb-4"><?= e($siteSettings['our_reach_content'] ?: ($siteSettings['barangay_name'] . ' residents, or even non-residents, can access barangay services through one online portal.')) ?></p>
        <p class="text-green-300 text-sm leading-relaxed">Discover how we keep the community connected, informed, and better served.</p>
        <div class="mt-6 flex gap-6">
          <div><p class="text-2xl font-bold text-green-300"><?= e($siteSettings['puroks_covered']) ?></p><p class="text-xs text-green-400 uppercase tracking-wider">Puroks Covered</p></div>
          <div class="w-px bg-green-700"></div>
          <div><p class="text-2xl font-bold text-green-300"><?= e($siteSettings['area_served']) ?>km²</p><p class="text-xs text-green-400 uppercase tracking-wider">Area Served</p></div>
        </div>
      </div>
    <div class="flex-1 w-full flex justify-center" data-aos="fade-left">
        <div class="w-full max-w-xl rounded-3xl border border-green-700 shadow-2xl overflow-hidden bg-white">
          <div id="landing-map" class="w-full h-[360px] md:h-[450px]"></div>
        </div>
      </div>
    </div>
  </section>

  <!-- CONSIDERATIONS + PLATFORMS -->
  <section class="max-w-6xl mx-auto px-4 py-16">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-12 items-start">
      <div data-aos="fade-up">
        <p class="section-label mb-2">About the System</p>
        <h2 class="text-3xl font-bold text-green-950 mb-4">Considerations in Barangay Services</h2>
        <p class="text-gray-600 leading-relaxed mb-6">This project introduces a web-based system that helps residents request documents and access assistance programs, barangay officials manage records and equipment efficiently, and local business owners and tenants find services and rentals easily.</p>
        <p class="text-gray-500 text-sm leading-relaxed">By using technology, the barangay ensures transparency, fairness, and better governance - improving service delivery for all community members.</p>
        <div class="mt-8 p-5 bg-green-50 border-l-4 border-green-500 rounded-r-xl">
          <p class="text-green-800 text-sm font-semibold"> Our Mission</p>
          <p class="text-green-700 text-sm mt-1">To make barangay governance accessible, fair, and efficient for every resident of <?= e($siteSettings['barangay_name']) ?>.</p>
        </div>
      </div>
      <div>
        <p class="section-label mb-2">Platforms</p>
        <h2 class="text-3xl font-bold text-green-950 mb-6">Community Service Tools</h2>
        <div class="space-y-3">
          <div class="platform-item bg-white px-5 py-4 rounded-r-xl flex items-center gap-4 shadow-sm" data-aos="fade-up" data-aos-delay="100"><i class="fa-solid fa-users text-green-600 text-lg w-6"></i><span class="text-gray-700 font-medium text-sm">Resident Information System</span></div>
          <div class="platform-item bg-white px-5 py-4 rounded-r-xl flex items-center gap-4 shadow-sm" data-aos="fade-up" data-aos-delay="200"><i class="fa-solid fa-file-alt text-green-600 text-lg w-6"></i><span class="text-gray-700 font-medium text-sm">Document Request System</span></div>
          <div class="platform-item bg-white px-5 py-4 rounded-r-xl flex items-center gap-4 shadow-sm" data-aos="fade-up" data-aos-delay="300"><i class="fa-solid fa-hand-holding-heart text-green-600 text-lg w-6"></i><span class="text-gray-700 font-medium text-sm">Assistance & Beneficiary Management</span></div>
          <div class="platform-item bg-white px-5 py-4 rounded-r-xl flex items-center gap-4 shadow-sm" data-aos="fade-up" data-aos-delay="400"><i class="fa-solid fa-toolbox text-green-600 text-lg w-6"></i><span class="text-gray-700 font-medium text-sm">Equipment Borrowing</span></div>
          <div class="platform-item bg-white px-5 py-4 rounded-r-xl flex items-center gap-4 shadow-sm" data-aos="fade-up" data-aos-delay="500"><i class="fa-solid fa-building text-green-600 text-lg w-6"></i><span class="text-gray-700 font-medium text-sm">Business & Apartment Directory</span></div>
          <div class="platform-item bg-white px-5 py-4 rounded-r-xl flex items-center gap-4 shadow-sm" data-aos="fade-up" data-aos-delay="600"><i class="fa-solid fa-bullhorn text-green-600 text-lg w-6"></i><span class="text-gray-700 font-medium text-sm">Community Announcements</span></div>
        </div>
      </div>
    </div>
  </section>

  <!-- OUR IMPACT -->
  <section class="bg-gradient-to-br py-16 px-4" style="background:var(--site-primary-pale)">
    <div class="max-w-6xl mx-auto">
      <div class="text-center mb-10"><p class="section-label">Results</p><h2 class="text-3xl font-bold text-green-950 mt-2">Our Impact</h2></div>
      <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-4">
        <div class="impact-card bg-white p-6 rounded-2xl shadow-sm text-center cursor-default" data-aos="zoom-in" data-aos-delay="100"><div class="w-12 h-12 mx-auto rounded-2xl bg-yellow-50 flex items-center justify-center mb-3"><i class="fa-solid fa-bolt text-2xl text-yellow-500"></i></div><p class="font-semibold text-gray-800 text-sm">Faster Services</p></div>
        <div class="impact-card bg-white p-6 rounded-2xl shadow-sm text-center cursor-default" data-aos="zoom-in" data-aos-delay="200"><div class="w-12 h-12 mx-auto rounded-2xl bg-blue-50 flex items-center justify-center mb-3"><i class="fa-solid fa-eye text-2xl text-blue-500"></i></div><p class="font-semibold text-gray-800 text-sm">Transparent Assistance</p></div>
        <div class="impact-card bg-white p-6 rounded-2xl shadow-sm text-center cursor-default" data-aos="zoom-in" data-aos-delay="300"><div class="w-12 h-12 mx-auto rounded-2xl bg-green-50 flex items-center justify-center mb-3"><i class="fa-solid fa-database text-2xl text-green-500"></i></div><p class="font-semibold text-gray-800 text-sm">Organized Data</p></div>
        <div class="impact-card bg-white p-6 rounded-2xl shadow-sm text-center cursor-default" data-aos="zoom-in" data-aos-delay="400"><div class="w-12 h-12 mx-auto rounded-2xl bg-purple-50 flex items-center justify-center mb-3"><i class="fa-solid fa-chart-line text-2xl text-purple-500"></i></div><p class="font-semibold text-gray-800 text-sm">Better Decisions</p></div>
        <div class="impact-card bg-white p-6 rounded-2xl shadow-sm text-center cursor-default" data-aos="zoom-in" data-aos-delay="500"><div class="w-12 h-12 mx-auto rounded-2xl bg-pink-50 flex items-center justify-center mb-3"><i class="fa-solid fa-hand-holding-heart text-2xl text-pink-500"></i></div><p class="font-semibold text-gray-800 text-sm">Support Local Programs</p></div>
      </div>
    </div>
  </section>

  <!-- =========================================
       ANNOUNCEMENTS - live from tbl_announcement
       ========================================= -->
  <section id="announcements" class="max-w-6xl mx-auto px-4 py-16" data-aos="fade-up">
    <div class="flex items-center justify-between mb-6">
      <div>
        <p class="section-label">Latest Updates</p>
        <h2 class="text-3xl font-bold text-green-950 mt-1">Announcements</h2>
      </div>
 
    </div>

    <div class="scroll-area space-y-4 h-[420px] overflow-y-auto pr-2">

      <?php if (empty($announcements)): ?>
        <!-- Empty state -->
        <div class="flex flex-col items-center justify-center h-64 text-center">
          <div class="w-16 h-16 rounded-2xl bg-gray-100 flex items-center justify-center mb-4">
            <i class="fa-solid fa-bullhorn text-2xl text-gray-300"></i>
          </div>
          <p class="text-gray-400 font-medium">No announcements yet.</p>
          <p class="text-gray-300 text-sm mt-1">Check back soon for updates.</p>
        </div>

      <?php else: ?>
        <?php foreach ($announcements as $ann):
          $colors    = tagColor($ann['announcementTag'] ?? '');
          $postDate  = !empty($ann['announcementPost'])  ? date('M j, Y', strtotime($ann['announcementPost']))  : null;
          $startDate = !empty($ann['announcementStart']) ? date('M j, Y', strtotime($ann['announcementStart'])) : null;
          $tag       = htmlspecialchars($ann['announcementTag'] ?? '');
          $hasImg    = !empty($ann['announcementImg']);
          $decoded   = json_decode($ann['announcementImg'], true);
          $images    = is_array($decoded) ? $decoded : [$ann['announcementImg']];
          $firstImg  = !empty($images) ? $images[0] : '';
          $hasImg    = !empty($firstImg);
          $detailUrl = '../announcement-page.php?id=' . (int)$ann['announcementID'];
        ?>
        <div class="announcement-card bg-white rounded-2xl p-5 flex gap-5 shadow-sm border border-gray-100">

          <!-- Thumbnail: real image or icon fallback -->
          <div class="w-24 h-24 flex-shrink-0 rounded-xl overflow-hidden flex items-center justify-center <?php echo $hasImg ? '' : $colors['icon_bg']; ?>">
            <?php if ($hasImg): ?>
              <img src="../uploads/announcement/<?php echo htmlspecialchars($firstImg); ?>"
                   alt="<?php echo htmlspecialchars($ann['announcementTitle']); ?>"
                   class="w-full h-full object-cover">
            <?php else: ?>
              <i class="fa-solid <?php echo $colors['icon']; ?> text-2xl <?php echo $colors['icon_color']; ?>"></i>
            <?php endif; ?>
          </div>

          <!-- Body -->
          <div class="flex-1 min-w-0">
            <div class="flex items-start justify-between gap-2">
              <a href="<?php echo $detailUrl; ?>" class="font-bold text-gray-800 hover:text-green-700 transition leading-tight">
                <?php echo htmlspecialchars($ann['announcementTitle']); ?>
              </a>
              <?php if ($tag): ?>
              <span class="text-xs px-2 py-1 rounded-full whitespace-nowrap font-medium flex-shrink-0 <?php echo $colors['bg'] . ' ' . $colors['text']; ?>">
                <?php echo $tag; ?>
              </span>
              <?php endif; ?>
            </div>

            <?php if (!empty($ann['announcementDesc'])): ?>
            <p class="mt-2 text-gray-500 text-sm leading-relaxed"
              style="display:-webkit-box;-webkit-line-clamp:2;line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">
              <?php echo htmlspecialchars($ann['announcementDesc']); ?>
            </p>
            <?php endif; ?>

            <div class="mt-3 flex items-center gap-4 text-xs text-gray-400 flex-wrap">
              <?php if ($postDate): ?>
                <span><i class="fa-regular fa-calendar mr-1"></i>Posted: <?php echo $postDate; ?></span>
              <?php endif; ?>
              <?php if ($startDate): ?>
                <span><i class="fa-solid fa-flag mr-1 text-green-500"></i>Starts: <?php echo $startDate; ?></span>
              <?php endif; ?>
              <a href="<?php echo $detailUrl; ?>" class="text-green-600 font-semibold ml-auto hover:underline">
                Read more →
              </a>
            </div>
          </div>

        </div>
        <?php endforeach; ?>
      <?php endif; ?>

    </div>
  </section>

  <!-- FOOTER -->
  <footer class="bg-green-950 text-white pt-14 pb-6 px-4">
    <div class="max-w-6xl mx-auto">
      <div class="grid grid-cols-1 md:grid-cols-3 gap-10 pb-10 border-b border-green-800">
        <div>
          <div class="flex items-center gap-3 mb-4">
            <div class="w-12 h-12 rounded-full bg-green-700 flex items-center justify-center overflow-hidden">
              <img src="<?= e(site_config_logo_url($siteSettings, '../')) ?>" alt="Logo" class="w-full h-full object-contain" />
            </div>
            <div>
              <h3 class="text-lg font-bold" style="font-family:'DM Sans',sans-serif;"><?= e($siteSettings['site_title']) ?></h3>
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
          <a href="../infoSecurity/dataProtection.php" class="block text-sm text-green-400 hover:text-white transition">Privacy Policy</a>
          <a href="../infoSecurity/terms.php" class="block text-sm text-green-400 hover:text-white transition">Terms of Service</a>
          <a href="../infoSecurity/dataProtection.php" class="block text-sm text-green-400 hover:text-white transition">Data Protection Notice</a>
        </div>
      </div>
        <div>
          <h4 class="font-semibold text-green-300 text-xs uppercase tracking-widest mb-4">Quick Links</h4>
          <div class="space-y-2">
            <a href="adminDashboard.php" class="block text-sm text-green-400 hover:text-white transition">Dashboard</a>
            <a href="../busaptListing.php?type=apartment" class="block text-sm text-green-400 hover:text-white transition">Apartments</a>
            <a href="../busaptListing.php?type=business" class="block text-sm text-green-400 hover:text-white transition">Business Directory</a>
          </div>
        </div>
      </div>
      <div class="text-center mt-6 text-green-500 text-sm">
        © 2026 SumEste Portal. All Rights Reserved. Made with ❤️ for <?= e($siteSettings['barangay_name']) ?>.
      </div>
    </div>
  </footer>

  <script>
    document.addEventListener("DOMContentLoaded", function () {
  const mapAddress = "<?= e($siteSettings['map_query']) ?>";
  const fallbackLat = 15.44915, fallbackLon = 120.94359;

  function initMap(lat, lon, popupHtml) {
    const map = L.map("landing-map", { scrollWheelZoom: false }).setView([lat, lon], 15);
    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
      attribution: "&copy; OpenStreetMap contributors",
      maxZoom: 19
    }).addTo(map);
    L.marker([lat, lon]).addTo(map).bindPopup(popupHtml).openPopup();
    setTimeout(() => map.invalidateSize(), 300);
  }

  const defaultPopup = "<b><?= e($siteSettings['barangay_name']) ?></b><br><?= e($siteSettings['municipality']) ?>";

  if (mapAddress) {
    fetch("https://nominatim.openstreetmap.org/search?format=json&q=" + encodeURIComponent(mapAddress))
      .then(response => response.json())
      .then(data => {
        if (data.length > 0) {
          initMap(parseFloat(data[0].lat), parseFloat(data[0].lon), defaultPopup);
        } else {
          initMap(fallbackLat, fallbackLon, defaultPopup);
        }
      })
      .catch(() => initMap(fallbackLat, fallbackLon, defaultPopup));
  } else {
    initMap(fallbackLat, fallbackLon, defaultPopup);
  }
});

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
      document.getElementById('profile-dropdown').classList.add('hidden');
      document.getElementById('profile-btn').setAttribute('aria-expanded', 'false');
      document.getElementById('profile-chevron').style.transform = '';
    }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      document.getElementById('profile-dropdown').classList.add('hidden');
      document.getElementById('profile-btn').setAttribute('aria-expanded', 'false');
      document.getElementById('profile-chevron').style.transform = '';
    }
  });

  function showPageLoader() {
    const loader = document.getElementById('pageLoader');
    if (!loader) return;
    loader.classList.remove('opacity-0', 'pointer-events-none');
    loader.classList.add('opacity-100');
  }

  function hidePageLoader() {
    const loader = document.getElementById('pageLoader');
    if (!loader) return;
    loader.classList.add('opacity-0', 'pointer-events-none');
    loader.classList.remove('opacity-100');
  }

  // Show loader only for same-tab page navigations.
  document.querySelectorAll('[onclick^="window.location.href"], a[href]').forEach(btn => {
    btn.addEventListener('click', function() {
      if (this.tagName === 'A' && (this.target === '_blank' || this.href.includes('#') || this.href.startsWith('javascript:'))) return;
      showPageLoader();
    });
  });

  // Prevent stuck loader when returning via browser back/forward cache.
  window.addEventListener('pageshow', hidePageLoader);
  window.addEventListener('popstate', hidePageLoader);

  // Mobile Sidebar Logic
  document.addEventListener("DOMContentLoaded", () => {
    hidePageLoader();
    const mobileMenuBtn = document.getElementById("mobile-menu-btn");
    const mobileMenuClose = document.getElementById("mobile-menu-close");
    const mobileSidebar = document.getElementById("mobile-sidebar");
    const mobileSidebarOverlay = document.getElementById("mobile-sidebar-overlay");

    if (mobileMenuBtn && mobileSidebar) {
      function openMobileMenu() {
        mobileSidebarOverlay.classList.remove("hidden");
        setTimeout(() => {
          mobileSidebarOverlay.classList.remove("opacity-0");
          mobileSidebarOverlay.classList.add("opacity-100");
          mobileSidebar.classList.remove("translate-x-full");
        }, 10);
      }

      function closeMobileMenu() {
        mobileSidebar.classList.add("translate-x-full");
        mobileSidebarOverlay.classList.remove("opacity-100");
        mobileSidebarOverlay.classList.add("opacity-0");
        setTimeout(() => {
          mobileSidebarOverlay.classList.add("hidden");
        }, 300);
      }

      mobileMenuBtn.addEventListener("click", openMobileMenu);
      mobileMenuClose.addEventListener("click", closeMobileMenu);
      mobileSidebarOverlay.addEventListener("click", closeMobileMenu);
      
      const mobileNavLinks = mobileSidebar.querySelectorAll('a[href^="#"]');
      mobileNavLinks.forEach(link => {
        link.addEventListener('click', closeMobileMenu);
      });
    }

    const slider = document.getElementById('heroSlider');
    const dots = document.querySelectorAll('#sliderDots .slider-dot');
    const totalSlides = <?= $heroTotalSlides ?>;
    let currentSlide = 0;
    let sliderTimer;

    function goToSlide(index) {
      if (!slider) return;
      currentSlide = (index + totalSlides) % totalSlides;
      slider.style.transform = `translateX(-${currentSlide * (100 / totalSlides)}%)`;
      dots.forEach((dot, dotIndex) => {
        dot.classList.toggle('active', dotIndex === currentSlide);
      });
    }

    function startAutoSlide() {
      if (totalSlides <= 1) return;
      clearInterval(sliderTimer);
      sliderTimer = setInterval(() => goToSlide(currentSlide + 1), 5000);
    }

    dots.forEach((dot) => {
      dot.addEventListener('click', () => {
        goToSlide(parseInt(dot.dataset.index, 10));
        startAutoSlide();
      });
    });

    goToSlide(0);
    startAutoSlide();
  });
  </script>
  <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
  <script>AOS.init({ once: true, offset: 100, duration: 600 });</script>
</body>
</html>