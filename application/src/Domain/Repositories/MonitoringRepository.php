<?php

declare(strict_types=1);

namespace Satrak\Domain\Repositories;

use PDO;

/**
 * Consultas de monitoreo (mapa en vivo e historial), siempre scopeadas por
 * `company_id`. Sólo lectura: el procesador es quien escribe posiciones/viajes.
 */
final class MonitoringRepository
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * Última posición por dispositivo activo de la empresa. Base del **mapa
     * unificado**: trae tanto equipos de flota (con vehículo y conductor vigente
     * de `processor_state`) como equipos de persona (con la persona asignada).
     *
     * `kind` distingue unos de otros; el controlador arma la etiqueta y el estado.
     *
     * @return array<int,array<string,mixed>>
     */
    public function livePositions(int $companyId): array
    {
        $stmt = $this->db->prepare(
            'SELECT
                d.id           AS device_id,
                d.imei,
                d.label,
                d.has_pin,
                d.kind,
                d.last_seen_at,
                p.id           AS position_id,
                p.ts,
                p.lat,
                p.lon,
                p.speed,
                p.heading,
                p.ignition,
                p.battery_pct,
                v.id           AS vehicle_id,
                v.plate,
                ps.current_driver_id,
                dr.first_name,
                dr.last_name,
                pe.id          AS person_id,
                pe.first_name  AS person_first_name,
                pe.last_name   AS person_last_name
             FROM devices d
             LEFT JOIN positions p ON p.id = d.last_position_id
             LEFT JOIN device_vehicle_assignments a
                    ON a.device_id = d.id AND a.unassigned_at IS NULL
             LEFT JOIN vehicles v ON v.id = a.vehicle_id
             LEFT JOIN device_person_assignments dpa
                    ON dpa.device_id = d.id AND dpa.unassigned_at IS NULL
             LEFT JOIN people pe ON pe.id = dpa.person_id
             LEFT JOIN processor_state ps ON ps.device_id = d.id
             LEFT JOIN drivers dr ON dr.id = ps.current_driver_id
             WHERE d.company_id = :cid AND d.status = "active"
             ORDER BY COALESCE(v.plate, CONCAT(pe.last_name, ", ", pe.first_name), d.imei)'
        );
        $stmt->execute([':cid' => $companyId]);

        return $stmt->fetchAll();
    }

    /**
     * Puntos de un dispositivo en un rango (para dibujar la traza y reproducir).
     *
     * @return array<int,array<string,mixed>>
     */
    public function track(int $deviceId, int $companyId, string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            'SELECT p.ts, p.lat, p.lon, p.speed, p.heading, p.ignition, p.driver_id
             FROM positions p
             WHERE p.device_id = :did AND p.company_id = :cid AND p.ts BETWEEN :from AND :to
             ORDER BY p.ts ASC, p.id ASC'
        );
        $stmt->execute([':did' => $deviceId, ':cid' => $companyId, ':from' => $from, ':to' => $to]);

        return $stmt->fetchAll();
    }

    /**
     * Viajes de un dispositivo en un rango, con el conductor atribuido. Lista
     * lateral del historial (§11).
     *
     * @return array<int,array<string,mixed>>
     */
    public function tripsInRange(int $deviceId, int $companyId, string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            'SELECT t.id, t.driver_id, t.started_at, t.ended_at,
                    t.distance_km, t.max_speed, t.avg_speed, t.duration_sec, t.points_count,
                    dr.first_name, dr.last_name
             FROM trips t
             LEFT JOIN drivers dr ON dr.id = t.driver_id
             WHERE t.device_id = :did AND t.company_id = :cid AND t.started_at BETWEEN :from AND :to
             ORDER BY t.started_at ASC'
        );
        $stmt->execute([':did' => $deviceId, ':cid' => $companyId, ':from' => $from, ':to' => $to]);

        return $stmt->fetchAll();
    }

    /**
     * Dispositivos activos de la empresa con su vehículo o su persona (selector
     * del historial). El `kind` permite agrupar el select por tipo de unidad.
     *
     * @return array<int,array<string,mixed>>
     */
    public function devicesForSelect(int $companyId): array
    {
        $stmt = $this->db->prepare(
            'SELECT d.id, d.imei, d.label, d.kind, v.plate,
                    TRIM(CONCAT(COALESCE(pe.first_name, ""), " ", COALESCE(pe.last_name, ""))) AS person_name
             FROM devices d
             LEFT JOIN device_vehicle_assignments a
                    ON a.device_id = d.id AND a.unassigned_at IS NULL
             LEFT JOIN vehicles v ON v.id = a.vehicle_id
             LEFT JOIN device_person_assignments dpa
                    ON dpa.device_id = d.id AND dpa.unassigned_at IS NULL
             LEFT JOIN people pe ON pe.id = dpa.person_id
             WHERE d.company_id = :cid AND d.status = "active"
             ORDER BY d.kind, COALESCE(v.plate, pe.last_name, d.imei)'
        );
        $stmt->execute([':cid' => $companyId]);

        return $stmt->fetchAll();
    }
}
