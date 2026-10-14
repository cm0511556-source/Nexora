<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once BASE_PATH . '/app/Database.php';
require_once BASE_PATH . '/app/Auth.php';

use VibrantHub\Auth;
use VibrantHub\Database;

Auth::requireAdmin();

header('Content-Type: application/json; charset=utf-8');

function respond(
    bool $success,
    string $message,
    array $data = [],
    int $code = 200
): void {
    http_response_code($code);

    echo json_encode(
        [
            'success' => $success,
            'message' => $message,
            'data' => $data
        ],
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(false, 'POST request required.', [], 405);
    }

    if (!verifyCsrf(isset($_POST["csrf_token"]) ? (string)$_POST["csrf_token"] : null)) {
    respond(false, "Invalid or expired security token. Refresh the page and try again.", [], 403);
}

$shipmentId = filter_input(
        INPUT_POST,
        'shipment_id',
        FILTER_VALIDATE_INT
    );

    $action = trim((string)($_POST['action'] ?? ''));

    if (!$shipmentId || $shipmentId < 1) {
        respond(false, 'Invalid shipment ID.', [], 400);
    }

    $allowedActions = [
        'start',
        'pause',
        'resume',
        'speed',
        'mode',
        'save_position',
        'event',
        'complete'
    ];

    if (!in_array($action, $allowedActions, true)) {
        respond(false, 'Invalid tracking action.', [], 400);
    }

    $db = Database::connection();

    $shipmentStmt = $db->prepare(
        'SELECT *
         FROM shipments
         WHERE id = :id
         LIMIT 1'
    );

    $shipmentStmt->execute([
        ':id' => $shipmentId
    ]);

    $shipment = $shipmentStmt->fetch();

    if (!$shipment) {
        respond(false, 'Shipment not found.', [], 404);
    }

    $controlStmt = $db->prepare(
        'SELECT *
         FROM tracking_controls
         WHERE shipment_id = :shipment_id
         LIMIT 1'
    );

    $controlStmt->execute([
        ':shipment_id' => $shipmentId
    ]);

    $control = $controlStmt->fetch();

    if (!$control) {
        $db->prepare(
            'INSERT INTO tracking_controls (
                shipment_id,
                is_running,
                control_speed,
                tracking_mode
            ) VALUES (
                :shipment_id,
                0,
                :speed,
                :mode
            )'
        )->execute([
            ':shipment_id' => $shipmentId,
            ':speed' => (float)$shipment['speed'],
            ':mode' => (string)$shipment['tracking_mode']
        ]);

        $controlStmt->execute([
            ':shipment_id' => $shipmentId
        ]);

        $control = $controlStmt->fetch();
    }

    if (!$control) {
        respond(false, 'Unable to initialize tracking control.', [], 500);
    }

    $createdEventId = null;
    $db->beginTransaction();

    try {
        switch ($action) {

            case 'start':
            case 'resume':

                $holdCheck = $db->prepare("SELECT hold_type, reason FROM shipment_holds WHERE shipment_id = :id AND status = 'active' AND mandatory = 1 LIMIT 1");
                $holdCheck->execute([":id" => $shipmentId]);
                $activeHold = $holdCheck->fetch(PDO::FETCH_ASSOC);

                if ($activeHold) {
                    respond(false, "Tracking cannot start or resume: an active mandatory hold must be formally cleared first.", ["hold_type" => $activeHold['hold_type']], 409);
                }

                $speed = (float)$control['control_speed'];

                if ($speed <= 0) {
                    respond(
                        false,
                        'Set a speed greater than zero before starting tracking.',
                        [],
                        422
                    );
                }

                if (
                    in_array(
                        strtolower((string)$shipment['status']),
                        ['delivered', 'cancelled'],
                        true
                    )
                ) {
                    respond(
                        false,
                        'This shipment cannot be started in its current status.',
                        [],
                        422
                    );
                }

                $db->prepare(
                    'UPDATE tracking_controls
                     SET is_running = 1,
                         paused_reason = NULL,
                         last_started_at = CURRENT_TIMESTAMP,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE shipment_id = :shipment_id'
                )->execute([
                    ':shipment_id' => $shipmentId
                ]);

                $db->prepare(
                    'UPDATE shipments
                     SET status = :status,
                         speed = :speed,
                         last_update = CURRENT_TIMESTAMP,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id'
                )->execute([
                    ':status' => 'in_transit',
                    ':speed' => $speed,
                    ':id' => $shipmentId
                ]);

                $title = $action === 'start'
                    ? 'Shipment tracking started'
                    : 'Shipment tracking resumed';

                $db->prepare(
                    'INSERT INTO tracking_events (
                        shipment_id,
                        status,
                        title,
                        description,
                        location
                    ) VALUES (
                        :shipment_id,
                        :status,
                        :title,
                        :description,
                        :location
                    )'
                )->execute([
                    ':shipment_id' => $shipmentId,
                    ':status' => 'in_transit',
                    ':title' => $title,
                    ':description' => 'Shipment movement tracking is active.',
                    ':location' => $shipment['origin']
                ]);

                break;

            case 'pause':

                $reason = trim(
                    (string)($_POST['reason'] ?? 'Admin paused tracking')
                );

                if ($reason === '') {
                    $reason = 'Admin paused tracking';
                }

                $db->prepare(
                    'UPDATE tracking_controls
                     SET is_running = 0,
                         paused_reason = :reason,
                         last_paused_at = CURRENT_TIMESTAMP,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE shipment_id = :shipment_id'
                )->execute([
                    ':reason' => $reason,
                    ':shipment_id' => $shipmentId
                ]);

                $db->prepare(
                    'UPDATE shipments
                     SET speed = 0,
                         status = :status,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id'
                )->execute([
                    ':status' => 'on_hold',
                    ':id' => $shipmentId
                ]);

                $db->prepare(
                    'INSERT INTO tracking_events (
                        shipment_id,
                        status,
                        title,
                        description,
                        location
                    ) VALUES (
                        :shipment_id,
                        :status,
                        :title,
                        :description,
                        :location
                    )'
                )->execute([
                    ':shipment_id' => $shipmentId,
                    ':status' => 'on_hold',
                    ':title' => 'Shipment tracking paused',
                    ':description' => $reason,
                    ':location' => $shipment['destination']
                ]);

                break;

            case 'speed':
                $rawSpeed = $_POST['speed'] ?? null;

                if ($rawSpeed === null || $rawSpeed === '' || !is_numeric($rawSpeed)) {
                    respond(false, 'Enter a valid numeric speed.', [], 422);
                }

                $speed = (float)$rawSpeed;

                if ($speed < 0 || $speed > 2000) {
                    respond(false, 'Speed must be between 0 and 2000 km/h.', [], 422);
                }

                $update = $db->prepare(
                    'UPDATE tracking_controls
                     SET control_speed = :speed,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE shipment_id = :shipment_id'
                );
                $update->execute([
                    ':speed' => $speed,
                    ':shipment_id' => $shipmentId
                ]);

                if ($update->rowCount() === 0) {
                    $check = $db->prepare(
                        'SELECT 1 FROM tracking_controls WHERE shipment_id = :id LIMIT 1'
                    );
                    $check->execute([':id' => $shipmentId]);
                    if (!$check->fetchColumn()) {
                        respond(false, 'Tracking controls were not found.', [], 404);
                    }
                }

                $db->prepare(
                    'UPDATE shipments
                     SET speed = :speed, updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id'
                )->execute([':speed' => $speed, ':id' => $shipmentId]);

                break;
            case 'mode':

                $mode = trim(
                    (string)($_POST['mode'] ?? '')
                );

                $allowedModes = [
                    'simulation',
                    'gps'
                ];

                if (!in_array($mode, $allowedModes, true)) {
                    respond(false, 'Invalid tracking mode.', [], 422);
                }

                $db->prepare(
                    'UPDATE tracking_controls
                     SET tracking_mode = :mode,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE shipment_id = :shipment_id'
                )->execute([
                    ':mode' => $mode,
                    ':shipment_id' => $shipmentId
                ]);

                $db->prepare(
                    'UPDATE shipments
                     SET tracking_mode = :mode,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id'
                )->execute([
                    ':mode' => $mode,
                    ':id' => $shipmentId
                ]);

                break;

            case 'save_position':

                $latitude = filter_input(
                    INPUT_POST,
                    'latitude',
                    FILTER_VALIDATE_FLOAT
                );

                $longitude = filter_input(
                    INPUT_POST,
                    'longitude',
                    FILTER_VALIDATE_FLOAT
                );

                $speed = filter_input(
                    INPUT_POST,
                    'speed',
                    FILTER_VALIDATE_FLOAT
                );

                if (
                    $latitude === false ||
                    $latitude === null ||
                    $longitude === false ||
                    $longitude === null
                ) {
                    respond(false, 'Invalid latitude or longitude.', [], 422);
                }

                if (
                    $latitude < -90 ||
                    $latitude > 90 ||
                    $longitude < -180 ||
                    $longitude > 180
                ) {
                    respond(false, 'Coordinates are outside valid geographic ranges.', [], 422);
                }

                if (
                    $speed === false ||
                    $speed === null ||
                    $speed < 0
                ) {
                    respond(false, 'Invalid speed.', [], 422);
                }

                $mode = trim(
                    (string)($_POST['mode'] ?? $shipment['tracking_mode'])
                );

                if (!in_array($mode, ['simulation', 'gps'], true)) {
                    respond(false, 'Invalid tracking mode.', [], 422);
                }

                $db->prepare(
                    'UPDATE shipments
                     SET current_lat = :latitude,
                         current_lng = :longitude,
                         speed = :speed,
                         tracking_mode = :mode,
                         last_update = CURRENT_TIMESTAMP,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id'
                )->execute([
                    ':latitude' => $latitude,
                    ':longitude' => $longitude,
                    ':speed' => $speed,
                    ':mode' => $mode,
                    ':id' => $shipmentId
                ]);

                $db->prepare(
                    'UPDATE tracking_controls
                     SET control_speed = :speed,
                         tracking_mode = :mode,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE shipment_id = :shipment_id'
                )->execute([
                    ':speed' => $speed,
                    ':mode' => $mode,
                    ':shipment_id' => $shipmentId
                ]);

                break;

            case 'event':

                $eventType = trim(
                    (string)($_POST['event_type'] ?? '')
                );

                $location = trim(
                    (string)($_POST['location'] ?? '')
                );

                $description = trim(
                    (string)($_POST['description'] ?? '')
                );

                $latitudeRaw = trim(
                    (string)($_POST['latitude'] ?? '')
                );

                $longitudeRaw = trim(
                    (string)($_POST['longitude'] ?? '')
                );

                $pausesTracking = filter_var(
                    $_POST['pauses_tracking'] ?? false,
                    FILTER_VALIDATE_BOOLEAN
                ) ? 1 : 0;

                $delayMinutes = filter_var(
                    $_POST['delay_minutes'] ?? 0,
                    FILTER_VALIDATE_INT
                );

                if ($delayMinutes === false || $delayMinutes < 0) {
                    respond(false, 'Invalid delay duration.', [], 422);
                }

                $icon = trim(
                    (string)($_POST['icon'] ?? 'event')
                );

                $allowedEventTypes = [
                    'picked_up',
                    'processing',
                    'departed',
                    'in_transit',
                    'checkpoint',
                    'customs',
                    'on_hold',
                    'delayed',
                    'route_changed',
                    'weather_delay',
                    'transport_issue',
                    'facility_issue',
                    'out_for_delivery',
                    'delivered',
                    'custom'
                ];

                if (!in_array($eventType, $allowedEventTypes, true)) {
                    respond(false, 'Invalid operational event type.', [], 422);
                }

                if ($description === '') {
                    respond(false, 'Event description is required.', [], 422);
                }

                $latitude = null;
                $longitude = null;

                if ($latitudeRaw !== '' || $longitudeRaw !== '') {
                    if ($latitudeRaw === '' || $longitudeRaw === '') {
                        respond(false, 'Both latitude and longitude are required when setting an event position.', [], 422);
                    }

                    if (!is_numeric($latitudeRaw) || !is_numeric($longitudeRaw)) {
                        respond(false, 'Invalid event coordinates.', [], 422);
                    }

                    $latitude = (float)$latitudeRaw;
                    $longitude = (float)$longitudeRaw;

                    if (
                        $latitude < -90 ||
                        $latitude > 90 ||
                        $longitude < -180 ||
                        $longitude > 180
                    ) {
                        respond(false, 'Event coordinates are outside valid geographic ranges.', [], 422);
                    }
                }

                if ($latitude === null || $longitude === null) {
                    $latitude = (float)$shipment['current_lat'];
                    $longitude = (float)$shipment['current_lng'];
                }

                $titleMap = [
                    'picked_up' => 'Shipment picked up',
                    'processing' => 'Shipment processing',
                    'departed' => 'Shipment departed',
                    'in_transit' => 'Shipment in transit',
                    'checkpoint' => 'Checkpoint reached',
                    'customs' => 'Customs processing',
                    'on_hold' => 'Shipment placed on hold',
                    'delayed' => 'Shipment delayed',
                    'route_changed' => 'Route changed',
                    'weather_delay' => 'Weather delay',
                    'transport_issue' => 'Transport issue',
                    'facility_issue' => 'Facility issue',
                    'out_for_delivery' => 'Out for delivery',
                    'delivered' => 'Shipment delivered',
                    'custom' => 'Shipment update'
                ];

                $statusMap = [
                    'picked_up' => 'picked_up',
                    'processing' => 'processing',
                    'departed' => 'departed',
                    'in_transit' => 'in_transit',
                    'checkpoint' => 'checkpoint',
                    'customs' => 'customs',
                    'on_hold' => 'on_hold',
                    'delayed' => 'delayed',
                    'route_changed' => 'route_changed',
                    'weather_delay' => 'weather_delay',
                    'transport_issue' => 'transport_issue',
                    'facility_issue' => 'facility_issue',
                    'out_for_delivery' => 'out_for_delivery',
                    'delivered' => 'delivered',
                    'custom' => 'custom'
                ];

                $db->prepare(
                    'INSERT INTO tracking_events (
                        shipment_id,
                        status,
                        title,
                        description,
                        location,
                        event_type,
                        latitude,
                        longitude,
                        pauses_tracking,
                        delay_minutes,
                        icon
                    ) VALUES (
                        :shipment_id,
                        :status,
                        :title,
                        :description,
                        :location,
                        :event_type,
                        :latitude,
                        :longitude,
                        :pauses_tracking,
                        :delay_minutes,
                        :icon
                    )'
                )->execute([
                    ':shipment_id' => $shipmentId,
                    ':status' => $statusMap[$eventType],
                    ':title' => $titleMap[$eventType],
                    ':description' => $description,
                    ':location' => $location !== ''
                        ? $location
                        : $shipment['destination'],
                    ':event_type' => $eventType,
                    ':latitude' => $latitude,
                    ':longitude' => $longitude,
                    ':pauses_tracking' => $pausesTracking,
                    ':delay_minutes' => $delayMinutes,
                    ':icon' => $icon !== '' ? $icon : 'event'
                ]);
                $createdEventId = (int) $db->lastInsertId();

                if ($pausesTracking === 1) {
                    $db->prepare(
                        'UPDATE tracking_controls
                         SET is_running = 0,
                             paused_reason = :reason,
                             last_paused_at = CURRENT_TIMESTAMP,
                             updated_at = CURRENT_TIMESTAMP
                         WHERE shipment_id = :shipment_id'
                    )->execute([
                        ':reason' => $description,
                        ':shipment_id' => $shipmentId
                    ]);

                    $db->prepare(
                        'UPDATE shipments
                         SET current_lat = :latitude,
                             current_lng = :longitude,
                             speed = 0,
                             status = :status,
                             last_update = CURRENT_TIMESTAMP,
                             updated_at = CURRENT_TIMESTAMP
                         WHERE id = :id'
                    )->execute([
                        ':latitude' => $latitude,
                        ':longitude' => $longitude,
                        ':status' => $statusMap[$eventType],
                        ':id' => $shipmentId
                    ]);
                }

                break;
            case 'complete':

                $db->prepare(
                    'UPDATE tracking_controls
                     SET is_running = 0,
                         control_speed = 0,
                         paused_reason = NULL,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE shipment_id = :shipment_id'
                )->execute([
                    ':shipment_id' => $shipmentId
                ]);

                $db->prepare(
                    'UPDATE shipments
                     SET current_lat = destination_lat,
                         current_lng = destination_lng,
                         speed = 0,
                         distance_remaining = 0,
                         distance_travelled = distance_total,
                         status = :status,
                         eta = NULL,
                         last_update = CURRENT_TIMESTAMP,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id'
                )->execute([
                    ':status' => 'delivered',
                    ':id' => $shipmentId
                ]);

                $db->prepare(
                    'INSERT INTO tracking_events (
                        shipment_id,
                        status,
                        title,
                        description,
                        location
                    ) VALUES (
                        :shipment_id,
                        :status,
                        :title,
                        :description,
                        :location
                    )'
                )->execute([
                    ':shipment_id' => $shipmentId,
                    ':status' => 'delivered',
                    ':title' => 'Shipment marked delivered',
                    ':description' => 'Shipment has been marked as delivered at the final destination.',
                    ':location' => $shipment['destination']
                ]);

                break;
        }

        $db->commit();

    } catch (Throwable $e) {

        if ($db->inTransaction()) {
            $db->rollBack();
        }

        throw $e;
    }

    $fresh = $db->prepare(
        'SELECT
            s.id,
            s.tracking_number,
            s.current_lat,
            s.current_lng,
            s.speed,
            s.status,
            s.distance_total,
            s.distance_travelled,
            s.distance_remaining,
            s.eta,
            s.tracking_mode,
            c.is_running,
            c.control_speed,
            c.paused_reason
         FROM shipments s
         LEFT JOIN tracking_controls c
           ON c.shipment_id = s.id
         WHERE s.id = :id
         LIMIT 1'
    );

    $fresh->execute([
        ':id' => $shipmentId
    ]);

    $responseData = $fresh->fetch() ?: [];

    if ($action === 'event' && $createdEventId !== null) {
        $createdEventStmt = $db->prepare(
            'SELECT * FROM tracking_events WHERE id = :id LIMIT 1'
        );
        $createdEventStmt->execute([':id' => $createdEventId]);
        $createdEvent = $createdEventStmt->fetch();

        if ($createdEvent) {
            $responseData['event'] = $createdEvent;
        }
    }

    nxAudit('tracking.' . $action, 'shipment', $shipmentId);

    respond(
        true,
        'Tracking control updated.',
        $responseData
    );

} catch (Throwable $e) {

    respond(
        false,
        'Unable to update tracking control.',
        [],
        500
    );
}
