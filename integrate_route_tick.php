<?php
declare(strict_types=1);

$file = __DIR__ . '/app/Tracking/Engine.php.route-candidate';
$source = file_get_contents($file);

if ($source === false) {
    exit("ERROR: Candidate file unavailable.\n");
}

$old = <<<'CODE'
        $remainingToTarget = $this->distanceKm(
            $currentLat,
            $currentLng,
            $targetLat,
            $targetLng
        );
CODE;

if (substr_count($source, $old) !== 1) {
    exit("STOP: Expected movement section not found exactly once. No changes made.\n");
}

$new = <<<'CODE'
        $remainingToTarget = $this->distanceKm(
            $currentLat,
            $currentLng,
            $targetLat,
            $targetLng
        );

        $routeMove = $this->moveAlongStoredRoute(
            $shipmentId,
            $currentLat,
            $currentLng,
            $targetLat,
            $targetLng,
            $distanceKm,
            $nextWaypoint
        );

        if ($routeMove !== null) {
            $newLat = (float)$routeMove['latitude'];
            $newLng = (float)$routeMove['longitude'];
            $heading = (float)$routeMove['heading'];
            $totalDistance = (float)$routeMove['total'];
            $travelled = (float)$routeMove['travelled'];
            $remaining = (float)$routeMove['remaining'];
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
            $heading = $this->bearing($currentLat, $currentLng, $newLat, $newLng);
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
        }
CODE;

$source = str_replace($old, $new, $source);
$out = __DIR__ . '/app/Tracking/Engine.php.route-integrated-candidate';

if (file_put_contents($out, $source) === false) {
    exit("ERROR: Could not write candidate.\n");
}

echo "Integrated candidate prepared; live engine unchanged.\n";
