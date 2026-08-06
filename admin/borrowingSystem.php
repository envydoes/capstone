<?php
session_start();

// 1. Session Gate
if (!isset($_SESSION['user_id'])) { 
    header('Location: ../login.php'); 
    exit; 
}

// 2. Role Gate
$role = $_SESSION['account_role'] ?? '';
require_once __DIR__ . '/../includes/check_permissions.php';

// 3. Connect to Database FIRST
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
require_permission($conn, 'manage_borrowing');

// â”€â”€ Fetch Equipment â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$equipmentSQL = "SELECT equipmentId AS equipmentID, equipmentName AS equipment_name, equipmentStock AS quantity_in_storage, equipmentImage AS image_path, description AS description, createdAt AS created_at, updatedAt AS updated_at FROM tbl_equipmentlist ORDER BY createdAt DESC";
$equipmentResult = mysqli_query($conn, $equipmentSQL);
$equipmentList = [];
if ($equipmentResult) { while ($row = mysqli_fetch_assoc($equipmentResult)) $equipmentList[] = $row; mysqli_free_result($equipmentResult); }
$totalEquipment = count($equipmentList);

require_once '../includes/site_config.php';
$siteSettings = site_config_load($conn);

// â”€â”€ Fetch Borrow Requests â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$borrowSQL = "
    SELECT br.id AS requestID, br.userId AS user_id, br.equipmentId AS equipment_id,
           br.quantityRequested AS qty_requested, br.status,
           br.requestDate AS submitted_at, br.approvedDate, br.returnDate AS return_date,
           br.notes,
           e.equipmentName AS equipment_name, e.equipmentStock AS quantity_in_storage, e.equipmentImage AS image_path,
           CONCAT(u.firstname, ' ', IF(u.middlename != '' AND u.middlename IS NOT NULL, CONCAT(LEFT(u.middlename,1), '. '), ''), u.lastname) AS borrower_name
    FROM tbl_equipmentrequest br
    JOIN tbl_equipmentlist e ON br.equipmentId = e.equipmentId
    JOIN tbl_userinfo u ON br.userId = u.userID
    ORDER BY br.requestDate ASC
";
$borrowResult = mysqli_query($conn, $borrowSQL);
$borrowList = [];
if ($borrowResult) { while ($row = mysqli_fetch_assoc($borrowResult)) $borrowList[] = $row; mysqli_free_result($borrowResult); }
$totalBorrow = count($borrowList);

$today = date('Y-m-d');

// â”€â”€ Stat cards: Borrowing Overview â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

// Borrow Requests This Month
$borrowThisMonth = (int) mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(*) AS total FROM tbl_equipmentrequest
    WHERE requestDate >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
"))['total'];

// Average Borrow Duration: requested -> returned, for items actually returned.
// `returnDate` is set at request time to the *requested* return date, then
// (per the app's own JS, which stamps returnDate = today on the "Mark
// Returned" action) gets overwritten with the *actual* return date once
// status becomes 'Returned'. So for already-returned items this reads as
// actual hold time.
$avgDurRow = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT AVG(TIMESTAMPDIFF(HOUR, requestDate, returnDate)) AS avg_hours
    FROM tbl_equipmentrequest
    WHERE LOWER(status) = 'returned'
"));
$avgBorrowHours = ($avgDurRow && $avgDurRow['avg_hours'] !== null)
    ? round((float) $avgDurRow['avg_hours'], 1)
    : null;

// Return Rate: % returned on time vs late.
// Needs a due date that survives the actual return â€” i.e. a separate
// `dueDate` column set once and never overwritten, unlike `returnDate`
// which gets reused for the actual return timestamp. Reads as N/A until
// that column exists. See add_due_date.sql.
$onTimeRate = null;
$hasDueDateCol = mysqli_query($conn, "SHOW COLUMNS FROM tbl_equipmentrequest LIKE 'dueDate'");
if ($hasDueDateCol && mysqli_num_rows($hasDueDateCol) > 0) {
    $rateRow = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT
            SUM(CASE WHEN returnDate <= dueDate THEN 1 ELSE 0 END) AS on_time,
            COUNT(*) AS total
        FROM tbl_equipmentrequest
        WHERE LOWER(status) = 'returned' AND dueDate IS NOT NULL
    "));
    if ($rateRow && (int) $rateRow['total'] > 0) {
        $onTimeRate = round(((int) $rateRow['on_time'] / (int) $rateRow['total']) * 100);
    }
}

// Repeat Borrowers: residents who've borrowed 3+ times (any status)
$repeatBorrowRow = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(*) AS total FROM (
        SELECT userId FROM tbl_equipmentrequest GROUP BY userId HAVING COUNT(*) >= 3
    ) t
"));
$repeatBorrowers = (int) ($repeatBorrowRow['total'] ?? 0);

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Borrowing System - <?= e($siteSettings['site_title']) ?></title>
  <link rel="icon" href="<?= e(site_config_logo_url($siteSettings, '../')) ?>" type="image/png">
  <?= site_config_css_vars($siteSettings) ?>
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
    .main-content { flex: 1; min-width: 0; display: flex; flex-direction: column; width: calc(100% - 260px); margin-left: 260px; transition: margin-left 0.3s cubic-bezier(0.4,0,0.2,1); overflow-x: hidden; }
    .main-content.sidebar-collapsed { width: 100%; margin-left: 0; }

    /* â”€â”€ Topbar â”€â”€ */
    .topbar { background: #fff; border-bottom: 1px solid #e5e7eb; padding: 14px 28px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; position: sticky; top: 0; z-index: 100; }
    .topbar-title-block { transition: margin-left 0.25s ease; }

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

    /* â”€â”€ Table â”€â”€ */
    .tbl-wrap { background: #fff; border-radius: 14px; border: 1px solid #e5e7eb; box-shadow: 0 2px 12px rgba(21,128,61,0.05); overflow-x: auto; -webkit-overflow-scrolling: touch; }
    table { width: 100%; border-collapse: collapse; min-width: 520px; }
    thead th { background: #f9fafb; padding: 11px 16px; text-align: left; font-size: 0.75rem; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.06em; border-bottom: 1px solid #e5e7eb; white-space: nowrap; }
    tbody tr { border-bottom: 1px solid #f3f4f6; transition: background 0.15s; }
    tbody tr:last-child { border-bottom: none; }
    tbody tr:hover { background: #f0fdf4; }
    tbody td { padding: 14px 16px; font-size: 0.84rem; color: #374151; vertical-align: middle; }

    /* Status chips */
    .chip { display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 999px; font-size: 0.71rem; font-weight: 700; border: 1.5px solid; }
    .chip-available   { background: #f0fdf4; color: #15803d; border-color: #bbf7d0; }
    .chip-unavailable { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
    .chip-borrowed    { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
    .chip-returned    { background: #f3f4f6; color: #6b7280; border-color: #e5e7eb; }
    .chip-pending     { background: #fefce8; color: #a16207; border-color: #fde68a; }
    .chip-rejected    { background: #fef2f2; color: #dc2626; border-color: #fecaca; }

    /* Return date color coding */
    .return-date-ontime  { color: #15803d; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; }
    .return-date-overdue { color: #dc2626; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; }
    .return-date-neutral { color: #6b7280; }

    /* Buttons */
    .btn-primary { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 9px; background: var(--site-primary); color: #fff; border: none; font-size: 0.83rem; font-weight: 700; cursor: pointer; transition: background 0.15s; white-space: nowrap; font-family: inherit; }
    .btn-primary:hover { background: var(--site-primary-dark); }
    .btn-approve { display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; border-radius: 8px; border: 1.5px solid #16a34a; color: #15803d; background: #f0fdf4; font-size: 0.75rem; font-weight: 700; cursor: pointer; transition: all 0.15s; white-space: nowrap; font-family: inherit; }
    .btn-approve:hover:not(:disabled) { background: #16a34a; color: #fff; }
    .btn-approve:disabled { opacity: 0.4; cursor: not-allowed; pointer-events: none; }
    .btn-reject { display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; border-radius: 8px; border: 1.5px solid #ef4444; color: #dc2626; background: #fef2f2; font-size: 0.75rem; font-weight: 700; cursor: pointer; transition: all 0.15s; white-space: nowrap; font-family: inherit; }
    .btn-reject:hover { background: #ef4444; color: #fff; }
    .btn-return { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 9px; border: 1.5px solid #10b981; color: #065f46; background: #dcfce7; font-size: 0.78rem; font-weight: 700; cursor: pointer; transition: all 0.15s ease-in-out; white-space: nowrap; font-family: inherit; }
    .btn-return:hover, .btn-return:focus { background: #10b981; color: #fff; border-color: #059669; transform: translateY(-1px); box-shadow: 0 8px 20px rgba(16,185,129,0.22); }
    .btn-return:active { transform: translateY(0); }
    .icon-btn { width: 30px; height: 30px; border-radius: 8px; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; transition: all 0.15s; flex-shrink: 0; }
    .icon-btn-edit    { background: #f0fdf4; color: #15803d; }
    .icon-btn-edit:hover    { background: #dcfce7; }
    .icon-btn-archive { background: #f9fafb; color: #6b7280; }
    .icon-btn-archive:hover { background: #fee2e2; color: #dc2626; }
    .icon-btn-restore { background: #f0fdf4; color: #15803d; }
    .icon-btn-restore:hover { background: #dcfce7; }
    .btn-icon.danger { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 8px; border: none; cursor: pointer; background: #f9fafb; color: #6b7280; font-size: 0.8rem; transition: all 0.15s; flex-shrink: 0; }
    .btn-icon.danger:hover { background: #fee2e2; color: #dc2626; }
    .btn-filter{display:inline-flex;align-items:center;gap:6px;padding:7px 16px;border-radius:9px;border:1.5px solid #e5e7eb;background:#fff;font-size:.83rem;font-weight:600;color:#374151;cursor:pointer;transition:all .15s;white-space:nowrap}
    .btn-filter:hover{border-color:var(--site-primary);color:var(--site-primary)}
    .status-pill { padding:5px 14px;border-radius:999px;border:1.5px solid #e5e7eb;background:#fff;font-size:.75rem;font-weight:700;color:#6b7280;cursor:pointer;transition:all .15s;white-space:nowrap; }
    .status-pill:hover { border-color:var(--site-primary);color:var(--site-primary); }
    .status-pill.active-pill { background:#6b7280;border-color:#6b7280;color:#fff; }
    .status-pill[data-status="pending"].active-pill  { background:#d97706;border-color:#d97706;color:#fff; }
    .status-pill[data-status="approved"].active-pill { background:#15803d;border-color:#15803d;color:#fff; }
    .status-pill[data-status="rejected"].active-pill { background:#dc2626;border-color:#dc2626;color:#fff; }
    .status-pill[data-status="borrowed"].active-pill { background:#1d4ed8;border-color:#1d4ed8;color:#fff; }
    .status-pill[data-status="returned"].active-pill { background:#6b7280;border-color:#6b7280;color:#fff; }
    .table-loader { position: absolute; inset: 0; background: rgba(255,255,255,0.86); backdrop-filter: blur(4px); z-index: 10; display: flex; flex-direction: column; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity 0.2s ease; }
    .table-loader.visible { opacity: 1; pointer-events: auto; }
    .table-loader i { font-size: 1.5rem; color: #16a34a; margin-bottom: 10px; }
    .table-loader span { font-size: 0.78rem; color: #065f46; font-weight: 700; }
    .btn-refresh{width:30px;height:30px;border-radius:8px;border:1.5px solid #e5e7eb;background:#fff;color:#6b7280;font-size:.82rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s;flex-shrink:0}
    .btn-refresh:hover{border-color:var(--site-primary);color:var(--site-primary)}

    /* Search */
    .search-box { display:flex; align-items:center; gap:8px; border:1.5px solid #e5e7eb; border-radius:9px; padding:7px 12px; background:#fff; transition:border-color 0.15s; }
    .search-box:focus-within { border-color:var(--site-primary); }
    .search-box input { border:none; outline:none; font-size:0.83rem; color:#374151; font-family:inherit; width:100%; background:transparent; }

    /* Bulk bar */
    #bulkBar { border-radius: 12px; }
    #bulkApproveBtn:disabled { opacity: 0.4; cursor: not-allowed; pointer-events: none; }

    /* Tabs */
    .tab-btn { flex: 1; padding: 12px 20px; border: none; font-size: 0.83rem; font-weight: 600; cursor: pointer; transition: all 0.18s; color: #6b7280; background: #f9fafb; white-space: nowrap; border-bottom: 2px solid transparent; }
.tab-btn.active {
    background: var(--site-primary-pale);
    color: var(--site-primary);
    border-bottom-color: var(--site-primary);
}    .tab-btn:first-child { border-radius: 14px 0 0 0; }
    .tab-btn:last-child  { border-radius: 0 14px 0 0; }
    .tab-btn:hover:not(.active) { color: #374151; background: #f3f4f6; }

    /* Pagination */
    .page-btn { width: 34px; height: 34px; border-radius: 8px; border: 1.5px solid #e5e7eb; background: #fff; font-size: 0.82rem; font-weight: 600; color: #374151; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.15s; flex-shrink: 0; }
    .page-btn:hover { border-color: var(--site-primary); color: var(--site-primary); }
    .page-btn.active { background: var(--site-primary); border-color: var(--site-primary); color: #fff; }
    .page-btn:disabled { opacity: 0.35; cursor: default; }

    /* â•â• MODAL â•â• */
    .modal-overlay { position: fixed; inset: 0; z-index: 800; background: rgba(5,46,22,0.45); backdrop-filter: blur(4px); display: flex; align-items: flex-start; justify-content: center; padding: 16px; overflow-y: auto; opacity: 0; pointer-events: none; transition: opacity 0.22s; }
    .modal-overlay.open { opacity: 1; pointer-events: auto; }
    .modal { background: #fff; border-radius: 18px; width: 100%; max-width: 640px; box-shadow: 0 24px 60px rgba(5,46,22,0.22); transform: translateY(16px); transition: transform 0.25s cubic-bezier(0.4,0,0.2,1); margin: auto; display: flex; flex-direction: column; }
    .modal-overlay.open .modal { transform: translateY(0); }
    .modal-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px 13px; border-bottom: 1px solid #f3f4f6; flex-shrink: 0; gap: 8px; position: sticky; top: 0; background: #fff; border-radius: 18px 18px 0 0; z-index: 10; }
    .modal-close { width: 28px; height: 28px; border-radius: 8px; border: none; background: #f3f4f6; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #6b7280; font-size: 0.78rem; transition: background 0.15s, color 0.15s; flex-shrink: 0; }
    .modal-close:hover { background: #fee2e2; color: #dc2626; }
    .modal-body { padding: 18px 20px; overflow-y: auto; max-height: calc(100vh - 170px); }
    .modal-body::-webkit-scrollbar { width: 4px; }
    .modal-body::-webkit-scrollbar-thumb { background: #d1fae5; border-radius: 4px; }

    /* Section cards */
    .section-card { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px; margin-bottom: 16px; }
    .section-card:last-child { margin-bottom: 0; }
    .section-title { display: flex; align-items: center; gap: 8px; font-size: 0.78rem; font-weight: 700; color: #374151; text-transform: uppercase; letter-spacing: 0.07em; margin-bottom: 14px; }
    .section-icon { width: 26px; height: 26px; background: #dcfce7; border-radius: 7px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }

    /* Fields */
    .field-label { display: block; font-size: 0.72rem; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 5px; }
    .field-input { width: 100%; padding: 9px 12px; border: 1.5px solid #e5e7eb; border-radius: 9px; font-size: 0.84rem; color: #374151; background: #fff; font-family: inherit; outline: none; transition: border-color 0.15s, box-shadow 0.15s; }
    .field-input:focus { border-color: #16a34a; box-shadow: 0 0 0 3px rgba(22,163,74,0.1); }
    .field-input.changed { border-color: #f59e0b; background: #fffbeb; }
    .field-input[readonly] { background: #f9fafb; cursor: default; color: #6b7280; }
    textarea.field-input { resize: vertical; min-height: 84px; }
    .required-star { color: #dc2626; }

    .changes-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 999px; background: #fef9c3; color: #a16207; font-size: 0.72rem; font-weight: 700; border: 1px solid #fde047; white-space: nowrap; }

    /* Image preview zone */
    .img-zone { border: 2px dashed #d1d5db; border-radius: 12px; background: #f9fafb; overflow: hidden; position: relative; transition: border-color 0.15s, background 0.15s; cursor: pointer; }
    .img-zone.has-img { border: 2px solid #bbf7d0; background: #fff; }
    .img-zone img { width: 100%; height: 100%; object-fit: cover; display: block; border-radius: 10px; }
    .img-zone-overlay { position: absolute; inset: 0; background: rgba(5,46,22,0.55); display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px; opacity: 0; transition: opacity 0.18s; border-radius: 10px; }
    .img-zone:hover .img-zone-overlay { opacity: 1; }
    .img-zone-placeholder { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; height: 150px; color: #9ca3af; font-size: 0.75rem; }
    .img-zone-placeholder i { font-size: 2rem; color: #d1d5db; }

    /* Modal footer */
    .modal-footer { display: grid; grid-template-columns: 1fr 1fr; border-top: 1px solid #f3f4f6; flex-shrink: 0; border-radius: 0 0 18px 18px; overflow: hidden; }
    .mf-btn { padding: 14px; border: none; font-size: 0.88rem; font-weight: 700; cursor: pointer; font-family: inherit; transition: all 0.15s; display: flex; align-items: center; justify-content: center; gap: 6px; }
    .mf-cancel { background: #f9fafb; color: #374151; }
    .mf-cancel:hover { background: #e5e7eb; }
    .mf-update { background: #15803d; color: #fff; }
    .mf-update:hover:not(:disabled) { background: #166534; }
    .mf-update:disabled { background: #d1d5db; color: #9ca3af; cursor: not-allowed; }

    /* Lightbox */
    .lightbox { position: fixed; inset: 0; z-index: 1000; background: rgba(0,0,0,0.86); display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity 0.2s; padding: 20px; }
    .lightbox.open { opacity: 1; pointer-events: auto; }
    .lightbox img { max-width: 90vw; max-height: 88vh; border-radius: 12px; box-shadow: 0 8px 40px rgba(0,0,0,0.5); object-fit: contain; }
    .lightbox-close { position: absolute; top: 18px; right: 22px; background: rgba(255,255,255,0.14); border: none; color: #fff; font-size: 1.1rem; width: 38px; height: 38px; border-radius: 10px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background 0.15s; }
    .lightbox-close:hover { background: rgba(255,255,255,0.28); }
    .lightbox-caption { position: absolute; bottom: 20px; left: 50%; transform: translateX(-50%); color: rgba(255,255,255,0.7); font-size: 0.8rem; }

    /* Confirm Dialog */
    .dialog-overlay { position: fixed; inset: 0; z-index: 900; background: rgba(5,46,22,0.5); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; padding: 20px; opacity: 0; pointer-events: none; transition: opacity 0.2s; }
    .dialog-overlay.open { opacity: 1; pointer-events: auto; }
    .dialog-box { background: #fff; border-radius: 20px; width: 100%; max-width: 400px; box-shadow: 0 24px 64px rgba(5,46,22,0.3); transform: scale(0.94) translateY(12px); transition: transform 0.25s cubic-bezier(0.34,1.56,0.64,1), opacity 0.2s; opacity: 0; overflow: hidden; }
    .dialog-overlay.open .dialog-box { transform: scale(1) translateY(0); opacity: 1; }
    .dialog-icon-wrap { width: 64px; height: 64px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 1.6rem; }
    .dialog-body { padding: 28px 24px 20px; text-align: center; }
    .dialog-title { font-size: 1.05rem; font-weight: 800; color: #111827; margin-bottom: 8px; font-family: 'Playfair Display', serif; }
    .dialog-desc  { font-size: 0.84rem; color: #6b7280; line-height: 1.5; }
    .dialog-name-badge { display: inline-block; margin-top: 10px; background: #f3f4f6; border-radius: 8px; padding: 6px 14px; font-size: 0.82rem; font-weight: 700; color: #374151; }
    .dialog-footer { padding: 0 20px 20px; display: flex; gap: 10px; }
    .dialog-btn { flex: 1; padding: 11px; border-radius: 11px; border: none; font-size: 0.86rem; font-weight: 700; cursor: pointer; font-family: inherit; transition: all 0.15s; display: flex; align-items: center; justify-content: center; gap: 6px; }
    .dialog-btn-cancel  { background: #f3f4f6; color: #374151; }
    .dialog-btn-cancel:hover { background: #e5e7eb; }
    .dialog-btn-approve { background: linear-gradient(135deg,#16a34a,#15803d); color: #fff; box-shadow: 0 4px 14px rgba(22,163,74,0.35); }
    .dialog-btn-approve:hover { transform: translateY(-1px); }
    .dialog-btn-reject  { background: linear-gradient(135deg,#ef4444,#dc2626); color: #fff; }
    .dialog-btn-reject:hover { transform: translateY(-1px); }
    .dialog-btn-warning { background: linear-gradient(135deg,#f59e0b,#d97706); color: #fff; }
    .dialog-btn-warning:hover { transform: translateY(-1px); }
    .dialog-btn-delete  { background: linear-gradient(135deg,#dc2626,#b91c1c); color: #fff; }
    .dialog-btn-delete:hover { transform: translateY(-1px); }

    /* Alert */
    #alertBanner { display: none; border-radius: 10px; }
    #alertBanner.show { display: flex; }
    .alert-inner { display: flex; align-items: center; gap: 10px; padding: 13px 16px; font-size: 0.85rem; font-weight: 600; border-radius: 10px; border: 1.5px solid transparent; width: 100%; }
    .alert-success { background: #f0fdf4; border-color: #bbf7d0; color: #15803d; }
    .alert-error   { background: #fef2f2; border-color: #fecaca; color: #dc2626; }
    .alert-warning { background: #fefce8; border-color: #fde68a; color: #a16207; }
    .alert-close { margin-left: auto; background: none; border: none; cursor: pointer; font-size: 0.8rem; opacity: 0.6; color: inherit; }
    .alert-close:hover { opacity: 1; }

    /* Empty state */
    .empty-state { padding: 60px 24px; text-align: center; color: #9ca3af; }
    .empty-state i { font-size: 2.5rem; margin-bottom: 12px; display: block; color: #d1d5db; }

    @keyframes fadeUp { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
    .fade-up { animation: fadeUp 0.35s ease both; }

    @media (max-width: 1024px) {
      .sidebar { transform: translateX(-100%); width: 260px !important; }
      .sidebar.mobile-open { transform: translateX(0); }
      .main-content { width: 100% !important; margin-left: 0 !important; }
      .topbar-title-block { margin-left: 46px !important; }
    }
    @media (max-width: 640px) {
      .topbar { padding: 10px 14px; }
      .page-pad { padding: 14px !important; }
      .modal-overlay { padding: 0; align-items: flex-end; }
      .modal { border-radius: 20px 20px 0 0; max-width: 100%; max-height: 95vh; }
      .modal-body { max-height: calc(95vh - 150px); padding: 14px; }
      .col-hide-sm { display: none; }
      .grid-2col { grid-template-columns: 1fr !important; }
    }
  </style>
</head>
<body>

<div id="pageLoader" class="fixed inset-0 bg-green-900/40 backdrop-blur-sm z-[9999] flex flex-col items-center justify-center opacity-0 pointer-events-none transition-opacity duration-300">
  <div class="w-12 h-12 border-4 border-white/20 border-t-green-400 rounded-full animate-spin shadow-lg"></div>
  <p class="text-white font-medium mt-4 tracking-wider text-sm">Loading...</p>
</div>

  <link rel="stylesheet" href="../assets/responsive-global.css">
<div class="sidebar-backdrop" id="sidebarBackdrop" onclick="closeMobileSidebar()"></div>
<button class="expand-btn" id="expandBtn"><i class="fa-solid fa-bars"></i></button>

<div class="main-wrapper">

  <aside class="sidebar" id="sidebar">
    <div class="sidebar-inner">
      <div class="sidebar-logo">
        <button type="button" data-nav="adminLanding.php" style="text-decoration:none;color:inherit;border:none;background:none;padding:0;text-align:left;cursor:pointer;">
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
        <button type="button" class="menu-item active"><div class="menu-left"><i class="fa-solid fa-hammer mi"></i>Borrowing System</div><span class="active-dot"></span></button>
        <?php endif; ?>
      </nav>
      <div class="section-label">Community</div>
      <nav class="space-y-0.5 px-2">
        <?php if ($role === 'admin' || in_array('manage_listings', $myPerms)): ?>
        <button class="menu-item" data-nav="communityListings.php"><div class="menu-left"><i class="fa-solid fa-building mi"></i>Community Listings</div></button>
        <?php endif; ?>
        <?php if ($role === 'admin' || in_array('manage_announcements', $myPerms)): ?>
        <button class="menu-item" data-nav="announcement.php"><div class="menu-left"><i class="fa-solid fa-pen-to-square mi"></i>Announcements</div></button>
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
    <header class="topbar">
      <div class="topbar-title-block">
        <h2 class="font-bold text-2xl leading-tight" style="font-family:'Playfair Display',serif;color: var(--site-primary-darker);">Borrowing System</h2>
        <p class="text-gray-500 text-sm mt-0.5">Manage borrowed items and equipment from the barangay.</p>
      </div>
    </header>

    <div id="realtimeLoader" style="display:none;min-height:360px;" class="w-full flex-col items-center justify-center py-24 text-center text-emerald-700">
      <div class="w-12 h-12 border-4 border-emerald-300 border-t-emerald-700 rounded-full animate-spin mb-4"></div>
      <p class="text-sm font-semibold tracking-wide uppercase">Loading borrowing data...</p>
    </div>

    <div id="mainDataContainer" class="p-6 page-pad flex-1 space-y-5 fade-up">

      <!-- Stat cards -->
      <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        <div class="stat-card">
          <p class="stat-label">Borrow Requests This Month</p>
          <div class="stat-row"><i class="fa-solid fa-hammer stat-ico" style="color: var(--site-primary);"></i><span class="stat-num"><?= number_format($borrowThisMonth) ?></span></div>
        </div>
        <div class="stat-card">
          <p class="stat-label">Average Borrow Duration</p>
          <?php if ($avgBorrowHours !== null): ?>
            <div class="stat-row"><i class="fa-solid fa-hourglass-half stat-ico text-amber-500"></i><span class="stat-num"><?= $avgBorrowHours < 48 ? number_format($avgBorrowHours, 1) . 'h' : number_format($avgBorrowHours / 24, 1) . 'd' ?></span></div>
            <span class="stat-sub">Requested â†’ Returned</span>
          <?php else: ?>
            <div class="stat-row"><i class="fa-solid fa-hourglass-half stat-ico text-gray-300"></i><span class="stat-num text-gray-300">N/A</span></div>
            <span class="stat-sub">No returned items yet</span>
          <?php endif; ?>
        </div>
        <div class="stat-card">
          <p class="stat-label">Return Rate</p>
          <?php if ($onTimeRate !== null): ?>
            <div class="stat-row"><i class="fa-solid fa-clock-rotate-left stat-ico text-blue-500"></i><span class="stat-num"><?= $onTimeRate ?>%</span></div>
            <span class="stat-sub">Returned on time</span>
          <?php else: ?>
            <div class="stat-row"><i class="fa-solid fa-clock-rotate-left stat-ico text-gray-300"></i><span class="stat-num text-gray-300">N/A</span></div>
            <span class="stat-sub">Needs due-date tracking</span>
          <?php endif; ?>
        </div>
        <div class="stat-card">
          <p class="stat-label">Repeat Borrowers</p>
          <div class="stat-row"><i class="fa-solid fa-user-group stat-ico text-purple-500"></i><span class="stat-num"><?= number_format($repeatBorrowers) ?></span></div>
          <span class="stat-sub">Borrowed 3+ times</span>
        </div>
      </div>

      <!-- Top row -->
      <div class="flex items-center justify-between gap-4 flex-wrap">
        <div class="flex items-baseline gap-2">
          <h3 class="font-bold text-gray-900 text-lg" id="sectionTitle">Borrow Requests</h3>
          <span class="text-green-600 font-bold text-lg" id="itemCount" style="color:var(--site-primary-dark)"><?= $totalBorrow ?></span>
        </div>
        <div class="flex items-center gap-3 flex-wrap">
          <div class="search-box" style="width:200px;">
            <i class="fa-solid fa-magnifying-glass text-gray-400 text-xs flex-shrink-0"></i>
            <input type="text" id="searchInput" placeholder="Search..." oninput="handleSearch()">
          </div>
          <button class="btn-filter" id="filterToggleBtn" onclick="toggleFilter()">
            <i class="fa-solid fa-filter text-xs"></i> Filter <i class="fa-solid fa-chevron-down text-xs"></i>
          </button>
          <button class="btn-refresh" onclick="triggerRefresh()" title="Refresh"><i class="fa-solid fa-rotate-right text-xs"></i></button>
          <button class="btn-primary hidden" id="addEquipmentBtn" onclick="openAddModal()">
            <i class="fa-solid fa-plus text-xs"></i> Add New Equipment
          </button>
        </div>
      </div>
      <div id="statusPillRow" class="flex items-center gap-2 flex-wrap mt-2"></div>

      <!-- BULK ACTION BAR -->
      <div id="bulkBar" class="hidden items-center gap-3 flex-wrap p-3 border border-gray-200 bg-white" style="border-radius:12px;margin-bottom:0;">
        <span id="bulkCount" class="text-sm font-bold text-gray-700"></span>
        <div class="flex items-center gap-2 flex-wrap ml-auto">
          <button id="bulkApproveBtn" class="btn-approve" style="padding:7px 16px;font-size:0.82rem;" onclick="executeBulkAction('approve')">
            <i class="fa-solid fa-check text-[10px]"></i> Approve Selected
          </button>
          <button id="bulkRejectBtn" class="btn-reject" style="padding:7px 16px;font-size:0.82rem;" onclick="executeBulkAction('reject')">
            <i class="fa-solid fa-xmark text-[10px]"></i> Reject Selected
          </button>
          <button class="btn-filter" style="font-size:0.82rem;padding:6px 14px;" onclick="clearBulkSelection()">
            <i class="fa-solid fa-xmark text-xs"></i> Deselect All
          </button>
        </div>
      </div>

      <!-- BORROW ALERT -->
      <div id="alertBanner" style="margin: 12px 0 0;">
        <div class="alert-inner" id="alertInner">
          <i id="alertIcon" class="fa-solid fa-circle-check" style="font-size:1rem;flex-shrink:0;"></i>
          <div><span id="alertTitle" style="font-weight:700;"></span><span id="alertDesc" style="font-weight:400;margin-left:6px;opacity:0.85;"></span></div>
          <button class="alert-close" onclick="dismissAlert()"><i class="fa-solid fa-xmark"></i></button>
        </div>
      </div>

      <!-- Filter panel -->
      <div id="filterPanel" class="hidden bg-white border border-gray-200 p-4 shadow-sm flex flex-wrap gap-4" style="border-radius:12px;">
        <div style="min-width:140px;flex:1;">
          <p class="field-label mb-1">Status</p>
          <select id="filterStatus" class="field-input" onchange="handleSearch()">
            <option value="">All Status</option>
            <option value="pending">Pending</option>
            <option value="borrowed">Borrowed</option>
            <option value="returned">Returned</option>
            <option value="rejected">Rejected</option>
            <option value="available">Available</option>
            <option value="unavailable">Unavailable</option>
          </select>
        </div>
        <div style="min-width:140px;flex:1;">
          <p class="field-label mb-1">Return Date From</p>
          <input type="date" id="filterDateFrom" class="field-input" onchange="handleSearch()">
        </div>
        <div style="min-width:140px;flex:1;">
          <p class="field-label mb-1">Return Date To</p>
          <input type="date" id="filterDateTo" class="field-input" onchange="handleSearch()">
        </div>
      </div>

      <!-- Table + tabs -->
      <div class="tbl-wrap" style="position:relative;">
        <div id="tableLoader" class="table-loader">
          <i class="fa-solid fa-circle-notch fa-spin"></i>
          <span>Loading...</span>
        </div>
        <div class="flex border-b border-gray-100">
          <button class="tab-btn active" id="tabBorrow" onclick="switchTab('borrow')">Borrow Request</button>
          <button class="tab-btn" id="tabManage" onclick="switchTab('manage')">Manage Equipment</button>
        </div>

        <!-- BORROW REQUESTS -->
        <div id="panelBorrow">
          <table id="borrowTable">
            <thead><tr>
              <th style="width:36px;"><input type="checkbox" class="rounded" id="checkAllBorrow" onchange="toggleAllBorrow(this)"></th>
              <th>Item</th><th>Borrower</th><th>Status</th>
              <th class="col-hide-sm">Return Date</th>
              <th style="text-align:right;">Action</th>
            </tr></thead>
            <tbody>
              <?php if (empty($borrowList)): ?>
              <tr><td colspan="6"><div class="empty-state"><i class="fa-regular fa-folder-open"></i><p class="font-semibold text-gray-500 text-sm">No borrow requests yet</p></div></td></tr>
              <?php else: foreach ($borrowList as $b):
                $status    = strtolower(trim($b['status'] ?? 'pending'));
                $chipCls   = match($status){
                  'borrowed' => 'chip-borrowed',
                  'returned' => 'chip-returned',
                  'rejected' => 'chip-rejected',
                  'pending'  => 'chip-pending',
                  default    => 'chip-pending'
                };
                $chipLabel = ucfirst($status);

                $retDateRaw = $b['return_date'] ?? '';
                $retDateDisplay = 'â€”';
                $retDateClass   = 'return-date-neutral';
                $retDateIcon    = '';
                if (!empty($retDateRaw)) {
                    $retTs = strtotime($retDateRaw);
                    if ($retTs) {
                        $retDateDisplay = date('M j, Y', $retTs);
                        if ($status === 'borrowed' || $status === 'pending') {
                            $retDateOnly = date('Y-m-d', $retTs);
                            if ($retDateOnly >= $today) {
                                $retDateClass = 'return-date-ontime';
                                $retDateIcon  = '<i class="fa-solid fa-circle-check text-[10px]"></i>';
                            } else {
                                $retDateClass = 'return-date-overdue';
                                $retDateIcon  = '<i class="fa-solid fa-triangle-exclamation text-[10px]"></i>';
                            }
                        }
                    }
                }

                $isPending  = $status === 'pending';
                $isBorrowed = $status === 'borrowed';
                $isReturned = $status === 'returned';

                $retDateValue = !empty($retDateRaw) ? date('Y-m-d', strtotime($retDateRaw)) : '';
              ?>
              <tr
                data-id="<?= (int)$b['requestID'] ?>"
                data-status="<?= htmlspecialchars($status) ?>"
                data-name="<?= htmlspecialchars(strtolower($b['equipment_name'].' '.$b['borrower_name'].' '.$status)) ?>"
                data-return-date="<?= htmlspecialchars($retDateValue) ?>"
                data-submitted="<?= htmlspecialchars($b['submitted_at'] ?? '') ?>"
                data-qty="<?= (int)($b['qty_requested'] ?? 0) ?>"
                data-equip-id="<?= (int)$b['equipment_id'] ?>"
                class="borrow-row<?= $isReturned ? ' opacity-60' : '' ?>"
              >
                <td><input type="checkbox" class="row-check-borrow rounded" onchange="updateBulkBar()"></td>
                <td class="font-semibold"><?= htmlspecialchars($b['equipment_name']) ?> <span class="text-gray-400 font-normal">(<?= (int)($b['qty_requested']??0) ?> pcs)</span></td>
                <td><?= htmlspecialchars($b['borrower_name'] ?? 'â€”') ?></td>
                <td><span class="chip <?= $chipCls ?>"><?= $chipLabel ?></span></td>
                <td class="col-hide-sm">
                  <?php if ($retDateDisplay !== 'â€”'): ?>
                    <span class="<?= $retDateClass ?>"><?= $retDateIcon ?><?= htmlspecialchars($retDateDisplay) ?></span>
                  <?php else: ?>
                    <span class="return-date-neutral">â€”</span>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="flex items-center justify-end gap-2">
                    <?php if ($isPending): ?>
                      <button class="btn-approve" onclick="confirmBorrowAction(<?= (int)$b['requestID'] ?>,'approve',this.closest('tr'))"><i class="fa-solid fa-check text-[10px]"></i> Approve</button>
                      <button class="btn-reject"  onclick="confirmBorrowAction(<?= (int)$b['requestID'] ?>,'reject',this.closest('tr'))"><i class="fa-solid fa-xmark text-[10px]"></i> Reject</button>
                    <?php elseif ($isBorrowed): ?>
                      <button class="btn-return" onclick="confirmBorrowAction(<?= (int)$b['requestID'] ?>,'return',this.closest('tr'))"><i class="fa-solid fa-rotate-left text-[10px]"></i> Mark Returned</button>
                    <?php else: ?>
                      <span class="text-xs text-gray-400 italic">â€”</span>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

        <!-- MANAGE EQUIPMENT -->
        <div id="panelManage" class="hidden">
          <table id="equipmentTable">
            <thead><tr>
              <th style="width:36px;"><input type="checkbox" class="rounded" id="checkAllManage" onchange="toggleAllManage(this)"></th>
              <th>Item</th><th>Status</th><th style="text-align:right;">Action</th>
            </tr></thead>
            <tbody>
              <?php if (empty($equipmentList)): ?>
              <tr><td colspan="4"><div class="empty-state"><i class="fa-regular fa-folder-open"></i><p class="font-semibold text-gray-500 text-sm">No equipment yet</p><p class="text-xs text-gray-400 mt-1">Click "+ Add New Equipment" to get started.</p></div></td></tr>
              <?php else: foreach ($equipmentList as $e):
                $qty    = (int)($e['quantity_in_storage'] ?? 0);
                $isAvail = $qty > 0;
              ?>
              <tr data-id="<?= (int)$e['equipmentID'] ?>"
                  data-status="<?= $isAvail ? 'available' : 'unavailable' ?>"
                  data-name="<?= htmlspecialchars(strtolower($e['equipment_name'].' '.($isAvail ? 'available':'unavailable'))) ?>"
                  data-stock="<?= $qty ?>"
                  data-equip="<?= htmlspecialchars(json_encode($e, JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS), ENT_QUOTES) ?>"
                  class="equip-row">
                <td><input type="checkbox" class="row-check-manage rounded"></td>
                <td>
                  <div class="flex items-center gap-3">
                    <?php if (!empty($e['image_path'])): ?>
                    <img src="../uploads/equipment/<?= htmlspecialchars($e['image_path']) ?>"
                         class="w-10 h-10 rounded-lg object-cover border border-gray-200 cursor-pointer flex-shrink-0 hover:opacity-80 transition-opacity"
                         onclick="openLightbox('../uploads/equipment/<?= htmlspecialchars($e['image_path']) ?>','<?= htmlspecialchars(addslashes($e['equipment_name'])) ?>')"
                         title="Click to zoom">
                    <?php else: ?>
                    <div class="w-10 h-10 rounded-lg bg-gray-100 border border-gray-200 flex items-center justify-center flex-shrink-0">
                      <i class="fa-solid fa-box text-gray-300 text-sm"></i>
                    </div>
                    <?php endif; ?>
                    <div>
                      <p class="font-semibold text-gray-900 text-sm"><?= htmlspecialchars($e['equipment_name']) ?></p>
                      <p class="text-xs text-gray-400"><?= $qty ?> pcs in storage</p>
                    </div>
                  </div>
                </td>
                <td><span class="chip <?= $isAvail ? 'chip-available' : 'chip-unavailable' ?>"><?= $isAvail ? 'Available' : 'Unavailable' ?></span></td>
                <td>
                  <div class="flex items-center justify-end gap-2">
                    <button class="icon-btn icon-btn-edit" onclick="openEditModal(this.closest('tr'))" title="Edit"><i class="fa-solid fa-pen-to-square text-xs"></i></button>
                    <button class="btn-icon danger" onclick="confirmDeleteEquipment(this.closest('tr'))" title="Delete"><i class="fa-solid fa-trash text-xs"></i></button>
                  </div>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Pagination -->
      <div class="flex items-center justify-center gap-2 pt-2 flex-wrap" id="paginationContainer"></div>

    </div>
  </main>
</div>

<!-- â•â• EQUIPMENT MODAL â•â• -->
<div class="modal-overlay" id="equipModalOverlay" onclick="closeEquipModalOnOverlay(event)">
  <div class="modal" id="equipModal">
    <div class="modal-header">
      <div class="flex items-center gap-3 min-w-0">
        <div style="width:36px;height:36px;background:#dcfce7;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <i class="fa-solid fa-hammer text-green-700 text-sm" id="equipModalIcon"></i>
        </div>
        <div class="min-w-0">
          <p class="font-bold text-gray-900 text-base" id="equipModalTitle">Add New Equipment</p>
          <p class="text-gray-400 text-xs mt-0.5 truncate" id="equipModalSubtitle">Fill in the details below</p>
        </div>
      </div>
      <div class="flex items-center gap-2 flex-shrink-0">
        <span class="changes-badge" id="changesBadge" style="display:none;">
          <i class="fa-solid fa-circle-dot text-xs"></i>
          <span id="changesCount">0</span> change(s)
        </span>
        <button class="modal-close" onclick="closeEquipModal()"><i class="fa-solid fa-xmark"></i></button>
      </div>
    </div>

    <div class="modal-body">
      <input type="hidden" id="equipID">

      <div class="section-card">
        <div class="section-title">
          <div class="section-icon"><i class="fa-solid fa-image text-green-700 text-sm"></i></div>
          Equipment Image
          <span style="font-size:0.67rem;color:#9ca3af;font-weight:400;text-transform:none;letter-spacing:0;margin-left:4px;">(click to zoom Â· right-click image to copy)</span>
        </div>
        <div style="display:flex;gap:18px;align-items:flex-start;flex-wrap:wrap;">
          <div class="img-zone" id="imgZone" style="width:170px;height:150px;flex-shrink:0;" onclick="imgZoneClick()">
            <img id="equipImgPreview" src="" alt="Equipment image" style="display:none;" title="Right-click to copy image">
            <div id="imgZonePlaceholder" class="img-zone-placeholder">
              <i class="fa-solid fa-image"></i>
              <span>No image</span>
              <span style="font-size:0.68rem;color:#d1d5db;">Click to upload</span>
            </div>
            <div class="img-zone-overlay" id="imgZoneOverlay">
              <i class="fa-solid fa-magnifying-glass-plus text-white" style="font-size:1.5rem;"></i>
              <span style="color:#fff;font-size:0.72rem;font-weight:600;">Zoom / Change</span>
            </div>
          </div>
          <div class="flex flex-col gap-2 pt-1">
            <input type="file" id="fileInput" accept="image/*" class="hidden" onchange="handleFileSelect(this.files)">
            <button type="button" class="btn-primary" style="font-size:0.79rem;padding:7px 14px;" onclick="document.getElementById('fileInput').click()">
              <i class="fa-solid fa-cloud-arrow-up text-xs"></i> Upload Image
            </button>
            <button type="button" id="removeImgBtn" class="btn-filter" style="font-size:0.79rem;padding:6px 14px;display:none;" onclick="removeImage()">
              <i class="fa-solid fa-trash text-xs"></i> Remove
            </button>
            <p class="text-xs text-gray-400">JPG, PNG, WEBP Â· max 5 MB</p>
            <p class="text-xs text-gray-500 font-semibold" id="currentImgName" style="display:none;word-break:break-all;max-width:160px;"></p>
          </div>
        </div>
      </div>

      <div class="section-card">
        <div class="section-title">
          <div class="section-icon"><i class="fa-solid fa-box text-green-700 text-sm"></i></div>
          Equipment Details
        </div>
        <div class="space-y-4">
          <div>
            <label class="field-label">Item Name <span class="required-star">*</span></label>
            <input type="text" id="equipName" class="field-input" placeholder="e.g. Lawn Mower, Tent, Ladder" maxlength="200" oninput="checkEquipChanges()">
          </div>
          <div class="grid grid-cols-2 gap-4 grid-2col">
            <div>
              <label class="field-label">Quantity in Storage <span class="required-star">*</span></label>
              <input type="number" id="equipQty" class="field-input" min="0" max="9999" step="1" oninput="checkEquipChanges()">
            </div>
            <div>
              <label class="field-label">Status</label>
              <input type="text" id="equipStatusDisplay" class="field-input" readonly placeholder="Auto-calculated">
            </div>
          </div>
          <div>
            <label class="field-label">Description <span style="font-size:0.7rem;color:#9ca3af;font-weight:400;text-transform:none;">Â· optional</span></label>
            <textarea id="equipDesc" class="field-input" rows="3" placeholder="Condition, notes, usage instructionsâ€¦" oninput="checkEquipChanges()"></textarea>
          </div>
        </div>
      </div>

      <div class="section-card" id="equipMetaCard" style="display:none;">
        <div class="section-title">
          <div class="section-icon"><i class="fa-solid fa-circle-info text-green-700 text-sm"></i></div>
          Record Info
          <span style="font-size:0.68rem;color:#9ca3af;font-weight:400;text-transform:none;letter-spacing:0;margin-left:4px;">(read only)</span>
        </div>
        <div class="grid grid-cols-2 gap-4 grid-2col">
          <div>
            <label class="field-label">Equipment ID</label>
            <input type="text" id="equipIDDisplay" class="field-input" readonly>
          </div>
          <div>
            <label class="field-label">Date Added</label>
            <input type="text" id="equipCreatedAt" class="field-input" readonly>
          </div>
        </div>
      </div>
    </div>

    <div class="modal-footer">
      <button class="mf-btn mf-cancel" onclick="closeEquipModal()">Cancel <i class="fa-solid fa-xmark text-sm"></i></button>
      <button class="mf-btn mf-update" id="equipSaveBtn" disabled onclick="saveEquipment()">
        <span id="equipSaveLabel">Save Equipment</span> <i class="fa-solid fa-floppy-disk text-sm"></i>
      </button>
    </div>
  </div>
</div>

<!-- â•â• LIGHTBOX â•â• -->
<div class="lightbox" id="lightbox" onclick="closeLightbox()">
  <button class="lightbox-close" onclick="closeLightbox()"><i class="fa-solid fa-xmark"></i></button>
  <img id="lightboxImg" src="" alt="">
  <span class="lightbox-caption" id="lightboxCaption"></span>
</div>

<!-- â•â• CONFIRM DIALOG â•â• -->
<div class="dialog-overlay" id="dialogOverlay">
  <div class="dialog-box">
    <div class="dialog-body">
      <div class="dialog-icon-wrap" id="dialogIconWrap"><i id="dialogIcon" class="fa-solid fa-check"></i></div>
      <p class="dialog-title" id="dialogTitle">Confirm</p>
      <p class="dialog-desc"  id="dialogDesc">Are you sure?</p>
      <span class="dialog-name-badge" id="dialogBadge" style="display:none;"></span>
    </div>
    <div class="dialog-footer">
      <button class="dialog-btn dialog-btn-cancel" onclick="closeDialog()"><i class="fa-solid fa-xmark"></i> Cancel</button>
      <button class="dialog-btn" id="dialogConfirmBtn">
        <i id="dialogConfirmIcon" class="fa-solid fa-check"></i>
        <span id="dialogConfirmLabel">Confirm</span>
      </button>
    </div>
  </div>
</div>

<script>
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
document.querySelectorAll('[data-nav]').forEach(btn=>btn.addEventListener('click',function(){const t=this.getAttribute('data-nav');if(t){showPageLoader('Loading page...');setTimeout(()=>window.location.href=t,180);}}));

function showPageLoader(message='Loading...'){
  const main=document.getElementById('mainDataContainer');
  const loader=document.getElementById('realtimeLoader');
  if(main) main.style.display='none';
  if(loader){
    const txt=loader.querySelector('p');
    if(txt)txt.textContent=message;
    loader.style.display='flex';
  }
}
function hidePageLoader(){
  const main=document.getElementById('mainDataContainer');
  const loader=document.getElementById('realtimeLoader');
  if(loader) loader.style.display='none';
  if(main) main.style.display='';
}
function triggerRefresh(){showPageLoader('Refreshing borrowing data...');setTimeout(()=>location.reload(),180);}

/* â•â• TABS â•â• */
let currentTab='borrow';
function switchTab(tab){
  currentTab=tab;
  document.getElementById('tabBorrow').classList.toggle('active',tab==='borrow');
  document.getElementById('tabManage').classList.toggle('active',tab==='manage');
  document.getElementById('panelBorrow').classList.toggle('hidden',tab!=='borrow');
  document.getElementById('panelManage').classList.toggle('hidden',tab!=='manage');
  document.getElementById('addEquipmentBtn').classList.toggle('hidden',tab!=='manage');
  document.getElementById('sectionTitle').textContent=tab==='borrow'?'Borrow Requests':'All Items';
  document.getElementById('itemCount').textContent=tab==='borrow'?'<?= $totalBorrow ?>':'<?= $totalEquipment ?>';
  const filterBtn=document.getElementById('filterToggleBtn');
  if(filterBtn) filterBtn.style.display=tab==='borrow'?'':'none';
  /* Hide bulk bar on tab switch */
  clearBulkSelection();
  document.getElementById('filterStatus').value='';
  currentFilteredRows=[];
  updateStatusPills();
  handleSearch();
  currentPage=1;
  renderPagination();
}

function updateStatusPills(){
  const container=document.getElementById('statusPillRow');
  container.innerHTML='';
  const statuses=currentTab==='borrow'
    ?['All','Pending','Borrowed','Returned','Rejected']
    :['All','Available','Unavailable'];
  const currentStatus=(document.getElementById('filterStatus')?.value||'').toLowerCase().trim();
  statuses.forEach(s=>{
    const pill=document.createElement('button');
    pill.type='button';
    pill.className='status-pill'+((currentStatus===s.toLowerCase()||(currentStatus===''&&s==='All'))?' active-pill':'');
    pill.textContent=s;
    pill.dataset.status=s.toLowerCase();
    pill.onclick=()=>{
      document.getElementById('filterStatus').value=(s==='All'?'':s.toLowerCase());
      updateStatusPills();
      handleSearch();
    };
    container.appendChild(pill);
  });
}

/* â•â• SEARCH / FILTER â•â• */
function toggleFilter(){document.getElementById('filterPanel').classList.toggle('hidden');}
let searchTimeout;
let currentFilteredRows=[];

function handleSearch(){
  clearTimeout(searchTimeout);
  currentPage=1;

  searchTimeout=setTimeout(()=>{
    setTimeout(()=>{
      const q=(document.getElementById('searchInput').value||'').toLowerCase().trim();
      const status=(document.getElementById('filterStatus')?.value||'').toLowerCase().trim();
      const dateFrom=document.getElementById('filterDateFrom')?.value||'';
      const dateTo=document.getElementById('filterDateTo')?.value||'';

      /* Always re-query fresh from DOM so status changes from actions are reflected */
      let matchCount=0;
      const allRows=Array.from(document.querySelectorAll(
        currentTab==='borrow'
          ?'#borrowTable tbody tr[data-id]'
          :'#equipmentTable tbody tr[data-id]'
      ));

      allRows.forEach(r=>{
        const rowStatus=(r.dataset.status||'').toLowerCase();
        const rowName=(r.dataset.name||'').toLowerCase();
        const rowReturnDate=(r.dataset.returnDate||'').trim();

        const matchSearch=!q||rowName.includes(q);
      const matchStatus=!status||rowStatus===status;

      let matchDate=true;
      if(rowReturnDate){
        if(dateFrom&&rowReturnDate<dateFrom) matchDate=false;
        if(dateTo&&rowReturnDate>dateTo) matchDate=false;
      } else if(dateFrom||dateTo){
        // only match if date was specified and no returnDate is found
        matchDate=false;
      }

      const ok = matchSearch&&matchStatus&&matchDate;
      if (ok) {
        r.dataset.filteredout = "false";
        matchCount++;
      } else {
        r.dataset.filteredout = "true";
        r.style.display = 'none';
      }
    });

    renderPagination();
    }, 10);
  }, 400);
}

/* â•â• STOCK MAP â•â• */
const stockMap={};
document.querySelectorAll('#equipmentTable tbody tr[data-id]').forEach(r=>{
  const id=parseInt(r.dataset.id);
  const stock=parseInt(r.dataset.stock??0);
  if(!isNaN(id)) stockMap[id]=stock;
});

/**
 * Re-evaluate ALL pending rows for a given equipmentId.
 * Sorted oldest-first (highest priority). Approve buttons are
 * enabled greedily; disabled when remaining stock is insufficient.
 */
function reEvaluateStockForEquip(equipId){
  const available=stockMap[equipId]??0;

  /* Check equipment status from equipment table */
  const equipRow=document.querySelector(`#equipmentTable tbody tr[data-id="${equipId}"]`);
  let isEquipAvailable=false;
  if(equipRow){
    const equipStatus=equipRow.dataset.status||'unavailable';
    isEquipAvailable=equipStatus==='available';
  } else {
    /* Fallback: assume available if stock > 0 */
    isEquipAvailable=available>0;
  }

  const pendingRows=Array.from(
    document.querySelectorAll(`#borrowTable tbody tr[data-id][data-equip-id="${equipId}"]`)
  ).filter(r=>r.dataset.status==='pending')
   .sort((a,b)=>new Date(a.dataset.submitted)-new Date(b.dataset.submitted));

  let remaining=available;
  pendingRows.forEach((r, index)=>{
    const qty=parseInt(r.dataset.qty??1);
    const approveBtn=r.querySelector('.btn-approve');
    if(!approveBtn) return;

    /* Always enable approve button for the first (oldest) request */
    const isFirstRequest = index === 0;
    const hasEnoughStock = remaining >= qty;

    /* Disable only if equipment is unavailable AND not the first request */
    if(!isEquipAvailable && !isFirstRequest){
      approveBtn.disabled=true;
      approveBtn.title='Equipment is unavailable';
      approveBtn.style.opacity='0.4';
      approveBtn.style.cursor='not-allowed';
      approveBtn.style.pointerEvents='none';
    } else if(!hasEnoughStock && !isFirstRequest){
      approveBtn.disabled=true;
      approveBtn.title=`Not enough stock (${remaining} left, needs ${qty})`;
      approveBtn.style.opacity='0.4';
      approveBtn.style.cursor='not-allowed';
      approveBtn.style.pointerEvents='none';
    } else {
      /* Enable for first request or when there's enough stock */
      if(isFirstRequest || hasEnoughStock) remaining -= qty;
      approveBtn.disabled=false;
      approveBtn.title='Approve this request';
      approveBtn.style.opacity='';
      approveBtn.style.cursor='';
      approveBtn.style.pointerEvents='';
    }
  });
}

/* Run initial stock evaluation for all equipment that appear in pending requests */
(function initStockEval(){
  const equipIds=new Set(
    Array.from(document.querySelectorAll('#borrowTable tbody tr[data-id][data-equip-id]'))
      .map(r=>parseInt(r.dataset.equipId))
      .filter(n=>!isNaN(n))
  );
  equipIds.forEach(id=>reEvaluateStockForEquip(id));
})();

/* Refresh stock map from equipment table and re-evaluate all approve buttons */
function refreshStockAndReEvaluate(){
  /* Update stockMap from current equipment table data */
  document.querySelectorAll('#equipmentTable tbody tr[data-id]').forEach(r=>{
    const id=parseInt(r.dataset.id);
    const stock=parseInt(r.dataset.stock??0);
    if(!isNaN(id)) stockMap[id]=stock;
  });

  /* Re-evaluate approve buttons for all equipment that have pending requests */
  const equipIds=new Set(
    Array.from(document.querySelectorAll('#borrowTable tbody tr[data-id][data-equip-id][data-status="pending"]'))
      .map(r=>parseInt(r.dataset.equipId))
      .filter(n=>!isNaN(n))
  );
  equipIds.forEach(id=>reEvaluateStockForEquip(id));
}

/* Update equipment row status and chip based on stock */
function updateEquipRowStatus(equipId){
  const equipRow=document.querySelector(`#equipmentTable tbody tr[data-id="${equipId}"]`);
  if(!equipRow) return;

  const stock=parseInt(equipRow.dataset.stock??0);
  const isAvail=stock>0;
  const newStatus=isAvail?'available':'unavailable';

  /* Update data-status */
  equipRow.dataset.status=newStatus;
  equipRow.dataset.name=equipRow.dataset.name.replace(/(available|unavailable)$/i, newStatus);

  /* Update chip */
  const chip=equipRow.querySelector('.chip');
  if(chip){
    chip.className='chip';
    chip.classList.add(isAvail?'chip-available':'chip-unavailable');
    chip.textContent=isAvail?'Available':'Unavailable';
  }

  /* Update quantity display */
  const qtyText=equipRow.querySelector('td:nth-child(2) p.text-xs');
  if(qtyText) qtyText.textContent=`${stock} pcs in storage`;
}

/* â•â• CHECKBOXES â•â• */
function toggleAllBorrow(cb){
  const visible=Array.from(document.querySelectorAll('.row-check-borrow'))
    .filter(c=>c.closest('tr').style.display!=='none');
  visible.forEach(c=>c.checked=cb.checked);
  updateBulkBar();
}
function toggleAllManage(cb){document.querySelectorAll('.row-check-manage').forEach(c=>c.checked=cb.checked);}

function getCheckedBorrowRows(){
  return Array.from(document.querySelectorAll('#borrowTable tbody tr[data-id]'))
    .filter(r=>r.querySelector('.row-check-borrow')?.checked);
}

function clearBulkSelection(){
  document.querySelectorAll('.row-check-borrow').forEach(c=>c.checked=false);
  const ca=document.getElementById('checkAllBorrow');
  if(ca) ca.checked=false;
  updateBulkBar();
}

function updateBulkBar(){
  const checked=getCheckedBorrowRows();
  const bar=document.getElementById('bulkBar');
  const countEl=document.getElementById('bulkCount');
  const approveBtn=document.getElementById('bulkApproveBtn');
  const rejectBtn=document.getElementById('bulkRejectBtn');

  if(checked.length===0){
    bar.classList.add('hidden');
    bar.classList.remove('flex');
    return;
  }

  bar.classList.remove('hidden');
  bar.classList.add('flex');

  const pendingRows  =checked.filter(r=>r.dataset.status==='pending');
  const borrowedRows =checked.filter(r=>r.dataset.status==='borrowed');
  const nonActionable=checked.filter(r=>!['pending','borrowed'].includes(r.dataset.status));

  /* â”€â”€ Stock validation for bulk approve â”€â”€
     Group pending checked rows by equipmentId, sort oldest-first,
     greedily allocate stock. Any row that can't be fulfilled disables Approve. */
  let canApproveAll=pendingRows.length>0;
  const insufficientNames=[];

  if(pendingRows.length>0){
    const byEquip={};
    pendingRows.forEach(r=>{
      const eid=parseInt(r.dataset.equipId);
      if(!byEquip[eid]) byEquip[eid]=[];
      byEquip[eid].push(r);
    });
    Object.entries(byEquip).forEach(([eid,rows])=>{
      const avail=stockMap[parseInt(eid)]??0;
      rows.sort((a,b)=>new Date(a.dataset.submitted)-new Date(b.dataset.submitted));
      let remaining=avail;
      rows.forEach(r=>{
        const qty=parseInt(r.dataset.qty??1);
        if(qty>remaining){
          canApproveAll=false;
          const name=r.querySelector('td:nth-child(2)')?.textContent?.trim()||'Item';
          if(!insufficientNames.includes(name)) insufficientNames.push(name);
        } else {
          remaining-=qty;
        }
      });
    });
  }

  /* Show/hide action buttons based on what is selected */
  if(pendingRows.length===0&&borrowedRows.length===0){
    approveBtn.style.display='none';
    rejectBtn.style.display='none';
  } else {
    approveBtn.style.display=pendingRows.length>0?'inline-flex':'none';
    rejectBtn.style.display =pendingRows.length>0?'inline-flex':'none';

    if(!canApproveAll&&pendingRows.length>0){
      approveBtn.disabled=true;
      approveBtn.title=`Insufficient stock for: ${insufficientNames.join(', ')}`;
    } else {
      approveBtn.disabled=false;
      approveBtn.title='';
    }
  }

  /* Count label */
  const parts=[];
  if(pendingRows.length)   parts.push(`${pendingRows.length} pending`);
  if(borrowedRows.length)  parts.push(`${borrowedRows.length} borrowed`);
  if(nonActionable.length) parts.push(`${nonActionable.length} non-actionable`);
  countEl.textContent=`${checked.length} selected${parts.length?' ('+parts.join(', ')+')':''}`;
}

/* â•â• PAGINATION â•â• */
const ROWS=10;
let currentPage=1;

function getAllRows(){
  const sel=currentTab==='borrow'?'#borrowTable tbody tr[data-id]':'#equipmentTable tbody tr[data-id]';
  return Array.from(document.querySelectorAll(sel));
}

function getVisibleRows() {
  return getAllRows().filter(r => r.dataset.filteredout !== 'true');
}

function renderPagination(){
  const rows = getVisibleRows();
  const total=rows.length;
  const pages=Math.max(1,Math.ceil(total/ROWS));
  if(currentPage>pages) currentPage=pages;

  getAllRows().forEach(r=>{ r.style.display='none'; });
  rows.forEach((r,i)=>{
    r.style.display=(Math.floor(i/ROWS)+1===currentPage)?'':'none';
  });

  const c=document.getElementById('paginationContainer');
  c.innerHTML='';

  const prev=document.createElement('button');
  prev.className='page-btn';
  prev.disabled=currentPage===1;
  prev.innerHTML='<i class="fa-solid fa-chevron-left text-xs"></i>';
  prev.onclick=()=>{ currentPage--; renderPagination(); };
  c.appendChild(prev);

  let s=Math.max(1,currentPage-2),e=Math.min(pages,s+4);
  if(e-s<4) s=Math.max(1,e-4);
  for(let p=s;p<=e;p++){
    const b=document.createElement('button');
    b.className='page-btn'+(p===currentPage?' active':'');
    b.textContent=p;
    b.onclick=()=>{ currentPage=p; renderPagination(); };
    c.appendChild(b);
  }

  const next=document.createElement('button');
  next.className='page-btn';
  next.disabled=currentPage===pages;
  next.innerHTML='<i class="fa-solid fa-chevron-right text-xs"></i>';
  next.onclick=()=>{ currentPage++; renderPagination(); };
  c.appendChild(next);
}

/* Init */
(function init(){
  updateStatusPills();
  handleSearch();
})();

/* â•â• ALERT â•â• */
let alertT;
function showToast(type,title,desc){
  const icons={success:'fa-circle-check',error:'fa-circle-xmark',warning:'fa-triangle-exclamation'};
  const types={success:'alert-success',error:'alert-error',warning:'alert-warning'};
  const b=document.getElementById('alertBanner'),inn=document.getElementById('alertInner');
  document.getElementById('alertIcon').className='fa-solid '+(icons[type]||'fa-circle-check');
  document.getElementById('alertTitle').textContent=title;
  document.getElementById('alertDesc').textContent=desc||'';
  inn.className='alert-inner '+(types[type]||'alert-success');
  b.classList.add('show');b.scrollIntoView({behavior:'smooth',block:'nearest'});
  clearTimeout(alertT);alertT=setTimeout(()=>dismissAlert(),4500);
}
function dismissAlert(){document.getElementById('alertBanner').classList.remove('show');}

/* â•â• CONFIRM DIALOG â•â• */
let dialogConfirmFn=null;
function showDialog({type='approve',title,desc,badge,confirmLabel,confirmClass,iconClass,onConfirm}){
  document.getElementById('dialogTitle').textContent=title||'Confirm';
  document.getElementById('dialogDesc').textContent=desc||'Are you sure?';
  const bd=document.getElementById('dialogBadge');
  if(badge){bd.textContent=badge;bd.style.display='inline-block';}else bd.style.display='none';
  const wrap=document.getElementById('dialogIconWrap');
  wrap.className='dialog-icon-wrap';
  wrap.style.background={'approve':'#dcfce7','reject':'#fee2e2','warning':'#fef9c3','delete':'#fee2e2'}[type]||'#dcfce7';
  document.getElementById('dialogIcon').className='fa-solid '+(iconClass||'fa-check');
  const cb=document.getElementById('dialogConfirmBtn');
  cb.className='dialog-btn dialog-btn-'+(confirmClass||type);
  document.getElementById('dialogConfirmLabel').textContent=confirmLabel||'Confirm';
  document.getElementById('dialogConfirmIcon').className='fa-solid '+(iconClass||'fa-check');
  dialogConfirmFn=()=>{closeDialog();onConfirm&&onConfirm();};
  cb.onclick=dialogConfirmFn;
  document.getElementById('dialogOverlay').classList.add('open');
  document.body.style.overflow='hidden';
}
function closeDialog(){document.getElementById('dialogOverlay').classList.remove('open');document.body.style.overflow='';}
document.getElementById('dialogOverlay').addEventListener('click',function(e){if(e.target===this)closeDialog();});

/* â•â• LIGHTBOX â•â• */
function openLightbox(src,caption){
  if(!src)return;
  document.getElementById('lightboxImg').src=src;
  document.getElementById('lightboxCaption').textContent=caption||'';
  document.getElementById('lightbox').classList.add('open');
  document.body.style.overflow='hidden';
}
function closeLightbox(){document.getElementById('lightbox').classList.remove('open');document.body.style.overflow='';}

/* â•â• SINGLE BORROW ACTIONS â•â• */
function confirmBorrowAction(requestID,action,row){
  const cfg={
    approve:{type:'approve',title:'Approve Borrow Request',desc:'The item will be marked as borrowed and inventory decremented.',confirmLabel:'Yes, Approve',iconClass:'fa-check',confirmClass:'approve'},
    reject: {type:'reject', title:'Reject Borrow Request', desc:"The borrower's request will be declined.",confirmLabel:'Yes, Reject',iconClass:'fa-xmark',confirmClass:'reject'},
    return: {type:'warning',title:'Mark as Returned',desc:'Item will be marked returned and stock restored.',confirmLabel:'Mark Returned',iconClass:'fa-rotate-left',confirmClass:'warning'},
  }[action];
  showDialog({...cfg,onConfirm:()=>executeBorrowAction(requestID,action,row)});
}

function executeBorrowAction(requestID,action,row){
  /* Pre-check for approve actions */
  if(action==='approve'){
    const eid=parseInt(row.dataset.equipId);
    const equipRow=document.querySelector(`#equipmentTable tbody tr[data-id="${eid}"]`);
    let isEquipAvailable=false;
    if(equipRow){
      const equipStatus=equipRow.dataset.status||'unavailable';
      isEquipAvailable=equipStatus==='available';
    } else {
      /* Fallback: assume available if stock > 0 */
      isEquipAvailable=(stockMap[eid]??0)>0;
    }

    /* Check if this is the first (oldest) pending request for this equipment */
    const pendingRows=Array.from(
      document.querySelectorAll(`#borrowTable tbody tr[data-id][data-equip-id="${eid}"][data-status="pending"]`)
    ).sort((a,b)=>new Date(a.dataset.submitted)-new Date(b.dataset.submitted));

    const isFirstRequest = pendingRows.length > 0 && pendingRows[0].dataset.id === row.dataset.id;

    if(!isEquipAvailable && !isFirstRequest){
      showToast('error','Cannot Approve','Equipment is currently unavailable.');
      return;
    }
  }

  const loader=document.getElementById('tableLoader');
  let actionSucceeded=false;

  if(loader) loader.classList.add('visible');
  fetch('borrowAction.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`requestID=${requestID}&action=${action}`})
    .then(r=>r.json())
    .then(data=>{
      if(loader) loader.classList.remove('visible');
      
      if(data.success){
        actionSucceeded=true;
        if(row){
          const chip=row.querySelector('.chip');
          if(chip){
            chip.className='chip';
            const map={approve:['chip-borrowed','Borrowed'],reject:['chip-rejected','Rejected'],return:['chip-returned','Returned']};
            chip.classList.add(map[action][0]);chip.textContent=map[action][1];
            if(action==='return') row.classList.add('opacity-60');
          }
          const ac=row.querySelector('td:last-child div');
          if(ac) ac.innerHTML='<span class="text-xs text-gray-400 italic">â€”</span>';

          const eid=parseInt(row.dataset.equipId);
          const qty=parseInt(row.dataset.qty??1);

          if(action==='approve'){
            row.dataset.status='borrowed';
            if(!isNaN(eid)) {
              stockMap[eid]=Math.max(0,(stockMap[eid]??0)-qty);
              /* Update equipment table row data-stock */
              const equipRow=document.querySelector(`#equipmentTable tbody tr[data-id="${eid}"]`);
              if(equipRow) {
                equipRow.dataset.stock=stockMap[eid];
                updateEquipRowStatus(eid);
              }
            }
          }
          if(action==='reject'){
            row.dataset.status='rejected';
          }
          if(action==='return'){
            row.dataset.status='returned';
            if(!isNaN(eid)) {
              stockMap[eid]=(stockMap[eid]??0)+qty;
              /* Update equipment table row data-stock */
              const equipRow=document.querySelector(`#equipmentTable tbody tr[data-id="${eid}"]`);
              if(equipRow) {
                equipRow.dataset.stock=stockMap[eid];
                updateEquipRowStatus(eid);
              }
            }
            const todayStr=new Date().toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
            const todayISO=new Date().toISOString().slice(0,10);
            row.dataset.returnDate=todayISO;
            const cells=row.querySelectorAll('td');
            if(cells[4]) cells[4].innerHTML=`<span class="return-date-neutral">${todayStr}</span>`;
          }

          /* Re-evaluate approve buttons for all pending rows of this equipment */
          if(!isNaN(eid)) reEvaluateStockForEquip(eid);
        }
        showToast('success','Done!',{approve:'Approved! Item is now borrowed.',reject:'Request rejected.',return:'Item marked as returned.'}[action]);
        handleSearch();
        setTimeout(()=>location.reload(), 1200);
      } else {
        showToast('error','Action Failed',data.message||'Something went wrong.');
      }
    })
    .catch(()=>{ showToast('error','Network Error','Could not connect to server.'); });
}

/* â•â• BULK ACTIONS â•â• */
function executeBulkAction(action){
  const checked=getCheckedBorrowRows();
  const targetRows=checked.filter(r=>{
    if(action==='approve'||action==='reject') return r.dataset.status==='pending';
    if(action==='return') return r.dataset.status==='borrowed';
    return false;
  });

  if(targetRows.length===0){
    showToast('warning','Nothing to do','No eligible rows for this action.');
    return;
  }

  /* Stock pre-check for bulk approve */
  if(action==='approve'){
    const byEquip={};
    targetRows.forEach(r=>{
      const eid=parseInt(r.dataset.equipId);
      if(!byEquip[eid]) byEquip[eid]=[];
      byEquip[eid].push(r);
    });
    const blocked=[];

    Object.entries(byEquip).forEach(([eid,rows])=>{
      const equipRow=document.querySelector(`#equipmentTable tbody tr[data-id="${eid}"]`);
      let isEquipAvailable=false;
      if(equipRow){
        const equipStatus=equipRow.dataset.status||'unavailable';
        isEquipAvailable=equipStatus==='available';
      } else {
        /* Fallback: assume available if stock > 0 */
        isEquipAvailable=(stockMap[parseInt(eid)]??0)>0;
      }

      if(!isEquipAvailable){
        const name=rows[0]?.querySelector('td:nth-child(2)')?.textContent?.trim()||'Item';
        blocked.push(`${name} (unavailable)`);
        return;
      }

      let remaining=stockMap[parseInt(eid)]??0;
      rows.sort((a,b)=>new Date(a.dataset.submitted)-new Date(b.dataset.submitted));
      rows.forEach((r, index)=>{
        const qty=parseInt(r.dataset.qty??1);
        const isFirstRequest = index === 0;
        if(!isFirstRequest && qty>remaining){
          const name=r.querySelector('td:nth-child(2)')?.textContent?.trim()||'Item';
          blocked.push(name);
        } else if(!isFirstRequest) {
          remaining-=qty;
        }
        /* First request is always allowed */
      });
    });
    if(blocked.length){
      showToast('error','Cannot Approve All',`Some requests cannot be approved: ${[...new Set(blocked)].join(', ')}`);
      return;
    }
  }

  const label={approve:'Approve',reject:'Reject',return:'Mark Returned'}[action];
  const iconCls={approve:'fa-check',reject:'fa-xmark',return:'fa-rotate-left'}[action];
  const type={approve:'approve',reject:'reject',return:'warning'}[action];

  showDialog({
    type,
    title:`${label} ${targetRows.length} Request${targetRows.length>1?'s':''}`,
    desc:`This will ${label.toLowerCase()} ${targetRows.length} selected request${targetRows.length>1?'s':''}.`,
    confirmLabel:`Yes, ${label}`,
    iconClass:iconCls,
    confirmClass:type,
    onConfirm:()=>_runBulkRequests(targetRows,action)
  });
}

function _runBulkRequests(rows,action){
  const loader=document.getElementById('tableLoader');
  if(loader) loader.classList.add('visible');

  const promises=rows.map(row=>{
    const requestID=row.dataset.id;
    return fetch('borrowAction.php',{
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:`requestID=${requestID}&action=${action}`
    })
    .then(r=>r.json())
    .then(data=>({row,data,action}));
  });

  const minLoadingMs=2000;
  const startTime=Date.now();

  Promise.allSettled(promises).then(results=>{
    let successCount=0,failCount=0;
    const affectedEquips=new Set();

    results.forEach(res=>{
      if(res.status==='fulfilled'&&res.value.data.success){
        const {row,action}=res.value;
        successCount++;

        const chip=row.querySelector('.chip');
        if(chip){
          chip.className='chip';
          const map={approve:['chip-borrowed','Borrowed'],reject:['chip-rejected','Rejected'],return:['chip-returned','Returned']};
          chip.classList.add(map[action][0]);chip.textContent=map[action][1];
          if(action==='return') row.classList.add('opacity-60');
        }

        const ac=row.querySelector('td:last-child div');
        if(ac) ac.innerHTML='<span class="text-xs text-gray-400 italic">â€”</span>';

        const eid=parseInt(row.dataset.equipId);
        const qty=parseInt(row.dataset.qty??1);

        if(action==='approve'){
          row.dataset.status='borrowed';
          if(!isNaN(eid)) {
            stockMap[eid]=Math.max(0,(stockMap[eid]??0)-qty);
            /* Update equipment table row data-stock */
            const equipRow=document.querySelector(`#equipmentTable tbody tr[data-id="${eid}"]`);
            if(equipRow) {
              equipRow.dataset.stock=stockMap[eid];
              updateEquipRowStatus(eid);
            }
          }
        }
        if(action==='reject'){
          row.dataset.status='rejected';
        }
        if(action==='return'){
          row.dataset.status='returned';
          if(!isNaN(eid)) {
            stockMap[eid]=(stockMap[eid]??0)+qty;
            /* Update equipment table row data-stock */
            const equipRow=document.querySelector(`#equipmentTable tbody tr[data-id="${eid}"]`);
            if(equipRow) {
              equipRow.dataset.stock=stockMap[eid];
              updateEquipRowStatus(eid);
            }
          }
          const todayStr=new Date().toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
          const todayISO=new Date().toISOString().slice(0,10);
          row.dataset.returnDate=todayISO;
          const cells=row.querySelectorAll('td');
          if(cells[4]) cells[4].innerHTML=`<span class="return-date-neutral">${todayStr}</span>`;
        }

        const cb=row.querySelector('.row-check-borrow');
        if(cb) cb.checked=false;

        if(!isNaN(eid)) affectedEquips.add(eid);
      } else {
        failCount++;
      }
    });

    /* Re-evaluate stock for all affected equipment */
    affectedEquips.forEach(eid=>reEvaluateStockForEquip(eid));

    clearBulkSelection();
    handleSearch();

    const elapsed=Date.now()-startTime;
    setTimeout(()=>{
      if(loader) loader.classList.remove('visible');
      if(successCount>0&&failCount===0)
        showToast('success','Bulk action complete',`${successCount} request${successCount>1?'s':''} ${action==='approve'?'approved':action==='reject'?'rejected':'marked returned'}.`);
      else if(successCount>0&&failCount>0)
        showToast('warning','Partially complete',`${successCount} succeeded, ${failCount} failed.`);
      else
        showToast('error','All failed','Could not process the selected requests.');
    },Math.max(0,minLoadingMs-elapsed));
  });
}

/* â•â• EQUIPMENT MODAL â•â• */
let equipOriginal={};
let equipNewFile=null;
let equipImgRemoved=false;
let equipIsAddMode=false;

function equipSnapshot(){
  return {
    name:(document.getElementById('equipName').value||'').trim(),
    qty:(document.getElementById('equipQty').value||'').trim(),
    desc:(document.getElementById('equipDesc').value||'').trim(),
    newFile:equipNewFile?equipNewFile.name+equipNewFile.size:'__none__',
    imgRemoved:equipImgRemoved,
  };
}

function checkEquipChanges(){
  const qty=parseInt(document.getElementById('equipQty').value)||0;
  document.getElementById('equipStatusDisplay').value=qty>0?'Available':'Unavailable';
  const fieldMap={equipName:'name',equipQty:'qty',equipDesc:'desc'};
  let count=0;
  Object.entries(fieldMap).forEach(([id,key])=>{
    const el=document.getElementById(id);
    if(!el)return;
    const changed=el.value.trim()!==(equipOriginal[key]??'');
    el.classList.toggle('changed',changed);
    if(changed)count++;
  });
  if(equipNewFile||equipImgRemoved)count++;
  const noChange=equipIsAddMode?false:JSON.stringify(equipSnapshot())===JSON.stringify(equipOriginal);
  if(!equipIsAddMode){
    document.getElementById('equipSaveBtn').disabled=noChange;
  } else {
    document.getElementById('equipSaveBtn').disabled=!(document.getElementById('equipName').value||'').trim();
  }
  const badge=document.getElementById('changesBadge');
  if(count>0&&!noChange&&!equipIsAddMode){
    badge.style.display='inline-flex';
    document.getElementById('changesCount').textContent=count;
  } else {
    badge.style.display='none';
  }
}

function imgZoneClick(){
  const img=document.getElementById('equipImgPreview');
  if(img.style.display!=='none'&&img.src&&!img.src.endsWith('/')){
    openLightbox(img.src,document.getElementById('equipName').value||'Equipment');
  } else {
    document.getElementById('fileInput').click();
  }
}

function setImgPreview(src,name){
  const img=document.getElementById('equipImgPreview');
  const ph=document.getElementById('imgZonePlaceholder');
  const zone=document.getElementById('imgZone');
  const nameEl=document.getElementById('currentImgName');
  const removeBtn=document.getElementById('removeImgBtn');
  if(src){
    img.src=src;img.style.display='block';
    ph.style.display='none';
    zone.classList.add('has-img');
    nameEl.textContent=name||'';nameEl.style.display=name?'block':'none';
    removeBtn.style.display='';
  } else {
    img.src='';img.style.display='none';
    ph.style.display='flex';
    zone.classList.remove('has-img');
    nameEl.style.display='none';
    removeBtn.style.display='none';
  }
}

function handleFileSelect(files){
  if(!files||!files[0])return;
  const file=files[0];
  if(file.size>5*1024*1024){showToast('error','File Too Large','Max image size is 5 MB.');return;}
  equipNewFile=file;
  equipImgRemoved=false;
  const reader=new FileReader();
  reader.onload=e=>{setImgPreview(e.target.result,file.name);checkEquipChanges();};
  reader.readAsDataURL(file);
}

function removeImage(){
  equipNewFile=null;equipImgRemoved=true;
  setImgPreview(null,null);
  document.getElementById('fileInput').value='';
  checkEquipChanges();
}

function resetEquipModal(){
  equipNewFile=null;equipImgRemoved=false;equipIsAddMode=false;
  document.getElementById('fileInput').value='';
  setImgPreview(null,null);
  ['equipName','equipQty','equipDesc'].forEach(id=>{
    const el=document.getElementById(id);
    if(el){el.value='';el.classList.remove('changed');}
  });
  document.getElementById('equipDesc').value='';
  document.getElementById('equipStatusDisplay').value='';
  document.getElementById('equipIDDisplay').value='';
  document.getElementById('equipCreatedAt').value='';
  document.getElementById('changesBadge').style.display='none';
  document.getElementById('equipSaveBtn').disabled=true;
  document.getElementById('equipMetaCard').style.display='none';
}

function openAddModal(){
  resetEquipModal();
  equipIsAddMode=true;
  equipOriginal={name:'',qty:'1',desc:'',newFile:'__none__',imgRemoved:false};
  document.getElementById('equipID').value='';
  document.getElementById('equipQty').value='1';
  document.getElementById('equipStatusDisplay').value='Available';
  document.getElementById('equipModalTitle').textContent='Add New Equipment';
  document.getElementById('equipModalSubtitle').textContent='Fill in the details below';
  document.getElementById('equipSaveLabel').textContent='Save Equipment';
  document.getElementById('equipModalIcon').className='fa-solid fa-plus text-green-700 text-sm';
  document.getElementById('equipSaveBtn').disabled=true;
  document.getElementById('equipModalOverlay').classList.add('open');
  document.body.style.overflow='hidden';
  setTimeout(()=>document.getElementById('equipName').focus(),120);
}

function openEditModal(row){
  const raw=row.getAttribute('data-equip');
  if(!raw)return;
  const e=JSON.parse(raw);
  resetEquipModal();
  equipIsAddMode=false;
  document.getElementById('equipID').value=e.equipmentID||'';
  document.getElementById('equipName').value=e.equipment_name||'';
  document.getElementById('equipQty').value=e.quantity_in_storage??0;
  document.getElementById('equipDesc').value=e.description||'';
  const qty=parseInt(e.quantity_in_storage)||0;
  document.getElementById('equipStatusDisplay').value=qty>0?'Available':'Unavailable';
  document.getElementById('equipIDDisplay').value='#'+(e.equipmentID||'');
  let created='â€”';
  if(e.created_at){const d=new Date(e.created_at);if(!isNaN(d))created=d.toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'});}
  document.getElementById('equipCreatedAt').value=created;
  document.getElementById('equipMetaCard').style.display='';
  if(e.image_path) setImgPreview('../uploads/equipment/'+e.image_path,e.image_path);
  equipOriginal={
    name:(e.equipment_name||'').trim(),
    qty:(e.quantity_in_storage??0).toString(),
    desc:(e.description||'').trim(),
    newFile:'__none__',imgRemoved:false,
  };
  document.getElementById('equipModalTitle').textContent='Edit Equipment';
  document.getElementById('equipModalSubtitle').textContent=e.equipment_name||'Update the details below';
  document.getElementById('equipSaveLabel').textContent='Update';
  document.getElementById('equipModalIcon').className='fa-solid fa-pen-to-square text-green-700 text-sm';
  document.getElementById('equipSaveBtn').disabled=true;
  document.getElementById('equipModalOverlay').classList.add('open');
  document.body.style.overflow='hidden';
}

function closeEquipModal(){document.getElementById('equipModalOverlay').classList.remove('open');document.body.style.overflow='';}
function closeEquipModalOnOverlay(e){if(e.target===document.getElementById('equipModalOverlay'))closeEquipModal();}

function saveEquipment(){
  const id=document.getElementById('equipID').value;
  const name=(document.getElementById('equipName').value||'').trim();
  const qty=document.getElementById('equipQty').value;
  const desc=(document.getElementById('equipDesc').value||'').trim();
  if(!name){showToast('error','Validation Error','Item name is required.');document.getElementById('equipName').focus();document.getElementById('equipName').classList.add('changed');return;}
  const btn=document.getElementById('equipSaveBtn');
  btn.disabled=true;
  btn.innerHTML='<i class="fa-solid fa-spinner fa-spin text-sm"></i> Savingâ€¦';
  const fd=new FormData();
  fd.append('action',id?'update':'add');
  if(id)fd.append('equipmentID',id);
  fd.append('equipment_name',name);
  fd.append('quantity_in_storage',qty);
  fd.append('description',desc);
  if(equipNewFile) fd.append('image',equipNewFile);
  if(equipImgRemoved) fd.append('remove_image','1');
  fetch('equipmentAction.php',{method:'POST',body:fd})
    .then(r=>r.json())
    .then(data=>{
      if(data.success){
        closeEquipModal();
        showToast('success',id?'Equipment Updated!':'Equipment Added!',`"${name}" has been ${id?'updated':'added'} successfully.`);
        setTimeout(()=>location.reload(),1200);
      } else {
        showToast('error','Save Failed',data.message||'Could not save equipment.');
        btn.disabled=false;
        btn.innerHTML=`<span id="equipSaveLabel">${id?'Update':'Save Equipment'}</span> <i class="fa-solid fa-floppy-disk text-sm"></i>`;
      }
    })
    .catch(()=>{
      showToast('error','Network Error','Could not connect to server.');
      btn.disabled=false;
      btn.innerHTML=`<span>${id?'Update':'Save Equipment'}</span> <i class="fa-solid fa-floppy-disk text-sm"></i>`;
    });
}

function confirmDeleteEquipment(row){
  const e=JSON.parse(row.getAttribute('data-equip'));
  showDialog({
    type:'delete',title:'Delete Equipment',iconClass:'fa-trash',confirmClass:'delete',
    desc:`"${e.equipment_name}" will be permanently removed and cannot be recovered.`,
    badge:e.equipment_name,confirmLabel:'Yes, Delete',
    onConfirm:()=>{
      fetch('equipmentAction.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`action=delete&equipmentID=${e.equipmentID}`})
        .then(r=>r.json())
        .then(data=>{
          if(data.success){row.remove();showToast('warning','Deleted',`"${e.equipment_name}" removed.`);}
          else showToast('error','Delete Failed',data.message||'Could not delete equipment.');
        })
        .catch(()=>showToast('error','Network Error','Could not connect.'));
    }
  });
}
</script>
</body>
</html>