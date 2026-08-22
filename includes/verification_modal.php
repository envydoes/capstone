<?php
/**
 * includes/verification_modal.php
 * ------------------------------------------------------------
 * Annual "are you still active?" verification modal.
 *
 * HOW TO USE ON ANY PAGE:
 *   Just require this file AFTER $conn (mysqli) and $accId
 *   (the resident's tbl_userinfo.accID, usually from
 *   $_SESSION['acc_id']) are already defined - anywhere in the
 *   <body>, before </body> is fine:
 *
 *     require_once __DIR__ . '/../includes/verification_modal.php';
 *
 *   By default the "No, update my info" button links to
 *   residentEditProfile.php and the "Yes" form posts to
 *   verifyAccount.php, both resolved relative to the CURRENT page
 *   (not this include) - i.e. they need to exist in the same folder
 *   as whatever page required this file. If a page lives somewhere
 *   else (e.g. the non-resident side), set these two variables
 *   BEFORE requiring this file to point at the right files:
 *
 *     $verifyEditProfileUrl = 'nonresidentEditProfile.php';
 *     $verifyActionUrl      = 'verifyAccount.php'; // its own copy in that folder
 *     require_once __DIR__ . '/../includes/verification_modal.php';
 *
 *   If the resident's tbl_userinfo.last_verified_at is more than a
 *   year old (or was never set - falls back to dateRegistered), this
 *   prints a full-screen modal that:
 *     - Has NO close button, no click-outside-to-close, no Escape
 *       key handling. The only ways out are the two buttons below.
 *     - "Yes, still active"  -> POSTs to verifyAccount.php, which
 *       stamps last_verified_at = NOW() and redirects back here.
 *     - "No, update my info" -> sends them to residentEditProfile.php;
 *       last_verified_at gets stamped there instead, on successful save.
 *     - A MutationObserver puts the modal back if it's removed from
 *       the DOM (e.g. via "Delete element" in devtools). This is a
 *       best-effort deterrent against casual dismissal, NOT real
 *       security - see the note at the bottom of this file.
 *
 * REQUIRES the following column on tbl_userinfo (run once):
 *   ALTER TABLE tbl_userinfo
 *     ADD COLUMN last_verified_at DATETIME NULL DEFAULT NULL;
 * ------------------------------------------------------------
 */

if (!isset($conn, $accId) || !$accId) {
    return; // nothing to check without a logged-in resident + DB connection
}

$needsVerification = false;

$stmt = $conn->prepare('SELECT last_verified_at, dateRegistered FROM tbl_userinfo WHERE accID = ? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('s', $accId);
    $stmt->execute();
    $verifyRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($verifyRow) {
        // last_verified_at hasn't been set yet on older accounts, so fall
        // back to dateRegistered as the baseline for "a year has passed".
        $baseline = $verifyRow['last_verified_at'] ?: $verifyRow['dateRegistered'];

        if (!$baseline) {
            $needsVerification = true; // no dates at all on record - ask anyway
        } else {
            $baselineTs = strtotime($baseline);
            $needsVerification = $baselineTs !== false && $baselineTs <= strtotime('-1 year');
        }
    }
}

if ($needsVerification):
    // Where verifyAccount.php should send the resident back to once
    // they confirm - whatever page included this modal.
    $verifyRedirectTo = basename($_SERVER['SCRIPT_NAME'] ?? 'residentLanding.php');

    // Allow the including page to point these at its own folder's
    // copies (see the docblock above). Fall back to the resident-side
    // defaults for backward compatibility.
    $verifyEditProfileUrl = $verifyEditProfileUrl ?? 'residentEditProfile.php';
    $verifyActionUrl      = $verifyActionUrl      ?? 'verifyAccount.php';
?>
<div id="verifyModalOverlay" class="verify-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="verifyModalTitle">
  <div class="verify-modal-box">
    <div class="verify-modal-icon">
      <i class="fa-solid fa-user-check"></i>
    </div>
    <h2 id="verifyModalTitle" class="verify-modal-title">Just checking in</h2>
    <p class="verify-modal-text">
      It's been a while since we last confirmed your account details.
      Are you still using this account?
    </p>

    <div class="verify-modal-actions">
      <form id="verifyYesForm" method="POST" action="<?= htmlspecialchars($verifyActionUrl, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($verifyRedirectTo, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="verify-btn verify-btn-yes">
          <i class="fa-solid fa-check"></i> Yes, still active
        </button>
      </form>
      <a href="<?= htmlspecialchars($verifyEditProfileUrl, ENT_QUOTES, 'UTF-8') ?>" class="verify-btn verify-btn-no">
        <i class="fa-solid fa-pen"></i> No, update my info
      </a>
    </div>

    <p class="verify-modal-footnote">
      Please confirm before continuing - this only takes a second.
    </p>
  </div>
</div>

<style>
  .verify-modal-overlay {
    position: fixed; inset: 0; z-index: 999999;
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(6px);
    display: flex; align-items: center; justify-content: center;
    padding: 20px;
  }
  .verify-modal-box {
    background: #fff; width: 100%; max-width: 420px;
    border-radius: 20px; padding: 32px 28px 26px;
    text-align: center;
    box-shadow: 0 24px 60px rgba(0,0,0,0.3);
    font-family: 'DM Sans', sans-serif;
    animation: verifyModalPop 0.25s ease;
  }
  @keyframes verifyModalPop {
    from { opacity: 0; transform: scale(0.94) translateY(8px); }
    to   { opacity: 1; transform: scale(1) translateY(0); }
  }
  .verify-modal-icon {
    width: 56px; height: 56px; border-radius: 50%;
    background: var(--site-primary-pale, #dcfce7);
    color: var(--site-primary-dark, #15803d);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.4rem; margin: 0 auto 16px;
  }
  .verify-modal-title {
    font-family: 'Playfair Display', serif;
    font-size: 1.35rem; font-weight: 800;
    color: #111827; margin: 0 0 10px;
  }
  .verify-modal-text {
    font-size: 0.92rem; color: #4b5563; line-height: 1.6; margin: 0 0 24px;
  }
  .verify-modal-actions {
    display: flex; flex-direction: column; gap: 10px;
  }
  .verify-btn {
    display: flex; align-items: center; justify-content: center; gap: 8px;
    width: 100%; padding: 13px 20px;
    border: none; border-radius: 12px;
    font-size: 0.9rem; font-weight: 700;
    cursor: pointer; text-decoration: none;
    transition: transform 0.15s, box-shadow 0.15s, opacity 0.15s;
  }
  .verify-btn:active { transform: translateY(1px); }
  .verify-btn-yes {
    background: var(--site-primary, #16a34a); color: #fff;
    box-shadow: 0 4px 14px rgba(22,163,74,0.3);
  }
  .verify-btn-yes:hover { opacity: 0.92; }
  .verify-btn-no {
    background: #f3f4f6; color: #374151;
  }
  .verify-btn-no:hover { background: #e5e7eb; }
  .verify-modal-footnote {
    font-size: 0.72rem; color: #9ca3af; margin: 18px 0 0;
  }
  body.verify-modal-lock { overflow: hidden !important; }
</style>

<script>
(function () {
  var overlay = document.getElementById('verifyModalOverlay');
  if (!overlay) return;

  document.body.classList.add('verify-modal-lock');

  // No Escape-key dismissal, no click-outside dismissal - intentionally
  // not wired up. The only exits are the two buttons/forms above.

  function unlockAndRemove() {
    document.body.classList.remove('verify-modal-lock');
    observer.disconnect();
    overlay.remove();
  }

  // "Yes, still active" - try AJAX for a smooth no-reload experience;
  // fall back to a normal form submit (works even with fetch blocked).
  var yesForm = document.getElementById('verifyYesForm');
  if (yesForm) {
    yesForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = yesForm.querySelector('button[type="submit"]');
      btn.disabled = true;
      btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';

      fetch(yesForm.action, { method: 'POST', body: new FormData(yesForm) })
        .then(function (res) {
          if (!res.ok) throw new Error('verify request failed');
          unlockAndRemove();
        })
        .catch(function () {
          yesForm.submit(); // fallback: real page reload via verifyAccount.php
        });
    });
  }

  // Best-effort deterrent: if someone deletes the modal via devtools
  // ("Inspect" -> delete element), put it right back. This does NOT
  // stop a determined user with JS disabled entirely or the browser
  // console open - there is no such thing as truly un-removable
  // client-side HTML. Treat this as a nag, not a security boundary.
  var parent = overlay.parentNode;
  var nextSibling = overlay.nextSibling;
  var observer = new MutationObserver(function () {
    if (!document.body.contains(overlay)) {
      if (nextSibling && nextSibling.parentNode === parent) {
        parent.insertBefore(overlay, nextSibling);
      } else {
        parent.appendChild(overlay);
      }
      document.body.classList.add('verify-modal-lock');
    }
  });
  observer.observe(document.body, { childList: true, subtree: false });
})();
</script>
<?php endif; ?>
