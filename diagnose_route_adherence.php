<?php
declare(strict_types=1);

require_once 'config/config.php';
require_once 'app/Database.php';
require_once 'patches/Engine.php.movement-test';
require_once 'patches/route_segment_distance.php';

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

$routeStmt = $db->prepare(
    'SELECT sequence, latitude, longitude
     FROM shipment_route_points
     WHERE shipment_id = :id
     ORDER BY sequence ASC'
);
$routeStmt->execute([':id' => (int)$shipment['id']]);
$route = $routeStmt->fetchAll(PDO::FETCH_ASSOC);

if (count($route) < 2) {
    exit("ERROR: At least two route points are required.\n");
}

$engine = new Engine($db);
$move = new ReflectionMethod($engine, 'moveAlongStoredRoute');
$distance = new ReflectionMethod($engine, 'distanceKm');

echo "READ-ONLY ROUTE-SEGMENT TEST\n";
echo "Candidate: patches/Engine.php.movement-test\n";
echo "Shipment: TEST-001 | 30 simulated steps | 10 km requested per step\n";
echo "No shipment or control records will be saved.\n\n";

printf(
    "%-4s %-10s %-11s %-9s %-11s %-11s %-10s\n",
    "Step", "Latitude", "Longitude", "Moved km",
    "Travelled", "Remaining", "Route km"
);

$previousLat = (float)$shipment['current_lat'];
$previousLng = (float)$shipment['current_lng'];
$counterErrors = 0;
$movementCollapses = 0;
$previousTravelled = (float)$shipment['distance_travelled'];

for ($step = 1; $step <= 30; $step++) {
    $result = $move->invoke(
        $engine,
        $shipment,
        $control,
        null,
        10.0
    );

    if (!is_array($result)) {
        exit("ERROR: No movement result at step {$step}.\n");
    }

    $lat = (float)$result['lat'];
    $lng = (float)$result['lng'];

    $moved = (float)$distance->invoke(
        $engine,
        $previousLat,
        $previousLng,
        $lat,
        $lng
    );

    $routeDistance = nearestRouteSegmentDistanceKm(
        $lat,
        $lng,
        $route
    );

    $travelled = (float)$result['travelled'];
    $remaining = (float)$result['remaining'];
    $total = (float)$result['total'];

    if (abs($total - $travelled - $remaining) > 0.01) {
        $counterErrors++;
    }

    if ($moved < 1.0 && $step > 1) {
        $movementCollapses++;
    }

    printf(
        "%-4d %-10.5f %-11.5f %-9.3f %-11.3f %-11.3f %-10.3f\n",
        $step,
        $lat,
        $lng,
        $moved,
        $travelled,
        $remaining,
        $routeDistance
    );

    $shipment['current_lat'] = $lat;
    $shipment['current_lng'] = $lng;
    $shipment['distance_total'] = $total;
    $shipment['distance_travelled'] = $travelled;
    $shipment['distance_remaining'] = $remaining;

    $control['current_route_sequence'] = (int)$result['route_sequence'];

    $previousLat = $lat;
    $previousLng = $lng;
    $previousTravelled = $travelled;
}

echo "\nCOUNTER CHECK: " .
    ($counterErrors === 0 ? "PASS" : "FAIL ({$counterErrors} errors)") . "\n";
echo "MOVEMENT COLLAPSES (<1 km after step 1): {$movementCollapses}\n";
echo "Simulation finished in memory; database unchanged.\n";
