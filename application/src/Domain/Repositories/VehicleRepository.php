<?php

declare(strict_types=1);

namespace Satrak\Domain\Repositories;

use Satrak\Application\Support\Listing;

/**
 * Acceso a la tabla `vehicles`. Patente única por empresa.
 */
final class VehicleRepository extends BaseRepository
{
    /** @return array<string,mixed>|null */
    public function findScoped(int $id, int $companyId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM vehicles WHERE id = ? AND company_id = ? LIMIT 1');
        $stmt->execute([$id, $companyId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Por id, sin scope (uso interno del procesador con ids ya confiables). @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT id, plate FROM vehicles WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function plateTaken(string $plate, int $companyId, ?int $exceptId = null): bool
    {
        $stmt = $this->db->prepare('SELECT id FROM vehicles WHERE company_id = ? AND plate = ? LIMIT 1');
        $stmt->execute([$companyId, mb_strtoupper(trim($plate))]);
        $row = $stmt->fetch();

        return $row !== false && (int) $row['id'] !== (int) $exceptId;
    }

    /** @param array<string,mixed> $data */
    public function create(int $companyId, array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO vehicles (company_id, plate, brand, model, year, type, color, status)
             VALUES (:company_id, :plate, :brand, :model, :year, :type, :color, :status)'
        );
        $stmt->execute([
            ':company_id' => $companyId,
            ':plate'      => mb_strtoupper(trim($data['plate'])),
            ':brand'      => $data['brand'] ?: null,
            ':model'      => $data['model'] ?: null,
            ':year'       => $data['year'] ?: null,
            ':type'       => $data['type'],
            ':color'      => $data['color'] ?: null,
            ':status'     => $data['status'] ?? 'active',
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE vehicles SET plate=:plate, brand=:brand, model=:model, year=:year,
             type=:type, color=:color, status=:status WHERE id=:id'
        );
        $stmt->execute([
            ':plate'  => mb_strtoupper(trim($data['plate'])),
            ':brand'  => $data['brand'] ?: null,
            ':model'  => $data['model'] ?: null,
            ':year'   => $data['year'] ?: null,
            ':type'   => $data['type'],
            ':color'  => $data['color'] ?: null,
            ':status' => $data['status'],
            ':id'     => $id,
        ]);
    }

    /** Vehículos activos de la empresa (para selects). @return array<int,array<string,mixed>> */
    public function activeForCompany(int $companyId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, plate, brand, model FROM vehicles
             WHERE company_id = ? AND status = 'active' ORDER BY plate"
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
            'vehicles',
            [
                'id', 'plate', 'brand', 'model', 'year', 'type', 'status',
                '(SELECT device_id FROM device_vehicle_assignments a
                  WHERE a.vehicle_id = vehicles.id AND a.unassigned_at IS NULL LIMIT 1) AS device_id',
            ],
            ['company_id = :cid'],
            [':cid' => $companyId],
            ['plate', 'brand', 'model'],
            ['plate' => 'plate', 'type' => 'type', 'status' => 'status', 'id' => 'id'],
            $listing,
            'plate'
        );
    }
}
