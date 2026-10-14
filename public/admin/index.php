<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . "/config/config.php";
require_once BASE_PATH . "/app/Database.php";
require_once BASE_PATH . "/app/Auth.php";

use VibrantHub\Auth;
use VibrantHub\Database;

Auth::requireAdmin();

$db = Database::connection();
$admin = Auth::admin();

$totalShipments = (int) $db->query(
    "SELECT COUNT(*) FROM shipments"
)->fetchColumn();

$inTransit = (int) $db->query(
    "SELECT COUNT(*) FROM shipments WHERE status = 'in_transit'"
)->fetchColumn();

$delivered = (int) $db->query(
    "SELECT COUNT(*) FROM shipments WHERE status = 'delivered'"
)->fetchColumn();

$pending = (int) $db->query(
    "SELECT COUNT(*) FROM shipments WHERE status = 'pending'"
)->fetchColumn();

$held = (int) $db->query(
    "SELECT COUNT(*) FROM shipments WHERE status IN ('held', 'on_hold', 'delayed')"
)->fetchColumn();

$deliveredWeek = (int) $db->query(
    "SELECT COUNT(*) FROM shipments WHERE status = 'delivered' AND updated_at >= datetime('now', '-7 day')"
)->fetchColumn();

$delayed = (int) $db->query(
    "SELECT COUNT(*) FROM shipments WHERE status = 'delayed'"
)->fetchColumn();

$activeNow = (int) $db->query(
    "SELECT COUNT(*) FROM shipments WHERE status IN ('in_transit', 'pending', 'on_hold', 'held', 'delayed')"
)->fetchColumn();

$recentShipments = $db->query(
    "SELECT
        id,
        tracking_number,
        customer_name,
        transport_type,
        origin,
        destination,
        status,
        speed,
        current_lat,
        current_lng,
        updated_at
     FROM shipments
     ORDER BY updated_at DESC
     LIMIT 8"
)->fetchAll();

$recentEvents = $db->query(
    "SELECT
        te.title,
        te.description,
        te.location,
        te.event_time,
        s.tracking_number
     FROM tracking_events te
     INNER JOIN shipments s ON s.id = te.shipment_id
     ORDER BY te.event_time DESC
     LIMIT 6"
)->fetchAll();

function statusLabel(string $status): string
{
    return ucwords(str_replace('_', ' ', $status));
}

function transportIcon(string $type): string
{
    switch ($type) {
        case 'flight':
            return '✈️';
        case 'ship':
            return '🚢';
        case 'train':
            return '🚆';
        default:
            return '🚚';
    }
}

function statusClass(string $status): string
{
    switch ($status) {
        case 'delivered':
            return 'success';
        case 'in_transit':
            return 'info';
        case 'held':
        case 'on_hold':
        case 'delayed':
            return 'warning';
        default:
            return 'pending';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Nexora Logistics Admin Dashboard</title>

    <link rel="stylesheet" href="<?= asset('css/app.css') ?>">
<style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: #f3f6fb;
            color: #172033;
        }

        a {
            text-decoration: none;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .brand-logo {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }

        .brand h1 {
            margin: 0;
            font-size: 20px;
        }

        .brand p {
            margin: 4px 0 0;
            color: #aeb9ca;
            font-size: 13px;
        }

        .admin-box {
            text-align: right;
            font-size: 13px;
        }

        .admin-name {
            font-weight: bold;
            color: white;
        }

        .admin-role {
            color: #98a2b3;
            margin-top: 3px;
        }

        .container {
            width: 100%;
            max-width: 1250px;
            margin: auto;
            padding: 24px;
        }

        .welcome {
            margin-bottom: 22px;
        }

        .welcome h2 {
            margin: 0;
            font-size: 25px;
        }

        .welcome p {
            margin: 7px 0 0;
            color: #667085;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 14px;
            margin-bottom: 24px;
        }

        .stat {
            background: white;
            border: 1px solid #e4e7ec;
            border-radius: 15px;
            padding: 18px;
            box-shadow: 0 3px 12px rgba(16, 24, 40, .05);
        }

        .stat-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .stat-title {
            color: #667085;
            font-size: 13px;
            font-weight: 600;
        }

        .stat-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: #eef4ff;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .stat-number {
            font-size: 28px;
            font-weight: 800;
            margin-top: 12px;
        }

        .grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
        }

        .panel {
            background: white;
            border: 1px solid #e4e7ec;
            border-radius: 15px;
            box-shadow: 0 3px 12px rgba(16, 24, 40, .05);
            overflow: hidden;
        }

        .panel-header {
            padding: 18px 20px;
            border-bottom: 1px solid #eaecf0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .panel-header h3 {
            margin: 0;
            font-size: 17px;
        }

        .panel-header a {
            color: #2563eb;
            font-size: 13px;
            font-weight: bold;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px;
        }

        th,
        td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid #f0f2f5;
            font-size: 13px;
        }

        th {
            background: #fafbfc;
            color: #667085;
            font-size: 12px;
            text-transform: uppercase;
        }

        .tracking-number {
            color: #175cd3;
            font-weight: bold;
        }

        .route {
            color: #667085;
            max-width: 230px;
        }

        .status {
            display: inline-flex;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: bold;
        }

        .status.success {
            background: #ecfdf3;
            color: #027a48;
        }

        .status.info {
            background: #eff8ff;
            color: #175cd3;
        }

        .status.warning {
            background: #fffaeb;
            color: #b54708;
        }

        .status.pending {
            background: #f2f4f7;
            color: #475467;
        }

        .events {
            padding: 5px 20px 15px;
        }

        .event {
            padding: 15px 0;
            border-bottom: 1px solid #eaecf0;
        }

        .event:last-child {
            border-bottom: none;
        }

        .event-title {
            font-weight: bold;
            font-size: 14px;
        }

        .event-meta {
            color: #667085;
            font-size: 12px;
            margin-top: 5px;
        }

        .event-description {
            color: #475467;
            font-size: 13px;
            margin-top: 6px;
            line-height: 1.45;
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-top: 20px;
        }

        .action {
            background: white;
            border: 1px solid #e4e7ec;
            border-radius: 14px;
            padding: 18px;
            color: #172033;
            box-shadow: 0 3px 12px rgba(16, 24, 40, .04);
            transition: .2s;
        }

        .action:hover {
            transform: translateY(-2px);
            border-color: #98a2b3;
        }

        .action-icon {
            font-size: 23px;
            margin-bottom: 12px;
        }

        .action-title {
            font-weight: bold;
            font-size: 14px;
        }

        .action-text {
            color: #667085;
            font-size: 12px;
            margin-top: 5px;
            line-height: 1.4;
        }

        .footer {
            text-align: center;
            color: #98a2b3;
            font-size: 12px;
            padding: 28px 10px;
        }

        @media (max-width: 1000px) {
            .stats {
                grid-template-columns: repeat(3, 1fr);
            }

            .grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 700px) {

            .admin-box {
                text-align: left;
            }

            .container {
                padding: 15px;
            }

            .stats {
                grid-template-columns: repeat(2, 1fr);
            }

            .quick-actions {
                grid-template-columns: repeat(2, 1fr);
            }

            .welcome h2 {
                font-size: 21px;
            }
        }

        @media (max-width: 430px) {
            .stats {
                grid-template-columns: 1fr 1fr;
            }

            .quick-actions {
                grid-template-columns: 1fr;
            }

            .stat-number {
                font-size: 24px;
            }
        }
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
    <a href="/admin/index.php" class="active">Dashboard</a>
    <a href="/admin/shipments-hub.php">Shipments</a>
    <a href="/admin/people.php">People</a>
    <a href="/admin/content.php">Content</a>
    <a href="/admin/settings.php">Settings</a>
    <a href="/" target="_blank" rel="noopener" style="color:#ffd166 !important;">🌐 View Site</a>
    <a href="/admin/login.php?logout=1">Logout</a>
  </nav>
  <div style="margin-left:auto;color:#dbe4f3;font-size:.85rem;">
    <strong style="color:#fff;"><?= htmlspecialchars($admin['name'] ?? 'Administrator', ENT_QUOTES, 'UTF-8') ?></strong>
    · <?= htmlspecialchars($admin['role'] ?? 'admin', ENT_QUOTES, 'UTF-8') ?>
  </div>
</header>

<main class="container">

    <section class="welcome" style="margin-top:24px;">
  <h2>Where do you want to go?</h2>
  <p>Each area groups the pages that belong together. Click any card.</p>
</section>

<section class="stats" style="margin-bottom:1.5rem;">
  <div class="stat">
    <div class="stat-top"><span class="stat-title">Total shipments</span><span class="stat-icon">📦</span></div>
    <div class="stat-number"><?= $totalShipments ?></div>
  </div>
  <div class="stat">
    <div class="stat-top"><span class="stat-title">Active now</span><span class="stat-icon">🚚</span></div>
    <div class="stat-number"><?= $activeNow ?></div>
  </div>
  <div class="stat">
    <div class="stat-top"><span class="stat-title">Delivered this week</span><span class="stat-icon">✅</span></div>
    <div class="stat-number"><?= $deliveredWeek ?></div>
  </div>
  <div class="stat">
    <div class="stat-top"><span class="stat-title">In transit</span><span class="stat-icon">✈️</span></div>
    <div class="stat-number"><?= $inTransit ?></div>
  </div>
  <div class="stat">
    <div class="stat-top"><span class="stat-title">Delayed / held</span><span class="stat-icon">⚠️</span></div>
    <div class="stat-number"><?= max($delayed, $held) ?></div>
  </div>
</section>

<style>
.hub-grid{display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));margin-bottom:1.5rem;}
.hub-card{display:block;background:#fff;border-radius:14px;padding:1.5rem;text-decoration:none;color:inherit;border:1px solid #e5e7eb;box-shadow:0 8px 24px rgba(11,29,58,.06);transition:.15s;}
.hub-card:hover{transform:translateY(-3px);box-shadow:0 12px 30px rgba(11,29,58,.12);}
.hub-card .hub-icon{font-size:2rem;margin-bottom:.6rem;}
.hub-card .hub-title{font-weight:800;color:#0b1d3a;font-size:1.15rem;margin:0 0 .35rem;}
.hub-card .hub-desc{color:#5b6b85;font-size:.92rem;margin:0;}
.hub-card .hub-pill{display:inline-block;margin-top:.75rem;background:rgba(240,180,41,.15);color:#854d0e;padding:.2rem .65rem;border-radius:999px;font-size:.78rem;font-weight:700;}
</style>

<section class="hub-grid">
  <a class="hub-card" href="/admin/shipments-hub.php">
    <div class="hub-icon">📦</div>
    <div class="hub-title">Shipments</div>
    <p class="hub-desc">Create, manage, monitor, and control every shipment. Live map, holds, timeline, media.</p>
    <span class="hub-pill"><?= $totalShipments ?> total · <?= $inTransit ?> in transit · <?= $held ?> flagged</span>
  </a>
  <a class="hub-card" href="/admin/people.php">
    <div class="hub-icon">👥</div>
    <div class="hub-title">People</div>
    <p class="hub-desc">Customer lookups and the reviews they write. See who is tracking what.</p>
  </a>
  <a class="hub-card" href="/admin/content.php">
    <div class="hub-icon">📝</div>
    <div class="hub-title">Content</div>
    <p class="hub-desc">Every word your customers see — homepage text, timeline messages, popups.</p>
  </a>
  <a class="hub-card" href="/admin/settings.php">
    <div class="hub-icon">⚙️</div>
    <div class="hub-title">Settings</div>
    <p class="hub-desc">Partner carriers and system diagnostics.</p>
  </a>
</section>

    <section style="margin-top:2rem;">
  <h2 style="color:#0b1d3a;font-size:1.25rem;margin:0 0 .35rem;">📖 How each page works</h2>
  <p style="color:#5b6b85;margin:0 0 1rem;font-size:.92rem;">Quick reference — every admin page and what it's for.</p>

  <style>
  .uses{display:grid;gap:.85rem;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));}
  .uses .group{background:#fff;border-radius:14px;padding:1.25rem;border:1px solid #e5e7eb;box-shadow:0 6px 18px rgba(11,29,58,.05);}
  .uses .group h3{margin:0 0 .75rem;font-size:1rem;color:#0b1d3a;}
  .uses .row{display:flex;gap:.6rem;padding:.55rem 0;border-bottom:1px solid #f1f5f9;font-size:.88rem;}
  .uses .row:last-child{border-bottom:0;}
  .uses .row b{color:#0b1d3a;font-weight:700;flex:0 0 42%;}
  .uses .row span{color:#5b6b85;flex:1;}
  .uses .row a{color:#0b1d3a;text-decoration:none;}
  .uses .row a:hover{text-decoration:underline;}
  </style>

  <div class="uses">
    <div class="group">
      <h3>📦 Shipments</h3>
      <div class="row"><b><a href="/admin/shipments-hub.php">Hub</a></b><span>Start here. Live counts + links to the three tasks below.</span></div>
      <div class="row"><b><a href="/admin/shipments.php">Create a Shipment</a></b><span>Register a new shipment: customer, route, transport, ETA.</span></div>
      <div class="row"><b><a href="/admin/shipment-management.php">Manage All</a></b><span>Search, edit, open any shipment. Click its tracking number to control it.</span></div>
      <div class="row"><b><a href="/admin/tracking-operations.php">Operations</a></b><span>Monitor every active shipment on one screen. Start / pause / resume.</span></div>
      <div class="row"><b>Inside a shipment</b><span>Tabs: Summary · Live Map · Movement · History · Issues · Media.</span></div>
    </div>

    <div class="group">
      <h3>👥 People</h3>
      <div class="row"><b><a href="/admin/tracking-lookups.php">Tracking Lookups</a></b><span>Every customer who tracked a shipment. Search + CSV export + detail view.</span></div>
      <div class="row"><b><a href="/admin/testimonials.php">Testimonials</a></b><span>Customer reviews shown on the homepage carousel.</span></div>
    </div>

    <div class="group">
      <h3>📝 Content</h3>
      <div class="row"><b><a href="/admin/site-content.php">Site Content</a></b><span>Homepage headlines, service texts, counters labels, contact copy, footer.</span></div>
      <div class="row"><b><a href="/admin/customer-messages.php">Customer Messages</a></b><span>Wording on the tracking timeline + map popups (customs, delay, delivered…).</span></div>
    </div>

    <div class="group">
      <h3>⚙️ Settings</h3>
      <div class="row"><b><a href="/admin/carriers.php">Carriers</a></b><span>Partner logistics companies. Add logo, contacts, tracking URL.</span></div>
      <div class="row"><b><a href="/admin/dashboard-test.php">Diagnostics</a></b><span>Check auth + database health. Only needed if something looks broken.</span></div>
    </div>

    <div class="group">
      <h3>🌐 Public Site</h3>
      <div class="row"><b><a href="/" target="_blank" rel="noopener">Homepage</a></b><span>What customers see first. Text comes from Site Content.</span></div>
      <div class="row"><b><a href="/track.php" target="_blank" rel="noopener">Track Page</a></b><span>Live map, timeline, media, weather. Reached from the homepage form.</span></div>
    </div>
  </div>
</section>
<div class="footer">
        Nexora Logistics Shipping & Tracking · Admin Console
    </div>

</main>

</body>
</html>
