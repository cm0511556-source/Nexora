<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/config.php';
require_once BASE_PATH . '/app/Database.php';
require_once BASE_PATH . '/app/Auth.php';
require_once BASE_PATH . '/app/Tracking/Engine.php';

use VibrantHub\Auth;
use VibrantHub\Database;
use VibrantHub\Tracking\Engine;

Auth::requireAdmin();

header('Content-Type: application/json; charset=utf-8');

try {
    $db = Database::connection();

    $shipmentId = filter_input(INPUT_POST, 'shipment_id', FILTER_VALIDATE_INT);
    $action = trim((string)($_POST['action'] ?? ''));

    if (!$shipmentId || $shipmentId < 1) {
        throw new RuntimeException('Invalid shipment ID.');
    }

    $allowedActions = [
        'start',
        'pause',
        'resume',
        'reset',
        'save_position',
        'event',
    ];

    if (!in_array($action, $allowedActions, true)) {
        throw new RuntimeException('Invalid tracking action.');
    }

    $shipmentStmt = $db->prepare(
        'SELECT * FROM shipments WHERE id = :id LIMIT 1'
    );
    $shipmentStmt->execute([':id' => $shipmentId]);
    $shipment = $shipmentStmt->fetch();

    if (!$shipment) {
        throw new RuntimeException('Shipment not found.');
    }

    $controlStmt = $db->prepare(
        'SELECT * FROM tracking_controls
         WHERE shipment_id = :shipment_id
         LIMIT 1'
    );
    $controlStmt->execute([':shipment_id' => $shipmentId]);
    $control = $controlStmt->fetch();

    if (!$control) {
        $create = $db->prepare(
            'INSERT INTO tracking_controls
             (shipment_id, is_running, control_speed, tracking_mode)
             VALUES (:shipment_id, 0, :speed, :mode)'
        );

        $create->execute([
            ':shipment_id' => $shipmentId,
            ':speed' => max(0, (float)$shipment['speed']),
            ':mode' => (string)$shipment['tracking_mode'],
        ]);

        $controlStmt->execute([':shipment_id' => $shipmentId]);
        $control = $controlStmt->fetch();
    }

    if (!$control) {
        throw new RuntimeException('Tracking control could not be initialized.');
    }

    /*
     * START / RESUME
     */
    if ($action === 'start' || $action === 'resume') {
        $speed = max(
            0.0,
            (float)($_POST['speed'] ?? $control['control_speed'])
        );

        if ($speed <= 0) {
            throw new RuntimeException(
                'Set a speed greater than 0 before starting tracking.'
            );
        }

        $mode = trim(
            (string)($_POST['tracking_mode'] ?? $control['tracking_mode'])
        );

        if (!in_array($mode, ['simulation', 'gps'], true)) {
            $mode = 'simulation';
        }

        $stmt = $db->prepare(
            'UPDATE tracking_controls
             SET is_running = 1,
                 control_speed = :speed,
                 tracking_mode = :mode,
                 paused_reason = NULL,
                 last_started_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE shipment_id = :shipment_id'
        );

        $stmt->execute([
            ':speed' => $speed,
            ':mode' => $mode,
            ':shipment_id' => $shipmentId,
        ]);

        $shipmentUpdate = $db->prepare(
            'UPDATE shipments
             SET speed = :speed,
                 status = :status,
                 tracking_mode = :mode,
                 last_update = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );

        $shipmentUpdate->execute([
            ':speed' => $speed,
            ':status' => 'in_transit',
            ':mode' => $mode,
            ':id' => $shipmentId,
        ]);

        $engine = new Engine($db);
        $state = $engine->tick($shipmentId);

        echo json_encode([
            'success' => true,
            'message' => $action === 'start'
                ? 'Tracking started.'
                : 'Tracking resumed.',
            'state' => $state,
        ], JSON_THROW_ON_ERROR);

        exit;
    }

    /*
     * PAUSE
     */
    if ($action === 'pause') {
        $reason = trim(
            (string)($_POST['reason'] ?? 'Paused by administrator.')
        );

        $stmt = $db->prepare(
            'UPDATE tracking_controls
             SET is_running = 0,
                 paused_reason = :reason,
                 last_paused_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE shipment_id = :shipment_id'
        );

        $stmt->execute([
            ':reason' => $reason !== '' ? $reason : 'Paused by administrator.',
            ':shipment_id' => $shipmentId,
        ]);

        $shipmentUpdate = $db->prepare(
            'UPDATE shipments
             SET speed = 0,
                 status = :status,
                 last_update = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );

        $shipmentUpdate->execute([
            ':status' => 'on_hold',
            ':id' => $shipmentId,
        ]);

        $stateStmt = $db->prepare(
            'SELECT
                s.current_lat AS latitude,
                s.current_lng AS longitude,
                s.speed,
                s.distance_total,
                s.distance_travelled,
                s.distance_remaining,
                s.eta,
                s.status,
                s.tracking_mode,
                tc.is_running
             FROM shipments s
             LEFT JOIN tracking_controls tc
               ON tc.shipment_id = s.id
             WHERE s.id = :id
             LIMIT 1'
        );

        $stateStmt->execute([':id' => $shipmentId]);
        $state = $stateStmt->fetch();

        echo json_encode([
            'success' => true,
            'message' => 'Tracking paused.',
            'state' => $state,
        ], JSON_THROW_ON_ERROR);

        exit;
    }

    /*
     * RESET
     */
    if ($action === 'reset') {
        $db->beginTransaction();

        try {
            $resetShipment = $db->prepare(
                'UPDATE shipments
                 SET current_lat = origin_lat,
                     current_lng = origin_lng,
                     speed = 0,
                     distance_travelled = 0,
                     distance_remaining = distance_total,
                     status = :status,
                     eta = NULL,
                     last_update = CURRENT_TIMESTAMP,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );

            $resetShipment->execute([
                ':status' => 'pending',
                ':id' => $shipmentId,
            ]);

            $resetControl = $db->prepare(
                'UPDATE tracking_controls
                 SET is_running = 0,
                     current_waypoint_id = NULL,
                     next_waypoint_id = NULL,
                     paused_reason = NULL,
                     last_started_at = NULL,
                     last_paused_at = CURRENT_TIMESTAMP,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE shipment_id = :shipment_id'
            );

            $resetControl->execute([
                ':shipment_id' => $shipmentId,
            ]);

            $resetWaypoints = $db->prepare(
                'UPDATE shipment_waypoints
                 SET status = :status,
                     arrival_at = NULL,
                     departure_at = NULL,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE shipment_id = :shipment_id'
            );

            $resetWaypoints->execute([
                ':status' => 'pending',
                ':shipment_id' => $shipmentId,
            ]);

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }

        $engine = new Engine($db);
        $state = $engine->tick($shipmentId);

        echo json_encode([
            'success' => true,
            'message' => 'Tracking reset to the origin.',
            'state' => $state,
        ], JSON_THROW_ON_ERROR);

        exit;
    }

    /*
     * SAVE POSITION / SPEED
     */
    if ($action === 'save_position') {
        $lat = filter_input(INPUT_POST, 'latitude', FILTER_VALIDATE_FLOAT);
        $lng = filter_input(INPUT_POST, 'longitude', FILTER_VALIDATE_FLOAT);
        $speed = filter_input(INPUT_POST, 'speed', FILTER_VALIDATE_FLOAT);

        if ($lat === false || $lat === null || $lat < -90 || $lat > 90) {
            throw new RuntimeException('Invalid latitude.');
        }

        if ($lng === false || $lng === null || $lng < -180 || $lng > 180) {
            throw new RuntimeException('Invalid longitude.');
        }

        $speed = ($speed === false || $speed === null)
            ? (float)$control['control_speed']
            : max(0.0, (float)$speed);

        $mode = trim(
            (string)($_POST['tracking_mode'] ?? $control['tracking_mode'])
        );

        if (!in_array($mode, ['simulation', 'gps'], true)) {
            $mode = 'simulation';
        }

        $stmt = $db->prepare(
            'UPDATE shipments
             SET current_lat = :lat,
                 current_lng = :lng,
                 speed = :speed,
                 tracking_mode = :mode,
                 last_update = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );

        $stmt->execute([
            ':lat' => (float)$lat,
            ':lng' => (float)$lng,
            ':speed' => $speed,
            ':mode' => $mode,
            ':id' => $shipmentId,
        ]);

        $controlUpdate = $db->prepare(
            'UPDATE tracking_controls
             SET control_speed = :speed,
                 tracking_mode = :mode,
                 updated_at = CURRENT_TIMESTAMP
             WHERE shipment_id = :shipment_id'
        );

        $controlUpdate->execute([
            ':speed' => $speed,
            ':mode' => $mode,
            ':shipment_id' => $shipmentId,
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Position and speed saved.',
            'state' => [
                'latitude' => (float)$lat,
                'longitude' => (float)$lng,
                'speed' => $speed,
                'tracking_mode' => $mode,
            ],
        ], JSON_THROW_ON_ERROR);

        exit;
    }

    /*
     * OPERATIONAL EVENT
     */
    if ($action === 'event') {
        $eventType = trim((string)($_POST['event_type'] ?? ''));
        $location = trim((string)($_POST['location'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));

        $eventTitles = [
            'customs' => 'Customs Clearance',
            'inspection' => 'Inspection',
            'weather' => 'Weather Delay',
            'traffic' => 'Traffic Delay',
            'maintenance' => 'Vehicle Maintenance',
            'other' => 'Operational Event',
        ];

        if (!isset($eventTitles[$eventType])) {
            throw new RuntimeException('Select a valid event type.');
        }

        if ($description === '') {
            throw new RuntimeException('Enter an event description.');
        }

        $event = $db->prepare(
            'INSERT INTO tracking_events
             (shipment_id, status, title, description, location)
             VALUES
             (:shipment_id, :status, :title, :description, :location)'
        );

        $event->execute([
            ':shipment_id' => $shipmentId,
            ':status' => 'operational',
            ':title' => $eventTitles[$eventType],
            ':description' => $description,
            ':location' => $location !== '' ? $location : null,
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Operational event recorded.',
        ], JSON_THROW_ON_ERROR);

        exit;
    }

    throw new RuntimeException('Action could not be processed.');

} catch (\Throwable $e) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    exit;
}
