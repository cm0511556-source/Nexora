<?php
declare(strict_types=1);

/**
 * Nexora shared support layer.
 *
 * Global (un-namespaced) helpers used by both the admin console and the public
 * tracking pages. Kept PHP 7.4 compatible on purpose — no match/mixed/never,
 * no union types, no named arguments.
 */

/**
 * Shared PDO connection to the SQLite database. Mirrors app/Database.php but is
 * callable from plain scripts without the VibrantHub namespace.
 */
function nxPdo(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $path = dirname(__DIR__) . '/database/database.sqlite';

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');

    nxEnsureSchema($pdo);

    return $pdo;
}

/** True when a column exists on a SQLite table. */
function nxColumnExists(PDO $pdo, string $table, string $column): bool
{
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

/**
 * Idempotent schema upgrade for the features added after the initial build.
 * Safe to call on every request: it short-circuits once the marker table exists.
 */
function nxEnsureSchema(PDO $pdo): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $hasSettings = $pdo->query(
        "SELECT name FROM sqlite_master WHERE type='table' AND name='settings' LIMIT 1"
    )->fetchColumn();

    if (!$hasSettings) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL DEFAULT '',
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                admin_id INTEGER,
                admin_name TEXT,
                action TEXT NOT NULL,
                entity_type TEXT,
                entity_id TEXT,
                details TEXT,
                ip_address TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS password_resets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                admin_id INTEGER NOT NULL,
                token_hash TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                used INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS proof_of_delivery (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                shipment_id INTEGER NOT NULL UNIQUE,
                photo_path TEXT,
                signature_path TEXT,
                signed_by TEXT,
                note TEXT,
                delivered_at TEXT,
                created_by INTEGER,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );

        $defaults = [
            'ambient_speed'   => 'normal',
            'map_style_url'   => '',
            'chatbot_enabled' => '1',
            'company_email'   => '',
        ];
        $ins = $pdo->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (:k, :v)');
        foreach ($defaults as $k => $v) {
            $ins->execute([':k' => $k, ':v' => $v]);
        }
    }

    // Customer tracker sessions live on the existing lookups table.
    if (!nxColumnExists($pdo, 'tracking_lookups', 'session_token')) {
        $pdo->exec('ALTER TABLE tracking_lookups ADD COLUMN session_token TEXT');
    }
    if (!nxColumnExists($pdo, 'tracking_lookups', 'token_expires_at')) {
        $pdo->exec('ALTER TABLE tracking_lookups ADD COLUMN token_expires_at TEXT');
    }
    if (!nxColumnExists($pdo, 'tracking_lookups', 'verified')) {
        $pdo->exec('ALTER TABLE tracking_lookups ADD COLUMN verified INTEGER NOT NULL DEFAULT 0');
    }
    if (!nxColumnExists($pdo, 'tracking_lookups', 'match_method')) {
        $pdo->exec('ALTER TABLE tracking_lookups ADD COLUMN match_method TEXT');
    }

    // Optional ambient-traffic master switch per shipment is global, so no
    // per-row column is required.

    try {
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_log (created_at DESC)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lookup_token ON tracking_lookups (session_token)');
    } catch (Throwable $e) {
        // Indexes are an optimisation only.
    }

    $done = true;
}

/** Read one setting (or all when $key is null). */
function nxSetting(string $key, $default = null)
{
    try {
        $stmt = nxPdo()->prepare('SELECT value FROM settings WHERE key = :k LIMIT 1');
        $stmt->execute([':k' => $key]);
        $value = $stmt->fetchColumn();

        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return $value;
    } catch (Throwable $e) {
        return $default;
    }
}

/** Persist one setting. */
function nxSettingSet(string $key, string $value): void
{
    try {
        $stmt = nxPdo()->prepare(
            'INSERT INTO settings (key, value, updated_at) VALUES (:k, :v, CURRENT_TIMESTAMP)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([':k' => $key, ':v' => $value]);
    } catch (Throwable $e) {
        // Settings are best-effort; never fatal.
    }
}

/** All settings as key => value. */
function nxSettingsAll(): array
{
    $out = [];
    try {
        foreach (nxPdo()->query('SELECT key, value FROM settings') as $row) {
            $out[$row['key']] = $row['value'];
        }
    } catch (Throwable $e) {
    }

    return $out;
}

/** Editable site content (site_content table), cached per request. */
function nxContentMap(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    try {
        foreach (nxPdo()->query('SELECT content_key, value FROM site_content') as $row) {
            $map[$row['content_key']] = (string) $row['value'];
        }
    } catch (Throwable $e) {
    }

    return $map;
}

/** One editable homepage string, falling back to the built-in default. */
function nxContent(string $key, string $default = ''): string
{
    $map = nxContentMap();

    return (isset($map[$key]) && trim($map[$key]) !== '') ? $map[$key] : $default;
}

/**
 * Ambient traffic multiplier. Admin picks Off / Slow / Normal / Fast in Settings.
 */
function nxAmbientMode(): string
{
    $mode = strtolower((string) nxSetting('ambient_speed', 'normal'));
    if (in_array($mode, ['force_off', 'on', 'off', 'high', 'normal', 'low'], true)) {
        return $mode;
    }
    // Legacy values
    return match ($mode) {
        'off', 'slow'  => 'off',
        'fast'         => 'high',
        default        => 'normal',
    };
}

/** Record an admin action in audit_log. */
function nxAudit(string $action, ?string $entityType = null, $entityId = null, ?string $details = null): void
{
    try {
        $adminId = isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
        $adminName = isset($_SESSION['admin_name']) ? (string) $_SESSION['admin_name'] : null;

        $stmt = nxPdo()->prepare(
            'INSERT INTO audit_log (admin_id, admin_name, action, entity_type, entity_id, details, ip_address)
             VALUES (:aid, :aname, :action, :etype, :eid, :details, :ip)'
        );
        $stmt->execute([
            ':aid'     => $adminId,
            ':aname'   => $adminName,
            ':action'  => $action,
            ':etype'   => $entityType,
            ':eid'     => $entityId === null ? null : (string) $entityId,
            ':details' => $details,
            ':ip'      => nxClientIp(),
        ]);
    } catch (Throwable $e) {
        // Auditing must never break the action it is recording.
    }
}

function nxClientIp(): ?string
{
    return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null;
}

/**
 * Very small plain-text mailer built on PHP mail(). No SMTP, no paid service.
 * Returns true/false so callers can surface a fallback when Termux has no MTA.
 */
function nxSendMail(string $to, string $subject, string $body): bool
{
    $from = (string) env('MAIL_FROM', 'no-reply@localhost');
    $headers = "From: Nexora Logistics <" . $from . ">\r\n"
        . "Reply-To: " . $from . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n";

    $ok = false;
    try {
        $ok = @mail($to, $subject, $body, $headers);
    } catch (Throwable $e) {
        $ok = false;
    }

    // Keep a local copy so a reset link is still recoverable on hosts with no MTA.
    try {
        $logDir = dirname(__DIR__) . '/storage';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0700, true);
        }
        @file_put_contents(
            $logDir . '/mail.log',
            date('c') . " to={$to} subject={$subject}\n{$body}\n---\n",
            FILE_APPEND
        );
    } catch (Throwable $e) {
    }

    return $ok;
}

/** Human "Arriving in X h Y m" label, or "Arrived" / "—". */
function nxEtaText($remainingKm, $speedKmh): string
{
    $remainingKm = (float) $remainingKm;
    $speedKmh = (float) $speedKmh;

    if ($remainingKm <= 0.05) {
        return 'Arrived';
    }
    if ($speedKmh <= 0.05) {
        return '—';
    }

    $seconds = ($remainingKm / $speedKmh) * 3600;
    $hours = (int) floor($seconds / 3600);
    $minutes = (int) round(($seconds % 3600) / 60);

    if ($minutes === 60) {
        $hours++;
        $minutes = 0;
    }

    if ($hours <= 0) {
        return 'Arriving in ' . $minutes . ' min';
    }

    return 'Arriving in ' . $hours . ' h ' . $minutes . ' m';
}

/** True when the current session is a signed-in admin (used for previews). */
function nxIsAdminSession(): bool
{
    return !empty($_SESSION['admin_id']);
}

/** Resolve the requested tracking number from ?id= or ?tracking= (POST too). */
function nxTrackingFromRequest(): string
{
    $raw = $_POST['id'] ?? $_GET['id'] ?? $_POST['tracking'] ?? $_GET['tracking'] ?? '';
    $value = trim((string) $raw);

    if ($value === '') {
        return '';
    }

    // A numeric id= is accepted as a shipment primary key.
    if (ctype_digit($value)) {
        try {
            $stmt = nxPdo()->prepare('SELECT tracking_number FROM shipments WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => (int) $value]);
            $found = $stmt->fetchColumn();
            if ($found) {
                return (string) $found;
            }
        } catch (Throwable $e) {
        }
    }

    return $value;
}

function nxTrackerCookieName(string $tracking): string
{
    return 'nx_t_' . substr(md5(strtoupper(trim($tracking))), 0, 16);
}

/**
 * The customer's verified tracker record for a shipment, from the PHP session or
 * the persistent remember-me cookie. Returns null when not verified.
 */
function nxTrackerVerified(string $tracking): ?array
{
    $key = strtoupper(trim($tracking));
    if ($key === '') {
        return null;
    }

    if (!empty($_SESSION['nx_verified_lookups'][$key])) {
        return $_SESSION['nx_verified_lookups'][$key];
    }

    $cookieName = nxTrackerCookieName($key);
    $token = isset($_COOKIE[$cookieName]) ? (string) $_COOKIE[$cookieName] : '';
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
        return null;
    }

    try {
        $stmt = nxPdo()->prepare(
            'SELECT * FROM tracking_lookups
             WHERE tracking_number = :t
               AND session_token = :tok
               AND (token_expires_at IS NULL OR token_expires_at > CURRENT_TIMESTAMP)
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':t' => $tracking, ':tok' => $token]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        return null;
    }

    if (!$row) {
        return null;
    }

    $entry = [
        'name'    => (string) $row['customer_name'],
        'email'   => (string) $row['customer_email'],
        'phone'   => (string) $row['customer_phone'],
        'at'      => isset($row['created_at']) ? (string) $row['created_at'] : '',
        'verified' => (int) $row['verified'],
        'method'  => (string) $row['match_method'],
        'token'   => $token,
    ];

    $_SESSION['nx_verified_lookups'][$key] = $entry;

    return $entry;
}

/**
 * Persist a tracker visit + issue a remember-me cookie.
 * $verified = 1 when email/phone matched; 0 for shipments with no contact on file.
 */
function nxRegisterTracker(
    string $tracking,
    string $name,
    string $email,
    string $phone,
    int $verified,
    string $method
): array {
    $token = bin2hex(random_bytes(32));
    $expires = gmdate('Y-m-d H:i:s', time() + 60 * 60 * 24 * 30); // 30 days

    $stmt = nxPdo()->prepare(
        'INSERT INTO tracking_lookups
            (tracking_number, customer_name, customer_email, customer_phone, ip_address, user_agent,
             session_token, token_expires_at, verified, match_method)
         VALUES
            (:t, :n, :e, :p, :ip, :ua, :tok, :exp, :verified, :method)'
    );
    $stmt->execute([
        ':t'        => $tracking,
        ':n'        => $name,
        ':e'        => $email,
        ':p'        => $phone,
        ':ip'       => nxClientIp(),
        ':ua'       => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ':tok'      => $token,
        ':exp'      => $expires,
        ':verified' => $verified,
        ':method'   => $method,
    ]);

    $entry = [
        'name'     => $name,
        'email'    => $email,
        'phone'    => $phone,
        'at'       => time(),
        'verified' => $verified,
        'method'   => $method,
        'token'    => $token,
    ];

    $key = strtoupper(trim($tracking));
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['nx_verified_lookups'][$key] = $entry;
    }

    if (!headers_sent()) {
        setcookie(nxTrackerCookieName($key), $token, [
            'expires'  => time() + 60 * 60 * 24 * 30,
            'path'     => '/',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    return $entry;
}

/**
 * Does a submitted email/phone match the shipment contact details?
 * Returns [ok(bool), verified(int), method(string), reason(string)].
 */
function nxCheckTrackerIdentity(array $shipment, string $email, string $phone): array
{
    $shipEmail = trim((string) ($shipment['customer_email'] ?? ''));
    $shipPhone = trim((string) ($shipment['customer_phone'] ?? ''));

    // No contact on file — allow access, flagged as unverified.
    if ($shipEmail === '' && $shipPhone === '') {
        return [true, 0, 'none', ''];
    }

    $emailOk = $shipEmail !== '' && strcasecmp($shipEmail, trim($email)) === 0;
    $phoneOk = $shipPhone !== '' && nxNormalisePhone($shipPhone) === nxNormalisePhone($phone);

    if ($emailOk && $phoneOk) {
        return [true, 1, 'email+phone', ''];
    }
    if ($emailOk) {
        return [true, 1, 'email', ''];
    }
    if ($phoneOk) {
        return [true, 1, 'phone', ''];
    }

    return [false, 0, '', 'The email or phone does not match our records for this tracking number.'];
}

/** Digits-only phone comparison so "+1 555 123 4567" matches "15551234567". */
function nxNormalisePhone(string $phone): string
{
    return preg_replace('/\D+/', '', $phone);
}

/** Absolute public URL, honoring the base path when hosted in a subfolder. */
function nxAbsoluteUrl(string $path = ''): string
{
    $base = defined('APP_URL') ? (string) APP_URL : (string) env('APP_URL', 'http://localhost:8000');
    $base = rtrim($base, '/');

    return $base . ($path === '' ? '/' : '/' . ltrim($path, '/'));
}

/** Shared customer-facing smart tracking URL (includes the tracking id). */
function nxTrackUrl(string $tracking): string
{
    return nxAbsoluteUrl('track.php?id=' . rawurlencode($tracking));
}
