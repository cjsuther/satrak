/**
 * Jornada laboral en el cliente — espejo de `ShiftGuard` del backend.
 *
 * Es lo que hace que la app **no capture fuera de turno**: la primera de las dos
 * capas de la regla legal (la segunda es el servidor, que descarta lo que igual
 * llegue). Que el servidor tenga la última palabra es deliberado: si este
 * cálculo se equivoca, el peor caso es gastar batería de más, nunca guardar una
 * posición fuera de la jornada.
 *
 * Reglas, iguales a las del backend:
 *  - Las ventanas son semanales, en el huso de la EMPRESA (no el del teléfono).
 *  - `to <= from` significa que la ventana cruza la medianoche.
 *  - Las excepciones pisan lo semanal: `off` anula el día, `extra` agrega una
 *    ventana y gana sobre un `off` del mismo día.
 */

import type { ShiftException, ShiftWindow } from '../api/types';

/**
 * Minutos de desfasaje del huso de la empresa respecto de UTC, leídos del
 * `server_time` que manda el backend (ej. "2026-08-13T12:37:26-03:00" → -180).
 *
 * Se saca de ahí y no de `Intl` a propósito: en un WebView viejo el soporte de
 * husos por nombre es incompleto, y el offset del propio servidor no falla.
 */
export function parseUtcOffsetMinutes(serverTime: string): number | null {
  const match = /([+-])(\d{2}):(\d{2})$/.exec(serverTime.trim());
  if (match) {
    const sign = match[1] === '-' ? -1 : 1;
    return sign * (parseInt(match[2], 10) * 60 + parseInt(match[3], 10));
  }
  if (/Z$/i.test(serverTime.trim())) {
    return 0;
  }
  return null;
}

interface CompanyParts {
  date: string;      // 'YYYY-MM-DD' en hora de la empresa
  weekday: number;   // 1 = lunes … 7 = domingo
  minutes: number;   // minutos desde la medianoche de la empresa
}

/** Componentes de un instante expresados en el huso de la empresa. */
function partsInCompanyTime(at: Date, offsetMinutes: number): CompanyParts {
  // Corriendo el instante por el offset, los getters UTC devuelven directamente
  // los componentes "locales de la empresa" sin depender de Intl.
  const shifted = new Date(at.getTime() + offsetMinutes * 60_000);
  const y = shifted.getUTCFullYear();
  const m = shifted.getUTCMonth() + 1;
  const d = shifted.getUTCDate();
  const jsDay = shifted.getUTCDay(); // 0 = domingo

  return {
    date: `${y}-${pad(m)}-${pad(d)}`,
    weekday: jsDay === 0 ? 7 : jsDay,
    minutes: shifted.getUTCHours() * 60 + shifted.getUTCMinutes(),
  };
}

function pad(n: number): string {
  return n < 10 ? `0${n}` : String(n);
}

/** 'HH:MM' → minutos desde la medianoche. Devuelve null si no parsea. */
function toMinutes(hhmm: string | null): number | null {
  if (!hhmm) return null;
  const match = /^(\d{1,2}):(\d{2})/.exec(hhmm.trim());
  if (!match) return null;
  const h = parseInt(match[1], 10);
  const m = parseInt(match[2], 10);
  if (h > 23 || m > 59) return null;
  return h * 60 + m;
}

/** El día anterior a 'YYYY-MM-DD'. */
function previousDate(date: string): string {
  const [y, m, d] = date.split('-').map((v) => parseInt(v, 10));
  const prev = new Date(Date.UTC(y, m - 1, d));
  prev.setUTCDate(prev.getUTCDate() - 1);
  return `${prev.getUTCFullYear()}-${pad(prev.getUTCMonth() + 1)}-${pad(prev.getUTCDate())}`;
}

function previousWeekday(weekday: number): number {
  return weekday === 1 ? 7 : weekday - 1;
}

/**
 * ¿La ventana [from, to) que ARRANCA en el día evaluado cubre a `minutes`?
 *
 * @param sameDay true si la ventana arranca el mismo día del instante; false si
 *        arrancó el día anterior (y entonces sólo cuenta su tramo pasada la
 *        medianoche).
 */
function windowCovers(from: number, to: number, minutes: number, sameDay: boolean): boolean {
  const crossesMidnight = to <= from;

  if (!crossesMidnight) {
    return sameDay && minutes >= from && minutes < to;
  }
  // Cruza medianoche: del día que arranca cubre [from, 24:00);
  // del día siguiente, [00:00, to).
  return sameDay ? minutes >= from : minutes < to;
}

export interface ShiftSchedule {
  shifts: ShiftWindow[];
  exceptions: ShiftException[];
  /** Offset del huso de la empresa, en minutos respecto de UTC. */
  offsetMinutes: number;
}

/**
 * ¿El instante cae dentro de la jornada?
 *
 * Evalúa el día del instante y el anterior, porque una ventana nocturna que
 * arrancó ayer todavía puede cubrir la madrugada de hoy.
 */
export function isWithinShift(schedule: ShiftSchedule, at: Date = new Date()): boolean {
  const now = partsInCompanyTime(at, schedule.offsetMinutes);

  const days: Array<{ date: string; weekday: number; sameDay: boolean }> = [
    { date: now.date, weekday: now.weekday, sameDay: true },
    { date: previousDate(now.date), weekday: previousWeekday(now.weekday), sameDay: false },
  ];

  for (const day of days) {
    const dayExceptions = schedule.exceptions.filter((e) => e.date === day.date);

    // 'extra' gana sobre todo, incluido un 'off' del mismo día.
    const extras = dayExceptions.filter((e) => e.kind === 'extra');
    let coveredByExtra = false;
    for (const extra of extras) {
      const from = toMinutes(extra.from);
      const to = toMinutes(extra.to);
      if (from !== null && to !== null && windowCovers(from, to, now.minutes, day.sameDay)) {
        coveredByExtra = true;
        break;
      }
    }
    if (coveredByExtra) return true;

    // 'off' anula las ventanas semanales de ese día.
    if (dayExceptions.some((e) => e.kind === 'off')) continue;

    for (const shift of schedule.shifts) {
      if (shift.weekday !== day.weekday) continue;
      const from = toMinutes(shift.from);
      const to = toMinutes(shift.to);
      if (from !== null && to !== null && windowCovers(from, to, now.minutes, day.sameDay)) {
        return true;
      }
    }
  }

  return false;
}

/**
 * Timestamp 'YYYY-MM-DD HH:MM:SS' en hora de la empresa, que es el formato que
 * espera la API. Se manda en el huso de la empresa para que el servidor evalúe
 * la jornada con la misma referencia que usó la app.
 */
export function toApiTimestamp(at: Date, offsetMinutes: number): string {
  const shifted = new Date(at.getTime() + offsetMinutes * 60_000);
  return (
    `${shifted.getUTCFullYear()}-${pad(shifted.getUTCMonth() + 1)}-${pad(shifted.getUTCDate())}` +
    ` ${pad(shifted.getUTCHours())}:${pad(shifted.getUTCMinutes())}:${pad(shifted.getUTCSeconds())}`
  );
}
