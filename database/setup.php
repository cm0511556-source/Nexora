<?php
/**
 * Nexora database setup script.
 *
 * Creates database/database.sqlite with all tables (schema dump + migrations),
 * then seeds:
 *   - One admin user (email + password from args or defaults)
 *   - A demo shipment with tracking number NX-2026-0001
 *
 * Usage:
 *   php database/setup.php [email] [password]
 *
 * Example:
 *   php database/setup.php admins@logistics.com "Admins@Logistics0 YOUR_REAL_PASSWORD"
 */
declare(strict_types=1);

$dbPath = __DIR__ . '/database.sqlite';
$adminEmail = $argv[1] ?? 'admins@logistics.com';
$adminPass  = $argv[2] ?? 'Admins@Logistics0 YOUR_REAL_PASSWORD';

// Remove old database so the script is idempotent
if (file_exists($dbPath)) {
    unlink($dbPath);
    echo "Removed existing database.\n";
}

$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('PRAGMA journal_mode = WAL');

echo "=== Creating tables ===\n";

// ============================================================
// Base tables from _schema_dump.txt
// ============================================================
$tables = [
    "CREATE TABLE subscribers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL UNIQUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )",

    "CREATE TABLE workspace_stats (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        active_users INTEGER NOT NULL DEFAULT 0,
        projects_completed INTEGER NOT NULL DEFAULT 0,
        uptime REAL NOT NULL DEFAULT 0,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )",

    "CREATE TABLE admins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'admin',
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )",

    "CREATE TABLE customers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT,
        phone TEXT,
        address TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )",

    "CREATE TABLE shipments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tracking_number TEXT NOT NULL UNIQUE,
        customer_id INTEGER,
        customer_name TEXT NOT NULL,
        customer_email TEXT,
        customer_phone TEXT,
        package_description TEXT,
        transport_type TEXT NOT NULL DEFAULT 'vehicle',
        vehicle_name TEXT,
        vehicle_number TEXT,
        origin TEXT NOT NULL,
        destination TEXT NOT NULL,
        origin_lat REAL NOT NULL DEFAULT 0,
        origin_lng REAL NOT NULL DEFAULT 0,
        destination_lat REAL NOT NULL DEFAULT 0,
        destination_lng REAL NOT NULL DEFAULT 0,
        current_lat REAL NOT NULL DEFAULT 0,
        current_lng REAL NOT NULL DEFAULT 0,
        speed REAL NOT NULL DEFAULT 0,
        distance_total REAL NOT NULL DEFAULT 0,
        distance_travelled REAL NOT NULL DEFAULT 0,
        distance_remaining REAL NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'pending',
        eta TEXT,
        estimated_delivery TEXT,
        tracking_mode TEXT NOT NULL DEFAULT 'simulation',
        last_update TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )",

    "CREATE TABLE tracking_positions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        shipment_id INTEGER NOT NULL,
        latitude REAL NOT NULL,
        longitude REAL NOT NULL,
        speed REAL NOT NULL DEFAULT 0,
        heading REAL NOT NULL DEFAULT 0,
        recorded_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(shipment_id) REFERENCES shipments(id) ON DELETE CASCADE
    )",

    "CREATE TABLE tracking_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        shipment_id INTEGER NOT NULL,
        status TEXT NOT NULL,
        title TEXT NOT NULL,
        description TEXT,
        location TEXT,
        event_time TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        event_type TEXT NOT NULL DEFAULT 'location',
        latitude REAL,
        longitude REAL,
        icon TEXT,
        delay_minutes INTEGER DEFAULT 0,
        FOREIGN KEY(shipment_id) REFERENCES shipments(id) ON DELETE CASCADE
    )",

    "CREATE TABLE notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        shipment_id INTEGER,
        type TEXT NOT NULL DEFAULT 'info',
        title TEXT NOT NULL,
        message TEXT NOT NULL,
        is_read INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(shipment_id) REFERENCES shipments(id) ON DELETE CASCADE
    )",

    "CREATE TABLE shipment_waypoints (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        shipment_id INTEGER NOT NULL,
        sequence INTEGER NOT NULL,
        name TEXT NOT NULL,
        address TEXT,
        latitude REAL NOT NULL,
        longitude REAL NOT NULL,
        waypoint_type TEXT NOT NULL DEFAULT 'waypoint',
        status TEXT NOT NULL DEFAULT 'pending',
        arrival_at TEXT,
        departure_at TEXT,
        notes TEXT,
        leg_type TEXT NOT NULL DEFAULT 'truck',
        public_label TEXT,
        public_note TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(shipment_id) REFERENCES shipments(id) ON DELETE CASCADE,
        UNIQUE(shipment_id, sequence)
    )",

    "CREATE INDEX idx_waypoints_shipment ON shipment_waypoints(shipment_id)",
    "CREATE INDEX idx_waypoints_sequence ON shipment_waypoints(shipment_id, sequence)",

    "CREATE TABLE tracking_controls (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        shipment_id INTEGER NOT NULL UNIQUE,
        is_running INTEGER NOT NULL DEFAULT 0,
        control_speed REAL NOT NULL DEFAULT 0,
        current_waypoint_id INTEGER,
        next_waypoint_id INTEGER,
        tracking_mode TEXT NOT NULL DEFAULT 'simulation',
        paused_reason TEXT,
        last_started_at TEXT,
        last_paused_at TEXT,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        current_route_sequence INTEGER NOT NULL DEFAULT 0,
        FOREIGN KEY(shipment_id) REFERENCES shipments(id) ON DELETE CASCADE,
        FOREIGN KEY(current_waypoint_id) REFERENCES shipment_waypoints(id) ON DELETE SET NULL,
        FOREIGN KEY(next_waypoint_id) REFERENCES shipment_waypoints(id) ON DELETE SET NULL
    )",

    "CREATE TABLE shipment_holds (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        shipment_id INTEGER NOT NULL,
        hold_type TEXT NOT NULL CHECK (hold_type IN ('customs', 'safety', 'security', 'compliance', 'other')),
        reason TEXT NOT NULL,
        details TEXT,
        status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'cleared')),
        mandatory INTEGER NOT NULL DEFAULT 1 CHECK (mandatory IN (0, 1)),
        created_by INTEGER,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        cleared_at TEXT,
        cleared_by INTEGER,
        clearance_note TEXT,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE,
        FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL,
        FOREIGN KEY (cleared_by) REFERENCES admins(id) ON DELETE SET NULL
    )",

    "CREATE INDEX idx_shipment_holds_active ON shipment_holds (shipment_id, status, mandatory)",

    "CREATE TABLE shipment_route_points (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        shipment_id INTEGER NOT NULL,
        sequence INTEGER NOT NULL,
        latitude REAL NOT NULL,
        longitude REAL NOT NULL,
        segment_type TEXT NOT NULL DEFAULT 'route',
        encoded_polyline TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        waypoint_id INTEGER REFERENCES shipment_waypoints(id) ON DELETE SET NULL,
        FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE,
        UNIQUE (shipment_id, sequence)
    )",
];

// Additional columns on shipments (from multicarrier migration)
$tables[] = "CREATE TABLE IF NOT EXISTS carriers (
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
)";

$tables[] = "CREATE TABLE IF NOT EXISTS event_translations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    raw_key TEXT NOT NULL UNIQUE,
    public_title TEXT NOT NULL,
    public_description TEXT,
    public_icon TEXT,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)";

$tables[] = "CREATE TABLE IF NOT EXISTS segment_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    segment_type TEXT NOT NULL UNIQUE,
    public_title TEXT NOT NULL,
    public_description TEXT,
    icon TEXT,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)";

// tracking_lookups (from migrate_lookups.php, with added columns from Support.php)
$tables[] = "CREATE TABLE IF NOT EXISTS tracking_lookups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tracking_number TEXT NOT NULL,
    customer_name TEXT NOT NULL,
    customer_email TEXT,
    customer_phone TEXT,
    ip_address TEXT,
    user_agent TEXT,
    session_token TEXT,
    token_expires_at TEXT,
    verified INTEGER NOT NULL DEFAULT 0,
    match_method TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)";
$tables[] = "CREATE INDEX IF NOT EXISTS idx_lookups_tracking ON tracking_lookups(tracking_number)";
$tables[] = "CREATE INDEX IF NOT EXISTS idx_lookup_token ON tracking_lookups (session_token)";

// shipment_media (from migrate_media.php, with context column)
$tables[] = "CREATE TABLE IF NOT EXISTS shipment_media (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    shipment_id INTEGER NOT NULL,
    media_type TEXT NOT NULL CHECK (media_type IN ('photo','video')),
    file_path TEXT NOT NULL,
    mime_type TEXT,
    file_size INTEGER NOT NULL DEFAULT 0,
    caption TEXT,
    sort_order INTEGER NOT NULL DEFAULT 0,
    is_public INTEGER NOT NULL DEFAULT 1,
    uploaded_by INTEGER,
    context TEXT NOT NULL DEFAULT 'general',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(shipment_id) REFERENCES shipments(id) ON DELETE CASCADE,
    FOREIGN KEY(uploaded_by) REFERENCES admins(id) ON DELETE SET NULL
)";
$tables[] = "CREATE INDEX IF NOT EXISTS idx_media_shipment ON shipment_media(shipment_id, sort_order)";

// site_content (from migrate_site_content.php)
$tables[] = "CREATE TABLE IF NOT EXISTS site_content (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    section TEXT NOT NULL,
    content_key TEXT NOT NULL UNIQUE,
    label TEXT NOT NULL,
    value TEXT NOT NULL DEFAULT '',
    multiline INTEGER NOT NULL DEFAULT 0,
    sort_order INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)";

// testimonials (from migrate_testimonials.php)
$tables[] = "CREATE TABLE IF NOT EXISTS testimonials (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    author_name TEXT NOT NULL,
    author_country TEXT,
    author_role TEXT,
    author_photo_url TEXT,
    author_photo_path TEXT,
    rating INTEGER NOT NULL DEFAULT 5 CHECK (rating BETWEEN 1 AND 5),
    message TEXT NOT NULL,
    is_published INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)";
$tables[] = "CREATE INDEX IF NOT EXISTS idx_testimonials_pub ON testimonials(is_published, sort_order)";

// settings, audit_log, password_resets, proof_of_delivery (from Support.php)
$tables[] = "CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL DEFAULT '',
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)";

$tables[] = "CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_id INTEGER,
    admin_name TEXT,
    action TEXT NOT NULL,
    entity_type TEXT,
    entity_id TEXT,
    details TEXT,
    ip_address TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)";
$tables[] = "CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_log (created_at DESC)";

$tables[] = "CREATE TABLE IF NOT EXISTS password_resets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_id INTEGER NOT NULL,
    token_hash TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    used INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)";

$tables[] = "CREATE TABLE IF NOT EXISTS proof_of_delivery (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    shipment_id INTEGER NOT NULL UNIQUE,
    photo_path TEXT,
    signature_path TEXT,
    signed_by TEXT,
    note TEXT,
    delivered_at TEXT,
    created_by INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL
)";

foreach ($tables as $sql) {
    try {
        $pdo->exec($sql);
        $label = trim(substr($sql, 0, 60));
        echo "OK: " . $label . "...\n";
    } catch (Throwable $e) {
        echo "ERR: " . $e->getMessage() . "\n";
    }
}

// Add carrier_id to shipments if not present
// Helper to check if a column exists
function nxColumnExists($pdo, $table, $column) {
    try {
        $rows = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
    } catch (Throwable $e) {
        return false;
    }
    foreach ($rows as $row) {
        if (isset($row['name']) && strcasecmp((string) $row['name'], $column) === 0) {
            return true;
        }
    }
    return false;
}

if (!nxColumnExists($pdo, 'shipments', 'carrier_id')) {
    $pdo->exec("ALTER TABLE shipments ADD COLUMN carrier_id INTEGER NULL");
    echo "Added shipments.carrier_id\n";
}

echo "\n=== Seeding data ===\n";

// Settings
$ins = $pdo->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (:k, :v)");
foreach (['ambient_speed' => 'normal', 'map_style_url' => '', 'chatbot_enabled' => '1', 'company_email' => ''] as $k => $v) {
    $ins->execute([':k' => $k, ':v' => $v]);
}
echo "Settings seeded.\n";

// Event translations
$ins = $pdo->prepare("INSERT OR IGNORE INTO event_translations (raw_key, public_title, public_description, public_icon) VALUES (?,?,?,?)");
$et = [
    ['shipment tracking started',  'Shipment dispatched',              'Your shipment has left the origin facility and is en route.',               'truck'],
    ['shipment tracking paused',   'Shipment movement paused',         'Movement temporarily paused for operational review.',                        'clock'],
    ['shipment tracking resumed',  'Shipment cleared to proceed',      'Movement has resumed and your shipment is continuing to its destination.',   'truck'],
    ['shipment placed on hold',    'Shipment held for review',         'Your shipment is being reviewed and will resume as soon as clearance is complete.', 'shield'],
    ['hold cleared',               'Shipment released',                'Clearance complete. Your shipment is on the move again.',                    'check'],
    ['customs inspection',         'Customs inspection in progress',   'You shipment has been selected for customs inspection.',                     'customs'],
];
foreach ($et as $row) $ins->execute($row);
echo "Event translations seeded (" . count($et) . ").\n";

// Segment messages
$ins = $pdo->prepare("INSERT OR IGNORE INTO segment_messages (segment_type, public_title, public_description, icon) VALUES (?,?,?,?)");
$sm = [
    ['air',       'Air freight in transit',   'Cargo is airborne and moving on schedule.',              'air'],
    ['sea',       'Ocean freight in transit', 'Container is at sea, proceeding to the next port.',      'sea'],
    ['rail',      'Rail transport in transit', 'Cargo is moving by rail toward the next hub.',           'rail'],
    ['road',      'Road transport in transit', 'Truck is on the road toward the next checkpoint.',       'road'],
    ['bike',      'Courier en route',         'A courier is handling the final leg of this shipment.',  'bike'],
    ['last_mile', 'Out for final delivery',   'Shipment is on its final leg to the delivery address.',  'truck'],
];
foreach ($sm as $s) $ins->execute($s);
echo "Segment messages seeded (" . count($sm) . ").\n";

// Admin user
$hash = password_hash($adminPass, PASSWORD_DEFAULT);
$pdo->exec("DELETE FROM admins");
$stmt = $pdo->prepare("INSERT INTO admins (name, email, password_hash, role, is_active, created_at, updated_at) VALUES (?, ?, ?, 'admin', 1, datetime('now'), datetime('now'))");
$stmt->execute(['Nexora Admin', $adminEmail, $hash]);
$adminId = $pdo->lastInsertId();
echo "Admin seeded: $adminEmail (ID: $adminId)\n";

// Customer
$stmt = $pdo->prepare("INSERT INTO customers (name, email, phone, address, created_at, updated_at) VALUES (?, ?, ?, ?, datetime('now'), datetime('now'))");
$stmt->execute(['Acme Corporation', 'ops@acme.example.com', '+15551234567', '123 Industrial Way, Los Angeles, CA']);
$customerId = $pdo->lastInsertId();

// Demo shipment: NX-2026-0001 (New York → London via Rotterdam & Hamburg)
$stmt = $pdo->prepare("DELETE FROM shipments WHERE tracking_number = 'NX-2026-0001'");
$stmt->execute();
$stmt = $pdo->prepare("INSERT INTO shipments (
    tracking_number, customer_id, customer_name, customer_email, customer_phone,
    package_description, transport_type, vehicle_name, vehicle_number,
    origin, destination, origin_lat, origin_lng, destination_lat, destination_lng,
    current_lat, current_lng, speed, distance_total, distance_travelled,
    distance_remaining, status, eta, estimated_delivery, tracking_mode, last_update,
    created_at, updated_at
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))");
$stmt->execute([
    'NX-2026-0001', $customerId, 'Acme Corporation', 'ops@acme.example.com', '+15551234567',
    'Electronics components (12 pallets)', 'vehicle', 'Trucker', 'NY-2026',
    'New York, USA', 'London, UK', 40.7128, -74.0060, 51.5074, -0.1278,
    52.5, 8.0, 85.0, 5500.0, 1250.0, 4250.0,
    'in_transit', '2026-02-28T10:00:00Z', '2026-02-28T10:00:00Z', 'simulation',
    '2026-02-23 14:00:00',
]);
$shipmentId = $pdo->lastInsertId();
echo "Shipment seeded: NX-2026-0001 (ID: $shipmentId)\n";

// Waypoints: New York → Rotterdam → Hamburg → London
$wpData = [
    [1, 'New York Port',        'Port of NY & NJ',           40.6405, -74.1562, 'origin',      '2026-02-10 08:00:00', '2026-02-11 10:00:00', 'sea',   'Departure port',  'Container loaded onto vessel.'],
    [2, 'Rotterdam Port',       'Port of Rotterdam',         51.9225,   4.4792, 'port',          '2026-02-18 14:30:00', '2026-02-20 12:00:00', 'road',  'Transit hub',     'Container moved to terminal.'],
    [3, 'Hamburg Freight Center', 'Hamburg Logistics Park',  53.5511,   9.9937, 'warehouse',     '2026-02-21 09:00:00', '2026-02-22 16:00:00', 'road',  'Distribution',     'Goods sorted for last-mile delivery.'],
    [4, 'London Delivery',      '10 Downing Street, London', 51.5034,  -0.1276, 'destination',   '2026-02-23 08:00:00', null,                   'road',  'Final delivery',  'Out for delivery.'],
];

$stmt = $pdo->prepare("DELETE FROM shipment_waypoints WHERE shipment_id = :sid");
$stmt->execute([':sid' => $shipmentId]);
$wpIns = $pdo->prepare("INSERT INTO shipment_waypoints (
    id, shipment_id, sequence, name, address, latitude, longitude, waypoint_type, status,
    arrival_at, departure_at, leg_type, public_label, public_note, created_at, updated_at
) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, datetime('now'), datetime('now'))");
$seq = 0;
foreach ($wpData as $wp) {
    $wpIns->execute([$wp[0], $shipmentId, $seq++, $wp[1], $wp[2], $wp[3], $wp[4], $wp[5], $wp[6], $wp[7], $wp[8], $wp[9]]);
}
echo "Waypoints seeded (" . count($wpData) . ").\n";

// Route points: New York → Rotterdam → Hamburg → London (linear interpolation)
$wpCoords = [
    ['lat' => 40.6405, 'lng' => -74.1562, 'seg' => 'sea', 'wid' => 1],
    ['lat' => 51.9225, 'lng' =>  4.4792, 'seg' => 'road', 'wid' => 2],
    ['lat' => 53.5511, 'lng' =>  9.9937, 'seg' => 'road', 'wid' => 3],
    ['lat' => 51.5034, 'lng' => -0.1276, 'seg' => 'road', 'wid' => 4],
];
$stmt = $pdo->prepare("DELETE FROM shipment_route_points WHERE shipment_id = :sid");
$stmt->execute([':sid' => $shipmentId]);
$rpIns = $pdo->prepare("INSERT INTO shipment_route_points (shipment_id, sequence, latitude, longitude, segment_type, encoded_polyline, waypoint_id, created_at) VALUES (:sid, :seq, :lat, :lng, :seg, null, :wid, datetime('now'))");
$seq = 0;
for ($i = 0; $i < count($wpCoords) - 1; $i++) {
    $a = $wpCoords[$i]; $b = $wpCoords[$i + 1];
    for ($st = 0; $st <= 20; $st++) {
        $t = $st / 20.0;
        $lat = $a['lat'] + ($b['lat'] - $a['lat']) * $t;
        $lng = $a['lng'] + ($b['lng'] - $a['lng']) * $t;
        $dLat = $b['lat'] - $a['lat']; $dLng = $b['lng'] - $a['lng'];
        $lat += sin(M_PI * $t) * 0.06 * (-$dLng * 0.05);
        $lng += sin(M_PI * $t) * 0.06 * ($dLat * 0.05);
        $wid = ($st === 0) ? $a['wid'] : null;
        $rpIns->execute([':sid' => $shipmentId, ':seq' => $seq++, ':lat' => $lat, ':lng' => $lng, ':seg' => $b['seg'], ':wid' => $wid]);
    }
}
$last = $wpCoords[count($wpCoords) - 1];
$rpIns->execute([':sid' => $shipmentId, ':seq' => $seq++, ':lat' => $last['lat'], ':lng' => $last['lng'], ':seg' => $last['seg'], ':wid' => $last['wid']]);
echo "Route points seeded ($seq points).\n";

// Tracking control (running simulation)
$stmt = $pdo->prepare("DELETE FROM tracking_controls WHERE shipment_id = :sid");
$stmt->execute([':sid' => $shipmentId]);
$pdo->exec("INSERT INTO tracking_controls (shipment_id, is_running, control_speed, tracking_mode, current_route_sequence, updated_at) VALUES ($shipmentId, 1, 80.0, 'simulation', 1, datetime('now'))");
echo "Tracking control seeded (running, 80 km/h).\n";

// Tracking positions (for Replay Journey — 41 positions NY → London)
$stmt = $pdo->prepare("DELETE FROM tracking_positions WHERE shipment_id = :sid");
$stmt->execute([':sid' => $shipmentId]);
$rpPts = $wpCoords;
$start_ts = strtotime('2026-02-10 12:00:00 UTC');
$posIns = $pdo->prepare("INSERT INTO tracking_positions (shipment_id, latitude, longitude, speed, heading, recorded_at) VALUES (?, ?, ?, ?, ?, ?)");
for ($j = 0; $j <= 40; $j++) {
    $t = $j / 40.0;
    if ($t < 0.33) {
        $tt = $t / 0.33;
        $lat = 40.6405 + (51.9225 - 40.6405) * $tt;
        $lng = -74.1562 + (4.4792 - (-74.1562)) * $tt;
    } elseif ($t < 0.66) {
        $tt = ($t - 0.33) / 0.33;
        $lat = 51.9225 + (53.5511 - 51.9225) * $tt;
        $lng = 4.4792 + (9.9937 - 4.4792) * $tt;
    } else {
        $tt = ($t - 0.66) / 0.34;
        $lat = 53.5511 + (51.5034 - 53.5511) * $tt;
        $lng = 9.9937 + (-0.1276 - 9.9937) * $tt;
    }
    $ts = gmdate('Y-m-d H:i:s', $start_ts + $j * 1800);
    $posIns->execute([$shipmentId, $lat, $lng, 80.0, 120, $ts]);
}
echo "Tracking positions seeded (41 positions for replay).\n";

// Tracking events
$evIns = $pdo->prepare("INSERT INTO tracking_events (shipment_id, status, title, description, location, event_time, event_type, latitude, longitude, icon, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))");
$events = [
    ['in_transit', 'Left New York Port', 'Container NY-2026 departed.', 'New York, USA', '2026-02-11 10:00:00', 'departure', 40.6405, -74.1562, '🚢'],
    ['in_transit', 'Arrived Rotterdam', 'Container arrived at Port of Rotterdam.', 'Rotterdam, Netherlands', '2026-02-18 14:30:00', 'arrival', 51.9225, 4.4792, '⚓'],
    ['in_transit', 'In transit to Hamburg', 'Cargo is moving by truck toward Hamburg Logistics Park.', 'North Sea', '2026-02-19 06:00:00', 'location', 52.5, 6.0, '🚚'],
    ['in_transit', 'Arrived Hamburg', 'Container arrived at Hamburg Logistics Park.', 'Hamburg, Germany', '2026-02-21 09:00:00', 'arrival', 53.5511, 9.9937, '🚚'],
    ['out_for_delivery', 'Out for delivery', 'Shipment is out for final delivery to London.', 'London, UK', '2026-02-23 08:00:00', 'location', 51.5034, -0.1276, '🚚'],
];
foreach ($events as $e) {
    $evIns->execute(array_merge([$shipmentId], $e));
}
echo "Tracking events seeded (" . count($events) . ").\n";

// Tracking lookup (for pre-track verification gate)
$pdo->exec("INSERT OR IGNORE INTO tracking_lookups (tracking_number, customer_name, customer_email, customer_phone, verified, match_method) VALUES ('NX-2026-0001', 'Acme Corporation', 'ops@acme.example.com', '+15551234567', 1, 'email')");
echo "Tracking lookup seeded (verified).\n";

// ============================================================
// Summary
// ============================================================
echo "\n=== Setup complete ===\n";
echo "Database: $dbPath\n";
echo "Admin:    $adminEmail / $adminPass\n";
echo "Shipment: NX-2026-0001\n\n";
echo "Start server:\n  cd public\n  php -S 127.0.0.1:8000\n\n";
echo "Admin:  http://127.0.0.1:8000/admin/login.php\n";
echo "Public: http://127.0.0.1:8000/track.php?id=NX-2026-0001\n";
