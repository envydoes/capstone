<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['account_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

require_once __DIR__ . '/config/db_connection.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request body.']);
    exit;
}

function s(array $input, string $key, int $maxLen = 255): string
{
    $val = trim((string) ($input[$key] ?? ''));
    return mb_substr($val, 0, $maxLen);
}

// A logo value is either blank (revert to default asset) or a plain filename
// that was actually returned by settingsLogoUpload.php — never a path.
function sanitizeLogoFilename($input, string $key): string
{
    $val = basename(trim((string) ($input[$key] ?? '')));
    if ($val === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $val)) {
        return '';
    }
    return $val;
}

$barangayName   = s($input, 'barangay_name', 150);
$municipality   = s($input, 'municipality', 150);
$contactNumber  = s($input, 'contact_number', 20);
$email          = s($input, 'email', 254);
$facebookLink   = s($input, 'facebook_link', 255);
$reachContent   = s($input, 'our_reach_content', 1000);
$puroksCovered  = max(0, (int) ($input['puroks_covered'] ?? 0));
$areaServed     = max(0, (float) ($input['area_served'] ?? 0));
$mapQuery       = s($input, 'map_query', 255);
$siteTitle      = s($input, 'site_title', 150) ?: 'SumEste Portal';
$colorTheme     = s($input, 'color_theme', 20);
$barangayLogo   = sanitizeLogoFilename($input, 'barangay_logo');
$municipalityLogo = sanitizeLogoFilename($input, 'municipality_logo');
$siteLogo       = sanitizeLogoFilename($input, 'site_logo');

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

if ($colorTheme !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $colorTheme)) {
    echo json_encode(['success' => false, 'message' => 'Invalid color value.']);
    exit;
}
if ($colorTheme === '') {
    $colorTheme = '#15803d';
}

// Fetch the current logo filenames so we can clean up replaced files
// once the new values are safely committed.
$oldResult = mysqli_query($conn, "SELECT site_logo, barangay_logo, municipality_logo FROM tbl_settings WHERE id = 1");
$oldRow = $oldResult ? mysqli_fetch_assoc($oldResult) : null;
$oldSiteLogo = $oldRow['site_logo'] ?? '';
$oldBarangayLogo = $oldRow['barangay_logo'] ?? '';
$oldMunicipalityLogo = $oldRow['municipality_logo'] ?? '';

$stmt = mysqli_prepare($conn, "
    UPDATE tbl_settings SET
        barangay_name = ?, municipality = ?, contact_number = ?, email = ?,
        facebook_link = ?, our_reach_content = ?, puroks_covered = ?, area_served = ?,
        map_query = ?, site_title = ?, color_theme = ?, site_logo = ?, barangay_logo = ?, municipality_logo = ?
    WHERE id = 1
");

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error.']);
    exit;
}

mysqli_stmt_bind_param(
    $stmt,
    'ssssssidssssss',
    $barangayName, $municipality, $contactNumber, $email,
    $facebookLink, $reachContent, $puroksCovered, $areaServed,
    $mapQuery, $siteTitle, $colorTheme, $siteLogo, $barangayLogo, $municipalityLogo
);

if (mysqli_stmt_execute($stmt)) {
    $uploadDir = __DIR__ . '/uploads/site/';
    if ($oldSiteLogo && $oldSiteLogo !== $siteLogo) {
        $p = $uploadDir . basename($oldSiteLogo);
        if (is_file($p)) @unlink($p);
    }
    if ($oldBarangayLogo && $oldBarangayLogo !== $barangayLogo) {
        $p = $uploadDir . basename($oldBarangayLogo);
        if (is_file($p)) @unlink($p);
    }
    if ($oldMunicipalityLogo && $oldMunicipalityLogo !== $municipalityLogo) {
        $p = $uploadDir . basename($oldMunicipalityLogo);
        if (is_file($p)) @unlink($p);
    }
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to save settings.']);
}

mysqli_stmt_close($stmt);
mysqli_close($conn);