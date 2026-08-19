<?php

declare(strict_types=1);

namespace Satrak\Domain\Repositories;

use PDO;

/**
 * Geocercas (`geofences`) y su alcance (`geofence_targets`).
 *
 * Una geocerca puede apuntar a vehículos y/o a personas. La convención de
 * alcance es **por tipo**: si no hay ningún target de un tipo, la geocerca
 * aplica a TODOS los de ese tipo. Esto evita el efecto no deseado de que una
 * geocerca pensada para la flota alcance también a todo el personal.
 */
final class GeofenceRepository
{
    public const TARGET_VEHICLE = 'vehicle';
    public const TARGET_PERSON = 'person';

    public function __construct(private PDO $db)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function forCompany(int $companyId): array
    {
        $stmt = $this->db->prepare(
            "SELECT g.*,
                    (SELECT COUNT(*) FROM geofence_targets t
                      WHERE t.geofence_id = g.id AND t.target_type = 'vehicle') AS vehicle_count,
                    (SELECT COUNT(*) FROM geofence_targets t
                      WHERE t.geofence_id = g.id AND t.target_type = 'person') AS person_count
             FROM geofences g WHERE g.company_id = ? ORDER BY g.name"
        );
        $stmt->execute([$companyId]);

        return $stmt->fetchAll();
    }

    /**
     * Geocercas activas de la empresa, para selects (puesto de una persona,
     * destino de una misión).
     *
     * @return array<int,array<string,mixed>>
     */
    public function activeForCompany(int $companyId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, name, shape FROM geofences
             WHERE company_id = ? AND active = 1 ORDER BY name'
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

    /** Ids alcanzados de un tipo (lista explícita; vacía = todos los de ese tipo). @return int[] */
    public function targetIds(int $geofenceId, string $type): array
    {
        $stmt = $this->db->prepare(
            'SELECT target_id FROM geofence_targets WHERE geofence_id = ? AND target_type = ?'
        );
        $stmt->execute([$geofenceId, $type]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return int[] */
    public function vehicleIds(int $geofenceId): array
    {
        return $this->targetIds($geofenceId, self::TARGET_VEHICLE);
    }

    /** @return int[] */
    public function personIds(int $geofenceId): array
    {
        return $this->targetIds($geofenceId, self::TARGET_PERSON);
    }

    /**
     * Geocercas activas con sus targets resueltos, para el motor de alertas.
     *
     * @return array<int,array<string,mixed>> cada una con 'vehicle_ids' y 'person_ids' (int[])
     */
    public function activeWithTargets(int $companyId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM geofences WHERE company_id = ? AND active = 1');
        $stmt->execute([$companyId]);
        $rows = $stmt->fetchAll();
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn ($g) => (int) $g['id'], $rows);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $targets = $this->db->prepare(
            "SELECT geofence_id, target_type, target_id FROM geofence_targets WHERE geofence_id IN ({$in})"
        );
        $targets->execute($ids);

        $byGeofence = [];
        foreach ($targets->fetchAll() as $t) {
            $byGeofence[(int) $t['geofence_id']][(string) $t['target_type']][] = (int) $t['target_id'];
        }

        foreach ($rows as &$g) {
            $gid = (int) $g['id'];
            $g['vehicle_ids'] = $byGeofence[$gid][self::TARGET_VEHICLE] ?? [];
            $g['person_ids'] = $byGeofence[$gid][self::TARGET_PERSON] ?? [];
        }

        return $rows;
    }

    /**
     * Crea una geocerca y su alcance. La geometría llega ya validada/serializada.
     *
     * @param int[] $vehicleIds
     * @param int[] $personIds
     */
    public function create(
        int $companyId,
        string $name,
        string $shape,
        string $geometryJson,
        array $vehicleIds,
        array $personIds = []
    ): int {
        $this->db->beginTransaction();
        try {
            $ins = $this->db->prepare(
                'INSERT INTO geofences (company_id, name, shape, geometry, active)
                 VALUES (?, ?, ?, ?, 1)'
            );
            $ins->execute([$companyId, $name, $shape, $geometryJson]);
            $id = (int) $this->db->lastInsertId();
            $this->replaceTargets($id, $vehicleIds, $personIds);
            $this->db->commit();

            return $id;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * @param int[] $vehicleIds
     * @param int[] $personIds
     */
    public function update(
        int $id,
        string $name,
        string $geometryJson,
        array $vehicleIds,
        bool $active,
        array $personIds = []
    ): void {
        $this->db->beginTransaction();
        try {
            $upd = $this->db->prepare('UPDATE geofences SET name = ?, geometry = ?, active = ? WHERE id = ?');
            $upd->execute([$name, $geometryJson, $active ? 1 : 0, $id]);
            $this->replaceTargets($id, $vehicleIds, $personIds);
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
            $this->db->prepare('DELETE FROM geofence_targets WHERE geofence_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM geofences WHERE id = ? AND company_id = ?')->execute([$id, $companyId]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * @param int[] $vehicleIds
     * @param int[] $personIds
     */
    private function replaceTargets(int $geofenceId, array $vehicleIds, array $personIds): void
    {
        $this->db->prepare('DELETE FROM geofence_targets WHERE geofence_id = ?')->execute([$geofenceId]);

        $ins = $this->db->prepare(
            'INSERT INTO geofence_targets (geofence_id, target_type, target_id) VALUES (?, ?, ?)'
        );
        foreach (array_unique($vehicleIds) as $vid) {
            $ins->execute([$geofenceId, self::TARGET_VEHICLE, (int) $vid]);
        }
        foreach (array_unique($personIds) as $pid) {
            $ins->execute([$geofenceId, self::TARGET_PERSON, (int) $pid]);
        }
    }
}
