<?php
declare(strict_types=1);

$candidate = __DIR__ . '/app/Tracking/Engine.php.route-aware-candidate';
$backup = $candidate . '.before-movement-v4';

if (!is_file($candidate) || !is_file($backup)) {
    exit("ERROR: Candidate or backup missing. No changes made.\n");
}

$source = file_get_contents($candidate);
if ($source === false) {
    exit("ERROR: Cannot read candidate. No changes made.\n");
}

$start = strpos($source, 'private function moveAlongStoredRoute(');
$end = strpos($source, 'private function arriveAtTarget(', $start === false ? 0 : $start);

if ($start === false || $end === false || $end <= $start) {
    exit("ERROR: Could not locate method boundaries. No changes made.\n");
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
    $previousTravelled = max(0.0, (float)($shipment['distance_travelled'] ?? 0.0));

    $segmentLengths = [];
    $cumulative = [$startIndex => 0.0];
    $geometryTotal = 0.0;

    for ($index = $startIndex; $index < $endIndex; $index++) {
        $length = $this->distanceKm(
            $points[$index]['latitude'],
            $points[$index]['longitude'],
            $points[$index + 1]['latitude'],
            $points[$index + 1]['longitude']
        );
        $segmentLengths[$index] = $length;
        $geometryTotal += $length;
        $cumulative[$index + 1] = $geometryTotal;
    }

    $storedTotal = (float)($shipment['distance_total'] ?? 0.0);
    $total = $storedTotal > 0.0 ? $storedTotal : $geometryTotal;

    if ($geometryTotal <= 0.000001 || $total <= 0.000001) {
        return null;
    }

    // Find the closest projection on the selected route range.
    $bestDistance = INF;
    $bestIndex = $startIndex;
    $bestFraction = 0.0;
    $bestLat = $points[$startIndex]['latitude'];
    $bestLng = $points[$startIndex]['longitude'];

    for ($index = $startIndex; $index < $endIndex; $index++) {
        $a = $points[$index];
        $b = $points[$index + 1];
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
        $fraction = $denominator > 0.0
            ? (($px - $ax) * $dx + ($py - $ay) * $dy) / $denominator
            : 0.0;
        $fraction = max(0.0, min(1.0, $fraction));

        $candidateLat = $a['latitude']
            + ($b['latitude'] - $a['latitude']) * $fraction;
        $candidateLng = $a['longitude']
            + ($b['longitude'] - $a['longitude']) * $fraction;

        $offRoute = $this->distanceKm($lat, $lng, $candidateLat, $candidateLng);
        if ($offRoute < $bestDistance) {
            $bestDistance = $offRoute;
            $bestIndex = $index;
            $bestFraction = $fraction;
            $bestLat = $candidateLat;
            $bestLng = $candidateLng;
        }
    }

    $heading = (float)($shipment['heading'] ?? 0.0);
    $movedThisTick = 0.0;

    // Travel to the route projection first; never teleport to it.
    if ($bestDistance > 0.001) {
        $step = min($remainingMove, $bestDistance);
        if ($step > 0.0) {
            $heading = $this->bearing($lat, $lng, $bestLat, $bestLng);
            $angular = $step / self::EARTH_RADIUS_KM;
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

    // Start from the projected point and continue along the route.
    $routePosition = ($cumulative[$bestIndex] ?? 0.0)
        + (($segmentLengths[$bestIndex] ?? 0.0) * $bestFraction);
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
        $along = max(0.0, min($segmentLength, $routePosition - $segmentStart));
        $available = max(0.0, $segmentLength - $along);

        if ($available <= 0.000001) {
            $index++;
            continue;
        }

        $step = min($remainingMove, $available);
        $heading = $this->bearing(
            $a['latitude'], $a['longitude'],
            $b['latitude'], $b['longitude']
        );

        $angular = ($along + $step) / self::EARTH_RADIUS_KM;
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

$newSource = substr($source, 0, $start) . $method . "\n\n    " . substr($source, $end);

if (strpos($newSource, 'private function arriveAtTarget(') === false) {
    exit("ERROR: Method boundary validation failed. Candidate unchanged.\n");
}

$temp = $candidate . '.tmp';
if (file_put_contents($temp, $newSource, LOCK_EX) === false) {
    exit("ERROR: Could not write temporary candidate. Original candidate unchanged.\n");
}

$lintOutput = [];
$lintStatus = 1;
exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($temp) . ' 2>&1', $lintOutput, $lintStatus);

if ($lintStatus !== 0) {
    @unlink($temp);
    echo implode("\n", $lintOutput) . "\n";
    exit("ERROR: Syntax check failed. Original candidate unchanged.\n");
}

if (!rename($temp, $candidate)) {
    @unlink($temp);
    exit("ERROR: Could not install candidate patch. Original candidate unchanged.\n");
}

echo "Candidate movement method updated.\n";
echo "Live app/Tracking/Engine.php was not changed.\n";
echo "Database was not accessed or changed.\n";
echo implode("\n", $lintOutput) . "\n";
