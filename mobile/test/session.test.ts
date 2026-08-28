/**
 * Sesión persistida de la app.
 *
 * El `install_id` es lo delicado: identifica esta instalación en `devices` y
 * ocupa el lugar del IMEI. Si cambiara entre arranques, cada login crearía un
 * dispositivo nuevo en el panel y el historial de la persona quedaría partido
 * en pedazos.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest';

const store = new Map<string, string>();
let deviceIdImpl: () => Promise<{ identifier: string }>;

vi.mock('@capacitor/preferences', () => ({
  Preferences: {
    get: async ({ key }: { key: string }) => ({ value: store.get(key) ?? null }),
    set: async ({ key, value }: { key: string; value: string }) => {
      store.set(key, value);
    },
    remove: async ({ key }: { key: string }) => {
      store.delete(key);
    },
  },
}));

vi.mock('@capacitor/device', () => ({
  Device: {
    getId: () => deviceIdImpl(),
  },
}));

async function fresh() {
  store.clear();
  vi.resetModules();
  return import('../src/state/session');
}

beforeEach(() => {
  deviceIdImpl = async () => ({ identifier: 'id-del-equipo' });
});

describe('sesión', () => {
  it('sin sesión guardada devuelve null', async () => {
    const s = await fresh();
    expect(await s.loadSession()).toBeNull();
  });

  it('guarda y recupera la sesión', async () => {
    const s = await fresh();
    const sesion = {
      token: 'abc123',
      companySlug: 'transportes-comahue',
      person: { id: 1, first_name: 'Ana', last_name: 'Gómez', company: 'Transportes del Comahue' },
    };

    await s.saveSession(sesion);
    expect(await s.loadSession()).toEqual(sesion);
  });

  it('clearSession borra el token', async () => {
    const s = await fresh();
    await s.saveSession({
      token: 'abc',
      companySlug: 'x',
      person: { id: 1, first_name: 'A', last_name: 'B', company: 'C' },
    });
    await s.clearSession();

    expect(await s.loadSession()).toBeNull();
  });

  /** Un valor corrupto en disco no debe dejar la app inutilizable. */
  it('una sesión corrupta se trata como ausente', async () => {
    store.clear();
    vi.resetModules();
    store.set('satrak.session', 'no es json');
    const s = await import('../src/state/session');

    expect(await s.loadSession()).toBeNull();
  });
});

describe('install_id', () => {
  it('es estable entre llamadas', async () => {
    const s = await fresh();

    const primero = await s.getInstallId();
    const segundo = await s.getInstallId();

    expect(primero).toBe(segundo);
    expect(primero).not.toBe('');
  });

  it('sobrevive a reiniciar la app', async () => {
    const s = await fresh();
    const original = await s.getInstallId();

    vi.resetModules();
    const otra = await import('../src/state/session');

    expect(await otra.getInstallId()).toBe(original);
  });

  /**
   * Si `Device.getId()` falla —pasa en algunos equipos y en el navegador— hay
   * que caer a un id aleatorio, pero UNA sola vez: si generara uno nuevo en cada
   * llamada, cada arranque daría de alta un dispositivo distinto.
   */
  it('si Device.getId falla, genera uno y lo conserva', async () => {
    store.clear();
    vi.resetModules();
    deviceIdImpl = async () => {
      throw new Error('no disponible');
    };
    const s = await import('../src/state/session');

    const primero = await s.getInstallId();
    const segundo = await s.getInstallId();

    expect(primero).toBeTruthy();
    expect(segundo).toBe(primero);
  });

  /**
   * La columna `devices.imei` es VARCHAR(64) desde la migración 006, porque un
   * UUID de equipo real son 36 caracteres y antes no entraba: el login fallaba
   * con «Data too long». Este test fija el límite del lado del cliente.
   */
  it('entra en devices.imei (64 caracteres)', async () => {
    const s = await fresh();
    expect((await s.getInstallId()).length).toBeLessThanOrEqual(64);
  });
});

describe('base URL', () => {
  it('sin nada guardado usa el default', async () => {
    const s = await fresh();
    expect(await s.getBaseUrl()).toBe(s.DEFAULT_BASE_URL);
  });

  it('se puede apuntar a otro servidor y persiste', async () => {
    const s = await fresh();
    await s.setBaseUrl('https://pruebas.satrak.online');

    expect(await s.getBaseUrl()).toBe('https://pruebas.satrak.online');
  });

  it('un valor vacío guardado cae al default en vez de dejar la app sin backend', async () => {
    const s = await fresh();
    await s.setBaseUrl('');

    expect(await s.getBaseUrl()).toBe(s.DEFAULT_BASE_URL);
  });
});
