<?php

declare(strict_types=1);

namespace Satrak\Domain\Services;

/**
 * Utilidades geográficas. Haversine para distancia entre coordenadas.
 * (El ray casting para polígonos llega en la Fase 6 con GeofenceMath.)
 */
final class Geo
{
    private const EARTH_KM = 6371.0088;

    /**
     * Distancia en kilómetros entre dos puntos (lat/lon en grados).
     */
    public static function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return self::EARTH_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
