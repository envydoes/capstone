<?php
include 'config/db_connection.php';
$stmt = $conn->query("SELECT announcementImg FROM tbl_announcement LIMIT 5");
while($row = $stmt->fetch_assoc()) {
    echo "PATH: " . $row['announcementImg'] . "\n";
}
?>