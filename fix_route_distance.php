<?php
$file = 'app/Tracking/Engine.php.route-aware-candidate';
$source = file_get_contents($file);

if ($source === false) {
    exit("ERROR: Candidate file not found.\n");
}

$start = strpos($source, '    private function moveAlongStoredRoute(');
$end = strpos($source, '    private function arriveAtTarget(', $start === false ? 0 : $start);

if ($start === false || $end === false) {
    exit("ERROR: Movement method boundaries not found. No changes made.\n");
}

$method = substr($source, $start, $end - $start);

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
    exit("ERROR: Could not uniquely locate route-total check. No changes made.\n");
}
$method = str_replace($old, $new, $method);

$old = <<<'OLD'
                $travelled = (float)($shipment['distance_travelled'] ?? 0.0);
                $remaining = (float)($shipment['distance_remaining'] ?? $total);
OLD;

$new = <<<'NEW'
                $travelled = min(
                    $total,
                    max(0.0, (float)($shipment['distance_travelled'] ?? 0.0) + $towardRoute)
                );
                $remaining = max(0.0, $total - $travelled);
OLD;

if (substr_count($method, $old) !== 1) {
    exit("ERROR: Could not uniquely locate off-route counters. No changes made.\n");
}
$method = str_replace($old, $new, $method);

$old = <<<'OLD'
        $routeTravelled = min($total, max(0.0, $routeTravelled));
        $remaining = max(0.0, $total - $routeTravelled);
OLD;

$new = <<<'NEW'
        $previousTravelled = (float)($shipment['distance_travelled'] ?? 0.0);
        $routeTravelled = min(
            $total,
            max(0.0, $previousTravelled + ($distanceKm - $remainingMove))
        );
        $remaining = max(0.0, $total - $routeTravelled);
OLD;

if (substr_count($method, $old) !== 1) {
    exit("ERROR: Could not uniquely locate final distance counters. No changes made.\n");
}
$method = str_replace($old, $new, $method);

$backup = $file . '.before-distance-counter-fix';
if (!copy($file, $backup)) {
    exit("ERROR: Could not create backup. No changes made.\n");
}

$updated = substr($source, 0, $start) . $method . substr($source, $end);

if (file_put_contents($file, $updated) === false) {
    copy($backup, $file);
    exit("ERROR: Could not save candidate. Backup restored.\n");
}

echo "Distance-counter patch applied to candidate only.\n";
echo "Backup: $backup\n";
