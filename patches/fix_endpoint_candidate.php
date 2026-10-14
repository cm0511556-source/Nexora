<?php
declare(strict_types=1);

$file = __DIR__ . '/Engine.php.movement-test';
$source = file_get_contents($file);

if ($source === false) {
    exit("ERROR: Cannot read candidate.\n");
}

$old = '$atTarget = $index >= $endIndex && $remainingMove > 0.000001;';
$new = '$atTarget = $index >= $endIndex;';

if (substr_count($source, $old) !== 1) {
    exit("ERROR: Expected endpoint condition not found exactly once. No changes made.\n");
}

$updated = str_replace($old, $new, $source);

if (file_put_contents($file, $updated) === false) {
    exit("ERROR: Could not update candidate.\n");
}

echo "Candidate endpoint condition updated.\n";
echo "Live engine and database untouched.\n";
