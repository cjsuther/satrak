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

    /**
     * Registra un evento crudo. Lo usa la API de la app (pánico, batería,
     * permisos) con el mismo contrato que usará el módulo de captura real.
     *
     * @param array<string,mixed>|null $raw
     */
    public function create(
        int $companyId,
        int $deviceId,
        string $ts,
        string $eventType,
        ?array $raw = null
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO device_events (company_id, device_id, ts, event_type, raw)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $companyId,
            $deviceId,
            $ts,
            $eventType,
            $raw !== null ? json_encode($raw, JSON_UNESCAPED_UNICODE) : null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * ¿Ya hay un evento de ese tipo para el dispositivo en ese instante?
     * Evita duplicar el pánico cuando la app reintenta sin conexión.
     */
    public function existsAt(int $deviceId, string $eventType, string $ts): bool
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM device_events WHERE device_id = ? AND event_type = ? AND ts = ? LIMIT 1'
        );
        $stmt->execute([$deviceId, $eventType, $ts]);

        return $stmt->fetch() !== false;
    }
}
