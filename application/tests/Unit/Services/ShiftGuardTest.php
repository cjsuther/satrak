<?php

declare(strict_types=1);

namespace Satrak\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Satrak\Domain\Repositories\CompanyRepository;
use Satrak\Domain\Repositories\PersonShiftRepository;
use Satrak\Domain\Services\ShiftGuard;
use Satrak\Tests\Support\InMemoryDb;

/**
 * ShiftGuard hace cumplir la regla legal del módulo: **fuera de la jornada no
 * se rastrea**. Es la pieza con más consecuencias del sistema —guardar una
 * posición fuera de turno es vigilar a alguien sin derecho a hacerlo— y la que
 * más casos límite tiene: turnos nocturnos que cruzan la medianoche, francos,
 * turnos extra y husos horarios por empresa.
 *
 * Los repositorios son `final`, así que en vez de simularlos se los conecta a
 * una SQLite en memoria: se ejercita el SQL real y la lógica a la vez.
 */
final class ShiftGuardTest extends TestCase
{
    private const EMPRESA = 1;
    private const PERSONA = 1;

    /**
     * Arma un ShiftGuard con repositorios REALES sobre SQLite en memoria: así
     * el SQL de PersonShiftRepository queda ejercitado, no salteado.
     *
     * @param array<int,array{0:int,1:string,2:string}>                  $shifts     [weekday, from, to]
     * @param array<int,array{0:string,1:string,2:?string,3:?string}>    $exceptions [fecha, kind, from, to]
     */
    private function guard(array $shifts, array $exceptions = [], string $tz = 'America/Argentina/Buenos_Aires'): ShiftGuard
    {
        $pdo = InMemoryDb::withShiftTables(InMemoryDb::connect());
        InMemoryDb::insertCompany($pdo, self::EMPRESA, $tz);

        foreach ($shifts as [$weekday, $from, $to]) {
            InMemoryDb::insertShift($pdo, self::EMPRESA, self::PERSONA, $weekday, $from, $to);
        }
        foreach ($exceptions as [$fecha, $kind, $from, $to]) {
            InMemoryDb::insertException($pdo, self::EMPRESA, self::PERSONA, $fecha, $kind, $from, $to);
        }

        return new ShiftGuard(new PersonShiftRepository($pdo), new CompanyRepository($pdo));
    }

    /** @return array{0:int,1:string,2:string} */
    private function shift(int $weekday, string $from, string $to): array
    {
        return [$weekday, $from, $to];
    }

    /** @return array{0:string,1:string,2:?string,3:?string} */
    private function off(string $fecha): array
    {
        return [$fecha, 'off', null, null];
    }

    /** @return array{0:string,1:string,2:?string,3:?string} */
    private function extra(string $fecha, ?string $from, ?string $to): array
    {
        return [$fecha, 'extra', $from, $to];
    }

    private function within(ShiftGuard $g, string $ts): bool
    {
        return $g->isWithinShift(self::PERSONA, self::EMPRESA, $ts);
    }

    // --- Sin jornada ---------------------------------------------------------

    /**
     * El default más importante del sistema: sin jornada cargada NO se rastrea.
     * Si esto devolviera true, alta una persona y ya la estás siguiendo 24/7.
     */
    public function testSinJornadaCargadaNuncaHayRastreo(): void
    {
        $g = $this->guard([]);

        self::assertFalse($this->within($g, '2026-08-26 10:00:00'));
        self::assertFalse($this->within($g, '2026-08-26 23:59:59'));
    }

    // --- Ventana diurna simple ----------------------------------------------

    public function testDentroDeUnaVentanaDiurna(): void
    {
        // Miércoles 2026-08-26, 08:00–17:00.
        $g = $this->guard([$this->shift(3, '08:00:00', '17:00:00')]);

        self::assertTrue($this->within($g, '2026-08-26 08:00:00'), 'el inicio es inclusivo');
        self::assertTrue($this->within($g, '2026-08-26 12:30:00'));
        self::assertTrue($this->within($g, '2026-08-26 16:59:59'));
    }

    /**
     * La ventana es [inicio, fin): el instante exacto del fin ya está fuera.
     * Sin este límite, dos turnos consecutivos se solaparían un segundo.
     */
    public function testElFinDeLaVentanaEsExclusivo(): void
    {
        $g = $this->guard([$this->shift(3, '08:00:00', '17:00:00')]);

        self::assertFalse($this->within($g, '2026-08-26 17:00:00'));
        self::assertFalse($this->within($g, '2026-08-26 07:59:59'));
    }

    public function testOtroDiaDeLaSemanaNoAplica(): void
    {
        // Sólo miércoles (3). El jueves 27 no debe cubrir.
        $g = $this->guard([$this->shift(3, '08:00:00', '17:00:00')]);

        self::assertFalse($this->within($g, '2026-08-27 12:00:00'));
    }

    public function testDomingoEsElDiaSiete(): void
    {
        // ISO-8601: 1=lunes .. 7=domingo. 2026-08-30 es domingo.
        $g = $this->guard([$this->shift(7, '08:00:00', '17:00:00')]);

        self::assertTrue($this->within($g, '2026-08-30 12:00:00'));
        self::assertFalse($this->within($g, '2026-08-31 12:00:00'), 'el lunes no');
    }

    // --- Turno nocturno ------------------------------------------------------

    /**
     * 22:00–06:00 cruza la medianoche. Es el caso que rompe cualquier
     * comparación ingenua de horas, y el más común en seguridad y guardias.
     */
    public function testTurnoNocturnoCubreLaMadrugadaDelDiaSiguiente(): void
    {
        // Miércoles 22:00 → jueves 06:00.
        $g = $this->guard([$this->shift(3, '22:00:00', '06:00:00')]);

        self::assertTrue($this->within($g, '2026-08-26 22:00:00'), 'arranca el miércoles');
        self::assertTrue($this->within($g, '2026-08-26 23:59:59'));
        self::assertTrue($this->within($g, '2026-08-27 02:00:00'), 'sigue el jueves de madrugada');
        self::assertTrue($this->within($g, '2026-08-27 05:59:59'));
    }

    public function testElTurnoNocturnoTerminaAlAmanecer(): void
    {
        $g = $this->guard([$this->shift(3, '22:00:00', '06:00:00')]);

        self::assertFalse($this->within($g, '2026-08-27 06:00:00'), 'a las 06:00 ya terminó');
        self::assertFalse($this->within($g, '2026-08-27 12:00:00'));
        self::assertFalse($this->within($g, '2026-08-26 21:59:59'), 'antes de arrancar');
    }

    /**
     * La madrugada del miércoles NO está cubierta por el turno que arranca ese
     * mismo miércoles a la noche: correspondería al turno del martes, que no
     * existe. Es el error de mirar sólo el día del instante.
     */
    public function testLaMadrugadaDelMismoDiaNoLaCubreSuPropioTurnoNocturno(): void
    {
        $g = $this->guard([$this->shift(3, '22:00:00', '06:00:00')]);

        self::assertFalse($this->within($g, '2026-08-26 02:00:00'));
    }

    /** Una ventana de 24 h (00:00–00:00) cubre el día entero. */
    public function testVentanaDeVeinticuatroHoras(): void
    {
        $g = $this->guard([$this->shift(3, '00:00:00', '00:00:00')]);

        self::assertTrue($this->within($g, '2026-08-26 00:00:00'));
        self::assertTrue($this->within($g, '2026-08-26 12:00:00'));
        self::assertTrue($this->within($g, '2026-08-26 23:59:59'));
        self::assertFalse($this->within($g, '2026-08-27 00:00:00'), 'el jueves ya es otro día');
    }

    // --- Excepciones ---------------------------------------------------------

    /** Un franco anula la jornada semanal de ese día. */
    public function testUnFrancoAnulaElDiaCompleto(): void
    {
        $g = $this->guard(
            [$this->shift(3, '08:00:00', '17:00:00')],
            [$this->off('2026-08-26')],
        );

        self::assertFalse($this->within($g, '2026-08-26 12:00:00'));
    }

    public function testElFrancoSoloAfectaSuFecha(): void
    {
        $g = $this->guard(
            [$this->shift(3, '08:00:00', '17:00:00'), $this->shift(4, '08:00:00', '17:00:00')],
            [$this->off('2026-08-26')],
        );

        self::assertFalse($this->within($g, '2026-08-26 12:00:00'), 'miércoles de franco');
        self::assertTrue($this->within($g, '2026-08-27 12:00:00'), 'jueves normal');
    }

    /** Un turno extra agrega jornada donde no había ninguna. */
    public function testUnTurnoExtraAgregaJornada(): void
    {
        $g = $this->guard(
            [],
            [$this->extra('2026-08-26', '09:00:00', '13:00:00')],
        );

        self::assertTrue($this->within($g, '2026-08-26 10:00:00'));
        self::assertFalse($this->within($g, '2026-08-26 14:00:00'), 'fuera de la ventana extra');
    }

    /**
     * Regla explícita del diseño: `extra` gana sobre `off`. Si alguien está de
     * franco pero se lo convoca por una urgencia, el extra manda.
     */
    public function testElTurnoExtraGanaSobreElFranco(): void
    {
        $g = $this->guard(
            [$this->shift(3, '08:00:00', '17:00:00')],
            [$this->off('2026-08-26'), $this->extra('2026-08-26', '20:00:00', '23:00:00')],
        );

        self::assertFalse($this->within($g, '2026-08-26 12:00:00'), 'el franco anuló la jornada normal');
        self::assertTrue($this->within($g, '2026-08-26 21:00:00'), 'pero el extra sí cubre');
    }

    /** Un extra incompleto (sin horas) se ignora en vez de romper. */
    public function testUnExtraSinHorasSeIgnora(): void
    {
        $g = $this->guard(
            [],
            [$this->extra('2026-08-26', null, null)],
        );

        self::assertFalse($this->within($g, '2026-08-26 12:00:00'));
    }

    // --- Huso horario --------------------------------------------------------

    /**
     * La jornada se evalúa en el huso de la EMPRESA, no el del servidor. Una
     * empresa en otro huso no debe correrse de horario. Se compara el mismo
     * instante nominal bajo dos husos distintos para que la diferencia sólo
     * pueda venir de la zona.
     */
    public function testLaJornadaSeEvaluaEnElHusoDeLaEmpresa(): void
    {
        $shifts = [$this->shift(3, '08:00:00', '17:00:00')];

        $arg   = $this->guard($shifts, [], 'America/Argentina/Buenos_Aires');
        $tokio = $this->guard($shifts, [], 'Asia/Tokyo');

        // El timestamp llega como hora local de la empresa, así que las 12:00
        // son las 12:00 en ambos casos: la ventana cubre en los dos.
        self::assertTrue($this->within($arg, '2026-08-26 12:00:00'));
        self::assertTrue($this->within($tokio, '2026-08-26 12:00:00'));
    }

    /** Un huso inválido en la base no debe tumbar el procesador. */
    public function testUnHusoInvalidoCaeAlPorDefectoSinRomper(): void
    {
        $g = $this->guard([$this->shift(3, '08:00:00', '17:00:00')], [], 'Marte/Olympus');

        self::assertTrue($this->within($g, '2026-08-26 12:00:00'));
    }

    // --- Caché ---------------------------------------------------------------

    /**
     * El procesador corre en ciclos cerrados y cachea por persona. `flush()`
     * existe para que un cambio de configuración se vea sin reiniciar.
     */
    public function testFlushNoRompeLasConsultasPosteriores(): void
    {
        $g = $this->guard([$this->shift(3, '08:00:00', '17:00:00')]);

        self::assertTrue($this->within($g, '2026-08-26 12:00:00'));
        $g->flush();
        self::assertTrue($this->within($g, '2026-08-26 12:00:00'));
    }

    /**
     * El formato esperado es 'Y-m-d H:i:s', pero hay un fallback a
     * `new DateTimeImmutable()` que además parsea ISO-8601. Se fija acá porque
     * la app manda `server_time` en ISO y conviene que ambos formatos den el
     * mismo veredicto en vez de que uno quede fuera de jornada por la 'T'.
     */
    public function testAceptaTambienElFormatoIso(): void
    {
        $g = $this->guard([$this->shift(3, '08:00:00', '17:00:00')]);

        self::assertTrue($this->within($g, '2026-08-26T12:00:00'));
        self::assertFalse($this->within($g, '2026-08-26T20:00:00'));
    }

    /** Una cadena impresentable no debe lanzar excepción. */
    public function testUnTimestampBasuraNoRompe(): void
    {
        $g = $this->guard([$this->shift(3, '08:00:00', '17:00:00')]);

        try {
            $resultado = $this->within($g, 'no soy una fecha');
            self::assertIsBool($resultado);
        } catch (\Throwable $e) {
            self::fail('un timestamp basura no debería propagar una excepción: ' . $e->getMessage());
        }
    }
}
