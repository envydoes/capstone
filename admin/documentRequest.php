<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
$role = $_SESSION['account_role'] ?? '';
require_once __DIR__ . '/../includes/check_permissions.php';
$host="o7jpqmin0zgconui4xtnfju6"; $user="root"; $password="UKkJ05DHQDMMMOxFEUI5f1HJGVj8Vb5gfJAEvAESTGCVWDtFEGb42qX67AxGUXvj"; $database="sumeste_db";
$conn = mysqli_connect($host,$user,$password,$database);
if (!$conn) { session_unset(); session_destroy(); die("Connection failed: ".mysqli_connect_error()); }
$myPerms = get_my_permissions($conn);
if ($role !== 'admin' && empty($myPerms)) {
    switch ($role) {
        case 'resident': case 'resident,business/apartment owner': header('Location: ../resident/residentLanding.php'); break;
        case 'non-resident': case 'non-resident,business/apartment owner': header('Location: ../nonresident/nonresidentLanding.php'); break;
        default: header('Location: ../landing.php');
    }
    exit;
}
ob_start();

require_once '../includes/site_config.php';
$siteSettings = site_config_load($conn);
require_permission($conn, 'manage_documents');
$sql = "
    SELECT
        r.id,
        r.userId,
        r.document_type,
        r.num_copies,
        r.purpose,
        r.notes,
        r.uploaded_files,
        r.status,
        r.submitted_at,
        u.firstname, u.lastname, u.middlename, u.suffix, u.email,
        u.gender, u.birthday, u.emergency_contact, u.emergency_phone,
        u.health_conditions, u.employment_status, u.monthly_income,
        u.years_resident, u.resident_birth, u.frontID, u.backID
    FROM tbl_requestDocs r
    LEFT JOIN tbl_userinfo u ON r.userId = u.userID
    ORDER BY r.submitted_at DESC
";
$result = mysqli_query($conn, $sql);
$all_docs = [];
if ($result) { while ($row = mysqli_fetch_assoc($result)) { $all_docs[] = $row; } mysqli_free_result($result); }
$count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM tbl_requestDocs WHERE LOWER(status)='pending'");
$count_row = mysqli_fetch_assoc($count_result);
$total_pending = $count_row['total'] ?? 0;

// â”€â”€ Stat cards: Document Requests Overview â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

// Requests This Month, plus Today as a sub-stat
$reqCountRow = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        SUM(CASE WHEN COALESCE(submitted_at, created_at) >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN 1 ELSE 0 END) AS this_month,
        SUM(CASE WHEN DATE(COALESCE(submitted_at, created_at)) = CURDATE() THEN 1 ELSE 0 END) AS today
    FROM tbl_requestDocs
"));
$reqThisMonth = (int) ($reqCountRow['this_month'] ?? 0);
$reqToday     = (int) ($reqCountRow['today'] ?? 0);

// Average Turnaround Time: requested -> released.
// This schema has no separate "released" status (only pending/approved/
// rejected), so 'approved' is treated as the release point. updated_at
// auto-stamps on any row change, so a later edit to an approved row would
// nudge this up slightly.
$avgTurnRow = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT AVG(TIMESTAMPDIFF(HOUR, COALESCE(submitted_at, created_at), updated_at)) AS avg_hours
    FROM tbl_requestDocs
    WHERE LOWER(status) = 'approved'
"));
$avgTurnaroundHours = ($avgTurnRow && $avgTurnRow['avg_hours'] !== null)
    ? round((float) $avgTurnRow['avg_hours'], 1)
    : null;

// Repeat Requesters: residents who've filed 2+ requests (any status)
$repeatRow = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(*) AS total FROM (
        SELECT userId FROM tbl_requestDocs GROUP BY userId HAVING COUNT(*) >= 2
    ) t
"));
$repeatRequesters = (int) ($repeatRow['total'] ?? 0);

// Peak Request Day: day of week with the most submissions, all-time
$peakDayRow = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT DAYNAME(COALESCE(submitted_at, created_at)) AS day_name, COUNT(*) AS total
    FROM tbl_requestDocs
    GROUP BY day_name
    ORDER BY total DESC
    LIMIT 1
"));
$peakDayName  = $peakDayRow['day_name'] ?? null;
$peakDayTotal = (int) ($peakDayRow['total'] ?? 0);

mysqli_close($conn);

function prettyDocumentType($type) {
    $map = [
        'barangay_clearance'    => 'Barangay Clearance',
        'brangay_clearance'     => 'Barangay Clearance',
        'certificate_indigency' => 'Certificate of Indigency',
        'indigency'             => 'Certificate of Indigency',
        'certificate_residency' => 'Certificate of Residency',
        'residency'             => 'Certificate of Residency',
        'business_permit'       => 'Barangay Business Permit',
        'id'                    => 'Barangay ID',
        'jobseeker'             => 'Jobseeker Certificate',
        'first_time_jobseeker'  => 'First-Time Jobseeker',
    ];
    $lower = strtolower(trim((string)$type));
    return $map[$lower] ?? ucwords(str_replace(['_','-'],' ',$lower));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="../assets/responsive-global.css">
  <title>Document Request â€” <?= e($siteSettings['site_title']) ?></title>
  <link rel="icon" href="<?= e(site_config_logo_url($siteSettings, '../')) ?>" type="image/png">
  <?= site_config_css_vars($siteSettings) ?>
  <script src="https://cdn.tailwindcss.com/3.4.16"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    *{box-sizing:border-box}body { font-family: 'DM Sans', sans-serif; background: var(--site-primary-pale); margin: 0; }
    :root {
  --site-primary-dark:   color-mix(in srgb, var(--site-primary) 55%, black);
  --site-primary-darker: color-mix(in srgb, var(--site-primary) 75%, black);
  --site-primary-light:  color-mix(in srgb, var(--site-primary) 55%, white);
  --site-primary-pale:   color-mix(in srgb, var(--site-primary) 12%, white);
}
    .sidebar{
      width:260px;flex-shrink:0;
      background: linear-gradient(180deg, var(--site-primary-dark) 0%, var(--site-primary-darker) 55%, var(--site-primary) 100%);
    display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;z-index:300;overflow:hidden;transition:width .3s cubic-bezier(.4,0,.2,1),transform .3s cubic-bezier(.4,0,.2,1)}
    .sidebar.collapsed{width:0}.sidebar:not(.collapsed){overflow-y:auto}.sidebar::-webkit-scrollbar{width:4px}.sidebar::-webkit-scrollbar-thumb{background:rgba(134,239,172,.2);border-radius:4px}
    .sidebar-inner{width:260px;min-width:260px;display:flex;flex-direction:column;height:100%}
    .sidebar-logo{padding:20px 18px 16px;border-bottom:1px solid rgba(134,239,172,.12);display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
    .section-label { padding:18px 18px 6px; font-size:.62rem; font-weight:700; letter-spacing:.13em; text-transform:uppercase; color:rgba(255,255,255,0.4); white-space:nowrap; }
    .menu-item{display:flex;align-items:center;justify-content:space-between;width:calc(100% - 16px);padding:10px 14px;margin:1px 8px;border-radius:10px;color:rgba(255,255,255,.72);font-size:.84rem;font-weight:500;text-decoration:none;border:none;background:none;text-align:left;cursor:pointer;transition:background .18s,color .18s;white-space:nowrap}
    .menu-item:hover{background:rgba(255,255,255,.07);color:#fff}.menu-item.active{background:rgba(255,255,255,.13);color:#fff}
    .menu-left{display:flex;align-items:center;gap:11px}.menu-item .mi{width:17px;text-align:center;font-size:.85rem;flex-shrink:0}
    .active-dot{width:7px;height:7px;border-radius:50%;background:var(--site-primary-light);flex-shrink:0}
    .collapse-btn{width:28px;height:28px;border-radius:8px;background:rgba(255,255,255,.1);border:none;cursor:pointer;color:#fff;font-size:.72rem;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .2s}
    .collapse-btn:hover{background:rgba(255,255,255,.22)}
    .expand-btn{position:fixed;top:18px;left:12px;z-index:200;width:36px;height:36px;border-radius:10px;background:var(--site-primary-darker);border:1px solid rgba(134,239,172,.25);color:#fff;font-size:.82rem;cursor:pointer;display:none;align-items:center;justify-content:center;box-shadow:0 4px 16px rgba(5,46,22,.4);transition:background .2s}
    .expand-btn.visible{display:flex}.expand-btn:hover{background:var(--site-primary)}
    .sidebar-backdrop{display:none;position:fixed;inset:0;z-index:250;background:rgba(5,46,22,.5);backdrop-filter:blur(2px)}.sidebar-backdrop.visible{display:block}
    .sidebar-bottom{margin-top:auto;flex-shrink:0}.sidebar-bottom-links{padding:0 16px 8px}
    .sidebar-bottom-links .side-link{display:block;width:100%;font-size:.84rem;padding:8px 8px;border-radius:8px;transition:color .15s,background .15s;text-decoration:none;white-space:nowrap;border:none;background:none;text-align:left;cursor:pointer}
    .main-wrapper{display:flex;min-height:100vh}
    .main-content{flex:1;min-width:0;display:flex;flex-direction:column;width:calc(100% - 260px);margin-left:260px;transition:margin-left .3s cubic-bezier(.4,0,.2,1);overflow-x:hidden}
    .main-content.sidebar-collapsed{width:100%;margin-left:0}
    .topbar{background:#fff;border-bottom:1px solid #e5e7eb;padding:14px 28px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;position:sticky;top:0;z-index:100}
    .stat-card{background:#fff;border-radius:14px;padding:20px 22px;border:1px solid #e5e7eb;box-shadow:0 2px 12px rgba(21,128,61,.05);display:flex;flex-direction:column;gap:10px;transition:transform .2s,box-shadow .2s}
    .stat-card:hover{transform:translateY(-3px);box-shadow:0 8px 24px rgba(21,128,61,.1)}
    .stat-label{font-size:.82rem;font-weight:600;color:#6b7280}
    .stat-row{display:flex;align-items:center;gap:14px}
    .stat-ico{font-size:1.6rem}
    .stat-num{font-size:2.4rem;font-weight:800;color:#111827;line-height:1}
    .stat-sub{font-size:.75rem;font-weight:600;color:#9ca3af}
    .stat-trend{display:inline-flex;align-items:center;gap:4px;font-size:.78rem;font-weight:700}
    .stat-trend-up{color:#15803d}
    .stat-trend-down{color:#dc2626}
    .stat-trend-flat{color:#9ca3af}
    .tbl-wrap{background:#fff;border-radius:14px;border:1px solid #e5e7eb;box-shadow:0 2px 12px rgba(21,128,61,.05);overflow-x:auto}
    table{width:100%;border-collapse:collapse;min-width:560px}
    thead th{background:#f9fafb;padding:11px 16px;text-align:left;font-size:.75rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.06em;border-bottom:1px solid #e5e7eb;white-space:nowrap}
    tbody tr{border-bottom:1px solid #f3f4f6;transition:background .15s}tbody tr:last-child{border-bottom:none}tbody tr:hover{background:#f0fdf4}
    tbody td{padding:14px 16px;font-size:.84rem;color:#374151;vertical-align:middle}
    .doc-chip{display:inline-flex;align-items:center;padding:3px 10px;border-radius:999px;font-size:.68rem;font-weight:700;white-space:nowrap}
    .chip-clearance{background:#dcfce7;color:#15803d}.chip-residency{background:#dbeafe;color:#1d4ed8}.chip-indigency{background:#fef9c3;color:#a16207}.chip-id{background:#fce7f3;color:#9d174d}.chip-jobseeker{background:#ede9fe;color:#6d28d9}.chip-default{background:#f3f4f6;color:#374151}
    .status-badge{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;font-size:.7rem;font-weight:700;white-space:nowrap;text-transform:uppercase;letter-spacing:.02em}
    .status-pending{background:#fef3c7;color:#d97706}.status-approved{background:#dcfce7;color:#15803d}.status-rejected{background:#fee2e2;color:#dc2626}
    .btn-view{font-size:.78rem;font-weight:600;color:#374151;text-decoration:underline;cursor:pointer;background:none;border:none;padding:0;white-space:nowrap}.btn-view:hover{color:#15803d}
    .btn-approve{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:8px;border:1.5px solid #16a34a;color:#15803d;background:#f0fdf4;font-size:.75rem;font-weight:700;cursor:pointer;transition:all .15s;white-space:nowrap}.btn-approve:hover{background:#16a34a;color:#fff}
    .btn-reject{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:8px;border:1.5px solid #ef4444;color:#dc2626;background:#fef2f2;font-size:.75rem;font-weight:700;cursor:pointer;transition:all .15s;white-space:nowrap}.btn-reject:hover{background:#ef4444;color:#fff}
    .bulk-toolbar{display:flex;align-items:center;gap:10px;padding:8px 16px;background:#f9fafb;border-bottom:1px solid #e5e7eb;border-radius:14px 14px 0 0}
    .bulk-toolbar-divider{width:1px;height:20px;background:#e5e7eb}
    .search-box { display:flex; align-items:center; gap:8px; border:1.5px solid #e5e7eb; border-radius:9px; padding:7px 12px; background:#fff; transition:border-color 0.15s; }
    .search-box:focus-within { border-color:var(--site-primary); }
    .search-box input { border:none; outline:none; font-size:0.83rem; color:#374151; font-family:inherit; width:100%; background:transparent; }
    .btn-filter{display:inline-flex;align-items:center;gap:6px;padding:7px 16px;border-radius:9px;border:1.5px solid #e5e7eb;background:#fff;font-size:.83rem;font-weight:600;color:#374151;cursor:pointer;transition:all .15s;white-space:nowrap}
    .btn-filter:hover{border-color:var(--site-primary);color:var(--site-primary)}
    .btn-refresh{width:30px;height:30px;border-radius:8px;border:1.5px solid #e5e7eb;background:#fff;color:#6b7280;font-size:.82rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s;flex-shrink:0}
    .btn-refresh:hover{border-color:var(--site-primary);color:var(--site-primary)}
    .page-btn{width:34px;height:34px;border-radius:8px;border:1.5px solid #e5e7eb;background:#fff;font-size:.82rem;font-weight:600;color:#374151;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s;flex-shrink:0}.page-btn:hover{border-color:var(--site-primary);color:var(--site-primary)}.page-btn.active{background:var(--site-primary);border-color:var(--site-primary);color:#fff}.page-btn:disabled{opacity:.35;cursor:default}
    .status-pill{padding:5px 14px;border-radius:999px;border:1.5px solid #e5e7eb;background:#fff;font-size:.75rem;font-weight:700;color:#6b7280;cursor:pointer;transition:all .15s;white-space:nowrap}
    .status-pill:hover{border-color:#16a34a;color:#15803d}
    .status-pill.active-pill{background:#6b7280;border-color:#6b7280;color:#fff}
    .status-pill[data-status="pending"].active-pill{background:#d97706;border-color:#d97706;color:#fff}
    .status-pill[data-status="approved"].active-pill{background:#15803d;border-color:#15803d;color:#fff}
    .status-pill[data-status="rejected"].active-pill{background:#dc2626;border-color:#dc2626;color:#fff}
    /* Modal */
    .modal-overlay{position:fixed;inset:0;z-index:500;background:rgba(5,46,22,.45);backdrop-filter:blur(3px);display:flex;align-items:flex-start;justify-content:center;padding:16px;overflow-y:auto;opacity:0;pointer-events:none;transition:opacity .22s}.modal-overlay.open{opacity:1;pointer-events:auto}
    .modal{background:#fff;border-radius:18px;width:100%;max-width:640px;max-height:calc(100vh - 32px);display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(5,46,22,.25);transform:translateY(16px);transition:transform .25s cubic-bezier(.4,0,.2,1);margin:auto}.modal-overlay.open .modal{transform:translateY(0)}
    .modal-header{padding:18px 20px 14px;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:#fff;border-radius:18px 18px 0 0;z-index:10;flex-shrink:0}
    .modal-close{width:30px;height:30px;border-radius:8px;border:none;background:#f3f4f6;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#6b7280;font-size:.8rem;transition:background .15s,color .15s;flex-shrink:0}.modal-close:hover{background:#fee2e2;color:#dc2626}
    .modal-body{padding:18px 20px;overflow-y:auto;flex:1;min-height:0}
    .modal-footer{display:grid;grid-template-columns:1fr 1fr;border-top:1px solid #f3f4f6;position:sticky;bottom:0;background:#fff;border-radius:0 0 18px 18px;overflow:hidden;min-height:56px;z-index:20;flex-shrink:0}
    .modal-footer button{width:100%;margin:0;border:none;border-radius:0;padding:14px;font-size:.88rem;font-weight:700;display:inline-flex;align-items:center;justify-content:center;gap:6px;transition:all .15s;cursor:pointer;font-family:inherit}
    #modalRejectBtn{background:#f9fafb;color:#374151;border-right:1px solid #f3f4f6}#modalRejectBtn:hover{background:#fee2e2;color:#dc2626}
    #modalApproveBtn{background:#15803d;color:#fff}#modalApproveBtn:hover{background:#166534}
    .section-title{display:flex;align-items:center;gap:8px;font-size:.8rem;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.07em;margin-bottom:12px}
    .section-icon{width:26px;height:26px;background:#dcfce7;border-radius:7px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .section-card{background:#f9fafb;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:16px}.section-card:last-child{margin-bottom:0}
    .field-label{font-size:.72rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px}
    .field-val{width:100%;padding:9px 12px;border:1.5px solid #e5e7eb;border-radius:9px;font-size:.84rem;color:#374151;background:#f9fafb;font-family:inherit;outline:none}
    textarea.field-val{resize:vertical;min-height:72px}
    /* Supporting docs file items */
    .sdoc-item{display:flex;align-items:center;gap:10px;padding:10px 12px;background:#fff;border:1.5px solid #e5e7eb;border-radius:9px;margin-bottom:8px;transition:background .12s}.sdoc-item:last-child{margin-bottom:0}.sdoc-item:hover{background:#f9fafb}
    .sdoc-icon{width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .sdoc-icon-img{background:#dcfce7}.sdoc-icon-pdf{background:#fee2e2}.sdoc-icon-file{background:#f3f4f6}
    .sdoc-name{font-size:.82rem;font-weight:600;color:#374151;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .sdoc-actions{display:flex;align-items:center;gap:6px;flex-shrink:0}
    .sdoc-btn{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:6px;font-size:.72rem;font-weight:700;text-decoration:none;cursor:pointer;border:1.5px solid;transition:all .15s;white-space:nowrap}
    .sdoc-btn-view{border-color:#d1d5db;color:#374151;background:#fff}.sdoc-btn-view:hover{border-color:#16a34a;color:#15803d;background:#f0fdf4}
    .sdoc-btn-zoom{border-color:#d1d5db;color:#374151;background:#fff}.sdoc-btn-zoom:hover{border-color:#2563eb;color:#2563eb;background:#eff6ff}
    .sdoc-btn-dl{border-color:#d1d5db;color:#374151;background:#fff}.sdoc-btn-dl:hover{border-color:#7c3aed;color:#7c3aed;background:#f5f3ff}
    /* Thumbnail preview strip for images */
    .sdoc-thumb{width:36px;height:36px;border-radius:6px;object-fit:cover;border:1px solid #e5e7eb;cursor:pointer;transition:opacity .15s}.sdoc-thumb:hover{opacity:.85}
    /* ID placeholders */
    .id-placeholder{flex:1;aspect-ratio:4/3;border:1.5px dashed #d1d5db;border-radius:10px;background:#f9fafb;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#9ca3af;font-size:.72rem;gap:6px;cursor:pointer;transition:border-color .15s,background .15s;position:relative;overflow:hidden;min-width:0}.id-placeholder:hover{border-color:#16a34a;background:#f0fdf4}
    .id-placeholder img{width:100%;height:100%;object-fit:cover;position:absolute;inset:0;border-radius:9px}
    /* Lightbox */
    .lightbox{position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.85);display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .2s;padding:16px}.lightbox.open{opacity:1;pointer-events:auto}
    .lightbox-inner{position:relative;display:flex;flex-direction:column;align-items:center;max-width:92vw;max-height:92vh}
    .lightbox-inner img{max-width:100%;max-height:80vh;border-radius:10px;box-shadow:0 8px 40px rgba(0,0,0,.6);display:block}
    .lightbox-close{position:absolute;top:-14px;right:-14px;background:rgba(255,255,255,.15);border:1.5px solid rgba(255,255,255,.2);color:#fff;font-size:1rem;width:34px;height:34px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .15s;flex-shrink:0}.lightbox-close:hover{background:rgba(239,68,68,.6)}
    .lightbox-caption{margin-top:10px;font-size:.78rem;color:rgba(255,255,255,.6);text-align:center;max-width:80vw;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .empty-state{padding:60px 24px;text-align:center;color:#9ca3af}.empty-state i{font-size:2.5rem;margin-bottom:12px;display:block;color:#d1d5db}
    /* Dialog */
    .dialog-overlay{position:fixed;inset:0;z-index:900;background:rgba(5,46,22,.5);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .2s}.dialog-overlay.open{opacity:1;pointer-events:auto}
    .dialog-box{background:#fff;border-radius:20px;width:100%;max-width:420px;box-shadow:0 24px 64px rgba(5,46,22,.3),0 4px 16px rgba(0,0,0,.08);transform:scale(.94) translateY(12px);transition:transform .25s cubic-bezier(.34,1.56,.64,1),opacity .2s;opacity:0;overflow:hidden}.dialog-overlay.open .dialog-box{transform:scale(1) translateY(0);opacity:1}
    .dialog-icon-wrap{width:64px;height:64px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:1.6rem}
    .dialog-icon-approve{background:#dcfce7;color:#16a34a}.dialog-icon-reject{background:#fee2e2;color:#dc2626}.dialog-icon-bulk{background:#fef9c3;color:#a16207}
    .dialog-body{padding:28px 24px 20px;text-align:center}
    .dialog-title{font-size:1.1rem;font-weight:800;color:#111827;margin-bottom:8px;font-family:'Playfair Display',serif}
    .dialog-desc{font-size:.84rem;color:#6b7280;line-height:1.5}
    .dialog-name-badge{display:inline-block;margin-top:10px;background:#f3f4f6;border-radius:8px;padding:6px 14px;font-size:.82rem;font-weight:700;color:#374151}
    .dialog-footer{padding:0 20px 20px;display:flex;gap:10px}
    .dialog-btn{flex:1;padding:11px;border-radius:11px;border:none;font-size:.86rem;font-weight:700;cursor:pointer;font-family:inherit;transition:all .15s;display:flex;align-items:center;justify-content:center;gap:6px}
    .dialog-btn-cancel{background:#f3f4f6;color:#374151}.dialog-btn-cancel:hover{background:#e5e7eb}
    .dialog-btn-confirm-approve{background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;box-shadow:0 4px 14px rgba(22,163,74,.35)}.dialog-btn-confirm-approve:hover{transform:translateY(-1px)}
    .dialog-btn-confirm-reject{background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;box-shadow:0 4px 14px rgba(239,68,68,.35)}.dialog-btn-confirm-reject:hover{transform:translateY(-1px)}
    .dialog-btn-confirm-bulk{background:linear-gradient(135deg,#ca8a04,#a16207);color:#fff;box-shadow:0 4px 14px rgba(202,138,4,.35)}.dialog-btn-confirm-bulk:hover{transform:translateY(-1px)}
    #alertBanner{display:none;border-radius:10px;margin-bottom:4px}#alertBanner.show{display:flex}
    .alert-inner{display:flex;align-items:center;gap:10px;padding:13px 16px;font-size:.85rem;font-weight:600;border-radius:10px;border:1.5px solid transparent;width:100%;flex-wrap:wrap}
    .alert-success{background:#f0fdf4;border-color:#bbf7d0;color:#15803d}.alert-error{background:#fef2f2;border-color:#fecaca;color:#dc2626}.alert-warning{background:#fefce8;border-color:#fde68a;color:#a16207}
    .alert-close{margin-left:auto;background:none;border:none;cursor:pointer;font-size:.8rem;opacity:.6;color:inherit;padding:2px 4px}.alert-close:hover{opacity:1}
    #tableLoader{transition:opacity .2s}
    @keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}.fade-up{animation:fadeUp .35s ease both}
    @media(max-width:1024px){.sidebar{transform:translateX(-100%);width:260px!important}.sidebar.mobile-open{transform:translateX(0)}.main-content{width:100%!important;margin-left:0!important}.topbar{padding:12px 16px}.topbar-title-block{margin-left:46px!important}}
    @media(max-width:640px){.page-pad{padding:14px!important}.col-date{display:none}.col-type{display:none}.modal-overlay{padding:0;align-items:flex-end}.modal{border-radius:20px 20px 0 0;max-width:100%;max-height:95vh}.modal-body{padding:14px}.modal-header{padding:14px 14px 10px}}
    @media(max-width:380px){.btn-approve .btn-label,.btn-reject .btn-label{display:none}.btn-approve,.btn-reject{padding:5px 8px}}
  
  </style>
</head>
<body>

<div id="pageLoader" class="fixed inset-0 bg-green-900/40 backdrop-blur-sm z-[9999] flex flex-col items-center justify-center opacity-0 pointer-events-none transition-opacity duration-300">
  <div class="w-12 h-12 border-4 border-white/20 border-t-green-400 rounded-full animate-spin shadow-lg"></div>
  <p class="text-white font-medium mt-4 tracking-wider text-sm">Loading...</p>
</div>

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
        <button type="button" class="menu-item active"><div class="menu-left"><i class="fa-regular fa-file-lines mi"></i>Document Request</div><span class="active-dot"></span></button>
        <?php endif; ?>
        <?php if ($role === 'admin' || in_array('manage_borrowing', $myPerms)): ?>
        <button type="button" data-nav="borrowingSystem.php" class="menu-item"><div class="menu-left"><i class="fa-solid fa-hammer mi"></i>Borrowing System</div></button>
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

  <main class="main-content" id="mainContent">
    <header class="topbar">
      <div class="topbar-title-block">
        <h2 class="font-bold text-2xl leading-tight" style="font-family:'Playfair Display',serif;color: var(--site-primary-darker);">Document Request</h2>
        <p class="text-gray-500 text-sm mt-0.5">Process and manage document requests here.</p>
      </div>
    </header>

    <div id="realtimeLoader" class="flex-1 flex flex-col items-center justify-center min-h-[50vh]">
      <i class="fa-solid fa-circle-notch fa-spin text-4xl text-green-600 mb-4"></i>
      <p class="text-sm font-semibold text-green-800 animate-pulse tracking-wide">Loading requests...</p>
    </div>

    <div id="mainDataContainer" class="p-6 page-pad flex-1 space-y-5 fade-up" style="display:none;">
      <?php echo str_repeat("<!-- PADDING -->\n", 30); ob_flush(); flush(); ?>

      <!-- Stat cards -->
      <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        <div class="stat-card">
          <p class="stat-label">Requests This Month</p>
          <div class="stat-row"><i class="fa-regular fa-file-lines stat-ico" style="color: var(--site-primary);"></i><span class="stat-num"><?= number_format($reqThisMonth) ?></span></div>
          <span class="stat-sub"><?= number_format($reqToday) ?> today</span>
        </div>
        <div class="stat-card">
          <p class="stat-label">Average Turnaround Time</p>
          <?php if ($avgTurnaroundHours !== null): ?>
            <div class="stat-row"><i class="fa-solid fa-hourglass-half stat-ico text-amber-500"></i><span class="stat-num"><?= $avgTurnaroundHours < 48 ? number_format($avgTurnaroundHours, 1) . 'h' : number_format($avgTurnaroundHours / 24, 1) . 'd' ?></span></div>
            <span class="stat-sub">Requested â†’ Released</span>
          <?php else: ?>
            <div class="stat-row"><i class="fa-solid fa-hourglass-half stat-ico text-gray-300"></i><span class="stat-num text-gray-300">N/A</span></div>
            <span class="stat-sub">No released requests yet</span>
          <?php endif; ?>
        </div>
        <div class="stat-card">
          <p class="stat-label">Repeat Requesters</p>
          <div class="stat-row"><i class="fa-solid fa-user-clock stat-ico text-blue-500"></i><span class="stat-num"><?= number_format($repeatRequesters) ?></span></div>
          <span class="stat-sub">Filed 2+ requests</span>
        </div>
        <div class="stat-card">
          <p class="stat-label">Peak Request Day</p>
          <?php if ($peakDayName): ?>
            <div class="stat-row"><i class="fa-solid fa-calendar-week stat-ico text-purple-500"></i><span class="stat-num" style="font-size:1.6rem;"><?= htmlspecialchars($peakDayName, ENT_QUOTES, 'UTF-8') ?></span></div>
            <span class="stat-sub"><?= number_format($peakDayTotal) ?> requests, all-time</span>
          <?php else: ?>
            <div class="stat-row"><i class="fa-solid fa-calendar-week stat-ico text-gray-300"></i><span class="stat-num text-gray-300">N/A</span></div>
            <span class="stat-sub">Not enough data yet</span>
          <?php endif; ?>
        </div>
      </div>

      <div class="flex items-center justify-between gap-4 flex-wrap">
        <div class="flex items-baseline gap-2">
          <h3 class="font-bold text-gray-900 text-lg">Pending Request</h3>
          <span class="font-bold text-lg" id="pendingCount" style="color:var(--site-primary-dark)"><?= $total_pending ?></span>
        </div>
        <div class="flex items-center gap-3 flex-wrap">
          <div class="search-box" style="width:220px;">
            <i class="fa-solid fa-magnifying-glass text-gray-400 text-xs flex-shrink:0"></i>
            <input type="text" id="searchInput" placeholder="Search..." oninput="filterTable()">
          </div>
          <div class="flex items-center gap-1.5" id="statusPills">
            <button class="status-pill active-pill" data-status="" onclick="setStatusPill(this, '')">All</button>
            <button class="status-pill" data-status="pending" onclick="setStatusPill(this, 'pending')">Pending</button>
            <button class="status-pill" data-status="approved" onclick="setStatusPill(this, 'approved')">Approved</button>
            <button class="status-pill" data-status="rejected" onclick="setStatusPill(this, 'rejected')">Rejected</button>
          </div>
          <button class="btn-filter" onclick="toggleFilter()"><i class="fa-solid fa-filter text-xs"></i><span>Filter</span><i class="fa-solid fa-chevron-down text-xs"></i></button>
        </div>
      </div>

      <div id="filterPanel" class="hidden bg-white border border-gray-200 p-4 shadow-sm flex flex-wrap gap-4" style="border-radius:12px;">
        <div style="min-width:160px;flex:1;"><p class="field-label mb-1">Document Type</p>
          <select id="filterType" class="field-val w-full" onchange="filterTable()">
            <option value="">All Types</option>
            <option>Barangay Clearance</option><option>Certificate of Residency</option><option>Barangay ID</option>
            <option>Certificate of Indigency</option><option>Jobseeker Certificate</option><option>Business Clearance</option>
          </select>
        </div>
        <div style="min-width:120px;flex:1;"><p class="field-label mb-1">Status</p>
          <select id="filterStatus" class="field-val w-full" onchange="syncPillsFromSelect(); filterTable()">
            <option value="">All</option><option value="pending">Pending</option><option value="approved">Approved</option><option value="rejected">Rejected</option>
          </select>
        </div>
        <div style="min-width:130px;flex:1;"><p class="field-label mb-1">Date From</p><input type="date" id="filterDateFrom" class="field-val w-full" onchange="filterTable()"></div>
        <div style="min-width:130px;flex:1;"><p class="field-label mb-1">Date To</p><input type="date" id="filterDateTo" class="field-val w-full" onchange="filterTable()"></div>
      </div>

      <div id="alertBanner">
        <div class="alert-inner" id="alertInner">
          <i id="alertIcon" class="fa-solid fa-circle-check" style="font-size:1rem;flex-shrink:0;"></i>
          <div><span id="alertTitle" style="font-weight:700;"></span><span id="alertDesc" style="font-weight:400;margin-left:6px;opacity:.85;"></span></div>
          <button class="alert-close" onclick="dismissAlert()"><i class="fa-solid fa-xmark"></i></button>
        </div>
      </div>

      <div class="tbl-wrap relative" id="tableWrap">
        <div class="bulk-toolbar">
          <input type="checkbox" class="rounded w-4 h-4 accent-green-600" id="checkAll" onchange="toggleAll(this);updateBulkVisibility();">
          <button class="btn-approve text-xs px-2 py-1" id="bulkApproveBtn" onclick="bulkAction('approve', this)" style="display:none;"><i class="fa-solid fa-check text-[10px]"></i></button>
          <button class="btn-reject text-xs px-2 py-1" id="bulkRejectBtn" onclick="bulkAction('reject', this)" style="display:none;"><i class="fa-solid fa-xmark text-[10px]"></i></button>
          <div class="bulk-toolbar-divider" id="bulkDivider" style="display:none;"></div>
          <span class="text-xs text-gray-400 font-medium" id="bulkCountLabel" style="display:none;"></span>
          <button class="btn-refresh ml-auto" onclick="triggerRefresh()"><i class="fa-solid fa-rotate-right text-xs"></i></button>
        </div>
        <div id="tableLoader" class="absolute inset-0 bg-white/80 backdrop-blur-sm z-10 flex flex-col items-center justify-center opacity-0 pointer-events-none">
          <i class="fa-solid fa-circle-notch fa-spin text-3xl text-green-600 mb-3"></i>
          <p class="text-xs font-semibold text-green-800 animate-pulse">Searching...</p>
        </div>
        <div id="noResultsState" class="hidden absolute inset-0 z-0 flex flex-col items-center justify-center text-center p-6 pb-12">
          <i class="fa-solid fa-magnifying-glass text-gray-300 text-4xl mb-3"></i>
          <p class="font-semibold text-gray-800 text-lg">No requests found</p>
          <p class="text-gray-500 text-sm mt-1">Try adjusting your search criteria.</p>
        </div>

        <table id="docTable">
          <thead>
            <tr>
              <th style="width:36px;"></th>
              <th onclick="sortTable('name')" style="cursor:pointer;">Requestor <i class="fa-solid fa-sort"></i></th>
              <th class="col-type" onclick="sortTable('type')" style="cursor:pointer;">Document Type <i class="fa-solid fa-sort"></i></th>
              <th class="col-date" onclick="sortTable('date')" style="cursor:pointer;">Date Requested <i class="fa-solid fa-sort"></i></th>
              <th>Status</th>
              <th style="text-align:right;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($all_docs)): ?>
            <tr><td colspan="6"><div class="empty-state"><i class="fa-regular fa-folder-open"></i><p class="font-semibold text-gray-500 text-sm">No document requests</p><p class="text-xs text-gray-400 mt-1">No requests have been submitted yet.</p></div></td></tr>
            <?php else: ?>
            <?php foreach ($all_docs as $d):
              $fullname = trim($d['firstname'].' '.($d['middlename']?$d['middlename'].' ':'').$d['lastname'].($d['suffix']?' '.$d['suffix']:''));
              $date = 'â€”';
              if (!empty($d['submitted_at'])) { $ts=strtotime($d['submitted_at']); if($ts&&$ts>0)$date=date('F j, Y',$ts); }
              $raw = $d['document_type']??'';
              $pretty = htmlspecialchars(prettyDocumentType($raw));
              $chip = match(true){
                str_contains(strtolower($raw),'clearance')  => 'chip-clearance',
                str_contains(strtolower($raw),'residency')  => 'chip-residency',
                str_contains(strtolower($raw),'indigency')  => 'chip-indigency',
                str_contains(strtolower($raw),'id')         => 'chip-id',
                str_contains(strtolower($raw),'jobseeker')  => 'chip-jobseeker',
                default                                      => 'chip-default'
              };
              $status = strtolower($d['status']??'pending');
              $statusCls = match($status){'approved'=>'status-approved','rejected'=>'status-rejected',default=>'status-pending'};
              $rowJson = htmlspecialchars(json_encode($d), ENT_QUOTES);
            ?>
            <tr data-doc="<?= $rowJson ?>" data-date="<?= htmlspecialchars($d['submitted_at']??'') ?>" data-status="<?= $status ?>">
              <td><?php if($status==='pending'):?><input type="checkbox" class="row-check rounded w-4 h-4 accent-green-600" onchange="updateBulkVisibility()"><?php else:?><input type="checkbox" class="row-check rounded w-4 h-4 accent-green-600" disabled style="opacity:.3;"><?php endif;?></td>
              <td><p class="font-semibold text-gray-900 text-sm"><?=htmlspecialchars($fullname)?></p><p class="text-gray-400 text-xs"><?=htmlspecialchars($d['email']??'')?></p></td>
              <td class="col-type"><span class="doc-chip <?=$chip?>"><?=$pretty?></span></td>
              <td class="text-gray-500 col-date"><?=$date?></td>
              <td><span class="status-badge <?=$statusCls?>"><?=ucfirst($status)?></span></td>
              <td>
                <div class="flex items-center justify-end gap-2 flex-wrap">
                  <button class="btn-view">View</button>
                  <?php if($status==='pending'):?>
                  <button class="btn-approve"><i class="fa-solid fa-check text-[10px]"></i><span class="btn-label"> Approve</span></button>
                  <button class="btn-reject"><i class="fa-solid fa-xmark text-[10px]"></i><span class="btn-label"> Reject</span></button>
                  <?php endif;?>
                </div>
              </td>
            </tr>
            <?php endforeach;?>
            <?php endif;?>
          </tbody>
        </table>
      </div>

      <div class="flex items-center justify-center gap-2 pt-2 flex-wrap" id="paginationContainer"></div>
    </div>
  </main>
</div>

<!-- â•â• DETAIL MODAL â•â• -->
<div class="modal-overlay" id="modalOverlay" onclick="closeModalOnOverlay(event)">
  <div class="modal">
    <div class="modal-header">
      <div class="min-w-0">
        <p class="font-bold text-gray-900 text-base">Request Details</p>
        <p class="text-gray-400 text-xs mt-0.5 truncate" id="modalSubtitle">Review document request</p>
      </div>
      <button class="modal-close" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">

      <!-- Request Information -->
      <div class="section-card">
        <div class="section-title">
          <div class="section-icon"><i class="fa-regular fa-file-lines text-green-700 text-sm"></i></div>
          Request Information
        </div>
        <div class="space-y-3">
          <div class="grid grid-cols-2 gap-3">
            <div><p class="field-label">Document Type</p><input class="field-val" id="mDocType" readonly></div>
            <div><p class="field-label">Number of Copies</p><input class="field-val" id="mNumCopies" readonly></div>
          </div>
          <div><p class="field-label">Purpose / Reason</p><input class="field-val" id="mPurpose" readonly></div>
          <div><p class="field-label">Additional Notes</p><textarea class="field-val" id="mNotes" readonly></textarea></div>
        </div>
      </div>

      <!-- Supporting Documents -->
      <div class="section-card" id="supportingDocsCard">
        <div class="section-title">
          <div class="section-icon"><i class="fa-solid fa-paperclip text-green-700 text-sm"></i></div>
          Supporting Documents
          <span class="ml-auto text-xs font-normal text-gray-400 normal-case tracking-normal" id="sdocCount"></span>
        </div>
        <div id="supportingDocsList"></div>
      </div>

      <!-- Requestor Information -->
      <div class="section-card">
        <div class="section-title">
          <div class="section-icon"><i class="fa-solid fa-id-card text-green-700 text-sm"></i></div>
          Requestor Information
        </div>
        <div class="space-y-3">
          <div>
            <p class="field-label mb-2">Uploaded ID</p>
            <div class="flex gap-4" style="min-height:80px;">
              <div class="id-placeholder" onclick="openIDLightbox('front')">
                <img id="frontIDImg" src="" alt="" style="display:none;">
                <div id="frontIDPh" style="display:flex;flex-direction:column;align-items:center;gap:6px;">
                  <i class="fa-regular fa-id-card text-2xl text-gray-300"></i>
                  <span>Front ID</span>
                  <span class="text-[10px] text-gray-300">(click to zoom)</span>
                </div>
              </div>
              <div class="id-placeholder" onclick="openIDLightbox('back')">
                <img id="backIDImg" src="" alt="" style="display:none;">
                <div id="backIDPh" style="display:flex;flex-direction:column;align-items:center;gap:6px;">
                  <i class="fa-regular fa-id-card text-2xl text-gray-300"></i>
                  <span>Back ID</span>
                  <span class="text-[10px] text-gray-300">(click to zoom)</span>
                </div>
              </div>
            </div>
          </div>
          <div><p class="field-label">Full Name</p><input class="field-val" id="mFullName" readonly></div>
          <div class="grid grid-cols-2 gap-3">
            <div><p class="field-label">Sex</p><input class="field-val" id="mGender" readonly></div>
            <div><p class="field-label">Age</p><input class="field-val" id="mAge" readonly></div>
          </div>
          <div><p class="field-label">Birthdate</p><input class="field-val" id="mBirthdate" readonly></div>
          <div class="grid grid-cols-2 gap-3">
            <div><p class="field-label">Emergency Contact</p><input class="field-val" id="mEmergencyContact" readonly></div>
            <div><p class="field-label">Emergency No.</p><input class="field-val" id="mEmergencyPhone" readonly></div>
          </div>
          <div><p class="field-label">Blood Type / Health Conditions</p><input class="field-val" id="mBloodType" readonly></div>
          <div class="grid grid-cols-2 gap-3">
            <div><p class="field-label">Employment Status</p><input class="field-val" id="mEmployment" readonly></div>
            <div><p class="field-label">Monthly Income</p><input class="field-val" id="mIncome" readonly></div>
          </div>
          <div><p class="field-label">Years as Resident</p><input class="field-val" id="mDateMovedIn" readonly></div>
          <div class="flex items-center gap-2 mt-1">
            <input type="checkbox" id="mResidentBirth" disabled class="rounded accent-green-600">
            <label for="mResidentBirth" class="text-sm text-gray-600">Resident since birth</label>
          </div>
        </div>
      </div>

    </div><!-- /modal-body -->
    <div class="modal-footer" id="modalFooter">
      <button id="modalRejectBtn" onclick="handleModalAction('reject', this)">
        <i class="fa-solid fa-xmark text-[10px]"></i> Reject
      </button>
      <button id="modalApproveBtn" onclick="handleModalAction('approve', this)">
        <i class="fa-solid fa-check text-[10px]"></i> Approve
      </button>
    </div>
  </div>
</div>

<!-- â•â• LIGHTBOX (shared for IDs + supporting doc images) â•â• -->
<div class="lightbox" id="lightbox" onclick="closeLightbox()">
  <div class="lightbox-inner" onclick="event.stopPropagation()">
    <button class="lightbox-close" onclick="closeLightbox()"><i class="fa-solid fa-xmark"></i></button>
    <img id="lightboxImg" src="" alt="Preview">
    <p class="lightbox-caption" id="lightboxCaption"></p>
  </div>
</div>

<!-- â•â• CONFIRM DIALOG â•â• -->
<div class="dialog-overlay" id="dialogOverlay">
  <div class="dialog-box">
    <div class="dialog-body">
      <div class="dialog-icon-wrap" id="dialogIconWrap"><i id="dialogIconEl"></i></div>
      <p class="dialog-title" id="dialogTitle">Confirm Action</p>
      <p class="dialog-desc" id="dialogDesc">Are you sure?</p>
      <span class="dialog-name-badge" id="dialogNameBadge" style="display:none;"></span>
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
/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   SIDEBAR
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
const sidebar      = document.getElementById('sidebar');
const mainContent  = document.getElementById('mainContent');
const expandBtn    = document.getElementById('expandBtn');
const collapseBtn  = document.getElementById('collapseBtn');
const backdrop     = document.getElementById('sidebarBackdrop');

const isMobile = () => window.innerWidth <= 1024;
let collapsed  = localStorage.getItem('sidebarCollapsed') === 'true';

function applyCollapse() {
  if (isMobile()) {
    sidebar.classList.remove('collapsed');
    mainContent.classList.remove('sidebar-collapsed');
    expandBtn.classList.add('visible');
    return;
  }
  sidebar.classList.remove('mobile-open');
  backdrop.classList.remove('visible');
  document.body.style.overflow = '';
  if (collapsed) {
    sidebar.classList.add('collapsed');
    mainContent.classList.add('sidebar-collapsed');
    expandBtn.classList.add('visible');
  } else {
    sidebar.classList.remove('collapsed');
    mainContent.classList.remove('sidebar-collapsed');
    expandBtn.classList.remove('visible');
  }
}
function openMobileSidebar()  { sidebar.classList.add('mobile-open');    backdrop.classList.add('visible');    document.body.style.overflow = 'hidden'; }
function closeMobileSidebar() { sidebar.classList.remove('mobile-open'); backdrop.classList.remove('visible'); document.body.style.overflow = ''; }

collapseBtn.addEventListener('click', () => {
  if (isMobile()) { closeMobileSidebar(); return; }
  collapsed = true; localStorage.setItem('sidebarCollapsed', 'true'); applyCollapse();
});
expandBtn.addEventListener('click', () => {
  if (isMobile()) { openMobileSidebar(); return; }
  collapsed = false; localStorage.setItem('sidebarCollapsed', 'false'); applyCollapse();
});
window.addEventListener('resize', applyCollapse);
applyCollapse();

/* â”€â”€ page loader fake-delay â”€â”€ */
const realtimeLoader    = document.getElementById('realtimeLoader');
const mainDataContainer = document.getElementById('mainDataContainer');
function finishLoading() {
  if (realtimeLoader)    realtimeLoader.style.display    = 'none';
  if (mainDataContainer) mainDataContainer.style.display = '';
}
setTimeout(finishLoading, 400);

document.querySelectorAll('[data-nav]').forEach(b =>
  b.addEventListener('click', function () {
    const t = this.getAttribute('data-nav');
    if (t) { showPageLoader('Loading page...'); setTimeout(() => window.location.href = t, 180); }
  })
);
function triggerRefresh() { showPageLoader('Refreshing requests...'); setTimeout(() => location.reload(), 180); }

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   ALERT TOAST
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
let alertTimer = null;
function showToast(type, title, desc) {
  const icons = { success: 'fa-circle-check', error: 'fa-circle-xmark', warning: 'fa-triangle-exclamation' };
  const map   = { success: 'alert-success', error: 'alert-error', warning: 'alert-warning' };
  document.getElementById('alertInner').className  = 'alert-inner ' + (map[type] || 'alert-success');
  document.getElementById('alertIcon').className   = 'fa-solid ' + (icons[type] || 'fa-circle-check');
  document.getElementById('alertTitle').textContent = title;
  document.getElementById('alertDesc').textContent  = desc || '';
  document.getElementById('alertBanner').classList.add('show');
  clearTimeout(alertTimer);
  alertTimer = setTimeout(dismissAlert, 4500);
}
function dismissAlert() { document.getElementById('alertBanner').classList.remove('show'); }

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   PAGE LOADER
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
function showPageLoader(message = 'Loading...') {
  mainDataContainer.style.display = 'none';
  window.__docLoaderShownAt = Date.now();
  realtimeLoader.style.display = 'flex';
  const txt = realtimeLoader.querySelector('p');
  if (txt) txt.textContent = message;
}
function hidePageLoader() {
  const elapsed = Date.now() - (window.__docLoaderShownAt || 0);
  if (elapsed < 300) { setTimeout(hidePageLoader, 300 - elapsed); return; }
  realtimeLoader.style.display    = 'none';
  mainDataContainer.style.display = '';
}

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   BUTTON LOADING STATE
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
function setActionButtonLoading(btn, label = 'Processing...') {
  if (!btn) return null;
  const original = btn.innerHTML;
  btn.disabled   = true;
  btn.innerHTML  = `<i class="fa-solid fa-spinner fa-spin text-[10px]"></i><span class="btn-label"> ${label}</span>`;
  return () => { btn.disabled = false; btn.innerHTML = original; };
}

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   DIALOG
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
let dialogCallback = null;
function showDialog({ type = 'approve', title, desc, nameBadge, confirmLabel, onConfirm }) {
  const iconWrap   = document.getElementById('dialogIconWrap');
  const iconEl     = document.getElementById('dialogIconEl');
  const confirmBtn = document.getElementById('dialogConfirmBtn');
  iconWrap.className  = 'dialog-icon-wrap';
  confirmBtn.className = 'dialog-btn';
  if (type === 'approve') {
    iconWrap.classList.add('dialog-icon-approve'); iconEl.className = 'fa-solid fa-check';
    confirmBtn.classList.add('dialog-btn-confirm-approve');
    document.getElementById('dialogConfirmIcon').className = 'fa-solid fa-check';
  } else if (type === 'reject') {
    iconWrap.classList.add('dialog-icon-reject'); iconEl.className = 'fa-solid fa-xmark';
    confirmBtn.classList.add('dialog-btn-confirm-reject');
    document.getElementById('dialogConfirmIcon').className = 'fa-solid fa-xmark';
  } else {
    iconWrap.classList.add('dialog-icon-bulk'); iconEl.className = 'fa-solid fa-layer-group';
    confirmBtn.classList.add('dialog-btn-confirm-bulk');
    document.getElementById('dialogConfirmIcon').className = 'fa-solid fa-layer-group';
  }
  document.getElementById('dialogTitle').textContent  = title || 'Confirm Action';
  document.getElementById('dialogDesc').textContent   = desc  || 'Are you sure?';
  document.getElementById('dialogConfirmLabel').textContent = confirmLabel || 'Confirm';
  const badge = document.getElementById('dialogNameBadge');
  if (nameBadge) { badge.textContent = nameBadge; badge.style.display = 'inline-block'; }
  else           { badge.style.display = 'none'; }
  dialogCallback   = onConfirm;
  confirmBtn.onclick = () => { closeDialog(); if (dialogCallback) dialogCallback(); };
  document.getElementById('dialogOverlay').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeDialog() {
  document.getElementById('dialogOverlay').classList.remove('open');
  document.body.style.overflow = '';
}
document.getElementById('dialogOverlay').addEventListener('click', e => {
  if (e.target === document.getElementById('dialogOverlay')) closeDialog();
});

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   BULK SELECTION
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
function toggleAll(cb) {
  document.querySelectorAll('.row-check:not([disabled])').forEach(c => c.checked = cb.checked);
  updateBulkVisibility();
}
function updateBulkVisibility() {
  const count = document.querySelectorAll('.row-check:not([disabled]):checked').length;
  const show  = count >= 1;
  document.getElementById('bulkApproveBtn').style.display = show ? '' : 'none';
  document.getElementById('bulkRejectBtn').style.display  = show ? '' : 'none';
  document.getElementById('bulkDivider').style.display    = show ? '' : 'none';
  const lbl = document.getElementById('bulkCountLabel');
  if (show) { lbl.style.display = ''; lbl.textContent = count + ' selected'; }
  else      { lbl.style.display = 'none'; }
}

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   STATUS ORDER + SORT
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
const STATUS_ORDER = { pending: 0, approved: 1, rejected: 2 };

function sortByStatusFirst(rows) {
  const tbody = document.querySelector('#docTable tbody');
  rows.sort((a, b) => (STATUS_ORDER[a.dataset.status] ?? 9) - (STATUS_ORDER[b.dataset.status] ?? 9));
  rows.forEach(r => tbody.appendChild(r));
}

let sortCol = null, sortDir = 'asc';
function sortTable(col) {
  if (sortCol === col) sortDir = sortDir === 'asc' ? 'desc' : 'asc';
  else { sortCol = col; sortDir = 'asc'; }
  const rows  = Array.from(document.querySelectorAll('#docTable tbody tr[data-doc]'));
  const tbody = document.querySelector('#docTable tbody');
  rows.sort((a, b) => {
    const sa = STATUS_ORDER[a.dataset.status] ?? 9, sb = STATUS_ORDER[b.dataset.status] ?? 9;
    if (sa !== sb) return sa - sb;
    const dA = JSON.parse(a.dataset.doc), dB = JSON.parse(b.dataset.doc);
    let vA, vB;
    if (col === 'name')  { vA = (dA.firstname + ' ' + dA.lastname).toLowerCase(); vB = (dB.firstname + ' ' + dB.lastname).toLowerCase(); }
    if (col === 'type')  { vA = (dA.document_type || '').toLowerCase();           vB = (dB.document_type || '').toLowerCase(); }
    if (col === 'date')  { vA = dA.submitted_at ? new Date(dA.submitted_at).getTime() : 0; vB = dB.submitted_at ? new Date(dB.submitted_at).getTime() : 0; }
    if (vA < vB) return sortDir === 'asc' ? -1 :  1;
    if (vA > vB) return sortDir === 'asc' ?  1 : -1;
    return 0;
  });
  rows.forEach(r => tbody.appendChild(r));
  renderPagination();
}

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   STATUS PILLS
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
function setStatusPill(btn, status) {
  document.querySelectorAll('.status-pill').forEach(p => p.classList.remove('active-pill'));
  btn.classList.add('active-pill');
  const fs = document.getElementById('filterStatus');
  if (fs) fs.value = status;
  currentPage = 1;
  filterTable();
}
function syncPillsFromSelect() {
  const val = document.getElementById('filterStatus').value;
  document.querySelectorAll('.status-pill').forEach(p =>
    p.classList.toggle('active-pill', p.dataset.status === val)
  );
}

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   FILTER
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
let searchTimeout;
function filterTable() {
  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(() => {
    setTimeout(() => {
      const q       = document.getElementById('searchInput').value.toLowerCase();
      const type    = (document.getElementById('filterType')?.value || '').toLowerCase();
      const statusF = (document.getElementById('filterStatus')?.value || '').toLowerCase();
      const df      = document.getElementById('filterDateFrom')?.value || '';
      const dt      = document.getElementById('filterDateTo')?.value   || '';
      let count = 0;
      document.querySelectorAll('#docTable tbody tr[data-doc]').forEach(row => {
        const text     = row.textContent.toLowerCase();
        const rowDate  = row.dataset.date   || '';
        const rowStat  = row.dataset.status || '';
        const ok = (!q       || text.includes(q))
                && (!type    || text.includes(type))
                && (!statusF || rowStat === statusF)
                && (!df      || rowDate >= df)
                && (!dt      || rowDate <= dt);
        if (ok) {
          row.dataset.filteredout = "false";
          count++;
        } else {
          row.dataset.filteredout = "true";
          row.style.display = 'none';
        }
      });
      currentPage = 1;
      renderPagination();
      const noResults = document.getElementById('noResultsState');
      const table     = document.getElementById('docTable');
      if (count === 0) { table.style.opacity = '0'; noResults.classList.remove('hidden'); document.getElementById('tableWrap').classList.add('min-h-[300px]'); }
      else             { table.style.opacity = '1'; noResults.classList.add('hidden');    document.getElementById('tableWrap').classList.remove('min-h-[300px]'); }
    }, 10);
  }, 400);
}
function toggleFilter() { document.getElementById('filterPanel').classList.toggle('hidden'); }

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   HELPERS
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
function calcAge(dob) {
  const b = new Date(dob), n = new Date();
  let a = n.getFullYear() - b.getFullYear();
  if (n < new Date(n.getFullYear(), b.getMonth(), b.getDate())) a--;
  return a;
}
function prettyDocType(type) {
  const map = {
    'barangay_clearance':'Barangay Clearance','brangay_clearance':'Barangay Clearance',
    'certificate_indigency':'Certificate of Indigency','indigency':'Certificate of Indigency',
    'certificate_residency':'Certificate of Residency','residency':'Certificate of Residency',
    'business_permit':'Barangay Business Permit','id':'Barangay ID',
    'first_time_jobseeker':'First-Time Jobseeker','jobseeker':'Jobseeker Certificate'
  };
  if (!type) return '';
  const key = String(type).toLowerCase().trim();
  return map[key] || (key.replace(/[_-]/g,' ').replace(/\b\w/g, c => c.toUpperCase()));
}
function getNameFromDoc(d) {
  return [d.firstname, d.middlename ? d.middlename + '.' : '', d.lastname, d.suffix].filter(Boolean).join(' ');
}
function escHtml(str) {
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function isImageExt(name) {
  return /\.(jpg|jpeg|png|gif|webp)$/i.test(name);
}
function isPdfExt(name) {
  return /\.pdf$/i.test(name);
}

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   LIGHTBOX
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
function openLightbox(src, caption) {
  if (!src) return;
  document.getElementById('lightboxImg').src          = src;
  document.getElementById('lightboxCaption').textContent = caption || '';
  document.getElementById('lightbox').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeLightbox() {
  document.getElementById('lightbox').classList.remove('open');
  document.body.style.overflow = '';
}
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') { closeLightbox(); closeDialog(); }
});

function resolveUserIDSrc(src) {
  if (!src) return '';
  src = src.trim();
  if (src.startsWith('../uploads/id_verification/')) return src;
  if (src.startsWith('./uploads/id_verification/')) return src.replace('./', '../');
  if (src.startsWith('/uploads/id_verification/')) return '../' + src.slice(1);
  if (src.startsWith('uploads/id_verification/')) return '../' + src;
  return '../uploads/id_verification/' + src;
}

// ID photos
let currentFront = '', currentBack = '';
function openIDLightbox(side) {
  const src = side === 'front' ? currentFront : currentBack;
  if (!src) return;
  openLightbox(resolveUserIDSrc(src), side === 'front' ? 'Front ID' : 'Back ID');
}
// Supporting doc images
function openDocLightbox(url, name) {
  openLightbox(url, name);
}

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   MODAL â€” OPEN
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
let currentRequestId = null, currentRow = null, currentStatus = '';

function openModal(row, triggerBtn = null) {
  const resetBtn = setActionButtonLoading(triggerBtn, 'Loading...');
  const d = JSON.parse(row.dataset.doc);
  currentRequestId = d.id;
  currentRow       = row;
  currentFront     = d.frontID  || '';
  currentBack      = d.backID   || '';
  currentStatus    = (d.status  || 'pending').toLowerCase();

  // â”€â”€ Request info â”€â”€
  document.getElementById('mDocType').value    = prettyDocType(d.document_type || '');
  document.getElementById('mNumCopies').value  = d.num_copies || '';
  document.getElementById('mPurpose').value    = d.purpose    || '';
  document.getElementById('mNotes').value      = d.notes      || '';
  document.getElementById('modalSubtitle').textContent = d.email || prettyDocType(d.document_type || '');

  // â”€â”€ Supporting Documents â”€â”€
  const docsList = document.getElementById('supportingDocsList');
  const docsCard = document.getElementById('supportingDocsCard');
  const sdocCount= document.getElementById('sdocCount');
  docsList.innerHTML = '';

  let files = [];
  try { files = d.uploaded_files ? JSON.parse(d.uploaded_files) : []; } catch (e) {}

  if (files && files.length > 0) {
    docsCard.style.display = '';
    sdocCount.textContent  = files.length + ' file' + (files.length > 1 ? 's' : '');

    files.forEach(f => {
      const name   = typeof f === 'string' ? f : (f.name || f.filename || JSON.stringify(f));
      const url    = '../uploads/document_requests/' + encodeURIComponent(name);
      const isImg  = isImageExt(name);
      const isPdf  = isPdfExt(name);

      // Icon block
      let iconHtml;
      if (isImg) {
        iconHtml = `<img
          class="sdoc-thumb"
          src="${url}"
          alt="${escHtml(name)}"
          onclick="openDocLightbox('${url.replace(/'/g,"\\'")}', '${escHtml(name).replace(/'/g,"\\'")}')">`;
      } else if (isPdf) {
        iconHtml = `<div class="sdoc-icon sdoc-icon-pdf"><i class="fa-solid fa-file-pdf text-red-500"></i></div>`;
      } else {
        iconHtml = `<div class="sdoc-icon sdoc-icon-file"><i class="fa-solid fa-file text-gray-400"></i></div>`;
      }

      // Action buttons
      let actionsHtml = `<a class="sdoc-btn sdoc-btn-view" href="${url}" target="_blank" rel="noopener">
                           <i class="fa-solid fa-eye text-[10px]"></i> View
                         </a>`;
      if (isImg) {
        actionsHtml += `<button class="sdoc-btn sdoc-btn-zoom"
                          onclick="openDocLightbox('${url.replace(/'/g,"\\'")}','${escHtml(name).replace(/'/g,"\\'")}')">
                          <i class="fa-solid fa-expand text-[10px]"></i> Zoom
                        </button>`;
      }
      actionsHtml += `<a class="sdoc-btn sdoc-btn-dl" href="${url}" download="${escHtml(name)}">
                        <i class="fa-solid fa-download text-[10px]"></i>
                      </a>`;

      const el = document.createElement('div');
      el.className = 'sdoc-item';
      el.innerHTML = `
        ${iconHtml}
        <span class="sdoc-name" title="${escHtml(name)}">${escHtml(name)}</span>
        <div class="sdoc-actions">${actionsHtml}</div>`;
      docsList.appendChild(el);
    });
  } else {
    docsCard.style.display = 'none';
  }

  // â”€â”€ Requestor info â”€â”€
  document.getElementById('mFullName').value         = getNameFromDoc(d);
  document.getElementById('mGender').value           = d.gender           || '';
  document.getElementById('mAge').value              = d.birthday ? calcAge(d.birthday) : '';
  document.getElementById('mBirthdate').value        = d.birthday ? new Date(d.birthday).toLocaleDateString('en-US',{month:'2-digit',day:'2-digit',year:'numeric'}) : '';
  document.getElementById('mEmergencyContact').value = d.emergency_contact || '';
  document.getElementById('mEmergencyPhone').value   = d.emergency_phone   || '';
  document.getElementById('mBloodType').value        = d.health_conditions || '';
  document.getElementById('mEmployment').value       = d.employment_status || '';
  document.getElementById('mIncome').value           = d.monthly_income ? 'â‚± ' + parseFloat(d.monthly_income).toLocaleString('en-PH', { minimumFractionDigits: 2 }) : '';
  document.getElementById('mDateMovedIn').value      = d.years_resident ? d.years_resident + ' year(s)' : '';
  document.getElementById('mResidentBirth').checked  = !!parseInt(d.resident_birth);

  // â”€â”€ ID photos â”€â”€
  const fImg = document.getElementById('frontIDImg'), bImg = document.getElementById('backIDImg');
  const fPh  = document.getElementById('frontIDPh'),  bPh  = document.getElementById('backIDPh');
  if (d.frontID) { fImg.src = resolveUserIDSrc(d.frontID); fImg.style.display = 'block'; fPh.style.display = 'none'; }
  else           { fImg.style.display = 'none'; fPh.style.display = 'flex'; }
  if (d.backID)  { bImg.src = resolveUserIDSrc(d.backID);  bImg.style.display = 'block'; bPh.style.display = 'none'; }
  else           { bImg.style.display = 'none'; bPh.style.display = 'flex'; }

  // â”€â”€ Footer â”€â”€
  document.getElementById('modalFooter').style.display = currentStatus === 'pending' ? 'grid' : 'none';

  document.getElementById('modalOverlay').classList.add('open');
  document.body.style.overflow = 'hidden';
  if (resetBtn) setTimeout(resetBtn, 300);
}

function closeModal() {
  document.getElementById('modalOverlay').classList.remove('open');
  document.body.style.overflow = '';
  currentRequestId = null;
  currentRow       = null;
}
function closeModalOnOverlay(e) {
  if (e.target === document.getElementById('modalOverlay')) closeModal();
}

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   CORE ACTION (approve / reject)
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
function executeAction(requestId, action, row) {
  return fetch('documentrequestAction.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'requestId=' + encodeURIComponent(requestId) + '&action=' + encodeURIComponent(action)
  })
  .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status + ': ' + r.statusText); return r.json(); })
  .then(data => {
    if (data.success) {
      if (row) {
        const badge = row.querySelector('.status-badge');
        if (badge) { badge.textContent = data.status.charAt(0).toUpperCase() + data.status.slice(1); badge.className = 'status-badge status-' + data.status; }
        const actionDiv = row.querySelector('td:last-child div');
        if (actionDiv) { const vBtn = actionDiv.querySelector('.btn-view'); actionDiv.innerHTML = ''; if (vBtn) actionDiv.appendChild(vBtn); }
        const cb = row.querySelector('.row-check'); if (cb) { cb.checked = false; cb.disabled = true; cb.style.opacity = '0.3'; }
        row.dataset.status = data.status;
        try { const doc = JSON.parse(row.dataset.doc); doc.status = data.status; row.dataset.doc = JSON.stringify(doc); } catch(e){}
      }
      const pc = document.getElementById('pendingCount');
      if (pc) pc.textContent = Math.max(0, parseInt(pc.textContent) - 1);
      updateBulkVisibility();
      return { success: true };
    }
    return { success: false, message: data.message || 'Failed to ' + action + ' request.' };
  })
  .catch(err => ({ success: false, message: 'Network error: ' + err.message }));
}

function handleAction(requestId, action, row, triggerBtn = null) {
  const d       = JSON.parse(row.dataset.doc);
  const name    = getNameFromDoc(d);
  const docType = prettyDocType(d.document_type || '');
  const isApp   = action === 'approve';
  showDialog({
    type: isApp ? 'approve' : 'reject',
    title: isApp ? 'Approve Request' : 'Reject Request',
    desc:  isApp
      ? `Approve "${docType}"? It will be marked ready for pick-up.`
      : `Reject "${docType}"? This cannot be easily undone.`,
    nameBadge: name || null,
    confirmLabel: isApp ? 'Yes, Approve' : 'Yes, Reject',
    onConfirm: () => {
      const resetBtn = setActionButtonLoading(triggerBtn, isApp ? 'Approving...' : 'Rejecting...');
      showPageLoader(isApp ? 'Approving request...' : 'Rejecting request...');
      executeAction(requestId, action, row).then(res => {
        if (res.success) showToast(isApp ? 'success' : 'warning', isApp ? 'Request Approved!' : 'Request Rejected', isApp ? `${name || 'Request'} approved and ready for pick-up.` : `${name || 'Request'} has been rejected.`);
        else             showToast('error', 'Action Failed', res.message);
      }).finally(() => { hidePageLoader(); if (resetBtn) resetBtn(); });
    }
  });
}

function handleModalAction(action, triggerBtn = null) {
  if (!currentRequestId) return;
  const id = currentRequestId, row = currentRow;
  closeModal();
  handleAction(id, action, row, triggerBtn);
}

async function bulkAction(action, triggerBtn = null) {
  const selectedRows = Array.from(document.querySelectorAll('.row-check:not([disabled]):checked')).map(cb => cb.closest('tr'));
  if (!selectedRows.length) { showToast('warning', 'No Requests Selected', 'Please select at least one pending request.'); return; }
  const isApp = action === 'approve', count = selectedRows.length;
  showDialog({
    type: 'bulk',
    title: isApp ? `Approve ${count} Request${count > 1 ? 's' : ''}` : `Reject ${count} Request${count > 1 ? 's' : ''}`,
    desc:  isApp ? `Approve ${count} document request${count > 1 ? 's' : ''}? They will be marked ready for pick-up.` : `Reject ${count} document request${count > 1 ? 's' : ''}?`,
    confirmLabel: isApp ? `Approve All ${count}` : `Reject All ${count}`,
    onConfirm: async () => {
      const resetBtn = setActionButtonLoading(triggerBtn, isApp ? 'Approving...' : 'Rejecting...');
      showPageLoader(isApp ? 'Processing approvals...' : 'Processing rejections...');
      let ok = 0;
      await Promise.all(selectedRows.map(row => {
        const d = JSON.parse(row.dataset.doc);
        return executeAction(d.id, action, row).then(res => { if (res.success) ok++; });
      }));
      const pc = document.getElementById('pendingCount');
      if (pc) pc.textContent = Math.max(0, parseInt(pc.textContent) - ok);
      updateBulkVisibility();
      document.getElementById('checkAll').checked = false;
      if (ok === count) showToast(isApp ? 'success' : 'warning', isApp ? `${ok} Request${ok > 1 ? 's' : ''} Approved!` : `${ok} Request${ok > 1 ? 's' : ''} Rejected`, isApp ? 'All approved and ready for pick-up.' : 'All selected requests rejected.');
      else              showToast('warning', 'Partial Success', `${ok} of ${count} requests processed.`);
      hidePageLoader();
      if (resetBtn) resetBtn();
    }
  });
}

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   PAGINATION
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
const ROWS_PER_PAGE = 10;
let currentPage = 1;

function getVisibleRows() {
  return Array.from(document.querySelectorAll('#docTable tbody tr[data-doc]')).filter(r => r.dataset.filteredout !== 'true');
}
function renderPagination() {
  const rows       = getVisibleRows();
  const total      = rows.length;
  const totalPages = Math.max(1, Math.ceil(total / ROWS_PER_PAGE));
  if (currentPage > totalPages) currentPage = totalPages;

  rows.forEach((row, i) => {
    row.style.display = (Math.floor(i / ROWS_PER_PAGE) + 1 === currentPage) ? '' : 'none';
  });

  const c = document.getElementById('paginationContainer');
  c.innerHTML = '';

  const prev = document.createElement('button');
  prev.className = 'page-btn'; prev.disabled = currentPage === 1;
  prev.innerHTML = '<i class="fa-solid fa-chevron-left text-xs"></i>';
  prev.addEventListener('click', () => { currentPage--; renderPagination(); });
  c.appendChild(prev);

  let start = Math.max(1, currentPage - 2), end = Math.min(totalPages, start + 4);
  if (end - start < 4) start = Math.max(1, end - 4);
  for (let p = start; p <= end; p++) {
    const btn = document.createElement('button');
    btn.className = 'page-btn' + (p === currentPage ? ' active' : '');
    btn.textContent = p;
    btn.addEventListener('click', () => { currentPage = p; renderPagination(); });
    c.appendChild(btn);
  }

  const next = document.createElement('button');
  next.className = 'page-btn'; next.disabled = currentPage === totalPages;
  next.innerHTML = '<i class="fa-solid fa-chevron-right text-xs"></i>';
  next.addEventListener('click', () => { currentPage++; renderPagination(); });
  c.appendChild(next);
}

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   INIT
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
const allRows = Array.from(document.querySelectorAll('#docTable tbody tr[data-doc]'));
sortByStatusFirst(allRows);
renderPagination();

document.querySelectorAll('#docTable tbody tr[data-doc]').forEach(row => {
  row.querySelector('.btn-view')?.addEventListener('click',    function () { openModal(row, this); });
  row.querySelector('.btn-approve')?.addEventListener('click', function () { const d = JSON.parse(row.dataset.doc); handleAction(d.id, 'approve', row, this); });
  row.querySelector('.btn-reject')?.addEventListener('click',  function () { const d = JSON.parse(row.dataset.doc); handleAction(d.id, 'reject',  row, this); });
});
</script>
</body>
</html>