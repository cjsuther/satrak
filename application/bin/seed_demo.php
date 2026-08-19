<?php
/**
 * Seed de DEMO: crea una empresa completa para mostrar el sistema.
 *
 * Arma el grafo de entidades (empresa, usuarios, conductores con PIN, vehículos,
 * dispositivos con/sin PIN, asignaciones device↔vehículo y allowlist/conductor
 * por defecto). NO genera posiciones/viajes/alertas: eso lo hace `seed_mock.php`
 * (tracking) + `processor.php` (atribución), que ya están probados.
 *
 * Flujo de demo recomendado:
 *   php bin/seed_demo.php            # crea la empresa demo + entidades
 *   php bin/seed_mock.php --reset    # genera positions/events + reglas/geocerca
 *   php bin/processor.php            # atribuye por PIN, arma viajes, dispara alertas
 *
 * Idempotente: si la empresa demo ya existe, aborta (usá --fresh para recrearla).
 *
 * Uso:
 *   php bin/seed_demo.php
 *   php bin/seed_demo.php --fresh    # borra la empresa demo y la vuelve a crear
 *   php bin/seed_demo.php --pass=Otra1234   # contraseña de los usuarios demo
 */

declare(strict_types=1);

/** @var array $config @var PDO $pdo */
[$config, $pdo] = require __DIR__ . '/bootstrap.php';

$opts  = getopt('', ['fresh', 'pass::']);
$fresh = isset($opts['fresh']);
$pass  = isset($opts['pass']) ? (string) $opts['pass'] : 'Demo.1234';

$slug = 'transportes-comahue';

// ¿Ya existe?
$stmt = $pdo->prepare('SELECT id FROM companies WHERE slug = ? LIMIT 1');
$stmt->execute([$slug]);
$existingId = $stmt->fetchColumn();

if ($existingId !== false) {
    if (!$fresh) {
        fwrite(STDERR, "La empresa demo ya existe (id {$existingId}). Usá --fresh para recrearla.\n");
        exit(1);
    }
    wipeCompany($pdo, (int) $existingId);
    echo "Empresa demo previa (id {$existingId}) eliminada.\n";
}

$algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
$hash = password_hash($pass, $algo);

$pdo->beginTransaction();
try {
    // ---- Empresa ----------------------------------------------------------
    $pdo->prepare(
        "INSERT INTO companies (name, slug, status, device_quota, timezone)
         VALUES ('Transportes del Comahue', ?, 'active', 10, 'America/Argentina/Buenos_Aires')"
    )->execute([$slug]);
    $companyId = (int) $pdo->lastInsertId();

    // ---- Conductores (con PIN único por empresa) --------------------------
    $insDriver = $pdo->prepare(
        'INSERT INTO drivers (company_id, first_name, last_name, dni, license_number, phone, email, pin, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, "active")'
    );
    $driversData = [
        ['Ana', 'Gómez',  '28111222', 'B1122334', '299-4100001', 'ana@comahue.demo',   '1234'],
        ['Beto', 'Díaz',  '30222333', 'B2233445', '299-4100002', 'beto@comahue.demo',  '5678'],
        ['Carla', 'Ruiz', '32333444', 'B3344556', '299-4100003', 'carla@comahue.demo', '4321'],
        ['Diego', 'Pérez','27444555', 'B4455667', '299-4100004', 'diego@comahue.demo', '8765'],
        ['Elena', 'Soto', '33555666', 'B5566778', '299-4100005', 'elena@comahue.demo', '2468'],
    ];
    $driverIds = [];
    foreach ($driversData as $d) {
        $insDriver->execute([$companyId, $d[0], $d[1], $d[2], $d[3], $d[4], $d[5], $d[6]]);
        $driverIds[$d[0]] = (int) $pdo->lastInsertId();
    }

    // ---- Usuarios (admin / operador / conductor) --------------------------
    $insUser = $pdo->prepare(
        'INSERT INTO users (company_id, driver_id, name, email, password_hash, role, status)
         VALUES (?, ?, ?, ?, ?, ?, "active")'
    );
    $insUser->execute([$companyId, null, 'Admin Comahue', 'admin@comahue.demo', $hash, 'company_admin']);
    $insUser->execute([$companyId, null, 'Operador Comahue', 'operador@comahue.demo', $hash, 'operator']);
    // El usuario conductor ve sólo lo de Ana (portal del conductor).
    $insUser->execute([$companyId, $driverIds['Ana'], 'Ana Gómez', 'conductor@comahue.demo', $hash, 'driver']);

    // ---- Vehículos (patentes AR, mezcla de formatos) ----------------------
    $insVeh = $pdo->prepare(
        'INSERT INTO vehicles (company_id, plate, brand, model, year, type, color, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, "active")'
    );
    $vehData = [
        ['AE842JK', 'Toyota',     'Hilux',    2022, 'camion',     'Blanco'],
        ['AD201LM', 'Ford',       'Ranger',   2021, 'utilitario', 'Gris'],
        ['NQA334',  'Volkswagen', 'Amarok',   2019, 'camion',     'Negro'],
        ['AF558PR', 'Renault',    'Kangoo',   2023, 'utilitario', 'Blanco'],
        ['AC889TS', 'Chevrolet',  'S10',      2020, 'camion',     'Rojo'],
    ];
    $vehIds = [];
    foreach ($vehData as $v) {
        $insVeh->execute([$companyId, $v[0], $v[1], $v[2], $v[3], $v[4], $v[5]]);
        $vehIds[] = (int) $pdo->lastInsertId();
    }

    // ---- Dispositivos (con y sin PIN) -------------------------------------
    $insDev = $pdo->prepare(
        'INSERT INTO devices (company_id, imei, label, model, has_pin, status)
         VALUES (?, ?, ?, ?, ?, "active")'
    );
    // [label, imei, has_pin]
    $devData = [
        ['Equipo Hilux',  '350000000000001', 1],
        ['Equipo Ranger', '350000000000002', 1],
        ['Equipo Amarok', '350000000000003', 0],
        ['Equipo Kangoo', '350000000000004', 0],
        ['Equipo S10',    '350000000000005', 1],
    ];
    $devIds = [];
    foreach ($devData as $d) {
        $insDev->execute([$companyId, $d[1], $d[0], 'GT06', $d[2]]);
        $devIds[] = (int) $pdo->lastInsertId();
    }

    // ---- Asignaciones device ↔ vehículo (1:1 activa) ----------------------
    $insAssign = $pdo->prepare(
        'INSERT INTO device_vehicle_assignments (company_id, device_id, vehicle_id) VALUES (?, ?, ?)'
    );
    foreach ($devIds as $i => $devId) {
        $insAssign->execute([$companyId, $devId, $vehIds[$i]]);
    }

    // ---- Vínculos device ↔ conductor --------------------------------------
    // Equipos CON PIN: allowlist (is_default=0). SIN PIN: conductor por defecto (1).
    $insLink = $pdo->prepare(
        'INSERT INTO device_driver_links (company_id, device_id, driver_id, is_default) VALUES (?, ?, ?, ?)'
    );
    // dev0 (Hilux, PIN): Ana + Beto
    $insLink->execute([$companyId, $devIds[0], $driverIds['Ana'], 0]);
    $insLink->execute([$companyId, $devIds[0], $driverIds['Beto'], 0]);
    // dev1 (Ranger, PIN): Carla + Diego
    $insLink->execute([$companyId, $devIds[1], $driverIds['Carla'], 0]);
    $insLink->execute([$companyId, $devIds[1], $driverIds['Diego'], 0]);
    // dev2 (Amarok, sin PIN): default Elena
    $insLink->execute([$companyId, $devIds[2], $driverIds['Elena'], 1]);
    // dev3 (Kangoo, sin PIN): default Diego
    $insLink->execute([$companyId, $devIds[3], $driverIds['Diego'], 1]);
    // dev4 (S10, PIN): Ana + Carla
    $insLink->execute([$companyId, $devIds[4], $driverIds['Ana'], 0]);
    $insLink->execute([$companyId, $devIds[4], $driverIds['Carla'], 0]);

    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "ERROR creando la empresa demo: {$e->getMessage()}\n");
    exit(1);
}

echo "Empresa demo creada (id {$companyId}): Transportes del Comahue\n";
echo "  · 5 conductores (PINs 1234/5678/4321/8765/2468), 5 vehículos, 5 dispositivos (3 con PIN, 2 sin PIN)\n";
echo "  · Usuarios (contraseña: {$pass}):\n";
echo "      admin@comahue.demo      (Admin de empresa)\n";
echo "      operador@comahue.demo   (Operador / monitoreo)\n";
echo "      conductor@comahue.demo  (Portal del conductor — Ana)\n";
echo "\nAhora generá el tracking:\n";
echo "  php bin/seed_mock.php --reset && php bin/processor.php\n";

/**
 * Borra todos los datos de una empresa (para --fresh). Hijos primero.
 */
function wipeCompany(PDO $pdo, int $companyId): void
{
    $pdo->beginTransaction();
    try {
        // Dependientes de dispositivos / geocercas.
        $pdo->prepare('DELETE FROM geofence_targets WHERE geofence_id IN (SELECT id FROM geofences WHERE company_id = ?)')->execute([$companyId]);
        foreach ([
            'positions', 'device_events', 'trips', 'processor_state', 'notifications', 'alerts',
            'alert_rules', 'geofences', 'device_driver_links', 'device_vehicle_assignments',
            'devices', 'vehicles', 'drivers', 'users',
        ] as $t) {
            $pdo->prepare("DELETE FROM {$t} WHERE company_id = ?")->execute([$companyId]);
        }
        $pdo->prepare('DELETE FROM companies WHERE id = ?')->execute([$companyId]);
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
