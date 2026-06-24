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
     * Posiciones nuevas de un dispositivo (id > cursor), en orden cronológico.
     * El desempate por id mantiene estable el orden ante ts iguales.
     *
     * @return array<int,array<string,mixed>>
     */
    public function newSince(int $deviceId, int $afterId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, ts, lat, lon, speed, ignition, pin_code
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
