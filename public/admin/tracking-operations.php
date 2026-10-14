<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once BASE_PATH . '/app/Database.php';
require_once BASE_PATH . '/app/Auth.php';

use VibrantHub\Auth;
use VibrantHub\Database;

Auth::requireAdmin();

$db = Database::connection();

$stmt = $db->query(
    'SELECT
        s.*,
        tc.is_running,
        tc.control_speed,
        tc.tracking_mode AS control_mode
     FROM shipments s
     LEFT JOIN tracking_controls tc
        ON tc.shipment_id = s.id
     ORDER BY s.updated_at DESC, s.id DESC'
);

$shipments = $stmt->fetchAll();

function h(string|int|float|null $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function statusClass(string $status): string
{
    return match (strtolower($status)) {
        'delivered' => 'success',
        'in_transit' => 'info',
        'delayed', 'held', 'on_hold' => 'warning',
        'cancelled', 'failed' => 'danger',
        default => 'muted',
    };
}

function transportIcon(string $type): string
{
    return match (strtolower($type)) {
        'airplane', 'plane', 'air' => '✈️',
        'ship', 'ocean', 'sea' => '🚢',
        'train', 'rail' => '🚆',
        default => '🚚',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Nexora Logistics - Tracking Operations</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, Helvetica, sans-serif;
    background: #f1f5f9;
    color: #0f172a;
}

.container {
    width: min(1400px, 94%);
    margin: 35px auto;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    margin-bottom: 25px;
}

.header h1 {
    margin: 0 0 6px;
    font-size: 30px;
}

.header p {
    margin: 0;
    color: #64748b;
}

.back {
    text-decoration: none;
    color: #2563eb;
    font-weight: 700;
}

.card {
    background: #fff;
    border-radius: 18px;
    padding: 24px;
    box-shadow: 0 8px 30px rgba(15, 23, 42, .07);
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 950px;
}

th,
td {
    text-align: left;
    padding: 16px 14px;
    border-bottom: 1px solid #e2e8f0;
    vertical-align: middle;
}

th {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: #64748b;
    background: #f8fafc;
}

.tracking {
    font-weight: 800;
    color: #2563eb;
}

.route {
    font-weight: 600;
}

.route small {
    display: block;
    color: #64748b;
    margin-top: 4px;
}

.badge {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 800;
}

.badge.success {
    background: #dcfce7;
    color: #166534;
}

.badge.info {
    background: #dbeafe;
    color: #1d4ed8;
}

.badge.warning {
    background: #fef3c7;
    color: #92400e;
}

.badge.danger {
    background: #fee2e2;
    color: #991b1b;
}

.badge.muted {
    background: #e2e8f0;
    color: #475569;
}

.control {
    display: inline-block;
    padding: 9px 13px;
    border-radius: 9px;
    background: #0f172a;
    color: #fff;
    text-decoration: none;
    font-weight: 700;
    font-size: 13px;
}

.control:hover {
    background: #1e293b;
}

.empty {
    text-align: center;
    padding: 50px 20px;
    color: #64748b;
}
</style>
<link rel="stylesheet" href="/assets/css/app.css">
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
    <a href="/admin/shipments-hub.php" class="active">Shipments</a>
    <a href="/admin/people.php">People</a>
    <a href="/admin/content.php">Content</a>
    <a href="/admin/settings.php">Settings</a>
    <a href="/" target="_blank" rel="noopener" style="color:#ffd166 !important;">🌐 View Site</a>
    <a href="/admin/login.php?logout=1">Logout</a>
  </nav>
</header>

<div class="container">

    <div class="header">
        <div>
            <h1>Tracking Operations</h1>
            <p>Monitor and control active shipments from one operations center.</p>
        </div>

        <a class="back" href="/admin/index.php">← Dashboard</a>
    </div>

    <div class="card">

        <?php if (!$shipments): ?>

            <div class="empty">
                <h3>No shipments available</h3>
                <p>Create a shipment first, then it will appear here for tracking operations.</p>
            </div>

        <?php else: ?>

            <table>
                <thead>
                    <tr>
                        <th>Tracking</th>
                        <th>Customer</th>
                        <th>Route</th>
                        <th>Transport</th>
                        <th>Status</th>
                        <th>Speed</th>
                        <th>ETA</th>
                        <th>Operation</th>
                    </tr>
                </thead>

                <tbody>

                <?php foreach ($shipments as $shipment): ?>

                    <tr>

                        <td>
                            <div class="tracking">
                                <?= h($shipment['tracking_number']) ?>
                            </div>
                        </td>

                        <td>
                            <?= h($shipment['customer_name']) ?>
                        </td>

                        <td>
                            <div class="route">
                                <?= h($shipment['origin']) ?>
                                →
                                <?= h($shipment['destination']) ?>
                            </div>
                            <small>
                                Updated <?= h($shipment['updated_at']) ?>
                            </small>
                        </td>

                        <td>
                            <?= transportIcon((string) $shipment['transport_type']) ?>
                            <?= h($shipment['transport_type']) ?>
                        </td>

                        <td>
                            <span class="badge <?= statusClass((string) $shipment['status']) ?>">
                                <?= e(str_replace('_', ' ', ucfirst((string) $shipment['status']))) ?>
                            </span>
                        </td>

                        <td>
                            <?= number_format((float) ($shipment['speed'] ?? 0), 1) ?> km/h
                        </td>

                        <td>
                            <?= h($shipment['eta'] ?: $shipment['estimated_delivery'] ?: '—') ?>
                        </td>

                        <td>
                            <a
                                class="control"
                                href="/admin/shipment-control.php?id=<?= (int) $shipment['id'] ?>"
                            >
                                Open Controls
                            </a>
                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>
            </table>

        <?php endif; ?>

    </div>

</div>
</body>
</html>
