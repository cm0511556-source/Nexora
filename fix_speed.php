<?php
$file = 'public/admin/tracking-action.php';
$source = file_get_contents($file);
$start = strpos($source, "            case 'speed':");
$end = strpos($source, "            case 'mode':", $start === false ? 0 : $start);

if ($start === false || $end === false) {
    exit("STOP: speed section not found. File unchanged.\n");
}

$replacement = <<<'CODE'
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

CODE;

if (file_put_contents($file, substr($source, 0, $start) . $replacement . substr($source, $end)) === false) {
    exit("ERROR: write failed.\n");
}

echo "Speed action updated.\n";
