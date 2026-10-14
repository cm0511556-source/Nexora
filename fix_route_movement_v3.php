<?php
declare(strict_types=1);

$candidate = __DIR__ . '/app/Tracking/Engine.php.route-aware-candidate';

if (!is_file($candidate)) {
    exit("ERROR: Candidate engine not found. No files changed.\n");
}

$source = file_get_contents($candidate);

if ($source === false) {
    exit("ERROR: Cannot read candidate. No files changed.\n");
}

$start = strpos($source, 'private function moveAlongStoredRoute(');
$end = strpos($source, 'private function arriveAtTarget(', $start === false ? 0 : $start);

if ($start === false || $end === false || $end <= $start) {
    exit("ERROR: Could not identify method boundaries. No files changed.\n");
}

$method = substr($source, $start, $end - $start);

$required = [
    '$bestDistance > 1.0',
    '$remainingMove -= $towardRoute',
    '$movedThisTick = max(0.0, $distanceKm - $remainingMove)',
];

foreach ($required as $needle) {
    if (strpos($method, $needle) === false) {
        exit("ERROR: Expected movement logic not found: {$needle}\nNo files changed.\n");
    }
}

echo "Candidate method located and expected logic confirmed.\n";
echo "No files changed. Ready for the targeted movement correction.\n";
