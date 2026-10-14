<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . "/config/config.php";
require_once BASE_PATH . "/app/Database.php";
require_once BASE_PATH . "/app/Auth.php";

use VibrantHub\Auth;

Auth::startSession();

echo "<h1>Session Test</h1>";
echo "<p>Session ID: " . htmlspecialchars(session_id()) . "</p>";
echo "<p>Auth check: ";
var_dump(Auth::check());
echo "</p>";

echo "<pre>";
var_export($_SESSION);
echo "</pre>";
