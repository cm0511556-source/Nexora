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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid or expired security token.';
    } else {
        try {
            $keys = $_POST['key'] ?? [];
            $vals = $_POST['val'] ?? [];
            $upd = $db->prepare('UPDATE site_content SET value = :v, updated_at = CURRENT_TIMESTAMP WHERE content_key = :k');
            $count = 0;
            foreach ($keys as $i => $k) {
                if (!is_string($k) || $k === '') continue;
                $upd->execute([':v' => (string)($vals[$i] ?? ''), ':k' => $k]);
                $count++;
            }
            $message = "Saved $count field(s).";
        } catch (Throwable $e) {
            $error = 'Save failed: ' . $e->getMessage();
        }
    }
}

$rows = $db->query('SELECT * FROM site_content ORDER BY section, sort_order, id')->fetchAll();
$grouped = [];
foreach ($rows as $r) $grouped[$r['section']][] = $r;

$sectionTitles = [
    'hero'     => '🏠 Hero (top of homepage)',
    'services' => '🚚 Services section',
    'numbers'  => '🔢 Live counters labels',
    'reviews'  => '⭐ Reviews heading',
    'contact'  => '✉️ Contact section',
    'footer'   => '🔗 Footer',
];

$csrf = csrfToken();
function hh(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Site Content — Nexora Logistics Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
<style>
  body { background: #f1f5f9; }
  .wrap { max-width: 900px; margin: 0 auto; padding: 1rem; }
  .panel { background: #fff; border-radius: 14px; padding: 1.5rem; box-shadow: 0 8px 24px rgba(11,29,58,.08); margin-bottom: 1.5rem; }
  .tabs { display: flex; gap: .35rem; flex-wrap: wrap; margin-bottom: 1rem; }
  .tab { padding: .5rem .9rem; border-radius: 9px; background: #fff; border: 1px solid #cbd5e1; cursor: pointer; font-weight: 600; font-size: .85rem; color: #0f172a; font-family: inherit; }
  .tab.active { background: #0b1d3a; color: #fff; border-color: #0b1d3a; }
  .tab-panel { display: none; }
  .tab-panel.active { display: block; }
  .field { margin-bottom: 1.25rem; }
  .field label { display: block; font-size: .8rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: .04em; margin-bottom: .35rem; }
  .field code { display: inline-block; background: #f1f5f9; padding: 1px 6px; border-radius: 5px; font-size: .78rem; color: #334155; margin-left: .5rem; text-transform: none; letter-spacing: 0; font-weight: 500; }
  .field input, .field textarea { width: 100%; padding: .65rem .85rem; border: 1px solid #cbd5e1; border-radius: 9px; font-family: inherit; font-size: .95rem; box-sizing: border-box; }
  .field input:focus, .field textarea:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.15); }
  .field textarea { min-height: 78px; resize: vertical; }
  .save-bar { position: sticky; bottom: 0; background: #fff; border-top: 1px solid #e5e7eb; padding: 1rem; margin: 1rem -1.5rem -1.5rem; display: flex; gap: .75rem; align-items: center; flex-wrap: wrap; }
  .msg-ok { padding: .75rem 1rem; background: rgba(22,163,74,.1); color: #166534; border-radius: 10px; margin-bottom: 1rem; }
  .msg-err { padding: .75rem 1rem; background: rgba(220,38,38,.1); color: #991b1b; border-radius: 10px; margin-bottom: 1rem; }
  .hint { color: #64748b; font-size: .85rem; margin: 0 0 1rem; }
</style>
    <script src="<?= asset('js/nx-labels.js') ?>"></script>
<script src="<?= asset('js/nx-popup.js') ?>"></script>
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
    <a href="/admin/content.php" class="active">Content</a>
    <a href="/admin/settings.php">Settings</a>
    <a href="/" target="_blank" rel="noopener" style="color:#ffd166 !important;">🌐 View Site</a>
    <a href="/admin/login.php?logout=1">Logout</a>
  </nav>
</header>

<div class="wrap">
  <h1 style="margin:1rem 0 .25rem;">Site Content</h1>
  <p class="hint">Edit any text on the public homepage. Changes appear instantly after saving.</p>

  <?php if ($message): ?><div class="msg-ok"><?= hh($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="msg-err"><?= hh($error) ?></div><?php endif; ?>

  <div class="tabs">
    <?php $first = true; foreach ($grouped as $section => $items): ?>
      <button type="button" class="tab<?= $first ? ' active' : '' ?>" data-tab="<?= hh($section) ?>"><?= hh($sectionTitles[$section] ?? ucfirst($section)) ?></button>
      <?php $first = false; endforeach; ?>
  </div>

  <form method="post" action="/admin/site-content.php">
    <input type="hidden" name="csrf_token" value="<?= hh($csrf) ?>">
    <?php $first = true; foreach ($grouped as $section => $items): ?>
      <div class="tab-panel<?= $first ? ' active' : '' ?>" id="tab-<?= hh($section) ?>">
        <div class="panel">
          <?php foreach ($items as $r): ?>
            <div class="field">
              <label>
                <?= hh($r['label']) ?>
                <code><?= hh($r['content_key']) ?></code>
              </label>
              <input type="hidden" name="key[]" value="<?= hh($r['content_key']) ?>">
              <?php if ((int)$r['multiline'] === 1): ?>
                <textarea name="val[]"><?= hh($r['value']) ?></textarea>
              <?php else: ?>
                <input type="text" name="val[]" value="<?= hh($r['value']) ?>">
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php $first = false; endforeach; ?>

    <div class="save-bar">
      <button type="submit" class="btn btn-primary">Save all changes</button>
      <span style="color:#64748b;font-size:.85rem;">All fields on every tab save together.</span>
    </div>
  </form>
</div>

<script>
(function(){
  'use strict';
  var tabs = document.querySelectorAll('.tab');
  tabs.forEach(function(t){
    t.addEventListener('click', function(){
      document.querySelectorAll('.tab').forEach(function(x){ x.classList.remove('active'); });
      document.querySelectorAll('.tab-panel').forEach(function(x){ x.classList.remove('active'); });
      t.classList.add('active');
      var panel = document.getElementById('tab-' + t.dataset.tab);
      if (panel) panel.classList.add('active');
    });
  });
})();
</script>
</body>
</html>
