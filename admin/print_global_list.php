<?php
/**
 * print_global_list.php
 * ------------------------------------------------------------
 * Unified printable / "Save as PDF" report page for admin lists:
 *   ?list=global   (default) - Global Resident List filtered results
 *   ?list=outreach            - Residents NOT Yet Registered as Beneficiaries
 *   ?list=owners              - Owner Directory (Business & Apartment listings)
 *   ?list=borrowed            - Currently Borrowed / Overdue Items
 *   ?list=analytics (POST)    - "Print Report" chart/graph picker from adminDashboard.php
 *
 * Paper size is formatted to Legal via @page CSS in print_report_layout.php.
 * ------------------------------------------------------------
 */

session_start();

// Explicit local timezone — without this, date()/strtotime() below fall back
// to the server's default (often UTC), which is why "Generated On ... at
// [TIME]" could show a time several hours off from local Philippine time.
date_default_timezone_set('Asia/Manila');

// 1. Authentication Check
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// 2. Load Core Dependencies
require_once __DIR__ . '/../config/db_connection.php';
require_once __DIR__ . '/../includes/check_permissions.php';
require_once __DIR__ . '/../includes/site_config.php';
require_once __DIR__ . '/../includes/global_list_query.php';
require_once __DIR__ . '/../includes/report_queries.php';
require_once __DIR__ . '/../includes/analytics_report_items.php';
require_once __DIR__ . '/../includes/print_report_layout.php';

// Helper function to safely escape strings for HTML output if not globally defined
if (!function_exists('e')) {
    function e($string): string {
        return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
    }
}

// 3. Route Setup (decide BEFORE the permission check, since each report
//    type is gated by a different module)
$list = isset($_GET['list']) ? trim((string) $_GET['list']) : (isset($_POST['list']) ? trim((string) $_POST['list']) : 'global');
if (!in_array($list, ['global', 'outreach', 'owners', 'borrowed', 'analytics'], true)) {
    $list = 'global';
}

$moduleForList = [
    'global'    => 'manage_residents',
    'outreach'  => 'manage_beneficiaries',
    'owners'    => 'manage_listings',
    'borrowed'  => 'manage_borrowing',
    'analytics' => 'dashboard',
];
$neededModule = $moduleForList[$list];

// 4. Role / Permission Check
//    Founder admin always passes (has_permission() short-circuits true
//    for account_role === 'admin'). Staff need the specific module for
//    the report they're trying to print. Anyone else - resident,
//    non-resident, or a staff account without that module - never falls
//    through to the public landing.php; they're sent back into the
//    admin panel (or the public site only if they're not admin-panel
//    users at all).
$role = $_SESSION['account_role'] ?? '';

if (!has_permission($conn, $neededModule)) {
    $normalizedRole = strtolower(trim($role));
    if ($normalizedRole === 'staff' || $normalizedRole === 'admin') {
        header('Location: adminLanding.php');
    } else {
        header('Location: ../landing.php');
    }
    exit;
}

// 5. System Configuration
$siteSettings = site_config_load($conn);

// 5b. ANALYTICS REPORT (Print Report ? chart/graph picker from adminDashboard.php)
//     Has no tabular "#, Name, columns" shape at all, so it's handled as its
//     own render path and exits before the list/table logic below ever runs.
if ($list === 'analytics') {
    $payload = [];
    $rawPost = isset($_POST['payload']) ? (string) $_POST['payload'] : '';
    if ($rawPost !== '') {
        $decoded = json_decode($rawPost, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }

    $selected = isset($payload['selected']) && is_array($payload['selected']) ? $payload['selected'] : [];
    $charts   = isset($payload['charts'])   && is_array($payload['charts'])   ? $payload['charts']   : [];
    $bars     = isset($payload['bars'])     && is_array($payload['bars'])     ? $payload['bars']     : [];
    $tables   = isset($payload['tables'])   && is_array($payload['tables'])   ? $payload['tables']   : [];

    // Enforce module access on the selection itself - a hand-crafted POST
    // payload can't smuggle in a chart from a module this account doesn't
    // have, even though the picker UI already disables those checkboxes.
    $myPermissions   = get_my_permissions($conn);
    $arGroupModuleMap = [
        'Resident Management'    => 'manage_residents',
        'Beneficiary Management' => 'manage_beneficiaries',
        'Business / Apartment'   => 'manage_listings',
        'Equipment'              => 'manage_borrowing',
        'Document Requests'      => 'manage_documents',
    ];
    $selected = array_values(array_filter($selected, function ($key) use ($ANALYTICS_REPORT_ITEMS, $arGroupModuleMap, $myPermissions, $role) {
        $group = $ANALYTICS_REPORT_ITEMS[$key]['group'] ?? null;
        if ($group === null) return false;
        if ($group === 'User / Accounts') return $role === 'admin';
        $requiredModule = $arGroupModuleMap[$group] ?? null;
        return $requiredModule === null || in_array($requiredModule, $myPermissions, true);
    }));

    // Keep only keys we actually recognize, in OUR canonical order (not
    // whatever order the client happened to submit), grouped by section.
    $orderedSelected = [];
    foreach ($ANALYTICS_REPORT_ITEMS as $key => $meta) {
        if (in_array($key, $selected, true)) {
            $orderedSelected[$key] = $meta;
        }
    }

    print_report_start($siteSettings, 'Analytics Report', [
        'Generated On: ' . date('F d, Y') . ' at ' . date('g:i A'),
    ]);

    echo '<style>.analytics-data-table{margin-top:10px;font-size:0.85em;}.analytics-data-table th,.analytics-data-table td{padding:6px 10px;}.analytics-item-count{font-size:0.8rem;color:#374151;margin:0 0 10px;}.analytics-item-count strong{color:#1a2e1a;}</style>';

    if (empty($orderedSelected)) {
        echo '<p class="conditions-empty">No charts or graphs were selected.</p>';
    } else {
        $currentGroup = null;
        foreach ($orderedSelected as $key => $meta) {
            if ($meta['group'] !== $currentGroup) {
                $currentGroup = $meta['group'];
                echo '<p class="analytics-section-title">' . e($currentGroup) . '</p>';
            }

            echo '<div class="analytics-item">';
            echo '<p class="analytics-item-title">' . e($meta['title']) . '</p>';
            echo '<p class="analytics-item-summary">' . e($meta['summary']) . '</p>';

            if ($meta['type'] === 'roster') {
                $fn = $ANALYTICS_ROSTER_QUERIES[$key] ?? null;
                if ($fn && function_exists($fn)) {
                    $roster = $fn($conn);
                    echo '<p class="analytics-item-count">Count: <strong>' . number_format($roster['count']) . '</strong></p>';

                    if (empty($roster['rows'])) {
                        echo '<p class="analytics-item-unavailable">No records found.</p>';
                    } else {
                        echo '<table class="report-table analytics-data-table">';
                        echo '<thead><tr>';
                        echo '<th style="width: 32px; text-align: center;">#</th>';
                        echo '<th>Name</th><th>Age</th><th>Birthdate</th><th>Contact Number</th><th>Address</th><th>Role</th>';
                        echo '</tr></thead><tbody>';
                        foreach ($roster['rows'] as $i => $row) {
                            echo '<tr>';
                            echo '<td style="text-align: center;">' . ($i + 1) . '</td>';
                            echo '<td>' . e($row['name']) . '</td>';
                            echo '<td>' . e($row['age']) . '</td>';
                            echo '<td>' . e($row['birthdate']) . '</td>';
                            echo '<td>' . e($row['contact_number']) . '</td>';
                            echo '<td>' . e($row['address']) . '</td>';
                            echo '<td>' . e($row['role']) . '</td>';
                            echo '</tr>';
                        }
                        echo '</tbody></table>';
                    }
                } else {
                    echo '<p class="analytics-item-unavailable">Roster data unavailable.</p>';
                }
            } elseif ($meta['type'] === 'image') {
                $dataUri = $charts[$key] ?? '';
                if ($dataUri !== '' && strpos($dataUri, 'data:image') === 0) {
                    echo '<img class="analytics-item-image" src="' . e($dataUri) . '" alt="' . e($meta['title']) . '">';
                } else {
                    echo '<p class="analytics-item-unavailable">Chart image unavailable - try re-generating the report.</p>';
                }

                // Data table version — always included alongside the chart
                // image (or in its place if the image capture failed), so
                // the printed report always has the exact figures on paper.
                $tableData = $tables[$key] ?? null;
                if ($tableData && !empty($tableData['rows'])) {
                    echo '<table class="report-table analytics-data-table">';
                    echo '<thead><tr>';
                    foreach ($tableData['headers'] as $h) {
                        echo '<th>' . e($h) . '</th>';
                    }
                    echo '</tr></thead><tbody>';
                    foreach ($tableData['rows'] as $row) {
                        echo '<tr>';
                        foreach ($row as $cell) {
                            echo '<td>' . e($cell) . '</td>';
                        }
                        echo '</tr>';
                    }
                    echo '</tbody></table>';
                }
            } else { // 'bars'
                $rows = $bars[$key] ?? [];
                if (empty($rows)) {
                    echo '<p class="analytics-item-unavailable">No data available.</p>';
                } else {
                    foreach ($rows as $row) {
                        $label     = e($row['label'] ?? '');
                        $value     = e($row['value'] ?? ($row['pct'] ?? ''));
                        $pct       = e($row['pct'] ?? '0%');
                        $color     = isset($row['color']) ? e($row['color']) : null;
                        $fillStyle = $color ? "width:{$pct};background:{$color};" : "width:{$pct};";
                        echo '<div class="print-bar-row">';
                        echo '<div class="print-bar-label">' . $label . '</div>';
                        echo '<div class="print-bar-track"><div class="print-bar-fill" style="' . $fillStyle . '"></div></div>';
                        echo '<div class="print-bar-value">' . $value . '</div>';
                        echo '</div>';
                    }

                    // Data table version of the same bars, for a plain
                    // figures-only view alongside the visual bars above.
                    echo '<table class="report-table analytics-data-table">';
                    echo '<thead><tr><th>Category</th><th>Value</th><th>Share</th></tr></thead><tbody>';
                    foreach ($rows as $row) {
                        echo '<tr>';
                        echo '<td>' . e($row['label'] ?? '—') . '</td>';
                        echo '<td>' . e($row['value'] ?? '—') . '</td>';
                        echo '<td>' . e($row['pct'] ?? '—') . '</td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table>';
                }
            }

            echo '</div>';
        }
    }

    print_report_signature($conn);
    print_report_end($siteSettings);
    exit;
}

$nameLabel      = 'Name';
$summaryLabel   = 'Total residents';

// 6. Fetch Report Data Based on ?list= Route
switch ($list) {
    case 'outreach':
        $result       = gf_run_nonbeneficiaries_query($conn, $_GET);
        $title        = 'Outreach List - Residents Not Yet Registered as Beneficiaries';
        $summaryLabel = 'Total residents';
        break;

    case 'owners':
        $result       = gf_run_owners_query($conn, $_GET);
        $title        = 'Owner Directory - Business & Apartment Listings';
        $nameLabel    = 'Owner';
        $summaryLabel = 'Total owners';
        break;

    case 'borrowed':
        $result       = gf_run_borrowed_query($conn, $_GET);
        $title        = 'Borrowers List - Currently Borrowed / Overdue Items';
        $nameLabel    = 'Item';
        $summaryLabel = 'Total items out';
        break;

    case 'global':
    default:
        $myPermissions  = get_my_permissions($conn);
        $result         = gf_run_global_list_query($conn, $_GET, $myPermissions);
        $title          = 'Global Resident List';
        $summaryLabel   = 'Total residents';
        break;
}

$columns = $result['columns'] ?? [];
$rows    = $result['data'] ?? [];
$count   = $result['count'] ?? 0;

// 7. Construct Top-Right Metadata Lines
$metaLines = [];
if ($list === 'global') {
    $dateFromRaw = isset($_GET['date_from']) ? trim((string) $_GET['date_from']) : '';
    $dateToRaw   = isset($_GET['date_to'])   ? trim((string) $_GET['date_to'])   : '';

    if ($dateFromRaw === '' && $dateToRaw === '') {
        $dateRangeLabel = 'All Records';
    } else {
        $dateRangeLabel = ($dateFromRaw !== '' ? date('M d, Y', strtotime($dateFromRaw)) : 'Earliest')
            . ' - '
            . ($dateToRaw !== '' ? date('M d, Y', strtotime($dateToRaw)) : 'Present');
    }
    $metaLines[] = 'Date Range: ' . $dateRangeLabel;
} else {
    $q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
    if ($q !== '') {
        $metaLines[] = 'Search: "' . $q . '"';
    }
}
$metaLines[] = 'Generated On: ' . date('F d, Y') . ' at ' . date('g:i A');

// 8. Begin Print Layout Rendering
print_report_start($siteSettings, $title, $metaLines, $list === 'global' ? 'landscape' : 'portrait');
?>

<!-- Main Printable Data Table -->
<table class="report-table">
  <thead>
    <tr>
      <th style="width: 40px; text-align: center;">#</th>
      <th><?= e($nameLabel) ?></th>
      <?php if ($list === 'global'): ?>
        <th>Age</th>
        <th>Birthdate</th>
        <th>Contact Number</th>
        <th>Address</th>
      <?php endif; ?>
      <?php foreach ($columns as $c): ?>
        <th><?= e($c['label']) ?></th>
      <?php endforeach; ?>
    </tr>
  </thead>
  <tbody>
    <?php if (empty($rows)): ?>
      <tr>
        <td class="report-empty" colspan="<?= ($list === 'global' ? 6 : 2) + count($columns) ?>">
          No records found matching the criteria.
        </td>
      </tr>
    <?php else: ?>
      <?php foreach ($rows as $i => $row): ?>
        <tr>
          <td style="text-align: center; color: #6b7280; font-weight: 600;"><?= $i + 1 ?></td>
          <td style="font-weight: 600; color: #111827;"><?= e($row['name'] ?? '-') ?></td>
          <?php if ($list === 'global'): ?>
            <td><?= e($row['age'] ?? '-') ?></td>
            <td><?= e($row['birthdate'] ?? '-') ?></td>
            <td><?= e($row['contact_number'] ?? '-') ?></td>
            <td><?= e($row['address'] ?? '-') ?></td>
          <?php endif; ?>
          <?php foreach ($columns as $c): ?>
            <td>
              <?php
                $val = $row[$c['key']] ?? '-';
                echo !empty($c['raw']) ? $val : e($val);
              ?>
            </td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
    <?php endif; ?>
  </tbody>
</table>

<!-- Summary Section (bottom-left, after the table) -->
<div class="report-summary" style="margin-top: 14px; margin-bottom: 0;">
  <span class="count"><?= e($summaryLabel) ?>: <strong><?= number_format($count) ?></strong></span>
</div>

<?php
// 9. Signature Section & Footer
print_report_signature($conn);
print_report_end($siteSettings);