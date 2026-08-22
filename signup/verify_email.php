<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../config/db_connection.php';

$token = trim($_GET['token'] ?? '');

function renderResultPage(string $title, string $message, bool $success = false): void
{
    $accent = $success ? 'bg-green-700' : 'bg-red-700';
    $box    = $success ? 'bg-green-50 border-green-200 text-green-700' : 'bg-red-50 border-red-200 text-red-700';

    echo '<!DOCTYPE html>';
    echo '<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>Email Verification - SumEste Portal</title>';
    echo '    <link rel="stylesheet" href="dist/output.css">
    <script src="https://cdn.tailwindcss.com/3.4.16"></script>
</head><body class="min-h-screen bg-green-50 flex items-center justify-center p-6">';
    echo '<div class="w-full max-w-md bg-white rounded-2xl border border-green-100 shadow-lg overflow-hidden">';
    echo '<div class="h-1.5 ' . $accent . '"></div>';
    echo '<div class="p-7">';
    echo '<h1 class="text-2xl font-bold text-green-950 mb-3">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
    echo '<div class="p-3 border rounded-lg text-sm ' . $box . '">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>';
    echo '<a href="../login.php" class="mt-5 inline-block text-sm text-green-700 hover:underline">Go to Login</a>';
    echo '</div></div></body></html>';
}

if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    renderResultPage('Invalid Verification Link', 'The verification link is invalid. Please request a new verification email.');
    exit;
}

$stmt = $conn->prepare('SELECT accID, token_expires, is_verified FROM tbl_useracc WHERE verify_token = ? LIMIT 1');
if (!$stmt) {
    renderResultPage('Verification Error', 'Unable to verify your account right now. Please try again later.');
    exit;
}

$stmt->bind_param('s', $token);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    renderResultPage('Invalid or Expired Link', 'This verification link is no longer valid. Please request a new one.');
    exit;
}

if ((int)$user['is_verified'] === 1) {
    header('Location: ../login.php?verified=1');
    exit;
}

if (!empty($user['token_expires']) && strtotime((string)$user['token_expires']) < time()) {
    renderResultPage('Verification Link Expired', 'Your verification link has expired. Please request a new verification email from the login page.');
    exit;
}

$accId = (string)$user['accID'];
$update = $conn->prepare('UPDATE tbl_useracc SET is_verified = 1, verify_token = NULL, token_expires = NULL WHERE accID = ? LIMIT 1');
if (!$update) {
    renderResultPage('Verification Error', 'Unable to activate your account right now. Please try again later.');
    exit;
}

$update->bind_param('s', $accId);
$ok = $update->execute();
$update->close();

if (!$ok) {
    renderResultPage('Verification Error', 'We could not activate your account. Please try again later.');
    exit;
}

header('Location: ../login.php?verified=1');
exit;
