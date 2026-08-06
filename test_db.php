<?php
require_once __DIR__ . '/config/db_connection.php';
if (!$conn) {
    echo 'DB Error: ' . mysqli_connect_error();
} else {
    echo 'DB Connected';
    mysqli_close($conn);
}
?>