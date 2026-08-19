<?php

declare(strict_types=1);

namespace Satrak\Domain\Services;

use DateTimeImmutable;
use DateTimeZone;
use Satrak\Domain\Repositories\CompanyRepository;
use Satrak\Domain\Repositories\PersonShiftRepository;

/**
 * Resuelve si un instante cae dentro de la jornada laboral de una persona.
 *
 * Es la pieza que hace cumplir la regla legal del módulo: **fuera de la jornada
 * no se rastrea**. Se aplica en dos capas — la app no captura fuera de turno y
 * la API de ingesta descarta lo que igual llegue.
 *
 * Reglas (spec §4.2):
 *  - La jornada son ventanas semanales (`person_shifts`) en el huso de la EMPRESA,
 *    no del servidor: una empresa en otra provincia no debe correrse de horario.
 *  - Una ventana con `end_time <= start_time` cruza la medianoche (ej. 22:00–06:00):
 *    se evalúa también contra el día anterior.
 *  - `person_shift_exceptions` pisa lo semanal: `off` anula el día completo,
 *    `extra` agrega una ventana puntual (y gana sobre un `off` del mismo día).
 *
 * Cachea por persona: el procesador y la ingesta lo llaman en ciclos cerrados.
 */
final class ShiftGuard
{
    /** @var array<int,array<int,array<string,mixed>>> shifts por persona */
    private array $shiftCache = [];
    /** @var array<int,array<string,array<int,array<string,mixed>>>> excepciones por persona y fecha */
    private array $exceptionCache = [];
    /** @var array<int,DateTimeZone> huso por empresa */
    private array $tzCache = [];

    public function __construct(
        private PersonShiftRepository $shifts,
        private CompanyRepository $companies,
    ) {
    }

    /**
     * ¿El instante cae dentro de la jornada de la persona?
     *
     * @param string $ts DATETIME 'Y-m-d H:i:s' en el huso de la empresa
     */
    public function isWithinShift(int $personId, int $companyId, string $ts): bool
    {
        $tz = $this->timezone($companyId);
        $moment = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $ts, $tz);
        if ($moment === false) {
            $moment = new DateTimeImmutable($ts, $tz);
        }

        // Se evalúa el día del instante y el anterior: una ventana nocturna
        // (22:00–06:00) que arrancó ayer todavía cubre las 02:00 de hoy.
        foreach ([0, -1] as $dayOffset) {
            $day = $moment->modify(($dayOffset === 0 ? '+0' : $dayOffset) . ' day');
            if ($this->dayCovers($personId, $companyId, $day, $moment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿Alguna ventana que ARRANCA en `$day` cubre a `$moment`?
     */
    private function dayCovers(int $personId, int $companyId, DateTimeImmutable $day, DateTimeImmutable $moment): bool
    {
        $dateKey = $day->format('Y-m-d');
        $exceptions = $this->exceptionsFor($personId, $companyId, $dateKey);

        // 'extra' gana sobre todo: si una ventana extra cubre el instante, hay jornada.
        foreach ($exceptions as $exc) {
            if ($exc['kind'] === 'extra'
                && $exc['start_time'] !== null
                && $exc['end_time'] !== null
                && $this->windowCovers($day, (string) $exc['start_time'], (string) $exc['end_time'], $moment)) {
                return true;
            }
        }

        // 'off' anula las ventanas semanales de ese día.
        foreach ($exceptions as $exc) {
            if ($exc['kind'] === 'off') {
                return false;
            }
        }

        $weekday = (int) $day->format('N');   // 1=lunes .. 7=domingo
        foreach ($this->shiftsFor($personId, $companyId) as $shift) {
            if ((int) $shift['weekday'] !== $weekday) {
                continue;
            }
            if ($this->windowCovers($day, (string) $shift['start_time'], (string) $shift['end_time'], $moment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿La ventana [start, end) que arranca en `$day` contiene a `$moment`?
     * Si `end <= start`, la ventana cruza la medianoche y termina al día siguiente.
     */
    private function windowCovers(DateTimeImmutable $day, string $start, string $end, DateTimeImmutable $moment): bool
    {
        $from = $this->at($day, $start);
        $to = $this->at($day, $end);
        if ($to <= $from) {
            $to = $to->modify('+1 day');
        }

        return $moment >= $from && $moment < $to;
    }

    private function at(DateTimeImmutable $day, string $time): DateTimeImmutable
    {
        [$h, $m, $s] = array_pad(array_map('intval', explode(':', $time)), 3, 0);

        return $day->setTime($h, $m, $s);
    }

    /** @return array<int,array<string,mixed>> */
    private function shiftsFor(int $personId, int $companyId): array
    {
        return $this->shiftCache[$personId] ??= $this->shifts->activeForPerson($personId, $companyId);
    }

    /** @return array<int,array<string,mixed>> */
    private function exceptionsFor(int $personId, int $companyId, string $date): array
    {
        if (!isset($this->exceptionCache[$personId][$date])) {
            $this->exceptionCache[$personId][$date] =
                $this->shifts->exceptionsForDate($personId, $companyId, $date);
        }

        return $this->exceptionCache[$personId][$date];
    }

    private function timezone(int $companyId): DateTimeZone
    {
        if (!isset($this->tzCache[$companyId])) {
            $company = $this->companies->find($companyId);
            $name = (string) ($company['timezone'] ?? 'America/Argentina/Buenos_Aires');
            try {
                $this->tzCache[$companyId] = new DateTimeZone($name);
            } catch (\Exception) {
                $this->tzCache[$companyId] = new DateTimeZone('America/Argentina/Buenos_Aires');
            }
        }

        return $this->tzCache[$companyId];
    }

    /** Limpia las cachés (para procesos largos que ven cambios de configuración). */
    public function flush(): void
    {
        $this->shiftCache = [];
        $this->exceptionCache = [];
    }
}
