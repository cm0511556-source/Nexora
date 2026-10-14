<?php
require_once 'config/config.php';
require_once 'app/Database.php';
require_once 'app/Tracking/Engine.php.route-aware-candidate';

$db = \VibrantHub\Database::connection();

$shipmentStmt = $db->prepare("SELECT * FROM shipments WHERE tracking_number = 'TEST-001'");
$shipmentStmt->execute();
$shipment = $shipmentStmt->fetch(PDO::FETCH_ASSOC);

if (!$shipment) {
    exit("TEST FAILED: TEST-001 not found.\n");
}

$controlStmt = $db->prepare("SELECT * FROM tracking_controls WHERE shipment_id = :id");
$controlStmt->execute([':id' => (int)$shipment['id']]);
$control = $controlStmt->fetch(PDO::FETCH_ASSOC);

$engine = new \VibrantHub\Tracking\Engine($db);
$method = new ReflectionMethod($engine, 'moveAlongStoredRoute');

$result = $method->invoke(
    $engine,
    $shipment,
    $control ?: ['current_route_sequence' => 0],
    null,
    10.0
);

echo "Shipment: TEST-001\n";
echo "Stored position: {$shipment['current_lat']}, {$shipment['current_lng']}\n";
echo "Route cursor: " . ($control['current_route_sequence'] ?? 0) . "\n";
echo "Proposed result (10 km movement):\n";
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

echo "\nDatabase remains unchanged by this test.\n";
