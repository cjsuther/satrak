<?php

declare(strict_types=1);

namespace Satrak\Application\Support;

/**
 * Resuelve permisos de un rol contra la matriz de config/permissions.php.
 */
final class Rbac
{
    /** @var array<string,string[]> rol => permisos */
    private array $map;

    /** @param array<string,string[]> $map */
    public function __construct(array $map)
    {
        $this->map = $map;
    }

    /**
     * ¿El rol tiene el permiso? '*' en el mapa del rol concede todo.
     */
    public function roleCan(?string $role, string $permission): bool
    {
        if ($role === null || !isset($this->map[$role])) {
            return false;
        }
        $perms = $this->map[$role];

        return in_array('*', $perms, true) || in_array($permission, $perms, true);
    }

    /** @return string[] */
    public function permissionsFor(?string $role): array
    {
        return $role !== null ? ($this->map[$role] ?? []) : [];
    }
}
