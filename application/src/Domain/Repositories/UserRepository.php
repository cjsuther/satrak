<?php

declare(strict_types=1);

namespace Satrak\Domain\Repositories;

use PDO;

/**
 * Acceso a la tabla `users`. Prepared statements en todo acceso.
 */
final class UserRepository
{
    public function __construct(private PDO $db)
    {
    }

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
     *
     * Si $companyId es NULL (super admin en vista global), busca por id global;
     * si no, exige que el usuario pertenezca a esa empresa. Devuelve null cuando
     * no existe o pertenece a otra empresa → el controlador responde 404 (no 403,
     * para no filtrar existencia).
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

    /**
     * Lista usuarios scopeados a una empresa (o todos si NULL = super admin global).
     *
     * @return array<int,array<string,mixed>>
     */
    public function listByCompany(?int $companyId): array
    {
        if ($companyId === null) {
            return $this->db->query(
                'SELECT id, company_id, name, email, role, status, last_login_at
                 FROM users ORDER BY role, name'
            )->fetchAll();
        }
        $stmt = $this->db->prepare(
            'SELECT id, company_id, name, email, role, status, last_login_at
             FROM users WHERE company_id = ? ORDER BY role, name'
        );
        $stmt->execute([$companyId]);

        return $stmt->fetchAll();
    }
}
