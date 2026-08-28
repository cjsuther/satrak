<?php

declare(strict_types=1);

namespace Satrak\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Satrak\Application\Support\Perm;
use Satrak\Application\Support\Rbac;

/**
 * Rbac decide qué puede hacer cada ROL. Es la primera de las dos barreras de
 * autorización (la otra es Entitlements, que decide qué contrató la empresa).
 *
 * Buena parte de estos tests son negativos a propósito: en control de acceso lo
 * que importa no es que el permitido pase, sino que el no permitido NO pase.
 */
final class RbacTest extends TestCase
{
    private function rbac(): Rbac
    {
        return new Rbac(require dirname(__DIR__, 3) . '/config/permissions.php');
    }

    public function testElSuperAdminTieneComodinYPuedeTodo(): void
    {
        $rbac = $this->rbac();

        foreach ([Perm::COMPANIES_MANAGE, Perm::PEOPLE_MANAGE, Perm::AUDIT_GLOBAL] as $perm) {
            self::assertTrue($rbac->roleCan('super_admin', $perm));
        }
        // Incluso un permiso que todavía no existe: el comodín concede todo.
        self::assertTrue($rbac->roleCan('super_admin', 'un.permiso.futuro'));
    }

    public function testUnRolDesconocidoNoPuedeNada(): void
    {
        $rbac = $this->rbac();

        self::assertFalse($rbac->roleCan('root', Perm::COMPANIES_MANAGE));
        self::assertFalse($rbac->roleCan('administrador', Perm::MONITORING_VIEW));
        self::assertFalse($rbac->roleCan('', Perm::MONITORING_VIEW));
    }

    /** Sin sesión no hay rol; debe negar en vez de romper. */
    public function testRolNuloNoPuedeNada(): void
    {
        self::assertFalse($this->rbac()->roleCan(null, Perm::MONITORING_VIEW));
        self::assertSame([], $this->rbac()->permissionsFor(null));
    }

    /**
     * El límite que importa del multi-tenant: administrar EMPRESAS es
     * exclusivo del super admin. Si un company_admin pudiera, vería y editaría
     * la configuración de otras empresas.
     */
    public function testSoloElSuperAdminAdministraEmpresas(): void
    {
        $rbac = $this->rbac();

        self::assertTrue($rbac->roleCan('super_admin', Perm::COMPANIES_MANAGE));
        foreach (['company_admin', 'operator', 'driver', 'person'] as $rol) {
            self::assertFalse(
                $rbac->roleCan($rol, Perm::COMPANIES_MANAGE),
                "{$rol} no debe poder administrar empresas",
            );
        }
    }

    public function testElOperadorNoAdministraUsuarios(): void
    {
        // Si pudiera, se crearía un company_admin y escalaría privilegios.
        self::assertFalse($this->rbac()->roleCan('operator', Perm::USERS_MANAGE));
        self::assertTrue($this->rbac()->roleCan('company_admin', Perm::USERS_MANAGE));
    }

    /**
     * Los portales (conductor / persona) sólo ven lo propio. No deben tener
     * ningún permiso de gestión ni de monitoreo de terceros.
     */
    public function testLosPortalesNoVenDatosDeTerceros(): void
    {
        $rbac = $this->rbac();
        $prohibidos = [
            Perm::MONITORING_VIEW, Perm::PEOPLE_MANAGE, Perm::PEOPLE_MONITOR,
            Perm::FLEET_MANAGE, Perm::USERS_MANAGE, Perm::REPORTS_VIEW,
            Perm::GEOFENCES_MANAGE, Perm::ALERTS_ACK, Perm::MISSIONS_MANAGE,
        ];

        foreach (['driver', 'person'] as $rol) {
            foreach ($prohibidos as $perm) {
                self::assertFalse(
                    $rbac->roleCan($rol, $perm),
                    "el portal '{$rol}' no debe tener '{$perm}'",
                );
            }
        }
    }

    /** Todos los roles humanos pueden gestionar su propia cuenta. */
    public function testTodosLosRolesPuedenEditarSuPerfil(): void
    {
        $rbac = $this->rbac();

        foreach (['super_admin', 'company_admin', 'operator', 'driver', 'person'] as $rol) {
            self::assertTrue($rbac->roleCan($rol, Perm::PROFILE_EDIT), "{$rol} debe poder editar su perfil");
        }
    }

    public function testAuditoriaGlobalEsSoloDelSuperAdmin(): void
    {
        $rbac = $this->rbac();

        self::assertTrue($rbac->roleCan('super_admin', Perm::AUDIT_GLOBAL));
        self::assertFalse($rbac->roleCan('company_admin', Perm::AUDIT_GLOBAL));
        // El company_admin sí ve la auditoría de SU empresa.
        self::assertTrue($rbac->roleCan('company_admin', Perm::AUDIT_COMPANY));
        self::assertFalse($rbac->roleCan('operator', Perm::AUDIT_COMPANY));
    }

    public function testPermissionsForDevuelveLaListaDelRol(): void
    {
        $perms = $this->rbac()->permissionsFor('operator');

        self::assertContains(Perm::MONITORING_VIEW, $perms);
        self::assertNotContains(Perm::USERS_MANAGE, $perms);
        self::assertSame([], $this->rbac()->permissionsFor('inexistente'));
    }

    /**
     * Un permiso vacío no debe colarse por un `in_array` laxo. Con comparación
     * estricta '' nunca coincide, salvo para el comodín del super admin.
     */
    public function testUnPermisoVacioNoSeConcede(): void
    {
        self::assertFalse($this->rbac()->roleCan('operator', ''));
    }
}
