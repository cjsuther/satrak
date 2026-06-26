<?php

declare(strict_types=1);

namespace Satrak\Domain\Repositories;

use PDO;

/**
 * Acceso a `trips`. Los viajes los construye el procesador (§12): se abren al
 * arrancar el vehículo y se cierran al detenerse, al apagar la ignición o al
 * cambiar el conductor (cambio de PIN parte el viaje, §8).
 */
final class TripRepository
{
    public function __construct(private PDO $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(int $tripId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM trips WHERE id = ? LIMIT 1');
        $stmt->execute([$tripId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Viajes de un conductor en un rango (portal del conductor, §9.4).
     *
     * @return array<int,array<string,mixed>>
     */
    public function forDriver(int $driverId, int $companyId, string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            'SELECT t.*, v.plate
             FROM trips t LEFT JOIN vehicles v ON v.id = t.vehicle_id
             WHERE t.driver_id = :did AND t.company_id = :cid
               AND t.started_at BETWEEN :from AND :to
             ORDER BY t.started_at DESC'
        );
        $stmt->execute([':did' => $driverId, ':cid' => $companyId, ':from' => $from, ':to' => $to]);

        return $stmt->fetchAll();
    }

    /**
     * Un viaje, sólo si pertenece al conductor (scope estricto del portal).
     *
     * @return array<string,mixed>|null
     */
    public function findForDriver(int $tripId, int $driverId, int $companyId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT t.*, v.plate FROM trips t LEFT JOIN vehicles v ON v.id = t.vehicle_id
             WHERE t.id = ? AND t.driver_id = ? AND t.company_id = ? LIMIT 1'
        );
        $stmt->execute([$tripId, $driverId, $companyId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Abre un viaje y devuelve su id. Los agregados (distancia, velocidades,
     * duración) se completan al cerrarlo.
     */
    public function open(
        int $companyId,
        int $deviceId,
        ?int $vehicleId,
        ?int $driverId,
        string $startedAt,
        float $startLat,
        float $startLon
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO trips
                (company_id, device_id, vehicle_id, driver_id, started_at, start_lat, start_lon)
             VALUES (:company_id, :device_id, :vehicle_id, :driver_id, :started_at, :start_lat, :start_lon)'
        );
        $stmt->execute([
            ':company_id' => $companyId,
            ':device_id'  => $deviceId,
            ':vehicle_id' => $vehicleId,
            ':driver_id'  => $driverId,
            ':started_at' => $startedAt,
            ':start_lat'  => $startLat,
            ':start_lon'  => $startLon,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Cierra un viaje con sus agregados ya calculados.
     *
     * @param array{ended_at:string,end_lat:float,end_lon:float,distance_km:float,max_speed:int,avg_speed:int,duration_sec:int,points_count:int} $a
     */
    public function close(int $tripId, array $a): void
    {
        $stmt = $this->db->prepare(
            'UPDATE trips SET
                ended_at     = :ended_at,
                end_lat      = :end_lat,
                end_lon      = :end_lon,
                distance_km  = :distance_km,
                max_speed    = :max_speed,
                avg_speed    = :avg_speed,
                duration_sec = :duration_sec,
                points_count = :points_count
             WHERE id = :id'
        );
        $stmt->execute([
            ':ended_at'     => $a['ended_at'],
            ':end_lat'      => $a['end_lat'],
            ':end_lon'      => $a['end_lon'],
            ':distance_km'  => $a['distance_km'],
            ':max_speed'    => $a['max_speed'],
            ':avg_speed'    => $a['avg_speed'],
            ':duration_sec' => $a['duration_sec'],
            ':points_count' => $a['points_count'],
            ':id'           => $tripId,
        ]);
    }
}
