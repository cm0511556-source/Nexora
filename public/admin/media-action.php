<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once BASE_PATH . '/app/Database.php';
require_once BASE_PATH . '/app/Auth.php';

use VibrantHub\Auth;
use VibrantHub\Database;

Auth::requireAdmin();
header('Content-Type: application/json; charset=utf-8');

function jout(bool $ok, string $msg, array $data = [], int $code = 200): never {
    http_response_code($code);
    echo json_encode(['success' => $ok, 'message' => $msg, 'data' => $data], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jout(false, 'POST required.', [], 405);
if (!verifyCsrf($_POST['csrf_token'] ?? null)) jout(false, 'Invalid or expired token.', [], 403);

$action     = trim((string)($_POST['action'] ?? ''));
$shipmentId = (int)($_POST['shipment_id'] ?? 0);

$context = trim((string)($_POST['context'] ?? 'general'));
$allowedContexts = ['general','pickup','incident','delivery','customs','damage'];
if (!in_array($context, $allowedContexts, true)) $context = 'general';
if ($shipmentId < 1) jout(false, 'Invalid shipment.', [], 400);

$db = Database::connection();

$sChk = $db->prepare('SELECT id FROM shipments WHERE id = :id LIMIT 1');
$sChk->execute([':id' => $shipmentId]);
if (!$sChk->fetch()) jout(false, 'Shipment not found.', [], 404);

$mediaDir = dirname(__DIR__) . '/assets/media';
if (!is_dir($mediaDir)) @mkdir($mediaDir, 0755, true);

const MAX_IMAGE_BYTES = 2097152;   // 2 MB
const MAX_VIDEO_BYTES = 20971520;  // 20 MB

$allowedMimes = [
    'image/png'      => ['ext' => 'png', 'type' => 'photo'],
    'image/jpeg'     => ['ext' => 'jpg', 'type' => 'photo'],
    'image/webp'     => ['ext' => 'webp','type' => 'photo'],
    'image/gif'      => ['ext' => 'gif', 'type' => 'photo'],
    'video/mp4'      => ['ext' => 'mp4', 'type' => 'video'],
    'video/quicktime'=> ['ext' => 'mov', 'type' => 'video'],
    'video/webm'     => ['ext' => 'webm','type' => 'video'],
];

try {
    if ($action === 'upload') {
        if (empty($_FILES['files'])) jout(false, 'No files uploaded.', [], 400);

        $f = $_FILES['files'];
        $count = is_array($f['name']) ? count($f['name']) : 1;
        $insert = $db->prepare('INSERT INTO shipment_media (shipment_id, media_type, file_path, mime_type, file_size, caption, sort_order, is_public, uploaded_by, context) VALUES (:sid, :mt, :fp, :mm, :fs, :cap, :so, :ip, :by, :ctx)');
        $saved = [];
        $errors = [];

        $maxOrder = (int)$db->query("SELECT COALESCE(MAX(sort_order),0) FROM shipment_media WHERE shipment_id = " . $shipmentId)->fetchColumn();

        for ($i = 0; $i < $count; $i++) {
            $name = is_array($f['name']) ? $f['name'][$i] : $f['name'];
            $tmp  = is_array($f['tmp_name']) ? $f['tmp_name'][$i] : $f['tmp_name'];
            $err  = is_array($f['error']) ? $f['error'][$i] : $f['error'];
            $size = is_array($f['size']) ? $f['size'][$i] : $f['size'];

            if ($err !== UPLOAD_ERR_OK) { $errors[] = "$name: upload error $err"; continue; }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($tmp) ?: '';
            if (!isset($allowedMimes[$mime])) { $errors[] = "$name: unsupported type ($mime)"; continue; }

            $info = $allowedMimes[$mime];
            $limit = $info['type'] === 'photo' ? MAX_IMAGE_BYTES : MAX_VIDEO_BYTES;
            if ($size > $limit) {
                $mb = $info['type'] === 'photo' ? '2 MB' : '20 MB';
                $errors[] = "$name: exceeds $mb limit";
                continue;
            }

            $newName = 'media-' . $shipmentId . '-' . bin2hex(random_bytes(6)) . '.' . $info['ext'];
            $dest = $mediaDir . '/' . $newName;
            if (!move_uploaded_file($tmp, $dest)) { $errors[] = "$name: could not save"; continue; }

            $maxOrder++;
            $insert->execute([
                ':sid' => $shipmentId,
                ':mt'  => $info['type'],
                ':fp'  => $newName,
                ':mm'  => $mime,
                ':fs'  => (int)$size,
                ':cap' => null,
                ':so'  => $maxOrder,
                ':ip'  => 1,
                ':by'  => $_SESSION['admin_id'] ?? null,
                ':ctx' => $context,
            ]);
            $saved[] = ['id' => (int)$db->lastInsertId(), 'file' => $newName, 'type' => $info['type']];
        }

        if (!$saved && $errors) jout(false, implode(' · ', $errors), [], 400);
        $msg = count($saved) . ' file(s) uploaded.';
        if ($errors) $msg .= ' Skipped: ' . implode(' · ', $errors);
        jout(true, $msg, ['saved' => $saved]);
    }

    if ($action === 'delete') {
        $mediaId = (int)($_POST['media_id'] ?? 0);
        $st = $db->prepare('SELECT file_path FROM shipment_media WHERE id = :id AND shipment_id = :sid LIMIT 1');
        $st->execute([':id' => $mediaId, ':sid' => $shipmentId]);
        $row = $st->fetch();
        if (!$row) jout(false, 'Media not found.', [], 404);
        $path = $mediaDir . '/' . basename($row['file_path']);
        if (is_file($path)) @unlink($path);
        $db->prepare('DELETE FROM shipment_media WHERE id = :id')->execute([':id' => $mediaId]);
        jout(true, 'Deleted.');
    }

    if ($action === 'toggle') {
        $mediaId = (int)($_POST['media_id'] ?? 0);
        $db->prepare('UPDATE shipment_media SET is_public = 1 - is_public WHERE id = :id AND shipment_id = :sid')
           ->execute([':id' => $mediaId, ':sid' => $shipmentId]);
        jout(true, 'Visibility updated.');
    }

    if ($action === 'caption') {
        $mediaId = (int)($_POST['media_id'] ?? 0);
        $cap = trim((string)($_POST['caption'] ?? ''));
        $db->prepare('UPDATE shipment_media SET caption = :c WHERE id = :id AND shipment_id = :sid')
           ->execute([':c' => $cap !== '' ? $cap : null, ':id' => $mediaId, ':sid' => $shipmentId]);
        jout(true, 'Caption saved.');
    }

    jout(false, 'Unknown action.', [], 400);
} catch (Throwable $e) {
    jout(false, 'Upload failed: ' . $e->getMessage(), [], 500);
}
