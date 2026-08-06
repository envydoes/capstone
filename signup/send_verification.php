<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/mail_config.php';

/**
 * Builds the absolute verification URL for a token.
 */
function buildVerificationLink(string $token): string
{
    $base = rtrim(APP_BASE_URL, '/');
    return $base . '/signup/verify_email.php?token=' . urlencode($token);
}

/**
 * Sends the verification email using PHPMailer.
 */
function sendVerificationEmail(string $recipientEmail, string $token, ?string &$mailError = null): bool
{
    $verificationLink = buildVerificationLink($token);

    try {
        if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
            $mailError = 'Mailer dependency is missing (vendor/autoload.php not found).';
            return false;
        }

        require_once __DIR__ . '/../vendor/autoload.php';

        $mailClass = 'PHPMailer\\PHPMailer\\PHPMailer';
        $mail = new $mailClass(true);

        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = MAIL_ENCRYPTION;
        $mail->Port       = MAIL_PORT;

        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->addAddress($recipientEmail);

        $mail->isHTML(true);
        $mail->Subject = 'Verify Your Email - SumEste Portal';
        $mail->Body = '
            <div style="margin:0;padding:24px;background:#f0fdf4;font-family:Arial,sans-serif;color:#14532d;">
              <div style="max-width:620px;margin:0 auto;background:#ffffff;border:1px solid #dcfce7;border-radius:14px;overflow:hidden;">
                <div style="background:#15803d;padding:18px 22px;">
                  <h2 style="margin:0;font-size:20px;color:#ffffff;">SumEste Portal</h2>
                  <p style="margin:6px 0 0 0;font-size:13px;color:#dcfce7;">Email Verification Required</p>
                </div>
                <div style="padding:24px 22px;">
                  <p style="margin:0 0 14px 0;font-size:14px;line-height:1.6;">Hello,</p>
                  <p style="margin:0 0 14px 0;font-size:14px;line-height:1.6;">Thank you for registering at <strong>SumEste Portal</strong>. Please verify your email address to activate your account.</p>
                  <p style="margin:22px 0;">
                    <a href="' . htmlspecialchars($verificationLink, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:#15803d;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:600;">Verify Email</a>
                  </p>
                  <p style="margin:0 0 10px 0;font-size:13px;color:#166534;line-height:1.6;">If the button does not work, copy and paste this link into your browser:</p>
                  <p style="margin:0;font-size:12px;word-break:break-all;color:#15803d;">' . htmlspecialchars($verificationLink, ENT_QUOTES, 'UTF-8') . '</p>
                  <p style="margin:18px 0 0 0;font-size:12px;color:#4b5563;line-height:1.6;">This verification link expires in 24 hours.</p>
                </div>
              </div>
            </div>
        ';
        $mail->AltBody = "Verify your SumEste account: {$verificationLink} (valid for 24 hours).";

        return $mail->send();
    } catch (Throwable $e) {
        $mailError = 'Verification email failed: ' . $e->getMessage();
        error_log($mailError);
        return false;
    }
}

/**
 * Creates a new verify token in DB and sends verification email.
 */
function dispatchVerificationEmail(mysqli $conn, string $email, ?string &$error = null): bool
{
    try {
        $token = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $error = 'Unable to generate verification token.';
        error_log($error . ' ' . $e->getMessage());
        return false;
    }

    $expiresAt = date('Y-m-d H:i:s', time() + 86400);
    $update = $conn->prepare('UPDATE tbl_useracc SET verify_token = ?, token_expires = ?, is_verified = 0 WHERE email = ? LIMIT 1');
    if (!$update) {
        $error = 'Unable to prepare token update.';
        error_log($error . ' ' . $conn->error);
        return false;
    }

    $update->bind_param('sss', $token, $expiresAt, $email);
    $ok = $update->execute();
    $update->close();

    if (!$ok) {
        $error = 'Unable to store verification token.';
        error_log($error);
        return false;
    }

    $mailError = null;
    if (!sendVerificationEmail($email, $token, $mailError)) {
        $error = $mailError ?? 'Unable to send verification email.';
        return false;
    }

    return true;
}
