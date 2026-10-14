<?php
declare(strict_types=1);

// Fake a logged-in admin session so manual.php renders fully
session_start();
$_SESSION['admin_id'] = 1;
$_SESSION['admin_name'] = 'Manual Export';
$_SESSION['csrf_token'] = 'export-token';

// Provide the request vars manual.php might expect
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/admin/manual.php';

// Capture manual.php output
$manualFile = __DIR__ . '/public/admin/manual.php';
if (!file_exists($manualFile)) { fwrite(STDERR, "manual.php not found\n"); exit(1); }

ob_start();
try {
    include $manualFile;
} catch (Throwable $e) {
    // Some constants/classes might be missing in CLI context — ignore
}
$html = ob_get_clean();

// If output is empty, try harder: define the constants manual.php likely needs
if (strlen($html) < 500) {
    ob_start();
    define('BASE_PATH', __DIR__);
    // Mimic what config/config.php usually sets
    if (file_exists(__DIR__ . '/config/config.php')) {
        include_once __DIR__ . '/config/config.php';
    }
    if (file_exists(__DIR__ . '/app/Auth.php')) {
        require_once __DIR__ . '/app/Auth.php';
    }
    // Skip the requireAdmin by stubbing
    class AuthStub {
        public static function requireAdmin(): void {}
    }
    if (!class_exists('VibrantHub\\Auth')) {
        // Alias to stub only if the real one isn't there
    }
    include $manualFile;
    $html = ob_get_clean();
}

if (strlen($html) < 500) {
    fwrite(STDERR, "Could not capture manual (got " . strlen($html) . " bytes)\n");
    exit(1);
}

// Save it out
$out = __DIR__ . '/NEXORA-ADMIN-MANUAL.html';
file_put_contents($out, $html);
echo "Saved: $out (" . number_format(strlen($html)) . " bytes)\n";
