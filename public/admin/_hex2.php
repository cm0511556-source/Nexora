<?php
$f = '/data/data/com.termux/files/home/vibranthub/public/admin/shipment-control.php';
$c = file_get_contents($f);
$orig = $c;
$log = [];

$FLAGS = 'JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES';

// 1. waypoints block (line ~1287)
// Pattern: const waypoints = <?= json_encode( array_map( static function(array \$point): array {...}, \$waypoints ) ?>;  
$pat1 = '/const waypoints = <\?= json_encode\(\s*(array_map\([^;]*?\))\s*\) \?>/s';
if (preg_match($pat1, $c)) {
    $c = preg_replace($pat1, 'const waypoints = <?= json_encode($1, ' . $FLAGS . ') ?>', $c, 1, $n1);
    $log[] = $n1 ? 'waypoints json_encode patched' : 'WARN: waypoints not patched';
} else {
    // fallback: just find the first "const waypoints = <?= json_encode("
    $pos = strpos($c, 'const waypoints = <?= json_encode(');
    if ($pos !== false) {
        $end = strpos($c, ') ?>', $pos);
        if ($end !== false) {
            $c = substr($c, 0, $end) . ', ' . $FLAGS . substr($c, $end);
            $log[] = 'waypoints json_encode patched (fallback)';
        }
    }
}

// 2. wpList block (line ~1636)
$pos2 = strpos($c, 'const wpList = <?= json_encode(');
if ($pos2 !== false) {
    // find the matching ")) ?>" closing
    $end2 = strpos($c, ')) ?>', $pos2);
    if ($end2 !== false) {
        // Insert flags right before ")) ?>"
        $c = substr($c, 0, $end2) . ', ' . $FLAGS . ')) ?>' . substr($c, $end2 + strlen(')) ?>'));
        $log[] = 'wpList json_encode patched';
    } else {
        $log[] = 'WARN: wpList end anchor not found';
    }
} else {
    $log[] = 'wpList not found (already patched or different name)';
}

if ($c !== $orig) {
    file_put_contents($f, $c);
    echo implode("\n", $log) . "\n";
} else {
    echo "NO CHANGE\n" . implode("\n", $log) . "\n";
}
