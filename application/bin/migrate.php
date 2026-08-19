<?php
/**
 * Runner de migraciones de esquema.
 *
 * `schema.sql` sirve para CREAR una base desde cero; no para evolucionar una que
 * ya tiene datos. Este runner aplica, una sola vez y en orden, los archivos de
 * `database/migrations/`, registrando lo aplicado en la tabla `schema_migrations`.
 *
 * Formato de los archivos (el orden lo da el nombre):
 *   NNN_descripcion.sql   sentencias SQL separadas por `;`
 *   NNN_descripcion.php   `return function (PDO $pdo): void { ... };`
 *
 * Uso:
 *   php bin/migrate.php              aplica las pendientes
 *   php bin/migrate.php --status     lista aplicadas/pendientes y sale
 *   php bin/migrate.php --dry-run    muestra qué se aplicaría, sin tocar la base
 *   php bin/migrate.php --baseline   marca TODAS como aplicadas sin ejecutarlas
 *                                    (usar sólo después de importar schema.sql
 *                                     en una base nueva, que ya viene al día)
 *
 * En Hostinger, con PHP 8.3:
 *   /opt/alt/php83/usr/bin/php bin/migrate.php --status
 *
 * Nota: el separador de sentencias de los `.sql` es un `;` fuera de comentarios.
 * No usar `;` dentro de literales de texto en una migración; si hace falta, se
 * escribe la migración como `.php`.
 */

declare(strict_types=1);

/** @var array $config @var PDO $pdo */
[$config, $pdo] = require __DIR__ . '/bootstrap.php';

$args     = array_slice($argv, 1);
$has      = static fn (string $flag): bool => in_array('--' . $flag, $args, true);
$status   = $has('status');
$dryRun   = $has('dry-run');
$baseline = $has('baseline');
$quiet    = $has('quiet');

$out = static function (string $line) use ($quiet): void {
    if (!$quiet) {
        echo $line . "\n";
    }
};

$dir = dirname(__DIR__) . '/database/migrations';
if (!is_dir($dir)) {
    fwrite(STDERR, "No existe {$dir}\n");
    exit(1);
}

// Registro de lo aplicado.
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
       version    VARCHAR(120) NOT NULL PRIMARY KEY,
       checksum   CHAR(64) NOT NULL,
       applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

/** @var array<string,string> version => checksum */
$applied = [];
foreach ($pdo->query('SELECT version, checksum FROM schema_migrations')->fetchAll() as $r) {
    $applied[(string) $r['version']] = (string) $r['checksum'];
}

$files = array_merge(glob($dir . '/*.sql') ?: [], glob($dir . '/*.php') ?: []);
sort($files, SORT_STRING);

if ($files === []) {
    $out('No hay migraciones en database/migrations/.');
    exit(0);
}

// --- --status: informe y salida --------------------------------------------
if ($status) {
    $out("Migraciones en {$dir}:");
    foreach ($files as $file) {
        $version = basename($file);
        $checksum = hash_file('sha256', $file);
        if (!isset($applied[$version])) {
            $out("  [ ] {$version}");
        } elseif ($applied[$version] !== $checksum) {
            $out("  [!] {$version}  (aplicada, pero el archivo CAMBIÓ desde entonces)");
        } else {
            $out("  [x] {$version}");
        }
    }
    exit(0);
}

// --- --baseline: marcar todo como aplicado sin ejecutar ---------------------
if ($baseline) {
    $stmt = $pdo->prepare(
        'INSERT INTO schema_migrations (version, checksum) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE checksum = VALUES(checksum)'
    );
    foreach ($files as $file) {
        $stmt->execute([basename($file), hash_file('sha256', $file)]);
    }
    $out('Baseline: ' . count($files) . ' migraciones marcadas como aplicadas (no se ejecutó ninguna).');
    exit(0);
}

// --- Aplicar pendientes ------------------------------------------------------

/**
 * Parte un script SQL en sentencias. Descarta comentarios de línea (`--`, `#`)
 * y de bloque, y separa por `;`.
 *
 * @return string[]
 */
$statements = static function (string $sql): array {
    $sql = (string) preg_replace('~/\*.*?\*/~s', '', $sql);
    $keep = [];
    foreach (preg_split('/\R/', $sql) ?: [] as $line) {
        $trimmed = ltrim($line);
        if (str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
            continue;
        }
        $keep[] = $line;
    }
    $parts = array_map('trim', explode(';', implode("\n", $keep)));

    return array_values(array_filter($parts, static fn (string $s): bool => $s !== ''));
};

$record = $pdo->prepare('INSERT INTO schema_migrations (version, checksum) VALUES (?, ?)');
$pending = 0;

foreach ($files as $file) {
    $version = basename($file);
    $checksum = hash_file('sha256', $file);

    if (isset($applied[$version])) {
        if ($applied[$version] !== $checksum) {
            $out("  [!] {$version} ya estaba aplicada pero el archivo cambió. Se omite.");
        }
        continue;
    }

    $pending++;

    if ($dryRun) {
        $out("  [dry-run] aplicaría {$version}");
        continue;
    }

    $out("  → {$version}");

    try {
        if (str_ends_with($file, '.php')) {
            /** @var callable(PDO):void $migration */
            $migration = require $file;
            if (!is_callable($migration)) {
                throw new RuntimeException('la migración .php debe devolver un callable(PDO)');
            }
            $migration($pdo);
        } else {
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException('no se pudo leer el archivo');
            }
            foreach ($statements($sql) as $stmt) {
                $pdo->exec($stmt);
            }
        }
    } catch (\Throwable $e) {
        // El DDL de MySQL hace auto-commit: si falla a mitad, la migración queda
        // parcialmente aplicada. Se aborta acá para no encadenar más daño.
        fwrite(STDERR, "\nERROR en {$version}: {$e->getMessage()}\n");
        fwrite(STDERR, "La migración NO se registró como aplicada. Revisá el estado de la base antes de reintentar.\n");
        exit(1);
    }

    $record->execute([$version, $checksum]);
}

if ($pending === 0) {
    $out('Base al día: no hay migraciones pendientes.');
} elseif ($dryRun) {
    $out("dry-run: {$pending} migración(es) pendiente(s).");
} else {
    $out("OK · {$pending} migración(es) aplicada(s).");
}
