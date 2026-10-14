<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once BASE_PATH . '/app/Database.php';
require_once BASE_PATH . '/app/Auth.php';
use VibrantHub\Auth; use VibrantHub\Database;
Auth::requireAdmin();
function hh(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES); }
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Content — Nexora Admin</title>
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
  <h1 style="margin:1rem 0 .25rem;">Content</h1>
  <p style="color:#475569;margin-top:0;">Every word your customers see on the public website.</p>
  <div class="tile-grid">
    <a class="tile" href="/admin/site-content.php">
      <div class="tile-icon">📝</div>
      <h2 class="tile-title">Site Content</h2>
      <p class="tile-desc">Homepage headlines, service descriptions, counters labels, contact text, footer.</p>
    </a>
    <a class="tile" href="/admin/customer-messages.php">
      <div class="tile-icon">💬</div>
      <h2 class="tile-title">Customer Messages</h2>
      <p class="tile-desc">Wording shown on the tracking timeline and map popups (customs, delays, delivered, etc.).</p>
    </a>
  </div>
</div>
</body></html>
