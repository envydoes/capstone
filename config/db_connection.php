<?php
session_start();

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$host = getenv('DB_HOST');
$dbUser = getenv('DB_USER');
$dbPassword = getenv('DB_PASSWORD');
$database = getenv('DB_NAME');

$conn = mysqli_connect($host, $dbUser, $dbPassword, $database);
if (!$conn) {
    session_unset();
    session_destroy();
    die("Connection failed: " . mysqli_connect_error());
}