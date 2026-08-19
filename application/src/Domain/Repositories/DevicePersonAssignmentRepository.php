<?php

declare(strict_types=1);

namespace Satrak\Domain\Repositories;

use PDO;

/**
 * Asignación dispositivo ↔ persona, espejo de `device_vehicle_assignments`.
 *
 * Invariante (en código, igual que en flota): un dispositivo y una persona no
 * pueden tener más de una fila con `unassigned_at IS NULL`. Al asignar se cierra
 * lo anterior de ambos lados dentro de una transacción.
 */
final class DevicePersonAssignmentRepository
{
    public function __construct(private PDO $db)
    {
    }

    /** Persona con el dispositivo asignado ahora, o NULL. */
    public function activePersonId(int $deviceId, int $companyId): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT person_id FROM device_person_assignments
             WHERE device_id = ? AND company_id = ? AND unassigned_at IS NULL
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$deviceId, $companyId]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    /** Dispositivo asignado a la persona ahora, o NULL. */
    public function activeDeviceId(int $personId, int $companyId): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT device_id FROM device_person_assignments
             WHERE person_id = ? AND company_id = ? AND unassigned_at IS NULL
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$personId, $companyId]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    /**
     * Asigna el dispositivo a la persona, cerrando cualquier asignación activa
     * previa del dispositivo y de la persona. Idempotente: si ya están
     * vinculados entre sí, no hace nada.
     */
    public function assign(int $companyId, int $deviceId, int $personId): void
    {
        if ($this->activePersonId($deviceId, $companyId) === $personId
            && $this->activeDeviceId($personId, $companyId) === $deviceId) {
            return;
        }

        $this->db->beginTransaction();
        try {
            $close = $this->db->prepare(
                'UPDATE device_person_assignments SET unassigned_at = NOW()
                 WHERE company_id = ? AND unassigned_at IS NULL AND (device_id = ? OR person_id = ?)'
            );
            $close->execute([$companyId, $deviceId, $personId]);

            $ins = $this->db->prepare(
                'INSERT INTO device_person_assignments (company_id, device_id, person_id)
                 VALUES (?, ?, ?)'
            );
            $ins->execute([$companyId, $deviceId, $personId]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }
    }

    public function unassignDevice(int $deviceId, int $companyId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE device_person_assignments SET unassigned_at = NOW()
             WHERE device_id = ? AND company_id = ? AND unassigned_at IS NULL'
        );
        $stmt->execute([$deviceId, $companyId]);
    }

    /**
     * Persona asignada a cada dispositivo de la empresa (mapa unificado).
     *
     * @return array<int,array<string,mixed>> device_id => persona
     */
    public function activeByCompany(int $companyId): array
    {
        $stmt = $this->db->prepare(
            'SELECT a.device_id, p.id AS person_id, p.first_name, p.last_name
             FROM device_person_assignments a
             JOIN people p ON p.id = a.person_id
             WHERE a.company_id = ? AND a.unassigned_at IS NULL'
        );
        $stmt->execute([$companyId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['device_id']] = $row;
        }

        return $out;
    }
}
