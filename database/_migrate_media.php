<?php
declare(strict_types=1);
$dbPath = __DIR__ . '/database.sqlite';
$backupDir = dirname(__DIR__) . '/backups';
if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
$backup = $backupDir . '/database-before-media-' . date('Ymd-His') . '.sqlite';
copy($dbPath, $backup);
echo "backup: $backup\n";

$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->beginTransaction();
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS shipment_media (
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
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(shipment_id) REFERENCES shipments(id) ON DELETE CASCADE,
        FOREIGN KEY(uploaded_by) REFERENCES admins(id) ON DELETE SET NULL
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_media_shipment ON shipment_media(shipment_id, sort_order)");
    $pdo->commit();
    echo "shipment_media created\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
echo "--- verify ---\n";
foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='shipment_media'") as $r) echo "  " . $r['name'] . "\n";
foreach ($pdo->query("PRAGMA table_info(shipment_media)") as $r) echo "    " . $r['name'] . " " . $r['type'] . "\n";
