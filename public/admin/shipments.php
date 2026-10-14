<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once BASE_PATH . '/app/Database.php';
require_once BASE_PATH . '/app/Auth.php';

use VibrantHub\Auth;
use VibrantHub\Database;

Auth::requireAdmin();

$db = Database::connection();

// Active carriers for the "Carrier / partner" picker on the create form.
$nxCarriers = [];
try {
    $nxCarriers = $db->query("SELECT id, name, code FROM carriers WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
} catch (Throwable $e) {}

// Load editable form placeholders from site_content
$nxPh = [];
try {
    foreach ($db->query("SELECT content_key, value FROM site_content WHERE section = 'form'") as $r) {
        $nxPh[$r['content_key']] = $r['value'];
    }
} catch (Throwable $e) {}
function nxph(array $map, string $key, string $default = ''): string {
    $v = $map[$key] ?? '';
    return $v !== '' ? $v : $default;
}
$message = null;
$error = null;

/**
 * Calculate great-circle distance between two coordinates in kilometres.
 * Uses the same Haversine calculation as the tracking engine.
 */
function shipmentDistanceKm(
    float $lat1,
    float $lng1,
    float $lat2,
    float $lng2
): float {
    $earthRadiusKm = 6371.0088;

    $lat1Rad = deg2rad($lat1);
    $lat2Rad = deg2rad($lat2);
    $deltaLat = deg2rad($lat2 - $lat1);
    $deltaLng = deg2rad($lng2 - $lng1);

    $a = sin($deltaLat / 2) ** 2
        + cos($lat1Rad)
        * cos($lat2Rad)
        * sin($deltaLng / 2) ** 2;

    return $earthRadiusKm
        * 2
        * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('Invalid security token.');
        }

        $trackingNumber = trim((string)($_POST['tracking_number'] ?? ''));
        $transportType = trim((string)($_POST['transport_type'] ?? 'vehicle'));
        $customerName = trim((string)($_POST['customer_name'] ?? ''));
        $customerEmail = trim((string)($_POST['customer_email'] ?? ''));
        $customerPhone = trim((string)($_POST['customer_phone'] ?? ''));
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $vehicleName = trim((string)($_POST['vehicle_name'] ?? ''));
        $vehicleNumber = trim((string)($_POST['vehicle_number'] ?? ''));
        $status = trim((string)($_POST['status'] ?? 'pending'));
        $origin = trim((string)($_POST['origin'] ?? ''));
        $destination = trim((string)($_POST['destination'] ?? ''));

        $originLat = (float)($_POST['origin_lat'] ?? 0);
        $originLng = (float)($_POST['origin_lng'] ?? 0);
        $destinationLat = (float)($_POST['destination_lat'] ?? 0);
        $destinationLng = (float)($_POST['destination_lng'] ?? 0);

        $speed = (float)($_POST['speed'] ?? 0);
        $estimatedDelivery = trim((string)($_POST['estimated_delivery'] ?? ''));
        $packageDescription = trim((string)($_POST['package_description'] ?? ''));

        if (
            $trackingNumber === '' ||
            $customerName === '' ||
            $origin === '' ||
            $destination === ''
        ) {
            throw new RuntimeException('Please complete all required fields.');
        }

        if (!in_array($transportType, ['vehicle', 'flight', 'ship', 'train', 'van', 'bike', 'pedestrian'], true)) {
            throw new RuntimeException('Invalid transport type.');
        }

        if (!in_array($status, ['pending', 'in_transit', 'delayed', 'delivered', 'held'], true)) {
            throw new RuntimeException('Invalid shipment status.');
        }

        if ($customerEmail !== '' && !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Please enter a valid customer email address.');
        }

        // ---- Resolve / create the linked customer record -------------------
        if ($customerId > 0) {
            $cs = $db->prepare('SELECT * FROM customers WHERE id = :id LIMIT 1');
            $cs->execute([':id' => $customerId]);
            $cust = $cs->fetch();
            if (!$cust) {
                $customerId = 0;
            } else {
                if ($customerName === '')  { $customerName = (string) $cust['name']; }
                if ($customerEmail === '') { $customerEmail = (string) $cust['email']; }
                if ($customerPhone === '') { $customerPhone = (string) $cust['phone']; }
            }
        }

        if ($customerId === 0) {
            $found = null;
            if ($customerEmail !== '') {
                $cs = $db->prepare('SELECT * FROM customers WHERE email = :e LIMIT 1');
                $cs->execute([':e' => $customerEmail]);
                $found = $cs->fetch() ?: null;
            }
            if (!$found && $customerPhone !== '') {
                $cs = $db->prepare('SELECT * FROM customers WHERE phone = :p LIMIT 1');
                $cs->execute([':p' => $customerPhone]);
                $found = $cs->fetch() ?: null;
            }
            if ($found) {
                $customerId = (int) $found['id'];
            } elseif ($customerEmail !== '' || $customerPhone !== '') {
                $db->prepare('INSERT INTO customers (name, email, phone) VALUES (:n, :e, :p)')
                   ->execute([':n' => $customerName, ':e' => $customerEmail ?: null, ':p' => $customerPhone ?: null]);
                $customerId = (int) $db->lastInsertId();
            }
        }

        if (
            $originLat < -90 || $originLat > 90 ||
            $destinationLat < -90 || $destinationLat > 90 ||
            $originLng < -180 || $originLng > 180 ||
            $destinationLng < -180 || $destinationLng > 180
        ) {
            throw new RuntimeException('Invalid geographic coordinates.');
        }

        if ($speed < 0) {
            throw new RuntimeException('Speed cannot be negative.');
        }

        if ($status === 'in_transit' && $speed <= 0) {
            throw new RuntimeException(
                'An in-transit shipment must have an initial speed greater than zero.'
            );
        }

        $distanceTotal = shipmentDistanceKm(
            $originLat,
            $originLng,
            $destinationLat,
            $destinationLng
        );

        if ($distanceTotal <= 0.001) {
            throw new RuntimeException(
                'Origin and destination must be different locations.'
            );
        }

        $initialSpeed = $status === 'in_transit' ? $speed : 0;
        $initialStatus = $status;

        $db->beginTransaction();

        try {
            $stmt = $db->prepare(
                'INSERT INTO shipments (
                    tracking_number,
                    customer_id,
                    customer_name,
                    customer_email,
                    customer_phone,
                    package_description,
                    transport_type,
                    vehicle_name,
                    vehicle_number,
                    origin,
                    destination,
                    origin_lat,
                    origin_lng,
                    destination_lat,
                    destination_lng,
                    current_lat,
                    current_lng,
                    speed,
                    distance_total,
                    distance_travelled,
                    distance_remaining,
                    status,
                    eta,
                    estimated_delivery,
                    tracking_mode,
                    last_update,
                    created_at,
                    updated_at
                ) VALUES (
                    :tracking_number,
                    :customer_id,
                    :customer_name,
                    :customer_email,
                    :customer_phone,
                    :package_description,
                    :transport_type,
                    :vehicle_name,
                    :vehicle_number,
                    :origin,
                    :destination,
                    :origin_lat,
                    :origin_lng,
                    :destination_lat,
                    :destination_lng,
                    :current_lat,
                    :current_lng,
                    :speed,
                    :distance_total,
                    0,
                    :distance_remaining,
                    :status,
                    :eta,
                    :estimated_delivery,
                    :tracking_mode,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                )'
            );

            $stmt->execute([
                'tracking_number' => $trackingNumber,
                'customer_id' => $customerId > 0 ? $customerId : null,
                'customer_name' => $customerName,
                'customer_email' => $customerEmail !== '' ? $customerEmail : null,
                'customer_phone' => $customerPhone !== '' ? $customerPhone : null,
                'package_description' => $packageDescription !== '' ? $packageDescription : null,
                'transport_type' => $transportType,
                'vehicle_name' => $vehicleName !== '' ? $vehicleName : null,
                'vehicle_number' => $vehicleNumber !== '' ? $vehicleNumber : null,
                'origin' => $origin,
                'destination' => $destination,
                'origin_lat' => $originLat,
                'origin_lng' => $originLng,
                'destination_lat' => $destinationLat,
                'destination_lng' => $destinationLng,
                'current_lat' => $originLat,
                'current_lng' => $originLng,
                'speed' => $initialSpeed,
                'distance_total' => $distanceTotal,
                'distance_remaining' => $distanceTotal,
                'status' => $initialStatus,
                'eta' => $estimatedDelivery !== '' ? $estimatedDelivery : null,
                'estimated_delivery' => $estimatedDelivery !== '' ? $estimatedDelivery : null,
                'tracking_mode' => 'simulation'
            ]);

            $shipmentId = (int)$db->lastInsertId();

    $nxCid = (int)($_POST['carrier_id'] ?? 0);
    if ($nxCid > 0) {
        $db->prepare('UPDATE shipments SET carrier_id = :cid WHERE id = :id')->execute([':cid' => $nxCid, ':id' => $shipmentId]);
    }

            $control = $db->prepare(
                'INSERT INTO tracking_controls (
                    shipment_id,
                    is_running,
                    control_speed,
                    tracking_mode
                ) VALUES (
                    :shipment_id,
                    :is_running,
                    :control_speed,
                    :tracking_mode
                )'
            );

            $control->execute([
                'shipment_id' => $shipmentId,
                'is_running' => $status === 'in_transit' ? 1 : 0,
                'control_speed' => $initialSpeed,
                'tracking_mode' => 'simulation'
            ]);

            $event = $db->prepare(
                'INSERT INTO tracking_events (
                    shipment_id,
                    status,
                    title,
                    description,
                    location
                ) VALUES (
                    :shipment_id,
                    :status,
                    :title,
                    :description,
                    :location
                )'
            );

            $event->execute([
                'shipment_id' => $shipmentId,
                'status' => $initialStatus,
                'title' => 'Shipment created',
                'description' => 'Shipment tracking has been initialized.',
                'location' => $origin
            ]);

            $db->commit();

            nxAudit('shipment.create', 'shipment', $shipmentId, $trackingNumber);

            // --- Upload pickup media ---
            if (!empty($_FILES['pickup_media']) && is_array($_FILES['pickup_media']['name'])) {
                require_once __DIR__ . '/_upload_media_after_create.php';
                nxAttachCreateMedia($shipmentId, !empty($_POST['pickup_media_public']));
            }

$message =
                'Shipment created successfully. Tracking number: ' .
                $trackingNumber .
                ' | Route distance: ' .
                number_format($distanceTotal, 2) .
                ' km';
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }
    } catch (Throwable $e) {
        $message = null;

        if ($e instanceof PDOException && (int)$e->getCode() === 23000) {
            $error = 'That tracking number already exists. Please use a unique tracking number.';
        } else {
            $error = $e->getMessage();
        }
    }
}

$admin = Auth::admin();

$customerOptions = [];
try {
    $customerOptions = $db->query('SELECT id, name, email, phone FROM customers ORDER BY name')->fetchAll();
} catch (Throwable $e) {
    $customerOptions = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Nexora Logistics - Create Shipment</title>
<style>
body{
    margin:0;
    font-family:Arial,sans-serif;
    background:#f4f7fb;
    color:#172033
}
.page{
    max-width:1100px;
    margin:auto;
    padding:25px
}
.top{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:25px
}
.back{
    color:#2563eb;
    text-decoration:none
}
.form{
    background:#fff;
    padding:25px;
    border-radius:12px;
    box-shadow:0 2px 10px rgba(0,0,0,.05)
}
.grid{
    display:grid;
    grid-template-columns:repeat(2,1fr);
    gap:18px
}
.field{
    display:flex;
    flex-direction:column;
    gap:7px
}
.field label{
    font-weight:600;
    font-size:14px
}
.field input,
.field select,
.field textarea{
    padding:12px;
    border:1px solid #d1d5db;
    border-radius:8px;
    font-size:15px
}
.field textarea{
    min-height:90px;
    resize:vertical
}
.full{
    grid-column:1/-1
}
.button{
    margin-top:20px;
    padding:13px 20px;
    background:#2563eb;
    color:#fff;
    border:0;
    border-radius:8px;
    font-weight:600;
    font-size:15px;
    cursor:pointer
}
.notice{
    padding:12px;
    margin-bottom:15px;
    border-radius:8px
}
.success{
    background:#dcfce7;
    color:#166534
}
.error{
    background:#fee2e2;
    color:#991b1b
}
@media(max-width:700px){
    .grid{
        grid-template-columns:1fr
    }
    .full{
        grid-column:auto
    }
    .top{
        align-items:flex-start;
        gap:10px
    }
    .page{
        padding:15px
    }
}
</style>
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
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
    <a href="/admin/shipments-hub.php" class="active">Shipments</a>
    <a href="/admin/people.php">People</a>
    <a href="/admin/content.php">Content</a>
    <a href="/admin/settings.php">Settings</a>
    <a href="/" target="_blank" rel="noopener" style="color:#ffd166 !important;">🌐 View Site</a>
    <a href="/admin/login.php?logout=1">Logout</a>
  </nav>
</header>


<div class="page">

    <div class="top">
        <div>
            <h1>Create Shipment</h1>
            <p>Create and configure a shipment for live tracking.</p>
        </div>

        <a class="back" href="/admin/index.php">← Dashboard</a>
    </div>

    <div class="form">

        <?php if ($message): ?>
            <div class="notice success">
                <?= e($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="notice error">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(csrfToken()) ?>"
            >

            <div class="grid">

                <div class="field">
                    <label>Tracking Number</label>
                    <input
                        type="text"
                        name="tracking_number"
                        placeholder="<?= htmlspecialchars(nxph($nxPh, 'ph_tracking_number', 'NX-2026-0001')) ?>"
                        required
                    >
                </div>

                <div class="field">
                    <label>Transport Type</label>
                    <select name="transport_type" required>
                        <option value="vehicle">🚚 Vehicle / Truck</option>
                        <option value="flight">✈️ Flight</option>
                        <option value="ship">🚢 Ship</option>
                        <option value="train">🚆 Train</option>
                        <option value="van">🚐 Van</option>
                        <option value="bike">🏍️ Bike / Courier</option>
                        <option value="pedestrian">🚶 Pedestrian</option>
                    </select>
                </div>

                <div class="field">
                    <label>Existing customer (optional)</label>
                    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                        <select name="customer_id" id="nx-customer-select" style="flex:1;min-width:180px;">
                            <option value="">— New customer / not linked —</option>
                            <?php foreach ($customerOptions as $co): ?>
                                <option
                                    value="<?= (int) $co['id'] ?>"
                                    data-name="<?= htmlspecialchars((string) $co['name'], ENT_QUOTES) ?>"
                                    data-email="<?= htmlspecialchars((string) $co['email'], ENT_QUOTES) ?>"
                                    data-phone="<?= htmlspecialchars((string) $co['phone'], ENT_QUOTES) ?>"
                                ><?= htmlspecialchars((string) $co['name'], ENT_QUOTES) ?><?= $co['email'] ? ' · ' . htmlspecialchars((string) $co['email'], ENT_QUOTES) : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button
                            type="button"
                            class="btn btn-outline"
                            id="nx-copy-customer"
                            style="white-space:nowrap;color:#0b1d3a;border-color:#cbd5e1;"
                        >📋 Copy Customer Details</button>
                    </div>
                    <p style="font-size:.78rem;color:#94a3b8;margin:.35rem 0 0;">Pick a saved customer and copy their name, email and phone into the fields below.</p>
                </div>

                <div class="field">
                    <label>Customer Name</label>
                    <input type="text" name="customer_name" id="nx-customer-name" required>
                </div>

                <div class="field">
                    <label>Customer Email</label>
                    <input type="email" name="customer_email" id="nx-customer-email">
                </div>

                <div class="field">
                    <label>Customer Phone</label>
                    <input type="text" name="customer_phone" id="nx-customer-phone">
                </div>

                <div class="field">
                    <label>Vehicle / Carrier Name</label>
                    <input
                        type="text"
                        name="vehicle_name"
                        placeholder="<?= htmlspecialchars(nxph($nxPh, 'ph_vehicle_name', 'Carrier or vehicle name')) ?>"
                    >
                </div>

                <div class="field">
                    <label>Vehicle / Flight / Vessel Number</label>
                    <input
                        type="text"
                        name="vehicle_number"
                        placeholder="<?= htmlspecialchars(nxph($nxPh, 'ph_vehicle_number', 'VH-001')) ?>"
                    >
                </div>

                <div class="field">
                    <label>Status</label>
                    <select name="status">
                        <option value="pending">Pending</option>
                        <option value="in_transit">In Transit</option>
                        <option value="delayed">Delayed</option>
                        <option value="delivered">Delivered</option>
                        <option value="held">Held</option>
                    </select>
                </div>

                <div class="field">
                    <label>Origin</label>
                    <input
                        type="text"
                        name="origin"
                        placeholder="<?= htmlspecialchars(nxph($nxPh, 'ph_origin', 'e.g. Los Angeles, USA')) ?>"
                        required
                    >
                </div>

                <div class="field">
                    <label>Destination</label>
                    <input
                        type="text"
                        name="destination"
                        placeholder="<?= htmlspecialchars(nxph($nxPh, 'ph_destination', 'e.g. London, United Kingdom')) ?>"
                        required
                    >
                </div>

                <div class="field">
                    <label>Origin Latitude</label>
                    <input
                        type="number"
                        step="any"
                        name="origin_lat"
                        placeholder="<?= htmlspecialchars(nxph($nxPh, 'ph_origin_lat', '34.0522')) ?>"
                        required
                    >
                </div>

                <div class="field">
                    <label>Origin Longitude</label>
                    <input
                        type="number"
                        step="any"
                        name="origin_lng"
                        placeholder="<?= htmlspecialchars(nxph($nxPh, 'ph_origin_lng', '-118.2437')) ?>"
                        required
                    >
                </div>

                <div class="field">
                    <label>Destination Latitude</label>
                    <input
                        type="number"
                        step="any"
                        name="destination_lat"
                        placeholder="<?= htmlspecialchars(nxph($nxPh, 'ph_destination_lat', '51.5074')) ?>"
                        required
                    >
                </div>

                <div class="field">
                    <label>Destination Longitude</label>
                    <input
                        type="number"
                        step="any"
                        name="destination_lng"
                        placeholder="<?= htmlspecialchars(nxph($nxPh, 'ph_destination_lng', '-0.1278')) ?>"
                        required
                    >
                </div>

                <div class="field">
                    <label>Initial Speed (km/h)</label>
                    <input
                        type="number"
                        step="any"
                        min="0"
                        name="speed"
                        value="0"
                    >
                </div>

                <div class="field">
                    <label>Estimated Delivery</label>
                    <input
                        type="datetime-local"
                        name="estimated_delivery"
                    >
                </div>

                <div class="field full">
                    <label>Package Description</label>
                    <textarea
                        name="package_description"
                        placeholder="<?= htmlspecialchars(nxph($nxPh, 'ph_package_description', 'Describe the shipment contents…')) ?>"
                    ></textarea>
                </div>

            </div>

            <div style="margin-top:16px;padding:14px;border:1px dashed #94a3b8;border-radius:10px;background:#f8fafc;">
    <strong style="display:block;margin-bottom:6px;color:#0b1d3a;">📷 Photos &amp; videos (optional)</strong>
    <p style="margin:0 0 10px;font-size:.85rem;color:#64748b;">Attach pickup / inventory photos or videos of this shipment. Photos up to 2 MB · Videos up to 20 MB.</p>
    <input type="file" name="pickup_media[]" multiple accept="image/png,image/jpeg,image/webp,image/gif,video/mp4,video/quicktime,video/webm" style="display:block;width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;box-sizing:border-box;">
    <label style="display:flex;align-items:center;gap:.5rem;margin-top:10px;font-size:.85rem;color:#475569;">
        <input type="checkbox" name="pickup_media_public" value="1" checked> Show to customer on tracking page
    </label>
</div>

<div style="margin-top:16px;padding:14px;border:1px dashed #94a3b8;border-radius:10px;background:#f8fafc;">
    <label style="display:block;font-size:.8rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.35rem;">Carrier / partner (optional)</label>
    <select name="carrier_id" style="width:100%;padding:.65rem .85rem;border:1px solid #cbd5e1;border-radius:9px;font-family:inherit;font-size:.95rem;box-sizing:border-box;">
        <option value="">— None —</option>
        <?php foreach ($nxCarriers as $cc): ?>
            <option value="<?= (int)$cc['id'] ?>"><?= htmlspecialchars($cc['name'] . ($cc['code'] ? ' (' . $cc['code'] . ')' : ''), ENT_QUOTES) ?></option>
        <?php endforeach; ?>
    </select>
    <p style="margin:6px 0 0;font-size:.78rem;color:#64748b;">Logo chip on shipment cards. Manage in <a href="/admin/carriers.php" style="color:#0b1d3a;">Settings → Carriers</a>.</p>
</div>

<button class="button" type="submit">
                Create Shipment
            </button>

        </form>

    </div>

</div>


<div id="nx-lng-hint" style="display:none;position:fixed;bottom:16px;left:16px;right:16px;background:#fef3c7;color:#78350f;border:1px solid #fbbf24;border-radius:10px;padding:12px 16px;font-size:.85rem;z-index:99999;box-shadow:0 6px 18px rgba(0,0,0,.15);">
  <strong>Longitude sign check:</strong> <span id="nx-lng-msg"></span>
  <button type="button" onclick="document.getElementById('nx-lng-hint').style.display='none'" style="float:right;background:none;border:0;font-size:1.1rem;cursor:pointer;color:#78350f;">×</button>
</div>
<script>
(function(){
  var WEST = ["los angeles","san francisco","new york","seattle","chicago","houston","miami","boston","washington","las vegas","portland","vancouver","toronto","montreal","mexico city","bogota","lima","santiago","sao paulo","buenos aires","rio de janeiro"];
  var EAST = ["london","paris","madrid","rome","berlin","lagos","abuja","cairo","dubai","mumbai","delhi","karachi","shanghai","beijing","tokyo","seoul","singapore","sydney","jakarta","bangkok","hanoi","manila","hong kong"];
  function check(nameField, lngField){
    if (!nameField || !lngField) return;
    var n = (nameField.value||"").toLowerCase();
    var v = parseFloat(lngField.value||"0");
    if (isNaN(v)) return;
    var box = document.getElementById("nx-lng-hint");
    var msg = document.getElementById("nx-lng-msg");
    for (var i=0;i<WEST.length;i++) if (n.indexOf(WEST[i]) !== -1) {
      if (v > 0) { msg.textContent = nameField.value + " is West — longitude should be negative (e.g. -118.2437)."; box.style.display = "block"; return; }
    }
    for (var j=0;j<EAST.length;j++) if (n.indexOf(EAST[j]) !== -1) {
      if (v < 0) { msg.textContent = nameField.value + " is East — longitude should be positive (e.g. 139.6503)."; box.style.display = "block"; return; }
    }
    box.style.display = "none";
  }
  document.addEventListener("DOMContentLoaded", function(){
    var nameFields = document.querySelectorAll("input[name=origin], input[name=destination]");
    var lngFields = document.querySelectorAll("input[name=origin_lng], input[name=destination_lng]");
    nameFields.forEach(function(nf){
      var lf = nf.name.indexOf("origin") === 0 ? document.querySelector("input[name=origin_lng]") : document.querySelector("input[name=destination_lng]");
      if (!lf) return;
      nf.addEventListener("blur", function(){ check(nf, lf); });
      lf.addEventListener("blur", function(){ check(nf, lf); });
    });
  });
})();
</script>
</body>
<script>
/* nxValidateShipForm */
(function(){
  "use strict";
  function init(){
    var form = document.querySelector("form[enctype]");
    if (!form) {
      // fallback: first form on the page
      form = document.querySelector("form");
    }
    if (!form) return;

    // Grab the western-hemisphere rule data we already show
    var WEST = ["los angeles","san francisco","new york","seattle","chicago","houston","miami","boston","washington","las vegas","portland","vancouver","toronto","montreal","mexico city","bogota","lima","santiago","sao paulo","buenos aires","rio de janeiro","lisbon","dublin"];
    var EAST = ["london","paris","madrid","rome","berlin","lagos","abuja","cairo","dubai","mumbai","delhi","karachi","shanghai","beijing","tokyo","seoul","singapore","sydney","jakarta","bangkok","hanoi","manila","hong kong"];

    function field(name) { return form.querySelector("[name=\"" + name + "\"]"); }
    function val(name) { var el = field(name); return el ? String(el.value || "").trim() : ""; }
    function num(name) { var v = val(name); return v === "" ? NaN : parseFloat(v); }
    function show(msg, kind) { try { (kind === "warn" ? NXP.warn : NXP.err)(msg); } catch(e){ alert(msg); } }

    form.addEventListener("submit", function(e){
      var tracking = val("tracking_number");
      var name     = val("customer_name");
      var origin   = val("origin");
      var dest     = val("destination");
      var oLat = num("origin_lat"), oLng = num("origin_lng");
      var dLat = num("destination_lat"), dLng = num("destination_lng");
      var speed = num("speed");
      var email = val("customer_email");

      if (tracking === "") { e.preventDefault(); return show("Tracking number is required."); }
      if (name === "")     { e.preventDefault(); return show("Customer name is required."); }
      if (origin === "")   { e.preventDefault(); return show("Origin is required."); }
      if (dest === "")     { e.preventDefault(); return show("Destination is required."); }

      if (isNaN(oLat) || oLat < -90 || oLat > 90) { e.preventDefault(); return show("Origin latitude must be a number between -90 and 90."); }
      if (isNaN(oLng) || oLng < -180 || oLng > 180) { e.preventDefault(); return show("Origin longitude must be a number between -180 and 180."); }
      if (isNaN(dLat) || dLat < -90 || dLat > 90) { e.preventDefault(); return show("Destination latitude must be a number between -90 and 90."); }
      if (isNaN(dLng) || dLng < -180 || dLng > 180) { e.preventDefault(); return show("Destination longitude must be a number between -180 and 180."); }

      // Same point check
      if (Math.abs(oLat - dLat) < 0.0001 && Math.abs(oLng - dLng) < 0.0001) {
        e.preventDefault();
        return show("Origin and destination cannot be the same location.");
      }

      // Email format
      if (email !== "" && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        e.preventDefault();
        return show("Customer email does not look valid.");
      }

      // Speed not negative
      if (!isNaN(speed) && speed < 0) { e.preventDefault(); return show("Initial speed cannot be negative."); }

      // Longitude sign rule
      var lowerOrigin = origin.toLowerCase();
      var lowerDest = dest.toLowerCase();
      for (var i = 0; i < WEST.length; i++) {
        if (lowerOrigin.indexOf(WEST[i]) !== -1 && oLng > 0) { e.preventDefault(); return show(origin + " is in the western hemisphere — longitude must be negative (e.g. -118.2437)."); }
        if (lowerDest.indexOf(WEST[i]) !== -1 && dLng > 0) { e.preventDefault(); return show(dest + " is in the western hemisphere — longitude must be negative."); }
      }
      for (var j = 0; j < EAST.length; j++) {
        if (lowerOrigin.indexOf(EAST[j]) !== -1 && oLng < 0) { e.preventDefault(); return show(origin + " is in the eastern hemisphere — longitude must be positive."); }
        if (lowerDest.indexOf(EAST[j]) !== -1 && dLng < 0) { e.preventDefault(); return show(dest + " is in the eastern hemisphere — longitude must be positive."); }
      }

      // All checks passed — show saving message (will disappear when the page navigates)
      try { NXP.ok("Saving shipment…", "Please wait"); } catch(e){}
    });
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();
</script>

<script>
/* Copy customer details into the shipment form */
(function(){
  var sel = document.getElementById('nx-customer-select');
  var btn = document.getElementById('nx-copy-customer');
  if (!sel || !btn) return;
  btn.addEventListener('click', function(){
    var opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) { alert('Choose a saved customer first.'); return; }
    var n = document.getElementById('nx-customer-name');
    var e = document.getElementById('nx-customer-email');
    var p = document.getElementById('nx-customer-phone');
    if (n) n.value = opt.getAttribute('data-name') || '';
    if (e) e.value = opt.getAttribute('data-email') || '';
    if (p) p.value = opt.getAttribute('data-phone') || '';
  });
})();
</script>

</body>
</html>
