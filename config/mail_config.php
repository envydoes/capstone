<?php
// Mail settings for PHPMailer
const MAIL_HOST       = 'smtp.gmail.com';
const MAIL_PORT       = 587;
const MAIL_USERNAME   = 'macapagalpatrickjohn@gmail.com';
const MAIL_PASSWORD   = 'trek fkbu umsu kmgy';
const MAIL_ENCRYPTION = 'tls';

const MAIL_FROM_EMAIL = 'noreply@sumesteportal.com';
const MAIL_FROM_NAME  = 'SumEste Portal';

// Auto-detect base URL (works for localhost, LAN IP, and live domain)
function resolveBaseUrl(): string {
    $host   = $_SERVER['HTTP_HOST'] ?? 'o7jpqmin0zgconui4xtnfju6';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

    // Live domain (not localhost and not a LAN IP)
    $isLocalhost = ($host === 'o7jpqmin0zgconui4xtnfju6' || str_starts_with($host, '127.'));
    $isLanIp     = preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2\d|3[01])\.)/', $host);

    if (!$isLocalhost && !$isLanIp) {
        // Live server — use the actual domain
        return $scheme . '://' . $host;
    }

    // Localhost or LAN — use the current host (IP or localhost) + project folder
    return $scheme . '://' . $host . '/capstone';
}

define('APP_BASE_URL', resolveBaseUrl());