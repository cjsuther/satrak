<?php
/**
 * Carga y normaliza la configuración de la aplicación.
 *
 * Devuelve el array de config/config.php. Si no existe (deploy sin configurar),
 * aborta con un mensaje claro en vez de fallar de forma críptica más adelante.
 */

declare(strict_types=1);

$configFile = dirname(__DIR__) . '/config/config.php';

if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit(
        "Configuración faltante.\n\n" .
        "Copiá config/config.example.php a config/config.php y completá los valores.\n"
    );
}

/** @var array<string,mixed> $config */
$config = require $configFile;

// Defaults defensivos por si el config real omite alguna clave.
$config['app']      = ($config['app']      ?? []) + [
    'env'                 => 'production',
    'base_url'            => '',
    'debug'               => false,
    'tz'                  => 'America/Argentina/Buenos_Aires',
    'locale'              => 'es_AR',
    'session_timeout_min' => 480,
];
$config['tracking'] = ($config['tracking'] ?? []) + [
    'offline_minutes'   => 30,
    'idle_minutes'      => 10,
    'trip_stop_minutes' => 5,
    'retention_months'  => 12,
];
$config['pin']      = ($config['pin']      ?? []) + ['min_length' => 4, 'max_length' => 10];
$config['map']      = ($config['map']      ?? []) + ['live_poll_seconds' => 15];
$config['people']   = ($config['people']   ?? []) + [
    'moving_sample_seconds'  => 60,
    'stopped_sample_seconds' => 300,
    'walk_speed_kmh'         => 2,
    'min_step_m'             => 25,
    'max_accuracy_m'         => 100,
    'person_stop_minutes'    => 10,
    'no_movement_minutes'    => 15,
    'app_offline_minutes'    => 15,
    'low_battery_pct'        => 15,
];

return $config;
