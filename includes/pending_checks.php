<?php
/**
 * includes/pending_checks.php
 * ------------------------------------------------------------
 * Shared guard used before archiving a resident or disabling any
 * account (resident or non-resident) - blocks the action if the
 * person has anything still pending across:
 *   - tbl_requestdocs      (document requests)
 *   - tbl_beneficiary      (beneficiary application)
 *   - tbl_equipmentrequest (equipment borrowing)
 *
 * Usage:
 *   require_once __DIR__ . '/../includes/pending_checks.php';
 *   $blockers = get_pending_blockers($conn, $userID);
 *   if (!empty($blockers)) {
 *       // don't proceed - $blockers is a list of human-readable
 *       // strings like "1 pending document request"
 *   }
 * ------------------------------------------------------------
 */

if (!function_exists('get_pending_blockers')) {
    function get_pending_blockers(mysqli $conn, int $userID): array {
        $blockers = [];

        // Table name + user-facing label for each thing we check. Table
        // names here are fixed/hardcoded (never derived from request
        // input), so string-building the query with them is safe -
        // mysqli placeholders can't parameterize identifiers anyway.
        $checks = [
            ['tbl_requestdocs',      'pending document request'],
            ['tbl_beneficiary',      'pending beneficiary application'],
            ['tbl_equipmentrequest', 'pending equipment borrowing'],
        ];

        foreach ($checks as [$table, $label]) {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM `{$table}` WHERE userId = ? AND LOWER(status) = 'pending'");
            if (!$stmt) {
                continue; // if the check itself fails, don't block on it - just skip
            }
            $stmt->bind_param('i', $userID);
            $stmt->execute();
            $stmt->bind_result($count);
            $stmt->fetch();
            $stmt->close();

            if ($count > 0) {
                $blockers[] = $count . ' ' . $label . ($count > 1 ? 's' : '');
            }
        }

        return $blockers;
    }
}
