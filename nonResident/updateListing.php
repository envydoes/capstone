<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthenticated']);
    exit;
}

$host     = "o7jpqmin0zgconui4xtnfju6";
$dbuser   = "root";
$password = "''";
$database = "sumeste_db";

$conn = mysqli_connect($host, $dbuser, $password, $database);
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$accId = $_SESSION['acc_id'] ?? '';
if (empty($accId)) {
    echo json_encode(['success' => false, 'message' => 'User session invalid']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Ã¢â€â‚¬Ã¢â€â‚¬ Parse request: multipart (with new photos) OR JSON Ã¢â€â‚¬Ã¢â€â‚¬
$data      = null;
$newPhotos = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (str_contains($contentType, 'multipart/form-data')) {
    $rawData = $_POST['data'] ?? '';
    if (empty($rawData)) {
        echo json_encode(['success' => false, 'message' => 'No data received']);
        exit;
    }
    $data = json_decode($rawData, true);
    if (!is_array($data)) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
        exit;
    }
    if (!empty($_FILES['new_photos']['tmp_name'])) {
        $newPhotos = $_FILES['new_photos'];
    }
} else {
    $rawBody = file_get_contents('php://input');
    $data    = json_decode($rawBody, true);
    if (!is_array($data)) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON body']);
        exit;
    }
}

$listingId   = intval($data['id']        ?? 0);
$listingType = trim($data['listingType'] ?? '');

if (!$listingId) {
    echo json_encode(['success' => false, 'message' => 'Invalid listing ID']);
    exit;
}

// Ã¢â€â‚¬Ã¢â€â‚¬ Verify ownership Ã¢â€â‚¬Ã¢â€â‚¬
$ownerStmt = $conn->prepare("SELECT id, photos FROM tbl_busaptlisting WHERE id = ? AND userId = ? LIMIT 1");
if (!$ownerStmt) {
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $conn->error]);
    exit;
}
$ownerStmt->bind_param('is', $listingId, $accId);
$ownerStmt->execute();
$ownerRow = $ownerStmt->get_result()->fetch_assoc();
$ownerStmt->close();

if (!$ownerRow) {
    echo json_encode(['success' => false, 'message' => 'Listing not found or access denied']);
    exit;
}

// Ã¢â€â‚¬Ã¢â€â‚¬ Shared fields Ã¢â€â‚¬Ã¢â€â‚¬
$contact  = trim($data['contact']  ?? '');
$email    = trim($data['email']    ?? '');
$mapsLink = trim($data['mapsLink'] ?? '');
$address  = trim($data['address']  ?? '');

$isApt = ($listingType === 'apt' || $listingType === 'apartment');

// Ã¢â€â‚¬Ã¢â€â‚¬ Handle photo removals Ã¢â€â‚¬Ã¢â€â‚¬
$currentPhotos = json_decode($ownerRow['photos'] ?? '[]', true);
if (!is_array($currentPhotos)) $currentPhotos = [];

$removedPhotos = (isset($data['removedPhotos']) && is_array($data['removedPhotos']))
    ? $data['removedPhotos'] : [];

foreach ($removedPhotos as $photoPath) {
    $filename = basename((string)$photoPath);
    $currentPhotos = array_values(array_filter($currentPhotos, fn($p) => $p !== $filename));
    $uploadDir = dirname(__FILE__) . '/../uploads/listings/';
    $absPhoto  = realpath($uploadDir . $filename);
    if ($absPhoto && str_starts_with($absPhoto, realpath($uploadDir))) {
        @unlink($absPhoto);
    }
}

// Ã¢â€â‚¬Ã¢â€â‚¬ Upload new photos Ã¢â€â‚¬Ã¢â€â‚¬
if (!empty($newPhotos['tmp_name'])) {
    $uploadDir = dirname(__FILE__) . '/../uploads/listings/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    $maxSize = 5 * 1024 * 1024;

    foreach ($newPhotos['tmp_name'] as $key => $tmpName) {
        if (count($currentPhotos) >= 4) break;
        if (empty($tmpName) || $newPhotos['error'][$key] !== UPLOAD_ERR_OK) continue;
        $ext = strtolower(pathinfo($newPhotos['name'][$key], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) continue;
        if ($newPhotos['size'][$key] > $maxSize) continue;
        if (!str_starts_with(mime_content_type($tmpName), 'image/')) continue;
        $fileName = time() . '_' . uniqid() . '.' . $ext;
        if (move_uploaded_file($tmpName, $uploadDir . $fileName)) {
            $currentPhotos[] = $fileName;
        }
    }
}

$photosJson = json_encode(array_values($currentPhotos));

if ($isApt) {
    // Ã¢â€â‚¬Ã¢â€â‚¬ APARTMENT UPDATE (20 params) Ã¢â€â‚¬Ã¢â€â‚¬
    // Positions and types:
    //  1 aptTitle       s    5 aptFloor       s    9 slotsAvailable i   13 aptRules  s   17 email      s
    //  2 aptType        s    6 aptRooms        i   10 aptDesc        s   14 address   s   18 photosJson s
    //  3 aptStatus      s    7 aptOccupants    i   11 aptIncluded    s   15 mapsLink  s   19 listingId  i
    //  4 aptPrice       d    8 aptBath         s   12 aptAmenities   s   16 contact   s   20 accId      s
    //
    // Type string (20 chars): s s s d s i i s i s s s s s s s s s i s
    // Verified: 'sssdsiisisssssssssis' length=20 Ã¢Å“â€œ

    $aptTitle       = trim($data['aptTitle']       ?? '');
    $aptType        = trim($data['aptType']        ?? '');
    $aptStatus      = trim($data['aptStatus']      ?? '');
    $aptPrice       = floatval($data['aptPrice']   ?? 0);
    $aptFloor       = trim($data['aptFloor']       ?? '');
    $aptRooms       = ($data['aptRooms']     !== '' && $data['aptRooms']     !== null) ? intval($data['aptRooms'])     : null;
    $aptOccupants   = ($data['aptOccupants'] !== '' && $data['aptOccupants'] !== null) ? intval($data['aptOccupants']) : null;
    $aptBath        = trim($data['aptBath']        ?? '');
    $slotsAvailable = intval($data['slotsAvailable'] ?? 0);
    $aptDesc        = trim($data['aptDesc']        ?? '');
    $aptIncluded    = json_encode(array_values((array)($data['aptIncluded']  ?? [])));
    $aptAmenities   = json_encode(array_values((array)($data['aptAmenities'] ?? [])));
    $aptRules       = json_encode(array_values((array)($data['aptRules']     ?? [])));

    $sql = "
        UPDATE tbl_busaptlisting SET
            aptTitle       = ?,
            aptType        = ?,
            aptStatus      = ?,
            aptPrice       = ?,
            aptFloor       = ?,
            aptRooms       = ?,
            aptOccupants   = ?,
            aptBath        = ?,
            slotsAvailable = ?,
            aptDesc        = ?,
            aptIncluded    = ?,
            aptAmenities   = ?,
            aptRules       = ?,
            aptAddress     = ?,
            aptMapsLink    = ?,
            contact        = ?,
            email          = ?,
            photos         = ?
        WHERE id = ? AND userId = ?
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'DB prepare error: ' . $conn->error]);
        exit;
    }

    $stmt->bind_param(
        'sssdsiisisssssssssis',   // Ã¢â€ Â 20 chars, verified Ã¢Å“â€œ
        $aptTitle,        //  1 s
        $aptType,         //  2 s
        $aptStatus,       //  3 s
        $aptPrice,        //  4 d
        $aptFloor,        //  5 s
        $aptRooms,        //  6 i
        $aptOccupants,    //  7 i
        $aptBath,         //  8 s
        $slotsAvailable,  //  9 i
        $aptDesc,         // 10 s
        $aptIncluded,     // 11 s
        $aptAmenities,    // 12 s
        $aptRules,        // 13 s
        $address,         // 14 s
        $mapsLink,        // 15 s
        $contact,         // 16 s
        $email,           // 17 s
        $photosJson,      // 18 s
        $listingId,       // 19 i
        $accId            // 20 s
    );

} else {
    // Ã¢â€â‚¬Ã¢â€â‚¬ BUSINESS UPDATE (17 params) Ã¢â€â‚¬Ã¢â€â‚¬
    // All string fields, then listingId (int), then accId (string)
    // Type string (17 chars): 15Ãƒâ€”s + i + s
    // Verified: 'sssssssssssssssis' length=17 Ã¢Å“â€œ

    $bussName     = trim($data['bussName']   ?? '');
    $bussCat      = trim($data['bussCat']    ?? '');
    $bussStatus   = trim($data['bussStatus'] ?? '');
    $bussPrice    = trim($data['bussPrice']  ?? '');
    $bussYears    = trim($data['bussYears']  ?? '');
    $rawOpen      = trim($data['bussOpen']   ?? '');
    $rawClose     = trim($data['bussClose']  ?? '');
    $bussOpen     = ($rawOpen  !== '') ? $rawOpen  : null;
    $bussClose    = ($rawClose !== '') ? $rawClose : null;
    $bussDesc     = trim($data['bussDesc']   ?? '');
    $bussFeatures = json_encode(array_values((array)($data['bussFeatures'] ?? [])));
    $bussDays     = json_encode(array_values((array)($data['bussDays']     ?? [])));

    $sql = "
        UPDATE tbl_busaptlisting SET
            bussName     = ?,
            bussCat      = ?,
            bussStatus   = ?,
            bussPrice    = ?,
            bussYears    = ?,
            bussOpen     = ?,
            bussClose    = ?,
            bussFeatures = ?,
            bussDays     = ?,
            bussDesc     = ?,
            bussAddress  = ?,
            bussMapsLink = ?,
            contact      = ?,
            email        = ?,
            photos       = ?
        WHERE id = ? AND userId = ?
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'DB prepare error: ' . $conn->error]);
        exit;
    }

    $stmt->bind_param(
        'sssssssssssssssis',   // Ã¢â€ Â 17 chars, verified Ã¢Å“â€œ
        $bussName,      //  1 s
        $bussCat,       //  2 s
        $bussStatus,    //  3 s
        $bussPrice,     //  4 s
        $bussYears,     //  5 s
        $bussOpen,      //  6 s
        $bussClose,     //  7 s
        $bussFeatures,  //  8 s
        $bussDays,      //  9 s
        $bussDesc,      // 10 s
        $address,       // 11 s
        $mapsLink,      // 12 s
        $contact,       // 13 s
        $email,         // 14 s
        $photosJson,    // 15 s
        $listingId,     // 16 i
        $accId          // 17 s
    );
}

if ($stmt->execute()) {
    $stmt->close();
    $conn->close();
    echo json_encode(['success' => true, 'message' => 'Listing updated successfully']);
} else {
    $errMsg = $stmt->error;
    error_log("updateListing.php: Update failed: $errMsg");
    $stmt->close();
    $conn->close();
    echo json_encode(['success' => false, 'message' => 'Update failed: ' . $errMsg]);
}