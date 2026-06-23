<?php
/**
 * Satrak — Plantilla de configuración.
 *
 * Copiá este archivo a config/config.php y completá los valores reales.
 * config.php NO se versiona (ver .gitignore): contiene los secretos.
 */

return [
    'app' => [
        'env'                 => 'production',           // 'production' | 'development'
        'base_url'            => 'https://app.satrak.online',
        'debug'               => false,                  // true muestra errores en pantalla (solo dev)
        'tz'                  => 'America/Argentina/Buenos_Aires',
        'locale'              => 'es_AR',
        'session_timeout_min' => 480,                    // 8 h de inactividad
    ],

    'db' => [
        'host'    => 'localhost',
        'name'    => '',
        'user'    => '',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    'smtp' => [
        'host'      => '',
        'port'      => 587,
        'user'      => '',
        'pass'      => '',
        'secure'    => 'tls',                            // 'tls' | 'ssl' | ''
        'from'      => 'alertas@satrak.online',
        'from_name' => 'Satrak',
    ],

    'map' => [
        'live_poll_seconds' => 15,
        'tile_url'          => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
    ],

    'tracking' => [
        'offline_minutes'   => 30,                       // sin reportar => offline
        'idle_minutes'      => 10,                        // detenido con motor encendido
        'trip_stop_minutes' => 5,                         // detenido => corta el viaje
        'retention_months'  => 12,                        // purga de positions/device_events
    ],

    // PIN: longitud y formato (decisión confirmada §2 del prompt)
    'pin' => [
        'min_length' => 4,
        'max_length' => 10,
    ],

    // Endpoint /cron/run opcional (no prioritario: el cron real corre por CLI).
    'cron' => [
        'token' => 'CAMBIAR_POR_TOKEN_LARGO_Y_ALEATORIO',
    ],

    'security' => [
        // Orígenes extra permitidos en la CSP (Leaflet/OSM ya vienen incluidos).
        'csp_extra' => '',
    ],
];
