/**
 * Cliente HTTP de la app.
 *
 * Lo que más importa acá no son los caminos felices sino cómo distingue los
 * fracasos: una sesión revocada (401) tiene que cerrar la sesión local, un
 * error de red NO —si un corte de señal desloguease a la persona, perdería el
 * buffer de posiciones y quedaría sin rastreo hasta que alguien la ayude a
 * volver a entrar en medio del campo.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiClient, ApiError, UnauthorizedError } from '../src/api/client';

/** Respuesta con el sobre `{ok, data, error}` que usa la API. */
function envelope(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

describe('ApiClient · construcción de la URL', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(envelope({ ok: true, data: {}, error: null })));
  });
  afterEach(() => vi.unstubAllGlobals());

  it('saca las barras finales para no generar // en la ruta', async () => {
    const api = new ApiClient('https://app.satrak.online///');
    api.setToken('t');
    await api.sync();

    expect(fetch).toHaveBeenCalledWith('https://app.satrak.online/api/app/sync', expect.anything());
  });

  it('setBaseUrl también normaliza', () => {
    const api = new ApiClient('https://a.test');
    api.setBaseUrl('https://b.test/');
    expect(api.getBaseUrl()).toBe('https://b.test');
  });
});

describe('ApiClient · autenticación', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('el login NO manda Authorization: todavía no hay token', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      envelope({ ok: true, data: { token: 'abc' }, error: null }),
    );
    vi.stubGlobal('fetch', fetchMock);

    await new ApiClient('https://x.test').login({
      company_slug: 'transportes-comahue',
      dni: '28111222',
      password: 'Demo.1234',
      install_id: 'abc',
      platform: 'android',
    });

    const headers = fetchMock.mock.calls[0][1].headers as Record<string, string>;
    expect(headers.Authorization).toBeUndefined();
  });

  it('el resto de las llamadas mandan el Bearer', async () => {
    const fetchMock = vi.fn().mockResolvedValue(envelope({ ok: true, data: {}, error: null }));
    vi.stubGlobal('fetch', fetchMock);

    const api = new ApiClient('https://x.test');
    api.setToken('un-token');
    await api.sync();

    const headers = fetchMock.mock.calls[0][1].headers as Record<string, string>;
    expect(headers.Authorization).toBe('Bearer un-token');
  });

  /**
   * Sin token no se sale a la red siquiera: se corta local. Evita una tanda de
   * requests 401 cuando la sesión ya se perdió.
   */
  it('sin token lanza UnauthorizedError sin llamar a fetch', async () => {
    const fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);

    await expect(new ApiClient('https://x.test').sync()).rejects.toBeInstanceOf(UnauthorizedError);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('setToken(null) vuelve al estado sin sesión', async () => {
    vi.stubGlobal('fetch', vi.fn());
    const api = new ApiClient('https://x.test');
    api.setToken('t');
    api.setToken(null);

    await expect(api.sync()).rejects.toBeInstanceOf(UnauthorizedError);
  });
});

describe('ApiClient · manejo de errores', () => {
  afterEach(() => vi.unstubAllGlobals());

  /** La distinción que más importa: red caída ≠ sesión cerrada. */
  it('un fallo de red da ApiError con status 0, NO UnauthorizedError', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')));

    const api = new ApiClient('https://x.test');
    api.setToken('t');

    const error = await api.sync().catch((e) => e);
    expect(error).toBeInstanceOf(ApiError);
    expect(error).not.toBeInstanceOf(UnauthorizedError);
    expect(error.status).toBe(0);
    expect(error.message).toBe('Sin conexión con el servidor.');
  });

  it('un 401 da UnauthorizedError con el mensaje del servidor', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
      envelope({ ok: false, data: null, error: 'Sesión cerrada desde el panel.' }, 401),
    ));

    const api = new ApiClient('https://x.test');
    api.setToken('t');

    const error = await api.sync().catch((e) => e);
    expect(error).toBeInstanceOf(UnauthorizedError);
    expect(error.message).toBe('Sesión cerrada desde el panel.');
  });

  it('propaga el mensaje de error del servidor en un 4xx', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
      envelope({ ok: false, data: null, error: 'El botón de pánico no está habilitado para tu empresa.' }, 403),
    ));

    const api = new ApiClient('https://x.test');
    api.setToken('t');

    const error = await api.panic('2026-08-28 10:00:00', -34.5, -58.4).catch((e) => e);
    expect(error).toBeInstanceOf(ApiError);
    expect(error.status).toBe(403);
    expect(error.message).toContain('pánico no está habilitado');
  });

  /** Un 500 con HTML de error no debe romper el parseo ni perder el status. */
  it('una respuesta que no es JSON no rompe el cliente', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
      new Response('<html>502 Bad Gateway</html>', { status: 502 }),
    ));

    const api = new ApiClient('https://x.test');
    api.setToken('t');

    const error = await api.sync().catch((e) => e);
    expect(error).toBeInstanceOf(ApiError);
    expect(error.status).toBe(502);
  });

  /**
   * El sobre puede venir con HTTP 200 pero `ok:false`. Tratarlo como éxito
   * devolvería `undefined` a la pantalla y se vería como un error raro después.
   */
  it('ok:false con HTTP 200 igual es un error', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
      envelope({ ok: false, data: null, error: 'Algo salió mal.' }, 200),
    ));

    const api = new ApiClient('https://x.test');
    api.setToken('t');

    await expect(api.sync()).rejects.toBeInstanceOf(ApiError);
  });
});

describe('ApiClient · endpoints', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('devuelve el `data` del sobre, no el sobre entero', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
      envelope({ ok: true, data: { post: null, missions: [] }, error: null }),
    ));

    const api = new ApiClient('https://x.test');
    api.setToken('t');

    expect(await api.sync()).toEqual({ post: null, missions: [] });
  });

  it('las posiciones van envueltas en {points}', async () => {
    const fetchMock = vi.fn().mockResolvedValue(envelope({ ok: true, data: {}, error: null }));
    vi.stubGlobal('fetch', fetchMock);

    const api = new ApiClient('https://x.test');
    api.setToken('t');
    const punto = {
      ts: '2026-08-28 10:00:00', lat: -34.5, lon: -58.4,
      speed: null, heading: null, accuracy_m: 12, battery_pct: 80,
    };
    await api.positions([punto]);

    const [url, init] = fetchMock.mock.calls[0];
    expect(url).toBe('https://x.test/api/app/positions');
    expect(init.method).toBe('POST');
    expect(JSON.parse(init.body)).toEqual({ points: [punto] });
  });

  it('las acciones de misión pegan a la ruta del id', async () => {
    // Una respuesta NUEVA por llamada: el body de un Response se consume una
    // sola vez, y este test hace dos requests seguidos.
    const fetchMock = vi.fn().mockImplementation(() => Promise.resolve(envelope({ ok: true, data: {}, error: null })));
    vi.stubGlobal('fetch', fetchMock);

    const api = new ApiClient('https://x.test');
    api.setToken('t');

    await api.startMission(7);
    expect(fetchMock.mock.calls[0][0]).toBe('https://x.test/api/app/missions/7/start');

    await api.arriveMission(7, -34.5, -58.4);
    expect(fetchMock.mock.calls[1][0]).toBe('https://x.test/api/app/missions/7/arrive');
    expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toEqual({ lat: -34.5, lon: -58.4 });
  });

  it('un GET no manda Content-Type ni body', async () => {
    const fetchMock = vi.fn().mockResolvedValue(envelope({ ok: true, data: {}, error: null }));
    vi.stubGlobal('fetch', fetchMock);

    const api = new ApiClient('https://x.test');
    api.setToken('t');
    await api.sync();

    const init = fetchMock.mock.calls[0][1];
    expect(init.body).toBeUndefined();
    expect((init.headers as Record<string, string>)['Content-Type']).toBeUndefined();
  });
});
