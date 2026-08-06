<?php
session_start();
if (!isset($_SESSION['user_id'])) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

require_once __DIR__ . '/../config/db_connection.php';

// Was hardcoded to account_role === 'admin' only, which blocked any staff
// account granted manage_listings from deleting listings �?" even though
// communityListings.php already shows them the delete buttons based on
// that same permission. Now this endpoint checks the same permission.
require_once __DIR__ . '/../includes/check_permissions.php';
require_permission_ajax($conn, 'manage_listings');

header('Content-Type: application/json');

// Support both JSON body and GET param
$ids = [];

// JSON body (fetch call)
$body = file_get_contents('php://input');
if ($body) {
    $data = json_decode($body, true);
    if (isset($data['ids']) && is_array($data['ids'])) {
        $ids = array_map('intval', $data['ids']);
    }
}

// GET/POST fallback
if (empty($ids)) {
    $idsRaw = $_GET['ids'] ?? $_POST['ids'] ?? '';
    if ($idsRaw) {
        $ids = array_map('intval', explode(',', $idsRaw));
    }
    if (isset($_POST['id'])) $ids[] = (int)$_POST['id'];
}

$ids = array_filter($ids, fn($v) => $v > 0);
$ids = array_values(array_unique($ids));

if (empty($ids)) {
    echo json_encode(['success' => false, 'message' => 'No IDs provided']);
    exit;
}

$deleted = 0;
$errors  = [];

foreach ($ids as $id) {
    // Get photos before deleting
    $stmt = $conn->prepare("SELECT photos FROM tbl_busaptlisting WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        // Delete record
        $del = $conn->prepare("DELETE FROM tbl_busaptlisting WHERE id = ?");
        $del->bind_param('i', $id);
        if ($del->execute()) {
            $deleted++;
            // Optionally remove photo files
            $photos = json_decode($row['photos'] ?? '[]', true) ?: [];
            foreach ($photos as $photo) {
                $path = __DIR__ . '/../uploads/listings/' . basename($photo);
                if (file_exists($path)) @unlink($path);
            }
        } else {
            $errors[] = $id;
        }
        $del->close();
    }
}

mysqli_close($conn);

// Redirect fallback (for non-fetch requests)
if (isset($_GET['redirect'])) {
    header('Location: ' . $_GET['redirect'] . '?deleted=1');
    exit;
}

if ($deleted > 0) {
    echo json_encode(['success' => true, 'deleted' => $deleted]);
} else {
    echo json_encode(['success' => false, 'message' => 'Could not delete the listing(s).', 'errors' => $errors]);
}