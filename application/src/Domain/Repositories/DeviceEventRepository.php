<?php

declare(strict_types=1);

namespace Satrak\Domain\Repositories;

use PDO;

/**
 * Acceso a `device_events` (eventos crudos del equipo).
 *
 * El procesador consume los no procesados (pin_set/pin_cleared/ignition_*) para
 * mantener el PIN vigente y delimitar viajes, y los marca `processed = 1`.
 */
final class DeviceEventRepository
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * Eventos sin procesar de un dispositivo, en orden cronológico.
     *
     * @return array<int,array<string,mixed>>
     */
    public function unprocessed(int $deviceId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, ts, event_type, pin_code
             FROM device_events
             WHERE device_id = ? AND processed = 0
             ORDER BY ts ASC, id ASC'
        );
        $stmt->execute([$deviceId]);

        return $stmt->fetchAll();
    }

    /** Marca un evento como procesado. */
    public function markProcessed(int $eventId): void
    {
        $stmt = $this->db->prepare('UPDATE device_events SET processed = 1 WHERE id = ?');
        $stmt->execute([$eventId]);
    }
}
