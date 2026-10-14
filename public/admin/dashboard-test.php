<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . "/config/config.php";
require_once BASE_PATH . "/app/Database.php";
require_once BASE_PATH . "/app/Auth.php";

use VibrantHub\Auth;
use VibrantHub\Database;

Auth::requireAdmin();

echo "<h1>Dashboard Diagnostic</h1>";

echo "<p>1. Authentication: OK</p>";

$db = Database::connection();

echo "<p>2. Database connection: OK</p>";

$total = (int) $db->query("SELECT COUNT(*) FROM shipments")->fetchColumn();

echo "<p>3. Shipments query: OK</p>";
echo "<p>Total shipments: " . $total . "</p>";

$admin = Auth::admin();

echo "<p>4. Admin data: OK</p>";

echo "<pre>";
var_export($admin);
echo "</pre>";

echo "<p style='color:green;font-weight:bold;'>ALL DASHBOARD TESTS PASSED</p>";
