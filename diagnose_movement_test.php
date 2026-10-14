<?php
declare(strict_types=1);

require_once 'config/config.php';
require_once 'app/Database.php';
require_once 'patches/Engine.php.movement-test';

use VibrantHub\Database;
use VibrantHub\Tracking\Engine;

$db = Database::connection();

$shipment = $db->query(
    "SELECT * FROM shipments WHERE tracking_number = 'TEST-001'"
)->fetch(PDO::FETCH_ASSOC);

if (!$shipment) {
    exit("ERROR: TEST-001 not found.\n");
}

$stmt = $db->prepare(
    'SELECT * FROM tracking_controls WHERE shipment_id = :id'
);
$stmt->execute([':id' => (int)$shipment['id']]);
$control = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
    'current_route_sequence' => 0
];

$engine = new Engine($db);
$move = new ReflectionMethod($engine, 'moveAlongStoredRoute');
$distance = new ReflectionMethod($engine, 'distanceKm');

echo "READ-ONLY REPEATED MOVEMENT TEST\n";
echo "Each step starts from the previous simulated result.\n\n";

for ($step = 1; $step <= 5; $step++) {
    $oldLat = (float)$shipment['current_lat'];
    $oldLng = (float)$shipment['current_lng'];

    $result = $move->invoke($engine, $shipment, $control, null, 10.0);

    if (!is_array($result)) {
        exit("ERROR: Movement returned no route result at step $step.\n");
    }

    $actual = $distance->invoke(
        $engine,
        $oldLat,
        $oldLng,
        (float)$result['lat'],
        (float)$result['lng']
    );

    printf(
        "Step %d: moved %.3f km | travelled %.3f km | remaining %.3f km\n",
        $step,
        $actual,
        (float)$result['travelled'],
        (float)$result['remaining']
    );

    $shipment['current_lat'] = $result['lat'];
    $shipment['current_lng'] = $result['lng'];
    $shipment['distance_total'] = $result['total'];
    $shipment['distance_travelled'] = $result['travelled'];
    $shipment['distance_remaining'] = $result['remaining'];
    $control['current_route_sequence'] = $result['route_sequence'];
}

echo "\nDatabase unchanged; all five steps were simulated in memory.\n";
