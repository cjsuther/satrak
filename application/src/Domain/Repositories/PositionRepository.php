<?php

declare(strict_types=1);

namespace Satrak\Domain\Repositories;

use PDO;

/**
 * Acceso a `positions` (alto volumen). El procesador resuelve `driver_id` y el
 * mapa en vivo / historial leen las últimas posiciones.
 */
final class PositionRepository
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * Inserta un lote de posiciones de la app, salteando los `ts` que ese
     * dispositivo ya tiene.
     *
     * La app bufferea offline y reintenta, así que el mismo lote puede llegar
     * dos veces; se filtra por `ts` en vez de con un UNIQUE en la tabla para no
     * tener que reescribir `positions` (alto volumen, datos históricos).
     *
     * @param array<int,array<string,mixed>> $points ya validados y ordenados por ts
     * @return int cantidad realmente insertada
     */
    public function insertBatch(int $companyId, int $deviceId, array $points): int
    {
        if ($points === []) {
            return 0;
        }

        $stamps = array_column($points, 'ts');
        $known = $this->existingTimestamps($deviceId, min($stamps), max($stamps));

        $stmt = $this->db->prepare(
            'INSERT INTO positions
                (company_id, device_id, ts, lat, lon, speed, heading, accuracy_m, battery_pct)
             VALUES (:company_id, :device_id, :ts, :lat, :lon, :speed, :heading, :accuracy, :battery)'
        );

        $inserted = 0;
        foreach ($points as $p) {
            if (isset($known[$p['ts']])) {
                continue;
            }
            $stmt->execute([
                ':company_id' => $companyId,
                ':device_id'  => $deviceId,
                ':ts'         => $p['ts'],
                ':lat'        => $p['lat'],
                ':lon'        => $p['lon'],
                ':speed'      => $p['speed'],
                ':heading'    => $p['heading'],
                ':accuracy'   => $p['accuracy_m'],
                ':battery'    => $p['battery_pct'],
            ]);
            $known[$p['ts']] = true;   // evita duplicados dentro del mismo lote
            $inserted++;
        }

        return $inserted;
    }

    /**
     * `ts` que el dispositivo ya tiene en la ventana, como set para lookup O(1).
     *
     * @return array<string,true>
     */
    public function existingTimestamps(int $deviceId, string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            'SELECT ts FROM positions WHERE device_id = ? AND ts BETWEEN ? AND ?'
        );
        $stmt->execute([$deviceId, $from, $to]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $ts) {
            $out[(string) $ts] = true;
        }

        return $out;
    }

    /**
     * Última posición de una persona (portal y mapa). Va por el dispositivo que
     * tiene asignado, no por `driver_id`: la persona no se atribuye por PIN.
     *
     * @return array<string,mixed>|null
     */
    public function lastForPerson(int $personId, int $companyId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT p.id, p.device_id, p.ts, p.lat, p.lon, p.speed, p.heading, p.battery_pct, p.accuracy_m
             FROM positions p
             JOIN device_person_assignments a
               ON a.device_id = p.device_id AND a.unassigned_at IS NULL
             WHERE a.person_id = ? AND p.company_id = ?
             ORDER BY p.ts DESC, p.id DESC LIMIT 1'
        );
        $stmt->execute([$personId, $companyId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Posiciones nuevas de un dispositivo (id > cursor), en orden cronológico.
     * El desempate por id mantiene estable el orden ante ts iguales.
     *
     * @return array<int,array<string,mixed>>
     */
    public function newSince(int $deviceId, int $afterId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, ts, lat, lon, speed, ignition, pin_code, accuracy_m, battery_pct
             FROM positions
             WHERE device_id = ? AND id > ?
             ORDER BY ts ASC, id ASC'
        );
        $stmt->execute([$deviceId, $afterId]);

        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(int $positionId): ?array
    {
        $stmt = $this->db->prepare('SELECT id, ts, lat, lon, speed, ignition FROM positions WHERE id = ? LIMIT 1');
        $stmt->execute([$positionId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Última posición atribuida a un conductor (portal: "mi última posición").
     *
     * @return array<string,mixed>|null
     */
    public function lastForDriver(int $driverId, int $companyId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT p.id, p.device_id, p.ts, p.lat, p.lon, p.speed, p.heading, p.ignition, v.plate
             FROM positions p
             LEFT JOIN device_vehicle_assignments a ON a.device_id = p.device_id AND a.unassigned_at IS NULL
             LEFT JOIN vehicles v ON v.id = a.vehicle_id
             WHERE p.driver_id = ? AND p.company_id = ?
             ORDER BY p.ts DESC, p.id DESC LIMIT 1'
        );
        $stmt->execute([$driverId, $companyId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Puntos de un viaje atribuidos a un conductor (track del portal, scope estricto).
     *
     * @return array<int,array<string,mixed>>
     */
    public function trackForDriver(int $deviceId, int $driverId, string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            'SELECT ts, lat, lon, speed, ignition FROM positions
             WHERE device_id = ? AND driver_id = ? AND ts BETWEEN ? AND ?
             ORDER BY ts ASC, id ASC'
        );
        $stmt->execute([$deviceId, $driverId, $from, $to]);

        return $stmt->fetchAll();
    }

    /** Asigna el conductor resuelto a una posición. */
    public function setDriver(int $positionId, ?int $driverId): void
    {
        $stmt = $this->db->prepare('UPDATE positions SET driver_id = ? WHERE id = ?');
        $stmt->execute([$driverId, $positionId]);
    }

    /**
     * Puntos de un dispositivo en una ventana temporal cerrada [from, to], en
     * orden cronológico. Lo usa el TripBuilder para calcular agregados del viaje.
     *
     * @return array<int,array<string,mixed>>
     */
    public function inWindow(int $deviceId, string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, ts, lat, lon, speed
             FROM positions
             WHERE device_id = ? AND ts BETWEEN ? AND ?
             ORDER BY ts ASC, id ASC'
        );
        $stmt->execute([$deviceId, $from, $to]);

        return $stmt->fetchAll();
    }
}
