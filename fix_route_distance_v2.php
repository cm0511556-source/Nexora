<?php
declare(strict_types=1);

$file = 'app/Tracking/Engine.php.route-aware-candidate';
$source = file_get_contents($file);

if ($source === false) {
    exit("ERROR: Candidate engine not found.\n");
}

$start = strpos($source, '    private function moveAlongStoredRoute(');
$end = strpos(
    $source,
    '    private function arriveAtTarget(',
    $start === false ? 0 : $start
);

if ($start === false || $end === false) {
    exit("ERROR: Method boundaries not found. No changes made.\n");
}

$method = substr($source, $start, $end - $start);

/*
 * Preserve the shipment's existing total distance.
 */
$old = <<<'OLD'
        if ($total <= 0.000001) {
            return null;
        }
OLD;

$new = <<<'NEW'
        $routeGeometryLength = $total;
        $storedTotal = (float)($shipment['distance_total'] ?? 0.0);
        if ($storedTotal > 0.0) {
            $total = $storedTotal;
        }

        if ($routeGeometryLength <= 0.000001 || $total <= 0.000001) {
            return null;
        }
NEW;

if (substr_count($method, $old) !== 1) {
    exit("ERROR: Route-total section did not match exactly. No changes made.\n");
}
$method = str_replace($old, $new, $method);

/*
 * Keep the existing travelled distance and add movement made
 * while approaching the route.
 */
$old = <<<'OLD'
                $travelled = (float)($shipment['distance_travelled'] ?? 0.0);
                $remaining = (float)($shipment['distance_remaining'] ?? $total);
OLD;

$new = <<<'NEW'
                $travelled = min(
                    $total,
                    max(
                        0.0,
                        (float)($shipment['distance_travelled'] ?? 0.0)
                        + $towardRoute
                    )
                );
                $remaining = max(0.0, $total - $travelled);
NEW;

if (substr_count($method, $old) !== 1) {
    exit("ERROR: Off-route section did not match exactly. No changes made.\n");
}
$method = str_replace($old, $new, $method);

/*
 * Keep distance counters consistent with actual movement.
 */
$old = <<<'OLD'
        $routeTravelled = min($total, max(0.0, $routeTravelled));
        $remaining = max(0.0, $total - $routeTravelled);
OLD;

$new = <<<'NEW'
        $previousTravelled = (float)($shipment['distance_travelled'] ?? 0.0);
        $movedThisTick = max(0.0, $distanceKm - $remainingMove);
        $routeTravelled = min(
            $total,
            max(0.0, $previousTravelled + $movedThisTick)
        );
        $remaining = max(0.0, $total - $routeTravelled);
NEW;

if (substr_count($method, $old) !== 1) {
    exit("ERROR: Final distance section did not match exactly. No changes made.\n");
}
$method = str_replace($old, $new, $method);

$backup = $file . '.before-distance-counter-fix-v2';

if (!copy($file, $backup)) {
    exit("ERROR: Backup failed. No changes made.\n");
}

$updated = substr($source, 0, $start)
    . $method
    . substr($source, $end);

if (file_put_contents($file, $updated) === false) {
    copy($backup, $file);
    exit("ERROR: Write failed; candidate backup restored.\n");
}

echo "Candidate distance patch applied.\n";
echo "Backup: $backup\n";
