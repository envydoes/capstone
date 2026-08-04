<?php
session_start();
$host = "o7jpqmin0zgconui4xtnfju6";
$dbUser = "root";
$dbPassword = "UKkJ05DHQDMMMOxFEUI5f1HJGVj8Vb5gfJAEvAESTGCVWDtFEGb42qX67AxGUXvj";
$database = "sumeste_db";

$conn = mysqli_connect($host, $dbUser, $dbPassword, $database);
if (!$conn) {
    session_unset();
    session_destroy();
    die("Connection failed: " . mysqli_connect_error());
}