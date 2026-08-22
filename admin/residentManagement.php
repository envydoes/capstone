<?php
session_start();
// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$role = $_SESSION['account_role'] ?? '';

require_once __DIR__ . '/../config/db_connection.php';

// Permission gate: founding admin always passes; Secretary/Treasurer
// pass only if explicitly granted 'manage_residents' in
// tbl_admin_permissions. Anyone else is redirected away.
require_once __DIR__ . '/../includes/check_permissions.php';
require_permission($conn, 'manage_residents');

// True only for the single founding admin account — used to gate the
// "Change Permission" button itself, since only the founder may
// grant/revoke staff positions.
$isFounderAdmin = (($_SESSION['account_role'] ?? '') === 'admin');

ob_start();

require_once '../includes/site_config.php';
$siteSettings = site_config_load($conn);

function sidebar_menu_allowed(string $key, string $role, array $myPermissions): bool
{
  if ($role === 'admin') {
    return true;
  }

  return in_array($key, $myPermissions, true);
}

$myPermissions = get_my_permissions($conn);
$sidebarSections = [
  'Management' => [
    ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'fa-chart-bar', 'href' => 'adminDashboard.php'],
    ['key' => 'manage_users', 'label' => 'User Management', 'icon' => 'fa-user', 'href' => 'userManagement.php', 'admin_only' => true],
    ['key' => 'manage_residents', 'label' => 'Resident Management', 'icon' => 'fa-house-chimney-user', 'href' => 'residentManagement.php', 'active' => true],
    ['key' => 'manage_beneficiaries', 'label' => 'Beneficiary Management', 'icon' => 'fa-hand-holding-heart', 'href' => 'beneficiaryManagement.php'],
    ['key' => 'manage_documents', 'label' => 'Document Request', 'icon' => 'fa-file-lines', 'href' => 'documentRequest.php'],
    ['key' => 'manage_borrowing', 'label' => 'Borrowing System', 'icon' => 'fa-hammer', 'href' => 'borrowingSystem.php'],
  ],
  'Community' => [
    ['key' => 'manage_listings', 'label' => 'Community Listings', 'icon' => 'fa-building', 'href' => 'communityListings.php'],
    ['key' => 'manage_announcements', 'label' => 'Announcements', 'icon' => 'fa-pen-to-square', 'href' => 'announcement.php'],
  ],
];

// Preload accID — staff grant (position + permissions) so each row
// can be stamped with its current grant without a query per row.
$staffGrants = [];
$grantRes = mysqli_query($conn, "SELECT accID, permissions_csv FROM tbl_admin_permissions");
if ($grantRes) {
    while ($g = mysqli_fetch_assoc($grantRes)) {
        $staffGrants[$g['accID']] = $g;
    }
}

function rowJson($u) {
    return htmlspecialchars(
        json_encode($u, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS),
        ENT_QUOTES, 'UTF-8'
    );
}

function e($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

$base_dir = dirname($_SERVER['SCRIPT_NAME']);
$upload_base = rtrim(dirname($base_dir), '/\\');
if ($upload_base === '' || $upload_base === '.') { $upload_base = ''; }
$upload_path = $upload_base . '/uploads/id_verification/';
if (strpos($upload_path, '//') === 0) { $upload_path = '/' . ltrim($upload_path, '/'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Resident Management - <?= e($siteSettings['site_title']) ?></title>
  <link rel="icon" href="<?= e(site_config_logo_url($siteSettings, '../')) ?>" type="image/png">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <?= site_config_css_vars($siteSettings) ?>
  <style>
    * { box-sizing: border-box; }
   body { font-family: 'DM Sans', sans-serif; background: var(--site-primary-pale); margin: 0; }

    /* ── Sidebar ── */
    .sidebar {
      width: 260px;
      flex-shrink: 0;
     background: linear-gradient(180deg, var(--site-primary-dark) 0%, var(--site-primary-darker) 55%, var(--site-primary) 100%);
      display: flex;
      flex-direction: column;
      position: fixed;
      top: 0;
      left: 0;
      height: 100vh;
      z-index: 300;
      overflow: hidden;
      transition: width 0.3s cubic-bezier(0.4,0,0.2,1), transform 0.3s cubic-bezier(0.4,0,0.2,1);
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
    .menu-item.active { background: rgba(255,255,255,0.13); color: #fff; }
    .menu-left { display: flex; align-items: center; gap: 11px; }
    .mi { width: 17px; text-align: center; font-size: 0.85rem; flex-shrink: 0; }
    .active-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--site-primary-light); flex-shrink: 0; }
    .collapse-btn { width: 28px; height: 28px; border-radius: 8px; background: rgba(255,255,255,0.1); border: none; cursor: pointer; color: #fff; font-size: 0.72rem; display: flex; align-items: center; justify-content: center; flex-shrink: 0; transition: background 0.2s; }
    .collapse-btn:hover { background: rgba(255,255,255,0.22); }

    /* Expand button — shown ONLY when sidebar is collapsed/hidden */
    .expand-btn {
      position: fixed;
      top: 18px;
      left: 12px;
      z-index: 200;
      width: 36px;
      height: 36px;
      border-radius: 10px;
      background: var(--site-primary-darker);
      border: 1px solid rgba(134,239,172,0.25);
      color: #fff;
      font-size: 0.82rem;
      cursor: pointer;
      display: none;
      align-items: center;
      justify-content: center;
      box-shadow: 0 4px 16px rgba(5,46,22,0.4);
      transition: background 0.2s;
    }
    .expand-btn.visible { display: flex; }
    .expand-btn:hover { background: var(--site-primary); }

    /* Mobile sidebar overlay backdrop */
    .sidebar-backdrop {
      display: none;
      position: fixed;
      inset: 0;
      z-index: 250;
      background: rgba(5,46,22,0.5);
      backdrop-filter: blur(2px);
    }
    .sidebar-backdrop.visible { display: block; }

    .sidebar-bottom { margin-top: auto; flex-shrink: 0; }
    .sidebar-bottom-links { padding: 0 16px 8px; }
    .side-link { display: block; width: 100%; font-size: 0.84rem; padding: 8px 8px; border-radius: 8px; transition: color 0.15s, background 0.15s; text-decoration: none; white-space: nowrap; border: none; background: none; text-align: left; cursor: pointer; }

    /* ── Main content wrapper ── */
    .main-wrapper {
      display: flex;
      min-height: 100vh;
    }

    /* Desktop: push main content by sidebar width */
    .main-content {
      flex: 1;
      min-width: 0;
      display: flex;
      flex-direction: column;
      margin-left: 260px;
      transition: margin-left 0.3s cubic-bezier(0.4,0,0.2,1);
      overflow-x: hidden;
    }
    .main-content.sidebar-collapsed { margin-left: 0; }

    /* ── Topbar ── */
    .topbar {
      background: #fff;
      border-bottom: 1px solid #e5e7eb;
      padding: 14px 28px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
      position: sticky;
      top: 0;
      z-index: 100;
    }
    .topbar-title-block { transition: margin-left 0.25s ease; }

    /* ── Stat cards ── */
    .stat-card { background:#fff; border-radius:14px; padding:20px 22px; border:1px solid #e5e7eb; box-shadow:0 2px 12px rgba(21,128,61,0.05); display:flex; flex-direction:column; gap:10px; transition:transform .2s, box-shadow .2s; }
    .stat-card:hover { transform:translateY(-3px); box-shadow:0 8px 24px rgba(21,128,61,.1); }
    .stat-label { font-size:.82rem; font-weight:600; color:#6b7280; }
    .stat-row { display:flex; align-items:center; gap:14px; }
    .stat-ico { font-size:1.6rem; }
    .stat-num { font-size:2.4rem; font-weight:800; color:#111827; line-height:1; }
    .stat-sub { font-size:.75rem; font-weight:600; color:#9ca3af; }
    .stat-trend { display:inline-flex; align-items:center; gap:4px; font-size:.78rem; font-weight:700; }
    .stat-trend-up { color:#15803d; }
    .stat-trend-down { color:#dc2626; }
    .stat-trend-flat { color:#9ca3af; }

    /* ── Table wrapper — horizontal scroll on small screens ── */
    .tbl-wrap {
      background: #fff;
      border-radius: 0 0 14px 14px;
      border: 1px solid #e5e7eb;
      border-top: none;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
    }
    table { width: 100%; border-collapse: collapse; min-width: 600px; }
    thead th { background: #f9fafb; padding: 10px 16px; text-align: left; font-size: 0.72rem; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.06em; border-bottom: 1px solid #e5e7eb; white-space: nowrap; }
    tbody tr { border-bottom: 1px solid #f3f4f6; transition: background 0.12s; }
    tbody tr:last-child { border-bottom: none; }
    tbody tr:hover { background: #f0fdf4; }
    tbody td { padding: 14px 16px; font-size: 0.84rem; color: #374151; vertical-align: middle; }

    /* ── Tabs ── */
    .tabs-bar { background: #fff; border: 1px solid #e5e7eb; border-bottom: none; border-radius: 14px 14px 0 0; display: grid; grid-template-columns: 1fr 1fr; }
    .tab-btn { padding: 13px; text-align: center; font-size: 0.84rem; font-weight: 600; color: #6b7280; cursor: pointer; border: none; background: none; transition: all 0.18s; border-bottom: 2px solid transparent; }
    .tab-btn.active {
    color: var(--site-primary);
    border-bottom-color: var(--site-primary);
    background: var(--site-primary-pale);
}
    .tab-btn:first-child { border-radius: 14px 0 0 0; }
    .tab-btn:last-child  { border-radius: 0 14px 0 0; }

    /* ── Toolbar ── */
    .toolbar { background: #fff; border: 1px solid #e5e7eb; border-top: none; border-bottom: none; padding: 10px 16px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .toolbar-divider { width: 1px; height: 22px; background: #e5e7eb; }

    /* Role chips */
    .role-chip { display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 999px; font-size: 0.68rem; font-weight: 600; border: 1px solid; margin-right: 4px; white-space: nowrap; }
    .chip-resident    { background: #f0fdf4; color: #15803d; border-color: #86efac; }
    .chip-nonresident { background: #eff6ff; color: #1d4ed8; border-color: #93c5fd; }
    .chip-business    { background: #fefce8; color: #a16207; border-color: #fde047; }
    .chip-staff       { background: #fdf2f8; color: #a21caf; border-color: #f5d0fe; }

    /* Icon buttons */
    .icon-btn { width: 30px; height: 30px; border-radius: 8px; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; transition: all 0.15s; flex-shrink: 0; }
    .icon-btn-edit    { background: #f0fdf4; color: #15803d; }
    .icon-btn-edit:hover    { background: #dcfce7; }
    .icon-btn-archive { background: #f9fafb; color: #6b7280; }
    .icon-btn-archive:hover { background: #fee2e2; color: #dc2626; }
    .icon-btn-restore { background: #f0fdf4; color: #15803d; }
    .icon-btn-restore:hover { background: #dcfce7; }
    .role-option { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border: 1.5px solid #e5e7eb; border-radius: 10px; cursor: pointer; transition: all 0.15s; margin-bottom: 8px; }
    .role-option:hover { border-color: #a21caf; background: #fdf2f8; }
    .role-option.selected { border-color: #a21caf; background: #fdf2f8; box-shadow: 0 0 0 3px rgba(162,28,175,0.1); }
    .role-option input { accent-color: #a21caf; }
    .role-option-title { font-weight: 700; font-size: 0.86rem; color: #111827; }
    .role-option-desc { font-size: 0.74rem; color: #6b7280; margin-top: 1px; }
    .perm-toggle-row { display: flex; align-items: center; justify-content: space-between; padding: 10px 12px; border: 1px solid #f3f4f6; border-radius: 9px; margin-bottom: 6px; }
    .perm-toggle-row:last-child { margin-bottom: 0; }
    .perm-toggle-label { font-size: 0.83rem; font-weight: 600; color: #374151; }
    .perm-toggle-track { width: 40px; height: 22px; background: #d1d5db; border-radius: 999px; position: relative; transition: background 0.2s; cursor: pointer; flex-shrink: 0; }
    .perm-toggle-track.on { background: #a21caf; }
    .perm-toggle-thumb { width: 18px; height: 18px; background: #fff; border-radius: 50%; position: absolute; top: 2px; left: 2px; transition: transform 0.2s; box-shadow: 0 1px 4px rgba(0,0,0,0.15); }
    .perm-toggle-track.on .perm-toggle-thumb { transform: translateX(18px); }

    /* Search */
    .search-box { display: flex; align-items: center; gap: 8px; border: 1.5px solid #e5e7eb; border-radius: 9px; padding: 7px 12px; background: #fff; transition: border-color 0.15s; min-width: 0; }
    .search-box:focus-within { border-color:var(--site-primary); }
    .search-box input { border: none; outline: none; font-size: 0.83rem; color: #374151; font-family: inherit; width: 100%; min-width: 0; background: transparent; }
    .btn-filter { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 9px; border: 1.5px solid #e5e7eb; background: #fff; font-size: 0.83rem; font-weight: 600; color: #374151; cursor: pointer; transition: all 0.15s; white-space: nowrap; }
    .btn-filter:hover { border-color:var(--site-primary); color:var(--site-primary); }
.btn-add {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 8px 16px;
    border-radius: 9px;
    background: var(--site-primary);
    color: #fff;
    font-size: 0.83rem;
    font-weight: 700;
    border: none;
    cursor: pointer;
    transition: background 0.15s;
    white-space: nowrap;
}    .btn-add:hover {
    filter: brightness(0.9);
}
    .btn-refresh { width: 30px; height: 30px; border-radius: 8px; border: 1.5px solid #e5e7eb; background: #fff; color: #6b7280; font-size: 0.82rem; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.15s; flex-shrink: 0; }
    .btn-refresh:hover { border-color:var(--site-primary); color:var(--site-primary); }

    /* Pagination */
    .page-btn { width: 34px; height: 34px; border-radius: 8px; border: 1.5px solid #e5e7eb; background: #fff; font-size: 0.82rem; font-weight: 600; color: #374151; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.15s; flex-shrink: 0; }
    .page-btn:hover { border-color:var(--site-primary-light); color:var(--site-primary-light); }
    .page-btn.active { background:var(--site-primary); border-color:var(--site-primary); color:#fff; }
    .page-btn:disabled { opacity: 0.35; cursor: default; }

    /* Empty state */
    .empty-state { padding: 52px 24px; text-align: center; color: #9ca3af; }
    .empty-state i { font-size: 2.2rem; margin-bottom: 10px; display: block; color: #d1d5db; }

    /* ══ SHARED MODAL STYLES ══ */
    .modal-overlay {
      position: fixed; inset: 0; z-index: 800;
      background: rgba(5,46,22,0.45); backdrop-filter: blur(4px);
      display: flex; align-items: flex-start; justify-content: center;
      padding: 16px;
      overflow-y: auto;
      opacity: 0; pointer-events: none; transition: opacity 0.22s;
    }
    .modal-overlay.open { opacity: 1; pointer-events: auto; }
    .modal {
      background: #fff; border-radius: 18px;
      width: 100%; max-width: 720px;
      box-shadow: 0 24px 60px rgba(5,46,22,0.22);
      transform: translateY(16px);
      transition: transform 0.25s cubic-bezier(0.4,0,0.2,1);
      margin: auto; display: flex; flex-direction: column;
    }
    .modal-overlay.open .modal { transform: translateY(0); }
    .modal-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 18px 12px; border-bottom: 1px solid #f3f4f6; flex-shrink: 0; gap: 8px; flex-wrap: wrap; }
    .modal-close { width: 28px; height: 28px; border-radius: 8px; border: none; background: #f3f4f6; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #6b7280; font-size: 0.78rem; transition: background 0.15s, color 0.15s; flex-shrink: 0; }
    .modal-close:hover { background: #fee2e2; color: #dc2626; }
    .modal-body { padding: 16px 18px; overflow-y: auto; max-height: calc(100vh - 160px); }
    .modal-body::-webkit-scrollbar { width: 4px; }
    .modal-body::-webkit-scrollbar-thumb { background: #d1fae5; border-radius: 4px; }

    /* Section cards inside modal */
    .section-card { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px; margin-bottom: 16px; }
    .section-card:last-child { margin-bottom: 0; }
    .section-title { display: flex; align-items: center; gap: 8px; font-size: 0.8rem; font-weight: 700; color: #374151; text-transform: uppercase; letter-spacing: 0.07em; margin-bottom: 14px; }
    .section-icon { width: 26px; height: 26px; background: #dcfce7; border-radius: 7px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }

    /* Fields */
    .field-label { display: block; font-size: 0.72rem; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 5px; }
    .field-input { width: 100%; padding: 9px 12px; border: 1.5px solid #e5e7eb; border-radius: 9px; font-size: 0.84rem; color: #374151; background: #fff; font-family: inherit; outline: none; transition: border-color 0.15s, box-shadow 0.15s; }
    .field-input:focus { border-color: #16a34a; box-shadow: 0 0 0 3px rgba(22,163,74,0.1); }
    .field-input.changed { border-color: #f59e0b; background: #fffbeb; }
    .field-input.error  { border-color: #dc2626; background: #fef2f2; }
    .field-error { display: block; font-size: 0.72rem; color: #dc2626; margin-top: 4px; }
    .required-star { color: #dc2626; }

    /* ID placeholder boxes */
    .id-placeholder { flex: 1; aspect-ratio: 4/3; border: 1.5px dashed #d1d5db; border-radius: 10px; background: #f9fafb; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #9ca3af; font-size: 0.72rem; gap: 6px; cursor: pointer; transition: border-color 0.15s, background 0.15s; position: relative; overflow: hidden; min-width: 0; }
    .id-placeholder:hover { border-color: #16a34a; background: #f0fdf4; }
    .id-placeholder img { width: 100%; height: 100%; object-fit: cover; position: absolute; inset: 0; border-radius: 9px; }

    /* Modal footer */
    .modal-footer { display: grid; grid-template-columns: 1fr 1fr; border-top: 1px solid #f3f4f6; flex-shrink: 0; }
    .mf-btn { padding: 14px; border: none; font-size: 0.88rem; font-weight: 700; cursor: pointer; font-family: inherit; transition: all 0.15s; display: flex; align-items: center; justify-content: center; gap: 6px; }
    .mf-cancel { background: #f9fafb; color: #374151; border-radius: 0 0 0 18px; }
    .mf-cancel:hover { background: #e5e7eb; }
    .mf-update { background: #15803d; color: #fff; border-radius: 0 0 18px 0; }
    .mf-update:hover:not(:disabled) { background: #166534; }
    .mf-update:disabled { background: #d1d5db; color: #9ca3af; cursor: not-allowed; }

    /* Changes badge */
    .changes-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 999px; background: #fef9c3; color: #a16207; font-size: 0.72rem; font-weight: 700; border: 1px solid #fde047; white-space: nowrap; }

    /* ── Lightbox ── */
    .lightbox { position: fixed; inset: 0; z-index: 1000; background: rgba(0,0,0,0.82); display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity 0.2s; padding: 16px; }
    .lightbox.open { opacity: 1; pointer-events: auto; }
    .lightbox img { max-width: 90vw; max-height: 88vh; border-radius: 10px; }
    .lightbox-close { position: absolute; top: 16px; right: 20px; background: rgba(255,255,255,0.12); border: none; color: #fff; font-size: 1.1rem; width: 36px; height: 36px; border-radius: 10px; cursor: pointer; display: flex; align-items: center; justify-content: center; }

    /* ── Confirm Dialog ── */
    .dialog-overlay { position: fixed; inset: 0; z-index: 900; background: rgba(5,46,22,0.45); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; padding: 20px; opacity: 0; pointer-events: none; transition: opacity 0.2s; }
    .dialog-overlay.open { opacity: 1; pointer-events: auto; }
    .dialog-box { background: #fff; border-radius: 20px; width: 100%; max-width: 400px; box-shadow: 0 24px 64px rgba(5,46,22,0.3), 0 4px 16px rgba(0,0,0,0.08); transform: scale(0.94) translateY(12px); transition: transform 0.25s cubic-bezier(0.34,1.56,0.64,1), opacity 0.2s; opacity: 0; overflow: hidden; }
    .dialog-overlay.open .dialog-box { transform: scale(1) translateY(0); opacity: 1; }
    .dialog-icon-wrap { width: 64px; height: 64px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 1.6rem; }
    .dialog-icon-approve { background: #dcfce7; color: #15803d; }
    .dialog-icon-reject  { background: #fee2e2; color: #dc2626; }
    .dialog-icon-bulk    { background: #fef9c3; color: #a16207; }
    .dialog-body-d { padding: 28px 24px 20px; text-align: center; }
    .dialog-title-d { font-size: 1.1rem; font-weight: 800; color: #111827; margin-bottom: 8px; font-family: 'Playfair Display', serif; }
    .dialog-desc-d  { font-size: 0.85rem; color: #6b7280; line-height: 1.5; }
    .dialog-name-badge { display: inline-block; margin-top: 10px; background: #f3f4f6; border-radius: 8px; padding: 6px 14px; font-size: 0.82rem; font-weight: 700; color: #374151; }
    .dialog-footer-d { padding: 0 20px 20px; display: flex; gap: 10px; }
    .dbtn { flex: 1; padding: 11px; border-radius: 11px; border: none; font-size: 0.86rem; font-weight: 700; cursor: pointer; font-family: inherit; transition: all 0.15s; display: flex; align-items: center; justify-content: center; gap: 6px; }
    .dbtn-cancel { background: #f3f4f6; color: #374151; }
    .dbtn-cancel:hover { background: #e5e7eb; }
    .dbtn-confirm { background: #16a34a; color: #fff; box-shadow: 0 4px 14px rgba(22,163,74,0.35); }
    .dbtn-confirm:hover { background: #15803d; transform: translateY(-1px); }
    .dbtn-confirm.danger { background: #ef4444; box-shadow: 0 4px 14px rgba(239,68,68,0.35); }
    .dbtn-confirm.danger:hover { background: #dc2626; transform: translateY(-1px); }

    /* ══════════════════════════════════════════
       ALERT BANNER — inline, above table
    ══════════════════════════════════════════ */
    #alertBanner { display: none; border-radius: 10px; margin-bottom: 4px; }
    #alertBanner.show { display: flex; }
    .alert-inner { display: flex; align-items: center; gap: 10px; padding: 13px 16px; font-size: 0.85rem; font-weight: 600; border-radius: 10px; border: 1.5px solid transparent; width: 100%; flex-wrap: wrap; }
    .alert-success { background: #f0fdf4; border-color: #bbf7d0; color: #15803d; }
    .alert-error   { background: #fef2f2; border-color: #fecaca; color: #dc2626; }
    .alert-warning { background: #fefce8; border-color: #fde68a; color: #a16207; }
    .alert-close { margin-left: auto; background: none; border: none; cursor: pointer; font-size: 0.8rem; opacity: 0.6; color: inherit; padding: 2px 4px; transition: opacity 0.15s; }
    .alert-close:hover { opacity: 1; }

    /* Toggle switch */
    .toggle-track { width: 44px; height: 24px; background: #d1d5db; border-radius: 999px; position: relative; transition: background 0.2s; cursor: pointer; flex-shrink: 0; }
    .toggle-track.on { background: #16a34a; }
    .toggle-thumb { width: 20px; height: 20px; background: #fff; border-radius: 50%; position: absolute; top: 2px; left: 2px; transition: transform 0.2s; box-shadow: 0 1px 4px rgba(0,0,0,0.15); }
    .toggle-track.on .toggle-thumb { transform: translateX(20px); }

    @keyframes fadeUp { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
    .fade-up { animation: fadeUp 0.35s ease both; }

    /* ══════════════════════════════════════════
       RESPONSIVE — Tablet (≥ 1024px)
    ══════════════════════════════════════════ */
    @media (max-width: 1024px) {
      .sidebar {
        transform: translateX(-100%);
        width: 260px !important;
      }
      .sidebar.mobile-open {
        transform: translateX(0);
      }
      /* On mobile the expand btn visibility is controlled by JS (same .visible class),
         but we always want it reachable — JS sets .visible on init for mobile */
      .main-content {
        margin-left: 0 !important;
      }
      .topbar {
        padding: 12px 16px;
      }
      .topbar-title-block {
        margin-left: 46px !important;
      }
    }

    /* ══════════════════════════════════════════
       RESPONSIVE — Mobile (≥ 640px)
    ══════════════════════════════════════════ */
    @media (max-width: 640px) {
      .topbar {
        padding: 10px 14px;
        gap: 8px;
      }
      .topbar h2 {
        font-size: 1.2rem !important;
      }
      .topbar p {
        font-size: 0.75rem;
      }
      /* Page content padding */
      .page-pad { padding: 14px !important; }

      /* Top row controls stack vertically */
      .top-row { flex-direction: column; align-items: stretch !important; gap: 10px !important; }
      .top-row-right { display: flex; flex-direction: column; gap: 8px; }
      .search-box { width: 100% !important; }
      .search-box input { width: 100%; }
      .controls-row { display: flex; gap: 8px; width: 100%; }
      .btn-filter { flex: 1; justify-content: center; }
      .btn-add    { flex: 1; justify-content: center; }

      /* Filter panel */
      #filterPanel { flex-direction: column; gap: 12px; }
      #filterPanel select,
      #filterPanel input[type="date"] { width: 100% !important; }

      /* Table: hide less important columns on tiny screens */
      .col-date  { display: none; }
      .col-role  { display: none; }

      /* Modal tweaks */
      .modal-overlay { padding: 0; align-items: flex-end; }
      .modal {
        border-radius: 20px 20px 0 0;
        max-width: 100%;
        max-height: 95vh;
      }
      .modal-body { max-height: calc(95vh - 140px); padding: 14px 14px; }
      .modal-header { padding: 14px 14px 10px; }
      .section-card { padding: 14px 12px; }

      /* ID photos side by side on mobile */
      .id-row { gap: 10px !important; }

      /* Dialog tweaks */
      .dialog-body-d { padding: 20px 18px 14px; }
      .dialog-title-d { font-size: 1rem; }
      .dialog-desc-d  { font-size: 0.85rem; }

      /* Pagination — smaller buttons */
      .page-btn { width: 30px; height: 30px; font-size: 0.76rem; }

      /* Alert banner */
      .alert-inner { font-size: 0.8rem; }

      /* Changes badge — hide text on very small */
      .changes-badge span:not(.badge-count) { display: none; }
    }

    /* ══════════════════════════════════════════
       RESPONSIVE — Extra small (≥ 380px)
    ══════════════════════════════════════════ */
    @media (max-width: 380px) {
      .btn-add .btn-label { display: none; }
      .btn-filter .btn-label { display: none; }
    }
    :root {
  --site-primary-dark:   color-mix(in srgb, var(--site-primary) 55%, black);
  --site-primary-darker: color-mix(in srgb, var(--site-primary) 75%, black);
  --site-primary-light:  color-mix(in srgb, var(--site-primary) 55%, white);
  --site-primary-pale:   color-mix(in srgb, var(--site-primary) 12%, white);
}
  </style>
</head>
<body>

<!-- Page Loader -->
<div id="pageLoader" class="fixed inset-0 bg-green-900/40 backdrop-blur-sm z-[9999] flex flex-col items-center justify-center opacity-0 pointer-events-none transition-opacity duration-300">
  <div class="w-12 h-12 border-4 border-white/20 border-t-green-400 rounded-full animate-spin shadow-lg"></div>
  <p class="text-white font-medium mt-4 tracking-wider text-sm shadow-sm">Loading...</p>
</div>

<!-- Mobile sidebar backdrop -->
<div class="sidebar-backdrop" id="sidebarBackdrop" onclick="closeMobileSidebar()"></div>

<!-- Expand (hamburger) button — always visible on mobile, visible when collapsed on desktop -->
<button class="expand-btn" id="expandBtn"><i class="fa-solid fa-bars"></i></button>

<div class="main-wrapper">

  <aside class="sidebar" id="sidebar">
    <div class="sidebar-inner">
      <div class="sidebar-logo">
        <button onclick="location.href='adminLanding'" style="border:none;background:none;padding:0;cursor:pointer;color:inherit;text-align:left;">
          <div class="flex items-center gap-2.5">
            <div class="w-10 h-10 rounded-full flex items-center justify-center shadow overflow-hidden" style="background: var(--site-primary);">
              <img src="<?= e(site_config_logo_url($siteSettings, '../')) ?>" alt="Logo" class="w-full h-full object-cover" />
            </div>
            <div>
              <p class="text-white font-bold text-sm leading-tight"><?= e($siteSettings['site_title']) ?></p>
              <p class="text-[10px] tracking-widest uppercase" style="color: var(--site-primary-light)">Admin Panel</p>
            </div>
          </div>
        </button>
        <button class="collapse-btn" id="collapseBtn"><i class="fa-solid fa-chevron-left"></i></button>
      </div>

      <?php foreach ($sidebarSections as $sectionLabel => $items): ?>
      <div class="section-label"><?= e($sectionLabel) ?></div>
      <nav class="space-y-0.5 px-2">
        <?php foreach ($items as $item): ?>
          <?php
            $visible = !empty($item['admin_only'])
              ? $role === 'admin'
              : sidebar_menu_allowed($item['key'], $role, $myPermissions);
            if (!$visible) {
                continue;
            }
            $isActive = !empty($item['active']);
          ?>
          <button class="menu-item<?= $isActive ? ' active' : '' ?>" onclick="location.href='<?= e($item['href']) ?>'">
            <div class="menu-left"><i class="fa-solid <?= e($item['icon']) ?> mi"></i><?= e($item['label']) ?></div>
            <?php if ($isActive): ?><span class="active-dot"></span><?php endif; ?>
          </button>
        <?php endforeach; ?>
      </nav>
      <?php endforeach; ?>

      <div class="sidebar-bottom">
        <div class="sidebar-bottom-links">
        <?php if ($role === 'admin'): ?>
            <button type="button" onclick="window.location.href='../settings.php'" class="side-link" style="color: rgba(255,255,255,0.55);" onmouseover="this.style.color='var(--site-primary-light)'" onmouseout="this.style.color=' rgba(255,255,255,0.55)'">Settings</button>
        <?php endif; ?>
        <div class="h-px bg-white/10 my-1 mx-2"></div>
          <button class="side-link text-red-400/70 hover:text-red-300 hover:bg-white/5" onclick="location.href='../logout.php'">Logout</button>
        </div>
      </div>
    </div>
  </aside>

  <!-- ══════════ MAIN ══════════ -->
  <main class="main-content" id="mainContent">

    <header class="topbar">
      <div class="topbar-title-block">
        <h2 class="font-bold text-2xl leading-tight" style="font-family:'Playfair Display',serif;color: var(--site-primary-darker);">Resident Management</h2>
        <p class="text-gray-500 text-sm mt-0.5">View and manage all registered community members.</p>
      </div>
    </header>

    <!-- REALTIME LOADER -->
    <div id="realtimeLoader" class="flex-1 flex flex-col items-center justify-center min-h-[50vh]">
      <i class="fa-solid fa-circle-notch fa-spin text-4xl text-green-600 mb-4"></i>
      <p class="text-sm font-semibold text-green-800 animate-pulse tracking-wide">Loading residents...</p>
    </div>

    <!-- MAIN DATA CONTAINER -->
    <div id="mainDataContainer" class="p-6 page-pad flex-1 space-y-5 fade-up" style="display: none;">

<!-- BROWSER RENDER PADDING TO FORCE INSTANT PAINT (Browsers often wait for 1-4KB before rendering) -->
<?php
echo str_repeat("<!-- PADDING TO FORCE BROWSER RENDER -->\n", 100);
// FLUSH THE OUTPUT BUFFER SO BROWSER RENDERS SIDEBAR + SPINNER
ob_flush();
flush();

// ── Fetch active residents ──
$sql_active = "
    SELECT
        userID, accID, account_role_csv,
        firstname, lastname, middlename, suffix,
        family_role, gender, birthday, birthplace,
        civil_status, citizenship, religion, ethnicity,
        street, barangay, city, province, zip,
        phone, email, emergency_contact, emergency_phone,
        health_conditions, employment_status, job_title,
        monthly_income, years_resident, resident_birth,
        voter_id, precinct, userStatus, frontID, backID, dateRegistered
    FROM tbl_userinfo
    WHERE LOWER(userStatus) = 'approved' AND account_role_csv LIKE '%resident%' AND NOT account_role_csv LIKE '%non-resident%'
    ORDER BY dateRegistered DESC
";
$res_active   = mysqli_query($conn, $sql_active);
$active_users = [];
if ($res_active) { while ($r = mysqli_fetch_assoc($res_active)) $active_users[] = $r; }

// ── Fetch archived residents ──
$sql_arch = "
    SELECT
        userID, accID, account_role_csv,
        firstname, lastname, middlename, suffix,
        family_role, gender, birthday, birthplace,
        civil_status, citizenship, religion, ethnicity,
        street, barangay, city, province, zip,
        phone, email, emergency_contact, emergency_phone,
        health_conditions, employment_status, job_title,
        monthly_income, years_resident, resident_birth,
        voter_id, precinct, userStatus, frontID, backID, dateRegistered
    FROM tbl_userinfo
    WHERE LOWER(userStatus) = 'archived'
    ORDER BY dateRegistered DESC
";
$res_arch       = mysqli_query($conn, $sql_arch);
$archived_users = [];
if ($res_arch) { while ($r = mysqli_fetch_assoc($res_arch)) $archived_users[] = $r; }

$total_active   = count($active_users);
$total_archived = count($archived_users);

// ── Stat cards: Resident Overview ───────────────────────────────────────
// All four use the same "active resident" population as the table above:
// approved accounts with a resident role.
$residentFilter = "LOWER(userStatus) = 'approved' AND account_role_csv LIKE '%resident%' AND NOT account_role_csv LIKE '%non-resident%'";

// New Residents This Month, vs Last Month
$resTrendRow = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        SUM(CASE WHEN dateRegistered >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN 1 ELSE 0 END) AS this_month,
        SUM(CASE WHEN dateRegistered >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
                  AND dateRegistered <  DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN 1 ELSE 0 END) AS last_month
    FROM tbl_userinfo
    WHERE $residentFilter
"));
$resThisMonth = (int) ($resTrendRow['this_month'] ?? 0);
$resLastMonth = (int) ($resTrendRow['last_month'] ?? 0);
if ($resLastMonth > 0) {
    $resTrendPct = (int) round((($resThisMonth - $resLastMonth) / $resLastMonth) * 100);
} else {
    $resTrendPct = $resThisMonth > 0 ? 100 : 0;
}
$resTrendDir = $resThisMonth > $resLastMonth ? 'up' : ($resThisMonth < $resLastMonth ? 'down' : 'flat');

// Average Years as Resident
$avgYearsRow = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT AVG(years_resident) AS avg_years
    FROM tbl_userinfo
    WHERE $residentFilter AND years_resident IS NOT NULL
"));
$avgYearsResident = ($avgYearsRow && $avgYearsRow['avg_years'] !== null)
    ? round((float) $avgYearsRow['avg_years'], 1)
    : null;

// Voter vs Non-Voter Ratio
$voterRow = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        SUM(CASE WHEN voter_id IS NOT NULL AND voter_id != '' THEN 1 ELSE 0 END) AS voters,
        COUNT(*) AS total
    FROM tbl_userinfo
    WHERE $residentFilter
"));
$voterCount   = (int) ($voterRow['voters'] ?? 0);
$voterTotal   = (int) ($voterRow['total'] ?? 0);
$voterRate    = $voterTotal > 0 ? round(($voterCount / $voterTotal) * 100) : 0;

// Households/Families Count — approximate. There's no dedicated household/
// family ID linking members together, so this counts residents marked as
// family_role = 'head' (Head of Family) as a stand-in for household count.
// It undercounts if a household never designated a head, and overcounts if
// more than one member per household was marked 'head'.
$householdCount = (int) mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(*) AS total
    FROM tbl_userinfo
    WHERE $residentFilter AND family_role = 'head'
"))['total'];

mysqli_close($conn);
?>

      <!-- Stat cards -->
      <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        <div class="stat-card">
          <p class="stat-label">New Residents This Month</p>
          <div class="stat-row"><i class="fa-solid fa-user-plus stat-ico" style="color: var(--site-primary);"></i><span class="stat-num"><?= number_format($resThisMonth) ?></span></div>
          <?php if ($resTrendDir === 'up'): ?>
            <span class="stat-trend stat-trend-up"><i class="fa-solid fa-arrow-up"></i> <?= $resTrendPct ?>% vs last month</span>
          <?php elseif ($resTrendDir === 'down'): ?>
            <span class="stat-trend stat-trend-down"><i class="fa-solid fa-arrow-down"></i> <?= abs($resTrendPct) ?>% vs last month</span>
          <?php else: ?>
            <span class="stat-trend stat-trend-flat"><i class="fa-solid fa-minus"></i> Same as last month</span>
          <?php endif; ?>
        </div>
        <div class="stat-card">
          <p class="stat-label">Average Years as Resident</p>
          <?php if ($avgYearsResident !== null): ?>
            <div class="stat-row"><i class="fa-solid fa-house-chimney stat-ico text-blue-500"></i><span class="stat-num"><?= number_format($avgYearsResident, 1) ?></span></div>
            <span class="stat-sub">Years</span>
          <?php else: ?>
            <div class="stat-row"><i class="fa-solid fa-house-chimney stat-ico text-gray-300"></i><span class="stat-num text-gray-300">N/A</span></div>
            <span class="stat-sub">No data yet</span>
          <?php endif; ?>
        </div>
        <div class="stat-card">
          <p class="stat-label">Voter vs Non-Voter Ratio</p>
          <div class="stat-row"><i class="fa-solid fa-check-to-slot stat-ico text-purple-500"></i><span class="stat-num"><?= $voterRate ?>%</span></div>
          <span class="stat-sub"><?= number_format($voterCount) ?> of <?= number_format($voterTotal) ?> registered to vote</span>
        </div>
        <div class="stat-card">
          <p class="stat-label">Households/Families Count</p>
          <div class="stat-row"><i class="fa-solid fa-people-roof stat-ico text-amber-500"></i><span class="stat-num"><?= number_format($householdCount) ?></span></div>
          <span class="stat-sub">By designated head of family</span>
        </div>
      </div>

      <!-- Top row -->
      <div class="flex items-center justify-between gap-4 flex-wrap top-row">
        <div class="flex items-baseline gap-2">
          <h3 class="font-bold text-gray-900 text-lg" id="tableLabel">All Residents</h3>
          <span class="font-bold text-lg" id="residentCount" style="color:var(--site-primary-dark)"><?= $total_active ?></span>
        </div>
        <div class="flex items-center gap-3 flex-wrap top-row-right">
          <div class="search-box" style="width:180px;">
            <i class="fa-solid fa-magnifying-glass text-gray-400 text-xs flex-shrink-0"></i>
            <input type="text" id="searchInput" placeholder="Search..." oninput="filterTable()">
          </div>
          <div class="controls-row flex items-center gap-2">
            <button class="btn-filter" onclick="toggleFilter()"><span class="btn-label">Filter</span> <i class="fa-solid fa-caret-down text-xs ml-1"></i></button>
            <button class="btn-filter" onclick="handleExport(this)"><i class="fa-solid fa-file-export text-xs"></i> <span class="btn-label">Export</span></button>
            <button class="btn-add" onclick="openAddModal()"><i class="fa-solid fa-plus text-xs"></i> <span class="btn-label">Add Resident</span></button>
          </div>
        </div>
      </div>

      <!-- Filter panel -->
      <div id="filterPanel" class="hidden bg-white border border-gray-200 rounded-xl p-4 shadow-sm flex flex-wrap gap-4">
        <div style="min-width:140px;flex:1;">
          <p class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">Role</p>
          <select id="filterRole" class="border border-gray-200 rounded-lg px-3 py-2 text-sm outline-none w-full" onchange="filterTable()">
            <option value="">All Roles</option>
            <option>Resident</option><option>Non-Resident</option><option>Business/Apartment Owner</option>
          </select>
        </div>
        <div style="min-width:140px;flex:1;">
          <p class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">Date From</p>
          <input type="date" id="filterDateFrom" class="border border-gray-200 rounded-lg px-3 py-2 text-sm outline-none w-full" onchange="filterTable()">
        </div>
        <div style="min-width:140px;flex:1;">
          <p class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">Date To</p>
          <input type="date" id="filterDateTo" class="border border-gray-200 rounded-lg px-3 py-2 text-sm outline-none w-full" onchange="filterTable()">
        </div>
      </div>

      <!-- Alert Banner -->
      <div id="alertBanner">
        <div class="alert-inner" id="alertInner">
          <i id="alertIcon" class="fa-solid fa-circle-check" style="font-size:1rem;flex-shrink:0;"></i>
          <div>
            <span id="alertTitle" style="font-weight:700;"></span>
            <span id="alertDesc" style="font-weight:400;margin-left:6px;opacity:0.85;"></span>
          </div>
          <button class="alert-close" onclick="dismissAlert()"><i class="fa-solid fa-xmark"></i></button>
        </div>
      </div>

      <!-- Tabs + Table -->
      <div>
        <div class="tabs-bar">
          <button class="tab-btn active" id="tabActive"   onclick="switchTab('active')">All Residents</button>
          <button class="tab-btn"        id="tabArchived" onclick="switchTab('archived')">Archived</button>
        </div>

        <div class="toolbar">
          <input type="checkbox" id="checkAll" class="rounded w-4 h-4 accent-green-600" onchange="toggleAll(this)">
          <button class="icon-btn icon-btn-archive" id="toolbarArchiveBtn" title="Archive selected" onclick="bulkArchive(this)" style="display:flex;"><i class="fa-solid fa-box-archive text-xs"></i></button>
          <button class="icon-btn icon-btn-restore" id="toolbarRestoreBtn" title="Unarchive selected" onclick="bulkUnarchive(this)" style="display:none;"><i class="fa-solid fa-box-open text-xs"></i></button>
          <div class="toolbar-divider"></div>
          <button class="btn-refresh" onclick="triggerRefresh()" title="Refresh"><i class="fa-solid fa-rotate-right text-xs"></i></button>
        </div>

        <div class="tbl-wrap relative" id="tableWrap">
          
          <!-- INLINE TABLE LOADER -->
          <div id="tableLoader" class="absolute inset-0 bg-white/80 backdrop-blur-sm z-10 flex flex-col items-center justify-center opacity-0 pointer-events-none transition-opacity duration-200">
            <i class="fa-solid fa-circle-notch fa-spin text-3xl text-green-600 mb-3"></i>
            <p class="text-xs font-semibold text-green-800 animate-pulse tracking-wide">Searching...</p>
          </div>

          <!-- NO RESULTS STATE -->
          <div id="noResultsState" class="hidden absolute inset-0 z-0 flex flex-col items-center justify-center text-center p-6 pb-12">
            <i class="fa-solid fa-magnifying-glass text-gray-300 text-4xl mb-3"></i>
            <p class="font-semibold text-gray-800 text-lg">No residents found</p>
            <p class="text-gray-500 text-sm mt-1">Try adjusting your search criteria.</p>
          </div>

          <!-- ACTIVE TABLE -->
          <table id="activeTable">
            <thead><tr>
              <th style="width:36px;"></th>
              <th>User Profile</th>
              <th class="col-role">Role</th>
              <th class="col-date">Date Registered</th>
              <th style="text-align:right;">Action</th>
            </tr></thead>
            <tbody id="activeTableBody">
              <?php if (empty($active_users)): ?>
              <tr id="emptyActiveRow"><td colspan="5"><div class="empty-state"><i class="fa-regular fa-folder-open"></i><p class="font-semibold text-sm">No active residents found.</p></div></td></tr>
              <?php else: foreach ($active_users as $u):
                $fn   = trim($u['firstname'].' '.($u['middlename'] ? $u['middlename'].' ' : '').' '.$u['lastname'].($u['suffix'] ? ' '.$u['suffix'] : ''));
                $date = !empty($u['dateRegistered']) ? date('F j, Y', strtotime($u['dateRegistered'])) : '—';
                $roles = explode(',', $u['account_role_csv'] ?? '');
              ?>
           <tr data-uid="<?= (int)$u['userID'] ?>"
    data-name="<?= htmlspecialchars(strtolower($fn)) ?>"
    data-date="<?= htmlspecialchars($u['dateRegistered'] ?? '') ?>"
    data-roles="<?= htmlspecialchars(strtolower($u['account_role_csv'] ?? '')) ?>"
    data-fullname="<?= htmlspecialchars($fn) ?>"
    data-permissions="<?= htmlspecialchars($staffGrants[$u['accID']]['permissions_csv'] ?? '') ?>"
    data-user="<?= rowJson($u) ?>">
                <td><input type="checkbox" class="row-check rounded w-4 h-4 accent-green-600"></td>
                <td>
                  <p class="font-bold text-gray-900 text-sm"><?= htmlspecialchars($fn) ?></p>
                  <p class="text-gray-400 text-xs"><?= htmlspecialchars($u['email'] ?? '') ?></p>
                </td>
                <td class="col-role">
  <?php foreach ($roles as $r): $r = trim($r); if (!$r) continue;
    $cls = str_contains(strtolower($r),'non') ? 'chip-nonresident' : (str_contains(strtolower($r),'business')||str_contains(strtolower($r),'apartment') ? 'chip-business' : 'chip-resident');
  ?><span class="role-chip <?= $cls ?>"><?= htmlspecialchars($r) ?></span><?php endforeach; ?>
  <?php if (!empty($staffGrants[$u['accID']]['permissions_csv'])): ?><span class="role-chip chip-staff">Staff Access</span><?php endif; ?>
</td>
                <td class="text-gray-500 text-sm col-date"><?= $date ?></td>
                <td>
                  <div class="flex items-center justify-end gap-2">
                    <button class="icon-btn icon-btn-edit" title="Edit" onclick="openEditModal(this.closest('tr'))">
                      <i class="fa-solid fa-pen-to-square text-xs"></i>
                    </button>
                    <button class="icon-btn icon-btn-archive" title="Archive" onclick="confirmArchive(<?= (int)$u['userID'] ?>,'<?= htmlspecialchars(addslashes($fn)) ?>',this.closest('tr'),this)">
                      <i class="fa-solid fa-box-archive text-xs"></i>
                    </button>
                  </div>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>

          <!-- ARCHIVED TABLE -->
          <table id="archivedTable" style="display:none;">
            <thead><tr>
              <th style="width:36px;"></th>
              <th>User Profile</th>
              <th class="col-role">Role</th>
              <th class="col-date">Date Registered</th>
              <th style="text-align:right;">Action</th>
            </tr></thead>
            <tbody>
              <?php if (empty($archived_users)): ?>
              <tr><td colspan="5"><div class="empty-state"><i class="fa-regular fa-folder-open"></i><p class="font-semibold text-sm">No archived residents.</p></div></td></tr>
              <?php else: foreach ($archived_users as $u):
                $fn   = trim($u['firstname'].' '.($u['middlename'] ? $u['middlename'].' ' : '').' '.$u['lastname'].($u['suffix'] ? ' '.$u['suffix'] : ''));
                $date = !empty($u['dateRegistered']) ? date('F j, Y', strtotime($u['dateRegistered'])) : '—';
                $roles = explode(',', $u['account_role_csv'] ?? '');
              ?>
              <tr data-uid="<?= (int)$u['userID'] ?>"
                  data-name="<?= htmlspecialchars(strtolower($fn)) ?>"
                  data-date="<?= htmlspecialchars($u['dateRegistered'] ?? '') ?>"
                  data-roles="<?= htmlspecialchars(strtolower($u['account_role_csv'] ?? '')) ?>">
                <td><input type="checkbox" class="row-check rounded w-4 h-4 accent-green-600"></td>
                <td>
                  <p class="font-bold text-gray-900 text-sm"><?= htmlspecialchars($fn) ?></p>
                  <p class="text-gray-400 text-xs"><?= htmlspecialchars($u['email'] ?? '') ?></p>
                </td>
                <td class="col-role">
                  <?php foreach ($roles as $r): $r = trim($r); if (!$r) continue;
                    $cls = str_contains(strtolower($r),'non') ? 'chip-nonresident' : (str_contains(strtolower($r),'business')||str_contains(strtolower($r),'apartment') ? 'chip-business' : 'chip-resident');
                  ?><span class="role-chip <?= $cls ?>"><?= htmlspecialchars($r) ?></span><?php endforeach; ?>
                </td>
                <td class="text-gray-500 text-sm col-date"><?= $date ?></td>
                <td>
                  <div class="flex justify-end">
                    <button class="icon-btn icon-btn-restore" title="Unarchive" onclick="confirmUnarchive(<?= (int)$u['userID'] ?>,'<?= htmlspecialchars(addslashes($fn)) ?>',1,this.closest('tr'),this)">
                      <i class="fa-solid fa-box-open text-xs"></i>
                    </button>
                  </div>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

        <div class="flex items-center justify-center gap-2 pt-5 flex-wrap" id="paginationContainer"></div>
      </div>
    </div>
    
    <!-- CLOSE MAIN DATA CONTAINER -->
    </div>

    <!-- Script to hide loader, show content, and auto-refresh -->
    <script>
      document.getElementById('realtimeLoader').style.display = 'none';
      document.getElementById('mainDataContainer').style.display = '';

      function triggerRefresh() {
        document.getElementById('mainDataContainer').style.display = 'none';
        document.getElementById('realtimeLoader').style.display = 'flex';
        setTimeout(() => location.reload(), 180);
      }

      function showPageLoader(message='Loading...') {
        const main = document.getElementById('mainDataContainer');
        const loader = document.getElementById('realtimeLoader');
        if (main) main.style.display = 'none';
        if (loader) {
          const txt = loader.querySelector('p');
          if (txt) txt.textContent = message;
          loader.style.display = 'flex';
        }
      }

      function hidePageLoader() {
        const main = document.getElementById('mainDataContainer');
        const loader = document.getElementById('realtimeLoader');
        if (loader) loader.style.display = 'none';
        if (main) main.style.display = '';
      }

      document.querySelectorAll('.sidebar button[onclick*="location.href"]').forEach(btn => {
        btn.addEventListener('click', function(e) {
          const raw = this.getAttribute('onclick') || '';
          const match = raw.match(/location\.href\s*=\s*['"]([^'"]+)['"]/);
          if (!match) return;
          e.preventDefault();
          e.stopPropagation();
          showPageLoader('Loading page...');
          setTimeout(() => { window.location.href = match[1]; }, 180);
        });
      });
    </script>
  </main>
</div>

<!-- ══════════════════════════════════════
     EDIT MODAL
══════════════════════════════════════ -->
<div class="modal-overlay" id="editModalOverlay" onclick="closeEditModalOnOverlay(event)">
  <div class="modal" id="editModal">
    <div class="modal-header">
      <div class="flex items-center gap-3 min-w-0">
        <div style="width:36px;height:36px;background:#dcfce7;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <i class="fa-solid fa-pen-to-square text-green-700 text-sm"></i>
        </div>
        <div class="min-w-0">
          <p class="font-bold text-gray-900 text-base">Edit Resident</p>
          <p class="text-gray-400 text-xs mt-0.5 truncate" id="editModalSubtitle">Update resident information</p>
        </div>
      </div>
      <div class="flex items-center gap-2 flex-shrink-0">
        <span class="changes-badge" id="changesBadge" style="display:none;">
          <i class="fa-solid fa-circle-dot text-xs"></i>
          <span id="changesCount" class="badge-count">0</span> change(s)
        </span>
        <button class="modal-close" onclick="closeEditModal()"><i class="fa-solid fa-xmark"></i></button>
      </div>
    </div>

    <div class="modal-body" id="editModalBody">
      <!-- ID Photos (view only) -->
      <div class="section-card">
        <div class="section-title">
          <div class="section-icon"><i class="fa-solid fa-id-card text-green-700 text-sm"></i></div>
          Uploaded ID <span style="font-size:0.68rem;color:#9ca3af;font-weight:400;text-transform:none;letter-spacing:0;">(view only)</span>
        </div>
        <div class="flex gap-4 id-row">
          <div class="id-placeholder" onclick="openLightbox('front')" title="Click to zoom">
            <img id="frontIDImg" src="" alt="" style="display:none;">
            <div id="frontIDPlaceholder" style="display:flex;flex-direction:column;align-items:center;gap:6px;">
              <i class="fa-regular fa-id-card text-2xl text-gray-300"></i><span>Front ID</span>
              <span class="text-[10px] text-gray-300">(click to zoom)</span>
            </div>
          </div>
          <div class="id-placeholder" onclick="openLightbox('back')" title="Click to zoom">
            <img id="backIDImg" src="" alt="" style="display:none;">
            <div id="backIDPlaceholder" style="display:flex;flex-direction:column;align-items:center;gap:6px;">
              <i class="fa-regular fa-id-card text-2xl text-gray-300"></i><span>Back ID</span>
              <span class="text-[10px] text-gray-300">(click to zoom)</span>
            </div>
          </div>
        </div>
      </div>
      <!-- Edit form fields injected by JS below -->
      <input type="hidden" id="mUserID">
    </div>

    <div class="modal-footer">
      <button class="mf-btn mf-cancel" onclick="closeEditModal()">Cancel <i class="fa-solid fa-xmark text-sm"></i></button>
      <button class="mf-btn mf-update" id="editUpdateBtn" disabled onclick="handleUpdate()">
        Update <i class="fa-solid fa-floppy-disk text-sm"></i>
      </button>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════
     ADD RESIDENT MODAL
══════════════════════════════════════ -->
<div class="modal-overlay" id="addModalOverlay" onclick="closeAddModalOnOverlay(event)">
  <div class="modal" id="addModal">
    <div class="modal-header">
      <div class="flex items-center gap-3 min-w-0">
        <div style="width:36px;height:36px;background:#dcfce7;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <i class="fa-solid fa-user-plus text-green-700 text-sm"></i>
        </div>
        <div class="min-w-0">
          <p class="font-bold text-gray-900 text-base">Add Resident</p>
          <p class="text-gray-400 text-xs mt-0.5">Fill in the details to register a new resident.</p>
        </div>
      </div>
      <button class="modal-close" onclick="closeAddModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <div class="modal-body" id="addModalBody">

      <!-- PERSONAL INFORMATION -->
      <div class="section-card">
        <div class="section-title"><div class="section-icon"><i class="fa-solid fa-user text-green-700 text-sm"></i></div>Personal Information</div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
          <div><label class="field-label">First Name <span class="required-star">*</span></label><input type="text" id="a_firstname" maxlength="100" class="field-input" placeholder="First name"></div>
          <div><label class="field-label">Last Name <span class="required-star">*</span></label><input type="text" id="a_lastname" maxlength="100" class="field-input" placeholder="Last name"></div>
          <div><label class="field-label">Middle Name</label><input type="text" id="a_middlename" maxlength="100" class="field-input" placeholder="Middle name"></div>
          <div><label class="field-label">Suffix</label><input type="text" id="a_suffix" maxlength="20" class="field-input" placeholder="e.g. Jr., Sr., III"></div>
          <div><label class="field-label">Family Role <span class="required-star">*</span></label>
            <select id="a_family_role" class="field-input"><option value="">Select Family Role</option><option value="head">Head of Family</option><option value="spouse">Spouse</option><option value="child">Child</option><option value="parent">Parent</option><option value="other">Other</option></select></div>
          <div><label class="field-label">Sex <span class="required-star">*</span></label>
            <select id="a_gender" class="field-input"><option value="">Select Sex</option><option value="male">Male</option><option value="female">Female</option><option value="other">Other</option></select></div>
          <div><label class="field-label">Birthday <span class="required-star">*</span></label><input type="date" id="a_birthday" max="<?= date('Y-m-d') ?>" class="field-input"></div>
          <div><label class="field-label">Birthplace <span class="required-star">*</span></label><input type="text" id="a_birthplace" maxlength="200" class="field-input" placeholder="City, Province, Country"></div>
          <div><label class="field-label">Civil Status <span class="required-star">*</span></label>
            <select id="a_civil_status" class="field-input"><option value="">Select Civil Status</option><option value="single">Single</option><option value="married">Married</option><option value="divorced">Divorced</option><option value="widowed">Widowed</option><option value="separated">Separated</option></select></div>
          <div><label class="field-label">Citizenship <span class="required-star">*</span></label><input type="text" id="a_citizenship" maxlength="100" class="field-input" placeholder="e.g. Filipino"></div>
          <div><label class="field-label">Religion</label><input type="text" id="a_religion" maxlength="100" class="field-input" placeholder="e.g. Catholic"></div>
          <div><label class="field-label">Ethnicity</label><input type="text" id="a_ethnicity" maxlength="100" class="field-input" placeholder="e.g. Tagalog"></div>
        </div>
      </div>

      <!-- ADDRESS -->
      <div class="section-card">
        <div class="section-title"><div class="section-icon"><i class="fa-solid fa-location-dot text-green-700 text-sm"></i></div>Complete Address Information</div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
          <div><label class="field-label">Street Address <span class="required-star">*</span></label><input type="text" id="a_street" maxlength="200" class="field-input" placeholder="Street name and number"></div>
          <div><label class="field-label">Barangay <span class="required-star">*</span></label><input type="text" id="a_barangay" maxlength="100" class="field-input" placeholder="Barangay"></div>
          <div><label class="field-label">City / Municipality <span class="required-star">*</span></label><input type="text" id="a_city" maxlength="100" class="field-input" placeholder="City or Municipality"></div>
          <div><label class="field-label">Province <span class="required-star">*</span></label><input type="text" id="a_province" maxlength="100" class="field-input" placeholder="Province"></div>
          <div><label class="field-label">ZIP Code <span class="required-star">*</span></label><input type="text" id="a_zip" maxlength="10" class="field-input" placeholder="ZIP Code"></div>
        </div>
      </div>

      <!-- CONTACT & HEALTH -->
      <div class="section-card">
        <div class="section-title"><div class="section-icon"><i class="fa-solid fa-phone text-green-700 text-sm"></i></div>Contact and Health Information</div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
          <div><label class="field-label">Phone Number <span class="required-star">*</span></label><input type="tel" id="a_phone" maxlength="20" class="field-input" placeholder="+63 912 345 6789"><span class="text-gray-400 text-xs mt-1 block">Format: +639XXXXXXXXX or 09XXXXXXXXX</span></div>
          <div><label class="field-label">Email <span class="required-star">*</span></label><input type="email" id="a_email" maxlength="254" class="field-input" placeholder="Email address"></div>
          <div><label class="field-label">Emergency Contact</label><input type="text" id="a_emergency_contact" maxlength="150" class="field-input" placeholder="Name of emergency contact"></div>
          <div><label class="field-label">Emergency Contact Phone</label><input type="tel" id="a_emergency_phone" maxlength="20" class="field-input" placeholder="Emergency contact number"></div>
          <div class="md:col-span-2"><label class="field-label">Blood Type</label><input type="text" id="a_health_conditions" maxlength="10" class="field-input" placeholder="e.g. O+, A-, B+"></div>
        </div>
      </div>

      <!-- EMPLOYMENT -->
      <div class="section-card">
        <div class="section-title"><div class="section-icon"><i class="fa-solid fa-briefcase text-green-700 text-sm"></i></div>Employment Information</div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
          <div><label class="field-label">Employment Status <span class="required-star">*</span></label>
            <select id="a_employment_status" class="field-input"><option value="">Select Employment Status</option><option value="employed">Employed</option><option value="self-employed">Self-Employed</option><option value="unemployed">Unemployed</option><option value="student">Student</option><option value="retired">Retired</option><option value="other">Other</option></select></div>
          <div><label class="field-label">Job Title</label><input type="text" id="a_job_title" maxlength="150" class="field-input" placeholder="Your job title"></div>
          <div><label class="field-label">Monthly Income (PHP)</label><input type="number" id="a_monthly_income" min="0" max="9999999" step="1" class="field-input" placeholder="e.g. 25000"></div>
        </div>
      </div>

      <!-- VOTER -->
      <div class="section-card">
        <div class="section-title"><div class="section-icon"><i class="fa-solid fa-check-to-slot text-green-700 text-sm"></i></div>Voter Information</div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
          <div><label class="field-label">Voter ID Number</label><input type="text" id="a_voter_id" maxlength="50" class="field-input" placeholder="Voter ID if applicable"></div>
          <div><label class="field-label">Precinct Number</label><input type="text" id="a_precinct" maxlength="50" class="field-input" placeholder="Precinct number"></div>
        </div>
      </div>

      <!-- RESIDENCY -->
      <div class="section-card">
        <div class="section-title"><div class="section-icon"><i class="fa-solid fa-house text-green-700 text-sm"></i></div>Residency Information</div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
          <div><label class="field-label">Years as Resident <span class="required-star">*</span></label><input type="number" id="a_years_resident" min="0" max="120" step="1" class="field-input" placeholder="Number of years"></div>
          <div class="flex items-end pb-1">
            <label class="flex items-center gap-3 cursor-pointer select-none" onclick="toggleAddResidentBirth()">
              <div class="toggle-track" id="addResidentBirthToggle"><div class="toggle-thumb"></div></div>
              <span class="text-sm font-semibold text-gray-700">Resident since Birth</span>
            </label>
            <input type="hidden" id="a_resident_birth" value="0">
          </div>
        </div>
      </div>

    </div><!-- /modal-body -->

    <div class="modal-footer">
      <button class="mf-btn mf-cancel" onclick="closeAddModal()">Cancel <i class="fa-solid fa-xmark text-sm"></i></button>
      <button class="mf-btn mf-update" id="addSubmitBtn" onclick="handleAddResident()">
        Add Resident <i class="fa-solid fa-user-plus text-sm"></i>
      </button>
    </div>
  </div>
</div>


<!-- Lightbox -->
<div class="lightbox" id="lightbox" onclick="closeLightbox()">
  <button class="lightbox-close" onclick="closeLightbox()"><i class="fa-solid fa-xmark"></i></button>
  <img id="lightboxImg" src="" alt="">
</div>

<!-- Confirm Dialog -->
<div class="dialog-overlay" id="dialogOverlay">
  <div class="dialog-box" id="dialogBox">
    <div class="dialog-header-bar" id="dialogHeaderBar"></div>
    <div class="dialog-body-d">
      <div class="dialog-icon-wrap" id="dialogIconWrap"><i id="dialogIconEl" class="fa-solid fa-check"></i></div>
      <p class="dialog-title-d" id="dialogTitle">Confirm Action</p>
      <p class="dialog-desc-d"  id="dialogDesc">Are you sure you want to proceed?</p>
      <span class="dialog-name-badge" id="dialogNameBadge" style="display:none;"></span>
    </div>
    <div class="dialog-footer-d">
      <button class="dbtn dbtn-cancel" onclick="closeDialog()"><i class="fa-solid fa-xmark"></i> Cancel</button>
      <button class="dbtn dbtn-confirm" id="dialogConfirmBtn">
        <i class="fa-solid fa-check" id="dialogConfirmIcon"></i> 
        <span id="dialogConfirmLabel">Confirm</span>
      </button>
    </div>
  </div>
</div>

<script>
/* ══════════════════════════════════════════
   SIDEBAR — Desktop collapse + Mobile drawer
══════════════════════════════════════════ */
const sidebar     = document.getElementById('sidebar');
const mainContent = document.getElementById('mainContent');
const expandBtn   = document.getElementById('expandBtn');
const backdrop    = document.getElementById('sidebarBackdrop');
const isMobile    = () => window.innerWidth <= 1024;

let collapsed = localStorage.getItem('sidebarCollapsed') === 'true';

function applyCollapse() {
  if (isMobile()) {
    sidebar.classList.remove('collapsed');
    mainContent.classList.remove('sidebar-collapsed');
    expandBtn.classList.add('visible');   // always show hamburger on mobile
    return;
  }
  // Desktop
  sidebar.classList.remove('mobile-open');
  backdrop.classList.remove('visible');
  document.body.style.overflow = '';
  if (collapsed) {
    sidebar.classList.add('collapsed');
    mainContent.classList.add('sidebar-collapsed');
    expandBtn.classList.add('visible');    // show hamburger when collapsed
  } else {
    sidebar.classList.remove('collapsed');
    mainContent.classList.remove('sidebar-collapsed');
    expandBtn.classList.remove('visible'); // HIDE hamburger when sidebar is open
  }
}

function openMobileSidebar() {
  sidebar.classList.add('mobile-open');
  backdrop.classList.add('visible');
  document.body.style.overflow = 'hidden';
}
function closeMobileSidebar() {
  sidebar.classList.remove('mobile-open');
  backdrop.classList.remove('visible');
  document.body.style.overflow = '';
}

document.getElementById('collapseBtn').addEventListener('click', () => {
  if (isMobile()) { closeMobileSidebar(); return; }
  collapsed = true; localStorage.setItem('sidebarCollapsed','true'); applyCollapse();
});
expandBtn.addEventListener('click', () => {
  if (isMobile()) { openMobileSidebar(); return; }
  collapsed = false; localStorage.setItem('sidebarCollapsed','false'); applyCollapse();
});

window.addEventListener('resize', applyCollapse);
applyCollapse();

/* ════════════════════════════════════════════
   ALERT BANNER SYSTEM
════════════════════════════════════════════ */
let alertTimer = null;

function showToast(type, title, desc) {
  const icons   = { success: 'fa-circle-check', error: 'fa-circle-xmark', warning: 'fa-triangle-exclamation' };
  const typeMap = { success: 'alert-success', error: 'alert-error', warning: 'alert-warning' };
  const banner  = document.getElementById('alertBanner');
  const inner   = document.getElementById('alertInner');
  const icon    = document.getElementById('alertIcon');
  const titleEl = document.getElementById('alertTitle');
  const descEl  = document.getElementById('alertDesc');
  inner.className     = 'alert-inner ' + (typeMap[type] || 'alert-success');
  icon.className      = 'fa-solid ' + (icons[type] || 'fa-circle-check');
  titleEl.textContent = title;
  descEl.textContent  = desc || '';
  banner.classList.add('show');
  banner.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  clearTimeout(alertTimer);
  alertTimer = setTimeout(() => dismissAlert(), 4000);
}
function dismissAlert() { document.getElementById('alertBanner').classList.remove('show'); }

/* ── Tab switching ── */
let activeTab = 'active';
function switchTab(tab) {
  activeTab = tab;
  document.getElementById('activeTable').style.display   = tab === 'active'   ? '' : 'none';
  document.getElementById('archivedTable').style.display = tab === 'archived' ? '' : 'none';
  document.getElementById('tabActive').classList.toggle('active', tab === 'active');
  document.getElementById('tabArchived').classList.toggle('active', tab === 'archived');
  document.getElementById('toolbarArchiveBtn').style.display = tab === 'active'   ? 'flex' : 'none';
  document.getElementById('toolbarRestoreBtn').style.display = tab === 'archived' ? 'flex' : 'none';
  document.getElementById('tableLabel').textContent    = tab === 'active' ? 'All Residents' : 'Archived Residents';
  document.getElementById('residentCount').textContent = tab === 'active' ? '<?= $total_active ?>' : '<?= $total_archived ?>';
  document.getElementById('checkAll').checked = false;
  currentPage = 1; filterTable();
}
function toggleAll(cb) {
  const sel = activeTab === 'active' ? '#activeTable' : '#archivedTable';
  document.querySelectorAll(sel + ' .row-check').forEach(c => c.checked = cb.checked);
}

/* ── Filter / Search ── */
let searchTimeout;

function filterTable() {
  clearTimeout(searchTimeout);

  searchTimeout = setTimeout(() => {
    const q    = document.getElementById('searchInput').value.toLowerCase().trim();
    const role = (document.getElementById('filterRole')?.value ?? '').toLowerCase();
    const from = document.getElementById('filterDateFrom')?.value ?? '';
    const to   = document.getElementById('filterDateTo')?.value ?? '';
    const sel  = activeTab === 'active' ? '#activeTable' : '#archivedTable';
    
    let matchCount = 0;
    
    document.querySelectorAll(sel + ' tbody tr[data-uid]').forEach(row => {
        const ok = (!q || row.dataset.name.includes(q))
                && (!role || row.dataset.roles.includes(role))
                && (!from || row.dataset.date >= from)
                && (!to   || row.dataset.date <= to);
        if (ok) {
          row.dataset.filteredout = "false";
          matchCount++;
        } else {
          row.dataset.filteredout = "true";
          row.style.display = 'none';
        }
      });

      currentPage = 1; 
      renderPagination();

      const noResults = document.getElementById('noResultsState');
      const tblActive = document.getElementById('activeTable');
      const tblArchived = document.getElementById('archivedTable');
      // Check if empty and show "No Results" overlay
      const tblWrap = document.getElementById('tableWrap');
      if (matchCount === 0) {
        if (activeTab === 'active') tblActive.style.opacity = '0';
        if (activeTab === 'archived') tblArchived.style.opacity = '0';
        noResults.classList.remove('hidden');
        if(tblWrap) tblWrap.classList.add('min-h-[350px]'); // Expand to look pretty!
      } else {
        tblActive.style.opacity = '1';
        tblArchived.style.opacity = '1';
        noResults.classList.add('hidden');
        if(tblWrap) tblWrap.classList.remove('min-h-[350px]'); // Go back to default wrap size
      }
  }, 400); // 400ms loading filter delay
}
function toggleFilter() { document.getElementById('filterPanel').classList.toggle('hidden'); }

function handleExport(btn) {
  if (!btn) return;
  const originalHtml = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-xs"></i> <span class="btn-label">Exporting...</span>';
  window.location.href = 'residentExport.php';
  setTimeout(() => {
    btn.disabled = false;
    btn.innerHTML = originalHtml;
  }, 1500);
}

/* ══════════════════════════════════════════
   EDIT MODAL
══════════════════════════════════════════ */
const FIELD_MAP = [
  ['e_firstname','firstname'],['e_lastname','lastname'],['e_middlename','middlename'],
  ['e_suffix','suffix'],['e_family_role','family_role'],['e_gender','gender'],
  ['e_birthday','birthday'],['e_birthplace','birthplace'],['e_civil_status','civil_status'],
  ['e_citizenship','citizenship'],['e_religion','religion'],['e_ethnicity','ethnicity'],
  ['e_street','street'],['e_barangay','barangay'],['e_city','city'],
  ['e_province','province'],['e_zip','zip'],['e_phone','phone'],['e_email','email'],
  ['e_emergency_contact','emergency_contact'],['e_emergency_phone','emergency_phone'],
  ['e_health_conditions','health_conditions'],['e_employment_status','employment_status'],
  ['e_job_title','job_title'],['e_monthly_income','monthly_income'],
  ['e_voter_id','voter_id'],['e_precinct','precinct'],['e_years_resident','years_resident'],
];

let originalData = {}, currentFront = '', currentBack = '', currentModalUID = null;

function buildIdUploadUrl(filename) {
  if (!filename) return '';
  const escaped = filename.split('/').map(encodeURIComponent).join('/');
  const basePath = window.location.pathname.replace(/\/[^\/]*$/, '/');
  return new URL(`${escaped}`, window.location.origin + basePath).href;
}

function buildIdUploadUrlFallback(filename) {
  if (!filename) return '';
  const escaped = filename.split('/').map(encodeURIComponent).join('/');
  const rootUpload = window.location.pathname.replace(/\/admin\/.*$/, '/uploads/id_verification/');
  return window.location.origin + rootUpload + escaped;
}

function setIdImage(img, filename) {
  if (!img) return;
  if (!filename) { img.src = ''; return; }
  img.onerror = function() {
    img.onerror = null;
    img.src = buildIdUploadUrlFallback(filename);
  };
  img.src = buildIdUploadUrl(filename);
}

function openEditModal(row) {
  const raw = row.getAttribute('data-user');
  if (!raw) return;
  const u = JSON.parse(raw);
  currentModalUID = u.userID;
  document.getElementById('mUserID').value = u.userID;
  document.getElementById('editModalSubtitle').textContent = u.email || 'Update resident information';
  FIELD_MAP.forEach(([id, key]) => {
    const el = document.getElementById(id);
    if (el) el.value = u[key] ?? '';
  });
  const rb = parseInt(u.resident_birth) || 0;
  document.getElementById('e_resident_birth').value = rb;
  const track = document.getElementById('residentBirthToggle');
  rb ? track.classList.add('on') : track.classList.remove('on');
  originalData = {};
  FIELD_MAP.forEach(([id, key]) => { originalData[id] = (u[key] ?? '').toString().trim(); });
  originalData['e_resident_birth'] = rb.toString();
  currentFront = u.frontID || ''; currentBack = u.backID || '';
  const fi = document.getElementById('frontIDImg'), fp = document.getElementById('frontIDPlaceholder');
  const bi = document.getElementById('backIDImg'),  bp = document.getElementById('backIDPlaceholder');
  if (currentFront) { fi.src = buildIdUploadUrl(currentFront); fi.style.display = 'block'; fp.style.display = 'none'; }
  else              { fi.style.display = 'none'; fp.style.display = 'flex'; }
  if (currentBack)  { bi.src = buildIdUploadUrl(currentBack);  bi.style.display = 'block'; bp.style.display = 'none'; }
  else              { bi.style.display = 'none'; bp.style.display = 'flex'; }
  document.querySelectorAll('#editModal .field-input').forEach(el => el.classList.remove('changed'));
  document.getElementById('editUpdateBtn').disabled = true;
  document.getElementById('changesBadge').style.display = 'none';
  attachEditChangeListeners();
  document.getElementById('editModalOverlay').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function attachEditChangeListeners() {
  FIELD_MAP.forEach(([id]) => {
    const el = document.getElementById(id);
    if (!el) return;
    el.oninput = el.onchange = checkChanges;
  });
  document.getElementById('e_resident_birth').addEventListener('change', checkChanges);
}
function checkChanges() {
  let count = 0;
  FIELD_MAP.forEach(([id]) => {
    const el = document.getElementById(id); if (!el) return;
    const changed = el.value.trim() !== (originalData[id] ?? '');
    el.classList.toggle('changed', changed);
    if (changed) count++;
  });
  const rbCur = document.getElementById('e_resident_birth').value;
  if (rbCur !== (originalData['e_resident_birth'] ?? '0')) count++;
  document.getElementById('editUpdateBtn').disabled = (count === 0);
  const badge = document.getElementById('changesBadge');
  if (count > 0) { badge.style.display = 'inline-flex'; document.getElementById('changesCount').textContent = count; }
  else           { badge.style.display = 'none'; }
}
function toggleResidentBirth() {
  const track = document.getElementById('residentBirthToggle');
  const hidden = document.getElementById('e_resident_birth');
  hidden.value = track.classList.toggle('on') ? '1' : '0';
  checkChanges();
}
function closeEditModal() {
  document.getElementById('editModalOverlay').classList.remove('open');
  document.body.style.overflow = '';
  currentModalUID = null;
}
function closeEditModalOnOverlay(e) { if (e.target === document.getElementById('editModalOverlay')) closeEditModal(); }

function reloadAfterSuccess(delayMs = 800) {
  setTimeout(() => { triggerRefresh(); }, delayMs);
}

function collectEditFormData() {
  const data = { userID: document.getElementById('mUserID').value };
  FIELD_MAP.forEach(([id, key]) => {
    const el = document.getElementById(id);
    data[key] = el ? el.value.trim() : '';
  });
  data['resident_birth'] = document.getElementById('e_resident_birth').value;
  return data;
}
function handleUpdate() {
  if (!currentModalUID) return;
  const formData = collectEditFormData();
  showDialog('Save Changes', "Are you sure you want to update this resident's information?", null, 'Update', () => {
    const btn = document.getElementById('editUpdateBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-sm"></i> Saving...';
    fetch('residentUpdate.php', { 
      method: 'POST', 
      headers: { 'Content-Type': 'application/json' }, 
      body: JSON.stringify(formData) 
    })
      .then(r => r.json())
      .then(d => {
        if (d.success) {
          const row = document.querySelector(`#activeTable tr[data-uid="${currentModalUID}"]`);
          if (row) {
            const merged = Object.assign({}, JSON.parse(row.getAttribute('data-user')), formData);
            row.setAttribute('data-user', JSON.stringify(merged));
            const nameParts = [formData.firstname, formData.middlename, formData.lastname, formData.suffix].filter(Boolean);
            const fn = nameParts.join(' ');
            row.querySelector('td:nth-child(2) p:first-child').textContent = fn;
            row.querySelector('td:nth-child(2) p:last-child').textContent = formData.email;
            row.setAttribute('data-name', fn.toLowerCase());
          }
          closeEditModal();
          showToast('success', 'Resident Updated', 'Changes saved successfully.');
          reloadAfterSuccess();
        } else {
          showToast('warning', 'Update Failed', d.message || 'Could not save changes.');
          btn.disabled = false;
          btn.innerHTML = 'Update <i class="fa-solid fa-floppy-disk text-sm"></i>';
        }
      })
      .catch(() => {
        showToast('warning', 'Network Error', 'Please try again.');
        btn.disabled = false;
        btn.innerHTML = 'Update <i class="fa-solid fa-floppy-disk text-sm"></i>';
      });
  });
}

/* ══════════════════════════════════════════
   ADD RESIDENT MODAL
══════════════════════════════════════════ */
const ADD_FIELDS = [
  'a_firstname','a_lastname','a_middlename','a_suffix',
  'a_family_role','a_gender','a_birthday','a_birthplace',
  'a_civil_status','a_citizenship','a_religion','a_ethnicity',
  'a_street','a_barangay','a_city','a_province','a_zip',
  'a_phone','a_email','a_emergency_contact','a_emergency_phone',
  'a_health_conditions','a_employment_status','a_job_title',
  'a_monthly_income','a_voter_id','a_precinct','a_years_resident',
];

function openAddModal() {
  ADD_FIELDS.forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
  document.getElementById('a_resident_birth').value = '0';
  document.getElementById('addResidentBirthToggle').classList.remove('on');
  document.querySelectorAll('#addModal .field-input').forEach(el => el.classList.remove('error'));
  document.getElementById('addModalOverlay').classList.add('open');
  document.body.style.overflow = 'hidden';
  setTimeout(() => document.getElementById('a_firstname')?.focus(), 100);
}
function closeAddModal() {
  document.getElementById('addModalOverlay').classList.remove('open');
  document.body.style.overflow = '';
}
function closeAddModalOnOverlay(e) { if (e.target === document.getElementById('addModalOverlay')) closeAddModal(); }

function toggleAddResidentBirth() {
  const track = document.getElementById('addResidentBirthToggle');
  document.getElementById('a_resident_birth').value = track.classList.toggle('on') ? '1' : '0';
}

function collectAddFormData() {
  const get = id => { const el = document.getElementById(id); return el ? el.value.trim() : ''; };
  return {
    firstname: get('a_firstname'), lastname: get('a_lastname'),
    middlename: get('a_middlename'), suffix: get('a_suffix'),
    family_role: get('a_family_role'), gender: get('a_gender'),
    birthday: get('a_birthday'), birthplace: get('a_birthplace'),
    civil_status: get('a_civil_status'), citizenship: get('a_citizenship'),
    religion: get('a_religion'), ethnicity: get('a_ethnicity'),
    street: get('a_street'), barangay: get('a_barangay'),
    city: get('a_city'), province: get('a_province'), zip: get('a_zip'),
    phone: get('a_phone'), email: get('a_email'),
    emergency_contact: get('a_emergency_contact'), emergency_phone: get('a_emergency_phone'),
    health_conditions: get('a_health_conditions'),
    employment_status: get('a_employment_status'), job_title: get('a_job_title'),
    monthly_income: get('a_monthly_income'),
    voter_id: get('a_voter_id'), precinct: get('a_precinct'),
    years_resident: get('a_years_resident'),
    resident_birth: document.getElementById('a_resident_birth').value,
  };
}

const ADD_REQUIRED = {
  a_firstname: 'First Name', a_lastname: 'Last Name',
  a_family_role: 'Family Role', a_gender: 'Gender',
  a_birthday: 'Birthday', a_birthplace: 'Birthplace',
  a_civil_status: 'Civil Status', a_citizenship: 'Citizenship',
  a_street: 'Street Address', a_barangay: 'Barangay',
  a_city: 'City', a_province: 'Province', a_zip: 'ZIP Code',
  a_phone: 'Phone Number', a_email: 'Email',
  a_employment_status: 'Employment Status', a_years_resident: 'Years as Resident',
};

function validateAddForm() {
  let valid = true;
  document.querySelectorAll('#addModal .field-input').forEach(el => el.classList.remove('error'));
  for (const [id, label] of Object.entries(ADD_REQUIRED)) {
    const el = document.getElementById(id);
    if (!el || el.value.trim() === '') {
      el?.classList.add('error');
      if (valid) {
        el?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        showToast('warning', 'Required Field', `"${label}" is required.`);
      }
      valid = false;
    }
  }
  if (valid) {
    const emailEl = document.getElementById('a_email');
    const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRe.test(emailEl.value.trim())) {
      emailEl.classList.add('error');
      emailEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
      showToast('warning', 'Invalid Email', 'Please enter a valid email address.');
      valid = false;
    }
  }
  return valid;
}

function handleAddResident() {
  if (!validateAddForm()) return;
  const formData = collectAddFormData();
  const btn = document.getElementById('addSubmitBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-sm"></i> Adding...';
  fetch('residentAdd.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(formData)
  })
  .then(r => r.json())
  .then(d => {
    if (d.success) {
      const emptyRow = document.getElementById('emptyActiveRow');
      if (emptyRow) emptyRow.remove();
      const u = d.data;
      const fn = [u.firstname, u.middlename ? u.middlename + ' ' : '', u.lastname, u.suffix].filter(Boolean).join(' ');
      const dateDisplay = new Date(u.dateRegistered).toLocaleDateString('en-US', { year:'numeric', month:'long', day:'numeric' });
      const tr = document.createElement('tr');
      tr.setAttribute('data-uid',   u.userID);
      tr.setAttribute('data-name',  fn.toLowerCase());
      tr.setAttribute('data-date',  u.dateRegistered.substring(0, 10));
      tr.setAttribute('data-roles', 'resident');
      tr.setAttribute('data-user',  JSON.stringify(u));
      tr.innerHTML = `
        <td><input type="checkbox" class="row-check rounded w-4 h-4 accent-green-600"></td>
        <td>
          <p class="font-bold text-gray-900 text-sm">${escHtml(fn)}</p>
          <p class="text-gray-400 text-xs">${escHtml(u.email)}</p>
        </td>
        <td class="col-role"><span class="role-chip chip-resident">resident</span></td>
        <td class="text-gray-500 text-sm col-date">${dateDisplay}</td>
        <td>
          <div class="flex items-center justify-end gap-2">
            <button class="icon-btn icon-btn-edit" title="Edit" onclick="openEditModal(this.closest('tr'))">
              <i class="fa-solid fa-pen-to-square text-xs"></i>
            </button>
            <button class="icon-btn icon-btn-archive" title="Archive"
              onclick="confirmArchive(${u.userID},'${escHtml(fn).replace(/'/g,"\\'")}',this.closest('tr'))">
              <i class="fa-solid fa-box-archive text-xs"></i>
            </button>
          </div>
        </td>`;
      const tbody = document.getElementById('activeTableBody');
      tbody.insertBefore(tr, tbody.firstChild);
      const countEl = document.getElementById('residentCount');
      countEl.textContent = parseInt(countEl.textContent || 0) + 1;
      closeAddModal();
      renderPagination();
      showToast('success', 'Resident Added', `${fn} has been registered successfully.`);
      reloadAfterSuccess();
    } else {
      showToast('warning', 'Add Failed', d.message || 'Could not add resident.');
    }
  })
  .catch(() => showToast('warning', 'Network Error', 'Please try again.'))
  .finally(() => {
    btn.disabled = false;
    btn.innerHTML = 'Add Resident <i class="fa-solid fa-user-plus text-sm"></i>';
  });
}

function escHtml(str) {
  return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ── Lightbox ── */
function openLightbox(side) {
  const src = side === 'front' ? currentFront : currentBack;
  if (!src) return;
  document.getElementById('lightboxImg').src = buildIdUploadUrl(src);
  document.getElementById('lightbox').classList.add('open');
}
function closeLightbox() { document.getElementById('lightbox').classList.remove('open'); }

/* ── Confirm Dialog ── */
let dialogCallback = null;
function showDialog(title, desc, nameBadge, confirmLabel, onConfirm, isDanger = false) {
  const overlay     = document.getElementById('dialogOverlay');
  const iconWrap    = document.getElementById('dialogIconWrap');
  const iconEl      = document.getElementById('dialogIconEl');
  const confirmBtn  = document.getElementById('dialogConfirmBtn');
  const confirmIcon = document.getElementById('dialogConfirmIcon');
  const confirmLbl  = document.getElementById('dialogConfirmLabel');
  const nameBadgeEl = document.getElementById('dialogNameBadge');
  
  iconWrap.className = 'dialog-icon-wrap';
  confirmBtn.className = 'dbtn dbtn-confirm';

  if (isDanger) {
    iconWrap.classList.add('dialog-icon-reject'); iconEl.className = 'fa-solid fa-xmark';
    confirmBtn.classList.add('danger'); confirmIcon.className = 'fa-solid fa-box-archive';
  } else {
    iconWrap.classList.add('dialog-icon-approve'); iconEl.className = 'fa-solid fa-check';
    confirmIcon.className = 'fa-solid fa-check';
  }

  document.getElementById('dialogTitle').textContent = title || 'Confirm Action';
  document.getElementById('dialogDesc').textContent  = desc  || 'Are you sure?';
  confirmLbl.textContent = confirmLabel || 'Confirm';

  if (nameBadge) { nameBadgeEl.textContent = nameBadge; nameBadgeEl.style.display = 'inline-block'; }
  else { nameBadgeEl.style.display = 'none'; }

  dialogCallback = onConfirm;
  confirmBtn.onclick = () => { closeDialog(); if (dialogCallback) dialogCallback(); };
  overlay.classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeDialog() { 
  document.getElementById('dialogOverlay').classList.remove('open');
  document.body.style.overflow = '';
}
document.getElementById('dialogOverlay').addEventListener('click', function(e) { if (e.target === this) closeDialog(); });

function setActionButtonLoading(btn, label = 'Loading...') {
  if (!btn) return null;
  const original = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin text-xs"></i> ${label}`;
  return () => {
    btn.disabled = false;
    btn.innerHTML = original;
  };
}

/* ── Archive / Unarchive ── */
function confirmArchive(uid, name, row, triggerBtn = null) {
  showDialog('Archive Resident', `This will archive the account. They will lose active access.`, null, 'Yes, Archive', 
    () => doAction(uid, 'archive', row, name, triggerBtn), true);
}
function confirmUnarchive(uid, name, count, row, triggerBtn = null) {
  showDialog('Unarchive Account', `This will restore the account as an active member.`, null, 'Yes, Unarchive',
    () => doAction(uid, 'unarchive', row, name, triggerBtn));
}
function bulkArchive(triggerBtn = null) {
  const rows = Array.from(document.querySelectorAll('#activeTable .row-check:checked')).map(c => c.closest('tr'));
  if (!rows.length) return;
  showDialog('Archive Selected', `Are you sure you want to archive ${rows.length} resident${rows.length>1?'s':''}?`, null, 'Yes, Archive', async () => {
    const resetBtn = setActionButtonLoading(triggerBtn, 'Archiving...');
    let succeeded = 0, blocked = 0;
    for (const r of rows) {
      const result = await doAction(+r.dataset.uid, 'archive', r, '', null, false, true);
      if (result.success) succeeded++; else blocked++;
    }
    if (resetBtn) resetBtn();
    if (succeeded > 0 && blocked === 0) {
      showToast('warning', `${succeeded} Archived`, 'Moved to Archived tab.');
    } else if (succeeded > 0 && blocked > 0) {
      showToast('warning', `${succeeded} of ${rows.length} Archived`, `${blocked} skipped - they still have pending requests/applications/borrowings.`);
    } else {
      showToast('error', 'None Archived', 'All selected residents still have pending requests/applications/borrowings.');
    }
    document.getElementById('checkAll').checked = false;
    reloadAfterSuccess();
  }, true);
}
function bulkUnarchive(triggerBtn = null) {
  const rows = Array.from(document.querySelectorAll('#archivedTable .row-check:checked')).map(c => c.closest('tr'));
  if (!rows.length) return;
  showDialog('Unarchive Selected', `Are you sure you want to unarchive ${rows.length} resident${rows.length>1?'s':''}?`, null, 'Yes, Unarchive', async () => {
    const resetBtn = setActionButtonLoading(triggerBtn, 'Restoring...');
    for (const r of rows) await doAction(+r.dataset.uid, 'unarchive', r, '', null, false);
    if (resetBtn) resetBtn();
    showToast('success', `${rows.length} Restored`, 'Back to active.');
    document.getElementById('checkAll').checked = false;
    reloadAfterSuccess();
  });
}
function doAction(uid, action, row, name, triggerBtn = null, shouldReload = true, silent = false) {
  const resetBtn = setActionButtonLoading(triggerBtn, action === 'archive' ? 'Archiving...' : 'Restoring...');
  return fetch('residentAction.php', {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:`userID=${uid}&action=${action}`
  }).then(r => r.json()).then(d => {
    if (d.success) {
      if (row) row.remove();
      renderPagination();
      if (name) showToast(action==='archive'?'warning':'success',
        action==='archive'?'Archived':'Restored',
        action==='archive'?`${name} has been archived.`:`${name} is now active.`);
      if (shouldReload) reloadAfterSuccess();
    } else if (!silent) {
      showToast('error', action==='archive'?'Cannot Archive':'Cannot Restore', d.message || 'Something went wrong.');
    }
    if (resetBtn) resetBtn();
    return { success: d.success, message: d.message || '' };
  }).catch(() => {
    if (!silent) showToast('error', 'Network Error', 'Please try again.');
    if (resetBtn) resetBtn();
    return { success: false, message: 'Network error.' };
  });
}

/* ── Pagination ── */
const ROWS = 10; let currentPage = 1;
function getVisibleRows() {
  const sel = activeTab === 'active' ? '#activeTable' : '#archivedTable';
  return Array.from(document.querySelectorAll(sel + ' tbody tr[data-uid]')).filter(r => r.dataset.filteredout !== 'true');
}
function renderPagination() {
  const rows = getVisibleRows(), total = rows.length, pages = Math.max(1, Math.ceil(total / ROWS));
  if (currentPage > pages) currentPage = pages;
  rows.forEach((r, i) => { r.style.display = (Math.floor(i/ROWS)+1 === currentPage) ? '' : 'none'; });
  const c = document.getElementById('paginationContainer'); c.innerHTML = '';
  const prev = document.createElement('button');
  prev.className = 'page-btn'; prev.disabled = currentPage === 1;
  prev.innerHTML = '<i class="fa-solid fa-chevron-left text-xs"></i>';
  prev.onclick = () => { currentPage--; renderPagination(); };
  c.appendChild(prev);
  let s = Math.max(1, currentPage-2), e = Math.min(pages, s+4);
  if (e-s < 4) s = Math.max(1, e-4);
  for (let p = s; p <= e; p++) {
    const b = document.createElement('button');
    b.className = 'page-btn' + (p === currentPage ? ' active' : '');
    b.textContent = p;
    b.onclick = () => { currentPage = p; renderPagination(); };
    c.appendChild(b);
  }
  const next = document.createElement('button');
  next.className = 'page-btn'; next.disabled = currentPage === pages;
  next.innerHTML = '<i class="fa-solid fa-chevron-right text-xs"></i>';
  next.onclick = () => { currentPage++; renderPagination(); };
  c.appendChild(next);
}
renderPagination();

/* ══════════════════════════════════════════
   BUILD EDIT MODAL FORM FIELDS (injected by JS)
══════════════════════════════════════════ */
(function buildEditModalBody() {
  const body = document.getElementById('editModalBody');
  if (!body) return;
  const html = `
  <div class="section-card">
    <div class="section-title"><div class="section-icon"><i class="fa-solid fa-user text-green-700 text-sm"></i></div>Personal Information</div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
      <div><label class="field-label">First Name <span class="required-star">*</span></label><input type="text" id="e_firstname" maxlength="100" class="field-input" placeholder="First name"></div>
      <div><label class="field-label">Last Name <span class="required-star">*</span></label><input type="text" id="e_lastname" maxlength="100" class="field-input" placeholder="Last name"></div>
      <div><label class="field-label">Middle Name</label><input type="text" id="e_middlename" maxlength="100" class="field-input" placeholder="Middle name"></div>
      <div><label class="field-label">Suffix</label><input type="text" id="e_suffix" maxlength="20" class="field-input" placeholder="e.g. Jr., Sr., III"></div>
      <div><label class="field-label">Family Role <span class="required-star">*</span></label>
        <select id="e_family_role" class="field-input"><option value="">Select</option><option value="head">Head of Family</option><option value="spouse">Spouse</option><option value="child">Child</option><option value="parent">Parent</option><option value="other">Other</option></select></div>
      <div><label class="field-label">Gender <span class="required-star">*</span></label>
        <select id="e_gender" class="field-input"><option value="">Select</option><option value="male">Male</option><option value="female">Female</option><option value="other">Other</option></select></div>
      <div><label class="field-label">Birthday <span class="required-star">*</span></label><input type="date" id="e_birthday" max="<?= date('Y-m-d') ?>" class="field-input"></div>
      <div><label class="field-label">Birthplace <span class="required-star">*</span></label><input type="text" id="e_birthplace" maxlength="200" class="field-input" placeholder="City, Province, Country"></div>
      <div><label class="field-label">Civil Status <span class="required-star">*</span></label>
        <select id="e_civil_status" class="field-input"><option value="">Select</option><option value="single">Single</option><option value="married">Married</option><option value="divorced">Divorced</option><option value="widowed">Widowed</option><option value="separated">Separated</option></select></div>
      <div><label class="field-label">Citizenship <span class="required-star">*</span></label><input type="text" id="e_citizenship" maxlength="100" class="field-input" placeholder="e.g. Filipino"></div>
      <div><label class="field-label">Religion</label><input type="text" id="e_religion" maxlength="100" class="field-input" placeholder="e.g. Catholic"></div>
      <div><label class="field-label">Ethnicity</label><input type="text" id="e_ethnicity" maxlength="100" class="field-input" placeholder="e.g. Tagalog"></div>
    </div>
  </div>
  <div class="section-card">
    <div class="section-title"><div class="section-icon"><i class="fa-solid fa-location-dot text-green-700 text-sm"></i></div>Complete Address Information</div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
      <div><label class="field-label">Street Address <span class="required-star">*</span></label><input type="text" id="e_street" maxlength="200" class="field-input" placeholder="Street name and number"></div>
      <div><label class="field-label">Barangay <span class="required-star">*</span></label><input type="text" id="e_barangay" maxlength="100" class="field-input"></div>
      <div><label class="field-label">City / Municipality <span class="required-star">*</span></label><input type="text" id="e_city" maxlength="100" class="field-input"></div>
      <div><label class="field-label">Province <span class="required-star">*</span></label><input type="text" id="e_province" maxlength="100" class="field-input"></div>
      <div><label class="field-label">ZIP Code <span class="required-star">*</span></label><input type="text" id="e_zip" maxlength="10" class="field-input"></div>
    </div>
  </div>
  <div class="section-card">
    <div class="section-title"><div class="section-icon"><i class="fa-solid fa-phone text-green-700 text-sm"></i></div>Contact and Health Information</div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
      <div><label class="field-label">Phone Number <span class="required-star">*</span></label><input type="tel" id="e_phone" maxlength="20" class="field-input" placeholder="+63 912 345 6789"><span class="text-gray-400 text-xs mt-1 block">Format: +639XXXXXXXXX or 09XXXXXXXXX</span></div>
      <div><label class="field-label">Email <span class="required-star">*</span></label><input type="email" id="e_email" maxlength="254" class="field-input"></div>
      <div><label class="field-label">Emergency Contact</label><input type="text" id="e_emergency_contact" maxlength="150" class="field-input" placeholder="Name of emergency contact"></div>
      <div><label class="field-label">Emergency Contact Phone</label><input type="tel" id="e_emergency_phone" maxlength="20" class="field-input"></div>
      <div class="md:col-span-2"><label class="field-label">Blood Type</label><input type="text" id="e_health_conditions" maxlength="10" class="field-input" placeholder="e.g. O+, A-, B+"></div>
    </div>
  </div>
  <div class="section-card">
    <div class="section-title"><div class="section-icon"><i class="fa-solid fa-briefcase text-green-700 text-sm"></i></div>Employment Information</div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
      <div><label class="field-label">Employment Status <span class="required-star">*</span></label>
        <select id="e_employment_status" class="field-input"><option value="">Select</option><option value="employed">Employed</option><option value="self-employed">Self-Employed</option><option value="unemployed">Unemployed</option><option value="student">Student</option><option value="retired">Retired</option><option value="other">Other</option></select></div>
      <div><label class="field-label">Job Title</label><input type="text" id="e_job_title" maxlength="150" class="field-input" placeholder="Your job title"></div>
      <div><label class="field-label">Monthly Income (PHP)</label><input type="number" id="e_monthly_income" min="0" max="9999999" step="1" class="field-input" placeholder="e.g. 25000"></div>
    </div>
  </div>
  <div class="section-card">
    <div class="section-title"><div class="section-icon"><i class="fa-solid fa-check-to-slot text-green-700 text-sm"></i></div>Voter Information</div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
      <div><label class="field-label">Voter ID Number</label><input type="text" id="e_voter_id" maxlength="50" class="field-input" placeholder="Voter ID if applicable"></div>
      <div><label class="field-label">Precinct Number</label><input type="text" id="e_precinct" maxlength="50" class="field-input" placeholder="Precinct number"></div>
    </div>
  </div>
  <div class="section-card">
    <div class="section-title"><div class="section-icon"><i class="fa-solid fa-house text-green-700 text-sm"></i></div>Residency Information</div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
      <div><label class="field-label">Years as Resident <span class="required-star">*</span></label><input type="number" id="e_years_resident" min="0" max="120" step="1" class="field-input" placeholder="Number of years"></div>
      <div class="flex items-end pb-1">
        <label class="flex items-center gap-3 cursor-pointer select-none" onclick="toggleResidentBirth()">
          <div class="toggle-track" id="residentBirthToggle"><div class="toggle-thumb"></div></div>
          <span class="text-sm font-semibold text-gray-700">Resident since Birth</span>
        </label>
        <input type="hidden" id="e_resident_birth" value="0">
      </div>
    </div>
  </div>`;
  const idSection = body.querySelector('.section-card');
  if (idSection) idSection.insertAdjacentHTML('afterend', html);
})();

/* ════════════════════════════════════════════
   SIDEBAR NAVIGATION LOADER
════════════════════════════════════════════ */
document.querySelectorAll('.menu-item, .side-link, .sidebar-logo button').forEach(btn => {
  btn.addEventListener('click', function() {
    const hasTarget = this.getAttribute('data-nav') || this.getAttribute('onclick');
    if(hasTarget && this.id !== 'collapseBtn' && this.id !== 'expandBtn') {
      const loader = document.getElementById('realtimeLoader');
      const mainData = document.getElementById('mainDataContainer');
      if(loader && mainData) {
        mainData.style.display = 'none';
        loader.style.display = 'flex';
        const txt = loader.querySelector('p');
        if(txt) txt.textContent = 'Loading ' + (this.innerText.trim() || 'page') + '...';
      }
    }
  });
});

  // Show loader on navigation
  document.querySelectorAll('[onclick^="window.location.href"], a[href]').forEach(btn => {
    btn.addEventListener('click', function(e) {
      if (this.tagName === 'A' && (this.target === '_blank' || this.href.includes('#') || this.href.startsWith('javascript:'))) return;
      const loader = document.getElementById('pageLoader');
      if(loader) {
        loader.classList.remove('opacity-0', 'pointer-events-none');
        loader.classList.add('opacity-100');
      }
    });
  });
</script>
</body>
</html>
