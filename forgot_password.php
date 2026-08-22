<?php
session_start();
require_once __DIR__ . '/config/db_connection.php';
require_once __DIR__ . '/config/mail_config.php';

$notice = null;
$error = null;
$debugResetLink = null;

function sendResetEmail(string $recipientEmail, string $resetLink, ?string $fromEmail = null, ?string &$mailError = null): bool
{
    $htmlBody = '
        <p>Hello,</p>
        <p>We received a request to reset your SumEste Portal password.</p>
        <p><a href="' . htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8') . '">Click here to reset your password</a></p>
        <p>This link expires in 1 hour.</p>
        <p>If you did not request this, you can ignore this email.</p>
    ';

    $sent = sendMail($recipientEmail, 'Reset your SumEste password', $htmlBody, $mailError);

    if (!$sent) {
        error_log('Brevo send failed: ' . $mailError);
    }

    return $sent;
}
    function isPlaceholderMailConfig(): bool
    {
      return (
        !defined('BREVO_API_KEY') ||
        BREVO_API_KEY === '' ||
        BREVO_API_KEY === 'PASTE_YOUR_BREVO_API_KEY_HERE'
      );
    }

    function resolveAppBaseUrl(): string
    {
      $configured = rtrim(APP_BASE_URL, '/');
      if ($configured !== '' && $configured !== 'http://localhost') {
        return $configured;
      }

      $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
      $scheme = $isHttps ? 'https' : 'http';
      $host = $_SERVER['HTTP_HOST'] ?? 'o7jpqmin0zgconui4xtnfju6';

      $scriptDir = str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '/'));
      $scriptDir = rtrim($scriptDir, '/');
      if ($scriptDir === '.' || $scriptDir === '') {
        $scriptDir = '';
      }

      return $scheme . '://' . $host . $scriptDir;
    }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawEmail = trim($_POST['email'] ?? '');

    if (strlen($rawEmail) > 255 || !filter_var($rawEmail, FILTER_VALIDATE_EMAIL)) {
        $notice = 'If an account with that email exists, a reset link has been sent.';
    } else {
        $stmt = $conn->prepare('SELECT accID, email FROM tbl_useracc WHERE email = ? LIMIT 1');
        if (!$stmt) {
            error_log('Forgot password prepare error: ' . $conn->error);
            $error = 'A server error occurred. Please try again later.';
        } else {
            $stmt->bind_param('s', $rawEmail);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();

            if ($user) {
                $selector = bin2hex(random_bytes(8));
                $token = random_bytes(32);
                $tokenHash = hash('sha256', $token);
                $expiresAt = date('Y-m-d H:i:s', time() + 3600);

                $deleteStmt = $conn->prepare('DELETE FROM tbl_password_resets WHERE accID = ?');
                if ($deleteStmt) {
                    $deleteStmt->bind_param('s', $user['accID']);
                    $deleteStmt->execute();
                    $deleteStmt->close();
                }

                $insertStmt = $conn->prepare(
                    'INSERT INTO tbl_password_resets (accID, selector, token_hash, expires_at)
                     VALUES (?, ?, ?, ?)'
                );

                if (!$insertStmt) {
                    error_log('Forgot password insert prepare error: ' . $conn->error);
                    $error = 'A server error occurred. Please try again later.';
                } else {
                    $insertStmt->bind_param('ssss', $user['accID'], $selector, $tokenHash, $expiresAt);

                    if ($insertStmt->execute()) {
                        $tokenParam = bin2hex($token);
                        $baseUrl = rtrim(APP_BASE_URL, '/');
                        $resetLink = $baseUrl . '/reset_password.php?selector=' . urlencode($selector) . '&token=' . urlencode($tokenParam);

                        $mailError = null;
                        // Pass user's email as sender if they're resetting their own account
                        if (!sendResetEmail($user['email'], $resetLink, $user['email'], $mailError)) {
                          $debugResetLink = $resetLink;

                          if (isPlaceholderMailConfig()) {
                            $error = 'SMTP is not configured yet. Update config/mail_config.php with your real email credentials.';
                          } elseif ($mailError !== null) {
                            $error = $mailError;
                          } else {
                            $error = 'Unable to send reset email right now. Please try again later.';
                          }
                        }
                    } else {
                        error_log('Forgot password insert execute error: ' . $insertStmt->error);
                        $error = 'A server error occurred. Please try again later.';
                    }

                    $insertStmt->close();
                }
            }

            if ($error === null) {
                $notice = 'If an account with that email exists, a reset link has been sent.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="assets/responsive-global.css">
  <title>Forgot Password - SumEste Portal</title>
  <link rel="icon" href="assets/logo2.png" type="image/png">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'DM Sans', sans-serif; }
    .hero-bg { background: linear-gradient(135deg, #052e16 0%, #14532d 40%, #166534 75%, #15803d 100%); }
    h1 { font-family: 'Playfair Display', serif; }
  </style>
    <link rel="stylesheet" href="dist/output.css">
</head>
<body class="min-h-screen bg-green-50 flex items-center justify-center p-6">
  <div class="w-full max-w-md bg-white rounded-2xl border border-green-100 shadow-lg overflow-hidden">
    <div class="h-1.5 bg-gradient-to-r from-green-700 to-emerald-400"></div>
    <div class="p-8">
      <p class="text-xs uppercase tracking-widest text-green-700 font-semibold mb-2">Account Recovery</p>
      <h1 class="text-2xl text-green-950 font-bold mb-2">Forgot Password</h1>
      <p class="text-sm text-gray-500 mb-6">Enter your account email and we will send a reset link.</p>
      <?php if ($notice !== null): ?>
      <div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-sm flex items-center gap-2">
      <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="flex-shrink-0">
        <circle cx="12" cy="12" r="10"/>
        <line x1="12" y1="8" x2="12" y2="8" stroke-width="3"/>
        <line x1="12" y1="12" x2="12" y2="16"/>
      </svg>
        <?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?>
      </div>
      <?php endif; ?>

      <?php if ($error !== null): ?>
      <div class="mb-4 p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm">
        <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
      </div>
      <?php endif; ?>

      <?php if ($debugResetLink !== null): ?>
      <div class="mb-4 p-3 bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-lg text-xs break-all">
        <p class="font-semibold mb-1">Local testing fallback (email not sent):</p>
        <a class="underline" href="<?php echo htmlspecialchars($debugResetLink, ENT_QUOTES, 'UTF-8'); ?>">
          <?php echo htmlspecialchars($debugResetLink, ENT_QUOTES, 'UTF-8'); ?>
        </a>
      </div>
      <?php endif; ?>

      <form action="forgot_password.php" method="post" class="space-y-4">
        <div>
          <label for="email" class="block text-xs font-semibold text-gray-600 mb-2 uppercase tracking-wide">Email</label>
          <input
            type="email"
            id="email"
            name="email"
            maxlength="255"
            required
            autocomplete="email"
            placeholder="Enter your email"
            class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-300 focus:border-green-600">
        </div>

        <button type="submit" class="w-full py-3 rounded-lg bg-green-700 text-white font-semibold text-sm hover:bg-green-800 transition">
          <i class="fa-solid fa-paper-plane mr-2"></i>Send Reset Link
        </button>
      </form>

      <a href="login.php" class="mt-6 inline-flex items-center text-xs text-gray-500 hover:text-green-700">
        <i class="fa-solid fa-arrow-left mr-1"></i>Back to login
      </a>
    </div>
  </div>
  <script>
    (function () {
      document.querySelectorAll("a[href]").forEach(function (link) {
        const href = link.getAttribute("href");
        if (!href || link.hasAttribute("data-nav")) return;
        const lower = href.toLowerCase();
        if (href.startsWith("#") || lower.startsWith("javascript:") || lower.startsWith("mailto:") || lower.startsWith("tel:")) return;
        link.setAttribute("data-nav", href);
        link.setAttribute("href", "javascript:void(0)");
        link.addEventListener("click", function (e) {
          e.preventDefault();
          const target = link.getAttribute("data-nav");
          if (!target) return;
          if (link.getAttribute("target") === "_blank") {
            window.open(target, "_blank", "noopener");
          } else {
            window.location.href = target;
          }
        });
      });
    })();
  </script>
</body>
</html>
