<?php
session_start();
require_once __DIR__ . '/config/db_connection.php';

$selector   = $_GET['selector']  ?? $_POST['selector']  ?? '';
$tokenHex   = $_GET['token']     ?? $_POST['token']     ?? '';

$error      = null;
$validLink  = false;
$resetDone  = false;

/* ?? helpers ??????????????????????????????????????????????????????????????? */
function isValidHex(string $v): bool { return ctype_xdigit($v); }

function validatePassword(string $pw): array
{
    $errors = [];
    if (strlen($pw) < 8)     $errors[] = 'at least 8 characters';
    if (strlen($pw) > 72)    $errors[] = 'no more than 72 characters';
    if (!preg_match('/[A-Z]/', $pw)) $errors[] = 'an uppercase letter';
    if (!preg_match('/[a-z]/', $pw)) $errors[] = 'a lowercase letter';
    if (!preg_match('/[0-9]/', $pw)) $errors[] = 'a number';
    return $errors;
}

/* ?? validate selector / token ????????????????????????????????????????????? */
if (
    strlen($selector) === 16 &&
    strlen($tokenHex) === 64 &&
    isValidHex($selector) &&
    isValidHex($tokenHex)
) {
    $checkStmt = $conn->prepare(
        'SELECT id, accID, token_hash, expires_at, used_at
           FROM tbl_password_resets
          WHERE selector = ?
          LIMIT 1'
    );
    if ($checkStmt) {
        $checkStmt->bind_param('s', $selector);
        $checkStmt->execute();
        $result   = $checkStmt->get_result();
        $resetRow = $result->fetch_assoc();
        $checkStmt->close();

        if ($resetRow) {
            $isExpired = strtotime($resetRow['expires_at']) < time();
            $isUsed    = !empty($resetRow['used_at']);
            $tokenBin  = hex2bin($tokenHex);

            if (!$isExpired && !$isUsed && $tokenBin !== false) {
                $incomingHash = hash('sha256', $tokenBin);
                if (hash_equals($resetRow['token_hash'], $incomingHash)) {
                    $validLink = true;
                }
            }
        }
    }
}

/* ?? handle POST ??????????????????????????????????????????????????????????? */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword     = $_POST['password']         ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (!$validLink) {
        $error = 'This reset link is invalid or has expired.';
    } else {
        $pwErrors = validatePassword($newPassword);

        if (count($pwErrors) > 0) {
            $error = 'Password must include: ' . implode(', ', $pwErrors) . '.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } else {
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $conn->begin_transaction();

            try {
                $updateUserStmt = $conn->prepare(
                    'UPDATE tbl_useracc SET password = ? WHERE accID = ? LIMIT 1'
                );
                if (!$updateUserStmt) throw new Exception('Failed to prepare user update.');
                $updateUserStmt->bind_param('ss', $passwordHash, $resetRow['accID']);
                if (!$updateUserStmt->execute()) throw new Exception('Failed to update password.');
                $updateUserStmt->close();

                $markUsedStmt = $conn->prepare(
                    'UPDATE tbl_password_resets SET used_at = NOW() WHERE id = ? LIMIT 1'
                );
                if (!$markUsedStmt) throw new Exception('Failed to prepare token update.');
                $markUsedStmt->bind_param('i', $resetRow['id']);
                if (!$markUsedStmt->execute()) throw new Exception('Failed to mark token as used.');
                $markUsedStmt->close();

                $cleanupStmt = $conn->prepare(
                    'DELETE FROM tbl_password_resets WHERE accID = ? AND id <> ?'
                );
                if ($cleanupStmt) {
                    $cleanupStmt->bind_param('si', $resetRow['accID'], $resetRow['id']);
                    $cleanupStmt->execute();
                    $cleanupStmt->close();
                }

                $conn->commit();
                $resetDone = true;   // show inline success state - no hard redirect

            } catch (Exception $e) {
                $conn->rollback();
                error_log('Password reset error: ' . $e->getMessage());
                $error = 'A server error occurred. Please try again later.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="assets/responsive-global.css">
  <title>Reset Password - SumEste Portal</title>
  <link rel="icon" href="assets/logo2.png" type="image/png">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'DM Sans', sans-serif; }
    h1, h2 { font-family: 'Playfair Display', serif; }

    .field-input {
      width: 100%; border: 1.5px solid #d1d5db; border-radius: 10px;
      padding: 10px 16px; font-size: .95rem;
      transition: border-color .2s, box-shadow .2s; outline: none; background: #fff;
    }
    .field-input:focus { border-color: #16a34a; box-shadow: 0 0 0 3px rgba(22,163,74,.12); }
    .field-input.error { border-color: #ef4444; box-shadow: 0 0 0 3px rgba(239,68,68,.1); }
    .field-input.valid { border-color: #16a34a; }

    .seg { flex:1; height:6px; border-radius:4px; background:#e5e7eb; transition:background .3s; }

    /* success checkmark animation */
    @keyframes scaleIn { from { transform: scale(0) rotate(-20deg); opacity:0; } to { transform: scale(1) rotate(0deg); opacity:1; } }
    @keyframes fadeUp  { from { opacity:0; transform:translateY(16px); } to { opacity:1; transform:translateY(0); } }
    .anim-scale  { animation: scaleIn .5s cubic-bezier(.34,1.56,.64,1) both; }
    .anim-fadeup { animation: fadeUp  .45s ease both; }
    .anim-delay-1 { animation-delay:.15s; }
    .anim-delay-2 { animation-delay:.30s; }
    .anim-delay-3 { animation-delay:.45s; }

    /* redirect countdown ring */
    @keyframes countdown { from { stroke-dashoffset: 0; } to { stroke-dashoffset: 113; } }
    #ring { stroke-dasharray: 113; stroke-dashoffset: 0; animation: countdown 5s linear forwards; }
  </style>
    <link rel="stylesheet" href="dist/output.css">
    <script src="https://cdn.tailwindcss.com/3.4.16"></script>
</head>
<body class="min-h-screen bg-green-50 flex items-center justify-center p-6">
<div class="w-full max-w-md bg-white rounded-2xl border border-green-100 shadow-lg overflow-hidden">
  <div class="h-1.5 bg-gradient-to-r from-green-700 to-emerald-400"></div>
  <div class="p-8">

    <p class="text-xs uppercase tracking-widest text-green-700 font-semibold mb-2">Account Recovery</p>
    <h1 class="text-2xl text-green-950 font-bold mb-2">Reset Password</h1>

    <?php if ($resetDone): /* ?????????? SUCCESS STATE ?????????? */ ?>

    <div class="text-center py-4">
      <!-- animated check -->
      <div class="relative inline-flex items-center justify-center mb-6">
        <!-- countdown ring -->
        <svg width="88" height="88" viewBox="0 0 40 40" class="absolute">
          <circle cx="20" cy="20" r="18" fill="none" stroke="#bbf7d0" stroke-width="2.5"/>
          <circle id="ring" cx="20" cy="20" r="18" fill="none"
                  stroke="#16a34a" stroke-width="2.5"
                  stroke-linecap="round"
                  transform="rotate(-90 20 20)"/>
        </svg>
        <div class="w-16 h-16 rounded-full bg-green-100 flex items-center justify-center anim-scale">
          <i class="fa-solid fa-check text-green-600 text-2xl"></i>
        </div>
      </div>

      <h2 class="text-xl font-bold text-green-900 mb-2 anim-fadeup anim-delay-1">Password Updated!</h2>
      <p class="text-sm text-gray-500 mb-6 anim-fadeup anim-delay-2">
        Your password has been changed successfully.<br>
        You'll be redirected to the login page in <span id="countdown-num" class="font-semibold text-green-700">5</span>s.
      </p>

      <a href="login.php"
         class="anim-fadeup anim-delay-3 inline-flex items-center gap-2 bg-green-700 hover:bg-green-800
                text-white text-sm font-semibold px-6 py-2.5 rounded-xl transition shadow-md shadow-green-200">
        <i class="fa-solid fa-right-to-bracket"></i> Go to Login Now
      </a>
    </div>

    <script>
      /* auto-redirect with live countdown */
      let n = 5;
      const num = document.getElementById('countdown-num');
      const t = setInterval(() => { n--; num.textContent = n; if (n <= 0) { clearInterval(t); location.href = 'login.php?reset=1'; } }, 1000);
    </script>

    <?php elseif (!$validLink): /* ?????????? INVALID LINK ?????????? */ ?>

    <?php if ($error): ?>
    <div class="mb-4 p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm flex items-center gap-2">
      <i class="fa-solid fa-circle-exclamation flex-shrink-0"></i>
      <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <?php endif; ?>

    <p class="text-sm text-gray-600 mb-6">This reset link is invalid or has expired. Please request a new one.</p>
    <a href="../forgot_password.php" class="inline-flex items-center gap-2 text-sm text-green-700 hover:underline font-medium">
      <i class="fa-solid fa-arrow-left"></i> Go to Forgot Password
    </a>

    <?php else: /* ?????????? RESET FORM ?????????? */ ?>

    <?php if ($error): ?>
    <div class="mb-4 p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm flex items-center gap-2">
      <i class="fa-solid fa-circle-exclamation flex-shrink-0"></i>
      <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <?php endif; ?>

    <p class="text-sm text-gray-500 mb-6">Enter your new password below.</p>

    <form id="resetForm" action="reset_password.php" method="post" novalidate class="space-y-5">
      <input type="hidden" name="selector" value="<?php echo htmlspecialchars($selector, ENT_QUOTES, 'UTF-8'); ?>">
      <input type="hidden" name="token"    value="<?php echo htmlspecialchars($tokenHex, ENT_QUOTES, 'UTF-8'); ?>">

      <!-- NEW PASSWORD -->
      <div>
        <label for="password" class="block text-xs font-semibold text-gray-600 mb-2 uppercase tracking-wide">
          New Password
        </label>
        <div class="relative">
          <input
            type="password" id="password" name="password"
            placeholder="Create a strong password"
            maxlength="72" autocomplete="new-password"
            class="field-input pr-12"
            aria-describedby="password-error password-hint" required>
          <button type="button"
                  class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-green-600 transition toggle-pw"
                  data-target="password" aria-label="Toggle password visibility">
            <i class="fa fa-eye"></i>
          </button>
        </div>

        <!-- strength bar -->
        <div class="flex gap-1.5 mt-2.5">
          <div class="seg" id="seg1"></div>
          <div class="seg" id="seg2"></div>
          <div class="seg" id="seg3"></div>
          <div class="seg" id="seg4"></div>
        </div>
        <p id="strength-label" class="text-xs mt-1 text-gray-400"></p>
        <p id="password-hint" class="text-gray-400 text-xs mt-1">
          Min 8 – max 72 characters - include uppercase, lowercase, and a number.
        </p>
        <p id="password-error" class="text-red-500 text-xs mt-1.5 hidden" role="alert"></p>
      </div>

      <!-- CONFIRM PASSWORD -->
      <div>
        <label for="confirm_password" class="block text-xs font-semibold text-gray-600 mb-2 uppercase tracking-wide">
          Confirm Password
        </label>
        <div class="relative">
          <input
            type="password" id="confirm_password" name="confirm_password"
            placeholder="Re-enter your password"
            maxlength="72" autocomplete="new-password"
            class="field-input pr-12"
            aria-describedby="confirm-error" required>
          <button type="button"
                  class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-green-600 transition toggle-pw"
                  data-target="confirm_password" aria-label="Toggle confirm password visibility">
            <i class="fa fa-eye"></i>
          </button>
        </div>
        <p id="confirm-error" class="text-red-500 text-xs mt-1.5 hidden" role="alert"></p>
      </div>

      <!-- submit -->
      <button type="submit" id="submitBtn"
              class="w-full py-3 rounded-xl bg-green-700 text-white font-semibold text-sm
                     hover:bg-green-800 transition flex items-center justify-center gap-2
                     disabled:opacity-50 disabled:cursor-not-allowed">
        <i class="fa-solid fa-key"></i> Update Password
      </button>
    </form>

    <?php endif; ?>

  </div><!-- /p-8 -->
</div><!-- /card -->

<script>
/* ?? UTILITIES ??????????????????????????????????????????????????????????? */
function validatePassword(pw) {
  const e = [];
  if (pw.length < 8)     e.push('at least 8 characters');
  if (pw.length > 72)    e.push('no more than 72 characters');
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

function showError(id, msg) { const el = document.getElementById(id); el.textContent = msg; el.classList.remove('hidden'); }
function clearError(id)     { const el = document.getElementById(id); el.textContent = ''; el.classList.add('hidden'); }
function setInputState(id, state) {
  const el = document.getElementById(id);
  el.classList.remove('error','valid');
  if (state) el.classList.add(state);
}

/* ?? STRENGTH METER ?????????????????????????????????????????????????????? */
const segEls      = [1,2,3,4].map(i => document.getElementById('seg'+i));
const segColors   = [
  ['bg-red-400',    'bg-red-400',    'bg-gray-200',  'bg-gray-200'],
  ['bg-orange-400', 'bg-orange-400', 'bg-gray-200',  'bg-gray-200'],
  ['bg-yellow-400', 'bg-yellow-400', 'bg-yellow-400','bg-gray-200'],
  ['bg-lime-500',   'bg-lime-500',   'bg-lime-500',  'bg-lime-500'],
];
const strengthLabels = ['Weak','Fair','Good','Strong'];
const strengthColors = ['text-red-500','text-orange-500','text-yellow-600','text-lime-600'];

const pwInput = document.getElementById('password');
if (pwInput) {
  pwInput.addEventListener('input', function () {
    const score  = passwordStrengthScore(this.value);
    const idx    = Math.max(0, score - 1);
    const colors = this.value.length ? segColors[idx] : Array(4).fill('bg-gray-200');
    segEls.forEach((s, i) => { s.className = 'seg ' + colors[i]; });
    const lbl = document.getElementById('strength-label');
    lbl.textContent = this.value.length ? strengthLabels[idx] : '';
    lbl.className   = 'text-xs mt-1 ' + strengthColors[idx];
  });

  /* blur validation */
  pwInput.addEventListener('blur', function () {
    if (!this.value) return;
    const errs = validatePassword(this.value);
    if (errs.length > 0) {
      showError('password-error', 'Must include: ' + errs.join(', ') + '.'); setInputState('password','error');
    } else {
      clearError('password-error'); setInputState('password','valid');
    }
  });
}

/* ?? TOGGLE VISIBILITY ??????????????????????????????????????????????????? */
document.querySelectorAll('.toggle-pw').forEach(btn => {
  btn.addEventListener('click', function () {
    const input = document.getElementById(this.dataset.target);
    const icon  = this.querySelector('i');
    input.type  = input.type === 'password' ? 'text' : 'password';
    icon.classList.toggle('fa-eye');
    icon.classList.toggle('fa-eye-slash');
  });
});

/* ?? LIVE CONFIRM MATCH ?????????????????????????????????????????????????? */
const confirmInput = document.getElementById('confirm_password');
if (confirmInput) {
  confirmInput.addEventListener('input', function () {
    const pw = document.getElementById('password').value;
    if (!this.value) { clearError('confirm-error'); return; }
    if (this.value !== pw) {
      showError('confirm-error','Passwords do not match.'); setInputState('confirm_password','error');
    } else {
      clearError('confirm-error'); setInputState('confirm_password','valid');
    }
  });
}

/* ?? FORM SUBMIT ????????????????????????????????????????????????????????? */
const resetForm = document.getElementById('resetForm');
if (resetForm) {
  resetForm.addEventListener('submit', function (e) {
    e.preventDefault();
    let valid = true;

    /* 1. New password */
    const pw   = document.getElementById('password').value;
    const errs = validatePassword(pw);
    if (!pw) {
      showError('password-error','Password is required.'); setInputState('password','error'); valid = false;
    } else if (errs.length > 0) {
      showError('password-error','Must include: ' + errs.join(', ') + '.'); setInputState('password','error'); valid = false;
    } else {
      clearError('password-error'); setInputState('password','valid');
    }

    /* 2. Confirm password */
    const cpw = document.getElementById('confirm_password').value;
    if (!cpw) {
      showError('confirm-error','Please confirm your password.'); setInputState('confirm_password','error'); valid = false;
    } else if (pw !== cpw) {
      showError('confirm-error','Passwords do not match.'); setInputState('confirm_password','error'); valid = false;
    } else {
      clearError('confirm-error'); setInputState('confirm_password','valid');
    }

    if (!valid) return;

    const btn = document.getElementById('submitBtn');
    btn.disabled     = true;
    btn.innerHTML    = '<i class="fa-solid fa-spinner fa-spin"></i> Updating.';
    this.submit();
  });
}
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
