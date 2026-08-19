<?php

declare(strict_types=1);

namespace Satrak\Domain\Repositories;

use PDO;

/**
 * Jornada laboral de una persona: ventanas semanales (`person_shifts`) y sus
 * excepciones puntuales (`person_shift_exceptions`).
 *
 * La interpretación de las ventanas (huso horario, cruce de medianoche,
 * precedencia de excepciones) vive en {@see \Satrak\Domain\Services\ShiftGuard};
 * acá sólo se lee y escribe.
 */
final class PersonShiftRepository
{
    public function __construct(private PDO $db)
    {
    }

    // -- Ventanas semanales ---------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public function activeForPerson(int $personId, int $companyId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, weekday, start_time, end_time FROM person_shifts
             WHERE person_id = ? AND company_id = ? AND active = 1
             ORDER BY weekday, start_time'
        );
        $stmt->execute([$personId, $companyId]);

        return $stmt->fetchAll();
    }

    /** Todas las ventanas (activas o no) para el ABM. @return array<int,array<string,mixed>> */
    public function allForPerson(int $personId, int $companyId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, weekday, start_time, end_time, active FROM person_shifts
             WHERE person_id = ? AND company_id = ? ORDER BY weekday, start_time'
        );
        $stmt->execute([$personId, $companyId]);

        return $stmt->fetchAll();
    }

    public function addShift(int $companyId, int $personId, int $weekday, string $start, string $end): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO person_shifts (company_id, person_id, weekday, start_time, end_time)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$companyId, $personId, $weekday, $start, $end]);

        return (int) $this->db->lastInsertId();
    }

    /** Borra una ventana, validando que sea de la persona y la empresa. */
    public function deleteShift(int $id, int $personId, int $companyId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM person_shifts WHERE id = ? AND person_id = ? AND company_id = ?'
        );
        $stmt->execute([$id, $personId, $companyId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Reemplaza toda la jornada de una persona de una sola vez (el form manda la
     * grilla completa). En transacción: o queda la nueva o queda la vieja.
     *
     * @param array<int,array{weekday:int,start_time:string,end_time:string}> $windows
     */
    public function replaceShifts(int $companyId, int $personId, array $windows): void
    {
        $this->db->beginTransaction();
        try {
            $del = $this->db->prepare('DELETE FROM person_shifts WHERE person_id = ? AND company_id = ?');
            $del->execute([$personId, $companyId]);

            $ins = $this->db->prepare(
                'INSERT INTO person_shifts (company_id, person_id, weekday, start_time, end_time)
                 VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($windows as $w) {
                $ins->execute([$companyId, $personId, $w['weekday'], $w['start_time'], $w['end_time']]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }
    }

    // -- Excepciones ----------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public function exceptionsForDate(int $personId, int $companyId, string $date): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, kind, start_time, end_time FROM person_shift_exceptions
             WHERE person_id = ? AND company_id = ? AND date = ?'
        );
        $stmt->execute([$personId, $companyId, $date]);

        return $stmt->fetchAll();
    }

    /** Próximas excepciones (para el ABM). @return array<int,array<string,mixed>> */
    public function upcomingExceptions(int $personId, int $companyId, int $limit = 30): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, date, kind, start_time, end_time, note FROM person_shift_exceptions
             WHERE person_id = ? AND company_id = ? AND date >= CURDATE()
             ORDER BY date LIMIT ' . max(1, $limit)
        );
        $stmt->execute([$personId, $companyId]);

        return $stmt->fetchAll();
    }

    /** @param array<string,mixed> $data */
    public function addException(int $companyId, int $personId, array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO person_shift_exceptions (company_id, person_id, date, kind, start_time, end_time, note)
             VALUES (:company_id, :person_id, :date, :kind, :start_time, :end_time, :note)'
        );
        $stmt->execute([
            ':company_id' => $companyId,
            ':person_id'  => $personId,
            ':date'       => $data['date'],
            ':kind'       => $data['kind'],
            ':start_time' => $data['kind'] === 'extra' ? $data['start_time'] : null,
            ':end_time'   => $data['kind'] === 'extra' ? $data['end_time'] : null,
            ':note'       => $data['note'] ?: null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function deleteException(int $id, int $personId, int $companyId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM person_shift_exceptions WHERE id = ? AND person_id = ? AND company_id = ?'
        );
        $stmt->execute([$id, $personId, $companyId]);

        return $stmt->rowCount() > 0;
    }
}
