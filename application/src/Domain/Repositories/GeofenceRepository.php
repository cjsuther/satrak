<?php

declare(strict_types=1);

namespace Satrak\Domain\Repositories;

use PDO;

/**
 * Geocercas (`geofences`) y su alcance por vehículo (`geofence_vehicles`).
 *
 * Convención: si una geocerca no tiene filas en `geofence_vehicles`, aplica a
 * TODOS los vehículos de la empresa (spec §7).
 */
final class GeofenceRepository
{
    public function __construct(private PDO $db)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function forCompany(int $companyId): array
    {
        $stmt = $this->db->prepare(
            'SELECT g.*,
                    (SELECT COUNT(*) FROM geofence_vehicles gv WHERE gv.geofence_id = g.id) AS vehicle_count
             FROM geofences g WHERE g.company_id = ? ORDER BY g.name'
        );
        $stmt->execute([$companyId]);

        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function findScoped(int $id, int $companyId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM geofences WHERE id = ? AND company_id = ? LIMIT 1');
        $stmt->execute([$id, $companyId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Ids de vehículos alcanzados (lista explícita; vacía = todos). @return int[] */
    public function vehicleIds(int $geofenceId): array
    {
        $stmt = $this->db->prepare('SELECT vehicle_id FROM geofence_vehicles WHERE geofence_id = ?');
        $stmt->execute([$geofenceId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Geocercas activas con su set de vehículos, para el motor de alertas.
     *
     * @return array<int,array<string,mixed>> cada una con clave 'vehicle_ids' (int[])
     */
    public function activeWithVehicles(int $companyId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM geofences WHERE company_id = ? AND active = 1');
        $stmt->execute([$companyId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$g) {
            $g['vehicle_ids'] = $this->vehicleIds((int) $g['id']);
        }

        return $rows;
    }

    /**
     * Crea una geocerca y su alcance. La geometría llega ya validada/serializada.
     *
     * @param int[] $vehicleIds
     */
    public function create(int $companyId, string $name, string $shape, string $geometryJson, array $vehicleIds): int
    {
        $this->db->beginTransaction();
        try {
            $ins = $this->db->prepare(
                'INSERT INTO geofences (company_id, name, shape, geometry, active)
                 VALUES (?, ?, ?, ?, 1)'
            );
            $ins->execute([$companyId, $name, $shape, $geometryJson]);
            $id = (int) $this->db->lastInsertId();
            $this->replaceVehicles($id, $vehicleIds);
            $this->db->commit();

            return $id;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @param int[] $vehicleIds */
    public function update(int $id, string $name, string $geometryJson, array $vehicleIds, bool $active): void
    {
        $this->db->beginTransaction();
        try {
            $upd = $this->db->prepare('UPDATE geofences SET name = ?, geometry = ?, active = ? WHERE id = ?');
            $upd->execute([$name, $geometryJson, $active ? 1 : 0, $id]);
            $this->replaceVehicles($id, $vehicleIds);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function delete(int $id, int $companyId): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM geofence_vehicles WHERE geofence_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM geofences WHERE id = ? AND company_id = ?')->execute([$id, $companyId]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @param int[] $vehicleIds */
    private function replaceVehicles(int $geofenceId, array $vehicleIds): void
    {
        $this->db->prepare('DELETE FROM geofence_vehicles WHERE geofence_id = ?')->execute([$geofenceId]);
        if ($vehicleIds === []) {
            return;
        }
        $ins = $this->db->prepare('INSERT INTO geofence_vehicles (geofence_id, vehicle_id) VALUES (?, ?)');
        foreach (array_unique($vehicleIds) as $vid) {
            $ins->execute([$geofenceId, (int) $vid]);
        }
    }
}
