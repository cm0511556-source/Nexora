<?php
declare(strict_types=1);

if (!function_exists('nxAttachCreateMedia')) {
    function nxAttachCreateMedia(int $shipmentId, bool $isPublic = true): array {
        $result = ['saved' => 0, 'errors' => []];

        if (empty($_FILES['pickup_media']) || !is_array($_FILES['pickup_media']['name'])) {
            return $result;
        }

        $db = \VibrantHub\Database::connection();
        $mediaDir = dirname(__DIR__) . '/assets/media';
        if (!is_dir($mediaDir)) @mkdir($mediaDir, 0755, true);

        $allowedMimes = [
            'image/png'       => ['ext' => 'png',  'type' => 'photo'],
            'image/jpeg'      => ['ext' => 'jpg',  'type' => 'photo'],
            'image/webp'      => ['ext' => 'webp', 'type' => 'photo'],
            'image/gif'       => ['ext' => 'gif',  'type' => 'photo'],
            'video/mp4'       => ['ext' => 'mp4',  'type' => 'video'],
            'video/quicktime' => ['ext' => 'mov',  'type' => 'video'],
            'video/webm'      => ['ext' => 'webm', 'type' => 'video'],
        ];

        $MAX_IMAGE = 2097152;
        $MAX_VIDEO = 20971520;

        $f = $_FILES['pickup_media'];
        $count = count($f['name']);

        $maxOrder = (int)$db->query("SELECT COALESCE(MAX(sort_order),0) FROM shipment_media WHERE shipment_id = " . $shipmentId)->fetchColumn();

        $insert = $db->prepare('INSERT INTO shipment_media (shipment_id, media_type, file_path, mime_type, file_size, caption, sort_order, is_public, uploaded_by, context) VALUES (:sid, :mt, :fp, :mm, :fs, NULL, :so, :ip, :by, :ctx)');

        for ($i = 0; $i < $count; $i++) {
            if ($f['error'][$i] !== UPLOAD_ERR_OK) { $result['errors'][] = $f['name'][$i] . ': error ' . $f['error'][$i]; continue; }
            $tmp  = $f['tmp_name'][$i];
            $size = (int)$f['size'][$i];
            if (!is_uploaded_file($tmp)) { $result['errors'][] = $f['name'][$i] . ': not an uploaded file'; continue; }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($tmp) ?: '';
            if (!isset($allowedMimes[$mime])) { $result['errors'][] = $f['name'][$i] . ': unsupported type'; continue; }

            $info = $allowedMimes[$mime];
            $limit = $info['type'] === 'photo' ? $MAX_IMAGE : $MAX_VIDEO;
            if ($size > $limit) { $result['errors'][] = $f['name'][$i] . ': too large'; continue; }

            $newName = 'media-' . $shipmentId . '-' . bin2hex(random_bytes(6)) . '.' . $info['ext'];
            $dest = $mediaDir . '/' . $newName;
            if (!move_uploaded_file($tmp, $dest)) { $result['errors'][] = $f['name'][$i] . ': save failed'; continue; }

            $maxOrder++;
            try {
                $insert->execute([
                    ':sid' => $shipmentId,
                    ':mt'  => $info['type'],
                    ':fp'  => $newName,
                    ':mm'  => $mime,
                    ':fs'  => $size,
                    ':so'  => $maxOrder,
                    ':ip'  => $isPublic ? 1 : 0,
                    ':by'  => $_SESSION['admin_id'] ?? null,
                    ':ctx' => 'pickup',
                ]);
                $result['saved']++;
            } catch (\Throwable $e) {
                @unlink($dest);
                $result['errors'][] = $f['name'][$i] . ': ' . $e->getMessage();
            }
        }

        return $result;
    }
}
