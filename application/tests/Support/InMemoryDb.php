<?php

declare(strict_types=1);

namespace Satrak\Tests\Support;

use PDO;

/**
 * Base SQLite en memoria para tests.
 *
 * Los repositorios son `final` y reciben un PDO, así que en vez de simularlos
 * con dobles se les da una base de verdad. Se gana bastante: el SQL de los
 * repositorios queda EJERCITADO, no salteado, y un test que pasa demuestra que
 * la consulta es válida y devuelve lo que el servicio espera.
 *
 * Sólo se crean las tablas que cada test necesita, con los tipos mínimos: no es
 * un espejo del esquema de producción, y no debe usarse para validar el esquema.
 */
final class InMemoryDb
{
    public static function connect(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return $pdo;
    }

    /** Tablas de jornada, para ShiftGuard / PersonShiftRepository. */
    public static function withShiftTables(PDO $pdo): PDO
    {
        $pdo->exec(
            'CREATE TABLE companies (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL DEFAULT "Empresa",
                slug TEXT NOT NULL DEFAULT "empresa",
                timezone TEXT NOT NULL DEFAULT "America/Argentina/Buenos_Aires",
                panic_enabled INTEGER NOT NULL DEFAULT 1,
                modules TEXT NOT NULL DEFAULT "fleet"
            )'
        );
        $pdo->exec(
            'CREATE TABLE person_shifts (
                id INTEGER PRIMARY KEY,
                company_id INTEGER NOT NULL,
                person_id INTEGER NOT NULL,
                weekday INTEGER NOT NULL,
                start_time TEXT NOT NULL,
                end_time TEXT NOT NULL,
                active INTEGER NOT NULL DEFAULT 1
            )'
        );
        $pdo->exec(
            'CREATE TABLE person_shift_exceptions (
                id INTEGER PRIMARY KEY,
                company_id INTEGER NOT NULL,
                person_id INTEGER NOT NULL,
                date TEXT NOT NULL,
                kind TEXT NOT NULL,
                start_time TEXT NULL,
                end_time TEXT NULL
            )'
        );

        return $pdo;
    }

    public static function insertCompany(PDO $pdo, int $id, string $tz): void
    {
        $pdo->prepare('INSERT INTO companies (id, timezone) VALUES (?, ?)')->execute([$id, $tz]);
    }

    public static function insertShift(
        PDO $pdo,
        int $companyId,
        int $personId,
        int $weekday,
        string $from,
        string $to,
        int $active = 1,
    ): void {
        $pdo->prepare(
            'INSERT INTO person_shifts (company_id, person_id, weekday, start_time, end_time, active)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$companyId, $personId, $weekday, $from, $to, $active]);
    }

    public static function insertException(
        PDO $pdo,
        int $companyId,
        int $personId,
        string $date,
        string $kind,
        ?string $from = null,
        ?string $to = null,
    ): void {
        $pdo->prepare(
            'INSERT INTO person_shift_exceptions (company_id, person_id, date, kind, start_time, end_time)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$companyId, $personId, $date, $kind, $from, $to]);
    }
}
