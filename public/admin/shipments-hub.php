<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once BASE_PATH . '/app/Database.php';
require_once BASE_PATH . '/app/Auth.php';
use VibrantHub\Auth; use VibrantHub\Database;
Auth::requireAdmin();
$db = Database::connection();
$total = (int)$db->query('SELECT COUNT(*) FROM shipments')->fetchColumn();
$moving = (int)$db->query("SELECT COUNT(*) FROM shipments WHERE status='in_transit'")->fetchColumn();
$held = (int)$db->query("SELECT COUNT(*) FROM shipments WHERE status IN ('held','on_hold','seized','delayed')")->fetchColumn();
function hh(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES); }
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Shipments — Nexora Admin</title>
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
.stats{display:grid;gap:.75rem;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));margin-bottom:1.5rem}
.stat{background:linear-gradient(135deg,#0b1d3a,#132a52);color:#fff;border-radius:14px;padding:1rem;text-align:center}
.stat b{display:block;font-size:1.8rem;color:#f0b429;line-height:1}
.stat span{display:block;font-size:.75rem;text-transform:uppercase;letter-spacing:.06em;margin-top:.4rem;opacity:.85}
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
  <h1 style="margin:1rem 0 .5rem;">Shipments</h1>
  <div class="stats">
    <div class="stat"><b><?= $total ?></b><span>Total</span></div>
    <div class="stat"><b><?= $moving ?></b><span>In transit</span></div>
    <div class="stat"><b><?= $held ?></b><span>Needs attention</span></div>
  </div>
  <div class="tile-grid">
    <a class="tile" href="/admin/shipments.php">
      <div class="tile-icon">➕</div>
      <h2 class="tile-title">Create a Shipment</h2>
      <p class="tile-desc">Register a new shipment: customer, origin, destination, transport type, route.</p>
    </a>
    <a class="tile" href="/admin/shipment-management.php">
      <div class="tile-icon">📦</div>
      <h2 class="tile-title">Manage Shipments</h2>
      <p class="tile-desc">Search, edit, and open any shipment. Click a tracking number to control it.</p>
    </a>
    <a class="tile" href="/admin/tracking-operations.php">
      <div class="tile-icon">🗺️</div>
      <h2 class="tile-title">Tracking Operations</h2>
      <p class="tile-desc">Monitor every active shipment on one screen. Start, pause, resume movement.</p>
    </a>

    <a class="tile" href="/admin/fleet.php">
      <div class="tile-icon">🛰️</div>
      <h2 class="tile-title">Fleet View</h2>
      <p class="tile-desc">Every active shipment on one live map. Search, filter, and jump to any shipment.</p>
    </a>
  </div>
</div>
</body></html>
