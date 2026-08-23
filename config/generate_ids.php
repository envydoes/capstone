<?php
// 1. Include your database connection file
require_once __DIR__ . '/config/db.php'; // Adjust file name if your DB file is connection.php or similar

// Set execution limit higher for bulk processing
set_time_limit(300);

// Number of users to generate
$totalUsers = 100;

// Ensure target uploads folder exists
$uploadDir = __DIR__ . '/uploads/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// 2. Load background template (Upload a blank PhilSys image to assets/template.jpg)
$templatePath = __DIR__ . '/assets/template.jpg';

if (!file_exists($templatePath)) {
    die("Error: Please place a blank ID template at assets/template.jpg first.");
}

$passwordHash = password_hash('password123', PASSWORD_BCRYPT);

echo "Starting image and database generation...<br>";

for ($i = 1; $i <= $totalUsers; $i++) {
    $philsysId = "1234-" . str_pad($i, 4, '0', STR_PAD_LEFT) . "-9012-3456";
    $fileName = "id_card_{$i}.jpg";
    $savePath = $uploadDir . $fileName;

    // Load canvas from template
    $image = imagecreatefromjpeg($templatePath);
    $textColor = imagecolorallocate($image, 20, 20, 20);

    // Write text onto card image using built-in fonts (No extra TTF font required)
    imagestring($image, 5, 40, 70, "PSN: " . $philsysId, $textColor);
    imagestring($image, 4, 180, 110, "RESIDENT USER {$i}", $textColor);
    imagestring($image, 3, 180, 140, "SUMACAB ESTE, CABANATUAN CITY", $textColor);

    // Save final image file to /uploads directory
    imagejpeg($image, $savePath, 85);
    imagedestroy($image);

    // 3. Insert record into database via PDO / mysqli
    $dbPath = "uploads/" . $fileName;
    $name = "Resident User " . $i;
    $email = "resident" . $i . "@sumacab.gov.ph";

    // Assuming standard PDO connection ($pdo variable in config/db.php)
    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, id_image_path, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute([$name, $email, $passwordHash, $dbPath]);

    echo "Generated User {$i} -> {$dbPath}<br>";
}

echo "<strong>Successfully created {$totalUsers} residents with ID images!</strong>";
?>