<?php
declare(strict_types=1);

namespace VibrantHub\Tracking;

use PDO;
use RuntimeException;

final class Engine
{
    private const EARTH_RADIUS_KM = 6371.0088;

    public function __construct(private PDO $db)
    {
    }

    public function tick(int $shipmentId): array
    {
        $shipment = $this->getShipment($shipmentId);

        if (!$shipment) {
            throw new RuntimeException('Shipment not found.');
        }

        $control = $this->getControl($shipmentId);

        if (!$control || (int)$control['is_running'] !== 1) {
            return $this->state($shipmentId);
        }

        $holdCheck = $this->db->prepare("SELECT id FROM shipment_holds WHERE shipment_id = :id AND status = 'active' AND mandatory = 1 LIMIT 1");
        $holdCheck->execute([":id" => $shipmentId]);
        if ($holdCheck->fetchColumn()) {
            return $this->state($shipmentId);
        }

        $speed = max(0.0, (float)$control['control_speed']);

        if ($speed <= 0) {
            return $this->state($shipmentId);
        }

        $now = microtime(true);

        $lastUpdate = $shipment['last_update']
            ? strtotime((string)$shipment['last_update'])
            : null;

        if ($lastUpdate === false || $lastUpdate === null) {
            $elapsedSeconds = 1.0;
        } else {
            $elapsedSeconds = max(0.1, min(60.0, time() - $lastUpdate));
        }

        $distanceKm = ($speed * $elapsedSeconds) / 3600.0;

        $nextWaypoint = $this->getNextWaypoint($shipmentId, $control);

        $targetLat = $nextWaypoint
            ? (float)$nextWaypoint['latitude']
            : (float)$shipment['destination_lat'];

        $targetLng = $nextWaypoint
            ? (float)$nextWaypoint['longitude']
            : (float)$shipment['destination_lng'];

        $currentLat = (float)$shipment['current_lat'];
        $currentLng = (float)$shipment['current_lng'];

        $remainingToTarget = $this->distanceKm(
            $currentLat,
            $currentLng,
            $targetLat,
            $targetLng
        );

        if ($remainingToTarget <= 0.01 || $distanceKm >= $remainingToTarget) {
            return $this->arriveAtTarget(
                $shipment,
                $control,
                $nextWaypoint
            );
        }

        $fraction = min(1.0, $distanceKm / $remainingToTarget);
        $angularDistance = $remainingToTarget / self::EARTH_RADIUS_KM;
        $bearingRad = deg2rad($this->bearing($currentLat, $currentLng, $targetLat, $targetLng));
        $latRad = deg2rad($currentLat);
        $lngRad = deg2rad($currentLng);
        $step = $angularDistance * $fraction;
        $newLatRad = asin(
            sin($latRad) * cos($step)
            + cos($latRad) * sin($step) * cos($bearingRad)
        );
        $newLngRad = $lngRad + atan2(
            sin($bearingRad) * sin($step) * cos($latRad),
            cos($step) - sin($latRad) * sin($newLatRad)
        );
        $newLat = rad2deg($newLatRad);
        $newLng = fmod(rad2deg($newLngRad) + 540.0, 360.0) - 180.0;

        $heading = $this->bearing(
            $currentLat,
            $currentLng,
            $newLat,
            $newLng
        );

        $totalDistance = $this->distanceKm(
            (float)$shipment['origin_lat'],
            (float)$shipment['origin_lng'],
            (float)$shipment['destination_lat'],
            (float)$shipment['destination_lng']
        );

        $travelled = $this->distanceKm(
            (float)$shipment['origin_lat'],
            (float)$shipment['origin_lng'],
            $newLat,
            $newLng
        );

        $remaining = max(0.0, $totalDistance - $travelled);

        $etaSeconds = $speed > 0
            ? ($remaining / $speed) * 3600
            : 0;

        $eta = date(
            'Y-m-d H:i:s',
            time() + (int)round($etaSeconds)
        );

        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare(
                'UPDATE shipments
                 SET current_lat = :lat,
                     current_lng = :lng,
                     speed = :speed,
                     distance_total = :total,
                     distance_travelled = :travelled,
                     distance_remaining = :remaining,
                     eta = :eta,
                     status = :status,
                     tracking_mode = :mode,
                     last_update = CURRENT_TIMESTAMP,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );

            $stmt->execute([
                ':lat' => $newLat,
                ':lng' => $newLng,
                ':speed' => $speed,
                ':total' => $totalDistance,
                ':travelled' => $travelled,
                ':remaining' => $remaining,
                ':eta' => $eta,
                ':status' => 'in_transit',
                ':mode' => $control['tracking_mode'] ?: 'simulation',
                ':id' => $shipmentId,
            ]);

            $position = $this->db->prepare(
                'INSERT INTO tracking_positions
                 (shipment_id, latitude, longitude, speed, heading)
                 VALUES
                 (:shipment_id, :lat, :lng, :speed, :heading)'
            );

            $position->execute([
                ':shipment_id' => $shipmentId,
                ':lat' => $newLat,
                ':lng' => $newLng,
                ':speed' => $speed,
                ':heading' => $heading,
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }

        return $this->state($shipmentId);
    }

    private function arriveAtTarget(
        array $shipment,
        array $control,
        ?array $waypoint
    ): array {
        $shipmentId = (int)$shipment['id'];

        $targetLat = $waypoint
            ? (float)$waypoint['latitude']
            : (float)$shipment['destination_lat'];

        $targetLng = $waypoint
            ? (float)$waypoint['longitude']
            : (float)$shipment['destination_lng'];

        $this->db->beginTransaction();

        try {
            if ($waypoint) {
                $waypointId = (int)$waypoint['id'];

                $updateWaypoint = $this->db->prepare(
                    'UPDATE shipment_waypoints
                     SET status = :status,
                         arrival_at = COALESCE(arrival_at, CURRENT_TIMESTAMP),
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id'
                );

                $updateWaypoint->execute([
                    ':status' => 'arrived',
                    ':id' => $waypointId,
                ]);

                $event = $this->db->prepare(
                    'INSERT INTO tracking_events
                     (shipment_id, status, title, description, location)
                     VALUES
                     (:shipment_id, :status, :title, :description, :location)'
                );

                $event->execute([
                    ':shipment_id' => $shipmentId,
                    ':status' => 'arrived',
                    ':title' => 'Arrived at waypoint',
                    ':description' => 'Shipment has arrived at the next scheduled waypoint and is awaiting departure.',
                    ':location' => $waypoint['name'],
                ]);

                $next = $this->db->prepare(
                    'SELECT *
                     FROM shipment_waypoints
                     WHERE shipment_id = :shipment_id
                       AND sequence > :sequence
                     ORDER BY sequence ASC
                     LIMIT 1'
                );

                $next->execute([
                    ':shipment_id' => $shipmentId,
                    ':sequence' => (int)$waypoint['sequence'],
                ]);

                $nextWaypoint = $next->fetch();

                $controlUpdate = $this->db->prepare(
                    'UPDATE tracking_controls
                     SET is_running = 0,
                         current_waypoint_id = :current_waypoint,
                         next_waypoint_id = :next_waypoint,
                         paused_reason = :reason,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE shipment_id = :shipment_id'
                );

                $controlUpdate->execute([
                    ':current_waypoint' => $waypointId,
                    ':next_waypoint' => $nextWaypoint
                        ? (int)$nextWaypoint['id']
                        : null,
                    ':reason' => 'Arrived at waypoint; awaiting departure.',
                    ':shipment_id' => $shipmentId,
                ]);

                $shipmentUpdate = $this->db->prepare(
                    'UPDATE shipments
                     SET current_lat = :lat,
                         current_lng = :lng,
                         speed = 0,
                         status = :status,
                         last_update = CURRENT_TIMESTAMP,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id'
                );

                $shipmentUpdate->execute([
                    ':lat' => $targetLat,
                    ':lng' => $targetLng,
                    ':status' => 'on_hold',
                    ':id' => $shipmentId,
                ]);
            } else {
                $this->db->prepare(
                    'UPDATE shipments
                     SET current_lat = :lat,
                         current_lng = :lng,
                         speed = 0,
                         distance_remaining = 0,
                         distance_travelled = distance_total,
                         status = :status,
                         eta = NULL,
                         last_update = CURRENT_TIMESTAMP,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id'
                )->execute([
                    ':lat' => $targetLat,
                    ':lng' => $targetLng,
                    ':status' => 'delivered',
                    ':id' => $shipmentId,
                ]);

                $this->db->prepare(
                    'UPDATE tracking_controls
                     SET is_running = 0,
                         paused_reason = NULL,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE shipment_id = :shipment_id'
                )->execute([
                    ':shipment_id' => $shipmentId,
                ]);

                $this->db->prepare(
                    'INSERT INTO tracking_positions
                     (shipment_id, latitude, longitude, speed, heading)
                     VALUES (:shipment_id, :lat, :lng, 0, 0)'
                )->execute([
                    ':shipment_id' => $shipmentId,
                    ':lat' => $targetLat,
                    ':lng' => $targetLng,
                ]);

                $this->db->prepare(
                    'INSERT INTO tracking_events
                     (shipment_id, status, title, description, location)
                     VALUES
                     (:shipment_id, :status, :title, :description, :location)'
                )->execute([
                    ':shipment_id' => $shipmentId,
                    ':status' => 'delivered',
                    ':title' => 'Shipment delivered',
                    ':description' => 'Shipment has reached its final destination.',
                    ':location' => $shipment['destination'],
                ]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }

        return $this->state($shipmentId);
    }

    private function getShipment(int $shipmentId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT *
             FROM shipments
             WHERE id = :id
             LIMIT 1'
        );

        $stmt->execute([':id' => $shipmentId]);

        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function getControl(int $shipmentId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT *
             FROM tracking_controls
             WHERE shipment_id = :shipment_id
             LIMIT 1'
        );

        $stmt->execute([':shipment_id' => $shipmentId]);

        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function getNextWaypoint(
        int $shipmentId,
        array $control
    ): ?array {
        if (!empty($control['next_waypoint_id'])) {
            $stmt = $this->db->prepare(
                'SELECT *
                 FROM shipment_waypoints
                 WHERE id = :id
                 LIMIT 1'
            );

            $stmt->execute([
                ':id' => (int)$control['next_waypoint_id'],
            ]);

            $row = $stmt->fetch();

            if ($row && $row['status'] !== 'completed') {
                return $row;
            }
        }

        $stmt = $this->db->prepare(
            'SELECT *
             FROM shipment_waypoints
             WHERE shipment_id = :shipment_id
               AND status = :status
             ORDER BY sequence ASC
             LIMIT 1'
        );

        $stmt->execute([
            ':shipment_id' => $shipmentId,
            ':status' => 'pending',
        ]);

        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function distanceKm(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2
    ): float {
        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLng = deg2rad($lng2 - $lng1);

        $a = sin($deltaLat / 2) ** 2
            + cos($lat1Rad)
            * cos($lat2Rad)
            * sin($deltaLng / 2) ** 2;

        return self::EARTH_RADIUS_KM
            * 2
            * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
    }

    private function bearing(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2
    ): float {
        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $deltaLng = deg2rad($lng2 - $lng1);

        $y = sin($deltaLng) * cos($lat2Rad);

        $x = cos($lat1Rad) * sin($lat2Rad)
            - sin($lat1Rad)
            * cos($lat2Rad)
            * cos($deltaLng);

        $bearing = rad2deg(atan2($y, $x));

        return fmod($bearing + 360.0, 360.0);
    }

    private function state(int $shipmentId): array
    {
        $shipment = $this->getShipment($shipmentId);

        if (!$shipment) {
            throw new RuntimeException('Shipment not found.');
        }

        $control = $this->getControl($shipmentId);

        return [
            'shipment_id' => $shipmentId,
            'tracking_number' => $shipment['tracking_number'],
            'latitude' => (float)$shipment['current_lat'],
            'longitude' => (float)$shipment['current_lng'],
            'current_lat' => (float)$shipment['current_lat'],
            'current_lng' => (float)$shipment['current_lng'],
            'speed' => (float)$shipment['speed'],
            'distance_total' => (float)$shipment['distance_total'],
            'distance_travelled' => (float)$shipment['distance_travelled'],
            'distance_remaining' => (float)$shipment['distance_remaining'],
            'eta' => $shipment['eta'],
            'status' => $shipment['status'],
            'tracking_mode' => $shipment['tracking_mode'],
            'is_running' => $control
                ? (int)$control['is_running']
                : 0,
        ];
    }
}
