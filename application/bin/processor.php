<?php
/**
 * Procesador de Satrak (spec §12) — núcleo del sistema.
 *
 * Corre por cron CLI cada 1 minuto. Es idempotente: procesa sólo las posiciones
 * y eventos nuevos de cada dispositivo (cursor en `processor_state`).
 *
 * Hace:
 *   1. Resolver `driver_id` de cada posición nueva manteniendo el PIN vigente (§8).
 *   2. Construir / cerrar viajes (`trips`); un cambio de PIN parte el viaje.
 *   3. Actualizar `devices.last_position_id` / `last_seen_at`.
 *   4. Motor de alertas (§12) sobre posiciones y eventos.
 *   5. Módulo de personas: recorridos y alertas de personal (fuera de puesto,
 *      sin movimiento, pánico) y cierre de misiones vencidas.
 *
 * Uso:
 *   php bin/processor.php            # procesa todo lo pendiente
 *   php bin/processor.php --quiet    # sin salida por dispositivo
 */

declare(strict_types=1);

use Satrak\Domain\Repositories\AlertRepository;
use Satrak\Domain\Repositories\AlertRuleRepository;
use Satrak\Domain\Repositories\AssignmentRepository;
use Satrak\Domain\Repositories\DeviceDriverLinkRepository;
use Satrak\Domain\Repositories\CompanyRepository;
use Satrak\Domain\Repositories\DeviceEventRepository;
use Satrak\Domain\Repositories\DevicePersonAssignmentRepository;
use Satrak\Domain\Repositories\DeviceRepository;
use Satrak\Domain\Repositories\DriverRepository;
use Satrak\Domain\Repositories\GeofenceRepository;
use Satrak\Domain\Repositories\MissionRepository;
use Satrak\Domain\Repositories\NotificationRepository;
use Satrak\Domain\Repositories\PersonPostRepository;
use Satrak\Domain\Repositories\PersonRepository;
use Satrak\Domain\Repositories\PositionRepository;
use Satrak\Domain\Repositories\ProcessorStateRepository;
use Satrak\Domain\Repositories\TripRepository;
use Satrak\Domain\Repositories\UserRepository;
use Satrak\Domain\Repositories\VehicleRepository;
use Satrak\Domain\Services\AlertEngine;
use Satrak\Domain\Services\Mailer;
use Satrak\Domain\Services\PinResolver;
use Satrak\Domain\Services\TripBuilder;

/** @var array $config @var PDO $pdo */
[$config, $pdo] = require __DIR__ . '/bootstrap.php';

$opts  = getopt('', ['quiet']);
$quiet = isset($opts['quiet']);

$stopSeconds = (int) ($config['tracking']['trip_stop_minutes'] ?? 5) * 60;
$people      = $config['people'] ?? [];

// --- Cableado de repositorios y servicios (sin contenedor: CLI con PDO directo) ---
$deviceRepo   = new DeviceRepository($pdo);
$driverRepo   = new DriverRepository($pdo);
$linkRepo     = new DeviceDriverLinkRepository($pdo);
$positionRepo = new PositionRepository($pdo);
$eventRepo    = new DeviceEventRepository($pdo);
$tripRepo     = new TripRepository($pdo);
$stateRepo    = new ProcessorStateRepository($pdo);
$assignRepo   = new AssignmentRepository($pdo);
$personAssign = new DevicePersonAssignmentRepository($pdo);
$postRepo     = new PersonPostRepository($pdo);
$missionRepo  = new MissionRepository($pdo);

$resolver = new PinResolver($driverRepo, $linkRepo);

// Motor de alertas (§12): reglas configurables -> alerts + notifications + email.
$alertEngine = new AlertEngine(
    new AlertRuleRepository($pdo),
    new GeofenceRepository($pdo),
    new AlertRepository($pdo),
    new NotificationRepository($pdo),
    new UserRepository($pdo),
    new VehicleRepository($pdo),
    new Mailer($config['smtp'], dirname(__DIR__) . '/storage/logs'),
    (int) ($config['tracking']['offline_minutes'] ?? 30),
    (int) ($config['tracking']['idle_minutes'] ?? 10),
    new PersonRepository($pdo),
    new CompanyRepository($pdo),
    (int) ($people['no_movement_minutes'] ?? 15),
    (int) ($people['min_step_m'] ?? 25),
    (int) ($people['app_offline_minutes'] ?? 15),
);

$builder = new TripBuilder(
    $positionRepo,
    $eventRepo,
    $tripRepo,
    $stateRepo,
    $deviceRepo,
    $assignRepo,
    $resolver,
    $stopSeconds,
    $alertEngine,
    $personAssign,
    $postRepo,
    $missionRepo,
    (int) ($people['person_stop_minutes'] ?? 10) * 60,
    (int) ($people['walk_speed_kmh'] ?? 2),
    (int) ($people['min_step_m'] ?? 25),
    (int) ($people['max_accuracy_m'] ?? 100),
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

// --- Chequeo periódico de offline (no depende de posiciones nuevas) ---------
// Flota: "el equipo se cayó". Personal: "la app dejó de reportar". Son alertas
// distintas porque el destinatario hace cosas distintas con cada una.
$offlineRows = $pdo->query(
    "SELECT d.id, d.company_id, d.kind, d.last_seen_at,
            (SELECT a.vehicle_id FROM device_vehicle_assignments a
             WHERE a.device_id = d.id AND a.unassigned_at IS NULL LIMIT 1) AS vehicle_id,
            (SELECT a.person_id FROM device_person_assignments a
             WHERE a.device_id = d.id AND a.unassigned_at IS NULL LIMIT 1) AS person_id
     FROM devices d WHERE d.status = 'active'"
)->fetchAll();

foreach ($offlineRows as $d) {
    $pdo->beginTransaction();
    try {
        if (($d['kind'] ?? 'vehicle') === 'person') {
            if ($d['person_id'] !== null) {
                $alertEngine->checkAppOffline([
                    'company_id'   => (int) $d['company_id'],
                    'device_id'    => (int) $d['id'],
                    'person_id'    => (int) $d['person_id'],
                    'last_seen_at' => $d['last_seen_at'],
                ]);
            }
        } else {
            $alertEngine->checkOffline([
                'id'           => (int) $d['id'],
                'company_id'   => (int) $d['company_id'],
                'last_seen_at' => $d['last_seen_at'],
                'vehicle_id'   => $d['vehicle_id'] !== null ? (int) $d['vehicle_id'] : null,
            ]);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "ERROR offline dispositivo {$d['id']}: {$e->getMessage()}\n");
    }
}

// --- Misiones vencidas -------------------------------------------------------
// `pending` que nunca arrancó o `in_progress` que no llegó: se avisa y se cierran
// como no cumplidas para que no queden vivas para siempre.
$missionsClosed = 0;
foreach ($missionRepo->overdue() as $mission) {
    $pdo->beginTransaction();
    try {
        $newStatus = $alertEngine->checkMission([
            'id'             => (int) $mission['id'],
            'company_id'     => (int) $mission['company_id'],
            'person_id'      => (int) $mission['person_id'],
            'status'         => (string) $mission['status'],
            'scheduled_end'  => (string) $mission['scheduled_end'],
        ]);
        $missionRepo->setStatus((int) $mission['id'], $newStatus);
        $missionsClosed++;
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "ERROR misión {$mission['id']}: {$e->getMessage()}\n");
    }
}

$ms = (int) round((microtime(true) - $started) * 1000);
printf(
    "Procesador OK · %d dispositivos · %d posiciones · %d eventos · viajes +%d/-%d · "
    . "%d misiones vencidas · %d alertas · %d ms\n",
    count($devices), $totals['positions'], $totals['events'],
    $totals['trips_opened'], $totals['trips_closed'], $missionsClosed,
    $alertEngine->firedCount(), $ms
);
