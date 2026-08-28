<?php

declare(strict_types=1);

namespace Satrak\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Satrak\Application\Support\Validator;

/**
 * El Validator es la validación del lado del servidor de todos los formularios.
 * Sus casos límite —el 0 como valor, el array donde se esperaba un string, el
 * campo ausente— son justamente por donde entra la basura.
 */
final class ValidatorTest extends TestCase
{
    public function testUnValidadorSinReglasNoFalla(): void
    {
        $v = new Validator([]);

        self::assertFalse($v->fails());
        self::assertSame([], $v->errors());
    }

    public function testRequiredFallaConAusenteVacioYSoloEspacios(): void
    {
        foreach ([[], ['nombre' => ''], ['nombre' => '   '], ['nombre' => null]] as $data) {
            $v = (new Validator($data))->required('nombre');
            self::assertTrue($v->fails(), 'debería fallar con ' . json_encode($data));
        }
    }

    public function testRequiredPasaConTextoReal(): void
    {
        self::assertFalse((new Validator(['nombre' => 'Ana']))->required('nombre')->fails());
    }

    /**
     * `'0'` es un valor legítimo (un cupo, un PIN) y no debe tratarse como
     * vacío. Es el clásico agujero de usar `empty()` en vez de comparar.
     */
    public function testElCeroEsUnValorValido(): void
    {
        self::assertFalse((new Validator(['cupo' => '0']))->required('cupo')->fails());
        self::assertFalse((new Validator(['cupo' => 0]))->required('cupo')->fails());
    }

    public function testGuardaSoloElPrimerErrorPorCampo(): void
    {
        $v = (new Validator(['email' => '']))
            ->required('email', 'El email')
            ->minLength('email', 5, 'El email');

        self::assertSame(['email' => 'El email es obligatorio.'], $v->errors());
    }

    public function testUsaLaEtiquetaEnElMensajeCuandoSeDa(): void
    {
        $v = (new Validator([]))->required('emergency_email', 'El email de guardia');

        self::assertSame('El email de guardia es obligatorio.', $v->errors()['emergency_email']);
    }

    public function testSinEtiquetaUsaElNombreDelCampo(): void
    {
        $v = (new Validator([]))->required('slug');

        self::assertSame('slug es obligatorio.', $v->errors()['slug']);
    }

    public function testEmailRechazaFormatosInvalidos(): void
    {
        foreach (['no-es-un-mail', 'a@', '@b.com', 'a b@c.com', 'a@b'] as $malo) {
            self::assertTrue(
                (new Validator(['email' => $malo]))->email('email')->fails(),
                "'{$malo}' no debería pasar como email",
            );
        }
    }

    public function testEmailAceptaFormatosValidos(): void
    {
        foreach (['a@b.com', 'admin@comahue.demo', 'nombre.apellido+etiqueta@sub.dominio.ar'] as $bueno) {
            self::assertFalse(
                (new Validator(['email' => $bueno]))->email('email')->fails(),
                "'{$bueno}' debería pasar como email",
            );
        }
    }

    /**
     * `email()` NO exige presencia: eso es tarea de `required()`. Así un campo
     * de email opcional (como el de guardia) puede quedar vacío.
     */
    public function testEmailVacioNoFallaPorSiSolo(): void
    {
        self::assertFalse((new Validator(['email' => '']))->email('email')->fails());
        self::assertFalse((new Validator([]))->email('email')->fails());
    }

    public function testMinLengthYMaxLengthCuentanCaracteresNoBytes(): void
    {
        // 8 caracteres, pero más de 8 bytes en UTF-8. Si contara bytes, pasaría
        // un mínimo que no cumple y rechazaría un máximo que sí.
        $clave = 'ñandúes1';
        self::assertSame(8, mb_strlen($clave));
        self::assertGreaterThan(8, strlen($clave));

        self::assertFalse((new Validator(['p' => $clave]))->minLength('p', 8)->fails());
        self::assertTrue((new Validator(['p' => $clave]))->minLength('p', 9)->fails());
        self::assertFalse((new Validator(['p' => $clave]))->maxLength('p', 8)->fails());
        self::assertTrue((new Validator(['p' => $clave]))->maxLength('p', 7)->fails());
    }

    public function testMatchesComparaDosCampos(): void
    {
        $iguales = (new Validator(['a' => 'x', 'b' => 'x']))->matches('a', 'b', 'No coinciden.');
        self::assertFalse($iguales->fails());

        $distintos = (new Validator(['a' => 'x', 'b' => 'y']))->matches('a', 'b', 'No coinciden.');
        self::assertTrue($distintos->fails());
        self::assertSame('No coinciden.', $distintos->errors()['a']);
    }

    /**
     * `matches` usa comparación estricta, así que '1' y 1 NO son iguales. Es lo
     * correcto para confirmar contraseñas, donde ambos vienen como string.
     */
    public function testMatchesEsEstricto(): void
    {
        self::assertTrue((new Validator(['a' => '1', 'b' => 1]))->matches('a', 'b', 'x')->fails());
    }

    /**
     * Dos campos ausentes "coinciden" (null === null). Es un borde real: la
     * confirmación de contraseña necesita un `required` propio, no alcanza con
     * `matches`. Se fija acá para que quede documentado.
     */
    public function testDosCamposAusentesCoinciden(): void
    {
        self::assertFalse((new Validator([]))->matches('a', 'b', 'x')->fails());
    }

    /** Un array donde se esperaba texto no debe romper las reglas de longitud. */
    public function testUnArrayNoRompeLasReglas(): void
    {
        $v = (new Validator(['nombre' => ['inyección']]))
            ->required('nombre')
            ->minLength('nombre', 3)
            ->maxLength('nombre', 5)
            ->email('nombre');

        // No falla porque las reglas de string se saltean lo que no es string,
        // pero tampoco explota: el tipado lo corta el controlador con casts.
        self::assertFalse($v->fails());
    }

    public function testLasReglasSeEncadenanYAcumulanPorCampo(): void
    {
        $v = (new Validator(['nombre' => '', 'email' => 'malo']))
            ->required('nombre', 'El nombre')
            ->required('email', 'El email')
            ->email('email');

        self::assertTrue($v->fails());
        self::assertCount(2, $v->errors());
        self::assertArrayHasKey('nombre', $v->errors());
        self::assertSame('Email inválido.', $v->errors()['email']);
    }
}
