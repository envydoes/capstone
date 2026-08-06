<?php
session_start();

require_once __DIR__ . '/../config/db_connection.php';
require_once __DIR__ . '/../includes/site_config.php';

$siteSettings = site_config_load($conn);

// Pull back any server-side error from process_account.php redirect
$serverErrorField   = $_SESSION['reg_error_field']   ?? '';
$serverErrorMessage = $_SESSION['reg_error_message'] ?? '';
$oldEmail           = htmlspecialchars($_SESSION['reg_old_email'] ?? '');
$oldRoles           = (array)($_SESSION['reg_old_role'] ?? []);


// Clear them so they don't persist on refresh
unset($_SESSION['reg_error_field'], $_SESSION['reg_error_message'],
      $_SESSION['reg_old_email'],   $_SESSION['reg_old_role']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="../assets/responsive-global.css">
<title>Account Creation - <?= e($siteSettings['site_title']) ?></title>
<link rel="icon" href="<?= e(site_config_logo_url($siteSettings, '../')) ?>" type="image/png">
<script src="https://cdn.tailwindcss.com/3.4.16"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="/tailwind/input.css">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<?= site_config_css_vars($siteSettings) ?>
<style>
  body { font-family: 'DM Sans', sans-serif; background: var(--site-primary-pale); }
  .nav-link { position: relative; transition: color 0.2s; }
  .nav-link::after { content: ''; position: absolute; bottom: -2px; left: 0; width: 0; height: 2px; background: var(--site-primary); transition: width 0.3s ease; }
  .nav-link:hover::after { width: 100%; }
  .nav-link:hover { color: var(--site-primary-dark); }
  .step-connector { flex: 1; height: 2px; background: #d1d5db; margin: 0 8px; margin-bottom: 24px; transition: background 0.4s; }
  .step-connector.active { background: var(--site-primary); }
  .field-input {
    width: 100%; border: 1.5px solid #d1d5db; border-radius: 10px;
    padding: 12px 16px; background: #fff;
    font-size: 0.95rem; transition: border-color 0.2s, box-shadow 0.2s;
    outline: none;
  }
  .field-input:focus { border-color: var(--site-primary); box-shadow: 0 0 0 3px rgba(var(--site-primary-rgb),0.12); }
  .field-input.error { border-color: #ef4444; box-shadow: 0 0 0 3px rgba(239,68,68,0.1); }
  .field-input.valid { border-color: var(--site-primary); }

  /* Hide browser-native password reveal controls so only custom toggle is visible */
  input[type='password']::-ms-reveal,
  input[type='password']::-ms-clear {
    display: none;
  }
  .role-card {
    display: flex; align-items: center; gap: 14px;
    padding: 14px 18px; border-radius: 12px;
    border: 2px solid #e5e7eb; background: #fff;
    cursor: pointer; transition: border-color 0.2s, background 0.2s, transform 0.15s;
    user-select: none;
  }
  .role-card:hover { border-color: var(--site-primary-light); background: var(--site-primary-pale); transform: translateY(-1px); }
  .role-card.selected { border-color: var(--site-primary); background: var(--site-primary-pale); }
  .role-card input { accent-color: var(--site-primary); width: 18px; height: 18px; }
  .seg { flex: 1; height: 6px; border-radius: 4px; background: #e5e7eb; transition: background 0.3s; }
  .submit-btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 12px 28px; background: var(--site-primary); color: #fff;
    border-radius: 10px; font-weight: 600; font-size: 0.95rem;
    transition: background 0.2s, transform 0.15s, box-shadow 0.2s;
    box-shadow: 0 4px 12px rgba(var(--site-primary-rgb),0.25);
  }
  .submit-btn:hover:not(:disabled) { background: var(--site-primary-dark); transform: translateY(-1px); box-shadow: 0 6px 18px rgba(var(--site-primary-rgb),0.3); }
  .submit-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
  @keyframes fadeUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
  .fade-up { animation: fadeUp 0.55s ease both; }
  .fade-up-1 { animation-delay: 0.05s; }
  .fade-up-2 { animation-delay: 0.15s; }
  .fade-up-3 { animation-delay: 0.25s; }

  /* Tailwind-green ? theme color overrides (same pattern as login.php) */
  .bg-green-700 { background-color: var(--site-primary) !important; }
  .bg-green-600 { background-color: var(--site-primary) !important; }
  .text-green-700 { color: var(--site-primary) !important; }
  .text-green-900 { color: var(--site-primary-darker) !important; }
  .text-green-950 { color: var(--site-primary-darker) !important; }
  .text-green-600 { color: var(--site-primary) !important; }
  .text-green-300 { color: var(--site-primary-light) !important; }
  .text-green-200 { color: color-mix(in srgb, var(--site-primary-light) 50%, white) !important; }
  .border-green-600 { border-color: var(--site-primary) !important; }
  .border-green-100 { border-color: color-mix(in srgb, var(--site-primary) 20%, white) !important; }
  .hover\:bg-green-50:hover { background-color: var(--site-primary-pale) !important; }
  .bg-green-50 { background-color: var(--site-primary-pale) !important; }
  .from-green-700 { --tw-gradient-from: var(--site-primary) var(--tw-gradient-from-position) !important; --tw-gradient-to: rgb(0 0 0 / 0) var(--tw-gradient-to-position) !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to) !important; }
  .to-green-600 { --tw-gradient-to: var(--site-primary-dark) var(--tw-gradient-to-position) !important; }
</style>
</head>
<body>

<!-- NAVBAR -->
<header class="w-full h-[68px] border-b border-green-100 flex items-center px-8 bg-white shadow-sm sticky top-0 z-50">
  <div class="flex items-center gap-3">
    <a href="../landing.php" class="flex items-center gap-3">
      <div class="w-10 h-10 rounded-xl bg-green-700 flex items-center justify-center shadow overflow-hidden">
        <img src="<?= e(site_config_logo_url($siteSettings, '../')) ?>" alt="Logo" class="w-full h-full object-contain" />
      </div>
      <div>
        <h3 class="font-bold text-green-900 text-base leading-tight"><?= e($siteSettings['site_title']) ?></h3>
        <p class="text-[10px] text-green-600 tracking-widest uppercase"><?= e($siteSettings['barangay_name']) ?></p>
      </div>
    </a>
  </div>
</header>

<div class="min-h-screen py-12 px-4">

  <!-- Header -->
  <div class="text-center mb-10 fade-up fade-up-1">
    <div class="w-16 h-16 mx-auto rounded-2xl bg-green-700 flex items-center justify-center shadow-lg mb-4">
      <img src="<?= e(site_config_logo_url($siteSettings, '../')) ?>" alt="Logo" class="w-full h-full object-contain" />
    </div>
    <h1 class="text-3xl font-bold text-green-950" style="font-family:'Playfair Display',serif;"><?= e($siteSettings['site_title']) ?> Resident Registration</h1>
    <p class="text-gray-500 text-sm mt-2">Create your account to access barangay services online</p>
  </div>

  <!-- PROGRESS STEPS -->
  <div class="max-w-lg mx-auto mb-10 fade-up fade-up-2">
    <div class="flex items-center">
      <div class="flex flex-col items-center">
        <div class="w-10 h-10 rounded-full bg-green-600 text-white flex items-center justify-center font-bold shadow-md text-sm">1</div>
        <p class="mt-2 text-xs font-semibold text-green-700 text-center whitespace-nowrap">Account Creation</p>
      </div>
      <div class="step-connector active"></div>
      <div class="flex flex-col items-center">
        <div class="w-10 h-10 rounded-full bg-white border-2 border-gray-300 text-gray-400 flex items-center justify-center font-bold text-sm">2</div>
        <p class="mt-2 text-xs font-semibold text-gray-400 text-center whitespace-nowrap">Personal Info</p>
      </div>
      <div class="step-connector"></div>
      <div class="flex flex-col items-center">
        <div class="w-10 h-10 rounded-full bg-white border-2 border-gray-300 text-gray-400 flex items-center justify-center font-bold text-sm">3</div>
        <p class="mt-2 text-xs font-semibold text-gray-400 text-center whitespace-nowrap">Verification</p>
      </div>
    </div>
  </div>

  <!-- FORM CARD -->
  <div class="max-w-2xl mx-auto fade-up fade-up-3">
    <div class="bg-white rounded-2xl shadow-lg border border-green-100 overflow-hidden">

      <div class="bg-gradient-to-r from-green-700 to-green-600 px-8 py-5">
        <h2 class="text-white font-bold text-lg flex items-center gap-2">
          <i class="fa-solid fa-user-plus text-green-300"></i>
          Step 1: Account Creation
        </h2>
        <p class="text-green-200 text-xs mt-1">Fill in your credentials to get started</p>
      </div>

      <!-- Server-side error banner (shown after failed form POST) -->
      <?php if ($serverErrorMessage): ?>
      <div class="mx-8 mt-6 p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm flex items-center gap-2">
        <i class="fa-solid fa-circle-exclamation flex-shrink-0"></i>
        <?php echo htmlspecialchars($serverErrorMessage); ?>
      </div>
      <?php endif; ?>

      <form id="registrationForm" method="post" action="process_account.php" novalidate class="p-8 space-y-6">
        <input type="hidden" name="csrf_token" id="csrf_token" value="">
        <!-- EMAIL -->
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-2" for="email">
            <i class="fa-solid fa-envelope text-green-600 mr-1"></i> Email Address
          </label>
          <div class="relative">
            <input
              type="email" id="email" name="email"
              placeholder="you@example.com"
              maxlength="254" autocomplete="email"
              value="<?php echo $oldEmail; ?>"
              class="field-input pr-10 <?php echo $serverErrorField === 'email' ? 'error' : ''; ?>"
              aria-describedby="email-error" required>
            <!-- Live check spinner/icon -->
            <span id="email-status" class="absolute right-3 top-1/2 -translate-y-1/2 text-sm hidden"></span>
          </div>
          <p id="email-error" class="text-red-500 text-xs mt-1.5 <?php echo $serverErrorField === 'email' ? '' : 'hidden'; ?>" role="alert">
            <?php echo $serverErrorField === 'email' ? htmlspecialchars($serverErrorMessage) : ''; ?>
          </p>
        </div>

        <!-- PASSWORD -->
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-2" for="password">
            <i class="fa-solid fa-lock text-green-600 mr-1"></i> Password
          </label>
          <div class="relative">
            <input
              type="password" id="password" name="password"
              placeholder="Create a strong password"
              maxlength="128" autocomplete="new-password"
              class="field-input pr-12 <?php echo $serverErrorField === 'password' ? 'error' : ''; ?>"
              aria-describedby="password-error password-hint" required>
            <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-green-600 transition toggle-pw" data-target="password" aria-label="Toggle password">
              <i class="fa fa-eye"></i>
            </button>
          </div>
          <!-- Strength bar -->
          <div class="flex gap-1.5 mt-2.5">
            <div class="seg" id="seg1"></div>
            <div class="seg" id="seg2"></div>
            <div class="seg" id="seg3"></div>
            <div class="seg" id="seg4"></div>
          </div>
          <p id="strength-label" class="text-xs mt-1 text-gray-400"></p>
          <p id="password-hint" class="text-gray-400 text-xs mt-1">Min 8 characters with uppercase, lowercase, and a number. Must not match your email.</p>
          <p id="password-error" class="text-red-500 text-xs mt-1.5 <?php echo $serverErrorField === 'password' ? '' : 'hidden'; ?>" role="alert">
            <?php echo $serverErrorField === 'password' ? htmlspecialchars($serverErrorMessage) : ''; ?>
          </p>
        </div>

        <!-- CONFIRM PASSWORD -->
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-2" for="confirm_password">
            <i class="fa-solid fa-shield-halved text-green-600 mr-1"></i> Confirm Password
          </label>
          <div class="relative">
            <input
              type="password" id="confirm_password" name="confirm_password"
              placeholder="Re-enter your password"
              maxlength="128" autocomplete="new-password"
              class="field-input pr-12 <?php echo $serverErrorField === 'confirm_password' ? 'error' : ''; ?>"
              aria-describedby="confirm-error" required>
            <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-green-600 transition toggle-pw" data-target="confirm_password" aria-label="Toggle confirm password">
              <i class="fa fa-eye"></i>
            </button>
          </div>
          <p id="confirm-error" class="text-red-500 text-xs mt-1.5 <?php echo $serverErrorField === 'confirm_password' ? '' : 'hidden'; ?>" role="alert">
            <?php echo $serverErrorField === 'confirm_password' ? htmlspecialchars($serverErrorMessage) : ''; ?>
          </p>
        </div>

        <!-- ACCOUNT ROLE -->
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-3">
            <i class="fa-solid fa-id-badge text-green-600 mr-1"></i> Account Role <span class="text-red-500">*</span>
          </label>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3" role="group">

            <label class="role-card <?php echo in_array('resident', $oldRoles) ? 'selected' : ''; ?>" id="card-resident">
              <input type="checkbox" id="resident" name="account_role[]" value="resident"
                <?php echo in_array('resident', $oldRoles) ? 'checked' : ''; ?>>
              <div>
                <p class="font-semibold text-sm text-gray-800">Resident</p>
                <p class="text-xs text-gray-400 mt-0.5">Lives in Sumacab Este</p>
              </div>
            </label>

            <label class="role-card <?php echo in_array('non-resident', $oldRoles) ? 'selected' : ''; ?>" id="card-non-resident">
              <input type="checkbox" id="non-resident" name="account_role[]" value="non-resident"
                <?php echo in_array('non-resident', $oldRoles) ? 'checked' : ''; ?>>
              <div>
                <p class="font-semibold text-sm text-gray-800">Non-Resident</p>
                <p class="text-xs text-gray-400 mt-0.5">Outside the barangay</p>
              </div>
            </label>

            <label class="role-card <?php echo in_array('business/apartment owner', $oldRoles) ? 'selected' : ''; ?>" id="card-business">
              <input type="checkbox" id="business" name="account_role[]" value="business/apartment owner"
                <?php echo in_array('business/apartment owner', $oldRoles) ? 'checked' : ''; ?>>
              <div>
                <p class="font-semibold text-sm text-gray-800">Owner</p>
                <p class="text-xs text-gray-400 mt-0.5">Business / Apartment</p>
              </div>
            </label>

          </div>
          <p id="role-error" class="text-red-500 text-xs mt-2 <?php echo $serverErrorField === 'role' ? '' : 'hidden'; ?>" role="alert">
            <?php echo $serverErrorField === 'role' ? htmlspecialchars($serverErrorMessage) : ''; ?>
          </p>
        </div>

        <!-- Rate limit message -->
        <p id="rate-limit-msg" class="text-red-500 text-sm bg-red-50 border border-red-200 rounded-lg px-4 py-3 hidden">
          <i class="fa-solid fa-triangle-exclamation mr-1"></i> Too many attempts. Please wait before trying again.
        </p>

        <div class="pt-2 border-t border-gray-100 flex items-center justify-between">
          <p class="text-xs text-gray-400">All fields are required</p>
          <button type="submit" id="submitBtn" class="submit-btn">
            Next Step <i class="fa-solid fa-arrow-right text-sm"></i>
          </button>
        </div>

      </form>
    </div>

    <div class="text-center mt-5">
      <a href="login.php" class="text-sm text-green-700 hover:underline">
        <i class="fa-solid fa-arrow-left mr-1"></i> Already have an account? Log in
      </a>
    </div>
  </div>
</div>

<script>
/* ?? UTILITIES ???????????????????????????????????????????????????????????? */
function isValidEmail(e) {
  if (e.length > 254) return false;
  return /^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+$/.test(e);
}

function validatePassword(pw) {
  const e = [];
  if (pw.length < 8)     e.push('at least 8 characters');
  if (pw.length > 128)   e.push('no more than 128 characters');
  if (!/[A-Z]/.test(pw)) e.push('an uppercase letter');
  if (!/[a-z]/.test(pw)) e.push('a lowercase letter');
  if (!/[0-9]/.test(pw)) e.push('a number');
  return e;
}

function passwordStrengthScore(pw) {
  let s = 0;
  if (pw.length >= 8)  s++;
  if (pw.length >= 12) s++;
  if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) s++;
  if (/[0-9]/.test(pw)) s++;
  return Math.min(s, 4);
}

/* ?? ERROR HELPERS ???????????????????????????????????????????????????????? */
function showError(id, msg) {
  const el = document.getElementById(id);
  el.textContent = msg;
  el.classList.remove('hidden');
}
function clearError(id) {
  const el = document.getElementById(id);
  el.textContent = '';
  el.classList.add('hidden');
}
function setInputState(id, state) {
  const el = document.getElementById(id);
  el.classList.remove('error', 'valid');
  if (state) el.classList.add(state);
}

/* ?? CSRF TOKEN ??????????????????????????????????????????????????????????? */
document.getElementById('csrf_token').value = (() => {
  const a = new Uint8Array(32); crypto.getRandomValues(a);
  return Array.from(a).map(b => b.toString(16).padStart(2,'0')).join('');
})();

/* ?? STRENGTH METER ??????????????????????????????????????????????????????? */
const segEls      = [1,2,3,4].map(i => document.getElementById('seg'+i));
const segColors   = [
  ['bg-red-400',   'bg-red-400',   'bg-gray-200', 'bg-gray-200'],
  ['bg-orange-400','bg-orange-400','bg-gray-200', 'bg-gray-200'],
  ['bg-yellow-400','bg-yellow-400','bg-yellow-400','bg-gray-200'],
  ['bg-lime-500',  'bg-lime-500',  'bg-lime-500', 'bg-lime-500'],
];
const strengthTexts = ['Weak','Fair','Good','Strong'];

document.getElementById('password').addEventListener('input', function() {
  const score  = passwordStrengthScore(this.value);
  const idx    = Math.max(0, score - 1);
  const colors = this.value.length ? segColors[idx] : Array(4).fill('bg-gray-200');
  segEls.forEach((s,i) => { s.className = 'seg ' + colors[i]; });
  const lbl = document.getElementById('strength-label');
  lbl.textContent  = this.value.length ? strengthTexts[idx] : '';
  lbl.className    = 'text-xs mt-1 ' + ['text-red-500','text-orange-500','text-yellow-600','text-lime-600'][idx];
});

/* ?? TOGGLE PASSWORD VISIBILITY ??????????????????????????????????????????? */
document.querySelectorAll('.toggle-pw').forEach(btn => {
  btn.addEventListener('click', function() {
    const input = document.getElementById(this.dataset.target);
    const icon  = this.querySelector('i');
    input.type  = input.type === 'password' ? 'text' : 'password';
    icon.classList.toggle('fa-eye');
    icon.classList.toggle('fa-eye-slash');
  });
});

/* ?? ROLE CARD HIGHLIGHT ?????????????????????????????????????????????????? */
const roleMap = [
  { cb: 'resident',    card: 'card-resident' },
  { cb: 'non-resident',card: 'card-non-resident' },
  { cb: 'business/apartment owner',    card: 'card-business' },
];
function syncCard(cbId, cardId) {
  document.getElementById(cardId).classList.toggle('selected', document.getElementById(cbId).checked);
}

/* Handle role selection logic: prevent resident + non-resident */
function handleRoleSelection(changedId) {
  const resident = document.getElementById('resident');
  const nonresident = document.getElementById('non-resident');
  
  if (changedId === 'resident' && resident.checked) {
    nonresident.checked = false;
    syncCard('non-resident', 'card-non-resident');
  } else if (changedId === 'non-resident' && nonresident.checked) {
    resident.checked = false;
    syncCard('resident', 'card-resident');
  }
}

roleMap.forEach(({ cb, card }) => {
  document.getElementById(cb).addEventListener('change', function() {
    syncCard(cb, card);
    handleRoleSelection(cb);
  });
});

/* ?? LIVE EMAIL AVAILABILITY CHECK (AJAX) ????????????????????????????????? */
let emailCheckTimer = null;
let emailAvailable  = true;   // optimistic - confirmed after check

const emailInput  = document.getElementById('email');
const emailStatus = document.getElementById('email-status');

function setEmailStatus(state) {
  // state: 'checking' | 'taken' | 'ok' | ''
  emailStatus.classList.remove('hidden');
  if (state === 'checking') {
    emailStatus.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-gray-400"></i>';
  } else if (state === 'taken') {
    emailStatus.innerHTML = '<i class="fa-solid fa-circle-xmark text-red-500"></i>';
    emailAvailable = false;
  } else if (state === 'ok') {
    emailStatus.innerHTML = '<i class="fa-solid fa-circle-check text-green-500"></i>';
    emailAvailable = true;
  } else {
    emailStatus.classList.add('hidden');
    emailStatus.innerHTML = '';
  }
}

emailInput.addEventListener('input', function() {
  clearTimeout(emailCheckTimer);
  const val = this.value.trim();

  // Reset state immediately
  clearError('email-error');
  setInputState('email', '');
  setEmailStatus('');
  emailAvailable = true;

  if (!val || !isValidEmail(val)) return;

  // Debounce 600ms - don't hammer the server on every keystroke
  setEmailStatus('checking');
  emailCheckTimer = setTimeout(async () => {
    try {
      const res  = await fetch('check_email.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'email=' + encodeURIComponent(val),
      });
      const data = await res.json();

      if (data.exists) {
        setEmailStatus('taken');
        showError('email-error', 'This email is already registered. Please log in or use a different email.');
        setInputState('email', 'error');
      } else {
        setEmailStatus('ok');
        setInputState('email', 'valid');
      }
    } catch {
      // Network error - allow form to proceed; server will catch it
      setEmailStatus('');
      emailAvailable = true;
    }
  }, 600);
});

/* Also check on blur if user tabbed away quickly */
emailInput.addEventListener('blur', function() {
  clearTimeout(emailCheckTimer);
  const val = this.value.trim();
  if (!val) { showError('email-error','Email is required.'); setInputState('email','error'); return; }
  if (!isValidEmail(val)) { showError('email-error','Please enter a valid email address.'); setInputState('email','error'); }
});

/* ?? RATE LIMIT ??????????????????????????????????????????????????????????? */
let attemptCount = 0, firstAttemptTime = null;
function isRateLimited() {
  const now = Date.now();
  if (!firstAttemptTime || (now - firstAttemptTime) > 60000) { firstAttemptTime = now; attemptCount = 0; }
  return ++attemptCount > 5;
}

/* ?? FORM SUBMIT ?????????????????????????????????????????????????????????? */
document.getElementById('registrationForm').addEventListener('submit', function(e) {
  e.preventDefault();

  if (isRateLimited()) {
    document.getElementById('rate-limit-msg').classList.remove('hidden');
    return;
  }
  document.getElementById('rate-limit-msg').classList.add('hidden');

  let valid = true;

  /* 1. Email */
  const rawEmail = emailInput.value.trim();
  if (!rawEmail) {
    showError('email-error','Email is required.'); setInputState('email','error'); valid = false;
  } else if (!isValidEmail(rawEmail)) {
    showError('email-error','Please enter a valid email address.'); setInputState('email','error'); valid = false;
  } else if (!emailAvailable) {
    showError('email-error','This email is already registered.'); setInputState('email','error'); valid = false;
  } else {
    clearError('email-error'); setInputState('email','valid');
  }

  /* 2. Password */
  const password = document.getElementById('password').value;
  const pwErrors = validatePassword(password);

  if (!password) {
    showError('password-error','Password is required.'); setInputState('password','error'); valid = false;
  } else if (pwErrors.length > 0) {
    showError('password-error','Must include: ' + pwErrors.join(', ') + '.'); setInputState('password','error'); valid = false;
  } else if (password.toLowerCase() === rawEmail.toLowerCase()) {
    /* ?? Password must not equal the email ?? */
    showError('password-error','Your password must not be the same as your email address.'); setInputState('password','error'); valid = false;
  } else {
    /* Also warn if password contains the local part of the email (before @) */
    const localPart = rawEmail.split('@')[0].toLowerCase();
    if (localPart.length >= 4 && password.toLowerCase().includes(localPart)) {
      showError('password-error','Your password is too similar to your email. Please choose a different one.'); setInputState('password','error'); valid = false;
    } else {
      clearError('password-error'); setInputState('password','valid');
    }
  }

  /* 3. Confirm password */
  const confirmPw = document.getElementById('confirm_password').value;
  if (!confirmPw) {
    showError('confirm-error','Please confirm your password.'); setInputState('confirm_password','error'); valid = false;
  } else if (password !== confirmPw) {
    showError('confirm-error','Passwords do not match.'); setInputState('confirm_password','error'); valid = false;
  } else {
    clearError('confirm-error'); setInputState('confirm_password','valid');
  }

  /* 4. Roles */
  const checkedRoles  = [...document.querySelectorAll('input[name="account_role[]"]:checked')];
  const checkedValues = checkedRoles.map(cb => cb.value);
  const allowedValues = ['resident','non-resident','business/apartment owner'];
  if (checkedRoles.length === 0) {
    showError('role-error','Please select at least one account role.'); valid = false;
  } else if (checkedValues.some(val => !allowedValues.includes(val))) {
    showError('role-error','Invalid role selected.'); valid = false;
  } else if (checkedValues.includes('resident') && checkedValues.includes('non-resident')) {
    showError('role-error','You cannot be both a resident and a non-resident.'); valid = false;
  } else {
    clearError('role-error');
  }

  if (!valid) return;

  const btn = document.getElementById('submitBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';
  this.submit();
});

/* ?? LIVE VALIDATION (blur / input) ??????????????????????????????????????? */
document.getElementById('confirm_password').addEventListener('input', function() {
  const pw = document.getElementById('password').value;
  if (this.value && this.value !== pw) { showError('confirm-error','Passwords do not match.'); setInputState('confirm_password','error'); }
  else { clearError('confirm-error'); if (this.value) setInputState('confirm_password','valid'); }
});

document.getElementById('password').addEventListener('blur', function() {
  const errs     = validatePassword(this.value);
  const rawEmail = emailInput.value.trim();
  if (!this.value) return;

  if (errs.length > 0) {
    showError('password-error','Must include: ' + errs.join(', ') + '.'); setInputState('password','error');
  } else if (this.value.toLowerCase() === rawEmail.toLowerCase()) {
    showError('password-error','Your password must not be the same as your email address.'); setInputState('password','error');
  } else {
    const localPart = rawEmail.split('@')[0].toLowerCase();
    if (localPart.length >= 4 && this.value.toLowerCase().includes(localPart)) {
      showError('password-error','Your password is too similar to your email.'); setInputState('password','error');
    } else {
      clearError('password-error'); setInputState('password','valid');
    }
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
</body>
</html>