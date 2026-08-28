<?php

declare(strict_types=1);

namespace Satrak\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Satrak\Domain\Services\Geo;
use Satrak\Domain\Services\GeofenceMath;

/**
 * `GeofenceMath::contains()` decide si alguien está dentro de su puesto o llegó
 * a destino. De él dependen la alerta «fuera de puesto» y la validación de
 * llegada a misión, así que un falso positivo acá le da por cumplida una misión
 * a quien no llegó, y un falso negativo le dispara una alerta a quien sí está
 * donde debe.
 */
final class GeofenceMathTest extends TestCase
{
    /** Geocerca real de la demo: Base Neuquén, círculo de 600 m. */
    private const BASE_NEUQUEN = [
        'shape'    => 'circle',
        'geometry' => ['lat' => -38.9396, 'lon' => -68.0676, 'radius_m' => 600],
    ];

    /**
     * Polígono real cargado desde la UI. Incluye el primer vértice DUPLICADO,
     * que es lo que produce un doble clic al dibujar: el algoritmo tiene que
     * tolerarlo sin alterar el resultado.
     */
    private const TRABAJO_ANA = [
        'shape'    => 'polygon',
        'geometry' => [
            [-34.5631168, -58.4560633],
            [-34.5631168, -58.4560633],
            [-34.5631521, -58.4562349],
            [-34.5644951, -58.4582520],
            [-34.5665448, -58.4568787],
            [-34.5651312, -58.4544325],
        ],
    ];

    public function testCentroDelCirculoEstaAdentro(): void
    {
        self::assertTrue(GeofenceMath::contains(self::BASE_NEUQUEN, -38.9396, -68.0676));
    }

    public function testPuntoLejanoAlCirculoEstaAfuera(): void
    {
        // ~1 km al norte del centro, con radio de 600 m.
        self::assertFalse(GeofenceMath::contains(self::BASE_NEUQUEN, -38.9306, -68.0676));
    }

    /**
     * El borde es inclusivo (`<=`). Se verifica construyendo un punto a una
     * distancia conocida en vez de confiar en un literal: si alguien cambia el
     * radio de la Tierra en Geo, este test lo detecta.
     */
    public function testElBordeDelCirculoCuentaComoAdentro(): void
    {
        $centroLat = -38.9396;
        $centroLon = -68.0676;
        // 600 m al norte ≈ 600/111320 grados de latitud.
        $bordeLat = $centroLat + (600 / 111320);

        $distancia = Geo::haversineKm($bordeLat, $centroLon, $centroLat, $centroLon) * 1000;
        self::assertEqualsWithDelta(600, $distancia, 1.0, 'el punto de prueba no quedó sobre el borde');

        // Apenas adentro del borde sí debe contener.
        self::assertTrue(GeofenceMath::contains(self::BASE_NEUQUEN, $centroLat + (595 / 111320), $centroLon));
        // Apenas afuera, no.
        self::assertFalse(GeofenceMath::contains(self::BASE_NEUQUEN, $centroLat + (610 / 111320), $centroLon));
    }

    public function testRadioCeroNuncaContiene(): void
    {
        $geofence = ['shape' => 'circle', 'geometry' => ['lat' => 0, 'lon' => 0, 'radius_m' => 0]];

        // Ni siquiera su propio centro: un radio 0 es una geocerca sin sentido y
        // devolver true haría que "todos estén adentro" de una cerca mal cargada.
        self::assertFalse(GeofenceMath::contains($geofence, 0.0, 0.0));
    }

    public function testRadioNegativoNuncaContiene(): void
    {
        $geofence = ['shape' => 'circle', 'geometry' => ['lat' => 0, 'lon' => 0, 'radius_m' => -100]];
        self::assertFalse(GeofenceMath::contains($geofence, 0.0, 0.0));
    }

    public function testPuntoDentroDelPoligono(): void
    {
        // Última posición real registrada de Ana, que cae dentro del polígono.
        self::assertTrue(GeofenceMath::contains(self::TRABAJO_ANA, -34.5646477, -58.4558096));
    }

    public function testPuntoFueraDelPoligono(): void
    {
        // ~1 km al norte del polígono.
        self::assertFalse(GeofenceMath::contains(self::TRABAJO_ANA, -34.5550, -58.4558));
    }

    public function testElVerticeDuplicadoNoAlteraElResultado(): void
    {
        $sinDuplicado = self::TRABAJO_ANA;
        array_shift($sinDuplicado['geometry']);   // saca la primera de las dos copias

        $punto = [-34.5646477, -58.4558096];
        self::assertSame(
            GeofenceMath::contains(self::TRABAJO_ANA, $punto[0], $punto[1]),
            GeofenceMath::contains($sinDuplicado, $punto[0], $punto[1]),
        );
    }

    public function testPoligonoConMenosDeTresVerticesNoContiene(): void
    {
        foreach ([[], [[-34.56, -58.45]], [[-34.56, -58.45], [-34.57, -58.46]]] as $geometry) {
            $geofence = ['shape' => 'polygon', 'geometry' => $geometry];
            self::assertFalse(
                GeofenceMath::contains($geofence, -34.565, -58.455),
                'un polígono degenerado no puede contener nada',
            );
        }
    }

    /**
     * La geometría viaja como JSON en la base. `contains()` acepta el string
     * crudo, y así lo llama el AlertEngine.
     */
    public function testAceptaGeometriaComoJsonString(): void
    {
        $geofence = [
            'shape'    => 'circle',
            'geometry' => json_encode(['lat' => -38.9396, 'lon' => -68.0676, 'radius_m' => 600]),
        ];
        self::assertTrue(GeofenceMath::contains($geofence, -38.9396, -68.0676));
    }

    public function testJsonInvalidoNoRompeYDevuelveFalse(): void
    {
        $geofence = ['shape' => 'circle', 'geometry' => '{esto no es json'];
        self::assertFalse(GeofenceMath::contains($geofence, 0.0, 0.0));
    }

    /** Los vértices también pueden venir como {lat, lon}. */
    public function testAceptaVerticesComoObjeto(): void
    {
        $geofence = [
            'shape'    => 'polygon',
            'geometry' => [
                ['lat' => 0.0, 'lon' => 0.0],
                ['lat' => 0.0, 'lon' => 1.0],
                ['lat' => 1.0, 'lon' => 1.0],
                ['lat' => 1.0, 'lon' => 0.0],
            ],
        ];
        self::assertTrue(GeofenceMath::contains($geofence, 0.5, 0.5));
        self::assertFalse(GeofenceMath::contains($geofence, 1.5, 0.5));
    }

    /**
     * Un polígono cóncavo (forma de C): el hueco NO está adentro aunque quede
     * dentro del rectángulo que lo envuelve. Es lo que distingue al ray casting
     * de una simple comparación de máximos y mínimos.
     */
    public function testPoligonoConcavoExcluyeElHueco(): void
    {
        $c = [
            'shape'    => 'polygon',
            'geometry' => [
                [0.0, 0.0], [0.0, 3.0], [1.0, 3.0], [1.0, 1.0],
                [2.0, 1.0], [2.0, 3.0], [3.0, 3.0], [3.0, 0.0],
            ],
        ];

        self::assertTrue(GeofenceMath::contains($c, 0.5, 1.5), 'el brazo inferior está adentro');
        self::assertFalse(GeofenceMath::contains($c, 1.5, 2.0), 'el hueco de la C no está adentro');
    }

    #[DataProvider('formasDesconocidas')]
    public function testUnaFormaDesconocidaSeTrataComoPoligono(string $shape): void
    {
        // No es un círculo => cae al ray casting. Con geometría de círculo (que
        // no es una lista de vértices) no contiene nada, en vez de explotar.
        $geofence = ['shape' => $shape, 'geometry' => ['lat' => 0, 'lon' => 0, 'radius_m' => 600]];
        self::assertFalse(GeofenceMath::contains($geofence, 0.0, 0.0));
    }

    /** @return array<string,array{0:string}> */
    public static function formasDesconocidas(): array
    {
        return [
            'vacía'       => [''],
            'rectangle'   => ['rectangle'],
            'CIRCLE mayús'=> ['CIRCLE'],
        ];
    }
}
