<?php
session_start();

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$host = $_ENV['DB_HOST'];
$dbUser = $_ENV['DB_USER'];
$dbPassword = $_ENV['DB_PASSWORD'];
$database = $_ENV['DB_NAME'];

$conn = mysqli_connect($host, $dbUser, $dbPassword, $database);
if (!$conn) {
    session_unset();
    session_destroy();
    die("Connection failed: " . mysqli_connect_error());
}