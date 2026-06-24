<?php

declare(strict_types=1);

namespace Satrak\Domain\Repositories;

use Satrak\Application\Support\Listing;

/**
 * Acceso a la tabla `companies` (tenants). ABM exclusivo del super admin.
 */
final class CompanyRepository extends BaseRepository
{
    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM companies WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM companies WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return $this->db->query('SELECT * FROM companies ORDER BY name')->fetchAll();
    }

    public function count(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM companies')->fetchColumn();
    }

    /**
     * Genera un slug único a partir del nombre.
     */
    public function uniqueSlug(string $name, ?int $exceptId = null): string
    {
        $base = slugify($name);
        $slug = $base;
        $n = 2;
        while (true) {
            $existing = $this->findBySlug($slug);
            if ($existing === null || (int) $existing['id'] === $exceptId) {
                return $slug;
            }
            $slug = $base . '-' . $n++;
        }
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO companies (name, slug, status, device_quota, timezone)
             VALUES (:name, :slug, :status, :device_quota, :timezone)'
        );
        $stmt->execute([
            ':name'         => $data['name'],
            ':slug'         => $data['slug'],
            ':status'       => $data['status'] ?? 'active',
            ':device_quota' => (int) ($data['device_quota'] ?? 0),
            ':timezone'     => $data['timezone'] ?? 'America/Argentina/Buenos_Aires',
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE companies SET name=:name, slug=:slug, status=:status,
             device_quota=:device_quota, timezone=:timezone WHERE id=:id'
        );
        $stmt->execute([
            ':name'         => $data['name'],
            ':slug'         => $data['slug'],
            ':status'       => $data['status'],
            ':device_quota' => (int) $data['device_quota'],
            ':timezone'     => $data['timezone'],
            ':id'           => $id,
        ]);
    }

    /**
     * Listado paginado con conteos de dispositivos/usuarios.
     *
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int,sort:string,dir:string}
     */
    public function listPaginated(Listing $listing): array
    {
        return $this->paginate(
            'companies',
            [
                'id', 'name', 'slug', 'status', 'device_quota', 'created_at',
                '(SELECT COUNT(*) FROM devices d WHERE d.company_id = companies.id AND d.status = \'active\') AS devices_active',
                '(SELECT COUNT(*) FROM users u WHERE u.company_id = companies.id) AS users_count',
            ],
            [],
            [],
            ['name', 'slug'],
            ['name' => 'name', 'slug' => 'slug', 'status' => 'status', 'created_at' => 'created_at', 'id' => 'id'],
            $listing,
            'name'
        );
    }
}
