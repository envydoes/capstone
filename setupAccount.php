<?php
require_once __DIR__ . '/config/db_connection.php';
require_once __DIR__ . '/includes/site_config.php';
$siteSettings = site_config_load($conn);

$selector = $_GET['selector'] ?? '';
$token    = $_GET['token'] ?? '';
$error    = '';
$success  = false;
$accID    = null;

if ($selector === '' || $token === '') {
    $error = 'This setup link is invalid.';
} else {
    $stmt = $conn->prepare('SELECT id, accID, token_hash, expires_at, used_at FROM tbl_password_resets WHERE selector = ? LIMIT 1');
    $stmt->bind_param('s', $selector);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $error = 'This setup link is invalid or has already been used.';
    } elseif ($row['used_at'] !== null) {
        $error = 'This setup link has already been used.';
    } elseif (strtotime($row['expires_at']) < time()) {
        $error = 'This setup link has expired. Ask your admin to resend it.';
    } elseif (!hash_equals($row['token_hash'], hash('sha256', $token))) {
        $error = 'This setup link is invalid.';
    } else {
        $accID = $row['accID'];
    }
}

if ($accID && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pw1   = $_POST['password'] ?? '';
    $pw2   = $_POST['password_confirm'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($pw1) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($pw1 !== $pw2) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($pw1, PASSWORD_DEFAULT);

        // Update password and email in tbl_useracc
        $upd = $conn->prepare('UPDATE tbl_useracc SET password = ?, email = ? WHERE accID = ?');
        $upd->bind_param('sss', $hash, $email, $accID);
        $upd->execute();
        $upd->close();

        // Also update tbl_userinfo email to keep records in sync
        $updInfo = $conn->prepare('UPDATE tbl_userinfo SET email = ? WHERE accID = ?');
        $updInfo->bind_param('ss', $email, $accID);
        $updInfo->execute();
        $updInfo->close();

        // Mark reset token as used
        $mark = $conn->prepare('UPDATE tbl_password_resets SET used_at = NOW() WHERE selector = ?');
        $mark->bind_param('s', $selector);
        $mark->execute();
        $mark->close();

        // Activate staff permissions if applicable
        $activate = $conn->prepare("UPDATE tbl_admin_permissions SET status = 'active', confirmed_at = NOW() WHERE accID = ? AND status = 'pending'");
        $activate->bind_param('s', $accID);
        $activate->execute();
        $staffActivated = $activate->affected_rows > 0;
        $activate->close();

        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Set Up Your Account - <?= e($siteSettings['site_title']) ?></title>
<link rel="icon" href="<?= e(site_config_logo_url($siteSettings, '')) ?>" type="image/png">
<script src="https://cdn.tailwindcss.com/3.4.16"></script>
<?= site_config_css_vars($siteSettings) ?>
<style>
  body { font-family: 'DM Sans', sans-serif; background: var(--site-primary-pale); min-height: 100vh; display: flex; align-items: center; justify-content: center; margin: 0; }
  .card { background: #fff; border-radius: 16px; padding: 32px; max-width: 420px; width: 100%; box-shadow: 0 10px 30px rgba(0,0,0,0.08); }
  .field { width: 100%; padding: 11px 14px; border: 1.5px solid #e5e7eb; border-radius: 10px; font-size: 0.9rem; margin-top: 6px; margin-bottom: 16px; outline: none; }
  .field:focus { border-color: var(--site-primary); }
  .btn { width: 100%; padding: 12px; background: var(--site-primary); color: #fff; border: none; border-radius: 10px; font-weight: 700; cursor: pointer; }
  .btn:hover { filter: brightness(0.9); }
  .alert { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; padding: 12px 14px; border-radius: 10px; font-size: 0.85rem; margin-bottom: 16px; }
  .success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; padding: 12px 14px; border-radius: 10px; font-size: 0.85rem; margin-bottom: 16px; }
</style>
</head>
<body>
  <div class="card">
    <h2 style="font-size:1.3rem;font-weight:800;color:#111827;margin-bottom:4px;">Set Up Your Account</h2>
    <p style="font-size:0.85rem;color:#6b7280;margin-bottom:20px;">for <?= e($siteSettings['site_title']) ?></p>

    <?php if ($success): ?>
      <div class="success">
        Account updated. You can now log in with your email and password.
        <?php if (!empty($staffActivated)): ?><br><br><strong>Your staff access is now active.</strong><?php endif; ?>
      </div>
      <a href="login.php" class="btn" style="display:block;text-align:center;text-decoration:none;">Go to Login</a>
    <?php elseif ($accID): ?>
      <?php if ($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>
      <form method="POST">
        <label style="font-size:0.8rem;font-weight:700;color:#374151;">Email Address</label>
        <input type="email" name="email" class="field" value="<?= e($_POST['email'] ?? '') ?>" required>

        <label style="font-size:0.8rem;font-weight:700;color:#374151;">New Password</label>
        <input type="password" name="password" class="field" minlength="8" required>

        <label style="font-size:0.8rem;font-weight:700;color:#374151;">Confirm Password</label>
        <input type="password" name="password_confirm" class="field" minlength="8" required>

        <button type="submit" class="btn">Set Up Account</button>
      </form>
    <?php else: ?>
      <div class="alert"><?= e($error) ?></div>
      <a href="login.php" style="color:var(--site-primary);font-weight:700;font-size:0.85rem;">? Back to Login</a>
    <?php endif; ?>
  </div>
</body>
</html>