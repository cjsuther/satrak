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

    // Módulo de personas (rastreo de personal)
    'people' => [
        'moving_sample_seconds'  => 60,   // frecuencia de reporte de la app en movimiento
        'stopped_sample_seconds' => 300,  // ídem detenida (cuida la batería en equipos viejos)
        'walk_speed_kmh'         => 2,    // por debajo, se considera detenida
        'min_step_m'             => 25,   // desplazamiento mínimo entre puntos (filtra ruido GPS)
        'max_accuracy_m'         => 100,  // puntos menos precisos que esto se descartan
        'person_stop_minutes'    => 10,   // detenida => corta el recorrido
        'no_movement_minutes'    => 15,   // sin desplazarse en jornada => alerta (hombre caído)
        'app_offline_minutes'    => 15,   // en jornada y sin reportar => alerta
        'low_battery_pct'        => 15,   // por debajo => alerta
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
