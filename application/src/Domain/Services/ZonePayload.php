<?php

declare(strict_types=1);

namespace Satrak\Domain\Services;

/**
 * Valida y convierte el `FeatureCollection` de zonas que manda el editor de
 * mapas hacia el formato que guarda Satrak.
 *
 * El editor dibuja con Terra Draw, que produce SIEMPRE polígonos: círculo,
 * rectángulo y mano alzada llegan ya poligonizados, así que del lado del
 * servidor no hay casos especiales por forma.
 *
 * Dos cosas que hay que tener presentes:
 *
 *  - **El orden de las coordenadas se invierte.** GeoJSON usa `[lon, lat]`;
 *    Satrak guarda `[lat, lon]`. Es el error más fácil de cometer en esta
 *    conversión y el más difícil de ver: un punto en Neuquén cae en el mar de
 *    China y nadie se da cuenta hasta que una alerta no salta.
 *  - **Los límites son una defensa, no una validación de forma.** El payload
 *    llega del navegador: sin topes, una zona con 200.000 vértices haría que
 *    cada posición procesada recorra 200.000 segmentos, en cada tick del
 *    procesador, para toda la empresa.
 */
final class ZonePayload
{
    /** Una geocerca es UN área. El editor lo respeta; esto lo hace cumplir. */
    public const MAX_ZONES = 1;

    /** Tope de vértices por zona (la spec de migración fija 500). */
    public const MAX_VERTICES = 500;

    /** Bounds de Argentina con margen: descarta coordenadas invertidas o basura. */
    private const LAT_MIN = -56.0;
    private const LAT_MAX = -20.0;
    private const LON_MIN = -74.5;
    private const LON_MAX = -52.0;

    /**
     * Resultado de convertir el payload.
     *
     * @param array<int,array{0:float,1:float}>|null $polygon `[[lat,lon],…]`, o NULL si hubo error
     */
    private function __construct(
        public readonly ?array $polygon,
        public readonly ?string $error,
    ) {
    }

    public static function ok(array $polygon): self
    {
        return new self($polygon, null);
    }

    public static function fail(string $error): self
    {
        return new self(null, $error);
    }

    public function failed(): bool
    {
        return $this->error !== null;
    }

    /**
     * Convierte el FeatureCollection a la geometría de una geocerca.
     *
     * @param string|array<string,mixed>|null $raw JSON del cliente o ya decodificado
     */
    public static function fromFeatureCollection(string|array|null $raw): self
    {
        if (is_string($raw)) {
            $raw = trim($raw);
            if ($raw === '') {
                return self::fail('No llegó ninguna zona dibujada.');
            }
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return self::fail('El formato de las zonas no es válido.');
        }

        $features = $raw['features'] ?? null;
        if (!is_array($features) || $features === []) {
            return self::fail('Dibujá la geocerca en el mapa.');
        }

        $polygons = array_values(array_filter($features, static function ($f): bool {
            return is_array($f)
                && isset($f['geometry']['type'], $f['geometry']['coordinates'])
                && $f['geometry']['type'] === 'Polygon';
        }));

        if ($polygons === []) {
            return self::fail('Dibujá la geocerca en el mapa.');
        }
        if (count($polygons) > self::MAX_ZONES) {
            return self::fail('Una geocerca es un área sola: dejá una zona dibujada.');
        }

        return self::polygonFromGeoJson($polygons[0]['geometry']['coordinates']);
    }

    /**
     * @param mixed $coordinates anillos del Polygon, en `[lon, lat]`
     */
    private static function polygonFromGeoJson(mixed $coordinates): self
    {
        if (!is_array($coordinates) || !isset($coordinates[0]) || !is_array($coordinates[0])) {
            return self::fail('La zona no tiene un contorno válido.');
        }

        // Sólo el anillo exterior: Satrak no modela huecos.
        $ring = $coordinates[0];
        if (count($ring) > self::MAX_VERTICES + 1) {
            return self::fail('La zona tiene demasiados vértices (máximo ' . self::MAX_VERTICES . ').');
        }

        $out = [];
        foreach ($ring as $pair) {
            if (!is_array($pair) || !isset($pair[0], $pair[1]) || !is_numeric($pair[0]) || !is_numeric($pair[1])) {
                return self::fail('La zona tiene coordenadas inválidas.');
            }
            $lon = (float) $pair[0];
            $lat = (float) $pair[1];

            if ($lat < self::LAT_MIN || $lat > self::LAT_MAX || $lon < self::LON_MIN || $lon > self::LON_MAX) {
                return self::fail('La zona cae fuera del área de cobertura.');
            }

            $out[] = [round($lat, 7), round($lon, 7)];
        }

        // GeoJSON cierra el anillo repitiendo el primer punto; Satrak no lo guarda.
        $n = count($out);
        if ($n > 1 && $out[0] === $out[$n - 1]) {
            array_pop($out);
        }

        if (count($out) < 3) {
            return self::fail('El polígono necesita al menos 3 vértices.');
        }

        return self::ok($out);
    }
}
