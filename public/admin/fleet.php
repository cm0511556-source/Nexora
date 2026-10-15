<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once BASE_PATH . '/app/Database.php';
require_once BASE_PATH . '/app/Auth.php';
use VibrantHub\Auth; use VibrantHub\Database;

Auth::requireAdmin();

$envFile = dirname(__DIR__, 2) . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) continue;
        [$n, $v] = explode('=', $line, 2);
        $_ENV[trim($n)] = trim(trim($v), "\"'");
    }
}
$mapStyle = nxMapStyleUrl();

$db = Database::connection();

// Fetch all active shipments (not delivered / cancelled)
$stmt = $db->query("
    SELECT s.id, s.tracking_number, s.customer_name, s.status,
           s.transport_type, s.vehicle_name, s.origin, s.destination,
           s.origin_lat, s.origin_lng, s.destination_lat, s.destination_lng,
           s.current_lat, s.current_lng, s.speed, s.distance_total, s.distance_travelled,
           s.estimated_delivery,
           COALESCE(cr.name, '') AS carrier_name,
           COALESCE(cr.logo_path, '') AS carrier_logo,
           (SELECT COUNT(*) FROM shipment_holds WHERE shipment_id = s.id AND status = 'active') AS active_holds
    FROM shipments s
    LEFT JOIN carriers cr ON cr.id = s.carrier_id
    WHERE LOWER(s.status) NOT IN ('delivered', 'cancelled')
    ORDER BY s.updated_at DESC
");
$shipments = $stmt->fetchAll();

function hh(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Fleet View — Nexora Admin</title>
<link href="<?= asset('vendor/maplibre/maplibre-gl.css') ?>" rel="stylesheet">
<script src="<?= asset('vendor/maplibre/maplibre-gl.js') ?>"></script>
<script src="<?= asset('js/nx-map.js') ?>"></script>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
<script src="<?= asset('js/nx-labels.js') ?>"></script>
<style>
  body { background: #f1f5f9; }
  .nx-fleet-wrap { max-width: 1500px; margin: 0 auto; padding: 1rem; }
  .nx-fleet-toolbar { display: flex; gap: .75rem; flex-wrap: wrap; align-items: center; background: #fff; padding: 1rem 1.25rem; border-radius: 14px; box-shadow: 0 8px 24px rgba(11,29,58,.08); margin-bottom: 1rem; }
  .nx-fleet-toolbar h1 { margin: 0; font-size: 1.25rem; color: #0b1d3a; }
  .nx-fleet-toolbar select, .nx-fleet-toolbar input[type=text] { padding: .55rem .85rem; border: 1px solid #cbd5e1; border-radius: 9px; font-family: inherit; font-size: .9rem; box-sizing: border-box; }
  .nx-fleet-toolbar input[type=text] { min-width: 220px; }
  .nx-fleet-stat { display: inline-flex; align-items: center; gap: .35rem; padding: .35rem .75rem; border-radius: 999px; font-size: .8rem; font-weight: 700; }
  .nx-fleet-stat.total { background: rgba(37,99,235,.12); color: #1e40af; }
  .nx-fleet-stat.moving { background: rgba(22,163,74,.12); color: #166534; }
  .nx-fleet-stat.held { background: rgba(220,38,38,.12); color: #991b1b; }
  #nx-fleet-map { height: 70vh; min-height: 420px; border-radius: 14px; overflow: hidden; box-shadow: 0 8px 24px rgba(11,29,58,.12); }
  @media (max-width: 700px) { #nx-fleet-map { height: 60vh; } }
  .nx-fleet-legend { position: absolute; bottom: 12px; left: 12px; z-index: 500; background: rgba(11,29,58,.92); color: #fff; padding: .6rem .85rem; border-radius: 10px; font-size: .72rem; line-height: 1.5; max-width: 220px; }
  .nx-fleet-legend b { color: #f0b429; font-size: .8rem; display: block; margin-bottom: .3rem; }
  .nx-veh-marker { display: inline-flex; flex-direction: column; align-items: center; cursor: pointer; }
  .nx-veh-circle { width: 42px; height: 42px; border-radius: 50%; background: rgba(11,29,58,.92); border: 2px solid #f0b429; display: flex; align-items: center; justify-content: center; box-shadow: 0 0 12px rgba(240,180,41,.8); }
  .nx-veh-circle.held { border-color: #dc2626; box-shadow: 0 0 14px rgba(220,38,38,.85); animation: nx-pulse 1.6s infinite; }
  .nx-veh-circle.delivered { border-color: #16a34a; box-shadow: 0 0 12px rgba(22,163,74,.8); }
  @keyframes nx-pulse { 0%,100% { box-shadow: 0 0 14px rgba(220,38,38,.85); } 50% { box-shadow: 0 0 24px rgba(220,38,38,.4); } }
  .nx-veh-emoji { font-size: 22px; line-height: 1; }
  .nx-veh-label { background: rgba(11,29,58,.92); color: #fff; font-size: .68rem; font-weight: 700; padding: 2px 6px; border-radius: 5px; margin-top: 2px; white-space: nowrap; }
  .nx-fleet-map-wrap { position: relative; }
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

<div class="nx-fleet-wrap">
  <div class="nx-fleet-toolbar">
    <h1>🛰 Fleet View</h1>
    <span class="nx-fleet-stat total"><?= count($shipments) ?> active</span>
    <span class="nx-fleet-stat moving" id="nx-fleet-moving">— moving</span>
    <span class="nx-fleet-stat held" id="nx-fleet-held">— flagged</span>
    <input type="text" id="nx-fleet-search" placeholder="Search tracking, customer, route…">
    <select id="nx-fleet-filter">
      <option value="">All statuses</option>
      <option value="in_transit">In transit</option>
      <option value="pending">Pending</option>
      <option value="delayed">Delayed</option>
      <option value="on_hold">On hold</option>
      <option value="seized">Seized</option>
    </select>
    <button type="button" id="nx-fleet-refresh" style="margin-left:auto;padding:.55rem 1rem;border:1px solid #cbd5e1;border-radius:9px;background:#fff;font-weight:600;cursor:pointer;font-family:inherit;">🔄 Refresh</button>
  </div>

  <div class="nx-fleet-map-wrap">
    <div id="nx-fleet-map"></div>
    <div class="nx-fleet-legend">
      <b>Shipment markers</b>
      <div><span style="color:#f0b429;">●</span> Moving (in transit)</div>
      <div><span style="color:#dc2626;">●</span> Flagged (hold / seized)</div>
      <div><span style="color:#16a34a;">●</span> Delivered</div>
      <div style="margin-top:.4rem;opacity:.75;">Tap a marker for details</div>
    </div>
  </div>
</div>

<script>
(function(){
'use strict';

const SHIPMENTS = <?= json_encode(array_map(function($s){
    $legEndpoints = [
        ['lat' => (float)$s['origin_lat'], 'lng' => (float)$s['origin_lng']],
        ['lat' => (float)$s['destination_lat'], 'lng' => (float)$s['destination_lng']],
    ];
    return [
        'id' => (int)$s['id'],
        'tracking' => $s['tracking_number'],
        'customer' => $s['customer_name'],
        'status' => $s['status'],
        'transport' => $s['transport_type'],
        'vehicle' => (string)($s['vehicle_name'] ?? ''),
        'origin' => $s['origin'],
        'destination' => $s['destination'],
        'origin_lat' => (float)$s['origin_lat'],
        'origin_lng' => (float)$s['origin_lng'],
        'dest_lat' => (float)$s['destination_lat'],
        'dest_lng' => (float)$s['destination_lng'],
        'current_lat' => (float)$s['current_lat'],
        'current_lng' => (float)$s['current_lng'],
        'speed' => (float)$s['speed'],
        'progress' => ((float)$s['distance_total'] > 0) ? round(((float)$s['distance_travelled'] / (float)$s['distance_total']) * 100, 1) : 0,
        'eta' => (string)($s['estimated_delivery'] ?? ''),
        'carrier' => (string)($s['carrier_name'] ?? ''),
        'carrier_logo' => (string)($s['carrier_logo'] ?? ''),
        'holds' => (int)$s['active_holds'],
    ];
}, $shipments)) ?>;

const map = nxCreateMap('nx-fleet-map', <?= json_encode($mapStyle, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>, {
  center: [10, 30], zoom: 2,
  attributionControl: { compact: true }
});
map.addControl(new maplibregl.NavigationControl({ visualizePitch: true }), 'top-left');
map.addControl(new maplibregl.FullscreenControl(), 'top-right');

function esc(s){ var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

// Country flag from a place name
function flagFor(place) {
  if (window.NX && NX.flag) return NX.flag(place);
  return '📍';
}

let markers = [];
let routeLines = {};

function statusMeta(s) {
  if (s.holds > 0) return { tone: '#dc2626', cls: 'held' };
  const st = String(s.status || '').toLowerCase();
  if (st === 'seized' || st === 'on_hold' || st === 'held' || st === 'delayed') return { tone: '#dc2626', cls: 'held' };
  if (st === 'delivered') return { tone: '#16a34a', cls: 'delivered' };
  return { tone: '#f0b429', cls: '' };
}

function vehEmoji(s) {
  if (window.NX && NX.transport) return NX.transport(s.transport, s.vehicle).emoji;
  return '🚚';
}

function renderMarkers(list) {
  markers.forEach(m => m.remove());
  markers = [];

  list.forEach(s => {
    const meta = statusMeta(s);
    const emoji = vehEmoji(s);

    const el = document.createElement('div');
    el.className = 'nx-veh-marker';
    el.innerHTML =
      '<div class="nx-veh-circle ' + meta.cls + '" style="border-color:' + meta.tone + ';">' +
        '<div class="nx-veh-emoji">' + emoji + '</div>' +
      '</div>' +
      '<div class="nx-veh-label">' + esc(s.tracking) + '</div>';

    const m = new maplibregl.Marker({ element: el, anchor: 'center' })
      .setLngLat([s.current_lng, s.current_lat])
      .addTo(map);

    const flag_o = flagFor(s.origin);
    const flag_d = flagFor(s.destination);
    m.setPopup(new maplibregl.Popup({ offset: 30, closeButton: true, maxWidth: 300 }).setHTML(
      '<div style="font-family:Plus Jakarta Sans,system-ui,sans-serif;font-size:.88rem;line-height:1.55;min-width:220px;">' +
        '<div style="font-size:.7rem;letter-spacing:.08em;text-transform:uppercase;color:#64748b;">Shipment</div>' +
        '<div style="font-weight:800;color:#0b1d3a;font-size:1.05rem;">' + esc(s.tracking) + '</div>' +
        '<hr style="border:0;border-top:1px solid #e2e8f0;margin:8px 0;">' +
        '<div><b>Customer:</b> ' + esc(s.customer) + '</div>' +
        '<div><b>Vehicle:</b> ' + esc(s.vehicle || s.transport) + '</div>' +
        '<div><b>Status:</b> ' + esc((s.status || '').replace(/_/g,' ')) + '</div>' +
        '<div><b>Progress:</b> ' + s.progress + '% · ' + Math.round(s.speed) + ' km/h</div>' +
        '<div><b>Route:</b> ' + flag_o + ' ' + esc(s.origin) + ' → ' + flag_d + ' ' + esc(s.destination) + '</div>' +
        (s.carrier ? '<div><b>Carrier:</b> ' + esc(s.carrier) + '</div>' : '') +
        '<div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;">' +
          '<a href="/admin/shipment-control.php?id=' + s.id + '" style="padding:.35rem .7rem;border-radius:7px;background:#0b1d3a;color:#fff;text-decoration:none;font-size:.82rem;font-weight:600;">Open</a>' +
          '<a href="/admin/shipment-route.php?id=' + s.id + '" style="padding:.35rem .7rem;border-radius:7px;border:1px solid #cbd5e1;color:#0b1d3a;text-decoration:none;font-size:.82rem;font-weight:600;">Route</a>' +
          '<a href="/track.php?id=' + encodeURIComponent(s.tracking) + '" target="_blank" rel="noopener" style="padding:.35rem .7rem;border-radius:7px;border:1px solid #cbd5e1;color:#0b1d3a;text-decoration:none;font-size:.82rem;font-weight:600;">Public view</a>' +
        '</div>' +
      '</div>'
    ));

    // Route line from origin → current → destination (OSRM geometry if available)
    const lineId = 'nx-fleet-route-' + s.id;
    if (!routeLines[lineId]) {
      const routeCoords = (s.route_points && s.route_points.length > 1)
        ? s.route_points.map(p => [parseFloat(p.longitude), parseFloat(p.latitude)])
        : [
            [s.origin_lng, s.origin_lat],
            [s.current_lng, s.current_lat],
            [s.dest_lng, s.dest_lat],
          ];
      map.addSource(lineId, {
        type: 'geojson',
        data: { type: 'Feature', geometry: { type: 'LineString', coordinates: routeCoords } }
      });
      // Glow underlay
      map.addLayer({
        id: lineId + '-glow', type: 'line', source: lineId,
        layout: { 'line-cap': 'round', 'line-join': 'round' },
        paint: { 'line-color': meta.tone, 'line-width': 8, 'line-opacity': 0.35, 'line-blur': 4 }
      });
      // Bright solid line
      map.addLayer({
        id: lineId, type: 'line', source: lineId,
        layout: { 'line-cap': 'round', 'line-join': 'round' },
        paint: { 'line-color': meta.tone, 'line-width': 3, 'line-opacity': 0.95 }
      });
      // Origin marker
      const oEl = document.createElement('div');
      oEl.style.cssText = 'font-size:20px;filter:drop-shadow(0 0 5px #16a34a);';
      oEl.textContent = '🟢';
      new maplibregl.Marker({ element: oEl, anchor: 'center' })
        .setLngLat([s.origin_lng, s.origin_lat]).addTo(map);
      // Destination marker
      const dEl = document.createElement('div');
      dEl.style.cssText = 'font-size:20px;filter:drop-shadow(0 0 5px #dc2626);';
      dEl.textContent = '🎯';
      new maplibregl.Marker({ element: dEl, anchor: 'center' })
        .setLngLat([s.dest_lng, s.dest_lat]).addTo(map);
      routeLines[lineId] = true;
    }

    markers.push(m);
  });

  // Update header stats
  const moving = list.filter(x => String(x.status||'').toLowerCase() === 'in_transit').length;
  const held = list.filter(x => x.holds > 0 || ['on_hold','held','seized','delayed'].includes(String(x.status||'').toLowerCase())).length;
  document.getElementById('nx-fleet-moving').textContent = moving + ' moving';
  document.getElementById('nx-fleet-held').textContent = held + ' flagged';
}

function fitAll(list) {
  if (!list.length) return;
  const b = new maplibregl.LngLatBounds();
  list.forEach(s => {
    b.extend([s.origin_lng, s.origin_lat]);
    b.extend([s.dest_lng, s.dest_lat]);
    b.extend([s.current_lng, s.current_lat]);
  });
  map.fitBounds(b, { padding: 60, maxZoom: 6, duration: 0 });
}

function applyFilters() {
  const q = document.getElementById('nx-fleet-search').value.toLowerCase().trim();
  const st = document.getElementById('nx-fleet-filter').value.toLowerCase();
  const filtered = SHIPMENTS.filter(s => {
    if (st && String(s.status||'').toLowerCase() !== st) return false;
    if (!q) return true;
    return (s.tracking + ' ' + s.customer + ' ' + s.origin + ' ' + s.destination + ' ' + s.carrier).toLowerCase().includes(q);
  });
  // Remove old route lines
  Object.keys(routeLines).forEach(id => {
    try { if (map.getLayer(id)) map.removeLayer(id); } catch(e){}
    try { if (map.getSource(id)) map.removeSource(id); } catch(e){}
  });
  routeLines = {};
  renderMarkers(filtered);
}

document.getElementById('nx-fleet-search').addEventListener('input', applyFilters);
document.getElementById('nx-fleet-filter').addEventListener('change', applyFilters);
document.getElementById('nx-fleet-refresh').addEventListener('click', () => location.reload());

map.on('load', () => {
  renderMarkers(SHIPMENTS);
  fitAll(SHIPMENTS);
});
})();
</script>
</body>
</html>
