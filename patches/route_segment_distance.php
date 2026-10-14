<?php
declare(strict_types=1);

/**
 * Approximate shortest spherical distance from a point to a route.
 * Tests the great-circle segments between consecutive stored points.
 */
function nearestRouteSegmentDistanceKm(
    float $lat,
    float $lng,
    array $route,
    float $radiusKm = 6371.0
): float {
    if (count($route) < 2) {
        return INF;
    }

    $toVector = static function (float $lat, float $lng): array {
        $lat = deg2rad($lat);
        $lng = deg2rad($lng);

        return [
            cos($lat) * cos($lng),
            cos($lat) * sin($lng),
            sin($lat),
        ];
    };

    $dot = static fn(array $a, array $b): float =>
        $a[0] * $b[0] + $a[1] * $b[1] + $a[2] * $b[2];

    $cross = static fn(array $a, array $b): array => [
        $a[1] * $b[2] - $a[2] * $b[1],
        $a[2] * $b[0] - $a[0] * $b[2],
        $a[0] * $b[1] - $a[1] * $b[0],
    ];

    $norm = static fn(array $v): float =>
        sqrt($v[0] * $v[0] + $v[1] * $v[1] + $v[2] * $v[2]);

    $angle = static function (array $a, array $b) use ($dot, $cross): float {
        $crossProduct = $cross($a, $b);
        $crossNorm = sqrt(
            $crossProduct[0] ** 2
            + $crossProduct[1] ** 2
            + $crossProduct[2] ** 2
        );

        return atan2($crossNorm, max(-1.0, min(1.0, $dot($a, $b))));
    };

    $p = $toVector($lat, $lng);
    $bestAngle = INF;

    for ($i = 0; $i < count($route) - 1; $i++) {
        $a = $toVector(
            (float)$route[$i]['latitude'],
            (float)$route[$i]['longitude']
        );
        $b = $toVector(
            (float)$route[$i + 1]['latitude'],
            (float)$route[$i + 1]['longitude']
        );

        $ab = $angle($a, $b);
        if ($ab < 1e-12) {
            $bestAngle = min($bestAngle, $angle($p, $a));
            continue;
        }

        $normal = $cross($a, $b);
        $normalNorm = $norm($normal);
        if ($normalNorm < 1e-12) {
            $bestAngle = min($bestAngle, $angle($p, $a), $angle($p, $b));
            continue;
        }

        $normal = [
            $normal[0] / $normalNorm,
            $normal[1] / $normalNorm,
            $normal[2] / $normalNorm,
        ];

        // Project the vehicle vector onto the segment's great-circle plane.
        $projection = [
            $p[0] - $dot($p, $normal) * $normal[0],
            $p[1] - $dot($p, $normal) * $normal[1],
            $p[2] - $dot($p, $normal) * $normal[2],
        ];
        $projectionNorm = $norm($projection);

        $bestSegmentAngle = min($angle($p, $a), $angle($p, $b));

        if ($projectionNorm > 1e-12) {
            $q = [
                $projection[0] / $projectionNorm,
                $projection[1] / $projectionNorm,
                $projection[2] / $projectionNorm,
            ];

            foreach ([$q, [-$q[0], -$q[1], -$q[2]]] as $candidate) {
                $aq = $angle($a, $candidate);
                $qb = $angle($candidate, $b);

                if (abs(($aq + $qb) - $ab) < 1e-7) {
                    $bestSegmentAngle = min(
                        $bestSegmentAngle,
                        $angle($p, $candidate)
                    );
                }
            }
        }

        $bestAngle = min($bestAngle, $bestSegmentAngle);
    }

    return $bestAngle * $radiusKm;
}
