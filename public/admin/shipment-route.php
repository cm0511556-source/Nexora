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
$shipmentId = (int)($_GET['id'] ?? 0);
if ($shipmentId < 1) { http_response_code(400); exit('Invalid shipment ID.'); }

$s = $db->prepare('SELECT * FROM shipments WHERE id = :id LIMIT 1');
$s->execute([':id' => $shipmentId]);
$shipment = $s->fetch();
if (!$shipment) { http_response_code(404); exit('Shipment not found.'); }

$w = $db->prepare('SELECT * FROM shipment_waypoints WHERE shipment_id = :sid ORDER BY sequence ASC');
$w->execute([':sid' => $shipmentId]);
$waypoints = $w->fetchAll();

$r = $db->prepare('SELECT * FROM shipment_route_points WHERE shipment_id = :sid ORDER BY sequence ASC');
$r->execute([':sid' => $shipmentId]);
$routePoints = $r->fetchAll();

$csrf = csrfToken();
function hh(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Route Editor — <?= hh($shipment['tracking_number']) ?></title>
<link href="<?= asset('vendor/maplibre/maplibre-gl.css') ?>" rel="stylesheet">
<script src="<?= asset('vendor/maplibre/maplibre-gl.js') ?>"></script>
<script src="<?= asset('js/nx-map.js') ?>"></script>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
<script src="<?= asset('js/nx-labels.js') ?>"></script>
<style>
  body { background: #f1f5f9; }
  .nx-rt-wrap { max-width: 1400px; margin: 0 auto; padding: 1rem; }
  .nx-rt-grid { display: grid; grid-template-columns: 1fr 420px; gap: 1rem; margin-top: 1rem; }
  @media (max-width: 900px) { .nx-rt-grid { grid-template-columns: 1fr; } }
  #nx-route-map { height: 620px; border-radius: 14px; overflow: hidden; box-shadow: 0 8px 24px rgba(11,29,58,.15); }
  @media (max-width: 900px) { #nx-route-map { height: 420px; } }
  .nx-rt-panel { background: #fff; border-radius: 14px; padding: 1.25rem; box-shadow: 0 8px 24px rgba(11,29,58,.08); }
  .nx-wp { border: 1px solid #e5e7eb; border-radius: 12px; padding: .85rem; margin-bottom: .65rem; background: #fff; position: relative; transition: box-shadow .15s; }
  .nx-wp:hover { box-shadow: 0 4px 12px rgba(11,29,58,.08); }
  .nx-wp.selected { border-color: #f0b429; box-shadow: 0 0 0 3px rgba(240,180,41,.15); }
  .nx-wp-head { display: flex; align-items: center; gap: .5rem; margin-bottom: .5rem; }
  .nx-wp-icon { font-size: 1.25rem; }
  .nx-wp-name { font-weight: 700; color: #0b1d3a; flex: 1; }
  .nx-wp-actions { display: flex; gap: .25rem; }
  .nx-wp-actions button { background: none; border: 0; cursor: pointer; font-size: 1rem; padding: 2px 6px; border-radius: 6px; color: #64748b; }
  .nx-wp-actions button:hover { background: #f1f5f9; color: #0b1d3a; }
  .nx-wp-row { font-size: .82rem; color: #475569; margin-top: .25rem; }
  .nx-wp-row b { color: #0b1d3a; }
  .nx-leg-badge { display: inline-flex; align-items: center; gap: .35rem; padding: .2rem .6rem; border-radius: 999px; font-size: .72rem; font-weight: 700; background: #f1f5f9; color: #334155; margin-right: .35rem; }
  .nx-rt-btn { display: inline-flex; align-items: center; gap: .35rem; padding: .55rem .95rem; border-radius: 9px; border: 1px solid #cbd5e1; background: #fff; color: #0b1d3a; font-family: inherit; font-weight: 600; font-size: .85rem; cursor: pointer; }
  .nx-rt-btn:hover { background: #f1f5f9; }
  .nx-rt-btn-primary { background: #0b1d3a; color: #fff; border-color: #0b1d3a; }
  .nx-rt-btn-primary:hover { background: #132a52; }
  .nx-rt-btn-danger { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
  .nx-rt-btn-danger:hover { background: #fee2e2; }
  .nx-rt-actions { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: .75rem; }
  .nx-rt-toast { position: fixed; top: 1rem; right: 1rem; z-index: 99999; padding: .85rem 1.2rem; border-radius: 10px; font-weight: 600; font-size: .9rem; box-shadow: 0 8px 20px rgba(0,0,0,.15); display: none; max-width: 340px; }
  .nx-rt-toast.ok { background: #166534; color: #fff; }
  .nx-rt-toast.err { background: #991b1b; color: #fff; }
  .nx-rt-toast.warn { background: #f59e0b; color: #0b1d3a; }
  .nx-legend-swatch { display: inline-block; width: 14px; height: 14px; border-radius: 3px; margin-right: 4px; vertical-align: middle; }
  .nx-form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: .6rem; margin-top: .5rem; }
  .nx-form-row label { display: block; font-size: .72rem; color: #64748b; text-transform: uppercase; font-weight: 700; letter-spacing: .03em; margin-bottom: .2rem; }
  .nx-form-row input, .nx-form-row select, .nx-form-row textarea { width: 100%; padding: .5rem .6rem; border: 1px solid #cbd5e1; border-radius: 8px; font-family: inherit; font-size: .88rem; box-sizing: border-box; }
  .nx-form-row textarea { min-height: 50px; resize: vertical; }
  #nx-add-form { border-top: 1px solid #e2e8f0; padding-top: 1rem; margin-top: 1rem; }
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

<div class="nx-rt-wrap">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;margin-top:1rem;">
    <div>
      <h1 style="margin:0;color:#0b1d3a;">Route editor</h1>
      <p style="margin:.25rem 0 0;color:#475569;">
        <strong><?= hh($shipment['tracking_number']) ?></strong> · <?= hh($shipment['origin']) ?> → <?= hh($shipment['destination']) ?>
      </p>
    </div>
    <a href="/admin/shipment-control.php?id=<?= (int)$shipmentId ?>" class="nx-rt-btn">← Back to shipment</a>
  </div>

  <div class="nx-rt-grid">
    <div>
      <div id="nx-route-map"></div>
    </div>

    <div class="nx-rt-panel">
      <h2 style="margin:0 0 .5rem;font-size:1.05rem;color:#0b1d3a;">Route stops (<?= count($waypoints) ?>)</h2>
      <p style="margin:0 0 1rem;font-size:.85rem;color:#64748b;">Each stop is a waypoint. The <em>leg</em> below it is how the shipment travels to reach that stop.</p>
      <div id="nx-wp-list">
        <?php if (!$waypoints): ?>
          <p style="color:#94a3b8;font-size:.9rem;text-align:center;padding:1rem 0;">No stops yet. Add the first one below.</p>
        <?php else: ?>
          <?php foreach ($waypoints as $i => $wp): ?>
            <div class="nx-wp" data-id="<?= (int)$wp['id'] ?>" data-lat="<?= hh((string)$wp['latitude']) ?>" data-lng="<?= hh((string)$wp['longitude']) ?>">
              <div class="nx-wp-head">
                <span class="nx-wp-icon" data-type="<?= hh($wp['waypoint_type']) ?>"></span>
                <span class="nx-wp-name"><?= hh($wp['name']) ?></span>
                <span class="nx-wp-actions">
                  <button type="button" class="nx-wp-edit" title="Edit">✎</button>
                  <button type="button" class="nx-wp-del" title="Delete">🗑</button>
                </span>
              </div>
              <div class="nx-wp-row"><b>Type:</b> <?= hh($wp['waypoint_type']) ?></div>
              <div class="nx-wp-row"><b>Leg to next:</b> <span class="nx-leg-badge" data-leg="<?= hh($wp['leg_type'] ?: 'truck') ?>"></span></div>
              <div class="nx-wp-row"><b>Coords:</b> <?= hh(number_format((float)$wp['latitude'], 4)) ?>, <?= hh(number_format((float)$wp['longitude'], 4)) ?></div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div class="nx-rt-actions">
        <button type="button" class="nx-rt-btn nx-rt-btn-primary" id="nx-show-add">+ Add a stop</button>
        <button type="button" class="nx-rt-btn" id="nx-rebuild">🛠 Rebuild route line</button>
      </div>

      <form id="nx-add-form" style="display:none;">
        <h3 style="margin:.5rem 0;font-size:.95rem;color:#0b1d3a;">New stop</h3>
        <div class="nx-form-row">
          <label>Name <input type="text" name="name" placeholder="e.g. Dubai Int'l Airport" required></label>
          <label>Type
            <select name="waypoint_type">
              <option value="origin">Origin</option>
              <option value="airport">Airport</option>
              <option value="port">Port</option>
              <option value="rail_hub">Rail hub</option>
              <option value="warehouse">Warehouse</option>
              <option value="office">Office</option>
              <option value="depot">Depot</option>
              <option value="home">Home / customer</option>
              <option value="checkpoint">Checkpoint</option>
              <option value="waypoint" selected>Waypoint</option>
              <option value="destination">Destination</option>
            </select>
          </label>
        </div>
        <div class="nx-form-row">
          <label>Latitude <input type="number" step="any" name="lat" placeholder="25.2532" required></label>
          <label>Longitude <input type="number" step="any" name="lng" placeholder="55.3657" required></label>
        </div>
        <div class="nx-form-row">
          <label>Leg type to this stop
            <select name="leg_type">
              <option value="air">✈️ Air</option>
              <option value="sea">🚢 Sea</option>
              <option value="rail">🚆 Rail</option>
              <option value="truck" selected>🚚 Truck</option>
              <option value="van">🚐 Van</option>
              <option value="bike">🏍️ Bike</option>
              <option value="ped">🚶 Pedestrian</option>
            </select>
          </label>
          <label>Address (optional) <input type="text" name="address" placeholder="Street or area"></label>
        </div>
        <div class="nx-form-row">
          <label>Public label (optional) <input type="text" name="public_label" placeholder="Shown to customer"></label>
          <label>Public note (optional) <input type="text" name="public_note" placeholder="e.g. Cleared customs"></label>
        </div>
        <div class="nx-rt-actions">
          <button type="button" class="nx-rt-btn nx-rt-btn-primary" id="nx-save-add">Save stop</button>
          <button type="button" class="nx-rt-btn" id="nx-save-add-another" style="background:#f0b429;color:#0b1d3a;border-color:#f0b429;">＋ Save &amp; add another</button>
          <button type="button" class="nx-rt-btn" id="nx-cancel-add">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div id="nx-rt-toast" class="nx-rt-toast"></div>

<div id="nx-route-errbox" style="display:none;position:fixed;bottom:0;left:0;right:0;background:#7f1d1d;color:#fff;padding:10px 14px;font-size:.8rem;z-index:99999;max-height:150px;overflow:auto;font-family:monospace;"></div>
<script>
window.addEventListener("error", function(e){
  var b = document.getElementById("nx-route-errbox");
  if (!b) return;
  b.style.display = "block";
  b.textContent = "JS error: " + (e.message || "") + " @ " + (e.filename || "") + ":" + (e.lineno || "");
});
window.addEventListener("unhandledrejection", function(e){
  var b = document.getElementById("nx-route-errbox");
  if (!b) return;
  b.style.display = "block";
  b.textContent = "Promise error: " + ((e.reason && e.reason.message) ? e.reason.message : e.reason);
});
</script>

<script>
(function(){
'use strict';

const SHIPMENT_ID = <?= (int)$shipmentId ?>;
const CSRF = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const WAYPOINTS = <?= json_encode(array_map(fn($w) => [
    'id' => (int)$w['id'],
    'sequence' => (int)$w['sequence'],
    'name' => $w['name'],
    'latitude' => (float)$w['latitude'],
    'longitude' => (float)$w['longitude'],
    'waypoint_type' => $w['waypoint_type'],
    'leg_type' => $w['leg_type'] ?: 'truck',
], $waypoints)) ?>;
const ROUTE_POINTS = <?= json_encode(array_map(fn($p) => [
    'latitude' => (float)$p['latitude'],
    'longitude' => (float)$p['longitude'],
    'segment_type' => $p['segment_type'],
], $routePoints)) ?>;

const WP_ICONS = {
  origin: '🟢', destination: '🎯', airport: '✈️', port: '⚓',
  rail_hub: '🚉', warehouse: '🏬', office: '🏢', depot: '🏭',
  home: '🏠', checkpoint: '🛡️', waypoint: '📍'
};
const LEG_ICONS = { air: '✈️ Air', sea: '🚢 Sea', rail: '🚆 Rail', truck: '🚚 Truck', van: '🚐 Van', bike: '🏍️ Bike', ped: '🚶 Pedestrian' };
const LEG_COLORS = { air: '#f0b429', sea: '#2563eb', rail: '#16a34a', truck: '#64748b', van: '#7c3aed', bike: '#ec4899', ped: '#0b1d3a' };

// Initialize icons on waypoint cards
document.querySelectorAll('.nx-wp-icon').forEach(el => {
  el.textContent = WP_ICONS[el.dataset.type] || '📍';
});
document.querySelectorAll('.nx-leg-badge').forEach(el => {
  el.textContent = LEG_ICONS[el.dataset.leg] || el.dataset.leg;
});

const map = nxCreateMap('nx-route-map', <?= json_encode($mapStyle, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>, {
  center: [0, 20],
  zoom: 2,
  attributionControl: { compact: true }
});
map.addControl(new maplibregl.NavigationControl({ visualizePitch: true }), 'top-left');
map.addControl(new maplibregl.ScaleControl({ maxWidth: 100 }), 'bottom-left');

let wpMarkers = [];
let addMode = false;

function esc(s){ const d=document.createElement('div'); d.textContent=s==null?'':String(s); return d.innerHTML; }

function renderRoute() {
  // Remove old line layers
  if (map.getLayer('nx-route-line')) map.removeLayer('nx-route-line');
  if (map.getSource('nx-route')) map.removeSource('nx-route');
  if (!ROUTE_POINTS.length) return;

  // Group by consecutive segment_type
  const features = [];
  let bucket = [ROUTE_POINTS[0]];
  for (let i = 1; i < ROUTE_POINTS.length; i++) {
    if (ROUTE_POINTS[i].segment_type === ROUTE_POINTS[i-1].segment_type) {
      bucket.push(ROUTE_POINTS[i]);
    } else {
      features.push({
        type: 'Feature',
        properties: { color: LEG_COLORS[bucket[0].segment_type] || '#94a3b8' },
        geometry: { type: 'LineString', coordinates: bucket.map(p => [p.longitude, p.latitude]) }
      });
      bucket = [ROUTE_POINTS[i-1], ROUTE_POINTS[i]];
    }
  }
  if (bucket.length > 1) {
    features.push({
      type: 'Feature',
      properties: { color: LEG_COLORS[bucket[0].segment_type] || '#94a3b8' },
      geometry: { type: 'LineString', coordinates: bucket.map(p => [p.longitude, p.latitude]) }
    });
  }

map.addSource('nx-route', { type: 'geojson', data: { type: 'FeatureCollection', features } });
    // Glow underlay
    map.addLayer({
      id: 'nx-route-glow', type: 'line', source: 'nx-route',
      layout: { 'line-cap': 'round', 'line-join': 'round' },
      paint: { 'line-color': ['get','color'], 'line-width': 8, 'line-opacity': 0.35, 'line-blur': 4 }
    });
    // Bright solid line
    map.addLayer({
      id: 'nx-route-line', type: 'line', source: 'nx-route',
      layout: { 'line-cap': 'round', 'line-join': 'round' },
      paint: { 'line-color': ['get','color'], 'line-width': 3, 'line-opacity': 0.95 }
    });
}

function renderWaypoints() {
  wpMarkers.forEach(m => m.remove());
  wpMarkers = [];
  WAYPOINTS.forEach((w, i) => {
    const el = document.createElement('div');
    el.style.cssText = 'display:flex;flex-direction:column;align-items:center;cursor:pointer;';
    el.innerHTML = '<div style="font-size:22px;line-height:1;text-shadow:0 0 8px rgba(0,0,0,.7);">' + (WP_ICONS[w.waypoint_type] || '📍') + '</div>' +
      '<div style="background:rgba(11,29,58,.92);color:#fff;font-size:.7rem;font-weight:700;padding:2px 7px;border-radius:6px;white-space:nowrap;margin-top:2px;border:1px solid rgba(240,180,41,.4);">' + esc(w.name) + '</div>';
    const m = new maplibregl.Marker({ element: el, anchor:'bottom' }).setLngLat([w.longitude, w.latitude]).addTo(map);
    m.setPopup(new maplibregl.Popup({ offset: 25 }).setHTML(
      '<div style="font-family:Plus Jakarta Sans,system-ui,sans-serif;font-size:.85rem;line-height:1.5;min-width:180px;">' +
        '<b style="color:#0b1d3a;">' + esc(w.name) + '</b><br>' +
        '<span style="color:#64748b;">Type: ' + esc(w.waypoint_type) + '</span><br>' +
        '<span style="color:#64748b;">Leg to next: ' + esc(LEG_ICONS[w.leg_type] || w.leg_type) + '</span>' +
      '</div>'
    ));
    wpMarkers.push(m);
  });
}

function fitToWaypoints() {
  if (!WAYPOINTS.length) return;
  if (WAYPOINTS.length === 1) {
    map.easeTo({ center: [WAYPOINTS[0].longitude, WAYPOINTS[0].latitude], zoom: 6, duration: 0 });
    return;
  }
  const b = new maplibregl.LngLatBounds();
  WAYPOINTS.forEach(w => b.extend([w.longitude, w.latitude]));
  map.fitBounds(b, { padding: 80, maxZoom: 8, duration: 0 });
}

function toast(msg, kind) {
  const t = document.getElementById('nx-rt-toast');
  t.textContent = msg;
  t.className = 'nx-rt-toast ' + (kind || 'ok');
  t.style.display = 'block';
  clearTimeout(window.__nxToastT);
  window.__nxToastT = setTimeout(() => { t.style.display = 'none'; }, 3200);
}

async function api(payload) {
  const body = new URLSearchParams();
  body.set('csrf_token', CSRF);
  body.set('shipment_id', SHIPMENT_ID);
  Object.entries(payload).forEach(([k,v]) => body.set(k, v == null ? '' : String(v)));
  const r = await fetch('/admin/route-action.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
    body: body.toString()
  });
  const j = await r.json();
  if (!r.ok || !j.success) throw new Error(j.message || 'Request failed');
  return j;
}

// ============ ADD ============
const addForm = document.getElementById('nx-add-form');
document.getElementById('nx-show-add').addEventListener('click', () => {
  addMode = !addMode;
  addForm.style.display = addMode ? 'block' : 'none';
  if (addMode) toast('Tip: click anywhere on the map to fill lat/lng.', 'warn');
});
document.getElementById('nx-cancel-add').addEventListener('click', () => {
  addMode = false;
  addForm.style.display = 'none';
});

document.getElementById('nx-save-add-another').addEventListener('click', async () => {
  const fd = new FormData(addForm);
  try {
    const res = await api({
      action: 'add',
      name: fd.get('name'),
      lat: fd.get('lat'),
      lng: fd.get('lng'),
      waypoint_type: fd.get('waypoint_type'),
      leg_type: fd.get('leg_type'),
      address: fd.get('address'),
      public_label: fd.get('public_label'),
      public_note: fd.get('public_note'),
    });
    toast(res.message || 'Saved. Add the next one.', 'ok');
    addForm.querySelectorAll('input[type=text], input[type=number], textarea').forEach(el => el.value = '');
    var nameField = addForm.querySelector('input[name="name"]');
    if (nameField) nameField.focus();
    addForm.style.display = 'block';
  } catch (e) { toast(e.message, 'err'); }
});
document.getElementById('nx-save-add').addEventListener('click', async () => {
  const fd = new FormData(addForm);
  try {
    const res = await api({
      action: 'add',
      name: fd.get('name'),
      lat: fd.get('lat'),
      lng: fd.get('lng'),
      waypoint_type: fd.get('waypoint_type'),
      leg_type: fd.get('leg_type'),
      address: fd.get('address'),
      public_label: fd.get('public_label'),
      public_note: fd.get('public_note'),
    });
    toast(res.message || 'Saved.');
    setTimeout(() => location.reload(), 800);
  } catch (e) { toast(e.message, 'err'); }
});

// ============ EDIT (via prompts to keep it light) ============
document.querySelectorAll('.nx-wp-edit').forEach(btn => {
  btn.addEventListener('click', async (e) => {
    const card = e.target.closest('.nx-wp');
    const id = card.dataset.id;
    const w = WAYPOINTS.find(x => x.id == id);
    if (!w) return;
    const name = prompt('Name:', w.name); if (name === null) return;
    const type = prompt('Type (origin/airport/port/rail_hub/warehouse/office/depot/home/checkpoint/waypoint/destination):', w.waypoint_type); if (type === null) return;
    const lat = prompt('Latitude:', w.latitude); if (lat === null) return;
    const lng = prompt('Longitude:', w.longitude); if (lng === null) return;
    const leg = prompt('Leg type to this stop (air/sea/rail/truck/van/bike/ped):', w.leg_type); if (leg === null) return;
    try {
      const res = await api({ action: 'update', id, name, waypoint_type: type, lat, lng, leg_type: leg });
      toast(res.message || 'Updated.');
      setTimeout(() => location.reload(), 700);
    } catch (err) { toast(err.message, 'err'); }
  });
});

document.querySelectorAll('.nx-wp-del').forEach(btn => {
  btn.addEventListener('click', async (e) => {
    const card = e.target.closest('.nx-wp');
    if (!confirm('Delete this stop?')) return;
    try {
      const res = await api({ action: 'delete', id: card.dataset.id });
      toast(res.message || 'Deleted.');
      setTimeout(() => location.reload(), 700);
    } catch (err) { toast(err.message, 'err'); }
  });
});

// ============ REBUILD ============
document.getElementById('nx-rebuild').addEventListener('click', async () => {
  if (!confirm('Rebuild the route line from the stops? This replaces the current route geometry.')) return;
  try {
    const res = await api({ action: 'rebuild' });
    toast(res.message || 'Route rebuilt.');
    setTimeout(() => location.reload(), 800);
  } catch (e) { toast(e.message, 'err'); }
});

// ============ MAP CLICK TO FILL LAT/LNG ============
map.on('click', (e) => {
  if (!addMode) return;
  const lat = e.lngLat.lat.toFixed(6);
  const lng = e.lngLat.lng.toFixed(6);
  const fLat = addForm.querySelector('input[name="lat"]');
  const fLng = addForm.querySelector('input[name="lng"]');
  if (fLat && fLng) {
    fLat.value = lat;
    fLng.value = lng;
    toast('Coordinates captured: ' + lat + ', ' + lng, 'ok');
  }
});

map.on('load', () => {
  renderRoute();
  renderWaypoints();
  fitToWaypoints();
});
})();
</script>

<script>
/* nxValidateRoute */
(function(){
  "use strict";
  function init(){
    var addForm = document.getElementById("nx-add-form");
    if (!addForm) return;
    var saveBtn = document.getElementById("nx-save-add");
    var saveAnother = document.getElementById("nx-save-add-another");

    function fieldVal(name) {
      var el = addForm.querySelector("[name=\"" + name + "\"]");
      return el ? String(el.value || "").trim() : "";
    }

    function validate() {
      var name = fieldVal("name");
      var lat  = parseFloat(fieldVal("lat"));
      var lng  = parseFloat(fieldVal("lng"));
      var type = fieldVal("waypoint_type");
      var leg  = fieldVal("leg_type");

      if (name === "") { NXP.err("Give this stop a name (e.g. \"Dubai Int\u2019l Airport\")."); return false; }
      if (isNaN(lat) || lat < -90 || lat > 90) { NXP.err("Latitude must be a number between -90 and 90. Tap on the map to fill it automatically."); return false; }
      if (isNaN(lng) || lng < -180 || lng > 180) { NXP.err("Longitude must be a number between -180 and 180."); return false; }
      var validTypes = ["origin","airport","port","rail_hub","warehouse","office","depot","home","checkpoint","waypoint","destination"];
      if (validTypes.indexOf(type) === -1) { NXP.err("Pick a valid stop type."); return false; }
      var validLegs = ["air","sea","rail","truck","van","bike","ped"];
      if (validLegs.indexOf(leg) === -1) { NXP.err("Pick a valid transport mode."); return false; }
      return true;
    }

    if (saveBtn) {
      saveBtn.addEventListener("click", function(e){
        if (!validate()) { e.stopImmediatePropagation(); e.preventDefault(); }
      }, true);
    }
    if (saveAnother) {
      saveAnother.addEventListener("click", function(e){
        if (!validate()) { e.stopImmediatePropagation(); e.preventDefault(); }
      }, true);
    }
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();
</script>

</body>
</html>
