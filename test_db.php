<?php
$conn = mysqli_connect('o7jpqmin0zgconui4xtnfju6', 'root', '''', 'sumeste_db');
if (!$conn) {
    echo 'DB Error: ' . mysqli_connect_error();
} else {
    echo 'DB Connected';
    mysqli_close($conn);
}
?>