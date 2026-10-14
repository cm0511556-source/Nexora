<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once BASE_PATH . '/app/Database.php';
require_once BASE_PATH . '/app/Auth.php';

use VibrantHub\Auth;
use VibrantHub\Database;

header('Content-Type: application/json; charset=utf-8');
Auth::requireAdmin();
Auth::startSession();

function holdResponse(bool $success, string $message, array $data = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $db = Database::connection();
    $admin = Auth::admin();

    if (!$admin) {
        holdResponse(false, 'Administrator authentication required.', [], 401);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $shipmentId = filter_input(INPUT_GET, 'shipment_id', FILTER_VALIDATE_INT);

        if (!$shipmentId || $shipmentId < 1) {
            holdResponse(false, 'A valid shipment ID is required.', [], 422);
        }

        $stmt = $db->prepare(
            'SELECT id, shipment_id, hold_type, reason, details, status,
                    mandatory, created_at, cleared_at, clearance_note
             FROM shipment_holds
             WHERE shipment_id = :shipment_id
             ORDER BY id DESC'
        );
        $stmt->execute(['shipment_id' => $shipmentId]);

        holdResponse(true, 'Shipment holds retrieved.', [
            'holds' => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: GET, POST');
        holdResponse(false, 'Method not allowed.', [], 405);
    }

    $token = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null;

    if (!verifyCsrf($token)) {
        holdResponse(false, 'Invalid or expired security token. Refresh and try again.', [], 403);
    }

    $action = trim((string) ($_POST['action'] ?? ''));
    $shipmentId = filter_var($_POST['shipment_id'] ?? null, FILTER_VALIDATE_INT);

    if (!$shipmentId || $shipmentId < 1) {
        holdResponse(false, 'A valid shipment ID is required.', [], 422);
    }

    $checkShipment = $db->prepare('SELECT id, status FROM shipments WHERE id = :id LIMIT 1');
    $checkShipment->execute(['id' => $shipmentId]);
    $shipment = $checkShipment->fetch(PDO::FETCH_ASSOC);

    if (!$shipment) {
        holdResponse(false, 'Shipment not found.', [], 404);
    }

    if ($action === 'create') {
        $allowedTypes = ['customs', 'safety', 'security', 'compliance', 'other'];
        $holdType = trim((string) ($_POST['hold_type'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $details = trim((string) ($_POST['details'] ?? ''));
        $mandatory = ($_POST['mandatory'] ?? '1') === '1' ? 1 : 0;

        if (!in_array($holdType, $allowedTypes, true)) {
            holdResponse(false, 'Invalid hold type.', [], 422);
        }

        if ($reason === '' || strlen($reason) > 500) {
            holdResponse(false, 'A reason of 1–500 characters is required.', [], 422);
        }

        if (strlen($details) > 3000) {
            holdResponse(false, 'Details must not exceed 3000 characters.', [], 422);
        }

        $stmt = $db->prepare(
            'INSERT INTO shipment_holds
                (shipment_id, hold_type, reason, details, status, mandatory, created_by)
             VALUES
                (:shipment_id, :hold_type, :reason, :details, \'active\', :mandatory, :created_by)'
        );
        $stmt->execute([
            'shipment_id' => $shipmentId,
            'hold_type' => $holdType,
            'reason' => $reason,
            'details' => $details !== '' ? $details : null,
            'mandatory' => $mandatory,
            'created_by' => (int) $admin['id']
        ]);

        $holdId = (int) $db->lastInsertId();

        if ($mandatory === 1) {
            $db->beginTransaction();

            try {
                $controlStmt = $db->prepare(
                    'UPDATE tracking_controls
                     SET is_running = 0,
                         control_speed = 0,
                         paused_reason = :reason,
                         last_paused_at = CURRENT_TIMESTAMP,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE shipment_id = :shipment_id'
                );
                $controlStmt->execute([
                    'reason' => 'Mandatory hold: ' . $reason,
                    'shipment_id' => $shipmentId
                ]);

                $shipmentStmt = $db->prepare(
                    'UPDATE shipments
                     SET speed = 0,
                         status = \'on_hold\',
                         last_update = CURRENT_TIMESTAMP,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id
                       AND LOWER(status) NOT IN (\'delivered\', \'cancelled\')'
                );
                $shipmentStmt->execute(['id' => $shipmentId]);

                $eventStmt = $db->prepare(
                    'INSERT INTO tracking_events
                        (shipment_id, status, title, description, location, event_type, pauses_tracking)
                     VALUES
                        (:shipment_id, \'on_hold\', :title, :description, :location, \'on_hold\', 1)'
                );
                $eventStmt->execute([
                    'shipment_id' => $shipmentId,
                    'title' => 'Shipment placed on hold',
                    'description' => 'Shipment movement is paused pending required review.',
                    'location' => $shipment['status']
                ]);

                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }
        }

        holdResponse(true, 'Hold created.', ['hold_id' => $holdId], 201);
    }

    if ($action === 'clear') {
        $holdId = filter_var($_POST['hold_id'] ?? null, FILTER_VALIDATE_INT);
        $clearanceNote = trim((string) ($_POST['clearance_note'] ?? ''));

        if (!$holdId || $holdId < 1) {
            holdResponse(false, 'A valid hold ID is required.', [], 422);
        }

        if ($clearanceNote === '' || mb_strlen($clearanceNote) > 2000) {
            holdResponse(false, 'A clearance note of 1–2000 characters is required.', [], 422);
        }

        $db->beginTransaction();

        try {
            $stmt = $db->prepare(
                'UPDATE shipment_holds
                 SET status = \'cleared\',
                     cleared_at = CURRENT_TIMESTAMP,
                     cleared_by = :admin_id,
                     clearance_note = :note,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :hold_id
                   AND shipment_id = :shipment_id
                   AND status = \'active\''
            );
            $stmt->execute([
                'admin_id' => (int) $admin['id'],
                'note' => $clearanceNote,
                'hold_id' => $holdId,
                'shipment_id' => $shipmentId
            ]);

            if ($stmt->rowCount() !== 1) {
                $db->rollBack();
                holdResponse(false, 'Active hold not found for this shipment.', [], 404);
            }

            $remainingStmt = $db->prepare(
                'SELECT COUNT(*)
                 FROM shipment_holds
                 WHERE shipment_id = :shipment_id
                   AND status = \'active\'
                   AND mandatory = 1'
            );
            $remainingStmt->execute(['shipment_id' => $shipmentId]);
            $remainingMandatory = (int) $remainingStmt->fetchColumn();

            if ($remainingMandatory === 0) {
                $controlStmt = $db->prepare(
                    'UPDATE tracking_controls
                     SET paused_reason = NULL,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE shipment_id = :shipment_id
                       AND is_running = 0
                       AND paused_reason LIKE \'Mandatory hold:%\''
                );
                $controlStmt->execute(['shipment_id' => $shipmentId]);

                $shipmentStmt = $db->prepare(
                    'UPDATE shipments
                     SET status = \'on_hold\',
                         speed = 0,
                         last_update = CURRENT_TIMESTAMP,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id
                       AND LOWER(status) NOT IN (\'delivered\', \'cancelled\')
                       AND status = \'on_hold\''
                );
                $shipmentStmt->execute(['id' => $shipmentId]);
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        holdResponse(true, 'Hold formally cleared. An administrator can review the shipment and resume tracking when appropriate.', [
            'remaining_mandatory_holds' => $remainingMandatory
        ]);
    }

    holdResponse(false, 'Unsupported hold action.', [], 422);
} catch (Throwable $e) {
    error_log('Hold management error: ' . $e->getMessage());
    holdResponse(false, 'The hold operation could not be completed.', [], 500);
}
