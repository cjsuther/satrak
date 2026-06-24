<?php

declare(strict_types=1);

namespace Satrak\Domain\Repositories;

use Satrak\Application\Support\Listing;

/**
 * Acceso a la tabla `users` (auth + ABM). Prepared statements en todo acceso.
 */
final class UserRepository extends BaseRepository
{
    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([mb_strtolower(trim($email))]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function touchLastLogin(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function updatePasswordHash(int $id, string $hash): void
    {
        $stmt = $this->db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$hash, $id]);
    }

    /**
     * Busca un usuario por id RESPETANDO el scope de empresa.
     * companyId NULL (super admin global) ⇒ por id; si no, exige misma empresa.
     * Devuelve null si no existe o es de otra empresa ⇒ el controlador hace 404.
     *
     * @return array<string,mixed>|null
     */
    public function findScoped(int $id, ?int $companyId): ?array
    {
        if ($companyId === null) {
            return $this->findById($id);
        }
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = ? AND company_id = ? LIMIT 1');
        $stmt->execute([$id, $companyId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO users (company_id, driver_id, name, email, password_hash, role, status)
             VALUES (:company_id, :driver_id, :name, :email, :password_hash, :role, :status)'
        );
        $stmt->execute([
            ':company_id'    => $data['company_id'],
            ':driver_id'     => $data['driver_id'] ?? null,
            ':name'          => $data['name'],
            ':email'         => mb_strtolower(trim($data['email'])),
            ':password_hash' => $data['password_hash'],
            ':role'          => $data['role'],
            ':status'        => $data['status'] ?? 'active',
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Actualiza datos básicos (no la contraseña; eso va aparte).
     *
     * @param array<string,mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE users SET name=:name, email=:email, role=:role, status=:status, driver_id=:driver_id WHERE id=:id'
        );
        $stmt->execute([
            ':name'      => $data['name'],
            ':email'     => mb_strtolower(trim($data['email'])),
            ':role'      => $data['role'],
            ':status'    => $data['status'],
            ':driver_id' => $data['driver_id'] ?? null,
            ':id'        => $id,
        ]);
    }

    /** ¿El email ya existe (opcionalmente excluyendo un id)? */
    public function emailTaken(string $email, ?int $exceptId = null): bool
    {
        $stmt = $this->db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([mb_strtolower(trim($email))]);
        $row = $stmt->fetch();

        return $row !== false && (int) $row['id'] !== (int) $exceptId;
    }

    /**
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int,sort:string,dir:string}
     */
    public function listPaginated(?int $companyId, Listing $listing): array
    {
        $where = [];
        $bind = [];
        if ($companyId !== null) {
            $where[] = 'company_id = :cid';
            $bind[':cid'] = $companyId;
        }

        return $this->paginate(
            'users',
            ['id', 'company_id', 'name', 'email', 'role', 'status', 'last_login_at'],
            $where,
            $bind,
            ['name', 'email'],
            ['name' => 'name', 'email' => 'email', 'role' => 'role', 'status' => 'status', 'id' => 'id'],
            $listing,
            'name'
        );
    }
}
