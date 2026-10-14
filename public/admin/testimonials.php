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

$uploadDir = dirname(__DIR__) . '/assets/images/testimonials';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

const MAX_PHOTO_BYTES = 524288;
const ALLOWED_PHOTO_MIMES = ['image/png','image/jpeg','image/webp'];

function handlePhotoUpload(int $id): array {
    if (empty($_FILES['author_photo_file']) || ($_FILES['author_photo_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    $f = $_FILES['author_photo_file'];
    if ($f['error'] !== UPLOAD_ERR_OK) return [null, 'Upload error code ' . $f['error']];
    if ($f['size'] > MAX_PHOTO_BYTES) return [null, 'Photo exceeds 512 KB.'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($f['tmp_name']);
    if (!in_array($mime, ALLOWED_PHOTO_MIMES, true)) return [null, 'Only PNG, JPG, WEBP allowed.'];
    $ext = match($mime) {
        'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', default => 'bin'
    };
    $name = 'reviewer-' . $id . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = dirname(__DIR__) . '/assets/images/testimonials/' . $name;
    if (!move_uploaded_file($f['tmp_name'], $dest)) return [null, 'Could not save photo.'];
    return [$name, null];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid or expired security token.';
    } else {
        $op = $_POST['op'] ?? '';
        try {
            if ($op === 'save') {
                $id       = (int)($_POST['id'] ?? 0);
                $name     = trim((string)($_POST['author_name'] ?? ''));
                $country  = trim((string)($_POST['author_country'] ?? ''));
                $role     = trim((string)($_POST['author_role'] ?? ''));
                $photoUrl = trim((string)($_POST['author_photo_url'] ?? ''));
                $rating   = max(1, min(5, (int)($_POST['rating'] ?? 5)));
                $msg      = trim((string)($_POST['message'] ?? ''));
                $pub      = isset($_POST['is_published']) ? 1 : 0;
                $sort     = (int)($_POST['sort_order'] ?? 0);

                if ($name === '') { $error = 'Author name is required.'; }
                elseif ($msg === '') { $error = 'Review text is required.'; }
                else {
                    if ($id > 0) {
                        $db->prepare('UPDATE testimonials SET author_name=:n, author_country=:c, author_role=:r, author_photo_url=:pu, rating=:rt, message=:m, is_published=:p, sort_order=:s, updated_at=CURRENT_TIMESTAMP WHERE id=:id')
                           ->execute([':n'=>$name, ':c'=>$country ?: null, ':r'=>$role ?: null, ':pu'=>$photoUrl ?: null, ':rt'=>$rating, ':m'=>$msg, ':p'=>$pub, ':s'=>$sort, ':id'=>$id]);
                    } else {
                        $db->prepare('INSERT INTO testimonials (author_name, author_country, author_role, author_photo_url, rating, message, is_published, sort_order) VALUES (:n,:c,:r,:pu,:rt,:m,:p,:s)')
                           ->execute([':n'=>$name, ':c'=>$country ?: null, ':r'=>$role ?: null, ':pu'=>$photoUrl ?: null, ':rt'=>$rating, ':m'=>$msg, ':p'=>$pub, ':s'=>$sort]);
                        $id = (int)$db->lastInsertId();
                    }
                    [$fileName, $upErr] = handlePhotoUpload($id);
                    if ($upErr) { $error = $upErr; }
                    elseif ($fileName) {
                        $db->prepare('UPDATE testimonials SET author_photo_path=:p, updated_at=CURRENT_TIMESTAMP WHERE id=:id')
                           ->execute([':p'=>$fileName, ':id'=>$id]);
                    }
                    if (!$error) $message = 'Testimonial saved.';
                }
            } elseif ($op === 'toggle') {
                $id = (int)($_POST['id'] ?? 0);
                $db->prepare('UPDATE testimonials SET is_published = 1 - is_published, updated_at=CURRENT_TIMESTAMP WHERE id=:id')->execute([':id'=>$id]);
                $message = 'Visibility updated.';
            } elseif ($op === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                $row = $db->prepare('SELECT author_photo_path FROM testimonials WHERE id=:id LIMIT 1');
                $row->execute([':id'=>$id]);
                $p = $row->fetchColumn();
                if ($p && is_file(dirname(__DIR__) . '/assets/images/testimonials/' . basename($p))) {
                    @unlink(dirname(__DIR__) . '/assets/images/testimonials/' . basename($p));
                }
                $db->prepare('DELETE FROM testimonials WHERE id=:id')->execute([':id'=>$id]);
                $message = 'Testimonial deleted.';
            }
        } catch (Throwable $e) { $error = 'Operation failed: ' . $e->getMessage(); }
    }
}

$editId = (int)($_GET['edit'] ?? 0);
$edit = null;
if ($editId > 0) {
    $s = $db->prepare('SELECT * FROM testimonials WHERE id=:id LIMIT 1');
    $s->execute([':id'=>$editId]);
    $edit = $s->fetch() ?: null;
}
$rows = $db->query('SELECT * FROM testimonials ORDER BY sort_order ASC, id DESC')->fetchAll();
$csrf = csrfToken();

function hh(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES); }
function stars(int $n): string { return str_repeat('★', $n) . str_repeat('☆', 5 - $n); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Testimonials — Nexora Logistics Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/app.css">
<style>
  body { background:#f1f5f9; }
  .wrap { max-width:1100px; margin:0 auto; padding:1rem; }
  .panel { background:#fff; border-radius:14px; padding:1.5rem; box-shadow:0 8px 24px rgba(11,29,58,.08); margin-bottom:1.5rem; }
  .row { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:1rem; }
  label { display:block; font-size:.8rem; color:#475569; font-weight:600; margin-bottom:.35rem; text-transform:uppercase; letter-spacing:.04em; }
  input[type=text], input[type=url], input[type=number], select, textarea { width:100%; padding:.65rem .85rem; border:1px solid #cbd5e1; border-radius:9px; font-family:inherit; font-size:.95rem; box-sizing:border-box; }
  input:focus, select:focus, textarea:focus { outline:none; border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.15); }
  textarea { min-height:90px; resize:vertical; }
  .actions { margin-top:1rem; display:flex; gap:.6rem; flex-wrap:wrap; }
  .msg-ok { padding:.75rem 1rem; background:rgba(22,163,74,.1); color:#166534; border-radius:10px; margin-bottom:1rem; }
  .msg-err { padding:.75rem 1rem; background:rgba(220,38,38,.1); color:#991b1b; border-radius:10px; margin-bottom:1rem; }
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
    <a href="/admin/people.php" class="active">People</a>
    <a href="/admin/content.php">Content</a>
    <a href="/admin/settings.php">Settings</a>
    <a href="/" target="_blank" rel="noopener" style="color:#ffd166 !important;">🌐 View Site</a>
    <a href="/admin/login.php?logout=1">Logout</a>
  </nav>
</header>

<div class="wrap">
  <h1 style="margin:1rem 0 .25rem;">Customer Testimonials</h1>
  <p style="color:#475569;margin-top:0;">Customer reviews shown on the homepage in a sliding carousel. Add real quotes, photos, and star ratings.</p>

  <?php if ($message): ?><div class="msg-ok"><?= hh($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="msg-err"><?= hh($error) ?></div><?php endif; ?>

  <div class="panel">
    <h2 style="margin-top:0;"><?= $edit ? 'Edit testimonial' : 'Add testimonial' ?></h2>
    <form method="post" enctype="multipart/form-data" action="/admin/testimonials.php">
      <input type="hidden" name="csrf_token" value="<?= hh($csrf) ?>">
      <input type="hidden" name="op" value="save">
      <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

      <div class="row">
        <div><label>Author name *</label><input type="text" name="author_name" required value="<?= hh($edit['author_name'] ?? '') ?>" placeholder="e.g. Sarah Chen"></div>
        <div><label>Country</label><input type="text" name="author_country" value="<?= hh($edit['author_country'] ?? '') ?>" placeholder="e.g. United States"></div>
        <div><label>Role / title</label><input type="text" name="author_role" value="<?= hh($edit['author_role'] ?? '') ?>" placeholder="e.g. Logistics Director"></div>
        <div>
          <label>Rating (1–5)</label>
          <select name="rating">
            <?php for ($i = 5; $i >= 1; $i--): ?>
              <option value="<?= $i ?>" <?= (int)($edit['rating'] ?? 5) === $i ? 'selected' : '' ?>><?= stars($i) ?> (<?= $i ?>)</option>
            <?php endfor; ?>
          </select>
        </div>
        <div><label>Photo URL (optional)</label><input type="url" name="author_photo_url" value="<?= hh($edit['author_photo_url'] ?? '') ?>" placeholder="https://..."></div>
        <div><label>Or upload photo (≤512 KB, PNG/JPG/WEBP)</label><input type="file" name="author_photo_file" accept="image/png,image/jpeg,image/webp"></div>
        <div><label>Sort order (lower = first)</label><input type="number" name="sort_order" value="<?= (int)($edit['sort_order'] ?? 0) ?>"></div>
        <div style="display:flex;align-items:flex-end;">
          <label style="display:flex;align-items:center;gap:.5rem;text-transform:none;font-weight:500;font-size:.9rem;">
            <input type="checkbox" name="is_published" value="1" <?= (!$edit || (int)$edit['is_published'] === 1) ? 'checked' : '' ?>>
            Published (visible on site)
          </label>
        </div>
      </div>

      <div style="margin-top:14px;">
        <label>Review text *</label>
        <textarea name="message" required placeholder="What did the customer say?"><?= hh($edit['message'] ?? '') ?></textarea>
      </div>

      <?php if ($edit && !empty($edit['author_photo_path'])): ?>
        <p style="margin-top:.75rem;font-size:.85rem;color:#475569;">Current photo: <code>/assets/images/testimonials/<?= hh($edit['author_photo_path']) ?></code></p>
      <?php endif; ?>

      <div class="actions">
        <button type="submit" class="btn btn-primary"><?= $edit ? 'Save changes' : 'Add testimonial' ?></button>
        <?php if ($edit): ?><a href="/admin/testimonials.php" class="btn btn-outline" style="color:#0f172a;border-color:#cbd5e1;">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div>

  <div class="panel">
    <h2 style="margin-top:0;">All testimonials (<?= count($rows) ?>)</h2>
    <?php if (!$rows): ?>
      <p style="color:#64748b;">No testimonials yet. Add the first one above.</p>
    <?php else: ?>
      <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:.92rem;">
        <thead><tr>
          <th style="padding:.6rem .5rem;text-align:left;font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:#64748b;">Photo</th>
          <th style="padding:.6rem .5rem;text-align:left;font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:#64748b;">Author</th>
          <th style="padding:.6rem .5rem;text-align:left;font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:#64748b;">Rating</th>
          <th style="padding:.6rem .5rem;text-align:left;font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:#64748b;">Review</th>
          <th style="padding:.6rem .5rem;text-align:left;font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:#64748b;">Status</th>
          <th style="padding:.6rem .5rem;"></th>
        </tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <?php
              $src = null;
              if (!empty($r['author_photo_path'])) $src = '/assets/images/testimonials/' . rawurlencode($r['author_photo_path']);
              elseif (!empty($r['author_photo_url'])) $src = $r['author_photo_url'];
            ?>
            <tr style="border-bottom:1px solid #e5e7eb;">
              <td style="padding:.75rem .5rem;vertical-align:top;">
                <?php if ($src): ?>
                  <img src="<?= hh($src) ?>" alt="" style="width:48px;height:48px;border-radius:50%;object-fit:cover;background:#f1f5f9;border:1px solid #e2e8f0;">
                <?php else: ?>
                  <span style="color:#94a3b8;">—</span>
                <?php endif; ?>
              </td>
              <td style="padding:.75rem .5rem;vertical-align:top;">
                <strong><?= hh($r['author_name']) ?></strong>
                <?php if ($r['author_country']): ?><br><span style="color:#64748b;font-size:.82rem;"><?= hh($r['author_country']) ?></span><?php endif; ?>
                <?php if ($r['author_role']): ?><br><span style="color:#94a3b8;font-size:.78rem;"><?= hh($r['author_role']) ?></span><?php endif; ?>
              </td>
              <td style="padding:.75rem .5rem;vertical-align:top;"><span style="color:#f0b429;font-size:1rem;letter-spacing:1px;"><?= stars((int)$r['rating']) ?></span></td>
              <td style="padding:.75rem .5rem;vertical-align:top;max-width:340px;"><?= hh(mb_strimwidth($r['message'], 0, 140, '…')) ?></td>
              <td style="padding:.75rem .5rem;vertical-align:top;">
                <span style="padding:.2rem .55rem;border-radius:999px;font-size:.72rem;font-weight:700;background:<?= (int)$r['is_published'] === 1 ? 'rgba(22,163,74,.12)' : 'rgba(220,38,38,.12)' ?>;color:<?= (int)$r['is_published'] === 1 ? '#166534' : '#991b1b' ?>;">
                  <?= (int)$r['is_published'] === 1 ? 'Published' : 'Hidden' ?>
                </span>
              </td>
              <td style="padding:.75rem .5rem;vertical-align:top;white-space:nowrap;">
                <a href="/admin/testimonials.php?edit=<?= (int)$r['id'] ?>" style="padding:.4rem .75rem;font-size:.82rem;border-radius:8px;border:1px solid #cbd5e1;background:#fff;text-decoration:none;color:#0f172a;display:inline-block;">Edit</a>
                <form method="post" style="display:inline;" onsubmit="return confirm('Toggle visibility?');">
                  <input type="hidden" name="csrf_token" value="<?= hh($csrf) ?>">
                  <input type="hidden" name="op" value="toggle">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button type="submit" style="padding:.4rem .75rem;font-size:.82rem;border-radius:8px;border:1px solid #cbd5e1;background:#fff;cursor:pointer;font-family:inherit;"><?= (int)$r['is_published'] === 1 ? 'Hide' : 'Publish' ?></button>
                </form>
                <form method="post" style="display:inline;" onsubmit="return confirm('Delete this testimonial?');">
                  <input type="hidden" name="csrf_token" value="<?= hh($csrf) ?>">
                  <input type="hidden" name="op" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button type="submit" style="padding:.4rem .75rem;font-size:.82rem;border-radius:8px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;cursor:pointer;font-family:inherit;">Delete</button>
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
/* nxValidateTestimonials */
(function(){
  "use strict";
  function init(){
    var form = document.querySelector("form[enctype]");
    if (!form) return;
    var fileInput = form.querySelector("input[type=file]");
    form.addEventListener("submit", function(e){
      var name = form.querySelector("input[name=author_name]");
      var msg  = form.querySelector("textarea[name=message]");
      var rating = form.querySelector("select[name=rating]");
      if (!name || name.value.trim() === "") { e.preventDefault(); return NXP.err("Author name is required."); }
      if (!msg || msg.value.trim() === "") { e.preventDefault(); return NXP.err("Review text is required."); }
      if (msg.value.trim().length < 10) { e.preventDefault(); return NXP.err("Review text is too short — write at least 10 characters."); }
      if (rating) {
        var r = parseInt(rating.value, 10);
        if (isNaN(r) || r < 1 || r > 5) { e.preventDefault(); return NXP.err("Rating must be between 1 and 5."); }
      }
      var file = fileInput && fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
      if (file) {
        var allowed = ["image/png","image/jpeg","image/webp"];
        if (allowed.indexOf(file.type) === -1) { e.preventDefault(); return NXP.err("Photo must be PNG, JPG, or WEBP."); }
        if (file.size > 524288) { e.preventDefault(); return NXP.err("Photo exceeds 512 KB."); }
      }
      var url = form.querySelector("input[name=author_photo_url]");
      if (url && url.value.trim() !== "" && !/^https?:\/\//i.test(url.value.trim())) {
        e.preventDefault(); return NXP.err("Photo URL must start with http:// or https://");
      }
    });
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();
</script>

</body>
</html>
