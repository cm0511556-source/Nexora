<?php
declare(strict_types=1);

/**
 * Admin password reset using PHP mail() only (no paid SMTP).
 *
 * On hosts without a mail transport (e.g. some Termux setups) mail() returns
 * false. For local use we then show the reset link directly on screen so the
 * admin is never locked out. Set PASSWORD_RESET_SHOW_LINK=false in .env to
 * disable that fallback once real mail delivery works.
 */

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once BASE_PATH . '/app/Database.php';
require_once BASE_PATH . '/app/Auth.php';

use VibrantHub\Auth;
use VibrantHub\Database;

Auth::startSession();

$db = Database::connection();
$message = '';
$error = '';
$showLink = '';
$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$email = trim((string) ($_GET['email'] ?? $_POST['email'] ?? ''));
$mode = $token !== '' ? 'reset' : 'request';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid or expired security token.';
    } else {
        $op = (string) ($_POST['op'] ?? '');

        if ($op === 'request') {
            $email = trim((string) ($_POST['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Enter a valid email address.';
            } else {
                try {
                    $s = $db->prepare('SELECT id, name FROM admins WHERE email=:e AND is_active=1 LIMIT 1');
                    $s->execute([':e' => $email]);
                    $admin = $s->fetch();

                    // Always show the same message to avoid leaking accounts.
                    $message = 'If that email belongs to an administrator, a reset link has been sent.';

                    if ($admin) {
                        $raw = bin2hex(random_bytes(32));
                        $hash = hash('sha256', $raw);
                        $expires = gmdate('Y-m-d H:i:s', time() + 3600);

                        $db->prepare('DELETE FROM password_resets WHERE admin_id=:a OR expires_at < CURRENT_TIMESTAMP')->execute([':a' => (int) $admin['id']]);
                        $db->prepare('INSERT INTO password_resets (admin_id, token_hash, expires_at) VALUES (:a, :h, :x)')
                           ->execute([':a' => (int) $admin['id'], ':h' => $hash, ':x' => $expires]);

                        $link = nxAbsoluteUrl('admin/password-reset.php?token=' . $raw . '&email=' . rawurlencode($email));
                        $body = "Hello " . $admin['name'] . ",\n\n"
                            . "Use the link below to reset your Nexora admin password. It expires in 1 hour.\n\n"
                            . $link . "\n\n"
                            . "If you did not request this, you can ignore this email.\n";

                        $sent = nxSendMail($email, 'Nexora admin password reset', $body);
                        nxAudit('admin.password_reset_request', 'admin', (int) $admin['id'], $email);

                        if (!$sent && (string) env('PASSWORD_RESET_SHOW_LINK', 'true') === 'true') {
                            $showLink = $link;
                        }
                    }
                } catch (Throwable $e) {
                    $error = 'Could not start a reset right now.';
                }
            }
        } elseif ($op === 'reset') {
            $password = (string) ($_POST['password'] ?? '');
            $confirm = (string) ($_POST['password_confirm'] ?? '');

            if (strlen($password) < 8) {
                $error = 'Password must be at least 8 characters.';
            } elseif ($password !== $confirm) {
                $error = 'The two passwords do not match.';
            } else {
                try {
                    $hash = hash('sha256', $token);
                    $s = $db->prepare('SELECT * FROM password_resets WHERE token_hash=:h AND used=0 AND expires_at > CURRENT_TIMESTAMP LIMIT 1');
                    $s->execute([':h' => $hash]);
                    $row = $s->fetch();

                    if (!$row) {
                        $error = 'This reset link is invalid or has expired. Please request a new one.';
                    } else {
                        $db->prepare('UPDATE admins SET password_hash=:p, updated_at=CURRENT_TIMESTAMP WHERE id=:id')
                           ->execute([':p' => password_hash($password, PASSWORD_DEFAULT), ':id' => (int) $row['admin_id']]);
                        $db->prepare('UPDATE password_resets SET used=1 WHERE id=:id')->execute([':id' => (int) $row['id']]);
                        nxAudit('admin.password_reset', 'admin', (int) $row['admin_id']);
                        $message = 'Your password has been updated. You can now sign in.';
                        $mode = 'done';
                    }
                } catch (Throwable $e) {
                    $error = 'Could not reset the password right now.';
                }
            }
        }
    }
}

$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Password Reset — Nexora Admin</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#f4f7fb;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.box{width:100%;max-width:440px;background:#fff;padding:32px;border-radius:18px;box-shadow:0 15px 45px rgba(0,0,0,.08)}
.logo{font-size:26px;font-weight:800;color:#2563eb;margin-bottom:6px}
.sub{color:#667085;margin-bottom:20px}
label{display:block;font-weight:600;margin:14px 0 7px;color:#344054}
input{width:100%;padding:13px;border:1px solid #d0d5dd;border-radius:10px;font-size:15px}
button{width:100%;margin-top:20px;padding:13px;border:0;border-radius:10px;background:#2563eb;color:#fff;font-size:16px;font-weight:700;cursor:pointer}
.ok{background:#ecfdf3;color:#027a48;padding:12px;border-radius:9px;margin-bottom:14px;font-size:.92rem;line-height:1.5;word-break:break-all}
.err{background:#fff1f0;color:#c62828;padding:12px;border-radius:9px;margin-bottom:14px}
a{color:#2563eb}
</style>
</head>
<body>
<div class="box">
  <div class="logo">Nexora Logistics</div>
  <div class="sub">Administrator password reset</div>

  <?php if ($message): ?><div class="ok"><?= htmlspecialchars($message, ENT_QUOTES) ?>
    <?php if ($showLink): ?><br><br><strong>Mail delivery is unavailable on this server.</strong> Use this one-time link instead:<br><a href="<?= htmlspecialchars($showLink, ENT_QUOTES) ?>"><?= htmlspecialchars($showLink, ENT_QUOTES) ?></a><?php endif; ?>
  </div><?php endif; ?>
  <?php if ($error): ?><div class="err"><?= htmlspecialchars($error, ENT_QUOTES) ?></div><?php endif; ?>

  <?php if ($mode === 'done'): ?>
    <p><a href="/admin/login.php">← Back to sign in</a></p>
  <?php elseif ($mode === 'reset'): ?>
    <form method="post" action="/admin/password-reset.php">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
      <input type="hidden" name="op" value="reset">
      <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">
      <input type="hidden" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES) ?>">
      <label>New password</label>
      <input type="password" name="password" minlength="8" required>
      <label>Confirm new password</label>
      <input type="password" name="password_confirm" minlength="8" required>
      <button type="submit">Set new password</button>
    </form>
  <?php else: ?>
    <form method="post" action="/admin/password-reset.php">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
      <input type="hidden" name="op" value="request">
      <label>Admin email address</label>
      <input type="email" name="email" required placeholder="you@example.com">
      <button type="submit">Send reset link</button>
    </form>
    <p style="margin-top:16px;"><a href="/admin/login.php">← Back to sign in</a></p>
  <?php endif; ?>
</div>
</body>
</html>
