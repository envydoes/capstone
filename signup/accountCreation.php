<?php
session_start();

require_once __DIR__ . '/../config/db_connection.php';
require_once __DIR__ . '/../includes/site_config.php';

$siteSettings = site_config_load($conn);

$serverErrorField   = $_SESSION['reg_error_field']   ?? '';
$serverErrorMessage = $_SESSION['reg_error_message'] ?? '';
$oldEmail           = htmlspecialchars($_SESSION['reg_old_email'] ?? '');
$oldRoles           = (array)($_SESSION['reg_old_role'] ?? []);

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
  input[type='password']::-ms-reveal, input[type='password']::-ms-clear { display: none; }
  .role-card {
    display: flex; align-items: center; gap: 14px;
    padding: 14px 18px; border-radius: 12px;
    border: 2px solid #e5e7eb; background: #fff;
    cursor: pointer; transition: border-color 0.2s, background 0.2s, transform 0.15s;
    user-select: none;
  }
  .role-card:hover { border-color: var(--site-primary-light); background: var(--site-primary-pale); transform: translateY(-1px); }
  .role-card.selected { border-color: var(--site-primary); background: var(--site-primary-pale); }
  .role-card input { accent-color: var(--site-primary); width: 18px; height: 18px; flex-shrink: 0; }
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

  /* ── Password requirements checklist ── */
  .pw-info-btn { color: #9ca3af; background: none; border: none; cursor: pointer; padding: 2px 4px; transition: color 0.15s; font-size: 0.85rem; }
  .pw-info-btn:hover { color: var(--site-primary-dark); }
  .pw-requirements { margin-top: 10px; padding: 12px 14px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px; }
  .pw-requirements.hidden { display: none; }
  .pw-req-item { display: flex; align-items: center; gap: 8px; font-size: 0.78rem; padding: 3px 0; line-height: 1.3; transition: color 0.2s; }
  .pw-req-item i { font-size: 0.7rem; width: 14px; text-align: center; flex-shrink: 0; }
</style>
</head>
<body>

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

  <div class="text-center mb-10 fade-up fade-up-1">
    <div class="w-16 h-16 mx-auto rounded-2xl bg-green-700 flex items-center justify-center shadow-lg mb-4">
      <img src="<?= e(site_config_logo_url($siteSettings, '../')) ?>" alt="Logo" class="w-full h-full object-contain" />
    </div>
    <h1 class="text-3xl font-bold text-green-950" style="font-family:'Playfair Display',serif;"><?= e($siteSettings['site_title']) ?> Registration</h1>
    <p class="text-gray-500 text-sm mt-2">Create your account to access barangay services online</p>
  </div>

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

  <div class="max-w-2xl mx-auto fade-up fade-up-3">
    <div class="bg-white rounded-2xl shadow-lg border border-green-100 overflow-hidden">

      <div class="bg-gradient-to-r from-green-700 to-green-600 px-8 py-5">
        <h2 class="text-white font-bold text-lg flex items-center gap-2">
          <i class="fa-solid fa-user-plus text-green-300"></i>
          Step 1: Account Creation
        </h2>
        <p class="text-green-200 text-xs mt-1">Fill in your credentials to get started</p>
      </div>

      <?php if ($serverErrorMessage): ?>
      <div class="mx-8 mt-6 p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm flex items-center gap-2">
        <i class="fa-solid fa-circle-exclamation flex-shrink-0"></i>
        <?php echo htmlspecialchars($serverErrorMessage); ?>
      </div>
      <?php endif; ?>

      <form id="registrationForm" method="post" action="process_account.php" novalidate class="p-8 space-y-6">
        <input type="hidden" name="csrf_token" id="csrf_token" value="">

        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-2" for="email">
            <i class="fa-solid fa-envelope text-green-600 mr-1"></i> Email Address
          </label>
          <div class="relative">
            <input type="email" id="email" name="email" placeholder="you@example.com" maxlength="254" autocomplete="email"
              value="<?php echo $oldEmail; ?>"
              class="field-input pr-10 <?php echo $serverErrorField === 'email' ? 'error' : ''; ?>"
              aria-describedby="email-error" required>
            <span id="email-status" class="absolute right-3 top-1/2 -translate-y-1/2 text-sm hidden"></span>
          </div>
          <p id="email-error" class="text-red-500 text-xs mt-1.5 <?php echo $serverErrorField === 'email' ? '' : 'hidden'; ?>" role="alert">
            <?php echo $serverErrorField === 'email' ? htmlspecialchars($serverErrorMessage) : ''; ?>
          </p>
        </div>

        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-2" for="password">
            <i class="fa-solid fa-lock text-green-600 mr-1"></i> Password
          </label>
          <div class="relative">
            <input type="password" id="password" name="password" placeholder="Create a strong password" maxlength="128" autocomplete="new-password"
              class="field-input pr-12 <?php echo $serverErrorField === 'password' ? 'error' : ''; ?>"
              aria-describedby="password-error pwRequirements" required>
            <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-green-600 transition toggle-pw" data-target="password" aria-label="Toggle password">
              <i class="fa fa-eye"></i>
            </button>
          </div>

          <button type="button" id="pwInfoBtn" class="pw-info-btn mt-2" aria-expanded="false" aria-controls="pwRequirements" aria-label="Show password requirements">
            <i class="fa-solid fa-circle-info"></i> Requirements
          </button>

          <!-- ═══ Live password requirements checklist ═══ -->
          <div id="pwRequirements" class="pw-requirements hidden">
            <p id="req-length" class="pw-req-item text-gray-400"><i class="fa-solid fa-circle"></i> At least 8 characters (max 128)</p>
            <p id="req-upper" class="pw-req-item text-gray-400"><i class="fa-solid fa-circle"></i> At least one uppercase letter (A–Z)</p>
            <p id="req-lower" class="pw-req-item text-gray-400"><i class="fa-solid fa-circle"></i> At least one lowercase letter (a–z)</p>
            <p id="req-number" class="pw-req-item text-gray-400"><i class="fa-solid fa-circle"></i> At least one number (0–9)</p>
            <p id="req-special" class="pw-req-item text-gray-400"><i class="fa-solid fa-circle"></i> At least one special character (!@#$%^&*)</p>
            <p id="req-common" class="pw-req-item text-gray-400"><i class="fa-solid fa-circle"></i> Not a commonly used password</p>
            <p id="req-pwned" class="pw-req-item text-gray-400"><i class="fa-solid fa-circle"></i> Not found in known data breaches</p>
            <p id="req-pii" class="pw-req-item text-gray-400"><i class="fa-solid fa-circle"></i> Doesn't contain your email address</p>
          </div>

          <div class="flex gap-1.5 mt-2.5">
            <div class="seg" id="seg1"></div><div class="seg" id="seg2"></div><div class="seg" id="seg3"></div><div class="seg" id="seg4"></div>
          </div>
          <p id="strength-label" class="text-xs mt-1 text-gray-400"></p>
          <p id="password-error" class="text-red-500 text-xs mt-1.5 <?php echo $serverErrorField === 'password' ? '' : 'hidden'; ?>" role="alert">
            <?php echo $serverErrorField === 'password' ? htmlspecialchars($serverErrorMessage) : ''; ?>
          </p>
        </div>

        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-2" for="confirm_password">
            <i class="fa-solid fa-shield-halved text-green-600 mr-1"></i> Confirm Password
          </label>
          <div class="relative">
            <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter your password" maxlength="128" autocomplete="new-password"
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

        <!-- ═══ #4: Resident/Non-Resident = radio (mutually exclusive), Owner = checkbox ═══ -->
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-3">
            <i class="fa-solid fa-id-badge text-green-600 mr-1"></i> Account Role <span class="text-red-500">*</span>
          </label>

          <p class="text-xs text-gray-400 mb-2">Choose one — are you a resident or a non-resident of <?= e($siteSettings['barangay_name']) ?>?</p>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4" role="radiogroup" aria-label="Residency status">
            <label class="role-card <?php echo in_array('resident', $oldRoles) ? 'selected' : ''; ?>" id="card-resident">
              <input type="radio" id="resident" name="residency_type" value="resident" <?php echo in_array('resident', $oldRoles) ? 'checked' : ''; ?>>
              <div>
                <p class="font-semibold text-sm text-gray-800">Resident</p>
                <p class="text-xs text-gray-400 mt-0.5">Lives in <?= e($siteSettings['barangay_name']) ?></p>
              </div>
            </label>
            <label class="role-card <?php echo in_array('non-resident', $oldRoles) ? 'selected' : ''; ?>" id="card-non-resident">
              <input type="radio" id="non-resident" name="residency_type" value="non-resident" <?php echo in_array('non-resident', $oldRoles) ? 'checked' : ''; ?>>
              <div>
                <p class="font-semibold text-sm text-gray-800">Non-Resident</p>
                <p class="text-xs text-gray-400 mt-0.5">Outside the barangay</p>
              </div>
            </label>
          </div>

          <p class="text-xs text-gray-400 mb-2">Additionally (optional) — do you own a business or apartment here?</p>
          <label class="role-card <?php echo in_array('business/apartment owner', $oldRoles) ? 'selected' : ''; ?>" id="card-business">
            <input type="checkbox" id="business" name="is_owner" value="business/apartment owner" <?php echo in_array('business/apartment owner', $oldRoles) ? 'checked' : ''; ?>>
            <div>
              <p class="font-semibold text-sm text-gray-800">Business / Apartment Owner</p>
              <p class="text-xs text-gray-400 mt-0.5">Check this if it applies to you, in addition to the option above</p>
            </div>
          </label>

          <p id="role-error" class="text-red-500 text-xs mt-2 hidden" role="alert"></p>
        </div>

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
      <a href="../login.php" class="text-sm text-green-700 hover:underline">
        <i class="fa-solid fa-arrow-left mr-1"></i> Already have an account? Log in
      </a>
    </div>
  </div>
</div>

<script>
/* ── Commonly used / breached passwords ──
   Mirrored with isCommonPassword() in process_account.php. Client-side check
   is for instant feedback only — the server always re-checks, since this
   list can be bypassed by anyone editing the page's JS. */
const COMMON_PASSWORDS = [
  'password','12345678','123456789','1234567890','123456','1234567','12345',
  'qwerty','qwerty123','qwertyuiop','qazwsx','1q2w3e4r','1qaz2wsx','zxcvbnm','zxcvbn',
  'abc123','abc12345','password1','password12','password123','password1234','passw0rd','p@ssw0rd',
  'letmein','letmein123','welcome','welcome1','welcome123',
  'admin','admin123','admin1234','root','toor','login','default','changeme','changeme123',
  'iloveyou','iloveyou1','monkey','dragon','dragon123',
  'football','football1','football123','baseball','baseball1','basketball','soccer','hockey',
  'starwars','superman','master','sunshine','princess','shadow','freedom','trustno1',
  'whatever','solo','1q2w3e4r5t','asdf1234','asdfghjkl',
  '123123','111111','000000','666666','696969','654321','987654321','121212','112233',
  '11111111','00000000','87654321','12341234','1122334455','aaaaaaaa','abcdefgh','abcd1234',
  'mypassword','loveme','ashley','jennifer','jessica','michael','michelle','charlie','donald',
  'jordan','hunter2','access','yankees','mustang','ninja','azerty',
  'test1234','temp1234','tinkerbell','liverpool','chelsea','arsenal','flower','hottie','biteme',
  'q1w2e3r4','1q2w3e4r5t6y','google','facebook','instagram','snapchat','tinder',
  '123qwe','000000000','111111111',
];
function isCommonPasswordJS(pw) {
  const lower = pw.toLowerCase();
  if (COMMON_PASSWORDS.includes(lower)) return true;
  const stripped = lower.replace(/^[^a-z]+|[^a-z]+$/g, '');
  return stripped.length >= 4 && COMMON_PASSWORDS.includes(stripped);
}
function containsEmailPII(pw, email) {
  if (!pw || !email || !email.includes('@')) return false;
  const lowerPw = pw.toLowerCase();
  const lowerEmail = email.toLowerCase();
  if (lowerPw === lowerEmail) return true;
  const [local, domainFull] = lowerEmail.split('@');
  const domain = (domainFull || '').split('.')[0] || '';
  if (local.length >= 4 && lowerPw.includes(local)) return true;
  if (domain.length >= 4 && lowerPw.includes(domain)) return true;
  return false;
}

/* ── Live breach-database check (Have I Been Pwned, k-anonymity) ──
   Only the first 5 hex chars of the SHA-1 hash are ever sent — never the
   password, never the full hash. Fails open (doesn't block) on any error;
   the server always re-checks this regardless of what the client found. */
async function sha1Hex(str) {
  const enc = new TextEncoder().encode(str);
  const buf = await crypto.subtle.digest('SHA-1', enc);
  return Array.from(new Uint8Array(buf)).map(b => b.toString(16).padStart(2, '0')).join('').toUpperCase();
}
async function isPwnedPasswordJS(pw) {
  if (!pw) return false;
  try {
    const hash   = await sha1Hex(pw);
    const prefix = hash.slice(0, 5);
    const suffix = hash.slice(5);
    const res    = await fetch('https://api.pwnedpasswords.com/range/' + prefix);
    if (!res.ok) return false;
    const text = await res.text();
    return text.split('\r\n').some(line => line.split(':')[0] === suffix);
  } catch {
    return false;
  }
}

function isValidEmail(e) {
  if (e.length > 254) return false;
  return /^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+$/.test(e);
}
function showError(id, msg) { const el = document.getElementById(id); el.textContent = msg; el.classList.remove('hidden'); }
function clearError(id) { const el = document.getElementById(id); el.textContent = ''; el.classList.add('hidden'); }
function setInputState(id, state) {
  const el = document.getElementById(id);
  el.classList.remove('error', 'valid');
  if (state) el.classList.add(state);
}

document.getElementById('csrf_token').value = (() => {
  const a = new Uint8Array(32); crypto.getRandomValues(a);
  return Array.from(a).map(b => b.toString(16).padStart(2,'0')).join('');
})();

/* ── Password requirements checklist (info icon + live states) ── */
const pwInfoBtn      = document.getElementById('pwInfoBtn');
const pwRequirements = document.getElementById('pwRequirements');
const passwordInput  = document.getElementById('password');
let pwnedCheckTimer  = null;
let passwordPwned    = false; // fail-open default; server always re-checks on submit

pwInfoBtn.addEventListener('click', function() {
  const isOpen = !pwRequirements.classList.contains('hidden');
  pwRequirements.classList.toggle('hidden');
  pwInfoBtn.setAttribute('aria-expanded', String(!isOpen));
});
passwordInput.addEventListener('focus', function() {
  pwRequirements.classList.remove('hidden');
  pwInfoBtn.setAttribute('aria-expanded', 'true');
});

function setReqState(id, state) {
  // state: 'neutral' | 'valid' | 'invalid'
  const el   = document.getElementById(id);
  const icon = el.querySelector('i');
  el.classList.remove('text-gray-400', 'text-green-600', 'text-red-500');
  if (state === 'valid') {
    el.classList.add('text-green-600');
    icon.className = 'fa-solid fa-circle-check';
  } else if (state === 'invalid') {
    el.classList.add('text-red-500');
    icon.className = 'fa-solid fa-circle-xmark';
  } else {
    el.classList.add('text-gray-400');
    icon.className = 'fa-solid fa-circle';
  }
}

function evaluatePassword(pw, email) {
  return {
    length:  pw.length >= 8 && pw.length <= 128,
    upper:   /[A-Z]/.test(pw),
    lower:   /[a-z]/.test(pw),
    number:  /[0-9]/.test(pw),
    special: /[^A-Za-z0-9]/.test(pw),
    common:  !isCommonPasswordJS(pw),
    pii:     !containsEmailPII(pw, email),
  };
}

function updatePasswordRequirements() {
  const pw    = passwordInput.value;
  const email = emailInput.value.trim();
  const has   = evaluatePassword(pw, email);
  const empty = pw.length === 0;

  setReqState('req-length',  empty ? 'neutral' : has.length  ? 'valid' : 'invalid');
  setReqState('req-upper',   empty ? 'neutral' : has.upper   ? 'valid' : 'invalid');
  setReqState('req-lower',   empty ? 'neutral' : has.lower   ? 'valid' : 'invalid');
  setReqState('req-number',  empty ? 'neutral' : has.number  ? 'valid' : 'invalid');
  setReqState('req-special', empty ? 'neutral' : has.special ? 'valid' : 'invalid');
  setReqState('req-common',  empty ? 'neutral' : has.common  ? 'valid' : 'invalid');
  setReqState('req-pii',     empty ? 'neutral' : has.pii     ? 'valid' : 'invalid');

  return has;
}

const segEls = [1,2,3,4].map(i => document.getElementById('seg'+i));
const segColors = [
  ['bg-red-400','bg-red-400','bg-gray-200','bg-gray-200'],
  ['bg-orange-400','bg-orange-400','bg-gray-200','bg-gray-200'],
  ['bg-yellow-400','bg-yellow-400','bg-yellow-400','bg-gray-200'],
  ['bg-lime-500','bg-lime-500','bg-lime-500','bg-lime-500'],
];
const strengthTexts = ['Weak','Fair','Good','Strong'];

document.getElementById('password').addEventListener('input', function() {
  const has = updatePasswordRequirements();
  const met = [has.length, has.upper && has.lower, has.number, has.special].filter(Boolean).length;
  const idx = this.value.length ? Math.max(0, met - 1) : 0;
  const colors = this.value.length ? segColors[idx] : Array(4).fill('bg-gray-200');
  segEls.forEach((s,i) => { s.className = 'seg ' + colors[i]; });
  const lbl = document.getElementById('strength-label');
  lbl.textContent  = this.value.length ? strengthTexts[idx] : '';
  lbl.className    = 'text-xs mt-1 ' + ['text-red-500','text-orange-500','text-yellow-600','text-lime-600'][idx];

  // Debounced breach-database lookup
  clearTimeout(pwnedCheckTimer);
  const pw = this.value;
  if (!pw) { setReqState('req-pwned', 'neutral'); passwordPwned = false; return; }
  setReqState('req-pwned', 'neutral');
  pwnedCheckTimer = setTimeout(async () => {
    const pwned = await isPwnedPasswordJS(passwordInput.value);
    if (passwordInput.value !== pw) return; // password changed since the check started
    passwordPwned = pwned;
    setReqState('req-pwned', pwned ? 'invalid' : 'valid');
    if (pwned) {
      showError('password-error', 'This password has appeared in known data breaches. Please choose a different password.');
      setInputState('password', 'error');
    }
  }, 600);
});

document.querySelectorAll('.toggle-pw').forEach(btn => {
  btn.addEventListener('click', function() {
    const input = document.getElementById(this.dataset.target);
    const icon  = this.querySelector('i');
    input.type  = input.type === 'password' ? 'text' : 'password';
    icon.classList.toggle('fa-eye');
    icon.classList.toggle('fa-eye-slash');
  });
});

/* Resident / Non-Resident are real <input type="radio"> now — the browser
   itself enforces "only one selected," no manual JS conflict-resolution
   needed anymore (previously required for the checkbox version). */
document.querySelectorAll('input[name="residency_type"]').forEach(radio => {
  radio.addEventListener('change', () => {
    document.getElementById('card-resident').classList.toggle('selected', document.getElementById('resident').checked);
    document.getElementById('card-non-resident').classList.toggle('selected', document.getElementById('non-resident').checked);
  });
});
document.getElementById('business').addEventListener('change', function() {
  document.getElementById('card-business').classList.toggle('selected', this.checked);
});

/* ── Live email availability check ── */
let emailCheckTimer = null;
let emailAvailable  = true;
const emailInput  = document.getElementById('email');
const emailStatus = document.getElementById('email-status');

function setEmailStatus(state) {
  emailStatus.classList.remove('hidden');
  if (state === 'checking') { emailStatus.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-gray-400"></i>'; }
  else if (state === 'taken') { emailStatus.innerHTML = '<i class="fa-solid fa-circle-xmark text-red-500"></i>'; emailAvailable = false; }
  else if (state === 'ok') { emailStatus.innerHTML = '<i class="fa-solid fa-circle-check text-green-500"></i>'; emailAvailable = true; }
  else { emailStatus.classList.add('hidden'); emailStatus.innerHTML = ''; }
}

emailInput.addEventListener('input', function() {
  clearTimeout(emailCheckTimer);
  const val = this.value.trim();
  clearError('email-error'); setInputState('email', ''); setEmailStatus(''); emailAvailable = true;
  if (passwordInput.value) updatePasswordRequirements(); // re-check PII as email changes
  if (!val || !isValidEmail(val)) return;
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
        setEmailStatus('ok'); setInputState('email', 'valid');
      }
    } catch { setEmailStatus(''); emailAvailable = true; }
  }, 600);
});

emailInput.addEventListener('blur', function() {
  clearTimeout(emailCheckTimer);
  const val = this.value.trim();
  if (!val) { showError('email-error','Email is required.'); setInputState('email','error'); return; }
  if (!isValidEmail(val)) { showError('email-error','Please enter a valid email address.'); setInputState('email','error'); }
});

/* ── Rate limit ── */
let attemptCount = 0, firstAttemptTime = null;
function isRateLimited() {
  const now = Date.now();
  if (!firstAttemptTime || (now - firstAttemptTime) > 60000) { firstAttemptTime = now; attemptCount = 0; }
  return ++attemptCount > 5;
}

/* ── Form submit ── */
document.getElementById('registrationForm').addEventListener('submit', function(e) {
  e.preventDefault();

  if (isRateLimited()) {
    document.getElementById('rate-limit-msg').classList.remove('hidden');
    return;
  }
  document.getElementById('rate-limit-msg').classList.add('hidden');

  let valid = true;
  const rawEmail = emailInput.value.trim();

  if (!rawEmail) { showError('email-error','Email is required.'); setInputState('email','error'); valid = false; }
  else if (!isValidEmail(rawEmail)) { showError('email-error','Please enter a valid email address.'); setInputState('email','error'); valid = false; }
  else if (!emailAvailable) { showError('email-error','This email is already registered.'); setInputState('email','error'); valid = false; }
  else { clearError('email-error'); setInputState('email','valid'); }

  const password = passwordInput.value;
  const has = updatePasswordRequirements();
  const missing = [];
  if (!has.length)  missing.push('at least 8 characters');
  if (!has.upper)   missing.push('an uppercase letter');
  if (!has.lower)   missing.push('a lowercase letter');
  if (!has.number)  missing.push('a number');
  if (!has.special) missing.push('a special character (e.g. !@#$%^&*)');

  if (!password) {
    showError('password-error','Password is required.'); setInputState('password','error'); valid = false;
  } else if (missing.length > 0) {
    showError('password-error','Must include: ' + missing.join(', ') + '.'); setInputState('password','error'); valid = false;
    pwRequirements.classList.remove('hidden');
  } else if (!has.common) {
    showError('password-error','This password is too common and not allowed (e.g. "password1234"). Please choose something more unique.'); setInputState('password','error'); valid = false;
    pwRequirements.classList.remove('hidden');
  } else if (passwordPwned) {
    showError('password-error','This password has appeared in known data breaches. Please choose a different password.'); setInputState('password','error'); valid = false;
    pwRequirements.classList.remove('hidden');
  } else if (!has.pii) {
    showError('password-error','Your password must not contain your email address or parts of it.'); setInputState('password','error'); valid = false;
    pwRequirements.classList.remove('hidden');
  } else {
    clearError('password-error'); setInputState('password','valid');
  }

  const confirmPw = document.getElementById('confirm_password').value;
  if (!confirmPw) { showError('confirm-error','Please confirm your password.'); setInputState('confirm_password','error'); valid = false; }
  else if (password !== confirmPw) { showError('confirm-error','Passwords do not match.'); setInputState('confirm_password','error'); valid = false; }
  else { clearError('confirm-error'); setInputState('confirm_password','valid'); }

  /* Residency type — now a radio group, so we just check one is checked */
  const residencyChecked = document.querySelector('input[name="residency_type"]:checked');
  const roleErrorEl = document.getElementById('role-error');
  if (!residencyChecked) {
    roleErrorEl.textContent = 'Please select whether you are a Resident or Non-Resident.';
    roleErrorEl.classList.remove('hidden');
    valid = false;
  } else {
    roleErrorEl.classList.add('hidden');
  }

  if (!valid) return;

  const btn = document.getElementById('submitBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';
  this.submit();
});

document.getElementById('confirm_password').addEventListener('input', function() {
  const pw = passwordInput.value;
  if (this.value && this.value !== pw) { showError('confirm-error','Passwords do not match.'); setInputState('confirm_password','error'); }
  else { clearError('confirm-error'); if (this.value) setInputState('confirm_password','valid'); }
});

document.getElementById('password').addEventListener('blur', function() {
  const rawEmail = emailInput.value.trim();
  const has = updatePasswordRequirements();
  if (!this.value) return;

  const missing = [];
  if (!has.length)  missing.push('at least 8 characters');
  if (!has.upper)   missing.push('an uppercase letter');
  if (!has.lower)   missing.push('a lowercase letter');
  if (!has.number)  missing.push('a number');
  if (!has.special) missing.push('a special character');

  if (missing.length > 0) {
    showError('password-error','Must include: ' + missing.join(', ') + '.'); setInputState('password','error');
  } else if (!has.common) {
    showError('password-error','This password is too common and not allowed (e.g. "password1234").'); setInputState('password','error');
  } else if (!has.pii) {
    showError('password-error','Your password must not contain your email address.'); setInputState('password','error');
  } else {
    clearError('password-error'); setInputState('password','valid');
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
        if (link.getAttribute("target") === "_blank") { window.open(target, "_blank", "noopener"); }
        else { window.location.href = target; }
      });
    });
  })();
</script>
</body>
</html>