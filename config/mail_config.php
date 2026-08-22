<?php
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    if (class_exists('Dotenv\Dotenv')) {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
        $dotenv->safeLoad();
    }
}
// Mail settings for Brevo API (replaces PHPMailer/SMTP)
define('BREVO_API_KEY', getenv('BREVO_API_KEY') ?: '');
const MAIL_FROM_EMAIL = 'noreply@sum-este-portal.digital';
const MAIL_FROM_NAME  = 'SumEste Portal';

function resolveBaseUrl(): string {
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $isLocalhost = ($host === 'localhost' || str_starts_with($host, '127.'));
    $isLanIp     = preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2\d|3[01])\.)/', $host);
    if (!$isLocalhost && !$isLanIp) {
        return $scheme . '://' . $host;
    }
    return $scheme . '://' . $host . '/capstone';
}
define('APP_BASE_URL', resolveBaseUrl());

function sendMail(string $toEmail, string $subject, string $htmlBody, ?string &$error = null): bool {
    $payload = [
        'sender'      => ['name' => MAIL_FROM_NAME, 'email' => MAIL_FROM_EMAIL],
        'to'          => [['email' => $toEmail]],
        'subject'     => $subject,
        'htmlContent' => $htmlBody,
    ];

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL            => 'https://api.brevo.com/v3/smtp/email',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'api-key: ' . BREVO_API_KEY,
            'content-type: application/json',
        ],
    ]);

    $response = curl_exec($curl);
    $curlErr  = curl_error($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($curlErr) {
        $error = "cURL error: $curlErr";
        return false;
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        $error = "Brevo API error (HTTP $httpCode): $response";
        return false;
    }
    return true;
}
