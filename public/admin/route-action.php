<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once BASE_PATH . '/app/Database.php';
require_once BASE_PATH . '/app/Auth.php';
use VibrantHub\Auth; use VibrantHub\Database;

Auth::requireAdmin();
header('Content-Type: application/json; charset=utf-8');

function respond(bool $ok, string $msg, array $data = [], int $code = 200): never {
    http_response_code($code);
    echo json_encode(['success' => $ok, 'message' => $msg, 'data' => $data], JSON_UNESCAPED_SLASHES);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(false, 'POST required.', [], 405);
if (!verifyCsrf($_POST['csrf_token'] ?? null)) respond(false, 'Invalid token.', [], 403);

$action = trim((string)($_POST['action'] ?? ''));
$shipmentId = (int)($_POST['shipment_id'] ?? 0);
if ($shipmentId < 1) respond(false, 'Invalid shipment.', [], 400);

$db = Database::connection();
$s = $db->prepare('SELECT id FROM shipments WHERE id = :id LIMIT 1');
$s->execute([':id' => $shipmentId]);
if (!$s->fetch()) respond(false, 'Shipment not found.', [], 404);

$allowedLegs = ['air','sea','rail','truck','van','bike','ped'];
$allowedTypes = ['origin','airport','port','rail_hub','warehouse','office','depot','home','checkpoint','waypoint','destination'];

try {
    if ($action === 'add') {
        $name = trim((string)($_POST['name'] ?? ''));
        $lat = $_POST['lat'] ?? null; $lng = $_POST['lng'] ?? null;
        $type = trim((string)($_POST['waypoint_type'] ?? 'waypoint'));
        $leg = trim((string)($_POST['leg_type'] ?? 'truck'));
        $address = trim((string)($_POST['address'] ?? ''));
        $pl = trim((string)($_POST['public_label'] ?? ''));
        $pn = trim((string)($_POST['public_note'] ?? ''));
        if ($name === '') respond(false, 'Name required.', [], 400);
        if (!is_numeric($lat) || !is_numeric($lng)) respond(false, 'Lat/lng required.', [], 400);
        if (!in_array($type, $allowedTypes, true)) $type = 'waypoint';
        if (!in_array($leg, $allowedLegs, true)) $leg = 'truck';
        $q = $db->prepare('SELECT COALESCE(MAX(sequence), -1) + 1 FROM shipment_waypoints WHERE shipment_id = :sid');
        $q->execute([':sid' => $shipmentId]);
        $nextSeq = (int)$q->fetchColumn();
        $db->prepare('INSERT INTO shipment_waypoints (shipment_id, sequence, name, address, latitude, longitude, waypoint_type, status, leg_type, public_label, public_note) VALUES (:sid,:seq,:n,:a,:lat,:lng,:t,"pending",:l,:pl,:pn)')
           ->execute([':sid'=>$shipmentId, ':seq'=>$nextSeq, ':n'=>$name, ':a'=>$address ?: null, ':lat'=>(float)$lat, ':lng'=>(float)$lng, ':t'=>$type, ':l'=>$leg, ':pl'=>$pl ?: null, ':pn'=>$pn ?: null]);
        respond(true, 'Waypoint added.', ['id' => (int)$db->lastInsertId()]);
    }

    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id < 1) respond(false, 'Invalid ID.', [], 400);
        $name = trim((string)($_POST['name'] ?? ''));
        $lat = $_POST['lat'] ?? null; $lng = $_POST['lng'] ?? null;
        $type = trim((string)($_POST['waypoint_type'] ?? 'waypoint'));
        $leg = trim((string)($_POST['leg_type'] ?? 'truck'));
        $address = trim((string)($_POST['address'] ?? ''));
        $pl = trim((string)($_POST['public_label'] ?? ''));
        $pn = trim((string)($_POST['public_note'] ?? ''));
        if ($name === '') respond(false, 'Name required.', [], 400);
        if (!is_numeric($lat) || !is_numeric($lng)) respond(false, 'Lat/lng required.', [], 400);
        if (!in_array($type, $allowedTypes, true)) $type = 'waypoint';
        if (!in_array($leg, $allowedLegs, true)) $leg = 'truck';
        $db->prepare('UPDATE shipment_waypoints SET name=:n, address=:a, latitude=:lat, longitude=:lng, waypoint_type=:t, leg_type=:l, public_label=:pl, public_note=:pn, updated_at=CURRENT_TIMESTAMP WHERE id=:id AND shipment_id=:sid')
           ->execute([':n'=>$name, ':a'=>$address ?: null, ':lat'=>(float)$lat, ':lng'=>(float)$lng, ':t'=>$type, ':l'=>$leg, ':pl'=>$pl ?: null, ':pn'=>$pn ?: null, ':id'=>$id, ':sid'=>$shipmentId]);
        respond(true, 'Waypoint updated.');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id < 1) respond(false, 'Invalid ID.', [], 400);
        $db->prepare('DELETE FROM shipment_waypoints WHERE id=:id AND shipment_id=:sid')->execute([':id'=>$id, ':sid'=>$shipmentId]);
        respond(true, 'Waypoint removed.');
    }

    if ($action === 'reorder') {
        $order = (string)($_POST['order'] ?? '');
        $ids = array_filter(array_map('intval', explode(',', $order)));
        if (empty($ids)) respond(false, 'Empty order.', [], 400);
        $upd = $db->prepare('UPDATE shipment_waypoints SET sequence=:seq WHERE id=:id AND shipment_id=:sid');
        foreach ($ids as $i => $id) $upd->execute([':seq'=>$i, ':id'=>$id, ':sid'=>$shipmentId]);
        respond(true, 'Order saved.');
    }

    if ($action === 'rebuild') {
        $wps = $db->prepare('SELECT * FROM shipment_waypoints WHERE shipment_id = :sid ORDER BY sequence ASC');
        $wps->execute([':sid' => $shipmentId]);
        $waypoints = $wps->fetchAll();
        if (count($waypoints) < 2) respond(false, 'Need at least 2 waypoints.', [], 400);
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM shipment_route_points WHERE shipment_id = :sid')->execute([':sid' => $shipmentId]);
            $insert = $db->prepare('INSERT INTO shipment_route_points (shipment_id, sequence, latitude, longitude, segment_type, waypoint_id) VALUES (:sid, :seq, :lat, :lng, :seg, :wid)');
            $seq = 0;
            for ($i = 0; $i < count($waypoints) - 1; $i++) {
                $a = $waypoints[$i]; $b = $waypoints[$i + 1];
                $legType = $b['leg_type'] ?: 'truck';
                $steps = 20;
                $bulge = 0.06;
                for ($st = 0; $st <= $steps; $st++) {
                    $t = $st / $steps;
                    $lat = (float)$a['latitude'] + ((float)$b['latitude'] - (float)$a['latitude']) * $t;
                    $lng = (float)$a['longitude'] + ((float)$b['longitude'] - (float)$a['longitude']) * $t;
                    $dLat = (float)$b['latitude'] - (float)$a['latitude'];
                    $dLng = (float)$b['longitude'] - (float)$a['longitude'];
                    $lat += sin(M_PI * $t) * $bulge * (-$dLng * 0.05);
                    $lng += sin(M_PI * $t) * $bulge * ($dLat * 0.05);
                    $wid = ($st === 0) ? (int)$a['id'] : null;
                    $insert->execute([':sid'=>$shipmentId, ':seq'=>$seq++, ':lat'=>$lat, ':lng'=>$lng, ':seg'=>$legType, ':wid'=>$wid]);
                }
            }
            $last = $waypoints[count($waypoints) - 1];
            $insert->execute([':sid'=>$shipmentId, ':seq'=>$seq++, ':lat'=>$last['latitude'], ':lng'=>$last['longitude'], ':seg'=>$last['leg_type'] ?: 'truck', ':wid'=>$last['id']]);
            $db->commit();
            respond(true, 'Route rebuilt from ' . count($waypoints) . ' waypoints.');
        } catch (Throwable $e) {
            $db->rollBack();
            respond(false, 'Rebuild failed: ' . $e->getMessage(), [], 500);
        }
    }

    respond(false, 'Unknown action.', [], 400);
} catch (Throwable $e) {
    respond(false, 'Error: ' . $e->getMessage(), [], 500);
}
