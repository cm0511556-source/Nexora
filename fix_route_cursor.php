<?php
declare(strict_types=1);

$file = __DIR__ . '/app/Tracking/Engine.php.route-integrated-candidate';
$source = file_get_contents($file);
if ($source === false) exit("ERROR: Candidate missing.\n");

$start = strpos($source, '        // Find the closest segment to the current position.');
$end = strpos($source, '        $total = 0.0;', $start === false ? 0 : $start);

if ($start === false || $end === false) {
    exit("ERROR: Route cursor replacement points not found; candidate unchanged.\n");
}

$replacement = <<<'CODE'
        $cursorQuery = $this->db->prepare(
            'SELECT current_route_sequence FROM tracking_controls WHERE shipment_id = :id'
        );
        $cursorQuery->execute([':id' => $shipmentId]);
        $cursor = max(0, (int)$cursorQuery->fetchColumn());

        $storedRoute = [];
        foreach ($stored as $point) {
            $storedRoute[(int)$point['sequence']] = [
                (float)$point['latitude'],
                (float)$point['longitude']
            ];
        }
        ksort($storedRoute);
        $route = array_values($storedRoute);

        $cursor = min($cursor, count($route) - 2);
        $a = $route[$cursor];
        $b = $route[$cursor + 1];

        // Project onto the current segment only; never search earlier segments.
        $cosLat = max(0.01, cos(deg2rad($currentLat)));
        $ax = ($a[1] - $currentLng) * $cosLat;
        $ay = $a[0] - $currentLat;
        $bx = ($b[1] - $currentLng) * $cosLat;
        $by = $b[0] - $currentLat;
        $dx = $bx - $ax;
        $dy = $by - $ay;
        $den = $dx * $dx + $dy * $dy;
        $t = $den > 0
            ? max(0.0, min(1.0, -($ax * $dx + $ay * $dy) / $den))
            : 0.0;

        $lat = $a[0] + ($b[0] - $a[0]) * $t;
        $lng = $a[1] + ($b[1] - $a[1]) * $t;

        if ($this->distanceKm($currentLat, $currentLng, $lat, $lng) > 120.0) {
            return null;
        }

        $remainingMove = max(0.0, $movementKm);
        $heading = $this->bearing($lat, $lng, $b[0], $b[1]);

        while ($cursor < count($route) - 1) {
            $next = $route[$cursor + 1];
            $segmentKm = $this->distanceKm($lat, $lng, $next[0], $next[1]);

            if ($segmentKm <= 0.001) {
                $lat = $next[0];
                $lng = $next[1];
                $cursor++;
                continue;
            }

            $heading = $this->bearing($lat, $lng, $next[0], $next[1]);

            if ($remainingMove < $segmentKm) {
                $angular = $remainingMove / self::EARTH_RADIUS_KM;
                $br = deg2rad($heading);
                $lr = deg2rad($lat);
                $gr = deg2rad($lng);
                $nlat = asin(sin($lr) * cos($angular) + cos($lr) * sin($angular) * cos($br));
                $nlng = $gr + atan2(
                    sin($br) * sin($angular) * cos($lr),
                    cos($angular) - sin($lr) * sin($nlat)
                );
                $newLat = rad2deg($nlat);
                $newLng = fmod(rad2deg($nlng) + 540.0, 360.0) - 180.0;
                break;
            }

            $remainingMove -= $segmentKm;
            $lat = $next[0];
            $lng = $next[1];
            $cursor++;

            if ($remainingMove <= 0.001) {
                $newLat = $lat;
                $newLng = $lng;
                break;
            }
        }

        if (!isset($newLat, $newLng)) {
            $newLat = $lat;
            $newLng = $lng;
        }

        $progressUpdate = $this->db->prepare(
            'UPDATE tracking_controls SET current_route_sequence = :seq WHERE shipment_id = :id'
        );
        $progressUpdate->execute([':seq' => $cursor, ':id' => $shipmentId]);

        $heading = $this->bearing($currentLat, $currentLng, $newLat, $newLng);

CODE;

$new = substr($source, 0, $start) . $replacement . substr($source, $end);
$out = __DIR__ . '/app/Tracking/Engine.php.route-cursor-candidate';

if (file_put_contents($out, $new) === false) {
    exit("ERROR: Could not write candidate.\n");
}

echo "Forward-cursor candidate created; live engine unchanged.\n";
