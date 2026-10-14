<?php
declare(strict_types=1);

$file = __DIR__ . '/Engine.php.movement-test';
$source = file_get_contents($file);

if ($source === false) {
    exit("ERROR: Cannot read candidate.\n");
}

$start = strpos($source, '    private function moveAlongStoredRoute(');
$end = strpos($source, '    private function arriveAtTarget(');

if ($start === false || $end === false || $end <= $start) {
    exit("ERROR: Movement method boundaries not found. No changes made.\n");
}

$method = substr($source, $start, $end - $start);

if (strpos($method, '$tickStartLat') !== false) {
    exit("Coordinate tracking already exists. No changes made.\n");
}

$pattern = '/(\$lat\s*=\s*\(float\)\s*\$shipment\[.current_lat.\];\s*\$lng\s*=\s*\(float\)\s*\$shipment\[.current_lng.\];)/';

$count = 0;
$method = preg_replace_callback(
    $pattern,
    static function (array $m): string {
        return $m[1]
            . "\n        \$tickStartLat = \$lat;"
            . "\n        \$tickStartLng = \$lng;";
    },
    $method,
    1,
    $count
);

if ($method === null || $count !== 1) {
    exit("ERROR: Could not safely locate coordinate initialization. No changes made.\n");
}

$oldFinal = '/\$travelled\s*=\s*min\(\$total,\s*max\(0\.0,\s*\$previousTravelled\s*\+\s*\$movedThisTick\)\);/';
$count = 0;

$method = preg_replace_callback(
    $oldFinal,
    static function (): string {
        return "\$movedThisTick = \$this->distanceKm("
            . "\$tickStartLat, \$tickStartLng, \$lat, \$lng"
            . ");\n        \$travelled = min(\$total, max(0.0, \$previousTravelled + \$movedThisTick));";
    },
    $method,
    1,
    $count
);

if ($method === null || $count !== 1) {
    exit("ERROR: Could not safely locate final distance calculation. No changes made.\n");
}

$updated = substr($source, 0, $start)
    . $method
    . substr($source, $end);

if (file_put_contents($file, $updated) === false) {
    exit("ERROR: Could not write candidate file.\n");
}

echo "Candidate updated. Live engine and database untouched.\n";
