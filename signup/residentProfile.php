<?php
session_start();

require_once __DIR__ . '/../config/db_connection.php';
require_once __DIR__ . '/../includes/site_config.php';

$siteSettings = site_config_load($conn);

// Every resident registering here lives in this barangay/city by definition —
// locking these instead of free text prevents "Sumacab Este" vs "Sumaca Este"
// style inconsistencies. Province isn't stored in tbl_settings (only
// barangay_name + municipality), so it's fixed here; update if this portal
// is ever reused for a barangay in a different province.
define('SITE_PROVINCE', 'Nueva Ecija');

/* ============================================================
   SERVER-SIDE SECURITY HELPERS
   ============================================================ */

function sanitizeText(string $value, int $maxLen = 512): string
{
    $value = strip_tags($value);
    $value = trim($value);
    $value = preg_replace('/\s+/', ' ', $value);
    return mb_substr($value, 0, $maxLen, 'UTF-8');
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function allowedValue(string $value, array $allowed): string
{
    return in_array($value, $allowed, true) ? $value : '';
}

function isValidPHPhone(string $phone): bool
{
    return (bool) preg_match('/^(\+63|0)9\d{9}$/', preg_replace('/[\s\-()\+]/', '', '+' . ltrim(preg_replace('/[\s\-()]/', '', $phone), '+')));
}

function isValidPHPhoneLoose(string $phone): bool
{
    $cleaned = preg_replace('/[\s\-()]/', '', $phone);
    return (bool) preg_match('/^(\+63|0)9\d{9}$/', $cleaned);
}

function isValidBirthdate(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date && $d < new DateTime();
}

function isValidZip(string $zip): bool
{
    return (bool) preg_match('/^[A-Z0-9]{4,10}$/i', $zip);
}

function isValidIncome(string $val): bool
{
    return $val === '' || (is_numeric($val) && (float)$val >= 0 && (float)$val <= 9999999);
}

function isValidYearsResident(string $val): bool
{
    return ctype_digit($val) && (int)$val >= 0 && (int)$val <= 120;
}

/* ============================================================
   CSRF TOKEN
   ============================================================ */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

/* ============================================================
   SESSION HIGHLIGHT PAYLOAD
   ============================================================ */
$highlightPayload = $_SESSION['id_profile_mismatch'] ?? ['fields' => [], 'messages' => []];
$highlightFields  = [];
if (isset($highlightPayload['fields']) && is_array($highlightPayload['fields'])) {
    foreach ($highlightPayload['fields'] as $field) {
        if (is_string($field) && $field !== '') {
            $highlightFields[$field] = true;
        }
    }
}
$highlightMessages = [];
if (isset($highlightPayload['messages']) && is_array($highlightPayload['messages'])) {
    foreach ($highlightPayload['messages'] as $message) {
        if (is_string($message) && trim($message) !== '') {
            $highlightMessages[] = sanitizeText($message);
        }
    }
}
unset($_SESSION['id_profile_mismatch']);

/* ============================================================
   ALLOWED WHITELISTS
   ============================================================ */
$allowedFamilyRoles      = ['head', 'spouse', 'child', 'parent', 'other'];
$allowedGenders          = ['male', 'female', 'other'];
$allowedCivilStatus      = ['single', 'married', 'divorced', 'widowed', 'separated'];
$allowedEmploymentStatus = ['employed', 'self-employed', 'unemployed', 'student', 'retired', 'other'];

/* ============================================================
   PREFILL KEYS
   ============================================================ */
$prefillKeys = [
    'firstname', 'lastname', 'middlename', 'suffix', 'family_role', 'gender', 'birthday', 'birthplace',
    'civil_status', 'citizenship', 'religion', 'ethnicity',
    'street', 'barangay', 'city', 'province', 'zip',
    'phone', 'email', 'emergency_contact', 'emergency_phone', 'health_conditions',
    'employment_status', 'job_title', 'monthly_income',
    'voter_id', 'precinct', 'years_resident', 'resident_birth', 'terms',
];
$prefillData = [];
foreach ($prefillKeys as $key) {
    if (array_key_exists($key, $_SESSION)) {
        $prefillData[$key] = $_SESSION[$key];
    }
}

function oldValue(string $key): string
{
    return e((string)($_SESSION[$key] ?? ''));
}

function inputClass(string $field, array $highlightFields): string
{
    return isset($highlightFields[$field])
        ? 'field-input border-red-500 ring-2 ring-red-200'
        : 'field-input';
}

/* ============================================================
   SERVER-SIDE FORM PROCESSING
   ============================================================ */
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF check
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrfToken, $submittedToken)) {
        http_response_code(403);
        die('Invalid CSRF token. Please go back and try again.');
    }

    // Collect and sanitize
    $data = [
        'firstname'          => sanitizeText($_POST['firstname']          ?? '', 100),
        'lastname'           => sanitizeText($_POST['lastname']           ?? '', 100),
        'middlename'         => sanitizeText($_POST['middlename']         ?? '', 100),
        'suffix'             => sanitizeText($_POST['suffix']             ?? '', 20),
        'family_role'        => allowedValue($_POST['family_role']        ?? '', $allowedFamilyRoles),
        'gender'             => allowedValue($_POST['gender']             ?? '', $allowedGenders),
        'birthday'           => sanitizeText($_POST['birthday']           ?? '', 10),
        'birthplace'         => sanitizeText($_POST['birthplace']         ?? '', 200),
        'civil_status'       => allowedValue($_POST['civil_status']       ?? '', $allowedCivilStatus),
        // Dropdown value; if "Other" was picked, the free-text fallback is used instead.
        'citizenship'        => sanitizeText(
                                     ($_POST['citizenship'] ?? '') === 'Other'
                                         ? ($_POST['citizenship_other'] ?? '')
                                         : ($_POST['citizenship'] ?? ''),
                                     100
                                 ),
        'religion'           => sanitizeText(
                                     ($_POST['religion'] ?? '') === 'Other'
                                         ? ($_POST['religion_other'] ?? '')
                                         : ($_POST['religion'] ?? ''),
                                     100
                                 ),
        'ethnicity'          => sanitizeText($_POST['ethnicity']          ?? '', 100),
        'street'             => sanitizeText($_POST['street']             ?? '', 200),
        // Locked fields — always the site's own barangay/city, never trust POST here.
        'barangay'           => $siteSettings['barangay_name'],
        'city'               => $siteSettings['municipality'],
        'province'           => SITE_PROVINCE,
        'zip'                => sanitizeText($_POST['zip']                ?? '', 10),
        'phone'              => sanitizeText($_POST['phone']              ?? '', 20),
        'email'              => filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL),
        'emergency_contact'  => sanitizeText($_POST['emergency_contact']  ?? '', 150),
        'emergency_phone'    => sanitizeText($_POST['emergency_phone']    ?? '', 20),
        'health_conditions'  => sanitizeText($_POST['health_conditions']  ?? '', 10),
        'employment_status'  => allowedValue($_POST['employment_status']  ?? '', $allowedEmploymentStatus),
        'job_title'          => sanitizeText($_POST['job_title']          ?? '', 150),
        'monthly_income'     => sanitizeText($_POST['monthly_income']     ?? '', 10),
        'voter_id'           => sanitizeText($_POST['voter_id']           ?? '', 50),
        'precinct'           => sanitizeText($_POST['precinct']           ?? '', 50),
        'years_resident'     => sanitizeText($_POST['years_resident']     ?? '', 3),
        'resident_birth'     => isset($_POST['resident_birth']) ? '1' : '0',
        'terms'              => ($_POST['terms'] ?? '') === 'agree' ? 'agree' : '',
    ];

    // --- Validation ---

    if ($data['terms'] !== 'agree') {
        $errors['terms'] = 'You must agree to the Terms of Service.';
    }

    // Required text fields
    $requiredText = [
        'firstname'   => 'First name',
        'lastname'    => 'Last name',
        'birthplace'  => 'Birthplace',
        'citizenship' => 'Citizenship',
        'street'      => 'Street address',
        // barangay/city/province are locked to site settings above — always
        // populated, no need to validate them as user input.
    ];
    foreach ($requiredText as $field => $label) {
        if ($data[$field] === '') {
            $errors[$field] = "$label is required.";
        } elseif (mb_strlen($data[$field]) < 2) {
            $errors[$field] = "$label is too short.";
        }
    }

    // Name character validation
    foreach (['firstname', 'lastname', 'middlename'] as $nf) {
        if ($data[$nf] !== '' && !preg_match("/^[\p{L}\s'\-\.]+$/u", $data[$nf])) {
            $errors[$nf] = ucfirst(str_replace('name','  name', $nf)) . ' contains invalid characters.';
        }
    }

    // Select whitelists
    if ($data['family_role']  === '') $errors['family_role']  = 'Family role is required.';
    if ($data['gender']       === '') $errors['gender']       = 'Sex is required.';
    if ($data['civil_status'] === '') $errors['civil_status'] = 'Civil status is required.';

    // Birthday
    if ($data['birthday'] === '') {
        $errors['birthday'] = 'Birthday is required.';
    } elseif (!isValidBirthdate($data['birthday'])) {
        $errors['birthday'] = 'Please enter a valid birthday (must be in the past).';
    }

    // ZIP
    if ($data['zip'] === '') {
        $errors['zip'] = 'ZIP code is required.';
    } elseif (!isValidZip($data['zip'])) {
        $errors['zip'] = 'Enter a valid ZIP code (4-10 alphanumeric characters).';
    }

    // Phone
    if ($data['phone'] === '') {
        $errors['phone'] = 'Phone number is required.';
    } elseif (!isValidPHPhoneLoose($data['phone'])) {
        $errors['phone'] = 'Enter a valid PH mobile number (+639XXXXXXXXX or 09XXXXXXXXX).';
    }

    // Emergency phone (optional)
    if ($data['emergency_phone'] !== '' && !isValidPHPhoneLoose($data['emergency_phone'])) {
        $errors['emergency_phone'] = 'Enter a valid emergency phone number.';
    }

    // Email
    if ($data['email'] === '') {
        $errors['email'] = 'Email address is required.';
    } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL) || strlen($data['email']) > 254) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    // Employment status (required for residents)
    if ($data['employment_status'] === '') {
        $errors['employment_status'] = 'Employment status is required.';
    }

    // Monthly income (optional, numeric)
    if ($data['monthly_income'] !== '' && !isValidIncome($data['monthly_income'])) {
        $errors['monthly_income'] = 'Enter a valid monthly income (numbers only, max 9,999,999).';
    }

    // Years as resident
    if ($data['years_resident'] === '') {
        $errors['years_resident'] = 'Years as resident is required.';
    } elseif (!isValidYearsResident($data['years_resident'])) {
        $errors['years_resident'] = 'Enter a valid number of years (0-120).';
    }

    // Voter ID - alphanumeric + dashes only if provided
    if ($data['voter_id'] !== '' && !preg_match('/^[A-Z0-9\-]{3,50}$/i', $data['voter_id'])) {
        $errors['voter_id'] = 'Voter ID contains invalid characters.';
    }

    // Precinct - alphanumeric + dashes only if provided
    if ($data['precinct'] !== '' && !preg_match('/^[A-Z0-9\-]{1,50}$/i', $data['precinct'])) {
        $errors['precinct'] = 'Precinct number contains invalid characters.';
    }

    if (empty($errors)) {
        foreach ($data as $key => $value) {
            $_SESSION[$key] = $value;
        }
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header('Location: verification.php');
        exit;
    }

    // Repopulate session for re-display
    foreach ($data as $key => $value) {
        $_SESSION[$key] = $value;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="../assets/responsive-global.css">
<title>Personal Information - SumEste Portal</title>
<link rel="icon" href="../assets/logo2.png" type="image/png">
<script src="https://cdn.tailwindcss.com/3.4.16"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<style>
  #addressMap.leaflet-container { font-family: 'DM Sans', sans-serif; }
  .map-result-list { position:relative; }
  .map-result-list ul { list-style:none; margin:0; padding:0; position:absolute; top:calc(100% + 4px); left:0; right:0; background:#fff; border:1px solid #d1d5db; border-radius:10px; max-height:220px; overflow-y:auto; z-index:50; box-shadow:0 8px 24px rgba(0,0,0,0.1); }
  .map-result-list li { padding:9px 14px; font-size:0.85rem; cursor:pointer; border-bottom:1px solid #f1f5f9; }
  .map-result-list li:last-child { border-bottom:none; }
  .map-result-list li:hover { background:#f0fdf4; }
  body { font-family: 'DM Sans', sans-serif; background: #f0fdf4; }
  .nav-link { position: relative; transition: color 0.2s; }
  .nav-link::after { content: ''; position: absolute; bottom: -2px; left: 0; width: 0; height: 2px; background: #16a34a; transition: width 0.3s ease; }
  .nav-link:hover::after { width: 100%; }
  .nav-link:hover { color: #15803d; }
  .field-input {
    width: 100%; border: 1.5px solid #d1d5db; border-radius: 10px;
    padding: 11px 14px; background: #fff; font-size: 0.9rem;
    transition: border-color 0.2s, box-shadow 0.2s; outline: none; color: #1f2937;
  }
  .field-input:focus { border-color: #16a34a; box-shadow: 0 0 0 3px rgba(22,163,74,0.12); }
  .field-input.error { border-color: #ef4444; box-shadow: 0 0 0 3px rgba(239,68,68,0.12); }
  .field-label { display: block; font-size: 0.8rem; font-weight: 600; color: #374151; margin-bottom: 6px; }
  .required-star { color: #ef4444; margin-left: 2px; }
  .section-card {
    background: #fff; border: 1px solid #dcfce7; border-radius: 16px;
    padding: 24px; margin-bottom: 20px; box-shadow: 0 1px 6px rgba(22,101,52,0.06);
  }
  .section-title {
    font-size: 1rem; font-weight: 700; color: #14532d;
    display: flex; align-items: center; gap: 10px;
    padding-bottom: 14px; margin-bottom: 18px; border-bottom: 1.5px solid #dcfce7;
  }
  .section-icon {
    width: 34px; height: 34px; border-radius: 9px; background: #dcfce7;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
  }
  .submit-btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 12px 28px; background: #15803d; color: #fff;
    border-radius: 10px; font-weight: 600; font-size: 0.95rem;
    transition: background 0.2s, transform 0.15s, box-shadow 0.2s;
    box-shadow: 0 4px 12px rgba(21,128,61,0.25);
  }
  .submit-btn:hover { background: #166534; transform: translateY(-1px); box-shadow: 0 6px 18px rgba(21,128,61,0.3); }
  .submit-btn:disabled { opacity: 0.55; cursor: not-allowed; transform: none; }
  .terms-box {
    background: #f0fdf4; border: 1.5px solid #86efac; border-radius: 12px;
    padding: 16px 20px; display: flex; align-items: flex-start; gap: 14px; margin-bottom: 24px;
  }
  .terms-box input[type="checkbox"] { accent-color: #16a34a; width: 18px; height: 18px; margin-top: 2px; flex-shrink: 0; }
  .field-error { color: #dc2626; font-size: 0.75rem; margin-top: 4px; display: block; }
  .field-locked { display: flex; align-items: center; gap: 8px; }

  /* ── Terms Modal ── */
  .terms-modal-overlay { position: fixed; inset: 0; background: rgba(15,23,42,0.6); z-index: 900; display: none; align-items: center; justify-content: center; padding: 20px; }
  .terms-modal-overlay.open { display: flex; }
  .terms-modal-card { background: #fff; border-radius: 18px; width: 100%; max-width: 640px; max-height: 85vh; display: flex; flex-direction: column; box-shadow: 0 24px 60px rgba(0,0,0,0.25); }
  .terms-modal-header { display: flex; align-items: center; justify-content: space-between; padding: 18px 22px; border-bottom: 1px solid #f3f4f6; flex-shrink: 0; }
  .terms-modal-body { flex: 1; overflow: hidden; min-height: 0; }
  .terms-modal-body iframe { width: 100%; height: 100%; border: none; min-height: 55vh; }
  .terms-modal-footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 16px 22px; border-top: 1px solid #f3f4f6; flex-shrink: 0; flex-wrap: wrap; }
  .terms-modal-close { width: 32px; height: 32px; border-radius: 8px; border: none; background: #f3f4f6; color: #6b7280; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background 0.15s, color 0.15s; }
  .terms-modal-close:hover { background: #fee2e2; color: #dc2626; }
  .terms-summary-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; background: #f0fdf4; border: 1.5px solid #86efac; border-radius: 12px; padding: 14px 18px; margin-bottom: 24px; flex-wrap: wrap; }
  .terms-summary-text { font-size: 0.85rem; color: #14532d; display: flex; align-items: center; gap: 10px; }
  .terms-view-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 8px; border: 1.5px solid #15803d; color: #15803d; background: #fff; font-size: 0.82rem; font-weight: 700; cursor: pointer; transition: background 0.15s; white-space: nowrap; }
  .terms-view-btn:hover { background: #f0fdf4; }
  @keyframes fadeUp { from { opacity:0; transform:translateY(18px); } to { opacity:1; transform:translateY(0); } }
  .fade-up { animation: fadeUp 0.5s ease both; }
  .fade-up-1 { animation-delay: 0.05s; }
  .fade-up-2 { animation-delay: 0.15s; }
  .fade-up-3 { animation-delay: 0.22s; }
</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
</head>
<body>

<!-- NAVBAR -->
<header class="w-full h-[68px] border-b border-green-100 flex items-center px-8 bg-white shadow-sm sticky top-0 z-50">
  <div class="flex items-center gap-3">
    <a href="../landing.php" class="flex items-center gap-3">
      <div class="w-10 h-10 rounded-xl bg-green-700 flex items-center justify-center shadow overflow-hidden">
        <img src="../assets/logo2.png" alt="Logo" class="w-full h-full object-contain" />
      </div>
      <div>
        <h3 class="font-bold text-green-900 text-base leading-tight">SumEste Portal</h3>
        <p class="text-[10px] text-green-600 tracking-widest uppercase">Sumacab Este</p>
      </div>
    </a>
  </div>
</header>

<div class="min-h-screen py-10 px-4">

  <!-- Header -->
  <div class="text-center mb-8 fade-up fade-up-1">
    <div class="w-16 h-16 mx-auto rounded-2xl bg-green-700 flex items-center justify-center shadow-lg mb-4">
      <img src="../assets/logo2.png" alt="Logo" class="w-full h-full object-contain" />
    </div>
    <h1 class="text-3xl font-bold text-green-950" style="font-family:'Playfair Display',serif;">SumEste Resident Registration</h1>
    <p class="text-gray-500 text-sm mt-2">Complete your profile to access barangay services</p>
  </div>

  <!-- PROGRESS STEPS -->
  <div class="max-w-lg mx-auto mb-10 fade-up fade-up-2">
    <div class="flex items-center">
      <div class="flex flex-col items-center">
        <div class="w-10 h-10 rounded-full bg-green-600 text-white flex items-center justify-center font-bold shadow-md text-sm">
          <i class="fa-solid fa-check text-xs"></i>
        </div>
        <p class="mt-2 text-xs font-semibold text-green-700 text-center whitespace-nowrap">Account Creation</p>
      </div>
      <div style="flex:1;height:2px;background:#22c55e;margin:0 8px;margin-bottom:24px;"></div>
      <div class="flex flex-col items-center">
        <div class="w-10 h-10 rounded-full bg-green-600 text-white flex items-center justify-center font-bold shadow-md text-sm ring-4 ring-green-200">2</div>
        <p class="mt-2 text-xs font-semibold text-green-700 text-center whitespace-nowrap">Personal Info</p>
      </div>
      <div style="flex:1;height:2px;background:#e5e7eb;margin:0 8px;margin-bottom:24px;"></div>
      <div class="flex flex-col items-center">
        <div class="w-10 h-10 rounded-full bg-white border-2 border-gray-300 text-gray-400 flex items-center justify-center font-bold text-sm">3</div>
        <p class="mt-2 text-xs font-semibold text-gray-400 text-center whitespace-nowrap">Verification</p>
      </div>
    </div>
  </div>

  <!-- FORM WRAPPER -->
  <div class="max-w-4xl mx-auto fade-up fade-up-3">

    <div class="bg-gradient-to-r from-green-700 to-green-600 px-8 py-5 rounded-t-2xl">
      <h2 class="text-white font-bold text-lg flex items-center gap-2">
        <i class="fa-solid fa-user text-green-300"></i>
        Step 2: Personal Information
      </h2>
      <p class="text-green-200 text-xs mt-1">Fields marked with <span class="text-red-300 font-bold">*</span> are required</p>
    </div>

    <div class="bg-white rounded-b-2xl shadow-lg border border-green-100 border-t-0 p-8">
    <form id="residentForm" action="residentProfile.php" method="POST" novalidate autocomplete="on">

      <!-- CSRF TOKEN -->
      <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

      <?php if (!empty($errors)): ?>
      <div class="mb-6 border border-red-300 bg-red-50 text-red-700 p-4 rounded-lg text-sm" role="alert">
        <p class="font-semibold mb-2">Please correct the following errors:</p>
        <ul class="list-disc pl-5 space-y-1">
          <?php foreach ($errors as $msg): ?>
            <li><?php echo e($msg); ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <?php if (!empty($highlightMessages)): ?>
      <div class="mb-6 border border-red-300 bg-red-50 text-red-700 p-4 rounded-lg text-sm" role="alert">
        <p class="font-semibold mb-2">Please correct the highlighted fields to match your ID.</p>
        <ul class="list-disc pl-5 space-y-1">
          <?php foreach ($highlightMessages as $item): ?>
            <li><?php echo e($item); ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <!-- TERMS -->
      <div class="terms-box mb-8 <?php echo isset($errors['terms']) ? 'border-red-400 bg-red-50' : ''; ?>">
        <input type="checkbox" name="terms" value="agree" id="terms" required
               <?php echo oldValue('terms') === 'agree' ? 'checked' : ''; ?>>
        <label for="terms" class="text-sm text-green-900 leading-relaxed cursor-pointer">
          I agree to the <a href="#" onclick="openLegalModal('../infoSecurity/termsModal.php', 'Terms of Service'); return false;" class="text-green-700 font-semibold hover:underline">Terms of Service</a> and have read the
          <a href="#" onclick="openLegalModal('../infoSecurity/dataProtectionModal.php', 'Data Protection Notice'); return false;" class="text-green-700 font-semibold hover:underline">Data Protection Notice</a> regarding the use of my personal information.
        </label>
      </div>
      <input type="hidden" name="terms" id="termsHiddenInput" value="<?php echo oldValue('terms') === 'agree' ? 'agree' : ''; ?>">
      <?php if (isset($errors['terms'])): ?>
        <p class="field-error -mt-6 mb-4"><?php echo e($errors['terms']); ?></p>
      <?php endif; ?>

      <!-- PERSONAL INFORMATION -->
      <div class="section-card">
        <div class="section-title">
          <div class="section-icon"><i class="fa-solid fa-user text-green-700 text-sm"></i></div>
          Personal Information
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">

          <div>
            <label class="field-label" for="firstname">First Name <span class="required-star">*</span></label>
            <input type="text" id="firstname" name="firstname" required maxlength="100" autocomplete="given-name"
                   class="<?php echo inputClass('firstname',$highlightFields).(isset($errors['firstname'])?' error':''); ?>"
                   value="<?php echo oldValue('firstname'); ?>" placeholder="Enter your first name">
            <?php if (isset($errors['firstname'])): ?><span class="field-error"><?php echo e($errors['firstname']); ?></span><?php endif; ?>
          </div>

          <div>
            <label class="field-label" for="lastname">Last Name <span class="required-star">*</span></label>
            <input type="text" id="lastname" name="lastname" required maxlength="100" autocomplete="family-name"
                   class="<?php echo inputClass('lastname',$highlightFields).(isset($errors['lastname'])?' error':''); ?>"
                   value="<?php echo oldValue('lastname'); ?>" placeholder="Enter your last name">
            <?php if (isset($errors['lastname'])): ?><span class="field-error"><?php echo e($errors['lastname']); ?></span><?php endif; ?>
          </div>

          <div>
            <label class="field-label" for="middlename">Middle Name</label>
            <input type="text" id="middlename" name="middlename" maxlength="100" autocomplete="additional-name"
                   class="<?php echo inputClass('middlename',$highlightFields).(isset($errors['middlename'])?' error':''); ?>"
                   value="<?php echo oldValue('middlename'); ?>" placeholder="Enter your middle name">
            <?php if (isset($errors['middlename'])): ?><span class="field-error"><?php echo e($errors['middlename']); ?></span><?php endif; ?>
          </div>

          <div>
            <label class="field-label" for="suffix">Suffix</label>
            <input type="text" id="suffix" name="suffix" maxlength="20"
                   class="field-input" value="<?php echo oldValue('suffix'); ?>" placeholder="e.g., Jr., Sr., III">
          </div>

          <div>
            <label class="field-label" for="family_role">Family Role <span class="required-star">*</span></label>
            <select id="family_role" name="family_role" required
                    class="field-input <?php echo isset($errors['family_role']) ? 'error' : ''; ?>">
              <option value="">Select Family Role</option>
              <?php foreach (['head'=>'Head of Family','spouse'=>'Spouse','child'=>'Child','parent'=>'Parent','other'=>'Other'] as $v=>$l): ?>
                <option value="<?php echo e($v); ?>" <?php echo oldValue('family_role')===$v?'selected':''; ?>><?php echo e($l); ?></option>
                 <?php endforeach; ?>
            </select>
            <?php if (isset($errors['family_role'])): ?><span class="field-error"><?php echo e($errors['family_role']); ?></span><?php endif; ?>
          </div>

          <div>
            <label class="field-label" for="gender">Sex <span class="required-star">*</span></label>
            <select id="gender" name="gender" required
                    class="field-input <?php echo isset($errors['gender']) ? 'error' : ''; ?>">
              <option value="">Select Sex</option>
              <?php foreach (['male'=>'Male','female'=>'Female','other'=>'Other'] as $v=>$l): ?>
                <option value="<?php echo e($v); ?>" <?php echo oldValue('gender')===$v?'selected':''; ?>><?php echo e($l); ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (isset($errors['gender'])): ?><span class="field-error"><?php echo e($errors['gender']); ?></span><?php endif; ?>
          </div>

          <div>
            <label class="field-label" for="birthday">Birthday <span class="required-star">*</span></label>
            <input type="date" id="birthday" name="birthday" required
                   max="<?php echo date('Y-m-d'); ?>"
                   class="<?php echo inputClass('birthday',$highlightFields).(isset($errors['birthday'])?' error':''); ?>"
                   value="<?php echo oldValue('birthday'); ?>">
            <?php if (isset($errors['birthday'])): ?><span class="field-error"><?php echo e($errors['birthday']); ?></span><?php endif; ?>
          </div>

          <div>
            <label class="field-label" for="birthplace">Birthplace <span class="required-star">*</span></label>
            <input type="text" id="birthplace" name="birthplace" required maxlength="200"
                   class="field-input <?php echo isset($errors['birthplace']) ? 'error' : ''; ?>"
                   value="<?php echo oldValue('birthplace'); ?>" placeholder="City, Province, Country">
            <?php if (isset($errors['birthplace'])): ?><span class="field-error"><?php echo e($errors['birthplace']); ?></span><?php endif; ?>
          </div>

          <div>
            <label class="field-label" for="civil_status">Civil Status <span class="required-star">*</span></label>
            <select id="civil_status" name="civil_status" required
                    class="field-input <?php echo isset($errors['civil_status']) ? 'error' : ''; ?>">
              <option value="">Select Civil Status</option>
              <?php foreach (['single'=>'Single','married'=>'Married','divorced'=>'Divorced','widowed'=>'Widowed','separated'=>'Separated'] as $v=>$l): ?>
                <option value="<?php echo e($v); ?>" <?php echo oldValue('civil_status')===$v?'selected':''; ?>><?php echo e($l); ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (isset($errors['civil_status'])): ?><span class="field-error"><?php echo e($errors['civil_status']); ?></span><?php endif; ?>
          </div>

          <?php
            $citizenshipOptions = ['Filipino', 'Dual Citizen', 'Foreign National'];
            $citizenshipOld     = oldValue('citizenship');
            $citizenshipIsOther = $citizenshipOld !== '' && !in_array($citizenshipOld, $citizenshipOptions, true);

            $religionOptions = [
                'Roman Catholic', 'Islam', 'Iglesia ni Cristo', 'Born Again Christian',
                'Seventh-day Adventist', "Jehovah's Witness", 'Aglipayan (Philippine Independent Church)',
                'Baptist', 'None',
            ];
            $religionOld     = oldValue('religion');
            $religionIsOther = $religionOld !== '' && !in_array($religionOld, $religionOptions, true);
          ?>

          <div>
            <label class="field-label" for="citizenship_select">Citizenship <span class="required-star">*</span></label>
            <select id="citizenship_select"
                    class="field-input <?php echo isset($errors['citizenship']) ? 'error' : ''; ?>"
                    onchange="toggleOtherField(this, 'citizenship_other', 'citizenship')">
              <option value="">Select Citizenship</option>
              <?php foreach ($citizenshipOptions as $opt): ?>
                <option value="<?php echo e($opt); ?>" <?php echo $citizenshipOld === $opt ? 'selected' : ''; ?>><?php echo e($opt); ?></option>
              <?php endforeach; ?>
              <option value="Other" <?php echo $citizenshipIsOther ? 'selected' : ''; ?>>Other</option>
            </select>
            <input type="text" id="citizenship_other" maxlength="100"
                   class="field-input mt-2 <?php echo $citizenshipIsOther ? '' : 'hidden'; ?>"
                   placeholder="Please specify"
                   value="<?php echo $citizenshipIsOther ? e($citizenshipOld) : ''; ?>"
                   oninput="syncOtherField('citizenship_other', 'citizenship')">
            <input type="hidden" name="citizenship" id="citizenship" value="<?php echo e($citizenshipOld); ?>">
            <?php if (isset($errors['citizenship'])): ?><span class="field-error"><?php echo e($errors['citizenship']); ?></span><?php endif; ?>
          </div>

          <div>
            <label class="field-label" for="religion_select">Religion</label>
            <select id="religion_select" class="field-input" onchange="toggleOtherField(this, 'religion_other', 'religion')">
              <option value="">Select Religion (optional)</option>
              <?php foreach ($religionOptions as $opt): ?>
                <option value="<?php echo e($opt); ?>" <?php echo $religionOld === $opt ? 'selected' : ''; ?>><?php echo e($opt); ?></option>
              <?php endforeach; ?>
              <option value="Other" <?php echo $religionIsOther ? 'selected' : ''; ?>>Other</option>
            </select>
            <input type="text" id="religion_other" maxlength="100"
                   class="field-input mt-2 <?php echo $religionIsOther ? '' : 'hidden'; ?>"
                   placeholder="Please specify"
                   value="<?php echo $religionIsOther ? e($religionOld) : ''; ?>"
                   oninput="syncOtherField('religion_other', 'religion')">
            <input type="hidden" name="religion" id="religion" value="<?php echo e($religionOld); ?>">
          </div>

          <div>
            <label class="field-label" for="ethnicity">Ethnicity</label>
            <input type="text" id="ethnicity" name="ethnicity" maxlength="100"
                   class="field-input" value="<?php echo oldValue('ethnicity'); ?>" placeholder="e.g., Tagalog">
          </div>

        </div>
      </div>

      <!-- ADDRESS -->
      <div class="section-card">
        <div class="section-title">
          <div class="section-icon"><i class="fa-solid fa-location-dot text-green-700 text-sm"></i></div>
          Complete Address Information
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">

          <div>
            <label class="field-label" for="street">Street Address <span class="required-star">*</span></label>
            <input type="text" id="street" name="street" required maxlength="200" autocomplete="address-line1"
                   class="<?php echo inputClass('street',$highlightFields).(isset($errors['street'])?' error':''); ?>"
                   value="<?php echo oldValue('street'); ?>" placeholder="Street name and number">
            <?php if (isset($errors['street'])): ?><span class="field-error"><?php echo e($errors['street']); ?></span><?php endif; ?>
          </div>

          <div>
            <label class="field-label">Barangay</label>
            <div class="field-input field-locked bg-gray-50 text-gray-500 cursor-not-allowed">
              <i class="fa-solid fa-lock text-xs text-gray-400"></i> <?php echo e($siteSettings['barangay_name']); ?>
            </div>
            <input type="hidden" name="barangay" value="<?php echo e($siteSettings['barangay_name']); ?>">
            <span class="text-gray-400 text-xs mt-1 block">Fixed — this portal is only for <?php echo e($siteSettings['barangay_name']); ?> residents</span>
          </div>

          <div>
            <label class="field-label">City / Municipality</label>
            <div class="field-input field-locked bg-gray-50 text-gray-500 cursor-not-allowed">
              <i class="fa-solid fa-lock text-xs text-gray-400"></i> <?php echo e($siteSettings['municipality']); ?>
            </div>
            <input type="hidden" name="city" value="<?php echo e($siteSettings['municipality']); ?>">
          </div>

          <div>
            <label class="field-label">Province</label>
            <div class="field-input field-locked bg-gray-50 text-gray-500 cursor-not-allowed">
              <i class="fa-solid fa-lock text-xs text-gray-400"></i> <?php echo e(SITE_PROVINCE); ?>
            </div>
            <input type="hidden" name="province" value="<?php echo e(SITE_PROVINCE); ?>">
          </div>

          <div>
            <label class="field-label" for="zip">ZIP Code <span class="required-star">*</span></label>
            <input type="text" id="zip" name="zip" required maxlength="10" autocomplete="postal-code"
                   class="field-input <?php echo isset($errors['zip']) ? 'error' : ''; ?>"
                   value="<?php echo oldValue('zip'); ?>" placeholder="ZIP Code" pattern="[A-Za-z0-9]{4,10}">
            <?php if (isset($errors['zip'])): ?><span class="field-error"><?php echo e($errors['zip']); ?></span><?php endif; ?>
          </div>
              
        </div>
      </div>
      <div class="section-card">
        <div class="section-title">
          <div class="section-icon"><i class="fa-solid fa-phone text-green-700 text-sm"></i></div>
          Contact and Health Information
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">

          <div>
            <label class="field-label" for="phone">Phone Number <span class="required-star">*</span></label>
            <input type="tel" id="phone" name="phone" required maxlength="20" autocomplete="tel"
                   class="field-input <?php echo isset($errors['phone']) ? 'error' : ''; ?>"
                   value="<?php echo oldValue('phone'); ?>" placeholder="+63 912 345 6789">
            <span class="text-gray-400 text-xs mt-1 block">Format: +639XXXXXXXXX or 09XXXXXXXXX</span>
            <?php if (isset($errors['phone'])): ?><span class="field-error"><?php echo e($errors['phone']); ?></span><?php endif; ?>
          </div>

          <div>
            <label class="field-label" for="email">Email <span class="required-star">*</span></label>
            <input type="email" id="profile_email" name="email" required maxlength="254" autocomplete="email"
                   class="field-input <?php echo isset($errors['email']) ? 'error' : ''; ?>"
                   value="<?php echo oldValue('email'); ?>" placeholder="Enter your email address">
            <?php if (isset($errors['email'])): ?><span class="field-error"><?php echo e($errors['email']); ?></span><?php endif; ?>
          </div>

          <div>
            <label class="field-label" for="emergency_contact">Emergency Contact</label>
            <input type="text" id="emergency_contact" name="emergency_contact" maxlength="150"
                   class="field-input" value="<?php echo oldValue('emergency_contact'); ?>" placeholder="Name of emergency contact">
          </div>

          <div>
            <label class="field-label" for="emergency_phone">Emergency Contact Phone</label>
            <input type="tel" id="emergency_phone" name="emergency_phone" maxlength="20"
                   class="field-input <?php echo isset($errors['emergency_phone']) ? 'error' : ''; ?>"
                   value="<?php echo oldValue('emergency_phone'); ?>" placeholder="Emergency contact number">
            <?php if (isset($errors['emergency_phone'])): ?><span class="field-error"><?php echo e($errors['emergency_phone']); ?></span><?php endif; ?>
          </div>

          <div class="md:col-span-2">
            <label class="field-label" for="health_conditions">Blood Type</label>
            <input type="text" id="health_conditions" name="health_conditions" maxlength="10"
                   class="field-input" value="<?php echo oldValue('health_conditions'); ?>" placeholder="e.g., O+, A-, B+">
          </div>

        </div>
      </div>

      <!-- EMPLOYMENT -->
      <div class="section-card">
        <div class="section-title">
          <div class="section-icon"><i class="fa-solid fa-briefcase text-green-700 text-sm"></i></div>
          Employment Information
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">

          <div>
            <label class="field-label" for="employment_status">Employment Status <span class="required-star">*</span></label>
            <select id="employment_status" name="employment_status" required
                    class="field-input <?php echo isset($errors['employment_status']) ? 'error' : ''; ?>">
              <option value="">Select Employment Status</option>
              <?php foreach (['employed'=>'Employed','self-employed'=>'Self-Employed','unemployed'=>'Unemployed','student'=>'Student','retired'=>'Retired','other'=>'Other'] as $v=>$l): ?>
                <option value="<?php echo e($v); ?>" <?php echo oldValue('employment_status')===$v?'selected':''; ?>><?php echo e($l); ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (isset($errors['employment_status'])): ?><span class="field-error"><?php echo e($errors['employment_status']); ?></span><?php endif; ?>
          </div>

          <div>
            <label class="field-label" for="job_title">Job Title</label>
            <input type="text" id="job_title" name="job_title" maxlength="150"
                   class="field-input" value="<?php echo oldValue('job_title'); ?>" placeholder="Your job title">
          </div>

          <div>
            <label class="field-label" for="monthly_income">Monthly Income (PHP)</label>
            <input type="number" id="monthly_income" name="monthly_income" min="0" max="9999999" step="1"
                   class="field-input <?php echo isset($errors['monthly_income']) ? 'error' : ''; ?>"
                   value="<?php echo oldValue('monthly_income'); ?>" placeholder="e.g., 25000">
            <?php if (isset($errors['monthly_income'])): ?><span class="field-error"><?php echo e($errors['monthly_income']); ?></span><?php endif; ?>
          </div>

        </div>
      </div>

      <!-- VOTER INFORMATION -->
      <div class="section-card">
        <div class="section-title">
          <div class="section-icon"><i class="fa-solid fa-vote-yea text-green-700 text-sm"></i></div>
          Voter Information
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">

          <div>
            <label class="field-label" for="voter_id">Voter ID Number</label>
            <input type="text" id="voter_id" name="voter_id" maxlength="50"
                   class="field-input <?php echo isset($errors['voter_id']) ? 'error' : ''; ?>"
                   value="<?php echo oldValue('voter_id'); ?>" placeholder="Voter ID if applicable"
                   pattern="[A-Za-z0-9\-]{3,50}">
            <?php if (isset($errors['voter_id'])): ?><span class="field-error"><?php echo e($errors['voter_id']); ?></span><?php endif; ?>
          </div>

          <div>
            <label class="field-label" for="precinct">Precinct Number</label>
            <input type="text" id="precinct" name="precinct" maxlength="50"
                   class="field-input <?php echo isset($errors['precinct']) ? 'error' : ''; ?>"
                   value="<?php echo oldValue('precinct'); ?>" placeholder="Precinct number"
                   pattern="[A-Za-z0-9\-]{1,50}">
            <?php if (isset($errors['precinct'])): ?><span class="field-error"><?php echo e($errors['precinct']); ?></span><?php endif; ?>
          </div>

        </div>
      </div>

      <!-- RESIDENCY -->
      <div class="section-card">
        <div class="section-title">
          <div class="section-icon"><i class="fa-solid fa-house text-green-700 text-sm"></i></div>
          Residency Information
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">

          <div>
            <label class="field-label" for="years_resident">Years as Resident <span class="required-star">*</span></label>
            <input type="number" id="years_resident" name="years_resident" required min="0" max="120" step="1"
                   class="field-input <?php echo isset($errors['years_resident']) ? 'error' : ''; ?>"
                   value="<?php echo oldValue('years_resident'); ?>" placeholder="Number of years">
            <?php if (isset($errors['years_resident'])): ?><span class="field-error"><?php echo e($errors['years_resident']); ?></span><?php endif; ?>
          </div>

          <div class="flex items-end pb-1">
            <label class="flex items-center gap-3 cursor-pointer select-none">
              <div class="relative">
                <input type="checkbox" id="resident_birth" name="resident_birth" class="sr-only peer"
                       <?php echo oldValue('resident_birth') === '1' ? 'checked' : ''; ?>>
                <div class="w-11 h-6 bg-gray-200 rounded-full peer-checked:bg-green-500 transition-colors"></div>
                <div class="absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform peer-checked:translate-x-5"></div>
              </div>
              <span class="text-sm font-semibold text-gray-700">Resident since Birth</span>
            </label>
          </div>

        </div>
      </div>

      <!-- FOOTER ACTIONS -->
      <div class="flex items-center justify-between pt-4 border-t border-gray-100 mt-2">
        <a href="accountCreation.php" class="text-sm text-green-700 hover:underline flex items-center gap-1">
          <i class="fa-solid fa-arrow-left text-xs"></i> Back to Step 1
        </a>
        <button type="submit" id="submitBtn" class="submit-btn">
          Next Step <i class="fa-solid fa-arrow-right text-sm"></i>
        </button>
      </div>

    </form>
    </div>
  </div>

</div>

<!-- TERMS OF SERVICE MODAL -->
<div class="terms-modal-overlay" id="termsModalOverlay" onclick="closeTermsModalOnOverlay(event)">
  <div class="terms-modal-card" onclick="event.stopPropagation()">
    <div class="terms-modal-header">
      <div>
        <p class="font-bold text-gray-900 text-base">Terms of Service &amp; Data Protection</p>
        <p class="text-gray-400 text-xs mt-0.5">Please read before continuing</p>
      </div>
      <button type="button" class="terms-modal-close" onclick="closeTermsModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="terms-modal-body">
      <iframe src="../infoSecurity/terms.php" title="Terms of Service"></iframe>
    </div>
    <div class="terms-modal-footer">
      <a href="../infoSecurity/dataProtection.php" target="_blank" class="text-xs text-green-700 hover:underline">
        <i class="fa-solid fa-arrow-up-right-from-square mr-1"></i> View Data Protection Notice
      </a>
      <div class="flex items-center gap-3">
        <button type="button" class="text-sm text-gray-500 hover:text-gray-700 px-3 py-2" onclick="closeTermsModal()">Cancel</button>
        <button type="button" class="submit-btn" onclick="agreeToTerms()">
          <i class="fa-solid fa-check"></i> I Agree, Continue
        </button>
      </div>
    </div>
  </div>
</div>

<script>
/* ============================================================
   TERMS MODAL
   ============================================================ */
function openTermsModal() {
    document.getElementById('termsModalOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeTermsModal() {
    document.getElementById('termsModalOverlay').classList.remove('open');
    document.body.style.overflow = '';
}
function closeTermsModalOnOverlay(e) {
    if (e.target.id === 'termsModalOverlay') closeTermsModal();
}
function agreeToTerms() {
    document.getElementById('termsHiddenInput').value = 'agree';
    document.getElementById('termsSummaryRow').style.borderColor = '#15803d';
    document.getElementById('termsStatusText').innerHTML =
        '<i class="fa-solid fa-circle-check" style="color:#15803d;"></i> You agreed to the Terms of Service.';
    const errEl = document.getElementById('terms-js-err');
    if (errEl) errEl.remove();
    closeTermsModal();
}
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeTermsModal(); });

/* ============================================================
   DROPDOWN + "OTHER, PLEASE SPECIFY" FIELDS (Citizenship / Religion)
   ============================================================ */
function toggleOtherField(selectEl, otherInputId, hiddenInputId) {
    const otherInput  = document.getElementById(otherInputId);
    const hiddenInput = document.getElementById(hiddenInputId);
    if (selectEl.value === 'Other') {
        otherInput.classList.remove('hidden');
        hiddenInput.value = otherInput.value;
        otherInput.focus();
    } else {
        otherInput.classList.add('hidden');
        hiddenInput.value = selectEl.value;
    }
}
function syncOtherField(otherInputId, hiddenInputId) {
    document.getElementById(hiddenInputId).value = document.getElementById(otherInputId).value;
}

/* ============================================================
   WHITELISTS (mirror PHP)
   ============================================================ */
const ALLOWED_FAMILY_ROLES      = ['head','spouse','child','parent','other'];
const ALLOWED_GENDERS           = ['male','female','other'];
const ALLOWED_CIVIL_STATUS      = ['single','married','divorced','widowed','separated'];
const ALLOWED_EMPLOYMENT_STATUS = ['employed','self-employed','unemployed','student','retired','other'];

/* ============================================================
   UTILITIES
   ============================================================ */
function isValidEmail(email) {
    return /^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+$/.test(email) && email.length <= 254;
}

function isValidPHPhone(phone) {
    return /^(\+63|0)9\d{9}$/.test(phone.replace(/[\s\-()]/g,''));
}

function isValidZip(zip) { return /^[A-Z0-9]{4,10}$/i.test(zip); }

function isValidBirthdate(d) {
    const dt = new Date(d);
    return !isNaN(dt.getTime()) && dt < new Date();
}

function isValidIncome(val) {
    return val === '' || (!isNaN(val) && parseFloat(val) >= 0 && parseFloat(val) <= 9999999);
}

function isValidYears(val) {
    return /^\d+$/.test(val) && parseInt(val) >= 0 && parseInt(val) <= 120;
}

function isValidId(val) { return val === '' || /^[A-Za-z0-9\-]{3,50}$/.test(val); }
function isValidPrecinct(val) { return val === '' || /^[A-Za-z0-9\-]{1,50}$/.test(val); }

function showError(el, msg) {
    el.classList.add('error');
    let err = el.parentElement.querySelector('.field-error-js');
    if (!err) { err = document.createElement('span'); err.className = 'field-error field-error-js'; el.parentElement.appendChild(err); }
    err.textContent = msg;
}
function clearError(el) {
    el.classList.remove('error');
    const err = el.parentElement.querySelector('.field-error-js');
    if (err) err.remove();
}

function getLabel(el) {
    return el.labels?.[0]?.textContent?.replace('*','').trim() || 'This field';
}

function validateField(el) {
    const name = el.name, val = el.value.trim();
    clearError(el);

    if (el.required && val === '') { showError(el, `${getLabel(el)} is required.`); return false; }

    if (name === 'email'             && val && !isValidEmail(val))        { showError(el, 'Enter a valid email address.'); return false; }
    if ((name==='phone'||name==='emergency_phone') && val && !isValidPHPhone(val)) { showError(el, 'Enter a valid PH number (e.g. +639XXXXXXXXX or 09XXXXXXXXX).'); return false; }
    if (name === 'zip'               && val && !isValidZip(val))          { showError(el, 'Enter a valid ZIP code (4-10 alphanumeric).'); return false; }
    if (name === 'birthday'          && val && !isValidBirthdate(val))    { showError(el, 'Birthday must be a past date.'); return false; }
    if (name === 'monthly_income'    && val && !isValidIncome(val))       { showError(el, 'Enter a valid income (0-9,999,999).'); return false; }
    if (name === 'years_resident'    && val && !isValidYears(val))        { showError(el, 'Enter a valid number of years (0-120).'); return false; }
    if (name === 'voter_id'          && val && !isValidId(val))           { showError(el, 'Voter ID contains invalid characters.'); return false; }
    if (name === 'precinct'          && val && !isValidPrecinct(val))     { showError(el, 'Precinct contains invalid characters.'); return false; }

    if (['firstname','lastname','middlename'].includes(name) && val && !/^[\u00C0-\u024F\u1E00-\u1EFFa-zA-Z\s'\-\.]+$/.test(val)) {
        showError(el, 'Invalid characters in name.'); return false;
    }

    if (name==='family_role'        && val && !ALLOWED_FAMILY_ROLES.includes(val))      { showError(el,'Invalid selection.'); return false; }
    if (name==='gender'             && val && !ALLOWED_GENDERS.includes(val))           { showError(el,'Invalid selection.'); return false; }
    if (name==='civil_status'       && val && !ALLOWED_CIVIL_STATUS.includes(val))      { showError(el,'Invalid selection.'); return false; }
    if (name==='employment_status'  && val && !ALLOWED_EMPLOYMENT_STATUS.includes(val)) { showError(el,'Invalid selection.'); return false; }

    return true;
}

/* ============================================================
   LIVE BLUR / INPUT VALIDATION
   ============================================================ */
document.querySelectorAll('.field-input').forEach(el => {
    el.addEventListener('blur',  () => validateField(el));
    el.addEventListener('input', () => { if (el.classList.contains('error')) validateField(el); });
});

/* ============================================================
   FORM SUBMIT
   ============================================================ */
document.getElementById('residentForm').addEventListener('submit', function(e) {
    e.preventDefault();

    // Always recompute from the dropdown right before validating — don't
    // rely on onchange having already synced the hidden field. This is
    // what was causing "Citizenship is required" even when Filipino was
    // visibly selected: the hidden #citizenship input could be stale.
    function getEffectiveValue(selectId, otherId) {
        const sel = document.getElementById(selectId);
        if (sel.value === 'Other') {
            return document.getElementById(otherId).value.trim();
        }
        return sel.value;
    }
    document.getElementById('citizenship').value = getEffectiveValue('citizenship_select', 'citizenship_other');
    document.getElementById('religion').value = getEffectiveValue('religion_select', 'religion_other');

    // Terms check (now driven by the modal's hidden input, not a checkbox)
    const termsHidden = document.getElementById('termsHiddenInput');
if (termsHidden && termsHidden.value !== 'agree') {
    const row = document.getElementById('termsSummaryRow');
    
    if (row) {
        row.style.borderColor = '#ef4444';
        
        let tErr = document.getElementById('terms-js-err');
        if (!tErr) {
            tErr = document.createElement('p');
            tErr.id = 'terms-js-err';
            tErr.className = 'field-error';
            tErr.textContent = 'You must agree to the Terms of Service.';
            row.insertAdjacentElement('afterend', tErr);
        }
        
        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
    } else {
        console.warn("Element 'termsSummaryRow' was not found in the DOM.");
    }
    return;
}

    let valid = true;
    document.querySelectorAll('.field-input').forEach(el => { if (!validateField(el)) valid = false; });

    // Citizenship select has no name/required attr (its value flows through
    // the hidden #citizenship input), so check it explicitly.
    const citizenshipSelect = document.getElementById('citizenship_select');
    const citizenshipHidden = document.getElementById('citizenship');
    if (!citizenshipHidden.value.trim()) {
        showError(citizenshipSelect, 'Citizenship is required.');
        valid = false;
    } else {
        clearError(citizenshipSelect);
    }

    if (!valid) {
        const first = document.querySelector('.error, .field-error');
        if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }

    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-sm"></i> Submitting.';
    this.submit();
});

/* ============================================================
   PREFILL
   ============================================================ */
const prefillData = <?php echo json_encode($prefillData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?> || {};

function hasTruthyValue(value) {
    if (Array.isArray(value)) return value.length > 0;
    if (typeof value === 'boolean') return value;
    if (value === null || value === undefined) return false;
    const t = String(value).trim().toLowerCase();
    return t !== '' && t !== '0' && t !== 'false' && t !== 'no' && t !== 'off';
}

document.addEventListener('DOMContentLoaded', function() {
    Object.keys(prefillData).forEach(function(name) {
        const value = prefillData[name];
        const fields = document.getElementsByName(name);
        if (!fields || fields.length === 0) return;
        Array.from(fields).forEach(function(field) {
            const type = (field.type || '').toLowerCase();
            if (type === 'checkbox') {
                field.checked = Array.isArray(value) ? value.includes(field.value)
                    : (field.value && field.value !== 'on') ? String(value) === String(field.value) : hasTruthyValue(value);
                return;
            }
            if (type === 'radio') { field.checked = String(field.value) === String(value); return; }
            field.value = value ?? '';
        });
    });

    // When user selects "Resident since Birth", auto-compute birthday/years based on the other field.
    const birthCheckbox = document.getElementById('resident_birth');
    const birthdayInput = document.getElementById('birthday');
    const yearsInput = document.getElementById('years_resident');

    function computeAgeFromBirthday(birthDateString) {
        if (!birthDateString) return null;
        const bd = new Date(birthDateString);
        if (isNaN(bd.getTime())) return null;
        const today = new Date();
        let age = today.getFullYear() - bd.getFullYear();
        const monthDiff = today.getMonth() - bd.getMonth();
        const dayDiff = today.getDate() - bd.getDate();
        if (monthDiff < 0 || (monthDiff === 0 && dayDiff < 0)) {
            age -= 1;
        }
        return age;
    }

    function computeBirthdayFromYears(years) {
        const num = parseInt(String(years).replace(/[^0-9]/g, ''), 10);
        if (Number.isNaN(num) || num < 0) return null;
        const today = new Date();
        const targetYear = today.getFullYear() - num;
        // Keep month/day consistent (approximate age).
        const bd = new Date(targetYear, today.getMonth(), today.getDate());
        // Handle Feb 29 edge-case by moving to Feb 28.
        if (today.getMonth() === 1 && today.getDate() === 29 && bd.getMonth() !== 1) {
            bd.setDate(28);
        }
        return bd.toISOString().slice(0, 10);
    }

    function syncResidentBirthState() {
        if (!birthCheckbox || !birthdayInput || !yearsInput) return;

        if (!birthCheckbox.checked) return;

        const birthVal = birthdayInput.value.trim();
        const yearsVal = yearsInput.value.trim();

        // Prefer birthday -> years
        if (birthVal && !yearsVal) {
            const age = computeAgeFromBirthday(birthVal);
            if (age !== null && age >= 0 && age <= 120) {
                yearsInput.value = age;
            }
            return;
        }

        // Otherwise if years provided, fill birthday (approximate)
        if (!birthVal && yearsVal) {
            const computed = computeBirthdayFromYears(yearsVal);
            if (computed) {
                birthdayInput.value = computed;
            }
            return;
        }

        // If both present, keep years in sync with birthday
        if (birthVal && yearsVal) {
            const age = computeAgeFromBirthday(birthVal);
            if (age !== null && age >= 0 && age <= 120 && String(age) !== yearsVal) {
                yearsInput.value = age;
            }
        }
    }

    if (birthCheckbox) {
        birthCheckbox.addEventListener('change', syncResidentBirthState);
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
  })();
</script>

<!-- ══════════ LEGAL DOCUMENT MODAL (Terms of Service / Data Protection Notice) ══════════ -->
<div id="legalModalOverlay" class="hidden fixed inset-0 z-[200] bg-black/50 backdrop-blur-sm items-center justify-center p-4" style="display:none;">
  <div class="bg-white w-full max-w-3xl h-[85vh] rounded-2xl shadow-2xl flex flex-col overflow-hidden">
    <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 flex-shrink-0">
      <h3 id="legalModalTitle" class="font-bold text-green-900 text-base"></h3>
      <button type="button" onclick="closeLegalModal()" class="p-2 text-gray-500 hover:text-red-500 rounded-full hover:bg-red-50 transition" aria-label="Close">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    <div class="flex-1 overflow-hidden bg-gray-50 relative">
      <div id="legalModalLoading" class="absolute inset-0 flex items-center justify-center">
        <i class="fa-solid fa-circle-notch fa-spin text-2xl text-green-600"></i>
      </div>
      <iframe id="legalModalFrame" src="" class="w-full h-full border-0 relative z-10" onload="document.getElementById('legalModalLoading').style.display='none';"></iframe>
    </div>
  </div>
</div>
<script>
  function openLegalModal(url, title) {
    const overlay = document.getElementById('legalModalOverlay');
    const frame   = document.getElementById('legalModalFrame');
    const loading = document.getElementById('legalModalLoading');
    document.getElementById('legalModalTitle').textContent = title;
    loading.style.display = 'flex';
    frame.src = url;
    overlay.style.display = 'flex';
    overlay.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
  }
  function closeLegalModal() {
    const overlay = document.getElementById('legalModalOverlay');
    overlay.style.display = 'none';
    overlay.classList.add('hidden');
    document.getElementById('legalModalFrame').src = '';
    document.body.style.overflow = '';
  }
  document.getElementById('legalModalOverlay').addEventListener('click', function (e) {
    if (e.target === this) closeLegalModal();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeLegalModal();
  });
</script>

</body>
</html>