from pathlib import Path
import re

target = Path("patches/Engine.php.movement-test")
source = target.read_text()

pattern = re.compile(
    r"    private function moveAlongStoredRoute\(.*?"
    r"(?=    private function arriveAtTarget\()",
    re.S
)

replacement = r'''    private function moveAlongStoredRoute(
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

        $startIndex = 0;
        $endIndex = count($points) - 1;
        $cursor = (int)($control['current_route_sequence'] ?? 0);

        foreach ($points as $i => $point) {
            if ($point['sequence'] >= $cursor) {
                $startIndex = max(0, $i - 1);
                break;
            }
        }

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
        $previousTravelled = max(
            0.0,
            (float)($shipment['distance_travelled'] ?? 0.0)
        );
        $storedTotal = (float)($shipment['distance_total'] ?? 0.0);

        $segmentLengths = [];
        $segmentBearings = [];
        $cumulative = [$startIndex => 0.0];
        $geometryTotal = 0.0;

        for ($i = $startIndex; $i < $endIndex; $i++) {
            $a = $points[$i];
            $b = $points[$i + 1];

            $length = $this->distanceKm(
                $a['latitude'], $a['longitude'],
                $b['latitude'], $b['longitude']
            );

            $segmentLengths[$i] = $length;
            $segmentBearings[$i] = $this->bearing(
                $a['latitude'], $a['longitude'],
                $b['latitude'], $b['longitude']
            );
            $geometryTotal += $length;
            $cumulative[$i + 1] = $geometryTotal;
        }

        $total = $storedTotal > 0.0 ? $storedTotal : $geometryTotal;

        if ($geometryTotal <= 0.000001 || $total <= 0.000001) {
            return null;
        }

        // Find the closest point by sampling each great-circle segment,
        // then refine the best fraction with a bounded search.
        $bestDistance = INF;
        $bestIndex = $startIndex;
        $bestFraction = 0.0;

        foreach (range($startIndex, $endIndex - 1) as $i) {
            $length = $segmentLengths[$i] ?? 0.0;
            if ($length <= 0.000001) {
                continue;
            }

            $a = $points[$i];
            $bearingRad = deg2rad($segmentBearings[$i]);
            $lat1 = deg2rad($a['latitude']);
            $lng1 = deg2rad($a['longitude']);

            $evaluate = function (float $fraction) use (
                $lat, $lng, $length, $bearingRad, $lat1, $lng1
            ): array {
                $angular = ($length * $fraction) / self::EARTH_RADIUS_KM;

                $lat2 = asin(
                    sin($lat1) * cos($angular)
                    + cos($lat1) * sin($angular) * cos($bearingRad)
                );

                $lng2 = $lng1 + atan2(
                    sin($bearingRad) * sin($angular) * cos($lat1),
                    cos($angular) - sin($lat1) * sin($lat2)
                );

                $candidateLat = rad2deg($lat2);
                $candidateLng = rad2deg($lng2);
                $distance = $this->distanceKm(
                    $lat, $lng, $candidateLat, $candidateLng
                );

                return [$distance, $candidateLat, $candidateLng];
            };

            $samples = 40;
            $localFraction = 0.0;

            for ($sample = 0; $sample <= $samples; $sample++) {
                $fraction = $sample / $samples;
                [$distance] = $evaluate($fraction);

                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $bestIndex = $i;
                    $bestFraction = $fraction;
                    $localFraction = $fraction;
                }
            }

            $low = max(0.0, $localFraction - 1.0 / $samples);
            $high = min(1.0, $localFraction + 1.0 / $samples);

            for ($iteration = 0; $iteration < 24; $iteration++) {
                $left = $low + ($high - $low) / 3.0;
                $right = $high - ($high - $low) / 3.0;
                [$leftDistance] = $evaluate($left);
                [$rightDistance] = $evaluate($right);

                if ($leftDistance <= $rightDistance) {
                    $high = $right;
                } else {
                    $low = $left;
                }
            }

            $fraction = ($low + $high) / 2.0;
            [$distance] = $evaluate($fraction);

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $bestIndex = $i;
                $bestFraction = $fraction;
            }
        }

        // Recalculate the exact projected coordinates on the chosen segment.
        $a = $points[$bestIndex];
        $segmentLength = $segmentLengths[$bestIndex] ?? 0.0;
        $segmentBearing = $segmentBearings[$bestIndex] ?? 0.0;
        $angular = ($segmentLength * $bestFraction) / self::EARTH_RADIUS_KM;
        $bearingRad = deg2rad($segmentBearing);
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

        $projectedLat = rad2deg($lat2);
        $projectedLng = rad2deg($lng2);
        $distanceToRoute = $this->distanceKm(
            $lat, $lng, $projectedLat, $projectedLng
        );

        $remainingMove = max(0.0, $distanceKm);
        $heading = (float)($shipment['heading'] ?? 0.0);
        $movedThisTick = 0.0;

        // Approach the route without teleporting.
        if ($distanceToRoute > 0.001) {
            $step = min($remainingMove, $distanceToRoute);

            if ($step > 0.0) {
                $heading = $this->bearing(
                    $lat, $lng, $projectedLat, $projectedLng
                );
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
                $movedThisTick = $this->distanceKm(
                    (float)$shipment['current_lat'],
                    (float)$shipment['current_lng'],
                    $lat,
                    $lng
                );
                $remainingMove = max(0.0, $distanceKm - $movedThisTick);
            }

            if ($distanceToRoute > $step + 0.001) {
                $travelled = min(
                    $total,
                    $previousTravelled + $movedThisTick
                );

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

            // Only snap when the computed travel has actually reached
            // the route projection within the numerical tolerance.
            if ($this->distanceKm(
                $lat, $lng, $projectedLat, $projectedLng
            ) <= 0.01) {
                $lat = $projectedLat;
                $lng = $projectedLng;
            }
        }

        $routePosition = ($cumulative[$bestIndex] ?? 0.0)
            + ($segmentLength * $bestFraction);
        $index = $bestIndex;

        // Continue along the route using the same great-circle geometry.
        while ($index < $endIndex && $remainingMove > 0.000001) {
            $length = $segmentLengths[$index] ?? 0.0;

            if ($length <= 0.000001) {
                $index++;
                continue;
            }

            $segmentStart = $cumulative[$index] ?? 0.0;
            $along = max(
                0.0,
                min($length, $routePosition - $segmentStart)
            );
            $available = max(0.0, $length - $along);

            if ($available <= 0.000001) {
                $index++;
                continue;
            }

            $step = min($remainingMove, $available);
            $heading = $segmentBearings[$index];
            $angular = ($along + $step) / self::EARTH_RADIUS_KM;
            $bearingRad = deg2rad($heading);
            $lat1 = deg2rad($points[$index]['latitude']);
            $lng1 = deg2rad($points[$index]['longitude']);

            $lat2 = asin(
                sin($lat1) * cos($angular)
                + cos($lat1) * sin($angular) * cos($bearingRad)
            );
            $lng2 = $lng1 + atan2(
                sin($bearingRad) * sin($angular) * cos($lat1),
                cos($angular) - sin($lat1) * sin($lat2)
            );

            $newLat = rad2deg($lat2);
            $newLng = rad2deg($lng2);

            $actualStep = $this->distanceKm(
                $lat, $lng, $newLat, $newLng
            );

            // Never count more distance than the coordinates actually moved.
            $lat = $newLat;
            $lng = $newLng;
            $movedThisTick += $actualStep;
            $remainingMove = max(0.0, $remainingMove - $actualStep);
            $routePosition += $step;

            if ($step >= $available - 0.000001) {
                $index++;
            } else {
                break;
            }
        }

        $travelled = min(
            $total,
            max(0.0, $previousTravelled + $movedThisTick)
        );
        $remaining = max(0.0, $total - $travelled);

        return [
            'lat' => $lat,
            'lng' => $lng,
            'heading' => $heading,
            'total' => $total,
            'travelled' => $travelled,
            'remaining' => $remaining,
            'route_sequence' => $points[min($index, $endIndex)]['sequence'],
            'at_target' => $index >= $endIndex && $remainingMove > 0.000001,
        ];
    }

'''

match = pattern.search(source)
if not match:
    raise SystemExit("ERROR: Movement method boundaries not found; no changes made.")

updated = source[:match.start()] + replacement + source[match.end():]
target.write_text(updated)
print("Candidate movement method replaced.")
