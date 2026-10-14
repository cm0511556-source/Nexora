<?php
declare(strict_types=1);

session_start();

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$name, $value] = explode('=', $line, 2);
        $_ENV[trim($name)] = trim(trim($value), "\"'");
    }
}
$appName = $_ENV['APP_NAME'] ?? 'Nexora Logistics';

function hh(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES); }

$tracking = trim((string)($_POST['tracking'] ?? $_GET['tracking'] ?? ''));
$name     = trim((string)($_POST['customer_name'] ?? ''));
$email    = trim((string)($_POST['customer_email'] ?? ''));
$phone    = trim((string)($_POST['customer_phone'] ?? ''));
$errors   = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ($tracking === '') $errors[] = 'Tracking number is required.';
    if ($name === '')     $errors[] = 'Your name is required.';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
    if ($phone === '')    $errors[] = 'A phone number is required.';

    if (!$errors) {
        try {
            $dbPath = dirname(__DIR__) . '/database/database.sqlite';
            $pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->prepare('INSERT INTO tracking_lookups (tracking_number, customer_name, customer_email, customer_phone, ip_address, user_agent) VALUES (:t,:n,:e,:p,:ip,:ua)')
                ->execute([
                    ':t' => $tracking,
                    ':n' => $name,
                    ':e' => $email,
                    ':p' => $phone,
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                    ':ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                ]);
            $_SESSION['nx_verified_lookups'][strtoupper($tracking)] = [
                'name' => $name,
                'email' => $email,
                'at' => time(),
            ];
            header('Location: /track.php?tracking=' . urlencode($tracking));
            exit;
        } catch (Throwable $e) {
            $errors[] = 'Could not save your details. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Confirm your details — <?= hh($appName) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/app.css">
<style>
  .pretrack-wrap { max-width: 480px; margin: 3rem auto; padding: 2rem; background: #fff; border-radius: 14px; box-shadow: 0 8px 24px rgba(11,29,58,.10); border: 1px solid #e5e7eb; }
  .pretrack-wrap h1 { color: #0b1d3a; font-size: 1.4rem; margin: 0 0 .35rem; }
  .pretrack-wrap p.lead { color: #5b6b85; font-size: .95rem; margin: 0 0 1.5rem; }
  .pretrack-wrap label { display: block; font-size: .8rem; font-weight: 600; color: #334155; text-transform: uppercase; letter-spacing: .04em; margin: .9rem 0 .35rem; }
  .pretrack-wrap input { width: 100%; padding: .8rem 1rem; border-radius: 10px; border: 1px solid #cbd5e1; font-family: inherit; font-size: .95rem; box-sizing: border-box; }
  .pretrack-wrap input:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.15); }
  .pretrack-wrap button { width: 100%; padding: .95rem; border-radius: 10px; border: 0; background: linear-gradient(135deg, #f0b429, #ffd166); color: #0b1d3a; font-weight: 700; font-size: 1rem; cursor: pointer; margin-top: 1.25rem; font-family: inherit; }
  .pretrack-wrap button:hover { transform: translateY(-1px); }
  .pretrack-err { background: rgba(220,38,38,.08); color: #991b1b; padding: .85rem 1rem; border-radius: 10px; font-size: .9rem; margin-bottom: 1rem; }
  .pretrack-back { display: inline-block; margin-top: 1rem; color: #5b6b85; font-size: .85rem; text-decoration: none; }
  .pretrack-back:hover { color: #0b1d3a; }
</style>
</head>
<body style="background: #f7f9fc;">

<div class="pretrack-wrap">
  <h1>Confirm your details</h1>
  <p class="lead">So we can keep you updated on this shipment, please tell us who you are.</p>

  <?php if (!empty($errors)): ?>
    <div class="pretrack-err">
      <?php foreach ($errors as $e): ?>• <?= hh($e) ?><br><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="post" action="/pre-track.php">
    <input type="hidden" name="tracking" value="<?= hh($tracking) ?>">

    <label>Tracking number</label>
    <input type="text" value="<?= hh($tracking) ?>" readonly style="background:#f8fafc;color:#64748b;">

    <label>Your name *</label>
    <input type="text" name="customer_name" required value="<?= hh($name) ?>" placeholder="e.g. Jane Doe">

    <label>Email *</label>
    <input type="email" name="customer_email" required value="<?= hh($email) ?>" placeholder="you@example.com">

    <label>Phone *</label>
    <input type="tel" name="customer_phone" required value="<?= hh($phone) ?>" placeholder="+1 555 123 4567">

    <button type="submit">Continue to tracking</button>
  </form>

  <a href="/" class="pretrack-back">← Back to home</a>
</div>

</body>
</html>
