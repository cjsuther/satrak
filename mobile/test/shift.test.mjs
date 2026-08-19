// Mismos casos que el test de ShiftGuard en PHP, para verificar que cliente y
// servidor deciden igual. Se ejecuta sobre el build de tsc a JS.
import { isWithinShift, parseUtcOffsetMinutes, toApiTimestamp } from '../.testbuild/tracking/shift.js';

const OFFSET = -180; // America/Argentina/Buenos_Aires

// Turno nocturno: lunes 22:00 -> 06:00
const nightly = {
  shifts: [{ weekday: 1, from: '22:00', to: '06:00' }],
  exceptions: [],
  offsetMinutes: OFFSET,
};

// Un instante "hora de la empresa" -> Date real (UTC = local + 3h)
const at = (s) => new Date(s.replace(' ', 'T') + '-03:00');

let fail = 0;
const check = (got, expected, label) => {
  const ok = got === expected;
  if (!ok) fail++;
  console.log(`${ok ? 'ok  ' : 'FALLA'}  ${label.padEnd(42)} esperado=${expected ? 'si' : 'no'} obtuvo=${got ? 'si' : 'no'}`);
};

check(isWithinShift(nightly, at('2026-08-10 21:59:00')), false, 'lunes 21:59, antes de entrar');
check(isWithinShift(nightly, at('2026-08-10 22:00:00')), true,  'lunes 22:00, arranca el turno');
check(isWithinShift(nightly, at('2026-08-10 23:30:00')), true,  'lunes 23:30, dentro');
check(isWithinShift(nightly, at('2026-08-11 02:00:00')), true,  'martes 02:00, sigue el del lunes');
check(isWithinShift(nightly, at('2026-08-11 05:59:00')), true,  'martes 05:59, ultimo minuto');
check(isWithinShift(nightly, at('2026-08-11 06:00:00')), false, 'martes 06:00, ya salio');
check(isWithinShift(nightly, at('2026-08-11 22:00:00')), false, 'martes 22:00, no tiene turno');

const withExceptions = {
  ...nightly,
  exceptions: [
    { date: '2026-08-10', kind: 'off', from: null, to: null },
    { date: '2026-08-11', kind: 'extra', from: '09:00', to: '12:00' },
  ],
};
check(isWithinShift(withExceptions, at('2026-08-10 23:30:00')), false, 'franco anula el turno del lunes');
check(isWithinShift(withExceptions, at('2026-08-11 10:00:00')), true,  'turno extra del martes');
check(isWithinShift(withExceptions, at('2026-08-11 12:30:00')), false, 'fuera del turno extra');

// offset y timestamp
check(parseUtcOffsetMinutes('2026-08-13T12:37:26-03:00') === -180, true, 'offset -03:00 parseado');
check(parseUtcOffsetMinutes('2026-08-13T15:37:26Z') === 0, true, 'offset Z parseado');
check(toApiTimestamp(at('2026-08-11 10:00:00'), OFFSET) === '2026-08-11 10:00:00', true, 'timestamp en hora de empresa');

console.log(fail === 0 ? '\nTODOS OK' : `\n${fail} CASO(S) FALLIDO(S)`);
process.exit(fail === 0 ? 0 : 1);
