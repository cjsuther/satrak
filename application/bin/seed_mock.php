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

$opts     = getopt('', ['reset', 'hours-ago::', 'live', 'ticks::', 'every::']);
$reset    = isset($opts['reset']);
$hoursAgo = isset($opts['hours-ago']) ? max(1, (int) $opts['hours-ago']) : 6;
$live     = isset($opts['live']);
$ticks    = isset($opts['ticks']) ? max(0, (int) $opts['ticks']) : 0;  // 0 = indefinido
$everySec = isset($opts['every']) ? max(1, (int) $opts['every']) : 5;

if ($reset) {
    foreach ([
        'positions', 'device_events', 'trips', 'processor_state',
        'notifications', 'alerts', 'alert_rules', 'geofence_targets', 'geofences',
    ] as $t) {
        $pdo->exec("DELETE FROM {$t}");
    }
    $pdo->exec('UPDATE devices SET last_position_id = NULL, last_seen_at = NULL');
    echo "Reset: posiciones, eventos, viajes, alertas, notificaciones y geocercas borrados.\n";
}

// Punto de partida: zona Neuquén capital.
$baseLat = -38.9516;
$baseLon = -68.0591;

// -- Modo vivo: agrega una posición por dispositivo cada N segundos y corre el
//    procesador, para demostrar el mapa en vivo (§10). Cortar con Ctrl-C.
if ($live) {
    $devices = $pdo->query("SELECT id, company_id, has_pin FROM devices WHERE status = 'active' ORDER BY id")->fetchAll();

    // PIN inicial para dispositivos con PIN, para que la atribución muestre conductor.
    $firstPin = static function (int $deviceId, int $companyId) use ($pdo): ?string {
        $stmt = $pdo->prepare(
            'SELECT dr.pin FROM device_driver_links l JOIN drivers dr ON dr.id = l.driver_id
             WHERE l.device_id = ? AND l.company_id = ? AND l.is_default = 0 AND l.unlinked_at IS NULL
               AND dr.pin IS NOT NULL ORDER BY l.id LIMIT 1'
        );
        $stmt->execute([$deviceId, $companyId]);
        $v = $stmt->fetchColumn();

        return $v !== false ? (string) $v : null;
    };

    // Estado de simulación por dispositivo (arranca desde su última posición o de la base).
    $sim = [];
    foreach ($devices as $i => $d) {
        $did = (int) $d['id'];
        $last = $pdo->prepare('SELECT lat, lon FROM positions WHERE device_id = ? ORDER BY id DESC LIMIT 1');
        $last->execute([$did]);
        $row = $last->fetch();
        $sim[$did] = [
            'lat' => $row ? (float) $row['lat'] : $baseLat + $i * 0.01,
            'lon' => $row ? (float) $row['lon'] : $baseLon - $i * 0.01,
        ];
        if ((bool) $d['has_pin'] && ($pin = $firstPin($did, (int) $d['company_id'])) !== null) {
            $ev = $pdo->prepare('INSERT INTO device_events (company_id, device_id, ts, event_type, pin_code) VALUES (?,?,?,?,?)');
            $ev->execute([(int) $d['company_id'], $did, date('Y-m-d H:i:s'), 'pin_set', $pin]);
        }
    }

    $insLive = $pdo->prepare(
        'INSERT INTO positions (company_id, device_id, ts, lat, lon, speed, heading, ignition, satellites)
         VALUES (?,?,?,?,?,?,?,1,?)'
    );

    $processor = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/processor.php') . ' --quiet';

    echo "Modo vivo: 1 posición por dispositivo cada {$everySec}s. Ctrl-C para cortar.\n";
    $n = 0;
    while ($ticks === 0 || $n < $ticks) {
        $now = date('Y-m-d H:i:s');
        foreach ($devices as $d) {
            $did = (int) $d['id'];
            $sim[$did]['lat'] += 0.0009 + mt_rand(0, 6) / 10000;
            $sim[$did]['lon'] += 0.0007 + mt_rand(0, 6) / 10000;
            $insLive->execute([
                (int) $d['company_id'], $did, $now,
                round($sim[$did]['lat'], 7), round($sim[$did]['lon'], 7),
                mt_rand(25, 90), mt_rand(0, 359), mt_rand(6, 12),
            ]);
        }
        exec($processor);
        $n++;
        echo "  tick {$n} · " . count($devices) . " posiciones @ {$now}\n";
        if ($ticks !== 0 && $n >= $ticks) {
            break;
        }
        sleep($everySec);
    }
    echo "Live OK · {$n} ticks generados y procesados.\n";

    return;
}

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

// -- Episodio de ralentí + SOS para el primer dispositivo (dispara idle y sos).
$first = $devices[0] ?? null;
if ($first !== null) {
    $did = (int) $first['id'];
    $cid = (int) $first['company_id'];
    $iLat = $baseLat + $did * 0.01 + 0.02;
    $iLon = $baseLon - $did * 0.01 + 0.02;
    $t = strtotime('-90 minutes');

    $insEv->execute([':company_id' => $cid, ':device_id' => $did, ':ts' => date('Y-m-d H:i:s', $t),
        ':event_type' => 'ignition_on', ':pin_code' => null]);
    $totalEv++;
    // 14 puntos detenidos con motor encendido, 1/min -> 14 min > umbral de ralentí (10).
    for ($k = 0; $k < 14; $k++) {
        $insPos->execute([':company_id' => $cid, ':device_id' => $did, ':ts' => date('Y-m-d H:i:s', $t),
            ':lat' => round($iLat, 7), ':lon' => round($iLon, 7),
            ':speed' => 0, ':heading' => 0, ':ignition' => 1, ':sat' => mt_rand(6, 12)]);
        $totalPos++;
        $t += 60;
    }
    // SOS en medio del ralentí.
    $insEv->execute([':company_id' => $cid, ':device_id' => $did, ':ts' => date('Y-m-d H:i:s', strtotime('-85 minutes')),
        ':event_type' => 'sos', ':pin_code' => null]);
    $totalEv++;
    $insEv->execute([':company_id' => $cid, ':device_id' => $did, ':ts' => date('Y-m-d H:i:s', $t),
        ':event_type' => 'ignition_off', ':pin_code' => null]);
    $totalEv++;
}

// -- Configuración de alertas demo por empresa (geocerca + reglas), idempotente.
$companies = array_values(array_unique(array_map(static fn ($d) => (int) $d['company_id'], $devices)));
$rulesSeeded = 0;
foreach ($companies as $cid) {
    $has = $pdo->prepare('SELECT COUNT(*) FROM alert_rules WHERE company_id = ?');
    $has->execute([$cid]);
    if ((int) $has->fetchColumn() > 0) {
        continue; // ya tiene reglas: no piso configuración existente.
    }

    // Geocerca circular cerca del arranque del primer dispositivo de la empresa.
    $dev1 = null;
    foreach ($devices as $d) {
        if ((int) $d['company_id'] === $cid) { $dev1 = (int) $d['id']; break; }
    }
    $gLat = $baseLat + ($dev1 ?? 1) * 0.01 + 0.002;
    $gLon = $baseLon - ($dev1 ?? 1) * 0.01 + 0.0015;
    $geom = json_encode(['lat' => round($gLat, 7), 'lon' => round($gLon, 7), 'radius_m' => 600]);
    $pdo->prepare('INSERT INTO geofences (company_id, name, shape, geometry, active) VALUES (?,?,?,?,1)')
        ->execute([$cid, 'Base Neuquén', 'circle', $geom]);
    $gid = (int) $pdo->lastInsertId();

    $insRule = $pdo->prepare(
        'INSERT INTO alert_rules (company_id, type, params, channels, recipients, active) VALUES (?,?,?,?,NULL,1)'
    );
    $insRule->execute([$cid, 'speed', json_encode(['max_kmh' => 90]), json_encode(['inapp', 'email'])]);
    $insRule->execute([$cid, 'sos', null, json_encode(['inapp', 'email'])]);
    $insRule->execute([$cid, 'idle', json_encode(['minutes' => 10]), json_encode(['inapp'])]);
    $insRule->execute([$cid, 'offline', json_encode(['minutes' => 30]), json_encode(['inapp'])]);
    $insRule->execute([$cid, 'geofence_enter', json_encode(['geofence_id' => $gid]), json_encode(['inapp'])]);
    $insRule->execute([$cid, 'geofence_exit', json_encode(['geofence_id' => $gid]), json_encode(['inapp'])]);
    $rulesSeeded++;
}

printf(
    "Seed OK · %d dispositivos · %d posiciones · %d eventos · config de alertas para %d empresa(s).\n",
    count($devices), $totalPos, $totalEv, $rulesSeeded
);
echo "Ahora corré:  php bin/processor.php\n";
