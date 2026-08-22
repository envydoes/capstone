<?php
require_once __DIR__ . '/config/mail_config.php';

$token   = bin2hex(random_bytes(32));
$expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

// Save token to DB
$stmt = $conn->prepare("UPDATE tbl_useracc SET verify_token = ?, token_expires = ?, is_verified = 0 WHERE email = ?");
$stmt->bind_param('sss', $token, $expires, $email);
$stmt->execute();
$stmt->close();

// Send verification email via Brevo
$verifyLink = APP_BASE_URL . "/signup/verify_email.php?token={$token}";

$htmlBody = "
    <div style='font-family:sans-serif;max-width:500px;margin:auto;'>
        <div style='background:#15803d;padding:24px;border-radius:12px 12px 0 0;text-align:center;'>
            <h2 style='color:#fff;margin:0;'>SumEste Portal</h2>
        </div>
        <div style='background:#fff;padding:32px;border:1px solid #d1fae5;'>
            <h3 style='color:#15803d;'>Verify Your Email</h3>
            <p>Click the button below to activate your account.</p>
            <div style='text-align:center;margin:28px 0;'>
                <a href='{$verifyLink}' style='background:#15803d;color:#fff;padding:14px 32px;
                   border-radius:10px;text-decoration:none;font-weight:700;'>
                    Verify My Email
                </a>
            </div>
            <p style='color:#6b7280;font-size:12px;'>Link expires in 24 hours.</p>
        </div>
    </div>
";

$mailError = null;
$sent = sendMail($email, 'Verify Your Email - SumEste Portal', $htmlBody, $mailError);

if (!$sent) {
    error_log('Brevo send failed (finalizeRegistration): ' . $mailError);
}
