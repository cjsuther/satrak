<?php

declare(strict_types=1);

namespace Satrak\Domain\Services;

use PDO;

/**
 * Reportes (spec §13). Sólo lectura, agregando sobre `trips` y `alerts`, siempre
 * scopeado por empresa.
 *
 *  - Por vehículo: viajes, km, tiempo en movimiento, velocidad máx/prom, excesos
 *    de velocidad y eventos de geocerca.
 *  - Por conductor (vía PIN): mismos indicadores, incluyendo la categoría
 *    "conductor no identificado" (driver_id NULL) — el diferencial del PIN.
 *  - Alertas: listado filtrable por tipo/severidad/estado.
 */
final class ReportService
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * Reporte por vehículo. Sólo viajes cerrados (con métricas calculadas).
     *
     * @return array<int,array<string,mixed>>
     */
    public function byVehicle(int $companyId, string $from, string $to, ?int $vehicleId = null): array
    {
        $sql =
            'SELECT t.vehicle_id,
                    COUNT(*)                              AS trips,
                    COALESCE(SUM(t.distance_km), 0)       AS km,
                    COALESCE(SUM(t.duration_sec), 0)      AS duration_sec,
                    COALESCE(MAX(t.max_speed), 0)         AS max_speed,
                    COALESCE(ROUND(AVG(NULLIF(t.avg_speed, 0))), 0) AS avg_speed
             FROM trips t
             WHERE t.company_id = :cid AND t.ended_at IS NOT NULL
               AND t.started_at BETWEEN :from AND :to';
        $bind = [':cid' => $companyId, ':from' => $from, ':to' => $to];
        if ($vehicleId !== null) {
            $sql .= ' AND t.vehicle_id = :vid';
            $bind[':vid'] = $vehicleId;
        }
        $sql .= ' GROUP BY t.vehicle_id ORDER BY km DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($bind);
        $rows = $stmt->fetchAll();

        // Patentes y conteos de alertas por vehículo.
        $plates = $this->plateMap($companyId);
        $alerts = $this->alertCountsBy('vehicle_id', $companyId, $from, $to);

        foreach ($rows as &$r) {
            $vid = $r['vehicle_id'] !== null ? (int) $r['vehicle_id'] : 0;
            $r['label'] = $vid > 0 ? ($plates[$vid] ?? ('vehículo #' . $vid)) : 'Sin vehículo';
            $r['speed_alerts'] = $alerts[$vid]['speed'] ?? 0;
            $r['geofence_alerts'] = ($alerts[$vid]['geofence_enter'] ?? 0) + ($alerts[$vid]['geofence_exit'] ?? 0);
        }

        return $rows;
    }

    /**
     * Reporte por conductor, incluyendo "no identificado" (driver_id NULL).
     *
     * @return array<int,array<string,mixed>>
     */
    public function byDriver(int $companyId, string $from, string $to, ?int $driverId = null): array
    {
        $sql =
            'SELECT t.driver_id,
                    COUNT(*)                              AS trips,
                    COALESCE(SUM(t.distance_km), 0)       AS km,
                    COALESCE(SUM(t.duration_sec), 0)      AS duration_sec,
                    COALESCE(MAX(t.max_speed), 0)         AS max_speed,
                    COALESCE(ROUND(AVG(NULLIF(t.avg_speed, 0))), 0) AS avg_speed
             FROM trips t
             WHERE t.company_id = :cid AND t.ended_at IS NOT NULL
               AND t.started_at BETWEEN :from AND :to';
        $bind = [':cid' => $companyId, ':from' => $from, ':to' => $to];
        if ($driverId !== null) {
            $sql .= ' AND t.driver_id = :did';
            $bind[':did'] = $driverId;
        }
        $sql .= ' GROUP BY t.driver_id ORDER BY km DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($bind);
        $rows = $stmt->fetchAll();

        $names = $this->driverNameMap($companyId);
        $alerts = $this->alertCountsBy('driver_id', $companyId, $from, $to);

        foreach ($rows as &$r) {
            $did = $r['driver_id'] !== null ? (int) $r['driver_id'] : 0;
            $r['label'] = $did > 0 ? ($names[$did] ?? ('conductor #' . $did)) : 'Conductor no identificado';
            $r['speed_alerts'] = $alerts[$did]['speed'] ?? 0;
        }

        return $rows;
    }

    /**
     * Listado de alertas filtrable (reporte de alertas).
     *
     * @return array<int,array<string,mixed>>
     */
    public function alerts(
        int $companyId,
        string $from,
        string $to,
        ?string $type = null,
        ?string $severity = null,
        ?string $status = null
    ): array {
        $sql =
            "SELECT a.id, a.type, a.severity, a.message, a.ts, a.acknowledged_at,
                    v.plate,
                    TRIM(CONCAT(COALESCE(dr.first_name,''),' ',COALESCE(dr.last_name,''))) AS driver_name
             FROM alerts a
             LEFT JOIN vehicles v ON v.id = a.vehicle_id
             LEFT JOIN drivers dr ON dr.id = a.driver_id
             WHERE a.company_id = :cid AND a.ts BETWEEN :from AND :to";
        $bind = [':cid' => $companyId, ':from' => $from, ':to' => $to];
        if ($type !== null && $type !== '') {
            $sql .= ' AND a.type = :type';
            $bind[':type'] = $type;
        }
        if ($severity !== null && $severity !== '') {
            $sql .= ' AND a.severity = :sev';
            $bind[':sev'] = $severity;
        }
        if ($status === 'open') {
            $sql .= ' AND a.acknowledged_at IS NULL';
        } elseif ($status === 'ack') {
            $sql .= ' AND a.acknowledged_at IS NOT NULL';
        }
        $sql .= ' ORDER BY a.ts DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($bind);

        return $stmt->fetchAll();
    }

    // -- Helpers --------------------------------------------------------------

    /** @return array<int,string> vehicle_id => plate */
    private function plateMap(int $companyId): array
    {
        $stmt = $this->db->prepare('SELECT id, plate FROM vehicles WHERE company_id = ?');
        $stmt->execute([$companyId]);
        $map = [];
        foreach ($stmt->fetchAll() as $r) {
            $map[(int) $r['id']] = (string) $r['plate'];
        }

        return $map;
    }

    /** @return array<int,string> driver_id => nombre */
    private function driverNameMap(int $companyId): array
    {
        $stmt = $this->db->prepare('SELECT id, first_name, last_name FROM drivers WHERE company_id = ?');
        $stmt->execute([$companyId]);
        $map = [];
        foreach ($stmt->fetchAll() as $r) {
            $map[(int) $r['id']] = trim($r['first_name'] . ' ' . $r['last_name']);
        }

        return $map;
    }

    /**
     * Conteo de alertas por entidad y tipo en el rango.
     *
     * @return array<int,array<string,int>> id (0 = NULL) => [type => count]
     */
    private function alertCountsBy(string $column, int $companyId, string $from, string $to): array
    {
        $col = $column === 'driver_id' ? 'driver_id' : 'vehicle_id';
        $stmt = $this->db->prepare(
            "SELECT COALESCE({$col}, 0) AS gid, type, COUNT(*) AS n
             FROM alerts WHERE company_id = ? AND ts BETWEEN ? AND ?
             GROUP BY gid, type"
        );
        $stmt->execute([$companyId, $from, $to]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['gid']][(string) $r['type']] = (int) $r['n'];
        }

        return $out;
    }
}
