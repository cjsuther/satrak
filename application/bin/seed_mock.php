<?php
/**
 * Generador de datos mock — versión mínima (Fase 4).
 *
 * Simula el módulo de captura: escribe `positions` y `device_events` para los
 * dispositivos YA creados (de los ABM de la Fase 3), incluyendo secuencias de
 * PIN (`pin_set`/`pin_cleared`) para poder probar la atribución de conductor del
 * procesador (§8). El mock completo (rutas largas, --live, --days) llega en la
 * Fase 9.
 *
 * Escenario por dispositivo:
 *   - CON PIN  : un viaje por cada conductor de su allowlist (cambio de PIN ⇒
 *                viaje nuevo) y un viaje final SIN PIN (queda "no identificado").
 *   - SIN PIN  : dos viajes; el procesador los atribuye al conductor por defecto
 *                (o a NULL si el dispositivo no tiene conductor por defecto).
 *
 * Uso:
 *   php bin/seed_mock.php                 # genera para todos los dispositivos activos
 *   php bin/seed_mock.php --reset         # borra posiciones/eventos/viajes/estado antes
 *   php bin/seed_mock.php --hours-ago=8   # empieza el histórico N horas atrás (default 6)
 */

declare(strict_types=1);

/** @var array $config @var PDO $pdo */
[$config, $pdo] = require __DIR__ . '/bootstrap.php';

$opts     = getopt('', ['reset', 'hours-ago::']);
$reset    = isset($opts['reset']);
$hoursAgo = isset($opts['hours-ago']) ? max(1, (int) $opts['hours-ago']) : 6;

if ($reset) {
    foreach (['positions', 'device_events', 'trips', 'processor_state'] as $t) {
        $pdo->exec("DELETE FROM {$t}");
    }
    $pdo->exec('UPDATE devices SET last_position_id = NULL, last_seen_at = NULL');
    echo "Reset: posiciones, eventos, viajes y estado del procesador borrados.\n";
}

// Punto de partida: zona Neuquén capital.
$baseLat = -38.9516;
$baseLon = -68.0591;

$intervalSec = 30;     // entre posiciones de un viaje
$pointsPerTrip = 10;   // posiciones por viaje
$stopGapSec = ((int) ($config['tracking']['trip_stop_minutes'] ?? 5) + 3) * 60; // fuerza el cierre por hueco

$insPos = $pdo->prepare(
    'INSERT INTO positions (company_id, device_id, ts, lat, lon, speed, heading, ignition, satellites, pin_code)
     VALUES (:company_id, :device_id, :ts, :lat, :lon, :speed, :heading, :ignition, :sat, NULL)'
);
$insEv = $pdo->prepare(
    'INSERT INTO device_events (company_id, device_id, ts, event_type, pin_code)
     VALUES (:company_id, :device_id, :ts, :event_type, :pin_code)'
);

/** PINs de la allowlist activa de un dispositivo con PIN (en orden estable). */
$allowlistPins = static function (int $deviceId, int $companyId) use ($pdo): array {
    $stmt = $pdo->prepare(
        'SELECT dr.pin FROM device_driver_links l
         JOIN drivers dr ON dr.id = l.driver_id
         WHERE l.device_id = ? AND l.company_id = ? AND l.is_default = 0 AND l.unlinked_at IS NULL
           AND dr.pin IS NOT NULL
         ORDER BY l.id'
    );
    $stmt->execute([$deviceId, $companyId]);

    return array_map(static fn ($r) => (string) $r['pin'], $stmt->fetchAll());
};

$devices = $pdo->query("SELECT id, company_id, has_pin FROM devices WHERE status = 'active' ORDER BY id")->fetchAll();

$totalPos = 0;
$totalEv = 0;

foreach ($devices as $device) {
    $deviceId  = (int) $device['id'];
    $companyId = (int) $device['company_id'];
    $hasPin    = (bool) $device['has_pin'];

    // Lista de "PIN por viaje": null = sin PIN (no identificado / o por defecto).
    if ($hasPin) {
        $pins = $allowlistPins($deviceId, $companyId);
        $pins[] = null; // viaje final sin PIN -> no identificado
    } else {
        $pins = [null, null]; // dos viajes; el procesador resuelve por conductor por defecto
    }

    $cursor = strtotime("-{$hoursAgo} hours");
    $lat = $baseLat + ($deviceId * 0.01); // separa visualmente cada dispositivo
    $lon = $baseLon - ($deviceId * 0.01);

    foreach ($pins as $tripIndex => $pin) {
        // Inicio del viaje: PIN (si corresponde) + ignición.
        if ($pin !== null) {
            $insEv->execute([
                ':company_id' => $companyId, ':device_id' => $deviceId,
                ':ts' => date('Y-m-d H:i:s', $cursor), ':event_type' => 'pin_set', ':pin_code' => $pin,
            ]);
            $totalEv++;
        }
        $insEv->execute([
            ':company_id' => $companyId, ':device_id' => $deviceId,
            ':ts' => date('Y-m-d H:i:s', $cursor), ':event_type' => 'ignition_on', ':pin_code' => null,
        ]);
        $totalEv++;

        // Recorrido del viaje (ignición encendida, en movimiento).
        for ($i = 0; $i < $pointsPerTrip; $i++) {
            $speed = mt_rand(28, 95);
            if ($i === intdiv($pointsPerTrip, 2)) {
                $speed = mt_rand(100, 120); // un pico de velocidad (exceso, útil para Fase 6)
            }
            $lat += 0.0018;  // deriva noreste, ~200 m por punto
            $lon += 0.0013;
            $insPos->execute([
                ':company_id' => $companyId, ':device_id' => $deviceId,
                ':ts' => date('Y-m-d H:i:s', $cursor),
                ':lat' => round($lat, 7), ':lon' => round($lon, 7),
                ':speed' => $speed, ':heading' => mt_rand(0, 359),
                ':ignition' => 1, ':sat' => mt_rand(6, 12),
            ]);
            $totalPos++;
            $cursor += $intervalSec;
        }

        // Fin del viaje: detención + ignición apagada + (si había PIN) limpieza.
        $insPos->execute([
            ':company_id' => $companyId, ':device_id' => $deviceId,
            ':ts' => date('Y-m-d H:i:s', $cursor),
            ':lat' => round($lat, 7), ':lon' => round($lon, 7),
            ':speed' => 0, ':heading' => 0, ':ignition' => 0, ':sat' => mt_rand(6, 12),
        ]);
        $totalPos++;
        $insEv->execute([
            ':company_id' => $companyId, ':device_id' => $deviceId,
            ':ts' => date('Y-m-d H:i:s', $cursor), ':event_type' => 'ignition_off', ':pin_code' => null,
        ]);
        $totalEv++;
        if ($pin !== null) {
            $insEv->execute([
                ':company_id' => $companyId, ':device_id' => $deviceId,
                ':ts' => date('Y-m-d H:i:s', $cursor), ':event_type' => 'pin_cleared', ':pin_code' => null,
            ]);
            $totalEv++;
        }

        // Hueco entre viajes (fuerza el cierre por parada en el procesador).
        $cursor += $stopGapSec;
    }
}

printf(
    "Seed OK · %d dispositivos · %d posiciones · %d eventos generados.\n",
    count($devices), $totalPos, $totalEv
);
echo "Ahora corré:  php bin/processor.php\n";
