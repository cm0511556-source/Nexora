<?php
declare(strict_types=1);

$file = __DIR__ . '/Engine.php.movement-test';
$source = file_get_contents($file);

if ($source === false) {
    exit("ERROR: Cannot read candidate file.\n");
}

$start = strpos($source, '    private function moveAlongStoredRoute(');
$end = strpos($source, '    private function arriveAtTarget(', $start === false ? 0 : $start);

if ($start === false || $end === false) {
    exit("ERROR: Method boundaries not found. Candidate unchanged.\n");
}

$method = <<<'METHOD'
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

        if (count($points) < 2 || $distanceKm < 0.0) {
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

        $cursor = (int)($control['current_route_sequence'] ?? 0);
        $startIndex = 0;

        foreach ($points as $index => $point) {
            if ($point['sequence'] >= $cursor) {
                $startIndex = max(0, $index - 1);
                break;
            }
        }

        $endIndex = count($points) - 1;

        if ($nextWaypoint !== null && isset($nextWaypoint['id'])) {
            foreach ($points as $index => $point) {
                if ($point['waypoint_id'] === (int)$nextWaypoint['id']) {
                    $endIndex = $index;
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
        $previousTravelled = max(
            0.0,
            (float)($shipment['distance_travelled'] ?? 0.0)
        );
        $heading = (float)($shipment['heading'] ?? 0.0);

        $segmentLengths = [];
        $cumulative = [$startIndex => 0.0];
        $geometryTotal = 0.0;

        for ($i = $startIndex; $i < $endIndex; $i++) {
            $length = $this->distanceKm(
                $points[$i]['latitude'],
                $points[$i]['longitude'],
                $points[$i + 1]['latitude'],
                $points[$i + 1]['longitude']
            );

            $segmentLengths[$i] = $length;
            $geometryTotal += $length;
            $cumulative[$i + 1] = $geometryTotal;
        }

        $storedTotal = (float)($shipment['distance_total'] ?? 0.0);
        $total = $storedTotal > 0.0 ? $storedTotal : $geometryTotal;

        if ($geometryTotal <= 0.000001 || $total <= 0.000001) {
            return null;
        }

        $radius = self::EARTH_RADIUS_KM;

        $destination = static function (
            float $fromLat,
            float $fromLng,
            float $bearingDeg,
            float $distance
        ) use ($radius): array {
            $angular = $distance / $radius;
            $bearing = deg2rad($bearingDeg);
            $lat1 = deg2rad($fromLat);
            $lng1 = deg2rad($fromLng);

            $sinLat2 = sin($lat1) * cos($angular)
                + cos($lat1) * sin($angular) * cos($bearing);
            $lat2 = asin(max(-1.0, min(1.0, $sinLat2)));

            $lng2 = $lng1 + atan2(
                sin($bearing) * sin($angular) * cos($lat1),
                cos($angular) - sin($lat1) * sin($lat2)
            );

            $outLng = fmod(rad2deg($lng2) + 540.0, 360.0) - 180.0;

            return [rad2deg($lat2), $outLng];
        };

        /*
         * Project onto each route segment using spherical cross-track
         * geometry, matching the great-circle movement used below.
         */
        $bestDistance = INF;
        $bestIndex = $startIndex;
        $bestAlong = 0.0;
        $bestLat = $points[$startIndex]['latitude'];
        $bestLng = $points[$startIndex]['longitude'];

        for ($i = $startIndex; $i < $endIndex; $i++) {
            $a = $points[$i];
            $b = $points[$i + 1];
            $segmentLength = $segmentLengths[$i] ?? 0.0;

            if ($segmentLength <= 0.000001) {
                continue;
            }

            $segmentBearing = deg2rad($this->bearing(
                $a['latitude'],
                $a['longitude'],
                $b['latitude'],
                $b['longitude']
            ));

            $distanceToStart = $this->distanceKm(
                $a['latitude'],
                $a['longitude'],
                $lat,
                $lng
            ) / $radius;

            $bearingToVehicle = deg2rad($this->bearing(
                $a['latitude'],
                $a['longitude'],
                $lat,
                $lng
            ));

            $difference = $bearingToVehicle - $segmentBearing;

            $alongAngle = atan2(
                sin($distanceToStart) * cos($difference),
                cos($distanceToStart)
            );

            $along = max(
                0.0,
                min($segmentLength, $alongAngle * $radius)
            );

            [$candidateLat, $candidateLng] = $destination(
                $a['latitude'],
                $a['longitude'],
                rad2deg($segmentBearing),
                $along
            );

            $offRoute = $this->distanceKm(
                $lat,
                $lng,
                $candidateLat,
                $candidateLng
            );

            if ($offRoute < $bestDistance) {
                $bestDistance = $offRoute;
                $bestIndex = $i;
                $bestAlong = $along;
                $bestLat = $candidateLat;
                $bestLng = $candidateLng;
            }
        }

        $movedThisTick = 0.0;

        /*
         * Move toward the route without teleporting. If the vehicle
         * cannot reach the route this tick, return its actual position.
         */
        if ($bestDistance > 0.001) {
            $step = min($remainingMove, $bestDistance);

            if ($step > 0.0) {
                $heading = $this->bearing($lat, $lng, $bestLat, $bestLng);
                [$lat, $lng] = $destination($lat, $lng, $heading, $step);
                $remainingMove -= $step;
                $movedThisTick += $step;
            }

            if ($bestDistance > $step + 0.001) {
                $travelled = min($total, $previousTravelled + $movedThisTick);

                return [
                    'lat' => $lat,
                    'lng' => $lng,
                    'heading' => $heading,
                    'total' => $total,
                    'travelled' => $travelled,
                    'remaining' => max(0.0, $total - $travelled),
                    'route_sequence' => $points[$bestIndex]['sequence'],
                    'at_target' => false,
                ];
            }

            $lat = $bestLat;
            $lng = $bestLng;
        }

        $routePosition = ($cumulative[$bestIndex] ?? 0.0) + $bestAlong;
        $index = $bestIndex;

        while ($index < $endIndex && $remainingMove > 0.000001) {
            $a = $points[$index];
            $b = $points[$index + 1];
            $segmentLength = $segmentLengths[$index] ?? 0.0;

            if ($segmentLength <= 0.000001) {
                $index++;
                continue;
            }

            $segmentStart = $cumulative[$index] ?? 0.0;
            $along = max(
                0.0,
                min($segmentLength, $routePosition - $segmentStart)
            );
            $available = max(0.0, $segmentLength - $along);

            if ($available <= 0.000001) {
                $index++;
                continue;
            }

            $step = min($remainingMove, $available);
            $heading = $this->bearing(
                $a['latitude'],
                $a['longitude'],
                $b['latitude'],
                $b['longitude']
            );

            [$lat, $lng] = $destination(
                $a['latitude'],
                $a['longitude'],
                $heading,
                $along + $step
            );

            $routePosition += $step;
            $remainingMove -= $step;
            $movedThisTick += $step;

            if ($step >= $available - 0.000001) {
                $index++;
            } else {
                break;
            }
        }

        $travelled = min($total, max(0.0, $previousTravelled + $movedThisTick));
        $remaining = max(0.0, $total - $travelled);
        $sequence = $points[min($index, $endIndex)]['sequence'];
        $atTarget = $index >= $endIndex && $remainingMove > 0.000001;

        return [
            'lat' => $lat,
            'lng' => $lng,
            'heading' => $heading,
            'total' => $total,
            'travelled' => $travelled,
            'remaining' => $remaining,
            'route_sequence' => $sequence,
            'at_target' => $atTarget,
        ];
    }

METHOD;

$newSource = substr($source, 0, $start)
    . $method
    . substr($source, $end);

if (file_put_contents($file, $newSource) === false) {
    exit("ERROR: Could not write candidate file.\n");
}

echo "Candidate movement method replaced. Live engine and database untouched.\n";
