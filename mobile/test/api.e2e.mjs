import { ApiClient, UnauthorizedError } from '../.testbuild/api/client.js';
import { isWithinShift, parseUtcOffsetMinutes, toApiTimestamp } from '../.testbuild/tracking/shift.js';

let fail = 0;
const ok = (cond, label, extra = '') => {
  if (!cond) fail++;
  console.log(`${cond ? 'ok  ' : 'FALLA'}  ${label}${extra ? ' · ' + extra : ''}`);
};

const api = new ApiClient('http://127.0.0.1:8099');

// --- login ---------------------------------------------------------------
const login = await api.login({
  company_slug: 'comahue', dni: '30111222', password: 'Campo.2026',
  install_id: 'app-e2e-1', platform: 'android', os_version: '5.1',
  app_version: '1.0.0', model: 'Moto G4',
});
api.setToken(login.token);
ok(!!login.token, 'login devuelve token');
ok(login.person.first_name === 'Ana', 'persona correcta', login.person.first_name + ' ' + login.person.last_name);
ok(login.post?.name === 'Base Neuquén', 'puesto en el payload', login.post?.name);
ok(login.missions.length === 1, 'misión del día en el payload');
ok(login.config.max_batch === 200, 'config de muestreo');

// --- la jornada del cliente coincide con lo que manda el server ----------
const offset = parseUtcOffsetMinutes(login.server_time);
ok(offset !== null, 'offset del huso de la empresa', String(offset));
const schedule = { shifts: login.shifts, exceptions: login.shift_exceptions, offsetMinutes: offset };
ok(isWithinShift(schedule, new Date()) === true, 'ahora estamos en jornada');

// --- posiciones ----------------------------------------------------------
const now = new Date();
const points = [0, 1, 2].map((i) => ({
  ts: toApiTimestamp(new Date(now.getTime() - i * 60_000), offset),
  lat: -38.9516 + i * 0.0001, lon: -68.0591, speed: 3, heading: 90,
  accuracy_m: 12, battery_pct: 88,
}));
const r1 = await api.positions(points);
ok(r1.stored === 3, 'sube 3 posiciones', JSON.stringify(r1));
const r2 = await api.positions(points);
ok(r2.stored === 0 && r2.duplicated === 3, 'reenvío es idempotente', JSON.stringify(r2));

// --- pánico --------------------------------------------------------------
await api.panic(toApiTimestamp(now, offset), -38.95, -68.05);
ok(true, 'pánico aceptado');

// --- heartbeat -----------------------------------------------------------
await api.heartbeat(64, true);
ok(true, 'heartbeat aceptado');

// --- misión --------------------------------------------------------------
const mission = login.missions[0];
await api.startMission(mission.id);
ok(true, 'misión iniciada');
try {
  await api.arriveMission(mission.id, -40, -70);
  ok(false, 'llegada lejos debería rechazarse');
} catch (e) {
  ok(e.status === 409, 'llegada lejos rechazada', e.message);
}
await api.arriveMission(mission.id, -38.83, -68.13);
ok(true, 'llegada en destino aceptada');

// --- sesión única --------------------------------------------------------
const api2 = new ApiClient('http://127.0.0.1:8099');
const second = await api2.login({
  company_slug: 'comahue', dni: '30111222', password: 'Campo.2026',
  install_id: 'app-e2e-2', platform: 'ios',
});
api2.setToken(second.token);
try {
  await api.sync();
  ok(false, 'el primer token debería quedar inválido');
} catch (e) {
  ok(e instanceof UnauthorizedError, 'login en otro equipo revoca el anterior');
}
const sync2 = await api2.sync();
ok(Array.isArray(sync2.shifts), 'el segundo equipo sincroniza bien');

// --- credenciales malas --------------------------------------------------
try {
  await new ApiClient('http://127.0.0.1:8099').login({
    company_slug: 'comahue', dni: '30111222', password: 'incorrecta',
    install_id: 'app-e2e-3', platform: 'android',
  });
  ok(false, 'credenciales malas deberían fallar');
} catch (e) {
  ok(e.message.includes('incorrect'), 'credenciales malas rechazadas', e.message);
}

console.log(fail === 0 ? '\nTODOS OK' : `\n${fail} CASO(S) FALLIDO(S)`);
process.exit(fail === 0 ? 0 : 1);
