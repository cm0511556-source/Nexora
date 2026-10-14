<?php
$file = __DIR__ . '/Engine.php.movement-test';
$code = file_get_contents($file);

if ($code === false) {
    exit("ERROR: Candidate engine not readable.\n");
}

$start = strpos($code, '$endIndex = count($points) - 1;');
$marker = "        if (\$endIndex <= \$startIndex) {";

if ($start === false || ($end = strpos($code, $marker, $start)) === false) {
    exit("ERROR: Expected waypoint block not found. No changes made.\n");
}

$block = substr($code, $start, $end - $start);

if (strpos($block, '$matchedWaypoint') !== false) {
    exit("Waypoint fix already exists.\n");
}

$newBlock = <<<'BLOCK'
$endIndex = count($points) - 1;

        if ($nextWaypoint !== null && isset($nextWaypoint['id'])) {
            $matchedWaypoint = false;

            foreach ($points as $index => $point) {
                if ($point['waypoint_id'] === (int)$nextWaypoint['id']) {
                    $endIndex = $index;
                    $matchedWaypoint = true;
                    break;
                }
            }

            if (!$matchedWaypoint) {
                return null;
            }
        }

BLOCK;

$backup = $file . '.before-waypoint-fix-verified';
if (!copy($file, $backup)) {
    exit("ERROR: Backup failed. No changes made.\n");
}

$updated = substr_replace($code, $newBlock, $start, $end - $start);

if (file_put_contents($file, $updated) === false) {
    copy($backup, $file);
    exit("ERROR: Write failed; candidate restored.\n");
}

echo "Waypoint fix applied to candidate.\n";
