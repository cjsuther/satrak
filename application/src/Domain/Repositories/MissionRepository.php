<?php

declare(strict_types=1);

namespace Satrak\Domain\Repositories;

use PDO;
use Satrak\Application\Support\Listing;

/**
 * Misiones: traslados autorizados de una persona entre dos lugares, dentro de
 * una ventana horaria. Junto con el puesto, definen dónde puede estar durante
 * la jornada.
 *
 * Las carga el operador; la persona sólo puede iniciar (`start`) y marcar
 * llegada (`arrive`) desde la app.
 */
final class MissionRepository extends BaseRepository
{
    /** @return array<string,mixed>|null */
    public function findScoped(int $id, int $companyId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM person_missions WHERE id = ? AND company_id = ? LIMIT 1');
        $stmt->execute([$id, $companyId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Misión de una persona concreta (scope de la app), con la geocerca de
     * destino resuelta para poder validar la llegada.
     *
     * @return array<string,mixed>|null
     */
    public function findForPerson(int $id, int $personId, int $companyId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT m.*, g.name AS dest_name, g.shape AS dest_shape, g.geometry AS dest_geometry
             FROM person_missions m
             JOIN geofences g ON g.id = m.dest_geofence_id
             WHERE m.id = ? AND m.person_id = ? AND m.company_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $personId, $companyId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Misión vigente de la persona: la que está en curso, o la pendiente cuya
     * ventana ya arrancó. Es lo que autoriza a estar fuera del puesto.
     *
     * @return array<string,mixed>|null
     */
    public function activeForPerson(int $personId, int $companyId, ?string $at = null): ?array
    {
        $at ??= date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            "SELECT m.*, g.name AS dest_name, g.shape AS dest_shape, g.geometry AS dest_geometry
             FROM person_missions m
             JOIN geofences g ON g.id = m.dest_geofence_id
             WHERE m.person_id = :pid AND m.company_id = :cid
               AND (
                    m.status = 'in_progress'
                 OR (m.status = 'pending' AND :at1 BETWEEN m.scheduled_start AND m.scheduled_end)
               )
             ORDER BY FIELD(m.status, 'in_progress', 'pending'), m.scheduled_start
             LIMIT 1"
        );
        $stmt->execute([':pid' => $personId, ':cid' => $companyId, ':at1' => $at]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Misiones del día de la persona (las que sincroniza la app).
     *
     * @return array<int,array<string,mixed>>
     */
    public function forPersonBetween(int $personId, int $companyId, string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            "SELECT m.id, m.status, m.scheduled_start, m.scheduled_end, m.started_at, m.arrived_at,
                    m.notes, m.dest_geofence_id, g.name AS dest_name, g.shape AS dest_shape,
                    g.geometry AS dest_geometry,
                    m.origin_geofence_id, og.name AS origin_name, og.shape AS origin_shape,
                    og.geometry AS origin_geometry
             FROM person_missions m
             JOIN geofences g ON g.id = m.dest_geofence_id
             LEFT JOIN geofences og ON og.id = m.origin_geofence_id
             WHERE m.person_id = ? AND m.company_id = ?
               AND m.scheduled_start BETWEEN ? AND ?
               AND m.status <> 'cancelled'
             ORDER BY m.scheduled_start"
        );
        $stmt->execute([$personId, $companyId, $from, $to]);

        return $stmt->fetchAll();
    }

    /**
     * Misiones vencidas que hay que marcar (las revisa el procesador):
     * `pending` cuya ventana pasó sin iniciarse, o `in_progress` que no llegó.
     *
     * @return array<int,array<string,mixed>>
     */
    public function overdue(?string $at = null): array
    {
        $at ??= date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            "SELECT id, company_id, person_id, status, scheduled_end
             FROM person_missions
             WHERE status IN ('pending','in_progress') AND scheduled_end < ?"
        );
        $stmt->execute([$at]);

        return $stmt->fetchAll();
    }

    /** @param array<string,mixed> $data */
    public function create(int $companyId, array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO person_missions (company_id, person_id, origin_geofence_id, dest_geofence_id,
                                          scheduled_start, scheduled_end, vehicle_id, notes, created_by)
             VALUES (:company_id, :person_id, :origin, :dest, :start, :end, :vehicle, :notes, :created_by)'
        );
        $stmt->execute([
            ':company_id' => $companyId,
            ':person_id'  => (int) $data['person_id'],
            ':origin'     => $data['origin_geofence_id'] ?: null,
            ':dest'       => (int) $data['dest_geofence_id'],
            ':start'      => $data['scheduled_start'],
            ':end'        => $data['scheduled_end'],
            ':vehicle'    => $data['vehicle_id'] ?: null,
            ':notes'      => $data['notes'] ?: null,
            ':created_by' => $data['created_by'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE person_missions SET person_id=:person_id, origin_geofence_id=:origin,
                    dest_geofence_id=:dest, scheduled_start=:start, scheduled_end=:end,
                    vehicle_id=:vehicle, notes=:notes WHERE id=:id'
        );
        $stmt->execute([
            ':person_id' => (int) $data['person_id'],
            ':origin'    => $data['origin_geofence_id'] ?: null,
            ':dest'      => (int) $data['dest_geofence_id'],
            ':start'     => $data['scheduled_start'],
            ':end'       => $data['scheduled_end'],
            ':vehicle'   => $data['vehicle_id'] ?: null,
            ':notes'     => $data['notes'] ?: null,
            ':id'        => $id,
        ]);
    }

    /**
     * Cambia el estado. `started_at` / `arrived_at` se sellan según la transición.
     */
    public function setStatus(int $id, string $status, ?string $at = null): void
    {
        $at ??= date('Y-m-d H:i:s');
        $sql = 'UPDATE person_missions SET status = :status';
        $bind = [':status' => $status, ':id' => $id];

        if ($status === 'in_progress') {
            $sql .= ', started_at = COALESCE(started_at, :at)';
            $bind[':at'] = $at;
        } elseif ($status === 'completed') {
            $sql .= ', arrived_at = :at';
            $bind[':at'] = $at;
        }
        $sql .= ' WHERE id = :id';

        $this->db->prepare($sql)->execute($bind);
    }

    /**
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int,sort:string,dir:string}
     */
    public function listPaginated(int $companyId, Listing $listing, ?string $status = null): array
    {
        $where = ['m.company_id = :cid'];
        $bind = [':cid' => $companyId];
        if ($status !== null && $status !== '') {
            $where[] = 'm.status = :status';
            $bind[':status'] = $status;
        }

        return $this->paginate(
            'person_missions m
             JOIN people p ON p.id = m.person_id
             JOIN geofences g ON g.id = m.dest_geofence_id',
            [
                'm.id', 'm.status', 'm.scheduled_start', 'm.scheduled_end',
                'm.started_at', 'm.arrived_at', 'm.person_id',
                "TRIM(CONCAT(p.first_name, ' ', p.last_name)) AS person_name",
                'g.name AS dest_name',
            ],
            $where,
            $bind,
            ['p.last_name', 'p.first_name', 'g.name'],
            [
                'start'  => 'm.scheduled_start',
                'person' => 'p.last_name',
                'status' => 'm.status',
                'id'     => 'm.id',
            ],
            $listing,
            'start'
        );
    }
}
