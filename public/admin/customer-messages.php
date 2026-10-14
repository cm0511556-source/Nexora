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
        $op = $_POST['op'] ?? '';
        try {
            if ($op === 'save_events') {
                $titles = $_POST['title'] ?? [];
                $descs  = $_POST['desc']  ?? [];
                $icons  = $_POST['icon']  ?? [];
                $keys   = $_POST['key']   ?? [];
                $upd = $db->prepare('UPDATE event_translations SET public_title=:t, public_description=:d, public_icon=:i, updated_at=CURRENT_TIMESTAMP WHERE raw_key=:k');
                foreach ($keys as $i => $k) {
                    $upd->execute([
                        ':t' => trim((string)($titles[$i] ?? '')),
                        ':d' => trim((string)($descs[$i]  ?? '')),
                        ':i' => trim((string)($icons[$i]  ?? '')),
                        ':k' => $k,
                    ]);
                }
                $message = 'Event messages updated.';
            } elseif ($op === 'save_segments') {
                $titles = $_POST['title'] ?? [];
                $descs  = $_POST['desc']  ?? [];
                $icons  = $_POST['icon']  ?? [];
                $keys   = $_POST['key']   ?? [];
                $upd = $db->prepare('UPDATE segment_messages SET public_title=:t, public_description=:d, icon=:i, updated_at=CURRENT_TIMESTAMP WHERE segment_type=:k');
                foreach ($keys as $i => $k) {
                    $upd->execute([
                        ':t' => trim((string)($titles[$i] ?? '')),
                        ':d' => trim((string)($descs[$i]  ?? '')),
                        ':i' => trim((string)($icons[$i]  ?? '')),
                        ':k' => $k,
                    ]);
                }
                $message = 'Segment messages updated.';
            }
        } catch (Throwable $e) {
            $error = 'Save failed: ' . $e->getMessage();
        }
    }
}

$events   = $db->query('SELECT * FROM event_translations ORDER BY raw_key')->fetchAll();
$segments = $db->query('SELECT * FROM segment_messages ORDER BY segment_type')->fetchAll();
$csrf = csrfToken();

function hh(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES); }

$iconChoices = ['truck','air','sea','rail','bike','shield','alert','check','clock','cloud','wrench','box','pin'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Customer Messages — Nexora Logistics Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
<style>
  body{background:#f1f5f9;}
  .wrap{max-width:1100px;margin:0 auto;padding:1rem;}
  .panel{background:#fff;border-radius:14px;padding:1.5rem;box-shadow:0 8px 24px rgba(11,29,58,.08);margin-bottom:1.5rem;overflow-x:auto;-webkit-overflow-scrolling:touch;}
  .panel table{min-width:640px;}
  body,html{overflow-x:hidden;}
  label{display:block;font-size:.75rem;color:#475569;font-weight:700;margin-bottom:.3rem;text-transform:uppercase;letter-spacing:.04em;}
  input[type=text], textarea, select{width:100%;padding:.55rem .75rem;border:1px solid #cbd5e1;border-radius:9px;font-family:inherit;font-size:.92rem;box-sizing:border-box;}
  input:focus, textarea:focus, select:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15);}
  textarea{min-height:56px;resize:vertical;}
  .msg-ok{padding:.75rem 1rem;background:rgba(22,163,74,.1);color:#166534;border-radius:10px;margin-bottom:1rem;}
  .msg-err{padding:.75rem 1rem;background:rgba(220,38,38,.1);color:#991b1b;border-radius:10px;margin-bottom:1rem;}
  table{width:100%;border-collapse:collapse;font-size:.9rem;}
  th,td{padding:.6rem .5rem;text-align:left;border-bottom:1px solid #e5e7eb;vertical-align:top;}
  th{font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:#64748b;}
  .key{font-family:ui-monospace,Menlo,monospace;background:#f1f5f9;padding:.2rem .45rem;border-radius:6px;font-size:.78rem;color:#334155;white-space:nowrap;}
  .tabs{display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap;}
  .tab{padding:.55rem 1rem;border-radius:9px;background:#fff;border:1px solid #cbd5e1;cursor:pointer;font-weight:600;font-size:.9rem;color:#0f172a;}
  .tab.active{background:#0b1d3a;color:#fff;border-color:#0b1d3a;}
  .tab-panel{display:none;}
  .tab-panel.active{display:block;}
  .icon-preview{width:26px;height:26px;border-radius:6px;background:#f1f5f9;display:inline-flex;align-items:center;justify-content:center;font-size:14px;}
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
  <h1 style="margin:1rem 0 .25rem;">Customer Messages</h1>
  <p style="color:#475569;margin-top:0;">This is what your customer sees in the tracking timeline and on the map. Edit any line, choose an icon, and save. Admin history is never changed.</p>

  <?php if ($message): ?><div class="msg-ok"><?= hh($message) ?></div><?php endif; ?>
  <?php if ($error):   ?><div class="msg-err"><?= hh($error) ?></div><?php endif; ?>

  <div class="tabs">
    <div class="tab active" data-tab="events">Timeline events (<?= count($events) ?>)</div>
    <div class="tab" data-tab="segments">Transport popups (<?= count($segments) ?>)</div>
  </div>

  <div class="tab-panel active" id="tab-events">
    <form method="post" action="/admin/customer-messages.php">
      <input type="hidden" name="csrf_token" value="<?= hh($csrf) ?>">
      <input type="hidden" name="op" value="save_events">
      <div class="panel">
        <table>
          <thead><tr><th style="width:170px;">Admin key</th><th>Customer sees (title)</th><th>Customer sees (description)</th><th style="width:110px;">Icon</th></tr></thead>
          <tbody>
            <?php foreach ($events as $e): ?>
              <tr>
                <td><span class="key"><?= hh($e['raw_key']) ?></span><input type="hidden" name="key[]" value="<?= hh($e['raw_key']) ?>"></td>
                <td><input type="text" name="title[]" value="<?= hh($e['public_title']) ?>" required></td>
                <td><textarea name="desc[]"><?= hh($e['public_description']) ?></textarea></td>
                <td>
                  <select name="icon[]">
                    <?php foreach ($iconChoices as $ic): ?>
                      <option value="<?= hh($ic) ?>" <?= $e['public_icon'] === $ic ? 'selected' : '' ?>><?= hh($ic) ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div style="margin-top:1rem;"><button type="submit" class="btn btn-primary">Save timeline messages</button></div>
      </div>
    </form>
  </div>

  <div class="tab-panel" id="tab-segments">
    <form method="post" action="/admin/customer-messages.php">
      <input type="hidden" name="csrf_token" value="<?= hh($csrf) ?>">
      <input type="hidden" name="op" value="save_segments">
      <div class="panel">
        <p style="color:#475569;margin-top:0;">These popups appear when a customer taps the vehicle on the map — one per transport type.</p>
        <table>
          <thead><tr><th style="width:130px;">Segment</th><th>Popup title</th><th>Popup description</th><th style="width:110px;">Icon</th></tr></thead>
          <tbody>
            <?php foreach ($segments as $s): ?>
              <tr>
                <td><span class="key"><?= hh($s['segment_type']) ?></span><input type="hidden" name="key[]" value="<?= hh($s['segment_type']) ?>"></td>
                <td><input type="text" name="title[]" value="<?= hh($s['public_title']) ?>" required></td>
                <td><textarea name="desc[]"><?= hh($s['public_description']) ?></textarea></td>
                <td>
                  <select name="icon[]">
                    <?php foreach ($iconChoices as $ic): ?>
                      <option value="<?= hh($ic) ?>" <?= $s['icon'] === $ic ? 'selected' : '' ?>><?= hh($ic) ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div style="margin-top:1rem;"><button type="submit" class="btn btn-primary">Save transport popups</button></div>
      </div>
    </form>
  </div>
</div>

<script>
document.querySelectorAll('.tab').forEach(t => {
  t.addEventListener('click', () => {
    document.querySelectorAll('.tab').forEach(x => x.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(x => x.classList.remove('active'));
    t.classList.add('active');
    document.getElementById('tab-' + t.dataset.tab).classList.add('active');
  });
});
</script>
</body>
</html>
