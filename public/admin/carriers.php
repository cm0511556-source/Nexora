<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once BASE_PATH . '/app/Database.php';
require_once BASE_PATH . '/app/Auth.php';

use VibrantHub\Auth;
use VibrantHub\Database;

Auth::requireAdmin();

$db = Database::connection();
$message = '';
$error = '';

$uploadDir = dirname(__DIR__) . '/assets/carriers';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

const MAX_LOGO_BYTES = 524288; // 512 KB
const ALLOWED_MIMES = ['image/png','image/jpeg','image/webp','image/svg+xml'];

function handleLogoUpload(int $carrierId): array {
    if (empty($_FILES['logo_file']) || ($_FILES['logo_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    $f = $_FILES['logo_file'];
    if ($f['error'] !== UPLOAD_ERR_OK) return [null, 'Upload error code ' . $f['error']];
    if ($f['size'] > MAX_LOGO_BYTES) return [null, 'Logo exceeds 512 KB.'];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($f['tmp_name']);
    if (!in_array($mime, ALLOWED_MIMES, true)) return [null, 'Only PNG, JPG, WEBP, or SVG allowed.'];

    $ext = match($mime) {
        'image/png' => 'png', 'image/jpeg' => 'jpg',
        'image/webp' => 'webp', 'image/svg+xml' => 'svg',
        default => 'bin'
    };
    $name = 'carrier-' . $carrierId . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = dirname(__DIR__) . '/assets/carriers/' . $name;
    if (!move_uploaded_file($f['tmp_name'], $dest)) return [null, 'Could not save uploaded logo.'];
    return [$name, null];
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid or expired security token.';
    } else {
        $op = $_POST['op'] ?? '';
        try {
            if ($op === 'save') {
                $id      = (int)($_POST['id'] ?? 0);
                $name    = trim((string)($_POST['name'] ?? ''));
                $code    = trim((string)($_POST['code'] ?? ''));
                $email   = trim((string)($_POST['contact_email'] ?? ''));
                $phone   = trim((string)($_POST['contact_phone'] ?? ''));
                $track   = trim((string)($_POST['tracking_url'] ?? ''));
                $logoUrl = trim((string)($_POST['logo_url'] ?? ''));
                $active  = isset($_POST['is_active']) ? 1 : 0;

                if ($name === '') { $error = 'Name is required.'; }
                else {
                    if ($id > 0) {
                        $db->prepare('UPDATE carriers SET name=:n, code=:c, contact_email=:e, contact_phone=:p, tracking_url=:t, logo_url=:lu, is_active=:a, updated_at=CURRENT_TIMESTAMP WHERE id=:id')
                           ->execute([':n'=>$name, ':c'=>$code ?: null, ':e'=>$email ?: null, ':p'=>$phone ?: null, ':t'=>$track ?: null, ':lu'=>$logoUrl ?: null, ':a'=>$active, ':id'=>$id]);
                    } else {
                        $db->prepare('INSERT INTO carriers (name,code,contact_email,contact_phone,tracking_url,logo_url,is_active) VALUES (:n,:c,:e,:p,:t,:lu,:a)')
                           ->execute([':n'=>$name, ':c'=>$code ?: null, ':e'=>$email ?: null, ':p'=>$phone ?: null, ':t'=>$track ?: null, ':lu'=>$logoUrl ?: null, ':a'=>$active]);
                        $id = (int)$db->lastInsertId();
                    }

                    [$fileName, $upErr] = handleLogoUpload($id);
                    if ($upErr) { $error = $upErr; }
                    elseif ($fileName) {
                        $db->prepare('UPDATE carriers SET logo_path=:p, updated_at=CURRENT_TIMESTAMP WHERE id=:id')
                           ->execute([':p'=>$fileName, ':id'=>$id]);
                    }
                    if (!$error) $message = $id > 0 ? 'Carrier saved.' : 'Carrier added.';
                }
            } elseif ($op === 'toggle') {
                $id = (int)($_POST['id'] ?? 0);
                $db->prepare('UPDATE carriers SET is_active = 1 - is_active, updated_at=CURRENT_TIMESTAMP WHERE id=:id')->execute([':id'=>$id]);
                $message = 'Carrier status updated.';
            } elseif ($op === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                $db->prepare('DELETE FROM carriers WHERE id=:id')->execute([':id'=>$id]);
                $message = 'Carrier deleted.';
            }
        } catch (Throwable $e) {
            $error = 'Operation failed: ' . $e->getMessage();
        }
    }
}

$editId = (int)($_GET['edit'] ?? 0);
$edit = null;
if ($editId > 0) {
    $s = $db->prepare('SELECT * FROM carriers WHERE id=:id LIMIT 1');
    $s->execute([':id'=>$editId]);
    $edit = $s->fetch() ?: null;
}

$carriers = $db->query('SELECT * FROM carriers ORDER BY name')->fetchAll();
$csrf = csrfToken();

function hh(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Carriers — Nexora Logistics Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/app.css">
<style>
  body { background: #f1f5f9; }
  .wrap { max-width: 1100px; margin: 0 auto; padding: 1rem; }
  .panel { background:#fff; border-radius:14px; padding:1.5rem; box-shadow:0 8px 24px rgba(11,29,58,.08); margin-bottom:1.5rem; }
  .row { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:1rem; }
  label { display:block; font-size:.8rem; color:#475569; font-weight:600; margin-bottom:.35rem; text-transform:uppercase; letter-spacing:.04em; }
  input[type=text],input[type=email],input[type=tel],input[type=url]{ width:100%; padding:.65rem .85rem; border:1px solid #cbd5e1; border-radius:9px; font-family:inherit; font-size:.95rem; }
  input:focus{ outline:none; border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.15); }
  .actions { margin-top:1rem; display:flex; gap:.6rem; flex-wrap:wrap; }
  table { width:100%; border-collapse:collapse; font-size:.92rem; }
  th, td { padding:.75rem .6rem; text-align:left; border-bottom:1px solid #e5e7eb; vertical-align:middle; }
  th { font-size:.75rem; text-transform:uppercase; letter-spacing:.05em; color:#64748b; }
  .logo-thumb { max-width:44px; max-height:44px; border-radius:6px; background:#f8fafc; padding:3px; object-fit:contain; }
  .pill { padding:.2rem .55rem; border-radius:999px; font-size:.72rem; font-weight:700; }
  .pill.on { background:rgba(22,163,74,.12); color:#166534; }
  .pill.off{ background:rgba(220,38,38,.12); color:#991b1b; }
  .msg-ok{ padding:.75rem 1rem; background:rgba(22,163,74,.1); color:#166534; border-radius:10px; margin-bottom:1rem; }
  .msg-err{ padding:.75rem 1rem; background:rgba(220,38,38,.1); color:#991b1b; border-radius:10px; margin-bottom:1rem; }
  .btn-sm { padding:.4rem .75rem; font-size:.82rem; border-radius:8px; border:1px solid #cbd5e1; background:#fff; cursor:pointer; text-decoration:none; color:#0f172a; display:inline-block; }
  .btn-sm:hover{ background:#f1f5f9; }
  .btn-danger{ color:#991b1b; border-color:#fecaca; background:#fef2f2; }
</style>
    <script src="/assets/js/nx-labels.js"></script>
<script src="/assets/js/nx-popup.js"></script>
</head>
<body>
<header class="navbar">
  <a href="/admin/index.php" class="logo" style="text-decoration:none;color:inherit;">
    <span class="logo-badge">N</span><span class="logo-text">Nexora Admin</span>
  </a>
  <nav class="nav-links">
    <a href="/admin/index.php">Dashboard</a>
    <a href="/admin/shipments-hub.php">Shipments</a>
    <a href="/admin/people.php">People</a>
    <a href="/admin/content.php">Content</a>
    <a href="/admin/settings.php" class="active">Settings</a>
    <a href="/" target="_blank" rel="noopener" style="color:#ffd166 !important;">🌐 View Site</a>
    <a href="/admin/login.php?logout=1">Logout</a>
  </nav>
</header>

<div class="wrap">
  <h1 style="margin:1rem 0;">Partner Carriers</h1>
  <p style="color:#475569;margin-top:0;">Manage logistics partners. Assign a carrier to any shipment from the shipment editor.</p>

  <?php if ($message): ?><div class="msg-ok"><?= hh($message) ?></div><?php endif; ?>
  <?php if ($error):   ?><div class="msg-err"><?= hh($error) ?></div><?php endif; ?>

  <div class="panel">
    <h2 style="margin-top:0;"><?= $edit ? 'Edit carrier' : 'Add carrier' ?></h2>
    <form method="post" enctype="multipart/form-data" action="/admin/carriers.php">
      <input type="hidden" name="csrf_token" value="<?= hh($csrf) ?>">
      <input type="hidden" name="op" value="save">
      <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

      <div class="row">
        <div><label>Name *</label><input type="text" name="name" required value="<?= hh($edit['name'] ?? '') ?>"></div>
        <div><label>Code</label><input type="text" name="code" placeholder="e.g. DHL" value="<?= hh($edit['code'] ?? '') ?>"></div>
        <div><label>Contact email</label><input type="email" name="contact_email" value="<?= hh($edit['contact_email'] ?? '') ?>"></div>
        <div><label>Contact phone</label><input type="tel" name="contact_phone" value="<?= hh($edit['contact_phone'] ?? '') ?>"></div>
        <div><label>Tracking URL</label><input type="url" name="tracking_url" placeholder="https://..." value="<?= hh($edit['tracking_url'] ?? '') ?>"></div>
        <div><label>Logo URL (optional)</label><input type="url" name="logo_url" placeholder="https://..." value="<?= hh($edit['logo_url'] ?? '') ?>"></div>
        <div><label>Or upload logo (≤512 KB, PNG/JPG/WEBP/SVG)</label><input type="file" name="logo_file" accept="image/png,image/jpeg,image/webp,image/svg+xml"></div>
        <div style="display:flex;align-items:flex-end;">
          <label style="display:flex;align-items:center;gap:.5rem;text-transform:none;font-weight:500;font-size:.9rem;">
            <input type="checkbox" name="is_active" value="1" <?= (!$edit || (int)$edit['is_active'] === 1) ? 'checked' : '' ?>>
            Active
          </label>
        </div>
      </div>

      <?php if ($edit && !empty($edit['logo_path'])): ?>
        <p style="margin-top:.75rem;font-size:.85rem;color:#475569;">Current: <code>/assets/carriers/<?= hh($edit['logo_path']) ?></code></p>
      <?php endif; ?>

      <div class="actions">
        <button type="submit" class="btn btn-primary"><?= $edit ? 'Save changes' : 'Add carrier' ?></button>
        <?php if ($edit): ?>
          <a href="/admin/carriers.php" class="btn btn-outline" style="color:#0f172a;border-color:#cbd5e1;">Cancel</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="panel">
    <h2 style="margin-top:0;">All carriers (<?= count($carriers) ?>)</h2>
    <?php if (empty($carriers)): ?>
      <p style="color:#64748b;">No carriers yet. Add the first one above.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table>
      <thead><tr><th>Logo</th><th>Name</th><th>Code</th><th>Email</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($carriers as $c): ?>
          <?php
            $logoSrc = null;
            if (!empty($c['logo_path'])) $logoSrc = '/assets/carriers/' . rawurlencode($c['logo_path']);
            elseif (!empty($c['logo_url'])) $logoSrc = $c['logo_url'];
          ?>
          <tr>
            <td>
              <?php if ($logoSrc): ?>
                <img src="<?= hh($logoSrc) ?>" alt="" class="logo-thumb">
              <?php else: ?>
                <span style="color:#94a3b8;font-size:.8rem;">—</span>
              <?php endif; ?>
            </td>
            <td><strong><?= hh($c['name']) ?></strong></td>
            <td><?= hh($c['code']) ?></td>
            <td><?= hh($c['contact_email']) ?></td>
            <td><span class="pill <?= (int)$c['is_active'] === 1 ? 'on' : 'off' ?>"><?= (int)$c['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
            <td style="white-space:nowrap;">
              <a href="/admin/carriers.php?edit=<?= (int)$c['id'] ?>" class="btn-sm">Edit</a>
              <form method="post" style="display:inline;" onsubmit="return confirm('Toggle this carrier status?');">
                <input type="hidden" name="csrf_token" value="<?= hh($csrf) ?>">
                <input type="hidden" name="op" value="toggle">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button class="btn-sm" type="submit"><?= (int)$c['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
              </form>
              <form method="post" style="display:inline;" onsubmit="return confirm('Delete this carrier? This cannot be undone.');">
                <input type="hidden" name="csrf_token" value="<?= hh($csrf) ?>">
                <input type="hidden" name="op" value="delete">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button class="btn-sm btn-danger" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
/* nxValidateCarriers */
(function(){
  "use strict";
  function init(){
    var form = document.querySelector("form[enctype]");
    if (!form) return;
    var fileInput = form.querySelector("input[type=file]");

    form.addEventListener("submit", function(e){
      var name = form.querySelector("input[name=name]");
      if (name && name.value.trim() === "") { e.preventDefault(); return NXP.err("Carrier name is required."); }

      var file = fileInput && fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
      if (file) {
        var allowed = ["image/png","image/jpeg","image/webp","image/svg+xml"];
        if (allowed.indexOf(file.type) === -1) { e.preventDefault(); return NXP.err("Logo must be PNG, JPG, WEBP, or SVG."); }
        if (file.size > 524288) { e.preventDefault(); return NXP.err("Logo exceeds 512 KB. Compress it or use a smaller image."); }
      }

      // URL fields sanity
      var logoUrl = form.querySelector("input[name=logo_url]");
      if (logoUrl && logoUrl.value.trim() !== "" && !/^https?:\/\//i.test(logoUrl.value.trim())) {
        e.preventDefault(); return NXP.err("Logo URL must start with http:// or https://");
      }
      var track = form.querySelector("input[name=tracking_url]");
      if (track && track.value.trim() !== "" && !/^https?:\/\//i.test(track.value.trim())) {
        e.preventDefault(); return NXP.err("Tracking URL must start with http:// or https://");
      }
      var email = form.querySelector("input[name=contact_email]");
      if (email && email.value.trim() !== "" && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim())) {
        e.preventDefault(); return NXP.err("Contact email does not look valid.");
      }
    });
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();
</script>

</body>
</html>
