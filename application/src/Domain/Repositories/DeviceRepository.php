<?php

declare(strict_types=1);

namespace Satrak\Domain\Repositories;

use Satrak\Application\Support\Listing;

/**
 * Acceso a la tabla `devices`. IMEI único global; el alta valida el cupo
 * (`companies.device_quota`) contando dispositivos activos de la empresa.
 */
final class DeviceRepository extends BaseRepository
{
    /** @return array<string,mixed>|null */
    public function findScoped(int $id, int $companyId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM devices WHERE id = ? AND company_id = ? LIMIT 1');
        $stmt->execute([$id, $companyId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function imeiTaken(string $imei, ?int $exceptId = null): bool
    {
        $stmt = $this->db->prepare('SELECT id FROM devices WHERE imei = ? LIMIT 1');
        $stmt->execute([trim($imei)]);
        $row = $stmt->fetch();

        return $row !== false && (int) $row['id'] !== (int) $exceptId;
    }

    /** Dispositivos activos de la empresa (para el cupo). */
    public function countActive(int $companyId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM devices WHERE company_id = ? AND status = 'active'");
        $stmt->execute([$companyId]);

        return (int) $stmt->fetchColumn();
    }

    /** @param array<string,mixed> $data */
    public function create(int $companyId, array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO devices (company_id, imei, label, model, protocol, sim_iccid, has_pin, status)
             VALUES (:company_id, :imei, :label, :model, :protocol, :sim_iccid, :has_pin, :status)'
        );
        $stmt->execute([
            ':company_id' => $companyId,
            ':imei'       => trim($data['imei']),
            ':label'      => $data['label'] ?: null,
            ':model'      => $data['model'] ?: null,
            ':protocol'   => $data['protocol'] ?: null,
            ':sim_iccid'  => $data['sim_iccid'] ?: null,
            ':has_pin'    => !empty($data['has_pin']) ? 1 : 0,
            ':status'     => $data['status'] ?? 'active',
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE devices SET imei=:imei, label=:label, model=:model, protocol=:protocol,
             sim_iccid=:sim_iccid, has_pin=:has_pin, status=:status WHERE id=:id'
        );
        $stmt->execute([
            ':imei'      => trim($data['imei']),
            ':label'     => $data['label'] ?: null,
            ':model'     => $data['model'] ?: null,
            ':protocol'  => $data['protocol'] ?: null,
            ':sim_iccid' => $data['sim_iccid'] ?: null,
            ':has_pin'   => !empty($data['has_pin']) ? 1 : 0,
            ':status'    => $data['status'],
            ':id'        => $id,
        ]);
    }

    /** Dispositivos activos de la empresa (para selects de asignación). */
    public function activeForCompany(int $companyId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, imei, label, has_pin FROM devices
             WHERE company_id = ? AND status = 'active' ORDER BY COALESCE(label, imei)"
        );
        $stmt->execute([$companyId]);

        return $stmt->fetchAll();
    }

    /**
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int,sort:string,dir:string}
     */
    public function listPaginated(int $companyId, Listing $listing): array
    {
        return $this->paginate(
            'devices',
            [
                'id', 'imei', 'label', 'model', 'has_pin', 'status', 'last_seen_at',
                '(SELECT vehicle_id FROM device_vehicle_assignments a
                  WHERE a.device_id = devices.id AND a.unassigned_at IS NULL LIMIT 1) AS vehicle_id',
            ],
            ['company_id = :cid'],
            [':cid' => $companyId],
            ['imei', 'label', 'model'],
            ['imei' => 'imei', 'label' => 'label', 'status' => 'status', 'id' => 'id'],
            $listing,
            'label'
        );
    }
}
