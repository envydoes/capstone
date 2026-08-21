<?php
/**
 * migrate_normalize_data.php
 * ------------------------------------------------------------
 * ONE-TIME MIGRATION — cleans up data that was already in tbl_userinfo
 * before the phone/name/address normalization rules existed, so old
 * records match the same 09XXXXXXXXX / Title Case / deduped-address
 * shape that new signups now get automatically (see
 * includes/normalize_helpers.php, residentProfile.php,
 * nonresidentProfile.php, session_data.php).
 *
 * SAFE BY DESIGN:
 *   - Read-only "preview" mode by default. It computes what WOULD
 *     change and shows you a diff table — nothing is written to the
 *     database until you explicitly click "Apply Changes".
 *   - Applying is a POST request gated by a CSRF token, and only runs
 *     inside a single transaction (all rows or none).
 *   - Idempotent — running it again after applying finds nothing left
 *     to change, since already-normalized values re-normalize to
 *     themselves. Safe to re-run any time.
 *   - Admin-only. Requires an active admin session.
 *
 * USAGE:
 *   1. Place this file at your project root (same level as config/
 *      and includes/), e.g. C:\xampp\htdocs\capstone\migrate_normalize_data.php
 *   2. Log in as admin in one browser tab.
 *   3. Visit http://localhost/capstone/migrate_normalize_data.php in
 *      another tab.
 *   4. Review the preview table, then click "Apply Changes" if it
 *      looks right.
 *   5. Delete this file once you're done — it's a one-time tool, not
 *      something that should stay reachable on a live site.
 * ------------------------------------------------------------
 */

session_start();
require_once __DIR__ . '/config/db_connection.php';
require_once __DIR__ . '/includes/normalize_helpers.php';

function e(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ── Admin-only gate ─────────────────────────────────────────────────
$role = $_SESSION['account_role'] ?? '';
if (!isset($_SESSION['user_id']) || $role !== 'admin') {
    http_response_code(403);
    die('Admin login required to run this migration.');
}

// ── CSRF token ───────────────────────────────────────────────────────
if (empty($_SESSION['migrate_csrf'])) {
    $_SESSION['migrate_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['migrate_csrf'];

/**
 * Computes the normalized version of every relevant field for one
 * tbl_userinfo row, and returns only the fields that actually differ
 * from what's currently stored.
 */
function computeChanges(array $row): array {
    $changes = [];

    $checks = [
        'firstname'         => normalize_person_name((string) $row['firstname']),
        'lastname'          => normalize_person_name((string) $row['lastname']),
        'middlename'        => normalize_person_name((string) $row['middlename']),
        'suffix'            => normalize_name_suffix((string) $row['suffix']),
        'emergency_contact' => normalize_person_name((string) $row['emergency_contact']),
        'phone'             => normalize_ph_phone((string) $row['phone']),
        'emergency_phone'   => normalize_ph_phone((string) $row['emergency_phone']),
        'street'            => normalize_street_address(
                                    (string) $row['street'],
                                    [$row['barangay'], $row['city'], $row['province']]
                                ),
    ];

    foreach ($checks as $field => $newValue) {
        $oldValue = (string) ($row[$field] ?? '');
        if ($oldValue !== $newValue && $newValue !== '') {
            $changes[$field] = ['old' => $oldValue, 'new' => $newValue];
        }
    }

    return $changes;
}

// ── APPLY (POST) ─────────────────────────────────────────────────────
$applyResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrfToken, $submittedToken)) {
        http_response_code(403);
        die('Invalid CSRF token. Please reload and try again.');
    }

    $rows = [];
    $res = mysqli_query($conn, "SELECT accID, firstname, lastname, middlename, suffix,
                                        emergency_contact, phone, emergency_phone,
                                        street, barangay, city, province
                                 FROM tbl_userinfo");
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
        mysqli_free_result($res);
    }

    $updatedCount = 0;
    $fieldTouchCount = 0;
    $log = [];

    mysqli_begin_transaction($conn);
    try {
        $stmt = $conn->prepare(
            "UPDATE tbl_userinfo SET
                firstname = ?, lastname = ?, middlename = ?, suffix = ?,
                emergency_contact = ?, phone = ?, emergency_phone = ?, street = ?
             WHERE accID = ?"
        );
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }

        foreach ($rows as $row) {
            $changes = computeChanges($row);
            if (empty($changes)) continue;

            $firstname        = $changes['firstname']['new']         ?? $row['firstname'];
            $lastname         = $changes['lastname']['new']          ?? $row['lastname'];
            $middlename       = $changes['middlename']['new']        ?? $row['middlename'];
            $suffix           = $changes['suffix']['new']            ?? $row['suffix'];
            $emergencyContact = $changes['emergency_contact']['new'] ?? $row['emergency_contact'];
            $phone            = $changes['phone']['new']             ?? $row['phone'];
            $emergencyPhone   = $changes['emergency_phone']['new']   ?? $row['emergency_phone'];
            $street           = $changes['street']['new']            ?? $row['street'];
            $accID            = $row['accID'];

            $stmt->bind_param(
                'sssssssss',
                $firstname, $lastname, $middlename, $suffix,
                $emergencyContact, $phone, $emergencyPhone, $street,
                $accID
            );
            $stmt->execute();

            $updatedCount++;
            $fieldTouchCount += count($changes);
            $log[] = ['accID' => $accID, 'changes' => $changes];
        }
        $stmt->close();

        mysqli_commit($conn);

        // Write an audit log file so there's a record of exactly what
        // changed and when, in case anything needs to be reviewed later.
        $logDir = __DIR__ . '/migration_logs';
        if (!is_dir($logDir)) mkdir($logDir, 0775, true);
        $logFile = $logDir . '/normalize_' . date('Ymd_His') . '.json';
        file_put_contents($logFile, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $applyResult = [
            'success'   => true,
            'rows'      => $updatedCount,
            'fields'    => $fieldTouchCount,
            'log_file'  => basename($logFile),
        ];
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        $applyResult = ['success' => false, 'error' => $e->getMessage()];
    }

    // Rotate CSRF token after use.
    $_SESSION['migrate_csrf'] = bin2hex(random_bytes(32));
    $csrfToken = $_SESSION['migrate_csrf'];
}

// ── PREVIEW (always computed, read-only) ─────────────────────────────
$previewRows = [];
$res = mysqli_query($conn, "SELECT accID, firstname, lastname, middlename, suffix,
                                    emergency_contact, phone, emergency_phone,
                                    street, barangay, city, province
                             FROM tbl_userinfo");
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $changes = computeChanges($r);
        if (!empty($changes)) {
            $previewRows[] = ['accID' => $r['accID'], 'changes' => $changes];
        }
    }
    mysqli_free_result($res);
}
$totalAffectedRows = count($previewRows);
$totalAffectedFields = array_sum(array_map(fn($r) => count($r['changes']), $previewRows));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Normalize Existing Data — Migration</title>
<style>
  body { font-family: 'Segoe UI', sans-serif; background: #f0fdf4; margin: 0; padding: 30px; color: #1f2937; }
  .wrap { max-width: 1100px; margin: 0 auto; }
  h1 { color: #14532d; font-size: 1.4rem; }
  .banner { background: #fff7ed; border: 1.5px solid #fdba74; border-radius: 10px; padding: 14px 18px; margin-bottom: 20px; font-size: 0.88rem; color: #9a3412; }
  .banner.success { background: #f0fdf4; border-color: #86efac; color: #166534; }
  .banner.error { background: #fef2f2; border-color: #fca5a5; color: #991b1b; }
  .summary { background: #fff; border: 1.5px solid #dcfce7; border-radius: 12px; padding: 18px 22px; margin-bottom: 20px; }
  .summary b { color: #15803d; font-size: 1.3rem; }
  table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 6px rgba(0,0,0,0.05); margin-bottom: 24px; }
  th, td { padding: 8px 12px; font-size: 0.82rem; text-align: left; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
  th { background: #15803d; color: #fff; text-transform: uppercase; font-size: 0.68rem; letter-spacing: 0.04em; }
  tr:nth-child(even) { background: #fafffb; }
  .old { color: #dc2626; text-decoration: line-through; }
  .new { color: #15803d; font-weight: 600; }
  .arrow { color: #9ca3af; margin: 0 6px; }
  .field-tag { display: inline-block; background: #dcfce7; color: #14532d; font-size: 0.65rem; font-weight: 700; padding: 1px 7px; border-radius: 999px; margin-bottom: 3px; text-transform: uppercase; }
  .apply-btn { display: inline-block; background: #15803d; color: #fff; border: none; padding: 12px 26px; border-radius: 9px; font-weight: 700; font-size: 0.9rem; cursor: pointer; }
  .apply-btn:hover { background: #166534; }
  .no-changes { padding: 40px; text-align: center; color: #6b7280; background: #fff; border-radius: 12px; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Normalize Existing Resident/Non-Resident Data</h1>

  <div class="banner">
    ⚠️ One-time migration tool. This page is <strong>not linked from anywhere in the app</strong> —
    delete this file once you've applied the changes you need.
  </div>

  <?php if ($applyResult): ?>
    <?php if ($applyResult['success']): ?>
      <div class="banner success">
        ✅ Applied successfully — <b><?= (int) $applyResult['rows'] ?></b> record(s) updated,
        <b><?= (int) $applyResult['fields'] ?></b> field(s) changed in total.
        Audit log saved to <code>migration_logs/<?= e($applyResult['log_file']) ?></code>.
      </div>
    <?php else: ?>
      <div class="banner error">❌ Migration failed and was rolled back: <?= e($applyResult['error']) ?></div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="summary">
    <p style="margin:0 0 6px;">Records that still need normalizing right now:</p>
    <p style="margin:0;"><b><?= $totalAffectedRows ?></b> record(s), <b><?= $totalAffectedFields ?></b> field(s) total.</p>
  </div>

  <?php if (empty($previewRows)): ?>
    <div class="no-changes">
      ✅ Nothing to normalize — every record already matches the expected format.
    </div>
  <?php else: ?>
    <table>
      <thead>
        <tr><th style="width:90px;">Acc ID</th><th>Field</th><th>Change</th></tr>
      </thead>
      <tbody>
        <?php foreach ($previewRows as $pr): ?>
          <?php foreach ($pr['changes'] as $field => $vals): ?>
            <tr>
              <td><?= e($pr['accID']) ?></td>
              <td><span class="field-tag"><?= e($field) ?></span></td>
              <td>
                <span class="old"><?= e($vals['old'] !== '' ? $vals['old'] : '(empty)') ?></span>
                <span class="arrow">→</span>
                <span class="new"><?= e($vals['new']) ?></span>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </tbody>
    </table>

    <form method="POST" onsubmit="return confirm('Apply these <?= $totalAffectedFields ?> field changes across <?= $totalAffectedRows ?> record(s)? This cannot be undone automatically (though an audit log is saved).');">
      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
      <input type="hidden" name="action" value="apply">
      <button type="submit" class="apply-btn">Apply Changes to <?= $totalAffectedRows ?> Record(s)</button>
    </form>
  <?php endif; ?>

</div>
</body>
</html>