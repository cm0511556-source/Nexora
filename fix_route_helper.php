<?php
$file = 'app/Tracking/Engine.php.route-aware-candidate';
$source = file_get_contents($file);

if ($source === false) {
    exit("ERROR: Candidate engine not found.\n");
}

$start = strpos($source, '    private function moveAlongStoredRoute(');
$end = strpos($source, '    private function arriveAtTarget(', $start === false ? 0 : $start);

if ($start === false || $end === false) {
    exit("ERROR: Method boundaries not found. No changes made.\n");
}

$backup = $file . '.before-stable-fix';
if (!copy($file, $backup)) {
    exit("ERROR: Could not create backup. No changes made.\n");
}

$replacement = <<<'METHOD'
    private function moveAlongStoredRoute(
        array $shipment,
        array $control,
        ?array $nextWaypoint,
        float $distanceKm
    ): ?array {
        $shipmentId = (int)($shipment['id'] ?? 0);

        $stmt = $this->db->prepare(
            'SELECT sequence, latitude, longitude, waypoint_id
             FROM shipment_route_points
             WHERE shipment_id = :shipment_id
             ORDER BY sequence ASC'
        );
        $stmt->execute([':shipment_id' => $shipmentId]);
        $points = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($points) < 2 || $distanceKm < 0) {
            return null;
        }

        foreach ($points as &$point) {
            $point['sequence'] = (int)$point['sequence'];
            $point['latitude'] = (float)$point['latitude'];
            $point['longitude'] = (float)$point['longitude'];
            $point['waypoint_id'] = isset($point['waypoint_id'])
                ? (int)$point['waypoint_id'] : null;
        }
        unset($point);

        $startIndex = 0;
        $cursor = (int)($control['current_route_sequence'] ?? 0);

        foreach ($points as $i => $point) {
            if ($point['sequence'] >= $cursor) {
                $startIndex = max(0, $i - 1);
                break;
            }
        }

        $endIndex = count($points) - 1;
        if ($nextWaypoint !== null && isset($nextWaypoint['id'])) {
            foreach ($points as $i => $point) {
                if ($point['waypoint_id'] === (int)$nextWaypoint['id']) {
                    $endIndex = $i;
                    break;
                }
            }
        }

        if ($endIndex <= $startIndex) {
            $startIndex = 0;
            $endIndex = count($points) - 1;
        }

        $lat = (float)$shipment['current_lat'];
        $lng = (float)$shipment['current_lng'];
        $remainingMove = max(0.0, $distanceKm);

        $total = 0.0;
        $segmentLengths = [];
        for ($i = $startIndex; $i < $endIndex; $i++) {
            $length = $this->distanceKm(
                $points[$i]['latitude'],
                $points[$i]['longitude'],
                $points[$i + 1]['latitude'],
                $points[$i + 1]['longitude']
            );
            $segmentLengths[$i] = $length;
            $total += $length;
        }

        if ($total <= 0.000001) {
            return null;
        }

        $routeTravelled = 0.0;
        $bestDistance = INF;
        $bestIndex = $startIndex;
        $bestFraction = 0.0;
        $bestLat = $points[$startIndex]['latitude'];
        $bestLng = $points[$startIndex]['longitude'];

        // Project the current location onto the stored route segments.
        for ($i = $startIndex; $i < $endIndex; $i++) {
            $a = $points[$i];
            $b = $points[$i + 1];

            $meanLat = deg2rad(($a['latitude'] + $b['latitude']) / 2.0);
            $scaleX = max(0.01, abs(cos($meanLat)));

            $ax = $a['longitude'] * $scaleX;
            $ay = $a['latitude'];
            $bx = $b['longitude'] * $scaleX;
            $by = $b['latitude'];
            $px = $lng * $scaleX;
            $py = $lat;

            $dx = $bx - $ax;
            $dy = $by - $ay;
            $denominator = $dx * $dx + $dy * $dy;
            $t = $denominator > 0
                ? (($px - $ax) * $dx + ($py - $ay) * $dy) / $denominator
                : 0.0;
            $t = max(0.0, min(1.0, $t));

            $candidateLat = $a['latitude']
                + ($b['latitude'] - $a['latitude']) * $t;
            $candidateLng = $a['longitude']
                + ($b['longitude'] - $a['longitude']) * $t;

            $offRoute = $this->distanceKm(
                $lat, $lng, $candidateLat, $candidateLng
            );

            if ($offRoute < $bestDistance) {
                $bestDistance = $offRoute;
                $bestIndex = $i;
                $bestFraction = $t;
                $bestLat = $candidateLat;
                $bestLng = $candidateLng;
            }
        }

        $heading = (float)($shipment['heading'] ?? 0.0);

        // Do not teleport a shipment onto a distant route.
        // First travel toward the nearest point on the route.
        if ($bestDistance > 1.0) {
            $towardRoute = min($remainingMove, $bestDistance);
            if ($towardRoute > 0.0) {
                $heading = $this->bearing($lat, $lng, $bestLat, $bestLng);
                $angular = $towardRoute / self::EARTH_RADIUS_KM;
                $bearingRad = deg2rad($heading);
                $lat1 = deg2rad($lat);
                $lng1 = deg2rad($lng);

                $lat2 = asin(
                    sin($lat1) * cos($angular)
                    + cos($lat1) * sin($angular) * cos($bearingRad)
                );
                $lng2 = $lng1 + atan2(
                    sin($bearingRad) * sin($angular) * cos($lat1),
                    cos($angular) - sin($lat1) * sin($lat2)
                );

                $lat = rad2deg($lat2);
                $lng = rad2deg($lng2);
                $remainingMove -= $towardRoute;
            }

            if ($bestDistance > $towardRoute + 0.001) {
                $travelled = (float)($shipment['distance_travelled'] ?? 0.0);
                $remaining = (float)($shipment['distance_remaining'] ?? $total);

                return [
                    'lat' => $lat,
                    'lng' => $lng,
                    'heading' => $heading,
                    'total' => $total,
                    'travelled' => $travelled,
                    'remaining' => $remaining,
                    'route_sequence' => $points[$bestIndex]['sequence'],
                    'at_target' => false,
                ];
            }

            $lat = $bestLat;
            $lng = $bestLng;
        }

        $routeTravelled = 0.0;
        for ($i = $startIndex; $i < $bestIndex; $i++) {
            $routeTravelled += $segmentLengths[$i] ?? 0.0;
        }
        $routeTravelled += ($segmentLengths[$bestIndex] ?? 0.0) * $bestFraction;

        $i = $bestIndex;
        while ($i < $endIndex && $remainingMove > 0.000001) {
            $a = $points[$i];
            $b = $points[$i + 1];
            $segmentLength = $segmentLengths[$i] ?? 0.0;

            if ($segmentLength <= 0.000001) {
                $i++;
                continue;
            }

            $fromStart = max(
                0.0,
                min($segmentLength, $routeTravelled - array_sum(
                    array_slice($segmentLengths, $startIndex, $i - $startIndex, true)
                ))
            );

            $available = max(0.0, $segmentLength - $fromStart);
            $step = min($remainingMove, $available);

            if ($step > 0.0) {
                $heading = $this->bearing(
                    $a['latitude'], $a['longitude'],
                    $b['latitude'], $b['longitude']
                );

                $angular = ($fromStart + $step) / self::EARTH_RADIUS_KM;
                $bearingRad = deg2rad($heading);
                $lat1 = deg2rad($a['latitude']);
                $lng1 = deg2rad($a['longitude']);

                $lat2 = asin(
                    sin($lat1) * cos($angular)
                    + cos($lat1) * sin($angular) * cos($bearingRad)
                );
                $lng2 = $lng1 + atan2(
                    sin($bearingRad) * sin($angular) * cos($lat1),
                    cos($angular) - sin($lat1) * sin($lat2)
                );

                $lat = rad2deg($lat2);
                $lng = rad2deg($lng2);

                $routeTravelled += $step;
                $remainingMove -= $step;
            }

            if ($step >= $available - 0.000001) {
                $i++;
            } else {
                break;
            }
        }

        $routeTravelled = min($total, max(0.0, $routeTravelled));
        $remaining = max(0.0, $total - $routeTravelled);
        $sequence = $points[min($i, $endIndex)]['sequence'];
        $atTarget = $i >= $endIndex && $remaining <= 0.001;

        return [
            'lat' => $lat,
            'lng' => $lng,
            'heading' => $heading,
            'total' => $total,
            'travelled' => $routeTravelled,
            'remaining' => $remaining,
            'route_sequence' => $sequence,
            'at_target' => $atTarget,
        ];
    }

METHOD;

$updated = substr($source, 0, $start)
    . $replacement
    . substr($source, $end);

if (file_put_contents($file, $updated) === false) {
    copy($backup, $file);
    exit("ERROR: Could not write candidate; backup restored.\n");
}

echo "Candidate movement method replaced.\n";
echo "Backup: $backup\n";
