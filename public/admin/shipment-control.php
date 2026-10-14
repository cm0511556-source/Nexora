<?php
declare(strict_types=1);
@ini_set('display_errors', '0');
@error_reporting(E_ERROR | E_PARSE);

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once BASE_PATH . '/app/Database.php';
require_once BASE_PATH . '/app/Auth.php';

use VibrantHub\Auth;
use VibrantHub\Database;

Auth::requireAdmin();

$db = Database::connection();

$rawShipmentId = $_GET['id'] ?? null;
$shipmentId = filter_var($rawShipmentId, FILTER_VALIDATE_INT);

if (!$shipmentId || $shipmentId < 1) {
    http_response_code(400);
    exit('Invalid shipment ID.');
}

$stmt = $db->prepare(
    'SELECT * FROM shipments WHERE id = :id LIMIT 1'
);
$stmt->execute(['id' => $shipmentId]);
$shipment = $stmt->fetch();

if (!$shipment) {
    http_response_code(404);
    exit('Shipment not found.');
}

$waypointStmt = $db->prepare(
    'SELECT * FROM shipment_waypoints
     WHERE shipment_id = :shipment_id
     ORDER BY sequence ASC'
);
$waypointStmt->execute([
    'shipment_id' => $shipmentId
]);
$waypoints = $waypointStmt->fetchAll();

$controlStmt = $db->prepare(
    'SELECT * FROM tracking_controls
     WHERE shipment_id = :shipment_id
     LIMIT 1'
);
$controlStmt->execute([
    'shipment_id' => $shipmentId
]);
$control = $controlStmt->fetch();

if (!$control) {
    $insertControl = $db->prepare(
        'INSERT INTO tracking_controls
        (shipment_id, is_running, control_speed, tracking_mode)
        VALUES (:shipment_id, 0, :speed, :mode)'
    );

    $insertControl->execute([
        'shipment_id' => $shipmentId,
        'speed' => (float) $shipment['speed'],
        'mode' => (string) $shipment['tracking_mode']
    ]);

    $controlStmt->execute([
        'shipment_id' => $shipmentId
    ]);

    $control = $controlStmt->fetch();
}

$eventStmt = $db->prepare(
    'SELECT * FROM tracking_events
     WHERE shipment_id = :shipment_id
     ORDER BY event_time DESC, id DESC
     LIMIT 30'
);
$eventStmt->execute([
    'shipment_id' => $shipmentId
]);
$events = $eventStmt->fetchAll();

function vhEscape($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function vhTransportIcon($type, $vehicleName = ''): string {
    $v = strtolower($vehicleName . ' ' . $type);
    if (preg_match('/air|plane|flight|jet|aircraft/', $v)) return '✈️';
    if (preg_match('/sea|ship|boat|vessel|container|cargo/', $v)) return '🚢';
    if (preg_match('/rail|train/', $v)) return '🚆';
    if (preg_match('/bike|motorcycle|scooter|courier/', $v)) return '🏍️';
    if (preg_match('/van|last.?mile|delivery/', $v)) return '📦';
    if (preg_match('/truck|lorry|road|vehicle/', $v)) return '🚚';
    return '🚚';
}

function vhStatusClass(string $status): string
{
    switch (strtolower($status)) {
        case 'delivered':
        case 'completed':
            return 'success';
        case 'in_transit':
        case 'moving':
            return 'info';
        case 'on_hold':
        case 'held':
        case 'paused':
            return 'warning';
        case 'cancelled':
        case 'failed':
            return 'danger';
        default:
            return 'muted';
    }
}

$status = (string) $shipment['status'];
$isRunning = (int) $control['is_running'] === 1;
$mode = (string) (
    $control['tracking_mode']
    ?? $shipment['tracking_mode']
);
$controlSpeed = (float) (
    $control['control_speed']
    ?? $shipment['speed']
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Shipment Control -
        <?= vhEscape($shipment['tracking_number']) ?>
    </title>

    <link
        rel="stylesheet"
        href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    >

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f4f7fb;
            color: #172033;
            font-family: Arial, Helvetica, sans-serif;
        }

        .topbar {
            background: #101828;
            color: #fff;
            padding: 15px 20px;
        }

        .topbar-inner {
            max-width: 1250px;
            margin: auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .brand {
            font-size: 20px;
            font-weight: 800;
        }

        .back {
            color: #fff;
            text-decoration: none;
            background: #344054;
            padding: 9px 13px;
            border-radius: 8px;
            font-size: 14px;
        }

        .container {
            max-width: 1250px;
            margin: 24px auto;
            padding: 0 16px 40px;
        }

        .card {
            background: #fff;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 18px;
            box-shadow: 0 4px 18px rgba(16,24,40,.06);
        }

        .header {
            padding: 24px;
        }

        .header-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
        }

        .tracking {
            margin: 0 0 7px;
            font-size: 27px;
            font-weight: 800;
        }

        .route {
            color: #667085;
            font-size: 14px;
        }

        .badge {
            display: inline-block;
            padding: 7px 11px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
            white-space: nowrap;
        }

        .success {
            background: #dcfae6;
            color: #067647;
        }

        .info {
            background: #dff4ff;
            color: #026aa2;
        }

        .warning {
            background: #fff3cd;
            color: #9a6700;
        }

        .danger {
            background: #fee4e2;
            color: #b42318;
        }

        .muted {
            background: #eef2f6;
            color: #475467;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 18px;
        }

        .full {
            grid-column: 1 / -1;
        }

        h2 {
            margin: 0 0 15px;
            font-size: 19px;
        }

        h3 {
            margin: 0 0 8px;
            font-size: 15px;
        }

        .control-status {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            padding: 15px;
            background: #f8fafc;
            border-radius: 12px;
            margin-bottom: 15px;
        }

        .running {
            color: #067647;
            font-weight: 800;
        }

        .stopped {
            color: #b42318;
            font-weight: 800;
        }

        .dot {
            display: inline-block;
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: currentColor;
            margin-right: 5px;
        }

        .buttons {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
        }

        button {
            border: 0;
            border-radius: 9px;
            padding: 12px;
            font-weight: 700;
            cursor: pointer;
            font-size: 14px;
        }

        .primary {
            background: #175cd3;
            color: #fff;
        }

        .dark {
            background: #344054;
            color: #fff;
        }

        .warning-btn {
            background: #f79009;
            color: #fff;
        }

        .light {
            background: #eef2f6;
            color: #172033;
        }

        button:disabled {
            opacity: .5;
            cursor: not-allowed;
        }

        .field {
            margin-bottom: 13px;
        }

        label {
            display: block;
            margin-bottom: 6px;
            color: #344054;
            font-size: 13px;
            font-weight: 700;
        }

        input,
        select,
        textarea {
            width: 100%;
            border: 1px solid #d0d5dd;
            border-radius: 9px;
            padding: 11px;
            font: inherit;
            background: #fff;
        }

        textarea {
            min-height: 90px;
            resize: vertical;
        }

        .row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .metrics {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 12px;
            margin-top: 18px;
        }

        .metric {
            background: #f8fafc;
            border: 1px solid #eaecf0;
            border-radius: 12px;
            padding: 14px;
        }

        .metric-label {
            display: block;
            color: #667085;
            font-size: 12px;
            margin-bottom: 6px;
        }

        .metric-value {
            display: block;
            font-size: 17px;
            font-weight: 800;
        }

        #globalTrackingMap {
            width: 100%;
            height: 560px;
            border-radius: 15px;
            overflow: hidden;
            background: #e2e8f0;
        }

        .map-note {
            margin-top: 12px;
            color: #667085;
            font-size: 13px;
        }

        .location-box {
            background: #f8fafc;
            border-radius: 12px;
            padding: 15px;
            margin-top: 15px;
        }

        .route-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .route-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 13px;
            border: 1px solid #eaecf0;
            border-radius: 12px;
        }

        .route-icon {
            width: 36px;
            height: 36px;
            display: grid;
            place-items: center;
            background: #f2f4f7;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .route-name {
            font-weight: 800;
        }

        .route-meta {
            color: #667085;
            font-size: 12px;
            margin-top: 3px;
        }

        .nx-tl-scroll{max-height:360px;overflow-y:auto;padding-right:6px;}
.nx-tl-scroll::-webkit-scrollbar{width:6px;}
.nx-tl-scroll::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:3px;}
.nx-tl-scroll::-webkit-scrollbar-track{background:#f1f5f9;}
.nx-tl-more{display:block;text-align:center;margin:12px 0 0;padding:.55rem 1.2rem;border-radius:9px;border:1px solid #cbd5e1;background:#fff;color:#0b1d3a;font-weight:600;font-size:.85rem;text-decoration:none;}
.nx-tl-more:hover{background:#f1f5f9;}
.timeline {
            display: flex;
            flex-direction: column;
        }

        .event {
            position: relative;
            padding: 0 0 18px 24px;
            border-left: 2px solid #d0d5dd;
        }

        .event:last-child {
            border-left-color: transparent;
        }

        .event-dot {
            position: absolute;
            left: -6px;
            top: 2px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #175cd3;
        }

        .event-title {
            font-weight: 800;
        }

        .event-meta {
            margin-top: 4px;
            color: #667085;
            font-size: 12px;
        }

        .event-description {
            margin-top: 6px;
            color: #475467;
            font-size: 13px;
            line-height: 1.5;
        }

        .note {
            background: #eff8ff;
            border: 1px solid #b2ddff;
            color: #175cd3;
            padding: 13px;
            border-radius: 10px;
            font-size: 13px;
            line-height: 1.5;
        }

        .toast {
            position: fixed;
            right: 18px;
            bottom: 18px;
            z-index: 9999;
            display: none;
            max-width: 320px;
            padding: 13px 16px;
            border-radius: 10px;
            background: #101828;
            color: #fff;
            box-shadow: 0 8px 30px rgba(0,0,0,.18);
            font-size: 13px;
        }

        @media (max-width: 900px) {
            .metrics {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 760px) {
            .grid {
                grid-template-columns: 1fr;
            }

            .full {
                grid-column: auto;
            }

            .header-top {
                flex-direction: column;
            }

            .buttons {
                grid-template-columns: 1fr;
            }

            .metrics {
                grid-template-columns: repeat(2, 1fr);
            }

            #globalTrackingMap {
                height: 430px;
            }
        }

        @media (max-width: 450px) {
            .row {
                grid-template-columns: 1fr;
            }

            .metrics {
                grid-template-columns: 1fr;
            }

            .tracking {
                font-size: 23px;
            }
        }
    .vh-event-marker-wrap {
        background: transparent;
        border: none;
    }

    .vh-event-marker {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 34px;
        height: 34px;
        border-radius: 50%;
        background: #ffffff;
        border: 2px solid #2563eb;
        box-shadow: 0 3px 10px rgba(0, 0, 0, 0.25);
        font-size: 17px;
    }
    </style>
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
    <script src="<?= asset('js/nx-labels.js') ?>"></script>
<link href="<?= asset('vendor/maplibre/maplibre-gl.css') ?>" rel="stylesheet">
<script src="<?= asset('vendor/maplibre/maplibre-gl.js') ?>"></script>
<script src="<?= asset('js/nx-map.js') ?>"></script>
    <style>
    .nx-admin-fs { position: absolute; bottom: 20px; right: 12px; z-index: 9999; background: rgba(11,29,58,.9); color: #fff; border: 1px solid rgba(240,180,41,.5); border-radius: 9px; padding: .5rem .85rem; font-size: .82rem; font-weight: 600; cursor: pointer; font-family: inherit; }
    .nx-admin-fs:hover { background: #132a52; }



    #globalTrackingMap { position: relative; }
    </style>
<script src="<?= asset('js/nx-popup.js') ?>"></script>
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


<header class="topbar">
    <div class="topbar-inner">
        <div class="brand">Nexora Logistics</div>

        <a
            class="back"
            href="/admin/shipment-management.php"
        >
            ← Shipment Management
        </a>
    </div>
</header>

<main class="container">

    <section class="card header">
        <div class="header-top">
            <div>
                <h1 class="tracking">
                    <?= vhEscape($shipment['tracking_number']) ?>
                </h1>

                <div class="route">
                    <?= vhTransportIcon((string) $shipment['transport_type'], (string) ($shipment['vehicle_name'] ?? '')) ?>
                    <?= vhEscape($shipment['origin']) ?>
                    →
                    <?= vhEscape($shipment['destination']) ?>
                    ·
                    <?= vhEscape($shipment['customer_name']) ?>
                </div>

                <div style="margin-top:.6rem;display:flex;gap:.5rem;flex-wrap:wrap;">
                    <a href="/track.php?id=<?= rawurlencode((string) $shipment['tracking_number']) ?>" target="_blank" rel="noopener" style="font-size:.85rem;font-weight:600;padding:.45rem .85rem;border-radius:8px;border:1px solid #cbd5e1;background:#fff;color:#0b1d3a;text-decoration:none;">👁️ View as Customer</a>
                    <a href="/admin/proof-of-delivery.php?id=<?= (int) $shipmentId ?>" style="font-size:.85rem;font-weight:600;padding:.45rem .85rem;border-radius:8px;border:1px solid #cbd5e1;background:#fff;color:#0b1d3a;text-decoration:none;">🧾 Proof of Delivery</a>
                </div>
            </div>

            <div>
                <span
                    id="shipment-status-badge"
                    class="badge <?= vhStatusClass($status) ?>"
                >
                    <?= vhEscape(strtoupper(str_replace('_', ' ', $status))) ?>
                </span>
            </div>
        </div>

        <div class="metrics">

            <div class="metric">
                <span class="metric-label">Speed</span>
                <span
                    class="metric-value"
                    id="shipment-speed"
                >
                    <?= number_format((float) $shipment['speed'], 1) ?>
                    km/h
                </span>
            </div>

            <div class="metric">
                <span class="metric-label">Distance Travelled</span>
                <span
                    class="metric-value"
                    id="shipment-distance-travelled"
                >
                    <?= number_format(
                        (float) $shipment['distance_travelled'],
                        2
                    ) ?>
                    km
                </span>
            </div>

            <div class="metric">
                <span class="metric-label">Distance Remaining</span>
                <span
                    class="metric-value"
                    id="shipment-distance-remaining"
                >
                    <?= number_format(
                        (float) $shipment['distance_remaining'],
                        2
                    ) ?>
                    km
                </span>
            </div>

            <div class="metric">
                <span class="metric-label">ETA</span>
                <span
                    class="metric-value"
                    id="shipment-eta"
                >
                    <?= vhEscape(
                        $shipment['eta'] ?: 'Calculating...'
                    ) ?>
                </span>
            </div>

            <div class="metric">
                <span class="metric-label">Tracking Mode</span>
                <span
                    class="metric-value"
                    id="shipment-mode"
                >
                    <?= vhEscape(ucfirst($mode)) ?>
                </span>
            </div>

            <div class="metric">
                <span class="metric-label">Last Updated</span>
                <span
                    class="metric-value"
                    id="shipment-updated"
                >
                    <?= vhEscape(
                        $shipment['last_update']
                        ?: $shipment['updated_at']
                    ) ?>
                </span>
            </div>

        </div>
    </section>

    <div class="grid">

        <section class="card">
            <h2>Tracking Controls</h2>

            <div class="control-status">

                <div>
                    <strong>Tracking engine</strong>
                    <br>

                    <span
                        id="engine-status"
                        class="<?= $isRunning ? 'running' : 'stopped' ?>"
                    >
                        <span class="dot"></span>
                        <?= $isRunning ? 'RUNNING' : 'STOPPED' ?>
                    </span>
                </div>

                <span
                    id="control-state"
                    class="badge <?= $isRunning ? 'info' : 'warning' ?>"
                >
                    <?= $isRunning ? 'Active' : 'Paused' ?>
                </span>

            </div>

            <div class="buttons">

                <button
                    type="button"
                    class="primary"
                    id="start-button"
                    onclick="controlAction('start')"
                >
                    ▶ Start Tracking
                </button>

                <button
                    type="button"
                    class="warning-btn"
                    id="pause-button"
                    onclick="controlAction('pause')"
                >
                    ⏸ Pause
                </button>

                <button
                    type="button"
                    class="dark"
                    id="resume-button"
                    onclick="controlAction('resume')"
                >
                    ▶ Resume
                </button>

            </div>

            <div class="note" style="margin-top:15px;">
                Simulation mode moves this shipment using the
                tracking engine. It is not presented as real GPS.
            </div>
        </section>

        <section class="card">
            <h2>Movement Settings</h2>

            <div class="field">
                <label for="control-speed">
                    Speed (km/h)
                </label>

                <input
                    type="number"
                    id="control-speed"
                    min="0"
                    max="2000"
                    step="0.1"
                    value="<?= vhEscape($controlSpeed) ?>"
                >
            </div>

            <div class="field">
                <label for="tracking-mode">
                    Tracking Mode
                </label>

                <select id="tracking-mode">
                    <option
                        value="simulation"
                        <?= $mode === 'simulation' ? 'selected' : '' ?>
                    >
                        Simulation
                    </option>

                    <option
                        value="gps"
                        <?= $mode === 'gps' ? 'selected' : '' ?>
                    >
                        GPS
                    </option>
                </select>
            </div>

            <button
                type="button"
                class="primary"
                onclick="updateSpeed()"
            >
                Save Movement Settings
            </button>
        </section>

    </div>

    <div class="grid">

        <section class="card">
            <h2>Current Position</h2>

            <div class="row">

                <div class="field">
                    <label for="current-latitude-input">
                        Latitude
                    </label>

                    <input
                        type="number"
                        id="current-latitude-input"
                        step="0.000001"
                        value="<?= vhEscape($shipment['current_lat']) ?>"
                    >
                </div>

                <div class="field">
                    <label for="current-longitude-input">
                        Longitude
                    </label>

                    <input
                        type="number"
                        id="current-longitude-input"
                        step="0.000001"
                        value="<?= vhEscape($shipment['current_lng']) ?>"
                    >
                </div>

            </div>

            <button
                type="button"
                class="dark"
                onclick="savePosition()"
            >
                📍 Save Position
            </button>

            <div class="location-box">
                <strong>Route</strong>

                <div style="margin-top:8px;">
                    <?= vhEscape($shipment['origin']) ?>
                    →
                    <?= vhEscape($shipment['destination']) ?>
                </div>

                <div class="route-meta">
                    Current coordinates are controlled by the
                    tracking engine.
                </div>
            </div>
        </section>

        <section class="card">
            <h2>Shipment Information</h2>

            <div class="row">

                <div>
                    <span class="metric-label">
                        Customer
                    </span>

                    <strong>
                        <?= vhEscape($shipment['customer_name']) ?>
                    </strong>
                </div>

                <div>
                    <span class="metric-label">
                        Transport
                    </span>

                    <strong>
                        <?= vhTransportIcon(
                            (string) $shipment['transport_type']
                        ) ?>
                        <?= vhEscape(
                            ucfirst(
                                (string) $shipment['transport_type']
                            )
                        ) ?>
                    </strong>
                </div>

            </div>

            <?php if (!empty($shipment['customer_email'])): ?>
                <div style="margin-top:15px;">
                    <span class="metric-label">
                        Email
                    </span>

                    <strong>
                        <?= vhEscape($shipment['customer_email']) ?>
                    </strong>
                </div>
            <?php endif; ?>

            <?php if (!empty($shipment['customer_phone'])): ?>
                <div style="margin-top:15px;">
                    <span class="metric-label">
                        Phone
                    </span>

                    <strong>
                        <?= vhEscape($shipment['customer_phone']) ?>
                    </strong>
                </div>
            <?php endif; ?>

            <?php if (!empty($shipment['package_description'])): ?>
                <div style="margin-top:15px;">
                    <span class="metric-label">
                        Package
                    </span>

                    <strong>
                        <?= vhEscape(
                            $shipment['package_description']
                        ) ?>
                    </strong>
                </div>
            <?php endif; ?>

            <?php if (!empty($shipment['vehicle_name'])): ?>
                <div style="margin-top:15px;">
                    <span class="metric-label">
                        Vehicle
                    </span>

                    <strong>
                        <?= vhEscape($shipment['vehicle_name']) ?>
                    </strong>
                </div>
            <?php endif; ?>

        </section>

        <section class="card full">
            <h2>Global Live Tracking</h2>

            <div style="position:relative;"><div id="globalTrackingMap">
  </div>

            <div class="map-note">
                The map shows the shipment's current position,
                origin, destination and configured waypoints.
                Movement is updated by the tracking engine.
            </div>
        </section>

    </div>

    <div class="grid">

        <section class="card">
            <h2 style="display:flex;align-items:center;gap:.75rem;justify-content:space-between;flex-wrap:wrap;">Global Shipment Route <a href="/admin/shipment-route.php?id=<?= (int)$shipmentId ?>" style="font-size:.85rem;font-weight:600;padding:.5rem .9rem;border-radius:8px;border:1px solid #cbd5e1;background:#fff;color:#0b1d3a;text-decoration:none;">🗺️ Edit route</a></h2>

            <div class="route-list">

                <div class="route-item">
                    <div class="route-icon">📦</div>

                    <div>
                        <div class="route-name">
                            Origin
                        </div>

                        <div class="route-meta">
                            <?= vhEscape($shipment['origin']) ?>
                        </div>
                    </div>
                </div>

                <?php foreach ($waypoints as $waypoint): ?>
                    <div class="route-item">
                        <div class="route-icon">
                            📍
                        </div>

                        <div>
                            <div class="route-name">
                                <?= vhEscape($waypoint['name']) ?>
                            </div>

                            <div class="route-meta">
                                <?= vhEscape(
                                    $waypoint['address']
                                    ?: 'Waypoint'
                                ) ?>

                                ·

                                <?= vhEscape(
                                    ucfirst(
                                        (string) $waypoint['status']
                                    )
                                ) ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="route-item">
                    <div class="route-icon">🏁</div>

                    <div>
                        <div class="route-name">
                            Destination
                        </div>

                        <div class="route-meta">
                            <?= vhEscape($shipment['destination']) ?>
                        </div>
                    </div>
                </div>

            </div>
        </section>

        <section class="card">
        <section class="card">
            <h2>Operational Event</h2>

            <div class="field">
                <label for="event-type">Event Type</label>
                <select id="event-type">
                    <option value="picked_up">Picked Up</option>
                    <option value="processing">Processing</option>
                    <option value="departed">Departed</option>
                    <option value="in_transit">In Transit</option>
                    <option value="checkpoint">Checkpoint</option>
                    <option value="customs">Customs</option>
                    <option value="on_hold">On Hold</option>
                    <option value="delayed">Delayed</option>
                    <option value="route_changed">Route Changed</option>
                    <option value="weather_delay">Weather Delay</option>
                    <option value="transport_issue">Transport Issue</option>
                    <option value="facility_issue">Facility Issue</option>
                    <option value="out_for_delivery">Out for Delivery</option>
                    <option value="delivered">Delivered</option>
                    <option value="custom">Custom Update</option>
                </select>
            </div>

            <div class="field">
                <label for="event-location">Location</label>
                <input
                    type="text"
                    id="event-location"
                    placeholder="Example: Paris Logistics Hub"
                >
            </div>

            <div class="field">
                <label for="event-latitude">Latitude</label>
                <input
                    type="number"
                    id="event-latitude"
                    step="any"
                    placeholder="Example: 48.8566"
                >
            </div>

            <div class="field">
                <label for="event-longitude">Longitude</label>
                <input
                    type="number"
                    id="event-longitude"
                    step="any"
                    placeholder="Example: 2.3522"
                >
            </div>

            <div class="field">
                <label for="event-delay">Delay Duration (minutes)</label>
                <input
                    type="number"
                    id="event-delay"
                    min="0"
                    step="1"
                    value="0"
                    placeholder="Example: 15"
                >
            </div>

            <div class="field">
                <label for="event-icon">Map Icon</label>
                <select id="event-icon">
                    <option value="event">General Event</option>
                    <option value="pickup">Pickup</option>
                    <option value="processing">Processing</option>
                    <option value="departure">Departure</option>
                    <option value="checkpoint">Checkpoint</option>
                    <option value="customs">Customs</option>
                    <option value="hold">On Hold</option>
                    <option value="delay">Delay</option>
                    <option value="weather">Weather</option>
                    <option value="route">Route Change</option>
                    <option value="transport">Transport Issue</option>
                    <option value="facility">Facility Issue</option>
                    <option value="delivery">Out for Delivery</option>
                    <option value="delivered">Delivered</option>
                </select>
            </div>

            <div class="field">
                <label for="event-description">Description</label>
                <textarea
                    id="event-description"
                    placeholder="Explain what happened and what the customer should know..."
                ></textarea>
            </div>

            <div class="field">
                <label>
                    <input type="checkbox" id="event-pauses">
                    Pause shipment when this event is recorded
                </label>
            </div>

            <button
                type="button"
                class="primary"
                onclick="recordEvent()"
            >
                📝 Record Operational Event
            </button>
        </section>

    </div>

    <section class="card">
        <h2>Tracking Timeline</h2>

        <div class="nx-tl-scroll"><div class="timeline" id="tracking-timeline">

            <?php if (!$events): ?>

                <div class="note">
                    No tracking events have been recorded yet.
                </div>

            <?php else: ?>

                <?php $nxShowAll = (isset($_GET['show']) && $_GET['show'] === 'all'); $nxVisible = $events; ?>
                        <?php foreach ($nxVisible as $event): ?>

                    <div class="event">
                        <span class="event-dot"></span>

                        <div class="event-title">
                            <?= vhEscape($event['title']) ?>
                        </div>

                        <div class="event-meta">
                            <?= vhEscape($event['event_time']) ?>

                            <?php if (!empty($event['location'])): ?>
                                ·
                                <?= vhEscape($event['location']) ?>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($event['description'])): ?>
                            <div class="event-description">
                                <?= vhEscape(
                                    $event['description']
                                ) ?>
                            </div>
                        <?php endif; ?>

                    </div>

                <?php endforeach; ?>

                        <?php endif; ?>
                    </div>
                    <?php if (count($events) > 5): ?><button type="button" class="nx-tl-more" id="nx-tl-toggle" data-total="<?= count($events) ?>">Show all (<?= count($events) - 5 ?> more)</button><?php endif; ?>
                    </div>
    </section>

    <div
        id="toast"
        class="toast"
    ></div>

</main>



<script>
const shipmentId = <?= (int) $shipmentId ?>;

let map = null;
let shipmentMarker = null;
let routeLine = null;
let eventMarkerLayer = null;
let mapReady = false;

const origin = {
    lat: <?= (float) $shipment['origin_lat'] ?>,
    lng: <?= (float) $shipment['origin_lng'] ?>
};

const destination = {
    lat: <?= (float) $shipment['destination_lat'] ?>,
    lng: <?= (float) $shipment['destination_lng'] ?>
};

const waypoints = <?= json_encode(
    array_map(
        static function (array $point): array {
            return [
                'name' => (string) $point['name'],
                'lat' => (float) $point['latitude'],
                'lng' => (float) $point['longitude']
            ];
        },
        $waypoints
    ),
    JSON_UNESCAPED_SLASHES
) ?>;

const trackingEvents = <?= json_encode($events, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value ?? '';
    return div.innerHTML;
}

function showToast(message) {
    const toast = document.getElementById('toast');

    if (!toast) {
        return;
    }

    toast.textContent = message;
    toast.style.display = 'block';

    clearTimeout(window.vhToastTimer);

    window.vhToastTimer = setTimeout(() => {
        toast.style.display = 'none';
    }, 3500);
}

function setText(id, value) {
    const element = document.getElementById(id);

    if (element) {
        element.textContent = value;
    }
}

function buildRoute() {
    const points = [
        [origin.lat, origin.lng],
        ...waypoints.map(point => [point.lat, point.lng]),
        [destination.lat, destination.lng]
    ];

    const mode = <?= json_encode((string) $shipment['transport_type'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const isAircraft = ['flight', 'airplane', 'aircraft'].includes(
        String(mode).toLowerCase()
    );

    if (!isAircraft || points.length < 2) return points;

    const rad = value => value * Math.PI / 180;
    const deg = value => value * 180 / Math.PI;

    function curveBetween(start, end, steps = 80) {
        const lat1 = rad(start[0]), lon1 = rad(start[1]);
        const lat2 = rad(end[0]), lon2 = rad(end[1]);

        const a = [
            Math.cos(lat1) * Math.cos(lon1),
            Math.cos(lat1) * Math.sin(lon1),
            Math.sin(lat1)
        ];
        const b = [
            Math.cos(lat2) * Math.cos(lon2),
            Math.cos(lat2) * Math.sin(lon2),
            Math.sin(lat2)
        ];

        const dot = Math.max(-1, Math.min(1,
            a[0] * b[0] + a[1] * b[1] + a[2] * b[2]
        ));
        const angle = Math.acos(dot);
        const result = [];

        for (let i = 0; i <= steps; i++) {
            const t = i / steps;
            let x, y, z;

            if (angle < 1e-8) {
                x = a[0]; y = a[1]; z = a[2];
            } else {
                const sinAngle = Math.sin(angle);
                const u = Math.sin((1 - t) * angle) / sinAngle;
                const v = Math.sin(t * angle) / sinAngle;
                x = u * a[0] + v * b[0];
                y = u * a[1] + v * b[1];
                z = u * a[2] + v * b[2];
            }

            result.push([
                deg(Math.atan2(z, Math.sqrt(x * x + y * y))),
                deg(Math.atan2(y, x))
            ]);
        }

        return result;
    }

    const result = [];
    for (let i = 0; i < points.length - 1; i++) {
        const segment = curveBetween(points[i], points[i + 1]);
        if (i > 0) segment.shift();
        result.push(...segment);
    }

    return result;
}
function eventIconSymbol(icon) {
    const icons = {
        pickup: '📦',
        processing: '⚙️',
        departure: '✈️',
        checkpoint: '📍',
        customs: '🛃',
        hold: '⏸️',
        delay: '⏱️',
        weather: '🌧️',
        route: '🔀',
        transport: '🚚',
        facility: '🏢',
        delivery: '🛵',
        delivered: '✅',
        event: 'ℹ️'
    };

    return icons[icon] || icons.event;
}

function renderTrackingEvents() {
    if (!map || typeof L === 'undefined') return;

    if (!eventMarkerLayer) {
        eventMarkerLayer = L.layerGroup().addTo(map);
    } else {
        eventMarkerLayer.clearLayers();
    }

    trackingEvents.forEach(event => {
        if (event.latitude === null || event.longitude === null) return;

        const lat = Number(event.latitude);
        const lng = Number(event.longitude);

        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;

        const popup =
            '<strong>' + escapeHtml(event.title) + '</strong><br>' +
            '<b>Type:</b> ' + escapeHtml(event.event_type) + '<br>' +
            '<b>Location:</b> ' + escapeHtml(event.location || '—') + '<br>' +
            '<b>Delay:</b> ' + Number(event.delay_minutes || 0) + ' min<br>' +
            '<b>Paused:</b> ' +
            (Number(event.pauses_tracking) === 1 ? 'Yes' : 'No') + '<br>' +
            '<b>Time:</b> ' + escapeHtml(event.event_time || '—') +
            (event.description
                ? '<div style="margin-top:8px;">' +
                  escapeHtml(event.description) + '</div>'
                : '');

        L.marker([lat, lng], {
            icon: L.divIcon({
                html: '<span class="vh-event-marker">' +
                    escapeHtml(eventIconSymbol(event.icon)) + '</span>',
                className: 'vh-event-marker-wrap',
                iconSize: [36, 36],
                iconAnchor: [18, 18],
                popupAnchor: [0, -18]
            })
        }).addTo(eventMarkerLayer).bindPopup(popup);
    });
}
function nxNightGeo() {
  const now = new Date();
  const utcH = now.getUTCHours() + now.getUTCMinutes()/60 + now.getUTCSeconds()/3600;
  const dayOfYear = Math.floor((now - new Date(Date.UTC(now.getUTCFullYear(), 0, 0))) / 86400000);
  const decl = (-23.44 * Math.PI/180) * Math.cos((2*Math.PI/365) * (dayOfYear + 10));
  const tanD = Math.tan(decl);
  const sunLng = -15 * (utcH - 12);
  const coords = [];
  if (Math.abs(tanD) < 1e-3) {
    const a = ((sunLng + 90 + 540) % 360) - 180;
    const b = ((sunLng - 90 + 540) % 360) - 180;
    coords.push([a, -90], [b, -90], [b, 90], [a, 90], [a, -90]);
  } else {
    for (let lng = -180; lng <= 180; lng += 3) {
      const H = (lng - sunLng) * Math.PI/180;
      const phi = Math.atan2(-Math.cos(H), tanD);
      coords.push([lng, phi * 180/Math.PI]);
    }
    const darkPole = decl > 0 ? -90 : 90;
    coords.push([180, darkPole], [-180, darkPole], [-180, coords[0][1]]);
    coords.reverse();
  }
  return { type:'Feature', geometry:{ type:'Polygon', coordinates:[coords] } };
}
function nxBearing(from, to) {
  // Bearing from `from` to `to` in degrees, 0=north, 90=east
  const toRad = Math.PI/180, toDeg = 180/Math.PI;
  const lat1 = from.lat * toRad, lat2 = to.lat * toRad;
  const dLng = (to.lng - from.lng) * toRad;
  const y = Math.sin(dLng) * Math.cos(lat2);
  const x = Math.cos(lat1)*Math.sin(lat2) - Math.sin(lat1)*Math.cos(lat2)*Math.cos(dLng);
  let b = Math.atan2(y, x) * toDeg;
  return (b + 360) % 360;
}
function nxGreatCircle(a, b, n) {
  // Returns n+1 points on the shortest great-circle path from a to b,
  // unrolling longitude so the line never wraps the long way around the globe.
  const toRad = Math.PI/180, toDeg = 180/Math.PI;
  const lat1 = a.lat*toRad, lng1 = a.lng*toRad;
  const lat2 = b.lat*toRad, lng2 = b.lng*toRad;
  const d = 2 * Math.asin(Math.sqrt(
    Math.pow(Math.sin((lat2-lat1)/2), 2) +
    Math.cos(lat1)*Math.cos(lat2)*Math.pow(Math.sin((lng2-lng1)/2), 2)
  ));
  const out = [];
  if (d === 0) { return [{lat:a.lat, lng:a.lng}, {lat:b.lat, lng:b.lng}]; }
  for (let i = 0; i <= n; i++) {
    const f = i / n;
    const A = Math.sin((1-f)*d) / Math.sin(d);
    const B = Math.sin(f*d) / Math.sin(d);
    const x = A*Math.cos(lat1)*Math.cos(lng1) + B*Math.cos(lat2)*Math.cos(lng2);
    const y = A*Math.cos(lat1)*Math.sin(lng1) + B*Math.cos(lat2)*Math.sin(lng2);
    const z = A*Math.sin(lat1) + B*Math.sin(lat2);
    let lat = Math.atan2(z, Math.sqrt(x*x + y*y)) * toDeg;
    let lng = Math.atan2(y, x) * toDeg;
    // Unroll so consecutive points don't jump > 180° (keep on short side)
    if (out.length > 0) {
      const prev = out[out.length-1].lng;
      while (lng - prev > 180) lng -= 360;
      while (prev - lng > 180) lng += 360;
    }
    out.push({ lat: lat, lng: lng });
  }
  return out;
}
function nxFixWrap(points) {
  // Unroll any longitude jump > 180° by inserting two points at ±180 (the antimeridian).
  // This makes the line cross the Pacific on the SHORT great-circle path instead of
  // looping the long way around the globe.
  if (!points || points.length < 2) return points || [];
  const out = [points[0]];
  for (let i = 1; i < points.length; i++) {
    const prev = out[out.length - 1];
    const cur = points[i];
    const dLng = cur.lng - prev.lng;
    if (dLng > 180) {
      // Going west across the antimeridian (e.g. -179 → 179): unroll by adding 360 to current
      const unrolled = cur.lng + 360;
      const t = (180 - prev.lng) / (unrolled - prev.lng);
      const lat = prev.lat + (cur.lat - prev.lat) * t;
      out.push({ lat: lat, lng: 180,  seg: prev.seg });
      out.push({ lat: lat, lng: -180, seg: cur.seg  });
    } else if (dLng < -180) {
      // Going east across the antimeridian (e.g. 179 → -179): unroll by subtracting 360 from current
      const unrolled = cur.lng - 360;
      const t = (-180 - prev.lng) / (unrolled - prev.lng);
      const lat = prev.lat + (cur.lat - prev.lat) * t;
      out.push({ lat: lat, lng: -180, seg: prev.seg });
      out.push({ lat: lat, lng: 180,  seg: cur.seg  });
    }
    out.push(cur);
  }
  return out;
}
// ===== Global JS error trap =====
window.addEventListener("error", function(e){
  try {
    var bar = document.getElementById("nx-admin-errbar");
    if (!bar) {
      bar = document.createElement("div");
      bar.id = "nx-admin-errbar";
      bar.style.cssText = "position:fixed;bottom:0;left:0;right:0;background:#7f1d1d;color:#fff;padding:8px 12px;font-size:.78rem;z-index:99999;max-height:120px;overflow:auto;font-family:monospace;";
      document.body.appendChild(bar);
    }
    bar.textContent = "JS error: " + (e.message || "") + " @ " + (e.filename || "") + ":" + (e.lineno || "");
  } catch(_){}
});
window.addEventListener("unhandledrejection", function(e){
  try {
    var bar = document.getElementById("nx-admin-errbar");
    if (!bar) {
      bar = document.createElement("div");
      bar.id = "nx-admin-errbar";
      bar.style.cssText = "position:fixed;bottom:0;left:0;right:0;background:#7f1d1d;color:#fff;padding:8px 12px;font-size:.78rem;z-index:99999;";
      document.body.appendChild(bar);
    }
    bar.textContent = "Promise error: " + ((e.reason && e.reason.message) ? e.reason.message : e.reason);
  } catch(_){}
});

// ===== nxSafeFetch: timeout + retry =====
async function nxSafeFetch(url, opts, timeoutMs, retries) {
  timeoutMs = timeoutMs || 8000;
  retries = typeof retries === "number" ? retries : 1;
  for (var attempt = 0; attempt <= retries; attempt++) {
    var controller = new AbortController();
    var t = setTimeout(function(){ controller.abort(); }, timeoutMs);
    try {
      var o = Object.assign({}, opts || {}, { signal: controller.signal });
      var r = await fetch(url, o);
      clearTimeout(t);
      return r;
    } catch (e) {
      clearTimeout(t);
      if (attempt === retries) throw e;
      await new Promise(function(res){ setTimeout(res, 400); });
    }
  }
}

// ===== timer registry =====
window.__nxTimers = window.__nxTimers || [];
function nxInterval(fn, ms) { var id = setInterval(fn, ms); window.__nxTimers.push(id); return id; }
window.addEventListener("pagehide", function(){ window.__nxTimers.forEach(function(id){ clearInterval(id); }); });
window.addEventListener("beforeunload", function(){ window.__nxTimers.forEach(function(id){ clearInterval(id); }); });
function initializeMap() {
  if (typeof maplibregl === 'undefined') {
    showToast('Map library could not be loaded.');
    return;
  }
  map = nxCreateMap('globalTrackingMap', <?= json_encode(MAP_STYLE_URL, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>, {
    center: [0, 30],
    zoom: 2,
    attributionControl: { compact: true }
  });
  map.addControl(new maplibregl.NavigationControl({ visualizePitch: true }), 'top-left');

  map.on('load', () => {
    const route = buildRoute();
    const routeMode = String(<?= json_encode((string) $shipment['transport_type'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>).toLowerCase();

    // ---- Route line (colored per segment from DBRoute_points if available) ----
    // Build the route as great-circle arcs between waypoints (or origin→destination).
    // This guarantees the SHORT path is drawn — across the Pacific, not around the globe.
    const segColors = { air:'#f0b429', sea:'#2563eb', rail:'#16a34a', truck:'#64748b', van:'#7c3aed', bike:'#ec4899', ped:'#0b1d3a', flight:'#f0b429', ship:'#2563eb', train:'#16a34a', vehicle:'#64748b' };

    const wpList = <?= json_encode(array_map(fn($w) => [
        'lat' => (float)$w['latitude'],
        'lng' => (float)$w['longitude'],
        'leg' => $w['leg_type'] ?: 'truck',
    ], $waypoints ?? [])) ?>;

    const stops = (wpList.length >= 2)
      ? wpList
      : [
          { lat: <?= (float)$shipment['origin_lat'] ?>, lng: <?= (float)$shipment['origin_lng'] ?>, leg: routeMode },
          { lat: <?= (float)$shipment['destination_lat'] ?>, lng: <?= (float)$shipment['destination_lng'] ?>, leg: routeMode },
        ];

    const features = [];
    for (let i = 1; i < stops.length; i++) {
      const a = stops[i-1];
      const b = stops[i];
      const legType = b.leg || 'truck';
      const arc = nxGreatCircle(a, b, 40);
      features.push({
        type: 'Feature',
        properties: { color: segColors[legType] || '#cbd5e1' },
        geometry: { type: 'LineString', coordinates: arc.map(p => [p.lng, p.lat]) }
      });
    }

    map.addSource('nx-route', { type:'geojson', data:{ type:'FeatureCollection', features } });
    map.addLayer({
      id: 'nx-route-line', type: 'line', source: 'nx-route',
      layout: { 'line-cap':'round', 'line-join':'round' },
      paint: { 'line-color':['get','color'], 'line-width':4, 'line-opacity':0.9, 'line-dasharray':[2,1.5] }
    });

    // ---- Origin flag ----
    const originFlag = (window.NX && NX.flag) ? NX.flag(<?= json_encode($shipment['origin'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>) : '📍';
    const oEl = document.createElement('div');
    oEl.innerHTML = '<div style="font-size:24px;text-align:center;filter:drop-shadow(0 0 6px rgba(0,0,0,.7));">' + originFlag + '</div>' +
                    '<div style="background:#0b1d3a;color:#fff;font-size:.72rem;font-weight:700;padding:2px 7px;border-radius:6px;white-space:nowrap;margin-top:2px;">' + escapeHtml(<?= json_encode($shipment['origin'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>) + '</div>';
    new maplibregl.Marker({ element: oEl, anchor:'bottom' }).setLngLat([origin.lng, origin.lat]).addTo(map);

    // ---- Destination flag ----
    const destFlag = (window.NX && NX.flag) ? NX.flag(<?= json_encode($shipment['destination'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>) : '📍';
    const dEl = document.createElement('div');
    dEl.innerHTML = '<div style="font-size:24px;text-align:center;filter:drop-shadow(0 0 6px rgba(0,0,0,.7));">' + destFlag + '</div>' +
                    '<div style="background:#f0b429;color:#0b1d3a;font-size:.72rem;font-weight:700;padding:2px 7px;border-radius:6px;white-space:nowrap;margin-top:2px;">' + escapeHtml(<?= json_encode($shipment['destination'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>) + '</div>';
    new maplibregl.Marker({ element: dEl, anchor:'bottom' }).setLngLat([destination.lng, destination.lat]).addTo(map);

    // ---- Waypoints ----
    waypoints.forEach(point => {
      const wpIconMap = { origin:'🟢', destination:'🎯', airport:'✈️', port:'⚓', rail_hub:'🚉', warehouse:'🏬', office:'🏢', depot:'🏭', home:'🏠', checkpoint:'🛡️', waypoint:'📍' };
      const icon = wpIconMap[point.type] || '📍';
      const wEl = document.createElement('div');
      wEl.innerHTML = '<div style="font-size:18px;text-align:center;">' + icon + '</div>' +
                      '<div style="background:rgba(11,29,58,.9);color:#fff;font-size:.7rem;font-weight:600;padding:1px 6px;border-radius:5px;white-space:nowrap;margin-top:1px;border:1px solid rgba(240,180,41,.4);">' + escapeHtml(point.name) + '</div>';
      const m = new maplibregl.Marker({ element: wEl, anchor:'bottom' }).setLngLat([point.lng, point.lat]).addTo(map);
      m.setPopup(new maplibregl.Popup({ offset: 20 }).setHTML('<b>Waypoint</b><br>' + escapeHtml(point.name)));
    });

    // ---- Shipment marker (gold, glowing) ----
    const sEl = document.createElement('div');
    sEl.id = 'nx-shipment-marker';
    const shipEmoji = (window.NX && NX.transport) ? NX.transport(<?= json_encode((string)$shipment['transport_type'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>, <?= json_encode((string)($shipment['vehicle_name'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>).emoji : '🚚';
    sEl.innerHTML = '<div style="width:52px;height:52px;border-radius:50%;background:rgba(11,29,58,.9);border:3px solid #f0b429;display:flex;align-items:center;justify-content:center;box-shadow:0 0 16px #f0b429;">' +
                    '<div id="nx-shipment-emoji" style="font-size:28px;line-height:1;">' + shipEmoji + '</div></div>' +
                    '<div style="background:#f0b429;color:#0b1d3a;font-size:.72rem;font-weight:800;padding:2px 8px;border-radius:6px;white-space:nowrap;margin-top:4px;text-align:center;">' + escapeHtml(<?= json_encode($shipment['tracking_number'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>) + '</div>';
    shipmentMarker = new maplibregl.Marker({ element: sEl, anchor:'center' })
      .setLngLat([<?= (float) $shipment['current_lng'] ?>, <?= (float) $shipment['current_lat'] ?>])
      .addTo(map);
    // Face the plane in the direction of travel (bearing to next stop)
    (function(){
      function rotateToHeading() {
        const el = document.getElementById('nx-shipment-emoji');
        if (!el) return;
        const cur = { lat: <?= (float)$shipment['current_lat'] ?>, lng: <?= (float)$shipment['current_lng'] ?> };
        // Find the next stop ahead of current position, or use the destination
        let target = null;
        for (let i = 0; i < stops.length; i++) {
          const s = stops[i];
          const d = Math.hypot(s.lat - cur.lat, s.lng - cur.lng);
          if (d > 0.5) { target = s; break; }
        }
        if (!target) target = stops[stops.length - 1];
        if (!target) return;
        const bearing = nxBearing(cur, target);
        // Only rotate plane/train/bike icons — leave trucks/vans/ped upright
        const rotatable = /✈️|🚆|🏍️/.test(el.textContent);
        el.style.transform = rotatable ? ('rotate(' + bearing + 'deg)') : 'rotate(0deg)';
      }
      rotateToHeading();
      // Re-check heading whenever the position update script runs
      window.nxRotateShipment = rotateToHeading;
      setInterval(rotateToHeading, 4000);
    })();

    const shipPopup = new maplibregl.Popup({ offset: 40, closeButton: true }).setHTML(
      '<div style="font-family:Plus Jakarta Sans,system-ui,sans-serif;font-size:.88rem;line-height:1.55;min-width:220px;">' +
        '<div style="font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;color:#64748b;">Shipment</div>' +
        '<div style="font-weight:800;color:#0b1d3a;font-size:1.05rem;">' + escapeHtml(<?= json_encode($shipment['tracking_number'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>) + '</div>' +
        '<hr style="border:0;border-top:1px solid #e2e8f0;margin:8px 0;">' +
        '<div><b>Customer:</b> ' + escapeHtml(<?= json_encode($shipment['customer_name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>) + '</div>' +
        '<div><b>Vehicle:</b> ' + escapeHtml(<?= json_encode((string)($shipment['vehicle_name'] ?: $shipment['transport_type']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>) + '</div>' +
        '<div><b>Status:</b> ' + escapeHtml(<?= json_encode($shipment['status'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>) + '</div>' +
        '<div><b>Speed:</b> ' + <?= json_encode((string)(float)$shipment['speed'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> + ' km/h</div>' +
        '<div><b>Route:</b> ' + escapeHtml(<?= json_encode($shipment['origin'] . ' → ' . $shipment['destination'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>) + '</div>' +
        '<div style="margin-top:6px;font-size:.78rem;color:#64748b;">Updated: ' + escapeHtml(<?= json_encode((string)($shipment['last_update'] ?: $shipment['updated_at']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>) + '</div>' +
      '</div>'
    );
    shipmentMarker.setPopup(shipPopup);

    // ---- Fit ----
    const bounds = new maplibregl.LngLatBounds();
    bounds.extend([origin.lng, origin.lat]);
    bounds.extend([destination.lng, destination.lat]);
    if (<?= (float)$shipment['current_lat'] ?>) bounds.extend([<?= (float) $shipment['current_lng'] ?>, <?= (float) $shipment['current_lat'] ?>]);
    map.fitBounds(bounds, { padding: 60, maxZoom: 6, duration: 0 });

    // Day/night terminator
    try {
      map.addSource('nx-night', { type:'geojson', data: nxNightGeo() });
      map.addLayer({ id:'nx-night-layer', type:'fill', source:'nx-night', paint:{ 'fill-color':'#000820', 'fill-opacity':0.55 } });
      setInterval(function(){ try { map.getSource('nx-night').setData(nxNightGeo()); } catch(e){} }, 90000);
    } catch(e){}

    renderTrackingEvents();
    mapReady = true;
  });
}

const csrfToken = <?= json_encode(csrfToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

async function requestAction(action, extra = {}) {
    const body = new URLSearchParams();

    body.set('shipment_id', String(shipmentId));
    body.set('action', action);
        body.set('csrf_token', csrfToken);

    Object.entries(extra).forEach(([key, value]) => {
        body.set(key, String(value));
    });

    const response = await fetch(
        '/admin/tracking-action.php',
        {
            method: 'POST',
            headers: {
                'Content-Type':
                    'application/x-www-form-urlencoded'
            },
            body
        }
    );

    const data = await response.json();

    if (!response.ok || !data.success) {
        throw new Error(
            data.message || 'Tracking action failed.'
        );
    }

    return data.data;
}

async function controlAction(action) {
    try {
        const state = await requestAction(action);

        updateState(state);

        showToast(
            action.charAt(0).toUpperCase() +
            action.slice(1) +
            ' successful.'
        );
    } catch (error) {
        showToast(error.message);
    }
}

async function updateSpeed() {
    const speed = Number(
        document.getElementById('control-speed').value
    );

    const mode =
        document.getElementById('tracking-mode').value;

    if (!Number.isFinite(speed) || speed < 0) {
        showToast('Enter a valid speed.');
        return;
    }

    try {
        const state = await requestAction(
            'speed',
            {
                speed,
                tracking_mode: mode
            }
        );

        updateState(state);

        showToast('Movement settings saved.');
    } catch (error) {
        showToast(error.message);
    }
}

async function savePosition() {
    const latitude = Number(
        document.getElementById(
            'current-latitude-input'
        ).value
    );

    const longitude = Number(
        document.getElementById(
            'current-longitude-input'
        ).value
    );

    if (
        !Number.isFinite(latitude) ||
        latitude < -90 ||
        latitude > 90
    ) {
        showToast('Invalid latitude.');
        return;
    }

    if (
        !Number.isFinite(longitude) ||
        longitude < -180 ||
        longitude > 180
    ) {
        showToast('Invalid longitude.');
        return;
    }

    try {
        const state = await requestAction(
            'save_position',
            {
                latitude,
                longitude
            }
        );

        updateState(state);

        showToast('Position saved.');
    } catch (error) {
        showToast(error.message);
    }
}

async function recordEvent() {
    const type =
        document.getElementById('event-type').value;

    const location =
        document.getElementById('event-location').value
            .trim();

    const description =
        document.getElementById('event-description').value
            .trim();

    const latitudeValue =
        document.getElementById('event-latitude').value
            .trim();

    const longitudeValue =
        document.getElementById('event-longitude').value
            .trim();

    const delayValue =
        document.getElementById('event-delay').value
            .trim();

    const icon =
        document.getElementById('event-icon').value;

    const pausesTracking =
        document.getElementById('event-pauses').checked;

    if (!description) {
        showToast('Enter an event description.');
        return;
    }

    if (
        (latitudeValue && !longitudeValue) ||
        (!latitudeValue && longitudeValue)
    ) {
        showToast('Enter both latitude and longitude.');
        return;
    }

    const latitude =
        latitudeValue === ''
            ? null
            : Number(latitudeValue);

    const longitude =
        longitudeValue === ''
            ? null
            : Number(longitudeValue);

    const delayMinutes =
        delayValue === ''
            ? 0
            : Number(delayValue);

    if (
        latitude !== null &&
        (!Number.isFinite(latitude) ||
         latitude < -90 ||
         latitude > 90)
    ) {
        showToast('Invalid latitude.');
        return;
    }

    if (
        longitude !== null &&
        (!Number.isFinite(longitude) ||
         longitude < -180 ||
         longitude > 180)
    ) {
        showToast('Invalid longitude.');
        return;
    }

    if (
        !Number.isFinite(delayMinutes) ||
        delayMinutes < 0
    ) {
        showToast('Invalid delay duration.');
        return;
    }

    try {
        const state = await requestAction(
            'event',
            {
                event_type: type,
                location,
                description,
                latitude,
                longitude,
                delay_minutes: Math.floor(delayMinutes),
                icon,
                pauses_tracking: pausesTracking ? 1 : 0
            }
        );

        updateState(state);

        if (state.event) {
            const savedEvent = state.event;
            const alreadyAdded = trackingEvents.some(
                item => Number(item.id) === Number(savedEvent.id)
            );
            if (!alreadyAdded) {
                trackingEvents.unshift(savedEvent);
            }
            renderTrackingEvents();
        }

        document.getElementById(
            'event-location'
        ).value = '';

        document.getElementById(
            'event-latitude'
        ).value = '';

        document.getElementById(
            'event-longitude'
        ).value = '';

        document.getElementById(
            'event-delay'
        ).value = '0';

        document.getElementById(
            'event-description'
        ).value = '';

        document.getElementById(
            'event-icon'
        ).value = 'event';

        document.getElementById(
            'event-pauses'
        ).checked = false;

        showToast('Operational event recorded.');
    } catch (error) {
        showToast(error.message);
    }
}

function updateState(state) {
    if (!state) {
        return;
    }

    const lat = Number(state.current_lat);
    const lng = Number(state.current_lng);

    if (
        Number.isFinite(lat) &&
        Number.isFinite(lng)
    ) {
        setText(
            'current-latitude-input',
            lat
        );

        setText(
            'current-longitude-input',
            lng
        );

        const latitudeInput = document.getElementById(
            'current-latitude-input'
        );

        const longitudeInput = document.getElementById(
            'current-longitude-input'
        );

        if (latitudeInput) {
            latitudeInput.value = lat;
        }

        if (longitudeInput) {
            longitudeInput.value = lng;
        }

        if (shipmentMarker && mapReady) {
            shipmentMarker.setLatLng([lat, lng]);
        }
    }

    setText(
        'shipment-speed',
        Number(state.speed || 0).toFixed(1) +
        ' km/h'
    );

    setText(
        'shipment-distance-travelled',
        Number(
            state.distance_travelled || 0
        ).toFixed(2) + ' km'
    );

    setText(
        'shipment-distance-remaining',
        Number(
            state.distance_remaining || 0
        ).toFixed(2) + ' km'
    );

    setText(
        'shipment-eta',
        state.eta || 'Calculating...'
    );

    setText(
        'shipment-mode',
        state.tracking_mode || 'simulation'
    );

    setText(
        'shipment-updated',
        state.last_update || 'Just now'
    );

    const running = Number(state.is_running) === 1;

    const engine = document.getElementById(
        'engine-status'
    );

    if (engine) {
        engine.className =
            running ? 'running' : 'stopped';

        engine.innerHTML =
            '<span class="dot"></span>' +
            (running ? 'RUNNING' : 'STOPPED');
    }

    const controlState = document.getElementById(
        'control-state'
    );

    if (controlState) {
        controlState.className =
            'badge ' +
            (running ? 'info' : 'warning');

        controlState.textContent =
            running ? 'Active' : 'Paused';
    }

    const badge = document.getElementById(
        'shipment-status-badge'
    );

    if (badge && state.status) {
        badge.textContent =
            state.status
                .replaceAll('_', ' ')
                .toUpperCase();

        badge.className =
            'badge ' +
            statusClass(state.status);
    }
}

function statusClass(status) {
    switch (String(status).toLowerCase()) {
        case 'delivered':
        case 'completed':
            return 'success';

        case 'in_transit':
        case 'moving':
            return 'info';

        case 'on_hold':
        case 'held':
        case 'paused':
            return 'warning';

        case 'cancelled':
        case 'failed':
            return 'danger';

        default:
            return 'muted';
    }
}

async function tick() {
    try {
        const response = await fetch(
            '/admin/tracking-tick.php?id=' +
            encodeURIComponent(shipmentId),
            {
                cache: 'no-store'
            }
        );

        const data = await response.json();

        if (data.success && data.data) {
            updateState(data.data);
        }
    } catch (error) {
        console.error(
            'Tracking tick failed:',
            error
        );
    }
}

document.addEventListener(
    'DOMContentLoaded',
    () => {
        initializeMap();

        tick();

        window.vhTickTimer = setInterval(
            tick,
            2000
        );
    }
);

window.controlAction = controlAction;
window.updateSpeed = updateSpeed;
window.savePosition = savePosition;
window.recordEvent = recordEvent;
</script>




<section class="card" id="vh-hold-management-panel" style="margin-top:24px;padding:22px">
  <h2 style="margin-top:0">Holds &amp; Incidents</h2>
  <p style="color:#64748b;margin-top:6px;">The only place you touch when something happens to this shipment. Current status at the top, one form below for anything new.</p>

  <div style="margin-top:20px;padding:16px;background:#f8fafc;border-radius:10px;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
      <h3 style="margin:0;font-size:1rem;">Current status on this shipment</h3>
      <button type="button" id="vh-hold-refresh" style="padding:7px 12px;border:1px solid #cbd5e1;background:#fff;border-radius:8px;cursor:pointer;font-size:.85rem;">Refresh</button>
    </div>
    <div id="vh-hold-list" style="margin-top:12px;">Loading holds…</div>
  </div>

  <div style="margin-top:22px;padding-top:22px;border-top:1px solid #e2e8f0;">
    <h3 style="margin-top:0;font-size:1rem;">Report something new</h3>
    <p style="color:#64748b;font-size:.9rem;margin:4px 0 14px;">Pick what happened. Delay is optional and pushes the ETA. Customer-visible wording lives in <a href="/admin/customer-messages.php">Customer Messages</a>.</p>

    <form id="nx-incident-form">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;">
        <label style="display:block;">
          <span style="font-weight:600;font-size:.85rem;color:#334155;">What happened?</span>
          <select name="incident" required style="display:block;width:100%;padding:10px;margin-top:6px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box;">
            <option value="">— Select —</option>
            <optgroup label="Hold the shipment (stops movement)">
              <option value="customs_inspection">Customs inspection</option>
              <option value="security_screening">Security screening</option>
              <option value="seized">Seized by authority</option>
              <option value="compliance_issue">Compliance issue</option>
            </optgroup>
            <optgroup label="Delay / operational (movement continues)">
              <option value="weather_delay">Weather delay</option>
              <option value="breakdown">Vehicle breakdown</option>
              <option value="damaged">Damaged packaging</option>
              <option value="port_congestion">Port congestion</option>
            </optgroup>
            <optgroup label="Progress">
              <option value="out_for_delivery">Out for delivery</option>
              <option value="delivered">Delivered</option>
            </optgroup>
            <optgroup label="Release">
              <option value="clear_hold">Clear all active holds</option>
            </optgroup>
          </select>
        </label>

        <label style="display:block;">
          <span style="font-weight:600;font-size:.85rem;color:#334155;">Delay amount (optional)</span>
          <div style="display:flex;gap:8px;margin-top:6px;">
            <input type="number" name="delay_value" min="0" step="1" value="0" style="flex:1 1 90px;min-width:90px;padding:10px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box;">
            <select name="delay_unit" style="padding:10px;border:1px solid #cbd5e1;border-radius:8px;">
              <option value="minutes">Minutes</option>
              <option value="hours">Hours</option>
              <option value="days">Days</option>
              <option value="weeks">Weeks</option>
              <option value="months">Months</option>
            </select>
          </div>
        </label>
      </div>

      <label style="display:block;margin-top:14px;">
        <span style="font-weight:600;font-size:.85rem;color:#334155;">Note shown to the customer (optional)</span>
        <textarea name="note" rows="2" maxlength="500" placeholder="e.g. Customs documents under review" style="display:block;width:100%;padding:10px;margin-top:6px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box;font-family:inherit;"></textarea>
      </label>

      <button type="submit" style="margin-top:16px;padding:11px 20px;border-radius:9px;border:0;background:#0b1d3a;color:#fff;font-weight:600;cursor:pointer;">Record incident</button>
      <details id="nx-media-details" style="margin-top:16px;background:#f8fafc;border:1px dashed #94a3b8;border-radius:10px;padding:12px;">
        <summary style="cursor:pointer;font-weight:600;color:#0b1d3a;list-style:none;display:flex;align-items:center;gap:8px;padding:4px 0;">
          📷 Attach photos / videos <span style="margin-left:auto;font-size:.8rem;font-weight:500;color:#64748b;">tap to open</span>
        </summary>
        <div style="margin-top:12px;">
          <p style="margin:0 0 10px;font-size:.85rem;color:#64748b;">Photos up to 2 MB · Videos up to 20 MB · PNG / JPG / WEBP / GIF / MP4 / MOV / WEBM</p>
          <input type="file" id="nx-il-file" multiple accept="image/png,image/jpeg,image/webp,image/gif,video/mp4,video/quicktime,video/webm" style="display:block;width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;box-sizing:border-box;">
          <select id="nx-il-context" style="margin-top:10px;padding:8px;border:1px solid #cbd5e1;border-radius:8px;width:100%;box-sizing:border-box;">
            <option value="incident">Incident evidence</option>
            <option value="damage">Damage evidence</option>
            <option value="customs">Customs documentation</option>
            <option value="pickup">Pickup photo</option>
            <option value="delivery">Proof of delivery</option>
            <option value="general">General</option>
          </select>
          <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
            <button type="button" id="nx-il-upload" style="padding:10px 18px;border:0;border-radius:8px;background:#0b1d3a;color:#fff;font-weight:600;cursor:pointer;font-family:inherit;">Upload</button>
          </div>
          <div id="nx-il-msg" style="margin-top:10px;font-size:.88rem;"></div>
        </div>
      </details>


      <div id="nx-incident-message" role="status" aria-live="polite" style="margin-top:12px;font-size:.9rem;"></div>
    </form>
  </div>
</section>

<script>
(function(){
  'use strict';
  const panel = document.getElementById('vh-hold-management-panel');
  if (!panel) return;

  const shipmentId = <?= (int)$shipmentId ?>;
  const csrfToken  = <?= json_encode(csrfToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const list = document.getElementById('vh-hold-list');
  const msg  = document.getElementById('nx-incident-message');
  const form = document.getElementById('nx-incident-form');

  function say(text, isErr) { msg.textContent = text || ''; msg.style.color = isErr ? '#b91c1c' : '#166534'; }

  async function loadHolds() {
    list.textContent = 'Loading…';
    try {
      const r = await fetch('/admin/hold-management.php?shipment_id=' + shipmentId, { headers:{'Accept':'application/json'}, cache:'no-store' });
      const j = await r.json();
      const holds = Array.isArray(j.data && j.data.holds) ? j.data.holds : (Array.isArray(j.data) ? j.data : (Array.isArray(j.holds) ? j.holds : []));
      if (!holds.length) {
        list.innerHTML = '<div style="padding:12px;background:#ecfdf5;border-radius:8px;color:#065f46;font-size:.9rem;">✓ No active holds. Shipment is moving normally.</div>';
        return;
      }
      list.innerHTML = holds.map(h =>
        '<div style="border:1px solid #fecaca;background:#fef2f2;border-radius:10px;padding:12px;margin-bottom:8px;">' +
          '<strong style="color:#991b1b;">' + (h.hold_type || 'hold').toUpperCase() + (Number(h.mandatory) === 1 ? ' · mandatory' : ' · advisory') + '</strong>' +
          '<div style="font-size:.88rem;margin-top:4px;">' + (h.reason || '') + '</div>' +
          (h.details ? '<div style="font-size:.85rem;color:#7f1d1d;margin-top:4px;">' + h.details + '</div>' : '') +
          '<div style="font-size:.78rem;color:#7f1d1d;margin-top:6px;">Since ' + (h.created_at || '') + '</div>' +
          '<div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;">' +
            '<input type="text" data-hold-id="' + h.id + '" class="nx-clear-note" placeholder="Clearance note (required)" style="flex:1;min-width:160px;padding:7px;border:1px solid #fecaca;border-radius:6px;font-size:.85rem;">' +
            '<button type="button" class="nx-clear-btn" data-hold-id="' + h.id + '" style="padding:7px 12px;border-radius:6px;border:1px solid #dc2626;background:#fff;color:#991b1b;cursor:pointer;font-weight:600;font-size:.85rem;">Clear hold</button>' +
          '</div>' +
        '</div>'
      ).join('');

      list.querySelectorAll('.nx-clear-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
          const id = btn.dataset.holdId;
          const ni = list.querySelector('.nx-clear-note[data-hold-id="' + id + '"]');
          const note = ni ? ni.value.trim() : '';
          if (!note) { say('Enter a clearance note before clearing this hold.', true); return; }
          if (!confirm('Mark this hold as formally cleared?')) return;
          btn.disabled = true;
          try {
            const body = new URLSearchParams({ action:'clear', shipment_id:String(shipmentId), hold_id:String(id), clearance_note:note, csrf_token:csrfToken });
            const r = await fetch('/admin/hold-management.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'}, body: body.toString() });
            const j = await r.json();
            if (!r.ok || j.success === false) throw new Error(j.message || 'Failed');
            say('Hold cleared.', false);
            loadHolds();
          } catch (e) { say(e.message, true); btn.disabled = false; }
        });
      });
    } catch (e) {
      list.innerHTML = '<div style="color:#b91c1c;font-size:.9rem;">Could not load holds.</div>';
    }
  }

  document.getElementById('vh-hold-refresh').addEventListener('click', loadHolds);

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(form);
    fd.set('csrf_token', csrfToken);
    fd.set('shipment_id', shipmentId);
    const inc = fd.get('incident');
    if (!inc) { say('Pick what happened first.', true); return; }
    if (!confirm('Record: ' + inc.replace(/_/g,' ') + '?')) return;
    say('Sending…', false);
    try {
      const r = await fetch('/admin/incident-action.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'}, body: new URLSearchParams(fd).toString() });
      const j = await r.json();
      if (!r.ok || !j.success) throw new Error(j.message || 'Failed');
      say(j.message || 'Recorded.', false);
      setTimeout(() => location.reload(), 900);
    } catch (err) { say(err.message, true); }
  });

  var _jmp = document.getElementById('nx-jump-to-media');
  var _iu = document.getElementById('nx-inline-uploader');
  var _ilmsg = document.getElementById('nx-il-msg');
  if (_jmp) _jmp.addEventListener('click', function(){
    if (!_iu) return;
    _iu.style.display = (_iu.style.display === 'block') ? 'none' : 'block';
  });
  var _ilc = document.getElementById('nx-il-cancel');
  if (_ilc) _ilc.addEventListener('click', function(){ if (_iu) _iu.style.display = 'none'; if (_ilmsg) _ilmsg.textContent = ''; });
  var _ilu = document.getElementById('nx-il-upload');
  if (_ilu) _ilu.addEventListener('click', function(){
    var f = document.getElementById('nx-il-file');
    var ctx = document.getElementById('nx-il-context');
    if (!f || !f.files || !f.files.length) { if (_ilmsg){_ilmsg.textContent='Pick at least one file.';_ilmsg.style.color='#b91c1c';} return; }
    var fd = new FormData();
    fd.set('action','upload');
    fd.set('shipment_id', shipmentId);
    fd.set('csrf_token', csrfToken);
    fd.set('context', ctx.value);
    for (var i=0;i<f.files.length;i++) fd.append('files[]', f.files[i]);
    _ilu.disabled = true;
    if (_ilmsg){_ilmsg.textContent='Uploading…';_ilmsg.style.color='#0b1d3a';}
    fetch('/admin/media-action.php', { method:'POST', body:fd })
      .then(function(r){return r.json();})
      .then(function(j){
        _ilu.disabled = false;
        if (!j.success) { if (_ilmsg){_ilmsg.textContent=j.message||'Failed';_ilmsg.style.color='#b91c1c';} return; }
        if (_ilmsg){_ilmsg.textContent=j.message||'Uploaded.';_ilmsg.style.color='#166534';}
        f.value = '';
        setTimeout(function(){ _iu.style.display='none'; if (_ilmsg) _ilmsg.textContent=''; }, 1200);
      })
      .catch(function(e){ _ilu.disabled = false; if (_ilmsg){_ilmsg.textContent=e.message||'Upload failed';_ilmsg.style.color='#b91c1c';} });
  });
  loadHolds();
})();
</script>
<?php include __DIR__ . '/_media_panel.php'; ?>

<style>
.nx-tabs{display:flex;gap:.3rem;margin:18px 0 0;flex-wrap:wrap;border-bottom:2px solid #e2e8f0;}
.nx-tab{padding:10px 16px;background:transparent;border:0;border-bottom:3px solid transparent;color:#475569;font-family:inherit;font-weight:600;font-size:.92rem;cursor:pointer;margin-bottom:-2px;transition:.15s;}
.nx-tab:hover{color:#0b1d3a;background:#f8fafc;}
.nx-tab.active{color:#0b1d3a;border-bottom-color:#f0b429;}
.nx-badge{display:inline-block;background:#dc2626;color:#fff;font-size:.7rem;font-weight:800;padding:1px 7px;border-radius:999px;margin-left:5px;vertical-align:middle;}
@media (max-width:640px){.nx-tab{padding:9px 11px;font-size:.82rem;}}
</style>


<script>
<?php
$__nxHoldBadge = '';
try {
    $__hStmt = Database::connection()->prepare("SELECT COUNT(*) FROM shipment_holds WHERE shipment_id = :id AND status = 'active'");
    $__hStmt->execute([':id' => (int)$shipmentId]);
    $__hCount = (int)$__hStmt->fetchColumn();
    if ($__hCount > 0) $__nxHoldBadge = ' <span class="nx-badge">' . $__hCount . '</span>';
} catch (Throwable $e) { $__nxHoldBadge = ''; }
?>

(function(){
  'use strict';
  var headerTitle = document.querySelector('h1.tracking');
  if (!headerTitle) return;
  var headerSec = headerTitle.closest('section');
  if (!headerSec) return;

  var groups = {
    overview: ['Shipment Information', 'Current Position'],
    map:      ['Global Live Tracking', 'Global Shipment Route'],
    controls: ['Tracking Controls', 'Movement Settings', 'Operational Event'],
    timeline: ['Tracking Timeline'],
    media:    ['Photos & Videos']
  };

  var assigned = new Map();

  function assignSection(titleText, tabName) {
    var h2s = document.querySelectorAll('h2');
    for (var i = 0; i < h2s.length; i++) {
      if (h2s[i].textContent.trim() === titleText) {
        var sec = h2s[i].closest('section');
        if (sec && sec !== headerSec && !assigned.has(sec)) {
          assigned.set(sec, tabName);
          return true;
        }
      }
    }
    return false;
  }

  Object.keys(groups).forEach(function(tab){
    groups[tab].forEach(function(title){ assignSection(title, tab); });
  });

  // Holds panel by ID
  var holdsSec = document.getElementById('vh-hold-management-panel');
  if (holdsSec) assigned.set(holdsSec, 'holds');

  var tabBar = document.createElement('nav');
  tabBar.className = 'nx-tabs';
  var badge = <?= json_encode($__nxHoldBadge, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  tabBar.innerHTML =
    '<button type="button" class="nx-tab" data-tab="overview">Overview</button>' +
    '<button type="button" class="nx-tab" data-tab="map">Live Map</button>' +
    '<button type="button" class="nx-tab" data-tab="controls">Controls</button>' +
    '<button type="button" class="nx-tab" data-tab="timeline">Timeline</button>' +
    '<button type="button" class="nx-tab" data-tab="holds">Holds &amp; Incidents' + badge + '</button>' +
    '<button type="button" class="nx-tab" data-tab="media">Photos &amp; Videos</button>';

  headerSec.insertAdjacentElement('afterend', tabBar);

  function showTab(name) {
    assigned.forEach(function(t, sec){ sec.style.display = (t === name) ? '' : 'none'; });
    Array.prototype.forEach.call(tabBar.querySelectorAll('.nx-tab'), function(b){
      b.classList.toggle('active', b.dataset.tab === name);
    });
    setTimeout(function(){
      try { window.dispatchEvent(new Event('resize')); } catch(e){}
    }, 120);
  }

  tabBar.addEventListener('click', function(e){
    var btn = e.target.closest('.nx-tab');
    if (!btn) return;
    showTab(btn.dataset.tab);
  });

  var statusEl = document.getElementById('shipment-status-badge');
  var status = statusEl ? statusEl.textContent.trim().toLowerCase() : '';
  var defaultTab = (status.indexOf('transit') !== -1 || status.indexOf('moving') !== -1) ? 'map' : 'overview';
  showTab(defaultTab);
})();
</script>

<<script>
/* nx-tl-toggle-init */
(function(){
  "use strict";
  var wrap = document.querySelector(".nx-tl-scroll");
  if (!wrap) return;
  var tl = wrap.querySelector("#tracking-timeline");
  if (!tl) return;
  var events = Array.prototype.slice.call(tl.querySelectorAll(".event"));
  if (events.length <= 5) return;
  var btn = document.getElementById("nx-tl-toggle");
  if (!btn) return;
  var expanded = false;
  function apply(){
    events.forEach(function(el, i){ el.style.display = (expanded || i < 5) ? "" : "none"; });
    btn.textContent = expanded ? "Show less" : ("Show all (" + (events.length - 5) + " more)");
  }
  btn.addEventListener("click", function(){
    expanded = !expanded;
    apply();
  });
  apply();
})();
</script>




<style>
/* nx-fs-inside */
#nx-map-fs-wrap { position: relative; }
#nx-map-fs-btn { position: absolute; bottom: 14px; right: 14px; z-index: 9000; background: rgba(11,29,58,.92); color: #fff; border: 1px solid rgba(240,180,41,.5); border-radius: 9px; padding: .55rem .9rem; font-size: .82rem; font-weight: 600; cursor: pointer; font-family: inherit; }
#nx-map-fs-btn:hover { background: #132a52; }
body.nx-map-fs-active { overflow: hidden !important; background: #000 !important; }
body.nx-map-fs-active #nx-map-fs-wrap {
  position: fixed !important; top: 0 !important; left: 0 !important;
  width: 100vw !important; height: 100vh !important;
  z-index: 10000 !important; border-radius: 0 !important;
}
body.nx-map-fs-active #globalTrackingMap {
  width: 100vw !important; height: 100vh !important;
  max-width: none !important; max-height: none !important;
  border-radius: 0 !important;
  margin: 0 !important; padding: 0 !important;
  position: relative !important;
}
body.nx-map-fs-active #globalTrackingMap .maplibregl-canvas {
  width: 100% !important; height: 100% !important;
}
body.nx-map-fs-active #nx-map-fs-btn {
  position: fixed !important; top: 14px !important; right: 14px !important;
  bottom: auto !important; z-index: 10002 !important;
}
</style>

<script>
/* nx-fs-inside-handler */
(function(){
  "use strict";
  function init(){
    var mapEl = document.getElementById('globalTrackingMap');
    if (!mapEl) return;

    // Wrap the map in a relative container so the button lives INSIDE the map
    if (!mapEl.parentNode.classList.contains('nx-map-fs-wrap-applied')) {
      var wrap = document.createElement('div');
      wrap.id = 'nx-map-fs-wrap';
      mapEl.parentNode.insertBefore(wrap, mapEl);
      wrap.appendChild(mapEl);
      wrap.classList.add('nx-map-fs-wrap-applied');
    }

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.id = 'nx-map-fs-btn';
    btn.textContent = '⛶ Fullscreen';
    document.getElementById('nx-map-fs-wrap').appendChild(btn);

    function toggleFs(forceOpen){
      var active = document.body.classList.contains('nx-map-fs-active');
      if (forceOpen === true && active) return;
      if (forceOpen === false && !active) return;

      document.body.classList.toggle('nx-map-fs-active', !active);
      var nowActive = document.body.classList.contains('nx-map-fs-active');
      btn.textContent = nowActive ? '✕ Close fullscreen' : '⛶ Fullscreen';
      // Force MapLibre to fully recompute size. Reading offsetWidth/Height after
      // toggling display guarantees layout has flushed before we call resize().
      var el = document.getElementById('globalTrackingMap');
      var timings = [0, 50, 150, 300, 500, 800, 1200, 1600];
      timings.forEach(function(t){
        setTimeout(function(){
          try {
            if (el) {
              var w = el.offsetWidth, h = el.offsetHeight;
              // Force a layout flush by touching a property
              void el.offsetWidth;
            }
            if (window.map && map.resize) map.resize();
            if (window.map && map.getCanvas) {
              var cvs = map.getCanvas();
              var el2 = document.getElementById('globalTrackingMap');
              if (cvs && el2) {
                cvs.width  = el2.offsetWidth  * (window.devicePixelRatio || 1);
                cvs.height = el2.offsetHeight * (window.devicePixelRatio || 1);
                cvs.style.width  = el2.offsetWidth  + 'px';
                cvs.style.height = el2.offsetHeight + 'px';
              }
            }
          } catch(e){}
        }, t);
      });
      setTimeout(function(){ try { window.dispatchEvent(new Event('resize')); } catch(e){} }, 120);
    }

    btn.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); toggleFs(); });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape') toggleFs(false); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
</script>

<script>
/* nxValidateShipControl */
(function(){
  "use strict";
  function init(){
    // ---- Incident form ----
    var incForm = document.getElementById("nx-incident-form");
    if (incForm) {
      incForm.addEventListener("submit", function(e){
        var sel = incForm.querySelector("select[name=incident]");
        var delayVal = incForm.querySelector("input[name=delay_value]");
        var delayUnit = incForm.querySelector("select[name=delay_unit]");
        if (!sel || !sel.value) { e.preventDefault(); return NXP.err("Pick what happened from the dropdown first."); }
        if (delayVal) {
          var v = parseFloat(delayVal.value);
          if (isNaN(v) || v < 0) { e.preventDefault(); return NXP.err("Delay amount must be zero or a positive number."); }
          if (v > 100000) { e.preventDefault(); return NXP.err("Delay amount is unrealistically large. Use weeks or months for long delays."); }
        }
        if (delayUnit) {
          var u = String(delayUnit.value || "").toLowerCase();
          if (["minutes","hours","days","weeks","months"].indexOf(u) === -1) {
            e.preventDefault(); return NXP.err("Pick a valid delay unit.");
          }
        }
      }, true);
    }

    // ---- Inline media uploader (Issues tab) ----
    var inlineUpBtn = document.getElementById("nx-il-upload");
    if (inlineUpBtn) {
      inlineUpBtn.addEventListener("click", function(e){
        var f = document.getElementById("nx-il-file");
        if (!f || !f.files || !f.files.length) { e.stopImmediatePropagation(); e.preventDefault(); return NXP.err("Pick at least one photo or video first."); }
        var maxPhoto = 2 * 1024 * 1024, maxVideo = 20 * 1024 * 1024;
        var allowed = ["image/png","image/jpeg","image/webp","image/gif","video/mp4","video/quicktime","video/webm"];
        for (var i = 0; i < f.files.length; i++) {
          var file = f.files[i];
          if (allowed.indexOf(file.type) === -1) { e.stopImmediatePropagation(); e.preventDefault(); return NXP.err("\"" + file.name + "\" — unsupported type (" + file.type + ")."); }
          var isVid = file.type.indexOf("video/") === 0;
          var cap = isVid ? maxVideo : maxPhoto;
          if (file.size > cap) { e.stopImmediatePropagation(); e.preventDefault(); return NXP.err("\"" + file.name + "\" exceeds the " + (isVid ? "20 MB" : "2 MB") + " limit."); }
        }
      }, true);
    }

    // ---- Media panel uploader (Photos & Videos tab) ----
    var mediaUpBtn = document.getElementById("nx-media-upload");
    if (mediaUpBtn) {
      mediaUpBtn.addEventListener("click", function(e){
        var f = document.getElementById("nx-media-input");
        if (!f || !f.files || !f.files.length) { e.stopImmediatePropagation(); e.preventDefault(); return NXP.err("Pick at least one photo or video first."); }
        var maxPhoto = 2 * 1024 * 1024, maxVideo = 20 * 1024 * 1024;
        var allowed = ["image/png","image/jpeg","image/webp","image/gif","video/mp4","video/quicktime","video/webm"];
        for (var i = 0; i < f.files.length; i++) {
          var file = f.files[i];
          if (allowed.indexOf(file.type) === -1) { e.stopImmediatePropagation(); e.preventDefault(); return NXP.err("\"" + file.name + "\" — unsupported type."); }
          var isVid = file.type.indexOf("video/") === 0;
          if (file.size > (isVid ? maxVideo : maxPhoto)) { e.stopImmediatePropagation(); e.preventDefault(); return NXP.err("\"" + file.name + "\" is too large."); }
        }
      }, true);
    }

    // ---- Movement settings form (speed + tracking mode) ----
    var speedInput = document.getElementById("control-speed");
    if (speedInput) {
      var movBtn = document.getElementById("save-movement");
      if (movBtn) {
        movBtn.addEventListener("click", function(e){
          var v = parseFloat(speedInput.value);
          if (isNaN(v) || v < 0) { e.stopImmediatePropagation(); e.preventDefault(); return NXP.err("Speed must be zero or a positive number."); }
          if (v > 2000) { e.stopImmediatePropagation(); e.preventDefault(); return NXP.err("Speed above 2,000 km/h is not realistic — check the value."); }
        }, true);
      }
    }

    // ---- Save Position (lat/lng inputs) ----
    var latIn = document.getElementById("current-latitude-input");
    var lngIn = document.getElementById("current-longitude-input");
    var posBtn = document.querySelector("[onclick=\"savePosition()\"]") || document.getElementById("save-position");
    if (posBtn && latIn && lngIn) {
      posBtn.addEventListener("click", function(e){
        var lat = parseFloat(latIn.value), lng = parseFloat(lngIn.value);
        if (isNaN(lat) || lat < -90 || lat > 90) { e.stopImmediatePropagation(); e.preventDefault(); return NXP.err("Latitude must be between -90 and 90."); }
        if (isNaN(lng) || lng < -180 || lng > 180) { e.stopImmediatePropagation(); e.preventDefault(); return NXP.err("Longitude must be between -180 and 180."); }
      }, true);
    }

    // ---- Operational Event form ----
    var eventSelect = document.getElementById("event-type");
    if (eventSelect) {
      var evBtn = document.querySelector("button[onclick=\"recordEvent()\"]");
      if (evBtn) {
        evBtn.addEventListener("click", function(e){
          if (!eventSelect.value) { e.stopImmediatePropagation(); e.preventDefault(); return NXP.err("Pick an event type first."); }
          var delayEl = document.getElementById("event-delay");
          if (delayEl && delayEl.value !== "") {
            var d = parseInt(delayEl.value, 10);
            if (isNaN(d) || d < 0) { e.stopImmediatePropagation(); e.preventDefault(); return NXP.err("Delay must be zero or a positive number of minutes."); }
          }
        }, true);
      }
    }
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();
</script>


<script>
/* nxHoldsCollapse */
(function(){
  "use strict";
  function init(){
    var list = document.getElementById("vh-hold-list");
    if (!list) return;

    // Watch for holds being rendered, then collapse if > 2
    function apply() {
      var items = list.querySelectorAll("div[style*=\"border:1px solid #fecaca\"]");
      if (items.length <= 2) return;
      // Only collapse once
      if (list.dataset.nxCollapsed === "1") return;
      list.dataset.nxCollapsed = "1";

      for (var i = 2; i < items.length; i++) items[i].style.display = "none";

      var btn = document.createElement("button");
      btn.type = "button";
      btn.style.cssText = "display:block;margin:10px auto 0;padding:.55rem 1.2rem;border-radius:9px;border:1px solid #fecaca;background:#fff;color:#991b1b;font-family:inherit;font-weight:600;font-size:.85rem;cursor:pointer;";
      btn.textContent = "Show all " + items.length + " issues";
      var open = false;
      btn.addEventListener("click", function(){
        open = !open;
        for (var j = 2; j < items.length; j++) items[j].style.display = open ? "" : "none";
        btn.textContent = open ? "Show fewer" : ("Show all " + items.length + " issues");
      });
      list.parentNode.insertBefore(btn, list.nextSibling);
    }

    // Initial + observer for when holds list refreshes
    apply();
    var obs = new MutationObserver(function(){ apply(); });
    obs.observe(list, { childList: true, subtree: true });
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();
</script>

</body>
</html>
