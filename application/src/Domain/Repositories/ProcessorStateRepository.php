<?php

declare(strict_types=1);

namespace Satrak\Domain\Repositories;

use PDO;

/**
 * Estado del procesador por dispositivo (`processor_state`).
 *
 * Guarda el PIN vigente (§8), el conductor resuelto, el cursor de la última
 * posición procesada y el viaje abierto. Hace que `bin/processor.php` sea
 * idempotente: cada corrida retoma exactamente donde quedó la anterior.
 */
final class ProcessorStateRepository
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * Estado del dispositivo. Si no existe, devuelve un estado vacío (cursor 0).
     *
     * @return array{device_id:int,company_id:int,current_pin:?string,current_driver_id:?int,last_position_id:int,open_trip_id:?int}
     */
    public function get(int $deviceId, int $companyId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM processor_state WHERE device_id = ? LIMIT 1');
        $stmt->execute([$deviceId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return [
                'device_id'         => $deviceId,
                'company_id'        => $companyId,
                'current_pin'       => null,
                'current_driver_id' => null,
                'last_position_id'  => 0,
                'open_trip_id'      => null,
            ];
        }

        return [
            'device_id'         => (int) $row['device_id'],
            'company_id'        => (int) $row['company_id'],
            'current_pin'       => $row['current_pin'] !== null ? (string) $row['current_pin'] : null,
            'current_driver_id' => $row['current_driver_id'] !== null ? (int) $row['current_driver_id'] : null,
            'last_position_id'  => (int) ($row['last_position_id'] ?? 0),
            'open_trip_id'      => $row['open_trip_id'] !== null ? (int) $row['open_trip_id'] : null,
        ];
    }

    /**
     * Persiste el estado del dispositivo (upsert).
     */
    public function save(
        int $deviceId,
        int $companyId,
        ?string $currentPin,
        ?int $currentDriverId,
        int $lastPositionId,
        ?int $openTripId
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO processor_state
                (device_id, company_id, current_pin, current_driver_id, last_position_id, open_trip_id)
             VALUES (:device_id, :company_id, :current_pin, :current_driver_id, :last_position_id, :open_trip_id)
             ON DUPLICATE KEY UPDATE
                current_pin       = VALUES(current_pin),
                current_driver_id = VALUES(current_driver_id),
                last_position_id  = VALUES(last_position_id),
                open_trip_id      = VALUES(open_trip_id)'
        );
        $stmt->execute([
            ':device_id'         => $deviceId,
            ':company_id'        => $companyId,
            ':current_pin'       => $currentPin,
            ':current_driver_id' => $currentDriverId,
            ':last_position_id'  => $lastPositionId,
            ':open_trip_id'      => $openTripId,
        ]);
    }
}
