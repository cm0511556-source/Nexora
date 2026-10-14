<?php
declare(strict_types=1);
$dbPath = __DIR__ . '/database.sqlite';
$backupDir = dirname(__DIR__) . '/backups';
if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
$stamp = date('Ymd-His');
$backup = $backupDir . '/database-before-migrate-' . $stamp . '.sqlite';
copy($dbPath, $backup);
echo "backup: $backup\n";

$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->beginTransaction();

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS carriers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        code TEXT UNIQUE,
        logo_url TEXT,
        logo_path TEXT,
        contact_email TEXT,
        contact_phone TEXT,
        tracking_url TEXT,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS event_translations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        raw_key TEXT NOT NULL UNIQUE,
        public_title TEXT NOT NULL,
        public_description TEXT,
        public_icon TEXT,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS segment_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        segment_type TEXT NOT NULL UNIQUE,
        public_title TEXT NOT NULL,
        public_description TEXT,
        icon TEXT,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    // Add columns only if they don't exist yet
    $cols = function(string $table) use ($pdo) {
        $out = [];
        foreach ($pdo->query("PRAGMA table_info($table)") as $r) $out[$r['name']] = true;
        return $out;
    };

    if (!isset($cols('shipments')['carrier_id'])) {
        $pdo->exec("ALTER TABLE shipments ADD COLUMN carrier_id INTEGER NULL");
        echo "added shipments.carrier_id\n";
    }
    if (!isset($cols('shipment_waypoints')['public_label'])) {
        $pdo->exec("ALTER TABLE shipment_waypoints ADD COLUMN public_label TEXT NULL");
        echo "added shipment_waypoints.public_label\n";
    }
    if (!isset($cols('shipment_waypoints')['public_note'])) {
        $pdo->exec("ALTER TABLE shipment_waypoints ADD COLUMN public_note TEXT NULL");
        echo "added shipment_waypoints.public_note\n";
    }

    $seedEvents = [
        ['shipment tracking started',  'Shipment dispatched',              'Your shipment has left the origin facility and is en route.',               'truck'],
        ['shipment tracking paused',   'Shipment movement paused',         'Movement temporarily paused for operational review.',                        'clock'],
        ['shipment tracking resumed',  'Shipment cleared to proceed',      'Movement has resumed and your shipment is continuing to its destination.',   'truck'],
        ['shipment placed on hold',    'Shipment held for review',         'Your shipment is being reviewed and will resume as soon as clearance is complete.', 'shield'],
        ['hold cleared',               'Shipment released',                'Clearance complete. Your shipment is on the move again.',                    'check'],
        ['customs inspection',         'Customs inspection in progress',   'Your shipment is being inspected by customs authorities.',                   'shield'],
        ['security screening',         'Security screening in progress',   'Your shipment is undergoing security screening.',                            'shield'],
        ['shipment seized',            'Shipment detained',                'Your shipment has been detained by customs authorities. Our team is in contact.', 'alert'],
        ['compliance issue',           'Compliance review',                'Additional compliance review is required before release.',                   'alert'],
        ['weather delay',              'Weather delay',                    'Severe weather is affecting the route. Delivery may be delayed.',            'cloud'],
        ['vehicle breakdown',          'Operational delay',                'An operational delay is affecting this route. We are monitoring closely.',   'wrench'],
        ['damaged',                    'Packaging concern reported',       'A packaging concern has been recorded. Our team is reviewing.',              'alert'],
        ['port congestion',            'Port congestion',                  'Port congestion is affecting movement. Delivery may be delayed.',            'clock'],
        ['out for delivery',           'Out for delivery',                 'Your shipment is on its final leg and will be delivered today.',             'truck'],
        ['delivered',                  'Delivered',                        'Your shipment has been delivered. Thank you for choosing Nexora Logistics.', 'check'],
    ];
    $ins = $pdo->prepare("INSERT OR IGNORE INTO event_translations (raw_key, public_title, public_description, public_icon) VALUES (?,?,?,?)");
    foreach ($seedEvents as $row) $ins->execute($row);
    echo "seeded event_translations (" . count($seedEvents) . ")\n";

    $seedSeg = [
        ['air',       'Air freight in transit',   'Cargo is airborne and moving on schedule.',              'air'],
        ['sea',       'Ocean freight in transit', 'Container is at sea, proceeding to the next port.',      'sea'],
        ['rail',      'Rail transport in transit','Cargo is moving by rail toward the next hub.',           'rail'],
        ['road',      'Road transport in transit','Truck is on the road toward the next checkpoint.',       'road'],
        ['bike',      'Courier en route',         'A courier is handling the final leg of this shipment.',  'bike'],
        ['last_mile', 'Out for final delivery',   'Shipment is on its final leg to the delivery address.',  'truck'],
    ];
    $insS = $pdo->prepare("INSERT OR IGNORE INTO segment_messages (segment_type, public_title, public_description, icon) VALUES (?,?,?,?)");
    foreach ($seedSeg as $row) $insS->execute($row);
    echo "seeded segment_messages (" . count($seedSeg) . ")\n";

    $pdo->commit();
    echo "migration OK\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "MIGRATION FAILED: " . $e->getMessage() . "\n";
    echo "DB not changed. Backup at: $backup\n";
    exit(1);
}

echo "--- new tables ---\n";
foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('carriers','event_translations','segment_messages') ORDER BY name") as $r) {
    echo "  " . $r['name'] . "\n";
}
echo "--- shipments has carrier_id? ---\n";
foreach ($pdo->query("PRAGMA table_info(shipments)") as $r) {
    if ($r['name'] === 'carrier_id') echo "  yes\n";
}
