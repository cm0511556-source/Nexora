<?php
declare(strict_types=1);
$dbPath = __DIR__ . '/database.sqlite';
$backupDir = dirname(__DIR__) . '/backups';
if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
$backup = $backupDir . '/database-before-testimonials-' . date('Ymd-His') . '.sqlite';
copy($dbPath, $backup);
echo "backup: $backup\n";

$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->beginTransaction();
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS testimonials (
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
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_testimonials_pub ON testimonials(is_published, sort_order)");
    $pdo->commit();
    echo "testimonials table created\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
echo "--- verify ---\n";
foreach ($pdo->query("PRAGMA table_info(testimonials)") as $r) echo "  " . $r['name'] . " " . $r['type'] . "\n";
