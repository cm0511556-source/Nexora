<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once BASE_PATH . '/app/Database.php';
require_once BASE_PATH . '/app/Auth.php';
require_once BASE_PATH . '/app/Tracking/Engine.php';

use VibrantHub\Auth;
use VibrantHub\Database;
use VibrantHub\Tracking\Engine;

Auth::requireAdmin();

header('Content-Type: application/json; charset=utf-8');

try {
    $shipmentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

    if (!$shipmentId || $shipmentId < 1) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid shipment ID.'
        ]);
        exit;
    }

    $engine = new Engine(Database::connection());
    $state = $engine->tick($shipmentId);

    echo json_encode([
        'success' => true,
        'data' => $state
    ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Tracking engine error.'
    ]);
}
