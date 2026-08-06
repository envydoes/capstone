<?php
session_start();

// 1. Session Gate
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$role = $_SESSION['account_role'] ?? '';
require_once __DIR__ . '/../includes/check_permissions.php';

require_once __DIR__ . '/../config/db_connection.php';

$myPerms = get_my_permissions($conn);
if ($role !== 'admin' && empty($myPerms)) {
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
require_permission($conn, 'manage_announcements');

// 5. Helper functions & Site Config
if (!function_exists('e')) {
    function e($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
}

function rowJson($u) {
    return htmlspecialchars(json_encode($u, JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS), ENT_QUOTES, 'UTF-8');
}

require_once '../includes/site_config.php';
$siteSettings = site_config_load($conn);

ob_start();

// Fetch announcements
$res = mysqli_query($conn, "SELECT * FROM tbl_announcement ORDER BY announcementPost DESC");
$announcements = [];
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $announcements[] = $r;
    }
}
$total = count($announcements);

// �"?�"? Stat cards: Announcements Overview �"?�"?�"?�"?�"?�"?�"?�"?�"?�"?�"?�"?�"?�"?�"?�"?�"?�"?�"?�"?�"?�"?�"?�"?�"?�"?�"?�"?�"?�"?�"?�"?�"?�"?

// Announcements This Month, vs Last Month
$annTrendRow = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        SUM(CASE WHEN announcementPost >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN 1 ELSE 0 END) AS this_month,
        SUM(CASE WHEN announcementPost >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
                  AND announcementPost <  DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN 1 ELSE 0 END) AS last_month
    FROM tbl_announcement
"));
$annThisMonth = (int) ($annTrendRow['this_month'] ?? 0);
$annLastMonth = (int) ($annTrendRow['last_month'] ?? 0);
if ($annLastMonth > 0) {
    $annTrendPct = (int) round((($annThisMonth - $annLastMonth) / $annLastMonth) * 100);
} else {
    $annTrendPct = $annThisMonth > 0 ? 100 : 0;
}
$annTrendDir = $annThisMonth > $annLastMonth ? 'up' : ($annThisMonth < $annLastMonth ? 'down' : 'flat');

// Average Posting Frequency: mean number of days between consecutive posts.
// Computed in PHP over the sorted post dates rather than in SQL, since it
// only needs one pass and avoids relying on window-function support.
$postDatesRow = mysqli_query($conn, "
    SELECT announcementPost FROM tbl_announcement
    WHERE announcementPost IS NOT NULL
    ORDER BY announcementPost ASC
");
$postDates = [];
if ($postDatesRow) {
    while ($r = mysqli_fetch_assoc($postDatesRow)) {
        $postDates[] = $r['announcementPost'];
    }
}
$avgPostingFrequencyDays = null;
if (count($postDates) >= 2) {
    $gaps = [];
    for ($i = 1; $i < count($postDates); $i++) {
        $gaps[] = (strtotime($postDates[$i]) - strtotime($postDates[$i - 1])) / 86400;
    }
    $avgPostingFrequencyDays = round(array_sum($gaps) / count($gaps), 1);
}

// Longest Gap Since Last Announcement: days between the most recent post and today
$lastPostRow = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT MAX(announcementPost) AS last_post FROM tbl_announcement
"));
$daysSinceLastPost = null;
if ($lastPostRow && $lastPostRow['last_post']) {
    $daysSinceLastPost = (int) floor((strtotime(date('Y-m-d')) - strtotime($lastPostRow['last_post'])) / 86400);
}

// Most Active Category: tag with the most posts
$topTagRow = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT announcementTag, COUNT(*) AS total
    FROM tbl_announcement
    WHERE announcementTag IS NOT NULL AND announcementTag != ''
    GROUP BY announcementTag
    ORDER BY total DESC
    LIMIT 1
"));
$topTagName  = $topTagRow['announcementTag'] ?? null;
$topTagCount = (int) ($topTagRow['total'] ?? 0);

// Note: If you close $conn here, ensure no other DB queries are executed later in the HTML/footer!
// mysqli_close($conn); 

function tagBadge(string $tag): string {
    return match(strtolower(trim($tag))) {
        'health'     => 'chip-health',
        'assistance' => 'chip-assistance',
        'community'  => 'chip-community',
        'education'  => 'chip-education',
        'safety'     => 'chip-safety',
        'event'      => 'chip-event',
        default      => 'chip-default',
    };
}

// Flush early for progressive rendering
echo str_repeat("<!-- pad -->\n", 80);
ob_flush(); 
flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($siteSettings['site_title']) ?></title>
<link rel="icon" href="<?= e(site_config_logo_url($siteSettings, '../')) ?>" type="image/png">
<?= site_config_css_vars($siteSettings) ?>
  <link rel="icon" href="../assets/logo2.png" type="image/png">
  <script src="https://cdn.tailwindcss.com/3.4.16"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    * { box-sizing: border-box; }
    body { font-family: 'DM Sans', sans-serif; background: var(--site-primary-pale); margin: 0; }
    :root {
      --site-primary-dark:   color-mix(in srgb, var(--site-primary) 55%, black);
      --site-primary-darker: color-mix(in srgb, var(--site-primary) 75%, black);
      --site-primary-light:  color-mix(in srgb, var(--site-primary) 55%, white);
      --site-primary-pale:   color-mix(in srgb, var(--site-primary) 12%, white);
    }

    /* �"?�"? Sidebar �"?�"? */
    .sidebar {
      width: 260px; flex-shrink: 0;
      background: linear-gradient(180deg, var(--site-primary-dark) 0%, var(--site-primary-darker) 55%, var(--site-primary) 100%);
      display: flex; flex-direction: column;
      position: fixed; top: 0; left: 0; height: 100vh; z-index: 300;
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
    .menu-item:hover { background: rgba(255,255,255,0.07); color: #fff; }
    .menu-item.active { background: rgba(255,255,255,0.13); color: #fff; }
    .menu-left { display: flex; align-items: center; gap: 11px; }
    .mi { width: 17px; text-align: center; font-size: 0.85rem; flex-shrink: 0; }
    .active-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--site-primary-light); flex-shrink: 0; }
    .collapse-btn { width: 28px; height: 28px; border-radius: 8px; background: rgba(255,255,255,0.1); border: none; cursor: pointer; color: #fff; font-size: 0.72rem; display: flex; align-items: center; justify-content: center; flex-shrink: 0; transition: background 0.2s; }
    .collapse-btn:hover { background: rgba(255,255,255,0.22); }
    .expand-btn { position: fixed; top: 18px; left: 12px; z-index: 200; width: 36px; height: 36px; border-radius: 10px; background: var(--site-primary-darker); border: 1px solid rgba(134,239,172,0.25); color: #fff; font-size: 0.82rem; cursor: pointer; display: none; align-items: center; justify-content: center; box-shadow: 0 4px 16px rgba(5,46,22,0.4); transition: background 0.2s; }
    .expand-btn.visible { display: flex; }
    .expand-btn:hover { background: var(--site-primary); }
    .sidebar-backdrop { display: none; position: fixed; inset: 0; z-index: 250; background: rgba(5,46,22,0.5); backdrop-filter: blur(2px); }
    .sidebar-backdrop.visible { display: block; }
    .sidebar-bottom { margin-top: auto; flex-shrink: 0; }
    .sidebar-bottom-links { padding: 0 16px 8px; }
    .side-link { display: block; width: 100%; font-size: 0.84rem; padding: 8px 8px; border-radius: 8px; transition: color 0.15s, background 0.15s; text-decoration: none; white-space: nowrap; border: none; background: none; text-align: left; cursor: pointer; }

    /* �"?�"? Layout �"?�"? */
    .main-wrapper { display: flex; min-height: 100vh; }
    .main-content { flex: 1; min-width: 0; display: flex; flex-direction: column; margin-left: 260px; transition: margin-left 0.3s cubic-bezier(0.4,0,0.2,1); overflow-x: hidden; }
    .main-content.sidebar-collapsed { margin-left: 0; }

    /* �"?�"? Topbar �"?�"? */
    .topbar { background: #fff; border-bottom: 1px solid #e5e7eb; padding: 14px 28px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; position: sticky; top: 0; z-index: 100; }

    /* �"?�"? Stat cards �"?�"? */
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

    /* �"?�"? Tag chips �"?�"? */
    .tag-chip { display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 999px; font-size: 0.68rem; font-weight: 600; border: 1px solid; white-space: nowrap; }
    .chip-health     { background: #f0fdf4; color: #15803d; border-color: #86efac; }
    .chip-assistance { background: #eff6ff; color: #1d4ed8; border-color: #93c5fd; }
    .chip-community  { background: #fff7ed; color: #c2410c; border-color: #fdba74; }
    .chip-education  { background: #faf5ff; color: #7c3aed; border-color: #c4b5fd; }
    .chip-safety     { background: #fef2f2; color: #dc2626; border-color: #fca5a5; }
    .chip-event      { background: #fefce8; color: #a16207; border-color: #fde047; }
    .chip-default    { background: #f9fafb; color: #6b7280; border-color: #e5e7eb; }

    /* �"?�"? Toolbar & Search �"?�"? */
    .toolbar { background: #fff; border: 1px solid #e5e7eb; border-bottom: none; padding: 10px 16px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; border-radius: 14px 14px 0 0; }
    .search-box { display: flex; align-items: center; gap: 8px; border: 1.5px solid #e5e7eb; border-radius: 9px; padding: 7px 12px; background: #fff; transition: border-color 0.15s; }
    .search-box:focus-within { border-color:var(--site-primary); }
    .search-box input { border: none; outline: none; font-size: 0.83rem; color: #374151; font-family: inherit; min-width: 0; background: transparent; }
    .btn-filter { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 9px; border: 1.5px solid #e5e7eb; background: #fff; font-size: 0.83rem; font-weight: 600; color: #374151; cursor: pointer; transition: all 0.15s; white-space: nowrap; }
    .btn-filter:hover { border-color: var(--site-primary); color: var(--site-primary); }
    .btn-add { display: inline-flex; align-items: center; gap: 7px; padding: 8px 16px; border-radius: 9px; background: var(--site-primary); color: #fff; font-size: 0.83rem; font-weight: 700; border: none; cursor: pointer; transition: background 0.15s; white-space: nowrap; }
    .btn-add:hover { background: var(--site-primary-dark); }
    .btn-refresh { width: 30px; height: 30px; border-radius: 8px; border: 1.5px solid #e5e7eb; background: #fff; color: #6b7280; font-size: 0.82rem; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.15s; flex-shrink: 0; }
    .btn-refresh:hover { border-color: var(--site-primary); color: var(--site-primary); }
    .icon-btn { width: 30px; height: 30px; border-radius: 8px; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; transition: all 0.15s; flex-shrink: 0; }
    .icon-btn-delete { background: #fef2f2; color: #dc2626; }
    .icon-btn-delete:hover { background: #fee2e2; }
    .toolbar-divider { width: 1px; height: 22px; background: #e5e7eb; }

    /* �"?�"? Announcement list items �"?�"? */
    .ann-list { background: #fff; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 14px 14px; }
    .ann-item { display: flex; align-items: flex-start; gap: 14px; padding: 16px; border-bottom: 1px solid #f3f4f6; transition: background 0.12s; cursor: pointer; }
    .ann-item:last-child { border-bottom: none; }
    .ann-item:hover { background: #f0fdf4; }
    .ann-item.selected { background: #f0fdf4; }
    .ann-checkbox { margin-top: 2px; flex-shrink: 0; width: 16px; height: 16px; accent-color: #16a34a; cursor: pointer; }
    .ann-body-col { flex: 1; min-width: 0; }
    .ann-title { font-size: 0.9rem; font-weight: 700; color: #111827; margin-bottom: 3px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .ann-desc { font-size: 0.8rem; color: #6b7280; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 600px; }
    .ann-meta { display: flex; align-items: center; gap: 6px; font-size: 0.72rem; color: #9ca3af; margin-top: 6px; }
    .ann-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
    .ann-action-link { display: inline-flex; align-items: center; gap: 6px; padding: 7px 11px; border-radius: 999px; font-size: 0.78rem; font-weight: 700; border: 1px solid transparent; transition: all 0.18s ease; cursor: pointer; text-decoration: none; }
    .ann-action-link.edit { color: #14532d; background: #ecfdf5; border-color: #d1fae5; border-radius: 8px;}
    .ann-action-link.edit:hover { background: #16a34a; color: #fff; box-shadow: 0 6px 18px rgba(22, 163, 74, 0.12); }
    .ann-action-link.del { color: #991b1b; background: #fef2f2; border-color: #fecaca; border-radius: 8px;}
    .ann-action-link.del:hover {background: #ef4444; color: #fff; box-shadow: 0 6px 18px rgba(220, 38, 38, 0.12); }
    .ann-action-link .fa-solid { font-size: 0.72rem; }

    /* �"?�"? Empty state �"?�"? */
    .empty-state { padding: 52px 24px; text-align: center; color: #9ca3af; }
    .empty-state i { font-size: 2.2rem; margin-bottom: 10px; display: block; color: #d1d5db; }

    /* �"?�"? Pagination �"?�"? */
    .page-btn { width: 34px; height: 34px; border-radius: 8px; border: 1.5px solid #e5e7eb; background: #fff; font-size: 0.82rem; font-weight: 600; color: #374151; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.15s; flex-shrink: 0; }
    .page-btn:hover { border-color: var(--site-primary); color: var(--site-primary); }
    .page-btn.active { background: var(--site-primary); border-color: var(--site-primary); color: #fff; }
    .page-btn:disabled { opacity: 0.35; cursor: default; }

    /* �.��.� MODAL �.��.� */
    .modal-overlay { position: fixed; inset: 0; z-index: 800; background: rgba(5,46,22,0.45); backdrop-filter: blur(4px); display: flex; align-items: flex-start; justify-content: center; padding: 16px; overflow-y: auto; opacity: 0; pointer-events: none; transition: opacity 0.22s; }
    .modal-overlay.open { opacity: 1; pointer-events: auto; }
    .modal { background: #fff; border-radius: 18px; width: 100%; max-width: 640px; box-shadow: 0 24px 60px rgba(5,46,22,0.22); transform: translateY(16px); transition: transform 0.25s cubic-bezier(0.4,0,0.2,1); margin: auto; display: flex; flex-direction: column; }
    .modal-overlay.open .modal { transform: translateY(0); }
    .modal-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 18px 12px; border-bottom: 1px solid #f3f4f6; flex-shrink: 0; gap: 8px; }
    .modal-close { width: 28px; height: 28px; border-radius: 8px; border: none; background: #f3f4f6; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #6b7280; font-size: 0.78rem; transition: background 0.15s, color 0.15s; flex-shrink: 0; }
    .modal-close:hover { background: #fee2e2; color: #dc2626; }
    .modal-body { padding: 16px 18px; overflow-y: auto; max-height: calc(100vh - 160px); }
    .modal-body::-webkit-scrollbar { width: 4px; }
    .modal-body::-webkit-scrollbar-thumb { background: #d1fae5; border-radius: 4px; }
    .modal-footer { display: grid; grid-template-columns: 1fr 1fr; border-top: 1px solid #f3f4f6; flex-shrink: 0; }
    .mf-btn { padding: 14px; border: none; font-size: 0.88rem; font-weight: 700; cursor: pointer; font-family: inherit; transition: all 0.15s; display: flex; align-items: center; justify-content: center; gap: 6px; }
    .mf-cancel { background: #f9fafb; color: #374151; border-radius: 0 0 0 18px; }
    .mf-cancel:hover { background:#fee2e2;color:#dc2626}
    .mf-submit { background: linear-gradient(135deg, #16a34a 0%, #15803d 100%); color: #fff; border-radius: 0 0 18px 0; font-weight: 800; box-shadow: 0 4px 12px rgba(22, 163, 74, 0.3); transition: all 0.15s cubic-bezier(0.34, 1.56, 0.64, 1); }
    .mf-submit:hover:not(:disabled) { background:#166534; box-shadow: 0 6px 20px rgba(22, 163, 74, 0.5); transform: none; color: #fff;}
    .mf-submit:disabled { background: #d1d5db; color: #9ca3af; cursor: not-allowed; box-shadow: none; transform: none; }

    /* �"?�"? Form fields �"?�"? */
    .field-label { display: block; font-size: 0.72rem; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 5px; }
    .required-star { color: #dc2626; }
    .field-input { width: 100%; padding: 9px 12px; border: 1.5px solid #e5e7eb; border-radius: 9px; font-size: 0.84rem; color: #374151; background: #fff; font-family: inherit; outline: none; transition: border-color 0.15s, box-shadow 0.15s; }
    .field-input:focus { border-color: #16a34a; box-shadow: 0 0 0 3px rgba(22,163,74,0.1); }
    .field-input.error { border-color: #dc2626; }
    .field-textarea { width: 100%; padding: 9px 12px; border: 1.5px solid #e5e7eb; border-radius: 9px; font-size: 0.84rem; color: #374151; background: #fff; font-family: inherit; outline: none; resize: vertical; min-height: 90px; transition: border-color 0.15s, box-shadow 0.15s; }
    .field-textarea:focus { border-color: #16a34a; box-shadow: 0 0 0 3px rgba(22,163,74,0.1); }

    /* �"?�"? Content split area (title + body) �"?�"? */
    .content-split { border: 1.5px solid #e5e7eb; border-radius: 9px; overflow: hidden; transition: border-color 0.15s, box-shadow 0.15s; }
    .content-split:focus-within { border-color: #16a34a; box-shadow: 0 0 0 3px rgba(22,163,74,0.1); }
    .content-title-input { width: 100%; padding: 10px 12px 8px; border: none; border-bottom: 1.5px dashed #e5e7eb; outline: none; font-size: 1rem; font-weight: 700; color: #111827; font-family: 'Playfair Display', serif; background: #fff; }
    .content-title-input::placeholder { font-weight: 400; color: #9ca3af; font-family: 'DM Sans', sans-serif; }
    .content-body-input { width: 100%; padding: 10px 12px; border: none; outline: none; font-size: 0.84rem; color: #374151; font-family: inherit; resize: vertical; min-height: 160px; background: #fff; }
    .content-body-input::placeholder { color: #9ca3af; }

    /* �"?�"? Image upload list �"?�"? */
    .upload-list { border: 1.5px solid #e5e7eb; border-radius: 9px; overflow: hidden; max-height: 180px; overflow-y: auto; }
    .upload-list:empty { display: none; }
    .upload-item { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-bottom: 1px solid #f3f4f6; background: #fff; }
    .upload-item:last-child { border-bottom: none; }
    .upload-item-thumb { width: 40px; height: 40px; border-radius: 8px; object-fit: cover; background: #f3f4f6; flex-shrink: 0; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; border: 1.5px solid transparent; }
    .upload-item-thumb:hover { transform: scale(1.08); box-shadow: 0 4px 12px rgba(0,0,0,0.15); border-color: #16a34a; }
    .upload-item-name { font-size: 0.8rem; color: #374151; flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .upload-remove { width: 22px; height: 22px; border-radius: 6px; border: none; background: #fef2f2; color: #dc2626; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; flex-shrink: 0; transition: all 0.15s; }
    .upload-remove:hover { background: #fee2e2; }
    .upload-drop-zone { border: 2px dashed #e5e7eb; border-radius: 9px; padding: 16px; text-align: center; color: #9ca3af; font-size: 0.8rem; cursor: pointer; transition: border-color 0.15s, background 0.15s; }
    .upload-drop-zone:hover, .upload-drop-zone.dragover { border-color: #16a34a; background: #f0fdf4; color: #15803d; }

    /* �"?�"? Lightbox for image preview �"?�"? */
    .lightbox-overlay { position: fixed; inset: 0; z-index: 950; background: rgba(0,0,0,0.88); display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity 0.25s; padding: 16px; }
    .lightbox-overlay.open { opacity: 1; pointer-events: auto; }
    .lightbox-overlay img { max-width: 90vw; max-height: 90vh; border-radius: 12px; object-fit: contain; animation: zoomIn 0.3s cubic-bezier(0.34,1.56,0.64,1); }
    .lightbox-close-btn { position: absolute; top: 16px; right: 20px; width: 40px; height: 40px; border-radius: 50%; background: rgba(255,255,255,0.15); border: none; color: #fff; font-size: 1.2rem; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background 0.2s; }
    .lightbox-close-btn:hover { background: rgba(255,255,255,0.25); }
    @keyframes zoomIn { from { transform: scale(0.85); opacity: 0; } to { transform: scale(1); opacity: 1; } }

    /* �"?�"? Loading Overlay �"?�"? */
    .loading-overlay { position: fixed; inset: 0; background: rgba(5, 46, 22, 0.6); backdrop-filter: blur(3px); z-index: 999; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity 0.2s ease; }
    .loading-overlay.show { opacity: 1; pointer-events: auto; }
    .spinner-box { display: flex; flex-direction: column; align-items: center; gap: 12px; }
    .spinner-box i { font-size: 2.2rem; color: #16a34a; }
    .spinner-box p { font-size: 0.85rem; font-weight: 600; color: #fff; letter-spacing: 0.5px; }
    .dialog-overlay { position: fixed; inset: 0; z-index: 900; background: rgba(5,46,22,0.45); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; padding: 20px; opacity: 0; pointer-events: none; transition: opacity 0.2s; }
    .dialog-overlay.open { opacity: 1; pointer-events: auto; }
    .dialog-box { background: #fff; border-radius: 20px; width: 100%; max-width: 380px; box-shadow: 0 24px 64px rgba(5,46,22,0.3); transform: scale(0.94) translateY(12px); transition: transform 0.25s cubic-bezier(0.34,1.56,0.64,1), opacity 0.2s; opacity: 0; overflow: hidden; }
    .dialog-overlay.open .dialog-box { transform: scale(1) translateY(0); opacity: 1; }
    .dialog-icon-wrap { width: 64px; height: 64px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 1.6rem; background: #fee2e2; color: #dc2626; }
    .dialog-icon-wrap.dialog-icon-danger { background: #fee2e2; color: #dc2626; }
    .dialog-body-d { padding: 28px 24px 20px; text-align: center; }
    .dialog-title-d { font-size: 1.1rem; font-weight: 800; color: #111827; margin-bottom: 8px; font-family: 'Playfair Display', serif; }
    .dialog-desc-d  { font-size: 0.85rem; color: #6b7280; line-height: 1.5; }
    .dialog-footer-d { padding: 0 20px 20px; display: flex; gap: 10px; }
    .dbtn { flex: 1; padding: 11px; border-radius: 11px; border: none; font-size: 0.86rem; font-weight: 700; cursor: pointer; font-family: inherit; transition: all 0.15s; display: flex; align-items: center; justify-content: center; gap: 6px; }
    .dbtn-cancel { background: #f3f4f6; color: #374151; }
    .dbtn-cancel:hover { background:#e5e7eb; }
    .dbtn-danger { background: #ef4444; color: #fff; }
    .dbtn-danger:hover { background: #dc2626; }
    .danger { background: #ef4444; color: #fff; }
    .danger:hover { background: #dc2626; }

    /* �"?�"? Alert �"?�"? */
    #alertBanner { display: none; }
    #alertBanner.show { display: flex; }
    .alert-inner { display: flex; align-items: center; gap: 10px; padding: 13px 16px; font-size: 0.85rem; font-weight: 600; border-radius: 10px; border: 1.5px solid transparent; width: 100%; flex-wrap: wrap; }
    .alert-success { background: #f0fdf4; border-color: #bbf7d0; color: #15803d; }
    .alert-error   { background: #fef2f2; border-color: #fecaca; color: #dc2626; }
    .alert-warning { background: #fefce8; border-color: #fde68a; color: #a16207; }
    .alert-close { margin-left: auto; background: none; border: none; cursor: pointer; font-size: 0.8rem; opacity: 0.6; color: inherit; padding: 2px 4px; }
    .alert-close:hover { opacity: 1; }

    @keyframes fadeUp { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
    .fade-up { animation: fadeUp 0.35s ease both; }

    @media (max-width: 1024px) {
      .sidebar { transform: translateX(-100%); width: 260px !important; }
      .sidebar.mobile-open { transform: translateX(0); }
      .main-content { margin-left: 0 !important; }
      .topbar { padding: 12px 16px; }
      .topbar-title-block { margin-left: 46px !important; }
    }
    @media (max-width: 640px) {
      .topbar { padding: 10px 14px; }
      .page-pad { padding: 14px !important; }
      .ann-desc { max-width: 200px; }
      .modal-overlay { padding: 0; align-items: flex-end; }
      .modal { border-radius: 20px 20px 0 0; max-width: 100%; max-height: 95vh; }
      .modal-body { max-height: calc(95vh - 140px); }
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

  <link rel="stylesheet" href="../assets/responsive-global.css">
<div class="sidebar-backdrop" id="sidebarBackdrop" onclick="closeMobileSidebar()"></div>
<button class="expand-btn" id="expandBtn"><i class="fa-solid fa-bars"></i></button>

<div class="main-wrapper">
  <!-- �"?�"? SIDEBAR �"?�"? -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-inner">
      <div class="sidebar-logo">
        <button onclick="location.href='adminLanding'" style="border:none;background:none;padding:0;cursor:pointer;color:inherit;">
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

      <div class="section-label">Management</div>
      <nav class="space-y-0.5 px-2">
        <?php if ($role === 'admin' || in_array('dashboard', $myPerms)): ?>
        <button class="menu-item" onclick="location.href='adminDashboard'"><div class="menu-left"><i class="fa-solid fa-chart-bar mi"></i>Dashboard</div></button>
        <?php endif; ?>
        <?php if ($role === 'admin'): ?>
        <button class="menu-item" onclick="location.href='userManagement'"><div class="menu-left"><i class="fa-solid fa-user mi"></i>User Management</div></button>
        <?php endif; ?>
        <?php if ($role === 'admin' || in_array('manage_residents', $myPerms)): ?>
        <button class="menu-item" onclick="location.href='residentManagement'"><div class="menu-left"><i class="fa-solid fa-house-chimney-user mi"></i>Resident Management</div></button>
        <?php endif; ?>
        <?php if ($role === 'admin' || in_array('manage_beneficiaries', $myPerms)): ?>
        <button class="menu-item" onclick="location.href='beneficiaryManagement'"><div class="menu-left"><i class="fa-solid fa-hand-holding-heart mi"></i>Beneficiary Management</div></button>
        <?php endif; ?>
        <?php if ($role === 'admin' || in_array('manage_documents', $myPerms)): ?>
        <button class="menu-item" onclick="location.href='documentRequest'"><div class="menu-left"><i class="fa-regular fa-file-lines mi"></i>Document Request</div></button>
        <?php endif; ?>
        <?php if ($role === 'admin' || in_array('manage_borrowing', $myPerms)): ?>
        <button class="menu-item" onclick="location.href='borrowingSystem'"><div class="menu-left"><i class="fa-solid fa-hammer mi"></i>Borrowing System</div></button>
        <?php endif; ?>
      </nav>

      <div class="section-label">Community</div>
      <nav class="space-y-0.5 px-2">
        <?php if ($role === 'admin' || in_array('manage_listings', $myPerms)): ?>
        <button class="menu-item" onclick="location.href='communityListings.php'"><div class="menu-left"><i class="fa-solid fa-building mi"></i>Community Listings</div></button>
        <?php endif; ?>
        <?php if ($role === 'admin' || in_array('manage_announcements', $myPerms)): ?>
        <button class="menu-item active"><div class="menu-left"><i class="fa-solid fa-pen-to-square mi"></i>Announcements</div><span class="active-dot"></span></button>
        <?php endif; ?>
      </nav>

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

  <!-- �"?�"? MAIN �"?�"? -->
  <main class="main-content" id="mainContent">
    <header class="topbar">
      <div class="topbar-title-block">
        <h2 class="font-bold text-2xl leading-tight" style="font-family:'Playfair Display',serif;color: var(--site-primary-darker);">Announcements</h2>
        <p class="text-gray-400 text-sm mt-0.5">Post and manage community announcements and updates.</p>
      </div>
    </header>

    <div id="realtimeLoader" style="display:none;" class="flex-1 flex flex-col items-center justify-center min-h-[50vh]">
      <i class="fa-solid fa-circle-notch fa-spin text-4xl text-green-600 mb-4"></i>
      <p class="text-sm font-semibold text-green-800 animate-pulse tracking-wide">Loading announcements...</p>
    </div>

    <div id="mainDataContainer" class="p-6 page-pad flex-1 space-y-5 fade-up">

      <!-- Stat cards -->
      <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        <div class="stat-card">
          <p class="stat-label">Announcements This Month</p>
          <div class="stat-row"><i class="fa-solid fa-bullhorn stat-ico" style="color: var(--site-primary);"></i><span class="stat-num"><?= number_format($annThisMonth) ?></span></div>
          <?php if ($annTrendDir === 'up'): ?>
            <span class="stat-trend stat-trend-up"><i class="fa-solid fa-arrow-up"></i> <?= $annTrendPct ?>% vs last month</span>
          <?php elseif ($annTrendDir === 'down'): ?>
            <span class="stat-trend stat-trend-down"><i class="fa-solid fa-arrow-down"></i> <?= abs($annTrendPct) ?>% vs last month</span>
          <?php else: ?>
            <span class="stat-trend stat-trend-flat"><i class="fa-solid fa-minus"></i> Same as last month</span>
          <?php endif; ?>
        </div>
        <div class="stat-card">
          <p class="stat-label">Average Posting Frequency</p>
          <?php if ($avgPostingFrequencyDays !== null): ?>
            <div class="stat-row"><i class="fa-solid fa-calendar-days stat-ico text-blue-500"></i><span class="stat-num"><?= number_format($avgPostingFrequencyDays, 1) ?>d</span></div>
            <span class="stat-sub">Days between posts</span>
          <?php else: ?>
            <div class="stat-row"><i class="fa-solid fa-calendar-days stat-ico text-gray-300"></i><span class="stat-num text-gray-300">N/A</span></div>
            <span class="stat-sub">Needs 2+ announcements</span>
          <?php endif; ?>
        </div>
        <div class="stat-card">
          <p class="stat-label">Longest Gap</p>
          <?php if ($daysSinceLastPost !== null): ?>
            <div class="stat-row"><i class="fa-solid fa-triangle-exclamation stat-ico <?= $daysSinceLastPost >= 14 ? 'text-red-500' : 'text-amber-500' ?>"></i><span class="stat-num"><?= number_format($daysSinceLastPost) ?>d</span></div>
            <span class="stat-sub">Since last post</span>
          <?php else: ?>
            <div class="stat-row"><i class="fa-solid fa-triangle-exclamation stat-ico text-gray-300"></i><span class="stat-num text-gray-300">N/A</span></div>
            <span class="stat-sub">No announcements yet</span>
          <?php endif; ?>
        </div>
        <div class="stat-card">
          <p class="stat-label">Most Active Category</p>
          <?php if ($topTagName): ?>
            <div class="stat-row"><i class="fa-solid fa-tag stat-ico text-purple-500"></i><span class="stat-num" style="font-size:1.6rem;"><?= e($topTagName) ?></span></div>
            <span class="stat-sub"><?= number_format($topTagCount) ?> posts</span>
          <?php else: ?>
            <div class="stat-row"><i class="fa-solid fa-tag stat-ico text-gray-300"></i><span class="stat-num text-gray-300">N/A</span></div>
            <span class="stat-sub">No tagged posts yet</span>
          <?php endif; ?>
        </div>
      </div>

      <!-- Top row -->
      <div class="flex items-center justify-between gap-4 flex-wrap">
        <div class="flex items-baseline gap-2">
          <h3 class="font-bold text-gray-900 text-lg">Recent Announcements</h3>
          <span class="font-bold text-lg" id="annCount" style="color:var(--site-primary-dark)"><?= $total ?></span>
        </div>
        <div class="flex items-center gap-3 flex-wrap">
          <div class="search-box" style="width:200px;">
            <i class="fa-solid fa-magnifying-glass text-gray-400 text-xs flex-shrink-0"></i>
            <input type="text" id="searchInput" placeholder="Search..." oninput="filterAnn()">
          </div>
          <button class="btn-filter" onclick="toggleFilter()">Filter <i class="fa-solid fa-caret-down text-xs ml-1"></i></button>
          <button class="btn-add" onclick="openModal('create')"><i class="fa-solid fa-plus text-xs"></i> New Announcement</button>
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

      <!-- Filter panel -->
      <div id="filterPanel" class="hidden bg-white border border-gray-200 rounded-xl p-4 shadow-sm flex flex-wrap gap-4">
        <div style="min-width:140px;flex:1;">
          <p class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">Tag</p>
          <select id="filterTag" class="border border-gray-200 rounded-lg px-3 py-2 text-sm outline-none w-full" onchange="filterAnn()">
            <option value="">All Tags</option>
            <option>Health</option><option>Assistance</option><option>Community</option>
            <option>Education</option><option>Safety</option><option>Event</option>
          </select>
        </div>
        <div style="min-width:140px;flex:1;">
          <p class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">Date From</p>
          <input type="date" id="filterDateFrom" class="border border-gray-200 rounded-lg px-3 py-2 text-sm outline-none w-full" onchange="filterAnn()">
        </div>
        <div style="min-width:140px;flex:1;">
          <p class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">Date To</p>
          <input type="date" id="filterDateTo" class="border border-gray-200 rounded-lg px-3 py-2 text-sm outline-none w-full" onchange="filterAnn()">
        </div>
      </div>

      <!-- Toolbar + List -->
      <div>
        <div class="toolbar">
          <input type="checkbox" id="checkAll" class="rounded w-4 h-4 accent-green-600" onchange="toggleAll(this)">
          <button type="button" class="icon-btn icon-btn-delete" title="Delete selected" onclick="bulkDelete(this)"><i class="fa-solid fa-trash text-xs"></i></button>
          <div class="toolbar-divider"></div>
          <button class="btn-refresh" onclick="triggerRefresh(this)" title="Refresh"><i class="fa-solid fa-rotate-right text-xs"></i></button>
        </div>

        <div class="ann-list" id="annList">
          <?php if (empty($announcements)): ?>
          <div class="empty-state">
            <i class="fa-regular fa-folder-open"></i>
            <p class="font-semibold text-sm">No announcements yet.</p>
            <p class="text-xs mt-1">Click "+ New Announcement" to get started.</p>
          </div>
          <?php else: foreach ($announcements as $a):
            $tag      = $a['announcementTag'] ?? '';
            $chipCls  = tagBadge($tag);
            $postDate = !empty($a['announcementPost']) ? date('F j, Y', strtotime($a['announcementPost'])) : '�?"';
            // Images: stored as JSON array in announcementImg
            $imgs = [];
            if (!empty($a['announcementImg'])) {
                $decoded = json_decode($a['announcementImg'], true);
                $imgs = is_array($decoded) ? $decoded : [$a['announcementImg']];
            }
          ?>
          <div class="ann-item" id="ann-<?= (int)$a['announcementID'] ?>"
               data-id="<?= (int)$a['announcementID'] ?>"
               data-title="<?= e(strtolower($a['announcementTitle'])) ?>"
               data-tag="<?= e(strtolower($tag)) ?>"
               data-date="<?= e($a['announcementPost'] ?? '') ?>"
               data-row="<?= rowJson($a) ?>">
            <input type="checkbox" class="ann-check ann-checkbox" onclick="event.stopPropagation()">
            <div class="ann-body-col">
              <div class="ann-title">
                <?= e($a['announcementTitle']) ?>
                <?php if ($tag): ?><span class="tag-chip <?= $chipCls ?>"><?= e($tag) ?></span><?php endif; ?>
              </div>
              <div class="ann-desc"><?= e($a['announcementDesc'] ?? '') ?></div>
              <div class="ann-meta">
                <i class="fa-regular fa-calendar text-xs"></i>
                <span><?= $postDate ?></span>
                <?php if (!empty($imgs)): ?>
                <span class="ml-2"><i class="fa-regular fa-image text-xs mr-1"></i><?= count($imgs) ?> image<?= count($imgs)>1?'s':'' ?></span>
                <?php endif; ?>
              </div>
            </div>
            <div class="ann-actions" onclick="event.stopPropagation()">
              <button class="ann-action-link edit" onclick="openModal('edit', this.closest('.ann-item').dataset.row)"><i class="fa-solid fa-pen-to-square"></i>Edit</button>
              <button class="ann-action-link del" onclick="confirmDelete(<?= (int)$a['announcementID'] ?>, '<?= e(addslashes($a['announcementTitle'])) ?>', this)"><i class="fa-solid fa-trash"></i>Delete</button>
            </div>
          </div>
          <?php endforeach; endif; ?>
        </div>

        <!-- Pagination -->
        <div class="flex items-center justify-center gap-2 pt-5 flex-wrap" id="paginationContainer"></div>
      </div>
    </div>
  </main>
</div>

<!-- �.��.� CREATE / EDIT MODAL �.��.� -->
<div class="modal-overlay" id="modalOverlay" onclick="closeModalOnOverlay(event)">
  <div class="modal">
    <div class="modal-header">
      <div class="flex items-center gap-3 min-w-0">
        <div style="width:36px;height:36px;background:#dcfce7;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <i class="fa-solid fa-pen-to-square text-green-700 text-sm"></i>
        </div>
        <div>
          <p class="font-bold text-gray-900 text-base" id="modalTitle">New Announcement</p>
          <p class="text-gray-400 text-xs mt-0.5" id="modalSubtitle">Fill in the details below</p>
        </div>
      </div>
      <button class="modal-close" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <div class="modal-body">
      <input type="hidden" id="m_id">

      <!-- Image upload -->
      <div class="mb-5">
        <label class="field-label mb-2 block">Uploaded image</label>
        <div id="uploadList" class="upload-list mb-2" style="display:none;"></div>
        <div class="upload-drop-zone" id="dropZone" onclick="document.getElementById('fileInput').click()"
             ondragover="event.preventDefault();this.classList.add('dragover')"
             ondragleave="this.classList.remove('dragover')"
             ondrop="handleDrop(event)">
          <i class="fa-solid fa-cloud-arrow-up text-xl mb-1 block"></i>
          <span>Click or drag &amp; drop to upload photos</span><br>
          <span class="text-[11px]">PNG, JPG, WEBP up to 5MB each</span>
        </div>
        <input type="file" id="fileInput" accept="image/*" multiple style="display:none;" onchange="handleFiles(this.files)">
        <!-- Hidden field holds existing image JSON when editing -->
        <input type="hidden" id="m_existing_imgs" value="[]">
      </div>

      <!-- Dates -->
      <div class="grid grid-cols-2 gap-4 mb-5">
        <div>
          <label class="field-label"><span class="required-star">*</span>Post Date</label>
          <div class="relative">
            <input type="date" id="m_postDate" class="field-input pr-9">
            <i class="fa-regular fa-calendar absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none"></i>
          </div>
        </div>
        <div>
          <label class="field-label"><span class="required-star">*</span>Post Start</label>
          <div class="relative">
            <input type="date" id="m_startDate" class="field-input pr-9">
            <i class="fa-regular fa-calendar absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none"></i>
          </div>
        </div>
      </div>

      <!-- Tag -->
      <div class="mb-5">
        <label class="field-label"><span class="required-star">*</span>Tag</label>
        <select id="m_tag" class="field-input">
          <option value="">-- Select --</option>
          <option value="Health">Health</option>
          <option value="Assistance">Assistance</option>
          <option value="Community">Community</option>
          <option value="Education">Education</option>
          <option value="Safety">Safety</option>
          <option value="Event">Event</option>
        </select>
      </div>

      <!-- Content (Title + Body split) -->
      <div class="mb-5">
        <label class="field-label"><span class="required-star">*</span>Content</label>
        <div class="content-split">
          <input type="text" id="m_title" class="content-title-input" placeholder="Title" maxlength="255">
          <textarea id="m_desc" class="content-body-input" placeholder="Type here..."></textarea>
        </div>
      </div>

      <!-- Extra details (optional) -->
      <div class="mb-2">
        <label class="field-label">Full Details <span class="text-gray-400 font-normal text-[11px] normal-case tracking-normal">(optional)</span></label>
        <textarea id="m_details" class="field-textarea" placeholder="Additional details shown on the announcement detail page..."></textarea>
      </div>
    </div>

    <div class="modal-footer">
      <button class="mf-btn mf-cancel" onclick="closeModal()">Cancel <i class="fa-solid fa-xmark text-sm"></i></button>
      <button class="mf-btn mf-submit" id="modalSubmitBtn" onclick="handleSubmit()">
        Post <i class="fa-solid fa-paper-plane text-sm"></i>
      </button>
    </div>
  </div>
</div>

<!-- �.��.� CONFIRM DELETE DIALOG �.��.� -->
<div class="dialog-overlay" id="dialogOverlay">
  <div class="dialog-box">
    <div class="dialog-body-d">
      <div class="dialog-icon-wrap dialog-icon-danger"><i class="fa-solid fa-trash"></i></div>
      <p class="dialog-title-d" id="dialogTitleText">Delete Announcement</p>
      <p class="dialog-desc-d" id="dialogDescText">Are you sure you want to delete this announcement? This action cannot be undone.</p>
    </div>
    <div class="dialog-footer-d">
      <button type="button" class="dbtn dbtn-cancel" onclick="event.stopPropagation(); closeDialog()"><i class="fa-solid fa-xmark"></i> Cancel</button>
      <button type="button" class="dbtn dbtn-confirm danger" id="dialogConfirmBtn" onclick="event.stopPropagation()"><i class="fa-solid fa-trash"></i> Delete</button>
    </div>
  </div>
</div>

<!-- �.��.� LIGHTBOX FOR IMAGE PREVIEW �.��.� -->
<div class="lightbox-overlay" id="lightboxOverlay" onclick="closeZoom()">
  <button class="lightbox-close-btn" onclick="event.stopPropagation(); closeZoom()"><i class="fa-solid fa-xmark"></i></button>
  <img id="lightboxImg" src="" alt="" onclick="event.stopPropagation()">
</div>

<script>
/* �"?�"? Sidebar �"?�"? */
const sidebar = document.getElementById('sidebar'), mainContent = document.getElementById('mainContent'), expandBtn = document.getElementById('expandBtn'), backdrop = document.getElementById('sidebarBackdrop');
const isMobile = () => window.innerWidth <= 1024;
let collapsed = localStorage.getItem('sidebarCollapsed') === 'true';
function applyCollapse() {
  if (isMobile()) { sidebar.classList.remove('collapsed'); mainContent.classList.remove('sidebar-collapsed'); expandBtn.classList.add('visible'); return; }
  sidebar.classList.remove('mobile-open'); backdrop.classList.remove('visible'); document.body.style.overflow = '';
  if (collapsed) { sidebar.classList.add('collapsed'); mainContent.classList.add('sidebar-collapsed'); expandBtn.classList.add('visible'); }
  else { sidebar.classList.remove('collapsed'); mainContent.classList.remove('sidebar-collapsed'); expandBtn.classList.remove('visible'); }
}
function closeMobileSidebar() { sidebar.classList.remove('mobile-open'); backdrop.classList.remove('visible'); document.body.style.overflow = ''; }
document.getElementById('collapseBtn').addEventListener('click', () => { if (isMobile()) { closeMobileSidebar(); return; } collapsed = true; localStorage.setItem('sidebarCollapsed','true'); applyCollapse(); });
expandBtn.addEventListener('click', () => { if (isMobile()) { sidebar.classList.add('mobile-open'); backdrop.classList.add('visible'); document.body.style.overflow = 'hidden'; return; } collapsed = false; localStorage.setItem('sidebarCollapsed','false'); applyCollapse(); });
window.addEventListener('resize', applyCollapse); applyCollapse();

function showPageLoader(message='Processing...') {
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
function triggerRefresh(triggerBtn = null) {
  const resetBtn = setActionButtonLoading(triggerBtn, 'Refreshing...');
  showPageLoader('Refreshing announcements...');
  setTimeout(() => { if (resetBtn) resetBtn(); }, 600);
  setTimeout(() => location.reload(), 180);
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

/* �"?�"? Alert �"?�"? */
let alertTimer;
function showToast(type, title, desc) {
  const icons = {success:'fa-circle-check',error:'fa-circle-xmark',warning:'fa-triangle-exclamation'};
  const types = {success:'alert-success',error:'alert-error',warning:'alert-warning'};
  document.getElementById('alertInner').className = 'alert-inner ' + (types[type]||'alert-success');
  document.getElementById('alertIcon').className  = 'fa-solid ' + (icons[type]||'fa-circle-check');
  document.getElementById('alertTitle').textContent = title;
  document.getElementById('alertDesc').textContent  = desc || '';
  document.getElementById('alertBanner').classList.add('show');
  clearTimeout(alertTimer); alertTimer = setTimeout(dismissAlert, 4000);
}
function dismissAlert() { document.getElementById('alertBanner').classList.remove('show'); }

/* �"?�"? Filter / Search �"?�"? */
function toggleFilter() { document.getElementById('filterPanel').classList.toggle('hidden'); }
let searchTimeout;
function filterAnn() {
  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(() => {
    setTimeout(() => {
      const q  = document.getElementById('searchInput').value.toLowerCase().trim();
      const t  = (document.getElementById('filterTag')?.value||'').toLowerCase();
      const fd = document.getElementById('filterDateFrom')?.value||'';
      const td = document.getElementById('filterDateTo')?.value||'';
      let vis = 0;
      document.querySelectorAll('.ann-item').forEach(row => {
        const ok = (!q||row.dataset.title.includes(q)) && (!t||row.dataset.tag===t) && (!fd||row.dataset.date>=fd) && (!td||row.dataset.date<=td);
        if (ok) {
          row.dataset.filteredout = "false";
          row.style.display = '';
          vis++;
        } else {
          row.dataset.filteredout = "true";
          row.style.display = 'none';
        }
      });
      currentPage = 1; renderPagination();
    }, 10);
  }, 400);
}

/* �"?�"? Select All �"?�"? */
function toggleAll(cb) { document.querySelectorAll('.ann-check').forEach(c => c.checked = cb.checked); }

/* �"?�"? Pagination �"?�"? */
const PER_PAGE = 10; let currentPage = 1;
function getVisible() { return Array.from(document.querySelectorAll('.ann-item')).filter(r => r.dataset.filteredout !== 'true'); }
function renderPagination() {
  const rows = getVisible(), total = rows.length, pages = Math.max(1, Math.ceil(total/PER_PAGE));
  if (currentPage > pages) currentPage = pages;
  rows.forEach((r, i) => { r.style.display = (Math.floor(i/PER_PAGE)+1 === currentPage) ? '' : 'none'; });
  const c = document.getElementById('paginationContainer'); c.innerHTML = '';
  const prev = document.createElement('button'); prev.className = 'page-btn'; prev.disabled = currentPage===1;
  prev.innerHTML = '<i class="fa-solid fa-chevron-left text-xs"></i>';
  prev.onclick = () => { currentPage--; renderPagination(); }; c.appendChild(prev);
  let s = Math.max(1, currentPage-2), e = Math.min(pages, s+4);
  if (e-s < 4) s = Math.max(1, e-4);
  for (let p = s; p <= e; p++) {
    const b = document.createElement('button'); b.className = 'page-btn' + (p===currentPage?' active':''); b.textContent = p;
    b.onclick = () => { currentPage = p; renderPagination(); }; c.appendChild(b);
  }
  const next = document.createElement('button'); next.className = 'page-btn'; next.disabled = currentPage===pages;
  next.innerHTML = '<i class="fa-solid fa-chevron-right text-xs"></i>';
  next.onclick = () => { currentPage++; renderPagination(); }; c.appendChild(next);
}
renderPagination();

/* �"?�"? File upload management �"?�"? */
let pendingFiles = []; // {file, previewUrl, name}
let removedExistingImgs = []; // filenames to delete on server

function handleFiles(files) {
  Array.from(files).forEach(f => {
    if (!f.type.startsWith('image/')) return;
    const url = URL.createObjectURL(f);
    pendingFiles.push({file: f, previewUrl: url, name: f.name});
  });
  renderUploadList();
}
function handleDrop(e) {
  e.preventDefault(); document.getElementById('dropZone').classList.remove('dragover');
  handleFiles(e.dataTransfer.files);
}
function removePending(idx) { URL.revokeObjectURL(pendingFiles[idx].previewUrl); pendingFiles.splice(idx,1); renderUploadList(); }
function removeExisting(filename) {
  removedExistingImgs.push(filename);
  let existing = JSON.parse(document.getElementById('m_existing_imgs').value || '[]');
  existing = existing.filter(f => f !== filename);
  document.getElementById('m_existing_imgs').value = JSON.stringify(existing);
  renderUploadList();
}
function renderUploadList() {
  const list = document.getElementById('uploadList');
  const existing = JSON.parse(document.getElementById('m_existing_imgs').value || '[]');
  const items = [
    ...existing.map(f => ({type:'existing', filename:f, name:f})),
    ...pendingFiles.map((p,i) => ({type:'pending', idx:i, previewUrl:p.previewUrl, name:p.name}))
  ];
  if (!items.length) { list.style.display = 'none'; list.innerHTML = ''; return; }
  list.style.display = 'block';
  list.innerHTML = items.map(it => {
    if (it.type === 'existing') {
      return `<div class="upload-item">
        <img class="upload-item-thumb" src="../uploads/announcement/${it.filename}" onerror="this.src=''" onclick="openZoom(this.src)" title="Click to zoom">
        <span class="upload-item-name">${it.name}</span>
        <button class="upload-remove" onclick="removeExisting('${it.filename}')"><i class="fa-solid fa-xmark"></i></button>
      </div>`;
    } else {
      return `<div class="upload-item">
        <img class="upload-item-thumb" src="${it.previewUrl}" onclick="openZoom(this.src)" title="Click to zoom">
        <span class="upload-item-name">${it.name}</span>
        <button class="upload-remove" onclick="removePending(${it.idx})"><i class="fa-solid fa-xmark"></i></button>
      </div>`;
    }
  }).join('');
  detectChanges();
}

/* �"?�"? Lightbox zoom preview �"?�"? */
function openZoom(src) {
  if (!src || src.includes('data:')) return;
  const overlay = document.getElementById('lightboxOverlay');
  document.getElementById('lightboxImg').src = src;
  overlay.classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeZoom() {
  const overlay = document.getElementById('lightboxOverlay');
  overlay.classList.remove('open');
  document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape' && document.getElementById('lightboxOverlay').classList.contains('open')) closeZoom();
});

/* �"?�"? Modal �"?�"? */
let modalMode = 'create';
let initialFormState = {};
function captureFormState() {
  return {
    id: document.getElementById('m_id').value,
    postDate: document.getElementById('m_postDate').value,
    startDate: document.getElementById('m_startDate').value,
    tag: document.getElementById('m_tag').value,
    title: document.getElementById('m_title').value,
    desc: document.getElementById('m_desc').value,
    details: document.getElementById('m_details').value,
    imgs: document.getElementById('m_existing_imgs').value,
    pendingCount: pendingFiles.length,
    removedCount: removedExistingImgs.length
  };
}
function detectChanges() {
  if (modalMode !== 'edit') { document.getElementById('modalSubmitBtn').disabled = false; return; }
  const current = captureFormState();
  const hasChanges = JSON.stringify(initialFormState) !== JSON.stringify(current);
  document.getElementById('modalSubmitBtn').disabled = !hasChanges;
}
function openModal(mode, rowJson) {
  modalMode = mode;
  pendingFiles = []; removedExistingImgs = [];
  document.getElementById('m_id').value = '';
  document.getElementById('m_postDate').value = '';
  document.getElementById('m_startDate').value = '';
  document.getElementById('m_tag').value = '';
  document.getElementById('m_title').value = '';
  document.getElementById('m_desc').value = '';
  document.getElementById('m_details').value = '';
  document.getElementById('m_existing_imgs').value = '[]';
  renderUploadList();
  if (mode === 'edit' && rowJson) {
    const d = JSON.parse(rowJson);
    document.getElementById('m_id').value = d.announcementID || '';
    document.getElementById('m_postDate').value  = (d.announcementPost||'').substring(0,10);
    document.getElementById('m_startDate').value = (d.announcementStart||'').substring(0,10);
    document.getElementById('m_tag').value   = d.announcementTag || '';
    document.getElementById('m_title').value = d.announcementTitle || '';
    document.getElementById('m_desc').value  = d.announcementDesc  || '';
    document.getElementById('m_details').value = d.announcementDetails || '';
    let imgs = [];
    if (d.announcementImg) { try { imgs = JSON.parse(d.announcementImg); if (!Array.isArray(imgs)) imgs = [d.announcementImg]; } catch(e) { imgs = [d.announcementImg]; } }
    document.getElementById('m_existing_imgs').value = JSON.stringify(imgs);
    renderUploadList();
    document.getElementById('modalTitle').textContent    = 'Edit Announcement';
    document.getElementById('modalSubtitle').textContent = d.announcementTitle || '';
    document.getElementById('modalSubmitBtn').innerHTML  = 'Update <i class="fa-solid fa-floppy-disk text-sm"></i>';
    initialFormState = captureFormState();
    document.getElementById('modalSubmitBtn').disabled = true;
  } else {
    document.getElementById('modalTitle').textContent    = 'New Announcement';
    document.getElementById('modalSubtitle').textContent = 'Fill in the details below';
    document.getElementById('modalSubmitBtn').innerHTML  = 'Post <i class="fa-solid fa-paper-plane text-sm"></i>';
    document.getElementById('modalSubmitBtn').disabled = false;
  }
  document.getElementById('modalOverlay').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeModal() { document.getElementById('modalOverlay').classList.remove('open'); document.body.style.overflow = ''; }
function closeModalOnOverlay(e) { if (e.target === document.getElementById('modalOverlay')) closeModal(); }
['m_postDate','m_startDate','m_tag','m_title','m_desc','m_details'].forEach(id => {
  document.getElementById(id).addEventListener('change', detectChanges);
  document.getElementById(id).addEventListener('input', detectChanges);
});


function handleSubmit() {
  const postDate  = document.getElementById('m_postDate').value;
  const startDate = document.getElementById('m_startDate').value;
  const tag       = document.getElementById('m_tag').value;
  const title     = document.getElementById('m_title').value.trim();
  const desc      = document.getElementById('m_desc').value.trim();
  if (!postDate || !startDate || !tag || !title || !desc) {
    showToast('warning','Required Fields','Please fill in all required fields.');
    return;
  }
  const btn = document.getElementById('modalSubmitBtn');
  btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-sm"></i> Saving...';
  showPageLoader('Saving announcement...');

  const formData = new FormData();
  formData.append('action', modalMode);
  formData.append('id',          document.getElementById('m_id').value);
  formData.append('postDate',    postDate);
  formData.append('startDate',   startDate);
  formData.append('tag',         tag);
  formData.append('title',       title);
  formData.append('desc',        desc);
  formData.append('details',     document.getElementById('m_details').value.trim());
  formData.append('existingImgs',document.getElementById('m_existing_imgs').value);
  formData.append('removedImgs', JSON.stringify(removedExistingImgs));
  pendingFiles.forEach(p => formData.append('images[]', p.file));

  let shouldHideLoader = true;
  fetch('announcementAction.php', { method:'POST', body: formData })
    .then(r => r.json())
    .then(d => {
      if (d.success) {
        showToast('success', modalMode==='create'?'Announcement Posted':'Announcement Updated', d.message||'');
        closeModal();
        shouldHideLoader = false;
        setTimeout(() => location.reload(), 800);
      } else {
        showToast('error','Error', d.message||'Something went wrong.');
      }
    })
    .catch(() => showToast('error','Network Error','Please try again.'))
    .finally(() => {
      if (shouldHideLoader) hidePageLoader();
      btn.disabled = false;
      btn.innerHTML = modalMode==='create' ? 'Post <i class="fa-solid fa-paper-plane text-sm"></i>' : 'Update <i class="fa-solid fa-floppy-disk text-sm"></i>';
    });
}

/* �"?�"? Delete �"?�"? */
let deleteCallback = null;
function openConfirmDialog(title, message, buttonLabel, callback) {
  document.getElementById('dialogTitleText').textContent = title;
  document.getElementById('dialogDescText').textContent = message;
  const confirmBtn = document.getElementById('dialogConfirmBtn');
  confirmBtn.innerHTML = `<i class="fa-solid fa-trash"></i> ${buttonLabel}`;
  confirmBtn.onclick = () => { closeDialog(); callback(); };
  document.getElementById('dialogOverlay').classList.add('open');
  document.body.style.overflow = 'hidden';
}

function confirmDelete(id, title, triggerBtn = null) {
  openConfirmDialog(
    'Delete Announcement',
    `Are you sure you want to delete "${title}"? This action cannot be undone.`,
    'Delete',
    () => doDelete(id, triggerBtn)
  );
}

let bulkDeleteItems = [];
function confirmBulkDelete(count, triggerBtn = null) {
  openConfirmDialog(
    'Delete Selected Announcements',
    `Are you sure you want to delete ${count} selected announcement(s)? This action cannot be undone.`,
    'Delete Selected',
    () => performBulkDelete(triggerBtn)
  );
}

function closeDialog() { document.getElementById('dialogOverlay').classList.remove('open'); document.body.style.overflow = ''; }
document.getElementById('dialogOverlay').addEventListener('click', function(e) { if (e.target===this) closeDialog(); });

function doDelete(id, triggerBtn = null) {
  const resetBtn = setActionButtonLoading(triggerBtn, 'Deleting...');
  showPageLoader('Deleting announcement...');
  let shouldHideLoader = true;
  const fd = new FormData(); fd.append('action','delete'); fd.append('id', id);
  fetch('announcementAction.php', {method:'POST',body:fd})
    .then(r=>r.json()).then(d => {
      if (d.success) {
        document.getElementById('ann-'+id)?.remove();
        renderPagination();
        showToast('warning','Deleted','Announcement removed.');
        const cnt = document.getElementById('annCount');
        if (cnt) cnt.textContent = Math.max(0, parseInt(cnt.textContent)-1);
        shouldHideLoader = false;
        setTimeout(() => location.reload(), 600);
      } else { showToast('error','Error',d.message||'Could not delete.'); }
    }).catch(() => showToast('error','Network Error','Please try again.'))
    .finally(() => {
      if (shouldHideLoader) hidePageLoader();
      if (resetBtn) resetBtn();
    });
}
function bulkDelete(triggerBtn = null) {
  bulkDeleteItems = Array.from(document.querySelectorAll('.ann-check:checked')).map(c => c.closest('.ann-item')?.dataset.id).filter(Boolean);
  if (!bulkDeleteItems.length) return;
  confirmBulkDelete(bulkDeleteItems.length, triggerBtn);
}

function performBulkDelete(triggerBtn = null) {
  if (!bulkDeleteItems.length) return;
  const resetBtn = setActionButtonLoading(triggerBtn, 'Deleting...');
  showPageLoader('Deleting selected announcements...');
  let shouldHideLoader = true;
  Promise.all(bulkDeleteItems.map(id => {
    const fd = new FormData(); fd.append('action','delete'); fd.append('id',id);
    return fetch('announcementAction.php',{method:'POST',body:fd}).then(r=>r.json());
  })).then(() => {
    showToast('warning','Deleted',`${bulkDeleteItems.length} announcement(s) removed.`);
    shouldHideLoader = false;
    setTimeout(()=>location.reload(),800);
  }).catch(() => {
    showToast('error','Network Error','Please try again.');
  }).finally(() => {
    if (shouldHideLoader) hidePageLoader();
    if (resetBtn) resetBtn();
    bulkDeleteItems = [];
  });
}
</script>
</body>
</html>