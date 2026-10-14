<?php
$file = __DIR__ . '/app/Tracking/Engine.php';
$source = file_get_contents($file);

if ($source === false) {
    exit("ERROR: Cannot read Engine.php\n");
}

if (strpos($source, 'private function moveAlongStoredRoute(') !== false) {
    exit("STOP: Route helper already exists. No changes made.\n");
}

$marker = '    private function arriveAtTarget(';

if (substr_count($source, $marker) !== 1) {
    exit("STOP: Could not locate arriveAtTarget safely. No changes made.\n");
}

$helper = <<<'CODE'
    /**
     * Follow stored route coordinates using spherical movement.
     * Returns null if route geometry is absent or unsuitable.
     */
    private function moveAlongStoredRoute(
        int $shipmentId,
        float $currentLat,
        float $currentLng,
        float $targetLat,
        float $targetLng,
        float $movementKm,
        ?array $targetWaypoint
    ): ?array {
        $stmt = $this->db->prepare(
            'SELECT latitude, longitude, sequence, waypoint_id
             FROM shipment_route_points
             WHERE shipment_id = :id
             ORDER BY sequence ASC'
        );
        $stmt->execute([':id' => $shipmentId]);
        $stored = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($stored) < 2) {
            return null;
        }

        $route = [];
        foreach ($stored as $point) {
            $lat = (float)$point['latitude'];
            $lng = (float)$point['longitude'];

            if (!is_finite($lat) || !is_finite($lng)
                || abs($lat) > 90 || abs($lng) > 180) {
                return null;
            }

            if (!$route || $this->distanceKm(
                $route[count($route) - 1][0],
                $route[count($route) - 1][1],
                $lat,
                $lng
            ) > 0.001) {
                $route[] = [$lat, $lng];
            }
        }

        if (count($route) < 2) {
            return null;
        }

        if ($targetWaypoint !== null) {
            $linked = false;
            foreach ($stored as $point) {
                if ((int)($point['waypoint_id'] ?? 0) === (int)$targetWaypoint['id']) {
                    $linked = true;
                    break;
                }
            }
            if (!$linked) {
                return null;
            }
        }

        // Find the closest segment to the current position.
        $bestIndex = 0;
        $bestT = 0.0;
        $bestError = INF;
        $cosLat = max(0.01, cos(deg2rad($currentLat)));

        for ($i = 0; $i < count($route) - 1; $i++) {
            [$aLat, $aLng] = $route[$i];
            [$bLat, $bLng] = $route[$i + 1];

            $ax = ($aLng - $currentLng) * $cosLat;
            $ay = $aLat - $currentLat;
            $bx = ($bLng - $currentLng) * $cosLat;
            $by = $bLat - $currentLat;
            $dx = $bx - $ax;
            $dy = $by - $ay;
            $den = $dx * $dx + $dy * $dy;

            $t = $den > 0
                ? max(0.0, min(1.0, -($ax * $dx + $ay * $dy) / $den))
                : 0.0;

            $errorX = $ax + $t * $dx;
            $errorY = $ay + $t * $dy;
            $error = $errorX * $errorX + $errorY * $errorY;

            if ($error < $bestError) {
                $bestError = $error;
                $bestIndex = $i;
                $bestT = $t;
            }
        }

        // Do not jump to unrelated route geometry.
        if (!is_finite($bestError) || sqrt($bestError) > 1.0) {
            return null;
        }

        $a = $route[$bestIndex];
        $b = $route[$bestIndex + 1];
        $lat = $a[0] + ($b[0] - $a[0]) * $bestT;
        $lng = $a[1] + ($b[1] - $a[1]) * $bestT;

        $total = 0.0;
        for ($i = 1; $i < count($route); $i++) {
            $total += $this->distanceKm(
                $route[$i - 1][0], $route[$i - 1][1],
                $route[$i][0], $route[$i][1]
            );
        }

        $travelled = 0.0;
        for ($i = 1; $i <= $bestIndex; $i++) {
            $travelled += $this->distanceKm(
                $route[$i - 1][0], $route[$i - 1][1],
                $route[$i][0], $route[$i][1]
            );
        }
        $travelled += $this->distanceKm($a[0], $a[1], $lat, $lng);

        $heading = $this->bearing($a[0], $a[1], $b[0], $b[1]);
        $remainingMove = max(0.0, $movementKm);

        for ($i = $bestIndex + 1; $i < count($route); $i++) {
            $nextLat = $route[$i][0];
            $nextLng = $route[$i][1];
            $segmentKm = $this->distanceKm($lat, $lng, $nextLat, $nextLng);

            if ($segmentKm <= 0.001) {
                $lat = $nextLat;
                $lng = $nextLng;
                continue;
            }

            $heading = $this->bearing($lat, $lng, $nextLat, $nextLng);

            if ($remainingMove >= $segmentKm) {
                $remainingMove -= $segmentKm;
                $travelled += $segmentKm;
                $lat = $nextLat;
                $lng = $nextLng;
                continue;
            }

            $angular = $remainingMove / self::EARTH_RADIUS_KM;
            $bearingRad = deg2rad($heading);
            $latRad = deg2rad($lat);
            $lngRad = deg2rad($lng);

            $newLatRad = asin(
                sin($latRad) * cos($angular)
                + cos($latRad) * sin($angular) * cos($bearingRad)
            );

            $newLngRad = $lngRad + atan2(
                sin($bearingRad) * sin($angular) * cos($latRad),
                cos($angular) - sin($latRad) * sin($newLatRad)
            );

            $newLat = rad2deg($newLatRad);
            $newLng = fmod(rad2deg($newLngRad) + 540.0, 360.0) - 180.0;
            $travelled += $remainingMove;

            return [
                'latitude' => $newLat,
                'longitude' => $newLng,
                'heading' => $this->bearing($lat, $lng, $newLat, $newLng),
                'total' => $total,
                'travelled' => min($total, $travelled),
                'remaining' => max(0.0, $total - $travelled),
            ];
        }

        return [
            'latitude' => $targetLat,
            'longitude' => $targetLng,
            'heading' => $heading,
            'total' => $total,
            'travelled' => $total,
            'remaining' => 0.0,
        ];
    }

CODE;

$patched = str_replace($marker, $helper . $marker, $source);
$preview = $file . '.route-preview';

if (file_put_contents($preview, $patched) === false) {
    exit("ERROR: Could not create preview.\n");
}

echo "Preview created successfully.\n";
echo "Engine.php remains unchanged.\n";
