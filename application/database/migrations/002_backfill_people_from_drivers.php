<?php
/**
 * 002 · Backfill: una `person` por cada `driver` existente.
 *
 * Se hace fila por fila y no en SQL: un UPDATE ... JOIN por nombre podría
 * emparejar dos conductores homónimos sin DNI con la misma persona y violar
 * `uq_driver_person`. Acá cada conductor genera su propia persona.
 *
 * Idempotente: sólo procesa conductores con `person_id IS NULL`.
 *
 * El DNI es UNIQUE por empresa en `people` pero no lo era en `drivers`: si dos
 * conductores comparten DNI, el segundo se crea con DNI NULL y queda anotado en
 * la salida para revisión manual.
 */

declare(strict_types=1);

return function (PDO $pdo): void {
    $drivers = $pdo->query(
        'SELECT id, company_id, first_name, last_name, dni, phone, email, status, created_at
         FROM drivers WHERE person_id IS NULL ORDER BY id'
    )->fetchAll();

    if ($drivers === []) {
        echo "     sin conductores para migrar.\n";

        return;
    }

    $findByDni = $pdo->prepare('SELECT id FROM people WHERE company_id = ? AND dni = ? LIMIT 1');
    $driverOfPerson = $pdo->prepare('SELECT id FROM drivers WHERE person_id = ? LIMIT 1');
    $insert = $pdo->prepare(
        'INSERT INTO people (company_id, first_name, last_name, dni, phone, email, status, created_at)
         VALUES (:company_id, :first_name, :last_name, :dni, :phone, :email, :status, :created_at)'
    );
    $link = $pdo->prepare('UPDATE drivers SET person_id = ? WHERE id = ?');

    $created = 0;
    $reused = 0;
    $warnings = [];

    foreach ($drivers as $d) {
        $companyId = (int) $d['company_id'];
        $dni = $d['dni'] !== null && trim((string) $d['dni']) !== '' ? trim((string) $d['dni']) : null;

        // ¿Ya hay una persona con ese DNI en la empresa?
        $personId = null;
        if ($dni !== null) {
            $findByDni->execute([$companyId, $dni]);
            $row = $findByDni->fetch();
            if ($row !== false) {
                $candidate = (int) $row['id'];
                $driverOfPerson->execute([$candidate]);
                if ($driverOfPerson->fetch() === false) {
                    // Libre: se reutiliza.
                    $personId = $candidate;
                    $reused++;
                } else {
                    // Ya es el perfil de conducción de otro conductor: `person_id`
                    // es UNIQUE, así que este conductor necesita su propia persona.
                    // Se crea sin DNI para no chocar con `uq_person_dni`.
                    $warnings[] = "conductor #{$d['id']} ({$d['last_name']}, {$d['first_name']}) comparte el DNI {$dni} "
                        . "con la persona #{$candidate}: se creó una persona nueva SIN DNI. Revisar a mano.";
                    $dni = null;
                }
            }
        }

        if ($personId === null) {
            $insert->execute([
                ':company_id' => $companyId,
                ':first_name' => $d['first_name'],
                ':last_name'  => $d['last_name'],
                ':dni'        => $dni,
                ':phone'      => $d['phone'],
                ':email'      => $d['email'],
                ':status'     => $d['status'],
                ':created_at' => $d['created_at'],
            ]);
            $personId = (int) $pdo->lastInsertId();
            $created++;
        }

        $link->execute([$personId, (int) $d['id']]);
    }

    echo "     {$created} persona(s) creada(s), {$reused} reutilizada(s) por DNI.\n";
    foreach ($warnings as $w) {
        echo "     · {$w}\n";
    }
};
