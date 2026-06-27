<?php
/**
 * Purga de retención (spec §12). Cron diario.
 *
 * Borra `positions` y `device_events` con más de `retention_months` meses
 * (configurable). Conserva `trips` y `alerts` agregados (datos de negocio).
 * Idempotente y acotado: borra en lotes para no bloquear la base.
 *
 * Uso:
 *   php bin/purge.php            # purga según config
 *   php bin/purge.php --months=6 # override de retención
 *   php bin/purge.php --dry-run  # sólo informa cuánto borraría
 */

declare(strict_types=1);

/** @var array $config @var PDO $pdo */
[$config, $pdo] = require __DIR__ . '/bootstrap.php';

$opts   = getopt('', ['months::', 'dry-run']);
$months = isset($opts['months']) ? max(1, (int) $opts['months']) : (int) ($config['tracking']['retention_months'] ?? 12);
$dryRun = isset($opts['dry-run']);

$cutoff = date('Y-m-d H:i:s', strtotime("-{$months} months"));
echo "Purga: borrando datos crudos anteriores a {$cutoff} (retención {$months} meses)" . ($dryRun ? ' [DRY-RUN]' : '') . "\n";

$tables = [
    'positions'     => 'ts',
    'device_events' => 'ts',
];

$total = 0;
foreach ($tables as $table => $col) {
    // Conteo de candidatos.
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$col} < ?");
    $cnt->execute([$cutoff]);
    $n = (int) $cnt->fetchColumn();

    if ($dryRun) {
        echo "  {$table}: {$n} filas a borrar\n";
        $total += $n;
        continue;
    }

    // Borrado en lotes (evita transacciones gigantes / locks largos).
    $deleted = 0;
    $stmt = $pdo->prepare("DELETE FROM {$table} WHERE {$col} < ? LIMIT 5000");
    do {
        $stmt->execute([$cutoff]);
        $rows = $stmt->rowCount();
        $deleted += $rows;
    } while ($rows > 0);

    echo "  {$table}: {$deleted} filas borradas\n";
    $total += $deleted;
}

echo ($dryRun ? "Total estimado: " : "Total borrado: ") . "{$total} filas\n";
