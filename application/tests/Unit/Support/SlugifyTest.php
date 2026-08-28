<?php

declare(strict_types=1);

namespace Satrak\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * `slugify()` genera el slug de empresa, que NO es cosmético: es la credencial
 * con la que las personas entran a la app móvil (`company_slug` en
 * /api/app/login).
 *
 * Estos tests existen por un incidente real (2026-08-28): `CompanyController`
 * regeneraba el slug desde el nombre en CADA guardado, así que tocar un campo
 * cualquiera de la empresa —el toggle de pánico, en ese caso— lo cambiaba y
 * dejaba a toda la empresa sin poder loguearse en la app. La corrección fue
 * hacer el slug estable al editar; estos casos fijan que la función en sí sea
 * determinística y segura para usar en una URL y en un login.
 */
final class SlugifyTest extends TestCase
{
    public function testEsDeterministica(): void
    {
        self::assertSame(slugify('Transportes del Comahue'), slugify('Transportes del Comahue'));
    }

    /**
     * El caso exacto del incidente: el slug que generaba la función NO era el
     * que la empresa tenía guardado. Por eso el slug no puede recalcularse al
     * editar aunque el nombre no haya cambiado.
     */
    public function testElNombreDeLaDemoNoProduceSuSlugHistorico(): void
    {
        self::assertSame('transportes-del-comahue', slugify('Transportes del Comahue'));
        self::assertNotSame(
            'transportes-comahue',
            slugify('Transportes del Comahue'),
            'si esto llegara a coincidir, el test de arriba dejaría de proteger nada',
        );
    }

    public function testPasaAMinusculasYUneConGuiones(): void
    {
        self::assertSame('mi-empresa-sa', slugify('Mi Empresa SA'));
        self::assertSame('dos-espacios', slugify('Dos   espacios'));
    }

    public function testTransliteraAcentosYEnies(): void
    {
        self::assertSame('logistica-nunez', slugify('Logística Núñez'));
        self::assertSame('transporte-jose-marmol', slugify('Transporte José Mármol'));
    }

    /**
     * Nada fuera de [a-z0-9-]: el slug va en la URL y en el cuerpo del login.
     * Si dejara pasar barras, puntos o espacios, se podrían construir rutas o
     * comparaciones inesperadas.
     */
    public function testSoloDejaCaracteresSegurosParaUrlYLogin(): void
    {
        foreach ([
            'Empresa / Sucursal',
            'Empresa "Comillas"',
            "Empresa 'S.A.'",
            'Empresa <script>',
            'Empresa?query=1&x=2',
            'Empresa..\\..\\etc',
            'Empresa%20encoded',
        ] as $nombre) {
            $slug = slugify($nombre);
            self::assertMatchesRegularExpression(
                '/^[a-z0-9-]+$/',
                $slug,
                "'{$nombre}' produjo un slug inseguro: '{$slug}'",
            );
        }
    }

    public function testNoEmpiezaNiTerminaConGuion(): void
    {
        foreach (['  Empresa  ', '---Empresa---', '¡Empresa!', '...Empresa...'] as $nombre) {
            $slug = slugify($nombre);
            self::assertStringStartsNotWith('-', $slug);
            self::assertStringEndsNotWith('-', $slug);
        }
    }

    public function testNoGeneraGuionesRepetidos(): void
    {
        self::assertStringNotContainsString('--', slugify('Empresa -- Sucursal // Norte'));
    }

    /**
     * Un nombre sin caracteres transliterables no puede dar slug vacío: un slug
     * vacío rompería el login y podría colisionar con otra empresa igual de
     * vacía.
     */
    public function testNuncaDevuelveVacio(): void
    {
        foreach (['', '   ', '!!!', '---', '日本語'] as $nombre) {
            self::assertNotSame('', slugify($nombre), "'{$nombre}' dio slug vacío");
        }
    }

    public function testConservaLosNumeros(): void
    {
        self::assertSame('transportes-24-7', slugify('Transportes 24/7'));
    }
}
