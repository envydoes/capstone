<?php
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['account_role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../config/db_connection.php';
require_once __DIR__ . '/../includes/site_config.php';

$currentAdminAccId = $_SESSION['acc_id'] ?? '';
$message = '';
$messageType = '';

// Load settings BEFORE handling POST - the barangay name at this point
// is what the admin must type to confirm (matches what's shown on screen).
$siteSettings = site_config_load($conn);

/**
 * Runs a COUNT(*) query and returns the total as an int.
 * Returns 0 (rather than throwing) if the query fails, so a missing/renamed
 * table can't accidentally block a legitimate reset - worst case it just
 * won't be part of the "has data" check.
 */
function count_rows(mysqli $conn, string $sql): int
{
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        return 0;
    }
    $row = mysqli_fetch_assoc($result);
    return (int) ($row['total'] ?? 0);
}

/**
 * True if there is anything in the system that a reset would actually delete -
 * any non-admin account, or any row in the dependent tables. Used to stop a
 * reset from running (and destroying the admin's session) when there's
 * nothing to clear.
 */
function system_has_resettable_data(mysqli $conn, string $currentAdminAccId): bool
{
    $tableChecks = [
        "SELECT COUNT(*) AS total FROM tbl_beneficiary",
        "SELECT COUNT(*) AS total FROM tbl_equipmentrequest",
        "SELECT COUNT(*) AS total FROM tbl_requestdocs",
        "SELECT COUNT(*) AS total FROM tbl_busaptlisting",
        "SELECT COUNT(*) AS total FROM tbl_announcement",
        "SELECT COUNT(*) AS total FROM tbl_equipmentlist",
        "SELECT COUNT(*) AS total FROM tbl_hero_images",
    ];
    foreach ($tableChecks as $sql) {
        if (count_rows($conn, $sql) > 0) {
            return true;
        }
    }

    // Any account other than the current admin also counts as resettable data.
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM tbl_useracc WHERE accID != ?");
    mysqli_stmt_bind_param($stmt, 's', $currentAdminAccId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $otherAccounts = (int) (mysqli_fetch_assoc($result)['total'] ?? 0);
    mysqli_stmt_close($stmt);

    return $otherAccounts > 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_phrase'])) {
    $expected = $siteSettings['barangay_name'];

    if (trim($_POST['confirm_phrase']) !== $expected) {
        $message = 'Confirmation text did not match the barangay name. Nothing was deleted.';
        $messageType = 'error';
    } elseif (empty($currentAdminAccId)) {
        $message = 'Could not identify your admin account. Aborted for safety.';
        $messageType = 'error';
    } elseif (!system_has_resettable_data($conn, $currentAdminAccId)) {
        // Nothing to delete - do not touch the database, do not destroy the session.
        $message = 'Nothing to reset - the system has no residents, accounts, listings, requests, or other data yet.';
        $messageType = 'info';
    } else {
        mysqli_begin_transaction($conn);
        try {
            // 1. Delete dependents first (FK-safe order)
            mysqli_query($conn, "DELETE FROM tbl_beneficiary");
            mysqli_query($conn, "DELETE FROM tbl_equipmentrequest");
            mysqli_query($conn, "DELETE FROM tbl_requestdocs");
            mysqli_query($conn, "DELETE FROM tbl_busaptlisting");
            mysqli_query($conn, "DELETE FROM tbl_password_resets");

            // 2. Delete all user accounts except the current admin.
            //    tbl_userinfo cascades automatically (ON DELETE CASCADE on accID).
            $stmt = mysqli_prepare($conn, "DELETE FROM tbl_useracc WHERE accID != ?");
            mysqli_stmt_bind_param($stmt, 's', $currentAdminAccId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            // 3. Wipe barangay-specific content
            mysqli_query($conn, "DELETE FROM tbl_announcement");
            mysqli_query($conn, "DELETE FROM tbl_equipmentlist");
            mysqli_query($conn, "DELETE FROM tbl_hero_images");

            // 4. Reset settings to defaults (row stays, values reset)
            $stmt = mysqli_prepare($conn, "
                UPDATE tbl_settings SET
                    barangay_name = 'Sumacab Este',
                    municipality = 'Cabanatuan City',
                    contact_number = '',
                    email = '',
                    facebook_link = '',
                    our_reach_content = '',
                    puroks_covered = 0,
                    area_served = 0.00,
                    map_query = '',
                    site_title = 'SumEste Portal',
                    site_logo = NULL,
                    color_theme = '#15803d'
                WHERE id = 1
            ");
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            // 5. Reset counter table
            mysqli_query($conn, "UPDATE tbl_count SET count = 0");

            // 6. Reset AUTO_INCREMENT so the next barangay starts clean
            $resets = [
                'tbl_announcement'      => 'announcementID',
                'tbl_beneficiary'       => 'id',
                'tbl_busaptlisting'     => 'id',
                'tbl_equipmentlist'     => 'equipmentId',
                'tbl_equipmentrequest'  => 'id',
                'tbl_hero_images'       => 'id',
                'tbl_password_resets'   => 'id',
                'tbl_requestdocs'       => 'id',
                'tbl_userinfo'          => 'userID',
            ];
            foreach ($resets as $table => $col) {
                mysqli_query($conn, "ALTER TABLE `$table` AUTO_INCREMENT = 1");
            }

            mysqli_commit($conn);

            // 7. Delete uploaded files from disk (folder structure kept).
            //    Double-check these paths match your actual upload handlers.
            $uploadFolders = [
                __DIR__ . '/../uploads/hero/',
                __DIR__ . '/../uploads/site/',
                __DIR__ . '/../uploads/announcement/',
                __DIR__ . '/../uploads/id_verification/',
                __DIR__ . '/../uploads/busapt/',   // adjust to your actual listing-photo folder
                __DIR__ . '/../uploads/documents/', // adjust to your actual requestdocs folder
            ];
            foreach ($uploadFolders as $folder) {
                if (!is_dir($folder)) {
                    continue;
                }
                foreach (glob($folder . '*') as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }

            $message = 'System reset complete. All input data has been wiped; your admin login remains active.';
            $messageType = 'success';
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $message = 'Reset failed: ' . $e->getMessage();
            $messageType = 'error';
            $message = 'System reset complete. All data has been wiped. You will be redirected to login shortly.';
        }
    }

    // Reload settings so the confirmation phrase shown after a successful
    // reset reflects the freshly-reset default barangay name, not stale data.
    $siteSettings = site_config_load($conn);

    // Force re-authentication after a successful wipe - the admin's old
    // session shouldn't keep working against a freshly reset system.
    if ($messageType === 'success') {
        $_SESSION = [];
        session_destroy();
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>System Reset - <?= e($siteSettings['site_title']) ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'DM Sans', sans-serif; }
    code { font-family: ui-monospace, monospace; }
  </style>
    <link rel="stylesheet" href="dist/output.css">
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-6">
  <div class="max-w-lg w-full">

    <a href="../settings.php" class="inline-flex items-center gap-2 text-sm text-gray-500 hover:text-gray-700 mb-4 transition">
      <i class="fa-solid fa-arrow-left"></i> Back to Settings
    </a>

    <div class="bg-white border border-red-200 rounded-2xl shadow-lg p-8">
      <div class="flex items-center gap-3 mb-4">
        <div class="w-11 h-11 rounded-xl bg-red-50 flex items-center justify-center flex-shrink-0">
          <i class="fa-solid fa-triangle-exclamation text-red-500 text-lg"></i>
        </div>
        <h1 class="text-xl font-bold text-gray-900">System Reset</h1>
      </div>

      <p class="text-sm text-gray-500 mb-4 leading-relaxed">
        This permanently deletes all residents, accounts (except Admin), announcements, listings,
        document requests, equipment records, and hero images, then resets Site Settings to defaults.
        <strong class="text-gray-700">Table structures are not affected</strong> - this only clears the
        data, so the system is ready for a new barangay.
      </p>

      <?php if ($message): ?>
        <?php
          $bannerClass = match ($messageType) {
              'success' => 'bg-green-50 text-green-700 border border-green-200',
              'info'    => 'bg-blue-50 text-blue-700 border border-blue-200',
              default   => 'bg-red-50 text-red-700 border border-red-200',
          };
          $bannerIcon = match ($messageType) {
              'success' => 'fa-circle-check',
              'info'    => 'fa-circle-info',
              default   => 'fa-circle-xmark',
          };
        ?>
        <div class="mb-4 p-3 rounded-lg text-sm <?= $bannerClass ?>">
          <i class="fa-solid <?= $bannerIcon ?> mr-1"></i>
          <?= e($message) ?>
        </div>
      <?php endif; ?>

      <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 mb-5">
        <p class="text-xs text-gray-500 mb-1 font-semibold">This will permanently delete:</p>
        <ul class="text-xs text-gray-600 space-y-0.5 list-disc list-inside">
          <li>All resident &amp; non-resident accounts (except Admin)</li>
          <li>All announcements, listings, and document requests</li>
          <li>All equipment records and hero images</li>
          <li>Site Settings (reset to defaults)</li>
        </ul>
        <p class="text-xs text-gray-400 mt-2">If the system currently has no data, the reset button won't delete or change anything - you'll just see a confirmation that there was nothing to clear.</p>
      </div>

      <form method="POST" id="resetForm">
        <label class="block text-sm font-semibold text-gray-700 mb-2">
          To confirm, type <code class="bg-red-50 text-red-600 px-1.5 py-0.5 rounded font-semibold"><?= e($siteSettings['barangay_name']) ?></code> below:
        </label>
        <input
          type="text"
          name="confirm_phrase"
          id="confirmInput"
          required
          autocomplete="off"
          class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm mb-4 focus:outline-none focus:ring-2 focus:ring-red-400"
          placeholder="<?= e($siteSettings['barangay_name']) ?>">
        <button
          type="submit"
          id="resetBtn"
          disabled
          class="w-full bg-gray-300 text-white font-semibold py-2.5 rounded-lg text-sm transition cursor-not-allowed">
          <i class="fa-solid fa-trash mr-1"></i> I understand, reset this system
        </button>
      </form>
    </div>
  </div>

  <script>
    const expected = <?= json_encode($siteSettings['barangay_name']) ?>;
    const input = document.getElementById('confirmInput');
    const btn = document.getElementById('resetBtn');
    const form = document.getElementById('resetForm');

    input.addEventListener('input', () => {
      const match = input.value === expected;
      btn.disabled = !match;
      btn.classList.toggle('bg-gray-300', !match);
      btn.classList.toggle('cursor-not-allowed', !match);
      btn.classList.toggle('bg-red-600', match);
      btn.classList.toggle('hover:bg-red-700', match);
      btn.classList.toggle('cursor-pointer', match);
    });

    form.addEventListener('submit', (e) => {
      if (input.value !== expected) {
        e.preventDefault();
        return;
      }
      if (!confirm('This is your final warning - this action cannot be undone. Proceed?')) {
        e.preventDefault();
      }
    });
    <?php if ($messageType === 'success'): ?>
(function () {
  // Disable the form/inputs since the session is already gone server-side
  input.disabled = true;
  btn.disabled = true;
  btn.classList.add('bg-gray-300', 'cursor-not-allowed');
  btn.classList.remove('bg-red-600', 'hover:bg-red-700', 'cursor-pointer');

  let seconds = 3;
  const notice = document.createElement('div');
  notice.className = 'mt-4 p-3 rounded-lg text-sm bg-blue-50 text-blue-700 border border-blue-200 flex items-center gap-2';
  notice.innerHTML = `<i class="fa-solid fa-arrow-right-to-bracket"></i> <span id="redirectText">Redirecting you to login in ${seconds}...</span>`;
  form.parentNode.insertBefore(notice, form.nextSibling);

  const timer = setInterval(() => {
    seconds--;
    const el = document.getElementById('redirectText');
    if (seconds > 0) {
      el.textContent = `Redirecting you to login in ${seconds}...`;
    } else {
      clearInterval(timer);
      el.textContent = 'Redirecting...';
      window.location.href = '../login.php';
    }
  }, 1000);
})();
<?php endif; ?>
  </script>
</body>

</html>
