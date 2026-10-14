<?php
$file = 'app/Tracking/Engine.php.route-aware-candidate';
$code = file_get_contents($file);

$start = '        $remainingOnSegment = max(0.0, (1.0 - $t) * $segmentKm);';
$end = '        $total = 0.0;';

$from = strpos($code, $start);
$to = strpos($code, $end, $from === false ? 0 : $from);

if ($from === false || $to === false) {
    exit("ERROR: Movement block not found; candidate unchanged.\n");
}

$replacement = <<<'PHPBLOCK'
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

        // Distance travelled before the projected point.
        $routeTravelled = 0.0;
        for ($j = 0; $j < $i; $j++) {
            $routeTravelled += $this->distanceKm(
                (float)$points[$j]['latitude'],
                (float)$points[$j]['longitude'],
                (float)$points[$j + 1]['latitude'],
                (float)$points[$j + 1]['longitude']
            );
        }
        $routeTravelled += $t * $segmentKm;

        // Consume requested distance along route segments.
        while ($i < $endIndex) {
            $a = $points[$i];
            $b = $points[$i + 1];
            $segmentKm = $this->distanceKm(
                (float)$a['latitude'],
                (float)$a['longitude'],
                (float)$b['latitude'],
                (float)$b['longitude']
            );

            $fractionStart = ($i === $best['index']) ? $best['t'] : 0.0;
            $available = max(0.0, (1.0 - $fractionStart) * $segmentKm);

            if ($travel <= $available) {
                $fraction = $segmentKm > 0.0
                    ? $fractionStart + ($travel / $segmentKm)
                    : 1.0;
                $fraction = max(0.0, min(1.0, $fraction));

                $newLat = (float)$a['latitude']
                    + $fraction * ((float)$b['latitude'] - (float)$a['latitude']);
                $newLng = (float)$a['longitude']
                    + $fraction * ((float)$b['longitude'] - (float)$a['longitude']);
                $routeTravelled += $travel;
                $routeSequence = (int)$a['sequence'];
                $heading = $this->bearing(
                    (float)$a['latitude'],
                    (float)$a['longitude'],
                    (float)$b['latitude'],
                    (float)$b['longitude']
                );
                $travel = 0.0;
                break;
            }

            $travel -= $available;
            $routeTravelled += $available;
            $i++;
            $routeSequence = (int)$points[$i]['sequence'];

            if ($i < $endIndex) {
                $heading = $this->bearing(
                    (float)$points[$i]['latitude'],
                    (float)$points[$i]['longitude'],
                    (float)$points[$i + 1]['latitude'],
                    (float)$points[$i + 1]['longitude']
                );
            }
        }

PHPBLOCK;

$code = substr_replace($code, $replacement, $from, $to - $from);
if (file_put_contents($file, $code) === false) {
    exit("ERROR: Could not write candidate.\n");
}
echo "Candidate movement block patched.\n";
