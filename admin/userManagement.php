<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}
$role = $_SESSION['account_role'] ?? '';
if ($role !== 'admin') {
    switch ($role) {
        case 'resident':
        case 'resident,business/apartment owner':
            header('Location: ../resident/residentLanding.php'); break;
        case 'non-resident':
        case 'non-resident,business/apartment owner':
            header('Location: ../nonResident/nonresidentLanding.php'); break;
        default:
            header('Location: ../landing.php');
    }
    exit;
}

require_once __DIR__ . '/../config/db_connection.php';

ob_start();

require_once '../includes/site_config.php';
$siteSettings = site_config_load($conn);
require_once __DIR__ . '/../includes/check_permissions.php';
require_permission($conn, 'manage_residents'); // swap the key per page — see table above
function fetchGroup($conn, $status) {
    $sql = "SELECT userID,accID,account_role_csv,firstname,lastname,middlename,suffix,
                   family_role,gender,birthday,birthplace,civil_status,citizenship,
                   religion,ethnicity,street,barangay,city,province,zip,phone,
                   emergency_contact,emergency_phone,health_conditions,employment_status,
                   job_title,monthly_income,years_resident,resident_birth,voter_id,
                   precinct,userStatus,frontID,backID,dateRegistered
            FROM tbl_userinfo WHERE LOWER(userStatus) = ?
            ORDER BY dateRegistered DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $status);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    return $rows;
}

$pending_users  = fetchGroup($conn, 'pending');
$approved_users = fetchGroup($conn, 'approved');
$rejected_users = fetchGroup($conn, 'rejected');
$disabled_users = fetchGroup($conn, 'disabled');

$cnt_pending  = count($pending_users);
$cnt_approved = count($approved_users) + count($disabled_users);
$cnt_rejected = count($rejected_users);

// ── Stat cards: Accounts Overview ───────────────────────────────────────

// New Registrations This Month, vs Last Month
$regTrendRow = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        SUM(CASE WHEN dateRegistered >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN 1 ELSE 0 END) AS this_month,
        SUM(CASE WHEN dateRegistered >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
                  AND dateRegistered <  DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN 1 ELSE 0 END) AS last_month
    FROM tbl_userinfo
"));
$regThisMonth = (int) ($regTrendRow['this_month'] ?? 0);
$regLastMonth = (int) ($regTrendRow['last_month'] ?? 0);
if ($regLastMonth > 0) {
    $regTrendPct = (int) round((($regThisMonth - $regLastMonth) / $regLastMonth) * 100);
} else {
    $regTrendPct = $regThisMonth > 0 ? 100 : 0;
}
$regTrendDir = $regThisMonth > $regLastMonth ? 'up' : ($regThisMonth < $regLastMonth ? 'down' : 'flat');

// Accounts Registered Today
$regToday = (int) mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(*) AS total FROM tbl_userinfo WHERE DATE(dateRegistered) = CURDATE()
"))['total'];

// Average Approval Time (Pending -> Approved/Rejected)
// Reads from a `statusUpdatedAt` column if present, and degrades to "N/A"
// if the migration hasn't been applied yet. See add_status_updated_at.sql.
$avgApprovalHours = null;
$hasStatusUpdatedAtCol = mysqli_query($conn, "SHOW COLUMNS FROM tbl_userinfo LIKE 'statusUpdatedAt'");
if ($hasStatusUpdatedAtCol && mysqli_num_rows($hasStatusUpdatedAtCol) > 0) {
    $avgRow = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT AVG(TIMESTAMPDIFF(HOUR, dateRegistered, statusUpdatedAt)) AS avg_hours
        FROM tbl_userinfo
        WHERE LOWER(userStatus) IN ('approved','rejected','disabled')
          AND statusUpdatedAt IS NOT NULL
    "));
    if ($avgRow && $avgRow['avg_hours'] !== null) {
        $avgApprovalHours = round((float) $avgRow['avg_hours'], 1);
    }
}

// Verification Rate: Approved vs Rejected, among accounts that have been decided
$verifRow = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        SUM(CASE WHEN LOWER(userStatus) IN ('approved','disabled') THEN 1 ELSE 0 END) AS approved_total,
        SUM(CASE WHEN LOWER(userStatus) = 'rejected' THEN 1 ELSE 0 END) AS rejected_total
    FROM tbl_userinfo
"));
$verifApproved = (int) ($verifRow['approved_total'] ?? 0);
$verifRejected = (int) ($verifRow['rejected_total'] ?? 0);
$verifDecided = $verifApproved + $verifRejected;
$verificationRate = $verifDecided > 0 ? round(($verifApproved / $verifDecided) * 100) : 0;

mysqli_close($conn);

function buildRow($u, $tab) {
    $fullname = trim($u['firstname'].' '.($u['middlename'] ? $u['middlename'].' ' : '').$u['lastname'].($u['suffix'] ? ' '.$u['suffix'] : ''));
    $date = '—';
    if (!empty($u['dateRegistered'])) {
        $ts = strtotime($u['dateRegistered']);
        if ($ts && date('Y',$ts) > 1900) $date = date('F j, Y', $ts);
    }
    $uid     = (int)$u['userID'];
    $enc     = str_replace('"','&quot;',json_encode($u));
    $dateRaw = htmlspecialchars($u['dateRegistered'] ?? '');
    $roles   = explode(',', $u['account_role_csv'] ?? '');
    $chips   = '';
    foreach ($roles as $r) {
        $r = trim($r); if (!$r) continue;
        // Role strings are stored lowercase (see nonresidentRoleChangeAction.php),
        // so this match must be case-insensitive or every role silently falls
        // through to the generic "business" chip.
        $cls = match(true) {
            stripos($r,'Non-Resident') !== false => 'chip-nonresident',
            stripos($r,'Resident')     !== false => 'chip-resident',
            default                               => 'chip-business',
        };
        $chips .= "<span class='role-chip $cls'>".htmlspecialchars($r)."</span>";
    }

    if ($tab === 'pending') {
        $actions = "
          <button class='btn-view' onclick='openModal(this.closest(\"tr\"))'>View</button>
          <button class='btn-approve' onclick='handleAction($uid,\"approve\",this.closest(\"tr\"))'>
            <i class='fa-solid fa-check text-[10px]'></i><span class='btn-label'>Approve</span>
          </button>
          <button class='btn-reject' onclick='handleAction($uid,\"reject\",this.closest(\"tr\"))'>
            <i class='fa-solid fa-xmark text-[10px]'></i><span class='btn-label'>Reject</span>
          </button>";
    } elseif ($tab === 'approved') {
        $actions = "
          <button class='btn-view' onclick='openModal(this.closest(\"tr\"))'>View</button>
          <button class='btn-disable' onclick='handleAction($uid,\"disable\",this.closest(\"tr\"))'>
            <i class='fa-solid fa-ban text-[10px]'></i><span class='btn-label'>Disable</span>
          </button>";
    } elseif ($tab === 'disabled') {
        $actions = "
          <button class='btn-view' onclick='openModal(this.closest(\"tr\"))'>View</button>
          <button class='btn-approve' onclick='handleAction($uid,\"approve\",this.closest(\"tr\"))'>
            <i class='fa-solid fa-rotate-left text-[10px]'></i><span class='btn-label'>Re-enable</span>
          </button>";
    } else { // rejected
        $actions = "
          <button class='btn-view' onclick='openModal(this.closest(\"tr\"))'>View</button>
          <button class='btn-revert' onclick='handleAction($uid,\"revert\",this.closest(\"tr\"))'>
            <i class='fa-solid fa-rotate-left text-[10px]'></i><span class='btn-label'>Set Pending</span>
          </button>";
    }

    return "<tr data-user='$enc' data-date='$dateRaw' data-tab='$tab'>
        <td><input type='checkbox' class='row-check rounded' onchange='updateBulkVisibility(\"$tab\")'></td>
        <td>
          <p class='font-semibold text-gray-900 text-sm'>".htmlspecialchars($fullname)."</p>
          <p class='text-gray-400 text-xs'>".htmlspecialchars($u['email'] ?? '')."</p>
        </td>
        <td class='col-role'>$chips</td>
        <td class='text-gray-500 col-date'>$date</td>
        <td><div class='flex items-center justify-end gap-2 flex-wrap'>$actions</div></td>
      </tr>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="../assets/responsive-global.css">
  <title>User Management - <?= e($siteSettings['site_title']) ?></title>
  <link rel="icon" href="<?= e(site_config_logo_url($siteSettings, '../')) ?>" type="image/png">
  <script src="https://cdn.tailwindcss.com/3.4.16"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <?= site_config_css_vars($siteSettings) ?>
  <style>
    * { box-sizing: border-box; }
   body { font-family: 'DM Sans', sans-serif; background: var(--site-primary-pale); margin: 0; }

    /* ── Sidebar ── */
    .sidebar { width:260px; flex-shrink:0; background: linear-gradient(180deg, var(--site-primary-dark) 0%, var(--site-primary-darker) 55%, var(--site-primary) 100%); display:flex; flex-direction:column; position:fixed; top:0; left:0; height:100vh; z-index:300; overflow:hidden; transition:width .3s cubic-bezier(.4,0,.2,1),transform .3s cubic-bezier(.4,0,.2,1); }
    .sidebar.collapsed { width:0; }
    .sidebar:not(.collapsed) { overflow-y:auto; }
    .sidebar::-webkit-scrollbar { width:4px; }
    .sidebar::-webkit-scrollbar-thumb { background:rgba(134,239,172,.2); border-radius:4px; }
    .sidebar-inner { width:260px; min-width:260px; display:flex; flex-direction:column; height:100%; }
    .sidebar-logo { padding:20px 18px 16px; border-bottom:1px solid rgba(134,239,172,.12); display:flex; align-items:center; justify-content:space-between; flex-shrink:0; }
    .section-label { padding:18px 18px 6px; font-size:.62rem; font-weight:700; letter-spacing:.13em; text-transform:uppercase; color:rgba(255,255,255,0.4); white-space:nowrap; }
    .menu-item { display:flex; align-items:center; justify-content:space-between; width:calc(100% - 16px); padding:10px 14px; margin:1px 8px; border-radius:10px; color:rgba(255,255,255,0.72); font-size:.84rem; font-weight:500; text-decoration:none; border:none; background:none; text-align:left; cursor:pointer; transition:background .18s,color .18s; white-space:nowrap; }
    .menu-item:hover { background:rgba(255,255,255,.07); color:#fff; }
    .menu-item.active { background:rgba(255,255,255,.13); color:#fff; }
    .menu-left { display:flex; align-items:center; gap:11px; }
    .menu-item .mi { width:17px; text-align:center; font-size:.85rem; flex-shrink:0; }
    .active-dot { width:7px; height:7px; border-radius:50%; background: var(--site-primary-light); flex-shrink:0; }
    .collapse-btn { width:28px; height:28px; border-radius:8px; background:rgba(255,255,255,.1); border:none; cursor:pointer; color:#fff; font-size:.72rem; display:flex; align-items:center; justify-content:center; flex-shrink:0; transition:background .2s; }
    .collapse-btn:hover { background:rgba(255,255,255,.22); }
    .expand-btn { position:fixed; top:18px; left:12px; z-index:200; width:36px; height:36px; border-radius:10px; background:var(--site-primary-darker); border:1px solid rgba(134,239,172,.25); color:#fff; font-size:.82rem; cursor:pointer; display:none; align-items:center; justify-content:center; box-shadow:0 4px 16px rgba(5,46,22,.4); transition:background .2s; }
    .expand-btn.visible { display:flex; }
    .expand-btn:hover { background:var(--site-primary); }
    .sidebar-backdrop { display:none; position:fixed; inset:0; z-index:250; background:rgba(5,46,22,.5); backdrop-filter:blur(2px); }
    .sidebar-backdrop.visible { display:block; }
    .sidebar-bottom { margin-top:auto; flex-shrink:0; }
    .sidebar-bottom-links { padding:0 16px 8px; }
    .sidebar-bottom-links .side-link { display:block; width:100%; font-size:.84rem; padding:8px 8px; border-radius:8px; transition:color .15s,background .15s; text-decoration:none; white-space:nowrap; border:none; background:none; text-align:left; cursor:pointer; }

    /* ── Main ── */
    .main-wrapper { display:flex; min-height:100vh; }
    .main-content { flex:1; min-width:0; display:flex; flex-direction:column; width:calc(100% - 260px); margin-left:260px; transition:margin-left .3s cubic-bezier(.4,0,.2,1); overflow-x:hidden; }
    .main-content.sidebar-collapsed { width:100%; margin-left:0; }
    .topbar { background:#fff; border-bottom:1px solid #e5e7eb; padding:14px 28px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; position:sticky; top:0; z-index:100; }
    .topbar-title-block { transition:margin-left .25s ease; }

    /* ── Stat cards ── */
    .stat-card { background:#fff; border-radius:14px; padding:20px 22px; border:1px solid #e5e7eb; box-shadow:0 2px 12px rgba(21,128,61,0.05); display:flex; flex-direction:column; gap:10px; transition:transform .2s, box-shadow .2s; }
    .stat-card:hover { transform:translateY(-3px); box-shadow:0 8px 24px rgba(21,128,61,.1); }
    .stat-label { font-size:.82rem; font-weight:600; color:#6b7280; }
    .stat-row   { display:flex; align-items:center; gap:14px; }
    .stat-ico   { font-size:1.6rem; }
    .stat-num   { font-size:2.4rem; font-weight:800; color:#111827; line-height:1; }
    .stat-sub { font-size:.75rem; font-weight:600; color:#9ca3af; }
    .stat-trend { display:inline-flex; align-items:center; gap:4px; font-size:.78rem; font-weight:700; }
    .stat-trend-up { color:#15803d; }
    .stat-trend-down { color:#dc2626; }
    .stat-trend-flat { color:#9ca3af; }

    /* ── Tabs ── */
    .tab-bar { display:flex; gap:2px; border-bottom:2px solid #e5e7eb; padding:0 24px; background:#fff; }
    .tab-btn { padding:12px 18px 11px; font-size:.84rem; font-weight:600; color:#6b7280; background:none; border:none; border-bottom:2px solid transparent; margin-bottom:-2px; cursor:pointer; white-space:nowrap; transition:color .15s,border-color .15s; display:flex; align-items:center; gap:7px; }
    .tab-btn:hover { color:#374151; }
    .tab-btn.active { color:#15803d; border-bottom-color:#15803d; }
    .tab-badge { display:inline-flex; align-items:center; justify-content:center; min-width:18px; height:18px; padding:0 5px; border-radius:999px; font-size:.65rem; font-weight:700; }
    .tab-badge-pending  { background:#fef9c3; color:#a16207; }
    .tab-badge-approved { background:#dcfce7; color:#15803d; }
    .tab-badge-rejected { background:#fee2e2; color:#dc2626; }

    /* ── Table ── */
    .tbl-wrap { background:#fff; border-radius:14px; border:1px solid #e5e7eb; box-shadow:0 2px 12px rgba(21,128,61,.05); overflow-x:auto; -webkit-overflow-scrolling:touch; }
    table { width:100%; border-collapse:collapse; min-width:560px; }
    thead th { background:#f9fafb; padding:11px 16px; text-align:left; font-size:.75rem; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.06em; border-bottom:1px solid #e5e7eb; white-space:nowrap; }
    tbody tr { border-bottom:1px solid #f3f4f6; transition:background .15s; }
    tbody tr:last-child { border-bottom:none; }
    tbody tr:hover { background:#f0fdf4; }
    tbody td { padding:14px 16px; font-size:.84rem; color:#374151; vertical-align:middle; }

    /* Chips */
    .role-chip { display:inline-flex; align-items:center; padding:3px 10px; border-radius:999px; font-size:.68rem; font-weight:700; margin-right:4px; white-space:nowrap; }
    .chip-resident    { background:#dcfce7; color:#15803d; }
    .chip-nonresident { background:#dbeafe; color:#1d4ed8; }
    .chip-business    { background:#fef9c3; color:#a16207; }

    /* Status badge */
    .status-badge { display:inline-flex; align-items:center; padding:3px 10px; border-radius:999px; font-size:.68rem; font-weight:700; white-space:nowrap; }
    .status-approved { background:#dcfce7; color:#15803d; }
    .status-rejected { background:#fee2e2; color:#dc2626; }
    .status-disabled { background:#f3f4f6; color:#6b7280; }

    /* Buttons */
    .btn-view    { font-size:.78rem; font-weight:600; color:#374151; text-decoration:underline; cursor:pointer; background:none; border:none; padding:0; white-space:nowrap; }
    .btn-view:hover { color:#15803d; }
    .btn-approve { display:inline-flex; align-items:center; gap:5px; padding:5px 12px; border-radius:8px; border:1.5px solid #16a34a; color:#15803d; background:#f0fdf4; font-size:.75rem; font-weight:700; cursor:pointer; transition:all .15s; white-space:nowrap; }
    .btn-approve:hover { background:#16a34a; color:#fff; }
    .btn-reject  { display:inline-flex; align-items:center; gap:5px; padding:5px 12px; border-radius:8px; border:1.5px solid #ef4444; color:#dc2626; background:#fef2f2; font-size:.75rem; font-weight:700; cursor:pointer; transition:all .15s; white-space:nowrap; }
    .btn-reject:hover { background:#ef4444; color:#fff; }
    .btn-disable { display:inline-flex; align-items:center; gap:5px; padding:5px 12px; border-radius:8px; border:1.5px solid #ef4444; color:#dc2626; background:#fef2f2; font-size:.75rem; font-weight:700; cursor:pointer; transition:all .15s; white-space:nowrap; }
    .btn-disable:hover { background:#ef4444; color:#fff; }
    .btn-revert  { display:inline-flex; align-items:center; gap:5px; padding:5px 12px; border-radius:8px; border:1.5px solid #f59e0b; color:#b45309; background:#fffbeb; font-size:.75rem; font-weight:700; cursor:pointer; transition:all .15s; white-space:nowrap; }
    .btn-revert:hover { background:#f59e0b; color:#fff; }

    /* Search & filter */
    .search-box { display:flex; align-items:center; gap:8px; border:1.5px solid #e5e7eb; border-radius:9px; padding:7px 12px; background:#fff; transition:border-color .15s; min-width:0; }
    .search-box:focus-within { border-color:var(--site-primary); }
    .search-box input { border:none; outline:none; font-size:.83rem; color:#374151; font-family:inherit; width:100%; min-width:0; background:transparent; }
    .btn-filter  { display:inline-flex; align-items:center; gap:6px; padding:7px 16px; border-radius:9px; border:1.5px solid #e5e7eb; background:#fff; font-size:.83rem; font-weight:600; color:#374151; cursor:pointer; transition:all .15s; white-space:nowrap; }
    .btn-filter:hover { border-color:var(--site-primary); color:var(--site-primary); }
    .btn-refresh { width:30px; height:30px; border-radius:8px; border:1.5px solid #e5e7eb; background:#fff; color:#6b7280; font-size:.82rem; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all .15s; flex-shrink:0; }
    .btn-refresh:hover { border-color:var(--site-primary); color:var(--site-primary); }

    /* Pagination */
    .page-btn { width:34px; height:34px; border-radius:8px; border:1.5px solid #e5e7eb; background:#fff; font-size:.82rem; font-weight:600; color:#374151; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all .15s; flex-shrink:0; }
    .page-btn:hover { border-color:var(--site-primary-light); color:var(--site-primary-light); }
    .page-btn.active { background:var(--site-primary); border-color:var(--site-primary); color:#fff; }
    .page-btn:disabled { opacity:.35; cursor:default; }

    /* Modal */
    .modal-overlay { position:fixed; inset:0; z-index:500; background:rgba(5,46,22,.45); backdrop-filter:blur(3px); display:flex; align-items:flex-start; justify-content:center; padding:16px; overflow-y:auto; opacity:0; pointer-events:none; transition:opacity .22s; }
    .modal-overlay.open { opacity:1; pointer-events:auto; }
    .modal { background:#fff; border-radius:18px; width:100%; max-width:620px; max-height:calc(100vh - 32px); display:flex; flex-direction:column; box-shadow:0 20px 60px rgba(5,46,22,.25); transform:translateY(16px); transition:transform .25s cubic-bezier(.4,0,.2,1); margin:auto; }
    .modal-overlay.open .modal { transform:translateY(0); }
    .modal-header { padding:18px 20px 14px; border-bottom:1px solid #f3f4f6; display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; background:#fff; border-radius:18px 18px 0 0; z-index:10; flex-shrink:0; }
    .modal-close { width:30px; height:30px; border-radius:8px; border:none; background:#f3f4f6; cursor:pointer; display:flex; align-items:center; justify-content:center; color:#6b7280; font-size:.8rem; transition:background .15s,color .15s; flex-shrink:0; }
    .modal-close:hover { background:#fee2e2; color:#dc2626; }
    .modal-body { padding:18px 20px; overflow-y:auto; flex:1; min-height:0; }
    .id-placeholder { flex:1; aspect-ratio:4/3; border:1.5px dashed #d1d5db; border-radius:10px; background:#f9fafb; display:flex; flex-direction:column; align-items:center; justify-content:center; color:#9ca3af; font-size:.72rem; gap:6px; cursor:pointer; transition:border-color .15s,background .15s; position:relative; overflow:hidden; min-width:0; }
    .id-placeholder:hover { border-color:#16a34a; background:#f0fdf4; }
    .id-placeholder img { width:100%; height:100%; object-fit:cover; position:absolute; inset:0; border-radius:9px; }
    .field-label { font-size:.72rem; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.06em; margin-bottom:4px; }
    .field-val { width:100%; padding:9px 12px; border:1.5px solid #e5e7eb; border-radius:9px; font-size:.84rem; color:#374151; background:#f9fafb; font-family:inherit; outline:none; transition:border-color .15s; }
    .field-val:focus { border-color:#16a34a; background:#fff; }
    textarea.field-val { resize:vertical; min-height:72px; }
    .section-title { display:flex; align-items:center; gap:8px; font-size:.8rem; font-weight:700; color:#374151; text-transform:uppercase; letter-spacing:.07em; margin-bottom:12px; }
    .section-icon { width:26px; height:26px; background:#dcfce7; border-radius:7px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .section-card { background:#f9fafb; border:1px solid #e5e7eb; border-radius:12px; padding:16px; margin-bottom:16px; }
    .section-card:last-child { margin-bottom:0; }
    .modal-footer { display:grid; grid-template-columns:1fr 1fr; border-top:1px solid #f3f4f6; position:sticky; bottom:0; background:#fff; border-radius:0 0 18px 18px; overflow:hidden; min-height:56px; z-index:20; flex-shrink:0; }
    .modal-footer button { width:100%; margin:0; border:none; border-radius:0; padding:14px; font-size:.88rem; font-weight:700; display:inline-flex; align-items:center; justify-content:center; gap:6px; transition:all .15s; cursor:pointer; }
    #modalActionLeft  { background:#f9fafb; color:#374151; border-right:1px solid #f3f4f6; }
    #modalActionLeft:hover  { background:#fee2e2; color:#dc2626; }
    #modalActionRight { background:#15803d; color:#fff; }
    #modalActionRight:hover { background:#166534; }
    .modal-footer button.btn-modal-revert { color:#b45309; background:#f3f4f6; }
    .modal-footer button.btn-modal-revert:hover { background:#f59e0b; color:#fff;}

    /* Lightbox */
    .lightbox { position:fixed; inset:0; z-index:1000; background:rgba(0,0,0,.82); display:flex; align-items:center; justify-content:center; opacity:0; pointer-events:none; transition:opacity .2s; padding:16px; }
    .lightbox.open { opacity:1; pointer-events:auto; }
    .lightbox img { max-width:90vw; max-height:88vh; border-radius:10px; box-shadow:0 8px 40px rgba(0,0,0,.6); }
    .lightbox-close { position:absolute; top:16px; right:20px; background:rgba(255,255,255,.12); border:none; color:#fff; font-size:1.2rem; width:38px; height:38px; border-radius:10px; cursor:pointer; display:flex; align-items:center; justify-content:center; }

    /* Empty state */
    .empty-state { padding:60px 24px; text-align:center; color:#9ca3af; }
    .empty-state i { font-size:2.5rem; margin-bottom:12px; display:block; color:#d1d5db; }

    /* Dialog */
    .dialog-overlay { position:fixed; inset:0; z-index:900; background:rgba(5,46,22,.5); backdrop-filter:blur(4px); display:flex; align-items:center; justify-content:center; padding:20px; opacity:0; pointer-events:none; transition:opacity .2s; }
    .dialog-overlay.open { opacity:1; pointer-events:auto; }
    .dialog-box { background:#fff; border-radius:20px; width:100%; max-width:400px; box-shadow:0 24px 64px rgba(5,46,22,.3),0 4px 16px rgba(0,0,0,.08); transform:scale(.94) translateY(12px); transition:transform .25s cubic-bezier(.34,1.56,.64,1),opacity .2s; opacity:0; overflow:hidden; }
    .dialog-overlay.open .dialog-box { transform:scale(1) translateY(0); opacity:1; }
    .dialog-icon-wrap { width:64px; height:64px; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 16px; font-size:1.6rem; }
    .dialog-icon-approve { background:#dcfce7; } .dialog-icon-reject { background:#fee2e2; } .dialog-icon-disable { background:#f3f4f6; } .dialog-icon-revert { background:#fef9c3; } .dialog-icon-bulk { background:#fef9c3; }
    .dialog-body { padding:28px 24px 20px; text-align:center; }
    .dialog-title { font-size:1.05rem; font-weight:800; color:#111827; margin-bottom:8px; font-family:'Playfair Display',serif; }
    .dialog-desc  { font-size:.84rem; color:#6b7280; line-height:1.5; }
    .dialog-name-badge { display:inline-block; margin-top:10px; background:#f3f4f6; border-radius:8px; padding:6px 14px; font-size:.82rem; font-weight:700; color:#374151; }
    .dialog-footer { padding:0 20px 20px; display:flex; gap:10px; }
    .dialog-btn { flex:1; padding:11px; border-radius:11px; border:none; font-size:.86rem; font-weight:700; cursor:pointer; font-family:inherit; transition:all .15s; display:flex; align-items:center; justify-content:center; gap:6px; }
    .dialog-btn-cancel  { background:#f3f4f6; color:#374151; }
    .dialog-btn-cancel:hover { background:#e5e7eb; }
    .dialog-btn-confirm-approve { background:linear-gradient(135deg,#16a34a,#15803d); color:#fff; box-shadow:0 4px 14px rgba(22,163,74,.35); }
    .dialog-btn-confirm-approve:hover { background:linear-gradient(135deg,#15803d,#14532d); transform:translateY(-1px); }
    .dialog-btn-confirm-reject  { background:linear-gradient(135deg,#ef4444,#dc2626); color:#fff; box-shadow:0 4px 14px rgba(239,68,68,.35); }
    .dialog-btn-confirm-reject:hover  { background:linear-gradient(135deg,#dc2626,#b91c1c); transform:translateY(-1px); }
    .dialog-btn-confirm-disable { background:linear-gradient(135deg,#ef4444,#dc2626); color:#fff; box-shadow:0 4px 14px rgba(239,68,68,.35); }
    .dialog-btn-confirm-disable:hover { transform:translateY(-1px); }
    .dialog-btn-confirm-revert  { background:#fff; color:#92400e; border:1.5px solid #f59e0b; box-shadow:0 4px 14px rgba(245,158,11,.15); }
    .dialog-btn-confirm-revert:hover  { background:#fde68a; transform:translateY(-1px); }
    .dialog-btn-confirm-bulk    { background:linear-gradient(135deg,#ca8a04,#a16207); color:#fff; }
    .dialog-btn-confirm-bulk:hover { transform:translateY(-1px); }

    /* Alert */
    #alertBanner { display:none; border-radius:10px; margin-bottom:4px; }
    #alertBanner.show { display:flex; }
    .alert-inner { display:flex; align-items:center; gap:10px; padding:13px 16px; font-size:.85rem; font-weight:600; border-radius:10px; border:1.5px solid transparent; width:100%; flex-wrap:wrap; }
    .alert-success { background:#f0fdf4; border-color:#bbf7d0; color:#15803d; }
    .alert-error   { background:#fef2f2; border-color:#fecaca; color:#dc2626; }
    .alert-warning { background:#fefce8; border-color:#fde68a; color:#a16207; }
    .alert-close { margin-left:auto; background:none; border:none; cursor:pointer; font-size:.8rem; opacity:.6; color:inherit; padding:2px 4px; transition:opacity .15s; }
    .alert-close:hover { opacity:1; }

    @keyframes fadeUp { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }
    .fade-up { animation:fadeUp .35s ease both; }

    /* Responsive */
    @media(max-width:1024px) {
      .sidebar { transform:translateX(-100%); width:260px!important; }
      .sidebar.mobile-open { transform:translateX(0); }
      .main-content { width:100%!important; margin-left:0!important; }
      .topbar { padding:12px 16px; }
      .topbar-title-block { margin-left:46px!important; }
      .tab-bar { padding:0 14px; overflow-x:auto; }
    }
    @media(max-width:640px) {
      .topbar { padding:10px 14px; gap:8px; }
      .page-pad { padding:14px!important; }
      .top-row { flex-direction:column; align-items:stretch!important; gap:10px!important; }
      .top-row-right { display:flex; flex-direction:column; gap:8px; width:100%; }
      .search-box { width:100%!important; }
      .controls-row { display:flex; gap:8px; width:100%; }
      .btn-filter { flex:1; justify-content:center; }
      #filterPanel { flex-direction:column!important; gap:12px!important; }
      #filterPanel select,#filterPanel input[type="date"] { width:100%!important; }
      .col-date,.col-role { display:none; }
      .modal-overlay { padding:0; align-items:flex-end; }
      .modal { border-radius:20px 20px 0 0; max-width:100%; max-height:95vh; }
      .modal-body { padding:14px; max-height:calc(95vh - 130px); }
      .modal-header { padding:14px 14px 10px; }
      .section-card { padding:14px 12px; }
      .modal .grid-cols-2 { grid-template-columns:1fr!important; }
      #bulkActions { flex-wrap:wrap; gap:8px; }
      .page-btn { width:30px; height:30px; font-size:.76rem; }
      #paginationContainer { flex-wrap:wrap; }
    }
    @media(max-width:380px) {
      .btn-approve .btn-label,.btn-reject .btn-label,.btn-disable .btn-label,.btn-revert .btn-label { display:none; }
      .btn-approve,.btn-reject,.btn-disable,.btn-revert { padding:5px 8px; }
    }

    /* Tab pane hidden/shown */
    .tab-pane { display:none; }
    .tab-pane.active { display:block; }
    :root {
  --site-primary-dark:   color-mix(in srgb, var(--site-primary) 55%, black);
  --site-primary-darker: color-mix(in srgb, var(--site-primary) 75%, black);
  --site-primary-light:  color-mix(in srgb, var(--site-primary) 55%, white);
  --site-primary-pale:   color-mix(in srgb, var(--site-primary) 12%, white);
}
  </style>
</head>
<body>

<div class="sidebar-backdrop" id="sidebarBackdrop" onclick="closeMobileSidebar()"></div>
<button class="expand-btn" id="expandBtn" title="Open sidebar"><i class="fa-solid fa-bars"></i></button>

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
        <button class="collapse-btn" id="collapseBtn" title="Collapse sidebar"><i class="fa-solid fa-chevron-left"></i></button>
      </div>
      <div class="section-label">Management</div>
      <nav class="space-y-0.5 px-2">
        <button type="button" data-nav="adminDashboard.php" class="menu-item"><div class="menu-left"><i class="fa-solid fa-chart-bar mi"></i>Dashboard</div></button>
        <button type="button" data-nav="userManagement.php" class="menu-item active">
          <div class="menu-left"><i class="fa-solid fa-user mi"></i>User Management</div>
          <span class="active-dot"></span>
        </button>
        <button type="button" data-nav="residentManagement.php" class="menu-item"><div class="menu-left"><i class="fa-solid fa-house-chimney-user mi"></i>Resident Management</div></button>
        <button type="button" data-nav="beneficiaryManagement" class="menu-item"><div class="menu-left"><i class="fa-solid fa-hand-holding-heart mi"></i>Beneficiary Management</div></button>
        <button type="button" data-nav="documentRequest.php" class="menu-item"><div class="menu-left"><i class="fa-regular fa-file-lines mi"></i>Document Request</div></button>
        <button type="button" data-nav="borrowingSystem.php" class="menu-item"><div class="menu-left"><i class="fa-solid fa-hammer mi"></i>Borrowing System</div></button>
      </nav>
      <div class="section-label">Community</div>
      <nav class="space-y-0.5 px-2">
        <button class="menu-item" data-nav="communityListings.php"><div class="menu-left"><i class="fa-solid fa-building mi"></i>Community Listings</div></button>
        <button class="menu-item" data-nav="announcement.php"><div class="menu-left"><i class="fa-solid fa-pen-to-square mi"></i>Announcements</div></button>
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

  <!-- ══════════ MAIN ══════════ -->
  <main class="main-content" id="mainContent">
    <header class="topbar">
      <div class="topbar-title-block">
        <h2 class="font-bold text-2xl leading-tight" style="font-family:'Playfair Display',serif;color: var(--site-primary-darker);">User Management</h2>
        <p class="text-gray-500 text-sm mt-0.5">Verify residents and manage account access here.</p>
      </div>
    </header>

    <!-- TAB BAR -->
    <div class="tab-bar">
      <button class="tab-btn active" onclick="switchTab('pending',this)">
        Pending <span class="tab-badge tab-badge-pending" id="badge-pending"><?= $cnt_pending ?></span>
      </button>
      <button class="tab-btn" onclick="switchTab('approved',this)">
        Approved <span class="tab-badge tab-badge-approved" id="badge-approved"><?= $cnt_approved ?></span>
      </button>
      <button class="tab-btn" onclick="switchTab('rejected',this)">
        Rejected <span class="tab-badge tab-badge-rejected" id="badge-rejected"><?= $cnt_rejected ?></span>
      </button>
    </div>

    <div id="realtimeLoader" class="flex-1 flex flex-col items-center justify-center min-h-[50vh]" style="display:none;">
      <i class="fa-solid fa-circle-notch fa-spin text-4xl text-green-600 mb-4"></i>
      <p class="text-sm font-semibold text-green-800 animate-pulse tracking-wide">Loading users...</p>
    </div>

    <!-- DATA CONTAINER -->
    <div id="mainDataContainer" class="p-6 page-pad flex-1 space-y-5 fade-up">

      <?php
      echo str_repeat("<!-- PADDING TO FORCE BROWSER RENDER -->\n", 40);
      ob_flush(); flush();
      ?>

      <!-- Alert Banner (shared) -->
      <div id="alertBanner"><div class="alert-inner" id="alertInner">
        <i id="alertIcon" class="fa-solid fa-circle-check" style="font-size:1rem;flex-shrink:0;"></i>
        <div><span id="alertTitle" style="font-weight:700;"></span><span id="alertDesc" style="font-weight:400;margin-left:6px;opacity:.85;"></span></div>
        <button class="alert-close" onclick="dismissAlert()"><i class="fa-solid fa-xmark"></i></button>
      </div></div>

      <!-- Stat cards -->
      <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        <div class="stat-card">
          <p class="stat-label">New Registrations This Month</p>
          <div class="stat-row"><i class="fa-solid fa-user-plus stat-ico text-blue-500"></i><span class="stat-num"><?= number_format($regThisMonth) ?></span></div>
          <?php if ($regTrendDir === 'up'): ?>
            <span class="stat-trend stat-trend-up"><i class="fa-solid fa-arrow-up"></i> <?= $regTrendPct ?>% vs last month</span>
          <?php elseif ($regTrendDir === 'down'): ?>
            <span class="stat-trend stat-trend-down"><i class="fa-solid fa-arrow-down"></i> <?= abs($regTrendPct) ?>% vs last month</span>
          <?php else: ?>
            <span class="stat-trend stat-trend-flat"><i class="fa-solid fa-minus"></i> Same as last month</span>
          <?php endif; ?>
        </div>
        <div class="stat-card">
          <p class="stat-label">Accounts Registered Today</p>
          <div class="stat-row"><i class="fa-solid fa-calendar-day stat-ico text-purple-500"></i><span class="stat-num"><?= number_format($regToday) ?></span></div>
          <span class="stat-sub"><?= date('F j, Y') ?></span>
        </div>
        <div class="stat-card">
          <p class="stat-label">Average Approval Time</p>
          <?php if ($avgApprovalHours !== null): ?>
            <div class="stat-row"><i class="fa-solid fa-hourglass-half stat-ico text-amber-500"></i><span class="stat-num"><?= $avgApprovalHours < 48 ? number_format($avgApprovalHours, 1) . 'h' : number_format($avgApprovalHours / 24, 1) . 'd' ?></span></div>
            <span class="stat-sub">Pending — Approved/Rejected</span>
          <?php else: ?>
            <div class="stat-row"><i class="fa-solid fa-hourglass-half stat-ico text-gray-300"></i><span class="stat-num text-gray-300">N/A</span></div>
            <span class="stat-sub">Awaiting status-change tracking</span>
          <?php endif; ?>
        </div>
        <div class="stat-card">
          <p class="stat-label">Verification Rate</p>
          <div class="stat-row"><i class="fa-solid fa-shield-halved stat-ico" style="color: var(--site-primary);"></i><span class="stat-num"><?= $verificationRate ?>%</span></div>
          <span class="stat-sub"><?= number_format($verifApproved) ?> approved / <?= number_format($verifRejected) ?> rejected</span>
        </div>
      </div>

      <!-- ══════ TAB: PENDING ══════ -->
      <div id="tab-pending" class="tab-pane active space-y-5">
        <?php renderTabControls('pending'); ?>
        <?php renderFilterPanel('pending'); ?>
        <?php renderTable('pending', $pending_users); ?>
        <?php renderBulkActions('pending'); ?>
        <div class="flex items-center justify-center gap-2 pt-2 flex-wrap" id="pagination-pending"></div>
      </div>

      <!-- ══════ TAB: APPROVED ══════ -->
      <div id="tab-approved" class="tab-pane space-y-5">
        <?php renderTabControls('approved'); ?>
        <?php renderFilterPanel('approved'); ?>
        <?php renderTable('approved', array_merge($approved_users, $disabled_users)); ?>
        <?php renderBulkActions('approved'); ?>
        <div class="flex items-center justify-center gap-2 pt-2 flex-wrap" id="pagination-approved"></div>
      </div>

      <!-- ══════ TAB: REJECTED ══════ -->
      <div id="tab-rejected" class="tab-pane space-y-5">
        <?php renderTabControls('rejected'); ?>
        <?php renderFilterPanel('rejected'); ?>
        <?php renderTable('rejected', $rejected_users); ?>
        <?php renderBulkActions('rejected'); ?>
        <div class="flex items-center justify-center gap-2 pt-2 flex-wrap" id="pagination-rejected"></div>
      </div>

    </div>
  </main>
</div>

<?php
// ── Reusable PHP renderers ────────────────────────────────────────────────
function renderTabControls($tab) { ?>
  <div class="flex items-center justify-between gap-4 flex-wrap top-row">
    <div class="flex items-baseline gap-2">
      <h3 class="font-bold text-gray-900 text-lg">
        <?= ucfirst($tab) ?> <?= $tab === 'approved' ? 'Accounts' : 'Requests' ?>
      </h3>
    </div>
    <div class="flex items-center gap-3 flex-wrap top-row-right">
      <div class="search-box" style="width:200px;">
        <i class="fa-solid fa-magnifying-glass text-gray-400 text-xs flex-shrink-0"></i>
        <input type="text" id="search-<?= $tab ?>" placeholder="Search..." oninput="filterTab('<?= $tab ?>')">
      </div>
      <div class="controls-row flex items-center gap-2">
        <button class="btn-filter" onclick="toggleFilter('<?= $tab ?>')">
          <i class="fa-solid fa-filter text-xs"></i><span class="btn-label">Filter</span><i class="fa-solid fa-chevron-down text-xs"></i>
        </button>
        <button class="btn-refresh" onclick="triggerRefresh()" title="Refresh"><i class="fa-solid fa-rotate-right text-xs"></i></button>
      </div>
    </div>
  </div>
<?php }

function renderFilterPanel($tab) { ?>
  <div id="fp-<?= $tab ?>" class="hidden bg-white border border-gray-200 p-4 shadow-sm flex flex-wrap gap-4" style="border-radius:12px;">
    <div style="min-width:140px;flex:1;">
      <p class="field-label mb-1">Role</p>
      <select id="fr-role-<?= $tab ?>" class="field-val w-full" onchange="filterTab('<?= $tab ?>')">
        <option value="">All Roles</option>
        <option>Resident</option><option>Non-Resident</option><option>Business/Apartment Owner</option>
      </select>
    </div>
    <?php if ($tab === 'approved'): ?>
    <div style="min-width:140px;flex:1;">
      <p class="field-label mb-1">Status</p>
      <select id="fr-status-<?= $tab ?>" class="field-val w-full" onchange="filterTab('<?= $tab ?>')">
        <option value="">All</option><option value="approved">Active</option><option value="disabled">Disabled</option>
      </select>
    </div>
    <?php endif; ?>
    <div style="min-width:140px;flex:1;">
      <p class="field-label mb-1">Date From</p>
      <input type="date" id="fr-from-<?= $tab ?>" class="field-val w-full" onchange="filterTab('<?= $tab ?>')">
    </div>
    <div style="min-width:140px;flex:1;">
      <p class="field-label mb-1">Date To</p>
      <input type="date" id="fr-to-<?= $tab ?>" class="field-val w-full" onchange="filterTab('<?= $tab ?>')">
    </div>
  </div>
<?php }

function renderTable($tab, $users) { ?>
  <div class="tbl-wrap relative" id="tbl-<?= $tab ?>">
    <div id="tl-<?= $tab ?>" class="absolute inset-0 bg-white/80 backdrop-blur-sm z-10 flex flex-col items-center justify-center opacity-0 pointer-events-none transition-opacity duration-200">
      <i class="fa-solid fa-circle-notch fa-spin text-3xl text-green-600 mb-3"></i>
      <p class="text-xs font-semibold text-green-800 animate-pulse tracking-wide">Searching...</p>
    </div>
    <div id="nr-<?= $tab ?>" class="hidden absolute inset-0 z-0 flex flex-col items-center justify-center text-center p-6 pb-12">
      <i class="fa-solid fa-magnifying-glass text-gray-300 text-4xl mb-3"></i>
      <p class="font-semibold text-gray-800 text-lg">No users found</p>
      <p class="text-gray-500 text-sm mt-1">Try adjusting your search criteria.</p>
    </div>
    <table id="table-<?= $tab ?>">
      <thead>
        <tr>
          <th style="width:36px;"><input type="checkbox" class="rounded" id="ca-<?= $tab ?>" onchange="toggleAll('<?= $tab ?>',this)"></th>
          <th onclick="sortTable('<?= $tab ?>','name')" style="cursor:pointer;">User Profile <i class="fa-solid fa-sort"></i></th>
          <th class="col-role" onclick="sortTable('<?= $tab ?>','role')" style="cursor:pointer;">Role <i class="fa-solid fa-sort"></i></th>
          <?php if ($tab === 'approved'): ?>
          <th>Status</th>
          <?php endif; ?>
          <th class="col-date" onclick="sortTable('<?= $tab ?>','date')" style="cursor:pointer;">Date Registered <i class="fa-solid fa-sort"></i></th>
          <th style="text-align:right;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($users)): ?>
        <tr><td colspan="<?= $tab === 'approved' ? 6 : 5 ?>">
          <div class="empty-state">
            <i class="fa-regular fa-folder-open"></i>
            <p class="font-semibold text-gray-500 text-sm">No <?= $tab ?> users</p>
          </div>
        </td></tr>
        <?php else: ?>
        <?php foreach ($users as $u):
          $fullname = trim($u['firstname'].' '.($u['middlename'] ? $u['middlename'].' ' : '').$u['lastname'].($u['suffix'] ? ' '.$u['suffix'] : ''));
          $date = '—';
          if (!empty($u['dateRegistered'])) { $ts = strtotime($u['dateRegistered']); if ($ts && date('Y',$ts)>1900) $date = date('F j, Y', $ts); }
          $uid   = (int)$u['userID'];
          $enc   = str_replace('"','&quot;',json_encode($u));
          $roles = explode(',', $u['account_role_csv'] ?? '');
          $rowStatus = strtolower($u['userStatus'] ?? $tab);
        ?>
        <tr data-user="<?= $enc ?>" data-date="<?= htmlspecialchars($u['dateRegistered'] ?? '') ?>" data-tab="<?= $tab ?>" data-status="<?= $rowStatus ?>">
          <td><input type="checkbox" class="row-check-<?= $tab ?> rounded" onchange="updateBulkVisibility('<?= $tab ?>')"></td>
          <td>
            <p class="font-semibold text-gray-900 text-sm"><?= htmlspecialchars($fullname) ?></p>
            <p class="text-gray-400 text-xs"><?= htmlspecialchars($u['email'] ?? '') ?></p>
          </td>
          <td class="col-role">
            <?php foreach ($roles as $r): $r=trim($r); if (!$r) continue;
              // Role strings are stored lowercase, so this match must be
              // case-insensitive or every role falls through to the
              // generic "business" chip (this previously hid the
              // Resident/Non-Resident distinction on combined-role rows,
              // e.g. after a non-resident requested the Owner role).
              $cls = match(true) {
                  stripos($r,'Non-Resident') !== false => 'chip-nonresident',
                  stripos($r,'Resident')     !== false => 'chip-resident',
                  default                                => 'chip-business',
              }; ?>
            <span class="role-chip <?= $cls ?>"><?= htmlspecialchars($r) ?></span>
            <?php endforeach; ?>
          </td>
          <?php if ($tab === 'approved'): ?>
          <td>
            <?php if ($rowStatus === 'disabled'): ?>
              <span class="status-badge status-disabled"><i class="fa-solid fa-ban mr-1 text-[9px]"></i>Disabled</span>
            <?php else: ?>
              <span class="status-badge status-approved"><i class="fa-solid fa-circle-check mr-1 text-[9px]"></i>Active</span>
            <?php endif; ?>
          </td>
          <?php endif; ?>
          <td class="text-gray-500 col-date"><?= $date ?></td>
          <td>
            <div class="flex items-center justify-end gap-2 flex-wrap">
              <button class="btn-view" onclick="openModal(this.closest('tr'))">View</button>
              <?php if ($tab === 'pending'): ?>
                <button class="btn-approve" onclick="handleAction(<?= $uid ?>,'approve',this.closest('tr'))"><i class="fa-solid fa-check text-[10px]"></i><span class="btn-label">Approve</span></button>
                <button class="btn-reject"  onclick="handleAction(<?= $uid ?>,'reject', this.closest('tr'))"><i class="fa-solid fa-xmark text-[10px]"></i><span class="btn-label">Reject</span></button>
              <?php elseif ($tab === 'approved' && $rowStatus === 'disabled'): ?>
                <button class="btn-approve" onclick="handleAction(<?= $uid ?>,'approve',this.closest('tr'))"><i class="fa-solid fa-rotate-left text-[10px]"></i><span class="btn-label">Re-enable</span></button>
              <?php elseif ($tab === 'approved'): ?>
                <button class="btn-disable" onclick="handleAction(<?= $uid ?>,'disable',this.closest('tr'))"><i class="fa-solid fa-ban text-[10px]"></i><span class="btn-label">Disable</span></button>
              <?php else: /* rejected */ ?>
                <button class="btn-revert" onclick="handleAction(<?= $uid ?>,'revert',this.closest('tr'))"><i class="fa-solid fa-rotate-left text-[10px]"></i><span class="btn-label">Set Pending</span></button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
<?php }

function renderBulkActions($tab) { ?>
  <div id="ba-<?= $tab ?>" class="hidden flex items-center justify-center gap-3 flex-wrap">
    <?php if ($tab === 'pending'): ?>
      <button onclick="bulkAction('<?= $tab ?>','approve')" class="btn-approve"><i class="fa-solid fa-check text-[10px]"></i>Approve Selected</button>
      <button onclick="bulkAction('<?= $tab ?>','reject')"  class="btn-reject"><i class="fa-solid fa-xmark text-[10px]"></i>Reject Selected</button>
    <?php elseif ($tab === 'approved'): ?>
      <button onclick="bulkAction('<?= $tab ?>','disable')" class="btn-disable"><i class="fa-solid fa-ban text-[10px]"></i>Disable Selected</button>
    <?php else: ?>
      <button onclick="bulkAction('<?= $tab ?>','revert')" class="btn-revert"><i class="fa-solid fa-rotate-left text-[10px]"></i>Set Selected as Pending</button>
    <?php endif; ?>
  </div>
<?php }
?>

<!-- ══════════ MODAL ══════════ -->
<div class="modal-overlay" id="modalOverlay" onclick="closeModalOnOverlay(event)">
  <div class="modal" id="modal">
    <div class="modal-header">
      <div class="min-w-0">
        <p class="font-bold text-gray-900 text-base">User Details</p>
        <p class="text-gray-400 text-xs mt-0.5 truncate" id="modalSubtitle">Review and verify account</p>
      </div>
      <button class="modal-close" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <div class="section-card">
        <div class="section-title"><div class="section-icon"><i class="fa-solid fa-id-card text-green-700 text-sm"></i></div>Uploaded ID</div>
        <div class="flex gap-4">
          <div class="id-placeholder" onclick="openLightbox('front')">
            <img id="frontIDImg" src="" alt="" style="display:none;">
            <div id="frontIDPlaceholder" style="display:flex;flex-direction:column;align-items:center;gap:6px;"><i class="fa-regular fa-id-card text-2xl text-gray-300"></i><span>Front ID</span><span class="text-[10px] text-gray-300">(click to zoom)</span></div>
          </div>
          <div class="id-placeholder" onclick="openLightbox('back')">
            <img id="backIDImg" src="" alt="" style="display:none;">
            <div id="backIDPlaceholder" style="display:flex;flex-direction:column;align-items:center;gap:6px;"><i class="fa-regular fa-id-card text-2xl text-gray-300"></i><span>Back ID</span><span class="text-[10px] text-gray-300">(click to zoom)</span></div>
          </div>
        </div>
      </div>
      <div class="section-card">
        <div class="section-title"><div class="section-icon"><i class="fa-solid fa-user text-green-700 text-sm"></i></div>Personal Information</div>
        <div class="space-y-3">
          <div><p class="field-label">Full Name</p><input class="field-val" id="mFullName" readonly></div>
          <div class="grid grid-cols-2 gap-3"><div><p class="field-label">Sex</p><input class="field-val" id="mGender" readonly></div><div><p class="field-label">Age</p><input class="field-val" id="mAge" readonly></div></div>
          <div><p class="field-label">Birthdate</p><input class="field-val" id="mBirthday" readonly></div>
          <div><p class="field-label">Birthplace</p><input class="field-val" id="mBirthplace" readonly></div>
          <div class="grid grid-cols-2 gap-3"><div><p class="field-label">Family Role</p><input class="field-val" id="mFamilyRole" readonly></div><div><p class="field-label">Civil Status</p><input class="field-val" id="mCivilStatus" readonly></div></div>
          <div class="grid grid-cols-2 gap-3"><div><p class="field-label">Citizenship</p><input class="field-val" id="mCitizenship" readonly></div><div><p class="field-label">Religion</p><input class="field-val" id="mReligion" readonly></div></div>
          <div><p class="field-label">Ethnicity</p><input class="field-val" id="mEthnicity" readonly></div>
        </div>
      </div>
      <div class="section-card">
        <div class="section-title"><div class="section-icon"><i class="fa-solid fa-location-dot text-green-700 text-sm"></i></div>Address</div>
        <div class="space-y-3">
          <div><p class="field-label">Complete Address</p><textarea class="field-val" id="mAddress" readonly></textarea></div>
          <div class="grid grid-cols-2 gap-3"><div><p class="field-label">Contact Number</p><input class="field-val" id="mPhone" readonly></div><div><p class="field-label">Email</p><input class="field-val" id="mEmail" readonly></div></div>
          <div class="grid grid-cols-2 gap-3"><div><p class="field-label">Emergency Contact</p><input class="field-val" id="mEmergencyContact" readonly></div><div><p class="field-label">Emergency Phone</p><input class="field-val" id="mEmergencyPhone" readonly></div></div>
        </div>
      </div>
      <div class="section-card">
        <div class="section-title"><div class="section-icon"><i class="fa-solid fa-briefcase text-green-700 text-sm"></i></div>Socio-Economic</div>
        <div class="space-y-3">
          <div><p class="field-label">Blood Type</p><input class="field-val" id="mHealth" readonly></div>
          <div class="grid grid-cols-2 gap-3"><div><p class="field-label">Employment Status</p><input class="field-val" id="mEmployment" readonly></div><div><p class="field-label">Job Title</p><input class="field-val" id="mJobTitle" readonly></div></div>
          <div><p class="field-label">Monthly Income</p><input class="field-val" id="mIncome" readonly></div>
        </div>
      </div>
      <div class="section-card">
        <div class="section-title"><div class="section-icon"><i class="fa-solid fa-house text-green-700 text-sm"></i></div>Residency</div>
        <div class="space-y-3">
          <div class="grid grid-cols-2 gap-3"><div><p class="field-label">Years as Resident</p><input class="field-val" id="mYearsResident" readonly></div><div><p class="field-label">Born in Barangay</p><input class="field-val" id="mResidentBirth" readonly></div></div>
          <div class="grid grid-cols-2 gap-3"><div><p class="field-label">Voter ID</p><input class="field-val" id="mVoterID" readonly></div><div><p class="field-label">Precinct</p><input class="field-val" id="mPrecinct" readonly></div></div>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button id="modalActionLeft"  onclick="handleModalAction('left')"><i id="modalLeftIcon"  class="fa-solid fa-xmark text-[10px]"></i><span id="modalLeftLabel">Reject</span></button>
      <button id="modalActionRight" onclick="handleModalAction('right')"><i id="modalRightIcon" class="fa-solid fa-check text-[10px]"></i><span id="modalRightLabel">Approve</span></button>
    </div>
  </div>
</div>

<!-- Lightbox -->
<div class="lightbox" id="lightbox" onclick="closeLightbox()">
  <button class="lightbox-close" onclick="closeLightbox()"><i class="fa-solid fa-xmark"></i></button>
  <img id="lightboxImg" src="" alt="ID Preview">
</div>

<!-- Confirm Dialog -->
<div class="dialog-overlay" id="dialogOverlay">
  <div class="dialog-box">
    <div class="dialog-body">
      <div class="dialog-icon-wrap" id="dialogIconWrap"><i id="dialogIconEl" class="fa-solid fa-check"></i></div>
      <p class="dialog-title" id="dialogTitle">Confirm Action</p>
      <p class="dialog-desc"  id="dialogDesc">Are you sure?</p>
      <span class="dialog-name-badge" id="dialogNameBadge" style="display:none;"></span>
    </div>
    <div class="dialog-footer">
      <button class="dialog-btn dialog-btn-cancel" onclick="closeDialog()"><i class="fa-solid fa-xmark"></i> Cancel</button>
      <button class="dialog-btn" id="dialogConfirmBtn"><i class="fa-solid fa-check" id="dialogConfirmIcon"></i><span id="dialogConfirmLabel">Confirm</span></button>
    </div>
  </div>
</div>

<script>
/* ════════════════════ SIDEBAR ════════════════════ */
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
document.querySelectorAll('[data-nav]').forEach(btn=>{btn.addEventListener('click',function(){const t=this.getAttribute('data-nav');if(t){showPageLoader('Loading page...');setTimeout(()=>window.location.href=t,180);}});});

function triggerRefresh(){showPageLoader('Refreshing users...');setTimeout(()=>location.reload(),180);}

/* ════════════════════ ALERT ════════════════════ */
let alertTimer=null;
function showToast(type,title,desc){
  const icons={success:'fa-circle-check',error:'fa-circle-xmark',warning:'fa-triangle-exclamation'};
  const typeMap={success:'alert-success',error:'alert-error',warning:'alert-warning'};
  const banner=document.getElementById('alertBanner'),inner=document.getElementById('alertInner');
  inner.className='alert-inner '+(typeMap[type]||'alert-success');
  document.getElementById('alertIcon').className='fa-solid '+(icons[type]||'fa-circle-check');
  document.getElementById('alertTitle').textContent=title;
  document.getElementById('alertDesc').textContent=desc||'';
  banner.classList.add('show');
  banner.scrollIntoView({behavior:'smooth',block:'nearest'});
  clearTimeout(alertTimer);alertTimer=setTimeout(()=>dismissAlert(),4000);
}
function dismissAlert(){document.getElementById('alertBanner').classList.remove('show');}

function showPageLoader(message='Loading...'){
  const content=document.getElementById('mainDataContainer');
  const loader=document.getElementById('realtimeLoader');
  if(content) content.style.display='none';
  if(loader){
    const txt=loader.querySelector('p');
    if(txt) txt.textContent=message;
    loader.style.display='flex';
  }
}
function hidePageLoader(){
  const content=document.getElementById('mainDataContainer');
  const loader=document.getElementById('realtimeLoader');
  if(loader) loader.style.display='none';
  if(content) content.style.display='';
}

/* ════════════════════ DIALOG ════════════════════ */
let dialogCallback=null;
function showDialog({type='approve',title,desc,nameBadge,confirmLabel,onConfirm}){
  const overlay=document.getElementById('dialogOverlay'),iconWrap=document.getElementById('dialogIconWrap'),iconEl=document.getElementById('dialogIconEl'),confirmBtn=document.getElementById('dialogConfirmBtn');
  iconWrap.className='dialog-icon-wrap';confirmBtn.className='dialog-btn';
  const cfg={
    approve: {iconCls:'dialog-icon-approve',icon:'fa-check',btn:'dialog-btn-confirm-approve'},
    reject:  {iconCls:'dialog-icon-reject', icon:'fa-xmark',btn:'dialog-btn-confirm-reject'},
    disable: {iconCls:'dialog-icon-disable',icon:'fa-ban',  btn:'dialog-btn-confirm-disable'},
    revert:  {iconCls:'dialog-icon-revert', icon:'fa-rotate-left',btn:'dialog-btn-confirm-revert'},
    bulk:    {iconCls:'dialog-icon-bulk',   icon:'fa-layer-group',btn:'dialog-btn-confirm-bulk'},
  }[type]||{iconCls:'dialog-icon-approve',icon:'fa-check',btn:'dialog-btn-confirm-approve'};
  iconWrap.classList.add(cfg.iconCls);iconEl.className='fa-solid '+cfg.icon;
  confirmBtn.classList.add(cfg.btn);
  document.getElementById('dialogConfirmIcon').className='fa-solid '+cfg.icon;
  document.getElementById('dialogTitle').textContent=title||'Confirm';
  document.getElementById('dialogDesc').textContent=desc||'Are you sure?';
  document.getElementById('dialogConfirmLabel').textContent=confirmLabel||'Confirm';
  const nb=document.getElementById('dialogNameBadge');
  if(nameBadge){nb.textContent=nameBadge;nb.style.display='inline-block';}else nb.style.display='none';
  dialogCallback=onConfirm;
  confirmBtn.onclick=()=>{closeDialog();if(dialogCallback)dialogCallback();};
  overlay.classList.add('open');document.body.style.overflow='hidden';
}
function closeDialog(){document.getElementById('dialogOverlay').classList.remove('open');document.body.style.overflow='';}
document.getElementById('dialogOverlay').addEventListener('click',function(e){if(e.target===this)closeDialog();});

/* ════════════════════ TABS ════════════════════ */
let activeTab='pending';
function switchTab(tab,btn){
  document.querySelectorAll('.tab-pane').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
  document.getElementById('tab-'+tab).classList.add('active');
  btn.classList.add('active');
  activeTab=tab;
  renderPagination(tab);
}

/* ════════════════════ SORT ════════════════════ */
const sortState={};
function sortTable(tab,column){
  if(sortState[tab]?.col===column)sortState[tab].dir=sortState[tab].dir==='asc'?'desc':'asc';
  else sortState[tab]={col:column,dir:'asc'};
  const dir=sortState[tab].dir;
  const rows=Array.from(document.querySelectorAll(`#table-${tab} tbody tr[data-user]`));
  rows.sort((a,b)=>{
    const uA=JSON.parse(a.dataset.user.replace(/&quot;/g,'"')),uB=JSON.parse(b.dataset.user.replace(/&quot;/g,'"'));
    let vA,vB;
    if(column==='name'){vA=[uA.firstname,uA.lastname].join(' ').toLowerCase();vB=[uB.firstname,uB.lastname].join(' ').toLowerCase();}
    else if(column==='role'){vA=uA.account_role_csv.toLowerCase();vB=uB.account_role_csv.toLowerCase();}
    else if(column==='date'){vA=uA.dateRegistered?new Date(uA.dateRegistered).getTime():0;vB=uB.dateRegistered?new Date(uB.dateRegistered).getTime():0;}
    if(vA<vB)return dir==='asc'?-1:1;if(vA>vB)return dir==='asc'?1:-1;return 0;
  });
  const tbody=document.querySelector(`#table-${tab} tbody`);
  rows.forEach(r=>tbody.appendChild(r));
  renderPagination(tab);
}

/* ════════════════════ SEARCH / FILTER ════════════════════ */
const searchTimers={};
function filterTab(tab){
  clearTimeout(searchTimers[tab]);
  searchTimers[tab]=setTimeout(()=>{
    setTimeout(()=>{
      const q=(document.getElementById('search-'+tab)?.value||'').toLowerCase();
      const role=(document.getElementById('fr-role-'+tab)?.value||'').toLowerCase();
      const status=(document.getElementById('fr-status-'+tab)?.value||'').toLowerCase();
      const from=document.getElementById('fr-from-'+tab)?.value||'';
      const to=document.getElementById('fr-to-'+tab)?.value||'';
      let count=0;
      document.querySelectorAll(`#table-${tab} tbody tr[data-user]`).forEach(row=>{
        const text=row.textContent.toLowerCase(),rowDate=row.dataset.date||'',rowStatus=row.dataset.status||'';
        const ok=(!q||text.includes(q))&&(!role||text.includes(role))&&(!status||rowStatus===status)&&(!from||rowDate>=from)&&(!to||rowDate<=to);
        if(ok) {
          row.dataset.filteredout = "false";
          count++;
        } else {
          row.dataset.filteredout = "true";
          row.style.display = 'none';
        }
      });
      renderPagination(tab);
      const noRes=document.getElementById('nr-'+tab),tbl=document.getElementById('table-'+tab),wrap=document.getElementById('tbl-'+tab);
      if(count===0){tbl.style.opacity='0';noRes.classList.remove('hidden');wrap.classList.add('min-h-[350px]');}
      else{tbl.style.opacity='1';noRes.classList.add('hidden');wrap.classList.remove('min-h-[350px]');}
    }, 10);
  }, 350);
}
function toggleFilter(tab){document.getElementById('fp-'+tab).classList.toggle('hidden');}

/* ════════════════════ CHECKBOX / BULK ════════════════════ */
function toggleAll(tab,cb){
  document.querySelectorAll('.row-check-'+tab).forEach(c=>c.checked=cb.checked);
  updateBulkVisibility(tab);
}
function updateBulkVisibility(tab){
  const count=document.querySelectorAll(`.row-check-${tab}:checked`).length;
  document.getElementById('ba-'+tab).classList.toggle('hidden',count<1);
}

/* ════════════════════ HELPERS ════════════════════ */
function calcAge(dob){const b=new Date(dob),n=new Date();let a=n.getFullYear()-b.getFullYear();if(n<new Date(n.getFullYear(),b.getMonth(),b.getDate()))a--;return a;}
function getUserNameFromRow(row){if(!row)return'';const u=JSON.parse(row.dataset.user.replace(/&quot;/g,'"'));return[u.firstname,u.middlename?u.middlename:'',u.lastname,u.suffix].filter(Boolean).join(' ');}

/* ════════════════════ MODAL ════════════════════ */
let currentUserID=null,currentUserRow=null,currentFrontID='',currentBackID='',currentUserTab='';

// Action configs per tab/status
const modalActions={
  pending: {
    left:  {action:'reject', label:'Reject',   icon:'fa-xmark',  leftStyle:'',rightStyle:''},
    right: {action:'approve',label:'Approve',  icon:'fa-check',  leftStyle:'',rightStyle:''},
  },
  approved_active: {
    left:  {action:'disable',label:'Disable',  icon:'fa-ban',    leftStyle:''},
    right: null,
  },
  approved_disabled: {
    left:  null,
    right: {action:'approve',label:'Re-enable',icon:'fa-rotate-left'},
  },
  rejected: {
    left:  null,
    right: {action:'revert',label:'Set Pending',icon:'fa-rotate-left'},
  },
};

function openModal(row){
  const activeBtn=document.activeElement;
  let resetViewBtn=null;
  if(activeBtn&&activeBtn.classList&&activeBtn.classList.contains('btn-view')){
    activeBtn.dataset.originalHtml=activeBtn.innerHTML;
    activeBtn.disabled=true;
    activeBtn.innerHTML='<i class="fa-solid fa-spinner fa-spin text-[10px]"></i><span class="btn-label">Loading...</span>';
    resetViewBtn=()=>{
      activeBtn.disabled=false;
      activeBtn.innerHTML=activeBtn.dataset.originalHtml||'View';
    };
  }
  const u=JSON.parse(row.dataset.user.replace(/&quot;/g,'"'));
  currentUserID=u.userID;currentUserRow=row;currentFrontID=u.frontID||'';currentBackID=u.backID||'';
  currentUserTab=row.dataset.tab||activeTab;

  const fn=[u.firstname,u.middlename?u.middlename:'',u.lastname,u.suffix].filter(Boolean).join(' ');
  document.getElementById('mFullName').value=fn;
  document.getElementById('mGender').value=u.gender||'';
  document.getElementById('mAge').value=u.birthday?calcAge(u.birthday):'';
  document.getElementById('mBirthday').value=u.birthday?new Date(u.birthday).toLocaleDateString('en-US',{month:'2-digit',day:'2-digit',year:'numeric'}):'';
  document.getElementById('mBirthplace').value=u.birthplace||'';
  document.getElementById('mFamilyRole').value=u.family_role||'';
  document.getElementById('mCivilStatus').value=u.civil_status||'';
  document.getElementById('mCitizenship').value=u.citizenship||'';
  document.getElementById('mReligion').value=u.religion||'';
  document.getElementById('mEthnicity').value=u.ethnicity||'';
  document.getElementById('mAddress').value=[u.street,u.barangay,u.city,u.province,u.zip].filter(Boolean).join(', ');
  document.getElementById('mPhone').value=u.phone||'';
  document.getElementById('mEmail').value=u.email||'';
  document.getElementById('mEmergencyContact').value=u.emergency_contact||'';
  document.getElementById('mEmergencyPhone').value=u.emergency_phone||'';
  document.getElementById('mHealth').value=u.health_conditions||'';
  document.getElementById('mEmployment').value=u.employment_status||'';
  document.getElementById('mJobTitle').value=u.job_title||'';
  document.getElementById('mIncome').value=u.monthly_income?'\u20B1 '+parseFloat(u.monthly_income).toLocaleString('en-PH',{minimumFractionDigits:2}):'';
  document.getElementById('mYearsResident').value=u.years_resident??'';
  document.getElementById('mResidentBirth').value=parseInt(u.resident_birth)?'Yes':'No';
  document.getElementById('mVoterID').value=u.voter_id||'';
  document.getElementById('mPrecinct').value=u.precinct||'';
  document.getElementById('modalSubtitle').textContent=u.email||'';

  // IDs
  const fImg=document.getElementById('frontIDImg'),bImg=document.getElementById('backIDImg');
  const fPh=document.getElementById('frontIDPlaceholder'),bPh=document.getElementById('backIDPlaceholder');
  if(u.frontID){fImg.src=resolveUserIDSrc(u.frontID);fImg.style.display='block';fPh.style.display='none';}
  else{fImg.style.display='none';fPh.style.display='flex';}
  if(u.backID){bImg.src=resolveUserIDSrc(u.backID);bImg.style.display='block';bPh.style.display='none';}
  else{bImg.style.display='none';bPh.style.display='flex';}

  // Configure footer buttons
  const rowStatus=(row.dataset.status||'').toLowerCase();
  let cfgKey=currentUserTab;
  if(currentUserTab==='approved')cfgKey=rowStatus==='disabled'?'approved_disabled':'approved_active';
  const cfg=modalActions[cfgKey]||modalActions.pending;

  const btnL=document.getElementById('modalActionLeft'),btnR=document.getElementById('modalActionRight');
  const footer=document.querySelector('.modal-footer');
  footer.style.gridTemplateColumns=(cfg.left&&cfg.right)?'1fr 1fr':'1fr';

  if(cfg.left){
    btnL.style.display='';
    btnL.style.background='';
    btnL.style.color='';
    btnL.style.border='';
    document.getElementById('modalLeftIcon').className='fa-solid '+cfg.left.icon+' text-[10px]';
    document.getElementById('modalLeftLabel').textContent=cfg.left.label;
    btnL.dataset.modalAction='left';
    btnL.className='btn-modal-'+(cfg.left.action==='disable'?'disable':cfg.left.action==='reject'?'reject':'approve');
  } else {
    btnL.style.display='none';
    btnL.style.background='';
    btnL.style.color='';
    btnL.style.border='';
    btnL.className='';
  }

  if(cfg.right){
    btnR.style.display='';
    btnR.style.background='';
    btnR.style.color='';
    btnR.style.border='';
    document.getElementById('modalRightIcon').className='fa-solid '+cfg.right.icon+' text-[10px]';
    document.getElementById('modalRightLabel').textContent=cfg.right.label;
    btnR.dataset.modalAction='right';
    btnR.className='btn-modal-'+(cfg.right.action==='approve'?'approve':cfg.right.action==='revert'?'revert':cfg.right.action==='reject'?'reject':'disable');
  } else {
    btnR.style.display='none';
    btnR.style.background='';
    btnR.style.color='';
    btnR.style.border='';
    btnR.className='';
  }

  document.getElementById('modalOverlay').classList.add('open');
  document.body.style.overflow='hidden';
  if(resetViewBtn)setTimeout(resetViewBtn,300);
}

function closeModal(){document.getElementById('modalOverlay').classList.remove('open');document.body.style.overflow='';currentUserID=null;currentUserRow=null;}
function closeModalOnOverlay(e){if(e.target===document.getElementById('modalOverlay'))closeModal();}

function handleModalAction(side){
  if(!currentUserID)return;
  const row=currentUserRow,rowStatus=(row?.dataset.status||'').toLowerCase();
  let cfgKey=currentUserTab;
  if(currentUserTab==='approved')cfgKey=rowStatus==='disabled'?'approved_disabled':'approved_active';
  const cfg=modalActions[cfgKey]||modalActions.pending;
  const btnCfg=side==='left'?cfg.left:cfg.right;
  if(!btnCfg)return;
  const userID=currentUserID,userName=getUserNameFromRow(row);
  // Set loading on modal buttons
  const btnL=document.getElementById('modalActionLeft'),btnR=document.getElementById('modalActionRight');
  [btnL,btnR].forEach(btn=>{
    if(btn&&btn.style.display!=='none'){
      btn.dataset.originalHtml=btn.innerHTML;
      btn.disabled=true;
      btn.innerHTML='<i class="fa-solid fa-spinner fa-spin text-[10px]"></i> Loading...';
    }
  });
  closeModal();
  handleAction(userID,btnCfg.action,row,userName);
}

/* ════════════════════ CORE ACTION ════════════════════ */
function executeAction(userID,action,row){
  if(row){
    const buttons = row.querySelectorAll('button');
    buttons.forEach(btn => {
      btn.dataset.originalHtml = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-[10px]"></i><span class="btn-label">Loading...</span>';
    });
  }
  return fetch('userAction.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`userID=${encodeURIComponent(userID)}&action=${encodeURIComponent(action)}`})
  .then(r=>r.json())
  .then(data=>{
    if(data.success){
      // Handle row movement between tabs
      if(row){
        const tab=row.dataset.tab;
        if(action==='approve'&&tab==='pending'){
          // move to approved tab (re-render on refresh is simplest; for now just remove)
          row.remove();
          updateBadge('pending',-1);updateBadge('approved',1);
        } else if(action==='reject'&&tab==='pending'){
          row.remove();
          updateBadge('pending',-1);updateBadge('rejected',1);
        } else if(action==='disable'&&tab==='approved'){
          // update status badge in row
          row.dataset.status='disabled';
          const statusTd=row.querySelector('.status-badge');
          if(statusTd){statusTd.className='status-badge status-disabled';statusTd.innerHTML='<i class="fa-solid fa-ban mr-1 text-[9px]"></i>Disabled';}
          // swap button
          const actionDiv=row.querySelector('.flex.items-center.justify-end');
          if(actionDiv){
            const disBtn=actionDiv.querySelector('.btn-disable');
            if(disBtn){
              disBtn.className='btn-approve';
              disBtn.onclick=()=>handleAction(userID,'approve',row);
              disBtn.innerHTML='<i class="fa-solid fa-rotate-left text-[10px]"></i><span class="btn-label">Re-enable</span>';
            }
          }
        } else if(action==='approve'&&tab==='approved'){
          // re-enable disabled user
          row.dataset.status='approved';
          const statusTd=row.querySelector('.status-badge');
          if(statusTd){statusTd.className='status-badge status-approved';statusTd.innerHTML='<i class="fa-solid fa-circle-check mr-1 text-[9px]"></i>Active';}
          const actionDiv=row.querySelector('.flex.items-center.justify-end');
          if(actionDiv){
            const reBtn=actionDiv.querySelector('.btn-approve');
            if(reBtn){
              reBtn.className='btn-disable';
              reBtn.onclick=()=>handleAction(userID,'disable',row);
              reBtn.innerHTML='<i class="fa-solid fa-ban text-[10px]"></i><span class="btn-label">Disable</span>';
            }
          }
        } else if(action==='revert'&&tab==='rejected'){
          row.remove();
          updateBadge('rejected',-1);updateBadge('pending',1);
        }
        renderPagination(tab);
        updateBulkVisibility(tab);
      }
      return{success:true};
    }
    return{success:false,message:data.message||`Failed to ${action} user.`};
  })
  .catch(()=>({success:false,message:'Network error. Please try again.'}))
  .finally(() => {
    if(row){
      const buttons = row.querySelectorAll('button');
      buttons.forEach(btn => {
        btn.disabled = false;
        btn.innerHTML = btn.dataset.originalHtml;
      });
    }
  });
}

function updateBadge(tab,delta){
  const el=document.getElementById('badge-'+tab);
  if(el)el.textContent=Math.max(0,parseInt(el.textContent||'0')+delta);
}

function handleAction(userID,action,row,userName){
  userName=userName||getUserNameFromRow(row);
  const labels={approve:{type:'approve',title:'Approve Account',desc:'This will grant the user portal access.',confirm:'Yes, Approve'},reject:{type:'reject',title:'Reject Account',desc:"This will deny the user's registration.",confirm:'Yes, Reject'},disable:{type:'disable',title:'Disable Account',desc:'This will suspend the user from accessing the portal.',confirm:'Yes, Disable'},revert:{type:'revert',title:'Set as Pending',desc:"This will move the user back to pending review.",confirm:'Yes, Set Pending'}};
  const cfg=labels[action]||labels.approve;
  showDialog({...cfg,nameBadge:userName||null,confirmLabel:cfg.confirm,onConfirm:()=>{
    showPageLoader('Processing action...');
    executeAction(userID,action,row).then(result=>{
      const toastMap={approve:{t:'success',title:'Account Approved!',desc:`${userName||'User'} has been approved.`},reject:{t:'warning',title:'Account Rejected',desc:`${userName||'User'}'s registration was rejected.`},disable:{t:'warning',title:'Account Disabled',desc:`${userName||'User'}'s account has been disabled.`},revert:{t:'success',title:'Set as Pending',desc:`${userName||'User'} is back in the pending queue.`}};
      const tm=toastMap[action];
      if(result.success)showToast(tm.t,tm.title,tm.desc);
      else showToast('error','Action Failed',result.message);
    }).finally(hidePageLoader);
  }});
}

/* ════════════════════ BULK ════════════════════ */
function bulkAction(tab,action){
  const selectedRows=Array.from(document.querySelectorAll(`.row-check-${tab}:checked`)).map(cb=>cb.closest('tr'));
  if(!selectedRows.length){showToast('warning','No Users Selected','Please select at least one user.');return;}
  const count=selectedRows.length;
  showDialog({type:'bulk',title:`${action.charAt(0).toUpperCase()+action.slice(1)} ${count} Account${count>1?'s':''}`,desc:`You are about to ${action} ${count} selected user${count>1?'s':''}.`,confirmLabel:`${action.charAt(0).toUpperCase()+action.slice(1)} All ${count}`,
    onConfirm:async()=>{
      showPageLoader('Processing selected users...');
      const bulkBtn = document.querySelector(`#ba-${tab} button`);
      if(bulkBtn){
        bulkBtn.dataset.originalHtml = bulkBtn.innerHTML;
        bulkBtn.disabled = true;
        bulkBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-[10px]"></i> Processing...';
      }
      let ok=0;
      await Promise.all(selectedRows.map(row=>{
        const u=JSON.parse(row.dataset.user.replace(/&quot;/g,'"'));
        return executeAction(u.userID,action,row).then(r=>{if(r.success)ok++;});
      })).finally(()=>{
        hidePageLoader();
      });
      document.getElementById('ca-'+tab).checked=false;
      updateBulkVisibility(tab);
      showToast(ok===count?'success':'warning',`${ok} of ${count} Processed`,`${ok} user${ok>1?'s':''} ${action}d successfully.`);
      if(bulkBtn){
        bulkBtn.disabled = false;
        bulkBtn.innerHTML = bulkBtn.dataset.originalHtml;
      }
    }
  });
}

/* ════════════════════ LIGHTBOX ════════════════════ */
function resolveUserIDSrc(src){
  if(!src) return '';
  src = src.trim();
  if(src.startsWith('../uploads/id_verification/')) return src;
  if(src.startsWith('./uploads/id_verification/')) return src.replace('./', '../');
  if(src.startsWith('/uploads/id_verification/')) return '../' + src.slice(1);
  if(src.startsWith('uploads/id_verification/')) return '../' + src;
  return '../uploads/id_verification/' + src;
}
function openLightbox(side){const src=side==='front'?currentFrontID:currentBackID;if(!src)return;document.getElementById('lightboxImg').src=resolveUserIDSrc(src);document.getElementById('lightbox').classList.add('open');}
function closeLightbox(){document.getElementById('lightbox').classList.remove('open');}

/* ════════════════════ PAGINATION ════════════════════ */
const ROWS=10,pageState={pending:1,approved:1,rejected:1};
function getVisibleRows(tab){return Array.from(document.querySelectorAll(`#table-${tab} tbody tr[data-user]`)).filter(r=>r.dataset.filteredout!=='true');}
function renderPagination(tab){
  const rows=getVisibleRows(tab),total=rows.length,pages=Math.max(1,Math.ceil(total/ROWS));
  if(pageState[tab]>pages)pageState[tab]=pages;
  rows.forEach((r,i)=>{r.style.display=(Math.floor(i/ROWS)+1===pageState[tab])?'':'none';});
  const c=document.getElementById('pagination-'+tab);if(!c)return;c.innerHTML='';
  const prev=document.createElement('button');prev.className='page-btn';prev.disabled=pageState[tab]===1;prev.innerHTML='<i class="fa-solid fa-chevron-left text-xs"></i>';prev.addEventListener('click',()=>{pageState[tab]--;renderPagination(tab);});c.appendChild(prev);
  let s=Math.max(1,pageState[tab]-2),e=Math.min(pages,s+4);if(e-s<4)s=Math.max(1,e-4);
  for(let p=s;p<=e;p++){const b=document.createElement('button');b.className='page-btn'+(p===pageState[tab]?' active':'');b.textContent=p;b.addEventListener('click',()=>{pageState[tab]=p;renderPagination(tab);});c.appendChild(b);}
  const next=document.createElement('button');next.className='page-btn';next.disabled=pageState[tab]===pages;next.innerHTML='<i class="fa-solid fa-chevron-right text-xs"></i>';next.addEventListener('click',()=>{pageState[tab]++;renderPagination(tab);});c.appendChild(next);
}
// Initial render
['pending','approved','rejected'].forEach(tab=>renderPagination(tab));
</script>
</body>
</html>