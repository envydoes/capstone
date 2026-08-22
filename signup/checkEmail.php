<?php
/**
 * check_email.php - AJAX email availability endpoint
 * Lives in: signup/check_email.php
 * Called by: accountCreation.php via fetch()
 */
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

include "../config/db_connection.php";

$rawEmail = trim($_POST['email'] ?? '');

// Validate format before touching the DB
if (empty($rawEmail) || strlen($rawEmail) > 254 || !filter_var($rawEmail, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['exists' => false, 'valid' => false]);
    exit;
}

$stmt = $conn->prepare('SELECT 1 FROM tbl_useracc WHERE email = ? LIMIT 1');
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'DB error']);
    exit;
}
$stmt->bind_param('s', $rawEmail);
$stmt->execute();
$stmt->store_result();
$exists = $stmt->num_rows > 0;
$stmt->close();

echo json_encode(['exists' => $exists, 'valid' => true]);
