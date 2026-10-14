<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once BASE_PATH . '/app/Database.php';
require_once BASE_PATH . '/app/Auth.php';

use VibrantHub\Auth;
use VibrantHub\Database;

Auth::requireAdmin();

header('Content-Type: application/json; charset=utf-8');

function respond(bool $success, string $message, array $data = [], int $code = 200): void {
    http_response_code($code);
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data], JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(false, 'POST required.', [], 405);
    }
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        respond(false, 'Invalid or expired security token.', [], 403);
    }

    $shipmentId = filter_input(INPUT_POST, 'shipment_id', FILTER_VALIDATE_INT);
    $incident   = trim((string)($_POST['incident'] ?? ''));
    $delayValue = (float)($_POST['delay_value'] ?? 0);
    $delayUnit  = strtolower(trim((string)($_POST['delay_unit'] ?? 'minutes')));
    $note       = trim((string)($_POST['note'] ?? ''));

    if (!$shipmentId || $shipmentId < 1) {
        respond(false, 'Invalid shipment ID.', [], 400);
    }

    $allowed = ['customs_inspection','security_screening','seized','compliance_issue','weather_delay','breakdown','damaged','port_congestion','out_for_delivery','delivered','clear_hold'];
    if (!in_array($incident, $allowed, true)) {
        respond(false, 'Unknown incident.', [], 400);
    }

    // Convert delay to minutes
    $unitMap = ['minutes' => 1, 'hours' => 60, 'days' => 1440, 'weeks' => 10080, 'months' => 43200];
    if (!isset($unitMap[$delayUnit])) $delayUnit = 'minutes';
    $delayMinutes = max(0, (int)round($delayValue * $unitMap[$delayUnit]));

    $db = Database::connection();

    $s = $db->prepare('SELECT * FROM shipments WHERE id = :id LIMIT 1');
    $s->execute([':id' => $shipmentId]);
    $shipment = $s->fetch();
    if (!$shipment) respond(false, 'Shipment not found.', [], 404);

    // Incident → behavior map
    $map = [
        'customs_inspection' => ['hold' => 'customs',    'mandatory' => 1, 'pauses' => 1, 'type' => 'compliance', 'icon' => 'shield',  'title' => 'Customs inspection in progress'],
        'security_screening' => ['hold' => 'security',   'mandatory' => 1, 'pauses' => 1, 'type' => 'security',   'icon' => 'shield',  'title' => 'Security screening in progress'],
        'seized'             => ['hold' => 'compliance', 'mandatory' => 1, 'pauses' => 1, 'type' => 'seized',     'icon' => 'alert',   'title' => 'Shipment seized by authority'],
        'compliance_issue'   => ['hold' => 'compliance', 'mandatory' => 1, 'pauses' => 1, 'type' => 'compliance', 'icon' => 'alert',   'title' => 'Compliance issue'],
        'weather_delay'      => ['hold' => null,         'mandatory' => 0, 'pauses' => 0, 'type' => 'delay',      'icon' => 'cloud',   'title' => 'Weather delay'],
        'breakdown'          => ['hold' => 'other',      'mandatory' => 0, 'pauses' => 1, 'type' => 'delay',      'icon' => 'wrench',  'title' => 'Vehicle breakdown'],
        'damaged'            => ['hold' => null,         'mandatory' => 0, 'pauses' => 0, 'type' => 'damaged',    'icon' => 'alert',   'title' => 'Damaged packaging reported'],
        'port_congestion'    => ['hold' => null,         'mandatory' => 0, 'pauses' => 0, 'type' => 'delay',      'icon' => 'clock',   'title' => 'Port congestion'],
        'out_for_delivery'   => ['hold' => null,         'mandatory' => 0, 'pauses' => 0, 'type' => 'milestone','icon' => 'truck',   'title' => 'Out for delivery'],
        'delivered'          => ['hold' => null,         'mandatory' => 0, 'pauses' => 0, 'type' => 'delivered','icon' => 'check',   'title' => 'Shipment delivered'],
    ];

    $db->beginTransaction();
    try {
        if ($incident === 'clear_hold') {
            $db->prepare("UPDATE shipment_holds SET status='cleared', cleared_at=CURRENT_TIMESTAMP, cleared_by=:by, updated_at=CURRENT_TIMESTAMP WHERE shipment_id=:id AND status='active'")
               ->execute([':by' => $_SESSION['admin_id'] ?? null, ':id' => $shipmentId]);

            $db->prepare('INSERT INTO tracking_events (shipment_id,status,title,description,event_type,icon,event_time) VALUES (:id,:st,:t,:d,:et,:ic,CURRENT_TIMESTAMP)')
               ->execute([':id'=>$shipmentId, ':st'=>$shipment['status'], ':t'=>'Hold cleared', ':d'=>'Shipment released for further movement.', ':et'=>'milestone', ':ic'=>'check']);

            $db->commit();
            respond(true, 'Hold cleared.');
        }

        $cfg = $map[$incident];

        // Create hold if configured
        if ($cfg['hold'] !== null) {
            $db->prepare('INSERT INTO shipment_holds (shipment_id,hold_type,reason,details,status,mandatory,created_by,created_at,updated_at) VALUES (:sid,:ht,:rs,:dt,"active",:md,:by,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)')
               ->execute([
                   ':sid'=>$shipmentId, ':ht'=>$cfg['hold'],
                   ':rs'=>$cfg['title'], ':dt'=>$note !== '' ? $note : null,
                   ':md'=>$cfg['mandatory'], ':by'=>$_SESSION['admin_id'] ?? null
               ]);
        }

        // Pause tracking if the incident dictates it
        if ($cfg['pauses'] === 1) {
            $db->prepare('UPDATE tracking_controls SET is_running=0, paused_reason=:r, last_paused_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE shipment_id=:id')
               ->execute([':r'=>$cfg['title'], ':id'=>$shipmentId]);
        }

        // Status change
        $newStatus = $shipment['status'];
        if ($incident === 'delivered') $newStatus = 'delivered';
        elseif ($incident === 'seized') $newStatus = 'seized';
        elseif ($cfg['hold'] !== null)  $newStatus = 'on_hold';

        // ETA extension
        if ($delayMinutes > 0 && !empty($shipment['estimated_delivery'])) {
            $db->prepare("UPDATE shipments SET estimated_delivery = datetime(estimated_delivery, '+' || :m || ' minutes'), updated_at=CURRENT_TIMESTAMP WHERE id=:id")
               ->execute([':m'=>$delayMinutes, ':id'=>$shipmentId]);
        }

        $db->prepare('UPDATE shipments SET status=:st, last_update=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=:id')
           ->execute([':st'=>$newStatus, ':id'=>$shipmentId]);

        // Timeline event
        $desc = $note !== '' ? $note : null;
        if ($delayMinutes > 0) {
            $desc = trim(($desc ? $desc . ' · ' : '') . 'Delay: ' . $delayMinutes . ' minute(s)');
        }
        $db->prepare('INSERT INTO tracking_events (shipment_id,status,title,description,event_type,icon,pauses_tracking,delay_minutes,event_time) VALUES (:sid,:st,:t,:d,:et,:ic,:pt,:dm,CURRENT_TIMESTAMP)')
           ->execute([
               ':sid'=>$shipmentId, ':st'=>$newStatus, ':t'=>$cfg['title'],
               ':d'=>$desc, ':et'=>$cfg['type'], ':ic'=>$cfg['icon'],
               ':pt'=>$cfg['pauses'], ':dm'=>$delayMinutes
           ]);

        $db->commit();
        respond(true, 'Incident recorded.', ['status' => $newStatus, 'delay_minutes' => $delayMinutes]);
    } catch (Throwable $e) {
        $db->rollBack();
        respond(false, 'Failed to record incident.', [], 500);
    }

} catch (Throwable $e) {
    respond(false, 'Request failed.', [], 500);
}
