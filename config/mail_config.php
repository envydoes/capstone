<?php
// Mail settings for PHPMailer
const MAIL_HOST       = 'smtp.gmail.com';
const MAIL_PORT       = 587;
define('MAIL_USERNAME', getenv('MAIL_USERNAME') ?: '');
define('MAIL_PASSWORD', getenv('MAIL_PASSWORD') ?: '');
const MAIL_ENCRYPTION = 'tls';
const MAIL_FROM_EMAIL = 'noreply@sumesteportal.com';
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