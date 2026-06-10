<?php

/**
 * Satrak — Plataforma de Tracking · configuración (plantilla)
 *
 * Copiá este archivo a config/config.php y completá los valores reales.
 * config/config.php está en .gitignore: NUNCA se versiona con secretos.
 *
 * En local podés dejar app.env = 'local' y app.debug = true para ver errores.
 * En Hostinger: app.env = 'production', app.debug = false.
 */

return [
    'app' => [
        'env'                 => 'production',                 // 'local' | 'production'
        'base_url'            => 'https://app.satrak.online',
        'debug'               => false,
        'tz'                  => 'America/Argentina/Buenos_Aires',
        'locale'              => 'es_AR',
        'session_timeout_min' => 480,                          // 8 h de inactividad
    ],

    'db' => [
        'host'    => 'localhost',
        'name'    => '',                                       // nombre de la base
        'user'    => '',
        'pass'    => '',
        'charset' => 'utf8mb4',
        'port'    => 3306,
    ],

    'smtp' => [
        'host'      => '',
        'port'      => 587,
        'user'      => '',
        'pass'      => '',
        'secure'    => 'tls',                                  // 'tls' | 'ssl' | ''
        'from'      => 'alertas@satrak.online',
        'from_name' => 'Satrak',
    ],

    'map' => [
        'live_poll_seconds' => 15,
        'tile_url'          => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
        'attribution'       => '© OpenStreetMap',
    ],

    // Umbrales del procesador (§24, decisiones confirmadas). Todos configurables.
    'tracking' => [
        'offline_minutes'   => 30,
        'idle_minutes'      => 10,
        'trip_stop_minutes' => 5,
        'retention_months'  => 12,
        // PIN: longitud 4 a 10; por defecto 4 numéricos, se acepta alfanumérico.
        'pin_min_length'    => 4,
        'pin_max_length'    => 10,
    ],

    // Para los endpoints /cron/run y /cron/purge (opcional; solo si no hay cron CLI).
    'cron' => [
        'token' => 'CAMBIAR_POR_UN_TOKEN_LARGO_Y_ALEATORIO',
    ],
];
