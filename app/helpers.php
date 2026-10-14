<?php

declare(strict_types=1);

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return '' === $needle || false !== strpos($haystack, $needle);
    }
}

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return '' === $needle || 0 === strncmp($haystack, $needle, strlen($needle));
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        return '' === $needle || substr($haystack, -strlen($needle)) === $needle;
    }
}

function loadEnv(string $file): void
{
    if (!is_file($file)) {
        return;
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }

        if (strpos($line, '=') === false) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);

        $key = trim($key);
        $value = trim($value);

        if (
            strlen($value) >= 2 &&
            (($value[0] === '"' && $value[-1] === '"') ||
             ($value[0] === "'" && $value[-1] === "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function env(string $key, $default = null)
{
    return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * URL prefix under which public/ is mounted.
 *
 * Makes asset/link URLs work whether the built-in server is started from the
 * project root (`php -S localhost:8000`) or with public/ as document root
 * (`php -S localhost:8000 -t public`).
 */
function nxWebBase(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');

    if ($dir === '.' || $dir === '') {
        return '';
    }

    // Entry scripts live in public/; admin scripts in public/admin[/api].
    if (preg_match('#^(.*?)/(?:admin/api|admin)$#', $dir, $m)) {
        $dir = $m[1];
    }

    return $dir === '/' ? '' : $dir;
}

/** Root-relative URL for a path inside public/ (e.g. base_url('admin/login.php')). */
function base_url(string $path = ''): string
{
    return nxWebBase() . '/' . ltrim($path, '/');
}

/** Root-relative URL for a file inside public/assets/. */
function asset(string $path = ''): string
{
    return nxWebBase() . '/assets/' . ltrim($path, '/');
}

/**
 * Vector basemap style used by every MapLibre map.
 *
 * Defaults to OpenFreeMap "Liberty" — free, no API key, no account.
 * Override in .env with MAP_STYLE_URL, e.g.
 *   https://tiles.openfreemap.org/styles/bright
 *   https://tiles.openfreemap.org/styles/positron
 *   https://tiles.openfreemap.org/styles/dark
 */
function nxMapStyleUrl(): string
{
    $default = 'https://tiles.openfreemap.org/styles/liberty';
    $url = trim((string) env('MAP_STYLE_URL', $default));

    return $url !== '' ? $url : $default;
}

/** Absolute URL built from APP_URL when config has defined it. */
function app_url(string $path = ''): string
{
    $base = defined('APP_URL') ? APP_URL : (string) env('APP_URL', 'http://localhost:8000');

    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);

    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    exit;
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrf(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}
