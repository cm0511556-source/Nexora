<?php
$file = 'build_route_candidate.php';
$code = file_get_contents($file);
if ($code === false) {
    exit("ERROR: Builder not found.\n");
}

$old = <<<'OLD'
        $remaining = 0.0;
        for ($j = $i; $j < count($points) - 1; $j++) {
            $remaining += $this->distanceKm(
                (float)$points[$j]['latitude'],
                (float)$points[$j]['longitude'],
                (float)$points[$j + 1]['latitude'],
                (float)$points[$j + 1]['longitude']
            );
        }
        $remaining = max(0.0, $remaining - max(0.0, $routeTravelled - $routeTravelled));

        $atTarget = $nextWaypoint !== null
            && $this->distanceKm(
                $newLat,
                $newLng,
                (float)$nextWaypoint['latitude'],
                (float)$nextWaypoint['longitude']
            ) <= 0.01;
OLD;

$new = <<<'NEW'
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
NEW;

if (substr_count($code, $old) !== 1) {
    exit("ERROR: Exact helper block did not match; builder unchanged.\n");
}
$code = str_replace($old, $new, $code);
file_put_contents($file, $code);
echo "Builder endpoint block repaired.\n";
