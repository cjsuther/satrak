<?php

declare(strict_types=1);

namespace Satrak\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Satrak\Domain\Services\GeofenceMath;
use Satrak\Domain\Services\ZonePayload;

/**
 * Conversión del FeatureCollection que dibuja el editor de mapas al formato que
 * guarda Satrak.
 *
 * Es una frontera entre dos convenciones de coordenadas opuestas —GeoJSON usa
 * `[lon, lat]` y Satrak guarda `[lat, lon]`— y ese es exactamente el tipo de
 * error que no da la cara: un punto de Neuquén invertido cae en el mar de
 * China, la geocerca deja de contener a nadie, y nadie se entera hasta que una
 * alerta no salta.
 */
final class ZonePayloadTest extends TestCase
{
    /** Polígono en GeoJSON (lon, lat) alrededor de Neuquén, anillo cerrado. */
    private function featureCollection(array $ring): array
    {
        return [
            'type' => 'FeatureCollection',
            'features' => [
                ['type' => 'Feature', 'properties' => [], 'geometry' => ['type' => 'Polygon', 'coordinates' => [$ring]]],
            ],
        ];
    }

    /** @return array<int,array{0:float,1:float}> anillo cerrado en [lon,lat] */
    private function anilloNeuquen(): array
    {
        return [
            [-68.07, -38.95],
            [-68.05, -38.95],
            [-68.05, -38.93],
            [-68.07, -38.93],
            [-68.07, -38.95],   // cierre
        ];
    }

    // --- Conversión ---------------------------------------------------------

    /**
     * El test central de toda la migración: entra `[lon, lat]`, sale
     * `[lat, lon]`. Si alguien "simplifica" la conversión, esto lo caza.
     */
    public function testInvierteElOrdenDeLasCoordenadas(): void
    {
        $r = ZonePayload::fromFeatureCollection($this->featureCollection($this->anilloNeuquen()));

        self::assertFalse($r->failed(), (string) $r->error);
        // Primer vértice: en GeoJSON era [-68.07, -38.95]; en Satrak va al revés.
        self::assertSame([-38.95, -68.07], $r->polygon[0]);
    }

    /** El anillo GeoJSON repite el primer punto al final; Satrak no lo guarda. */
    public function testDescartaElVerticeDeCierre(): void
    {
        $r = ZonePayload::fromFeatureCollection($this->featureCollection($this->anilloNeuquen()));

        self::assertCount(4, $r->polygon, 'el anillo tenía 5 puntos con el cierre repetido');
        self::assertNotSame($r->polygon[0], $r->polygon[3]);
    }

    /**
     * El resultado tiene que ser consumible por GeofenceMath sin traducciones
     * intermedias: es lo que va a guardarse y lo que evalúa el motor de alertas.
     */
    public function testElResultadoLoEntiendeGeofenceMath(): void
    {
        $r = ZonePayload::fromFeatureCollection($this->featureCollection($this->anilloNeuquen()));
        $geofence = ['shape' => 'polygon', 'geometry' => $r->polygon];

        self::assertTrue(GeofenceMath::contains($geofence, -38.94, -68.06), 'un punto del centro debe caer adentro');
        self::assertFalse(GeofenceMath::contains($geofence, -38.90, -68.06), 'uno de afuera, no');
    }

    public function testAceptaElJsonComoString(): void
    {
        $json = json_encode($this->featureCollection($this->anilloNeuquen()));
        $r = ZonePayload::fromFeatureCollection($json);

        self::assertFalse($r->failed());
        self::assertCount(4, $r->polygon);
    }

    public function testIgnoraLosHuecosDelPoligono(): void
    {
        // Satrak no modela huecos: sólo se toma el anillo exterior.
        $fc = $this->featureCollection($this->anilloNeuquen());
        $fc['features'][0]['geometry']['coordinates'][] = [
            [-68.065, -38.945], [-68.060, -38.945], [-68.060, -38.940], [-68.065, -38.945],
        ];

        $r = ZonePayload::fromFeatureCollection($fc);
        self::assertFalse($r->failed());
        self::assertCount(4, $r->polygon);
    }

    // --- Rechazos -----------------------------------------------------------

    public function testRechazaPayloadVacioOInvalido(): void
    {
        foreach ([null, '', '   ', 'no es json', '[]', '{}'] as $raw) {
            self::assertTrue(
                ZonePayload::fromFeatureCollection($raw)->failed(),
                'debería fallar con ' . var_export($raw, true),
            );
        }
    }

    public function testRechazaUnFeatureCollectionSinPoligonos(): void
    {
        $fc = [
            'type' => 'FeatureCollection',
            'features' => [
                ['type' => 'Feature', 'properties' => [], 'geometry' => ['type' => 'Point', 'coordinates' => [-68, -38]]],
                ['type' => 'Feature', 'properties' => [], 'geometry' => ['type' => 'LineString', 'coordinates' => [[-68, -38], [-67, -38]]]],
            ],
        ];

        self::assertTrue(ZonePayload::fromFeatureCollection($fc)->failed());
    }

    /** Una geocerca es un área sola: el modelo guarda una geometría. */
    public function testRechazaMasDeUnaZona(): void
    {
        $fc = $this->featureCollection($this->anilloNeuquen());
        $fc['features'][] = $fc['features'][0];

        $r = ZonePayload::fromFeatureCollection($fc);
        self::assertTrue($r->failed());
        self::assertStringContainsString('un área sola', (string) $r->error);
    }

    public function testRechazaMenosDeTresVertices(): void
    {
        $r = ZonePayload::fromFeatureCollection($this->featureCollection([
            [-68.07, -38.95], [-68.05, -38.95], [-68.07, -38.95],
        ]));

        self::assertTrue($r->failed());
        self::assertStringContainsString('3 vértices', (string) $r->error);
    }

    /**
     * Sin tope, una zona con cientos de miles de vértices haría que el
     * procesador recorra ese polígono por CADA posición de la empresa, en cada
     * corrida. Es una defensa de disponibilidad, no una validación de forma.
     */
    public function testRechazaDemasiadosVertices(): void
    {
        $ring = [];
        for ($i = 0; $i <= ZonePayload::MAX_VERTICES + 5; $i++) {
            $ring[] = [-68.06 + ($i % 100) * 0.0001, -38.94 + ($i % 97) * 0.0001];
        }
        $ring[] = $ring[0];

        $r = ZonePayload::fromFeatureCollection($this->featureCollection($ring));
        self::assertTrue($r->failed());
        self::assertStringContainsString('demasiados vértices', (string) $r->error);
    }

    /**
     * Coordenadas invertidas: si alguien manda `[lat, lon]` donde va
     * `[lon, lat]`, Neuquén (-38.95, -68.06) se lee como lat -68 / lon -38,
     * que cae fuera de cobertura. El chequeo de bounds lo agarra en vez de
     * guardar una geocerca silenciosamente inservible.
     */
    public function testRechazaCoordenadasInvertidas(): void
    {
        $invertido = array_map(static fn (array $p): array => [$p[1], $p[0]], $this->anilloNeuquen());

        $r = ZonePayload::fromFeatureCollection($this->featureCollection($invertido));
        self::assertTrue($r->failed());
        self::assertStringContainsString('fuera del área de cobertura', (string) $r->error);
    }

    public function testRechazaCoordenadasFueraDeArgentina(): void
    {
        // San Francisco: lo que reporta el simulador de iPhone.
        $sf = [[-122.41, 37.78], [-122.40, 37.78], [-122.40, 37.79], [-122.41, 37.78]];

        self::assertTrue(ZonePayload::fromFeatureCollection($this->featureCollection($sf))->failed());
    }

    public function testRechazaCoordenadasNoNumericas(): void
    {
        $r = ZonePayload::fromFeatureCollection($this->featureCollection([
            [-68.07, -38.95], ['x', -38.95], [-68.05, -38.93], [-68.07, -38.95],
        ]));

        self::assertTrue($r->failed());
        self::assertStringContainsString('inválidas', (string) $r->error);
    }

    public function testRedondeaASieteDecimales(): void
    {
        $r = ZonePayload::fromFeatureCollection($this->featureCollection([
            [-68.0712345678901, -38.9512345678901],
            [-68.05, -38.95],
            [-68.05, -38.93],
            [-68.0712345678901, -38.9512345678901],
        ]));

        self::assertFalse($r->failed());
        // La columna guarda 7 decimales: más precisión es ruido de GPS.
        self::assertSame(-38.9512346, $r->polygon[0][0]);
    }
}
