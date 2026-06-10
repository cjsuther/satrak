<?php

declare(strict_types=1);

/**
 * Helpers globales de Satrak (autocargados vía composer "files").
 * Mantener mínimo: utilidades de escape, entorno y formato es-AR.
 */

if (!function_exists('e')) {
    /**
     * Escapa una cadena para salida HTML segura.
     * (Twig ya autoescapa; esto es para usos puntuales fuera de plantillas.)
     */
    function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('env_is')) {
    /** ¿El entorno actual coincide? Lee $GLOBALS['satrak_env'] seteado en el bootstrap. */
    function env_is(string $env): bool
    {
        return ($GLOBALS['satrak_env'] ?? 'production') === $env;
    }
}

if (!function_exists('base_path')) {
    /** Ruta absoluta dentro del proyecto. */
    function base_path(string $relative = ''): string
    {
        $root = dirname(__DIR__, 3); // src/Application/Support -> raíz
        return $relative === '' ? $root : $root . '/' . ltrim($relative, '/');
    }
}

if (!function_exists('format_speed')) {
    /** Formatea velocidad en km/h (datos en fuente mono en la UI). */
    function format_speed(?int $kmh): string
    {
        return $kmh === null ? '—' : $kmh . ' km/h';
    }
}
