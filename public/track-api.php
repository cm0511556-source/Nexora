<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Cache-Control: no-store');

function jsonOut(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$tracking = trim((string)($_GET['tracking'] ?? ''));
if ($tracking === '') {
    jsonOut(['status' => 'error', 'message' => 'Missing tracking number'], 400);
}

try {
    $dbPath = dirname(__DIR__) . '/database/database.sqlite';
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $stmt = $pdo->prepare('SELECT * FROM shipments WHERE tracking_number = :t LIMIT 1');
    $stmt->execute([':t' => $tracking]);
    $shipment = $stmt->fetch();
    if (!$shipment) jsonOut(['status' => 'error', 'message' => 'Shipment not found'], 404);

    $sid = (int)$shipment['id'];

    $hs = $pdo->prepare("SELECT * FROM shipment_holds WHERE shipment_id = :s AND status = 'active' ORDER BY created_at DESC");
    $hs->execute([':s' => $sid]);
    $holds = $hs->fetchAll();

    $es = $pdo->prepare('SELECT id,status,title,description,location,event_time,event_type,latitude,longitude,icon,delay_minutes FROM tracking_events WHERE shipment_id = :s ORDER BY event_time DESC, id DESC LIMIT 20');
    $es->execute([':s' => $sid]);
    $events = $es->fetchAll();

    $ws = $pdo->prepare('SELECT id,sequence,name,address,latitude,longitude,waypoint_type,status,arrival_at,departure_at,public_label,public_note FROM shipment_waypoints WHERE shipment_id = :s ORDER BY sequence ASC');
    $ws->execute([':s' => $sid]);
    $waypoints = $ws->fetchAll();

    $rs = $pdo->prepare('SELECT sequence,latitude,longitude,segment_type,waypoint_id FROM shipment_route_points WHERE shipment_id = :s ORDER BY sequence ASC');
    $rs->execute([':s' => $sid]);
    $routePoints = $rs->fetchAll();

    $ps = $pdo->prepare('SELECT latitude,longitude,speed,heading,recorded_at FROM tracking_positions WHERE shipment_id = :s ORDER BY recorded_at DESC, id DESC LIMIT 1');
    $ps->execute([':s' => $sid]);
    $lastPos = $ps->fetch() ?: null;

    $cs = $pdo->prepare('SELECT is_running,control_speed,tracking_mode,current_route_sequence FROM tracking_controls WHERE shipment_id = :s LIMIT 1');
    $cs->execute([':s' => $sid]);
    $control = $cs->fetch() ?: null;

    // Translations (editable via admin)
    $trans = [];
    foreach ($pdo->query('SELECT raw_key, public_title, public_description, public_icon FROM event_translations') as $r) {
        $trans[strtolower($r['raw_key'])] = $r;
    }
    $events = array_values(array_map(function($e) use ($trans) {
        $key = strtolower(trim($e['title'] ?? ''));
        if (isset($trans[$key])) {
            $e['title'] = $trans[$key]['public_title'];
            if (!empty($trans[$key]['public_description'])) {
                $e['description'] = $trans[$key]['public_description'];
            }
            if (!empty($trans[$key]['public_icon'])) {
                $e['icon'] = $trans[$key]['public_icon'];
            }
        }
        return $e;
    }, $events));

    // Public media (photos + videos)
    $ms = $pdo->prepare('SELECT id, media_type, file_path, caption, created_at FROM shipment_media WHERE shipment_id = :s AND is_public = 1 ORDER BY sort_order ASC, id ASC');
    $ms->execute([':s' => $sid]);
    $media = $ms->fetchAll();

    // Segment messages for map popups
    $segMsgs = [];
    foreach ($pdo->query('SELECT segment_type, public_title, public_description, icon FROM segment_messages') as $r) {
        $segMsgs[$r['segment_type']] = $r;
    }

    // Carrier (if assigned)
    $carrier = null;
    if (!empty($shipment['carrier_id'])) {
        $cc = $pdo->prepare('SELECT id,name,code,logo_url,logo_path,contact_email,contact_phone,tracking_url FROM carriers WHERE id = :id LIMIT 1');
        $cc->execute([':id' => (int)$shipment['carrier_id']]);
        $c = $cc->fetch();
        if ($c) {
            $c['logo_public'] = !empty($c['logo_path']) ? '/assets/carriers/' . basename($c['logo_path']) : ($c['logo_url'] ?: null);
            $carrier = $c;
        }
    }

    $total = (float)$shipment['distance_total'];
    $done  = (float)$shipment['distance_travelled'];
    $pct   = $total > 0 ? round(min(100, max(0, ($done / $total) * 100)), 2) : 0.0;

    $mandatoryHold = false;
    foreach ($holds as $h) { if ((int)$h['mandatory'] === 1) { $mandatoryHold = true; break; } }
    $isRunning = $control ? ((int)$control['is_running'] === 1) : false;

    jsonOut([
        'status' => 'success',
        'server_time_utc' => gmdate('c'),
        'shipment' => [
            'tracking_number'   => $shipment['tracking_number'],
            'status'            => $shipment['status'],
            'customer_name'     => $shipment['customer_name'],
            'origin'            => $shipment['origin'],
            'destination'       => $shipment['destination'],
            'origin_lat'        => (float)$shipment['origin_lat'],
            'origin_lng'        => (float)$shipment['origin_lng'],
            'destination_lat'   => (float)$shipment['destination_lat'],
            'destination_lng'   => (float)$shipment['destination_lng'],
            'current_lat'       => (float)$shipment['current_lat'],
            'current_lng'       => (float)$shipment['current_lng'],
            'speed'             => (float)$shipment['speed'],
            'distance_total'    => $total,
            'distance_travelled'=> $done,
            'distance_remaining'=> (float)$shipment['distance_remaining'],
            'progress_pct'      => $pct,
            'transport_type'    => $shipment['transport_type'],
            'vehicle_name'      => $shipment['vehicle_name'],
            'vehicle_number'    => $shipment['vehicle_number'],
            'eta'               => $shipment['eta'],
            'estimated_delivery'=> $shipment['estimated_delivery'],
            'last_update'       => $shipment['last_update'],
            'updated_at'        => $shipment['updated_at'],
        ],
        'carrier' => $carrier,
        'control' => $control ? [
            'is_running'      => $isRunning,
            'control_speed'   => (float)$control['control_speed'],
            'current_route_sequence' => (int)$control['current_route_sequence'],
        ] : null,
        'holds' => array_map(static fn($h) => [
            'id'          => (int)$h['id'],
            'hold_type'   => $h['hold_type'],
            'reason'      => $h['reason'],
            'details'     => $h['details'],
            'mandatory'   => (int)$h['mandatory'],
            'created_at'  => $h['created_at'],
        ], $holds),
        'is_held'        => count($holds) > 0,
        'mandatory_hold' => $mandatoryHold,
        'events'    => $events,
        'waypoints' => $waypoints,
        'route'     => $routePoints,
        'segment_messages' => $segMsgs,
        'media' => $media,
        'last_position' => $lastPos,
    ]);

} catch (Throwable $e) {
    jsonOut(['status' => 'error', 'message' => 'Lookup failed'], 500);
}
