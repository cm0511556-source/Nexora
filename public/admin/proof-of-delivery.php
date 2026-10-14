<?php
declare(strict_types=1);

/**
 * Proof of delivery: upload a delivery photo and capture a recipient signature.
 * The customer tracking page then shows it in the "Proof of delivery" card.
 */

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once BASE_PATH . '/app/Database.php';
require_once BASE_PATH . '/app/Auth.php';

use VibrantHub\Auth;
use VibrantHub\Database;

Auth::requireAdmin();

$db = Database::connection();
$message = '';
$error = '';

$mediaDir = dirname(__DIR__) . '/assets/media';
$sigDir = dirname(__DIR__) . '/assets/signatures';
if (!is_dir($mediaDir)) @mkdir($mediaDir, 0755, true);
if (!is_dir($sigDir)) @mkdir($sigDir, 0755, true);

// Resolve shipment by numeric id or tracking number.
$idRaw = (string) ($_GET['id'] ?? $_POST['shipment_id'] ?? '');
$shipment = null;
if ($idRaw !== '') {
    if (ctype_digit($idRaw)) {
        $s = $db->prepare('SELECT * FROM shipments WHERE id=:id LIMIT 1');
        $s->execute([':id' => (int) $idRaw]);
    } else {
        $s = $db->prepare('SELECT * FROM shipments WHERE tracking_number=:t LIMIT 1');
        $s->execute([':t' => $idRaw]);
    }
    $shipment = $s->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid or expired security token.';
    } elseif (!$shipment) {
        $error = 'Shipment not found.';
    } else {
        try {
            $sid = (int) $shipment['id'];
            $signedBy = trim((string) ($_POST['signed_by'] ?? ''));
            $note = trim((string) ($_POST['note'] ?? ''));
            $deliveredAt = trim((string) ($_POST['delivered_at'] ?? ''));
            $markDelivered = isset($_POST['mark_delivered']);

            $existing = $db->prepare('SELECT * FROM proof_of_delivery WHERE shipment_id=:s LIMIT 1');
            $existing->execute([':s' => $sid]);
            $proof = $existing->fetch() ?: null;

            $photoPath = $proof['photo_path'] ?? null;
            $signaturePath = $proof['signature_path'] ?? null;

            // Photo upload
            if (!empty($_FILES['photo']['name']) && ($_FILES['photo']['error'] ?? 4) === UPLOAD_ERR_OK) {
                $f = $_FILES['photo'];
                if ($f['size'] > 5 * 1024 * 1024) {
                    $error = 'Photo exceeds 5 MB.';
                } else {
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->file($f['tmp_name']);
                    $extMap = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
                    if (!isset($extMap[$mime])) {
                        $error = 'Photo must be PNG, JPG or WEBP.';
                    } else {
                        $name = 'pod-' . $sid . '-' . bin2hex(random_bytes(5)) . '.' . $extMap[$mime];
                        if (@move_uploaded_file($f['tmp_name'], $mediaDir . '/' . $name)) {
                            $photoPath = $name;
                        } else {
                            $error = 'Could not save the uploaded photo.';
                        }
                    }
                }
            }

            // Signature (base64 PNG from the canvas)
            $sigData = (string) ($_POST['signature_data'] ?? '');
            if (!$error && $sigData !== '' && strpos($sigData, 'data:image/png;base64,') === 0) {
                $binary = base64_decode(substr($sigData, strlen('data:image/png;base64,')), true);
                if ($binary !== false && strlen($binary) > 100 && strlen($binary) < 2 * 1024 * 1024) {
                    $sigName = 'sig-' . $sid . '-' . bin2hex(random_bytes(5)) . '.png';
                    if (@file_put_contents($sigDir . '/' . $sigName, $binary) !== false) {
                        $signaturePath = $sigName;
                    }
                }
            }

            if (!$error) {
                $deliveredValue = $deliveredAt !== '' ? $deliveredAt : gmdate('Y-m-d H:i:s');

                if ($proof) {
                    $db->prepare('UPDATE proof_of_delivery SET photo_path=:p, signature_path=:sg, signed_by=:b, note=:n, delivered_at=:d, updated_at=CURRENT_TIMESTAMP WHERE shipment_id=:s')
                       ->execute([':p' => $photoPath, ':sg' => $signaturePath, ':b' => $signedBy ?: null, ':n' => $note ?: null, ':d' => $deliveredValue, ':s' => $sid]);
                } else {
                    $db->prepare('INSERT INTO proof_of_delivery (shipment_id, photo_path, signature_path, signed_by, note, delivered_at, created_by) VALUES (:s, :p, :sg, :b, :n, :d, :cb)')
                       ->execute([':s' => $sid, ':p' => $photoPath, ':sg' => $signaturePath, ':b' => $signedBy ?: null, ':n' => $note ?: null, ':d' => $deliveredValue, ':cb' => (int) ($_SESSION['admin_id'] ?? 0)]);
                }

                if ($markDelivered) {
                    $db->prepare("UPDATE shipments SET status='delivered', speed=0, distance_remaining=0, distance_travelled=distance_total, eta=NULL, last_update=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=:id")->execute([':id' => $sid]);
                    $db->prepare('UPDATE tracking_controls SET is_running=0, updated_at=CURRENT_TIMESTAMP WHERE shipment_id=:s')->execute([':s' => $sid]);
                    $db->prepare("INSERT INTO tracking_events (shipment_id, status, title, description, location) VALUES (:s, 'delivered', 'Proof of delivery recorded', 'A signed proof of delivery was uploaded.', :loc)")
                       ->execute([':s' => $sid, ':loc' => $shipment['destination']]);
                }

                nxAudit('proof.save', 'shipment', $sid, $shipment['tracking_number']);
                $message = 'Proof of delivery saved.';

                $existing->execute([':s' => $sid]);
                $proof = $existing->fetch() ?: null;
            }
        } catch (Throwable $e) {
            $error = 'Could not save proof: ' . $e->getMessage();
        }
    }
}

$proof = null;
if ($shipment) {
    $p = $db->prepare('SELECT * FROM proof_of_delivery WHERE shipment_id=:s LIMIT 1');
    $p->execute([':s' => (int) $shipment['id']]);
    $proof = $p->fetch() ?: null;
}

$csrf = csrfToken();
function hh(?string $v): string { return htmlspecialchars((string) $v, ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Proof of Delivery — Nexora Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
<style>
  body { background:#f1f5f9; }
  .wrap { max-width: 900px; margin: 0 auto; padding: 1rem; }
  .panel { background:#fff; border-radius:14px; padding:1.5rem; box-shadow:0 8px 24px rgba(11,29,58,.08); margin-bottom:1.5rem; }
  label { display:block; font-size:.8rem; color:#475569; font-weight:600; margin-bottom:.35rem; text-transform:uppercase; letter-spacing:.04em; }
  input[type=text],input[type=datetime-local],textarea { width:100%; padding:.65rem .85rem; border:1px solid #cbd5e1; border-radius:9px; font-family:inherit; font-size:.95rem; box-sizing:border-box; }
  textarea { min-height:70px; }
  .actions { margin-top:1rem; display:flex; gap:.6rem; flex-wrap:wrap; }
  .msg-ok{ padding:.75rem 1rem; background:rgba(22,163,74,.1); color:#166534; border-radius:10px; margin-bottom:1rem; }
  .msg-err{ padding:.75rem 1rem; background:rgba(220,38,38,.1); color:#991b1b; border-radius:10px; margin-bottom:1rem; }
  #sigpad { border:2px dashed #cbd5e1; border-radius:12px; background:#f8fafc; touch-action:none; width:100%; max-width:520px; height:180px; display:block; }
  .existing { display:flex; gap:1rem; flex-wrap:wrap; }
  .existing img { max-width:260px; border-radius:10px; border:1px solid #e2e8f0; background:#fff; }
</style>
    <script src="<?= asset('js/nx-labels.js') ?>"></script>
</head>
<body>
<header class="navbar">
  <a href="/admin/index.php" class="logo" style="text-decoration:none;color:inherit;"><span class="logo-badge">N</span><span class="logo-text">Nexora Admin</span></a>
  <nav class="nav-links">
    <a href="/admin/index.php">Dashboard</a>
    <a href="/admin/shipments-hub.php" class="active">Shipments</a>
    <a href="/admin/people.php">People</a>
    <a href="/admin/content.php">Content</a>
    <a href="/admin/settings.php">Settings</a>
    <a href="/" target="_blank" rel="noopener" style="color:#ffd166 !important;">🌐 View Site</a>
    <a href="/admin/login.php?logout=1">Logout</a>
  </nav>
</header>

<div class="wrap">
  <h1 style="margin:1rem 0;">Proof of Delivery</h1>

  <?php if (!$shipment): ?>
    <div class="panel"><p>Shipment not found. Open this page from a shipment row: <code>proof-of-delivery.php?id=SHIPMENT_ID</code>.</p></div>
  <?php else: ?>
    <p style="color:#475569;margin-top:0;">Tracking <strong><?= hh($shipment['tracking_number']) ?></strong> · <?= hh($shipment['origin']) ?> → <?= hh($shipment['destination']) ?></p>

    <?php if ($message): ?><div class="msg-ok"><?= hh($message) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="msg-err"><?= hh($error) ?></div><?php endif; ?>

    <?php if ($proof): ?>
    <div class="panel">
      <h2 style="margin-top:0;">Saved proof</h2>
      <div class="existing">
        <?php if (!empty($proof['photo_path'])): ?><img src="<?= hh(asset('media/' . rawurlencode($proof['photo_path']))) ?>" alt="Delivery photo"><?php endif; ?>
        <?php if (!empty($proof['signature_path'])): ?><img src="<?= hh(asset('signatures/' . rawurlencode($proof['signature_path']))) ?>" alt="Signature" style="background:#fff;"><?php endif; ?>
      </div>
      <p style="font-size:.9rem;color:#475569;">
        <?= $proof['signed_by'] ? 'Signed by <strong>' . hh($proof['signed_by']) . '</strong>. ' : '' ?>
        <?= $proof['delivered_at'] ? 'Delivered ' . hh($proof['delivered_at']) . ' UTC.' : '' ?>
      </p>
    </div>
    <?php endif; ?>

    <div class="panel">
      <h2 style="margin-top:0;">Record / update proof</h2>
      <form method="post" enctype="multipart/form-data" action="/admin/proof-of-delivery.php">
        <input type="hidden" name="csrf_token" value="<?= hh($csrf) ?>">
        <input type="hidden" name="shipment_id" value="<?= (int) $shipment['id'] ?>">
        <input type="hidden" name="signature_data" id="signature_data" value="">

        <div style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));">
          <div><label>Delivery photo (≤5 MB)</label><input type="file" name="photo" accept="image/png,image/jpeg,image/webp"></div>
          <div><label>Signed by</label><input type="text" name="signed_by" value="<?= hh($proof['signed_by'] ?? '') ?>" placeholder="Recipient name"></div>
          <div><label>Delivered at (UTC)</label><input type="datetime-local" name="delivered_at" value="<?= hh(substr((string) ($proof['delivered_at'] ?? ''), 0, 16)) ?>"></div>
        </div>

        <div style="margin-top:1rem;">
          <label>Recipient signature</label>
          <canvas id="sigpad" width="520" height="180"></canvas>
          <button type="button" class="btn btn-outline" id="sig-clear" style="margin-top:.5rem;color:#0f172a;border-color:#cbd5e1;">Clear signature</button>
        </div>

        <div style="margin-top:1rem;"><label>Note (optional)</label><textarea name="note" placeholder="e.g. Left with front desk"><?= hh($proof['note'] ?? '') ?></textarea></div>

        <label style="display:flex;align-items:center;gap:.5rem;margin-top:1rem;text-transform:none;font-weight:500;font-size:.9rem;">
          <input type="checkbox" name="mark_delivered" value="1"> Mark shipment as <strong>Delivered</strong> and stop movement
        </label>

        <div class="actions">
          <button type="submit" class="btn btn-primary">Save proof of delivery</button>
          <a class="btn btn-outline" href="/track.php?id=<?= hh(rawurlencode($shipment['tracking_number'])) ?>" target="_blank" rel="noopener" style="color:#0f172a;border-color:#cbd5e1;">👁️ View as Customer</a>
        </div>
      </form>
    </div>
  <?php endif; ?>
</div>

<script>
(function(){
  var canvas = document.getElementById('sigpad');
  if (!canvas) return;
  var ctx = canvas.getContext('2d');
  var drawing = false, hasInk = false;
  ctx.lineWidth = 2.2;
  ctx.lineCap = 'round';
  ctx.strokeStyle = '#0b1d3a';

  function pos(e){
    var r = canvas.getBoundingClientRect();
    var t = e.touches ? e.touches[0] : e;
    return { x: (t.clientX - r.left) * (canvas.width / r.width), y: (t.clientY - r.top) * (canvas.height / r.height) };
  }
  function start(e){ e.preventDefault(); drawing = true; hasInk = true; var p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); }
  function move(e){ if(!drawing) return; e.preventDefault(); var p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); }
  function end(e){ if(!drawing) return; e.preventDefault(); drawing = false; sync(); }
  function sync(){ document.getElementById('signature_data').value = hasInk ? canvas.toDataURL('image/png') : ''; }

  canvas.addEventListener('mousedown', start);
  canvas.addEventListener('mousemove', move);
  window.addEventListener('mouseup', end);
  canvas.addEventListener('touchstart', start, { passive:false });
  canvas.addEventListener('touchmove', move, { passive:false });
  canvas.addEventListener('touchend', end);

  var clear = document.getElementById('sig-clear');
  if (clear) clear.addEventListener('click', function(){ ctx.clearRect(0,0,canvas.width,canvas.height); hasInk=false; sync(); });
})();
</script>
</body>
</html>
