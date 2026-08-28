<?php

declare(strict_types=1);

namespace Satrak\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Satrak\Domain\Services\Geo;

/**
 * De `haversineKm` cuelgan la velocidad de los viajes, el filtro de ruido GPS
 * (`min_step_m`) y la contención en geocercas circulares. Un error de escala
 * acá se propaga a todo el sistema sin dar la cara.
 */
final class GeoTest extends TestCase
{
    public function testDistanciaCeroEntreElMismoPunto(): void
    {
        self::assertSame(0.0, Geo::haversineKm(-34.6037, -58.3816, -34.6037, -58.3816));
    }

    /**
     * Distancia conocida y verificable: Obelisco (CABA) → Catedral de Neuquén,
     * ~987 km EN LÍNEA RECTA (por ruta son ~1.140, que es otra cosa). Fija la
     * ESCALA del resultado (km, no metros ni millas), que es el error más fácil
     * de cometer y el más difícil de notar. Coincide con los 983 km que la app
     * muestra como distancia a Base Neuquén.
     */
    public function testDistanciaConocidaBuenosAiresNeuquen(): void
    {
        $km = Geo::haversineKm(-34.6037, -58.3816, -38.9516, -68.0591);

        self::assertEqualsWithDelta(987, $km, 15, "esperaba ~987 km, dio {$km}");
    }

    /**
     * Un grado de latitud son ~111,2 km en cualquier meridiano. Es la forma más
     * limpia de verificar el radio terrestre que usa la fórmula.
     */
    public function testUnGradoDeLatitudSonUnos111Km(): void
    {
        $km = Geo::haversineKm(0.0, 0.0, 1.0, 0.0);

        self::assertEqualsWithDelta(111.19, $km, 0.1);
    }

    /**
     * Un grado de longitud se acorta con el coseno de la latitud: en el ecuador
     * mide lo mismo que uno de latitud, y a 60° la mitad. Si la fórmula ignorara
     * el coseno, este test lo detecta.
     */
    public function testUnGradoDeLongitudSeAcortaConLaLatitud(): void
    {
        $enElEcuador = Geo::haversineKm(0.0, 0.0, 0.0, 1.0);
        $a60Grados   = Geo::haversineKm(60.0, 0.0, 60.0, 1.0);

        self::assertEqualsWithDelta(111.19, $enElEcuador, 0.1);
        self::assertEqualsWithDelta($enElEcuador / 2, $a60Grados, 0.5);
    }

    public function testEsSimetrica(): void
    {
        $ida    = Geo::haversineKm(-34.60, -58.38, -38.95, -68.05);
        $vuelta = Geo::haversineKm(-38.95, -68.05, -34.60, -58.38);

        self::assertEqualsWithDelta($ida, $vuelta, 0.000001);
    }

    /**
     * Cruzar el antimeridiano (±180°) es donde una resta ingenua de longitudes
     * da media vuelta al mundo. Dos puntos separados por 2° reales no pueden
     * dar ~40.000 km.
     */
    public function testCruzarElAntimeridianoNoDaLaVueltaAlMundo(): void
    {
        $km = Geo::haversineKm(0.0, 179.0, 0.0, -179.0);

        self::assertEqualsWithDelta(222.4, $km, 1.0, 'debería ser ~222 km, no media circunferencia');
    }

    public function testPuntosAntipodalesDanMediaCircunferencia(): void
    {
        $km = Geo::haversineKm(0.0, 0.0, 0.0, 180.0);

        // Media circunferencia ≈ π · 6371 km.
        self::assertEqualsWithDelta(20015, $km, 20);
    }

    /**
     * El filtro de ruido GPS compara contra `min_step_m` = 25 m. La fórmula
     * tiene que resolver distancias de ese orden sin perderse en el redondeo.
     */
    public function testResuelveDistanciasCortasEnElOrdenDelRuidoGps(): void
    {
        // ~20 m al norte.
        $metros = Geo::haversineKm(-34.6037, -58.3816, -34.6037 + (20 / 111320), -58.3816) * 1000;

        self::assertEqualsWithDelta(20, $metros, 0.5);
    }
}
