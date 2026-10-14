<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once BASE_PATH . '/app/Database.php';
require_once BASE_PATH . '/app/Auth.php';

use VibrantHub\Auth;
use VibrantHub\Database;

Auth::requireAdmin();

$db = Database::connection();

// CSV export (defensive: discard any buffered output, suppress errors)
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    @ini_set('display_errors', '0');
    @error_reporting(0);
    while (ob_get_level() > 0) { @ob_end_clean(); }
    ob_start();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="tracking-lookups-' . date('Ymd-His') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    $out = fopen('php://output', 'w');
    // UTF-8 BOM so Excel opens it correctly
    fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['ID', 'Tracking Number', 'Customer Name', 'Email', 'Phone', 'IP Address', 'User Agent', 'Submitted At']);
    foreach ($db->query('SELECT * FROM tracking_lookups ORDER BY id DESC') as $r) {
        fputcsv($out, [
            (string)($r['id'] ?? ''),
            (string)($r['tracking_number'] ?? ''),
            (string)($r['customer_name'] ?? ''),
            (string)($r['customer_email'] ?? ''),
            (string)($r['customer_phone'] ?? ''),
            (string)($r['ip_address'] ?? ''),
            (string)($r['user_agent'] ?? ''),
            (string)($r['created_at'] ?? ''),
        ]);
    }
    fclose($out);
    @ob_end_flush();
    exit;
}

// Detail view data
$detail = null;
$history = [];
$detailId = (int)($_GET['view'] ?? 0);
if ($detailId > 0) {
    $s = $db->prepare('SELECT * FROM tracking_lookups WHERE id = :id LIMIT 1');
    $s->execute([':id' => $detailId]);
    $detail = $s->fetch() ?: null;
    if ($detail && !empty($detail['customer_email'])) {
        $h = $db->prepare('SELECT id, tracking_number, created_at FROM tracking_lookups WHERE customer_email = :e ORDER BY id DESC LIMIT 20');
        $h->execute([':e' => $detail['customer_email']]);
        $history = $h->fetchAll();
    }
}

// Filter
$q = trim((string)($_GET['q'] ?? ''));
$rows = [];
if ($q !== '') {
    $stmt = $db->prepare("SELECT * FROM tracking_lookups WHERE tracking_number LIKE :q OR customer_name LIKE :q OR customer_email LIKE :q OR customer_phone LIKE :q ORDER BY id DESC LIMIT 300");
    $stmt->execute([':q' => '%' . $q . '%']);
    $rows = $stmt->fetchAll();
} else {
    $rows = $db->query('SELECT * FROM tracking_lookups ORDER BY id DESC LIMIT 300')->fetchAll();
}
$total = (int)$db->query('SELECT COUNT(*) FROM tracking_lookups')->fetchColumn();

function hh(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tracking Lookups — Nexora Logistics Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/app.css">
<style>
  body { background: #f1f5f9; }
  .wrap { max-width: 1200px; margin: 0 auto; padding: 1rem; }
  .panel { background: #fff; border-radius: 14px; padding: 1.5rem; box-shadow: 0 8px 24px rgba(11,29,58,.08); margin-bottom: 1.5rem; overflow-x: auto; }
  .toolbar { display: flex; gap: .75rem; flex-wrap: wrap; align-items: center; margin-bottom: 1rem; }
  .toolbar input[type=text] { flex: 1 1 240px; min-width: 0; padding: .65rem .85rem; border: 1px solid #cbd5e1; border-radius: 9px; font-family: inherit; font-size: .95rem; box-sizing: border-box; }
  table { width: 100%; border-collapse: collapse; font-size: .9rem; min-width: 780px; }
  th, td { padding: .7rem .6rem; text-align: left; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
  th { font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; color: #64748b; font-weight: 700; }
  tr:hover td { background: #f8fafc; }
  .row-link { color: #0b1d3a; text-decoration: none; font-weight: 600; }
  .detail-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-top: 1rem; }
  .detail-grid > div { padding: .75rem 1rem; background: #f8fafc; border-radius: 10px; }
  .detail-label { font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; color: #64748b; font-weight: 700; margin-bottom: .3rem; }
  .detail-value { font-size: .95rem; color: #0f172a; word-break: break-word; }
  .history-list { list-style: none; padding: 0; margin: .5rem 0 0; }
  .history-list li { padding: .5rem .75rem; border-radius: 8px; margin-bottom: 6px; background: #f8fafc; font-size: .88rem; }
  .history-list a { color: #0b1d3a; font-weight: 600; text-decoration: none; }
  .empty { padding: 2rem; text-align: center; color: #64748b; }
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
  <h1 style="margin:1rem 0 .25rem;">Tracking Lookups</h1>
  <p style="color:#475569;margin-top:0;">Every customer who has tracked a shipment. Total: <strong><?= $total ?></strong>.</p>

  <?php if ($detail): ?>
  <div class="panel">
    <a href="/admin/tracking-lookups.php<?= $q !== '' ? '?q=' . urlencode($q) : '' ?>" style="float:right;color:#64748b;text-decoration:none;font-size:1.4rem;line-height:1;">×</a>
    <h2 style="margin-top:0;">Lookup detail #<?= (int)$detail['id'] ?></h2>
    <div class="detail-grid">
      <div><div class="detail-label">Customer name</div><div class="detail-value"><?= hh($detail['customer_name']) ?></div></div>
      <div><div class="detail-label">Email</div><div class="detail-value"><?= hh($detail['customer_email']) ?></div></div>
      <div><div class="detail-label">Phone</div><div class="detail-value"><?= hh($detail['customer_phone']) ?></div></div>
      <div><div class="detail-label">Tracking number</div><div class="detail-value"><?= hh($detail['tracking_number']) ?></div></div>
      <div><div class="detail-label">IP address</div><div class="detail-value"><?= hh($detail['ip_address']) ?></div></div>
      <div><div class="detail-label">Submitted</div><div class="detail-value"><?= hh($detail['created_at']) ?></div></div>
      <div style="grid-column: 1 / -1;"><div class="detail-label">User agent</div><div class="detail-value" style="font-size:.82rem;color:#475569;"><?= hh($detail['user_agent']) ?></div></div>
    </div>
    <?php if (!empty($history)): ?>
      <h3 style="margin-top:1.5rem;">History from same email (<?= count($history) ?>)</h3>
      <ul class="history-list">
        <?php foreach ($history as $h): ?>
          <li>
            <a href="/admin/tracking-lookups.php?view=<?= (int)$h['id'] ?>">#<?= (int)$h['id'] ?> — <?= hh($h['tracking_number']) ?></a>
            <span style="color:#64748b;font-size:.8rem;margin-left:.5rem;"><?= hh($h['created_at']) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="panel">
    <form method="get" class="toolbar">
      <input type="text" name="q" value="<?= hh($q) ?>" placeholder="Search by name, email, phone, or tracking number…">
      <button type="submit" style="padding:.6rem 1.1rem;border-radius:9px;border:0;background:#f0b429;color:#0b1d3a;font-family:inherit;font-weight:600;font-size:.9rem;cursor:pointer;">Search</button>
      <?php if ($q !== ''): ?><a href="/admin/tracking-lookups.php" style="padding:.6rem 1.1rem;border-radius:9px;border:1px solid #cbd5e1;background:#fff;color:#0f172a;text-decoration:none;font-size:.9rem;">Clear</a><?php endif; ?>
      <a href="/admin/tracking-lookups.php?export=csv" style="margin-left:auto;padding:.6rem 1.1rem;border-radius:9px;border:1px solid #cbd5e1;background:#fff;color:#0f172a;text-decoration:none;font-size:.9rem;">⬇ Export CSV</a>
    </form>

    <?php if (empty($rows)): ?>
      <div class="empty">No lookups yet<?= $q !== '' ? ' matching "' . hh($q) . '"' : '' ?>.</div>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Customer</th>
            <th>Contact</th>
            <th>Tracking</th>
            <th>Submitted</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><strong><?= hh($r['customer_name']) ?></strong></td>
              <td>
                <div style="font-size:.85rem;"><?= hh($r['customer_email']) ?></div>
                <div style="font-size:.82rem;color:#64748b;"><?= hh($r['customer_phone']) ?></div>
              </td>
              <td><span style="font-family:ui-monospace,Menlo,monospace;background:#f1f5f9;padding:.2rem .5rem;border-radius:6px;font-size:.82rem;"><?= hh($r['tracking_number']) ?></span></td>
              <td style="font-size:.82rem;color:#64748b;"><?= hh($r['created_at']) ?></td>
              <td><a href="/admin/tracking-lookups.php?view=<?= (int)$r['id'] ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>" style="color:#0b1d3a;font-weight:600;text-decoration:none;font-size:.85rem;">View →</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
