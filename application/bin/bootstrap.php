<?php
/**
 * Bootstrap común para los scripts CLI de bin/ (processor, purge, seed, admin).
 *
 * Carga autoload + config + zona horaria y devuelve [config, PDO].
 * Aborta con mensaje claro si falta config o no conecta la base.
 *
 * Uso:
 *   [$config, $pdo] = require __DIR__ . '/bootstrap.php';
 */

declare(strict_types=1);

use Satrak\Application\Support\Database;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo corre por CLI.\n");
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

$config = require dirname(__DIR__) . '/src/settings.php';

date_default_timezone_set($config['app']['tz']);
mb_internal_encoding('UTF-8');

try {
    $pdo = Database::connect($config['db']);
} catch (\Throwable $e) {
    fwrite(STDERR, "ERROR de base de datos: {$e->getMessage()}\n");
    exit(1);
}

return [$config, $pdo];
