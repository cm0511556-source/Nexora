<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once BASE_PATH . '/app/Database.php';
require_once BASE_PATH . '/app/Auth.php';
use VibrantHub\Auth;
use VibrantHub\Database;
Auth::requireAdmin();
header('Content-Type: application/json; charset=utf-8');
$shipmentId = (int)($_GET['shipment_id'] ?? 0);
if ($shipmentId < 1) { echo json_encode(['success'=>false,'message'=>'Invalid shipment.']); exit; }
$db = Database::connection();
$st = $db->prepare('SELECT id, media_type, file_path, mime_type, file_size, caption, sort_order, is_public, created_at FROM shipment_media WHERE shipment_id = :sid ORDER BY sort_order ASC, id ASC');
$st->execute([':sid' => $shipmentId]);
echo json_encode(['success'=>true,'data'=>$st->fetchAll()], JSON_UNESCAPED_SLASHES);
