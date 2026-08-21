<?php
/**
 * includes/print_report_layout.php
 * ------------------------------------------------------------
 * Shared masthead / footer / Legal-page print chrome, reused by every
 * "Print This List" report (print_global_list.php, print_outreach_list.php,
 * print_owner_directory.php, print_borrowed_list.php) so they all look
 * and behave identically.
 *
 * Usage:
 *   require_once __DIR__ . '/../includes/print_report_layout.php';
 *   print_report_start($siteSettings, 'Report Title', ['Optional meta line 1', 'line 2']);
 *   ... your own <table> / content ...
 *   print_report_end($siteSettings);
 * ------------------------------------------------------------
 */

if (!function_exists('print_report_start')) {
    /**
     * @param string $orientation 'portrait' (default) or 'landscape'.
     *        Reports with many columns (e.g. the Global Resident List, which
     *        now always shows Name/Age/Birthdate/Contact/Address plus any
     *        active condition columns) should pass 'landscape' — Legal
     *        landscape gives roughly double the usable table width versus
     *        portrait, which is a much better fix for a wide table than
     *        shrinking the font until it's hard to read.
     */
    function print_report_start(array $siteSettings, string $reportTitle, array $metaLines = [], string $orientation = 'portrait'): void
    {
        // NOTE: intentionally using the standard CSS `legal` keyword rather
        // than a hand-specified dimension (e.g. `8.5in 13in`). A custom
        // two-value size isn't one of the recognized paper presets that
        // Chromium's print pipeline / "Microsoft Print to PDF" expects, and
        // that mismatch caused content to paginate incorrectly (splitting
        // a short table across 2 mostly-blank pages instead of 1). `legal`
        // (8.5in x 14in) is the size actually confirmed working end-to-end.
        $pageSize = $orientation === 'portrait' ? 'legal portrait' : 'legal';
        $logoUrl = site_config_logo_url($siteSettings, '../');
        $barangayUrl = site_config_barangay_logo_url($siteSettings, '../');
        $municipalUrl = site_config_municipality_logo_url($siteSettings, '../');
        $address = trim($siteSettings['map_query']);

        /* Values baked directly into @page margin-box `content` strings -
           CSS content strings can't contain raw quotes/newlines, so keep these plain. */
        $footerBarangay   = str_replace('"', "'", 'Barangay ' . $siteSettings['barangay_name']);
        $footerAddress    = str_replace('"', "'", $address);
        $footerContact    = str_replace('"', "'", $siteSettings['contact_number'] ?: '-');
        $footerEmail      = str_replace('"', "'", $siteSettings['email'] ?: '-');
        $runningHeaderTxt = str_replace('"', "'", 'Barangay ' . $siteSettings['barangay_name'] . ' - ' . $reportTitle);
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= e($reportTitle) ?> - <?= e($siteSettings['site_title']) ?></title>
<link rel="icon" href="<?= e($logoUrl) ?>" type="image/png">
<?= site_config_css_vars($siteSettings) ?>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  * { box-sizing: border-box; }
  body {
    font-family: 'DM Sans', sans-serif;
    color: #1f2937;
    margin: 0;
    background: #eef2f0;
  }
  .page-wrap {
    max-width: 950px;
    margin: 24px auto;
    background: #fff;
    padding: 40px 50px 60px;
    box-shadow: 0 4px 24px rgba(0,0,0,0.08);
  }

  /* ?? Toolbar (screen only) ?? */
  .toolbar {
    max-width: 950px;
    margin: 16px auto 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 4px;
  }
  .toolbar a {
    color: #4b5563; font-size: 0.85rem; font-weight: 600; text-decoration: none;
    display: inline-flex; align-items: center; gap: 6px;
  }
  .toolbar a:hover { color: var(--site-primary-dark); }
  .btn-print {
    background: var(--site-primary); color: #fff; border: none; border-radius: 9px;
    padding: 10px 20px; font-size: 0.85rem; font-weight: 700; cursor: pointer;
    display: inline-flex; align-items: center; gap: 8px; transition: background 0.15s;
  }
  .btn-print:hover { background: var(--site-primary-dark); }

  /* ?? Masthead (official letterhead) ?? */
  .masthead { display: flex; align-items: flex-start; gap: 18px; padding-bottom: 14px; }
  .logo-box {
    width: 84px; height: 84px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    overflow: hidden; background: #fff;
  }
  .logo-box img { max-width: 100%; max-height: 100%; object-fit: contain; }
  .logo-box.empty { color: #d1d5db; font-size: 0.62rem; text-align: center; line-height: 1.3; }

  .masthead-text { flex: 1; text-align: center; padding-top: 6px; }
  .masthead-text .republic { font-size: 0.95rem; font-weight: 800; letter-spacing: 0.03em; color: #1a2e1a; margin: 0; text-transform: uppercase; }
  .masthead-text .barangay-name { font-size: 1.5rem; font-weight: 400; color: #1f2937; margin: 4px 0 0; }
  .masthead-text .municipality { font-size: 1.05rem; color: #374151; margin: 2px 0 0; }

  .contact-line { text-align: center; font-size: 0.9rem; color: #374151; margin: 14px 0 0; }
  .contact-line span + span::before { content: "\2502"; margin: 0 10px; color: #9ca3af; }

  .masthead-rule { border: none; border-top: 1.5px solid #1f2937; margin: 14px 0 22px; }

  /* ?? Report meta (right-aligned lines above the title) ?? */
  .report-meta { text-align: right; font-size: 0.82rem; color: #374151; line-height: 1.6; margin-bottom: 22px; }

  /* ?? Report title ?? */
  .report-title { text-align: center; margin: 0 0 18px; }
  .report-title h1 { font-size: 1.05rem; font-weight: 800; letter-spacing: 0.03em; text-transform: uppercase; color: #1a2e1a; margin: 0; }

  /* ?? Conditions applied (used by print_global_list.php) ?? */
  .conditions-box { background: var(--site-primary-pale); border: 1px solid color-mix(in srgb, var(--site-primary) 25%, white); border-radius: 10px; padding: 12px 16px; margin-bottom: 20px; }
  .conditions-box .label { font-size: 0.68rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: var(--site-primary-darker); margin: 0 0 8px; }
  .conditions-chips { display: flex; flex-wrap: wrap; gap: 6px; }
  .condition-chip { background: #fff; border: 1px solid color-mix(in srgb, var(--site-primary) 30%, white); border-radius: 999px; padding: 4px 11px; font-size: 0.72rem; color: #374151; }
  .condition-chip strong { color: var(--site-primary-darker); font-weight: 700; }
  .conditions-empty { font-size: 0.78rem; color: #6b7280; font-style: italic; }

  /* ?? Table ?? */
  .report-summary { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 8px; }
  .report-summary .count { font-size: 0.8rem; color: #4b5563; }
  .report-summary .count strong { color: #1a2e1a; }
  table.report-table { width: 100%; border-collapse: collapse; }
  table.report-table thead th {
    background: var(--site-primary); color: #fff; text-align: left; font-size: 0.66rem;
    font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; padding: 7px 8px;
  }
  table.report-table tbody td { padding: 6px 8px; font-size: 0.72rem; color: #374151; border-bottom: 1px solid #eef0f2; }
  table.report-table tbody tr:nth-child(even) { background: #fafbfa; }
  table.report-table tbody tr:last-child td { border-bottom: 1px solid #d1d5db; }
  table.report-table tbody td.report-empty { text-align: center; color: #9ca3af; padding: 18px; }

  .mini-badge { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 0.68rem; font-weight: 700; }
  .mini-badge-overdue { background: #fee2e2; color: #b91c1c; }
  .mini-badge-ontime  { background: #dcfce7; color: #166534; }

  /* ?? Signature section (Name & Position) ?? */
  .signature-section {
    display: flex;
    margin-top: 56px; page-break-inside: avoid;
  }
  .signature-block { width: 260px; flex: none; }
  .signature-role-label {
    font-size: 0.66rem; font-weight: 800; color: var(--site-primary-darker);
    text-transform: uppercase; letter-spacing: 0.06em; margin: 0 0 26px;
  }
  .signature-fill-line { border-bottom: 1.3px solid #1f2937; height: 26px; }
  .signature-name { font-size: 0.8rem; font-weight: 700; color: #1f2937; margin: 6px 0 0; }
  .signature-position { font-size: 0.72rem; color: #6b7280; margin: 2px 0 0; }

  /* ?? Analytics report (chart/graph picker) ?? */
  .analytics-section-title {
    font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em;
    color: var(--site-primary-darker); margin: 28px 0 14px; padding-bottom: 6px;
    border-bottom: 1.5px solid color-mix(in srgb, var(--site-primary) 30%, white);
  }
  .analytics-section-title:first-of-type { margin-top: 0; }
  .analytics-item { margin-bottom: 26px; page-break-inside: avoid; }
  .analytics-item-title { font-size: 0.85rem; font-weight: 700; color: #1a2e1a; margin: 0 0 4px; }
  .analytics-item-summary { font-size: 0.76rem; color: #6b7280; line-height: 1.5; margin: 0 0 10px; }
  .analytics-item-image { max-width: 100%; border: 1px solid #eef0f2; border-radius: 8px; display: block; }
  .analytics-item-unavailable { font-size: 0.76rem; color: #9ca3af; font-style: italic; padding: 14px; background: #f9fafb; border-radius: 8px; border: 1px dashed #e5e7eb; }

  .print-bar-row { display: flex; align-items: center; gap: 12px; margin-bottom: 8px; }
  .print-bar-label { width: 150px; flex-shrink: 0; font-size: 0.76rem; color: #4b5563; }
  .print-bar-track { flex: 1; height: 9px; background: #f1f5f9; border-radius: 999px; overflow: hidden; }
  .print-bar-fill { height: 100%; border-radius: 999px; background: var(--site-primary); }
  .print-bar-value { width: 70px; flex-shrink: 0; text-align: right; font-size: 0.76rem; font-weight: 700; color: #1f2937; }

  /* ?? On-screen footer preview (mirrors the printed @page footer below) ?? */
  .screen-footer-preview { margin-top: 40px; padding-top: 14px; border-top: 1px solid #1f2937; }
  .screen-footer-preview .row1 { display: flex; justify-content: flex-end; font-size: 0.72rem; color: #6b7280; }
  .screen-footer-preview .row2 { margin-top: 8px; font-size: 0.68rem; color: #9ca3af; line-height: 1.5; }

  /* ?? Print rules ??
       Paper is forced to Legal. The footer (generated-by line, page X of Y,
       and repeated barangay contact block) is rendered via native CSS @page
       margin boxes, so it repeats automatically on every printed page -
       support for this varies slightly by browser/print engine, so the
       on-screen ".screen-footer-preview" block above mirrors the same
       content as a fallback that's always visible when reading on screen. */
  @page {
    size: <?= $pageSize ?>;
    margin: 0.7in 0.6in 1in 0.6in;
    @bottom-left {
      content: "<?= $footerBarangay ?>\A <?= $footerAddress ?>\A Contact: <?= $footerContact ?>\A Email: <?= $footerEmail ?>";
      white-space: pre-line;
      font-family: 'DM Sans', sans-serif;
      font-size: 7.5pt;
      color: #6b7280;
    }
    @bottom-right {
      content: "Page " counter(page) " of " counter(pages);
      font-family: 'DM Sans', sans-serif;
      font-size: 8pt;
      color: #6b7280;
    }
  }
  @page :first {
    @top-center { content: none; }
  }
  @page {
    @top-center {
      content: "<?= $runningHeaderTxt ?>";
      font-family: 'DM Sans', sans-serif;
      font-size: 8pt;
      color: #9ca3af;
    }
  }
  @media print {
    body { background: #fff; }
    .no-print { display: none !important; }
    .page-wrap { box-shadow: none; margin: 0; padding: 0; max-width: none; }
    table.report-table thead th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    table.report-table { page-break-inside: auto; }
    table.report-table tr { page-break-inside: avoid; page-break-after: auto; }
    thead { display: table-header-group; }
    .analytics-item { page-break-inside: avoid; }
  }
</style>
</head>
<body>

  <div class="toolbar no-print">
    <a href="adminDashboard.php"><i class="fa-solid fa-arrow-left"></i> Back to Dashboard</a>
    <button type="button" class="btn-print" onclick="window.print()">
      <i class="fa-solid fa-print"></i> Print / Save as PDF
    </button>
  </div>

  <div class="page-wrap">

    <div class="masthead">
      <div class="logo-box">
        <img src="<?= e($barangayUrl) ?>" alt="Barangay Logo">
      </div>
      <div class="masthead-text">
        <p class="republic">Republic of the Philippines</p>
        <p class="barangay-name">Barangay <?= e($siteSettings['barangay_name']) ?></p>
        <p class="municipality"><?= e($siteSettings['municipality']) ?></p>
      </div>
      <div class="logo-box empty">
        <img src="<?= e($municipalUrl) ?>" alt="Municipal Logo">
      </div>
    </div>

    <p class="contact-line">
      <span><?= e($address) ?></span><br/>
      <?php if (!empty($siteSettings['contact_number'])): ?><span><?= e($siteSettings['contact_number']) ?></span><?php endif; ?>
      <?php if (!empty($siteSettings['email'])): ?><span><?= e($siteSettings['email']) ?></span><?php endif; ?>
    </p>

    <hr class="masthead-rule">

    <?php if (!empty($metaLines)): ?>
    <div class="report-meta">
      <?= implode('<br>', array_map('e', $metaLines)) ?>
    </div>
    <?php endif; ?>

    <div class="report-title">
      <h1><?= e($reportTitle) ?></h1>
    </div>
<?php
    }
}

if (!function_exists('print_report_signature')) {
    /**
     * Renders the "Prepared By" signature block. ("Noted By" was removed —
     * this report only carries a single signatory now.)
     *
     * Name/Position are auto-filled from the current staff account's row in
     * tbl_admin_permissions (full_name, position) when available. Falls
     * back to a blank fillable-by-hand line when that data isn't there —
     * e.g. the founder admin account (account_role === 'admin') has no row
     * in tbl_admin_permissions at all, and some existing staff grants have
     * full_name left NULL.
     *
     * @param mysqli|null $conn Pass the active connection to enable
     *        auto-fill. Omitting it (or passing null) always renders the
     *        blank fillable line, same as before this change.
     */
    function print_report_signature(?mysqli $conn = null): void
    {
        // NOTE: this is a plain array, not a top-level `const` — PHP does
        // not allow `const` declarations inside a conditional block (the
        // `if (!function_exists(...))` wrapper above), which was a fatal
        // compile-time error that broke this entire file on every request.
        $positionLabels = [
            'sk_chairperson'     => 'SK Chairperson',
            'barangay_secretary' => 'Barangay Secretary',
            'barangay_treasurer' => 'Barangay Treasurer',
            'barangay_clerk'     => 'Barangay Clerk / Admin Staff',
            'lupon_tagapamayapa' => 'Lupon Tagapamayapa Member',
            'barangay_tanod'     => 'Barangay Tanod (Peace and Order)',
            'bhw'                => 'Barangay Health Worker (BHW)',
            'bns'                => 'Barangay Nutrition Scholar (BNS)',
            'other'              => 'Other Barangay Staff',
        ];

        $preparedName     = '';
        $preparedPosition = '';

        if ($conn !== null) {
            $accID = $_SESSION['acc_id'] ?? ($_SESSION['user_id'] ?? '');
            if ($accID !== '') {
                $stmt = $conn->prepare('SELECT full_name, position FROM tbl_admin_permissions WHERE accID = ? LIMIT 1');
                if ($stmt) {
                    $stmt->bind_param('s', $accID);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($row) {
                        $preparedName     = trim((string) ($row['full_name'] ?? ''));
                        $preparedPosition = $positionLabels[$row['position'] ?? ''] ?? '';
                    }
                }
            }
        }
        ?>
    <div class="signature-section">
      <div class="signature-block">
        <p class="signature-role-label">PREPARED BY:</p>
        <div class="signature-fill-line">&nbsp;</div>
        <p class="signature-name"><?= $preparedName !== '' ? e($preparedName) : '&nbsp;' ?></p>
        <p class="signature-position"><?= $preparedPosition !== '' ? e($preparedPosition) : '&nbsp;' ?></p>
      </div>
    </div>
<?php
    }
}

if (!function_exists('print_report_end')) {
    function print_report_end(array $siteSettings): void
    {
        $address = trim($siteSettings['barangay_name'] . ', ' . $siteSettings['municipality']);
        ?>
    <div class="screen-footer-preview no-print">
      <div class="row1">
        <span>Page 1 of 1</span>
      </div>
      <div class="row2">
        Barangay <?= e($siteSettings['barangay_name']) ?><br>
        <?= e($address) ?><br>
        Contact: <?= e($siteSettings['contact_number'] ?: '-') ?><br>
        Email: <?= e($siteSettings['email'] ?: '-') ?>
      </div>
    </div>

  </div>

</body>
</html>
<?php
    }
}