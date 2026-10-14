<?php
declare(strict_types=1);

require_once __DIR__ . '/patches/Engine.php.movement-test';

$db = new PDO('sqlite:' . __DIR__ . '/database/movement-test.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $shipment = $db->query(
        "SELECT * FROM shipments WHERE tracking_number = 'TEST-001'"
    )->fetch(PDO::FETCH_ASSOC);

    if (!$shipment) {
        throw new RuntimeException('TEST-001 not found.');
    }

    $id = (int)$shipment['id'];

    // Move only the disposable test shipment to its final destination.
    $stmt = $db->prepare(
        "UPDATE shipments
         SET current_lat = destination_lat,
             current_lng = destination_lng,
             last_update = CURRENT_TIMESTAMP
         WHERE id = :id"
    );
    $stmt->execute([':id' => $id]);

    $stmt = $db->prepare(
        "UPDATE tracking_controls
         SET is_running = 1, control_speed = 60,
             paused_reason = NULL
         WHERE shipment_id = :id"
    );
    $stmt->execute([':id' => $id]);

    echo "Running candidate tick at the destination...\n";

    $engine = new \VibrantHub\Tracking\Engine($db);
    $result = $engine->tick($id);
    print_r($result);

    echo "\nVERIFICATION:\n";

    $stmt = $db->prepare(
        "SELECT status, speed, distance_remaining, eta
         FROM shipments WHERE id = :id"
    );
    $stmt->execute([':id' => $id]);
    $final = $stmt->fetch(PDO::FETCH_ASSOC);
    print_r($final);

    $stmt = $db->prepare(
        "SELECT is_running, paused_reason
         FROM tracking_controls WHERE shipment_id = :id"
    );
    $stmt->execute([':id' => $id]);
    print_r($stmt->fetch(PDO::FETCH_ASSOC));

    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM tracking_events
         WHERE shipment_id = :id AND status = 'delivered'"
    );
    $stmt->execute([':id' => $id]);
    echo 'Delivery events: ' . $stmt->fetchColumn() . "\n";

    if (
        $final['status'] === 'delivered'
        && (float)$final['speed'] === 0.0
        && (float)$final['distance_remaining'] === 0.0
        && $final['eta'] === null
    ) {
        echo "FINAL-DELIVERY CHECK: PASS\n";
    } else {
        echo "FINAL-DELIVERY CHECK: FAIL\n";
    }

    echo "Only database/movement-test.sqlite was used.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "TEST ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
