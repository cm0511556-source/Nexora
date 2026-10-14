<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once BASE_PATH . '/app/Database.php';
require_once BASE_PATH . '/app/Auth.php';
use VibrantHub\Auth; use VibrantHub\Database;
Auth::requireAdmin();
$db = Database::connection();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid or expired security token.';
    } else {
        $op = (string) ($_POST['op'] ?? '');
        if ($op === 'ambient') {
            $speed = (string) ($_POST['ambient_speed'] ?? 'normal');
            if (!in_array($speed, ['off', 'slow', 'normal', 'fast'], true)) {
                $speed = 'normal';
            }
            nxSettingSet('ambient_speed', $speed);
            nxAudit('settings.ambient_speed', 'settings', null, $speed);
            $message = 'Ambient traffic speed saved.';
        }
    }
}

$carriers = (int) $db->query('SELECT COUNT(*) FROM carriers')->fetchColumn();
$customers = (int) $db->query('SELECT COUNT(*) FROM customers')->fetchColumn();
$auditCount = (int) $db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
$ambient = (string) nxSetting('ambient_speed', 'normal');
$chatbot = (string) nxSetting('chatbot_enabled', '1') === '1';
$deepseek = trim((string) env('DEEPSEEK_API_KEY', '')) !== '';
$csrf = csrfToken();
function hh(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES); }
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Settings — Nexora Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
<style>
body{background:#f1f5f9}.wrap{max-width:900px;margin:0 auto;padding:1rem}
.tile-grid{display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(260px,1fr))}
.tile{display:block;background:#fff;border-radius:14px;padding:1.5rem;box-shadow:0 8px 24px rgba(11,29,58,.08);text-decoration:none;color:inherit;border:1px solid #e5e7eb;transition:.15s}
.tile:hover{transform:translateY(-3px);box-shadow:0 12px 30px rgba(11,29,58,.12)}
.tile-icon{font-size:2rem;margin-bottom:.75rem}
.tile-title{font-weight:800;color:#0b1d3a;font-size:1.1rem;margin:0 0 .35rem}
.tile-desc{color:#5b6b85;font-size:.92rem;margin:0}
.tile-count{display:inline-block;margin-top:.75rem;background:rgba(240,180,41,.15);color:#854d0e;padding:.2rem .6rem;border-radius:999px;font-size:.78rem;font-weight:700}
.panel{background:#fff;border-radius:14px;padding:1.5rem;box-shadow:0 8px 24px rgba(11,29,58,.08);margin:1.5rem 0}
.msg-ok{padding:.75rem 1rem;background:rgba(22,163,74,.1);color:#166534;border-radius:10px;margin-bottom:1rem}
.msg-err{padding:.75rem 1rem;background:rgba(220,38,38,.1);color:#991b1b;border-radius:10px;margin-bottom:1rem}
select{padding:.6rem .8rem;border:1px solid #cbd5e1;border-radius:9px;font-family:inherit;font-size:.95rem}
.note{font-size:.85rem;color:#64748b;margin:.6rem 0 0;line-height:1.5}
</style>    <script src="<?= asset('js/nx-labels.js') ?>"></script>
<script src="<?= asset('js/nx-popup.js') ?>"></script>
</head><body>
<header class="navbar">
  <a href="/admin/index.php" class="logo" style="text-decoration:none;color:inherit;"><span class="logo-badge">N</span><span class="logo-text">Nexora Admin</span></a>
  <nav class="nav-links">
    <a href="/admin/index.php">Dashboard</a>
    <a href="/admin/shipments-hub.php">Shipments</a>
    <a href="/admin/people.php">People</a>
    <a href="/admin/content.php">Content</a>
    <a href="/admin/settings.php">Settings</a>
    <a href="/" target="_blank" rel="noopener" style="color:#ffd166 !important;">🌐 View Site</a>
    <a href="/admin/login.php?logout=1">Logout</a>
  </nav>
</header>
<div class="wrap">
  <h1 style="margin:1rem 0 .25rem;">Settings</h1>
  <p style="color:#475569;margin-top:0;">Partners, customers, map traffic, and system tools.</p>

  <?php if ($message): ?><div class="msg-ok"><?= hh($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="msg-err"><?= hh($error) ?></div><?php endif; ?>

  <div class="panel">
    <h2 style="margin-top:0;">🌍 Ambient map traffic</h2>
    <p class="note">Controls the decorative cars, planes, ships, trains and pedestrians on every map (customer and admin). This is global.</p>
    <form method="post" style="margin-top:.75rem;display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;">
      <input type="hidden" name="csrf_token" value="<?= hh($csrf) ?>">
      <input type="hidden" name="op" value="ambient">
      <select name="ambient_speed">
        <option value="off" <?= $ambient === 'off' ? 'selected' : '' ?>>Off</option>
        <option value="slow" <?= $ambient === 'slow' ? 'selected' : '' ?>>Slow</option>
        <option value="normal" <?= $ambient === 'normal' ? 'selected' : '' ?>>Normal</option>
        <option value="fast" <?= $ambient === 'fast' ? 'selected' : '' ?>>Fast</option>
      </select>
      <button type="submit" class="btn btn-primary">Save</button>
      <span class="tile-count">current: <?= hh($ambient) ?></span>
    </form>
    <p class="note">🤖 Customer chatbot: <strong><?= $chatbot ? 'enabled' : 'disabled' ?></strong> · DeepSeek key: <strong><?= $deepseek ? 'configured' : 'not set (local answers only)' ?></strong></p>
  </div>

  <div class="tile-grid">
    <a class="tile" href="/admin/carriers.php">
      <div class="tile-icon">🤝</div>
      <h2 class="tile-title">Partner Carriers</h2>
      <p class="tile-desc">Logistics partners you work with. Add name, logo, tracking URL.</p>
      <span class="tile-count"><?= $carriers ?> carrier(s)</span>
    </a>
    <a class="tile" href="/admin/customers.php">
      <div class="tile-icon">🧑‍💼</div>
      <h2 class="tile-title">Customers</h2>
      <p class="tile-desc">Reusable customer directory. Link shipments to a customer record.</p>
      <span class="tile-count"><?= $customers ?> customer(s)</span>
    </a>
    <a class="tile" href="/admin/audit-log.php">
      <div class="tile-icon">📜</div>
      <h2 class="tile-title">Audit Log</h2>
      <p class="tile-desc">Every admin action, who did it, and when.</p>
      <span class="tile-count"><?= $auditCount ?> entries</span>
    </a>
    <a class="tile" href="/admin/backup.php">
      <div class="tile-icon">💾</div>
      <h2 class="tile-title">Database Backup</h2>
      <p class="tile-desc">Download a consistent snapshot of the SQLite database.</p>
      <span class="tile-count">one click</span>
    </a>
  </div>
</div>
</body></html>
