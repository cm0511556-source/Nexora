<?php
declare(strict_types=1);
$dbPath = __DIR__ . '/database.sqlite';
$backup = dirname(__DIR__) . '/backups/database-before-site-content-' . date('Ymd-His') . '.sqlite';
copy($dbPath, $backup);
echo "backup: $backup\n";

$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE IF NOT EXISTS site_content (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    section TEXT NOT NULL,
    content_key TEXT NOT NULL UNIQUE,
    label TEXT NOT NULL,
    value TEXT NOT NULL DEFAULT '',
    multiline INTEGER NOT NULL DEFAULT 0,
    sort_order INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)");
echo "site_content created\n";

$seed = [
    ['hero','hero_pill','Tagline pill (small text above headline)','🌍 Global Shipping · Smarter Tracking',0,10],
    ['hero','hero_heading_pre','Headline — part before the highlight','Ship Worldwide with',0,20],
    ['hero','hero_heading_highlight','Headline — highlighted text','Nexora Logistics',0,30],
    ['hero','hero_subtitle','Sub-headline paragraph','Freight forwarding, air & ocean cargo, door-to-door delivery — with live tracking on every shipment, every continent.',1,40],
    ['hero','hero_cta_track','Primary button text','Track Shipment',0,50],
    ['hero','hero_cta_services','Secondary button text','Our Services',0,60],
    ['hero','hero_cta_quote','Third button text','Get a Quote',0,70],
    ['hero','hero_tracking_placeholder','Tracking input placeholder','Enter tracking number (e.g. NX-000123)',0,80],

    ['services','services_heading','Services heading','Every route. Every mode. One partner.',0,110],
    ['services','services_subheading','Services sub-heading','Air, ocean, road, and warehousing — coordinated under a single tracking number.',1,120],

    ['services','service_1_title','Service 1 title','✈️ Air Freight',0,130],
    ['services','service_1_body','Service 1 body','Priority and economy air cargo for time-sensitive shipments across continents.',1,140],
    ['services','service_2_title','Service 2 title','🚢 Ocean Freight',0,150],
    ['services','service_2_body','Service 2 body','FCL and LCL container shipping to major ports worldwide with full customs coordination.',1,160],
    ['services','service_3_title','Service 3 title','🚚 Road & Last Mile',0,170],
    ['services','service_3_body','Service 3 body','Reliable ground transport and final-mile delivery, with real-time status updates.',1,180],
    ['services','service_4_title','Service 4 title','🏬 Warehousing',0,190],
    ['services','service_4_body','Service 4 body','Secure storage, cross-docking, and fulfillment at strategic global hubs.',1,200],

    ['numbers','numbers_heading','Numbers section heading','Our numbers',0,210],
    ['numbers','number_1_label','Number 1 label','Shipments delivered',0,220],
    ['numbers','number_2_label','Number 2 label','Countries served',0,230],
    ['numbers','number_3_label','Number 3 label','Happy clients',0,240],
    ['numbers','number_4_label','Number 4 label','Average rating',0,250],

    ['reviews','reviews_heading','Reviews heading','Trusted by shippers worldwide',0,260],
    ['reviews','reviews_subheading_prefix','Reviews sub-heading (before counts)','',0,270],

    ['contact','contact_heading','Contact heading','Talk to Nexora Logistics',0,280],
    ['contact','contact_subtext','Contact sub-text','Subscribe for service updates, or reach our team for a custom shipping quote.',1,290],
    ['contact','contact_cta','Contact button','Subscribe',0,300],
    ['contact','contact_placeholder','Contact input placeholder','Your email address...',0,310],

    ['footer','footer_text','Footer text','Global Shipping. Smarter Tracking.',0,320],
];

$ins = $pdo->prepare("INSERT OR IGNORE INTO site_content (section, content_key, label, value, multiline, sort_order) VALUES (?,?,?,?,?,?)");
foreach ($seed as $row) $ins->execute($row);
echo "seeded " . count($seed) . " keys\n";

foreach ($pdo->query("SELECT section, COUNT(*) AS n FROM site_content GROUP BY section ORDER BY section") as $r) {
    echo "  " . $r['section'] . ": " . $r['n'] . "\n";
}
