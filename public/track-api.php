<?php
declare(strict_types=1);

/**
 * Public tracking data endpoint.
 *
 * Access rules (approved Phase 1):
 *   - Shipments WITH an email/phone on file require a verified tracker session
 *     (established by pre-track.php after email OR phone matches).
 *   - Shipments with NO contact on file are still readable, but flagged
 *     "unverified" so the customer page can show a warning.
 *
 * Movement: every customer poll advances the shipment via the tracking Engine,
 * so a shipment keeps moving even when the admin dashboard is closed.
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/app/Database.php';
require_once BASE_PATH . '/app/Tracking/Engine.php';

use VibrantHub\Tracking\Engine;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function nxApiOut(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$tracking = nxTrackingFromRequest();
if ($tracking === '') {
    nxApiOut(['status' => 'error', 'message' => 'Missing tracking number'], 400);
}

try {
    $pdo = nxPdo();

    $stmt = $pdo->prepare('SELECT * FROM shipments WHERE tracking_number = :t LIMIT 1');
    $stmt->execute([':t' => $tracking]);
    $shipment = $stmt->fetch();
    if (!$shipment) {
        nxApiOut(['status' => 'error', 'message' => 'Shipment not found'], 404);
    }

    $sid = (int) $shipment['id'];

    // ---- Access gate -------------------------------------------------------
    $hasContact = trim((string) $shipment['customer_email']) !== ''
        || trim((string) $shipment['customer_phone']) !== '';
    $tracker = nxTrackerVerified($tracking);
    $isAdminPreview = nxIsAdminSession();

    if ($hasContact && !$tracker && !$isAdminPreview) {
        nxApiOut([
            'status'  => 'error',
            'message' => 'Verification required',
            'code'    => 'verify_required',
        ], 403);
    }

    $trackerVerified = ($tracker && (int) ($tracker['verified'] ?? 0) === 1) ? 1 : 0;
    $isUnverified = (!$isAdminPreview && (!$hasContact || $trackerVerified === 0)) ? 1 : 0;

    // ---- Customer-driven movement -----------------------------------------
    nxMaybeTick($pdo, $sid, $shipment);

    // Reload after a possible tick.
    $stmt->execute([':t' => $tracking]);
    $shipment = $stmt->fetch();

    // ---- Related data ------------------------------------------------------
    $hs = $pdo->prepare("SELECT * FROM shipment_holds WHERE shipment_id = :s AND status = 'active' ORDER BY created_at DESC");
    $hs->execute([':s' => $sid]);
    $holds = $hs->fetchAll();

    $es = $pdo->prepare('SELECT id,status,title,description,location,event_time,event_type,latitude,longitude,icon,delay_minutes FROM tracking_events WHERE shipment_id = :s ORDER BY event_time DESC, id DESC LIMIT 20');
    $es->execute([':s' => $sid]);
    $events = $es->fetchAll();

    $ws = $pdo->prepare('SELECT id,sequence,name,address,latitude,longitude,waypoint_type,status,arrival_at,departure_at,public_label,public_note,leg_type FROM shipment_waypoints WHERE shipment_id = :s ORDER BY sequence ASC');
    $ws->execute([':s' => $sid]);
    $waypoints = $ws->fetchAll();

    $rs = $pdo->prepare('SELECT sequence,latitude,longitude,segment_type,encoded_polyline,waypoint_id FROM shipment_route_points WHERE shipment_id = :s ORDER BY sequence ASC');
    $rs->execute([':s' => $sid]);
    $routePoints = $rs->fetchAll();

    $ps = $pdo->prepare('SELECT latitude,longitude,speed,heading,recorded_at FROM tracking_positions WHERE shipment_id = :s ORDER BY recorded_at DESC, id DESC LIMIT 1');
    $ps->execute([':s' => $sid]);
    $lastPos = $ps->fetch() ?: null;

    // Full journey for the "Replay journey" feature (oldest → newest).
    $replayStmt = $pdo->prepare('SELECT latitude,longitude,speed,heading,recorded_at FROM tracking_positions WHERE shipment_id = :s ORDER BY recorded_at ASC, id ASC LIMIT 600');
    $replayStmt->execute([':s' => $sid]);
    $positions = $replayStmt->fetchAll();

    $cs = $pdo->prepare('SELECT is_running,control_speed,tracking_mode,current_route_sequence FROM tracking_controls WHERE shipment_id = :s LIMIT 1');
    $cs->execute([':s' => $sid]);
    $control = $cs->fetch() ?: null;

    // Translations (editable via admin)
    $trans = [];
    foreach ($pdo->query('SELECT raw_key, public_title, public_description, public_icon FROM event_translations') as $r) {
        $trans[strtolower($r['raw_key'])] = $r;
    }
    $events = array_values(array_map(function ($e) use ($trans) {
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
    $ms = $pdo->prepare('SELECT id, media_type, file_path, caption, created_at, context FROM shipment_media WHERE shipment_id = :s AND is_public = 1 ORDER BY sort_order ASC, id ASC');
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
        $cc->execute([':id' => (int) $shipment['carrier_id']]);
        $c = $cc->fetch();
        if ($c) {
            $c['logo_public'] = !empty($c['logo_path']) ? asset('carriers/' . basename($c['logo_path'])) : ($c['logo_url'] ?: null);
            $carrier = $c;
        }
    }

    // Proof of delivery (only meaningful once delivered / uploaded)
    $proof = null;
    try {
        $pf = $pdo->prepare('SELECT photo_path, signature_path, signed_by, note, delivered_at FROM proof_of_delivery WHERE shipment_id = :s LIMIT 1');
        $pf->execute([':s' => $sid]);
        $prow = $pf->fetch();
        if ($prow) {
            $prow['photo_url'] = !empty($prow['photo_path']) ? asset('media/' . $prow['photo_path']) : null;
            $prow['signature_url'] = !empty($prow['signature_path']) ? asset('signatures/' . $prow['signature_path']) : null;
            $proof = $prow;
        }
    } catch (Throwable $e) {
        $proof = null;
    }

    $total = (float) $shipment['distance_total'];
    $done  = (float) $shipment['distance_travelled'];
    $pct   = $total > 0 ? round(min(100, max(0, ($done / $total) * 100)), 2) : 0.0;

    $mandatoryHold = false;
    foreach ($holds as $h) {
        if ((int) $h['mandatory'] === 1) {
            $mandatoryHold = true;
            break;
        }
    }
    $isRunning = $control ? ((int) $control['is_running'] === 1) : false;

    $etaText = nxEtaText($shipment['distance_remaining'], $shipment['speed']);

    nxApiOut([
        'status' => 'success',
        'server_time_utc' => gmdate('c'),
        'tracker' => [
            'verified' => $trackerVerified,
            'unverified' => $isUnverified,
            'preview' => $isAdminPreview ? 1 : 0,
            'name' => $tracker ? (string) $tracker['name'] : (string) $shipment['customer_name'],
            'method' => $tracker ? (string) ($tracker['method'] ?? '') : '',
        ],
        'shipment' => [
            'tracking_number'    => $shipment['tracking_number'],
            'status'             => $shipment['status'],
            'customer_name'      => $shipment['customer_name'],
            'origin'             => $shipment['origin'],
            'destination'        => $shipment['destination'],
            'origin_lat'         => (float) $shipment['origin_lat'],
            'origin_lng'         => (float) $shipment['origin_lng'],
            'destination_lat'    => (float) $shipment['destination_lat'],
            'destination_lng'    => (float) $shipment['destination_lng'],
            'current_lat'        => (float) $shipment['current_lat'],
            'current_lng'        => (float) $shipment['current_lng'],
            'speed'              => (float) $shipment['speed'],
            'distance_total'     => $total,
            'distance_travelled' => $done,
            'distance_remaining' => (float) $shipment['distance_remaining'],
            'progress_pct'       => $pct,
            'transport_type'     => $shipment['transport_type'],
            'vehicle_name'       => $shipment['vehicle_name'],
            'vehicle_number'     => $shipment['vehicle_number'],
            'eta'                => $shipment['eta'],
            'eta_text'           => $etaText,
            'estimated_delivery' => $shipment['estimated_delivery'],
            'last_update'        => $shipment['last_update'],
            'updated_at'         => $shipment['updated_at'],
            'package_description'=> $shipment['package_description'],
            'created_at'         => $shipment['created_at'],
        ],
        'carrier' => $carrier,
        'control' => $control ? [
            'is_running'             => $isRunning,
            'control_speed'          => (float) $control['control_speed'],
            'current_route_sequence' => (int) $control['current_route_sequence'],
        ] : null,
        'holds' => array_map(static function ($h) {
            return [
                'id'         => (int) $h['id'],
                'hold_type'  => $h['hold_type'],
                'reason'     => $h['reason'],
                'details'    => $h['details'],
                'mandatory'  => (int) $h['mandatory'],
                'created_at' => $h['created_at'],
            ];
        }, $holds),
        'is_held'        => count($holds) > 0,
        'mandatory_hold' => $mandatoryHold,
        'events'    => $events,
        'waypoints' => $waypoints,
        'route'     => $routePoints,
        'positions' => $positions,
        'segment_messages' => $segMsgs,
        'media' => $media,
        'proof' => $proof,
        'settings' => [
            'ambient_speed'   => nxAmbientMode(),
            'map_style_url'   => nxMapStyleUrl(),
            'chatbot_enabled' => (string) nxSetting('chatbot_enabled', '1') === '1',
        ],
        'last_position' => $lastPos,
    ]);
} catch (Throwable $e) {
    nxApiOut(['status' => 'error', 'message' => 'Lookup failed'], 500);
}

/**
 * Advance a running shipment at most once every 2 seconds. Called on every
 * customer poll so movement no longer depends on the admin dashboard.
 */
function nxMaybeTick(PDO $pdo, int $shipmentId, array $shipment): void
{
    try {
        $ctrl = $pdo->prepare('SELECT is_running, control_speed FROM tracking_controls WHERE shipment_id = :s LIMIT 1');
        $ctrl->execute([':s' => $shipmentId]);
        $control = $ctrl->fetch();

        if (!$control || (int) $control['is_running'] !== 1) {
            return;
        }
        if ((float) $control['control_speed'] <= 0) {
            return;
        }

        $hold = $pdo->prepare("SELECT id FROM shipment_holds WHERE shipment_id = :s AND status = 'active' AND mandatory = 1 LIMIT 1");
        $hold->execute([':s' => $shipmentId]);
        if ($hold->fetchColumn()) {
            return;
        }

        // Throttle: ignore calls that arrive less than 2s after the last update.
        $last = isset($shipment['last_update']) ? (string) $shipment['last_update'] : '';
        if ($last !== '') {
            $lastTs = strtotime($last . ' UTC');
            if ($lastTs !== false && (time() - $lastTs) < 2) {
                return;
            }
        }

        $engine = new Engine($pdo);
        $engine->tick($shipmentId);
    } catch (Throwable $e) {
        // A failed tick must not break the read-only response.
    }
}
