<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$host = "o7jpqmin0zgconui4xtnfju6"; $user = "root"; $password = "UKkJ05DHQDMMMOxFEUI5f1HJGVj8Vb5gfJAEvAESTGCVWDtFEGb42qX67AxGUXvj"; $database = "sumeste_db";
$conn = mysqli_connect($host, $user, $password, $database);
if (!$conn) { echo json_encode(['success' => false, 'message' => 'DB connection failed']); exit; }

// Was hardcoded to account_role === 'admin' only, which blocked any staff
// account granted manage_borrowing from adding/editing/deleting equipment â€”
// even though the Borrowing System page itself already shows them the
// "Manage Equipment" tab and "Add New Equipment" button based on that same
// permission. Now this endpoint checks the same permission consistently.
require_once __DIR__ . '/../includes/check_permissions.php';
require_permission_ajax($conn, 'manage_borrowing');

$action = $_POST['action'] ?? '';

// â”€â”€ Upload helper â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function uploadImages(): string {
    $fileKey = 'images';
    if (!isset($_FILES[$fileKey]) || empty($_FILES[$fileKey]['name'][0])) {
        $fileKey = 'image';
    }
    if (!isset($_FILES[$fileKey])) return '';

    if (is_array($_FILES[$fileKey]['name'])) {
        if (empty($_FILES[$fileKey]['name'][0])) return '';
        $tmp = $_FILES[$fileKey]['tmp_name'][0];
        $name = $_FILES[$fileKey]['name'][0];
    } else {
        if (empty($_FILES[$fileKey]['name'])) return '';
        $tmp = $_FILES[$fileKey]['tmp_name'];
        $name = $_FILES[$fileKey]['name'];
    }

    if (!$tmp || !is_uploaded_file($tmp)) return '';

    $uploadDir = '../uploads/equipment/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','gif','webp'];
    if (!in_array($ext, $allowed)) return '';
    $fname = uniqid('eq_', true) . '.' . $ext;
    if (move_uploaded_file($tmp, $uploadDir . $fname)) return $fname;
    return '';
}

switch ($action) {

    case 'add': {
        $name = trim($_POST['equipment_name'] ?? '');
        $qty  = (int)($_POST['quantity_in_storage'] ?? 0);
        $desc = trim($_POST['description'] ?? '');
        if (!$name) { echo json_encode(['success'=>false,'message'=>'Name required']); exit; }
        $img = uploadImages();
        $stmt = mysqli_prepare($conn, "INSERT INTO tbl_equipmentList (equipmentName, equipmentStock, equipmentImage, description, createdAt) VALUES (?, ?, ?, ?, NOW())");
        mysqli_stmt_bind_param($stmt, 'siss', $name, $qty, $img, $desc);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        echo json_encode(['success' => $ok]);
        break;
    }

    case 'update': {
        $id   = (int)($_POST['equipmentID'] ?? 0);
        $name = trim($_POST['equipment_name'] ?? '');
        $qty  = (int)($_POST['quantity_in_storage'] ?? 0);
        $desc = trim($_POST['description'] ?? '');
        if (!$id || !$name) { echo json_encode(['success'=>false,'message'=>'Missing fields']); exit; }
        $newImg = uploadImages();
        if (!empty($newImg)) {
            $stmt = mysqli_prepare($conn, "UPDATE tbl_equipmentList SET equipmentName=?, equipmentStock=?, equipmentImage=?, description=?, updatedAt=NOW() WHERE equipmentId=?");
            mysqli_stmt_bind_param($stmt, 'sissi', $name, $qty, $newImg, $desc, $id);
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE tbl_equipmentList SET equipmentName=?, equipmentStock=?, description=?, updatedAt=NOW() WHERE equipmentId=?");
            mysqli_stmt_bind_param($stmt, 'sisi', $name, $qty, $desc, $id);
        }
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        echo json_encode(['success' => $ok]);
        break;
    }

    case 'delete': {
        $id = (int)($_POST['equipmentID'] ?? 0);
        if (!$id) { echo json_encode(['success'=>false,'message'=>'Missing ID']); exit; }
        $stmt = mysqli_prepare($conn, "DELETE FROM tbl_equipmentList WHERE equipmentId=?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        echo json_encode(['success' => $ok]);
        break;
    }

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
}

mysqli_close($conn);