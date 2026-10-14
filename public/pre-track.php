<?php
declare(strict_types=1);

/**
 * Tracking identity gate.
 *
 * A customer must confirm their name plus the email OR phone on file for the
 * shipment before the live map is unlocked. Shipments that have no contact
 * details stored are allowed through, flagged as "Unverified".
 *
 * On success the visit is remembered for 30 days with a secure cookie, so the
 * customer only ever confirms once per device.
 */

require_once dirname(__DIR__) . '/config/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$appName = (string) env('APP_NAME', 'Nexora Logistics');
$tracking = nxTrackingFromRequest();
$name  = trim((string) ($_POST['customer_name'] ?? ''));
$email = trim((string) ($_POST['customer_email'] ?? ''));
$phone = trim((string) ($_POST['customer_phone'] ?? ''));
$errors = [];

$shipment = null;
try {
    $pdo = nxPdo();
    if ($tracking !== '') {
        $stmt = $pdo->prepare('SELECT * FROM shipments WHERE tracking_number = :t LIMIT 1');
        $stmt->execute([':t' => $tracking]);
        $shipment = $stmt->fetch() ?: null;
    }
} catch (Throwable $e) {
    $shipment = null;
}

$hasContact = $shipment
    && (trim((string) $shipment['customer_email']) !== '' || trim((string) $shipment['customer_phone']) !== '');

// Already remembered? Go straight through.
if ($tracking !== '' && $shipment && nxTrackerVerified($tracking)) {
    header('Location: /track.php?id=' . rawurlencode($tracking));
    exit;
}

// Pre-fill from an earlier lookup on this device, if any.
if ($name === '' && $tracking !== '' && !empty($_SESSION['nx_verified_lookups'][strtoupper($tracking)]['name'])) {
    $name = (string) $_SESSION['nx_verified_lookups'][strtoupper($tracking)]['name'];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ($tracking === '') {
        $errors[] = 'Tracking number is required.';
    } elseif (!$shipment) {
        $errors[] = 'We could not find a shipment with that tracking number.';
    }
    if ($name === '') {
        $errors[] = 'Your name is required.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email is required.';
    }
    if ($phone === '') {
        $errors[] = 'A phone number is required.';
    }

    if (!$errors && $shipment) {
        $check = nxCheckTrackerIdentity($shipment, $email, $phone);
        if (!$check[0]) {
            $errors[] = $check[3] !== '' ? $check[3] : 'Those details do not match our records.';
        } else {
            try {
                nxRegisterTracker($tracking, $name, $email, $phone, (int) $check[1], (string) $check[2]);
                header('Location: /track.php?id=' . rawurlencode($tracking));
                exit;
            } catch (Throwable $e) {
                $errors[] = 'Could not save your details. Please try again.';
            }
        }
    }
}

function hh(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Confirm your details — <?= hh($appName) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
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
  .pretrack-note { background: rgba(37,99,235,.07); color: #1e40af; padding: .75rem .9rem; border-radius: 9px; font-size: .85rem; margin-bottom: 1rem; }
  .pretrack-back { display: inline-block; margin-top: 1rem; color: #5b6b85; font-size: .85rem; text-decoration: none; }
  .pretrack-back:hover { color: #0b1d3a; }
</style>
</head>
<body style="background: #f7f9fc;">

<div class="pretrack-wrap">
  <h1>Confirm your details</h1>
  <p class="lead">To protect this shipment, please confirm who you are. We only need your email <strong>or</strong> the phone number used when it was booked.</p>

  <?php if (!$shipment && $tracking !== ''): ?>
    <div class="pretrack-err">We could not find a shipment with tracking number <strong><?= hh($tracking) ?></strong>. Check the number and try again.</div>
  <?php endif; ?>

  <?php if ($shipment && !$hasContact): ?>
    <div class="pretrack-note">ℹ️ This shipment has no registered email or phone, so it will open as <strong>Unverified</strong>.</div>
  <?php endif; ?>

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
