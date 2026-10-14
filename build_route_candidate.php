<?php
$source = 'app/Tracking/Engine.php.route-clean-candidate';
$target = 'app/Tracking/Engine.php.route-aware-candidate';

$code = file_get_contents($source);
if ($code === false) {
    exit("ERROR: Clean source file not found.\n");
}

$oldMove = <<<'OLD'
        if ($remainingToTarget <= 0.01 || $distanceKm >= $remainingToTarget) {
            return $this->arriveAtTarget(
                $shipment,
                $control,
                $nextWaypoint
            );
        }

        $fraction = min(1.0, $distanceKm / $remainingToTarget);
        $angularDistance = $remainingToTarget / self::EARTH_RADIUS_KM;
        $bearingRad = deg2rad($this->bearing($currentLat, $currentLng, $targetLat, $targetLng));
        $latRad = deg2rad($currentLat);
        $lngRad = deg2rad($currentLng);
        $step = $angularDistance * $fraction;
        $newLatRad = asin(
            sin($latRad) * cos($step)
            + cos($latRad) * sin($step) * cos($bearingRad)
        );
        $newLngRad = $lngRad + atan2(
            sin($bearingRad) * sin($step) * cos($latRad),
            cos($step) - sin($latRad) * sin($newLatRad)
        );
        $newLat = rad2deg($newLatRad);
        $newLng = fmod(rad2deg($newLngRad) + 540.0, 360.0) - 180.0;

        $heading = $this->bearing(
            $currentLat,
            $currentLng,
            $newLat,
            $newLng
        );

        $totalDistance = $this->distanceKm(
            (float)$shipment['origin_lat'],
            (float)$shipment['origin_lng'],
            (float)$shipment['destination_lat'],
            (float)$shipment['destination_lng']
        );

        $travelled = $this->distanceKm(
            (float)$shipment['origin_lat'],
            (float)$shipment['origin_lng'],
            $newLat,
            $newLng
        );

        $remaining = max(0.0, $totalDistance - $travelled);
OLD;

$newMove = <<<'NEW'
        $routeMove = $this->moveAlongStoredRoute(
            $shipment,
            $control,
            $nextWaypoint,
            $distanceKm
        );

        if ($routeMove !== null && $routeMove['at_target']) {
            return $this->arriveAtTarget(
                $shipment,
                $control,
                $nextWaypoint
            );
        }

        if ($routeMove !== null) {
            $newLat = $routeMove['lat'];
            $newLng = $routeMove['lng'];
            $heading = $routeMove['heading'];
            $totalDistance = $routeMove['total'];
            $travelled = $routeMove['travelled'];
            $remaining = $routeMove['remaining'];
            $newRouteSequence = $routeMove['route_sequence'];
        } else {
            if ($remainingToTarget <= 0.01 || $distanceKm >= $remainingToTarget) {
                return $this->arriveAtTarget(
                    $shipment,
                    $control,
                    $nextWaypoint
                );
            }

            $fraction = min(1.0, $distanceKm / $remainingToTarget);
            $angularDistance = $remainingToTarget / self::EARTH_RADIUS_KM;
            $bearingRad = deg2rad($this->bearing($currentLat, $currentLng, $targetLat, $targetLng));
            $latRad = deg2rad($currentLat);
            $lngRad = deg2rad($currentLng);
            $step = $angularDistance * $fraction;
            $newLatRad = asin(
                sin($latRad) * cos($step)
                + cos($latRad) * sin($step) * cos($bearingRad)
            );
            $newLngRad = $lngRad + atan2(
                sin($bearingRad) * sin($step) * cos($latRad),
                cos($step) - sin($latRad) * sin($newLatRad)
            );
            $newLat = rad2deg($newLatRad);
            $newLng = fmod(rad2deg($newLngRad) + 540.0, 360.0) - 180.0;

            $heading = $this->bearing(
                $currentLat,
                $currentLng,
                $newLat,
                $newLng
            );

            $totalDistance = $this->distanceKm(
                (float)$shipment['origin_lat'],
                (float)$shipment['origin_lng'],
                (float)$shipment['destination_lat'],
                (float)$shipment['destination_lng']
            );

            $travelled = $this->distanceKm(
                (float)$shipment['origin_lat'],
                (float)$shipment['origin_lng'],
                $newLat,
                $newLng
            );

            $remaining = max(0.0, $totalDistance - $travelled);
            $newRouteSequence = null;
        }
NEW;

if (substr_count($code, $oldMove) !== 1) {
    exit("ERROR: Movement section did not match exactly; no candidate created.\n");
}
$code = str_replace($oldMove, $newMove, $code);

$transactionNeedle = <<<'TX'
            $position->execute([
                ':shipment_id' => $shipmentId,
                ':lat' => $newLat,
                ':lng' => $newLng,
                ':speed' => $speed,
                ':heading' => $heading,
            ]);

            $this->db->commit();
TX;

$transactionReplacement = <<<'TX'
            $position->execute([
                ':shipment_id' => $shipmentId,
                ':lat' => $newLat,
                ':lng' => $newLng,
                ':speed' => $speed,
                ':heading' => $heading,
            ]);

            if ($newRouteSequence !== null) {
                $cursorStmt = $this->db->prepare(
                    'UPDATE tracking_controls
                     SET current_route_sequence = :seq,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE shipment_id = :shipment_id'
                );
                $cursorStmt->execute([
                    ':seq' => $newRouteSequence,
                    ':shipment_id' => $shipmentId,
                ]);
            }

            $this->db->commit();
TX;

if (substr_count($code, $transactionNeedle) !== 1) {
    exit("ERROR: Transaction section did not match exactly; no candidate created.\n");
}
$code = str_replace($transactionNeedle, $transactionReplacement, $code);

$helper = <<<'HELPER'

    /**
     * Move along stored route geometry without changing the database.
     * Returns null when no usable route exists, allowing legacy movement.
     */
    private function moveAlongStoredRoute(
        array $shipment,
        array $control,
        ?array $nextWaypoint,
        float $distanceKm
    ): ?array {
        $stmt = $this->db->prepare(
            'SELECT sequence, latitude, longitude, waypoint_id
             FROM shipment_route_points
             WHERE shipment_id = :id
             ORDER BY sequence ASC'
        );
        $stmt->execute([':id' => (int)$shipment['id']]);
        $points = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($points) < 2) {
            return null;
        }

        $cursor = (int)($control['current_route_sequence'] ?? 0);
        $startIndex = 0;

        foreach ($points as $i => $point) {
            if ((int)$point['sequence'] >= $cursor) {
                $startIndex = $i;
                break;
            }
            if ($i === count($points) - 1) {
                return null;
            }
        }

        // Never skip the active operational waypoint.
        $endIndex = count($points) - 1;
        if ($nextWaypoint !== null) {
            $waypointId = (int)$nextWaypoint['id'];
            $found = false;
            foreach ($points as $i => $point) {
                if ((int)($point['waypoint_id'] ?? 0) === $waypointId) {
                    $endIndex = $i;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return null;
            }
        }

        if ($startIndex >= $endIndex) {
            return null;
        }

        $lat = (float)$shipment['current_lat'];
        $lng = (float)$shipment['current_lng'];

        // Find the closest point only among the current and next route segments.
        $best = null;
        $lastSegment = min($endIndex - 1, $startIndex + 1);

        for ($i = $startIndex; $i <= $lastSegment; $i++) {
            $a = $points[$i];
            $b = $points[$i + 1];

            $meanLat = deg2rad(($lat + (float)$a['latitude'] + (float)$b['latitude']) / 3.0);
            $scaleX = max(0.01, cos($meanLat));
            $ax = (float)$a['longitude'] * $scaleX;
            $ay = (float)$a['latitude'];
            $bx = (float)$b['longitude'] * $scaleX;
            $by = (float)$b['latitude'];
            $px = $lng * $scaleX;
            $py = $lat;
            $dx = $bx - $ax;
            $dy = $by - $ay;
            $denom = $dx * $dx + $dy * $dy;
            $t = $denom > 0.0
                ? max(0.0, min(1.0, (($px - $ax) * $dx + ($py - $ay) * $dy) / $denom))
                : 0.0;

            $projLat = (float)$a['latitude'] + $t * ((float)$b['latitude'] - (float)$a['latitude']);
            $projLng = (float)$a['longitude'] + $t * ((float)$b['longitude'] - (float)$a['longitude']);
            $offRoute = $this->distanceKm($lat, $lng, $projLat, $projLng);

            if ($best === null || $offRoute < $best['off_route']) {
                $best = [
                    'index' => $i,
                    't' => $t,
                    'lat' => $projLat,
                    'lng' => $projLng,
                    'off_route' => $offRoute,
                ];
            }
        }

        if ($best === null || $best['off_route'] > 120.0) {
            return null;
        }

        $i = $best['index'];
        $t = $best['t'];
        $a = $points[$i];
        $b = $points[$i + 1];
        $segmentKm = $this->distanceKm(
            (float)$a['latitude'],
            (float)$a['longitude'],
            (float)$b['latitude'],
            (float)$b['longitude']
        );
        $remainingOnSegment = max(0.0, (1.0 - $t) * $segmentKm);
        $travel = max(0.0, $distanceKm);
        $newLat = $best['lat'];
        $newLng = $best['lng'];
        $heading = $this->bearing(
            (float)$a['latitude'],
            (float)$a['longitude'],
            (float)$b['latitude'],
            (float)$b['longitude']
        );
        $routeSequence = (int)$a['sequence'];
        $routeTravelled = 0.0;

        for ($j = 0; $j < $i; $j++) {
            $routeTravelled += $this->distanceKm(
                (float)$points[$j]['latitude'],
                (float)$points[$j]['longitude'],
                (float)$points[$j + 1]['latitude'],
                (float)$points[$j + 1]['longitude']
            );
        }
        $routeTravelled += max(0.0, $segmentKm - $remainingOnSegment);

        while (true) {
            $a = $points[$i];
            $b = $points[$i + 1];
            $segmentKm = $this->distanceKm(
                (float)$a['latitude'],
                (float)$a['longitude'],
                (float)$b['latitude'],
                (float)$b['longitude']
            );

            $t = $i === $best['index'] ? $best['t'] : 0.0;
            $available = max(0.0, (1.0 - $t) * $segmentKm);

            if ($travel <= $available || $i + 1 >= $endIndex) {
                $fraction = $segmentKm > 0.0
                    ? min(1.0, $t + ($travel / $segmentKm))
                    : 1.0;

                $angular = $segmentKm / self::EARTH_RADIUS_KM;
                $bearingRad = deg2rad($heading);
                $startLatRad = deg2rad((float)$a['latitude']);
                $startLngRad = deg2rad((float)$a['longitude']);
                $step = $angular * $fraction;

                $latRad = asin(
                    sin($startLatRad) * cos($step)
                    + cos($startLatRad) * sin($step) * cos($bearingRad)
                );
                $lngRad = $startLngRad + atan2(
                    sin($bearingRad) * sin($step) * cos($startLatRad),
                    cos($step) - sin($startLatRad) * sin($latRad)
                );

                $newLat = rad2deg($latRad);
                $newLng = fmod(rad2deg($lngRad) + 540.0, 360.0) - 180.0;
                $routeSequence = (int)$a['sequence'];
                $routeTravelled += min($travel, $available);
                $travel = 0.0;
                break;
            }

            $travel -= $available;
            $routeTravelled += $available;
            $i++;
            $routeSequence = (int)$points[$i]['sequence'];
            $heading = $this->bearing(
                (float)$points[$i]['latitude'],
                (float)$points[$i]['longitude'],
                (float)$points[$i + 1]['latitude'],
                (float)$points[$i + 1]['longitude']
            );
        }

        $total = 0.0;
        for ($j = 0; $j < count($points) - 1; $j++) {
            $total += $this->distanceKm(
                (float)$points[$j]['latitude'],
                (float)$points[$j]['longitude'],
                (float)$points[$j + 1]['latitude'],
                (float)$points[$j + 1]['longitude']
            );
        }

        $target = $points[$endIndex];
        $atTarget = $this->distanceKm(
            $newLat,
            $newLng,
            (float)$target['latitude'],
            (float)$target['longitude']
        ) <= 0.01;

        if ($atTarget) {
            $newLat = (float)$target['latitude'];
            $newLng = (float)$target['longitude'];
            $routeSequence = (int)$target['sequence'];
            $routeTravelled = 0.0;
            for ($j = 0; $j < $endIndex; $j++) {
                $routeTravelled += $this->distanceKm(
                    (float)$points[$j]['latitude'],
                    (float)$points[$j]['longitude'],
                    (float)$points[$j + 1]['latitude'],
                    (float)$points[$j + 1]['longitude']
                );
            }
        }

        return [
            'lat' => $newLat,
            'lng' => $newLng,
            'heading' => $heading,
            'total' => $total,
            'travelled' => min($total, $routeTravelled),
            'remaining' => max(0.0, $total - $routeTravelled),
            'route_sequence' => $routeSequence,
            'at_target' => $atTarget,
        ];
    }
HELPER;

$marker = "\n    private function arriveAtTarget(";
if (substr_count($code, $marker) !== 1) {
    exit("ERROR: Helper insertion point was not unique; no candidate created.\n");
}
$code = str_replace($marker, $helper . $marker, $code);

if (file_put_contents($target, $code) === false) {
    exit("ERROR: Could not write candidate.\n");
}
echo "Candidate created: {$target}\n";
