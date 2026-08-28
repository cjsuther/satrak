<?php

declare(strict_types=1);

namespace Satrak\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Satrak\Application\Support\Entitlements;
use Satrak\Application\Support\Perm;

/**
 * Entitlements es la segunda barrera de autorización: el RBAC dice qué puede
 * el ROL, esto dice qué contrató la EMPRESA. Un company_admin siempre tiene
 * `people.manage` por su rol, pero si su empresa sólo contrató flota, no aplica.
 *
 * Tiene un caso límite peligroso: SIN empresa en contexto (vista global del
 * super admin) no hay gating y `has()` devuelve true para todo. Está bien —de
 * cortar se encarga RequireCompanyContextMiddleware— pero conviene fijarlo con
 * un test para que nadie lo "arregle" sin entenderlo.
 */
final class EntitlementsTest extends TestCase
{
    public function testSinEmpresaEnContextoNoHayGating(): void
    {
        $e = new Entitlements();
        $e->set(null);

        self::assertTrue($e->has('fleet'));
        self::assertTrue($e->has('people'));
        self::assertTrue($e->has('un-modulo-inexistente'));
        self::assertTrue($e->allows(Perm::PEOPLE_MANAGE));
        self::assertSame([], $e->modules(), 'modules() devuelve lista vacía aunque has() sea permisivo');
    }

    public function testAceptaElStringDeLaColumnaModules(): void
    {
        $e = new Entitlements();
        $e->set('fleet,people');

        self::assertSame(['fleet', 'people'], $e->modules());
        self::assertTrue($e->has('fleet'));
        self::assertTrue($e->has('people'));
    }

    public function testAceptaUnArray(): void
    {
        $e = new Entitlements();
        $e->set(['fleet']);

        self::assertSame(['fleet'], $e->modules());
        self::assertFalse($e->has('people'));
    }

    public function testStringVacioEsEmpresaSinModulos(): void
    {
        $e = new Entitlements();
        $e->set('');

        self::assertSame([], $e->modules());
        self::assertFalse($e->has('fleet'), 'sin módulos no habilita nada');
        self::assertFalse($e->allows(Perm::FLEET_MANAGE));
    }

    /** La columna es un SET de MySQL; puede llegar con espacios de sobra. */
    public function testToleraEspaciosYEntradasVacias(): void
    {
        $e = new Entitlements();
        $e->set(' fleet , , people ');

        self::assertSame(['fleet', 'people'], $e->modules());
    }

    /**
     * El caso de negocio que motivó la clase: vender flota y personal por
     * separado. Una empresa con sólo `fleet` no debe entrar al módulo de
     * personas aunque su rol tenga el permiso.
     */
    public function testEmpresaSoloConFlotaNoAccedeAPersonas(): void
    {
        $e = new Entitlements();
        $e->set('fleet');

        self::assertTrue($e->allows(Perm::FLEET_MANAGE));
        self::assertTrue($e->allows(Perm::ASSIGNMENTS_MANAGE));

        self::assertFalse($e->allows(Perm::PEOPLE_MANAGE));
        self::assertFalse($e->allows(Perm::PEOPLE_MONITOR));
        self::assertFalse($e->allows(Perm::MISSIONS_MANAGE));
    }

    public function testEmpresaSoloConPersonalNoAccedeAFlota(): void
    {
        $e = new Entitlements();
        $e->set('people');

        self::assertTrue($e->allows(Perm::PEOPLE_MANAGE));
        self::assertFalse($e->allows(Perm::FLEET_MANAGE));
        self::assertFalse($e->allows(Perm::ASSIGNMENTS_MANAGE));
    }

    /**
     * Un permiso que no está en la tabla REQUIRED_MODULE no depende de
     * contratación: si no, contratar «fleet» dejaría a la empresa sin poder
     * cambiar su propia contraseña.
     */
    public function testUnPermisoNoLigadoAModuloSiempreSePermite(): void
    {
        $e = new Entitlements();
        $e->set('fleet');

        self::assertTrue($e->allows(Perm::PROFILE_EDIT));
        self::assertTrue($e->allows(Perm::MONITORING_VIEW));
        self::assertTrue($e->allows(Perm::ALERTS_ACK));
        self::assertTrue($e->allows('permiso.inventado'));
    }

    /** `set()` se llama una vez por request; volver a llamarlo debe reemplazar. */
    public function testSetReemplazaElEstadoAnterior(): void
    {
        $e = new Entitlements();
        $e->set('fleet,people');
        $e->set('fleet');

        self::assertSame(['fleet'], $e->modules());
        self::assertFalse($e->has('people'));

        // Y volver a null restaura el modo sin contexto.
        $e->set(null);
        self::assertTrue($e->has('people'));
    }
}
