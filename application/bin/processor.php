<?php
/**
 * Procesador de Satrak (spec §12) — núcleo del sistema.
 *
 * Corre por cron CLI cada 1 minuto. Es idempotente: procesa sólo las posiciones
 * y eventos nuevos de cada dispositivo (cursor en `processor_state`).
 *
 * En esta fase (4) hace:
 *   1. Resolver `driver_id` de cada posición nueva manteniendo el PIN vigente (§8).
 *   2. Construir / cerrar viajes (`trips`); un cambio de PIN parte el viaje.
 *   3. Actualizar `devices.last_position_id` / `last_seen_at`.
 *
 * (El motor de alertas se suma en la Fase 6.)
 *
 * Uso:
 *   php bin/processor.php            # procesa todo lo pendiente
 *   php bin/processor.php --quiet    # sin salida por dispositivo
 */

declare(strict_types=1);

use Satrak\Domain\Repositories\AssignmentRepository;
use Satrak\Domain\Repositories\DeviceDriverLinkRepository;
use Satrak\Domain\Repositories\DeviceEventRepository;
use Satrak\Domain\Repositories\DeviceRepository;
use Satrak\Domain\Repositories\DriverRepository;
use Satrak\Domain\Repositories\PositionRepository;
use Satrak\Domain\Repositories\ProcessorStateRepository;
use Satrak\Domain\Repositories\TripRepository;
use Satrak\Domain\Services\PinResolver;
use Satrak\Domain\Services\TripBuilder;

/** @var array $config @var PDO $pdo */
[$config, $pdo] = require __DIR__ . '/bootstrap.php';

$opts  = getopt('', ['quiet']);
$quiet = isset($opts['quiet']);

$stopSeconds = (int) ($config['tracking']['trip_stop_minutes'] ?? 5) * 60;

// --- Cableado de repositorios y servicios (sin contenedor: CLI con PDO directo) ---
$deviceRepo   = new DeviceRepository($pdo);
$driverRepo   = new DriverRepository($pdo);
$linkRepo     = new DeviceDriverLinkRepository($pdo);
$positionRepo = new PositionRepository($pdo);
$eventRepo    = new DeviceEventRepository($pdo);
$tripRepo     = new TripRepository($pdo);
$stateRepo    = new ProcessorStateRepository($pdo);
$assignRepo   = new AssignmentRepository($pdo);

$resolver = new PinResolver($driverRepo, $linkRepo);
$builder  = new TripBuilder(
    $positionRepo,
    $eventRepo,
    $tripRepo,
    $stateRepo,
    $deviceRepo,
    $assignRepo,
    $resolver,
    $stopSeconds,
);

$started = microtime(true);
$devices = $deviceRepo->allActive();

$totals = ['positions' => 0, 'events' => 0, 'trips_opened' => 0, 'trips_closed' => 0];

foreach ($devices as $device) {
    $deviceId = (int) $device['id'];

    // Una transacción por dispositivo: aísla fallas y mantiene la idempotencia.
    $pdo->beginTransaction();
    try {
        $s = $builder->processDevice($device);
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "ERROR dispositivo {$deviceId}: {$e->getMessage()}\n");
        continue;
    }

    foreach ($totals as $k => $_) {
        $totals[$k] += $s[$k];
    }

    if (!$quiet && ($s['positions'] || $s['events'] || $s['trips_opened'] || $s['trips_closed'])) {
        printf(
            "  dispositivo %-4d · pos %-4d · ev %-3d · viajes +%d/-%d\n",
            $deviceId, $s['positions'], $s['events'], $s['trips_opened'], $s['trips_closed']
        );
    }
}

$ms = (int) round((microtime(true) - $started) * 1000);
printf(
    "Procesador OK · %d dispositivos · %d posiciones · %d eventos · viajes +%d/-%d · %d ms\n",
    count($devices), $totals['positions'], $totals['events'],
    $totals['trips_opened'], $totals['trips_closed'], $ms
);
