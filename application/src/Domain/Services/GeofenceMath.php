<?php

declare(strict_types=1);

namespace Satrak\Domain\Services;

/**
 * Geometría de geocercas (§12): contención punto-en-geocerca.
 *
 *  - Círculo: distancia haversine al centro < radio (en metros).
 *  - Polígono: ray casting (par/impar de cruces) sobre el anillo de vértices.
 *
 * La geometría llega como la guarda el CRUD (spec §7):
 *   circle:  {"lat":..,"lon":..,"radius_m":..}
 *   polygon: [[lat,lon],[lat,lon],...]
 */
final class GeofenceMath
{
    /**
     * ¿El punto (lat,lon) está dentro de la geocerca?
     *
     * @param array{shape:string,geometry:mixed} $geofence fila de `geofences`
     *        (geometry puede venir como JSON string o ya decodificado).
     */
    public static function contains(array $geofence, float $lat, float $lon): bool
    {
        $geom = $geofence['geometry'];
        if (is_string($geom)) {
            $geom = json_decode($geom, true);
        }
        if (!is_array($geom)) {
            return false;
        }

        if (($geofence['shape'] ?? '') === 'circle') {
            $cLat = (float) ($geom['lat'] ?? 0);
            $cLon = (float) ($geom['lon'] ?? 0);
            $radiusM = (float) ($geom['radius_m'] ?? 0);
            if ($radiusM <= 0) {
                return false;
            }

            return Geo::haversineKm($lat, $lon, $cLat, $cLon) * 1000.0 <= $radiusM;
        }

        return self::pointInPolygon($lat, $lon, $geom);
    }

    /**
     * Ray casting. $polygon es una lista de [lat, lon]. Devuelve true si el punto
     * cae dentro del anillo (la paridad de cruces de un rayo horizontal es impar).
     *
     * @param array<int,array{0:float,1:float}|array<string,float>> $polygon
     */
    private static function pointInPolygon(float $lat, float $lon, array $polygon): bool
    {
        $n = count($polygon);
        if ($n < 3) {
            return false;
        }

        $inside = false;
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$latI, $lonI] = self::coord($polygon[$i]);
            [$latJ, $lonJ] = self::coord($polygon[$j]);

            // ¿El rayo horizontal en `lat` cruza el segmento i–j? Si cruza, alterna.
            $straddles = ($latI > $lat) !== ($latJ > $lat);
            if ($straddles) {
                $lonAtLat = ($lonJ - $lonI) * ($lat - $latI) / ($latJ - $latI) + $lonI;
                if ($lon < $lonAtLat) {
                    $inside = !$inside;
                }
            }
        }

        return $inside;
    }

    /**
     * Normaliza un vértice a [lat, lon] aceptando [lat,lon] o {lat,lon}.
     *
     * @param array<int|string,float> $point
     * @return array{0:float,1:float}
     */
    private static function coord(array $point): array
    {
        if (array_key_exists('lat', $point)) {
            return [(float) $point['lat'], (float) $point['lon']];
        }

        return [(float) ($point[0] ?? 0), (float) ($point[1] ?? 0)];
    }
}
