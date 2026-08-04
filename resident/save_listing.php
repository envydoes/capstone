<?php
session_start();

// Authentication
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// Database connection
$host     = "localhost";
$dbuser   = "root";
$password = "";
$database = "sumeste_db";

$conn = mysqli_connect($host, $dbuser, $password, $database);
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Verify user is logged in and has an account ID
$userId = $_SESSION['acc_id'] ?? '';
if (empty($userId)) {
    header('Location: ../login.php');
    exit;
}

// Allow POST only with the submit_listing flag
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['submit_listing'])) {
    header('Location: manageList.php');
    exit;
}

// ── Listing Type ──
$listingType = trim($_POST['listing_type'] ?? '');

// Normalise legacy shorthand values from the hidden field
if ($listingType === 'apt') {
    $listingType = 'apartment';
} elseif ($listingType === 'biz') {
    $listingType = 'business';
}

if (!in_array($listingType, ['apartment', 'business'], true)) {
    header('Location: manageList.php?error=1');
    exit;
}

// ── Shared / contact fields ──
$contact  = trim($_POST['contact']   ?? '');
$email    = trim($_POST['email']     ?? '');
$houseNum = trim($_POST['house_num'] ?? '');
$street   = trim($_POST['street']    ?? '');
$barangay = 'Sumacab Este';    // fixed — disabled fields won't appear in POST
$city     = 'Cabanatuan City'; // fixed
$mapsLink = trim($_POST['maps_link'] ?? '');

// ── Initialise all column variables to safe defaults ──
$slotsAvailable = 0;
// Apartment columns
$aptType      = null;
$aptTitle     = null;
$aptStatus    = null;
$aptPrice     = null;
$aptFloor     = null;
$aptRooms     = null;
$aptOccupants = null;
$aptBath      = null;
$aptIncluded  = null;
$aptAmenities = null;
$aptRules     = null;
$aptDesc      = null;
$aptAddress   = null;
$aptMapsLink  = null;
// Business columns
$bussCat      = null;
$bussName     = null;
$bussStatus   = null;
$bussPrice    = null;
$bussYears    = null;
$bussOpen     = null;
$bussClose    = null;
$bussDays     = null;
$bussFeatures = null;
$bussDesc     = null;
$bussAddress  = null;
$bussMapsLink = null;

// ── Type-specific field population ──
if ($listingType === 'apartment') {
    $aptType        = trim($_POST['apt_type']    ?? '');
    $aptTitle       = trim($_POST['apt_title']   ?? '');
    $aptStatus      = trim($_POST['apt_status']  ?? '');
    $aptPrice       = floatval(str_replace(',', '', $_POST['apt_price'] ?? '0'));
    $aptFloor       = trim($_POST['apt_floor']   ?? '');
    $aptRooms       = intval($_POST['apt_rooms'] ?? 0)     ?: null;
    $aptOccupants   = intval($_POST['apt_occupants'] ?? 0) ?: null;
    $aptBath        = trim($_POST['apt_bath']    ?? '');
    $slotsAvailable = intval($_POST['apt_slots'] ?? $_POST['slots_available'] ?? 0);
    // JSON-encode checkbox arrays — default to empty array if nothing ticked
    $aptIncluded    = json_encode(array_values(array_filter((array)($_POST['apt_inc']   ?? []))));
    $aptAmenities   = json_encode(array_values(array_filter((array)($_POST['apt_amn']   ?? []))));
    $aptRules       = json_encode(array_values(array_filter((array)($_POST['apt_rules'] ?? []))));
    $aptDesc        = trim($_POST['apt_desc']    ?? '');
    $aptAddress     = trim("$houseNum $street, $barangay, $city");
    $aptMapsLink    = $mapsLink;

} elseif ($listingType === 'business') {
    $bussCat      = trim($_POST['buss_cat']    ?? '');
    $bussName     = trim($_POST['buss_name']   ?? '');
    $bussStatus   = trim($_POST['buss_status'] ?? '');
    $bussPrice    = trim($_POST['buss_price']  ?? '');
    $bussYears    = trim($_POST['buss_years']  ?? '');
    // TIME values — store NULL if empty
    $rawOpen      = trim($_POST['buss_open']  ?? '');
    $rawClose     = trim($_POST['buss_close'] ?? '');
    $bussOpen     = $rawOpen  !== '' ? $rawOpen  : null;
    $bussClose    = $rawClose !== '' ? $rawClose : null;
    // JSON-encode checkbox arrays
    $bussDays     = json_encode(array_values(array_filter((array)($_POST['buss_days'] ?? []))));
    $bussFeatures = json_encode(array_values(array_filter((array)($_POST['buss_feat'] ?? []))));
    $bussDesc     = trim($_POST['buss_desc']   ?? '');
    $bussAddress  = trim("$houseNum $street, $barangay, $city");
    $bussMapsLink = $mapsLink;
    $slotsAvailable = 0; // businesses don't use slots
}

// ── Handle photo uploads ──
$photoPaths = [];

if (isset($_FILES['photos']) && !empty($_FILES['photos']['name'][0])) {

    // Resolve path relative to THIS file's actual location
    $uploadDir = __DIR__ . '/../uploads/listings/';

    // Create the directory if it doesn't exist
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            error_log("[save_listing] Failed to create upload dir: $uploadDir");
        }
    }

    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    $maxSize = 5 * 1024 * 1024; // 5 MB

    foreach ($_FILES['photos']['tmp_name'] as $key => $tmpName) {
        if (count($photoPaths) >= 4) break; // enforce max 4

        if (empty($tmpName) || $_FILES['photos']['error'][$key] !== UPLOAD_ERR_OK) {
            error_log("[save_listing] Upload error for index $key: code=" . ($_FILES['photos']['error'][$key] ?? 'empty'));
            continue;
        }

        $origName = $_FILES['photos']['name'][$key];
        $fileSize = $_FILES['photos']['size'][$key];
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed, true)) {
            error_log("[save_listing] Rejected extension: $ext for file: $origName");
            continue;
        }

        if ($fileSize > $maxSize) {
            error_log("[save_listing] Rejected oversized file: $origName ({$fileSize} bytes)");
            continue;
        }

        // Validate it's actually an image using mime type
        $mime = mime_content_type($tmpName);
        if (!str_starts_with($mime, 'image/')) {
            error_log("[save_listing] Rejected non-image mime: $mime for file: $origName");
            continue;
        }

        $fileName = time() . '_' . uniqid() . '.' . $ext;
        $destPath = $uploadDir . $fileName;

        if (move_uploaded_file($tmpName, $destPath)) {
            $photoPaths[] = $fileName;
            error_log("[save_listing] Saved photo: $fileName");
        } else {
            error_log("[save_listing] move_uploaded_file failed: $tmpName -> $destPath");
        }
    }
}

$photosJson = json_encode($photoPaths);

// ── INSERT ──
$sql = "
    INSERT INTO tbl_busaptListing (
        userId, listingType, slotsAvailable,
        aptType, aptTitle, aptStatus, aptPrice, aptFloor,
        aptRooms, aptOccupants, aptBath,
        aptIncluded, aptAmenities, aptRules,
        aptDesc, aptAddress, aptMapsLink,
        bussCat, bussName, bussStatus, bussPrice, bussYears,
        bussOpen, bussClose, bussDays, bussFeatures,
        bussDesc, bussAddress, bussMapsLink,
        contact, email, houseNum, street, barangay, city,
        photos
    ) VALUES (
        ?, ?, ?,
        ?, ?, ?, ?, ?,
        ?, ?, ?,
        ?, ?, ?,
        ?, ?, ?,
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?,
        ?, ?, ?, ?, ?, ?,
        ?
    )
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("[save_listing] Prepare failed: " . $conn->error);
    header('Location: manageList.php?error=1');
    exit;
}

// Build type string — 36 params total
$types  = 'ssi';     // userId, listingType, slotsAvailable
$types .= 'sssds';   // aptType, aptTitle, aptStatus, aptPrice, aptFloor
$types .= 'iis';     // aptRooms, aptOccupants, aptBath
$types .= 'sss';     // aptIncluded, aptAmenities, aptRules
$types .= 'sss';     // aptDesc, aptAddress, aptMapsLink
$types .= 'sssss';   // bussCat, bussName, bussStatus, bussPrice, bussYears
$types .= 'ssss';    // bussOpen, bussClose, bussDays, bussFeatures
$types .= 'sss';     // bussDesc, bussAddress, bussMapsLink
$types .= 'ssssss';  // contact, email, houseNum, street, barangay, city
$types .= 's';       // photos
// Total: 3+5+3+3+3+5+4+3+6+1 = 36 ✓

$params = [
    $userId, $listingType, $slotsAvailable,
    $aptType, $aptTitle, $aptStatus, $aptPrice, $aptFloor,
    $aptRooms, $aptOccupants, $aptBath,
    $aptIncluded, $aptAmenities, $aptRules,
    $aptDesc, $aptAddress, $aptMapsLink,
    $bussCat, $bussName, $bussStatus, $bussPrice, $bussYears,
    $bussOpen, $bussClose, $bussDays, $bussFeatures,
    $bussDesc, $bussAddress, $bussMapsLink,
    $contact, $email, $houseNum, $street, $barangay, $city,
    $photosJson,
];

if (strlen($types) !== count($params)) {
    error_log("[save_listing] Type/param mismatch: types=" . strlen($types) . " params=" . count($params));
    header('Location: manageList.php?error=1');
    exit;
}

$stmt->bind_param($types, ...$params);

if ($stmt->execute()) {
    $stmt->close();
    $conn->close();
    header('Location: manageList.php?success=1');
    exit;
} else {
    error_log("[save_listing] Execute error: " . $stmt->error);
    $stmt->close();
    $conn->close();
    header('Location: manageList.php?error=1');
    exit;
}