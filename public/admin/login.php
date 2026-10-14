<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . "/config/config.php";
require_once BASE_PATH . "/app/Database.php";
require_once BASE_PATH . "/app/Auth.php";

use VibrantHub\Auth;

// Logout handler
if (isset($_GET['logout'])) {
    Auth::logout();
    header('Location: /admin/login.php');
    exit;
}

Auth::startSession();

if (Auth::check()) {
    header("Location: /admin/index.php");
    exit;
}

$error = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim((string) ($_POST["email"] ?? ""));
    $password = (string) ($_POST["password"] ?? "");

    if (!verifyCsrf($_POST["csrf_token"] ?? null)) {
        $error = "Invalid security token. Please try again.";
    } elseif (Auth::login($email, $password)) {
        nxAudit('admin.login', 'admin', (int) ($_SESSION['admin_id'] ?? 0), $email);
        header("Location: /admin/index.php");
        exit;
    } else {
        $error = "Invalid email or password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Nexora Logistics Admin Login</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:Arial,sans-serif;background:#f4f7fb;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.login-box{width:100%;max-width:420px;background:#fff;padding:32px;border-radius:18px;box-shadow:0 15px 45px rgba(0,0,0,.08)}
.logo{font-size:28px;font-weight:800;color:#2563eb;margin-bottom:8px}
.subtitle{color:#667085;margin-bottom:25px}
label{display:block;font-weight:600;margin:15px 0 7px;color:#344054}
input{width:100%;padding:14px;border:1px solid #d0d5dd;border-radius:10px;font-size:15px}
button{width:100%;margin-top:22px;padding:14px;border:0;border-radius:10px;background:#2563eb;color:#fff;font-size:16px;font-weight:700}
.error{background:#fff1f0;color:#c62828;padding:12px;border-radius:9px;margin-bottom:15px}
.footer{text-align:center;color:#98a2b3;font-size:13px;margin-top:20px}
</style>
</head>
<body>
<div class="login-box">
<div class="logo">Nexora Logistics</div>
<div class="subtitle">Administration Dashboard</div>
<?php if ($error): ?>
<div class="error"><?= e($error) ?></div>
<?php endif; ?>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
<label>Email address</label>
<input type="email" name="email" placeholder="admin@vibranthub.com" required>
<label>Password</label>
<input type="password" name="password" placeholder="Enter your password" required>
<button type="submit">Sign In</button>
</form>
<p style="text-align:center;margin-top:14px;"><a href="/admin/password-reset.php" style="color:#2563eb;font-size:14px;">Forgot your password?</a></p>
<div class="footer">Nexora Logistics Management</div>
</div>
</body>
</html>
