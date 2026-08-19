<?php

declare(strict_types=1);

namespace Satrak\Domain\Repositories;

use Satrak\Application\Support\Listing;

/**
 * Acceso a la tabla `people` — el maestro de personas.
 *
 * Una persona puede tener, opcionalmente, un perfil de conducción (`drivers`,
 * vía `drivers.person_id`) y un usuario de portal (`users.person_id`). Los datos
 * personales viven acá; `drivers` conserva sólo lo propio de conducir.
 *
 * `password_hash` es la credencial de la **app móvil** (no da acceso al panel).
 */
final class PersonRepository extends BaseRepository
{
    /** Sin scope de empresa: sólo para el procesador, que ya trae el id resuelto. */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM people WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findScoped(int $id, int $companyId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM people WHERE id = ? AND company_id = ? LIMIT 1');
        $stmt->execute([$id, $companyId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Persona por DNI dentro de la empresa. Es la clave de login de la app móvil
     * (empresa + DNI + contraseña).
     *
     * @return array<string,mixed>|null
     */
    public function findByDni(int $companyId, string $dni): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM people WHERE company_id = ? AND dni = ? LIMIT 1');
        $stmt->execute([$companyId, trim($dni)]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** ¿El DNI ya está usado por otra persona de la empresa? */
    public function dniTaken(string $dni, int $companyId, ?int $exceptId = null): bool
    {
        $stmt = $this->db->prepare('SELECT id FROM people WHERE company_id = ? AND dni = ? LIMIT 1');
        $stmt->execute([$companyId, trim($dni)]);
        $row = $stmt->fetch();

        return $row !== false && (int) $row['id'] !== (int) $exceptId;
    }

    /** @param array<string,mixed> $data */
    public function create(int $companyId, array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO people (company_id, first_name, last_name, dni, phone, email, job_title,
                                 consent_at, consent_note, status)
             VALUES (:company_id, :first_name, :last_name, :dni, :phone, :email, :job_title,
                     :consent_at, :consent_note, :status)'
        );
        $stmt->execute([
            ':company_id'   => $companyId,
            ':first_name'   => $data['first_name'],
            ':last_name'    => $data['last_name'],
            ':dni'          => $data['dni'] ?: null,
            ':phone'        => $data['phone'] ?: null,
            ':email'        => $data['email'] ?: null,
            ':job_title'    => $data['job_title'] ?: null,
            ':consent_at'   => $data['consent_at'] ?: null,
            ':consent_note' => $data['consent_note'] ?: null,
            ':status'       => $data['status'] ?? 'active',
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE people SET first_name=:first_name, last_name=:last_name, dni=:dni, phone=:phone,
                    email=:email, job_title=:job_title, consent_at=:consent_at,
                    consent_note=:consent_note, status=:status
             WHERE id=:id'
        );
        $stmt->execute([
            ':first_name'   => $data['first_name'],
            ':last_name'    => $data['last_name'],
            ':dni'          => $data['dni'] ?: null,
            ':phone'        => $data['phone'] ?: null,
            ':email'        => $data['email'] ?: null,
            ':job_title'    => $data['job_title'] ?: null,
            ':consent_at'   => $data['consent_at'] ?: null,
            ':consent_note' => $data['consent_note'] ?: null,
            ':status'       => $data['status'],
            ':id'           => $id,
        ]);
    }

    /** Setea (o limpia, con NULL) la contraseña de la app móvil. */
    public function setAppPassword(int $id, ?string $hash): void
    {
        $stmt = $this->db->prepare(
            'UPDATE people SET password_hash = ?, password_set_at = ? WHERE id = ?'
        );
        $stmt->execute([$hash, $hash !== null ? date('Y-m-d H:i:s') : null, $id]);
    }

    /** Actualiza sólo los datos de contacto (portal de la persona). */
    public function updateContact(int $id, int $companyId, ?string $phone, ?string $email): void
    {
        $stmt = $this->db->prepare('UPDATE people SET phone = ?, email = ? WHERE id = ? AND company_id = ?');
        $stmt->execute([$phone ?: null, $email ?: null, $id, $companyId]);
    }

    /** Personas activas de la empresa (para selects). @return array<int,array<string,mixed>> */
    public function activeForCompany(int $companyId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, first_name, last_name, dni FROM people
             WHERE company_id = ? AND status = 'active' ORDER BY last_name, first_name"
        );
        $stmt->execute([$companyId]);

        return $stmt->fetchAll();
    }

    public function countForCompany(int $companyId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM people WHERE company_id = ?');
        $stmt->execute([$companyId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Listado con el perfil de conductor y el usuario de portal ya resueltos, para
     * no hacer N+1 en la vista.
     *
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int,sort:string,dir:string}
     */
    public function listPaginated(int $companyId, Listing $listing): array
    {
        $page = $this->paginate(
            'people',
            ['id', 'first_name', 'last_name', 'dni', 'phone', 'job_title', 'status',
             'password_hash IS NOT NULL AS has_app_password', 'consent_at'],
            ['company_id = :cid'],
            [':cid' => $companyId],
            ['first_name', 'last_name', 'dni', 'job_title'],
            ['name' => 'last_name', 'dni' => 'dni', 'status' => 'status', 'id' => 'id'],
            $listing,
            'name'
        );

        if ($page['rows'] === []) {
            return $page;
        }

        $ids = array_map(static fn ($r) => (int) $r['id'], $page['rows']);
        $in = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $this->db->prepare(
            "SELECT person_id, id AS driver_id, pin FROM drivers WHERE person_id IN ({$in})"
        );
        $stmt->execute($ids);
        $drivers = [];
        foreach ($stmt->fetchAll() as $r) {
            $drivers[(int) $r['person_id']] = $r;
        }

        foreach ($page['rows'] as &$row) {
            $d = $drivers[(int) $row['id']] ?? null;
            $row['driver_id'] = $d !== null ? (int) $d['driver_id'] : null;
            $row['driver_pin'] = $d['pin'] ?? null;
        }

        return $page;
    }
}
