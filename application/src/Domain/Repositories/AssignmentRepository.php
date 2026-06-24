<?php

declare(strict_types=1);

namespace Satrak\Domain\Repositories;

use PDO;

/**
 * Asignación dispositivo ↔ vehículo (`device_vehicle_assignments`).
 *
 * Invariante (en código): a lo sumo UNA fila activa (unassigned_at IS NULL) por
 * device y por vehicle. Al reasignar se cierran las activas previas. Se conserva
 * el historial completo (filas cerradas).
 */
final class AssignmentRepository
{
    public function __construct(private PDO $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function activeForDevice(int $deviceId, int $companyId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT a.*, v.plate FROM device_vehicle_assignments a
             JOIN vehicles v ON v.id = a.vehicle_id
             WHERE a.device_id = ? AND a.company_id = ? AND a.unassigned_at IS NULL LIMIT 1'
        );
        $stmt->execute([$deviceId, $companyId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function activeForVehicle(int $vehicleId, int $companyId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT a.*, d.imei, d.label FROM device_vehicle_assignments a
             JOIN devices d ON d.id = a.device_id
             WHERE a.vehicle_id = ? AND a.company_id = ? AND a.unassigned_at IS NULL LIMIT 1'
        );
        $stmt->execute([$vehicleId, $companyId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Historial de un dispositivo. @return array<int,array<string,mixed>> */
    public function historyForDevice(int $deviceId, int $companyId): array
    {
        $stmt = $this->db->prepare(
            'SELECT a.*, v.plate FROM device_vehicle_assignments a
             JOIN vehicles v ON v.id = a.vehicle_id
             WHERE a.device_id = ? AND a.company_id = ? ORDER BY a.assigned_at DESC'
        );
        $stmt->execute([$deviceId, $companyId]);

        return $stmt->fetchAll();
    }

    /**
     * Asigna un dispositivo a un vehículo, cerrando asignaciones activas previas
     * (del device y del vehicle). Atómico.
     */
    public function assign(int $companyId, int $deviceId, int $vehicleId): void
    {
        $this->db->beginTransaction();
        try {
            $close = $this->db->prepare(
                'UPDATE device_vehicle_assignments SET unassigned_at = NOW()
                 WHERE company_id = ? AND unassigned_at IS NULL AND (device_id = ? OR vehicle_id = ?)'
            );
            $close->execute([$companyId, $deviceId, $vehicleId]);

            $ins = $this->db->prepare(
                'INSERT INTO device_vehicle_assignments (company_id, device_id, vehicle_id)
                 VALUES (?, ?, ?)'
            );
            $ins->execute([$companyId, $deviceId, $vehicleId]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Cierra la asignación activa del dispositivo (si la hay). */
    public function unassignDevice(int $companyId, int $deviceId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE device_vehicle_assignments SET unassigned_at = NOW()
             WHERE company_id = ? AND device_id = ? AND unassigned_at IS NULL'
        );
        $stmt->execute([$companyId, $deviceId]);
    }
}
