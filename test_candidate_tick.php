<?php
declare(strict_types=1);

require_once __DIR__ . '/patches/Engine.php.movement-test';

$db = new PDO('sqlite:' . __DIR__ . '/database/movement-test.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON');

try {
    $db->beginTransaction();

    $before = $db->query(
        "SELECT id, tracking_number, current_lat, current_lng,
                distance_travelled, distance_remaining, status
         FROM shipments WHERE tracking_number = 'TEST-001'"
    )->fetch(PDO::FETCH_ASSOC);

    if (!$before) {
        throw new RuntimeException('TEST-001 was not found in the test database.');
    }

    $id = (int)$before['id'];

    $control = $db->prepare(
        "UPDATE tracking_controls
         SET is_running = 1, control_speed = 60,
             paused_reason = NULL, updated_at = CURRENT_TIMESTAMP
         WHERE shipment_id = :id"
    );
    $control->execute([':id' => $id]);

    if ($control->rowCount() === 0) {
        throw new RuntimeException('Could not enable the test shipment control.');
    }

    $update = $db->prepare(
        "UPDATE shipments
         SET last_update = datetime('now', '-60 seconds')
         WHERE id = :id"
    );
    $update->execute([':id' => $id]);

    $db->commit();

    echo "BEFORE:\n";
    print_r($before);

    $engine = new \VibrantHub\Tracking\Engine($db);
    $result = $engine->tick($id);

    echo "\nTICK RESULT:\n";
    print_r($result);

    echo "\nDATABASE AFTER TICK:\n";
    foreach ([
        'shipment' => "SELECT tracking_number, current_lat, current_lng,
                              speed, distance_total, distance_travelled,
                              distance_remaining, status, last_update
                       FROM shipments WHERE id = $id",
        'control' => "SELECT is_running, control_speed, paused_reason,
                             current_route_sequence
                      FROM tracking_controls WHERE shipment_id = $id",
        'positions' => "SELECT COUNT(*) FROM tracking_positions WHERE shipment_id = $id",
        'events' => "SELECT COUNT(*) FROM tracking_events WHERE shipment_id = $id"
    ] as $label => $sql) {
        echo "\n$label:\n";
        $row = $db->query($sql)->fetch(PDO::FETCH_ASSOC);
        print_r($row);
    }

    echo "\nTEST COMPLETED. Only the disposable test database was used.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "\nTEST FAILED: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
}
