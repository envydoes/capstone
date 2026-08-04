<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }

$role = $_SESSION['account_role'] ?? '';
require_once __DIR__ . '/../includes/check_permissions.php';

$host = "o7jpqmin0zgconui4xtnfju6"; $dbuser = "root"; $password = "UKkJ05DHQDMMMOxFEUI5f1HJGVj8Vb5gfJAEvAESTGCVWDtFEGb42qX67AxGUXvj"; $database = "sumeste_db";
$conn = mysqli_connect($host, $dbuser, $password, $database);
if (!$conn) { session_unset(); session_destroy(); die("Connection failed: " . mysqli_connect_error()); }

$myPerms = get_my_permissions($conn);
if ($role !== 'admin' && empty($myPerms)) {
    switch ($role) {
        case 'resident':
        case 'resident,business/apartment owner':
            header('Location: ../resident/residentLanding.php'); break;
        case 'non-resident':
        case 'non-resident,business/apartment owner':
            header('Location: ../nonresident/nonresidentLanding.php'); break;
        default: header('Location: ../landing.php');
    }
    exit;
}
require_permission($conn, 'manage_listings');

// â”€â”€ Fetch all listings from tbl_busaptlisting â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$listingsSQL = "
    SELECT
        l.id, l.userId, l.listingType, l.slotsAvailable,
        l.aptType, l.aptTitle, l.aptStatus, l.aptPrice, l.aptFloor,
        l.aptRooms, l.aptOccupants, l.aptBath, l.aptIncluded, l.aptAmenities,
        l.aptRules, l.aptDesc, l.aptAddress, l.aptMapsLink,
        l.bussCat, l.bussName, l.bussStatus, l.bussPrice, l.bussYears,
        l.bussOpen, l.bussClose, l.bussDays, l.bussFeatures, l.bussDesc,  
        l.bussAddress, l.bussMapsLink,
        l.contact, l.email, l.houseNum, l.street, l.barangay, l.city,
        l.photos, l.createdAt,
        CONCAT(u.firstname, ' ', IF(u.middlename != '' AND u.middlename IS NOT NULL, CONCAT(LEFT(u.middlename,1), '. '), ''), u.lastname) AS owner_name
    FROM tbl_busaptlisting l
    LEFT JOIN tbl_userinfo u ON l.userId = u.accID
    ORDER BY l.createdAt DESC
";
require_once '../includes/site_config.php';
$siteSettings = site_config_load($conn);

$listingsResult = mysqli_query($conn, $listingsSQL);
$listings = [];
if ($listingsResult) {
    while ($row = mysqli_fetch_assoc($listingsResult)) {
        $isApt = ($row['listingType'] === 'apt' || $row['listingType'] === 'apartment');
        $photos = json_decode($row['photos'] ?? '[]', true) ?: [];
        $row['photos_arr'] = array_map(fn($p) => '../uploads/listings/' . $p, $photos);
        $row['first_photo'] = !empty($row['photos_arr']) ? $row['photos_arr'][0] : null;
        $row['display_name'] = $isApt
            ? ($row['aptTitle'] ?: 'Apartment Listing')
            : ($row['bussName'] ?: 'Business Listing');
        $row['display_address'] = $isApt ? $row['aptAddress'] : $row['bussAddress'];
        $row['display_maps'] = $isApt ? $row['aptMapsLink'] : $row['bussMapsLink'];
        $row['is_apt'] = $isApt;
        $row['date'] = !empty($row['createdAt']) ? date('F j, Y', strtotime($row['createdAt'])) : 'â€”';
        $row['date_short'] = !empty($row['createdAt']) ? date('M j, Y', strtotime($row['createdAt'])) : 'â€”';

        // Category label
        $aptTypeLabels = ['bed-spacer' => 'Bed Spacer', 'studio' => 'Studio', 'solo-room' => 'Solo Room',
            '1br' => '1-Bedroom', '2br' => '2-Bedroom', 'whole-unit' => 'Whole Unit'];
        $bizCatLabels = ['food' => 'Food', 'water' => 'Water Station', 'sari-sari' => 'Sari-Sari',
            'salon' => 'Salon', 'laundry' => 'Laundry', 'pharmacy' => 'Pharmacy',
            'printing' => 'Printing', 'bakery' => 'Bakery/CafÃ©', 'hardware' => 'Hardware', 'other' => 'Other'];
        $row['category_label'] = $isApt
            ? ($aptTypeLabels[$row['aptType']] ?? 'Apartment')
            : ($bizCatLabels[$row['bussCat']] ?? 'Business');

        $listings[] = $row;
    }
    mysqli_free_result($listingsResult);
}
$totalListings = count($listings);

// â”€â”€ Stat cards: Listings Overview â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

// New Listings This Month, vs Last Month
$listTrendRow = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        SUM(CASE WHEN createdAt >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN 1 ELSE 0 END) AS this_month,
        SUM(CASE WHEN createdAt >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
                  AND createdAt <  DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN 1 ELSE 0 END) AS last_month
    FROM tbl_busaptlisting
"));
$listThisMonth = (int) ($listTrendRow['this_month'] ?? 0);
$listLastMonth = (int) ($listTrendRow['last_month'] ?? 0);
if ($listLastMonth > 0) {
    $listTrendPct = (int) round((($listThisMonth - $listLastMonth) / $listLastMonth) * 100);
} else {
    $listTrendPct = $listThisMonth > 0 ? 100 : 0;
}
$listTrendDir = $listThisMonth > $listLastMonth ? 'up' : ($listThisMonth < $listLastMonth ? 'down' : 'flat');

// Average Listing Age: how long since a listing was last touched.
// tbl_busaptlisting only has `createdAt` â€” there's no `updatedAt` column,
// so today this can only measure time since the listing was first posted,
// not since it was last edited. It auto-detects an `updatedAt` column if
// you add one (see add_listing_updated_at.sql) and switches over.
$hasListingUpdatedAtCol = mysqli_query($conn, "SHOW COLUMNS FROM tbl_busaptlisting LIKE 'updatedAt'");
$listingAgeUsesUpdatedAt = $hasListingUpdatedAtCol && mysqli_num_rows($hasListingUpdatedAtCol) > 0;
$ageColumn = $listingAgeUsesUpdatedAt ? 'updatedAt' : 'createdAt';
$avgAgeRow = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT AVG(DATEDIFF(NOW(), $ageColumn)) AS avg_days
    FROM tbl_busaptlisting
"));
$avgListingAgeDays = ($avgAgeRow && $avgAgeRow['avg_days'] !== null)
    ? round((float) $avgAgeRow['avg_days'], 1)
    : null;

// Occupancy Rate: occupied vs available, across both listing types.
// Apartments use aptStatus (available/occupied/inquire). Businesses have
// no direct "occupied" concept, so 'open'/'new'/'temp-closed' (actively
// running there) are treated as occupied, and 'for-rent' as available.
$occRow = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        SUM(CASE
            WHEN listingType = 'apartment' AND aptStatus = 'occupied' THEN 1
            WHEN listingType = 'business' AND bussStatus IN ('open','new','temp-closed') THEN 1
            ELSE 0
        END) AS occupied,
        SUM(CASE
            WHEN listingType = 'apartment' AND aptStatus IN ('available','inquire') THEN 1
            WHEN listingType = 'business' AND bussStatus = 'for-rent' THEN 1
            ELSE 0
        END) AS available
    FROM tbl_busaptlisting
"));
$occOccupied  = (int) ($occRow['occupied'] ?? 0);
$occAvailable = (int) ($occRow['available'] ?? 0);
$occKnownTotal = $occOccupied + $occAvailable;
$occupancyRate = $occKnownTotal > 0 ? round(($occOccupied / $occKnownTotal) * 100) : 0;

// Owner Count: distinct owners across all listings (vs. listing count)
$ownerCount = (int) mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(DISTINCT userId) AS total FROM tbl_busaptlisting
"))['total'];

mysqli_close($conn);

function rowJsonSafe($u) {
    return htmlspecialchars(json_encode($u, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS), ENT_QUOTES, 'UTF-8');
}

$toastType = '';
$toastMsg  = '';
if (isset($_GET['deleted'])) { $toastType = 'warning'; $toastMsg = 'Listing deleted successfully.'; }
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
  <script src="https://cdn.tailwindcss.com/3.4.16"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body { font-family: 'DM Sans', sans-serif; background: var(--site-primary-pale); margin: 0; }
    :root {
  --site-primary-dark:   color-mix(in srgb, var(--site-primary) 55%, black);
  --site-primary-darker: color-mix(in srgb, var(--site-primary) 75%, black);
  --site-primary-light:  color-mix(in srgb, var(--site-primary) 55%, white);
  --site-primary-pale:   color-mix(in srgb, var(--site-primary) 12%, white);
}
    /* â”€â”€ Sidebar â”€â”€ */
    .sidebar { width: 260px; flex-shrink: 0; background: linear-gradient(180deg, var(--site-primary-dark) 0%, var(--site-primary-darker) 55%, var(--site-primary) 100%); display: flex; flex-direction: column; position: fixed; top: 0; left: 0; height: 100vh; z-index: 300; overflow: hidden; transition: width 0.3s cubic-bezier(0.4,0,0.2,1), transform 0.3s cubic-bezier(0.4,0,0.2,1); }
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
    .menu-item .mi { width: 17px; text-align: center; font-size: 0.85rem; flex-shrink: 0; }
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
    .sidebar-bottom-links .side-link { display: block; width: 100%; font-size: 0.84rem; padding: 8px 8px; border-radius: 8px; transition: color 0.15s, background 0.15s; text-decoration: none; white-space: nowrap; border: none; background: none; text-align: left; cursor: pointer; }

    /* â”€â”€ Layout â”€â”€ */
    .main-wrapper { display: flex; min-height: 100vh; }
    .main-content { flex: 1; min-width: 0; display: flex; flex-direction: column; margin-left: 260px; transition: margin-left 0.3s cubic-bezier(0.4,0,0.2,1); overflow-x: hidden; }
    .main-content.sidebar-collapsed { margin-left: 0; }

    /* â”€â”€ Topbar â”€â”€ */
    .topbar { background: #fff; border-bottom: 1px solid #e5e7eb; padding: 14px 28px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; position: sticky; top: 0; z-index: 100; }

    /* â”€â”€ Stat cards â”€â”€ */
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

    /* â”€â”€ Card Grid â”€â”€ */
    .listing-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; }

    /* â”€â”€ Listing Card â”€â”€ */
    .listing-card { background: #fff; border-radius: 16px; border: 2px solid #e5e7eb; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,0.06); transition: all 0.25s cubic-bezier(0.34,1.56,0.64,1); position: relative; }
    .listing-card:hover { box-shadow: 0 8px 24px rgba(21,128,61,0.1); transform: translateY(-2px); }
    .listing-card.selected { border-color: #15803d; border-width: 2px; box-shadow: 0 0 0 3px rgba(21,128,61,0.1), 0 8px 24px rgba(21,128,61,0.15); transform: scale(1.01); background: linear-gradient(135deg, #fff 0%, #f0fdf4 100%); }

    /* Photo area */
    .card-photo { width: 100%; height: 180px; background: #f3f4f6; display: flex; align-items: center; justify-content: center; position: relative; overflow: hidden; }
    .card-photo img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform 0.3s; }
    .listing-card:hover .card-photo img { transform: scale(1.04); }
    .card-photo-placeholder { display: flex; flex-direction: column; align-items: center; justify-content: center; width: 100%; height: 100%; color: #9ca3af; gap: 6px; background: #f0fdf4; }
    .card-photo-placeholder i { font-size: 2.2rem; color: #86efac; }

    /* Checkbox overlay */
    .card-check { position: absolute; top: 10px; left: 10px; z-index: 10; width: 24px; height: 24px; border-radius: 6px; border: 2px solid rgba(255,255,255,0.95); background: rgba(255,255,255,0.95); cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s cubic-bezier(0.34,1.56,0.64,1); box-shadow: 0 2px 6px rgba(0,0,0,0.2); }
    .card-check:hover { transform: scale(1.15); box-shadow: 0 4px 10px rgba(0,0,0,0.25); }
    .card-check.checked { background: #15803d; border-color: #15803d; box-shadow: 0 2px 8px rgba(21,128,61,0.3); }
    .card-check i { font-size: 0.7rem; color: #fff; display: none; }
    .card-check.checked i { display: block; animation: checkPing 0.4s ease; }
    @keyframes checkPing { 0% { transform: scale(1.5); opacity: 0; } 50% { opacity: 1; } 100% { transform: scale(1); opacity: 1; } }

    /* Card body */
    .card-body { padding: 14px 16px 12px; }
    .cat-badge { display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 999px; border: 1.5px solid #e5e7eb; font-size: 0.68rem; font-weight: 700; color: #374151; background: #f9fafb; margin-bottom: 8px; }
    .card-title { font-size: 1.05rem; font-weight: 800; color: #111827; line-height: 1.3; margin-bottom: 6px; font-family: 'Playfair Display', serif; }
    .card-address { display: flex; align-items: center; gap: 5px; font-size: 0.78rem; color: #6b7280; margin-bottom: 2px; }
    .card-date { font-size: 0.74rem; color: #9ca3af; margin-bottom: 10px; }

    /* Card footer actions */
    .card-footer { display: flex; align-items: center; justify-content: space-between; padding: 10px 16px; border-top: 1px solid #f3f4f6; }
    .card-action-link { font-size: 0.78rem; font-weight: 700; color:var(--site-primary); text-decoration: underline; text-underline-offset: 2px; cursor: pointer; background: none; border: none; padding: 0; font-family: inherit; transition: color 0.15s; }
    .card-action-link:hover { color:var(--site-primary-dark); }
    .card-delete-link { font-size: 0.78rem; font-weight: 700; color: #dc2626; text-decoration: underline; text-underline-offset: 2px; cursor: pointer; background: none; border: none; padding: 0; font-family: inherit; transition: color 0.15s; }
    .card-delete-link:hover { color: #b91c1c; }

    /* â”€â”€ Toolbar â”€â”€ */
    .toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; transition: all 0.2s ease; }
    .toolbar-check-all { width: 24px; height: 24px; border-radius: 6px; border: 2px solid #d1d5db; background: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s cubic-bezier(0.34,1.56,0.64,1); flex-shrink: 0; }
    .toolbar-check-all:hover { transform: scale(1.1); }
    .toolbar-check-all.checked { background: #15803d; border-color: #15803d; box-shadow: 0 2px 8px rgba(21,128,61,0.3); }
    .toolbar-check-all i { font-size: 0.7rem; color: #fff; display: none; }
    .toolbar-check-all.checked i { display: block; animation: checkPing 0.4s ease; }
    .toolbar-divider { width: 1px; height: 20px; background: #e5e7eb; flex-shrink: 0; }
    .toolbar-btn { width: 32px; height: 32px; border-radius: 8px; border: 1.5px solid #e5e7eb; background: #fff; color: #6b7280; font-size: 0.8rem; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s ease; flex-shrink: 0; }
    .toolbar-btn:hover { border-color: #dc2626; background: #fef2f2; color: #dc2626; }
    .toolbar-btn.refresh:hover { border-color:var(--site-primary); background: #f0fdf4; color:var(--site-primary); }

    /* â”€â”€ Buttons â”€â”€ */
    .btn-filter { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 9px; border: 1.5px solid #e5e7eb; background: #fff; font-size: 0.83rem; font-weight: 600; color: #374151; cursor: pointer; transition: all 0.15s; white-space: nowrap; font-family: inherit; }
    .btn-filter:hover { border-color: var(--site-primary); color: var(--site-primary); }
    .btn-filter.active { border-color: var(--site-primary); color: var(--site-primary); background: #f0fdf4; }
    .btn-refresh { width: 34px; height: 34px; border-radius: 9px; border: 1.5px solid #e5e7eb; background: #fff; color: #6b7280; font-size: 0.82rem; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.15s; flex-shrink: 0; }
    .btn-refresh:hover { border-color: var(--site-primary); color: var(--site-primary); }

    /* Search */
    .search-box { display: flex; align-items: center; gap: 8px; border: 1.5px solid #e5e7eb; border-radius: 9px; padding: 7px 12px; background: #fff; transition: border-color 0.15s; }
    .search-box:focus-within { border-color: var(--site-primary); }
    .search-box input { border: none; outline: none; font-size: 0.83rem; color: #374151; font-family: inherit; width: 100%; background: transparent; min-width: 120px; }

    /* â”€â”€ Pagination â”€â”€ */
    .page-btn { width: 34px; height: 34px; border-radius: 8px; border: 1.5px solid #e5e7eb; background: #fff; font-size: 0.82rem; font-weight: 600; color: #374151; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.15s; flex-shrink: 0; }
    .page-btn:hover { border-color: var(--site-primary); color: var(--site-primary); }
    .page-btn.active { background: var(--site-primary); border-color: var(--site-primary); color: #fff; }
    .page-btn:disabled { opacity: 0.35; cursor: default; }

    /* â”€â”€ Detail View â”€â”€ */
    .detail-view { background: #fff; border-radius: 16px; border: 1px solid #e5e7eb; overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,0.06); }
    .detail-photos { display: grid; grid-template-columns: 1fr 180px; gap: 8px; }
    .detail-photos .main-photo { border-radius: 12px; overflow: hidden; border: 1.5px solid #e5e7eb; height: 280px; background: #f3f4f6; }
    .detail-photos .main-photo img { width: 100%; height: 100%; object-fit: cover; display: block; cursor: pointer; transition: transform 0.2s; }
    .detail-photos .main-photo img:hover { transform: scale(1.02); }
    .detail-thumb-col { display: flex; flex-direction: column; gap: 8px; }
    .detail-thumb { border-radius: 10px; overflow: hidden; border: 1.5px solid #e5e7eb; height: calc((280px - 16px) / 3); background: #f3f4f6; cursor: pointer; }
    .detail-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; transition: opacity 0.15s; }
    .detail-thumb:hover img { opacity: 0.8; }
    .photo-placeholder-box { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; color: #d1d5db; font-size: 1.2rem; }

    /* Nav in detail */
    .detail-nav { display: flex; align-items: center; justify-content: center; gap: 12px; padding: 10px 0; }
    .detail-nav-btn { width: 32px; height: 32px; border-radius: 8px; border: 1.5px solid #e5e7eb; background: #fff; color: #6b7280; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; transition: all 0.15s; }
    .detail-nav-btn:hover { border-color: #16a34a; color: #15803d; }
    .detail-nav-dots { display: flex; gap: 4px; }
    .detail-nav-dot { width: 6px; height: 6px; border-radius: 50%; background: #d1d5db; transition: background 0.15s; }
    .detail-nav-dot.active { background: #15803d; }

    /* Info tags */
    .info-tag { display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; border-radius: 999px; border: 1.5px solid #e5e7eb; font-size: 0.76rem; font-weight: 600; color: #374151; background: #f9fafb; }

    /* Map embed */
    .map-embed { border-radius: 12px; overflow: hidden; border: 1.5px solid #e5e7eb; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }

    /* â”€â”€ Confirm Dialog â”€â”€ */
    .dialog-overlay { position: fixed; inset: 0; z-index: 900; background: rgba(5,46,22,0.45); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; padding: 20px; opacity: 0; pointer-events: none; transition: opacity 0.2s; }
    .dialog-overlay.open { opacity: 1; pointer-events: auto; }
    .dialog-box { background: #fff; border-radius: 20px; width: 100%; max-width: 460px; box-shadow: 0 24px 64px rgba(5,46,22,0.3); transform: scale(0.94) translateY(12px); transition: transform 0.25s cubic-bezier(0.34,1.56,0.64,1), opacity 0.2s; opacity: 0; overflow: hidden; }
    .dialog-overlay.open .dialog-box { transform: scale(1) translateY(0); opacity: 1; }
    .dialog-body { padding: 32px 28px 20px; text-align: center; }
    .dialog-title { font-size: 1.25rem; font-weight: 800; color: #111827; margin-bottom: 10px; font-family: 'Playfair Display', serif; }
    .dialog-desc  { font-size: 0.88rem; color: #6b7280; line-height: 1.6; }
    .dialog-footer { padding: 0; display: grid; grid-template-columns: 1fr 1fr; border-top: 1px solid #f3f4f6; }
    .dialog-btn { padding: 15px; border: none; font-size: 0.88rem; font-weight: 700; cursor: pointer; font-family: inherit; transition: all 0.15s; display: flex; align-items: center; justify-content: center; gap: 6px; }
    .dialog-btn-cancel { background: #f9fafb; color: #374151; border-radius: 0 0 0 20px; }
    .dialog-btn-cancel:hover { background: #e5e7eb; }
    .dialog-btn-delete { background: #ef4444; color: #fff; border-radius: 0 0 20px 0; }
    .dialog-btn-delete:hover { background: #dc2626; }

    /* â”€â”€ Lightbox â”€â”€ */
    .lightbox { position: fixed; inset: 0; z-index: 1000; background: rgba(0,0,0,0.88); display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity 0.2s; padding: 20px; }
    .lightbox.open { opacity: 1; pointer-events: auto; }
    .lightbox img { max-width: 90vw; max-height: 88vh; border-radius: 12px; box-shadow: 0 8px 40px rgba(0,0,0,0.5); object-fit: contain; }
    .lightbox-close { position: absolute; top: 18px; right: 22px; background: rgba(255,255,255,0.14); border: none; color: #fff; font-size: 1.1rem; width: 38px; height: 38px; border-radius: 10px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background 0.15s; }
    .lightbox-close:hover { background: rgba(255,255,255,0.28); }

    /* â”€â”€ Filter Panel â”€â”€ */
    .filter-panel { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px; box-shadow: 0 4px 16px rgba(0,0,0,0.06); }

    /* â”€â”€ Toast â”€â”€ */
    .toast-container { position: relative; top: auto; bottom: auto; right: auto; left: auto; z-index: 1; display: flex; flex-direction: column; gap: 10px; pointer-events: auto; margin-bottom: 10px; }
    .toast-item { display: flex; align-items: center; gap: 12px; padding: 14px 18px; border-radius: 12px; font-size: 0.875rem; font-weight: 600; border: 1.5px solid transparent; box-shadow: 0 8px 24px rgba(0,0,0,0.12); pointer-events: auto; animation: toastIn 0.35s cubic-bezier(0.34,1.56,0.64,1) both; width: 100%; }
    .toast-item.toast-success { background: #f0fdf4; border-color: #bbf7d0; color: #15803d; }
    .toast-item.toast-warning { background: #fefce8; border-color: #fde68a; color: #a16207; }
    .toast-item.toast-error   { background: #fef2f2; border-color: #fecaca; color: #dc2626; }
    .toast-close { margin-left: auto; background: none; border: none; cursor: pointer; font-size: 0.75rem; opacity: 0.55; color: inherit; padding: 2px 4px; }
    .toast-close:hover { opacity: 1; }
    @keyframes toastIn { from { opacity:0; transform: translateX(30px) scale(0.94); } to { opacity:1; transform: translateX(0) scale(1); } }
    @keyframes toastOut { from { opacity:1; } to { opacity:0; transform: translateX(30px); } }
    .toast-item.out { animation: toastOut 0.3s ease forwards; }

    /* Bulk bar */
    .bulk-bar { margin-bottom: 30px; background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border: 2px solid #86efac; border-radius: 12px; padding: 12px 18px; display: none; align-items: center; gap: 14px; flex-wrap: wrap; box-shadow: 0 4px 12px rgba(21,128,61,0.1); animation: slideDown 0.3s cubic-bezier(0.34,1.56,0.64,1); }
    .bulk-bar.visible { display: flex; }
    @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
    .btn-bulk-delete { display: inline-flex; align-items: center; gap: 6px; padding: 8px 18px; border-radius: 9px; border: 1.5px solid #dc2626; background: #fef2f2; color: #dc2626; font-size: 0.82rem; font-weight: 700; cursor: pointer; font-family: inherit; transition: all 0.2s ease; }
    .btn-bulk-delete:hover { background: #dc2626; color: #fff; box-shadow: 0 4px 12px rgba(220,38,38,0.3); transform: translateY(-1px); }

    /* Empty state */
    .empty-state { padding: 60px 24px; text-align: center; color: #9ca3af; }
    .empty-state i { font-size: 3rem; margin-bottom: 14px; display: block; color: #d1d5db; }

    /* Detail section labels */
    .sec-label { font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #9ca3af; margin-bottom: 4px; }
    .sec-value { font-size: 0.875rem; color: #1f2937; font-weight: 500; }

    /* â”€â”€ Loading Overlay â”€â”€ */
    .loading-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; display: flex; align-items: center; justify-content: center; }
    .spinner { width: 40px; height: 40px; border: 4px solid #f3f3f3; border-top: 4px solid #16a34a; border-radius: 50%; animation: spin 1s linear infinite; }
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

    @media (max-width: 1024px) {
      .sidebar { transform: translateX(-100%); width: 260px !important; }
      .sidebar.mobile-open { transform: translateX(0); }
      .main-content { margin-left: 0 !important; }
      .topbar-title-block { margin-left: 46px !important; }
    }
    @media (max-width: 640px) {
      .topbar { padding: 10px 14px; }
      .page-pad { padding: 14px !important; }
      .listing-grid { grid-template-columns: 1fr 1fr; gap: 12px; }
      .detail-photos { grid-template-columns: 1fr; }
      .detail-thumb-col { flex-direction: row; }
    }
    @media (max-width: 480px) {
      .listing-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>

<div class="sidebar-backdrop" id="sidebarBackdrop" onclick="closeMobileSidebar()"></div>
<button class="expand-btn" id="expandBtn"><i class="fa-solid fa-bars"></i></button>

<div class="main-wrapper">

  <!-- â•â• SIDEBAR â•â• -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-inner">
      <div class="sidebar-logo">
        <button type="button" data-nav="adminLanding.php" style="text-decoration:none;color:inherit;border:none;background:none;padding:0;text-align:left;cursor:pointer;">
          <div style="display:flex;align-items:center;gap:10px;">
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

      <p class="section-label">Management</p>
      <nav style="padding:0 8px;">
        <?php if ($role === 'admin' || in_array('dashboard', $myPerms)): ?>
        <button type="button" data-nav="adminDashboard.php" class="menu-item"><div class="menu-left"><i class="fa-solid fa-chart-bar mi"></i>Dashboard</div></button>
        <?php endif; ?>
        <?php if ($role === 'admin'): ?>
        <button type="button" data-nav="userManagement.php" class="menu-item"><div class="menu-left"><i class="fa-solid fa-user mi"></i>User Management</div></button>
        <?php endif; ?>
        <?php if ($role === 'admin' || in_array('manage_residents', $myPerms)): ?>
        <button type="button" data-nav="residentManagement.php" class="menu-item"><div class="menu-left"><i class="fa-solid fa-house-chimney-user mi"></i>Resident Management</div></button>
        <?php endif; ?>
        <?php if ($role === 'admin' || in_array('manage_beneficiaries', $myPerms)): ?>
        <button type="button" data-nav="beneficiaryManagement.php" class="menu-item"><div class="menu-left"><i class="fa-solid fa-hand-holding-heart mi"></i>Beneficiary Management</div></button>
        <?php endif; ?>
        <?php if ($role === 'admin' || in_array('manage_documents', $myPerms)): ?>
        <button type="button" data-nav="documentRequest.php" class="menu-item"><div class="menu-left"><i class="fa-regular fa-file-lines mi"></i>Document Request</div></button>
        <?php endif; ?>
        <?php if ($role === 'admin' || in_array('manage_borrowing', $myPerms)): ?>
        <button type="button" data-nav="borrowingSystem.php" class="menu-item"><div class="menu-left"><i class="fa-solid fa-hammer mi"></i>Borrowing System</div></button>
        <?php endif; ?>
      </nav>

      <p class="section-label">Community</p>
      <nav style="padding:0 8px;">
        <?php if ($role === 'admin' || in_array('manage_listings', $myPerms)): ?>
        <button type="button" class="menu-item active"><div class="menu-left"><i class="fa-solid fa-list mi"></i>Community Listings</div><span class="active-dot"></span></button>
        <?php endif; ?>
        <?php if ($role === 'admin' || in_array('manage_announcements', $myPerms)): ?>
        <button type="button" data-nav="announcement.php" class="menu-item"><div class="menu-left"><i class="fa-solid fa-pen-to-square mi"></i>Announcements</div></button>
        <?php endif; ?>
      </nav>

      <div class="sidebar-bottom">
        <div class="sidebar-bottom-links">
          <?php if ($role === 'admin'): ?>
            <button type="button" onclick="window.location.href='../settings.php'" class="side-link" style="color: rgba(255,255,255,0.55);" onmouseover="this.style.color='var(--site-primary-light)'" onmouseout="this.style.color=' rgba(255,255,255,0.55)'">Settings</button>
          <?php endif; ?>
          <div class="h-px bg-white/10 my-1 mx-2"></div>
          <button type="button" data-nav="../logout.php" class="side-link text-red-400/70 hover:text-red-300 hover:bg-white/5">Logout</button>
        </div>
      </div>
    </div>
  </aside>

  <!-- â•â• MAIN â•â• -->
  <main class="main-content" id="mainContent">

    <!-- Topbar -->
    <header class="topbar">
      <div class="topbar-title-block" id="topbarTitle">
        <h2 style="font-family:'Playfair Display',serif;font-weight:800;color:var(--site-primary-darker);font-size:1.5rem;line-height:1.2;">Community Listings</h2>
        <p style="color:#6b7280;font-size:0.85rem;margin-top:2px;">Browse and manage community facilities and resources.</p>
      </div>
    </header>

    <div id="realtimeLoader" class="flex-1 flex flex-col items-center justify-center min-h-[50vh]">
      <i class="fa-solid fa-circle-notch fa-spin text-4xl text-green-600 mb-4"></i>
      <p class="text-sm font-semibold text-green-800 animate-pulse tracking-wide">Loading listings...</p>
    </div>

    <div id="mainDataContainer" class="p-6 page-pad flex-1 space-y-5 fade-up" style="display: none;">

      <!-- Stat cards -->
      <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        <div class="stat-card">
          <p class="stat-label">New Listings This Month</p>
          <div class="stat-row"><i class="fa-solid fa-house-circle-check stat-ico" style="color: var(--site-primary);"></i><span class="stat-num"><?= number_format($listThisMonth) ?></span></div>
          <?php if ($listTrendDir === 'up'): ?>
            <span class="stat-trend stat-trend-up"><i class="fa-solid fa-arrow-up"></i> <?= $listTrendPct ?>% vs last month</span>
          <?php elseif ($listTrendDir === 'down'): ?>
            <span class="stat-trend stat-trend-down"><i class="fa-solid fa-arrow-down"></i> <?= abs($listTrendPct) ?>% vs last month</span>
          <?php else: ?>
            <span class="stat-trend stat-trend-flat"><i class="fa-solid fa-minus"></i> Same as last month</span>
          <?php endif; ?>
        </div>
        <div class="stat-card">
          <p class="stat-label">Average Listing Age</p>
          <?php if ($avgListingAgeDays !== null): ?>
            <div class="stat-row"><i class="fa-solid fa-hourglass-half stat-ico text-amber-500"></i><span class="stat-num"><?= number_format($avgListingAgeDays, 0) ?>d</span></div>
            <span class="stat-sub"><?= $listingAgeUsesUpdatedAt ? 'Since last updated' : 'Since listed' ?></span>
          <?php else: ?>
            <div class="stat-row"><i class="fa-solid fa-hourglass-half stat-ico text-gray-300"></i><span class="stat-num text-gray-300">N/A</span></div>
            <span class="stat-sub">No listings yet</span>
          <?php endif; ?>
        </div>
        <div class="stat-card">
          <p class="stat-label">Occupancy Rate</p>
          <div class="stat-row"><i class="fa-solid fa-door-open stat-ico text-blue-500"></i><span class="stat-num"><?= $occupancyRate ?>%</span></div>
          <span class="stat-sub"><?= number_format($occOccupied) ?> occupied / <?= number_format($occAvailable) ?> available</span>
        </div>
        <div class="stat-card">
          <p class="stat-label">Owner Count</p>
          <div class="stat-row"><i class="fa-solid fa-people-roof stat-ico text-purple-500"></i><span class="stat-num"><?= number_format($ownerCount) ?></span></div>
          <span class="stat-sub"><?= number_format($totalListings) ?> total listings</span>
        </div>
      </div>

      <!-- â”€â”€ LISTINGS VIEW â”€â”€ -->
      <div id="listingsView">

        <!-- Divider -->
        <div style="height:1px;background:#e5e7eb;margin-bottom:20px;"></div>

        <!-- Section Header + controls -->
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:10px;">
          <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
            <h3 style="font-size:1.15rem;font-weight:800;color:#111827;margin:0;">
              Available Listings
              <span style="color:var(--site-primary-dark);margin-left:6px;" id="listingCount"><?= $totalListings ?></span>
            </h3>
          </div>
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <div class="search-box">
              <i class="fa-solid fa-magnifying-glass" style="color:#9ca3af;font-size:0.75rem;flex-shrink:0;"></i>
              <input type="text" id="searchInput" placeholder="Search..." oninput="handleSearch()">
            </div>
            <button class="btn-filter" id="filterToggleBtn" onclick="toggleFilter()">
              Filter <i class="fa-solid fa-chevron-down" style="font-size:0.7rem;"></i>
            </button>
          </div>
        </div>

        <!-- TOAST -->
        <div class="toast-container" id="toastContainer">
          <?php if ($toastMsg): ?>
          <div class="toast-item toast-<?= $toastType ?>" id="php-toast">
            <i class="fa-solid <?= $toastType === 'warning' ? 'fa-triangle-exclamation' : 'fa-circle-check' ?>"></i>
            <span><?= htmlspecialchars($toastMsg) ?></span>
            <button class="toast-close" onclick="dismissToast(this.parentElement)"><i class="fa-solid fa-xmark"></i></button>
          </div>
          <?php endif; ?>
        </div>

        <!-- Toolbar: select-all, bulk delete, refresh -->
        <div class="toolbar" style="margin-bottom:12px;">
          <div class="toolbar-check-all" id="checkAllBtn" onclick="toggleSelectAll()" title="Select All">
            <i class="fa-solid fa-check"></i>
          </div>
          <button class="toolbar-btn" id="bulkDeleteBtn" title="Delete Selected" onclick="confirmBulkDelete(this)" style="display:none;">
            <i class="fa-solid fa-trash" style="font-size:0.75rem;"></i>
          </button>
          <div class="toolbar-divider"></div>
          <button class="toolbar-btn refresh" onclick="triggerRefresh(this)" title="Refresh">
            <i class="fa-solid fa-rotate-right" style="font-size:0.75rem;"></i>
          </button>
        </div>

        <!-- Bulk bar -->
        <div class="bulk-bar" id="bulkBar">
          <i class="fa-solid fa-circle-check" style="color:#15803d;flex-shrink:0;"></i>
          <span style="font-size:0.84rem;font-weight:700;color:#15803d;" id="bulkCountLabel">0 selected</span>
          <button class="btn-bulk-delete" onclick="confirmBulkDelete(this)">
            <i class="fa-solid fa-trash" style="font-size:0.72rem;"></i> Delete Selected
          </button>
          <button class="btn-filter" style="font-size:0.8rem;padding:6px 12px;" onclick="clearSelection()">
            <i class="fa-solid fa-xmark" style="font-size:0.7rem;"></i> Deselect All
          </button>
        </div>

        <!-- Filter panel -->
        <div class="filter-panel" id="filterPanel" style="display:none;margin-bottom:16px;">
          <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
            <div style="min-width:140px;flex:1;">
              <p style="font-size:0.72rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:5px;">Type</p>
              <select id="filterType" class="btn-filter" style="width:100%;cursor:pointer;" onchange="handleSearch()">
                <option value="">All Types</option>
                <option value="apt">Apartment / Room</option>
                <option value="business">Business</option>
              </select>
            </div>
            <div style="min-width:140px;flex:1;">
              <p style="font-size:0.72rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:5px;">Date From</p>
              <input type="date" id="filterDateFrom" class="btn-filter" style="width:100%;cursor:pointer;" onchange="handleSearch()">
            </div>
            <div style="min-width:140px;flex:1;">
              <p style="font-size:0.72rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:5px;">Date To</p>
              <input type="date" id="filterDateTo" class="btn-filter" style="width:100%;cursor:pointer;" onchange="handleSearch()">
            </div>
            <div style="align-self:flex-end;">
              <button class="btn-filter" onclick="clearFilters()" style="border-color:#ef4444;color:#dc2626;">
                <i class="fa-solid fa-xmark" style="font-size:0.72rem;"></i> Clear
              </button>
            </div>
          </div>
        </div>

        <!-- Card Grid -->
        <div class="listing-grid" id="listingGrid">
          <?php if (empty($listings)): ?>
          <div style="grid-column:1/-1;">
            <div class="empty-state">
              <i class="fa-solid fa-list-check"></i>
              <p style="font-size:1rem;font-weight:700;color:#374151;margin-bottom:4px;">No listings found</p>
              <p style="font-size:0.85rem;">No community listings have been submitted yet.</p>
            </div>
          </div>
          <?php else: foreach ($listings as $l): 
            $isApt = $l['is_apt'];
            $firstPhoto = $l['first_photo'];
          ?>
          <div class="listing-card"
            data-id="<?= (int)$l['id'] ?>"
            data-type="<?= htmlspecialchars($isApt ? 'apt' : 'business') ?>"
            data-name="<?= htmlspecialchars(strtolower($l['display_name'] . ' ' . ($l['category_label'] ?? '') . ' ' . ($l['display_address'] ?? ''))) ?>"
            data-date="<?= htmlspecialchars(!empty($l['createdAt']) ? date('Y-m-d', strtotime($l['createdAt'])) : '') ?>"
            data-listing='<?= rowJsonSafe($l) ?>'
          >
            <!-- Checkbox -->
            <div class="card-check" onclick="toggleCard(this)" title="Select">
              <i class="fa-solid fa-check"></i>
            </div>

            <!-- Photo -->
            <div class="card-photo">
              <?php if ($firstPhoto): ?>
                <img src="<?= htmlspecialchars($firstPhoto) ?>" alt="<?= htmlspecialchars($l['display_name']) ?>" onerror="this.parentElement.innerHTML='<div class=\'card-photo-placeholder\'><i class=\'fa-solid fa-image\'></i><span style=\'font-size:0.72rem;font-weight:600;color:#6b7280;\'>No photo</span></div>'">
              <?php else: ?>
                <div class="card-photo-placeholder">
                  <i class="fa-solid fa-<?= $isApt ? 'building' : 'store' ?>"></i>
                  <span style="font-size:0.72rem;font-weight:600;">No photo</span>
                </div>
              <?php endif; ?>
            </div>

            <!-- Body -->
            <div class="card-body">
              <span class="cat-badge"><?= htmlspecialchars($l['category_label']) ?></span>
              <p class="card-title"><?= htmlspecialchars($l['display_name']) ?></p>
              <?php if (!empty($l['display_address'])): ?>
              <div class="card-address">
                <i class="fa-solid fa-location-dot" style="font-size:0.7rem;color:#9ca3af;flex-shrink:0;"></i>
                <span><?= htmlspecialchars($l['display_address']) ?></span>
              </div>
              <?php endif; ?>
              <p class="card-date"><?= htmlspecialchars($l['date']) ?></p>
            </div>

            <!-- Footer -->
            <div class="card-footer">
              <button class="card-action-link" onclick="openDetailView(this.closest('.listing-card'))">View Details</button>
              <button class="card-delete-link" onclick="confirmSingleDelete(<?= (int)$l['id'] ?>, '<?= htmlspecialchars(addslashes($l['display_name'])) ?>', this)"><i class="fa-solid fa-trash" style="font-size:0.7rem;margin-right:4px;"></i>Delete</button>
            </div>
          </div>
          <?php endforeach; endif; ?>
        </div>

        <!-- Pagination -->
        <div style="display:flex;align-items:center;justify-content:center;gap:8px;margin-top:28px;flex-wrap:wrap;" id="paginationContainer"></div>

      </div><!-- /listingsView -->

      <!-- â”€â”€ DETAIL VIEW â”€â”€ -->
      <div id="detailView" style="display:none;">
        <a href="#" onclick="closeDetailView();return false;" style="display:inline-flex;align-items:center;gap:6px;color:#6b7280;font-size:0.84rem;font-weight:600;text-decoration:none;transition:color 0.15s;margin-bottom:16px;" onmouseover="this.style.color='#15803d'" onmouseout="this.style.color='#6b7280'">
          <i class="fa-solid fa-arrow-left" style="font-size:0.72rem;"></i> Back to Listings
        </a>

        <div class="detail-view" id="detailCard">
          <div style="padding:24px;" id="detailContent">
            <!-- Injected by JS -->
          </div>
        </div>
      </div>

    </div>
  </main>
</div>

<!-- â•â• CONFIRM DIALOG â•â• -->
<div class="dialog-overlay" id="dialogOverlay">
  <div class="dialog-box">
    <div class="dialog-body">
      <div style="width:64px;height:64px;border-radius:50%;background:#fee2e2;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:1.6rem;color:#dc2626;">
        <i class="fa-solid fa-trash"></i>
      </div>
      <p class="dialog-title" id="dialogTitle">Are You Sure?</p>
      <p class="dialog-desc"  id="dialogDesc">This will permanently delete the listing.</p>
    </div>
    <div class="dialog-footer">
      <button class="dialog-btn dialog-btn-cancel" onclick="closeDialog()">Cancel</button>
      <button class="dialog-btn dialog-btn-delete" id="dialogConfirmBtn">Yes</button>
    </div>
  </div>
</div>

<!-- â•â• LIGHTBOX â•â• -->
<div class="lightbox" id="lightbox" onclick="closeLightbox()">
  <button class="lightbox-close" onclick="closeLightbox()"><i class="fa-solid fa-xmark"></i></button>
  <img id="lightboxImg" src="" alt="">
</div>

<script>
/* â•â• All listings data from PHP â•â• */
const ALL_LISTINGS = <?= json_encode($listings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

/* â•â• SIDEBAR â•â• */
const sidebar=document.getElementById('sidebar'),mainContent=document.getElementById('mainContent'),expandBtn=document.getElementById('expandBtn'),collapseBtn=document.getElementById('collapseBtn'),backdrop=document.getElementById('sidebarBackdrop');
const isMobile=()=>window.innerWidth<=1024;
let collapsed=localStorage.getItem('sidebarCollapsed')==='true';
function applyCollapse(){
  if(isMobile()){sidebar.classList.remove('collapsed');mainContent.classList.remove('sidebar-collapsed');expandBtn.classList.add('visible');return;}
  sidebar.classList.remove('mobile-open');backdrop.classList.remove('visible');document.body.style.overflow='';
  if(collapsed){sidebar.classList.add('collapsed');mainContent.classList.add('sidebar-collapsed');expandBtn.classList.add('visible');}
  else{sidebar.classList.remove('collapsed');mainContent.classList.remove('sidebar-collapsed');expandBtn.classList.remove('visible');}
}
function openMobileSidebar(){sidebar.classList.add('mobile-open');backdrop.classList.add('visible');document.body.style.overflow='hidden';}
function closeMobileSidebar(){sidebar.classList.remove('mobile-open');backdrop.classList.remove('visible');document.body.style.overflow='';}
collapseBtn.addEventListener('click',()=>{if(isMobile()){closeMobileSidebar();return;}collapsed=true;localStorage.setItem('sidebarCollapsed','true');applyCollapse();});
expandBtn.addEventListener('click',()=>{if(isMobile()){openMobileSidebar();return;}collapsed=false;localStorage.setItem('sidebarCollapsed','false');applyCollapse();});
window.addEventListener('resize',applyCollapse);applyCollapse();
const realtimeLoader=document.getElementById('realtimeLoader');
const mainDataContainer=document.getElementById('mainDataContainer');
function finishLoading(){
  if(realtimeLoader) realtimeLoader.style.display='none';
  if(mainDataContainer) mainDataContainer.style.display='';
}
setTimeout(finishLoading,400);
document.querySelectorAll('[data-nav]').forEach(btn=>btn.addEventListener('click',function(){const t=this.getAttribute('data-nav');if(t){showPageLoader('Loading page...');setTimeout(()=>window.location.href=t,180);}}));

function showPageLoader(message='Loading...') {
  const content = document.getElementById('mainDataContainer');
  const loader = document.getElementById('realtimeLoader');
  if (content) content.style.display = 'none';
  if (loader) {
    const text = loader.querySelector('p');
    if (text) text.textContent = message;
    loader.style.display = 'flex';
  }
}
function hidePageLoader() {
  const content = document.getElementById('mainDataContainer');
  const loader = document.getElementById('realtimeLoader');
  if (loader) loader.style.display = 'none';
  if (content) content.style.display = '';
}
function setActionButtonLoading(btn, label = 'Loading...') {
  if (!btn) return null;
  const original = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin" style="font-size:0.72rem;"></i> ${label}`;
  return () => {
    btn.disabled = false;
    btn.innerHTML = original;
  };
}
function triggerRefresh(triggerBtn = null) {
  const resetBtn = setActionButtonLoading(triggerBtn, 'Refreshing...');
  showPageLoader('Refreshing listings...');
  setTimeout(() => { if (resetBtn) resetBtn(); }, 600);
  setTimeout(() => location.reload(), 180);
}

/* â•â• TOAST â•â• */
function showToast(type, msg) {
  const icons = {success:'fa-circle-check',warning:'fa-triangle-exclamation',error:'fa-circle-xmark'};
  const c = document.getElementById('toastContainer');
  const el = document.createElement('div');
  el.className = `toast-item toast-${type}`;
  el.innerHTML = `<i class="fa-solid ${icons[type]||'fa-circle-check'}" style="flex-shrink:0;"></i><span>${escHtml(msg)}</span><button class="toast-close" onclick="dismissToast(this.parentElement)"><i class="fa-solid fa-xmark"></i></button>`;
  c.appendChild(el);
  setTimeout(()=>dismissToast(el),4500);
}
function dismissToast(el){if(!el||el.classList.contains('out'))return;el.classList.add('out');setTimeout(()=>el.remove(),320);}
setTimeout(()=>{const t=document.getElementById('php-toast');if(t)dismissToast(t);},4000);

function escHtml(str){return String(str??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

/* â•â• SELECTION â•â• */
const selectedIds = new Set();

function toggleCard(checkEl) {
  const card = checkEl.closest('.listing-card');
  const id = parseInt(card.dataset.id);
  const isChecked = checkEl.classList.toggle('checked');
  card.classList.toggle('selected', isChecked);
  if (isChecked) selectedIds.add(id);
  else selectedIds.delete(id);
  updateBulkUI();
}

function toggleSelectAll() {
  const btn = document.getElementById('checkAllBtn');
  const allCards = getVisibleCards();
  const allChecked = allCards.every(c => c.querySelector('.card-check').classList.contains('checked'));
  allCards.forEach(card => {
    const id = parseInt(card.dataset.id);
    const cb = card.querySelector('.card-check');
    if (allChecked) { cb.classList.remove('checked'); card.classList.remove('selected'); selectedIds.delete(id); }
    else { cb.classList.add('checked'); card.classList.add('selected'); selectedIds.add(id); }
  });
  btn.classList.toggle('checked', !allChecked);
  updateBulkUI();
}

function clearSelection() {
  selectedIds.clear();
  document.querySelectorAll('.listing-card').forEach(c => {
    c.querySelector('.card-check').classList.remove('checked');
    c.classList.remove('selected');
  });
  document.getElementById('checkAllBtn').classList.remove('checked');
  updateBulkUI();
}

function updateBulkUI() {
  const count = selectedIds.size;
  const bulkBar = document.getElementById('bulkBar');
  const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
  const countLabel = document.getElementById('bulkCountLabel');
  if (count > 0) {
    bulkBar.classList.add('visible');
    bulkDeleteBtn.style.display = 'flex';
    countLabel.textContent = `${count} selected`;
  } else {
    bulkBar.classList.remove('visible');
    bulkDeleteBtn.style.display = 'none';
  }
}

function getVisibleCards() {
  return Array.from(document.querySelectorAll('.listing-card')).filter(c => c.style.display !== 'none');
}

/* â•â• FILTER / SEARCH â•â• */
function toggleFilter() {
  const p = document.getElementById('filterPanel');
  const btn = document.getElementById('filterToggleBtn');
  const isHidden = p.style.display === 'none';
  p.style.display = isHidden ? 'block' : 'none';
  btn.classList.toggle('active', isHidden);
}

function clearFilters() {
  document.getElementById('filterType').value = '';
  document.getElementById('filterDateFrom').value = '';
  document.getElementById('filterDateTo').value = '';
  document.getElementById('searchInput').value = '';
  handleSearch();
}

let filteredIds = null;

let searchTimeout;

function handleSearch() {
  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(() => {
    setTimeout(() => {
      const q = (document.getElementById('searchInput').value || '').toLowerCase().trim();
      const type = document.getElementById('filterType').value;
      const dateFrom = document.getElementById('filterDateFrom')?.value || '';
      const dateTo = document.getElementById('filterDateTo')?.value || '';

      const cards = document.querySelectorAll('.listing-card');
      let visible = 0;
      filteredIds = [];

      cards.forEach(card => {
      const name = card.dataset.name || '';
      const ctype = card.dataset.type || '';
      const cdate = card.dataset.date || '';
      const id = parseInt(card.dataset.id);

      const matchQ = !q || name.includes(q);
      const matchType = !type || ctype === type || (type === 'apt' && ctype === 'apt') || (type === 'business' && ctype === 'business');
      const matchDateFrom = !dateFrom || cdate >= dateFrom;
      const matchDateTo = !dateTo || cdate <= dateTo;

      const show = matchQ && matchType && matchDateFrom && matchDateTo;
      if (show) {
        card.dataset.filteredout = "false";
        card.style.display = '';
        visible++;
        filteredIds.push(id);
      } else {
        card.dataset.filteredout = "true";
        card.style.display = 'none';
      }
    });

    document.getElementById('listingCount').textContent = visible;
    currentPage = 1;
    renderPagination();
    }, 10);
  }, 400);
}

/* â•â• PAGINATION â•â• */
const ROWS_PER_PAGE = 9;
let currentPage = 1;

function getFilteredCards() {
  return Array.from(document.querySelectorAll('.listing-card')).filter(c => c.dataset.filteredout !== 'true');
}

function renderPagination() {
  const allCards = Array.from(document.querySelectorAll('.listing-card'));
  const visibleCards = allCards.filter(c => c.dataset.filteredout !== 'true');
  const total = visibleCards.length;
  const pages = Math.max(1, Math.ceil(total / ROWS_PER_PAGE));
  if (currentPage > pages) currentPage = pages;

  // Hide all, show only current page
  allCards.forEach(c => { c.style.display = 'none'; });
  visibleCards.forEach((c, i) => {
    c.style.display = (Math.floor(i / ROWS_PER_PAGE) + 1 === currentPage) ? '' : 'none';
  });

  const pc = document.getElementById('paginationContainer');
  pc.innerHTML = '';

  if (pages <= 1) return;

  const prev = document.createElement('button');
  prev.className = 'page-btn';
  prev.disabled = currentPage === 1;
  prev.innerHTML = '<i class="fa-solid fa-chevron-left" style="font-size:0.72rem;"></i>';
  prev.onclick = () => { currentPage--; renderPagination(); };
  pc.appendChild(prev);

  const s = Math.max(1, currentPage - 2), e = Math.min(pages, s + 4);
  for (let p = s; p <= e; p++) {
    const b = document.createElement('button');
    b.className = 'page-btn' + (p === currentPage ? ' active' : '');
    b.textContent = p;
    b.onclick = () => { currentPage = p; renderPagination(); };
    pc.appendChild(b);
  }

  const next = document.createElement('button');
  next.className = 'page-btn';
  next.disabled = currentPage === pages;
  next.innerHTML = '<i class="fa-solid fa-chevron-right" style="font-size:0.72rem;"></i>';
  next.onclick = () => { currentPage++; renderPagination(); };
  pc.appendChild(next);
}

/* Init pagination */
(function() { renderPagination(); })();

/* â•â• CONFIRM DIALOG â•â• */
let dialogCallback = null;
function openDialog(title, desc, onConfirm) {
  document.getElementById('dialogTitle').textContent = title;
  document.getElementById('dialogDesc').textContent = desc;
  dialogCallback = onConfirm;
  document.getElementById('dialogConfirmBtn').onclick = () => { closeDialog(); if (dialogCallback) dialogCallback(); };
  document.getElementById('dialogOverlay').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeDialog() {
  document.getElementById('dialogOverlay').classList.remove('open');
  document.body.style.overflow = '';
}
document.getElementById('dialogOverlay').addEventListener('click', function(e) { if (e.target === this) closeDialog(); });

/* â•â• SINGLE DELETE â•â• */
function confirmSingleDelete(id, name, triggerBtn = null) {
  openDialog(
    'Are You Sure?',
    `Are you sure you want to delete "${name}"? This will permanently delete it from the system.`,
    () => deleteListing([id], triggerBtn)
  );
}

/* â•â• BULK DELETE â•â• */
function confirmBulkDelete(triggerBtn = null) {
  if (selectedIds.size === 0) return;
  const count = selectedIds.size;
  openDialog(
    'Are You Sure?',
    `Are you sure you want to delete ${count} community listing${count > 1 ? 's' : ''}? This will permanently delete them in the system.`,
    () => deleteListing([...selectedIds], triggerBtn)
  );
}

function deleteListing(ids, triggerBtn = null) {
  const resetBtn = setActionButtonLoading(triggerBtn, 'Deleting...');
  showPageLoader('Deleting listing(s)...');
  fetch('deleteListings.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ ids })
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      showToast('warning', `${ids.length} listing${ids.length > 1 ? 's' : ''} deleted successfully.`);
      showPageLoader('Refreshing listings...');
      setTimeout(() => location.reload(), 1500);
    } else {
      showToast('error', data.message || 'Could not delete listing(s).');
      hidePageLoader();
      if (resetBtn) resetBtn();
    }
  })
  .catch(() => {
    if (resetBtn) resetBtn();
    // Fallback: redirect
    window.location.href = `deleteListings.php?ids=${ids.join(',')}&redirect=communityListings.php`;
  });
}

/* â•â• DETAIL VIEW â•â• */
const APT_TYPE_LABELS = {'bed-spacer':'Bed Spacer','studio':'Studio Type','solo-room':'Solo Room','1br':'1-Bedroom','2br':'2-Bedroom','whole-unit':'Whole Unit'};
const BATH_LABELS = {'private':'Private Bathroom','shared':'Shared Bathroom'};
const APT_STATUS_LABELS = {'available':'Available','occupied':'Fully Occupied','inquire':'Inquire First'};
const BIZ_STATUS_LABELS = {'open':'Open / Operating','new':'Newly Opened','temp-closed':'Temporarily Closed','for-rent':'Space for Rent'};
const BIZ_CAT_LABELS = {'food':'Food & Dining','water':'Water Station','sari-sari':'Sari-Sari Store','salon':'Salon / Barber','laundry':'Laundry Shop','pharmacy':'Pharmacy','printing':'Printing / Computer Shop','bakery':'Bakery / CafÃ©','hardware':'Hardware','other':'Other'};
const INC_LABELS = {'electric':'Electricity','water':'Water','wifi':'WiFi','cable':'Cable TV'};
const AMN_LABELS = {'aircon':'Aircon','fan':'Electric Fan','parking':'Parking','laundry':'Laundry Area','cctv':'CCTV','security':'Security','kitchen':'Shared Kitchen','gate':'Gated Compound'};
const RULES_LABELS = {'no-smoking':'No Smoking','no-pets':'No Pets','no-visitors':'No Overnight Visitors','curfew':'Curfew Policy','no-cooking':'No Cooking Inside'};
const FEAT_LABELS = {'delivery':'Delivery','pickup':'Pick-up','dine-in':'Dine-in','parking':'Parking','gcash':'GCash','maya':'Maya','wifi':'Free WiFi','aircon':'Aircon'};
const DAYS_LABELS = {'mon':'Mon','tue':'Tue','wed':'Wed','thu':'Thu','fri':'Fri','sat':'Sat','sun':'Sun','holiday':'Holidays'};
const YEARS_LABELS = {'new':'Just opened','1':'1 year','2-5':'2â€“5 years','5-10':'5â€“10 years','10+':'10+ years'};

function parseArr(v){if(!v)return[];if(Array.isArray(v))return v;try{const p=JSON.parse(v);return Array.isArray(p)?p:[];}catch(e){return[];}}

function tagList(arr, labels) {
  if (!arr || !arr.length) return '<span style="color:#9ca3af;font-style:italic;font-size:0.82rem;">None</span>';
  return arr.map(v => `<span class="info-tag">${escHtml(labels[v] || v)}</span>`).join(' ');
}

function secRow(label, val) {
  const v = val && String(val).trim() ? String(val) : null;
  return `<div><p class="sec-label">${escHtml(label)}</p><p class="sec-value">${v ? escHtml(v) : '<span style="color:#d1d5db;">â€”</span>'}</p></div>`;
}

let currentDetailIdx = 0;
let detailPhotoIdx = 0;

function openDetailView(card) {
  const raw = card.getAttribute('data-listing');
  if (!raw) return;
  const l = JSON.parse(raw);

  // Find index in ALL_LISTINGS for prev/next nav
  currentDetailIdx = ALL_LISTINGS.findIndex(x => x.id == l.id);
  detailPhotoIdx = 0;

  renderDetailContent(l);
  document.getElementById('listingsView').style.display = 'none';
  document.getElementById('detailView').style.display = 'block';
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function closeDetailView() {
  document.getElementById('listingsView').style.display = 'block';
  document.getElementById('detailView').style.display = 'none';
}

function renderDetailContent(l) {
  const isApt = l.is_apt;
  const photos = l.photos_arr || [];

  const inc   = parseArr(l.aptIncluded);
  const amn   = parseArr(l.aptAmenities);
  const rules = parseArr(l.aptRules);
  const feat  = parseArr(l.bussFeatures);
  const days  = parseArr(l.bussDays);

  /* Photo grid */
  const mainPhotoSrc = photos.length > detailPhotoIdx ? photos[detailPhotoIdx] : null;
  let thumbsHtml = '';
  for (let i = 1; i <= 3; i++) {
    const src = photos[i] || null;
    thumbsHtml += `<div class="detail-thumb" onclick="${src ? `openLightbox('${escHtml(src)}')` : ''}">`;
    thumbsHtml += src ? `<img src="${escHtml(src)}" alt="Photo ${i+1}">` : `<div class="photo-placeholder-box"><i class="fa-regular fa-image"></i></div>`;
    thumbsHtml += `</div>`;
  }

  /* Nav dots */
  const dots = photos.map((_, i) => `<div class="detail-nav-dot${i === detailPhotoIdx ? ' active' : ''}"></div>`).join('');

  let html = `
  <div class="detail-photos" style="margin-bottom:4px;">
    <div class="detail-photo-main-wrap">
      <div class="detail-photos" style="grid-template-columns:1fr 180px;gap:8px;">
        <div class="main-photo" style="height:280px;">
          ${mainPhotoSrc
            ? `<img src="${escHtml(mainPhotoSrc)}" alt="Main photo" onclick="openLightbox('${escHtml(mainPhotoSrc)}')" style="cursor:pointer;">`
            : `<div class="card-photo-placeholder"><i class="fa-solid fa-${isApt ? 'building' : 'store'}" style="font-size:2.2rem;color:#86efac;"></i><span style="font-size:0.75rem;font-weight:600;color:#6b7280;">No photo</span></div>`}
        </div>
        <div class="detail-thumb-col">${thumbsHtml}</div>
      </div>
    </div>
  </div>`;

  /* Photo nav */
  if (photos.length > 1) {
    html += `<div class="detail-nav">
      <button class="detail-nav-btn" onclick="navDetailPhoto(-1)"><i class="fa-solid fa-chevron-left" style="font-size:0.75rem;"></i></button>
      <span style="font-size:0.82rem;color:#374151;font-weight:600;">${escHtml(l.display_name)}</span>
      <button class="detail-nav-btn" onclick="navDetailPhoto(1)"><i class="fa-solid fa-chevron-right" style="font-size:0.75rem;"></i></button>
    </div>
    <div class="detail-nav-dots" style="display:flex;justify-content:center;gap:4px;margin-bottom:16px;">${dots}</div>`;
  } else {
    html += `<div style="margin:10px 0 16px;"><p style="font-weight:700;color:#374151;font-size:0.9rem;"><i class="fa-regular fa-clock" style="margin-right:6px;color:#9ca3af;"></i>Posted ${l.date}</p></div>`;
  }

  /* Title & amenity tags */
  const statusLabel = isApt ? (APT_STATUS_LABELS[l.aptStatus] || l.aptStatus || '') : (BIZ_STATUS_LABELS[l.bussStatus] || l.bussStatus || '');
  const allTags = isApt
    ? [...inc.map(v => INC_LABELS[v]||v), ...amn.map(v => AMN_LABELS[v]||v)].slice(0, 6)
    : [...feat.map(v => FEAT_LABELS[v]||v)].slice(0, 6);

  html += `<h2 style="font-family:'Playfair Display',serif;font-size:1.4rem;font-weight:800;color:#052e16;margin:0 0 10px;">${escHtml(l.display_name)} Details</h2>`;

  if (allTags.length) {
    html += `<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;">
      ${allTags.map(t => `<span class="info-tag"><i class="fa-solid fa-circle-check" style="font-size:0.65rem;color:#15803d;"></i> ${escHtml(t)}</span>`).join('')}
    </div>`;
  }

  /* Owner info */
  const ownerInitials = (l.owner_name || 'U').replace(/[^A-Za-z]/g, '').substring(0, 2).toUpperCase();
  html += `<div style="display:flex;align-items:center;gap:14px;padding:14px;background:#f9fafb;border-radius:12px;border:1px solid #e5e7eb;margin-bottom:16px;">
    <div style="width:44px;height:44px;border-radius:50%;background:#15803d;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
      <span style="color:#fff;font-size:0.9rem;font-weight:700;">${escHtml(ownerInitials)}</span>
    </div>
    <div>
      <p style="font-weight:700;color:#111827;font-size:0.9rem;margin:0 0 1px;">${escHtml(l.owner_name || 'Unknown Owner')}</p>
      <p style="font-size:0.78rem;color:#6b7280;margin:0;">Property Owner</p>
    </div>
    <div style="margin-left:auto;text-align:right;">
      <p style="font-size:0.88rem;font-weight:700;color:#374151;">${escHtml(l.owner_phone || l.contact || 'â€”')}</p>
      <p style="font-size:0.78rem;color:#15803d;">${escHtml(l.owner_email || l.email || '')}</p>
    </div>
  </div>`;

  /* Map embed */
  const mapsLink = isApt ? l.aptMapsLink : l.bussMapsLink;
  html += `<div style="margin-bottom:16px;">
    <p style="font-size:0.82rem;font-weight:700;color:#374151;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
      <i class="fa-solid fa-location-dot" style="color:#15803d;"></i> Location
    </p>
    <div class="map-embed" style="height:200px;overflow:hidden;">
      <iframe
        src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d14621.501652076073!2d120.94359404606583!3d15.449148612302375!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x339728a28ec550ad%3A0xaa3d1730b123812c!2sSumacab%20Este%2C%20Cabanatuan%20City%2C%20Nueva%20Ecija!5e1!3m2!1sen!2sph!4v1773387631553!5m2!1sen!2sph"
        width="100%" height="200" style="border:0;display:block;" allowfullscreen loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
    </div>
    ${mapsLink ? `<a href="${escHtml(mapsLink)}" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:5px;font-size:0.78rem;font-weight:700;color:#15803d;margin-top:8px;text-decoration:underline;text-underline-offset:2px;"><i class="fa-solid fa-map-pin" style="font-size:0.7rem;"></i> Open in Google Maps</a>` : ''}
  </div>`;

  /* Additional info */
  if (isApt) {
    html += `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;margin-bottom:16px;">
      ${secRow('Listing Title', l.aptTitle)}
      ${secRow('Room Type', APT_TYPE_LABELS[l.aptType] || l.aptType)}
      ${secRow('Availability', statusLabel)}
      ${secRow('Monthly Rent', l.aptPrice ? 'â‚± ' + Number(l.aptPrice).toLocaleString() : '')}
      ${secRow('Floor / Level', l.aptFloor)}
      ${secRow('Rooms', l.aptRooms)}
      ${secRow('Max Occupants', l.aptOccupants)}
      ${secRow('Bathroom', BATH_LABELS[l.aptBath] || l.aptBath)}
      ${secRow('Slots Available', l.slotsAvailable)}
    </div>
    <div style="margin-bottom:12px;"><p class="sec-label" style="margin-bottom:6px;">House Rules</p><div style="display:flex;flex-wrap:wrap;gap:6px;">${tagList(rules, RULES_LABELS)}</div></div>
    ${l.aptDesc ? `<div style="margin-bottom:12px;"><p class="sec-label" style="margin-bottom:4px;">Description</p><p style="font-size:0.875rem;color:#374151;line-height:1.7;white-space:pre-wrap;">${escHtml(l.aptDesc)}</p></div>` : ''}`;
  } else {
    html += `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;margin-bottom:16px;">
      ${secRow('Category', BIZ_CAT_LABELS[l.bussCat] || l.bussCat)}
      ${secRow('Status', statusLabel)}
      ${secRow('Starting Price', l.bussPrice ? 'â‚± ' + l.bussPrice : '')}
      ${secRow('Years in Business', YEARS_LABELS[l.bussYears] || l.bussYears)}
      ${secRow('Opens', l.bussOpen || '')}
      ${secRow('Closes', l.bussClose || '')}
    </div>
    <div style="margin-bottom:12px;"><p class="sec-label" style="margin-bottom:6px;">Days Open</p><div style="display:flex;flex-wrap:wrap;gap:6px;">${tagList(days, DAYS_LABELS)}</div></div>
    ${l.bussDesc ? `<div style="margin-bottom:12px;"><p class="sec-label" style="margin-bottom:4px;">Description</p><p style="font-size:0.875rem;color:#374151;line-height:1.7;white-space:pre-wrap;">${escHtml(l.bussDesc)}</p></div>` : ''}`;
  }

  /* Footer: date & delete */
  html += `<div style="display:flex;align-items:center;justify-content:space-between;padding-top:16px;border-top:1px solid #f3f4f6;margin-top:8px;">
    <p style="font-size:0.78rem;color:#9ca3af;">Listing #${escHtml(String(l.id))} Â· ${escHtml(l.date)}</p>
    <button class="card-delete-link" onclick="confirmSingleDelete(${l.id}, '${escHtml((l.display_name||'').replace(/'/g, "\\'"))}', this);closeDetailView();"><i class="fa-solid fa-trash" style="font-size:0.7rem;margin-right:4px;"></i>Delete Listing</button>
  </div>`;

  document.getElementById('detailContent').innerHTML = html;
}

function navDetailPhoto(dir) {
  if (currentDetailIdx < 0) return;
  const l = ALL_LISTINGS[currentDetailIdx];
  const photos = l.photos_arr || [];
  if (!photos.length) return;
  detailPhotoIdx = (detailPhotoIdx + dir + photos.length) % photos.length;
  renderDetailContent(l);
}

/* â•â• LIGHTBOX â•â• */
function openLightbox(src) {
  if (!src) return;
  document.getElementById('lightboxImg').src = src;
  document.getElementById('lightbox').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeLightbox() {
  document.getElementById('lightbox').classList.remove('open');
  document.body.style.overflow = '';
}

/* Keyboard */
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') { closeDialog(); closeLightbox(); }
});
</script>
</body>
</html>