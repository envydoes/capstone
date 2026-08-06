<?php
  require_once __DIR__ . '/config/db_connection.php';
  session_start();

  require_once __DIR__ . '/includes/site_config.php';
  $siteSettings = site_config_load($conn);

  $logged_in = isset($_SESSION['user_id']);
  $role      = $_SESSION['account_role'] ?? '';
  $userName  = $_SESSION['user_name']    ?? $_SESSION['user_id'] ?? 'User';
  $userEmail = $_SESSION['user_id']      ?? '';
  $accId     = $_SESSION['acc_id']       ?? '';
  $roleLower = strtolower(trim($role));
  

  // �"?�"? Show My Panel only for resident / resident+owner (NOT non-resident) �"?�"?
  $showMyPanel = $logged_in && (
      $roleLower === 'resident' ||
      $roleLower === 'resident,business/apartment owner'
  );
  

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

  $userName = $userEmail; // fallback to email
  $stmt = $conn->prepare('SELECT firstname FROM tbl_userinfo WHERE accID = ? LIMIT 1');
  if ($stmt) {
      $stmt->bind_param('s', $accId);
      $stmt->execute();
      $result = $stmt->get_result();
      $row = $result->fetch_assoc();
      if ($row && !empty($row['firstname'])) {
          $userName = $row['firstname'];
      }
      $stmt->close();
  }
  $initials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $userName), 0, 2));

  // Fetch announcement
  $ann = null;
  $id  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
  if ($id > 0) {
    $stmt = $conn->prepare('SELECT * FROM tbl_announcement WHERE announcementID = ? LIMIT 1');
    if ($stmt) { $stmt->bind_param('i', $id); $stmt->execute(); $ann = $stmt->get_result()->fetch_assoc(); $stmt->close(); }
  }

  function tagBadge(string $tag): string {
    return match(strtolower(trim($tag))) {
      'health' => 'bg-green-100 text-green-700', 'assistance' => 'bg-blue-100 text-blue-700',
      'community' => 'bg-orange-100 text-orange-700', 'education' => 'bg-purple-100 text-purple-700',
      'safety' => 'bg-red-100 text-red-700', 'event' => 'bg-yellow-100 text-yellow-700',
      default => 'bg-gray-100 text-gray-600',
    };
  }
  function tagInfoBox(string $tag): array {
    return match(strtolower(trim($tag))) {
      'health'     => ['box_bg'=>'bg-green-50',  'box_border'=>'border-green-100',  'head'=>'text-green-900',  'val'=>'text-green-700'],
      'assistance' => ['box_bg'=>'bg-blue-50',   'box_border'=>'border-blue-100',   'head'=>'text-blue-900',   'val'=>'text-blue-700'],
      'community'  => ['box_bg'=>'bg-orange-50', 'box_border'=>'border-orange-100', 'head'=>'text-orange-900', 'val'=>'text-orange-700'],
      'education'  => ['box_bg'=>'bg-purple-50', 'box_border'=>'border-purple-100', 'head'=>'text-purple-900', 'val'=>'text-purple-700'],
      'safety'     => ['box_bg'=>'bg-red-50',    'box_border'=>'border-red-100',    'head'=>'text-red-900',    'val'=>'text-red-700'],
      'event'      => ['box_bg'=>'bg-yellow-50', 'box_border'=>'border-yellow-100', 'head'=>'text-yellow-900', 'val'=>'text-yellow-700'],
      default      => ['box_bg'=>'bg-gray-50',   'box_border'=>'border-gray-100',   'head'=>'text-gray-900',   'val'=>'text-gray-600'],
    };
  }

  $pageTitle = $ann ? htmlspecialchars($ann['announcementTitle']) . ' �?" ' . e($siteSettings['site_title']) : 'Announcement �?" ' . e($siteSettings['site_title']);

  $images = [];
  if ($ann && !empty($ann['announcementImg'])) {
    $decoded = json_decode($ann['announcementImg'], true);
    $images = is_array($decoded) ? $decoded : [$ann['announcementImg']];
    $images = array_filter($images);
  }

  $backUrl = 'landing.php#announcements';
  if (!empty($_SERVER['HTTP_REFERER'])) {
    $referer = $_SERVER['HTTP_REFERER'];
    $selfHost = $_SERVER['HTTP_HOST'] ?? '';
    $refHost  = parse_url($referer, PHP_URL_HOST) ?? '';
    if ($refHost === $selfHost && !str_contains(parse_url($referer, PHP_URL_PATH)??'', 'announcement-page.php')) {
      $backUrl = $referer;
    }
  }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="assets/responsive-global.css">
  <title><?php echo $pageTitle; ?></title>
  <link rel="icon" href="<?= e(site_config_logo_url($siteSettings, '')) ?>" type="image/png">
  <?= site_config_css_vars($siteSettings) ?>
  <script src="https://cdn.tailwindcss.com/3.4.16"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body { font-family: 'DM Sans', sans-serif; min-height: 100vh; }
    h1, h2, h3 { font-family: 'Playfair Display', serif; }

    .nav-link { position: relative; transition: color 0.2s; }
    .nav-link::after { content: ''; position: absolute; bottom: -2px; left: 0; width: 0; height: 2px; background: var(--site-primary); transition: width 0.3s ease; }
    .nav-link:hover::after { width: 100%; }
    .nav-link:hover { color: var(--site-primary-dark); }
    .section-label { font-size: 0.75rem; letter-spacing: 0.15em; text-transform: uppercase; color: var(--site-primary); font-weight: 600; }
    .ann-body p + p { margin-top: 1rem; }

    /* �.��.� CAROUSEL �.��.� */
    .carousel-root { position: relative; width: 100%; overflow: hidden; background: #111; }
    .carousel-track { display: flex; transition: transform 0.45s cubic-bezier(0.4,0,0.2,1); }
    .carousel-slide { flex-shrink: 0; width: 100%; }
    .carousel-slide img { width: 100%; height: 320px; object-fit: cover; display: block; }
    .carousel-thumbs { display: flex; gap: 6px; padding: 10px 14px; background: #1a1a1a; overflow-x: auto; }
    .carousel-thumbs::-webkit-scrollbar { height: 3px; }
    .carousel-thumbs::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 4px; }
    .c-thumb { width: 56px; height: 42px; border-radius: 6px; object-fit: cover; cursor: pointer; border: 2px solid transparent; opacity: 0.55; transition: all 0.2s; flex-shrink: 0; }
    .c-thumb.active { border-color: var(--site-primary); opacity: 1; }
    .carousel-btn { position: absolute; top: 50%; transform: translateY(-50%); width: 38px; height: 38px; background: rgba(0,0,0,0.5); border: none; border-radius: 50%; color: #fff; font-size: 0.9rem; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background 0.2s; z-index: 5; backdrop-filter: blur(4px); }
    .carousel-btn:hover { background: rgba(0,0,0,0.8); }
    .carousel-btn.prev { left: 10px; }
    .carousel-btn.next { right: 10px; }
    .carousel-dots { position: absolute; bottom: 10px; left: 50%; transform: translateX(-50%); display: flex; gap: 6px; z-index: 5; }
    .c-dot { width: 7px; height: 7px; border-radius: 50%; background: rgba(255,255,255,0.45); cursor: pointer; transition: all 0.2s; border: none; padding: 0; }
    .c-dot.active { background: var(--site-primary); width: 22px; border-radius: 4px; }
    .carousel-counter { position: absolute; top: 10px; right: 10px; background: rgba(0,0,0,0.55); color: #fff; font-size: 0.72rem; font-weight: 600; padding: 3px 9px; border-radius: 999px; z-index: 5; backdrop-filter: blur(4px); }

    /* Lightbox */
    .lightbox { position: fixed; inset: 0; z-index: 1000; background: rgba(0,0,0,0.9); display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity 0.2s; padding: 16px; }
    .lightbox.open { opacity: 1; pointer-events: auto; }
    .lightbox img { max-width: 90vw; max-height: 90vh; border-radius: 8px; object-fit: contain; }
    .lightbox-close { position: absolute; top: 16px; right: 20px; background: rgba(255,255,255,0.12); border: none; color: #fff; font-size: 1.1rem; width: 36px; height: 36px; border-radius: 10px; cursor: pointer; display: flex; align-items: center; justify-content: center; }
    .lightbox-nav { position: absolute; top: 50%; transform: translateY(-50%); width: 42px; height: 42px; background: rgba(255,255,255,0.12); border: none; border-radius: 50%; color: #fff; font-size: 1rem; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background 0.2s; }
    .lightbox-nav:hover { background: rgba(255,255,255,0.25); }
    .lightbox-nav.prev { left: 16px; }
    .lightbox-nav.next { right: 16px; }

    #mobile-sidebar { overflow-y: auto; }

    @media (max-width: 640px) {
      .carousel-slide img { height: 220px; }
      .c-thumb { width: 44px; height: 34px; }
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

    footer .text-green-300 { color: rgba(255,255,255,0.75) !important; }
    footer .text-green-400 { color: rgba(255,255,255,0.65) !important; }
    footer .text-green-500 { color: rgba(255,255,255,0.45) !important; }
    footer .hover\:text-white:hover { color: #ffffff !important; }
    footer .border-green-800 { border-color: rgba(255,255,255,0.12) !important; }
  </style>
</head>
<body class="bg-gray-50">

<!-- �.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.� HEADER �.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.� -->
<header class="w-full h-[64px] border-b border-green-100 flex items-center px-4 sm:px-6 lg:px-8 bg-white shadow-sm sticky top-0 z-50">
  <div class="flex items-center gap-3 flex-shrink-0">
    <a href="landing.php" class="flex items-center gap-3">
      <div class="w-9 h-9 rounded-xl flex items-center justify-center shadow overflow-hidden flex-shrink-0" style="background: var(--site-primary)">
        <img src="<?= e(site_config_logo_url($siteSettings, '')) ?>" alt="Logo" class="w-full h-full object-contain" />
      </div>
      <div class="sm:block">
        <h3 class="font-bold text-sm leading-tight" style="font-family:'DM Sans',sans-serif;color:var(--site-primary-darker)"><?= e($siteSettings['site_title']) ?></h3>
        <p class="text-[9px] tracking-widest uppercase" style="color:var(--site-primary)"><?= e($siteSettings['barangay_name']) ?></p>
      </div>
    </a>
  </div>

  <nav class="ml-auto flex items-center gap-3 md:gap-6 text-gray-600 text-sm font-medium">
    <div class="hidden md:flex items-center gap-5 lg:gap-7">
      <?php if ($showMyPanel): ?>
        <a href="resident/residentPanel.php" class="nav-link">My Panel</a>
      <?php endif; ?>
      <?php if ($roleLower === 'admin'): ?>
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
        <a href="login.php" class="px-4 py-2 bg-green-700 hover:bg-green-800 text-white rounded-lg transition text-sm font-semibold shadow">Login / Register</a>
      <?php else: ?>
        <div class="relative" id="profile-menu-wrapper">
          <button id="profile-btn" onclick="toggleProfileMenu()"
            class="flex items-center gap-2 px-3 py-1.5 rounded-xl border border-gray-200 hover:border-green-300 hover:bg-green-50 transition focus:outline-none">
            <span class="w-8 h-8 rounded-full bg-green-700 text-white flex items-center justify-center text-xs font-bold select-none"><?= htmlspecialchars($initials) ?></span>
            <span class="hidden lg:block text-gray-700 text-sm max-w-[120px] truncate"><?= htmlspecialchars($userName) ?></span>
            <svg id="profile-chevron" class="w-4 h-4 text-gray-400 transition-transform duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
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
              <?php if (str_contains($roleLower, 'resident')): ?>
                <?php       
                  // Check non-resident first because it contains the word "resident"
                  if (str_contains($roleLower, 'non-resident')) {
                      $profileUrl = '../nonResident/nonResidentProfile';
                  } elseif (str_contains($roleLower, 'resident')) {
                      $profileUrl = '../resident/myProfile';
                  }
                ?>
                <a href="<?= htmlspecialchars($profileUrl) ?>" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-green-50 hover:text-green-800 transition">
                  <i class="fa-solid fa-user w-4 text-gray-400"></i> My Profile
                </a>
              <?php endif; ?>
              <?php if ($roleLower === 'admin'): ?>
              <a href="admin/announcements.php" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-purple-50 transition"><i class="fa-solid fa-shield-halved w-4 text-gray-400"></i> Admin Panel</a>
              <?php endif; ?>
            </div>
            <div class="border-t border-gray-100 py-1">
              <a href="logout.php" class="flex items-center gap-3 px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition"><i class="fa-solid fa-arrow-right-from-bracket w-4"></i> Logout</a>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <button id="mobile-menu-btn" class="md:hidden flex items-center justify-center p-2 text-gray-600 hover:text-green-700 hover:bg-green-50 rounded-lg transition" aria-label="Toggle menu">
      <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
      </svg>
    </button>
  </nav>
</header>

<!-- �.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.� MOBILE SIDEBAR �.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.� -->
<div id="mobile-sidebar-overlay" class="fixed inset-0 bg-black/50 z-[60] hidden opacity-0 transition-opacity duration-300"></div>
<div id="mobile-sidebar" class="fixed inset-y-0 right-0 w-72 max-w-[85vw] bg-white shadow-2xl transform translate-x-full transition-transform duration-300 z-[70] flex flex-col">
  <div class="p-4 border-b border-gray-100 flex items-center justify-between">
    <div class="flex items-center gap-2">
      <?php if ($logged_in): ?>
        <span class="w-8 h-8 rounded-full bg-green-700 text-white flex items-center justify-center text-xs font-bold"><?= htmlspecialchars($initials) ?></span>
        <div>
          <p class="text-sm font-semibold text-gray-800 truncate max-w-[140px]"><?= htmlspecialchars($userEmail) ?></p>
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
      <a href="resident/residentPanel.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 font-medium hover:bg-green-50 hover:text-green-700 transition">
        <i class="fa-solid fa-gauge-high w-4 text-green-600"></i> My Panel
      </a>
    <?php endif; ?>
    <?php if ($roleLower === 'admin'): ?>
      <a href="admin/adminDashboard.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 font-medium hover:bg-purple-50 hover:text-purple-700 transition">
        <i class="fa-solid fa-shield-halved w-4 text-purple-500"></i> Dashboard
      </a>
    <?php endif; ?>
    <a href="landing.php#announcements" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 font-medium hover:bg-green-50 hover:text-green-700 transition">
      <i class="fa-solid fa-bullhorn w-4 text-green-500"></i> Announcements
    </a>
    <a href="busaptListing.php?type=business" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 font-medium hover:bg-green-50 hover:text-green-700 transition">
      <i class="fa-solid fa-store w-4 text-green-500"></i> Business
    </a>
    <a href="busaptListing.php?type=apartment" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 font-medium hover:bg-green-50 hover:text-green-700 transition">
      <i class="fa-solid fa-building w-4 text-green-500"></i> Apartment
    </a>
    <?php if (str_contains($roleLower, 'non-resident,business/apartment owner') || str_contains($roleLower, 'business') && !str_contains($roleLower, 'resident')): ?>
      <a href="nonResident/manageList.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 font-medium hover:bg-[var(--site-primary-pale)] hover:text-[var(--site-primary-dark)] transition">
        <i class="fa-solid fa-plus w-4 text-[var(--site-primary)]"></i> Post Listing
      </a>
    <?php endif; ?>
    <?php if ($logged_in): ?>
    <div class="pt-2 border-t border-gray-100 mt-2 space-y-0.5">
      <a href="<?= htmlspecialchars($profileUrl) ?>" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 font-medium hover:bg-green-50 hover:text-green-700 transition">
        <i class="fa-solid fa-user w-4 text-gray-400"></i> My Profile
      </a>
      <a href="logout.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-red-600 font-medium hover:bg-red-50 transition">
        <i class="fa-solid fa-arrow-right-from-bracket w-4"></i> Logout
      </a>
    </div>
    <?php else: ?>
    <div class="pt-3 px-1">
      <a href="login.php" class="block text-center px-5 py-3 bg-green-700 hover:bg-green-800 text-white rounded-xl font-semibold shadow transition">Login / Register</a>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- �.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.� ANNOUNCEMENT DETAIL �.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.� -->
<section class="max-w-4xl mx-auto px-4 sm:px-6 py-12 sm:py-16">
  <?php if (!$ann): ?>
    <div class="text-center py-24">
      <i class="fa-solid fa-circle-exclamation text-5xl text-gray-300 mb-4"></i>
      <p class="text-gray-500 text-lg font-medium">Announcement not found.</p>
      <a href="<?= htmlspecialchars($backUrl) ?>" class="mt-4 inline-block text-green-700 font-semibold hover:underline">�?� Back to Announcements</a>
    </div>
  <?php else:
    $tag      = $ann['announcementTag'] ?? '';
    $badgeCls = tagBadge($tag);
    $ibox     = tagInfoBox($tag);
    $postDate  = !empty($ann['announcementPost'])  ? date('M j, Y', strtotime($ann['announcementPost']))  : null;
    $startDate = !empty($ann['announcementStart']) ? date('M j, Y', strtotime($ann['announcementStart'])) : null;
    $descHtml    = nl2br(htmlspecialchars($ann['announcementDesc']    ?? ''));
    $detailsHtml = nl2br(htmlspecialchars($ann['announcementDetails'] ?? ''));
  ?>

  <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">

    <!-- Carousel -->
    <?php if (!empty($images)): ?>
    <div class="carousel-root" id="carousel">
      <div class="carousel-track" id="carouselTrack">
        <?php foreach ($images as $i => $img): ?>
        <div class="carousel-slide">
          <img src="uploads/announcement/<?= htmlspecialchars($img) ?>"
               alt="Announcement image <?= $i+1 ?>"
               loading="<?= $i===0?'eager':'lazy' ?>"
               onclick="openLightbox(<?= $i ?>)"
               style="cursor:zoom-in;">
        </div>
        <?php endforeach; ?>
      </div>
      <?php if (count($images) > 1): ?>
      <button class="carousel-btn prev" onclick="carouselPrev()"><i class="fa-solid fa-chevron-left text-sm"></i></button>
      <button class="carousel-btn next" onclick="carouselNext()"><i class="fa-solid fa-chevron-right text-sm"></i></button>
      <div class="carousel-dots" id="carouselDots">
        <?php foreach ($images as $i => $img): ?>
        <button class="c-dot <?= $i===0?'active':'' ?>" onclick="goToSlide(<?= $i ?>)"></button>
        <?php endforeach; ?>
      </div>
      <span class="carousel-counter" id="carouselCounter">1 / <?= count($images) ?></span>
      <?php endif; ?>
    </div>
    <?php if (count($images) > 1): ?>
    <div class="carousel-thumbs" id="carouselThumbs">
      <?php foreach ($images as $i => $img): ?>
      <img class="c-thumb <?= $i===0?'active':'' ?>"
           src="uploads/announcement/<?= htmlspecialchars($img) ?>"
           onclick="goToSlide(<?= $i ?>)" alt="Thumb <?= $i+1 ?>">
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <div class="p-5 sm:p-8 md:p-10">

      <!-- Back + Tag -->
      <div class="flex items-center justify-between gap-3 flex-wrap mb-5">
        <a href="<?= htmlspecialchars($backUrl) ?>" class="text-sm text-green-700 font-semibold hover:underline flex items-center gap-1">
          <i class="fa-solid fa-arrow-left text-xs"></i> Back to Announcements
        </a>
        <?php if ($tag): ?>
        <span class="text-xs px-2 py-1 rounded-full font-medium <?= $badgeCls ?>"><?= htmlspecialchars($tag) ?></span>
        <?php endif; ?>
      </div>

      <h1 class="text-xl sm:text-2xl md:text-3xl font-bold text-gray-900 mb-4 leading-tight">
        <?= htmlspecialchars($ann['announcementTitle']) ?>
      </h1>

      <div class="flex items-center gap-3 sm:gap-4 text-xs text-gray-500 flex-wrap mb-6">
        <?php if ($postDate): ?><span><i class="fa-regular fa-calendar mr-1"></i>Posted: <?= $postDate ?></span><?php endif; ?>
        <?php if ($startDate): ?><span><i class="fa-solid fa-flag mr-1 text-green-500"></i>Starts: <?= $startDate ?></span><?php endif; ?>
        <?php if (!empty($images)): ?><span><i class="fa-regular fa-image mr-1 text-gray-400"></i><?= count($images) ?> photo<?= count($images)>1?'s':'' ?></span><?php endif; ?>
      </div>

      <?php if ($postDate || $startDate): ?>
      <div class="grid grid-cols-1 sm:grid-cols-<?= ($postDate && $startDate) ? '2' : '1' ?> gap-3 <?= $ibox['box_bg'].' border '.$ibox['box_border'] ?> rounded-xl p-4 text-sm mb-6">
        <?php if ($postDate): ?><div><p class="font-semibold <?= $ibox['head'] ?>">Date Posted</p><p class="<?= $ibox['val'] ?>"><?= $postDate ?></p></div><?php endif; ?>
        <?php if ($startDate): ?><div><p class="font-semibold <?= $ibox['head'] ?>">Start Date</p><p class="<?= $ibox['val'] ?>"><?= $startDate ?></p></div><?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if (!empty($ann['announcementDesc'])): ?>
      <div class="ann-body text-gray-700 leading-relaxed text-sm space-y-3 mb-8"><?= $descHtml ?></div>
      <?php endif; ?>

      <?php if (!empty($ann['announcementDetails'])): ?>
      <div class="border-t border-gray-100 pt-8">
        <p class="section-label mb-4">Full Details</p>
        <div class="ann-body text-gray-700 leading-relaxed text-sm space-y-3"><?= $detailsHtml ?></div>
      </div>
      <?php endif; ?>

      <div class="mt-10 pt-6 border-t border-gray-100">
        <a href="<?= htmlspecialchars($backUrl) ?>" class="inline-flex items-center gap-2 text-sm text-green-700 font-semibold hover:underline">
          <i class="fa-solid fa-arrow-left text-xs"></i> Back to Announcements
        </a>
      </div>
    </div>
  </div>
  <?php endif; ?>
</section>

<!-- Lightbox -->
<div class="lightbox" id="lightbox" onclick="closeLightbox()">
  <button class="lightbox-close" onclick="event.stopPropagation(); closeLightbox()"><i class="fa-solid fa-xmark"></i></button>
  <?php if (count($images) > 1): ?>
  <button class="lightbox-nav prev" onclick="event.stopPropagation(); lbPrev()"><i class="fa-solid fa-chevron-left"></i></button>
  <button class="lightbox-nav next" onclick="event.stopPropagation(); lbNext()"><i class="fa-solid fa-chevron-right"></i></button>
  <?php endif; ?>
  <img id="lightboxImg" src="" alt="" onclick="event.stopPropagation()">
</div>

<!-- �.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.� FOOTER �.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.��.� -->
<footer class="bg-green-950 text-white pt-14 pb-6 px-4 sm:px-6">
  <div class="max-w-6xl mx-auto">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-10 pb-10 border-b border-green-800">
      <div>
        <div class="flex items-center gap-3 mb-4">
          <div class="w-12 h-12 rounded-xl bg-green-700 flex items-center justify-center overflow-hidden flex-shrink-0">
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
        <a href="<?= e($siteSettings['facebook_link'] ?: '#') ?>" target="_blank" rel="noopener noreferrer" class="mt-4 inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded-lg text-sm transition"><i class="fab fa-facebook"></i> Facebook Page</a>
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
          <?php else: ?>
            <a href="busaptListing.php" class="block text-sm text-green-400 hover:text-white transition">Directory</a>
          <?php endif; ?>
          <a href="busaptListing.php?type=apartment" class="block text-sm text-green-400 hover:text-white transition">Apartments</a>
          <a href="busaptListing.php?type=business" class="block text-sm text-green-400 hover:text-white transition">Business Directory</a>
        </div>
      </div>
    </div>
    <div class="text-center mt-6 text-green-500 text-sm">© 2026 <?= e($siteSettings['site_title']) ?>. All Rights Reserved. Made with �YO� for <?= e($siteSettings['barangay_name']) ?>.</div>
  </div>
</footer>

<script>
/* �"?�"? Navbar profile dropdown �"?�"? */
function toggleProfileMenu() {
  const d = document.getElementById('profile-dropdown');
  const c = document.getElementById('profile-chevron');
  const open = !d.classList.contains('hidden');
  d.classList.toggle('hidden', open);
  if(c) c.style.transform = open ? '' : 'rotate(180deg)';
}
document.addEventListener('click', function(e) {
  const w = document.getElementById('profile-menu-wrapper');
  if (w && !w.contains(e.target)) {
    document.getElementById('profile-dropdown')?.classList.add('hidden');
    const c = document.getElementById('profile-chevron'); if(c) c.style.transform = '';
  }
});

/* �"?�"? Mobile sidebar �"?�"? */
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

/* �"?�"? Carousel �"?�"? */
<?php $imgCount = count($images); ?>
const TOTAL = <?= $imgCount ?>;
let current = 0;

function updateCarousel(idx) {
  if (idx < 0) idx = TOTAL - 1; if (idx >= TOTAL) idx = 0;
  current = idx;
  const track = document.getElementById('carouselTrack');
  if (track) track.style.transform = `translateX(-${current * 100}%)`;
  document.querySelectorAll('.c-dot').forEach((d,i) => d.classList.toggle('active', i === current));
  const thumbs = document.querySelectorAll('.c-thumb');
  thumbs.forEach((t,i) => { t.classList.toggle('active', i === current); if(i===current) t.scrollIntoView({behavior:'smooth',block:'nearest',inline:'center'}); });
  const ctr = document.getElementById('carouselCounter'); if (ctr) ctr.textContent = `${current + 1} / ${TOTAL}`;
}
function carouselPrev() { updateCarousel(current - 1); }
function carouselNext() { updateCarousel(current + 1); }
function goToSlide(idx)  { updateCarousel(idx); }

document.addEventListener('keydown', function(e) {
  if (document.getElementById('lightbox').classList.contains('open')) return;
  if (e.key === 'ArrowLeft') carouselPrev(); if (e.key === 'ArrowRight') carouselNext();
});
(function() {
  const el = document.getElementById('carousel'); if (!el || TOTAL < 2) return;
  let sx = 0;
  el.addEventListener('touchstart', e => sx = e.touches[0].clientX, {passive:true});
  el.addEventListener('touchend', e => { const dx = e.changedTouches[0].clientX - sx; if (Math.abs(dx) > 40) dx < 0 ? carouselNext() : carouselPrev(); }, {passive:true});
})();
<?php if ($imgCount > 1): ?>
let autoPlay = setInterval(() => carouselNext(), 6000);
const carr = document.getElementById('carousel');
if (carr) {
  carr.addEventListener('mouseenter', () => clearInterval(autoPlay));
  carr.addEventListener('mouseleave', () => { autoPlay = setInterval(() => carouselNext(), 6000); });
}
<?php endif; ?>

/* �"?�"? Lightbox �"?�"? */
const lbImgs = <?= json_encode(array_values(array_map(fn($f) => 'uploads/announcement/'.$f, $images))) ?>;
let lbIdx = 0;
function openLightbox(idx) { lbIdx = idx??current; document.getElementById('lightboxImg').src = lbImgs[lbIdx]||''; document.getElementById('lightbox').classList.add('open'); document.body.style.overflow='hidden'; }
function closeLightbox() { document.getElementById('lightbox').classList.remove('open'); document.body.style.overflow=''; }
function lbPrev() { lbIdx=(lbIdx-1+lbImgs.length)%lbImgs.length; document.getElementById('lightboxImg').src=lbImgs[lbIdx]; }
function lbNext() { lbIdx=(lbIdx+1)%lbImgs.length; document.getElementById('lightboxImg').src=lbImgs[lbIdx]; }
document.addEventListener('keydown', function(e) {
  if (!document.getElementById('lightbox').classList.contains('open')) return;
  if (e.key==='Escape') closeLightbox(); if(e.key==='ArrowLeft') lbPrev(); if(e.key==='ArrowRight') lbNext();
});
</script>
</body>
</html>