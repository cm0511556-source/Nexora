<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once BASE_PATH . '/app/Database.php';
require_once BASE_PATH . '/app/Auth.php';

use VibrantHub\Auth;
use VibrantHub\Database;

Auth::requireAdmin();

$db = Database::connection();

$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));

$allowedStatuses = [
    'pending',
    'in_transit',
    'delayed',
    'on_hold',
    'held',
    'seized',
    'delivered',
    'cancelled'
];

$sql = "
    SELECT
        s.id,
        s.tracking_number,
        s.customer_name,
        s.customer_email,
        s.customer_phone,
        s.transport_type,
        s.vehicle_name,
        s.vehicle_number,
        s.origin,
        s.destination,
        s.origin_lat,
        s.origin_lng,
        s.destination_lat,
        s.destination_lng,
        s.current_lat,
        s.current_lng,
        s.speed,
        s.distance_total,
        s.distance_travelled,
        s.distance_remaining,
        s.status,
        s.eta,
        s.estimated_delivery,
        s.tracking_mode,
        s.updated_at,
        COALESCE(tc.is_running, 0) AS is_running,
        COALESCE(tc.control_speed, 0) AS control_speed, s.carrier_id, cr.name AS carrier_name, cr.code AS carrier_code, cr.logo_path AS carrier_logo_path, cr.logo_url AS carrier_logo_url
    FROM shipments s
    LEFT JOIN tracking_controls tc ON tc.shipment_id = s.id
    LEFT JOIN carriers cr ON cr.id = s.carrier_id
    WHERE 1 = 1
";

$params = [];

if ($search !== '') {
    $sql .= "
        AND (
            s.tracking_number LIKE :search
            OR s.customer_name LIKE :search
            OR s.customer_email LIKE :search
            OR s.origin LIKE :search
            OR s.destination LIKE :search
        )
    ";

    $params['search'] = '%' . $search . '%';
}

if (in_array($statusFilter, $allowedStatuses, true)) {
    $sql .= " AND s.status = :status";
    $params['status'] = $statusFilter;
}

$sql .= " ORDER BY s.updated_at DESC, s.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$shipments = $stmt->fetchAll();

$countStmt = $db->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status = 'in_transit' THEN 1 ELSE 0 END) AS in_transit,
        SUM(CASE WHEN status = 'on_hold' THEN 1 ELSE 0 END) AS on_hold,
        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS delivered,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled
    FROM shipments
");

$counts = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function transportIcon(string $type, string $vehicleName = ''): string {
    $v = strtolower($vehicleName . ' ' . $type);
    if (preg_match('/air|plane|flight|jet|aircraft/', $v)) return '✈️';
    if (preg_match('/sea|ship|boat|vessel|container|cargo/', $v)) return '🚢';
    if (preg_match('/rail|train/', $v)) return '🚆';
    if (preg_match('/bike|motorcycle|scooter|courier/', $v)) return '🏍️';
    if (preg_match('/van|last.?mile|delivery/', $v)) return '📦';
    if (preg_match('/truck|lorry|road|vehicle/', $v)) return '🚚';
    return '🚚';
}

function transportLabel(string $type, string $vehicleName = ''): string {
    if ($vehicleName !== '') return $vehicleName;
    return ucwords(str_replace('_', ' ', $type));
}

function statusLabel(string $status): string
{
    switch (strtolower($status)) {
        case 'in_transit':
            return 'In Transit';
        case 'on_hold':
            return 'On Hold';
        case 'delivered':
            return 'Delivered';
        case 'cancelled':
            return 'Cancelled';
        default:
            return 'Pending';
    }
}

function statusClass(string $status): string
{
    switch (strtolower($status)) {
        case 'in_transit':
            return 'status-info';
        case 'delivered':
            return 'status-success';
        case 'on_hold':
            return 'status-warning';
        case 'cancelled':
            return 'status-danger';
        default:
            return 'status-muted';
    }
}

function formatDistance(float $distance): string
{
    if ($distance <= 0) {
        return '0 km';
    }

    return number_format($distance, 2) . ' km';
}

function formatSpeed(float $speed): string
{
    return number_format(max(0, $speed), 1) . ' km/h';
}

$admin = Auth::admin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shipment Management | Nexora Logistics</title>

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

        .page {
            max-width: 1450px;
            margin: 0 auto;
            padding: 24px;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 24px;
        }

        .title h1 {
            margin: 0;
            font-size: 28px;
        }

        .title p {
            margin: 7px 0 0;
            color: #64748b;
        }

        .back {
            display: inline-block;
            padding: 11px 16px;
            border-radius: 10px;
            background: #0f172a;
            color: #fff;
            text-decoration: none;
            font-weight: 700;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 14px;
            margin-bottom: 22px;
        }

        .stat {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 18px;
            box-shadow: 0 4px 14px rgba(15, 23, 42, .04);
        }

        .stat-label {
            color: #64748b;
            font-size: 13px;
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 26px;
            font-weight: 800;
        }

        .toolbar {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 18px;
        }

        .filters {
            display: grid;
            grid-template-columns: minmax(220px, 1fr) 180px auto auto;
            gap: 10px;
        }

        input,
        select {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 12px 13px;
            font-size: 14px;
            background: #fff;
        }

        button,
        .button {
            border: 0;
            border-radius: 10px;
            padding: 12px 16px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .primary {
            background: #2563eb;
            color: #fff;
        }

        .secondary {
            background: #e2e8f0;
            color: #0f172a;
        }

        .table-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 4px 14px rgba(15, 23, 42, .04);
        }

        .table-header {
            padding: 18px 20px;
            border-bottom: 1px solid #e2e8f0;
        }

        .table-header h2 {
            margin: 0;
            font-size: 18px;
        }

        .table-header p {
            margin: 5px 0 0;
            color: #64748b;
            font-size: 13px;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1100px;
        }

        th,
        td {
            padding: 15px 14px;
            border-bottom: 1px solid #e2e8f0;
            text-align: left;
            vertical-align: middle;
            font-size: 13px;
        }

        th {
            background: #f8fafc;
            color: #475569;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        tr:last-child td {
            border-bottom: 0;
        }

        .tracking {
            font-weight: 800;
            color: #2563eb;
            text-decoration: none;
        }

        .customer {
            font-weight: 700;
        }

        .muted {
            color: #64748b;
            font-size: 12px;
            margin-top: 4px;
        }

        .route {
            min-width: 180px;
        }

        .route-line {
            display: flex;
            gap: 7px;
            align-items: center;
            margin: 3px 0;
        }

        .status {
            display: inline-flex;
            align-items: center;
            padding: 6px 9px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
            white-space: nowrap;
        }

        .status-info {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .status-success {
            background: #dcfce7;
            color: #15803d;
        }

        .status-warning {
            background: #fef3c7;
            color: #a16207;
        }

        .status-danger {
            background: #fee2e2;
            color: #b91c1c;
        }

        .status-muted {
            background: #e2e8f0;
            color: #475569;
        }

        .running {
            color: #16a34a;
            font-weight: 800;
        }

        .stopped {
            color: #64748b;
            font-weight: 700;
        }

        .actions {
            display: flex;
            gap: 7px;
            flex-wrap: wrap;
        }

        .action {
            padding: 8px 10px;
            border-radius: 8px;
            background: #e2e8f0;
            color: #0f172a;
            text-decoration: none;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .action.primary-action {
            background: #2563eb;
            color: #fff;
        }

        .empty {
            padding: 55px 20px;
            text-align: center;
            color: #64748b;
        }

        @media (max-width: 900px) {
            .stats {
                grid-template-columns: repeat(2, 1fr);
            }

            .filters {
                grid-template-columns: 1fr;
            }

            .topbar {
                align-items: flex-start;
                flex-direction: column;
            }

            .page {
                padding: 15px;
            }
        }

        @media (max-width: 520px) {
            .stats {
                grid-template-columns: 1fr;
            }

            .title h1 {
                font-size: 23px;
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

    <div class="topbar">
        <div class="title">
            <h1>Shipment Management</h1>
            <p>
                Manage shipments, tracking IDs, live movement and customer deliveries.
            </p>
        </div>

        <a class="back" href="/admin/index.php">← Dashboard</a>
    </div>

    <div class="stats">
        <div class="stat">
            <div class="stat-label">Total Shipments</div>
            <div class="stat-value"><?= (int)($counts['total'] ?? 0) ?></div>
        </div>

        <div class="stat">
            <div class="stat-label">Pending</div>
            <div class="stat-value"><?= (int)($counts['pending'] ?? 0) ?></div>
        </div>

        <div class="stat">
            <div class="stat-label">In Transit</div>
            <div class="stat-value"><?= (int)($counts['in_transit'] ?? 0) ?></div>
        </div>

        <div class="stat">
            <div class="stat-label">On Hold</div>
            <div class="stat-value"><?= (int)($counts['on_hold'] ?? 0) ?></div>
        </div>

        <div class="stat">
            <div class="stat-label">Delivered</div>
            <div class="stat-value"><?= (int)($counts['delivered'] ?? 0) ?></div>
        </div>
    </div>

    <div class="toolbar">
        <form method="get" class="filters">

            <input
                type="search"
                name="search"
                value="<?= h($search) ?>"
                placeholder="Search tracking ID, customer, origin or destination..."
            >

            <select name="status">
                <option value="">All statuses</option>
                <?php foreach ($allowedStatuses as $statusOption): ?>
                    <option
                        value="<?= h($statusOption) ?>"
                        <?= $statusFilter === $statusOption ? 'selected' : '' ?>
                    >
                        <?= h(statusLabel($statusOption)) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button class="primary" type="submit">Search</button>

            <a class="button secondary" href="/admin/shipment-management.php">
                Clear
            </a>

        </form>
    </div>

    <div class="table-card">

        <div class="table-header">
            <h2>All Shipments</h2>
            <p><?= count($shipments) ?> shipment(s) currently shown</p>
        </div>

        <?php if (!$shipments): ?>

            <div class="empty">
                <strong>No shipments found.</strong>
                <div style="margin-top:6px;">
                    Try another search or create a new shipment.
                </div>
            </div>

        <?php else: ?>

            <div class="table-wrap">

                <table>

                    <thead>
                    <tr>
                        <th>Tracking ID</th>
                        <th>Customer</th>
                        <th>Route</th>
                        <th>Transport</th>
                        <th>Status</th>
                        <th>Movement</th>
                        <th>Distance</th>
                        <th>Updated</th>
                        <th>Actions</th>
                    </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($shipments as $shipment): ?>

                        <?php
                        $id = (int)$shipment['id'];
                        $status = (string)$shipment['status'];
                        $isRunning = (int)$shipment['is_running'] === 1;
                        ?>

                        <tr>

                            <td>
                                <a
                                    class="tracking"
                                    href="/admin/shipment-control.php?id=<?= $id ?>"
                                >
                                    <?= h($shipment['tracking_number']) ?>
                                </a>

                                <div class="muted">
                                    ID #<?= $id ?>
                                </div>
                                    <?php if (!empty($shipment['carrier_name'])): ?>
                                        <?php $nxClogo = !empty($shipment['carrier_logo_path']) ? asset('carriers/' . rawurlencode($shipment['carrier_logo_path'])) : (!empty($shipment['carrier_logo_url']) ? $shipment['carrier_logo_url'] : null); ?>
                                        <div class="nx-carrier-chip" style="display:inline-flex;align-items:center;gap:.35rem;margin-top:.35rem;padding:.15rem .55rem;border-radius:999px;background:#f1f5f9;font-size:.72rem;font-weight:600;color:#475569;border:1px solid #e2e8f0;">
                                            <?php if ($nxClogo): ?><img src="<?= h($nxClogo) ?>" alt="" style="width:14px;height:14px;border-radius:3px;object-fit:cover;"><?php endif; ?>
                                            <?= h($shipment['carrier_name']) ?><?php if (!empty($shipment['carrier_code'])): ?> · <?= h($shipment['carrier_code']) ?><?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                            </td>

                            <td>
                                <div class="customer">
                                    <?= h($shipment['customer_name']) ?>
                                </div>

                                <?php if (!empty($shipment['customer_email'])): ?>
                                    <div class="muted">
                                        <?= h($shipment['customer_email']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td class="route">

                                <div class="route-line">
                                    <span>📍</span>
                                    <span><?= h($shipment['origin']) ?></span>
                                </div>

                                <div class="route-line">
                                    <span>🏁</span>
                                    <span><?= h($shipment['destination']) ?></span>
                                </div>

                            </td>

                            <td>
                                <div style="font-size:20px;">
                                    <?= h(transportIcon((string)$shipment['transport_type'], (string)($shipment['vehicle_name'] ?? ''))) ?>
                                </div>

                                <div class="muted">
                                    <?= h(transportLabel((string)$shipment['transport_type'], (string)($shipment['vehicle_name'] ?? ''))) ?>
                                </div>

                                <?php if (!empty($shipment['vehicle_name'])): ?>
                                    <div class="muted">
                                        <?= h($shipment['vehicle_name']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="status <?= h(statusClass($status)) ?>">
                                    <?= h(statusLabel($status)) ?>
                                </span>
                            </td>

                            <td>

                                <?php if ($isRunning): ?>

                                    <div class="running">
                                        ● Live
                                    </div>

                                    <div class="muted">
                                        <?= h(formatSpeed((float)$shipment['control_speed'])) ?>
                                    </div>

                                <?php else: ?>

                                    <div class="stopped">
                                        ● Stopped
                                    </div>

                                    <div class="muted">
                                        <?= h(formatSpeed((float)$shipment['speed'])) ?>
                                    </div>

                                <?php endif; ?>

                            </td>

                            <td>

                                <div>
                                    <?= h(formatDistance((float)$shipment['distance_travelled'])) ?>
                                    travelled
                                </div>

                                <div class="muted">
                                    <?= h(formatDistance((float)$shipment['distance_remaining'])) ?>
                                    remaining
                                </div>

                            </td>

                            <td>
                                <div>
                                    <?= h((string)$shipment['updated_at']) ?>
                                </div>

                                <?php if (!empty($shipment['eta'])): ?>
                                    <div class="muted">
                                        ETA: <?= h((string)$shipment['eta']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td>

                                <div class="actions">

                                    <a
                                        class="action primary-action"
                                        href="/admin/shipment-control.php?id=<?= $id ?>"
                                    >
                                        🎛️ Controls
                                    </a>

                                    <a
                                        class="action"
                                        href="/track.php?id=<?= h(rawurlencode($shipment['tracking_number'])) ?>"
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        👁️ View as Customer
                                    </a>

                                    <a
                                        class="action"
                                        href="/admin/proof-of-delivery.php?id=<?= $id ?>"
                                    >
                                        🧾 Proof
                                    </a>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>

</div>

<script>
// Icon priority: vehicle_name first, then transport_type
function nxShipmentEmoji(vehicle, transport) {
  var label = ((vehicle||"") + " " + (transport||"")).toLowerCase();
  if (/air|plane|flight|jet|aircraft/.test(label)) return "✈️";
  if (/sea|ship|boat|vessel|container|cargo/.test(label)) return "🚢";
  if (/rail|train/.test(label)) return "🚆";
  if (/bike|motorcycle|scooter|courier/.test(label)) return "🏍️";
  if (/van|last.?mile|delivery/.test(label)) return "📦";
  if (/truck|lorry|road|vehicle/.test(label)) return "🚚";
  return "🚚";
}
document.addEventListener("DOMContentLoaded", function(){
  document.querySelectorAll("[data-nx-veh]").forEach(function(el){
    var parts = el.getAttribute("data-nx-veh").split("|");
    el.textContent = nxShipmentEmoji(parts[0] || "", parts[1] || "") + " " + (parts[0] || parts[1] || "");
  });
});
</script>
</body>
</html>
