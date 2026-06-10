<?php

declare(strict_types=1);

/**
 * Carga la configuración de la aplicación.
 *
 * Lee config/config.php (real, ignorado por git). Si no existe, cae a
 * config.example.php para que la app pueda al menos arrancar y avisar.
 *
 * @return array<string,mixed>
 */

$root = dirname(__DIR__);

$real    = $root . '/config/config.php';
$example = $root . '/config/config.example.php';

if (is_file($real)) {
    $config = require $real;
} elseif (is_file($example)) {
    $config = require $example;
    $config['_warning'] = 'Usando config.example.php: copiá config.example.php a config.php.';
} else {
    throw new RuntimeException('No se encontró config/config.php ni config/config.example.php.');
}

// Rutas absolutas útiles en todo el sistema.
$config['paths'] = [
    'root'      => $root,
    'public'    => $root . '/public',
    'templates' => $root . '/templates',
    'storage'   => $root . '/storage',
    'logs'      => $root . '/storage/logs',
];

return $config;
