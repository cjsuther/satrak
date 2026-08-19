<?php

declare(strict_types=1);

namespace Satrak\Application\Support;

/**
 * Módulos contratados por la empresa en contexto (`companies.modules`).
 *
 * El RBAC define qué puede hacer un ROL; esto define qué contrató la EMPRESA.
 * Son cosas distintas: un `company_admin` siempre tiene `people.manage` por su
 * rol, pero si su empresa sólo contrató flota, ese permiso no aplica.
 *
 * Se llena una vez por request en {@see \Satrak\Application\Middleware\TenantMiddleware}
 * y lo consultan `RbacMiddleware` (para cortar con 403) y el `can()` de Twig
 * (para ocultar el menú).
 *
 * Sin empresa en contexto (vista global del super admin) no hay gating: de eso
 * se encarga `RequireCompanyContextMiddleware`.
 */
final class Entitlements
{
    /** Permiso => módulo que lo habilita. Lo no listado no depende de contratación. */
    private const REQUIRED_MODULE = [
        Perm::FLEET_MANAGE       => 'fleet',
        Perm::ASSIGNMENTS_MANAGE => 'fleet',
        Perm::PEOPLE_MANAGE      => 'people',
        Perm::PEOPLE_MONITOR     => 'people',
        Perm::MISSIONS_MANAGE    => 'people',
    ];

    /** @var string[]|null NULL = sin empresa en contexto */
    private ?array $modules = null;

    /**
     * @param string|string[]|null $modules valor de `companies.modules`
     *                                      ("fleet,people") o ya como array
     */
    public function set(string|array|null $modules): void
    {
        if ($modules === null) {
            $this->modules = null;

            return;
        }
        if (is_string($modules)) {
            $modules = $modules === '' ? [] : explode(',', $modules);
        }
        $this->modules = array_values(array_filter(array_map('trim', $modules)));
    }

    /** @return string[] */
    public function modules(): array
    {
        return $this->modules ?? [];
    }

    public function has(string $module): bool
    {
        return $this->modules === null || in_array($module, $this->modules, true);
    }

    /** ¿La empresa en contexto habilita este permiso? */
    public function allows(string $permission): bool
    {
        $module = self::REQUIRED_MODULE[$permission] ?? null;

        return $module === null || $this->has($module);
    }
}
