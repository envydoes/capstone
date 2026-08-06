<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../config/db_connection.php';

$accId     = $_SESSION['acc_id'] ?? '';
$listingId = intval($_GET['id']  ?? 0);

if (empty($accId) || !$listingId) {
    header('Location: manageList.php?error=1');
    exit;
}

// â�?��,�â�?��,� Fetch listing to verify ownership and get photo paths â�?��,�â�?��,�
$stmt = $conn->prepare("SELECT id, photos FROM tbl_busaptlisting WHERE id = ? AND userId = ? LIMIT 1");
if (!$stmt) {
    header('Location: manageList.php?error=1');
    exit;
}
$stmt->bind_param('is', $listingId, $accId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    // Not found or not owner
    header('Location: manageList.php?error=1');
    exit;
}

// â�?��,�â�?��,� Delete uploaded photos from disk â�?��,�â�?��,�
$photos = json_decode($row['photos'] ?? '[]', true);
if (is_array($photos)) {
    $resolvedUploadDir = realpath(dirname(__FILE__) . '/../uploads/listings');
    foreach ($photos as $photoPath) {
        if (empty($photoPath)) continue;
        $absPhoto = realpath(dirname(__FILE__) . '/../' . ltrim($photoPath, './'));
        if (!$absPhoto) $absPhoto = realpath($photoPath);
        if ($absPhoto && $resolvedUploadDir && str_starts_with($absPhoto, $resolvedUploadDir)) {
            @unlink($absPhoto);
        }
    }
}

// â�?��,�â�?��,� Delete the database record â�?��,�â�?��,�
$del = $conn->prepare("DELETE FROM tbl_busaptlisting WHERE id = ? AND userId = ?");
if (!$del) {
    header('Location: manageList.php?error=1');
    exit;
}
$del->bind_param('is', $listingId, $accId);

if ($del->execute()) {
    $del->close();
    $conn->close();
    header('Location: manageList.php?deleted=1');
} else {
    $del->close();
    $conn->close();
    header('Location: manageList.php?error=1');
}
exit;