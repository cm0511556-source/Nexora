<?php
declare(strict_types=1);
$dbPath = __DIR__ . '/database.sqlite';
$backupDir = dirname(__DIR__) . '/backups';
$backup = $backupDir . '/database-before-lookups-' . date('Ymd-His') . '.sqlite';
copy($dbPath, $backup);
echo "backup: $backup\n";
$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE IF NOT EXISTS tracking_lookups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tracking_number TEXT NOT NULL,
    customer_name TEXT NOT NULL,
    customer_email TEXT,
    customer_phone TEXT,
    ip_address TEXT,
    user_agent TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_lookups_tracking ON tracking_lookups(tracking_number)");
echo "tracking_lookups created\n";
foreach ($pdo->query("PRAGMA table_info(tracking_lookups)") as $r) echo "  " . $r['name'] . " " . $r['type'] . "\n";
