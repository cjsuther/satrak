<?php

declare(strict_types=1);

namespace Satrak\Domain\Repositories;

use Satrak\Application\Support\Listing;

/**
 * Acceso a la tabla `drivers`. PIN único por empresa (validado en app, 4–10).
 */
final class DriverRepository extends BaseRepository
{
    /** @return array<string,mixed>|null */
    public function findScoped(int $id, int $companyId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM drivers WHERE id = ? AND company_id = ? LIMIT 1');
        $stmt->execute([$id, $companyId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** ¿El PIN ya está usado por otro conductor de la empresa? */
    public function pinTaken(string $pin, int $companyId, ?int $exceptId = null): bool
    {
        $stmt = $this->db->prepare('SELECT id FROM drivers WHERE company_id = ? AND pin = ? LIMIT 1');
        $stmt->execute([$companyId, $pin]);
        $row = $stmt->fetch();

        return $row !== false && (int) $row['id'] !== (int) $exceptId;
    }

    /** @param array<string,mixed> $data */
    public function create(int $companyId, array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO drivers (company_id, first_name, last_name, dni, license_number, phone, email, pin, status)
             VALUES (:company_id, :first_name, :last_name, :dni, :license_number, :phone, :email, :pin, :status)'
        );
        $stmt->execute([
            ':company_id'     => $companyId,
            ':first_name'     => $data['first_name'],
            ':last_name'      => $data['last_name'],
            ':dni'            => $data['dni'] ?: null,
            ':license_number' => $data['license_number'] ?: null,
            ':phone'          => $data['phone'] ?: null,
            ':email'          => $data['email'] ?: null,
            ':pin'            => $data['pin'] ?: null,
            ':status'         => $data['status'] ?? 'active',
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE drivers SET first_name=:first_name, last_name=:last_name, dni=:dni,
             license_number=:license_number, phone=:phone, email=:email, pin=:pin, status=:status WHERE id=:id'
        );
        $stmt->execute([
            ':first_name'     => $data['first_name'],
            ':last_name'      => $data['last_name'],
            ':dni'            => $data['dni'] ?: null,
            ':license_number' => $data['license_number'] ?: null,
            ':phone'          => $data['phone'] ?: null,
            ':email'          => $data['email'] ?: null,
            ':pin'            => $data['pin'] ?: null,
            ':status'         => $data['status'],
            ':id'             => $id,
        ]);
    }

    /**
     * Busca un conductor por PIN dentro de la empresa (atribución §8).
     *
     * @return array<string,mixed>|null
     */
    public function findByPin(int $companyId, string $pin): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM drivers WHERE company_id = ? AND pin = ? LIMIT 1');
        $stmt->execute([$companyId, $pin]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Conductores activos de la empresa (para selects). @return array<int,array<string,mixed>> */
    public function activeForCompany(int $companyId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, first_name, last_name, pin FROM drivers
             WHERE company_id = ? AND status = 'active' ORDER BY last_name, first_name"
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
            'drivers',
            ['id', 'first_name', 'last_name', 'dni', 'phone', 'pin', 'status'],
            ['company_id = :cid'],
            [':cid' => $companyId],
            ['first_name', 'last_name', 'dni', 'pin'],
            ['name' => 'last_name', 'dni' => 'dni', 'status' => 'status', 'id' => 'id'],
            $listing,
            'name'
        );
    }
}
