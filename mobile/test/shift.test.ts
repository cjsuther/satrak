/**
 * Jornada en el cliente — espejo de `ShiftGuard` del backend.
 *
 * Estos casos son deliberadamente **los mismos** que los del test de PHP: si
 * cliente y servidor no deciden igual, la app captura posiciones que el backend
 * después descarta (batería gastada al pepe) o —peor— deja de capturar cuando
 * la persona sí está en jornada y no queda registro de su recorrido.
 *
 * Migrado de `test/shift.test.mjs`, que corría sobre un build de tsc a mano.
 */

import { describe, expect, it } from 'vitest';
import { isWithinShift, parseUtcOffsetMinutes, toApiTimestamp } from '../src/tracking/shift';
import type { ShiftSchedule } from '../src/tracking/shift';
import type { ShiftException, ShiftWindow } from '../src/api/types';

const OFFSET = -180; // America/Argentina/Buenos_Aires

function schedule(shifts: ShiftWindow[], exceptions: ShiftException[] = []): ShiftSchedule {
  return { shifts, exceptions, offsetMinutes: OFFSET };
}

/** Un instante escrito en hora de la empresa → Date real. */
const at = (s: string) => new Date(s.replace(' ', 'T') + '-03:00');

describe('parseUtcOffsetMinutes', () => {
  it('lee el offset del server_time', () => {
    expect(parseUtcOffsetMinutes('2026-08-13T12:37:26-03:00')).toBe(-180);
    expect(parseUtcOffsetMinutes('2026-08-13T12:37:26+05:30')).toBe(330);
  });

  it('entiende la Z como UTC', () => {
    expect(parseUtcOffsetMinutes('2026-08-13T15:37:26Z')).toBe(0);
    expect(parseUtcOffsetMinutes('2026-08-13T15:37:26z')).toBe(0);
  });

  it('devuelve null si no hay offset, para que el llamador use su default', () => {
    expect(parseUtcOffsetMinutes('2026-08-13 12:37:26')).toBeNull();
    expect(parseUtcOffsetMinutes('')).toBeNull();
  });
});

describe('isWithinShift · sin jornada', () => {
  /** El default que protege a la persona: sin jornada cargada no se rastrea. */
  it('sin ventanas nunca hay jornada', () => {
    expect(isWithinShift(schedule([]), at('2026-08-10 12:00:00'))).toBe(false);
  });
});

describe('isWithinShift · ventana diurna', () => {
  const diurno = schedule([{ weekday: 1, from: '08:00', to: '17:00' }]); // lunes

  it('el inicio es inclusivo y el fin exclusivo', () => {
    expect(isWithinShift(diurno, at('2026-08-10 07:59:00'))).toBe(false);
    expect(isWithinShift(diurno, at('2026-08-10 08:00:00'))).toBe(true);
    expect(isWithinShift(diurno, at('2026-08-10 16:59:00'))).toBe(true);
    expect(isWithinShift(diurno, at('2026-08-10 17:00:00'))).toBe(false);
  });

  it('no aplica a otro día de la semana', () => {
    expect(isWithinShift(diurno, at('2026-08-11 12:00:00'))).toBe(false);
  });
});

describe('isWithinShift · turno nocturno', () => {
  const nocturno = schedule([{ weekday: 1, from: '22:00', to: '06:00' }]); // lunes 22 → martes 6

  it('cubre desde que arranca hasta la madrugada siguiente', () => {
    expect(isWithinShift(nocturno, at('2026-08-10 21:59:00'))).toBe(false);
    expect(isWithinShift(nocturno, at('2026-08-10 22:00:00'))).toBe(true);
    expect(isWithinShift(nocturno, at('2026-08-10 23:30:00'))).toBe(true);
    expect(isWithinShift(nocturno, at('2026-08-11 02:00:00'))).toBe(true);
    expect(isWithinShift(nocturno, at('2026-08-11 05:59:00'))).toBe(true);
    expect(isWithinShift(nocturno, at('2026-08-11 06:00:00'))).toBe(false);
  });

  it('el martes a la noche ya no tiene turno', () => {
    expect(isWithinShift(nocturno, at('2026-08-11 22:00:00'))).toBe(false);
  });

  /** La madrugada del lunes correspondería al turno del domingo, que no existe. */
  it('la madrugada del mismo día no la cubre su propio turno nocturno', () => {
    expect(isWithinShift(nocturno, at('2026-08-10 02:00:00'))).toBe(false);
  });
});

describe('isWithinShift · excepciones', () => {
  const base: ShiftWindow[] = [{ weekday: 1, from: '08:00', to: '17:00' }];

  it('un franco anula el día completo', () => {
    const s = schedule(base, [{ date: '2026-08-10', kind: 'off', from: null, to: null }]);
    expect(isWithinShift(s, at('2026-08-10 12:00:00'))).toBe(false);
  });

  it('el franco sólo afecta su fecha', () => {
    const s = schedule(
      [...base, { weekday: 2, from: '08:00', to: '17:00' }],
      [{ date: '2026-08-10', kind: 'off', from: null, to: null }],
    );
    expect(isWithinShift(s, at('2026-08-10 12:00:00'))).toBe(false);
    expect(isWithinShift(s, at('2026-08-11 12:00:00'))).toBe(true);
  });

  it('un turno extra agrega jornada donde no había', () => {
    const s = schedule([], [{ date: '2026-08-10', kind: 'extra', from: '09:00', to: '13:00' }]);
    expect(isWithinShift(s, at('2026-08-10 10:00:00'))).toBe(true);
    expect(isWithinShift(s, at('2026-08-10 14:00:00'))).toBe(false);
  });

  /** Regla explícita del diseño: convocar a alguien que estaba de franco. */
  it('el extra gana sobre el franco del mismo día', () => {
    const s = schedule(base, [
      { date: '2026-08-10', kind: 'off', from: null, to: null },
      { date: '2026-08-10', kind: 'extra', from: '20:00', to: '23:00' },
    ]);
    expect(isWithinShift(s, at('2026-08-10 12:00:00'))).toBe(false);
    expect(isWithinShift(s, at('2026-08-10 21:00:00'))).toBe(true);
  });

  it('un extra sin horas se ignora en vez de romper', () => {
    const s = schedule([], [{ date: '2026-08-10', kind: 'extra', from: null, to: null }]);
    expect(isWithinShift(s, at('2026-08-10 12:00:00'))).toBe(false);
  });
});

describe('isWithinShift · huso de la empresa', () => {
  /**
   * El teléfono puede estar en otro huso que la empresa (o con la hora mal).
   * La jornada se evalúa SIEMPRE en el huso de la empresa, que llega en
   * `offsetMinutes`. Este es el caso que más silenciosamente puede fallar.
   */
  it('el mismo instante cae dentro o fuera según el huso de la empresa', () => {
    const ventana: ShiftWindow[] = [{ weekday: 1, from: '08:00', to: '17:00' }];
    const instante = new Date('2026-08-10T22:00:00Z'); // lunes 19:00 en Argentina

    const argentina = { shifts: ventana, exceptions: [], offsetMinutes: -180 };
    const utc = { shifts: ventana, exceptions: [], offsetMinutes: 0 };

    expect(isWithinShift(argentina, instante)).toBe(false); // 19:00 local, ya salió
    expect(isWithinShift(utc, instante)).toBe(false);       // 22:00 UTC, también

    const manana = new Date('2026-08-10T13:00:00Z');        // 10:00 arg / 13:00 UTC
    expect(isWithinShift(argentina, manana)).toBe(true);
    expect(isWithinShift(utc, manana)).toBe(true);
  });

  it('un offset positivo cambia el día evaluado', () => {
    // 2026-08-10T23:00Z es martes 08:00 en +09:00.
    const instante = new Date('2026-08-10T23:00:00Z');
    const tokio = { shifts: [{ weekday: 2, from: '08:00', to: '17:00' }], exceptions: [], offsetMinutes: 540 };

    expect(isWithinShift(tokio, instante)).toBe(true);
  });
});

describe('toApiTimestamp', () => {
  /**
   * El backend espera 'Y-m-d H:i:s' en hora de la EMPRESA. Si acá se mandara
   * hora del teléfono, cada punto quedaría corrido y `ShiftGuard` los
   * descartaría por caer fuera de turno.
   */
  it('formatea en hora de la empresa, no del dispositivo', () => {
    const instante = new Date('2026-08-10T15:30:00Z');

    expect(toApiTimestamp(instante, -180)).toBe('2026-08-10 12:30:00');
    expect(toApiTimestamp(instante, 0)).toBe('2026-08-10 15:30:00');
    expect(toApiTimestamp(instante, 540)).toBe('2026-08-11 00:30:00');
  });

  it('no usa la T de ISO ni la Z', () => {
    const s = toApiTimestamp(new Date('2026-08-10T15:30:00Z'), -180);
    expect(s).not.toContain('T');
    expect(s).not.toContain('Z');
    expect(s).toMatch(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/);
  });
});
