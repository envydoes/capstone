<?php
session_start();

/* ============================================================
   SERVER-SIDE SECURITY HELPERS
   ============================================================ */

/**
 * Sanitize a plain-text string: strip tags, normalize whitespace.
 * Returns a UTF-8 clean string safe for storage and HTML output.
 */

function sanitizeText(string $value): string
{
    $value = strip_tags($value);           // remove any HTML/PHP tags
    $value = trim($value);                 // strip leading/trailing whitespace
    $value = preg_replace('/\s+/', ' ', $value); // collapse inner whitespace
    return mb_substr($value, 0, 512, 'UTF-8');   // hard length cap
}

/**
 * Safe output: always escape before echoing into HTML.
 */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Whitelist-based select validator.
 * Returns the value if in whitelist, empty string otherwise.
 */
function allowedValue(string $value, array $allowed): string
{
    return in_array($value, $allowed, true) ? $value : '';
}

/**
 * Validate a Philippine mobile number (accepts +63 or 0 prefix).
 */
function isValidPHPhone(string $phone): bool
{
    return (bool) preg_match('/^(\+63|0)9\d{9}$/', preg_replace('/[\s\-()]/', '', $phone));
}

/**
 * Validate a date string in Y-m-d format and ensure it's in the past.
 */
function isValidBirthdate(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date && $d < new DateTime();
}

/**
 * Validate ZIP (4-10 digits/alphanumerics).
 */
function isValidZip(string $zip): bool
{
    return (bool) preg_match('/^[A-Z0-9]{4,10}$/i', $zip);
}

/* ============================================================
   SESSION HIGHLIGHT PAYLOAD (from previous ID-mismatch check)
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
            $highlightMessages[] = sanitizeText($message); // sanitize before display
        }
    }
}

unset($_SESSION['id_profile_mismatch']);

/* ============================================================
   CSRF TOKEN: generate once per session
   ============================================================ */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

/* ============================================================
   PREFILL from session (safe values already sanitized on entry)
   ============================================================ */
$prefillKeys = [
    'firstname', 'lastname', 'middlename', 'suffix', 'family_role', 'gender', 'birthday', 'birthplace',
    'civil_status', 'citizenship', 'religion', 'ethnicity',
    'street', 'barangay', 'city', 'province', 'zip', 'latitude', 'longitude',
    'phone', 'email', 'emergency_contact', 'emergency_phone', 'health_conditions', 'terms',
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
   ALLOWED OPTION WHITELISTS (used by both server + JS)
   ============================================================ */
$allowedFamilyRoles  = ['head', 'spouse', 'child', 'parent', 'other'];
$allowedGenders      = ['male', 'female', 'other'];
$allowedCivilStatus  = ['single', 'married', 'divorced', 'widowed', 'separated'];

/* ============================================================
   PH ADDRESS REFERENCE DATA (Province / City / Barangay)
   Same JSON the client-side cascading dropdowns use — loaded here too so
   the server enforces the same whitelist instead of trusting the client.
   Where we don't have a verified list yet (most cities/provinces outside
   Nueva Ecija & Metro Manila), we fall back to the old free-text checks
   for that field instead of rejecting the submission.
   ============================================================ */
$phAddressData = [];
$phAddressFile = __DIR__ . '/../assets/data/ph-address.json';
if (is_readable($phAddressFile)) {
    $decoded = json_decode(file_get_contents($phAddressFile), true);
    if (is_array($decoded)) $phAddressData = $decoded;
}
$phProvinces  = $phAddressData['provinces']  ?? [];
$phCities     = $phAddressData['cities']     ?? [];
$phBarangays  = $phAddressData['barangays']  ?? [];

/**
 * Validate a submitted value that SHOULD be a dropdown selection.
 * If we have a reference list for the given key (province name, or a
 * city name for barangays), the value must exactly match an entry in it.
 * If we don't have a reference list for that key yet, fall back to a
 * lenient free-text check (non-empty, min length) — same behavior as before.
 */
function validateAddressLevel(string $value, ?array $referenceList, string $label): ?string
{
    if ($referenceList !== null && count($referenceList) > 0) {
        return in_array($value, $referenceList, true) ? null : "Please select a valid $label from the list.";
    }
    if ($value === '') return "$label is required.";
    if (mb_strlen($value) < 2) return "$label is too short.";
    return null;
}


/* ============================================================
   SERVER-SIDE FORM PROCESSING (POST)
   ============================================================ */
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- CSRF verification ---
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrfToken, $submittedToken)) {
        http_response_code(403);
        die('Invalid CSRF token. Please go back and try again.');
    }

    // --- Collect and sanitize raw inputs ---
    $data = [
        'firstname'         => sanitizeText($_POST['firstname']         ?? ''),
        'lastname'          => sanitizeText($_POST['lastname']          ?? ''),
        'middlename'        => sanitizeText($_POST['middlename']        ?? ''),
        'suffix'            => sanitizeText($_POST['suffix']            ?? ''),
        'family_role'       => allowedValue($_POST['family_role']       ?? '', $allowedFamilyRoles),
        'gender'            => allowedValue($_POST['gender']            ?? '', $allowedGenders),
        'birthday'          => sanitizeText($_POST['birthday']          ?? ''),
        'birthplace'        => sanitizeText($_POST['birthplace']        ?? ''),
        'civil_status'      => allowedValue($_POST['civil_status']      ?? '', $allowedCivilStatus),
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
        'ethnicity'         => sanitizeText($_POST['ethnicity']         ?? ''),
        'street'            => sanitizeText($_POST['street']            ?? ''),
        'barangay'          => sanitizeText($_POST['barangay']          ?? ''),
        'city'              => sanitizeText($_POST['city']              ?? ''),
        'province'          => sanitizeText($_POST['province']          ?? ''),
        'zip'               => sanitizeText($_POST['zip']               ?? ''),
        'phone'             => sanitizeText($_POST['phone']             ?? ''),
        'email'             => filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL),
        'emergency_contact' => sanitizeText($_POST['emergency_contact'] ?? ''),
        'emergency_phone'   => sanitizeText($_POST['emergency_phone']   ?? ''),
        'health_conditions' => sanitizeText($_POST['health_conditions'] ?? ''),
        'terms'             => ($_POST['terms'] ?? '') === 'agree' ? 'agree' : '',
        'latitude'          => is_numeric($_POST['latitude'] ?? '') ? (string)(float)$_POST['latitude'] : '',
        'longitude'         => is_numeric($_POST['longitude'] ?? '') ? (string)(float)$_POST['longitude'] : '',
    ];

    // --- Validation rules ---

    // Terms
    if ($data['terms'] !== 'agree') {
        $errors['terms'] = 'You must agree to the Terms of Service.';
    }

    // Required text fields: name only (address levels are validated separately below,
    // since province/city/barangay each need dataset-aware — not plain length — checks)
    $requiredTextFields = [
        'firstname'    => 'First name',
        'lastname'     => 'Last name',
        'birthplace'   => 'Birthplace',
        'citizenship'  => 'Citizenship',
        'street'       => 'Street address',
    ];
    foreach ($requiredTextFields as $field => $label) {
        if ($data[$field] === '') {
            $errors[$field] = "$label is required.";
        } elseif (mb_strlen($data[$field]) < 2) {
            $errors[$field] = "$label is too short.";
        }
    }

    // Province / City / Barangay — validated against the PH address dataset
    // wherever we have a verified list; falls back to lenient text checks otherwise.
    $provErr = validateAddressLevel($data['province'], $phProvinces, 'Province');
    if ($provErr) $errors['province'] = $provErr;

    $cityRef = $phCities[$data['province']] ?? null; // null = no verified list for this province -> free text ok
    $cityErr = validateAddressLevel($data['city'], $cityRef, 'City / Municipality');
    if ($cityErr) $errors['city'] = $cityErr;

    $brgyRef = $phBarangays[$data['city']] ?? null; // null = no verified list for this city -> free text ok
    $brgyErr = validateAddressLevel($data['barangay'], $brgyRef, 'Barangay');
    if ($brgyErr) $errors['barangay'] = $brgyErr;

    // Name fields: only letters, spaces, hyphens, apostrophes
    foreach (['firstname', 'lastname', 'middlename'] as $nameField) {
        if ($data[$nameField] !== '' && !preg_match("/^[\p{L}\s'\-\.]+$/u", $data[$nameField])) {
            $errors[$nameField] = ucfirst(str_replace('name', ' name', $nameField)) . ' contains invalid characters.';
        }
    }

    // Select whitelists
    if ($data['family_role'] === '') $errors['family_role'] = 'Family role is required.';
    if ($data['gender']      === '') $errors['gender']      = 'Sex is required.';
    if ($data['civil_status']=== '') $errors['civil_status']= 'Civil status is required.';

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
        $errors['zip'] = 'Please enter a valid ZIP code (4-10 alphanumeric characters).';
    }

    // Phone
    if ($data['phone'] === '') {
        $errors['phone'] = 'Phone number is required.';
    } elseif (!isValidPHPhone($data['phone'])) {
        $errors['phone'] = 'Enter a valid Philippine mobile number (e.g., +639171234567 or 09171234567).';
    }

    // Emergency phone (optional but must be valid if provided)
    if ($data['emergency_phone'] !== '' && !isValidPHPhone($data['emergency_phone'])) {
        $errors['emergency_phone'] = 'Enter a valid emergency phone number.';
    }

    // Email
    if ($data['email'] === '') {
        $errors['email'] = 'Email address is required.';
    } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL) || strlen($data['email']) > 254) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    // --- If no errors, save to session and proceed ---
    if (empty($errors)) {
        foreach ($data as $key => $value) {
            $_SESSION[$key] = $value;
        }
        // Regenerate CSRF token after successful use
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header('Location: verification.php');
        exit;
    }

    // Re-populate session with submitted data for re-display
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
    transition: border-color 0.2s, box-shadow 0.2s; outline: none;
    color: #1f2937;
  }
  .field-input:focus { border-color: #16a34a; box-shadow: 0 0 0 3px rgba(22,163,74,0.12); }
  .field-input.error { border-color: #ef4444; box-shadow: 0 0 0 3px rgba(239,68,68,0.12); }

  .field-label { display: block; font-size: 0.8rem; font-weight: 600; color: #374151; margin-bottom: 6px; }
  .required-star { color: #ef4444; margin-left: 2px; }

  .section-card {
    background: #fff; border: 1px solid #dcfce7; border-radius: 16px;
    padding: 24px; margin-bottom: 20px;
    box-shadow: 0 1px 6px rgba(22,101,52,0.06);
  }
  .section-title {
    font-size: 1rem; font-weight: 700; color: #14532d;
    display: flex; align-items: center; gap: 10px;
    padding-bottom: 14px; margin-bottom: 18px;
    border-bottom: 1.5px solid #dcfce7;
  }
  .section-icon {
    width: 34px; height: 34px; border-radius: 9px;
    background: #dcfce7; display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
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
    padding: 16px 20px; display: flex; align-items: flex-start; gap: 14px;
    margin-bottom: 24px;
  }
  .terms-box input[type="checkbox"] { accent-color: #16a34a; width: 18px; height: 18px; margin-top: 2px; flex-shrink: 0; }

  .role-banner {
    background: linear-gradient(135deg, #052e16, #166534);
    border-radius: 12px; padding: 14px 20px;
    display: flex; align-items: center; gap: 14px;
    margin-bottom: 24px;
  }

  .field-error { color: #dc2626; font-size: 0.75rem; margin-top: 4px; display: block; }

  @keyframes fadeUp { from { opacity:0; transform:translateY(18px); } to { opacity:1; transform:translateY(0); } }
  .fade-up { animation: fadeUp 0.5s ease both; }
  .fade-up-1 { animation-delay: 0.05s; }
  .fade-up-2 { animation-delay: 0.15s; }
  .fade-up-3 { animation-delay: 0.22s; }
</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script src="../assets/js/ph-address-picker.js"></script>
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
    <h1 class="text-3xl font-bold text-green-950" style="font-family:'Playfair Display',serif;">SumEste Non-Resident Registration</h1>
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

    <form id="profileForm" action="nonresidentProfile.php" method="POST" novalidate autocomplete="on">

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

      <!-- Non-resident notice banner -->
      <div class="role-banner mb-6">
        <div class="w-10 h-10 rounded-xl bg-green-800 flex items-center justify-center flex-shrink-0">
          <i class="fa-solid fa-person-walking-arrow-right text-green-300 text-lg"></i>
        </div>
        <div>
          <p class="text-white font-semibold text-sm">Non-Resident Account</p>
          <p class="text-green-300 text-xs mt-0.5">You are registering as a non-resident of Sumacab Este. Some services may have limited availability.</p>
        </div>
      </div>

      <!-- TERMS -->
<div class="terms-box mb-8 <?php echo isset($errors['terms']) ? 'border-red-400 bg-red-50' : ''; ?>">
  
  <label for="terms" class="flex items-start gap-2 text-sm text-green-900 leading-relaxed cursor-pointer">
    
    <input type="checkbox" name="terms" value="agree" id="terms" required
      class="mt-1"
      <?php echo oldValue('terms') === 'agree' ? 'checked' : ''; ?>>
    
    <span>
      I agree to the 
      <a href="#" onclick="openLegalModal('../infoSecurity/termsModal.php', 'Terms of Service'); return false;" class="text-green-700 font-semibold hover:underline">Terms of Service</a> 
      and have read the 
      <a href="#" onclick="openLegalModal('../infoSecurity/dataProtectionModal.php', 'Data Protection Notice'); return false;" class="text-green-700 font-semibold hover:underline">Data Protection Notice</a> 
      regarding the use of my personal information.
    </span>

  </label>

</div>

<?php if (isset($errors['terms'])): ?>
  <p class="field-error -mt-6 mb-4"><?php echo e($errors['terms']); ?></p>
<?php endif; ?>

      <!-- PERSONAL INFORMATION -->
      <div class="section-card">
        <div class="section-title">
          <div class="section-icon">
            <i class="fa-solid fa-user text-green-700 text-sm"></i>
          </div>
          Personal Information
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">

          <!-- First Name -->
          <div>
            <label class="field-label" for="firstname">First Name <span class="required-star">*</span></label>
            <input type="text" id="firstname" name="firstname" required maxlength="100" autocomplete="given-name"
                   class="<?php echo inputClass('firstname', $highlightFields) . (isset($errors['firstname']) ? ' error' : ''); ?>"
                   value="<?php echo oldValue('firstname'); ?>" placeholder="Enter your first name"
                   pattern="^[\p{L}\s'\-\.]+$">
            <?php if (isset($errors['firstname'])): ?><span class="field-error"><?php echo e($errors['firstname']); ?></span><?php endif; ?>
          </div>

          <!-- Last Name -->
          <div>
            <label class="field-label" for="lastname">Last Name <span class="required-star">*</span></label>
            <input type="text" id="lastname" name="lastname" required maxlength="100" autocomplete="family-name"
                   class="<?php echo inputClass('lastname', $highlightFields) . (isset($errors['lastname']) ? ' error' : ''); ?>"
                   value="<?php echo oldValue('lastname'); ?>" placeholder="Enter your last name"
                   pattern="^[\p{L}\s'\-\.]+$">
            <?php if (isset($errors['lastname'])): ?><span class="field-error"><?php echo e($errors['lastname']); ?></span><?php endif; ?>
          </div>

          <!-- Middle Name -->
          <div>
            <label class="field-label" for="middlename">Middle Name</label>
            <input type="text" id="middlename" name="middlename" maxlength="100" autocomplete="additional-name"
                   class="<?php echo inputClass('middlename', $highlightFields) . (isset($errors['middlename']) ? ' error' : ''); ?>"
                   value="<?php echo oldValue('middlename'); ?>" placeholder="Enter your middle name">
            <?php if (isset($errors['middlename'])): ?><span class="field-error"><?php echo e($errors['middlename']); ?></span><?php endif; ?>
          </div>

          <!-- Suffix -->
          <div>
            <label class="field-label" for="suffix">Suffix</label>
            <input type="text" id="suffix" name="suffix" maxlength="20"
                   class="field-input" value="<?php echo oldValue('suffix'); ?>" placeholder="e.g., Jr., Sr., III">
          </div>

          <!-- Family Role -->
          <div>
            <label class="field-label" for="family_role">Family Role <span class="required-star">*</span></label>
            <select id="family_role" name="family_role" required
                    class="field-input <?php echo isset($errors['family_role']) ? 'error' : ''; ?>">
              <option value="">Select Family Role</option>
              <?php
              $roles = ['head' => 'Head of Family', 'spouse' => 'Spouse', 'child' => 'Child',
                        'parent' => 'Parent', 'other' => 'Other'];
              foreach ($roles as $val => $label):
                $sel = (oldValue('family_role') === $val) ? 'selected' : '';
              ?>
                <option value="<?php echo e($val); ?>" <?php echo $sel; ?>><?php echo e($label); ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (isset($errors['family_role'])): ?><span class="field-error"><?php echo e($errors['family_role']); ?></span><?php endif; ?>
          </div>

          <!-- Gender -->
          <div>
            <label class="field-label" for="gender">Sex <span class="required-star">*</span></label>
            <select id="gender" name="gender" required
                    class="field-input <?php echo isset($errors['gender']) ? 'error' : ''; ?>">
              <option value="">Select Sex</option>
              <?php
              $genders = ['male' => 'Male', 'female' => 'Female', 'other' => 'Other'];
              foreach ($genders as $val => $label):
                $sel = (oldValue('gender') === $val) ? 'selected' : '';
              ?>
                <option value="<?php echo e($val); ?>" <?php echo $sel; ?>><?php echo e($label); ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (isset($errors['gender'])): ?><span class="field-error"><?php echo e($errors['gender']); ?></span><?php endif; ?>
          </div>

          <!-- Birthday -->
          <div>
            <label class="field-label" for="birthday">Birthday <span class="required-star">*</span></label>
            <input type="date" id="birthday" name="birthday" required
                   max="<?php echo date('Y-m-d'); ?>"
                   class="<?php echo inputClass('birthday', $highlightFields) . (isset($errors['birthday']) ? ' error' : ''); ?>"
                   value="<?php echo oldValue('birthday'); ?>">
            <?php if (isset($errors['birthday'])): ?><span class="field-error"><?php echo e($errors['birthday']); ?></span><?php endif; ?>
          </div>

          <!-- Birthplace -->
          <div>
            <label class="field-label" for="birthplace">Birthplace <span class="required-star">*</span></label>
            <input type="text" id="birthplace" name="birthplace" required maxlength="200"
                   class="field-input <?php echo isset($errors['birthplace']) ? 'error' : ''; ?>"
                   value="<?php echo oldValue('birthplace'); ?>" placeholder="City, Province, Country">
            <?php if (isset($errors['birthplace'])): ?><span class="field-error"><?php echo e($errors['birthplace']); ?></span><?php endif; ?>
          </div>

          <!-- Civil Status -->
          <div>
            <label class="field-label" for="civil_status">Civil Status <span class="required-star">*</span></label>
            <select id="civil_status" name="civil_status" required
                    class="field-input <?php echo isset($errors['civil_status']) ? 'error' : ''; ?>">
              <option value="">Select Civil Status</option>
              <?php
              $statuses = ['single' => 'Single', 'married' => 'Married', 'divorced' => 'Divorced',
                           'widowed' => 'Widowed', 'separated' => 'Separated'];
              foreach ($statuses as $val => $label):
                $sel = (oldValue('civil_status') === $val) ? 'selected' : '';
              ?>
                <option value="<?php echo e($val); ?>" <?php echo $sel; ?>><?php echo e($label); ?></option>
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

          <!-- Religion -->
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

          <!-- Ethnicity -->
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
          <div class="section-icon">
            <i class="fa-solid fa-location-dot text-green-700 text-sm"></i>
          </div>
          Complete Address Information
        </div>
        <div class="flex items-start gap-3 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 mb-5 text-sm text-amber-800">
          <i class="fa-solid fa-circle-info text-amber-500 mt-0.5 flex-shrink-0"></i>
          <span>Please provide your <strong>current residential address</strong>, which may be outside Sumacab Este.</span>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">

          <div>
            <label class="field-label" for="province">Province <span class="required-star">*</span></label>
            <select id="province" name="province" required disabled
                    class="<?php echo inputClass('province', $highlightFields) . (isset($errors['province']) ? ' error' : ''); ?>">
              <option value="">Loading provinces…</option>
            </select>
            <?php if (isset($errors['province'])): ?><span class="field-error"><?php echo e($errors['province']); ?></span><?php endif; ?>
          </div>

          <!-- City/Municipality and Barangay are rendered into these wrappers by ph-address-picker.js:
               a <select> when we have a verified list for the chosen province/city, otherwise a
               free-text <input> fallback (identical to the old behavior) so the form never blocks. -->
          <div id="cityFieldWrap" data-error="<?php echo isset($errors['city']) ? '1' : ''; ?>">
            <label class="field-label" for="city">City / Municipality <span class="required-star">*</span></label>
            <div id="cityFieldInner"><input type="text" class="field-input" disabled placeholder="Select a province first"></div>
            <?php if (isset($errors['city'])): ?><span class="field-error"><?php echo e($errors['city']); ?></span><?php endif; ?>
          </div>

          <div id="barangayFieldWrap" data-error="<?php echo isset($errors['barangay']) ? '1' : ''; ?>">
            <label class="field-label" for="barangay">Barangay <span class="required-star">*</span></label>
            <div id="barangayFieldInner"><input type="text" class="field-input" disabled placeholder="Select a city / municipality first"></div>
            <?php if (isset($errors['barangay'])): ?><span class="field-error"><?php echo e($errors['barangay']); ?></span><?php endif; ?>
          </div>

          <div>
            <label class="field-label" for="street">Street Address <span class="required-star">*</span></label>
            <input type="text" id="street" name="street" required maxlength="200" autocomplete="address-line1"
                   class="<?php echo inputClass('street', $highlightFields) . (isset($errors['street']) ? ' error' : ''); ?>"
                   value="<?php echo oldValue('street'); ?>" placeholder="House/Unit no., Street name">
            <?php if (isset($errors['street'])): ?><span class="field-error"><?php echo e($errors['street']); ?></span><?php endif; ?>
          </div>

          <div>
            <label class="field-label" for="zip">ZIP Code <span class="required-star">*</span></label>
            <input type="text" id="zip" name="zip" required maxlength="10" autocomplete="postal-code"
                   class="field-input <?php echo isset($errors['zip']) ? 'error' : ''; ?>"
                   value="<?php echo oldValue('zip'); ?>" placeholder="ZIP Code"
                   pattern="[A-Za-z0-9]{4,10}">
            <?php if (isset($errors['zip'])): ?><span class="field-error"><?php echo e($errors['zip']); ?></span><?php endif; ?>
          </div>

        </div>
      </div>

      <!-- CONTACT & HEALTH -->
      <div class="section-card">
        <div class="section-title">
          <div class="section-icon">
            <i class="fa-solid fa-phone text-green-700 text-sm"></i>
          </div>
          Contact and Health Information
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">

          <div>
            <label class="field-label" for="phone">Phone Number <span class="required-star">*</span></label>
            <input type="tel" id="phone" name="phone" required maxlength="20" autocomplete="tel"
                   class="field-input <?php echo isset($errors['phone']) ? 'error' : ''; ?>"
                   value="<?php echo oldValue('phone'); ?>" placeholder="+63 912 345 6789"
                   pattern="^(\+63|0)9\d{9}$">
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
                   value="<?php echo oldValue('emergency_phone'); ?>" placeholder="Emergency contact number"
                   pattern="^(\+63|0)9\d{9}$">
            <?php if (isset($errors['emergency_phone'])): ?><span class="field-error"><?php echo e($errors['emergency_phone']); ?></span><?php endif; ?>
          </div>

          <div class="md:col-span-2">
            <label class="field-label" for="health_conditions">Blood Type</label>
            <input type="text" id="health_conditions" name="health_conditions" maxlength="10"
                   class="field-input" value="<?php echo oldValue('health_conditions'); ?>" placeholder="e.g., O+, A-, B+">
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

<script>
/* ============================================================
   SAFE WHITELISTS (mirror server-side PHP arrays)
   ============================================================ */
const ALLOWED_FAMILY_ROLES = ['head','spouse','child','parent','other'];
const ALLOWED_GENDERS      = ['male','female','other'];
const ALLOWED_CIVIL_STATUS = ['single','married','divorced','widowed','separated'];

/* ============================================================
   UTILITIES
   ============================================================ */
function sanitizeInput(val) {
    return val.trim()
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#x27;')
        .replace(/\//g, '&#x2F;');
}

function isValidEmail(email) {
    return /^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+$/.test(email)
        && email.length <= 254;
}

function isValidPHPhone(phone) {
    return /^(\+63|0)9\d{9}$/.test(phone.replace(/[\s\-()\+]/g, match => match === '+' ? '+' : ''));
}

function isValidZip(zip) {
    return /^[A-Z0-9]{4,10}$/i.test(zip);
}

function isValidBirthdate(dateStr) {
    const d = new Date(dateStr);
    return !isNaN(d.getTime()) && d < new Date();
}

function showError(inputEl, message) {
    inputEl.classList.add('error');
    let err = inputEl.parentElement.querySelector('.field-error-js');
    if (!err) {
        err = document.createElement('span');
        err.className = 'field-error field-error-js';
        inputEl.parentElement.appendChild(err);
    }
    err.textContent = message;
}

function clearError(inputEl) {
    inputEl.classList.remove('error');
    const err = inputEl.parentElement.querySelector('.field-error-js');
    if (err) err.remove();
}

function validateField(el) {
    const name = el.name;
    const val  = el.value.trim();
    clearError(el);

    const required = el.required;
    if (required && val === '') { showError(el, `${el.labels?.[0]?.textContent?.replace('*','').trim() || 'This field'} is required.`); return false; }

    if (name === 'email'             && val && !isValidEmail(val))    { showError(el, 'Enter a valid email address.'); return false; }
    if ((name === 'phone' || name === 'emergency_phone') && val && !isValidPHPhone(val)) { showError(el, 'Enter a valid PH number (e.g. +639XXXXXXXXX or 09XXXXXXXXX).'); return false; }
    if (name === 'zip'               && val && !isValidZip(val))      { showError(el, 'Enter a valid ZIP code (4-10 alphanumeric).'); return false; }
    if (name === 'birthday'          && val && !isValidBirthdate(val)){ showError(el, 'Birthday must be a past date.'); return false; }

    // Name character check
    if (['firstname','lastname','middlename'].includes(name) && val && !/^[\u00C0-\u024F\u1E00-\u1EFFa-zA-Z\s'\-\.]+$/.test(val)) {
        showError(el, 'Invalid characters in name.'); return false;
    }

    // Select whitelist
    if (name === 'family_role'  && val && !ALLOWED_FAMILY_ROLES.includes(val)) { showError(el, 'Invalid selection.'); return false; }
    if (name === 'gender'       && val && !ALLOWED_GENDERS.includes(val))      { showError(el, 'Invalid selection.'); return false; }
    if (name === 'civil_status' && val && !ALLOWED_CIVIL_STATUS.includes(val)) { showError(el, 'Invalid selection.'); return false; }

    return true;
}

/* ============================================================
   LIVE BLUR VALIDATION
   ============================================================ */
document.querySelectorAll('.field-input').forEach(el => {
    el.addEventListener('blur', () => validateField(el));
    el.addEventListener('input', () => { if (el.classList.contains('error')) validateField(el); });
});

/* ============================================================
   FORM SUBMIT
   ============================================================ */
document.getElementById('profileForm').addEventListener('submit', function(e) {
    e.preventDefault();

    // Terms
    const terms = document.getElementById('terms');
    if (!terms.checked) {
        const termsBox = terms.closest('.terms-box');
        termsBox.style.borderColor = '#ef4444';
        let termsErr = document.getElementById('terms-js-err');
        if (!termsErr) {
            termsErr = document.createElement('p');
            termsErr.id = 'terms-js-err';
            termsErr.className = 'field-error';
            termsErr.textContent = 'You must agree to the Terms of Service.';
            termsBox.insertAdjacentElement('afterend', termsErr);
        }
        return;
    }

    let valid = true;
    document.querySelectorAll('.field-input').forEach(el => {
        if (!validateField(el)) valid = false;
    });

    if (!valid) {
        document.querySelector('.error, .field-error')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }

    // Disable submit to prevent double-post
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-sm"></i> Submitting.';

    this.submit();
});

/* ============================================================
   PREFILL from PHP session
   ============================================================ */
const prefillData = <?php echo json_encode($prefillData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?> || {};

function hasTruthyValue(value) {
    if (Array.isArray(value)) return value.length > 0;
    if (typeof value === 'boolean') return value;
    if (value === null || value === undefined) return false;
    const t = String(value).trim().toLowerCase();
    return t !== '' && t !== '0' && t !== 'false' && t !== 'no' && t !== 'off';
}

// Province/City/Barangay/lat/lng are owned by PHAddressPicker + the map below,
// which restore their own old values from PHP — skip them here to avoid a race
// against the async dataset fetch that builds those dropdowns.
const ADDRESS_PICKER_OWNED = ['province', 'city', 'barangay', 'latitude', 'longitude'];

document.addEventListener('DOMContentLoaded', function() {
    Object.keys(prefillData).forEach(function(name) {
        if (ADDRESS_PICKER_OWNED.includes(name)) return;
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
});
</script>

<script>
/* ============================================================
   CASCADING PROVINCE -> CITY/MUNICIPALITY -> BARANGAY DROPDOWNS
   ============================================================ */
document.addEventListener('DOMContentLoaded', function () {
    const picker = new PHAddressPicker({
        dataUrl:          '../assets/data/ph-address.json',
        provinceId:       'province',
        cityWrapId:       'cityFieldInner',
        barangayWrapId:   'barangayFieldInner',
        cityFieldName:    'city',
        barangayFieldName:'barangay',
        oldProvince:      <?php echo json_encode($_SESSION['province'] ?? ''); ?>,
        oldCity:          <?php echo json_encode($_SESSION['city'] ?? ''); ?>,
        oldBarangay:      <?php echo json_encode($_SESSION['barangay'] ?? ''); ?>,
        onChange: function () {
            if (typeof checkForChanges === 'function') checkForChanges();
        }
    });
});
</script>

<script>
/* ============================================================
   LEAFLET MAP: search + drag-pin, purely optional (fills
   #latitude/#longitude hidden inputs; does not block submission)
   ============================================================ */
document.addEventListener('DOMContentLoaded', function () {
    const mapEl = document.getElementById('addressMap');
    if (!mapEl || typeof L === 'undefined') return;

    const latInput  = document.getElementById('latitude');
    const lngInput  = document.getElementById('longitude');
    const statusEl  = document.getElementById('mapStatus');
    const searchBox = document.getElementById('mapSearchBox');
    const searchBtn = document.getElementById('mapSearchBtn');

    const startLat = parseFloat(latInput.value) || 15.4869; // Cabanatuan City center as default
    const startLng = parseFloat(lngInput.value) || 120.9673;

    const map = L.map('addressMap').setView([startLat, startLng], latInput.value ? 16 : 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(map);
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

    const marker = L.marker([startLat, startLng], { draggable: true }).addTo(map);

    function setCoords(lat, lng) {
        latInput.value = lat.toFixed(6);
        lngInput.value = lng.toFixed(6);
        statusEl.textContent = 'Pin set (' + lat.toFixed(5) + ', ' + lng.toFixed(5) + ')';
    }

    if (latInput.value && lngInput.value) setCoords(startLat, startLng);

    marker.on('dragend', function () {
        const pos = marker.getLatLng();
        setCoords(pos.lat, pos.lng);
    });

    map.on('click', function (e) {
        marker.setLatLng(e.latlng);
        setCoords(e.latlng.lat, e.latlng.lng);
    });

    // Nominatim search (OpenStreetMap's free geocoder) — client-side only, no server dependency.
    let resultsBox = null;
    function clearResults() { if (resultsBox) { resultsBox.remove(); resultsBox = null; } }

    function doSearch() {
        const q = searchBox.value.trim();
        if (!q) return;
        statusEl.textContent = 'Searching…';
        fetch('https://nominatim.openstreetmap.org/search?format=json&limit=5&countrycodes=ph&q=' + encodeURIComponent(q))
            .then(function (res) { return res.json(); })
            .then(function (results) {
                clearResults();
                statusEl.textContent = '';
                if (!results.length) { statusEl.textContent = 'No results found.'; return; }
                resultsBox = document.createElement('div');
                resultsBox.className = 'map-result-list';
                const ul = document.createElement('ul');
                results.forEach(function (r) {
                    const li = document.createElement('li');
                    li.textContent = r.display_name;
                    li.addEventListener('click', function () {
                        const lat = parseFloat(r.lat), lng = parseFloat(r.lon);
                        map.setView([lat, lng], 17);
                        marker.setLatLng([lat, lng]);
                        setCoords(lat, lng);
                        clearResults();
                    });
                    ul.appendChild(li);
                });
                resultsBox.appendChild(ul);
                searchBox.parentNode.style.position = 'relative';
                searchBox.parentNode.appendChild(resultsBox);
            })
            .catch(function () { statusEl.textContent = 'Search failed — you can still drag the pin manually.'; });
    }

    searchBtn.addEventListener('click', doSearch);
    searchBox.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); doSearch(); } });
    document.addEventListener('click', function (e) {
        if (resultsBox && !resultsBox.contains(e.target) && e.target !== searchBox) clearResults();
    });
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