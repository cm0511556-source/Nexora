<?php
declare(strict_types=1);

/**
 * One-click SQLite database download.
 * Uses VACUUM INTO to produce a consistent snapshot even in WAL mode.
 */

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once BASE_PATH . '/app/Database.php';
require_once BASE_PATH . '/app/Auth.php';

use VibrantHub\Auth;
use VibrantHub\Database;

Auth::requireAdmin();

$db = Database::connection();

$tmp = sys_get_temp_dir() . '/nexora-backup-' . bin2hex(random_bytes(6)) . '.sqlite';

try {
    // Safe quoting: the temp path is generated internally.
    $db->exec("VACUUM INTO '" . str_replace("'", "''", $tmp) . "'");
} catch (Throwable $e) {
    // Fallback: copy the raw file (still consistent enough for a manual backup).
    @copy(BASE_PATH . '/database/database.sqlite', $tmp);
}

if (!is_file($tmp)) {
    http_response_code(500);
    echo 'Backup failed.';
    exit;
}

nxAudit('database.backup', 'database', null, 'Downloaded a database backup');

$filename = 'nexora-backup-' . gmdate('Ymd-His') . '.sqlite';

header('Content-Type: application/vnd.sqlite3');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . (string) filesize($tmp));
header('Cache-Control: no-store');

readfile($tmp);
@unlink($tmp);
exit;
