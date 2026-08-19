<?php

declare(strict_types=1);

namespace Satrak\Domain\Repositories;

use PDO;

/**
 * Puesto de una persona: la geocerca donde debe estar durante su jornada.
 *
 * Se guarda con vigencia (`valid_from` / `valid_to`) para conservar el historial
 * de dónde estaba asignada cada persona: al cambiarle el puesto se cierra el
 * anterior en vez de pisarlo.
 */
final class PersonPostRepository
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * Puesto vigente de la persona (con el nombre y la geometría de la geocerca).
     *
     * @return array<string,mixed>|null
     */
    public function currentForPerson(int $personId, int $companyId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT pp.id, pp.geofence_id, pp.grace_min, pp.valid_from,
                    g.name AS geofence_name, g.shape, g.geometry
             FROM person_posts pp
             JOIN geofences g ON g.id = pp.geofence_id
             WHERE pp.person_id = ? AND pp.company_id = ? AND pp.valid_to IS NULL
             ORDER BY pp.id DESC LIMIT 1'
        );
        $stmt->execute([$personId, $companyId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Puestos vigentes de todas las personas de la empresa, indexados por
     * `person_id`. Lo usa el motor de alertas para no hacer N+1.
     *
     * @return array<int,array<string,mixed>>
     */
    public function currentByCompany(int $companyId): array
    {
        $stmt = $this->db->prepare(
            'SELECT pp.person_id, pp.geofence_id, pp.grace_min, g.name AS geofence_name,
                    g.shape, g.geometry
             FROM person_posts pp
             JOIN geofences g ON g.id = pp.geofence_id
             WHERE pp.company_id = ? AND pp.valid_to IS NULL
             ORDER BY pp.id'
        );
        $stmt->execute([$companyId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['person_id']] = $row;   // el último gana: es el más reciente
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    public function historyForPerson(int $personId, int $companyId): array
    {
        $stmt = $this->db->prepare(
            'SELECT pp.id, pp.grace_min, pp.valid_from, pp.valid_to, g.name AS geofence_name
             FROM person_posts pp
             JOIN geofences g ON g.id = pp.geofence_id
             WHERE pp.person_id = ? AND pp.company_id = ?
             ORDER BY pp.id DESC'
        );
        $stmt->execute([$personId, $companyId]);

        return $stmt->fetchAll();
    }

    /**
     * Asigna un puesto nuevo cerrando el vigente. En transacción para no dejar
     * dos puestos abiertos ni ninguno.
     */
    public function assign(int $companyId, int $personId, int $geofenceId, int $graceMin): int
    {
        $this->db->beginTransaction();
        try {
            $this->closeCurrentStatement()->execute([$personId, $companyId]);

            $ins = $this->db->prepare(
                'INSERT INTO person_posts (company_id, person_id, geofence_id, grace_min, valid_from)
                 VALUES (?, ?, ?, ?, CURDATE())'
            );
            $ins->execute([$companyId, $personId, $geofenceId, $graceMin]);
            $id = (int) $this->db->lastInsertId();

            $this->db->commit();

            return $id;
        } catch (\Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }
    }

    /** Deja a la persona sin puesto asignado. */
    public function clear(int $personId, int $companyId): void
    {
        $this->closeCurrentStatement()->execute([$personId, $companyId]);
    }

    private function closeCurrentStatement(): \PDOStatement
    {
        return $this->db->prepare(
            'UPDATE person_posts SET valid_to = CURDATE()
             WHERE person_id = ? AND company_id = ? AND valid_to IS NULL'
        );
    }
}
