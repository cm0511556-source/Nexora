<?php
declare(strict_types=1);

/** Audit log viewer — every recorded admin action. */

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once BASE_PATH . '/app/Database.php';
require_once BASE_PATH . '/app/Auth.php';

use VibrantHub\Auth;
use VibrantHub\Database;

Auth::requireAdmin();

$db = Database::connection();

$search = trim((string) ($_GET['q'] ?? ''));
$entity = trim((string) ($_GET['entity'] ?? ''));

$sql = 'SELECT * FROM audit_log WHERE 1=1';
$params = [];
if ($search !== '') {
    $sql .= ' AND (action LIKE :q OR admin_name LIKE :q OR details LIKE :q OR entity_id LIKE :q)';
    $params[':q'] = '%' . $search . '%';
}
if ($entity !== '') {
    $sql .= ' AND entity_type = :et';
    $params[':et'] = $entity;
}
$sql .= ' ORDER BY id DESC LIMIT 400';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$entities = $db->query('SELECT DISTINCT entity_type FROM audit_log WHERE entity_type IS NOT NULL ORDER BY entity_type')->fetchAll(PDO::FETCH_COLUMN);

function hh(?string $v): string { return htmlspecialchars((string) $v, ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Audit Log — Nexora Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
<style>
  body { background:#f1f5f9; }
  .wrap { max-width:1150px; margin:0 auto; padding:1rem; }
  .panel { background:#fff; border-radius:14px; padding:1.5rem; box-shadow:0 8px 24px rgba(11,29,58,.08); }
  table { width:100%; border-collapse:collapse; font-size:.86rem; }
  th, td { padding:.6rem .55rem; text-align:left; border-bottom:1px solid #eef2f7; vertical-align:top; }
  th { font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; color:#64748b; }
  code { background:#f1f5f9; padding:1px 5px; border-radius:5px; font-size:.82rem; }
  .tag { background:rgba(37,99,235,.12); color:#1e40af; padding:.12rem .45rem; border-radius:999px; font-size:.72rem; font-weight:700; }
  input[type=text] { padding:.5rem .7rem; border:1px solid #cbd5e1; border-radius:9px; font-family:inherit; }
  .btn-sm { padding:.45rem .8rem; border-radius:8px; border:1px solid #cbd5e1; background:#fff; cursor:pointer; text-decoration:none; color:#0f172a; display:inline-block; font-size:.82rem; }
</style>
    <script src="<?= asset('js/nx-labels.js') ?>"></script>
</head>
<body>
<header class="navbar">
  <a href="/admin/index.php" class="logo" style="text-decoration:none;color:inherit;"><span class="logo-badge">N</span><span class="logo-text">Nexora Admin</span></a>
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
  <h1 style="margin:1rem 0 .25rem;">Audit Log</h1>
  <p style="color:#475569;margin-top:0;">Who did what, and when. Most recent 400 actions.</p>

  <div class="panel">
    <form method="get" style="display:flex;gap:.6rem;flex-wrap:wrap;margin-bottom:1rem;">
      <input type="text" name="q" placeholder="Search action, admin, details…" value="<?= hh($search) ?>" style="min-width:240px;">
      <select name="entity" style="padding:.5rem .7rem;border:1px solid #cbd5e1;border-radius:9px;font-family:inherit;">
        <option value="">All entities</option>
        <?php foreach ($entities as $en): ?>
          <option value="<?= hh($en) ?>" <?= $entity === $en ? 'selected' : '' ?>><?= hh($en) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn-sm">Filter</button>
      <a class="btn-sm" href="/admin/audit-log.php">Reset</a>
    </form>

    <?php if (!$rows): ?>
      <p style="color:#64748b;">No audit entries yet.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table>
      <thead><tr><th>When (UTC)</th><th>Admin</th><th>Action</th><th>Entity</th><th>Details</th><th>IP</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td style="white-space:nowrap;"><?= hh($r['created_at']) ?></td>
            <td><?= hh($r['admin_name'] ?: '—') ?></td>
            <td><code><?= hh($r['action']) ?></code></td>
            <td><?= $r['entity_type'] ? '<span class="tag">' . hh($r['entity_type']) . '</span>' . ($r['entity_id'] ? ' #' . hh($r['entity_id']) : '') : '—' ?></td>
            <td><?= hh($r['details']) ?></td>
            <td style="color:#94a3b8;"><?= hh($r['ip_address']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
