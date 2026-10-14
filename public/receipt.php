<?php
declare(strict_types=1);

/**
 * Printable / downloadable shipment receipt.
 * Opens in a new tab; the "Print / Save as PDF" button uses the browser's
 * own print-to-PDF, so no paid library or service is required.
 */

require_once dirname(__DIR__) . '/config/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$tracking = nxTrackingFromRequest();
$shipment = null;
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

if ($tracking !== '' && $shipment && $hasContact && !nxIsAdminSession()) {
    $tracker = nxTrackerVerified($tracking);
    if (!$tracker || (int) ($tracker['verified'] ?? 0) !== 1) {
        header('Location: /pre-track.php?id=' . rawurlencode($tracking));
        exit;
    }
}

$events = [];
if ($shipment) {
    try {
        $es = $pdo->prepare('SELECT title, description, location, event_time FROM tracking_events WHERE shipment_id = :s ORDER BY event_time ASC, id ASC');
        $es->execute([':s' => (int) $shipment['id']]);
        $events = $es->fetchAll();
    } catch (Throwable $e) {
    }
    if (!empty($shipment['carrier_id'])) {
        try {
            $cc = $pdo->prepare('SELECT name FROM carriers WHERE id = :id LIMIT 1');
            $cc->execute([':id' => (int) $shipment['carrier_id']]);
            $carrier = $cc->fetch() ?: null;
        } catch (Throwable $e) {
        }
    }
}

$appName = (string) env('APP_NAME', 'Nexora Logistics');
function hh(?string $v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Receipt — <?= hh($shipment ? $shipment['tracking_number'] : $tracking) ?></title>
<style>
  * { box-sizing: border-box; }
  body { font-family: -apple-system, "Segoe UI", Arial, sans-serif; color: #172033; background: #f1f5f9; margin: 0; padding: 24px; }
  .sheet { max-width: 760px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 36px; box-shadow: 0 12px 34px rgba(11,29,58,.12); }
  .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #0b1d3a; padding-bottom: 16px; }
  .brand { font-size: 22px; font-weight: 800; color: #0b1d3a; }
  .brand span { color: #f0b429; }
  .muted { color: #64748b; font-size: 13px; }
  .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 14px; margin-top: 22px; }
  .cell .k { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: #94a3b8; }
  .cell .v { font-weight: 700; margin-top: 3px; }
  table { width: 100%; border-collapse: collapse; margin-top: 24px; font-size: 13px; }
  th, td { text-align: left; padding: 9px 10px; border-bottom: 1px solid #eef2f7; }
  th { background: #f8fafc; color: #64748b; font-size: 11px; text-transform: uppercase; }
  .actions { max-width: 760px; margin: 0 auto 14px; display: flex; gap: 10px; justify-content: flex-end; }
  .btn { border: 0; background: #0b1d3a; color: #fff; padding: 10px 18px; border-radius: 9px; font-weight: 700; cursor: pointer; font-size: 14px; text-decoration: none; }
  .btn.gold { background: linear-gradient(135deg,#f0b429,#ffd166); color: #0b1d3a; }
  .stamp { display: inline-block; padding: 5px 12px; border-radius: 999px; background: #eff8ff; color: #175cd3; font-weight: 700; font-size: 12px; text-transform: capitalize; }
  @media print { body { background: #fff; padding: 0; } .actions { display: none; } .sheet { box-shadow: none; border-radius: 0; border: 0; padding: 0; } }
</style>
</head>
<body>

<div class="actions">
  <button type="button" class="btn gold" onclick="window.print()">🖨️ Print / Save as PDF</button>
  <a class="btn" href="/track.php?id=<?= hh(rawurlencode($tracking)) ?>">← Back to live tracking</a>
</div>

<div class="sheet">
<?php if (!$shipment): ?>
  <h2>Shipment not found</h2>
  <p class="muted">No shipment matches tracking number <strong><?= hh($tracking) ?></strong>.</p>
<?php else: ?>
  <div class="head">
    <div>
      <div class="brand"><?= hh($appName) ?></div>
      <div class="muted">Global Shipping · Smarter Tracking</div>
    </div>
    <div style="text-align:right;">
      <div class="muted">Shipment receipt</div>
      <div style="font-size:20px;font-weight:800;"><?= hh($shipment['tracking_number']) ?></div>
      <div class="muted">Generated <?= hh(gmdate('Y-m-d H:i')) ?> UTC</div>
    </div>
  </div>

  <div style="margin-top:14px;"><span class="stamp"><?= hh(str_replace('_', ' ', $shipment['status'])) ?></span></div>

  <div class="grid">
    <div class="cell"><div class="k">Customer</div><div class="v"><?= hh($shipment['customer_name']) ?></div></div>
    <div class="cell"><div class="k">Origin</div><div class="v"><?= hh($shipment['origin']) ?></div></div>
    <div class="cell"><div class="k">Destination</div><div class="v"><?= hh($shipment['destination']) ?></div></div>
    <div class="cell"><div class="k">Transport</div><div class="v"><?= hh(ucfirst((string) $shipment['transport_type'])) ?><?= $shipment['vehicle_name'] ? ' · ' . hh($shipment['vehicle_name']) : '' ?></div></div>
    <div class="cell"><div class="k">Carrier</div><div class="v"><?= hh($carrier['name'] ?? '—') ?></div></div>
    <div class="cell"><div class="k">Package</div><div class="v"><?= hh($shipment['package_description'] ?: 'General cargo') ?></div></div>
    <div class="cell"><div class="k">Booked</div><div class="v"><?= hh((string) $shipment['created_at']) ?></div></div>
    <div class="cell"><div class="k">Estimated delivery</div><div class="v"><?= hh((string) ($shipment['estimated_delivery'] ?: ($shipment['eta'] ?: '—'))) ?></div></div>
    <div class="cell"><div class="k">Distance</div><div class="v"><?= hh((string) round((float) $shipment['distance_total'], 1)) ?> km total · <?= hh((string) round((float) $shipment['distance_remaining'], 1)) ?> km remaining</div></div>
    <div class="cell"><div class="k">Live ETA</div><div class="v"><?= hh(nxEtaText($shipment['distance_remaining'], $shipment['speed'])) ?></div></div>
  </div>

  <table>
    <thead><tr><th>Time (UTC)</th><th>Event</th><th>Location</th></tr></thead>
    <tbody>
      <?php if (!$events): ?>
        <tr><td colspan="3" class="muted">No tracking events recorded yet.</td></tr>
      <?php else: foreach ($events as $ev): ?>
        <tr>
          <td><?= hh($ev['event_time']) ?></td>
          <td><strong><?= hh($ev['title']) ?></strong><?= $ev['description'] ? '<br><span class="muted">' . hh($ev['description']) . '</span>' : '' ?></td>
          <td><?= hh($ev['location'] ?: '—') ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

  <p class="muted" style="margin-top:26px;">This computer-generated receipt summarises the shipment record held by <?= hh($appName) ?>. Verify live status at /track.php?id=<?= hh($shipment['tracking_number']) ?>.</p>
<?php endif; ?>
</div>

</body>
</html>
