<?php

include "config/db_connection.php";
session_start();

require_once __DIR__ . '/includes/site_config.php';
$siteSettings = site_config_load($conn);
$heroImages   = site_config_hero_images($conn);
$heroTotalSlides = 1 + count($heroImages);
// If user is logged in, redirect based on role
if (isset($_SESSION['user_id'])) {
  $role = $_SESSION['account_role'] ?? '';
  if ($role === 'admin' || $role === 'custom_admin' || !empty($_SESSION['staff_permissions'])) {
    header('Location: admin/adminLanding.php');
    exit;
  }
  switch ($role) {
    case 'admin':
      header('Location: admin/adminLanding.php');
      break;
    case 'resident':
    case 'resident,business/apartment owner':
      header('Location: resident/residentLanding.php');
      break;
    case 'non-resident':
    case 'non-resident,business/apartment owner':
    case 'business':
      header('Location: nonResident/nonresidentLanding.php');
      break;
    default:
      break;
  }
  exit;
}

$logged_in = isset($_SESSION['user_id']);

// Profile dropdown variables
$role           = $_SESSION['account_role'] ?? '';
$userEmail      = $_SESSION['user_id']      ?? '';
$accId          = $_SESSION['acc_id']       ?? '';

$roleLabel = match($role) {
  'admin'        => 'Admin',
  'resident'     => 'Resident',
  'non-resident' => 'Non-Resident',
  default        => 'User',
};

$roleBadgeClass = match($role) {
  'admin'        => 'bg-purple-100 text-purple-700 border border-purple-200',
  'resident'     => 'bg-green-100 text-green-700 border border-green-200',
  'non-resident' => 'bg-blue-100 text-blue-700 border border-blue-200',
  default        => 'bg-gray-100 text-gray-600 border border-gray-200',
};

$initials = strtoupper(substr($userEmail, 0, 2));

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
    'health'     => ['bg'=>'bg-green-100',  'text'=>'text-green-700',  'icon'=>'fa-heartbeat',           'icon_bg'=>'bg-green-100',  'icon_color'=>'text-green-600'],
    'assistance' => ['bg'=>'bg-blue-100',   'text'=>'text-blue-700',   'icon'=>'fa-hand-holding-heart',  'icon_bg'=>'bg-blue-50',    'icon_color'=>'text-blue-500'],
    'community'  => ['bg'=>'bg-orange-100', 'text'=>'text-orange-700', 'icon'=>'fa-people-group',        'icon_bg'=>'bg-orange-50',  'icon_color'=>'text-orange-500'],
    'education'  => ['bg'=>'bg-purple-100', 'text'=>'text-purple-700', 'icon'=>'fa-graduation-cap',      'icon_bg'=>'bg-purple-50',  'icon_color'=>'text-purple-500'],
    'safety'     => ['bg'=>'bg-red-100',    'text'=>'text-red-700',    'icon'=>'fa-shield-halved',       'icon_bg'=>'bg-red-50',     'icon_color'=>'text-red-500'],
    'event'      => ['bg'=>'bg-yellow-100', 'text'=>'text-yellow-700', 'icon'=>'fa-calendar-star',       'icon_bg'=>'bg-yellow-50',  'icon_color'=>'text-yellow-500'],
    default      => ['bg'=>'bg-gray-100',   'text'=>'text-gray-600',   'icon'=>'fa-bullhorn',            'icon_bg'=>'bg-gray-100',   'icon_color'=>'text-gray-500'],
  };
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="assets/responsive-global.css">
  <title><?= e($siteSettings['site_title']) ?></title> 
  <link rel="icon" href="<?= e(site_config_logo_url($siteSettings)) ?>" type="image/png">
  <script src="https://cdn.tailwindcss.com/3.4.16"></script>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <?= site_config_css_vars($siteSettings) ?>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="/tailwind/input.css">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <style>
    body { font-family: 'DM Sans', sans-serif; background: var(--site-primary-pale); margin: 0; }
    h1, h2 { font-family: 'Playfair Display', serif; }
    /* ?? Hero Slider ?? */
    .hero-slider-section {
      position: relative;
      overflow: hidden;
      min-height: 480px;
    }
    @media (max-width: 768px) {
      .hero-slider-section { min-height: 320px; }
    }

    /* Track that holds ALL slides side-by-side */
    .hero-slider {
      position: absolute;
      top: 0; left: 0;
      /* slide 0 = green hero; slides 1-3 = photos ? total 4 */
      width: 400%;
      height: 100%;
      display: flex;
      transition: transform 1s cubic-bezier(.77,0,.18,1);
      z-index: 0;
    }

    /* Each slide takes exactly 1 viewport width */
    .hero-slide {
      flex: 0 0 25%; /* 100% / 4 slides */
      height: 100%;
      position: relative;
    }

    /* Slide 0 - green gradient hero (matches residentLanding design) */
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
    /* Large decorative circle - top-right */
    .hero-slide-green::after {
      content: '';
      position: absolute;
      top: -80px; right: -80px;
      width: 420px; height: 420px;
      border-radius: 50%;
      border: 70px solid rgba(134,239,172,0.07);
      pointer-events: none;
    }
    /* Second decorative circle - bottom-left */
    .hero-slide-green .circle-bl {
      position: absolute;
      bottom: -60px; left: -60px;
      width: 300px; height: 300px;
      border-radius: 50%;
      border: 50px solid rgba(134,239,172,0.05);
      pointer-events: none;
    }

    /* Slides 1-3 - photo backgrounds */
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

    /* Grain texture (all slides) */
    .grain {
      position: absolute;
      inset: 0;
      background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E");
      opacity: 0.4;
      pointer-events: none;
      z-index: 2;
    }

    /* Nav */
    .nav-link { position: relative; transition: color 0.2s; }
    .nav-link::after { content: ''; position: absolute; bottom: -2px; left: 0; width: 0; height: 2px; background: var(--site-primary); transition: width 0.3s ease; }
    .nav-link:hover::after { width: 100%; }
    .nav-link:hover { color: var(--site-primary-dark); }

    /* Cards */
    .service-card { transition: transform 0.25s ease, box-shadow 0.25s ease; background: white; border-bottom: 3px solid transparent; }
.service-card:hover { box-shadow: 0 20px 40px rgba(var(--site-primary-rgb),0.12); }
    .impact-card { transition: transform 0.25s ease, background 0.25s ease; }
 .impact-card:hover { background: var(--site-primary-darker); }

    .announcement-card { transition: background 0.2s; }
.announcement-card:hover, .platform-item:hover { background: var(--site-primary-pale); }
.platform-item { border-left: 3px solid var(--site-primary); }

    /* Scrollbar */
    .scroll-area::-webkit-scrollbar { width: 6px; }
    .scroll-area::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 6px; }
    .scroll-area::-webkit-scrollbar-thumb { background: #86efac; border-radius: 6px; }

    /* Animations */
    @keyframes fadeUp { from { opacity: 0; transform: translateY(24px); } to { opacity: 1; transform: translateY(0); } }
    .fade-up { animation: fadeUp 0.7s ease both; }
    .fade-up-1 { animation-delay: 0.1s; }
    .fade-up-2 { animation-delay: 0.25s; }

    /* Badge / labels */
.badge { display: inline-block; background: color-mix(in srgb, var(--site-primary-light) 15%, transparent); color: var(--site-primary-light); border: 1px solid color-mix(in srgb, var(--site-primary-light) 30%, transparent); font-size: 0.7rem; letter-spacing: 0.12em; text-transform: uppercase; padding: 3px 12px; border-radius: 999px; }.section-label { color: var(--site-primary); }
      .text-green-400 { color: var(--site-primary-light) !important; }
.text-green-200 { color: color-mix(in srgb, var(--site-primary-light) 60%, white) !important; }
.text-green-100 { color: color-mix(in srgb, var(--site-primary-light) 40%, white) !important; }
.bg-green-500 { background-color: var(--site-primary) !important; }
.hover\:bg-green-400:hover { background-color: var(--site-primary-light) !important; }
.bg-green-800\/80 { background-color: color-mix(in srgb, var(--site-primary-dark) 80%, transparent) !important; }
.border-green-400\/30 { border-color: color-mix(in srgb, var(--site-primary-light) 30%, transparent) !important; }
.border-green-400\/50 { border-color: color-mix(in srgb, var(--site-primary-light) 50%, transparent) !important; }
    /* Slider dots */
     /* Slider dots */
    .slider-dots { position: absolute; bottom: 80px; left: 50%; transform: translateX(-50%); display: flex; gap: 8px; z-index: 20; }
    .slider-dot { width: 8px; height: 8px; border-radius: 50%; background: rgba(255,255,255,0.4); border: none; cursor: pointer; transition: background 0.3s, transform 0.3s; padding: 0; }
    .slider-dot.active { background: var(--site-primary-light); transform: scale(1.3); }

    /* Tailwind-green ? theme color overrides */
  .bg-green-700 { background-color: var(--site-primary) !important; }
.hover\:bg-green-800:hover { background-color: var(--site-primary-dark) !important; }
.text-green-700, .text-green-600, .text-green-500 { color: var(--site-primary) !important; }
.text-green-900, .text-green-950 { color: var(--site-primary-darker) !important; }
.bg-green-950 { background-color: var(--site-primary-darker) !important; }
.border-green-100 { border-color: color-mix(in srgb, var(--site-primary) 20%, white) !important; }

/* Hover states (mobile nav, profile dropdown, etc.) - same classes as above,
   but Tailwind's hover: variant needs its own selector to be overridden */
.hover\:bg-green-50:hover { background-color: var(--site-primary-pale) !important; }
.hover\:text-green-700:hover { color: var(--site-primary) !important; }
.hover\:text-green-800:hover { color: var(--site-primary-darker) !important; }
.hover\:border-green-300:hover { border-color: var(--site-primary-light) !important; }
.focus\:ring-green-400:focus { --tw-ring-color: var(--site-primary-light) !important; }

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
.text-green-700 { color: var(--site-primary-dark) !important; }

/* "Our Reach" dark section (on --site-primary-darker bg) */
.text-green-300 { color: color-mix(in srgb, var(--site-primary-light) 70%, white) !important; }
.text-green-200 { color: color-mix(in srgb, var(--site-primary-light) 50%, white) !important; }
.bg-green-700 { background-color: var(--site-primary) !important; }
.w-px.bg-green-700 { background-color: color-mix(in srgb, var(--site-primary-light) 40%, transparent) !important; }
  </style> 
  <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
</head>
<body class="bg-gray-50">

  <!-- NAVBAR -->
  <header class="w-full h-[68px] border-b border-green-100 flex items-center px-8 bg-white shadow-sm sticky top-0 z-50">
    <div class="flex items-center gap-3">
      <a href="landing" class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-full bg-green-700 flex items-center justify-center shadow overflow-hidden">
          <img src="<?= e(site_config_logo_url($siteSettings, '')) ?>" alt="Logo" class="w-full h-full object-contain" />
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
        <a href="#announcements" class="nav-link">Announcements</a>
        <a href="busaptListing.php?type=business"   class="nav-link">Business</a>
        <a href="busaptListing.php?type=apartment"  class="nav-link">Apartment</a>

        <?php if (!$logged_in): ?>
          <a href="login" class="px-5 py-2 bg-green-700 hover:bg-green-800 text-white rounded-lg transition text-sm font-semibold shadow">
            Login / Register
          </a>
        <?php else: ?>
          <div class="relative" id="profile-menu-wrapper">
            <button id="profile-btn" onclick="toggleProfileMenu()"
              class="flex items-center gap-2 px-3 py-1.5 rounded-xl border border-gray-200 hover:border-green-300 hover:bg-green-50 transition focus:outline-none focus:ring-2 focus:ring-green-400"
              aria-haspopup="true" aria-expanded="false">
              <span class="w-8 h-8 rounded-full bg-green-700 text-white flex items-center justify-center text-xs font-bold select-none">
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
              <div class="px-4 py-3 bg-gradient-to-br from-green-50 to-emerald-50 border-b border-gray-100">
                <div class="flex items-center gap-3">
                  <span class="w-10 h-10 rounded-full bg-green-700 text-white flex items-center justify-center text-sm font-bold select-none flex-shrink-0">
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
              <div class="py-1">
                <a href="profile" role="menuitem" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-green-50 hover:text-green-800 transition">
                  <i class="fa-solid fa-user w-4 text-gray-400"></i> My Profile
                </a>
                <?php if ($role === 'admin'): ?>
                <a href="settings.php" role="menuitem" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-green-50 hover:text-green-800 transition">
                  <i class="fa-solid fa-gear w-4 text-gray-400"></i> Settings
                </a>
                <?php endif; ?>
                <?php if ($role === 'admin'): ?>
                <a href="admin/dashboard" role="menuitem" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-purple-50 hover:text-purple-800 transition">
                  <i class="fa-solid fa-shield-halved w-4 text-gray-400"></i> Admin Panel
                </a>
                <?php endif; ?>
              </div>
              <div class="border-t border-gray-100 py-1">
                <a href="logout" role="menuitem" class="flex items-center gap-3 px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition">
                  <i class="fa-solid fa-arrow-right-from-bracket w-4"></i> Logout
                </a>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <!-- Mobile Menu Button -->
      <button id="mobile-menu-btn" class="md:hidden flex items-center justify-center p-2 text-gray-600 hover:text-green-700 hover:bg-green-50 rounded-lg transition" aria-label="Toggle menu">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
        </svg>
      </button>
    </nav>
  </header>

  <!-- Mobile Sidebar & Overlay -->
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
        <a href="#announcements"  class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 font-medium hover:bg-green-50 hover:text-green-700 transition"><i class="fa-solid fa-bullhorn w-4 text-green-500"></i>Announcements</a>
        <a href="busaptListing.php?type=business"  class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 font-medium hover:bg-green-50 hover:text-green-700 transition"><i class="fa-solid fa-store w-4 text-green-500"></i>Business</a>
        <a href="busaptListing.php?type=apartment"  class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 font-medium hover:bg-green-50 hover:text-green-700 transition"><i class="fa-solid fa-building w-4 text-green-500"></i>Apartment</a>
        <div class="h-px bg-gray-100 my-2"></div>
        <?php if (!$logged_in): ?>
          <a href="login" class="mt-2 block w-full text-center px-5 py-3 bg-green-700 hover:bg-green-800 text-white rounded-xl transition font-semibold shadow">
            Login / Register
          </a>
        <?php else: ?>
          <div class="bg-gray-50 rounded-xl p-4 mb-2 border border-gray-100">
            <div class="flex items-center gap-3 mb-3">
              <span class="w-10 h-10 rounded-full bg-green-700 text-white flex items-center justify-center text-sm font-bold select-none flex-shrink-0">
                <?php echo htmlspecialchars($initials); ?>
              </span>
              <div class="min-w-0">
                <p class="text-sm font-semibold text-gray-800 truncate"><?php echo htmlspecialchars($userEmail); ?></p>
                <span class="inline-block mt-0.5 px-2 py-0.5 rounded-full text-[11px] font-semibold <?php echo $roleBadgeClass; ?>">
                  <?php echo htmlspecialchars($roleLabel); ?>
                </span>
              </div>
            </div>
            <div class="flex flex-col gap-1">
              <a href="profile" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm text-gray-700 hover:bg-white hover:text-green-800 transition">
                <i class="fa-solid fa-user w-4 text-gray-400 text-center"></i> My Profile
              </a>
              <?php if ($role === 'admin'): ?>
              <a href="settings.php" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm text-gray-700 hover:bg-white hover:text-green-800 transition">
                <i class="fa-solid fa-gear w-4 text-gray-400 text-center"></i> Settings
              </a>
              <?php endif; ?>
              <?php if ($role === 'admin'): ?>
              <a href="admin/dashboard" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm text-gray-700 hover:bg-white hover:text-purple-800 transition">
                <i class="fa-solid fa-shield-halved w-4 text-gray-400 text-center"></i> Admin Panel
              </a>
              <?php endif; ?>
            </div>
          </div>
          <a href="logout" class="flex items-center justify-center gap-2 w-full px-4 py-2.5 rounded-xl border border-red-200 text-red-600 hover:bg-red-50 hover:border-red-300 transition font-medium text-sm mt-1">
            <i class="fa-solid fa-arrow-right-from-bracket"></i> Logout
          </a>
        <?php endif; ?>
      </nav>
    </div>
  </div>


  <!-- ??????????????????????????????????????????????
       HERO SECTION - Slide 0: green hero | Slides 1-3: photos
       ?????????????????????????????????????????????? -->
  <section class="hero-slider-section text-white py-20 px-6 relative overflow-hidden" id="hero">

    <!-- ?? Slider track (4 slides: 1 green + 3 photos) ?? -->
   <div class="hero-slider" id="heroSlider" style="width: <?= $heroTotalSlides * 100 ?>%;">

      <!-- SLIDE 0 - Green gradient hero (appears first) -->
      <div class="hero-slide hero-slide-green" style="flex-basis: <?= 100 / $heroTotalSlides ?>%;">
        <div class="circle-bl"></div>
        <div class="grain"></div>
      </div>

      <!-- Uploaded hero photos (from Settings ? Landing Page) -->
      <?php foreach ($heroImages as $img): ?>
      <div class="hero-slide hero-slide-photo" style="flex-basis: <?= 100 / $heroTotalSlides ?>%; background-image: url('uploads/hero/<?= e($img['filename']) ?>');">
        <div class="photo-overlay"></div>
        <div class="grain"></div>
      </div>
      <?php endforeach; ?>

    </div>

    <!-- ?? Hero content (sits above all slides) ?? -->
    <div class="relative max-w-6xl mx-auto flex flex-col md:flex-row items-center gap-12 pt-10 pb-10" style="z-index: 10;">
      <div class="flex-1" data-aos="fade-up">
        <span class="badge mb-4 inline-block">Barangay Integrated System</span>
        <h1 class="text-4xl md:text-5xl font-bold leading-tight mb-4">
          Welcome<?php echo $logged_in ? ', ' . htmlspecialchars($_SESSION['user_name'] ?? $_SESSION['user_id']) : ', Guest'; ?>!<br>
          <span class="text-green-400">How can we</span><br>serve you today?
        </h1>
        <?php if ($logged_in && isset($_SESSION['acc_id'])): ?>
        <div class="mb-4 px-4 py-2 bg-green-800/80 rounded-lg border border-green-400/30 backdrop-blur-sm shadow-lg">
          <p class="text-green-100 text-sm"><span class="font-semibold">Account ID:</span> <?php echo htmlspecialchars($_SESSION['acc_id']); ?></p>
        </div>
        <?php endif; ?>
        <p class="text-gray-200 text-base max-w-md leading-relaxed">
          Access all barangay services online - quickly, transparently, and from anywhere.
        </p>
        <?php if (!$logged_in): ?>
        <div class="mt-6 flex gap-3 flex-wrap">
          <a href="login" class="px-6 py-3 bg-green-500 hover:bg-green-400 text-white rounded-lg font-semibold text-sm transition shadow-lg">Login</a>
          <a href="signup/accountCreation" class="px-6 py-3 bg-black/40 backdrop-blur-sm border border-green-400/50 hover:bg-green-800 text-green-100 rounded-lg font-semibold text-sm transition">Register</a>
        </div>
        <?php endif; ?>
      </div>
      <div class="flex-1 grid grid-cols-2 gap-4 w-full max-w-sm" data-aos="fade-up" data-aos-delay="200">
        <div class="bg-black/40 backdrop-blur-md border border-white/20 rounded-2xl p-5 text-center shadow-lg"><p class="text-3xl font-bold text-green-400"><?= number_format($approvedUsersCount) . '+' ?></p><p class="text-xs text-green-200 mt-1 uppercase tracking-wider">Residents Served</p></div>
        <div class="bg-black/40 backdrop-blur-md border border-white/20 rounded-2xl p-5 text-center shadow-lg"><p class="text-3xl font-bold text-green-400">6</p><p class="text-xs text-green-200 mt-1 uppercase tracking-wider">Digital Services</p></div>
        <div class="bg-black/40 backdrop-blur-md border border-white/20 rounded-2xl p-5 text-center shadow-lg"><p class="text-3xl font-bold text-green-400">24/7</p><p class="text-xs text-green-200 mt-1 uppercase tracking-wider">Online Access</p></div>
        <div class="bg-black/40 backdrop-blur-md border border-white/20 rounded-2xl p-5 text-center shadow-lg"><p class="text-3xl font-bold text-green-400">100%</p><p class="text-xs text-green-200 mt-1 uppercase tracking-wider">Free to Use</p></div>
      </div>
    </div>

    <!-- Slider dots -->
   <div class="slider-dots" id="sliderDots" style="<?= $heroTotalSlides <= 1 ? 'display:none;' : '' ?>">
      <?php for ($i = 0; $i < $heroTotalSlides; $i++): ?>
        <button class="slider-dot<?= $i === 0 ? ' active' : '' ?>" data-index="<?= $i ?>" aria-label="Slide <?= $i + 1 ?>"></button>
      <?php endfor; ?>
    </div>

    <!-- Wave divider -->
    <div class="absolute bottom-0 left-0 right-0 overflow-hidden leading-none z-10">
      <svg viewBox="0 0 1440 60" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg" class="w-full h-[60px]">
        <path d="M0,60 C360,0 1080,60 1440,0 L1440,60 Z" fill="#f9fafb"/>
      </svg>
    </div>

    <script>
      // ?? Hero Slider Logic ??
      // Slide 0 = green bg (shown first); slides 1-3 = photos
      // The slider track is 400% wide; each slide is 25% of that = 1 viewport width.
   (function () {
        const slider  = document.getElementById('heroSlider');
        const dots    = document.querySelectorAll('.slider-dot');
        const TOTAL   = <?= $heroTotalSlides ?>; // 1 green + N uploaded photos
        let current   = 0;
        let timer;

        function goTo(idx) {
          current = (idx + TOTAL) % TOTAL;
          // Each slide's share of the track = 100/TOTAL%
          slider.style.transform = `translateX(-${current * (100 / TOTAL)}%)`;
          dots.forEach((d, i) => d.classList.toggle('active', i === current));
        }

        function startAuto() {
          if (TOTAL <= 1) return; // nothing to rotate
          clearInterval(timer);
          timer = setInterval(() => goTo(current + 1), 5000);
        }

        dots.forEach(dot => {
          dot.addEventListener('click', () => {
            goTo(parseInt(dot.dataset.index));
            startAuto();
          });
        });

        goTo(0);
        startAuto();
      })();
    </script>
  </section>


  <!-- SERVICES GRID -->
  <section class="max-w-6xl mx-auto px-4 py-16">
    <div class="text-center mb-10">
      <p class="section-label">What We Offer</p>
      <h2 class="text-3xl font-bold text-green-950 mt-2">Our Services</h2>
      <p class="text-gray-500 mt-2 text-sm max-w-xl mx-auto">Everything you need from the barangay, now accessible from your device anytime.</p>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-6">
      <a href="login" class="service-card rounded-2xl p-7 shadow-sm block text-center group" data-aos="fade-up" data-aos-delay="100"><div class="w-14 h-14 mx-auto rounded-2xl bg-blue-50 group-hover:bg-blue-100 flex items-center justify-center transition mb-4"><i class="fa-solid fa-handshake text-2xl text-blue-600"></i></div><p class="font-bold text-gray-800 text-base">Barangay Beneficiary List</p><p class="text-gray-400 text-xs mt-2 leading-relaxed">Apply and check status of assistance programs</p></a>
      <a href="login" class="service-card rounded-2xl p-7 shadow-sm block text-center group" data-aos="fade-up" data-aos-delay="200"><div class="w-14 h-14 mx-auto rounded-2xl bg-green-50 group-hover:bg-green-100 flex items-center justify-center transition mb-4"><i class="fa-solid fa-file-lines text-2xl text-green-600"></i></div><p class="font-bold text-gray-800 text-base">Apply / Request Documents</p><p class="text-gray-400 text-xs mt-2 leading-relaxed">Clearances, certificates, and more</p></a>
      <a href="login" class="service-card rounded-2xl p-7 shadow-sm block text-center group" data-aos="fade-up" data-aos-delay="300"><div class="w-14 h-14 mx-auto rounded-2xl bg-purple-50 group-hover:bg-purple-100 flex items-center justify-center transition mb-4"><i class="fa-solid fa-right-left text-2xl text-purple-600"></i></div><p class="font-bold text-gray-800 text-base">Borrow Barangay Equipment</p><p class="text-gray-400 text-xs mt-2 leading-relaxed">Submit borrowing requests online</p></a>
      <a href="login" class="service-card rounded-2xl p-7 shadow-sm block text-center group" data-aos="fade-up" data-aos-delay="400"><div class="w-14 h-14 mx-auto rounded-2xl bg-orange-50 group-hover:bg-orange-100 flex items-center justify-center transition mb-4"><i class="fa-solid fa-list text-2xl text-orange-500"></i></div><p class="font-bold text-gray-800 text-base">Listing Management</p><p class="text-gray-400 text-xs mt-2 leading-relaxed">Post and browse barangay listings</p></a>
      <a href="#announcements" class="service-card rounded-2xl p-7 shadow-sm block text-center group" data-aos="fade-up" data-aos-delay="500"><div class="w-14 h-14 mx-auto rounded-2xl bg-red-50 group-hover:bg-red-100 flex items-center justify-center transition mb-4"><i class="fa-solid fa-bullhorn text-2xl text-red-500"></i></div><p class="font-bold text-gray-800 text-base">View Announcements</p><p class="text-gray-400 text-xs mt-2 leading-relaxed">See the latest barangay news and alerts</p></a>
      <a href="#announcements" class="service-card rounded-2xl p-7 shadow-sm block text-center group" data-aos="fade-up" data-aos-delay="600"><div class="w-14 h-14 mx-auto rounded-2xl bg-teal-50 group-hover:bg-teal-100 flex items-center justify-center transition mb-4"><i class="fa-solid fa-newspaper text-2xl text-teal-600"></i></div><p class="font-bold text-gray-800 text-base">View Updates</p><p class="text-gray-400 text-xs mt-2 leading-relaxed">Stay informed with the latest community updates</p></a>
    </div>
  </section>

  <!-- OUR REACH -->
 <section class="bg-green-950 text-white py-16 px-4 relative overflow-hidden">
    <div class="absolute inset-0 opacity-5"
        style="background-image: radial-gradient(circle at 1px 1px, white 1px, transparent 0); background-size: 32px 32px;">
    </div>

    <div class="max-w-6xl mx-auto flex flex-col md:flex-row items-center gap-12 relative z-10">

        <!-- Left Content -->
        <div class="flex-1" data-aos="fade-right">
            <p class="section-label text-green-400 mb-2">Coverage</p>

            <h2 class="text-3xl font-bold mb-4">Our Reach</h2>

            <p class="text-green-200 leading-relaxed mb-4">
                <?= e($siteSettings['our_reach_content'] ?: 'Sumacab Este residents, or even non-residents, can access barangay services through one online portal.') ?>
            </p>

            <p class="text-green-300 text-sm leading-relaxed">
                Discover how we keep the community connected, informed, and better served.
            </p>

            <div class="mt-6 flex gap-6">

                <div>
                    <p class="text-2xl font-bold text-green-300"><?= e($siteSettings['puroks_covered']) ?></p>
                    <p class="text-xs text-green-400 uppercase tracking-wider">Puroks Covered</p>
                </div>

                <div class="w-px bg-green-700"></div>

                <div>
<p class="text-2xl font-bold text-green-300"><?= e($siteSettings['area_served']) ?>km²</p>                    <p class="text-xs text-green-400 uppercase tracking-wider">Area Served</p>
                </div>

            </div>
        </div>

        <!-- Right Map -->
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
          <p class="text-green-800 text-sm font-semibold">Our Mission</p>
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
  <section class="bg-gradient-to-br py-16 px-4">
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

  <!-- ANNOUNCEMENTS -->
  <section id="announcements" class="max-w-6xl mx-auto px-4 py-16" data-aos="fade-up">
    <div class="flex items-center justify-between mb-6">
      <div>
        <p class="section-label">Latest Updates</p>
        <h2 class="text-3xl font-bold text-green-950 mt-1">Announcements</h2>
      </div>
     
    </div>

    <div class="scroll-area space-y-4 h-[420px] overflow-y-auto pr-2">

      <?php if (empty($announcements)): ?>
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
          $decoded   = json_decode($ann['announcementImg'], true);
          $images    = is_array($decoded) ? $decoded : [$ann['announcementImg']];
          $firstImg  = !empty($images) ? $images[0] : '';
          $hasImg    = !empty($firstImg);
          $detailUrl = 'announcement-page.php?id=' . (int)$ann['announcementID'];
        ?>
        <div class="announcement-card bg-white rounded-2xl p-5 flex gap-5 shadow-sm border border-gray-100">
          <div class="w-24 h-24 flex-shrink-0 rounded-xl overflow-hidden flex items-center justify-center <?php echo $hasImg ? '' : $colors['icon_bg']; ?>">
           <?php if ($hasImg): ?>
            <img src="uploads/announcement/<?php echo htmlspecialchars($firstImg); ?>" 
             alt="<?php echo htmlspecialchars($ann['announcementTitle']); ?>" 
             class="w-full h-full object-cover">
            <?php else: ?>
            <i class="fa-solid <?php echo $colors['icon']; ?> text-2xl <?php echo $colors['icon_color']; ?>"></i>
          <?php endif; ?>
          </div>
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
<a href="<?php echo $detailUrl; ?>" class="text-green-600 font-semibold ml-auto hover:underline">Read more →</a>            </div>
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
              <img src="<?= e(site_config_logo_url($siteSettings, '')) ?>" alt="Logo" class="w-full h-full object-contain" />
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
          <a href="./infoSecurity/dataProtection.php" class="block text-sm text-green-400 hover:text-white transition">Privacy Policy</a>
          <a href="./infoSecurity/terms.php" class="block text-sm text-green-400 hover:text-white transition">Terms of Service</a>
          <a href="./infoSecurity/dataProtection.php" class="block text-sm text-green-400 hover:text-white transition">Data Protection Notice</a>
        </div>
      </div>
        <div>
          <h4 class="font-semibold text-green-300 text-xs uppercase tracking-widest mb-4">Quick Links</h4>
          <div class="space-y-2">
            <a href="./busaptListing.php" class="block text-sm text-green-400 hover:text-white transition">Directory</a>
            <a href="./busaptListing.php?type=apartment" class="block text-sm text-green-400 hover:text-white transition">Apartments</a>
            <a href="./busaptListing.php?type=business" class="block text-sm text-green-400 hover:text-white transition">Business Directory</a>
          </div>
        </div>
      </div>
      <div class="text-center mt-6 text-green-500 text-sm">
&copy; 2026 SumEste Portal. All Rights Reserved. Made for <?= e($siteSettings['barangay_name']) ?>.      </div>
    </div>
  </footer>s
  <script>
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
  </script>

  <script>
  (function () {
    document.querySelectorAll("a[href]").forEach(function (link) {
      const href = link.getAttribute("href");
      if (!href || link.hasAttribute("data-nav")) return;
      const lower = href.toLowerCase();
      if (href.startsWith("#") || lower.startsWith("javascript:") || lower.startsWith("mailto:") || lower.startsWith("tel:")) return;
      link.setAttribute("data-nav", href);
      link.setAttribute("href", "javascript:void(0)");
      link.addEventListener("click", function (e) {
        e.preventDefault();
        const target = link.getAttribute("data-nav");
        if (!target) return;
        if (link.getAttribute("target") === "_blank") {
          window.open(target, "_blank", "noopener");
        } else {
          window.location.href = target;
        }
      });
    });
  
    // Mobile Sidebar Logic
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
        setTimeout(() => { mobileSidebarOverlay.classList.add("hidden"); }, 300);
      }

      mobileMenuBtn.addEventListener("click", openMobileMenu);
      mobileMenuClose.addEventListener("click", closeMobileMenu);
      mobileSidebarOverlay.addEventListener("click", closeMobileMenu);
      
      mobileSidebar.querySelectorAll('a[href^="#"]').forEach(link => {
        link.addEventListener('click', closeMobileMenu);
      });
    }
  })();
  document.addEventListener("DOMContentLoaded", function () {

    // Initialize the map
   const mapAddress = "<?= e($siteSettings['map_query']) ?>";

fetch(
    "https://nominatim.openstreetmap.org/search?format=json&q=" 
    + encodeURIComponent(mapAddress)
)
.then(response => response.json())
.then(data => {

    if(data.length > 0){

        let lat = parseFloat(data[0].lat);
        let lon = parseFloat(data[0].lon);


        const map = L.map("landing-map", {
            scrollWheelZoom:false
        }).setView([lat, lon], 15);


        L.tileLayer(
            "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",
            {
                attribution:"&copy; OpenStreetMap contributors",
                maxZoom:19
            }
        ).addTo(map);


        L.marker([lat, lon])
        .addTo(map)
        .bindPopup(
            "<b><?= e($siteSettings['barangay_name']) ?></b><br><?= e($siteSettings['municipality']) ?>"
        )
        .openPopup();


        setTimeout(()=>{
            map.invalidateSize();
        },300);


    }else{

        console.log("Location not found");

    }

});

    // OpenStreetMap tiles
    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        attribution: "&copy; OpenStreetMap contributors",
        maxZoom: 19
    }).addTo(map);

    // Marker
    L.marker([15.44915, 120.94359])
        .addTo(map)
        .bindPopup("<b>Barangay Sumacab Este</b><br>Cabanatuan City, Nueva Ecija")
        .openPopup();

    // Fix rendering if hidden during page load
    setTimeout(function () {
        map.invalidateSize();
    }, 300);

});
  </script>

  <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
  <script>AOS.init({ once: true, offset: 100, duration: 600 });</script>
</body>
</html>