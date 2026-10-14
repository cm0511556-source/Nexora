<?php
declare(strict_types=1);

$engine = __DIR__ . '/app/Tracking/Engine.php';
$preview = __DIR__ . '/app/Tracking/Engine.php.route-preview';

if (!is_file($engine) || !is_file($preview)) {
    exit("ERROR: Engine or route preview file is missing. No changes made.\n");
}

$source = file_get_contents($engine);
$helper = file_get_contents($preview);

if ($source === false || $helper === false) {
    exit("ERROR: Could not read engine files. No changes made.\n");
}

if (strpos($source, 'moveAlongStoredRoute') !== false) {
    exit("Route movement is already integrated. No changes made.\n");
}

$start = strpos($helper, '    private function moveAlongStoredRoute(');
$end = strpos($helper, '    private function arriveAtTarget(', $start === false ? 0 : $start);

if ($start === false || $end === false) {
    exit("ERROR: Could not locate the route helper in the preview. No changes made.\n");
}

$method = substr($helper, $start, $end - $start);

if (strpos($source, '    private function arriveAtTarget(') === false) {
    exit("ERROR: Could not locate the insertion point. No changes made.\n");
}

echo "Patch preparation successful.\n";
echo "Route helper extracted: " . strlen($method) . " bytes.\n";
echo "Original Engine.php remains unchanged.\n";
