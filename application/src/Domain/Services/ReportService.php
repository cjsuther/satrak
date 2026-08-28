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
 *  - Por persona (módulo de personal): jornada prevista vs. tiempo con reporte,
 *    recorridos, misiones y alertas propias.
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
                    TRIM(CONCAT(COALESCE(dr.first_name,''),' ',COALESCE(dr.last_name,''))) AS driver_name,
                    TRIM(CONCAT(COALESCE(pe.first_name,''),' ',COALESCE(pe.last_name,''))) AS person_name
             FROM alerts a
             LEFT JOIN vehicles v ON v.id = a.vehicle_id
             LEFT JOIN drivers dr ON dr.id = a.driver_id
             LEFT JOIN people  pe ON pe.id = a.person_id
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


    /**
     * Reporte por persona (módulo de personal).
     *
     * Combina cuatro fuentes, porque una persona no se mide como un vehículo:
     *  - `trips` con `person_id`: recorridos y kilómetros.
     *  - `positions`: días con actividad y **tiempo con reporte** (del primer al
     *    último punto de cada día), que es la señal real de presencia.
     *  - `person_missions`: asignadas / cumplidas / no cumplidas.
     *  - `alerts`: episodios fuera de puesto, pánico y sin movimiento.
     *
     * La **jornada prevista** se calcula aparte ({@see expectedShiftSeconds}) a
     * partir de `person_shifts`, y sirve de denominador para la cobertura.
     *
     * @return array<int,array<string,mixed>>
     */
    public function byPerson(int $companyId, string $from, string $to, ?int $personId = null): array
    {
        $people = $this->peopleMap($companyId, $personId);
        if ($people === []) {
            return [];
        }

        $trips = $this->tripAggregatesByPerson($companyId, $from, $to, $personId);
        $presence = $this->presenceByPerson($companyId, $from, $to);
        $missions = $this->missionCountsByPerson($companyId, $from, $to);
        $alerts = $this->alertCountsBy('person_id', $companyId, $from, $to);

        $rows = [];
        foreach ($people as $pid => $name) {
            $t = $trips[$pid] ?? [];
            $p = $presence[$pid] ?? [];
            $m = $missions[$pid] ?? [];
            $a = $alerts[$pid] ?? [];

            $expected = $this->expectedShiftSeconds($pid, $companyId, $from, $to);
            $reported = (int) ($p['reported_sec'] ?? 0);

            $rows[] = [
                'person_id'      => $pid,
                'label'          => $name,
                'trips'          => (int) ($t['trips'] ?? 0),
                'km'             => round((float) ($t['km'] ?? 0), 2),
                'duration_sec'   => (int) ($t['duration_sec'] ?? 0),
                'days'           => (int) ($p['days'] ?? 0),
                'expected_sec'   => $expected,
                'reported_sec'   => $reported,
                'coverage_pct'   => $expected > 0 ? (int) round(min(100, $reported / $expected * 100)) : null,
                'missions'       => (int) ($m['total'] ?? 0),
                'missions_done'  => (int) ($m['completed'] ?? 0),
                'missions_miss'  => (int) ($m['missed'] ?? 0),
                'off_post'       => (int) ($a['off_post'] ?? 0),
                'panic'          => (int) (($a['panic'] ?? 0) + ($a['sos'] ?? 0)),
                'no_movement'    => (int) ($a['no_movement'] ?? 0),
            ];
        }

        usort($rows, static fn ($x, $y) => $y['reported_sec'] <=> $x['reported_sec']);

        return $rows;
    }

    /** @return array<int,string> person_id => nombre */
    private function peopleMap(int $companyId, ?int $personId): array
    {
        $sql = 'SELECT id, first_name, last_name FROM people WHERE company_id = ?';
        $bind = [$companyId];
        if ($personId !== null) {
            $sql .= ' AND id = ?';
            $bind[] = $personId;
        }
        $sql .= ' ORDER BY last_name, first_name';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($bind);

        $map = [];
        foreach ($stmt->fetchAll() as $r) {
            $map[(int) $r['id']] = trim($r['first_name'] . ' ' . $r['last_name']);
        }

        return $map;
    }

    /** @return array<int,array<string,mixed>> */
    private function tripAggregatesByPerson(int $companyId, string $from, string $to, ?int $personId): array
    {
        $sql =
            'SELECT t.person_id,
                    COUNT(*)                         AS trips,
                    COALESCE(SUM(t.distance_km), 0)  AS km,
                    COALESCE(SUM(t.duration_sec), 0) AS duration_sec
             FROM trips t
             WHERE t.company_id = ? AND t.person_id IS NOT NULL AND t.ended_at IS NOT NULL
               AND t.started_at BETWEEN ? AND ?';
        $bind = [$companyId, $from, $to];
        if ($personId !== null) {
            $sql .= ' AND t.person_id = ?';
            $bind[] = $personId;
        }
        $sql .= ' GROUP BY t.person_id';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($bind);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['person_id']] = $r;
        }

        return $out;
    }

    /**
     * Días con actividad y tiempo con reporte, por persona.
     *
     * La posición se atribuye a quien tenía el equipo **en ese momento**, no a
     * quien lo tiene hoy: por eso el JOIN acota por la ventana de la asignación.
     *
     * @return array<int,array<string,mixed>>
     */
    private function presenceByPerson(int $companyId, string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            'SELECT person_id, COUNT(*) AS days,
                    COALESCE(SUM(TIMESTAMPDIFF(SECOND, first_ts, last_ts)), 0) AS reported_sec
             FROM (
               SELECT a.person_id, DATE(p.ts) AS d, MIN(p.ts) AS first_ts, MAX(p.ts) AS last_ts
               FROM positions p
               JOIN device_person_assignments a
                 ON a.device_id = p.device_id
                AND p.ts >= a.assigned_at
                AND (a.unassigned_at IS NULL OR p.ts <= a.unassigned_at)
               WHERE p.company_id = ? AND p.ts BETWEEN ? AND ?
               GROUP BY a.person_id, DATE(p.ts)
             ) x
             GROUP BY person_id'
        );
        $stmt->execute([$companyId, $from, $to]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['person_id']] = $r;
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function missionCountsByPerson(int $companyId, string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            "SELECT person_id,
                    COUNT(*) AS total,
                    SUM(status = 'completed') AS completed,
                    SUM(status = 'missed')    AS missed
             FROM person_missions
             WHERE company_id = ? AND status <> 'cancelled'
               AND scheduled_start BETWEEN ? AND ?
             GROUP BY person_id"
        );
        $stmt->execute([$companyId, $from, $to]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['person_id']] = $r;
        }

        return $out;
    }

    /**
     * Segundos de jornada previstos en el rango, según `person_shifts` y sus
     * excepciones. Recorre día por día: los rangos de un reporte son de semanas
     * o meses, no de años, así que el costo es despreciable y evita replicar en
     * SQL la lógica de medianoche y excepciones.
     */
    private function expectedShiftSeconds(int $personId, int $companyId, string $from, string $to): int
    {
        $shifts = $this->shiftsByWeekday($personId, $companyId);
        if ($shifts === []) {
            return 0;
        }
        $exceptions = $this->exceptionsInRange($personId, $companyId, $from, $to);

        $total = 0;
        $day = new \DateTimeImmutable(substr($from, 0, 10));
        $last = new \DateTimeImmutable(substr($to, 0, 10));

        while ($day <= $last) {
            $date = $day->format('Y-m-d');
            $exc = $exceptions[$date] ?? [];

            // 'extra' suma su propia ventana y pisa el franco de ese día.
            $extras = array_filter($exc, static fn ($e) => $e['kind'] === 'extra');
            if ($extras !== []) {
                foreach ($extras as $e) {
                    $total += $this->windowSeconds((string) $e['start_time'], (string) $e['end_time']);
                }
            } elseif (!array_filter($exc, static fn ($e) => $e['kind'] === 'off')) {
                foreach ($shifts[(int) $day->format('N')] ?? [] as $w) {
                    $total += $this->windowSeconds((string) $w['start_time'], (string) $w['end_time']);
                }
            }

            $day = $day->modify('+1 day');
        }

        return $total;
    }

    /** Duración de una ventana, contemplando el cruce de medianoche. */
    private function windowSeconds(string $start, string $end): int
    {
        $a = strtotime('1970-01-01 ' . $start);
        $b = strtotime('1970-01-01 ' . $end);
        if ($a === false || $b === false) {
            return 0;
        }

        return $b > $a ? $b - $a : ($b + 86400) - $a;
    }

    /** @return array<int,array<int,array<string,mixed>>> weekday => ventanas */
    private function shiftsByWeekday(int $personId, int $companyId): array
    {
        $stmt = $this->db->prepare(
            'SELECT weekday, start_time, end_time FROM person_shifts
             WHERE person_id = ? AND company_id = ? AND active = 1'
        );
        $stmt->execute([$personId, $companyId]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['weekday']][] = $r;
        }

        return $out;
    }

    /** @return array<string,array<int,array<string,mixed>>> fecha => excepciones */
    private function exceptionsInRange(int $personId, int $companyId, string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            'SELECT date, kind, start_time, end_time FROM person_shift_exceptions
             WHERE person_id = ? AND company_id = ? AND date BETWEEN ? AND ?'
        );
        $stmt->execute([$personId, $companyId, substr($from, 0, 10), substr($to, 0, 10)]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(string) $r['date']][] = $r;
        }

        return $out;
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
        $col = in_array($column, ['driver_id', 'person_id'], true) ? $column : 'vehicle_id';
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
