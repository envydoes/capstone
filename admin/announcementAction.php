<?php
/**
 * announcementAction.php
 * Create / edit / delete announcements.
 */
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../config/db_connection.php';

require_once __DIR__ . '/../includes/check_permissions.php';
require_permission_ajax($conn, 'manage_announcements');

$action = $_POST['action'] ?? '';
$uploadDir = __DIR__ . '/../uploads/announcement/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);

/* â�?�â�?� Helper: save uploaded files â�?�â�?� */
function saveUploads(array $files, string $uploadDir): array {
    $saved = [];
    foreach ($files['name'] as $i => $name) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','webp','gif'])) continue;
        if ($files['size'][$i] > 5 * 1024 * 1024) continue;
        $newName = uniqid('ann_', true) . '.' . $ext;
        if (move_uploaded_file($files['tmp_name'][$i], $uploadDir . $newName)) {
            $saved[] = $newName;
        }
    }
    return $saved;
}

/* â�?�â�?� Helper: delete image files â�?�â�?� */
function deleteImages(array $filenames, string $uploadDir): void {
    foreach ($filenames as $f) {
        $path = $uploadDir . basename($f);
        if (file_exists($path)) unlink($path);
    }
}

switch ($action) {

    /* â�?��,�â�?��,� CREATE â�?��,�â�?��,� */
    case 'create':
        $title   = trim($_POST['title']   ?? '');
        $desc    = trim($_POST['desc']    ?? '');
        $details = trim($_POST['details'] ?? '');
        $tag     = trim($_POST['tag']     ?? '');
        $post    = $_POST['postDate']  ?? '';
        $start   = $_POST['startDate'] ?? '';

        if (!$title || !$desc || !$tag || !$post || !$start) {
            echo json_encode(['success'=>false,'message'=>'Missing required fields']); exit;
        }

        $imgs = [];
        if (!empty($_FILES['images']['name'][0])) {
            $imgs = saveUploads($_FILES['images'], $uploadDir);
        }
        $imgJson = json_encode($imgs);

        $stmt = $conn->prepare(
            "INSERT INTO tbl_announcement (announcementTitle, announcementDesc, announcementDetails, announcementTag, announcementPost, announcementStart, announcementImg)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('sssssss', $title, $desc, $details, $tag, $post, $start, $imgJson);
        if ($stmt->execute()) {
            echo json_encode(['success'=>true,'message'=>'Announcement posted successfully.','id'=>$stmt->insert_id]);
        } else {
            deleteImages($imgs, $uploadDir);
            echo json_encode(['success'=>false,'message'=>'Could not save announcement.']);
        }
        $stmt->close();
        break;

    /* â�?��,�â�?��,� UPDATE / EDIT â�?��,�â�?��,� */
    case 'edit':
        $id      = (int)($_POST['id'] ?? 0);
        $title   = trim($_POST['title']   ?? '');
        $desc    = trim($_POST['desc']    ?? '');
        $details = trim($_POST['details'] ?? '');
        $tag     = trim($_POST['tag']     ?? '');
        $post    = $_POST['postDate']  ?? '';
        $start   = $_POST['startDate'] ?? '';

        if (!$id || !$title || !$desc || !$tag || !$post || !$start) {
            echo json_encode(['success'=>false,'message'=>'Missing required fields']); exit;
        }

        $existing = json_decode($_POST['existingImgs'] ?? '[]', true);
        if (!is_array($existing)) $existing = [];

        $removed = json_decode($_POST['removedImgs'] ?? '[]', true);
        if (!is_array($removed)) $removed = [];
        deleteImages($removed, $uploadDir);

        $newImgs = [];
        if (!empty($_FILES['images']['name'][0])) {
            $newImgs = saveUploads($_FILES['images'], $uploadDir);
        }

        $allImgs = array_values(array_merge($existing, $newImgs));
        $imgJson = json_encode($allImgs);

        $stmt = $conn->prepare(
            "UPDATE tbl_announcement SET announcementTitle=?, announcementDesc=?, announcementDetails=?, announcementTag=?, announcementPost=?, announcementStart=?, announcementImg=? WHERE announcementID=?"
        );
        $stmt->bind_param('sssssssi', $title, $desc, $details, $tag, $post, $start, $imgJson, $id);
        if ($stmt->execute()) {
            echo json_encode(['success'=>true,'message'=>'Announcement updated successfully.']);
        } else {
            deleteImages($newImgs, $uploadDir);
            echo json_encode(['success'=>false,'message'=>'Could not update announcement.']);
        }
        $stmt->close();
        break;

    /* â�?��,�â�?��,� DELETE â�?��,�â�?��,� */
    case 'delete':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['success'=>false,'message'=>'Invalid ID']); exit; }

        $res = $conn->query("SELECT announcementImg FROM tbl_announcement WHERE announcementID=$id LIMIT 1");
        if ($res && $row = $res->fetch_assoc()) {
            $imgs = json_decode($row['announcementImg'] ?? '[]', true);
            if (is_array($imgs)) deleteImages($imgs, $uploadDir);
        }

        if ($conn->query("DELETE FROM tbl_announcement WHERE announcementID=$id")) {
            echo json_encode(['success'=>true,'message'=>'Deleted successfully.']);
        } else {
            echo json_encode(['success'=>false,'message'=>'Could not delete.']);
        }
        break;

    default:
        echo json_encode(['success'=>false,'message'=>'Unknown action.']);
}

$conn->close();