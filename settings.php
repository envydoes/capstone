<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$role = $_SESSION['account_role'] ?? '';
if ($role !== 'admin') {
    switch ($role) {
        case 'resident':
        case 'resident,business/apartment owner':
            header('Location: resident/residentLanding.php');
            break;
        case 'non-resident':
        case 'non-resident,business/apartment owner':
            header('Location: nonResident/nonresidentLanding.php');
            break;
        default:
            header('Location: admin/adminLanding.php');
            break;
    }
    exit;
}

require_once __DIR__ . '/config/db_connection.php';
require_once __DIR__ . '/includes/site_config.php';
require_once __DIR__ . '/includes/check_permissions.php';  

$siteSettings  = site_config_load($conn);
$heroImages    = site_config_hero_images($conn);
$heroImageMax  = 5;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Settings - <?= e($siteSettings['site_title']) ?></title>
  <link rel="icon" href="<?= e(site_config_logo_url($siteSettings, '')) ?>" type="image/png">
  <script src="https://cdn.tailwindcss.com/3.4.16"></script>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <?= site_config_css_vars($siteSettings) ?>
  <style>
    * { box-sizing: border-box; }
    body { font-family: 'DM Sans', sans-serif; background: var(--site-primary-pale); margin: 0; }

    /* ?? Sidebar (matches admin dashboard exactly) ?? */
    .sidebar {
      width: 260px; flex-shrink: 0;
      background: linear-gradient(180deg, var(--site-primary-dark) 0%, var(--site-primary-darker) 55%, var(--site-primary) 100%);
      display: flex; flex-direction: column;
      position: sticky; top: 0; height: 100vh; overflow: hidden;
      transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .sidebar.collapsed { width: 0; }
    .sidebar:not(.collapsed) { overflow-y: auto; }
    .sidebar::-webkit-scrollbar { width: 4px; }
    .sidebar::-webkit-scrollbar-thumb { background: rgba(134,239,172,0.2); border-radius: 4px; }
    .sidebar-inner { width: 260px; min-width: 260px; display: flex; flex-direction: column; height: 100%; }
    .sidebar-logo { padding: 20px 18px 16px; border-bottom: 1px solid rgba(134,239,172,0.12); display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
    .section-label { padding:18px 18px 6px; font-size:.62rem; font-weight:700; letter-spacing:.13em; text-transform:uppercase; color:rgba(255,255,255,0.4); white-space:nowrap; }
    .menu-item { display: flex; align-items: center; justify-content: space-between; width: calc(100% - 16px); padding: 10px 14px; margin: 1px 8px; border-radius: 10px; color: rgba(255,255,255,0.72); font-size: 0.84rem; font-weight: 500; text-decoration: none; border: none; background: none; text-align: left; cursor: pointer; transition: background 0.18s, color 0.18s; white-space: nowrap; }
    .menu-item:hover  { background: rgba(255,255,255,0.07); color: #fff; }
    .menu-item.active { background: rgba(var(--site-primary-rgb), 0.18); color: #fff; }
    .menu-left { display: flex; align-items: center; gap: 11px; }
    .menu-item .mi { width: 17px; text-align: center; font-size: 0.85rem; flex-shrink: 0; }
    .active-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--site-primary); filter: brightness(1.4); flex-shrink: 0; }
    .collapse-btn { width: 28px; height: 28px; border-radius: 8px; background: rgba(255,255,255,0.1); border: none; cursor: pointer; color: #fff; font-size: 0.72rem; display: flex; align-items: center; justify-content: center; flex-shrink: 0; transition: background 0.2s; }
    .collapse-btn:hover { background: rgba(255,255,255,0.22); }
    .expand-btn { position: fixed; top: 18px; left: 12px; z-index: 200; width: 36px; height: 36px; border-radius: 10px; background: var(--site-primary-darker); border: 1px solid rgba(134,239,172,0.25); color: #fff; font-size: 0.82rem; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 16px rgba(5,46,22,0.4); opacity: 0; pointer-events: none; transform: translateX(-8px); transition: opacity 0.25s, transform 0.25s, background 0.2s; }
    .expand-btn.visible { opacity: 1; pointer-events: auto; transform: translateX(0); }
    .expand-btn:hover { background: var(--site-primary); }
    .sidebar-bottom { margin-top: auto; flex-shrink: 0; }
    .sidebar-bottom-links { padding: 0 16px 8px; }
    .side-link { display: block; width: 100%; font-size: 0.84rem; padding: 8px 8px; border-radius: 8px; transition: color 0.15s, background 0.15s; text-decoration: none; white-space: nowrap; border: none; background: none; text-align: left; cursor: pointer; }
    .side-link.active { background: rgba(var(--site-primary-rgb), 0.18); color: #fff !important; }

    .topbar { background: #fff; border-bottom: 1px solid #e5e7eb; padding: 14px 24px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
    .topbar-title-block { transition: margin-left 0.25s ease; }
    body.sidebar-collapsed .topbar-title-block { margin-left: 46px; }

    /* ?? Tabs ?? */
    .settings-tabs { display: flex; gap: 28px; border-bottom: 1px solid #e5e7eb; padding: 0 24px; background: #fff; }
    .settings-tab { display: flex; align-items: center; gap: 8px; padding: 16px 2px; font-size: 0.92rem; font-weight: 700; color: #9ca3af; cursor: pointer; border: none; background: none; border-bottom: 2px solid transparent; transition: color 0.15s, border-color 0.15s; }
    .settings-tab.active { color: var(--site-primary); border-bottom-color: var(--site-primary); }
    .settings-tab:not(.active):not(:disabled):hover { color: #4b5563; }
    .settings-tab:disabled { cursor: not-allowed; opacity: 0.5; }

    /* ?? Section card ?? */
    .settings-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 26px 28px; box-shadow: 0 2px 12px rgba(21,128,61,0.05); }
    .settings-card + .settings-card { margin-top: 22px; }
    .settings-card-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 22px; }
    .settings-card-title { font-size: 1.35rem; font-weight: 800; color: #111827; font-family: 'Playfair Display', serif; }
    .settings-card-desc { font-size: 0.83rem; color: #9ca3af; margin-top: 3px; }
    .edit-toggle { display: inline-flex; align-items: center; gap: 6px; font-size: 0.85rem; font-weight: 700; color: var(--site-primary); background: none; border: none; cursor: pointer; padding: 4px 6px; border-radius: 6px; transition: background 0.15s; flex-shrink: 0; white-space: nowrap; }
    .edit-toggle:hover { background: rgba(var(--site-primary-rgb), 0.08); }
    .edit-toggle.editing { color: #dc2626; }

    .field-label { display: block; font-size: 0.86rem; font-weight: 700; color: #374151; margin-bottom: 6px; }
    .field-input { width: 100%; padding: 11px 14px; border: 1.5px solid #e5e7eb; border-radius: 10px; font-size: 0.9rem; color: #374151; background: #fff; font-family: inherit; outline: none; transition: border-color 0.15s, box-shadow 0.15s, background 0.15s; }
    .field-input:focus { border-color: var(--site-primary); box-shadow: 0 0 0 3px rgba(var(--site-primary-rgb),0.1); }
    .field-input:disabled { background: #f9fafb; color: #9ca3af; cursor: not-allowed; }
    .field-input.changed { border-color: #f59e0b; background: #fffbeb; }
    textarea.field-input { resize: vertical; min-height: 96px; }

    .section-divider { border: none; border-top: 1px solid #e5e7eb; margin: 30px 0 0; }

    /* Hero images list */
 .hero-hint-text { font-size: 0.78rem; color: #9ca3af; margin-top: 10px; display: block; }

/* Grid of clickable tiles - same interaction pattern as .logo-upload-zone */
.hero-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(88px, 1fr)); gap: 12px; }
.hero-tile {
  position: relative; aspect-ratio: 1/1; border-radius: 10px; overflow: hidden;
  background: #f9fafb; border: 1.5px solid #e5e7eb; cursor: pointer;
}
.hero-tile img { width: 100%; height: 100%; object-fit: cover; display: block; }
.hero-tile-overlay {
  position: absolute; inset: 0; background: rgba(0,0,0,0.45); display: flex;
  align-items: center; justify-content: center; opacity: 0; transition: opacity 0.15s; color: #fff; font-size: 0.85rem;
}
.hero-tile:hover .hero-tile-overlay { opacity: 1; }
.hero-tile-remove {
  position: absolute; top: 5px; right: 5px; width: 22px; height: 22px; border-radius: 6px;
  background: rgba(220,38,38,0.9); color: #fff; border: none; cursor: pointer; font-size: 0.68rem;
  display: flex; align-items: center; justify-content: center; z-index: 2;
  opacity: 0; transition: opacity 0.15s;
}
.hero-tile:hover .hero-tile-remove { opacity: 1; }
.hero-tile-uploading { opacity: 0.5; pointer-events: none; }

/* Add-new tile - visually identical to .logo-upload-zone */
.hero-add-tile {
  aspect-ratio: 1/1; border: 1.5px dashed #d1d5db; border-radius: 10px; display: flex;
  align-items: center; justify-content: center; color: #d1d5db; font-size: 1.4rem;
  background: #f9fafb; cursor: pointer; transition: border-color 0.15s, color 0.15s;
}
.hero-add-tile:hover { border-color: var(--site-primary); color: var(--site-primary); }
.hero-add-tile.disabled { cursor: not-allowed; opacity: 0.5; pointer-events: none; }

    /* Preview panels */
    .preview-label { font-size: 0.86rem; font-weight: 700; color: #374151; margin-bottom: 6px; display: block; }
    .preview-box { border: 1.5px solid #e5e7eb; border-radius: 12px; overflow: hidden; background: #f9fafb; }
    .hero-preview-img-wrap { position: relative; aspect-ratio: 16/9; background: #e5e7eb; display: flex; align-items: center; justify-content: center; }
    .hero-preview-img-wrap img { width: 100%; height: 100%; object-fit: cover; }
    .hero-preview-placeholder { color: #9ca3af; font-size: 0.8rem; text-align: center; }
    .hero-nav-btn { position: absolute; top: 50%; transform: translateY(-50%); width: 30px; height: 30px; border-radius: 50%; background: rgba(255,255,255,0.9); border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #374151; box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
    .hero-nav-btn.prev { left: 8px; }
    .hero-nav-btn.next { right: 8px; }
    .hero-preview-caption { padding: 8px 12px; font-size: 0.78rem; color: #6b7280; background: #fff; border-top: 1px solid #e5e7eb; }

    .reach-preview { padding: 14px; display: flex; gap: 12px; }
    .reach-preview-map { width: 72px; height: 72px; border-radius: 8px; background: #e5e7eb; flex-shrink: 0; display: flex; align-items: center; justify-content: center; color: #9ca3af; }
    .reach-preview-text p { margin: 0; }
    .reach-preview-title { font-size: 0.78rem; font-weight: 700; color: #374151; margin-bottom: 3px; }
    .reach-preview-body { font-size: 0.75rem; color: #9ca3af; line-height: 1.4; }

    .logo-upload-zone { width: 92px; height: 92px; border: 1.5px dashed #d1d5db; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #d1d5db; font-size: 1.6rem; flex-shrink: 0; overflow: hidden; background: #f9fafb; cursor: pointer; transition: opacity 0.15s; }
    .logo-upload-zone img { width: 100%; height: 100%; object-fit: contain; }
    .logo-upload-zone.locked { opacity: 0.55; cursor: not-allowed; pointer-events: none; }

    .upload-btn { display: inline-flex; align-items: center; gap: 7px; padding: 9px 16px; border-radius: 9px; border: 1.5px solid #e5e7eb; background: #fff; color: #374151; font-size: 0.84rem; font-weight: 700; cursor: pointer; transition: border-color 0.15s, color 0.15s, opacity 0.15s; }
    .upload-btn:hover:not(:disabled) { border-color: var(--site-primary); color: var(--site-primary-dark); }
    .upload-btn:disabled { opacity: 0.55; cursor: not-allowed; }

    .hero-add-tile.locked { opacity: 0.55; cursor: not-allowed; pointer-events: none; }

    .site-title-preview { padding: 12px; display: flex; align-items: center; gap: 10px; }
    .site-title-preview img { width: 30px; height: 30px; border-radius: 6px; object-fit: cover; background: #e5e7eb; }
    .site-title-preview span { font-size: 0.86rem; font-weight: 700; color: #374151; }

    .swatch-row { display: flex; gap: 12px; flex-wrap: wrap; padding: 14px; border: 1.5px solid #e5e7eb; border-radius: 12px; }
    .swatch { width: 42px; height: 42px; border-radius: 50%; cursor: pointer; border: 3px solid transparent; transition: transform 0.15s, border-color 0.15s; position: relative; flex-shrink: 0; }
    .swatch:hover { transform: scale(1.08); }
    .swatch.selected { border-color: #111827; }
    .swatch.selected::after { content: '\f00c'; font-family: 'Font Awesome 6 Free'; font-weight: 900; color: #fff; position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; text-shadow: 0 1px 2px rgba(0,0,0,0.4); }
    .swatch-custom { background: conic-gradient(red, yellow, lime, cyan, blue, magenta, red); display: flex; align-items: center; justify-content: center; }
    .swatch-custom input[type="color"] { opacity: 0; position: absolute; inset: 0; width: 100%; height: 100%; cursor: pointer; border: none; }
    .swatch-custom i { color: #fff; font-size: 1rem; text-shadow: 0 1px 3px rgba(0,0,0,0.4); pointer-events: none; }

    .theme-preview { display: flex; height: 92px; }
    .theme-preview-side { width: 40%; padding: 10px; color: #fff; display: flex; flex-direction: column; justify-content: space-between; }
    .theme-preview-side .tp-title { font-size: 0.7rem; font-weight: 700; display: flex; align-items: center; gap: 4px; }
    .theme-preview-side .tp-sub { font-size: 0.62rem; opacity: 0.85; line-height: 1.3; }
    .theme-preview-main { flex: 1; background: #fff; padding: 10px; display: flex; align-items: center; }
    .theme-preview-input { width: 100%; height: 28px; border: 1px solid #e5e7eb; border-radius: 6px; background: #f9fafb; }

    /* Save bar */
.save-bar { position: sticky; bottom: 0; padding: 14px 28px; display: flex; justify-content: flex-end; margin-top: 22px; border-radius: 14px; }
.save-btn { display: inline-flex; align-items: center; gap: 8px; padding: 11px 22px; border-radius: 10px; background: var(--site-primary); color: #fff; font-size: 0.88rem; font-weight: 700; border: none; outline: none; cursor: pointer; transition: background 0.15s; }
.save-btn:hover:not(:disabled) { background: color-mix(in srgb, var(--site-primary) 82%, black); }
.save-btn:disabled { background: #d1d5db; cursor: not-allowed; }

   #alertBanner {
      position: fixed;
      top: 24px;
      right: 24px;
      z-index: 1000;
      max-width: 380px;
      width: calc(100% - 48px);
      display: none;
      opacity: 0;
      transform: translateX(24px);
      transition: opacity 0.25s ease, transform 0.25s ease;
      pointer-events: none;
    }
    #alertBanner.show {
      display: flex;
      opacity: 1;
      transform: translateX(0);
      pointer-events: auto;
    }
    .alert-inner { display: flex; align-items: center; gap: 10px; padding: 14px 16px; font-size: 0.85rem; font-weight: 600; border-radius: 10px; border: 1.5px solid transparent; width: 100%; box-shadow: 0 8px 24px rgba(0,0,0,0.12); }
    .alert-success { background: #f0fdf4; border-color: #bbf7d0; color: #15803d; }
    .alert-error   { background: #fef2f2; border-color: #fecaca; color: #dc2626; }
    .alert-close { margin-left: auto; background: none; border: none; cursor: pointer; opacity: 0.6; color: inherit; flex-shrink: 0; }
    .alert-close:hover { opacity: 1; }

    /* ?? Settings panels (tab content) ?? */
    .settings-panel.hidden { display: none; }

    /* ?? Danger Zone (Security tab) ?? */
    .danger-card { border-color: #fecaca !important; }
    .danger-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 16px; border-radius: 12px; border: 1px solid #fecaca; background: #fef2f2; flex-wrap: wrap; }
    .danger-row-title { font-size: 0.9rem; font-weight: 700; color: #111827; }
    .danger-row-desc { font-size: 0.78rem; color: #6b7280; margin-top: 4px; max-width: 480px; line-height: 1.5; }
    .danger-btn { flex-shrink: 0; display: inline-flex; align-items: center; gap: 7px; padding: 10px 18px; background: #dc2626; color: #fff; border-radius: 9px; font-size: 0.84rem; font-weight: 700; text-decoration: none; border: none; cursor: pointer; transition: background 0.15s; }
    .danger-btn:hover { background: #b91c1c; }

    /* View Transitions: circular color wipe */
    ::view-transition-old(root),
    ::view-transition-new(root) {
      animation: none;
      mix-blend-mode: normal;
    }
    ::view-transition-old(root) { z-index: 1; }
    ::view-transition-new(root) { z-index: 2; }

    @keyframes wipe-in {
      from { clip-path: circle(0% at var(--wipe-x, 50%) var(--wipe-y, 50%)); }
      to   { clip-path: circle(150% at var(--wipe-x, 50%) var(--wipe-y, 50%)); }
    }
    ::view-transition-new(root) {
      animation: wipe-in 0.6s ease-out;
    }
   .map-search-wrap { position: relative; }
.map-suggestions {
  position: absolute; top: calc(100% + 4px); left: 0; right: 0;
  background: #fff; border: 1.5px solid #e5e7eb; border-radius: 10px;
  box-shadow: 0 8px 24px rgba(0,0,0,0.08); max-height: 220px; overflow-y: auto;
  z-index: 30; display: none;
}
.map-suggestions.show { display: block; }
.map-suggestion-item { padding: 10px 14px; font-size: 0.82rem; color: #374151; cursor: pointer; border-bottom: 1px solid #f3f4f6; display:flex; gap:8px; align-items:flex-start; }
.map-suggestion-item:last-child { border-bottom: none; }
.map-suggestion-item:hover { background: var(--site-primary-pale); }
.map-suggestion-item i { color: var(--site-primary); margin-top: 2px; flex-shrink:0; }
.map-suggestion-empty { padding: 12px 14px; font-size: 0.8rem; color: #9ca3af; text-align:center; }

    /* Save confirmation modal */
    .modal-overlay { position: fixed; inset: 0; background: rgba(15,23,42,0.55); z-index: 500; display: flex; align-items: center; justify-content: center; padding: 20px; }
    .modal-card { background: #fff; border-radius: 16px; padding: 28px; max-width: 380px; width: 100%; box-shadow: 0 20px 60px rgba(0,0,0,0.25); text-align: center; }
    .modal-icon { width: 52px; height: 52px; border-radius: 50%; background: #fef3c7; color: #b45309; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; margin: 0 auto 14px; }
    .modal-title { font-size: 1.1rem; font-weight: 800; color: #111827; font-family: 'Playfair Display', serif; margin-bottom: 8px; }
    .modal-desc { font-size: 0.85rem; color: #6b7280; line-height: 1.5; margin-bottom: 20px; }
    .modal-actions { display: flex; gap: 10px; }
    .modal-btn { flex: 1; padding: 10px 16px; border-radius: 9px; font-size: 0.85rem; font-weight: 700; border: none; cursor: pointer; transition: background 0.15s; }
    .modal-btn-cancel { background: #f3f4f6; color: #374151; }
    .modal-btn-cancel:hover { background: #e5e7eb; }
    .modal-btn-confirm { background: var(--site-primary); color: #fff; }
    .modal-btn-confirm:hover { background: color-mix(in srgb, var(--site-primary) 82%, black); }
    .modal-btn:disabled { opacity: 0.6; cursor: not-allowed; }
    .role-option { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border: 1.5px solid #e5e7eb; border-radius: 10px; cursor: pointer; transition: all 0.15s; margin-bottom: 8px; }
.role-option:hover { border-color: var(--site-primary); background: var(--site-primary-pale); }
.role-option.selected { border-color: var(--site-primary); background: var(--site-primary-pale); box-shadow: 0 0 0 3px rgba(var(--site-primary-rgb),0.1); }.role-option input { accent-color: var(--site-primary); }
.role-option-title { font-weight: 700; font-size: 0.86rem; color: #111827; }
.role-option-desc { font-size: 0.74rem; color: #6b7280; margin-top: 1px; }
.perm-toggle-row { display: flex; align-items: center; justify-content: space-between; padding: 10px 12px; border: 1px solid #f3f4f6; border-radius: 9px; margin-bottom: 6px; }
.perm-toggle-row:last-child { margin-bottom: 0; }
.perm-toggle-label { font-size: 0.83rem; font-weight: 600; color: #374151; }
.perm-toggle-track { width: 40px; height: 22px; background: #d1d5db; border-radius: 999px; position: relative; transition: background 0.2s; cursor: pointer; flex-shrink: 0; }
.perm-toggle-track.on { background: var(--site-primary); }
.role-option input { accent-color: var(--site-primary); }
.perm-toggle-thumb { width: 18px; height: 18px; background: #fff; border-radius: 50%; position: absolute; top: 2px; left: 2px; transition: transform 0.2s; box-shadow: 0 1px 4px rgba(0,0,0,0.15); }
.perm-toggle-track.on .perm-toggle-thumb { transform: translateX(18px); }
  </style>
</head>
<body>
  <div id="pageLoader" class="fixed inset-0 bg-green-900/40 backdrop-blur-sm z-[9999] flex flex-col items-center justify-center opacity-0 pointer-events-none transition-opacity duration-300">
    <div class="w-12 h-12 border-4 border-white/20 border-t-green-400 rounded-full animate-spin shadow-lg"></div>
    <p class="text-white font-medium mt-4 tracking-wider text-sm shadow-sm">Loading...</p>
  </div>

  <div id="saveConfirmModal" class="modal-overlay" style="display:none;">
    <div class="modal-card">
      <div class="modal-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
      <h3 class="modal-title">Apply these changes?</h3>
      <p class="modal-desc">This updates settings for the <strong>entire site</strong> - every visitor will see the new information and theme right away. The page will refresh after saving.</p>
      <div class="modal-actions">
        <button type="button" class="modal-btn modal-btn-cancel" onclick="closeSaveConfirmModal()">Cancel</button>
        <button type="button" class="modal-btn modal-btn-confirm" id="modalConfirmBtn" onclick="confirmSave()">Yes, Save &amp; Refresh</button>
      </div>
    </div>
  </div>

  <div class="flex min-h-screen">
    <button class="expand-btn" id="expandBtn" title="Open sidebar"><i class="fa-solid fa-bars"></i></button>

    <aside class="sidebar" id="sidebar">
      <div class="sidebar-inner">
        <div class="sidebar-logo">
          <button type="button" onclick="window.location.href='admin/adminLanding.php'" style="text-decoration:none;color:inherit;border:none;background:none;padding:0;text-align:left;cursor:pointer;">
            <div class="flex items-center gap-2.5">
              <div class="w-10 h-10 rounded-full flex items-center justify-center shadow overflow-hidden" style="background: var(--site-primary);">
                <img src="<?= e(site_config_logo_url($siteSettings, '')) ?>" alt="Logo" class="w-full h-full object-cover" />
              </div>
              <div>
                <p class="text-white font-bold text-sm leading-tight"><?= e($siteSettings['site_title']) ?></p>
                <p class="text-[10px] tracking-widest uppercase" style="color: var(--site-primary-light)">Admin Panel</p>
              </div>
            </div>
          </button>
          <button class="collapse-btn" id="collapseBtn" title="Collapse sidebar"><i class="fa-solid fa-chevron-left"></i></button>
        </div>

        <div class="section-label">Management</div>
        <nav class="space-y-0.5 px-2">
          <button type="button" onclick="window.location.href='admin/adminDashboard.php'" class="menu-item"><div class="menu-left"><i class="fa-solid fa-chart-bar mi"></i>Dashboard</div></button>
          <button type="button" onclick="window.location.href='admin/userManagement.php'" class="menu-item"><div class="menu-left"><i class="fa-solid fa-user mi"></i>User Management</div></button>
          <button type="button" onclick="window.location.href='admin/residentManagement.php'" class="menu-item"><div class="menu-left"><i class="fa-solid fa-house-chimney-user mi"></i>Resident Management</div></button>
          <button type="button" onclick="window.location.href='admin/beneficiaryManagement.php'" class="menu-item"><div class="menu-left"><i class="fa-solid fa-hand-holding-heart mi"></i>Beneficiary Management</div></button>
          <button type="button" onclick="window.location.href='admin/documentRequest.php'" class="menu-item"><div class="menu-left"><i class="fa-regular fa-file-lines mi"></i>Document Request</div></button>
          <button type="button" onclick="window.location.href='admin/borrowingSystem.php'" class="menu-item"><div class="menu-left"><i class="fa-solid fa-hammer mi"></i>Borrowing System</div></button>
        </nav>

        <div class="section-label">Community</div>
        <nav class="space-y-0.5 px-2">
          <button type="button" onclick="window.location.href='admin/communityListings.php'" class="menu-item"><div class="menu-left"><i class="fa-solid fa-building mi"></i>Community Listings</div></button>
          <button type="button" onclick="window.location.href='admin/announcement.php'" class="menu-item"><div class="menu-left"><i class="fa-solid fa-pen-to-square mi"></i>Announcements</div></button>
        </nav>

        <div class="sidebar-bottom">
          <div class="sidebar-bottom-links">
            <button type="button" class="side-link active" style="color:#fff;">Settings</button>
            <div class="h-px bg-white/10 my-1 mx-2"></div>
            <button type="button" onclick="window.location.href='logout.php'" class="side-link text-red-400/70 hover:text-red-300 hover:bg-white/5">Logout</button>
          </div>
        </div>
      </div>
    </aside>

    <main class="flex-1 overflow-x-hidden flex flex-col min-w-0">
      <header class="topbar">
        <div class="topbar-title-block">
          <h2 class="font-bold text-2xl leading-tight" style="font-family:'Playfair Display',serif;color:var(--site-primary-darker)">Settings</h2>
          <p class="text-gray-500 text-sm mt-0.5">Manage site-wide configuration for the whole portal.</p>
        </div>
      </header>

      <div class="settings-tabs">
        <button class="settings-tab" type="button" data-tab="general"><i class="fa-solid fa-gear"></i> General</button>
        <!-- <button class="settings-tab" type="button" disabled title="Coming soon"><i class="fa-solid fa-person-military-pointing"></i> Service</button>
        <button class="settings-tab" type="button" data-tab="security"><i class="fa-solid fa-shield-halved"></i> Security</button> -->
        <button class="settings-tab" type="button" data-tab="permissions"><i class="fa-solid fa-user-shield"></i> Permissions</button>
      </div>

      <div id="alertBanner">
        <div class="alert-inner" id="alertInner">
          <i id="alertIcon" class="fa-solid fa-circle-check"></i>
          <span id="alertText"></span>
          <button class="alert-close" onclick="dismissAlert()"><i class="fa-solid fa-xmark"></i></button>
        </div>
      </div>

      <!-- ??????????????????????? GENERAL PANEL ??????????????????????? -->
      <div class="settings-panel" data-panel="general">
        <form id="settingsForm" class="p-6 flex-1">

          <!-- ??????????? BARANGAY INFORMATION ??????????? -->
          <div class="settings-card" data-section="barangay">
            <div class="settings-card-head">
              <div>
                <p class="settings-card-title">Barangay Information</p>
                <p class="settings-card-desc">Update your barangay's name, logo, contact information, and office details.</p>
              </div>
              <button type="button" class="edit-toggle" data-toggle="barangay"><span>Edit</span> <i class="fa-solid fa-pen"></i></button>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
              <div>
                <label class="field-label">Barangay Name</label>
                <input type="text" class="field-input" name="barangay_name" data-section-field="barangay" disabled maxlength="150" value="<?= e($siteSettings['barangay_name']) ?>">
              </div>
              <div>
                <label class="field-label">Municipality</label>
                <input type="text" class="field-input" name="municipality" data-section-field="barangay" disabled maxlength="150" value="<?= e($siteSettings['municipality']) ?>">
              </div>
              <div>
                <label class="field-label">Contact Number</label>
                <input type="text" class="field-input" name="contact_number" data-section-field="barangay" disabled maxlength="20" value="<?= e($siteSettings['contact_number']) ?>">
              </div>
              <div>
                <label class="field-label">Email Address</label>
                <input type="email" class="field-input" name="email" data-section-field="barangay" disabled maxlength="254" value="<?= e($siteSettings['email']) ?>">
              </div>
              <div class="md:col-span-2">
                <label class="field-label">Facebook Page Link <span class="text-gray-400 font-normal">(Optional)</span></label>
                <input type="url" class="field-input" name="facebook_link" data-section-field="barangay" disabled maxlength="255" value="<?= e($siteSettings['facebook_link']) ?>">
              </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-6">
              <div class="flex gap-4">
                <div class="logo-upload-zone locked" id="barangayLogoZone" onclick="triggerBarangayLogoUpload()">
                  <img id="barangayLogoPreview" src="<?= e(site_config_barangay_logo_url($siteSettings, '')) ?>" alt="">
                </div>
                <div>
                  <p class="field-label" style="margin-bottom:4px;">Barangay Logo</p>
                  <p class="text-xs text-gray-400 mb-2">Square image (1:1 ratio), <strong>PNG</strong> or JPG.</p>
                  <input type="file" id="barangayLogoFileInput" accept="image/png,image/jpeg" style="display:none;" onchange="handleBarangayLogoUpload(this)">
                  <input type="hidden" name="barangay_logo" id="barangayLogoInput" data-section-field="barangay" value="<?= e($siteSettings['barangay_logo'] ?? '') ?>">
                  <button type="button" class="upload-btn" id="barangayLogoUploadBtn" onclick="triggerBarangayLogoUpload()" disabled><i class="fa-solid fa-upload"></i> Upload Photo</button>
                </div>
              </div>
              <div class="flex gap-4">
                <div class="logo-upload-zone locked" id="municipalityLogoZone" onclick="triggerMunicipalityLogoUpload()">
                  <img id="municipalityLogoPreview" src="<?= e(site_config_municipality_logo_url($siteSettings, '')) ?>" alt="">
                </div>
                <div>
                  <p class="field-label" style="margin-bottom:4px;">Municipality Logo</p>
                  <p class="text-xs text-gray-400 mb-2">Square image (1:1 ratio), <strong>PNG</strong> or JPG.</p>
                  <input type="file" id="municipalityLogoFileInput" accept="image/png,image/jpeg" style="display:none;" onchange="handleMunicipalityLogoUpload(this)">
                  <input type="hidden" name="municipality_logo" id="municipalityLogoInput" data-section-field="barangay" value="<?= e($siteSettings['municipality_logo'] ?? '') ?>">
                  <button type="button" class="upload-btn" id="municipalityLogoUploadBtn" onclick="triggerMunicipalityLogoUpload()" disabled><i class="fa-solid fa-upload"></i> Upload Photo</button>
                </div>
              </div>
            </div>
          </div>

          <!-- ??????????? LANDING PAGE ??????????? -->
          <div class="settings-card" data-section="landing">
            <div class="settings-card-head">
              <div>
                <p class="settings-card-title">Landing Page</p>
                <p class="settings-card-desc">Edit homepage sections, banners, and public information displayed on the website.</p>
              </div>
              <button type="button" class="edit-toggle" data-toggle="landing"><span>Edit</span> <i class="fa-solid fa-pen"></i></button>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-6">
              <div>
              <label class="field-label">Hero Images <span id="heroCount" class="text-gray-400 font-normal">(<?= count($heroImages) ?>/<?= $heroImageMax ?>)</span></label>
                <div class="hero-grid" id="heroGrid">
                  <?php foreach ($heroImages as $img): ?>
                    <div class="hero-tile" data-hero-id="<?= (int) $img['id'] ?>">
                      <img src="uploads/hero/<?= e($img['filename']) ?>" alt="">
                      <button type="button" class="hero-tile-remove" onclick="event.stopPropagation(); removeHeroImage(<?= (int) $img['id'] ?>, this)" title="Remove"><i class="fa-solid fa-xmark"></i></button>
                      <div class="hero-tile-overlay"><i class="fa-solid fa-image"></i></div>
                    </div>
                  <?php endforeach; ?>
                  <div class="hero-add-tile locked <?= count($heroImages) >= $heroImageMax ? 'disabled' : '' ?>" id="heroAddTile" onclick="triggerHeroUpload()" title="Add hero image">
                    <i class="fa-solid fa-plus"></i>
                  </div>
                </div>
                <input type="file" id="heroFileInput" accept="image/jpeg,image/png" style="display:none;" onchange="handleHeroUpload(this)">
                <span class="hero-hint-text">Accepted files: JPG, PNG &nbsp;|&nbsp; 1920×1080 &nbsp;|&nbsp; Click a tile's × to remove, or the + tile to add</span>

                <hr class="section-divider">

                <label class="field-label" style="margin-top:22px;">"Our Reach" Content</label>
                <textarea class="field-input" name="our_reach_content" data-section-field="landing" disabled maxlength="1000" oninput="updateReachPreview()" id="reachContentInput"><?= e($siteSettings['our_reach_content']) ?></textarea>

                <div class="grid grid-cols-2 gap-5 mt-5">
                  <div>
                    <label class="field-label">Puroks Covered</label>
                    <input type="number" class="field-input" name="puroks_covered" data-section-field="landing" disabled min="0" max="999" value="<?= (int) $siteSettings['puroks_covered'] ?>">
                  </div>
                  <div>
                    <label class="field-label">Area Served</label>
                    <div class="relative">
                      <input type="number" step="0.01" class="field-input" name="area_served" data-section-field="landing" disabled min="0" style="padding-right:44px;" value="<?= e($siteSettings['area_served']) ?>">
                      <span style="position:absolute;right:14px;top:50%;transform:translateY(-50%);font-size:0.78rem;color:#9ca3af;">km²</span>
                    </div>
                  </div>
                </div>

                <div class="mt-5">
  <label class="field-label">Map Display</label>
  <div class="map-search-wrap">
    <div class="relative">
      <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:0.8rem;"></i>
      <input type="text" class="field-input" name="map_query" data-section-field="landing" disabled maxlength="255"
             style="padding-left:38px;padding-right:34px;" autocomplete="off"
             id="mapQueryInput" oninput="onMapQueryInput(this.value)"
             value="<?= e($siteSettings['map_query']) ?>">
      <span id="mapQuerySpinner" style="display:none;position:absolute;right:14px;top:50%;transform:translateY(-50%);color:#9ca3af;">
        <i class="fa-solid fa-spinner fa-spin text-xs"></i>
      </span>
    </div>
    <div class="map-suggestions" id="mapSuggestions"></div>
  </div>

</div>
              </div>

              <div class="space-y-5">
                <div>
                  <span class="preview-label">Preview</span>
                  <div class="preview-box">
                    <div class="hero-preview-img-wrap" id="heroPreviewWrap">
                      <?php if (!empty($heroImages)): ?>
                        <img id="heroPreviewImg" src="uploads/hero/<?= e($heroImages[0]['filename']) ?>" alt="">
                      <?php else: ?>
                        <span class="hero-preview-placeholder" id="heroPreviewPlaceholder">No hero images yet</span>
                      <?php endif; ?>
                      <button type="button" class="hero-nav-btn prev" onclick="heroPreviewNav(-1)"><i class="fa-solid fa-chevron-left text-xs"></i></button>
                      <button type="button" class="hero-nav-btn next" onclick="heroPreviewNav(1)"><i class="fa-solid fa-chevron-right text-xs"></i></button>
                    </div>
                  </div>
                </div>
                <div>
                  <span class="preview-label">Preview</span>
                  <div class="preview-box reach-preview">
                    <div class="reach-preview-map"><i class="fa-solid fa-map-location-dot"></i></div>
                    <div class="reach-preview-text">
                      <p class="reach-preview-title">Our Reach</p>
                      <p class="reach-preview-body" id="reachPreviewText"><?= e($siteSettings['our_reach_content'] ?: 'Lorem ipsum dolor sit amet, consectetur adipiscing') ?></p>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- ??????????? SITE SETTINGS ??????????? -->
          <div class="settings-card" data-section="site">
            <div class="settings-card-head">
              <div>
                <p class="settings-card-title">Site Settings</p>
                <p class="settings-card-desc">Customize your website's logo, colors, favicon, and overall appearance.</p>
              </div>
              <button type="button" class="edit-toggle" data-toggle="site"><span>Edit</span> <i class="fa-solid fa-pen"></i></button>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-[1fr_280px] gap-6 items-start">
              <div>
                <label class="field-label">Site Title</label>
                <input type="text" class="field-input" name="site_title" data-section-field="site" disabled maxlength="150" oninput="updateSiteTitlePreview()" id="siteTitleInput" value="<?= e($siteSettings['site_title']) ?>">
              </div>
              <div>
                <span class="preview-label">Preview</span>
                <div class="preview-box site-title-preview">
                  <img id="siteTitleLogoPreview" src="<?= e(site_config_logo_url($siteSettings, '')) ?>" alt="">
                  <span id="siteTitlePreviewText"><?= e($siteSettings['site_title']) ?></span>
                </div>
              </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-[1fr_280px] gap-6 items-start mt-6">
              <div class="flex gap-4">
                <div class="logo-upload-zone locked" id="logoUploadZone" onclick="triggerLogoUpload()">
                  <img id="logoZonePreview" src="<?= e(site_config_logo_url($siteSettings, '')) ?>" alt="">
                </div>
                <div>
                  <p class="field-label" style="margin-bottom:4px;">Site Logo</p>
                  <p class="text-xs text-gray-400 mb-2">For best results, please upload a square image (1:1 ratio) in <strong>PNG</strong> format with a transparent background.</p>
                  <input type="file" id="logoFileInput" accept="image/png,image/jpeg" style="display:none;" onchange="handleLogoUpload(this)">
                  <input type="hidden" name="site_logo" id="siteLogoInput" data-section-field="site" value="<?= e($siteSettings['site_logo'] ?? '') ?>">
                  <button type="button" class="upload-btn" id="logoUploadBtn" onclick="triggerLogoUpload()" disabled><i class="fa-solid fa-upload"></i> Upload Photo</button>
                </div>
              </div>
              <div></div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-[1fr_280px] gap-6 items-start mt-6">
              <div>
                <label class="field-label">Color Theme</label>
                <div class="swatch-row" id="swatchRow">
                  <label class="swatch swatch-custom" title="Custom color">
                    <i class="fa-solid fa-eye-dropper"></i>
                    <input type="color" id="customColorInput" onchange="selectColorTheme(this.value, event)">
                  </label>
                  <?php
                  $presets = ['#15803d', '#374151', '#7c3aed', '#0ea5e9', '#a16207'];
                  foreach ($presets as $preset):
                      $isSelected = strtolower($siteSettings['color_theme']) === strtolower($preset);
                  ?>
                  <div class="swatch <?= $isSelected ? 'selected' : '' ?>" style="background: <?= e($preset) ?>;" data-color="<?= e($preset) ?>" onclick="selectColorTheme('<?= e($preset) ?>', event)"></div>
                  <?php endforeach; ?>
                </div>
                <input type="hidden" name="color_theme" id="colorThemeInput" data-section-field="site" value="<?= e($siteSettings['color_theme']) ?>">
              </div>
              <div>
                <span class="preview-label">Preview</span>
                <div class="preview-box">
                  <div class="theme-preview">
                    <div class="theme-preview-side" id="themePreviewSide" style="background: <?= e($siteSettings['color_theme']) ?>;">
                      <p class="tp-title"><i class="fa-regular fa-square"></i> <span id="themePreviewTitle"><?= e($siteSettings['site_title']) ?></span></p>
                      <p class="tp-sub">Welcome Back To Site</p>
                    </div>
                    <div class="theme-preview-main"><div class="theme-preview-input"></div></div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="save-bar">
            <button type="button" class="save-btn" id="saveBtn" disabled onclick="handleSave()">
              <i class="fa-regular fa-floppy-disk"></i> Save Changes
            </button>
          </div>
        </form>
      </div>

  <!-- ??????????????????????? SECURITY PANEL ??????????????????????? -->
      <!-- <div class="settings-panel hidden" data-panel="security">
        <div class="p-6 flex-1">

          <div class="settings-card danger-card">
            <div class="settings-card-head">
              <div>
                <p class="settings-card-title" style="color:#dc2626;">System Reset</p>
                <p class="settings-card-desc">Irreversible actions. Proceed with caution.</p>
              </div>
            </div>

            <div class="danger-row">
              <div>
                <p class="danger-row-title">Reset System Data</p>
                <p class="danger-row-desc">
                  Permanently deletes all accounts (except Admin), residents, announcements, listings,
                  document requests, and equipment records. Resets Site Settings to defaults. Use this
                  before handing the system over to a new barangay.
                </p>
              </div>
              <a href="admin/systemReset.php" class="danger-btn">
                <i class="fa-solid fa-trash"></i> Reset System
              </a>
            </div>
          </div>

        </div>
      </div> -->

      <!-- ??????????????????????? PERMISSIONS PANEL ??????????????????????? -->
      <div class="settings-panel hidden" data-panel="permissions">
        <div class="p-6 flex-1">

          <!-- ??? CREATE STAFF ACCOUNT ??? -->
          <div class="settings-card" style="margin-bottom:22px;">
            <div class="settings-card-head">
              <div>
                <p class="settings-card-title">Add Admin Account</p>
                <p class="settings-card-desc">Set login credentials directly and choose which modules this account can access.</p>
              </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
              <div>
                <label class="field-label">Email Address</label>
                <input type="email" class="field-input" id="staffEmailInput" maxlength="254" placeholder="staff@example.com">
              </div>
              <div>
                <label class="field-label">Password</label>
                <input type="password" class="field-input" id="staffPasswordInput" maxlength="72" placeholder="At least 8 characters">
              </div>
            </div>

            <div style="margin-top:20px;">
              <label class="field-label" style="margin-bottom:8px;">Module Access</label>
              <div id="staffModuleList">
                <?php foreach (PERMISSION_MODULES as $modKey => $modLabel): ?>
                <div class="perm-toggle-row">
                  <span class="perm-toggle-label"><?= e($modLabel) ?></span>
                  <div class="perm-toggle-track" data-perm-key="<?= e($modKey) ?>" onclick="toggleStaffModule(this)"><div class="perm-toggle-thumb"></div></div>
                </div>
                <?php endforeach; ?>
              </div>
            </div>

            <div style="display:flex;justify-content:flex-end;margin-top:20px;">
              <button type="button" class="save-btn" id="createStaffBtn" onclick="handleCreateStaff()">
                <i class="fa-solid fa-user-plus"></i> Create Account &amp; Grant Access
              </button>
            </div>
          </div>

          <!-- ??? MANAGE ADMINISTRATOR ??? -->
          <div class="settings-card">
            <div class="settings-card-head">
              <div>
                <p class="settings-card-title">Manage Administrator</p>
                <p class="settings-card-desc">Everyone who currently has admin module access.</p>
              </div>
              <button type="button" class="upload-btn" onclick="loadStaffList()" title="Refresh list">
                <i class="fa-solid fa-rotate-right"></i> Refresh
              </button>
            </div>

            <div style="border:1px solid #e5e7eb;border-radius:12px;overflow-x:auto;">
              <table style="width:100%;border-collapse:collapse;min-width:600px;">
                <thead>
                  <tr>
                    <th style="text-align:left;padding:10px 16px;font-size:0.72rem;font-weight:700;color:#6b7280;text-transform:uppercase;background:#f9fafb;border-bottom:1px solid #e5e7eb;">User</th>
                    <th style="text-align:left;padding:10px 16px;font-size:0.72rem;font-weight:700;color:#6b7280;text-transform:uppercase;background:#f9fafb;border-bottom:1px solid #e5e7eb;">Modules Granted</th>
                    <th style="text-align:left;padding:10px 16px;font-size:0.72rem;font-weight:700;color:#6b7280;text-transform:uppercase;background:#f9fafb;border-bottom:1px solid #e5e7eb;">Granted By</th>
                    <th style="text-align:right;padding:10px 16px;font-size:0.72rem;font-weight:700;color:#6b7280;text-transform:uppercase;background:#f9fafb;border-bottom:1px solid #e5e7eb;">Action</th>
                  </tr>
                </thead>
                <tbody id="permUserResults">
                  <tr><td colspan="4" style="text-align:center;padding:28px;color:#9ca3af;font-size:0.85rem;">
                    <i class="fa-solid fa-circle-notch fa-spin" style="margin-right:8px;color:var(--site-primary);"></i>Loading administrators.
                  </td></tr>
                </tbody>
              </table>
            </div>
          </div>

        </div>
      </div>

    </main>
  </div>

  <!-- ??????????????????????????????????????
       CHANGE PERMISSION MODAL (founder admin only)
  ?????????????????????????????????????? -->

  <div class="modal-overlay" id="permModalOverlay" style="display:none;align-items:center;justify-content:center;" onclick="closePermModalOnOverlay(event)">
    <div class="modal-card" style="max-width:480px;text-align:left;padding:0;overflow:hidden;">
      <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 18px 12px;border-bottom:1px solid #f3f4f6;">
        <div class="flex items-center gap-3 min-w-0">
          <div style="width:36px;height:36px;background:var(--site-primary-pale);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
  <i class="fa-solid fa-user-shield text-sm" style="color: var(--site-primary-dark);"></i>
</div>
          <div class="min-w-0">
            <p class="font-bold text-gray-900 text-base">Change Permission</p>
            <p class="text-gray-400 text-xs mt-0.5 truncate" id="permModalSubtitle">Update module access</p>
          </div>
        </div>
        <button type="button" onclick="closePermModal()" style="width:28px;height:28px;border-radius:8px;border:none;background:#f3f4f6;cursor:pointer;"><i class="fa-solid fa-xmark"></i></button>
      </div>

      <div style="padding:16px 18px;max-height:60vh;overflow-y:auto;">
        <input type="hidden" id="permUserID">
        <p class="text-xs text-gray-400 mb-3">Toggle modules on or off - takes effect immediately, no email confirmation is sent. Turn every module off to fully revoke access.</p>

        <div id="permModuleList">
          <?php foreach (PERMISSION_MODULES as $modKey => $modLabel): ?>
          <div class="perm-toggle-row">
            <span class="perm-toggle-label"><?= e($modLabel) ?></span>
            <div class="perm-toggle-track" data-perm-key="<?= e($modKey) ?>" onclick="togglePermModule(this)"><div class="perm-toggle-thumb"></div></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="modal-actions" style="padding:14px 18px;margin:0;">
        <button type="button" class="modal-btn modal-btn-cancel" onclick="closePermModal()">Cancel</button>
<button type="button" class="modal-btn modal-btn-confirm" id="permSaveBtn" onclick="handlePermSave()">Save</button>      </div>
    </div>
  </div>

  <!-- ??????????????????????????????????????
       REMOVE ADMIN CONFIRMATION MODAL
  ?????????????????????????????????????? -->
  <div class="modal-overlay" id="removeAdminModalOverlay" style="display:none;align-items:center;justify-content:center;" onclick="closeRemoveAdminModalOnOverlay(event)">
    <div class="modal-card">
      <div class="modal-icon" style="background:#fef2f2;color:#dc2626;"><i class="fa-solid fa-user-xmark"></i></div>
      <h3 class="modal-title">Remove admin access?</h3>
    <p class="modal-desc">
  This will revoke <strong id="removeAdminName"></strong>'s admin module access.
  Their regular account will not be affected - they'll simply lose access to admin features.
</p>
      <div class="modal-actions">
        <button type="button" class="modal-btn modal-btn-cancel" onclick="closeRemoveAdminModal()">Cancel</button>
        <button type="button" class="modal-btn" id="removeAdminConfirmBtn"
          style="background:#dc2626;color:#fff;" onclick="confirmRemoveAdmin()">
          Yes, Remove
        </button>
      </div>
    </div>
  </div>

</div>
  </div>
</div>

  
  </div>
</div>
    </main>
  </div>

  <script>
    /* ??????????????????????????????????????????
   Shared staff defaults (single source of truth -
   remove the duplicate inline STAFF_DEFAULTS consts
   that were inside openPermissionModal() and the
   role-option click handler; they all use this one now)
?????????????????????????????????????????? */
const STAFF_DEFAULTS = {
  barangay_secretary: ['dashboard','manage_residents','manage_beneficiaries','manage_documents','manage_listings','manage_announcements'],
  barangay_treasurer: ['dashboard','manage_documents','manage_borrowing'],
};

/* ??????????????????????????????????????????
   CREATE STAFF ACCOUNT
?????????????????????????????????????????? */
function selectStaffPosition(label) {
  document.querySelectorAll('#staffCreateSection .role-option, .settings-card .role-option[data-role]').forEach(o => {});
  // Scope to this card only (position radios named "staffPosition")
  document.querySelectorAll('label.role-option[data-role]').forEach(opt => {
    if (opt.querySelector('input[name="staffPosition"]')) {
      opt.classList.remove('selected');
      opt.querySelector('input').checked = false;
    }
  });
  label.classList.add('selected');
  label.querySelector('input').checked = true;

  const position = label.dataset.role;
  const moduleList = document.getElementById('staffModuleList');
  moduleList.style.display = 'block';
  moduleList.querySelectorAll('.perm-toggle-track').forEach(track => {
    track.classList.toggle('on', (STAFF_DEFAULTS[position] || []).includes(track.dataset.permKey));
  });
}

function toggleStaffModule(track) {
  track.classList.toggle('on');
}

function handleCreateStaff() {
  const email    = document.getElementById('staffEmailInput').value.trim();
  const password = document.getElementById('staffPasswordInput').value;
  const permissions = Array.from(document.querySelectorAll('#staffModuleList .perm-toggle-track.on')).map(t => t.dataset.permKey);

  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    showToast('error', 'Please enter a valid email address.');
    return;
  }
  if (password.length < 8) {
    showToast('error', 'Password must be at least 8 characters.');
    return;
  }
  if (permissions.length === 0) {
    showToast('error', 'Select at least one module to grant access to.');
    return;
  }

  const btn = document.getElementById('createStaffBtn');
  btn.disabled = true;
  const originalHtml = btn.innerHTML;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';

  const body = new URLSearchParams();
  body.append('email', email);
  body.append('password', password);
  permissions.forEach(p => body.append('permissions[]', p));

  fetch('admin/createStaffAccount.php', { method: 'POST', body })
    .then(r => r.json())
    .then(d => {
      if (d.success) {
        showToast('success', 'Staff account created. They can log in now.');
        document.getElementById('staffEmailInput').value = '';
        document.getElementById('staffPasswordInput').value = '';
        document.querySelectorAll('#staffModuleList .perm-toggle-track').forEach(t => t.classList.remove('on'));
        loadStaffList(); // refresh Manage Existing Staff table
      } else {
        showToast('error', d.message || 'Could not create the account.');
      }
    })
    .catch(() => showToast('error', 'Network error. Please try again.'))
    .finally(() => { btn.disabled = false; btn.innerHTML = originalHtml; });
}
    /* ?? Map Display autocomplete (Nominatim / OpenStreetMap) ?? */
let mapSearchDebounce = null;
let mapSearchAbort = null;

function onMapQueryInput(value) {
  const box = document.getElementById('mapSuggestions');
  const spinner = document.getElementById('mapQuerySpinner');
  clearTimeout(mapSearchDebounce);

  const q = value.trim();
  if (q.length < 3) {
    box.classList.remove('show');
    box.innerHTML = '';
    return;
  }

  // Debounce ~450ms so we don't hammer Nominatim on every keystroke
  mapSearchDebounce = setTimeout(() => {
    spinner.style.display = 'inline-block';
    if (mapSearchAbort) mapSearchAbort.abort();
    mapSearchAbort = new AbortController();

    fetch('https://nominatim.openstreetmap.org/search?format=json&addressdetails=1&limit=6&q=' + encodeURIComponent(q), {
      signal: mapSearchAbort.signal
    })
      .then(r => r.json())
      .then(renderMapSuggestions)
      .catch(err => {
        if (err.name !== 'AbortError') {
          box.innerHTML = '<div class="map-suggestion-empty">Search failed. Try again.</div>';
          box.classList.add('show');
        }
      })
      .finally(() => { spinner.style.display = 'none'; });
  }, 450);
}

function renderMapSuggestions(results) {
  const box = document.getElementById('mapSuggestions');
  if (!results || results.length === 0) {
    box.innerHTML = '<div class="map-suggestion-empty">No matching places found.</div>';
    box.classList.add('show');
    return;
  }
  box.innerHTML = results.map((r, i) => `
    <div class="map-suggestion-item" data-index="${i}">
      <i class="fa-solid fa-location-dot"></i>
      <span>${escHtml(r.display_name)}</span>
    </div>
  `).join('');
  box.classList.add('show');

  box.querySelectorAll('.map-suggestion-item').forEach(item => {
    item.addEventListener('click', () => selectMapSuggestion(results[parseInt(item.dataset.index)]));
  });
}

function selectMapSuggestion(result) {
  const input = document.getElementById('mapQueryInput');
  input.value = result.display_name;
  // Mirror the shared [data-section-field] input listener,
  // without re-dispatching 'input' (which would re-trigger the search).
  input.classList.toggle('changed', input.value !== (originalValues[input.name] ?? ''));
  checkFormChanged();

  document.getElementById('mapSuggestions').classList.remove('show');
  document.getElementById('mapSuggestions').innerHTML = '';
}

// Close the dropdown on outside click
document.addEventListener('click', (e) => {
  const wrap = document.querySelector('.map-search-wrap');
  if (wrap && !wrap.contains(e.target)) {
    document.getElementById('mapSuggestions').classList.remove('show');
  }
});
    const sidebar = document.getElementById('sidebar');
    const collapseBtn = document.getElementById('collapseBtn');
    const expandBtn = document.getElementById('expandBtn');
    let collapsed = localStorage.getItem('sidebarCollapsed') === 'true';
    function applyState() {
      if (collapsed) { sidebar.classList.add('collapsed'); expandBtn.classList.add('visible'); document.body.classList.add('sidebar-collapsed'); }
      else { sidebar.classList.remove('collapsed'); expandBtn.classList.remove('visible'); document.body.classList.remove('sidebar-collapsed'); }
    }
    applyState();
    collapseBtn.addEventListener('click', () => { collapsed = true; localStorage.setItem('sidebarCollapsed', 'true'); applyState(); });
    expandBtn.addEventListener('click', () => { collapsed = false; localStorage.setItem('sidebarCollapsed', 'false'); applyState(); });

    /* ?? Tabs ?? */
    document.querySelectorAll('.settings-tab[data-tab]').forEach(tab => {
      tab.addEventListener('click', () => {
        document.querySelectorAll('.settings-tab[data-tab]').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        const target = tab.dataset.tab;
        document.querySelectorAll('.settings-panel').forEach(p => {
          p.classList.toggle('hidden', p.dataset.panel !== target);
        });
      });
    });
    document.querySelector('.settings-tab[data-tab="general"]').classList.add('active');

    /* ?? Alert banner ?? */
    let alertTimer = null;
    function showToast(type, text) {
      const banner = document.getElementById('alertBanner');
      const inner = document.getElementById('alertInner');
      const icon = document.getElementById('alertIcon');
      inner.className = 'alert-inner ' + (type === 'success' ? 'alert-success' : 'alert-error');
      icon.className = 'fa-solid ' + (type === 'success' ? 'fa-circle-check' : 'fa-circle-xmark');
      document.getElementById('alertText').textContent = text;
      banner.classList.add('show');
      clearTimeout(alertTimer);
      alertTimer = setTimeout(dismissAlert, 4000);
    }
    function dismissAlert() { document.getElementById('alertBanner').classList.remove('show'); }

    /* ?? Per-section Edit toggle ?? */
    const originalValues = {};
    document.querySelectorAll('[data-section-field]').forEach(el => { originalValues[el.name] = el.value; });

    document.querySelectorAll('.edit-toggle').forEach(btn => {
      btn.addEventListener('click', () => {
        const section = btn.dataset.toggle;
        const fields = document.querySelectorAll(`[data-section-field="${section}"]`);
        const nowEditing = !btn.classList.contains('editing');
        fields.forEach(f => { f.disabled = !nowEditing; });
        btn.classList.toggle('editing', nowEditing);
        btn.querySelector('span').textContent = nowEditing ? 'Cancel' : 'Edit';
        btn.querySelector('i').className = nowEditing ? 'fa-solid fa-xmark' : 'fa-solid fa-pen';

        if (section === 'site') {
          document.getElementById('logoUploadZone').classList.toggle('locked', !nowEditing);
          document.getElementById('logoUploadBtn').disabled = !nowEditing;
        }
        if (section === 'barangay') {
          document.getElementById('barangayLogoZone').classList.toggle('locked', !nowEditing);
          document.getElementById('barangayLogoUploadBtn').disabled = !nowEditing;
          document.getElementById('municipalityLogoZone').classList.toggle('locked', !nowEditing);
          document.getElementById('municipalityLogoUploadBtn').disabled = !nowEditing;
        }
        if (section === 'landing') {
          document.getElementById('heroAddTile').classList.toggle('locked', !nowEditing);
        }

        if (!nowEditing) {
          fields.forEach(f => {
            f.value = originalValues[f.name] ?? '';
            f.classList.remove('changed');
          });
          if (section === 'site') {
            revertLogoField('siteLogoInput', 'logoZonePreview', 'assets/logo2.png');
            document.getElementById('siteTitleLogoPreview').src = document.getElementById('logoZonePreview').src;
          }
          if (section === 'barangay') {
            revertLogoField('barangayLogoInput', 'barangayLogoPreview', 'assets/sumacabLogo.jpg');
            revertLogoField('municipalityLogoInput', 'municipalityLogoPreview', 'assets/cabanatuan.png');
          }
          updateReachPreview();
          updateSiteTitlePreview();
          checkFormChanged();
        }
      });
    });

    function triggerLogoUpload() {
      if (document.getElementById('logoUploadZone').classList.contains('locked')) return;
      document.getElementById('logoFileInput').click();
    }
    function triggerHeroUpload() {
      if (document.getElementById('heroAddTile').classList.contains('locked')) return;
      if (document.getElementById('heroAddTile').classList.contains('disabled')) return;
      document.getElementById('heroFileInput').click();
    }
    function triggerBarangayLogoUpload() {
      if (document.getElementById('barangayLogoZone').classList.contains('locked')) return;
      document.getElementById('barangayLogoFileInput').click();
    }
    function triggerMunicipalityLogoUpload() {
      if (document.getElementById('municipalityLogoZone').classList.contains('locked')) return;
      document.getElementById('municipalityLogoFileInput').click();
    }

    // Undo an unsaved logo upload on Cancel: delete the orphaned file from
    // disk (nothing was ever written to the database for it) and restore
    // the preview to whatever it was before this edit session.
    function revertLogoField(hiddenInputId, previewImgId, defaultAssetUrl) {
      const input = document.getElementById(hiddenInputId);
      const original = originalValues[input.name] ?? '';
      const current = input.value;
      if (current && current !== original) {
        fetch('settingsLogoDelete.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'filename=' + encodeURIComponent(current)
        }).catch(() => {});
      }
      input.value = original;
      const img = document.getElementById(previewImgId);
      img.src = original ? ('uploads/site/' + encodeURIComponent(original)) : defaultAssetUrl;
    }

    document.querySelectorAll('[data-section-field]').forEach(el => {
      el.addEventListener('input', () => {
        el.classList.toggle('changed', el.value !== (originalValues[el.name] ?? ''));
        checkFormChanged();
      });
    });

    function checkFormChanged() {
      const anyChanged = Array.from(document.querySelectorAll('[data-section-field]')).some(
        el => el.value !== (originalValues[el.name] ?? '')
      );
      document.getElementById('saveBtn').disabled = !anyChanged;
    }

    /* ?? Live previews ?? */
    function updateReachPreview() {
      const val = document.getElementById('reachContentInput').value.trim();
      document.getElementById('reachPreviewText').textContent = val || 'Lorem ipsum dolor sit amet, consectetur adipiscing';
    }
    function updateSiteTitlePreview() {
      const val = document.getElementById('siteTitleInput').value.trim() || 'SumEste Portal';
      document.getElementById('siteTitlePreviewText').textContent = val;
      document.getElementById('themePreviewTitle').textContent = val;
    }

    /* ?? Color theme (with circular wipe transition) ?? */
    function selectColorTheme(hex, event) {
      const applyChange = () => {
        document.getElementById('colorThemeInput').value = hex;
        document.getElementById('colorThemeInput').dispatchEvent(new Event('input'));
        document.querySelectorAll('#swatchRow .swatch').forEach(s => s.classList.remove('selected'));
        const match = document.querySelector(`#swatchRow .swatch[data-color="${hex}"]`);
        if (match) match.classList.add('selected');
        document.getElementById('themePreviewSide').style.background = hex;
        document.documentElement.style.setProperty('--site-primary', hex);
      };

      if (event) {
        document.documentElement.style.setProperty('--wipe-x', event.clientX + 'px');
        document.documentElement.style.setProperty('--wipe-y', event.clientY + 'px');
      }

      if (document.startViewTransition) {
        document.startViewTransition(applyChange);
      } else {
        applyChange();
      }
    }

    /* ?? Hero images preview carousel ?? */
    let heroImages = <?= json_encode(array_map(fn($i) => $i['filename'], $heroImages), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    let heroPreviewIndex = 0;
    function renderHeroPreview() {
      const wrap = document.getElementById('heroPreviewWrap');
      let img = document.getElementById('heroPreviewImg');
      const placeholder = document.getElementById('heroPreviewPlaceholder');
      if (heroImages.length === 0) {
        if (img) img.remove();
        if (!document.getElementById('heroPreviewPlaceholder')) {
          const span = document.createElement('span');
          span.id = 'heroPreviewPlaceholder';
          span.className = 'hero-preview-placeholder';
          span.textContent = 'No hero images yet';
          wrap.insertBefore(span, wrap.firstChild);
        }
        return;
      }
      if (placeholder) placeholder.remove();
      if (!img) {
        img = document.createElement('img');
        img.id = 'heroPreviewImg';
        wrap.insertBefore(img, wrap.firstChild);
      }
      if (heroPreviewIndex >= heroImages.length) heroPreviewIndex = 0;
      img.src = 'uploads/hero/' + encodeURIComponent(heroImages[heroPreviewIndex]);
    }
    function heroPreviewNav(dir) {
      if (heroImages.length === 0) return;
      heroPreviewIndex = (heroPreviewIndex + dir + heroImages.length) % heroImages.length;
      renderHeroPreview();
    }

    /* ?? Hero image upload/remove (immediate, not tied to Save Changes) ?? */
 /* ?? Hero image upload/remove (tile grid, immediate - same click-to-upload pattern as the logo) ?? */
    function handleHeroUpload(input) {
      if (!input.files || !input.files[0]) return;
      const addTile = document.getElementById('heroAddTile');
      const originalIcon = addTile.innerHTML;
      addTile.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
      addTile.classList.add('disabled');

      const formData = new FormData();
      formData.append('hero_image', input.files[0]);

      fetch('heroImageUpload.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(d => {
          if (d.success) {
            const grid = document.getElementById('heroGrid');
            const tile = document.createElement('div');
            tile.className = 'hero-tile';
            tile.dataset.heroId = d.data.id;
            tile.innerHTML = `
              <img src="uploads/hero/${escHtml(d.data.filename)}" alt="">
              <button type="button" class="hero-tile-remove" onclick="event.stopPropagation(); removeHeroImage(${d.data.id}, this)" title="Remove"><i class="fa-solid fa-xmark"></i></button>
              <div class="hero-tile-overlay"><i class="fa-solid fa-image"></i></div>`;
            grid.insertBefore(tile, addTile);
            heroImages.push(d.data.filename);
            document.getElementById('heroCount').textContent = `(${heroImages.length}/<?= $heroImageMax ?>)`;
            renderHeroPreview();
            showToast('success', 'Hero image uploaded.');
          } else {
            showToast('error', d.message || 'Upload failed.');
          }
        })
        .catch(() => showToast('error', 'Network error during upload.'))
        .finally(() => {
          addTile.innerHTML = originalIcon;
          addTile.classList.toggle('disabled', heroImages.length >= <?= $heroImageMax ?>);
          input.value = '';
        });
    }
    function removeHeroImage(id, btn) {
      if (!confirm('Remove this hero image?')) return;
      fetch('heroImageDelete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + encodeURIComponent(id)
      })
        .then(r => r.json())
        .then(d => {
          if (d.success) {
            const tile = btn.closest('.hero-tile');
            const filename = tile.querySelector('img').getAttribute('src').split('/').pop();
            heroImages = heroImages.filter(f => f !== decodeURIComponent(filename));
            tile.remove();
            document.getElementById('heroCount').textContent = `(${heroImages.length}/<?= $heroImageMax ?>)`;
            document.getElementById('heroAddTile').classList.remove('disabled');
            renderHeroPreview();
            showToast('success', 'Hero image removed.');
          } else {
            showToast('error', d.message || 'Could not remove image.');
          }
        })
        .catch(() => showToast('error', 'Network error.'));
    }

    /* ?? Site logo upload (immediate) ?? */
    // Site Logo - now staged the same way as Barangay/Municipality Logo:
    // uploads immediately for preview, but only commits to the database
    // when "Save Changes" is confirmed. Cancel deletes the orphaned file.
    function handleLogoUpload(input) {
      handleStagedLogoUpload(input, 'site_logo', 'siteLogoInput', 'logoZonePreview');
    }

    // Barangay Logo / Municipality Logo / Site Logo - uploads immediately so
    // you can see a preview, but the filename is only staged in a hidden
    // field. Nothing is written to the database until "Save Changes" is
    // confirmed; if you click Cancel instead, the uploaded file is deleted
    // again (see the revertLogoField() call in the edit-toggle handler above).
    function handleStagedLogoUpload(input, field, hiddenInputId, previewImgId) {
      if (!input.files || !input.files[0]) return;
      const formData = new FormData();
      formData.append('logo_file', input.files[0]);
      formData.append('field', field);
      fetch('settingsLogoUpload.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(d => {
          if (d.success) {
            const hidden = document.getElementById(hiddenInputId);
            hidden.value = d.data.filename;
            const url = 'uploads/site/' + encodeURIComponent(d.data.filename) + '?t=' + Date.now();
            document.getElementById(previewImgId).src = url;
            if (field === 'site_logo') {
              document.getElementById('siteTitleLogoPreview').src = url;
            }
            checkFormChanged();
            showToast('success', 'Photo uploaded. Click "Save Changes" to keep it.');
          } else {
            showToast('error', d.message || 'Upload failed.');
          }
        })
        .catch(() => showToast('error', 'Network error during upload.'))
        .finally(() => { input.value = ''; });
    }
    function handleBarangayLogoUpload(input) {
      handleStagedLogoUpload(input, 'barangay_logo', 'barangayLogoInput', 'barangayLogoPreview');
    }
    function handleMunicipalityLogoUpload(input) {
      handleStagedLogoUpload(input, 'municipality_logo', 'municipalityLogoInput', 'municipalityLogoPreview');
    }

    function escHtml(str) {
      return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    /* ?? Save Changes (text/number fields + color theme) ?? */
    function handleSave() {
      document.getElementById('saveConfirmModal').style.display = 'flex';
    }
    function closeSaveConfirmModal() {
      document.getElementById('saveConfirmModal').style.display = 'none';
    }
    function confirmSave() {
      closeSaveConfirmModal();
      performSave();
    }

    function performSave() {
      const btn = document.getElementById('saveBtn');
      const payload = {};
      document.querySelectorAll('[data-section-field]').forEach(el => { payload[el.name] = el.value; });

      btn.disabled = true;
      const originalHtml = btn.innerHTML;
      btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';

      fetch('settingsSave.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      })
        .then(r => r.json())
        .then(d => {
          if (d.success) {
            showToast('success', 'Settings saved successfully. Refreshing.');
            setTimeout(() => { window.location.reload(); }, 900);
          } else {
            showToast('error', d.message || 'Could not save settings.');
            btn.innerHTML = originalHtml;
            checkFormChanged();
          }
        })
        .catch(() => {
          showToast('error', 'Network error while saving.');
          btn.innerHTML = originalHtml;
          checkFormChanged();
        });
    }/* ??????????????????????????????????????????
   USER PERMISSIONS SEARCH + MODAL
?????????????????????????????????????????? */
const STAFF_LABELS = {
  barangay_secretary: 'Barangay Secretary',
  barangay_treasurer: 'Barangay Treasurer',
};
const PERMISSION_LABELS = <?= json_encode(PERMISSION_MODULES) ?>;
let permListAbort = null;
let permUsersCache = {}; // userID -> user object, for re-populating modal

function loadStaffList() {
  const tbody = document.getElementById('permUserResults');
  if (permListAbort) permListAbort.abort();
  permListAbort = new AbortController();

  tbody.innerHTML = `
    <tr><td colspan="4" style="text-align:center;padding:28px;color:#9ca3af;font-size:0.85rem;">
      <i class="fa-solid fa-circle-notch fa-spin" style="margin-right:8px;color:var(--site-primary);"></i>Loading administrators.
    </td></tr>`;

  fetch('admin/userSearch.php', { signal: permListAbort.signal })
    .then(r => r.json())
    .then(d => renderPermUserResults(d.data || []))
    .catch(err => {
      if (err.name !== 'AbortError') {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:28px;color:#dc2626;font-size:0.85rem;">Could not load the staff list.</td></tr>';
      }
    });
}

function renderPermUserResults(users) {
  const tbody = document.getElementById('permUserResults');
  permUsersCache = {};
 if (users.length === 0) {
    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:28px;color:#9ca3af;font-size:0.85rem;">No one has admin access yet. Grant it above, or from Resident Management.</td></tr>';
    return;
  }
  tbody.innerHTML = users.map(u => {
    permUsersCache[u.userID] = u;
    const permKeys = (u.permissions || '').split(',').map(s => s.trim()).filter(Boolean);
    const modulesHtml = permKeys.length
      ? permKeys.map(k => `<span style="display:inline-block;background:#f3f4f6;color:#374151;font-size:0.7rem;font-weight:600;padding:2px 8px;border-radius:999px;margin:2px 4px 2px 0;">${escHtml(PERMISSION_LABELS[k] || k)}</span>`).join('')
      : '<span style="color:#9ca3af;font-size:0.78rem;">No modules</span>';
    const grantedByLabel = u.granted_by_name
      ? `${u.granted_by_name}${u.granted_by_email ? ' (' + u.granted_by_email + ')' : ''}`
      : 'Barangay Admin';
    return `
  <tr style="border-bottom:1px solid #f3f4f6;">
    <td style="padding:12px 16px;">
      <p style="font-weight:700;color:#111827;font-size:0.85rem;">${escHtml(u.fullname)}</p>
      <p style="color:#9ca3af;font-size:0.75rem;">${escHtml(u.email)}</p>
    </td>
    <td style="padding:12px 16px;max-width:260px;">${modulesHtml}</td>
    <td style="padding:12px 16px;font-size:0.82rem;color:#374151;">${escHtml(grantedByLabel)}</td>
    <td style="padding:12px 16px;text-align:right;">
      <div style="display:inline-flex;gap:6px;">
       <button type="button" onclick="openPermissionModal('${u.userID}')"
  style="display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border-radius:8px;background:var(--site-primary-pale);color:var(--site-primary-dark);border:none;font-size:0.76rem;font-weight:700;cursor:pointer;white-space:nowrap;transition:background 0.15s;"
  onmouseover="this.style.background='color-mix(in srgb, var(--site-primary) 18%, white)'" onmouseout="this.style.background='var(--site-primary-pale)'">
  <i class="fa-solid fa-user-pen" style="font-size:0.7rem;"></i> Edit
</button>
        <button type="button" onclick="handleRemoveAdmin('${u.userID}', '${escHtml(u.fullname).replace(/'/g,"\\'")}')"
          style="display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border-radius:8px;background:#fef2f2;color:#dc2626;border:none;font-size:0.76rem;font-weight:700;cursor:pointer;white-space:nowrap;transition:background 0.15s;"
          onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='#fef2f2'">
          <i class="fa-solid fa-user-xmark" style="font-size:0.7rem;"></i> Remove
        </button>
      </div>
    </td>
  </tr>`;
  }).join('');
}
function handleRemoveAdmin(userID, fullname) {
  if (!confirm(`Remove admin access for ${fullname}? They will no longer be able to log into the admin panel.`)) return;

  fetch('admin/updateStaffPermissions.php', {
    method: 'POST',
    body: new URLSearchParams({ userID }) // no permissions[] sent = revoke all
  })
    .then(r => r.json())
    .then(d => {
      if (d.success) {
        showToast('success', 'Admin access removed.');
        loadStaffList();
      } else {
        showToast('error', d.message || 'Could not remove access.');
      }
    })
    .catch(() => showToast('error', 'Network error. Please try again.'));
}
/* ??????????????????????????????????????????
   REMOVE ADMIN ACCOUNT
?????????????????????????????????????????? */
let removeAdminTargetID = null;
let removeAdminTargetName = null;

function handleRemoveAdmin(userID, fullname) {
  // ?? Validation ??
  if (!userID) {
    showToast('error', 'Invalid user - cannot remove.');
    return;
  }
  const currentUserID = <?= json_encode($_SESSION['user_id']) ?>;
  if (String(userID) === String(currentUserID)) {
    showToast('error', 'You cannot remove your own admin access.');
    return;
  }
  const u = permUsersCache[userID];
  if (!u) {
    showToast('error', 'Could not find that user. Try refreshing the list.');
    return;
  }
  removeAdminTargetID = userID;
  removeAdminTargetName = fullname;
  document.getElementById('removeAdminName').textContent = fullname || u.email || 'this user';
  document.getElementById('removeAdminModalOverlay').style.display = 'flex';
}

function closeRemoveAdminModal() {
  document.getElementById('removeAdminModalOverlay').style.display = 'none';
  removeAdminTargetID = null;
  removeAdminTargetName = null;
}
function closeRemoveAdminModalOnOverlay(e) {
  if (e.target === document.getElementById('removeAdminModalOverlay')) closeRemoveAdminModal();
}

function confirmRemoveAdmin() {
  if (!removeAdminTargetID) return;
  const btn = document.getElementById('removeAdminConfirmBtn');
  const originalHtml = btn.innerHTML; // capture BEFORE overwriting
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Removing...';

  fetch('admin/removeStaffAccount.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'userID=' + encodeURIComponent(removeAdminTargetID)
  })
    .then(r => r.json())
    .then(d => {
      if (d.success) {
        showToast('success', 'Admin account removed.');
        closeRemoveAdminModal();
        loadStaffList();
      } else {
        showToast('error', d.message || 'Could not remove this account.');
      }
    })
    .catch(() => {
      showToast('error', 'Network error. Please try again.');
    })
    .finally(() => {
      btn.disabled = false;
      btn.innerHTML = originalHtml;
    });
}
/* ??????????????????????????????????????????
   CHANGE PERMISSION MODAL
?????????????????????????????????????????? */
let permModalUserID = null;

function openPermissionModal(userID) {
  const u = permUsersCache[userID];
  if (!u) return;
  permModalUserID = userID;
  document.getElementById('permModalSubtitle').textContent = u.fullname || u.email || '';
  document.getElementById('permUserID').value = userID;

  const currentPerms = (u.permissions || '').split(',').map(s => s.trim()).filter(Boolean);
  document.querySelectorAll('#permModuleList .perm-toggle-track').forEach(track => {
    track.classList.toggle('on', currentPerms.includes(track.dataset.permKey));
  });

  document.getElementById('permModalOverlay').style.display = 'flex';
}
function closePermModal() {
  document.getElementById('permModalOverlay').style.display = 'none';
  permModalUserID = null;
}
function closePermModalOnOverlay(e) { if (e.target === document.getElementById('permModalOverlay')) closePermModal(); }
function togglePermModule(track) { track.classList.toggle('on'); }

function handlePermSave() {
  if (!permModalUserID) return;
  const permissions = Array.from(document.querySelectorAll('#permModuleList .perm-toggle-track.on')).map(t => t.dataset.permKey);

  const btn = document.getElementById('permSaveBtn');
  btn.disabled = true;
  const originalHtml = btn.innerHTML;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';

  const body = new URLSearchParams();
  body.append('userID', permModalUserID);
  permissions.forEach(p => body.append('permissions[]', p));

  fetch('admin/updateStaffPermissions.php', { method: 'POST', body })
    .then(r => r.json())
    .then(d => {
      if (d.success) {
        closePermModal();
        showToast('success', d.revoked ? 'Staff access revoked.' : 'Permission updated - takes effect immediately.');
        loadStaffList();
      } else {
        showToast('error', d.message || 'Could not save changes.');
      }
    })
    .catch(() => showToast('error', 'Network error. Please try again.'))
    .finally(() => { btn.disabled = false; btn.innerHTML = originalHtml; });
}

loadStaffList(); // populate the table as soon as the page loads
  </script>
</body>
</html>