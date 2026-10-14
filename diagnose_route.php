<?php
require_once 'config/config.php';
require_once 'app/Database.php';
require_once 'app/Tracking/Engine.php.route-aware-candidate';

$db = \VibrantHub\Database::connection();
$shipment = $db->query(
    "SELECT * FROM shipments WHERE tracking_number = 'TEST-001'"
)->fetch(PDO::FETCH_ASSOC);

if (!$shipment) {
    exit("TEST-001 not found\n");
}

$controlStmt = $db->prepare(
    "SELECT * FROM tracking_controls WHERE shipment_id = :id"
);
$controlStmt->execute([':id' => (int)$shipment['id']]);
$control = $controlStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'current_route_sequence' => 0
];

$engine = new \VibrantHub\Tracking\Engine($db);
$method = new ReflectionMethod($engine, 'moveAlongStoredRoute');
$result = $method->invoke($engine, $shipment, $control, null, 10.0);

$distanceMethod = new ReflectionMethod($engine, 'distanceKm');
$displacement = $distanceMethod->invoke(
    $engine,
    (float)$shipment['current_lat'],
    (float)$shipment['current_lng'],
    (float)$result['lat'],
    (float)$result['lng']
);

echo "Requested movement: 10 km\n";
echo "Actual displacement: " . round($displacement, 3) . " km\n";
echo "Reported travelled: " . round($result['travelled'], 3) . " km\n";
echo "Reported remaining: " . round($result['remaining'], 3) . " km\n";
echo "Database unchanged; no shipment movement was saved.\n";
