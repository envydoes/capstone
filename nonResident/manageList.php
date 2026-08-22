<?php
require_once __DIR__ . '/../config/db_connection.php';
session_start();

require_once __DIR__ . '/../includes/site_config.php';
$siteSettings = site_config_load($conn);

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// If user is logged in, redirect based on role
if (isset($_SESSION['user_id'])) {
  $role = $_SESSION['account_role'] ?? '';
  switch ($role) {
    case 'admin':
      header('Location: ../admin/adminLanding.php');
      exit;
    case 'resident':
    case 'resident,business/apartment owner':
      header('Location: ../resident/residentLanding.php');
      exit;
  }
}


// Check user account status for service access
$accId = $_SESSION['acc_id'] ?? '';
$userStatus = 'pending';
if ($accId !== '') {
    $statusStmt = $conn->prepare('SELECT userStatus FROM tbl_userinfo WHERE accID = ? LIMIT 1');
    if ($statusStmt) {
        $statusStmt->bind_param('s', $accId);
        $statusStmt->execute();
        $result = $statusStmt->get_result();
        $row = $result->fetch_assoc();
        if ($row) {
            $userStatus = strtolower($row['userStatus'] ?? 'pending');
        }
        $statusStmt->close();
    }
}
$canAccessServices = ($userStatus === 'approved' || $userStatus === 'active');

$role = strtolower(trim($_SESSION['account_role'] ?? ''));
$logged_in = isset($_SESSION['user_id']);
$userEmail = $_SESSION['user_id'] ?? '';
$accId     = $_SESSION['acc_id']  ?? '';
$userName  = $userEmail;

$stmt = $conn->prepare('SELECT firstname FROM tbl_userinfo WHERE accID = ? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('s', $accId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row && !empty($row['firstname'])) $userName = $row['firstname'];
    $stmt->close();
}

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

$initials = strtoupper(substr($userName, 0, 2));

// ─── Fetch listings for this user from tbl_busaptlisting ───
$apartmentListings = [];
$businessListings  = [];

$listStmt = $conn->prepare("
    SELECT id, userId, listingType, slotsAvailable,
           aptType, aptTitle, aptStatus, aptPrice, aptFloor, aptRooms, aptOccupants, aptBath,
           aptIncluded, aptAmenities, aptRules, aptDesc, aptAddress, aptMapsLink,
           bussCat, bussName, bussStatus, bussPrice, bussYears, bussOpen, bussClose,
           bussDays, bussFeatures, bussDesc, bussAddress, bussMapsLink,
           contact, email, houseNum, street, barangay, city, photos, createdAt
    FROM tbl_busaptlisting
    WHERE userId = ?
    ORDER BY createdAt DESC
");
if ($listStmt) {
    $listStmt->bind_param('s', $accId);
    $listStmt->execute();
    $listResult = $listStmt->get_result();
    while ($lr = $listResult->fetch_assoc()) {
        $lr['photos_arr'] = array_map(fn($p) => '../uploads/listings/' . $p, json_decode($lr['photos'] ?? '[]', true) ?: []);
        $isApartment = ($lr['listingType'] === 'apt' || $lr['listingType'] === 'apartment');
        $lr['listingSubtype'] = $isApartment ? $lr['aptStatus'] : $lr['bussStatus'];
        $displayName = $isApartment ? ($lr['aptTitle'] ?: 'Apartment Listing') : ($lr['bussName'] ?: 'Business Listing');
        $lr['display_name'] = $displayName;
        $lr['date'] = !empty($lr['createdAt']) ? date('m/d/Y', strtotime($lr['createdAt'])) : ' – ';
        if ($isApartment) {
            $apartmentListings[] = $lr;
        } else {
            $businessListings[] = $lr;
        }
    }
    $listStmt->close();
}

function rowJsonSafe($u) {
    return htmlspecialchars(json_encode($u, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS), ENT_QUOTES, 'UTF-8');
}

$toastType = '';
$toastMsg  = '';
if (isset($_GET['success'])) { $toastType = 'success'; $toastMsg = 'Listing submitted successfully!'; }
if (isset($_GET['error']))   { $toastType = 'error';   $toastMsg = 'Error submitting listing. Please try again.'; }
if (isset($_GET['deleted'])) { $toastType = 'warning'; $toastMsg = 'Listing deleted successfully.'; }
if (isset($_GET['updated'])) { $toastType = 'success'; $toastMsg = 'Listing updated successfully!'; }

$verifyEditProfileUrl = 'nonresidentEditProfile.php';
$verifyActionUrl      = 'verifyAccount.php';
require_once __DIR__ . '/../includes/verification_modal.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="../assets/responsive-global.css">
  <title>Manage Listings - <?= e($siteSettings['site_title']) ?></title>
  <link rel="icon" href="<?= e(site_config_logo_url($siteSettings, '../')) ?>" type="image/png">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <?= site_config_css_vars($siteSettings) ?>
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body { font-family: 'DM Sans', sans-serif; background: #f8fafc; margin: 0; }

    .nav-link { position: relative; transition: color 0.2s; }
    .nav-link::after { content: ''; position: absolute; bottom: -2px; left: 0; width: 0; height: 2px; background: var(--site-primary); transition: width 0.3s; }
    .nav-link:hover::after { width: 100%; }
    .nav-link:hover { color: var(--site-primary-dark); }

    /* active listings tabs */
    .tab-btn { flex: 1; padding: 11px 16px; font-size: 0.875rem; font-weight: 600; border: none; background: transparent; cursor: pointer; color: #6b7280; border-bottom: 2.5px solid transparent; transition: color 0.18s, border-color 0.18s; font-family: 'DM Sans', sans-serif; }
    .tab-btn.active { color: var(--site-primary-dark); border-bottom-color: var(--site-primary); }
    .tab-btn:hover:not(.active) { color: #374151; }

    /* table */
    .lt { width: 100%; border-collapse: collapse; }
    .lt th { text-align: left; padding: 10px 16px; font-size: 0.75rem; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.06em; background: #f9fafb; border-bottom: 1px solid #e5e7eb; }
    .lt td { padding: 13px 16px; font-size: 0.875rem; color: #374151; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
    .lt tr:last-child td { border-bottom: none; }
    .lt tbody tr { transition: background 0.14s; }
    .lt tbody tr:hover { background: var(--site-primary-pale); }
    .av { color: var(--site-primary); font-weight: 600; font-size: 0.8rem; text-decoration: underline; text-underline-offset: 2px; cursor: pointer; background: none; border: none; padding: 0; font-family: inherit; }
    .av:hover { color: var(--site-primary-dark); }
    .ai { width: 36px; height: 36px; border-radius: 50%; border: none; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; font-size: 0.9rem; transition: all 0.2s ease; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    .ai-e { background: #f3f4f6; color: #374151; } .ai-e:hover { background: #dbeafe; color: #2563eb; transform: translateY(-1px); box-shadow: 0 4px 8px rgba(0,0,0,0.15); }
    .ai-d { background: #f3f4f6; color: #374151; } .ai-d:hover { background: #fee2e2; color: #dc2626; transform: translateY(-1px); box-shadow: 0 4px 8px rgba(0,0,0,0.15); }

    .notice-banner { display: flex; align-items: flex-start; gap: 10px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 12px 16px; }
    .notice-banner.notice-banner-rejected { background: #fef2f2; border-color: #fecaca; }
    .notice-banner.notice-banner-rejected p { color: #b91c1c; }
    .notice-banner.notice-banner-rejected i { color: #dc2626; }

    /* form card */
    .form-card { background: #fff; border: 1.5px solid #e5e7eb; border-radius: 18px; overflow: hidden; box-shadow: 0 2px 16px rgba(0,0,0,0.05); }
    .form-header { background: linear-gradient(135deg, var(--site-primary-darker) 0%, var(--site-primary-dark) 100%); padding: 20px 28px; text-align: center; }
    .form-body { padding: 28px; }
    .form-card.form-disabled { position: relative; }
    .form-card.form-disabled::after { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(107, 114, 128, 0.35); pointer-events: auto; cursor: not-allowed; z-index: 10; }
    .form-card.form-disabled .type-card { pointer-events: none; cursor: not-allowed; opacity: 0.75; }
    .form-card.form-disabled .fi,
    .form-card.form-disabled .fs,
    .form-card.form-disabled .fta,
    .form-card.form-disabled #photoInput,
    .form-card.form-disabled .uzone { pointer-events: none; cursor: not-allowed; opacity: 0.85; }

    /* type cards */
    .type-card { flex: 1; border: 2.5px solid #e5e7eb; border-radius: 16px; padding: 22px 14px; text-align: center; cursor: pointer; background: #fafafa; transition: all 0.2s; user-select: none; }
    .type-card:hover { border-color: var(--site-primary-light); background: var(--site-primary-pale); transform: translateY(-2px); box-shadow: 0 4px 14px rgba(var(--site-primary-rgb),0.1); }
    .type-card.selected { border-color: var(--site-primary-dark); background: var(--site-primary-pale); transform: translateY(-2px); box-shadow: 0 4px 14px rgba(var(--site-primary-rgb),0.15); }
    .tc-icon { font-size: 2rem; display: block; margin-bottom: 8px; }
    .tc-title { font-size: 0.95rem; font-weight: 800; color: #1f2937; }
    .type-card.selected .tc-title { color: var(--site-primary-dark); }
    .tc-desc { font-size: 0.72rem; color: #9ca3af; margin-top: 4px; line-height: 1.4; }
    .tc-check { width: 20px; height: 20px; border-radius: 50%; border: 2px solid #d1d5db; background: #fff; margin: 10px auto 0; display: flex; align-items: center; justify-content: center; font-size: 0.6rem; color: transparent; transition: all 0.18s; }
    .type-card.selected .tc-check { border-color: var(--site-primary-dark); background: var(--site-primary-dark); color: #fff; }

    /* dynamic panels */
    .dyn-panel { display: none; }
    .dyn-panel.on { display: block; animation: panelIn 0.3s ease both; }
    @keyframes panelIn { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:translateY(0); } }

    /* section divider */
    .sdiv { display: flex; align-items: center; gap: 10px; margin: 24px 0 16px; }
    .sdiv::before, .sdiv::after { content:''; flex:1; height:1px; background:#e5e7eb; }
    .sdiv span { font-size: 0.68rem; font-weight: 800; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.09em; white-space: nowrap; }

    /* inputs */
    .fl { display: block; font-size: 0.82rem; font-weight: 700; color: #374151; margin-bottom: 6px; }
    .fl .req { color: #ef4444; margin-right: 2px; }
    .fl .hint { font-weight: 400; color: #9ca3af; font-size: 0.74rem; }
    .fi, .fs, .fta { width: 100%; padding: 10px 13px; border: 1.5px solid #d1d5db; border-radius: 10px; font-size: 0.875rem; font-family: 'DM Sans', sans-serif; color: #374151; background: #fff; outline: none; transition: border-color 0.18s, box-shadow 0.18s; }
    .fi:focus, .fs:focus, .fta:focus { border-color: var(--site-primary); box-shadow: 0 0 0 3px rgba(var(--site-primary-rgb),0.1); }
    .fi::placeholder, .fta::placeholder { color: #9ca3af; }
    .fi:disabled, .fs:disabled { background: #f9fafb; color: #9ca3af; cursor: not-allowed; }
    .fs { appearance: none; cursor: pointer; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%236b7280' stroke-width='2'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' d='M19 9l-7 7-7-7'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; background-size: 16px; padding-right: 38px; }
    .fta { resize: vertical; min-height: 86px; }

    /* price input */
    .price-wrap { display: flex; border: 1.5px solid #d1d5db; border-radius: 10px; overflow: hidden; transition: border-color 0.18s, box-shadow 0.18s; }
    .price-wrap:focus-within { border-color: var(--site-primary); box-shadow: 0 0 0 3px rgba(var(--site-primary-rgb),0.1); }
    .price-pfx { padding: 10px 12px; background: #f9fafb; border-right: 1.5px solid #e5e7eb; font-size: 0.85rem; font-weight: 800; color: #6b7280; flex-shrink: 0; }
    .price-in  { border: none; outline: none; flex: 1; padding: 10px 12px; font-size: 0.875rem; font-family: 'DM Sans', sans-serif; color: #374151; }

    /* pill selectors */
    .pg { display: flex; flex-wrap: wrap; gap: 7px; }
    .po { padding: 7px 14px; border-radius: 50px; border: 1.5px solid #e5e7eb; background: #f9fafb; font-size: 0.78rem; font-weight: 600; color: #6b7280; cursor: pointer; transition: all 0.14s; user-select: none; display: inline-flex; align-items: center; gap: 5px; }
    .po:hover { border-color: var(--site-primary-light); background: var(--site-primary-pale); color: #374151; }
    .po.sel  { border-color: var(--site-primary-dark); background: var(--site-primary-dark); color: #fff; }
    .po input { display: none; }

    /* slots */
    .krow { display: flex; gap: 6px; flex-wrap: wrap; }
    .kmeta { font-size: 0.78rem; color: #6b7280; margin-top: 7px; }
    .kmeta strong { color: var(--site-primary-dark); }

    /* photos */
    .uzone { border: 2px dashed #d1d5db; border-radius: 14px; padding: 22px 16px; text-align: center; cursor: pointer; transition: all 0.18s; background: #fafafa; }
    .uzone:hover, .uzone.dov { border-color: var(--site-primary); background: var(--site-primary-pale); }
    .pgrid4 { display: grid; grid-template-columns: repeat(4,1fr); gap: 8px; margin-top: 10px; }
    .pcell { position: relative; aspect-ratio:1; border-radius: 10px; overflow: hidden; border: 1.5px solid #e5e7eb; background: #f3f4f6; }
    .pcell img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .pcell .prm { position: absolute; top: 4px; right: 4px; width: 20px; height: 20px; border-radius: 50%; background: rgba(0,0,0,0.55); color: #fff; border: none; font-size: 0.6rem; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background 0.12s; }
    .pcell .prm:hover { background: #ef4444; }
    .padd { aspect-ratio: 1; border-radius: 10px; border: 2px dashed #d1d5db; background: #f9fafb; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 3px; cursor: pointer; transition: all 0.14s; font-size: 0.68rem; color: #9ca3af; }
    .padd:hover { border-color: var(--site-primary); background: var(--site-primary-pale); color: var(--site-primary-dark); }
    .padd i { font-size: 1rem; }

    /* map */
    .mwrap { border-radius: 14px; overflow: hidden; border: 1.5px solid #e5e7eb; box-shadow: 0 2px 8px rgba(0,0,0,0.07); }
    .mtip { background: var(--site-primary-pale); border: 1px solid color-mix(in srgb, var(--site-primary) 30%, white); border-radius: 8px; padding: 9px 13px; font-size: 0.78rem; color: var(--site-primary-darker); margin-top: 8px; display: flex; align-items: center; gap: 7px; }

    .cc { font-size: 0.7rem; color: #9ca3af; text-align: right; margin-top: 3px; }
    .cc.warn { color: #f59e0b; } .cc.over { color: #ef4444; }

    .submit-btn { display: inline-flex; align-items: center; gap: 8px; padding: 13px 30px; background: linear-gradient(135deg, var(--site-primary-darker) 0%, var(--site-primary-dark) 100%); color: #fff; border: none; border-radius: 10px; font-size: 0.9rem; font-weight: 700; cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all 0.2s ease; box-shadow: 0 4px 14px rgba(var(--site-primary-rgb),0.3); }
    .submit-btn:hover { background: linear-gradient(135deg, color-mix(in srgb, var(--site-primary) 85%, black) 0%, var(--site-primary-darker) 100%); transform: translateY(-2px); box-shadow: 0 6px 20px rgba(var(--site-primary-rgb),0.4); }

    /* Toast */
    .toast-container { position: fixed; top: 80px; right: 24px; z-index: 999; display: flex; flex-direction: column; gap: 10px; pointer-events: none; }
    .toast-item { display: flex; align-items: center; gap: 12px; padding: 14px 18px; border-radius: 12px; font-size: 0.875rem; font-weight: 600; border: 1.5px solid transparent; box-shadow: 0 8px 24px rgba(0,0,0,0.12); pointer-events: auto; animation: toastIn 0.35s cubic-bezier(0.34,1.56,0.64,1) both; max-width: 340px; }
    .toast-item.toast-success { background: var(--site-primary-pale); border-color: color-mix(in srgb, var(--site-primary) 30%, white); color: var(--site-primary-dark); }
    .toast-item.toast-error   { background: #fef2f2; border-color: #fecaca; color: #dc2626; }
    .toast-item.toast-warning { background: #fefce8; border-color: #fde68a; color: #a16207; }
    .toast-item .toast-close { margin-left: auto; background: none; border: none; cursor: pointer; font-size: 0.75rem; opacity: 0.55; color: inherit; padding: 2px 4px; transition: opacity 0.15s; flex-shrink: 0; }
    .toast-item .toast-close:hover { opacity: 1; }
    @keyframes toastIn { from { opacity:0; transform: translateX(30px) scale(0.94); } to { opacity:1; transform: translateX(0) scale(1); } }
    @keyframes toastOut { from { opacity:1; transform: translateX(0); } to { opacity:0; transform: translateX(30px); } }
    .toast-item.out { animation: toastOut 0.3s ease forwards; }

    .section-title { font-size: 1.35rem; font-weight: 800; color: #111827; font-family: 'Playfair Display', serif; margin: 0; }
    .empty-state { text-align: center; padding: 32px 20px; color: #9ca3af; }

    @keyframes fadeUp { from { opacity:0; transform:translateY(14px); } to { opacity:1; transform:translateY(0); } }
    .f1 { animation: fadeUp 0.4s 0.05s ease both; }
    .f2 { animation: fadeUp 0.4s 0.12s ease both; }
    .f3 { animation: fadeUp 0.4s 0.20s ease both; }

    .emsg { font-size: 0.72rem; color: #ef4444; margin-top: 5px; display: none; }
    .emsg.on { display: block; }

    .g2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .g3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; }
    .fg { margin-bottom: 16px; }
    @media (max-width: 540px) { .g2,.g3 { grid-template-columns: 1fr; } .pgrid4 { grid-template-columns: repeat(2,1fr); } }

    /* Modal styles */
    .modal-overlay { position: fixed; inset: 0; z-index: 800; background: rgba(var(--site-primary-rgb),0.45); backdrop-filter: blur(4px); display: flex; align-items: flex-start; justify-content: center; padding: 16px; overflow-y: auto; opacity: 0; pointer-events: none; transition: opacity 0.22s; }
    .modal-overlay.open { opacity: 1; pointer-events: auto; }
    .modal { background: #fff; border-radius: 18px; width: 100%; max-width: 680px; box-shadow: 0 24px 60px rgba(var(--site-primary-rgb),0.22); transform: translateY(16px); transition: transform 0.25s cubic-bezier(0.4,0,0.2,1); margin: auto; display: flex; flex-direction: column; }
    .modal-overlay.open .modal { transform: translateY(0); }
    .modal-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px 14px; border-bottom: 1px solid #f3f4f6; flex-shrink: 0; gap: 8px; flex-wrap: wrap; }
    .modal-close { width: 30px; height: 30px; border-radius: 8px; border: none; background: #f3f4f6; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #6b7280; font-size: 0.78rem; transition: background 0.15s, color 0.15s; flex-shrink: 0; }
    .modal-close:hover { background: #fee2e2; color: #dc2626; }
    .modal-body { padding: 18px 20px; overflow-y: auto; max-height: calc(100vh - 180px); }
    .modal-body::-webkit-scrollbar { width: 4px; }
    .modal-body::-webkit-scrollbar-thumb { background: color-mix(in srgb, var(--site-primary-light) 50%, white); border-radius: 4px; }
    .modal-footer { display: grid; grid-template-columns: 1fr 1fr; border-top: 1px solid #f3f4f6; flex-shrink: 0; }
    .mf-btn { padding: 14px; border: none; font-size: 0.88rem; font-weight: 700; cursor: pointer; font-family: inherit; transition: all 0.15s; display: flex; align-items: center; justify-content: center; gap: 6px; }
    .mf-cancel { background: #f9fafb; color: #374151; border-radius: 0 0 0 18px; }
    .mf-cancel:hover { background: #e5e7eb; }
    .mf-update { background: var(--site-primary-dark); color: #fff; border-radius: 0 0 18px 0; }
    .mf-update:hover:not(:disabled) { background: var(--site-primary-darker); }
    .mf-update:disabled { background: #d1d5db; color: #9ca3af; cursor: not-allowed; }

    /* Section cards inside modal */
    .section-card { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px; margin-bottom: 14px; }
    .section-card:last-child { margin-bottom: 0; }
    .sc-title { display: flex; align-items: center; gap: 8px; font-size: 0.78rem; font-weight: 700; color: #374151; text-transform: uppercase; letter-spacing: 0.07em; margin-bottom: 14px; }
    .sc-icon { width: 26px; height: 26px; background: var(--site-primary-pale); border-radius: 7px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }

    /* Field styles inside modal */
    .field-label { display: block; font-size: 0.72rem; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 5px; }
    .field-input { width: 100%; padding: 9px 12px; border: 1.5px solid #e5e7eb; border-radius: 9px; font-size: 0.84rem; color: #374151; background: #fff; font-family: inherit; outline: none; transition: border-color 0.15s, box-shadow 0.15s; }
    .field-input:focus { border-color: var(--site-primary); box-shadow: 0 0 0 3px rgba(var(--site-primary-rgb),0.1); }
    .field-input.changed { border-color: #f59e0b; background: #fffbeb; }
    .field-textarea { width: 100%; padding: 9px 12px; border: 1.5px solid #e5e7eb; border-radius: 9px; font-size: 0.84rem; color: #374151; background: #fff; font-family: inherit; outline: none; transition: border-color 0.15s, box-shadow 0.15s; resize: vertical; min-height: 80px; }
    .field-textarea:focus { border-color: var(--site-primary); box-shadow: 0 0 0 3px rgba(var(--site-primary-rgb),0.1); }
    .field-textarea.changed { border-color: #f59e0b; background: #fffbeb; }
    .field-select { width: 100%; padding: 9px 12px; border: 1.5px solid #e5e7eb; border-radius: 9px; font-size: 0.84rem; color: #374151; background: #fff; font-family: inherit; outline: none; transition: border-color 0.15s, box-shadow 0.15s; appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%236b7280' stroke-width='2'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' d='M19 9l-7 7-7-7'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 10px center; background-size: 14px; padding-right: 34px; cursor: pointer; }
    .field-select:focus { border-color: var(--site-primary); box-shadow: 0 0 0 3px rgba(var(--site-primary-rgb),0.1); }
    .field-select.changed { border-color: #f59e0b; background-color: #fffbeb; }

    /* Changes badge */
    .changes-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 999px; background: #fef9c3; color: #a16207; font-size: 0.72rem; font-weight: 700; border: 1px solid #fde047; white-space: nowrap; }

    /* View detail rows */
    .view-row { display: flex; flex-direction: column; gap: 2px; }
    .view-label { font-size: 0.68rem; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.07em; }
    .view-value { font-size: 0.88rem; color: #1f2937; font-weight: 500; }
    .view-value.empty { color: #d1d5db; font-style: italic; }

    /* Tags in view modal */
    .tag { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 999px; font-size: 0.72rem; font-weight: 600; background: var(--site-primary-pale); color: var(--site-primary-dark); border: 1px solid color-mix(in srgb, var(--site-primary) 30%, white); margin: 2px; }

    /* Photo grid in view modal */
    .view-photos { display: grid; grid-template-columns: repeat(2,1fr); gap: 8px; }
    .view-photo { aspect-ratio: 4/3; border-radius: 10px; overflow: hidden; border: 1.5px solid #e5e7eb; cursor: pointer; }
    .view-photo img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.2s; }
    .view-photo:hover img { transform: scale(1.04); }

    /* Photo placeholder */
    .photo-placeholder-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; margin-top: 6px; }
    .photo-placeholder { aspect-ratio: 4/3; border: 1.5px dashed #d1d5db; border-radius: 10px; background: #f9fafb; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #9ca3af; font-size: 0.72rem; gap: 6px; cursor: pointer; transition: border-color 0.15s, background 0.15s; position: relative; overflow: hidden; }
    .photo-placeholder:hover { border-color: var(--site-primary); background: var(--site-primary-pale); }
    .photo-placeholder img { width: 100%; height: 100%; object-fit: cover; position: absolute; inset: 0; border-radius: 9px; display: block; }
    .photo-placeholder .ph-inner { display: flex; flex-direction: column; align-items: center; gap: 6px; }

    /* Confirm Dialog */
    .dialog-overlay { position: fixed; inset: 0; z-index: 900; background: rgba(var(--site-primary-rgb),0.45); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; padding: 20px; opacity: 0; pointer-events: none; transition: opacity 0.2s; }
    .dialog-overlay.open { opacity: 1; pointer-events: auto; }
    .dialog-box { background: #fff; border-radius: 20px; width: 100%; max-width: 400px; box-shadow: 0 24px 64px rgba(var(--site-primary-rgb),0.3), 0 4px 16px rgba(0,0,0,0.08); transform: scale(0.94) translateY(12px); transition: transform 0.25s cubic-bezier(0.34,1.56,0.64,1), opacity 0.2s; opacity: 0; overflow: hidden; }
    .dialog-overlay.open .dialog-box { transform: scale(1) translateY(0); opacity: 1; }
    .dialog-icon-wrap { width: 64px; height: 64px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 1.6rem; }
    .dialog-icon-danger  { background: #fee2e2; color: #dc2626; }
    .dialog-icon-info    { background: #dbeafe; color: #2563eb; }
    .dialog-body-inner { padding: 28px 24px 20px; text-align: center; }
    .dialog-title  { font-size: 1.1rem; font-weight: 800; color: #111827; margin-bottom: 8px; font-family: 'Playfair Display', serif; }
    .dialog-desc   { font-size: 0.85rem; color: #6b7280; line-height: 1.5; }
    .dialog-name-badge { display: inline-block; margin-top: 10px; background: #f3f4f6; border-radius: 8px; padding: 6px 14px; font-size: 0.82rem; font-weight: 700; color: #374151; }
    .dialog-footer { padding: 0 20px 20px; display: flex; gap: 10px; }
    .dbtn { flex: 1; padding: 11px; border-radius: 11px; border: none; font-size: 0.86rem; font-weight: 700; cursor: pointer; font-family: inherit; transition: all 0.15s; display: flex; align-items: center; justify-content: center; gap: 6px; }
    .dbtn-cancel  { background: #f3f4f6; color: #374151; }
    .dbtn-cancel:hover { background: #e5e7eb; }
    .dbtn-confirm { background: #ef4444; color: #fff; box-shadow: 0 4px 14px rgba(239,68,68,0.3); }
    .dbtn-confirm:hover { background: #dc2626; transform: translateY(-1px); }

    /* Lightbox */
    .lightbox { position: fixed; inset: 0; z-index: 1000; background: rgba(0,0,0,0.85); display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity 0.2s; padding: 20px; }
    .lightbox.open { opacity: 1; pointer-events: auto; }
    .lightbox img { max-width: 90vw; max-height: 88vh; border-radius: 12px; box-shadow: 0 20px 60px rgba(0,0,0,0.4); }
    .lightbox-close { position: absolute; top: 16px; right: 20px; background: rgba(255,255,255,0.12); border: none; color: #fff; font-size: 1.1rem; width: 36px; height: 36px; border-radius: 10px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background 0.15s; }
    .lightbox-close:hover { background: rgba(255,255,255,0.22); }

    /* Info badge on view modal */
    .info-badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 999px; font-size: 0.72rem; font-weight: 700; }
    .badge-apt  { background: #eff6ff; color: #1d4ed8; border: 1px solid #93c5fd; }
    .badge-biz  { background: #fefce8; color: #a16207; border: 1px solid #fde047; }
    .badge-avail { background: var(--site-primary-pale); color: var(--site-primary-dark); border: 1px solid color-mix(in srgb, var(--site-primary) 40%, white); }

    /* Edit photo grid in modal */
    .edit-pgrid { display: grid; grid-template-columns: repeat(2,1fr); gap: 8px; }
    @media (min-width: 480px) { .edit-pgrid { grid-template-columns: repeat(4,1fr); } }

    :root {
      --site-primary-dark:   color-mix(in srgb, var(--site-primary) 55%, black);
      --site-primary-darker: color-mix(in srgb, var(--site-primary) 75%, black);
      --site-primary-light:  color-mix(in srgb, var(--site-primary) 55%, white);
      --site-primary-pale:   color-mix(in srgb, var(--site-primary) 12%, white);
    }

    /* Tailwind-green ? theme color overrides */
    .bg-green-700 { background-color: var(--site-primary) !important; }
    .bg-green-950 { background-color: var(--site-primary-darker) !important; }
    .text-green-200 { color: color-mix(in srgb, var(--site-primary-light) 60%, white) !important; }
    .text-green-300 { color: color-mix(in srgb, var(--site-primary-light) 70%, white) !important; }
    .text-green-400 { color: var(--site-primary-light) !important; }
    .text-green-500 { color: var(--site-primary) !important; }
    .text-green-600 { color: var(--site-primary) !important; }
    .text-green-700 { color: var(--site-primary) !important; }
    .text-green-900 { color: var(--site-primary-darker) !important; }
    .from-green-50 { --tw-gradient-from: var(--site-primary-pale) var(--tw-gradient-from-position) !important; --tw-gradient-to: rgb(255 255 255 / 0) var(--tw-gradient-to-position) !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to) !important; }
    .to-emerald-50 { --tw-gradient-to: var(--site-primary-pale) var(--tw-gradient-to-position) !important; }
    .hover\:bg-green-50:hover { background-color: var(--site-primary-pale) !important; }
    .hover\:border-green-300:hover { border-color: color-mix(in srgb, var(--site-primary-light) 60%, white) !important; }
    .hover\:text-green-700:hover { color: var(--site-primary) !important; }
    .hover\:text-green-800:hover { color: var(--site-primary-darker) !important; }
    .focus\:ring-green-400:focus { --tw-ring-color: var(--site-primary-light) !important; }

    /* Footer text: always light/white regardless of theme hue */
    footer .text-green-300 { color: rgba(255,255,255,0.75) !important; }
    footer .text-green-400 { color: rgba(255,255,255,0.65) !important; }
    footer .text-green-500 { color: rgba(255,255,255,0.45) !important; }
    footer .hover\:text-white:hover { color: #ffffff !important; }
    footer .border-green-800 { border-color: rgba(255,255,255,0.12) !important; }

    /* ─── Responsive mobile navbar ─── */
    .mobile-menu-btn { display: none; }
    @media (max-width: 767px) {
      header nav.desktop-nav { display: none; }
      .mobile-menu-btn { display: flex; }
    }
  </style>
    <link rel="stylesheet" href="dist/output.css">
</head>
<body>

<!-- NAVBAR -->
<header class="w-full h-[68px] border-b border-gray-100 flex items-center px-4 sm:px-6 lg:px-8 bg-white shadow-sm sticky top-0 z-50">
  <div class="flex items-center gap-3 flex-shrink-0">
    <a href="nonresidentLanding.php" class="flex items-center gap-3">
      <div class="w-10 h-10 rounded-full bg-green-700 flex items-center justify-center shadow overflow-hidden">
        <img src="<?= e(site_config_logo_url($siteSettings, '../')) ?>" alt="Logo" class="w-full h-full object-contain"/>
      </div>
      <div class="sm:block">
        <h3 class="font-bold text-green-900 text-base leading-tight"><?= e($siteSettings['site_title']) ?></h3>
        <p class="text-[10px] text-green-600 tracking-widest uppercase"><?= e($siteSettings['barangay_name']) ?></p>
      </div>
    </a>
  </div>

  <nav class="desktop-nav ml-auto flex gap-8 text-gray-600 text-sm font-medium items-center">
    <a href="nonresidentLanding.php#announcements" class="nav-link">Announcements</a>
    <a href="../busaptListing.php?type=business" class="nav-link">Business</a>
    <a href="../busaptListing.php?type=apartment" class="nav-link">Apartments</a>
    <?php $roleLower = strtolower($role); ?>
    <?php if ($logged_in): ?>
      <div class="relative" id="profile-menu-wrapper">
        <button id="profile-btn" onclick="toggleProfileMenu()"
          class="flex items-center gap-2 px-3 py-1.5 rounded-xl border border-gray-200 hover:border-green-300 hover:bg-green-50 transition focus:outline-none focus:ring-2 focus:ring-green-400">
          <span class="w-8 h-8 rounded-full bg-green-700 text-white flex items-center justify-center text-xs font-bold select-none"><?= htmlspecialchars($initials) ?></span>
          <span class="hidden lg:block text-gray-700 text-sm max-w-[140px] truncate"><?= htmlspecialchars($userName) ?></span>
          <svg id="profile-chevron" class="w-4 h-4 text-gray-400 transition-transform duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
        </button>
        <div id="profile-dropdown" class="hidden absolute right-0 mt-2 w-64 bg-white rounded-2xl shadow-xl border border-gray-100 overflow-hidden z-50">
          <div class="px-4 py-3 bg-gradient-to-br from-green-50 to-emerald-50 border-b border-gray-100">
            <div class="flex items-center gap-3">
              <span class="w-10 h-10 rounded-full bg-green-700 text-white flex items-center justify-center text-sm font-bold"><?= htmlspecialchars($initials) ?></span>
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
                      $profileUrl = 'nonresidentProfile.php';
                  } elseif (str_contains($roleLower, 'resident')) {
                      $profileUrl = '../resident/myProfile';
                  }
                ?>
                <a href="<?= htmlspecialchars($profileUrl) ?>" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-green-50 hover:text-green-800 transition">
                  <i class="fa-solid fa-user w-4 text-gray-400"></i> My Profile
                </a>
              <?php endif; ?>
          </div>
          <div class="border-t border-gray-100 py-1">
            <a href="../logout.php" class="flex items-center gap-3 px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition"><i class="fa-solid fa-arrow-right-from-bracket w-4"></i> Logout</a>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </nav>

  <!-- Mobile hamburger -->
  <button id="mobile-menu-btn"
    class="mobile-menu-btn items-center justify-center p-2 text-gray-600 hover:text-green-700 hover:bg-green-50 rounded-lg transition ml-auto"
    aria-label="Toggle menu">
    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
    </svg>
  </button>
</header>

<!-- ══════════════════════════ MOBILE SIDEBAR ══════════════════════════ -->
<div id="mobile-sidebar-overlay" class="fixed inset-0 bg-black/50 z-[60] hidden opacity-0 transition-opacity duration-300"></div>
<div id="mobile-sidebar" class="fixed inset-y-0 right-0 w-72 max-w-[85vw] bg-white shadow-2xl transform translate-x-full transition-transform duration-300 z-[70] flex flex-col">
  <div class="p-4 border-b border-gray-100 flex items-center justify-between">
    <div class="flex items-center gap-2">
      <?php if ($logged_in): ?>
        <span class="w-8 h-8 rounded-full bg-green-700 text-white flex items-center justify-center text-xs font-bold"><?= htmlspecialchars($initials) ?></span>
        <div>
          <p class="text-sm font-semibold text-gray-800 truncate max-w-[140px]"><?= htmlspecialchars($userName) ?></p>
          <span class="text-[10px] font-semibold <?= $roleBadgeClass ?> px-1.5 py-0.5 rounded-full"><?= htmlspecialchars($roleLabel) ?></span>
        </div>
      <?php else: ?>
        <h3 class="font-bold text-green-900">Menu</h3>
      <?php endif; ?>
    </div>
    <button id="mobile-menu-close" class="p-2 text-gray-500 hover:text-red-500 rounded-full hover:bg-red-50 transition">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
      </svg>
    </button>
  </div>
  <div class="flex-1 overflow-y-auto py-3 px-3 space-y-0.5">
    <a href="nonresidentLanding.php#announcements" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 font-medium hover:bg-green-50 hover:text-green-700 transition">
      <i class="fa-solid fa-bullhorn w-4 text-green-500"></i> Announcements
    </a>
    <a href="../busaptListing.php?type=business" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 font-medium hover:bg-green-50 hover:text-green-700 transition">
      <i class="fa-solid fa-store w-4 text-green-500"></i> Business
    </a>
    <a href="../busaptListing.php?type=apartment" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 font-medium hover:bg-green-50 hover:text-green-700 transition">
      <i class="fa-solid fa-building w-4 text-green-500"></i> Apartments
    </a>
    <?php if ($logged_in): ?>
    <div class="pt-2 border-t border-gray-100 mt-2 space-y-0.5">
      <a href="<?= htmlspecialchars($profileUrl) ?>" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 font-medium hover:bg-green-50 hover:text-green-700 transition">
        <i class="fa-solid fa-user w-4 text-gray-400"></i> My Profile
      </a>
      <a href="../logout.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-red-600 font-medium hover:bg-red-50 transition">
        <i class="fa-solid fa-arrow-right-from-bracket w-4"></i> Logout
      </a>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- TOAST CONTAINER -->
<div class="toast-container" id="toastContainer">
  <?php if ($toastMsg): ?>
  <div class="toast-item toast-<?= $toastType ?>" id="php-toast">
    <i class="fa-solid <?= $toastType === 'success' ? 'fa-circle-check' : ($toastType === 'error' ? 'fa-circle-xmark' : 'fa-triangle-exclamation') ?>"></i>
    <span><?= htmlspecialchars($toastMsg) ?></span>
    <button class="toast-close" onclick="dismissToast(this.parentElement)"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <?php endif; ?>
</div>

<main class="max-w-3xl mx-auto px-4 py-10 space-y-10">

  <div class="f1">
    <a href="nonresidentLanding.php" class="inline-flex items-center gap-2 text-gray-500 hover:text-green-700 text-sm font-medium transition">
      <i class="fa-solid fa-arrow-left text-xs"></i> Back
    </a>
  </div>

  <!-- ACTIVE LISTINGS -->
  <section class="f1">
    <h2 class="section-title mb-5">Active Listings</h2>
    <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
      <div class="flex border-b border-gray-200 bg-gray-50">
        <button class="tab-btn active" id="tab-apartment" onclick="switchTab('apartment')"><i class="fa-solid fa-building mr-2 text-xs"></i>Apartment</button>
        <button class="tab-btn" id="tab-business" onclick="switchTab('business')"><i class="fa-solid fa-store mr-2 text-xs"></i>Business</button>
      </div>

      <!-- APARTMENT TAB -->
      <div id="panel-apartment">
        <?php if (!empty($apartmentListings)): ?>
        <table class="lt">
          <thead><tr><th>Name</th><th>Date Submitted</th><th style="text-align:right">Actions</th></tr></thead>
          <tbody>
            <?php foreach ($apartmentListings as $l): ?>
            <tr data-listing='<?= rowJsonSafe($l) ?>'>
              <td class="font-medium text-gray-800"><?= htmlspecialchars($l['display_name']) ?></td>
              <td class="text-gray-500"><?= htmlspecialchars($l['date']) ?></td>
              <td><div class="flex items-center gap-2 justify-end">
                <button class="av" onclick="openViewModal(this.closest('tr'))">View</button>
                <button class="ai ai-e" onclick="openEditModal(this.closest('tr'))"><i class="fa-solid fa-pen-to-square"></i></button>
                <button class="ai ai-d" onclick="confirmDelete(<?= (int)$l['id'] ?>, '<?= htmlspecialchars(addslashes($l['display_name'])) ?>')"><i class="fa-solid fa-trash"></i></button>
              </div></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state"><i class="fa-solid fa-building text-3xl text-gray-300 mb-3 block"></i><p class="font-semibold text-gray-400">No apartment listings yet</p></div>
        <?php endif; ?>
      </div>

      <!-- BUSINESS TAB -->
      <div id="panel-business" class="hidden">
        <?php if (!empty($businessListings)): ?>
        <table class="lt">
          <thead><tr><th>Name</th><th>Date Submitted</th><th style="text-align:right">Actions</th></tr></thead>
          <tbody>
            <?php foreach ($businessListings as $l): ?>
            <tr data-listing='<?= rowJsonSafe($l) ?>'>
              <td class="font-medium text-gray-800"><?= htmlspecialchars($l['display_name']) ?></td>
              <td class="text-gray-500"><?= htmlspecialchars($l['date']) ?></td>
              <td><div class="flex items-center gap-2 justify-end">
                <button class="av" onclick="openViewModal(this.closest('tr'))">View</button>
                <button class="ai ai-e" onclick="openEditModal(this.closest('tr'))"><i class="fa-solid fa-pen-to-square"></i></button>
                <button class="ai ai-d" onclick="confirmDelete(<?= (int)$l['id'] ?>, '<?= htmlspecialchars(addslashes($l['display_name'])) ?>')"><i class="fa-solid fa-trash"></i></button>
              </div></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state"><i class="fa-solid fa-store text-3xl text-gray-300 mb-3 block"></i><p class="font-semibold text-gray-400">No business listings yet</p></div>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <!-- CREATE NEW POST -->
  <section class="f2">
    <h2 class="section-title mb-5">Create new post</h2>
    <?php if ($userStatus === 'rejected'): ?>
    <div class="notice-banner notice-banner-rejected mb-6">
      <i class="fa-solid fa-circle-xmark text-sm mt-0.5 flex-shrink-0"></i>
      <p class="text-sm leading-relaxed">Your account application has been rejected. Please update your profile information and submit again for review.</p>
    </div>
    <?php elseif (!$canAccessServices): ?>
    <div class="notice-banner mb-6">
      <i class="fa-solid fa-circle-info text-amber-500 text-sm mt-0.5 flex-shrink-0"></i>
      <p class="text-sm text-amber-800 leading-relaxed">Your account is pending verification. Listing creation is restricted until approval.</p>
    </div>
    <?php else: ?>
    <div class="notice-banner mb-6">
      <i class="fa-solid fa-circle-info text-amber-500 text-sm mt-0.5 flex-shrink-0"></i>
      <p class="text-sm text-amber-800 leading-relaxed">By posting, you certify that all details are <strong>accurate and truthful</strong>. Inaccurate listings will be removed by the Barangay Admin without notice.</p>
    </div>
    <?php endif; ?>

    <div class="form-card f3<?php if (!$canAccessServices) echo ' form-disabled'; ?>">
      <div class="form-header">
        <p class="text-white font-bold text-lg" style="font-family:'Playfair Display',serif;"><?= e($siteSettings['site_title']) ?> - Listing Form</p>
        <p class="text-green-200 text-xs mt-1">Fill in your listing details below</p>
      </div>

      <div class="form-body">
        <form method="POST" action="save_listing.php" enctype="multipart/form-data" id="listingForm" novalidate>
          <input type="hidden" name="submit_listing" value="1">
          <input type="hidden" name="listing_type"    id="fld_type"    value="">
          <input type="hidden" name="listing_subtype" id="fld_subtype" value="">
          <input type="hidden" name="slots_available" id="fld_key"     value="">

          <!-- STEP 1: TYPE -->
          <div class="sdiv"><span><i class="fa-solid fa-list-check" style="margin-right:5px;"></i>Step 1  –  What are you listing?</span></div>
          <div style="display:flex;gap:12px;">
            <div class="type-card" id="tc-apt" onclick="chooseType('apt')">
              <span class="tc-icon"><i class="fa-solid fa-building"></i></span>
              <div class="tc-title">Apartment / Room</div>
              <div class="tc-desc">Bed spacer, studio, solo room, units for rent</div>
              <div class="tc-check" id="chk-apt"><i class="fa-solid fa-check" style="font-size:0.6rem;"></i></div>
            </div>
            <div class="type-card" id="tc-biz" onclick="chooseType('biz')">
              <span class="tc-icon"><i class="fa-solid fa-store"></i></span>
              <div class="tc-title">Business</div>
              <div class="tc-desc">Food stall, water station, retail, services &amp; more</div>
              <div class="tc-check" id="chk-biz"><i class="fa-solid fa-check" style="font-size:0.6rem;"></i></div>
            </div>
          </div>
          <p class="emsg" id="err-type">Please choose a listing type to continue.</p>

          <!-- APARTMENT PANEL -->
          <div class="dyn-panel" id="panel-apt">
            <div class="sdiv"><span><i class="fa-solid fa-building"></i> Room / Unit Type</span></div>
            <div class="fg">
              <label class="fl"><span class="req">*</span>What type of room or unit is this?</label>
              <div class="pg" id="apt-type-pg">
                <label class="po"><input type="radio" name="apt_type" value="bed-spacer" onchange="pickRadio(this,'err-apt-type')"><i class="fa-solid fa-bed"></i> Bed Spacer</label>
                <label class="po"><input type="radio" name="apt_type" value="studio"    onchange="pickRadio(this,'err-apt-type')"><i class="fa-solid fa-couch"></i> Studio Type</label>
                <label class="po"><input type="radio" name="apt_type" value="solo-room" onchange="pickRadio(this,'err-apt-type')"><i class="fa-solid fa-door-closed"></i> Solo Room</label>
                <label class="po"><input type="radio" name="apt_type" value="1br"       onchange="pickRadio(this,'err-apt-type')"><i class="fa-solid fa-house"></i> 1-Bedroom</label>
                <label class="po"><input type="radio" name="apt_type" value="2br"       onchange="pickRadio(this,'err-apt-type')"><i class="fa-solid fa-house-chimney"></i> 2-Bedroom</label>
                <label class="po"><input type="radio" name="apt_type" value="whole-unit" onchange="pickRadio(this,'err-apt-type')"><i class="fa-solid fa-building"></i> Whole Unit</label>
              </div>
              <p class="emsg" id="err-apt-type">Please select a room type.</p>
            </div>
            <div class="sdiv"><span><i class="fa-solid fa-list-check"></i> Basic Information</span></div>
            <div class="g2 fg">
              <div><label class="fl"><span class="req">*</span>Listing Title:</label><input type="text" name="apt_title" class="fi" placeholder="e.g. Cozy Studio near Market" maxlength="80" oninput="charCount(this,'cc-apt-t',80)"><div class="cc" id="cc-apt-t">0 / 80</div></div>
              <div><label class="fl"><span class="req">*</span>Availability:</label>
                <select name="apt_status" class="fs">
                  <option value="" disabled selected>-- Select --</option>
                  <option value="available">Available</option>
                  <option value="occupied">Fully Occupied</option>
                  <option value="inquire">Inquire First</option>
                </select>
              </div>
            </div>
            <div class="g2 fg">
              <div><label class="fl"><span class="req">*</span>Monthly Rent:</label><div class="price-wrap"><span class="price-pfx">₱</span><input type="text" name="apt_price" class="price-in" placeholder="e.g. 3,500"></div></div>
              <div><label class="fl">Floor / Level: <span class="hint">(optional)</span></label><input type="text" name="apt_floor" class="fi" placeholder="e.g. 2nd Floor"></div>
            </div>
            <div class="sdiv"><span><i class="fa-solid fa-bed"></i> Room Specifications</span></div>
            <div class="g3 fg">
              <div><label class="fl">No. of Rooms:</label><input type="number" name="apt_rooms" class="fi" placeholder="e.g. 1" min="1" max="20"></div>
              <div><label class="fl">Max Occupants:</label><input type="number" name="apt_occupants" class="fi" placeholder="e.g. 2" min="1" max="30"></div>
              <div><label class="fl">Bathroom:</label>
                <select name="apt_bath" class="fs">
                  <option value="">-- Select --</option>
                  <option value="private">Private</option>
                  <option value="shared">Shared</option>
                </select>
              </div>
            </div>
            <div class="sdiv"><span><i class="fa-solid fa-hashtag"></i> Slots Available</span></div>
            <div class="fg">
              <label class="fl"><span class="req">*</span>How many units / slots are open?</label>
              <div style="max-width: 170px;" class="price-wrap">
                <span class="price-pfx">#</span>
                <input type="number" name="apt_slots" id="apt_slots" class="price-in" min="1" step="1" value="" oninput="updateSlotKey(this.value)">
              </div>
              <div class="kmeta" id="apt-kmeta">Enter a number (1 or more)</div>
              <p class="emsg" id="err-apt-key">Please enter number of slots available (minimum 1).</p>
            </div>
            <div class="sdiv"><span><i class="fa-solid fa-lightbulb"></i> Included in Rent</span></div>
            <div class="fg"><div class="pg">
              <label class="po"><input type="checkbox" name="apt_inc[]" value="electric" onchange="toggleCb(this)"><i class="fa-solid fa-bolt"></i> Electricity</label>
              <label class="po"><input type="checkbox" name="apt_inc[]" value="water"    onchange="toggleCb(this)"><i class="fa-solid fa-faucet"></i> Water</label>
              <label class="po"><input type="checkbox" name="apt_inc[]" value="wifi"     onchange="toggleCb(this)"><i class="fa-solid fa-wifi"></i> WiFi</label>
              <label class="po"><input type="checkbox" name="apt_inc[]" value="cable"    onchange="toggleCb(this)"><i class="fa-solid fa-tv"></i> Cable TV</label>
            </div></div>
            <div class="sdiv"><span><i class="fa-solid fa-house"></i> Amenities</span></div>
            <div class="fg"><div class="pg">
              <label class="po"><input type="checkbox" name="apt_amn[]" value="aircon"   onchange="toggleCb(this)"><i class="fa-solid fa-snowflake"></i> Aircon</label>
              <label class="po"><input type="checkbox" name="apt_amn[]" value="fan"      onchange="toggleCb(this)"><i class="fa-solid fa-fan"></i> Electric Fan</label>
              <label class="po"><input type="checkbox" name="apt_amn[]" value="parking"  onchange="toggleCb(this)"><i class="fa-solid fa-car"></i> Parking</label>
              <label class="po"><input type="checkbox" name="apt_amn[]" value="laundry"  onchange="toggleCb(this)"><i class="fa-solid fa-shirt"></i> Laundry Area</label>
              <label class="po"><input type="checkbox" name="apt_amn[]" value="cctv"     onchange="toggleCb(this)"><i class="fa-solid fa-camera"></i> CCTV</label>
              <label class="po"><input type="checkbox" name="apt_amn[]" value="security" onchange="toggleCb(this)"><i class="fa-solid fa-shield-halved"></i> Security</label>
              <label class="po"><input type="checkbox" name="apt_amn[]" value="kitchen"  onchange="toggleCb(this)"><i class="fa-solid fa-kitchen-set"></i> Shared Kitchen</label>
              <label class="po"><input type="checkbox" name="apt_amn[]" value="gate"     onchange="toggleCb(this)"><i class="fa-solid fa-lock"></i> Gated Compound</label>
            </div></div>
            <div class="sdiv"><span><i class="fa-solid fa-scroll"></i> House Rules</span></div>
            <div class="fg"><div class="pg">
              <label class="po"><input type="checkbox" name="apt_rules[]" value="no-smoking"  onchange="toggleCb(this)"><i class="fa-solid fa-ban-smoking"></i> No Smoking</label>
              <label class="po"><input type="checkbox" name="apt_rules[]" value="no-pets"     onchange="toggleCb(this)"><i class="fa-solid fa-paw"></i> No Pets</label>
              <label class="po"><input type="checkbox" name="apt_rules[]" value="no-visitors" onchange="toggleCb(this)"><i class="fa-solid fa-user-slash"></i> No Overnight Visitors</label>
              <label class="po"><input type="checkbox" name="apt_rules[]" value="curfew"      onchange="toggleCb(this)"><i class="fa-solid fa-moon"></i> Curfew Policy</label>
              <label class="po"><input type="checkbox" name="apt_rules[]" value="no-cooking"  onchange="toggleCb(this)"><i class="fa-solid fa-fire-burner"></i> No Cooking Inside</label>
            </div></div>
            <div class="fg">
              <label class="fl">Description: <span class="hint">(optional)</span></label>
              <textarea name="apt_desc" id="apt_desc" class="fta" placeholder="Describe the unit  –  surroundings, vibe, what's nearby..." maxlength="500" oninput="charCount(this,'cc-apt-d',500)"></textarea>
              <div class="cc" id="cc-apt-d">0 / 500</div>
            </div>
          </div><!-- /panel-apt -->

          <!-- BUSINESS PANEL -->
          <div class="dyn-panel" id="panel-biz">
            <div class="sdiv"><span><i class="fa-solid fa-store"></i> Business Category</span></div>
            <div class="fg">
              <label class="fl"><span class="req">*</span>What kind of business is this?</label>
              <div class="pg" id="buss-cat-pg">
                <label class="po"><input type="radio" name="buss_cat" value="food"      onchange="pickRadio(this,'err-buss-cat')"><i class="fa-solid fa-utensils"></i> Food &amp; Dining</label>
                <label class="po"><input type="radio" name="buss_cat" value="water"     onchange="pickRadio(this,'err-buss-cat')"><i class="fa-solid fa-droplet"></i> Water Station</label>
                <label class="po"><input type="radio" name="buss_cat" value="sari-sari" onchange="pickRadio(this,'err-buss-cat')"><i class="fa-solid fa-store"></i> Sari-Sari Store</label>
                <label class="po"><input type="radio" name="buss_cat" value="salon"     onchange="pickRadio(this,'err-buss-cat')"><i class="fa-solid fa-scissors"></i> Salon / Barber</label>
                <label class="po"><input type="radio" name="buss_cat" value="laundry"   onchange="pickRadio(this,'err-buss-cat')"><i class="fa-solid fa-shirt"></i> Laundry Shop</label>
                <label class="po"><input type="radio" name="buss_cat" value="pharmacy"  onchange="pickRadio(this,'err-buss-cat')"><i class="fa-solid fa-pills"></i> Pharmacy</label>
                <label class="po"><input type="radio" name="buss_cat" value="printing"  onchange="pickRadio(this,'err-buss-cat')"><i class="fa-solid fa-print"></i> Printing / Computer Shop</label>
                <label class="po"><input type="radio" name="buss_cat" value="bakery"    onchange="pickRadio(this,'err-buss-cat')"><i class="fa-solid fa-bread-slice"></i> Bakery / Café</label>
                <label class="po"><input type="radio" name="buss_cat" value="hardware"  onchange="pickRadio(this,'err-buss-cat')"><i class="fa-solid fa-screwdriver-wrench"></i> Hardware</label>
                <label class="po"><input type="radio" name="buss_cat" value="other"     onchange="pickRadio(this,'err-buss-cat')"><i class="fa-solid fa-ellipsis"></i> Other</label>
              </div>
              <p class="emsg" id="err-buss-cat">Please select a business category.</p>
            </div>
            <div class="sdiv"><span><i class="fa-solid fa-list-check"></i> Business Information</span></div>
            <div class="g2 fg">
              <div><label class="fl"><span class="req">*</span>Business Name:</label><input type="text" name="buss_name" class="fi" placeholder="e.g. Aling Nena's Carinderia" maxlength="80" oninput="charCount(this,'cc-buss-n',80)"><div class="cc" id="cc-buss-n">0 / 80</div></div>
              <div><label class="fl"><span class="req">*</span>Current Status:</label>
                <select name="buss_status" class="fs">
                  <option value="" disabled selected>-- Select --</option>
                  <option value="open">Open / Operating</option>
                  <option value="new">Newly Opened</option>
                  <option value="temp-closed">Temporarily Closed</option>
                  <option value="for-rent">Space for Rent</option>
                </select>
              </div>
            </div>
            <div class="g2 fg">
              <div><label class="fl">Starting Price / Rate: <span class="hint">(optional)</span></label><div class="price-wrap"><span class="price-pfx">₱</span><input type="text" name="buss_price" class="price-in" placeholder="e.g. 30 per load"></div></div>
              <div><label class="fl">Years in Business: <span class="hint">(optional)</span></label>
                <select name="buss_years" class="fs">
                  <option value="">-- Select --</option>
                  <option value="new">Just opened</option>
                  <option value="1">1 year</option>
                  <option value="2-5">2 – 5 years</option>
                  <option value="5-10">5 – 10 years</option>
                  <option value="10+">10+ years</option>
                </select>
              </div>
            </div>
            <div class="sdiv"><span><i class="fa-solid fa-clock"></i> Operating Hours</span></div>
            <div class="g2 fg">
              <div><label class="fl">Opening Time: <span class="hint">(optional)</span></label><input type="time" name="buss_open" class="fi"></div>
              <div><label class="fl">Closing Time: <span class="hint">(optional)</span></label><input type="time" name="buss_close" class="fi"></div>
            </div>
            <div class="fg">
              <label class="fl">Days Open: <span class="hint">(optional)</span></label>
              <div class="pg">
                <label class="po"><input type="checkbox" name="buss_days[]" value="mon"     onchange="toggleCb(this)">Mon</label>
                <label class="po"><input type="checkbox" name="buss_days[]" value="tue"     onchange="toggleCb(this)">Tue</label>
                <label class="po"><input type="checkbox" name="buss_days[]" value="wed"     onchange="toggleCb(this)">Wed</label>
                <label class="po"><input type="checkbox" name="buss_days[]" value="thu"     onchange="toggleCb(this)">Thu</label>
                <label class="po"><input type="checkbox" name="buss_days[]" value="fri"     onchange="toggleCb(this)">Fri</label>
                <label class="po"><input type="checkbox" name="buss_days[]" value="sat"     onchange="toggleCb(this)">Sat</label>
                <label class="po"><input type="checkbox" name="buss_days[]" value="sun"     onchange="toggleCb(this)">Sun</label>
                <label class="po"><input type="checkbox" name="buss_days[]" value="holiday" onchange="toggleCb(this)">Holidays</label>
              </div>
            </div>
            <div class="sdiv"><span><i class="fa-solid fa-sparkles"></i> Business Features</span></div>
            <div class="fg"><div class="pg">
              <label class="po"><input type="checkbox" name="buss_feat[]" value="delivery" onchange="toggleCb(this)"><i class="fa-solid fa-motorcycle"></i> Delivery</label>
              <label class="po"><input type="checkbox" name="buss_feat[]" value="pickup"   onchange="toggleCb(this)"><i class="fa-solid fa-bag-shopping"></i> Pick-up</label>
              <label class="po"><input type="checkbox" name="buss_feat[]" value="dine-in"  onchange="toggleCb(this)"><i class="fa-solid fa-chair"></i> Dine-in</label>
              <label class="po"><input type="checkbox" name="buss_feat[]" value="parking"  onchange="toggleCb(this)"><i class="fa-solid fa-car"></i> Parking</label>
              <label class="po"><input type="checkbox" name="buss_feat[]" value="gcash"    onchange="toggleCb(this)"><i class="fa-solid fa-mobile-screen"></i> GCash</label>
              <label class="po"><input type="checkbox" name="buss_feat[]" value="maya"     onchange="toggleCb(this)"><i class="fa-solid fa-mobile-screen"></i> Maya</label>
              <label class="po"><input type="checkbox" name="buss_feat[]" value="wifi"     onchange="toggleCb(this)"><i class="fa-solid fa-wifi"></i> Free WiFi</label>
              <label class="po"><input type="checkbox" name="buss_feat[]" value="aircon"   onchange="toggleCb(this)"><i class="fa-solid fa-snowflake"></i> Aircon</label>
            </div></div>
            <div class="fg">
              <label class="fl">Business Description: <span class="hint">(optional)</span></label>
              <textarea name="buss_desc" id="buss_desc" class="fta" placeholder="What do you offer? Specialties, promos, what makes you different..." maxlength="500" oninput="charCount(this,'cc-buss-d',500)"></textarea>
              <div class="cc" id="cc-buss-d">0 / 500</div>
            </div>
          </div><!-- /panel-biz -->

          <!-- SHARED FIELDS -->
          <div id="shared-sec" style="display:none;">
            <div class="sdiv"><span><i class="fa-solid fa-phone"></i> Contact Information</span></div>
            <div class="g2 fg">
              <div><label class="fl"><span class="req">*</span>Contact Number:</label><input type="text" name="contact" class="fi" placeholder="e.g. 0917-123-4567"></div>
              <div><label class="fl">Email Address: <span class="hint">(optional)</span></label><input type="email" name="email" class="fi" placeholder="e.g. owner@email.com"></div>
            </div>
            <div class="sdiv"><span><i class="fa-solid fa-location-dot"></i> Address</span></div>
            <div class="g2 fg">
              <div><label class="fl"><span class="req">*</span>House / Unit #:</label><input type="text" name="house_num" class="fi" placeholder="e.g. 123"></div>
              <div><label class="fl"><span class="req">*</span>Street / Block / Lot:</label><input type="text" name="street" class="fi" placeholder="e.g. Rizal Street"></div>
            </div>
            <div class="g2 fg">
              <div><label class="fl">Barangay:</label><input type="text" name="barangay" class="fi" value="<?= e($siteSettings['barangay_name']) ?>" disabled></div>
              <div><label class="fl">Municipality / City:</label><input type="text" name="city" class="fi" value="Cabanatuan City" disabled></div>
            </div>
            <div class="fg">
              <label class="fl"><i class="fa-solid fa-map-pin text-green-600" style="margin-right:4px;"></i> Pin Your Location on Map:</label>
              <div class="mwrap" style="margin-bottom:8px;">
                <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d14621.501652076073!2d120.94359404606583!3d15.449148612302375!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x339728a28ec550ad%3A0xaa3d1730b123812c!2sSumacab%20Este%2C%20Cabanatuan%20City%2C%20Nueva%20Ecija!5e1!3m2!1sen!2sph!4v1773387631553!5m2!1sen!2sph"
                  width="100%" height="260" style="border:0;display:block;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
              </div>
              <div class="mtip"><i class="fa-solid fa-circle-info"></i> Paste your exact Google Maps link below so people can find you easily.</div>
            </div>
            <div class="fg">
              <label class="fl"><span class="req">*</span>Your Exact Google Maps Link:</label>
              <input type="url" name="maps_link" class="fi" placeholder="https://maps.app.goo.gl/...">
              <p class="emsg" id="err-maps-link">Please provide a valid Google Maps link.</p>
            </div>
            <div class="sdiv"><span><i class="fa-solid fa-camera"></i> Photos <span style="font-weight:400;font-size:0.7rem;color:#9ca3af;text-transform:none;letter-spacing:0;">(max 4)</span></span></div>
            <div class="fg">
              <div class="uzone" id="uzone" onclick="document.getElementById('photoInput').click()" ondrop="dropPh(event)" ondragover="dovPh(event)" ondragleave="dlvPh(event)">
                <i class="fa-solid fa-cloud-arrow-up" style="font-size:1.6rem;color:#d1d5db;display:block;margin-bottom:6px;"></i>
                <p style="font-size:0.875rem;font-weight:700;color:#6b7280;margin:0 0 3px;">Click to upload or drag &amp; drop</p>
                <p style="font-size:0.72rem;color:#9ca3af;margin:0;">JPG, PNG, WEBP · max 5 MB each · up to <strong>4 photos</strong></p>
              </div>
              <input type="file" id="photoInput" name="photos[]" multiple accept="image/*" class="hidden" onchange="addPh(this)">
              <div class="pgrid4" id="pgrid" style="display:none;"></div>
              <p style="font-size:0.72rem;color:#9ca3af;margin-top:6px;display:none;" id="phlabel"></p>
              <p class="emsg" id="err-ph-limit" style="color:#f59e0b;">Maximum 4 photos  –  extra files were skipped.</p>
            </div>
            <div style="display:flex;justify-content:flex-end;margin-top:28px;">
              <button type="submit" class="submit-btn" onclick="return validateForm()" <?php if (!$canAccessServices) echo 'disabled style="background: linear-gradient(135deg, #9ca3af 0%, #6b7280 100%); cursor: not-allowed; opacity: 0.6;"'; ?>>
                Submit Listing <i class="fa-solid fa-arrow-right" style="font-size:0.75rem;"></i>
              </button>
            </div>
          </div><!-- /shared-sec -->
        </form>
      </div>
    </div>
  </section>

</main>

<!-- ══════════════════════════════════════════════════════════════════════
     VIEW MODAL
══════════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="viewModalOverlay" onclick="closeViewModalOnOverlay(event)">
  <div class="modal" id="viewModal" style="max-width:640px;">
    <div class="modal-header">
      <div class="flex items-center gap-3 min-w-0 flex-1">
        <div style="width:36px;height:36px;background:var(--site-primary-pale);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <i class="fa-solid fa-eye text-green-700 text-sm"></i>
        </div>
        <div class="min-w-0 flex-1">
          <p class="font-bold text-gray-900 text-base" id="viewModalTitle">Listing Details</p>
          <div class="flex items-center gap-2 mt-0.5 flex-wrap" id="viewModalBadges"></div>
        </div>
      </div>
      <button class="modal-close" onclick="closeViewModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body" id="viewModalBody"></div>
    <div class="modal-footer">
      <button class="mf-btn mf-cancel" onclick="closeViewModal()">Close <i class="fa-solid fa-xmark text-sm"></i></button>
      <button class="mf-btn mf-update" onclick="switchViewToEdit()">Edit <i class="fa-solid fa-pen-to-square text-sm"></i></button>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     EDIT MODAL
══════════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="editModalOverlay" onclick="closeEditModalOnOverlay(event)">
  <div class="modal" id="editModal" style="max-width:640px;">
    <div class="modal-header">
      <div class="flex items-center gap-3 min-w-0">
        <div style="width:36px;height:36px;background:#dbeafe;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <i class="fa-solid fa-pen-to-square text-blue-600 text-sm"></i>
        </div>
        <div class="min-w-0">
          <p class="font-bold text-gray-900 text-base">Edit Listing</p>
          <p class="text-gray-400 text-xs mt-0.5 truncate" id="editModalSubtitle">Update listing information</p>
        </div>
      </div>
      <div class="flex items-center gap-2 flex-shrink-0">
        <span class="changes-badge" id="changesBadge" style="display:none;">
          <i class="fa-solid fa-circle-dot text-xs"></i>
          <span id="changesCount">0</span> change(s)
        </span>
        <button class="modal-close" onclick="closeEditModal()"><i class="fa-solid fa-xmark"></i></button>
      </div>
    </div>
    <div class="modal-body" id="editModalBody">
      <!-- Fields injected by JS -->
    </div>
    <div class="modal-footer">
      <button class="mf-btn mf-cancel" onclick="closeEditModal()">Cancel <i class="fa-solid fa-xmark text-sm"></i></button>
      <button class="mf-btn mf-update" id="editSaveBtn" disabled onclick="handleEditSave()">Save Changes <i class="fa-solid fa-floppy-disk text-sm"></i></button>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     CONFIRM DIALOG
══════════════════════════════════════════════════════════════════════ -->
<div class="dialog-overlay" id="dialogOverlay">
  <div class="dialog-box">
    <div class="dialog-body-inner">
      <div class="dialog-icon-wrap" id="dialogIconWrap"><i id="dialogIconEl" class="fa-solid fa-trash"></i></div>
      <p class="dialog-title" id="dialogTitle">Delete Listing?</p>
      <p class="dialog-desc"  id="dialogDesc">This action cannot be undone.</p>
      <span class="dialog-name-badge" id="dialogNameBadge" style="display:none;"></span>
    </div>
    <div class="dialog-footer">
      <button class="dbtn dbtn-cancel" onclick="closeDialog()"><i class="fa-solid fa-xmark"></i> Cancel</button>
      <button class="dbtn dbtn-confirm" id="dialogConfirmBtn">
        <i class="fa-solid fa-trash" id="dialogConfirmIcon"></i>
        <span id="dialogConfirmLabel">Delete</span>
      </button>
    </div>
  </div>
</div>

<!-- LIGHTBOX -->
<div class="lightbox" id="lightbox" onclick="closeLightbox()">
  <button class="lightbox-close" onclick="closeLightbox()"><i class="fa-solid fa-xmark"></i></button>
  <img id="lightboxImg" src="" alt="">
</div>

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
          <a href="../busaptListing.php" class="block text-sm text-green-400 hover:text-white transition">Directory</a>
          <a href="../busaptListing.php?type=apartment" class="block text-sm text-green-400 hover:text-white transition">Apartments</a>
          <a href="../busaptListing.php?type=business" class="block text-sm text-green-400 hover:text-white transition">Business Directory</a>
        </div>
      </div>
    </div>
    <div class="text-center mt-6 text-green-500 text-sm">
      © <?= date('Y') ?> <?= e($siteSettings['site_title']) ?>. All Rights Reserved. <?= e($siteSettings['barangay_name']) ?>.
    </div>
  </div>
</footer>

<script>
/* ────────────────────────────────────────────────────────────
   TOAST SYSTEM
──────────────────────────────────────────────────────────── */
function showToast(type, title, desc) {
  const icons = { success: 'fa-circle-check', error: 'fa-circle-xmark', warning: 'fa-triangle-exclamation' };
  const container = document.getElementById('toastContainer');
  const el = document.createElement('div');
  el.className = `toast-item toast-${type}`;
  el.innerHTML = `
    <i class="fa-solid ${icons[type] || 'fa-circle-check'}" style="flex-shrink:0;font-size:1rem;"></i>
    <div style="min-width:0;">
      <span style="font-weight:700;">${escHtml(title)}</span>
      ${desc ? `<span style="font-weight:400;margin-left:6px;opacity:0.85;">${escHtml(desc)}</span>` : ''}
    </div>
    <button class="toast-close" onclick="dismissToast(this.parentElement)"><i class="fa-solid fa-xmark"></i></button>`;
  container.appendChild(el);
  setTimeout(() => dismissToast(el), 4500);
}
function dismissToast(el) {
  if (!el || el.classList.contains('out')) return;
  el.classList.add('out');
  setTimeout(() => el.remove(), 320);
}
setTimeout(() => { const t = document.getElementById('php-toast'); if (t) dismissToast(t); }, 4000);

function escHtml(str) {
  return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ─── Active listings tab ─── */
function switchTab(t) {
  ['apartment','business'].forEach(x => {
    document.getElementById('tab-'+x).classList.toggle('active', x===t);
    document.getElementById('panel-'+x).classList.toggle('hidden', x!==t);
  });
}

/* ─── Type chooser ─── */
let curType = '';
function chooseType(t) {
  curType = t;
  const mapping = { apt: 'apartment', biz: 'business' };
  document.getElementById('fld_type').value = mapping[t] || t;
  ['apt','biz'].forEach(x => {
    document.getElementById('tc-'+x).classList.toggle('selected', x===t);
    document.getElementById('panel-'+x).classList.toggle('on', x===t);
  });
  document.getElementById('shared-sec').style.display = 'block';
  document.getElementById('err-type').classList.remove('on');
}

/* ─── Radio pills ─── */
function pickRadio(radio, errId) {
  document.querySelectorAll(`[name="${radio.name}"]`).forEach(r => r.closest('.po').classList.toggle('sel', r.checked));
  document.getElementById('fld_subtype').value = radio.value;
  document.getElementById(errId).classList.remove('on');
}

/* ─── Checkbox pills ─── */
function toggleCb(cb) { cb.closest('.po').classList.toggle('sel', cb.checked); }

/* ─── Slots ─── */
let aptKey = 0;
function updateSlotKey(value) {
  const n = parseInt(value, 10);
  if (!n || n < 1) {
    aptKey = 0;
    document.getElementById('fld_key').value = '';
    document.getElementById('apt-kmeta').textContent = 'Enter a number (1 or more)';
    return;
  }
  aptKey = n;
  document.getElementById('fld_key').value = n;
  document.getElementById('apt-kmeta').innerHTML = `<strong>${n}</strong> slot${n > 1 ? 's' : ''} available`;
  document.getElementById('err-apt-key').classList.remove('on');
}

/* ─── Char counter ─── */
function charCount(el, cId, max) {
  const n = el.value.length;
  const d = document.getElementById(cId);
  d.textContent = n + ' / ' + max;
  d.className = 'cc' + (n >= max ? ' over' : n > max * 0.85 ? ' warn' : '');
}

/* ────────────────────────────────────────────────────────────
   PHOTOS (create form)
──────────────────────────────────────────────────────────── */
let files = [];
const MAX_PHOTOS = 4;

function addPh(input) {
  let warned = false;
  Array.from(input.files).forEach(f => {
    if (files.length < MAX_PHOTOS) files.push(f);
    else warned = true;
  });
  document.getElementById('err-ph-limit').classList.toggle('on', warned);
  renderGrid();
  input.value = '';
}

function dropPh(e) {
  e.preventDefault();
  dlvPh();
  addPh({ files: Array.from(e.dataTransfer.files).filter(f => f.type.startsWith('image/')), value: '' });
}
function dovPh(e) { e.preventDefault(); document.getElementById('uzone').classList.add('dov'); }
function dlvPh()  { document.getElementById('uzone').classList.remove('dov'); }

function removePh(i) {
  files.splice(i, 1);
  document.getElementById('err-ph-limit').classList.remove('on');
  renderGrid();
}

function renderGrid() {
  const g = document.getElementById('pgrid');
  const l = document.getElementById('phlabel');
  g.innerHTML = '';

  if (!files.length) {
    g.style.display = 'none';
    l.style.display = 'none';
    return;
  }

  g.style.display = 'grid';
  l.style.display = 'block';
  l.textContent = files.length + '/' + MAX_PHOTOS + ' photo' + (files.length > 1 ? 's' : '') + ' selected';

  files.forEach((f, i) => {
    const c = document.createElement('div');
    c.className = 'pcell';
    const id = 'ph' + i;
    c.innerHTML = `<img id="${id}" src="" alt=""><button type="button" class="prm" onclick="removePh(${i})"><i class="fa-solid fa-xmark"></i></button>`;
    g.appendChild(c);
    const r = new FileReader();
    r.onload = e => { const img = document.getElementById(id); if (img) img.src = e.target.result; };
    r.readAsDataURL(f);
  });

  if (files.length < MAX_PHOTOS) {
    const s = document.createElement('div');
    s.className = 'padd';
    s.innerHTML = '<i class="fa-solid fa-plus"></i><span>Add photo</span>';
    s.onclick = () => document.getElementById('photoInput').click();
    g.appendChild(s);
  }
}

/* ─── Form submit file sync ─── */
document.getElementById('listingForm').addEventListener('submit', function(e) {
  if (!files.length) return;
  const dt = new DataTransfer();
  files.forEach(f => dt.items.add(f));
  document.getElementById('photoInput').files = dt.files;
});

/* ─── Validation ─── */
function validateForm() {
  let ok = true;
  if (!curType) { document.getElementById('err-type').classList.add('on'); ok = false; }
  if (curType === 'apt') {
    if (!document.querySelector('[name="apt_type"]:checked')) { document.getElementById('err-apt-type').classList.add('on'); ok = false; }
    if (!aptKey) { document.getElementById('err-apt-key').classList.add('on'); ok = false; }
  }
  if (curType === 'biz') {
    if (!document.querySelector('[name="buss_cat"]:checked')) { document.getElementById('err-buss-cat').classList.add('on'); ok = false; }
  }
  const mapsInput = document.querySelector('[name="maps_link"]');
  if (!mapsInput || !mapsInput.value.trim()) {
    document.getElementById('err-maps-link').classList.add('on'); ok = false;
  } else {
    document.getElementById('err-maps-link').classList.remove('on');
  }

  if (ok && files.length) {
    try {
      const dt = new DataTransfer();
      files.forEach(f => dt.items.add(f));
      document.getElementById('photoInput').files = dt.files;
    } catch(e) {}
  }

  return ok;
}

/* ══════════════════════════════════════════════════════════════════════
   HELPERS
══════════════════════════════════════════════════════════════════════ */
function parseArr(val) {
  if (!val) return [];
  if (Array.isArray(val)) return val;
  try { const p = JSON.parse(val); return Array.isArray(p) ? p : []; } catch(e) { return []; }
}

const APT_TYPE_LABELS = {
  'bed-spacer': 'Bed Spacer', 'studio': 'Studio Type', 'solo-room': 'Solo Room',
  '1br': '1-Bedroom', '2br': '2-Bedroom', 'whole-unit': 'Whole Unit'
};
const BATH_LABELS = { 'private': 'Private Bathroom', 'shared': 'Shared Bathroom' };
const STATUS_LABELS_APT = { 'available': 'Available', 'occupied': 'Fully Occupied', 'inquire': 'Inquire First' };
const STATUS_LABELS_BIZ = { 'open': 'Open / Operating', 'new': 'Newly Opened', 'temp-closed': 'Temporarily Closed', 'for-rent': 'Space for Rent' };
const BUSS_CAT_LABELS = {
  'food': 'Food & Dining', 'water': 'Water Station', 'sari-sari': 'Sari-Sari Store',
  'salon': 'Salon / Barber', 'laundry': 'Laundry Shop', 'pharmacy': 'Pharmacy',
  'printing': 'Printing / Computer Shop', 'bakery': 'Bakery / Café', 'hardware': 'Hardware', 'other': 'Other'
};
const INC_LABELS   = { 'electric': 'Electricity', 'water': 'Water', 'wifi': 'WiFi', 'cable': 'Cable TV' };
const AMN_LABELS   = { 'aircon': 'Aircon', 'fan': 'Electric Fan', 'parking': 'Parking', 'laundry': 'Laundry Area', 'cctv': 'CCTV', 'security': 'Security', 'kitchen': 'Shared Kitchen', 'gate': 'Gated Compound' };
const RULES_LABELS = { 'no-smoking': 'No Smoking', 'no-pets': 'No Pets', 'no-visitors': 'No Overnight Visitors', 'curfew': 'Curfew Policy', 'no-cooking': 'No Cooking Inside' };
const FEAT_LABELS  = { 'delivery': 'Delivery', 'pickup': 'Pick-up', 'dine-in': 'Dine-in', 'parking': 'Parking', 'gcash': 'GCash', 'maya': 'Maya', 'wifi': 'Free WiFi', 'aircon': 'Aircon' };
const DAYS_LABELS  = { 'mon': 'Mon', 'tue': 'Tue', 'wed': 'Wed', 'thu': 'Thu', 'fri': 'Fri', 'sat': 'Sat', 'sun': 'Sun', 'holiday': 'Holidays' };
const YEARS_LABELS = { 'new': 'Just opened', '1': '1 year', '2-5': '2 – 5 years', '5-10': '5 – 10 years', '10+': '10+ years' };

function tagList(arr, labelObj) {
  if (!arr || !arr.length) return '<span style="color:#9ca3af;font-style:italic;font-size:0.82rem;">None specified</span>';
  return arr.map(v => `<span class="tag">${escHtml(labelObj[v] || v)}</span>`).join('');
}

function vrow(label, val) {
  const v = (val && String(val).trim()) ? String(val) : null;
  return `<div class="view-row"><span class="view-label">${escHtml(label)}</span><span class="view-value${!v ? ' empty' : ''}">${v ? escHtml(v) : ' – '}</span></div>`;
}

function fmt12(t) {
  if (!t) return null;
  const [h, m] = t.split(':').map(Number);
  if (isNaN(h) || isNaN(m)) return t;
  const ampm = h >= 12 ? 'PM' : 'AM';
  const hr = h % 12 || 12;
  return `${hr}:${String(m).padStart(2, '0')} ${ampm}`;
}

/* ══════════════════════════════════════════════════════════════════════
   VIEW MODAL
══════════════════════════════════════════════════════════════════════ */
let currentViewListing = null;

function openViewModal(row) {
  const raw = row.getAttribute('data-listing');
  if (!raw) return;
  const l = JSON.parse(raw);
  currentViewListing = l;

  const isApt = (l.listingType === 'apt' || l.listingType === 'apartment');

  const inc    = parseArr(l.aptIncluded);
  const amn    = parseArr(l.aptAmenities);
  const rules  = parseArr(l.aptRules);
  const feat   = parseArr(l.bussFeatures);
  const days   = parseArr(l.bussDays);
  const photos = l.photos_arr || [];

  const title = isApt
    ? ((l.aptType ? (APT_TYPE_LABELS[l.aptType] || l.aptType) + '  –  ' : '') + (l.aptAddress || 'Apartment Listing'))
    : (l.bussName || 'Business Listing');

  document.getElementById('viewModalTitle').textContent = title;

  const badges = document.getElementById('viewModalBadges');
  badges.innerHTML = `
    <span class="info-badge ${isApt ? 'badge-apt' : 'badge-biz'}">
      <i class="fa-solid ${isApt ? 'fa-building' : 'fa-store'} text-xs"></i>
      ${isApt ? 'Apartment' : 'Business'}
    </span>
    ${l.slotsAvailable ? `<span class="info-badge badge-avail"><i class="fa-solid fa-circle-check text-xs"></i> ${escHtml(String(l.slotsAvailable))} slot${l.slotsAvailable == 1 ? '' : 's'} open</span>` : ''}`;

  let html = '';

  if (photos.length) {
    html += `<div class="section-card">
      <div class="sc-title"><div class="sc-icon"><i class="fa-solid fa-camera text-green-700 text-xs"></i></div>Photos <span style="font-weight:400;font-size:0.68rem;color:#9ca3af;text-transform:none;letter-spacing:0;">(click to zoom)</span></div>
      <div class="photo-placeholder-grid">`;
    photos.forEach((p, i) => {
      html += `<div class="photo-placeholder" onclick="openLightbox('${escHtml(p)}')" title="Click to zoom">
        <img src="${escHtml(p)}" alt="Photo ${i+1}" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
        <div class="ph-inner" style="display:none;"><i class="fa-solid fa-image text-xl text-gray-300"></i><span>Photo ${i+1}</span></div>
      </div>`;
    });
    html += `</div></div>`;
  }

  if (isApt) {
    html += `
    <div class="section-card">
      <div class="sc-title"><div class="sc-icon"><i class="fa-solid fa-building text-green-700 text-xs"></i></div>Room Details</div>
      <div class="grid grid-cols-2 gap-4">
        ${vrow('Listing Title', l.aptTitle || '')}
        ${vrow('Room Type', APT_TYPE_LABELS[l.aptType] || l.aptType || '')}
        ${vrow('Availability', STATUS_LABELS_APT[l.aptStatus] || l.aptStatus || '')}
        ${vrow('Monthly Rent', l.aptPrice ? '₱ ' + Number(l.aptPrice).toLocaleString() : '')}
        ${vrow('Floor / Level', l.aptFloor || '')}
        ${vrow('No. of Rooms', l.aptRooms || '')}
        ${vrow('Max Occupants', l.aptOccupants || '')}
        ${vrow('Bathroom', BATH_LABELS[l.aptBath] || l.aptBath || '')}
        ${vrow('Slots Available', l.slotsAvailable ? l.slotsAvailable + ' open slot' + (l.slotsAvailable == 1 ? '' : 's') : '')}
      </div>
    </div>
    <div class="section-card">
      <div class="sc-title"><div class="sc-icon"><i class="fa-solid fa-lightbulb text-green-700 text-xs"></i></div>Included in Rent</div>
      <div>${tagList(inc, INC_LABELS)}</div>
    </div>
    <div class="section-card">
      <div class="sc-title"><div class="sc-icon"><i class="fa-solid fa-house text-green-700 text-xs"></i></div>Amenities</div>
      <div>${tagList(amn, AMN_LABELS)}</div>
    </div>
    <div class="section-card">
      <div class="sc-title"><div class="sc-icon"><i class="fa-solid fa-scroll text-green-700 text-xs"></i></div>House Rules</div>
      <div>${tagList(rules, RULES_LABELS)}</div>
    </div>
    <div class="section-card">
      <div class="sc-title"><div class="sc-icon"><i class="fa-solid fa-list text-green-700 text-xs"></i></div>Description</div>
      <p style="font-size:0.875rem;color:#374151;line-height:1.7;white-space:pre-wrap;margin:0;">${l.aptDesc ? escHtml(l.aptDesc) : '<span style="color:#9ca3af;font-style:italic;">No description provided.</span>'}</p>
    </div>`;
  } else {
    html += `
    <div class="section-card">
      <div class="sc-title"><div class="sc-icon"><i class="fa-solid fa-store text-green-700 text-xs"></i></div>Business Details</div>
      <div class="grid grid-cols-2 gap-4">
        ${vrow('Business Name', l.bussName)}
        ${vrow('Category', BUSS_CAT_LABELS[l.bussCat] || l.bussCat || '')}
        ${vrow('Status', STATUS_LABELS_BIZ[l.bussStatus] || l.bussStatus || '')}
        ${vrow('Starting Price', l.bussPrice ? '₱ ' + l.bussPrice : '')}
        ${vrow('Years in Business', YEARS_LABELS[l.bussYears] || l.bussYears || '')}
      </div>
    </div>`;

    const openTime  = fmt12(l.bussOpen);
    const closeTime = fmt12(l.bussClose);
    html += `
    <div class="section-card">
      <div class="sc-title"><div class="sc-icon"><i class="fa-solid fa-clock text-green-700 text-xs"></i></div>Operating Hours</div>
      <div class="grid grid-cols-2 gap-4" style="margin-bottom:12px;">
        ${vrow('Opens', openTime || '')}
        ${vrow('Closes', closeTime || '')}
      </div>
      <div style="margin-bottom:4px;"><span class="view-label">Days Open</span></div>
      <div>${tagList(days, DAYS_LABELS)}</div>
    </div>
    <div class="section-card">
      <div class="sc-title"><div class="sc-icon"><i class="fa-solid fa-sparkles text-green-700 text-xs"></i></div>Business Features</div>
      <div>${tagList(feat, FEAT_LABELS)}</div>
    </div>
    <div class="section-card">
      <div class="sc-title"><div class="sc-icon"><i class="fa-solid fa-list text-green-700 text-xs"></i></div>Description</div>
      <p style="font-size:0.875rem;color:#374151;line-height:1.7;white-space:pre-wrap;margin:0;">${l.bussDesc ? escHtml(l.bussDesc) : '<span style="color:#9ca3af;font-style:italic;">No description provided.</span>'}</p>
    </div>`;
  }

  const addr = isApt ? l.aptAddress  : l.bussAddress;
  const maps = isApt ? l.aptMapsLink : l.bussMapsLink;
  html += `
  <div class="section-card">
    <div class="sc-title"><div class="sc-icon"><i class="fa-solid fa-phone text-green-700 text-xs"></i></div>Contact & Location</div>
    <div class="grid grid-cols-2 gap-4">
      ${vrow('Contact', l.contact)}
      ${vrow('Email', l.email)}
      ${vrow('Address', addr)}
    </div>
    ${maps ? `
    <div style="margin-top:12px;">
      <a href="${escHtml(maps)}" target="_blank" rel="noopener"
        style="display:inline-flex;align-items:center;gap:6px;color:var(--site-primary-dark);font-size:0.82rem;font-weight:700;text-decoration:underline;text-underline-offset:3px;">
        <i class="fa-solid fa-map-pin text-xs"></i> View on Google Maps
      </a>
    </div>` : ''}
  </div>
  <div class="section-card">
    <div class="sc-title"><div class="sc-icon"><i class="fa-solid fa-calendar text-green-700 text-xs"></i></div>Submission Info</div>
    <div class="grid grid-cols-2 gap-4">
      ${vrow('Date Submitted', l.date)}
      ${vrow('Listing ID', '#' + l.id)}
    </div>
  </div>`;

  document.getElementById('viewModalBody').innerHTML = html;
  document.getElementById('viewModalOverlay').classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeViewModal() {
  document.getElementById('viewModalOverlay').classList.remove('open');
  document.body.style.overflow = '';
}
function closeViewModalOnOverlay(e) {
  if (e.target === document.getElementById('viewModalOverlay')) closeViewModal();
}
function switchViewToEdit() {
  closeViewModal();
  if (currentViewListing) {
    const fakeRow = { getAttribute: (attr) => attr === 'data-listing' ? JSON.stringify(currentViewListing) : null };
    openEditModal(fakeRow);
  }
}

/* ══════════════════════════════════════════════════════════════════════
   EDIT MODAL HELPERS
══════════════════════════════════════════════════════════════════════ */
let currentEditListing = null;
let editRemovedPhotos  = [];
let editNewFiles       = [];
const EDIT_MAX = 4;

function buildCheckPills(name, selected, labelObj, icons) {
  icons = icons || {};
  return Object.entries(labelObj).map(([val, lbl]) => {
    const isSel = selected.includes(val);
    const ico = icons[val] ? `<i class="fa-solid ${icons[val]}"></i> ` : '';
    return `<label class="po edit-cb-pill ${isSel ? 'sel' : ''}" data-name="${name}" data-value="${val}">
      <input type="checkbox" name="${name}" value="${val}" ${isSel ? 'checked' : ''} onchange="editToggleCb(this)">
      ${ico}${escHtml(lbl)}
    </label>`;
  }).join('');
}

function buildSelect(id, options, selected, extraAttr) {
  extraAttr = extraAttr || '';
  const opts = options.map(([val, lbl]) =>
    `<option value="${escHtml(val)}" ${selected === val ? 'selected' : ''}>${escHtml(lbl)}</option>`
  ).join('');
  return `<select id="${id}" class="field-select" data-orig="${escHtml(selected || '')}" onchange="checkEditChanges()" ${extraAttr}>${opts}</select>`;
}

function editToggleCb(cb) {
  cb.closest('.edit-cb-pill').classList.toggle('sel', cb.checked);
  checkEditChanges();
}

function buildAptEditFields(l) {
  const inc   = parseArr(l.aptIncluded);
  const amn   = parseArr(l.aptAmenities);
  const rules = parseArr(l.aptRules);

  return `
  <div class="section-card">
    <div class="sc-title"><div class="sc-icon"><i class="fa-solid fa-building text-green-700 text-xs"></i></div>Room Details</div>
    <div class="grid grid-cols-2 gap-3">
      <div>
        <label class="field-label">Listing Title</label>
        <input type="text" id="edit_aptTitle" class="field-input" value="${escHtml(l.aptTitle || '')}" placeholder="e.g. Cozy Studio near Market" data-orig="${escHtml(l.aptTitle || '')}" oninput="checkEditChanges()">
      </div>
      <div>
        <label class="field-label">Room Type</label>
        ${buildSelect('edit_aptType', [['','-- Select --'],['bed-spacer','Bed Spacer'],['studio','Studio Type'],['solo-room','Solo Room'],['1br','1-Bedroom'],['2br','2-Bedroom'],['whole-unit','Whole Unit']], l.aptType || '')}
      </div>
      <div>
        <label class="field-label">Availability</label>
        ${buildSelect('edit_aptStatus', [['','-- Select --'],['available','Available'],['occupied','Fully Occupied'],['inquire','Inquire First']], l.aptStatus || '')}
      </div>
      <div>
        <label class="field-label">Monthly Rent (₱)</label>
        <input type="number" id="edit_aptPrice" class="field-input" value="${escHtml(String(l.aptPrice || ''))}" placeholder="e.g. 3500" data-orig="${escHtml(String(l.aptPrice || ''))}" oninput="checkEditChanges()">
      </div>
      <div>
        <label class="field-label">Floor / Level</label>
        <input type="text" id="edit_aptFloor" class="field-input" value="${escHtml(l.aptFloor || '')}" placeholder="e.g. 2nd Floor" data-orig="${escHtml(l.aptFloor || '')}" oninput="checkEditChanges()">
      </div>
      <div>
        <label class="field-label">No. of Rooms</label>
        <input type="number" id="edit_aptRooms" class="field-input" value="${escHtml(String(l.aptRooms || ''))}" placeholder="e.g. 1" data-orig="${escHtml(String(l.aptRooms || ''))}" oninput="checkEditChanges()">
      </div>
      <div>
        <label class="field-label">Max Occupants</label>
        <input type="number" id="edit_aptOccupants" class="field-input" value="${escHtml(String(l.aptOccupants || ''))}" placeholder="e.g. 2" data-orig="${escHtml(String(l.aptOccupants || ''))}" oninput="checkEditChanges()">
      </div>
      <div>
        <label class="field-label">Bathroom</label>
        ${buildSelect('edit_aptBath', [['','-- Select --'],['private','Private'],['shared','Shared']], l.aptBath || '')}
      </div>
      <div>
        <label class="field-label">Slots Available</label>
        <input type="number" id="edit_slotsAvailable" class="field-input" value="${escHtml(String(l.slotsAvailable || ''))}" placeholder="e.g. 2" data-orig="${escHtml(String(l.slotsAvailable || ''))}" min="1" oninput="checkEditChanges()">
      </div>
    </div>
  </div>
  <div class="section-card">
    <div class="sc-title"><div class="sc-icon"><i class="fa-solid fa-lightbulb text-green-700 text-xs"></i></div>Included in Rent</div>
    <div class="pg">${buildCheckPills('edit_apt_inc', inc, INC_LABELS, {electric:'fa-bolt',water:'fa-faucet',wifi:'fa-wifi',cable:'fa-tv'})}</div>
  </div>
  <div class="section-card">
    <div class="sc-title"><div class="sc-icon"><i class="fa-solid fa-house text-green-700 text-xs"></i></div>Amenities</div>
    <div class="pg">${buildCheckPills('edit_apt_amn', amn, AMN_LABELS, {aircon:'fa-snowflake',fan:'fa-fan',parking:'fa-car',laundry:'fa-shirt',cctv:'fa-camera',security:'fa-shield-halved',kitchen:'fa-kitchen-set',gate:'fa-lock'})}</div>
  </div>
  <div class="section-card">
    <div class="sc-title"><div class="sc-icon"><i class="fa-solid fa-scroll text-green-700 text-xs"></i></div>House Rules</div>
    <div class="pg">${buildCheckPills('edit_apt_rules', rules, RULES_LABELS, {'no-smoking':'fa-ban-smoking','no-pets':'fa-paw','no-visitors':'fa-user-slash',curfew:'fa-moon','no-cooking':'fa-fire-burner'})}</div>
  </div>
  <div class="section-card">
    <div class="sc-title"><div class="sc-icon"><i class="fa-solid fa-list text-green-700 text-xs"></i></div>Description</div>
    <textarea id="edit_aptDesc" class="field-textarea" placeholder="Describe the unit..." data-orig="${escHtml(l.aptDesc || '')}" oninput="checkEditChanges()">${escHtml(l.aptDesc || '')}</textarea>
  </div>`;
}

function buildBizEditFields(l) {
  const feat = parseArr(l.bussFeatures);
  const days = parseArr(l.bussDays);

  return `
  <div class="section-card">
    <div class="sc-title"><div class="sc-icon"><i class="fa-solid fa-store text-green-700 text-xs"></i></div>Business Information</div>
    <div class="grid grid-cols-2 gap-3">
      <div style="grid-column:1/-1;">
        <label class="field-label">Business Name</label>
        <input type="text" id="edit_bussName" class="field-input" value="${escHtml(l.bussName || '')}" placeholder="Business name" data-orig="${escHtml(l.bussName || '')}" oninput="checkEditChanges()">
      </div>
      <div>
        <label class="field-label">Category</label>
        ${buildSelect('edit_bussCat', [['','-- Select --'],['food','Food & Dining'],['water','Water Station'],['sari-sari','Sari-Sari Store'],['salon','Salon / Barber'],['laundry','Laundry Shop'],['pharmacy','Pharmacy'],['printing','Printing / Computer Shop'],['bakery','Bakery / Café'],['hardware','Hardware'],['other','Other']], l.bussCat || '')}
      </div>
      <div>
        <label class="field-label">Status</label>
        ${buildSelect('edit_bussStatus', [['','-- Select --'],['open','Open / Operating'],['new','Newly Opened'],['temp-closed','Temporarily Closed'],['for-rent','Space for Rent']], l.bussStatus || '')}
      </div>
      <div>
        <label class="field-label">Starting Price / Rate (₱)</label>
        <input type="text" id="edit_bussPrice" class="field-input" value="${escHtml(l.bussPrice || '')}" placeholder="e.g. 30 per load" data-orig="${escHtml(l.bussPrice || '')}" oninput="checkEditChanges()">
      </div>
      <div>
        <label class="field-label">Years in Business</label>
        ${buildSelect('edit_bussYears', [['','-- Select --'],['new','Just opened'],['1','1 year'],['2-5','2 – 5 years'],['5-10','5 – 10 years'],['10+','10+ years']], l.bussYears || '')}
      </div>
    </div>
  </div>
  <div class="section-card">
    <div class="sc-title"><div class="sc-icon"><i class="fa-solid fa-clock text-green-700 text-xs"></i></div>Operating Hours</div>
    <div class="grid grid-cols-2 gap-3" style="margin-bottom:12px;">
      <div>
        <label class="field-label">Opening Time</label>
        <input type="time" id="edit_bussOpen" class="field-input" value="${escHtml(l.bussOpen || '')}" data-orig="${escHtml(l.bussOpen || '')}" oninput="checkEditChanges()">
      </div>
      <div>
        <label class="field-label">Closing Time</label>
        <input type="time" id="edit_bussClose" class="field-input" value="${escHtml(l.bussClose || '')}" data-orig="${escHtml(l.bussClose || '')}" oninput="checkEditChanges()">
      </div>
    </div>
    <label class="field-label" style="margin-bottom:7px;display:block;">Days Open</label>
    <div class="pg">${buildCheckPills('edit_buss_days', days, DAYS_LABELS)}</div>
  </div>
  <div class="section-card">
    <div class="sc-title"><div class="sc-icon"><i class="fa-solid fa-sparkles text-green-700 text-xs"></i></div>Business Features</div>
    <div class="pg">${buildCheckPills('edit_biz_feat', feat, FEAT_LABELS, {delivery:'fa-motorcycle',pickup:'fa-bag-shopping','dine-in':'fa-chair',parking:'fa-car',gcash:'fa-mobile-screen',maya:'fa-mobile-screen',wifi:'fa-wifi',aircon:'fa-snowflake'})}</div>
  </div>
  <div class="section-card">
    <div class="sc-title"><div class="sc-icon"><i class="fa-solid fa-list text-green-700 text-xs"></i></div>Description</div>
    <textarea id="edit_bussDesc" class="field-textarea" placeholder="Describe your business..." data-orig="${escHtml(l.bussDesc || '')}" oninput="checkEditChanges()">${escHtml(l.bussDesc || '')}</textarea>
  </div>`;
}

function buildEditSharedFields(l) {
  const isApt  = (l.listingType === 'apt' || l.listingType === 'apartment');
  const addr   = isApt ? (l.aptAddress  || '') : (l.bussAddress  || '');
  const maps   = isApt ? (l.aptMapsLink || '') : (l.bussMapsLink || '');
  const photos = l.photos_arr || [];

  let photosHtml = '';
  if (photos.length) {
    photosHtml = `<div style="margin-bottom:10px;">
      <p style="font-size:0.75rem;color:#6b7280;margin:0 0 7px;font-weight:600;">Current Photos</p>
      <div class="edit-pgrid" id="edit_cur_photos">
        ${photos.map((p, i) => `
          <div class="photo-placeholder" id="editcp_${i}" onclick="openLightbox('${escHtml(p)}')" title="Click to zoom" style="cursor:pointer;">
            <img src="${escHtml(p)}" alt="Photo ${i+1}" onerror="this.style.display='none'">
            <div class="ph-inner" style="display:none;z-index:1;position:relative;"><i class="fa-solid fa-image text-xl text-gray-300"></i><span>Photo ${i+1}</span></div>
            <button type="button" onclick="event.stopPropagation();removeExistingPhoto(${i}, '${escHtml(p)}')"
              style="position:absolute;top:4px;right:4px;width:20px;height:20px;border-radius:50%;background:rgba(0,0,0,0.55);color:#fff;border:none;font-size:0.6rem;cursor:pointer;display:flex;align-items:center;justify-content:center;z-index:2;">
              <i class="fa-solid fa-xmark"></i>
            </button>
          </div>`).join('')}
      </div>
    </div>`;
  }

  return `
  <div class="section-card">
    <div class="sc-title"><div class="sc-icon"><i class="fa-solid fa-phone text-green-700 text-xs"></i></div>Contact Information</div>
    <div class="grid grid-cols-2 gap-3">
      <div>
        <label class="field-label">Contact Number</label>
        <input type="text" id="edit_contact" class="field-input" value="${escHtml(l.contact || '')}" placeholder="e.g. 0917-123-4567" data-orig="${escHtml(l.contact || '')}" oninput="checkEditChanges()">
      </div>
      <div>
        <label class="field-label">Email Address</label>
        <input type="email" id="edit_email" class="field-input" value="${escHtml(l.email || '')}" placeholder="owner@email.com" data-orig="${escHtml(l.email || '')}" oninput="checkEditChanges()">
      </div>
    </div>
  </div>
  <div class="section-card">
    <div class="sc-title"><div class="sc-icon"><i class="fa-solid fa-location-dot text-green-700 text-xs"></i></div>Address & Map</div>
    <div style="display:grid;gap:10px;">
      <div>
        <label class="field-label">Full Address</label>
        <input type="text" id="edit_address" class="field-input" value="${escHtml(addr)}" placeholder="Full address" data-orig="${escHtml(addr)}" oninput="checkEditChanges()">
      </div>
      <div>
        <label class="field-label">Google Maps Link</label>
        <input type="url" id="edit_mapsLink" class="field-input" value="${escHtml(maps)}" placeholder="https://maps.app.goo.gl/..." data-orig="${escHtml(maps)}" oninput="checkEditChanges()">
      </div>
    </div>
  </div>
  <div class="section-card">
    <div class="sc-title"><div class="sc-icon"><i class="fa-solid fa-camera text-green-700 text-xs"></i></div>Photos <span style="font-weight:400;font-size:0.7rem;color:#9ca3af;text-transform:none;letter-spacing:0;">(max 4 total)</span></div>
    ${photosHtml}
    <div class="uzone" id="edit_uzone" onclick="document.getElementById('editPhotoInput').click()" ondrop="editDropPh(event)" ondragover="editDovPh(event)" ondragleave="editDlvPh(event)">
      <i class="fa-solid fa-cloud-arrow-up" style="font-size:1.4rem;color:#d1d5db;display:block;margin-bottom:5px;"></i>
      <p style="font-size:0.82rem;font-weight:700;color:#6b7280;margin:0 0 2px;">Add new photos</p>
      <p style="font-size:0.7rem;color:#9ca3af;margin:0;">JPG, PNG, WEBP · max 5 MB · up to <strong>4 total</strong></p>
    </div>
    <input type="file" id="editPhotoInput" multiple accept="image/*" class="hidden" onchange="editAddPh(this)">
    <div class="edit-pgrid" id="edit_new_pgrid" style="display:none;margin-top:8px;"></div>
    <p style="font-size:0.72rem;color:#f59e0b;margin-top:5px;display:none;" id="edit_ph_warn">Maximum 4 photos total  –  extra files skipped.</p>
    <input type="hidden" id="edit_removed_photos" value="[]">
  </div>`;
}

function removeExistingPhoto(idx, url) {
  const cell = document.getElementById('editcp_' + idx);
  if (cell) cell.remove();
  editRemovedPhotos.push(url);
  document.getElementById('edit_removed_photos').value = JSON.stringify(editRemovedPhotos);
  checkEditChanges();
}

function editAddPh(input) {
  const curPhCount = document.querySelectorAll('#edit_cur_photos .photo-placeholder').length;
  const slots = EDIT_MAX - curPhCount - editNewFiles.length;
  let warned = false;
  Array.from(input.files).forEach(f => {
    if (editNewFiles.length < slots && slots > 0) editNewFiles.push(f);
    else warned = true;
  });
  document.getElementById('edit_ph_warn').style.display = warned ? 'block' : 'none';
  renderEditGrid();
  input.value = '';
  checkEditChanges();
}
function editDropPh(e) { e.preventDefault(); editDlvPh(); editAddPh({ files: Array.from(e.dataTransfer.files).filter(f => f.type.startsWith('image/')), value: '' }); }
function editDovPh(e) { e.preventDefault(); document.getElementById('edit_uzone').classList.add('dov'); }
function editDlvPh()  { document.getElementById('edit_uzone').classList.remove('dov'); }

function renderEditGrid() {
  const g = document.getElementById('edit_new_pgrid');
  g.innerHTML = '';
  if (!editNewFiles.length) { g.style.display = 'none'; return; }
  g.style.display = 'grid';
  editNewFiles.forEach((f, i) => {
    const c = document.createElement('div'); c.className = 'photo-placeholder';
    const id = 'editph' + i;
    c.innerHTML = `<img id="${id}" src="" alt=""><button type="button" onclick="editRemoveNewPh(${i})" style="position:absolute;top:4px;right:4px;width:20px;height:20px;border-radius:50%;background:rgba(0,0,0,0.55);color:#fff;border:none;font-size:0.6rem;cursor:pointer;display:flex;align-items:center;justify-content:center;z-index:2;"><i class="fa-solid fa-xmark"></i></button>`;
    g.appendChild(c);
    const r = new FileReader();
    r.onload = ev => { const img = document.getElementById(id); if (img) img.src = ev.target.result; };
    r.readAsDataURL(f);
  });
}
function editRemoveNewPh(i) {
  editNewFiles.splice(i, 1);
  document.getElementById('edit_ph_warn').style.display = 'none';
  renderEditGrid();
  checkEditChanges();
}

/* ══════════════════════════════════════════════════════════════════════
   OPEN / CLOSE EDIT MODAL
══════════════════════════════════════════════════════════════════════ */
function openEditModal(row) {
  const raw = row.getAttribute('data-listing');
  if (!raw) return;
  const l = JSON.parse(raw);
  currentEditListing = l;
  editRemovedPhotos  = [];
  editNewFiles       = [];

  const isApt = (l.listingType === 'apt' || l.listingType === 'apartment');
  document.getElementById('editModalSubtitle').textContent = l.display_name || 'Update listing information';

  const body = document.getElementById('editModalBody');
  body.innerHTML = `
    <input type="hidden" id="edit_listing_id"   value="${escHtml(String(l.id))}">
    <input type="hidden" id="edit_listing_type" value="${escHtml(l.listingType)}">
    ${isApt ? buildAptEditFields(l) : buildBizEditFields(l)}
    ${buildEditSharedFields(l)}`;

  document.getElementById('editSaveBtn').disabled = true;
  document.getElementById('changesBadge').style.display = 'none';
  document.getElementById('editModalOverlay').classList.add('open');
  document.body.style.overflow = 'hidden';
}

function checkEditChanges() {
  if (!currentEditListing) return;
  let count = 0;

  document.querySelectorAll('#editModalBody .field-input, #editModalBody .field-select, #editModalBody .field-textarea').forEach(el => {
    const orig    = el.getAttribute('data-orig') ?? '';
    const changed = el.value.trim() !== orig.trim();
    el.classList.toggle('changed', changed);
    if (changed) count++;
  });

  const l      = currentEditListing;
  const isApt  = (l.listingType === 'apt' || l.listingType === 'apartment');

  if (isApt) {
    const origInc   = parseArr(l.aptIncluded);
    const origAmn   = parseArr(l.aptAmenities);
    const origRules = parseArr(l.aptRules);
    const curInc    = [...document.querySelectorAll('[name="edit_apt_inc"]:checked')].map(c => c.value);
    const curAmn    = [...document.querySelectorAll('[name="edit_apt_amn"]:checked')].map(c => c.value);
    const curRules  = [...document.querySelectorAll('[name="edit_apt_rules"]:checked')].map(c => c.value);
    if (JSON.stringify([...origInc].sort())   !== JSON.stringify([...curInc].sort()))   count++;
    if (JSON.stringify([...origAmn].sort())   !== JSON.stringify([...curAmn].sort()))   count++;
    if (JSON.stringify([...origRules].sort()) !== JSON.stringify([...curRules].sort())) count++;
  } else {
    const origFeat = parseArr(l.bussFeatures);
    const origDays = parseArr(l.bussDays);
    const curFeat  = [...document.querySelectorAll('[name="edit_biz_feat"]:checked')].map(c => c.value);
    const curDays  = [...document.querySelectorAll('[name="edit_buss_days"]:checked')].map(c => c.value);
    if (JSON.stringify([...origFeat].sort()) !== JSON.stringify([...curFeat].sort())) count++;
    if (JSON.stringify([...origDays].sort()) !== JSON.stringify([...curDays].sort())) count++;
  }

  if (editRemovedPhotos.length || editNewFiles.length) count++;

  const saveBtn = document.getElementById('editSaveBtn');
  const badge   = document.getElementById('changesBadge');
  saveBtn.disabled = (count === 0);
  if (count > 0) { badge.style.display = 'inline-flex'; document.getElementById('changesCount').textContent = count; }
  else { badge.style.display = 'none'; }
}

function closeEditModal() {
  document.getElementById('editModalOverlay').classList.remove('open');
  document.body.style.overflow = '';
  currentEditListing = null;
  editRemovedPhotos  = [];
  editNewFiles       = [];
}
function closeEditModalOnOverlay(e) {
  if (e.target === document.getElementById('editModalOverlay')) closeEditModal();
}

/* ══════════════════════════════════════════════════════════════════════
   SAVE EDIT
══════════════════════════════════════════════════════════════════════ */
function handleEditSave() {
  if (!currentEditListing) return;
  const listingId   = document.getElementById('edit_listing_id')?.value || '';
  const listingType = document.getElementById('edit_listing_type')?.value || '';
  const isApt = (listingType === 'apt' || listingType === 'apartment');

  const data = {
    id:           listingId,
    listingType:  listingType,
    contact:      document.getElementById('edit_contact')?.value.trim()  || '',
    email:        document.getElementById('edit_email')?.value.trim()    || '',
    address:      document.getElementById('edit_address')?.value.trim()  || '',
    mapsLink:     document.getElementById('edit_mapsLink')?.value.trim() || '',
    removedPhotos: JSON.parse(document.getElementById('edit_removed_photos')?.value || '[]'),
  };

  if (isApt) {
    data.aptTitle       = document.getElementById('edit_aptTitle')?.value.trim() || '';
    data.aptType        = document.getElementById('edit_aptType')?.value || '';
    data.aptStatus      = document.getElementById('edit_aptStatus')?.value || '';
    data.aptPrice       = document.getElementById('edit_aptPrice')?.value || '';
    data.aptRooms       = document.getElementById('edit_aptRooms')?.value || '';
    data.aptOccupants   = document.getElementById('edit_aptOccupants')?.value || '';
    data.aptBath        = document.getElementById('edit_aptBath')?.value || '';
    data.aptFloor       = document.getElementById('edit_aptFloor')?.value.trim() || '';
    data.slotsAvailable = document.getElementById('edit_slotsAvailable')?.value || '';
    data.aptDesc        = document.getElementById('edit_aptDesc')?.value.trim() || '';
    data.aptIncluded    = [...document.querySelectorAll('[name="edit_apt_inc"]:checked')].map(c => c.value);
    data.aptAmenities   = [...document.querySelectorAll('[name="edit_apt_amn"]:checked')].map(c => c.value);
    data.aptRules       = [...document.querySelectorAll('[name="edit_apt_rules"]:checked')].map(c => c.value);
  } else {
    data.bussName     = document.getElementById('edit_bussName')?.value.trim() || '';
    data.bussCat      = document.getElementById('edit_bussCat')?.value || '';
    data.bussStatus   = document.getElementById('edit_bussStatus')?.value || '';
    data.bussPrice    = document.getElementById('edit_bussPrice')?.value.trim() || '';
    data.bussYears    = document.getElementById('edit_bussYears')?.value || '';
    data.bussOpen     = document.getElementById('edit_bussOpen')?.value || '';
    data.bussClose    = document.getElementById('edit_bussClose')?.value || '';
    data.bussDesc     = document.getElementById('edit_bussDesc')?.value.trim() || '';
    data.bussFeatures = [...document.querySelectorAll('[name="edit_biz_feat"]:checked')].map(c => c.value);
    data.bussDays     = [...document.querySelectorAll('[name="edit_buss_days"]:checked')].map(c => c.value);
  }

  showDialog('Save Changes', 'Are you sure you want to update this listing?', null, 'Update', 'fa-floppy-disk', false, () => {
    const btn = document.getElementById('editSaveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-sm"></i> Saving...';

    const doFetch = (body, headers) => fetch('updateListing.php', { method: 'POST', headers, body })
      .then(r => r.json())
      .then(d => {
        if (d.success) {
          closeEditModal();
          showToast('success', 'Listing Updated', 'Your changes have been saved.');
          setTimeout(() => location.reload(), 900);
        } else {
          showToast('error', 'Update Failed', d.message || 'Could not save changes.');
          btn.disabled = false;
          btn.innerHTML = 'Save Changes <i class="fa-solid fa-floppy-disk text-sm"></i>';
        }
      })
      .catch(() => {
        showToast('error', 'Network Error', 'Please try again.');
        btn.disabled = false;
        btn.innerHTML = 'Save Changes <i class="fa-solid fa-floppy-disk text-sm"></i>';
      });

    if (editNewFiles.length) {
      const fd = new FormData();
      fd.append('data', JSON.stringify(data));
      editNewFiles.forEach(f => fd.append('new_photos[]', f));
      doFetch(fd, {});
    } else {
      doFetch(JSON.stringify(data), { 'Content-Type': 'application/json' });
    }
  });
}

/* ══════════════════════════════════════════════════════════════════════
   CONFIRM DIALOG
══════════════════════════════════════════════════════════════════════ */
let dialogCallback = null;

function showDialog(title, desc, nameBadge, confirmLabel, confirmIcon, isDanger, onConfirm) {
  if (isDanger === undefined) isDanger = true;
  const overlay  = document.getElementById('dialogOverlay');
  const iconWrap = document.getElementById('dialogIconWrap');
  const iconEl   = document.getElementById('dialogIconEl');
  const btn      = document.getElementById('dialogConfirmBtn');

  iconWrap.className = 'dialog-icon-wrap ' + (isDanger ? 'dialog-icon-danger' : 'dialog-icon-info');
  iconEl.className   = 'fa-solid ' + (confirmIcon || (isDanger ? 'fa-trash' : 'fa-check'));

  document.getElementById('dialogTitle').textContent         = title || 'Confirm';
  document.getElementById('dialogDesc').textContent          = desc  || 'Are you sure?';
  document.getElementById('dialogConfirmLabel').textContent  = confirmLabel || 'Confirm';
  document.getElementById('dialogConfirmIcon').className     = 'fa-solid ' + (confirmIcon || (isDanger ? 'fa-trash' : 'fa-check'));

  if (!isDanger) { btn.style.background = '#2563eb'; btn.style.boxShadow = '0 4px 14px rgba(37,99,235,0.3)'; }
  else           { btn.style.background = '';         btn.style.boxShadow = ''; }

  const nameBadgeEl = document.getElementById('dialogNameBadge');
  if (nameBadge) { nameBadgeEl.textContent = nameBadge; nameBadgeEl.style.display = 'inline-block'; }
  else { nameBadgeEl.style.display = 'none'; }

  dialogCallback = onConfirm;
  btn.onclick = () => { closeDialog(); if (dialogCallback) dialogCallback(); };
  overlay.classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeDialog() {
  document.getElementById('dialogOverlay').classList.remove('open');
  document.body.style.overflow = '';
}

document.getElementById('dialogOverlay').addEventListener('click', function(e) {
  if (e.target === this) closeDialog();
});

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') { closeDialog(); closeViewModal(); closeEditModal(); }
});

function confirmDelete(id, name) {
  showDialog(
    'Delete Listing?',
    'This will permanently remove your listing. This action cannot be undone.',
    name, 'Yes, Delete', 'fa-trash', true,
    () => { window.location.href = 'deleteListing.php?id=' + id; }
  );
}

/* ─── Lightbox ─── */
function openLightbox(src) {
  if (!src) return;
  document.getElementById('lightboxImg').src = src;
  document.getElementById('lightbox').classList.add('open');
}
function closeLightbox() {
  document.getElementById('lightbox').classList.remove('open');
  document.getElementById('lightboxImg').src = '';
}

/* ─── Profile dropdown ─── */
function toggleProfileMenu() {
  const dd = document.getElementById('profile-dropdown');
  const ch = document.getElementById('profile-chevron');
  const open = !dd.classList.contains('hidden');
  dd.classList.toggle('hidden', open);
  ch.style.transform = open ? '' : 'rotate(180deg)';
}
document.addEventListener('click', e => {
  const w = document.getElementById('profile-menu-wrapper');
  if (w && !w.contains(e.target)) {
    document.getElementById('profile-dropdown')?.classList.add('hidden');
    const ch = document.getElementById('profile-chevron');
    if (ch) ch.style.transform = '';
  }
});

/* ─── Mobile sidebar ─── */
const mobileOverlay = document.getElementById('mobile-sidebar-overlay');
const mobileSidebar = document.getElementById('mobile-sidebar');
const mobileOpenBtn = document.getElementById('mobile-menu-btn');
const mobileCloseBtn = document.getElementById('mobile-menu-close');

function openMobileSidebar() {
  mobileOverlay.classList.remove('hidden', 'opacity-0');
  mobileOverlay.classList.add('opacity-80');
  mobileSidebar.classList.remove('translate-x-full');
  document.body.style.overflow = 'hidden';
}
function closeMobileSidebar() {
  mobileOverlay.classList.add('opacity-0');
  mobileSidebar.classList.add('translate-x-full');
  document.body.style.overflow = '';
  setTimeout(() => mobileOverlay.classList.add('hidden'), 250);
}

mobileOpenBtn?.addEventListener('click', openMobileSidebar);
mobileCloseBtn?.addEventListener('click', closeMobileSidebar);
mobileOverlay?.addEventListener('click', closeMobileSidebar);

/* ─── Slots input: numbers only ─── */
const aptSlotsEl = document.getElementById('apt_slots');
if (aptSlotsEl) {
  aptSlotsEl.addEventListener('input', function() {
    this.value = this.value.replace(/[^0-9]/g, '');
  });
}
</script>
</body>
</html>
