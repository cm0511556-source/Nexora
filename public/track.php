<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$appName = (string) env('APP_NAME', 'Nexora Logistics');
$mapStyle = nxMapStyleUrl();
$tracking = nxTrackingFromRequest();
$shipment = null;
$tracker = null;
$carrier = null;

try {
    $pdo = nxPdo();
    if ($tracking !== '') {
        $stmt = $pdo->prepare('SELECT * FROM shipments WHERE tracking_number = :t LIMIT 1');
        $stmt->execute([':t' => $tracking]);
        $shipment = $stmt->fetch() ?: null;
    }
} catch (Throwable $e) {
    $shipment = null;
}

$hasContact = $shipment
    && (trim((string) $shipment['customer_email']) !== '' || trim((string) $shipment['customer_phone']) !== '');
$isAdminPreview = nxIsAdminSession();

// Customer must confirm details before seeing live tracking.
// A signed-in admin may preview the page ("View as Customer") without the gate.
if ($tracking !== '' && $shipment && $hasContact && !$isAdminPreview) {
    $tracker = nxTrackerVerified($tracking);
    if (!$tracker || (int) ($tracker['verified'] ?? 0) !== 1) {
        header('Location: /pre-track.php?id=' . rawurlencode($tracking));
        exit;
    }
}

// Shipment with no contact on file — allowed, flagged Unverified.
$isUnverified = ($shipment && !$isAdminPreview && (!$hasContact || ($tracker && (int) ($tracker['verified'] ?? 0) === 0))) ? true : false;

// Assigned carrier (rendered "Handled by ..." for the customer).
if ($shipment && !empty($shipment['carrier_id'])) {
    try {
        $cc = $pdo->prepare('SELECT id,name,code,logo_path,logo_url,contact_email,contact_phone,tracking_url FROM carriers WHERE id = :id AND is_active = 1 LIMIT 1');
        $cc->execute([':id' => (int) $shipment['carrier_id']]);
        $carrier = $cc->fetch() ?: null;
        if ($carrier && !empty($carrier['logo_path'])) {
            $carrier['logo_public'] = asset('carriers/' . basename($carrier['logo_path']));
        } elseif ($carrier && !empty($carrier['logo_url'])) {
            $carrier['logo_public'] = $carrier['logo_url'];
        } else {
            $carrier['logo_public'] = null;
        }
    } catch (Throwable $e) {
        $carrier = null;
    }
}

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES); }
function nxFmtDateServer(?string $v): string {
    if (!$v) return '—';
    $s = str_replace('T', ' ', (string)$v);
    // Trim seconds and milliseconds
    $s = preg_replace('/\.\d+$/', '', $s);
    $s = preg_replace('/:\d{2}$/', '', $s);
    return substr($s, 0, 16);
}
$initialTracking = $tracking;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Track Shipment — <?= h($appName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="<?= asset('vendor/maplibre/maplibre-gl.css') ?>" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
<style>
  .map-wrap { width: 100%; }
  #map { height: clamp(360px, 55vh, 640px); border-radius: 14px; overflow: hidden; box-shadow: 0 8px 24px rgba(11,29,58,.18); }
  @media (max-width: 640px) { #map { height: clamp(320px, 60vh, 520px); } }
  @media (min-width: 1100px) { #map { height: clamp(520px, 70vh, 780px); } }
  body.nx-map-full #map { position: fixed; inset: 0; height: 100vh; width: 100vw; border-radius: 0; z-index: 9000; }
  body.nx-map-full #nx-fs-btn { position: fixed; bottom: 20px; right: 20px; z-index: 9999; }
  body.nx-map-full #nx-amb-btn { position: fixed; top: 20px; right: 20px; z-index: 9999; }
  body.nx-map-full .map-hud { position: fixed; top: 20px; left: 20px; z-index: 9999; }
  #nx-fs-btn { position: absolute; bottom: 12px; right: 12px; z-index: 9999; }
  body.nx-map-full { overflow: hidden; }
  .map-wrap { position: relative; }
  .map-hud { position: absolute; top: 12px; left: 12px; z-index: 500; background: rgba(11,29,58,.85); color: #fff; padding: .65rem .9rem; border-radius: 10px; font-size: .82rem; backdrop-filter: blur(6px); border: 1px solid rgba(240,180,41,.4); }
  .map-hud .val { color: #ffd166; font-weight: 700; }
  .map-hud .row { margin-top: .2rem; opacity: .9; }
  .nx-amb-btn { position: absolute; top: 12px; right: 12px; z-index: 500; background: rgba(11,29,58,.85); color: #fff; border: 1px solid rgba(240,180,41,.4); border-radius: 9px; padding: .5rem .8rem; font-size: .8rem; cursor: pointer; font-weight: 600; font-family: inherit; }
  .nx-legend { position: absolute; bottom: 12px; left: 12px; z-index: 500; background: rgba(11,29,58,.85); color: #fff; padding: .55rem .8rem; border-radius: 9px; font-size: .75rem; line-height: 1.5; }
  .nx-veh { width: 34px; height: 34px; font-size: 26px; line-height: 34px; text-align: center; filter: drop-shadow(0 0 6px rgba(240,180,41,.9)); }
  .nx-veh-label { background: rgba(11,29,58,.9); color: #ffd166; font-size: .7rem; font-weight: 700; padding: 1px 6px; border-radius: 6px; margin-top: 2px; display: inline-block; white-space: nowrap; }
  .nx-wp { background: rgba(11,29,58,.92); color: #fff; font-size: .72rem; font-weight: 600; padding: 2px 7px; border-radius: 6px; border: 1px solid rgba(240,180,41,.5); white-space: nowrap; }
  .nx-amb { opacity: .65; }
  .nx-map-loading { position: absolute; inset: 0; background: linear-gradient(135deg, #0b1d3a, #132a52); display: flex; flex-direction: column; align-items: center; justify-content: center; z-index: 800; border-radius: 14px; color: #ffd166; font-family: inherit; transition: opacity .6s ease; }
  .nx-map-loading.hide { opacity: 0; pointer-events: none; }
  .nx-map-loading .spinner { width: 44px; height: 44px; border: 3px solid rgba(240,180,41,.25); border-top-color: #f0b429; border-radius: 50%; animation: nx-spin 1s linear infinite; margin-bottom: 16px; }
  .nx-map-loading .msg { font-size: 1rem; font-weight: 600; letter-spacing: .02em; }
  .nx-map-loading .sub { font-size: .8rem; opacity: .7; margin-top: 6px; }
  @keyframes nx-spin { to { transform: rotate(360deg); } }
  @keyframes nx-pulse { 0%,100% { box-shadow: 0 0 14px rgba(220,38,38,.85); } 50% { box-shadow: 0 0 24px rgba(220,38,38,.35); } }
  .caption { font-size: .78rem; opacity: .65; margin-top: .5rem; text-align: center; }
  .hold-banner { padding: 1rem 1.25rem; border-radius: 12px; margin-bottom: 1rem; background: rgba(220,38,38,.08); border-left: 4px solid #dc2626; }
  .hold-banner h3 { margin: 0 0 .35rem; color: #991b1b; font-size: 1rem; }
  .hold-banner p { margin: 0; font-size: .9rem; color: #7f1d1d; }
  .progress-outer { background: #e5e7eb; height: 12px; border-radius: 999px; overflow: hidden; }
  .progress-inner { height: 100%; background: linear-gradient(90deg,#f0b429,#2563eb); transition: width .8s ease; }
  .timeline { list-style: none; padding: 0; margin: 0; }
  .timeline li { padding: .9rem 0; border-bottom: 1px solid #e5e7eb; }
  .timeline li:last-child { border-bottom: 0; }
  .timeline .ev-head { display: flex; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
  .timeline .ev-time { font-size: .8rem; opacity: .7; }
  .timeline .ev-desc { font-size: .9rem; margin-top: .25rem; color: #334155; }
  .timeline .ev-loc { font-size: .85rem; opacity: .8; margin-top: .15rem; }
  .timeline-scroll { max-height: 360px; overflow-y: auto; padding-right: 6px; }
  .timeline-scroll::-webkit-scrollbar { width: 6px; }
  .timeline-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
  .timeline-scroll::-webkit-scrollbar-track { background: #f1f5f9; }
  .nx-share-row { display:flex; gap:.6rem; flex-wrap:wrap; margin-top:1rem; }
  .nx-btn { display:inline-flex; align-items:center; gap:.4rem; padding:.6rem 1rem; border-radius:10px; border:1px solid #cbd5e1; background:#fff; color:#0b1d3a; font-family:inherit; font-weight:600; font-size:.88rem; cursor:pointer; text-decoration:none; }
  .nx-btn:hover { background:#0b1d3a; color:#fff; }
  .nx-btn.primary { background:linear-gradient(135deg,#f0b429,#ffd166); border:0; color:#0b1d3a; }
  .nx-qr { margin-top:1rem; display:none; text-align:center; }
  .nx-qr img, .nx-qr canvas { background:#fff; padding:8px; border-radius:10px; border:1px solid #e2e8f0; }
  .unverified-banner { padding:.9rem 1.1rem; border-radius:12px; margin-bottom:1rem; background:rgba(245,158,11,.12); border-left:4px solid #f59e0b; color:#92400e; font-size:.9rem; }
  .nx-chat { position:fixed; right:18px; bottom:18px; z-index:9500; font-family:inherit; }
  .nx-chat-toggle { width:56px; height:56px; border-radius:50%; border:0; background:linear-gradient(135deg,#0b1d3a,#132a52); color:#ffd166; font-size:24px; cursor:pointer; box-shadow:0 8px 24px rgba(11,29,58,.35); }
  .nx-chat-panel { display:none; position:absolute; right:0; bottom:68px; width:min(340px,86vw); height:440px; background:#fff; border-radius:14px; box-shadow:0 18px 50px rgba(11,29,58,.3); border:1px solid #e2e8f0; flex-direction:column; overflow:hidden; }
  .nx-chat-panel.open { display:flex; }
  .nx-chat-head { background:#0b1d3a; color:#fff; padding:.8rem 1rem; font-weight:700; font-size:.9rem; display:flex; justify-content:space-between; align-items:center; }
  .nx-chat-body { flex:1; overflow-y:auto; padding:.85rem; background:#f8fafc; font-size:.86rem; }
  .nx-chat-msg { margin-bottom:.6rem; padding:.55rem .75rem; border-radius:10px; max-width:85%; line-height:1.45; white-space:pre-wrap; }
  .nx-chat-msg.bot { background:#fff; border:1px solid #e2e8f0; color:#0b1d3a; }
  .nx-chat-msg.user { background:#2563eb; color:#fff; margin-left:auto; }
  .nx-chat-form { display:flex; border-top:1px solid #e2e8f0; }
  .nx-chat-form input { flex:1; border:0; padding:.7rem .85rem; font-family:inherit; font-size:.86rem; outline:none; }
  .nx-chat-form button { border:0; background:#f0b429; color:#0b1d3a; font-weight:700; padding:0 1rem; cursor:pointer; font-family:inherit; }
</style>
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#0b1d3a">
    <link rel="icon" href="<?= asset('images/icon.svg') ?>" type="image/svg+xml">
    <script src="<?= asset('js/nx-labels.js') ?>"></script>
</head>
<body>
<div id="nx-errbox" style="display:none;position:fixed;bottom:0;left:0;right:0;background:#7f1d1d;color:#fff;padding:8px 12px;font-size:.78rem;z-index:99999;max-height:140px;overflow:auto;font-family:monospace;"></div>
<script>
window.addEventListener('error',function(e){var b=document.getElementById('nx-errbox');if(!b)return;b.style.display='block';b.textContent='JS error: '+(e.message||'')+' @ '+(e.filename||'')+':'+(e.lineno||'');});
window.addEventListener('unhandledrejection',function(e){var b=document.getElementById('nx-errbox');if(!b)return;b.style.display='block';b.textContent='Promise error: '+((e.reason&&e.reason.message)?e.reason.message:e.reason);});
</script>

<header class="navbar">
    <a href="/" class="logo" style="text-decoration:none;color:inherit;">
        <span class="logo-badge">N</span>
        <span class="logo-text"><?= h($appName) ?></span>
    </a>
    <nav class="nav-links">
        <a href="/#services">Services</a>
        <a href="/#why">Why Nexora</a>
        <a href="/#contact">Contact</a>
    </nav>
    <div id="google_translate_element" style="position:absolute;left:-9999px;"></div>
    <button type="button" id="nx-lang-btn" style="display:none;margin-left:auto;padding:.45rem .75rem;border-radius:9px;border:1px solid rgba(255,255,255,.35);background:transparent;color:#fff;font-weight:600;cursor:pointer;font-size:.85rem;">🌐 Language</button>
</header>

<main class="container">

<section class="hero" style="padding-bottom:1rem;">
    <span class="pill-tag">📦 Live Shipment Tracking</span>
    <h1>Track your <span class="gradient-text">shipment</span></h1>
    <form class="track-form" method="get" action="/track.php">
        <input type="text" name="tracking" value="<?= h($tracking) ?>" placeholder="Enter tracking number" required>
        <button type="submit" class="btn btn-lg btn-gradient">Track</button>
    </form>
</section>

<?php if ($tracking === ''): ?>
    <div class="card" style="padding:1.5rem;"><p>Enter a tracking number above to see the live position, route, weather, and estimated delivery.</p></div>
<?php elseif (!$shipment): ?>
    <div class="card" style="padding:1.5rem;border-left:4px solid #f59e0b;">
        <h3 style="margin-top:0;">No shipment found</h3>
        <p>We couldn't find a shipment with tracking number <strong><?= h($tracking) ?></strong>.</p>
    </div>
<?php else: ?>

    <div id="hold-banner" class="hold-banner" style="display:none;"></div>

    <?php if ($isAdminPreview): ?>
    <div class="unverified-banner" style="background:rgba(37,99,235,.1);border-left-color:#2563eb;color:#1e40af;">👁️ <strong>Admin preview</strong> — viewing this shipment exactly as the customer sees it.</div>
    <?php endif; ?>

    <?php if ($isUnverified): ?>
    <div class="unverified-banner">⚠️ <strong>Unverified shipment.</strong> This tracking number has no registered email or phone, so we could not confirm your identity.</div>
    <?php endif; ?>

    <div class="map-wrap">
        <div id="nx-map-loading" class="nx-map-loading"><div class="spinner"></div><div class="msg">🌍 Initialising live map…</div><div class="sub">Loading real-time map and shipment position</div></div>
        <div id="map"></div>
        <div class="map-hud">
            <div><span class="val" id="hud-veh">—</span> <span id="hud-mode">en route</span></div>
            <div class="row">⏱️ <span id="hud-eta">Calculating…</span></div>
            <div class="row">📍 <span id="hud-temp">— °C</span> · <span id="hud-cond">—</span></div>
            <div class="row">🕒 <span id="hud-local">—</span> local time</div>
            <div class="row">🌗 <span id="hud-daynight">—</span></div>
        </div>
        <button type="button" id="nx-amb-btn" class="nx-amb-btn" title="Toggle other traffic on the map">🌍 Ambient: ON</button>
        <button type="button" id="nx-follow-btn" class="nx-amb-btn" style="top:52px;right:12px;" title="Keep the camera locked on your shipment">🎯 Follow</button>
        <button type="button" id="nx-fs-btn" class="nx-amb-btn" style="top:auto;bottom:12px;right:12px;">⛶ Fullscreen</button>
    </div>
    <p class="caption">Position estimated from route schedule and shipment progress · updated live every few seconds.</p>

    <section class="card" id="nexora-media-strip" style="padding:1.5rem;margin-top:1.25rem;display:none;">
        <h3 style="margin-top:0;">Shipment media</h3>
        <p style="color:#64748b;font-size:.88rem;margin:4px 0 14px;">Photos and videos uploaded by our team for this shipment.</p>
        <div id="nx-media-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;"></div>
    </section>

    <div id="nx-lightbox" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.92);z-index:9999;padding:20px;box-sizing:border-box;">
        <button type="button" id="nx-lb-close" style="position:absolute;top:14px;right:16px;background:none;border:0;color:#fff;font-size:28px;cursor:pointer;">×</button>
        <button type="button" id="nx-lb-prev" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.15);border:0;color:#fff;font-size:26px;padding:8px 14px;cursor:pointer;border-radius:8px;">‹</button>
        <button type="button" id="nx-lb-next" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.15);border:0;color:#fff;font-size:26px;padding:8px 14px;cursor:pointer;border-radius:8px;">›</button>
        <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;">
            <div id="nx-lb-body" style="max-width:100%;max-height:85vh;"></div>
            <p id="nx-lb-caption" style="color:#fff;margin-top:12px;font-size:.9rem;"></p>
        </div>
    </div>

    <section class="card" style="padding:1.75rem;margin-top:1.25rem;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
            <div>
                <div style="font-size:.8rem;opacity:.7;text-transform:uppercase;letter-spacing:.08em;">Tracking number</div>
                <div style="font-size:1.4rem;font-weight:800;"><?= h($shipment['tracking_number']) ?></div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:.8rem;opacity:.7;text-transform:uppercase;letter-spacing:.08em;">Status</div>
                <div class="pill-tag" id="ship-status" style="margin-top:.25rem;text-transform:capitalize;"><?= h($shipment['status']) ?></div>
            </div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-top:1.25rem;">
            <div><div style="font-size:.75rem;opacity:.7;text-transform:uppercase;">Origin</div><div style="font-weight:600;"><?= h($shipment['origin']) ?></div></div>
            <div><div style="font-size:.75rem;opacity:.7;text-transform:uppercase;">Destination</div><div style="font-weight:600;"><?= h($shipment['destination']) ?></div></div>
            <div><div style="font-size:.75rem;opacity:.7;text-transform:uppercase;">Customer</div><div style="font-weight:600;"><?= h($shipment['customer_name']) ?></div></div>
            <div><div style="font-size:.75rem;opacity:.7;text-transform:uppercase;">Transport</div><div style="font-weight:600;text-transform:capitalize;"><?= h($shipment['transport_type']) ?><?= $shipment['vehicle_name'] ? ' · ' . h($shipment['vehicle_name']) : '' ?></div></div>
            <div><div style="font-size:.75rem;opacity:.7;text-transform:uppercase;">Estimated delivery</div><div style="font-weight:600;" id="eta-val"><?= h(nxFmtDateServer($shipment['estimated_delivery'] ?: ($shipment['eta'] ?: ''))) ?></div></div>
            <div><div style="font-size:.75rem;opacity:.7;text-transform:uppercase;">Last update</div><div style="font-weight:600;" id="last-update"><?= h(nxFmtDateServer($shipment['last_update'] ?: $shipment['updated_at'])) ?></div></div>
            <div><div style="font-size:.75rem;opacity:.7;text-transform:uppercase;">Live ETA</div><div style="font-weight:600;" id="eta-live">Calculating…</div></div>
        </div>

        <?php if ($carrier): ?>
        <div style="margin-top:1.25rem;padding-top:1rem;border-top:1px solid #eef2f7;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
            <?php if (!empty($carrier['logo_public'])): ?><img src="<?= h($carrier['logo_public']) ?>" alt="" style="height:34px;max-width:120px;object-fit:contain;"><?php endif; ?>
            <div>
                <div style="font-size:.75rem;opacity:.7;text-transform:uppercase;letter-spacing:.05em;">Handled by</div>
                <div style="font-weight:700;">
                    <?= h($carrier['name']) ?>
                    <?php if (!empty($carrier['tracking_url'])): ?>
                        · <a href="<?= h($carrier['tracking_url']) ?>" target="_blank" rel="noopener" style="font-size:.82rem;">carrier tracking</a>
                    <?php endif; ?>
                    <?php if (!empty($carrier['contact_phone'])): ?>
                        · <a href="tel:<?= h($carrier['contact_phone']) ?>" style="font-size:.82rem;"><?= h($carrier['contact_phone']) ?></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="nx-share-row">
            <button type="button" class="nx-btn primary" id="nx-copy-link">🔗 Copy Tracking Link</button>
            <button type="button" class="nx-btn" id="nx-qr-btn">📱 Show QR Code</button>
            <a class="nx-btn" id="nx-receipt-link" href="/receipt.php?id=<?= h(rawurlencode($shipment['tracking_number'])) ?>" target="_blank" rel="noopener">🧾 Print / Download Receipt</a>
            <button type="button" class="nx-btn" id="nx-replay-btn">▶ Replay Journey</button>
        </div>
        <div class="nx-qr" id="nx-qr-box"><div id="nx-qr-target"></div><p style="font-size:.78rem;color:#64748b;margin:.5rem 0 0;">Scan to open this shipment on another device.</p></div>
    </section>

    <section class="card" style="padding:1.75rem;margin-top:1.25rem;">
        <h3 style="margin-top:0;">Route progress</h3>
        <div class="progress-outer"><div class="progress-inner" id="prog-bar" style="width:0%"></div></div>
        <div style="display:flex;justify-content:space-between;margin-top:.5rem;font-size:.85rem;opacity:.8;">
            <span id="km-done">— km travelled</span>
            <span id="pct-val">—%</span>
            <span id="km-rem">— km remaining</span>
        </div>
        <p style="margin:.75rem 0 0;font-weight:700;color:#0b1d3a;" id="eta-live-2">⏱️ Calculating arrival…</p>
    </section>

    <section class="card" id="nx-proof-card" style="padding:1.75rem;margin-top:1.25rem;display:none;">
        <h3 style="margin-top:0;">✅ Proof of delivery</h3>
        <div id="nx-proof-body" style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));"></div>
    </section>

    <section class="card" style="padding:1.75rem;margin-top:1.25rem;">
        <h3 style="margin-top:0;">Tracking timeline</h3>
        <div class="timeline-scroll"><ol class="timeline" id="timeline"><li style="opacity:.6;">Loading events…</li></ol></div>
    </section>

    <div class="nx-chat" id="nx-chat">
        <div class="nx-chat-panel" id="nx-chat-panel">
            <div class="nx-chat-head"><span>🤖 Nexora Assistant</span><button type="button" id="nx-chat-close" style="background:none;border:0;color:#fff;font-size:18px;cursor:pointer;">×</button></div>
            <div class="nx-chat-body" id="nx-chat-body"><div class="nx-chat-msg bot">Hi! Ask me about this shipment — status, route, ETA or how to get updates.</div></div>
            <form class="nx-chat-form" id="nx-chat-form"><input type="text" id="nx-chat-input" placeholder="Ask about your shipment…" autocomplete="off"><button type="submit">Send</button></form>
        </div>
        <button type="button" class="nx-chat-toggle" id="nx-chat-toggle" title="Chat about this shipment">💬</button>
    </div>

<?php endif; ?>
</main>

<footer>
    <p>&copy; <?= date('Y') ?> <?= h($appName) ?>. Global Shipping. Smarter Tracking. · <a href="/admin/login.php" style="color:#8899aa;font-size:.8em;text-decoration:none;">Staff</a></p>
</footer>

<?php if ($shipment): ?>
<script src="<?= asset('vendor/maplibre/maplibre-gl.js') ?>"></script>
<script src="<?= asset('js/nx-map.js') ?>"></script>
<script src="<?= asset('js/qrcode.min.js') ?>"></script>
<script>
(function(){
'use strict';
function nxFmtDate(v){
  if (!v) return '—';
  var s = String(v).replace('T',' ');
  // trim seconds if present
  return s.replace(/:(\d{2})\.\d+.*$/, ':$1').replace(/:(\d{2})$/, ':$1').substring(0,16);
}
const TRACKING = <?= json_encode($shipment['tracking_number'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const API = '/track-api.php?tracking=' + encodeURIComponent(TRACKING);

const map = nxCreateMap('map', <?= json_encode($mapStyle, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>, {
  center: [-40, 40], zoom: 3, attributionControl: { compact: true },
  projection: 'globe', pitch: 50, bearing: -20
});
map.addControl(new maplibregl.NavigationControl({ visualizePitch: true }), 'top-left');

const segColors = { air:'#f0b429', sea:'#2563eb', rail:'#16a34a', road:'#94a3b8', bike:'#ec4899', last_mile:'#7c3aed', route:'#cbd5e1' };
const segIcons  = { air:'✈️', sea:'🚢', rail:'🚆', road:'🚚', bike:'🏍️', last_mile:'📦', route:'🚚' };
const wpIcons   = { origin:'🟢', destination:'🎯', airport:'✈️', port:'⚓', rail_hub:'🚉', warehouse:'🏬', office:'🏢', checkpoint:'🛡️', waypoint:'📍' };

let vehMarker = null, wpMarkers = [], ambientMarkers = [], ambientTimer = null, ambientOn = true, fitDone = false;
let followOn = false, segmentMsgs = {}, lastRouteCache = [], ambientMult = 1;
// Smooth motion: interpolate between API polls using requestAnimationFrame
let _smoothFrom = null, _smoothTo = null, _smoothStart = 0, _smoothDur = 1000, _smoothRaf = null;

function decodePolyline(encoded) {
  // OSRM polyline5 decode (mirrors the PHP decoder in route-action.php)
  var coords = [];
  if (!encoded || typeof encoded !== 'string' || encoded.length === 0) return coords;
  var index = 0, len = encoded.length;
  var lat = 0, lng = 0;
  while (index < len) {
    var result = 1, shift = 0, b;
    do { b = encoded.charCodeAt(index++) - 63; result += b << shift; shift += 5; } while (b >= 0x20);
    var dlat = (result & 1) ? ~(result >> 1) : (result >> 1); lat += dlat;
    result = 1; shift = 0;
    do { b = encoded.charCodeAt(index++) - 63; result += b << shift; shift += 5; } while (b >= 0x20);
    var dlng = (result & 1) ? ~(result >> 1) : (result >> 1); lng += dlng;
    coords.push([256 * lat / 1e6, 256 * lng / 1e6]);
  }
  return coords;
}

function toGeoJSON(route) {
  const features = []; let bucket = [route[0]];
  for (let i = 1; i < route.length; i++) {
    if (route[i].segment_type === route[i-1].segment_type) bucket.push(route[i]);
    else { features.push(makeFeature(bucket)); bucket = [route[i-1], route[i]]; }
  }
  if (bucket.length > 1) features.push(makeFeature(bucket));
  return { type:'FeatureCollection', features };
}
function makeFeature(pts) {
  // If any point in this segment carries an encoded_polyline (from OSRM),
  // decode it into dense [lng, lat] coordinate pairs so the line follows
  // the actual road geometry instead of straight lines between sparse points.
  var poly = null;
  for (var k = 0; k < pts.length; k++) {
    if (pts[k].encoded_polyline) { poly = pts[k].encoded_polyline; break; }
  }
  var coords;
  if (poly) {
    var decoded = decodePolyline(poly);
    coords = decoded.map(function (c) { return [c[1], c[0]]; }); // [lng, lat]
  } else {
    coords = pts.map(p => [parseFloat(p.longitude), parseFloat(p.latitude)]);
  }
  return { type:'Feature', properties:{ color: segColors[pts[0].segment_type] || segColors.route, seg: pts[0].segment_type || 'route' },
    geometry:{ type:'LineString', coordinates: coords } };
}
function renderRoute(route) {
  lastRouteCache = route || [];
  const gj = toGeoJSON(route); const src = map.getSource('nx-route');
  if (src) { src.setData(gj); return; }
  map.addSource('nx-route', { type:'geojson', data: gj });
  // Glow underlay (wide, semi-transparent)
  map.addLayer({ id:'nx-route-glow', type:'line', source:'nx-route',
    layout:{ 'line-cap':'round', 'line-join':'round' },
    paint:{ 'line-color':'#f0b429', 'line-width':8, 'line-opacity':0.35, 'line-blur':4 } });
  // Bright solid line on top
  map.addLayer({ id:'nx-route-line', type:'line', source:'nx-route',
    layout:{ 'line-cap':'round', 'line-join':'round' },
    paint:{ 'line-color':'#f0b429', 'line-width':3, 'line-opacity':0.95 } });
  // Origin marker
  if (route && route.length > 0) {
    const o = route[0];
    const el = document.createElement('div');
    el.style.cssText = 'font-size:24px;filter:drop-shadow(0 0 6px #16a34a);';
    el.textContent = '🟢';
    new maplibregl.Marker({ element: el, anchor: 'center' })
      .setLngLat([parseFloat(o.longitude), parseFloat(o.latitude)])
      .addTo(map);
  }
  // Destination marker
  if (route && route.length > 1) {
    const d = route[route.length - 1];
    const el = document.createElement('div');
    el.style.cssText = 'font-size:24px;filter:drop-shadow(0 0 6px #dc2626);';
    el.textContent = '🎯';
    new maplibregl.Marker({ element: el, anchor: 'center' })
      .setLngLat([parseFloat(d.longitude), parseFloat(d.latitude)])
      .addTo(map);
  }
  map.on('click', 'nx-route-line', function(ev){
    try {
      const f = ev.features && ev.features[0];
      const seg = f && f.properties ? f.properties.seg : null;
      const msg = seg ? segmentMsgs[seg] : null;
      if (!msg) return;
      new maplibregl.Popup({ offset: 12, closeButton: true })
        .setLngLat(ev.lngLat)
        .setHTML('<div style="font-family:Plus Jakarta Sans,system-ui,sans-serif;min-width:200px;line-height:1.5;">' +
          '<b style="color:#0b1d3a;">' + esc((msg.icon ? msg.icon + ' ' : '') + (msg.public_title || seg)) + '</b>' +
          (msg.public_description ? '<div style="color:#475467;font-size:.85rem;margin-top:4px;">' + esc(msg.public_description) + '</div>' : '') +
          '</div>')
        .addTo(map);
    } catch(e) {}
  });
  map.on('mouseenter', 'nx-route-line', function(){ map.getCanvas().style.cursor = 'pointer'; });
  map.on('mouseleave', 'nx-route-line', function(){ map.getCanvas().style.cursor = ''; });
}
function pickIcon(lat, lng, route, fallbackEmoji) {
  if (route && route.length) {
    let best = route[0], bd = Infinity;
    for (const p of route) { const d = Math.pow(p.latitude-lat,2)+Math.pow(p.longitude-lng,2); if (d<bd) { bd=d; best=p; } }
    const t = best.segment_type || 'route';
    if (segIcons[t] && t !== 'route') return segIcons[t];
  }
  return fallbackEmoji || '🚚';
}
function nxShipmentState(s, control, holds) {
  const st = String(s.status || '').toLowerCase();
  if (st === 'delivered') return { icon: '✅', label: 'Delivered', tone: '#16a34a', paused: true };
  if (st === 'seized') return { icon: '🚫', label: 'Seized by authority', tone: '#dc2626', paused: true };
  if (holds && holds.length) {
    const h = holds[0];
    const map = { customs:'Customs inspection', safety:'Safety check', security:'Security screening', compliance:'Compliance review', other:'Operational hold' };
    return { icon: '⏸️', label: (map[h.hold_type] || 'On hold') + (h.reason ? ' — ' + h.reason : ''), tone: '#dc2626', paused: true };
  }
  if (st === 'out_for_delivery' || st === 'out-for-delivery') return { icon: '📦', label: 'Out for delivery', tone: '#f59e0b', paused: false };
  if (control && control.is_running === false) return { icon: '⏸️', label: 'Movement paused', tone: '#f59e0b', paused: true };
  if (control && control.is_running === true) return { icon: null, label: 'En route', tone: '#f0b429', paused: false };
  return { icon: null, label: (s.status || 'pending').replace(/_/g,' '), tone: '#f0b429', paused: false };
}
function setVehicle(lat, lng, heading, route, fallbackEmoji, state) {
  const vehIcon = pickIcon(lat, lng, route, fallbackEmoji);
  const tone = (state && state.tone) ? state.tone : '#f0b429';
  const paused = state && state.paused;
  const badge = (state && state.icon) ? state.icon : '';
  const pulseStyle = paused ? 'animation:nx-pulse 1.6s infinite;' : '';
  const html = '<div style="display:inline-flex;flex-direction:column;align-items:center;position:relative;">' +
    '<div style="position:relative;width:64px;height:64px;">' +
      '<div style="position:absolute;inset:0;border-radius:50%;background:rgba(11,29,58,.92);border:3px solid ' + tone + ';display:flex;align-items:center;justify-content:center;box-shadow:0 0 18px ' + tone + ';' + pulseStyle + '">' +
        '<div id="nx-veh-ico" style="font-size:32px;line-height:1;transform:rotate(' + (heading||0) + 'deg);filter:drop-shadow(0 0 4px ' + tone + ');">' + vehIcon + '</div>' +
      '</div>' +
      (badge ? '<div style="position:absolute;top:-6px;right:-6px;width:26px;height:26px;border-radius:50%;background:' + tone + ';color:#fff;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:700;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.35);">' + badge + '</div>' : '') +
    '</div>' +
    '<div id="nx-veh-lbl" style="margin-top:4px;background:' + tone + ';color:#0b1d3a;font-size:.72rem;font-weight:800;padding:2px 8px;border-radius:6px;white-space:nowrap;">' + esc(TRACKING) + '</div>' +
  '</div>';
  if (!vehMarker) {
    const el = document.createElement('div');
    el.innerHTML = html;
    vehMarker = new maplibregl.Marker({ element: el, anchor:'center' }).setLngLat([lng, lat]).addTo(map);
    window.__nxVehPopup = new maplibregl.Popup({ offset: 40, closeButton: true });
    vehMarker.setPopup(window.__nxVehPopup);
  } else {
    vehMarker.getElement().innerHTML = html;
    // Smooth interpolation between API polls using requestAnimationFrame
    _smoothFrom = vehMarker.getLngLat();
    _smoothTo = { lng: lng, lat: lat };
    _smoothStart = performance.now();
    _smoothDur = 1000;
    if (!_smoothRaf) {
      _smoothRaf = requestAnimationFrame(_smoothStep);
    }
  }
  if (followOn) {
    try { map.easeTo({ center: [lng, lat], zoom: Math.max(map.getZoom(), 5), duration: 900 }); } catch(e) {}
  }
  try {
    const s = window.__nxLastShipment || {};
    const speedTxt = s.speed ? (s.speed + ' km/h') : '—';
    const updated = s.last_update || s.updated_at || '—';
    window.__nxVehPopup.setHTML(
      '<div style="font-family:Plus Jakarta Sans,system-ui,sans-serif;min-width:220px;line-height:1.5;">' +
        '<div style="font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;color:#64748b;">Your shipment</div>' +
        '<div style="font-weight:800;color:#0b1d3a;font-size:1.05rem;">' + esc(TRACKING) + '</div>' +
        '<hr style="border:0;border-top:1px solid #e2e8f0;margin:8px 0;">' +
        '<div><b>Vehicle:</b> ' + esc(s.vehicle_name || s.transport_type || '—') + '</div>' +
        '<div><b>Status:</b> ' + esc((s.status || '—').replace(/_/g,' ')) + '</div>' +
        '<div><b>State:</b> ' + esc((state && state.label) || '—') + '</div>' +
        '<div><b>Speed:</b> ' + esc(speedTxt) + '</div>' +
        '<div style="margin-top:6px;font-size:.78rem;color:#64748b;">Updated: ' + esc(updated) + '</div>' +
        (paused ? '<div style="margin-top:8px;padding:6px 8px;background:#fef2f2;color:#991b1b;border-radius:6px;font-size:.8rem;">⏸ Shipment is currently paused</div>' : '') +
      '</div>'
    );
  } catch(e) {}
}
function _smoothStep(now) {
  if (!_smoothFrom || !_smoothTo) { _smoothRaf = null; return; }
  const t = Math.min(1, (now - _smoothStart) / _smoothDur);
  const e = t < 0.5 ? 2*t*t : 1 - Math.pow(-2*t + 2, 2) / 2; // easeInOutQuad
  const lng = _smoothFrom.lng + (_smoothTo.lng - _smoothFrom.lng) * e;
  const lat = _smoothFrom.lat + (_smoothTo.lat - _smoothFrom.lat) * e;
  try { vehMarker.setLngLat([lng, lat]); } catch(e) {}
  if (t < 1) {
    _smoothRaf = requestAnimationFrame(_smoothStep);
  } else {
    try { vehMarker.setLngLat([_smoothTo.lng, _smoothTo.lat]); } catch(e) {}
    _smoothRaf = null;
  }
}
function esc(s){ const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
function renderWaypoints(wps) {
  wpMarkers.forEach(m => m.remove()); wpMarkers = [];
  wps.forEach(w => {
    const el = document.createElement('div');
    const labelTxt = w.public_label && String(w.public_label).trim() !== '' ? w.public_label : w.name;
    el.innerHTML = '<div style="font-size:20px;text-align:center;">'+(wpIcons[w.waypoint_type]||'📍')+'</div><div class="nx-wp">'+esc(labelTxt)+'</div>';
    const m = new maplibregl.Marker({ element: el, anchor:'bottom' }).setLngLat([parseFloat(w.longitude), parseFloat(w.latitude)]).addTo(map);
    m.setPopup(new maplibregl.Popup({ offset: 20 }).setHTML('<b>'+esc(w.name)+'</b>'+(w.address?'<br>'+esc(w.address):'')+'<br><small style="color:#64748b;">'+esc(w.public_note||w.waypoint_type||'')+'</small>'));
    wpMarkers.push(m);
  });
}

const wmo = {0:'Clear',1:'Mainly clear',2:'Partly cloudy',3:'Overcast',45:'Fog',48:'Rime fog',51:'Light drizzle',53:'Drizzle',55:'Heavy drizzle',61:'Light rain',63:'Rain',65:'Heavy rain',71:'Light snow',73:'Snow',75:'Heavy snow',80:'Rain showers',81:'Rain showers',82:'Violent showers',95:'Thunderstorm',96:'Thunderstorm + hail',99:'Severe thunderstorm'};
async function fetchWeather(lat, lng) {
  try {
    const r = await nxSafeFetch('https://api.open-meteo.com/v1/forecast?latitude='+lat+'&longitude='+lng+'&current=temperature_2m,weather_code&timezone=auto');
    const j = await r.json();
    document.getElementById('hud-temp').textContent = j.current.temperature_2m + ' °C';
    document.getElementById('hud-cond').textContent = wmo[j.current.weather_code] || 'Weather';
    const local = new Date(Date.now() + (j.utc_offset_seconds||0)*1000);
    document.getElementById('hud-local').textContent = local.toISOString().substring(11,16);
    const h = local.getUTCHours();
    let part = 'Day';
    if (h < 6 || h >= 20) part = 'Night'; else if (h < 9) part = 'Morning'; else if (h < 12) part = 'Mid-morning'; else if (h < 14) part = 'Midday'; else if (h < 17) part = 'Afternoon'; else part = 'Evening';
    document.getElementById('hud-daynight').textContent = part;
  } catch (e) {}
}

function renderHolds(data) {
  const el = document.getElementById('hold-banner');
  if (!data.holds || !data.holds.length) { el.style.display='none'; return; }
  el.style.display='block';
  const hh = data.holds[0];
  el.innerHTML = '<h3>⚠️ Shipment on hold — '+esc(hh.hold_type||'')+'</h3><p>'+esc(hh.reason||'')+(hh.details ? ' — '+esc(hh.details) : '')+'</p>';
}
function renderTimeline(events) {
  const ol = document.getElementById('timeline');
  const wrap = ol.parentElement;
  let toggle = document.getElementById('nx-timeline-toggle');
  if (!events || !events.length) {
    ol.innerHTML = '<li style="opacity:.6;">No events yet.</li>';
    if (toggle) toggle.style.display = 'none';
    return;
  }
  window.__nxAllEvents = events;
  if (typeof window.__nxEventsExpanded === 'undefined') window.__nxEventsExpanded = false;
  const shown = window.__nxEventsExpanded ? events : events.slice(0, 5);
  ol.innerHTML = shown.map(e => '<li><div class="ev-head"><strong>'+esc(e.title)+'</strong><span class="ev-time">'+esc(e.event_time)+'</span></div>'+(e.location?'<div class="ev-loc">📍 '+esc(e.location)+'</div>':'')+(e.description?'<div class="ev-desc">'+esc(e.description)+'</div>':'')+'</li>').join('');
  if (!toggle) {
    toggle = document.createElement('button');
    toggle.id = 'nx-timeline-toggle';
    toggle.type = 'button';
    toggle.style.cssText = 'display:block;margin:10px auto 0;padding:.55rem 1.2rem;border-radius:9px;border:1px solid #cbd5e1;background:#fff;color:#0b1d3a;font-family:inherit;font-weight:600;font-size:.85rem;cursor:pointer;';
    wrap.appendChild(toggle);
    toggle.addEventListener('click', function() {
      window.__nxEventsExpanded = !window.__nxEventsExpanded;
      renderTimeline(window.__nxAllEvents || []);
    });
  }
  if (events.length <= 5) { toggle.style.display = 'none'; return; }
  toggle.style.display = 'block';
  toggle.textContent = window.__nxEventsExpanded ? 'Show less' : ('Show all (' + (events.length - 5) + ' more)');
}

// Ambient
const AIR_HUBS = [['JFK',40.6413,-73.7781],['LHR',51.4700,-0.4543],['CDG',49.0097,2.5479],['DXB',25.2532,55.3657],['SIN',1.3644,103.9915],['HKG',22.3080,113.9185],['NRT',35.7720,140.3929],['LAX',33.9416,-118.4085],['ORD',41.9742,-87.9073],['SYD',-33.9399,151.1753],['GRU',-23.4356,-46.4731],['JNB',-26.1392,28.2460],['CAI',30.1219,31.4056],['IST',41.2753,28.7519],['PEK',40.0799,116.6031]];
const SEA_HUBS = [['Shanghai',31.2304,121.4737],['Singapore',1.3521,103.8198],['Rotterdam',51.9244,4.4777],['LosAngeles',33.7405,-118.2775],['Hamburg',53.5511,9.9937],['DubaiPort',25.2048,55.2708],['Busan',35.1796,129.0756],['Antwerp',51.2194,4.4025],['NYC',40.6892,-74.0445],['Mumbai',19.0760,72.8777],['Santos',-23.9608,-46.3336],['Durban',-29.8587,31.0218]];
// Ambient speed presets (per-arc progress units per tick)
const SP = { plane: 0.11, ship: 0.025, car: 0.06, train: 0.08, ped: 0.015 };

function randPair(a){ const x=a[Math.floor(Math.random()*a.length)]; let y=a[Math.floor(Math.random()*a.length)]; while(y===x) y=a[Math.floor(Math.random()*a.length)]; return [x,y]; }
function arc(a,b,n){ const pts=[]; const bulge=Math.min(20,Math.abs(b[2]-a[2])*0.18); for(let i=0;i<=n;i++){ const t=i/n; pts.push([a[1]+(b[1]-a[1])*t+Math.sin(Math.PI*t)*bulge*0.15, a[2]+(b[2]-a[2])*t]); } return pts; }
function buildAmbient() {
  ambientMarkers.forEach(m => m.m.remove()); ambientMarkers = [];
  if (ambientMult <= 0) return;
  const rand = (a, b) => a + Math.floor(Math.random() * (b - a + 1));
  const mode = window.__nxAmbientMode || 'normal';
  let density = 1, speedMul = 1;
  switch (mode) {
    case 'high':   density = 1.8; speedMul = 1.5; break;
    case 'low':    density = 0.5; speedMul = 0.5; break;
    default:       density = 1.0; speedMul = 1.0; break;
  }
  const planes = Math.floor(rand(14, 22) * density);
  const ships  = Math.floor(rand(7, 12) * density);
  const road   = Math.floor(rand(10, 18) * density);
  const peds   = Math.floor(rand(5, 10) * density);
  const push = (pts, emoji, size, speed, kind) => {
    const el = document.createElement('div');
    el.className = 'nx-amb';
    el.style.cssText = 'cursor:pointer;filter:drop-shadow(0 0 4px rgba(148,163,184,.8));';
    el.innerHTML = '<span class="nx-amb-ico" style="display:inline-block;font-size:' + size + 'px;line-height:1;">' + emoji + '</span>';
    const t = Math.random() * (pts.length - 1);
    const m = new maplibregl.Marker({ element: el, anchor:'center' }).setLngLat([pts[Math.floor(t)][1], pts[Math.floor(t)][0]]).addTo(map);
    const info = {
      plane: { title: 'Commercial aircraft', text: 'Other air traffic — not your shipment.' },
      ship:  { title: 'Cargo vessel',         text: 'Other sea traffic — not your shipment.' },
      car:   { title: 'Road vehicle',         text: 'Local road traffic.' },
      train: { title: 'Rail service',         text: 'Local rail traffic.' },
      ped:   { title: 'Pedestrian',           text: 'Foot traffic.' }
    }[kind] || { title: kind, text: 'Ambient activity.' };
    m.setPopup(new maplibregl.Popup({ offset: 12, closeButton: false }).setHTML(
      '<div style="font-family:Plus Jakarta Sans,system-ui,sans-serif;font-size:.85rem;line-height:1.4;">' +
        '<b style="color:#0b1d3a;">' + info.title + '</b><br>' +
        '<span style="color:#64748b;font-size:.78rem;">' + info.text + '</span>' +
      '</div>'
    ));
    ambientMarkers.push({ m, pts, t, speed: speed * speedMul * ambientMult, kind });
  };
  for (let i = 0; i < planes; i++) { const [a,b] = randPair(AIR_HUBS); push(arc(a,b,36), '✈️', 16, SP.plane * (0.7 + Math.random()*0.6), 'plane'); }
  for (let i = 0; i < ships;  i++) { const [a,b] = randPair(SEA_HUBS); push(arc(a,b,36), '🚢', 17, SP.ship * (0.7 + Math.random()*0.6), 'ship'); }
  for (let i = 0; i < road;   i++) { const [a] = randPair(AIR_HUBS); const isTrain = i % 3 === 0; push([[a[1],a[2]],[a[1]+(Math.random()-.5)*3,a[2]+(Math.random()-.5)*3]], isTrain ? '🚆' : '🚗', 15, (isTrain?SP.train:SP.car) * (0.7 + Math.random()*0.6), isTrain ? 'train' : 'car'); }
  for (let i = 0; i < peds;   i++) { const [a] = randPair(AIR_HUBS); push([[a[1],a[2]],[a[1]+(Math.random()-.5)*0.4,a[2]+(Math.random()-.5)*0.4]], '🚶', 12, SP.ped * (0.7 + Math.random()*0.6), 'ped'); }
}
function tickAmbient() {
  if (!ambientOn) return;
  ambientMarkers.forEach(it => {
    it.t += it.speed;
    if (it.t >= it.pts.length - 1) it.t = 0;
    const i0 = Math.floor(it.t), i1 = Math.min(i0+1, it.pts.length-1), f = it.t - i0;
    const p0 = it.pts[i0], p1 = it.pts[i1];
    const lat = p0[0] + (p1[0]-p0[0])*f;
    const lng = p0[1] + (p1[1]-p0[1])*f;
    it.m.setLngLat([lng, lat]);
    const ico = it.m.getElement().querySelector('.nx-amb-ico');
    if (ico) {
      const dLat = p1[0] - p0[0];
      const dLng = p1[1] - p0[1];
      let heading = Math.atan2(dLng * Math.cos(lat * Math.PI/180), dLat) * 180/Math.PI;
      ico.style.transform = 'rotate(' + heading.toFixed(1) + 'deg)';
    }
  });
}
function startAmbient(){ if(ambientTimer)return; buildAmbient(); ambientTimer = setInterval(tickAmbient, 900); }
function stopAmbient(){ if(ambientTimer){clearInterval(ambientTimer);ambientTimer=null;} ambientMarkers.forEach(m => m.m.remove()); ambientMarkers = []; }
function setAmbientMode(mode, showBtn) {
  window.__nxAmbientMode = mode;
  if (mode === 'force_off') {
    ambientOn = false;
    stopAmbient();
    const btn = document.getElementById('nx-amb-btn');
    if (btn) btn.style.display = 'none';
    return;
  }
  const btn = document.getElementById('nx-amb-btn');
  if (showBtn && btn) btn.style.display = '';
  if (mode === 'off') {
    ambientOn = false;
    stopAmbient();
    if (btn) { btn.textContent = '🌍 Ambient: OFF'; btn.style.display = ''; }
    return;
  }
  // on / high / normal / low
  ambientOn = true;
  if (btn) btn.textContent = '🌍 Ambient: ON';
  if (ambientTimer) { clearInterval(ambientTimer); ambientTimer = null; }
  startAmbient();
}
document.getElementById('nx-amb-btn').addEventListener('click', () => {
  ambientOn = !ambientOn;
  const btn = document.getElementById('nx-amb-btn');
  if (ambientOn) {
    startAmbient();
    if (btn) btn.textContent = '🌍 Ambient: ON';
  } else {
    stopAmbient();
    if (btn) btn.textContent = '🌍 Ambient: OFF';
  }
});

// Media
const mediaStrip = document.getElementById('nexora-media-strip');
const mediaGrid = document.getElementById('nx-media-grid');
let mediaItems = [], mediaIdx = 0;
function renderMediaStrip(items) {
  if (!items || !items.length) { mediaStrip.style.display = 'none'; return; }
  const sig = items.map(m => m.id + ':' + m.file_path).join('|');
  if (sig === renderMediaStrip._sig) return;
  renderMediaStrip._sig = sig; mediaItems = items;
  mediaStrip.style.display = '';
  mediaGrid.innerHTML = items.map((m, i) => {
    const src = '<?= asset('media/') ?>' + encodeURIComponent(m.file_path);
    const thumb = m.media_type === 'photo'
      ? '<img src="'+src+'" alt="" style="width:100%;height:110px;object-fit:cover;border-radius:8px;cursor:pointer;background:#e2e8f0;">'
      : '<div style="position:relative;width:100%;height:110px;border-radius:8px;overflow:hidden;cursor:pointer;background:#0b1d3a;"><video src="'+src+'" muted playsinline style="width:100%;height:100%;object-fit:cover;"></video><div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(11,29,58,.35);color:#fff;font-size:28px;">▶</div></div>';
    const cap = m.caption ? '<div style="font-size:.78rem;color:#475569;margin-top:6px;">'+m.caption+'</div>' : '';
    return '<div data-idx="'+i+'" class="nx-media-item">'+thumb+cap+'</div>';
  }).join('');
  mediaGrid.querySelectorAll('.nx-media-item').forEach(el => el.addEventListener('click', () => openLB(parseInt(el.dataset.idx, 10))));
}
function openLB(i) {
  if (!mediaItems.length) return;
  mediaIdx = (i + mediaItems.length) % mediaItems.length;
  const m = mediaItems[mediaIdx], src = '<?= asset('media/') ?>' + encodeURIComponent(m.file_path);
  const b = document.getElementById('nx-lb-body');
  b.innerHTML = m.media_type === 'photo' ? '<img src="'+src+'" style="max-width:100%;max-height:85vh;border-radius:10px;">' : '<video src="'+src+'" controls autoplay style="max-width:100%;max-height:85vh;border-radius:10px;"></video>';
  document.getElementById('nx-lb-caption').textContent = m.caption || '';
  document.getElementById('nx-lightbox').style.display = '';
}
document.getElementById('nx-lb-close').addEventListener('click', () => { document.getElementById('nx-lightbox').style.display='none'; document.getElementById('nx-lb-body').innerHTML=''; });
document.getElementById('nx-lb-prev').addEventListener('click', () => openLB(mediaIdx-1));
document.getElementById('nx-lb-next').addEventListener('click', () => openLB(mediaIdx+1));
document.getElementById('nx-lightbox').addEventListener('click', e => { if (e.target === document.getElementById('nx-lightbox')) document.getElementById('nx-lightbox').style.display='none'; });

// Poll
let __nxFirstFix = false;
// Country flag emoji from text
function nxFlagForText(txt) {
  if (!txt) return '📍';
  const t = String(txt).toLowerCase();
  const map = [
    ['united states|usa|u.s.a|america', '🇺🇸'],
    ['united kingdom|uk|britain|england|london', '🇬🇧'],
    ['nigeria', '🇳🇬'],
    ['china', '🇨🇳'],
    ['japan|tokyo', '🇯🇵'],
    ['germany', '🇩🇪'],
    ['france|paris', '🇫🇷'],
    ['netherlands|rotterdam|amsterdam', '🇳🇱'],
    ['singapore', '🇸🇬'],
    ['united arab emirates|dubai|uae', '🇦🇪'],
    ['india', '🇮🇳'],
    ['brazil', '🇧🇷'],
    ['south africa', '🇿🇦'],
    ['canada', '🇨🇦'],
    ['australia|sydney', '🇦🇺'],
    ['spain', '🇪🇸'],
    ['italy', '🇮🇹'],
    ['turkey', '🇹🇷'],
    ['egypt', '🇪🇬'],
    ['kenya', '🇰🇪'],
    ['ghana', '🇬🇭'],
    ['mexico', '🇲🇽'],
    ['russia', '🇷🇺'],
    ['south korea|korea', '🇰🇷'],
  ];
  for (const [pat, flag] of map) { if (new RegExp(pat).test(t)) return flag; }
  return '📍';
}

// Draw fallback route: origin → current → destination (a curve)
function nxFallbackRoute(s) {
  const o = [s.origin_lat, s.origin_lng];
  const d = [s.destination_lat, s.destination_lng];
  const cur = [s.current_lat, s.current_lng];
  // Build a set of intermediate points so the line looks like an arc
  const mid = [(o[0]+d[0])/2, (o[1]+d[1])/2];
  // Bulge outward slightly for a great-circle feel
  const bulge = 0.08;
  const perpLat = -(d[1]-o[1]) * bulge;
  const perpLng = (d[0]-o[0]) * bulge;
  const arcMid = [mid[0] + perpLat, mid[1] + perpLng];
  return [
    { latitude: o[0], longitude: o[1], segment_type: 'route' },
    { latitude: arcMid[0], longitude: arcMid[1], segment_type: 'route' },
    { latitude: cur[0], longitude: cur[1], segment_type: 'route' },
    { latitude: arcMid[0], longitude: arcMid[1], segment_type: 'route' },
    { latitude: d[0], longitude: d[1], segment_type: 'route' },
  ];
}

// Add origin + destination markers
let nxODMarkers = [];
function nxDrawOriginDestination(s) {
  nxODMarkers.forEach(m => m.remove());
  nxODMarkers = [];
  const oIcon = nxFlagForText(s.origin);
  const dIcon = nxFlagForText(s.destination);
  if (s.origin_lat || s.origin_lng) {
    const el = document.createElement('div');
    el.innerHTML = '<div style="font-size:22px;text-align:center;">' + oIcon + '</div><div style="background:#0b1d3a;color:#fff;font-size:.7rem;font-weight:700;padding:2px 7px;border-radius:6px;white-space:nowrap;margin-top:2px;">' + (s.origin||'Origin') + '</div>';
    const m = new maplibregl.Marker({ element: el, anchor:'bottom' }).setLngLat([s.origin_lng, s.origin_lat]).addTo(map);
    nxODMarkers.push(m);
  }
  if (s.destination_lat || s.destination_lng) {
    const el = document.createElement('div');
    el.innerHTML = '<div style="font-size:22px;text-align:center;">' + dIcon + '</div><div style="background:#f0b429;color:#0b1d3a;font-size:.7rem;font-weight:700;padding:2px 7px;border-radius:6px;white-space:nowrap;margin-top:2px;">' + (s.destination||'Destination') + '</div>';
    const m = new maplibregl.Marker({ element: el, anchor:'bottom' }).setLngLat([s.destination_lng, s.destination_lat]).addTo(map);
    nxODMarkers.push(m);
  }
}

// ===== nxSafeFetch: timeout + retry =====
async function nxSafeFetch(url, opts, timeoutMs, retries) {
  timeoutMs = timeoutMs || 8000;
  retries = typeof retries === 'number' ? retries : 1;
  for (let attempt = 0; attempt <= retries; attempt++) {
    const controller = new AbortController();
    const t = setTimeout(function(){ controller.abort(); }, timeoutMs);
    try {
      const o = Object.assign({}, opts || {}, { signal: controller.signal });
      const r = await fetch(url, o);
      clearTimeout(t);
      return r;
    } catch (e) {
      clearTimeout(t);
      if (attempt === retries) throw e;
      await new Promise(function(res){ setTimeout(res, 400); });
    }
  }
}

// ===== nxTimer registry: auto-clear on page unload =====
window.__nxTimers = window.__nxTimers || [];
function nxInterval(fn, ms) {
  var id = setInterval(fn, ms);
  window.__nxTimers.push(id);
  return id;
}
window.addEventListener('pagehide', function(){ window.__nxTimers.forEach(function(id){ clearInterval(id); }); });
window.addEventListener('beforeunload', function(){ window.__nxTimers.forEach(function(id){ clearInterval(id); }); });
async function tick() {
  try {
    const r = await nxSafeFetch(API, { cache:'no-store' }, 8000, 1);
    const j = await r.json();
    if (j.status !== 'success') return;
    const s = j.shipment, route = j.route || [];
    window.__nxLastShipment = s;
    window.__nxLastApi = j;
    window.__nxAmbientSettings = j.settings || {};
    segmentMsgs = j.segment_messages || {};
    if (j.settings && typeof j.settings.ambient_speed === 'string') {
      setAmbientMode(j.settings.ambient_speed, true);
    } else if (j.settings && typeof j.settings.ambient_speed === 'number') {
      ambientMult = j.settings.ambient_speed;
      if (ambientMult <= 0 && ambientTimer) { ambientOn = false; stopAmbient(); const ab = document.getElementById('nx-amb-btn'); if (ab) ab.textContent = '🌍 Ambient: OFF'; }
    }

    // Route + waypoints (isolated so a failure doesn't block the rest)
    try {
      const effectiveRoute = (route && route.length > 1) ? route : nxFallbackRoute(s);
    if (map.isStyleLoaded()) renderRoute(effectiveRoute);
    nxDrawOriginDestination(s);
      renderWaypoints(j.waypoints || []);
    } catch(e) { console.warn('route/wp error', e); }

    // Shipment marker
    try {
      const fb = iconForShipment(s);
      const stState = nxShipmentState(s, j.control || null, j.holds || []);
      setVehicle(s.current_lat, s.current_lng, (j.last_position && j.last_position.heading) || 0, route, fb, stState);
    } catch(e) { console.warn('vehicle error', e); }

    // Progress + status text
    try {
      document.getElementById('prog-bar').style.width = s.progress_pct + '%';
      document.getElementById('km-done').textContent = (+s.distance_travelled).toFixed(0) + ' km travelled';
      document.getElementById('pct-val').textContent = s.progress_pct + '%';
      document.getElementById('km-rem').textContent = (+s.distance_remaining).toFixed(0) + ' km remaining';
      document.getElementById('ship-status').textContent = (s.status||'').replace(/_/g,' ');
      document.getElementById('eta-val').textContent = nxFmtDate(s.estimated_delivery || s.eta);
      document.getElementById('last-update').textContent = nxFmtDate(s.last_update || s.updated_at);
      document.getElementById('hud-veh').textContent = s.vehicle_name || s.transport_type || 'shipment';
      document.getElementById('hud-mode').textContent = (j.mandatory_hold ? 'on hold' : (j.control && j.control.is_running ? 'en route' : 'not moving'));
      const etaTxt = s.eta_text || '—';
      const etaEl = document.getElementById('eta-live');
      const etaEl2 = document.getElementById('eta-live-2');
      const hudEta = document.getElementById('hud-eta');
      if (etaEl) etaEl.textContent = etaTxt;
      if (etaEl2) etaEl2.textContent = '⏱️ ' + etaTxt;
      if (hudEta) hudEta.textContent = etaTxt;
    } catch(e) { console.warn('hud error', e); }

    // Holds + timeline + media + proof of delivery
    try { renderHolds(j); } catch(e) { console.warn('holds error', e); }
    try { renderTimeline(j.events || []); } catch(e) { console.warn('timeline error', e); }
    try { renderMediaStrip(j.media || []); } catch(e) { console.warn('media error', e); }
    try { renderProof(j.proof); } catch(e) { console.warn('proof error', e); }

    // Weather every 60s
    try {
      if (!window.__nxWxAt || Date.now() - window.__nxWxAt > 60000) {
        window.__nxWxAt = Date.now();
        fetchWeather(s.current_lat, s.current_lng);
      }
    } catch(e) { console.warn('weather error', e); }

    // Fit bounds once
    try {
      if (!fitDone && (s.origin_lat || s.destination_lat)) {
        const b = new maplibregl.LngLatBounds();
        b.extend([s.origin_lng, s.origin_lat]);
        b.extend([s.destination_lng, s.destination_lat]);
        b.extend([s.current_lng, s.current_lat]);
        map.fitBounds(b, { padding: 80, maxZoom: 5, duration: 0 });
        fitDone = true;
      }
    } catch(e) { console.warn('fitbounds error', e); }
  } catch (e) {
    var b = document.getElementById('nx-errbox');
    if (b) { b.style.display='block'; b.textContent = 'Poll error: ' + (e.message || e); }
  }
}
function iconForShipment(s) {
  const label = ((s.vehicle_name || '') + ' ' + (s.transport_type || '')).toLowerCase();
  if (/air|plane|flight|jet|aircraft/.test(label)) return '✈️';
  if (/sea|ship|boat|vessel|container|cargo/.test(label)) return '🚢';
  if (/rail|train/.test(label)) return '🚆';
  if (/bike|motorcycle|scooter|courier/.test(label)) return '🏍️';
  if (/van|last.?mile|delivery/.test(label)) return '📦';
  if (/truck|lorry|road|vehicle/.test(label)) return '🚚';
  return '🚚';
}
document.getElementById('nx-fs-btn').addEventListener('click', () => {
  document.body.classList.toggle('nx-map-full');
  document.getElementById('nx-fs-btn').textContent = document.body.classList.contains('nx-map-full') ? '⛶ Close fullscreen' : '⛶ Fullscreen';
  setTimeout(() => { try { map.resize(); } catch(e){} }, 200);
});
// ---- Day / night terminator (real sun position, updates every 60s) ----
function nxNightGeo() {
  const now = new Date();
  const utcH = now.getUTCHours() + now.getUTCMinutes()/60 + now.getUTCSeconds()/3600;
  const dayOfYear = Math.floor((now - new Date(Date.UTC(now.getUTCFullYear(), 0, 0))) / 86400000);
  const decl = (-23.44 * Math.PI/180) * Math.cos((2*Math.PI/365) * (dayOfYear + 10));
  const tanD = Math.tan(decl);
  const sunLng = -15 * (utcH - 12);
  const coords = [];
  if (Math.abs(tanD) < 1e-3) {
    // Equinox: night polygon is the half-world opposite to sunLng
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
    // Reverse winding so the polygon fill is on the night side (MapLibre right-hand rule)
    coords.reverse();
  }
  return { type:'Feature', geometry:{ type:'Polygon', coordinates:[coords] } };
}
if (typeof window !== 'undefined' && !window.__nxResizeHooked) {
  window.__nxResizeHooked = true;
  window.addEventListener('resize', function(){ try { map.resize(); } catch(e){} });
  document.addEventListener('visibilitychange', function(){ if (!document.hidden) { setTimeout(function(){ try { map.resize(); } catch(e){} }, 200); } });
}
map.on('load', () => {
  try {
    map.addSource('nx-night', { type:'geojson', data: nxNightGeo() });
    map.addLayer({
      id: 'nx-night-layer',
      type: 'fill',
      source: 'nx-night',
      paint: { 'fill-color': '#000820', 'fill-opacity': 0.68 }
    });
    map.addLayer({
      id: 'nx-night-edge',
      type: 'line',
      source: 'nx-night',
      paint: { 'line-color': '#f0b429', 'line-width': 1.2, 'line-opacity': 0.45 }
    });
    setInterval(() => {
      try { map.getSource('nx-night').setData(nxNightGeo()); } catch(e){}
    }, 60000);
  } catch(e){}
});
function nxHideLoading(){ var el = document.getElementById('nx-map-loading'); if (el) { el.classList.add('hide'); setTimeout(function(){ el.style.display='none'; }, 700); } }
if (typeof window !== 'undefined' && !window.__nxResizeHooked) {
  window.__nxResizeHooked = true;
  window.addEventListener('resize', function(){ try { map.resize(); } catch(e){} });
  document.addEventListener('visibilitychange', function(){ if (!document.hidden) { setTimeout(function(){ try { map.resize(); } catch(e){} }, 200); } });
}
// ---- Surface map resource errors instead of a silent blank canvas ----
map.on('error', function(e){
  var msg = (e && e.error && e.error.message) ? e.error.message : 'basemap resource could not load';
  var box = document.getElementById('nx-errbox');
  if (box) { box.style.display = 'block'; box.textContent = 'Map: ' + msg; }
});

// ---- Proof of delivery ----
function renderProof(p) {
  var card = document.getElementById('nx-proof-card');
  if (!card) return;
  if (!p || (!p.photo_url && !p.signature_url)) { card.style.display = 'none'; return; }
  card.style.display = '';
  var html = '';
  if (p.photo_url) html += '<figure style="margin:0;"><img src="' + p.photo_url + '" alt="Delivery evidence" style="width:100%;border-radius:10px;"><figcaption style="font-size:.78rem;color:#64748b;margin-top:.35rem;">Delivery photo</figcaption></figure>';
  if (p.signature_url) html += '<figure style="margin:0;"><img src="' + p.signature_url + '" alt="Signature" style="width:100%;background:#fff;border-radius:10px;border:1px solid #e2e8f0;"><figcaption style="font-size:.78rem;color:#64748b;margin-top:.35rem;">Recipient signature</figcaption></figure>';
  html += '<div style="font-size:.9rem;">' + (p.signed_by ? '<div><b>Signed by:</b> ' + esc(p.signed_by) + '</div>' : '') + (p.delivered_at ? '<div><b>Delivered:</b> ' + esc(p.delivered_at) + '</div>' : '') + (p.note ? '<div style="margin-top:.35rem;">' + esc(p.note) + '</div>' : '') + '</div>';
  document.getElementById('nx-proof-body').innerHTML = html;
}

// ---- Replay journey ----
let replayMarker = null, replayTimer = null;
function nxReplayJourney() {
  var pts = (window.__nxLastApi && window.__nxLastApi.positions) || [];
  var btn = document.getElementById('nx-replay-btn');
  if (replayTimer) { clearInterval(replayTimer); replayTimer = null; }
  if (replayMarker) { replayMarker.remove(); replayMarker = null; }
  if (pts.length < 2) {
    if (btn) btn.textContent = '▶ Replay Journey';
    alert('Not enough journey history to replay yet.');
    return;
  }
  var i = 0;
  try {
    if (!map.getSource('nx-replay')) {
      map.addSource('nx-replay', { type: 'geojson', data: { type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: [] } } });
      map.addLayer({ id: 'nx-replay-line', type: 'line', source: 'nx-replay', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#f0b429', 'line-width': 5, 'line-opacity': 0.95 } });
    } else {
      map.getSource('nx-replay').setData({ type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: [] } });
    }
  } catch (e) { console.warn('replay source error', e); }
  var el = document.createElement('div');
  el.style.cssText = 'font-size:26px;filter:drop-shadow(0 0 8px #f0b429);';
  el.innerHTML = '🚚';
  replayMarker = new maplibregl.Marker({ element: el, anchor: 'center' }).setLngLat([parseFloat(pts[0].longitude), parseFloat(pts[0].latitude)]).addTo(map);
  var coords = [];
  if (btn) btn.textContent = '⏹ Stop replay';
  // Animate the marker along the recorded path using setInterval.
  // Moves through each recorded position, drawing the trail behind.
  replayTimer = setInterval(function () {
    if (!replayMarker || !map) { clearInterval(replayTimer); replayTimer = null; return; }
    if (i >= pts.length) {
      clearInterval(replayTimer); replayTimer = null;
      if (btn) btn.textContent = '▶ Replay Journey';
      return;
    }
    var p = pts[i];
    var lat = parseFloat(p.latitude);
    var lng = parseFloat(p.longitude);
    try {
      coords.push([lng, lat]);
      map.getSource('nx-replay').setData({ type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: coords } });
      replayMarker.setLngLat([lng, lat]);
      map.panTo([lng, lat], { animate: true, duration: 100 });
    } catch (e) { console.warn('replay frame error', e); }
    i++;
  }, 100);
  window.__nxTimers.push(replayTimer);
}
// ---- Follow camera ----
var followBtn = document.getElementById('nx-follow-btn');
if (followBtn) followBtn.addEventListener('click', function () {
  followOn = !followOn;
  followBtn.textContent = followOn ? '🎯 Following' : '🎯 Follow';
  followBtn.style.background = followOn ? 'rgba(240,180,41,.95)' : 'rgba(11,29,58,.85)';
  followBtn.style.color = followOn ? '#0b1d3a' : '#fff';
  if (followOn && window.__nxLastShipment) {
    var s = window.__nxLastShipment;
    try { map.easeTo({ center: [s.current_lng, s.current_lat], zoom: Math.max(map.getZoom(), 5), duration: 900 }); } catch(e) {}
  }
});

// Expose a tiny API for the sharing / replay / chat script below.
window.__nxMap = map;
window.__nxReplayJourney = nxReplayJourney;
window.__nxApiData = function () { return window.__nxLastApi || {}; };

map.on('load', () => { setTimeout(() => { try { map.resize(); } catch(e){} }, 300); setTimeout(nxHideLoading, 800); tick(); setInterval(tick, 8000); setTimeout(() => {
    // Apply ambient mode from settings DB
    const mode = (window.__nxAmbientSettings && window.__nxAmbientSettings.ambient_speed) || 'normal';
    setAmbientMode(mode, true);
  }, 1200); });
})();
</script>

<script>
/* Sharing, QR, replay trigger, assistant and PWA registration */
(function(){
  'use strict';
  var TRACKING = <?= json_encode($shipment['tracking_number'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  var trackUrl = location.origin + '/track.php?id=' + encodeURIComponent(TRACKING);

  var copyBtn = document.getElementById('nx-copy-link');
  if (copyBtn) copyBtn.addEventListener('click', function () {
    function done(){ copyBtn.textContent = '✅ Link copied'; setTimeout(function(){ copyBtn.textContent = '🔗 Copy Tracking Link'; }, 1800); }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(trackUrl).then(done, function(){ window.prompt('Copy this tracking link:', trackUrl); });
    } else {
      window.prompt('Copy this tracking link:', trackUrl);
    }
  });

  var qrBtn = document.getElementById('nx-qr-btn');
  var qrBox = document.getElementById('nx-qr-box');
  var qrDone = false;
  if (qrBtn && qrBox) qrBtn.addEventListener('click', function () {
    var show = qrBox.style.display !== 'block';
    qrBox.style.display = show ? 'block' : 'none';
    if (show && !qrDone && typeof QRCode !== 'undefined') {
      new QRCode(document.getElementById('nx-qr-target'), { text: trackUrl, width: 180, height: 180, correctLevel: QRCode.CorrectLevel.M });
      qrDone = true;
    }
    qrBtn.textContent = show ? '📱 Hide QR Code' : '📱 Show QR Code';
  });

  var replayBtn = document.getElementById('nx-replay-btn');
  if (replayBtn) replayBtn.addEventListener('click', function () {
    if (typeof window.__nxReplayJourney === 'function') window.__nxReplayJourney();
  });

  // ---- Assistant ----
  var toggle = document.getElementById('nx-chat-toggle');
  var panel = document.getElementById('nx-chat-panel');
  var closeBtn = document.getElementById('nx-chat-close');
  var form = document.getElementById('nx-chat-form');
  var input = document.getElementById('nx-chat-input');
  var body = document.getElementById('nx-chat-body');
  if (toggle && panel) {
    toggle.addEventListener('click', function () { panel.classList.toggle('open'); if (panel.classList.contains('open') && input) input.focus(); });
    if (closeBtn) closeBtn.addEventListener('click', function () { panel.classList.remove('open'); });
  }
  function addMsg(text, cls) {
    var d = document.createElement('div');
    d.className = 'nx-chat-msg ' + cls;
    d.textContent = text;
    if (body) { body.appendChild(d); body.scrollTop = body.scrollHeight; }
  }
  if (form) form.addEventListener('submit', function (e) {
    e.preventDefault();
    var msg = (input.value || '').trim();
    if (!msg) return;
    addMsg(msg, 'user');
    input.value = '';
    addMsg('…', 'bot');
    var pending = body.lastChild;
    fetch('/api/chat.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ tracking: TRACKING, message: msg })
    }).then(function (r) { return r.json(); }).then(function (j) {
      pending.textContent = (j && j.reply) ? j.reply : 'Sorry, I could not answer that right now.';
      body.scrollTop = body.scrollHeight;
    }).catch(function () {
      pending.textContent = 'Sorry, the assistant is unavailable right now.';
    });
  });

  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/service-worker.js').catch(function () {});
  }
})();
</script>
<?php endif; ?>

<script>
(function(){
  var brandTerms = <?= json_encode(['Nexora Logistics','Global Shipping. Smarter Tracking.'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  var userLang = (navigator.language || 'en').toLowerCase();
  var btn = document.getElementById('nx-lang-btn');
  if (!btn) return;
  if (!userLang.startsWith('en')) btn.style.display = '';
  btn.addEventListener('click', function(){
    if (window.__nxGTloaded) return;
    window.__nxGTloaded = true;
    window.googleTranslateElementInit = function(){ new google.translate.TranslateElement({ pageLanguage:'en', autoDisplay:false, layout: google.translate.TranslateElement.InlineLayout.SIMPLE }, 'google_translate_element'); };
    var s = document.createElement('script'); s.src = 'https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit'; s.async = true; document.body.appendChild(s);
    btn.textContent = '🌐 Choose language';
  });
  setTimeout(function(){
    var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null);
    var n; while (n = walker.nextNode()) { for (var i=0;i<brandTerms.length;i++) { if (n.nodeValue && n.nodeValue.indexOf(brandTerms[i]) !== -1) { n.parentNode.classList.add('notranslate'); } } }
  }, 500);
})();
</script>

<script>
/* nx-visibility-handler */
(function(){
  var paused = false;
  document.addEventListener("visibilitychange", function(){
    if (document.hidden) {
      paused = true;
      try { window.__nxTimers.forEach(function(id){ clearInterval(id); }); } catch(e){}
    } else {
      paused = false;
      try { if (typeof tick === "function") tick(); } catch(e){}
    }
  });
})();
</script>

</body>
</html>
