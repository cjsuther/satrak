/**
 * Buffer de posiciones sin subir.
 *
 * Es lo que sostiene el rastreo cuando no hay señal, que en el campo es la
 * mitad del tiempo. Dos propiedades importan más que ninguna otra:
 *
 *  1. No perder puntos por escrituras concurrentes: el watcher del GPS y el
 *     uploader corren en paralelo, y una lectura-modificación-escritura sin
 *     serializar borra puntos en silencio.
 *  2. Al llenarse, descartar los MÁS VIEJOS. En una emergencia importa dónde
 *     está la persona ahora, no dónde estaba hace seis horas.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { PositionInput } from '../src/api/types';

/** Preferences en memoria: reemplaza SharedPreferences/UserDefaults. */
const store = new Map<string, string>();

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

const KEY = 'satrak.buffer.positions';

function punto(ts: string): PositionInput {
  return {
    ts, lat: -34.5, lon: -58.4, speed: null, heading: null,
    accuracy_m: 10, battery_pct: 80,
  };
}

/** El módulo cachea en memoria, así que hay que reimportarlo limpio por test. */
async function freshBuffer() {
  store.clear();
  vi.resetModules();
  return import('../src/tracking/buffer');
}

describe('buffer · básicos', () => {
  it('arranca vacío', async () => {
    const buffer = await freshBuffer();
    expect(await buffer.size()).toBe(0);
    expect(await buffer.peek(10)).toEqual([]);
  });

  it('append devuelve el tamaño resultante', async () => {
    const buffer = await freshBuffer();
    expect(await buffer.append(punto('2026-08-28 10:00:00'))).toBe(1);
    expect(await buffer.append(punto('2026-08-28 10:01:00'))).toBe(2);
  });

  it('peek respeta el orden cronológico y no consume', async () => {
    const buffer = await freshBuffer();
    await buffer.append(punto('10:00'));
    await buffer.append(punto('10:01'));
    await buffer.append(punto('10:02'));

    expect((await buffer.peek(2)).map((p) => p.ts)).toEqual(['10:00', '10:01']);
    expect(await buffer.size()).toBe(3);
  });

  it('drop saca del frente los ya confirmados', async () => {
    const buffer = await freshBuffer();
    await buffer.append(punto('10:00'));
    await buffer.append(punto('10:01'));
    await buffer.append(punto('10:02'));

    await buffer.drop(2);
    expect((await buffer.peek(10)).map((p) => p.ts)).toEqual(['10:02']);
  });

  it('clear vacía todo', async () => {
    const buffer = await freshBuffer();
    await buffer.append(punto('10:00'));
    await buffer.clear();

    expect(await buffer.size()).toBe(0);
  });

  it('persiste entre lecturas (sobrevive a reiniciar la app)', async () => {
    const buffer = await freshBuffer();
    await buffer.append(punto('10:00'));

    // Nueva importación = proceso nuevo, misma "disco".
    vi.resetModules();
    const otra = await import('../src/tracking/buffer');

    expect(await otra.size()).toBe(1);
  });
});

describe('buffer · límite', () => {
  /**
   * El tope es 5.000 puntos. Con muestreo cada 60 s son ~83 h de rastreo sin
   * red: si se llegara a llenar, lo viejo es lo prescindible.
   */
  it('al desbordar descarta los MÁS VIEJOS, no los nuevos', async () => {
    const buffer = await freshBuffer();

    // Se precarga cerca del tope escribiendo directo, para no hacer 5.000 awaits.
    const previos = Array.from({ length: 5_000 }, (_, i) => punto(`viejo-${i}`));
    store.set(KEY, JSON.stringify(previos));

    const total = await buffer.append(punto('el-nuevo'));

    expect(total).toBe(5_000);
    const guardados = JSON.parse(store.get(KEY)!) as PositionInput[];
    expect(guardados[guardados.length - 1].ts).toBe('el-nuevo');
    expect(guardados[0].ts).toBe('viejo-1');
    expect(guardados.some((p) => p.ts === 'viejo-0')).toBe(false);
  });
});

describe('buffer · concurrencia', () => {
  /**
   * El escenario real: llega un fix del GPS mientras el uploader está
   * confirmando un lote. Sin serializar, una de las dos escrituras pisa a la
   * otra y los puntos desaparecen sin error.
   */
  it('no pierde puntos con appends concurrentes', async () => {
    const buffer = await freshBuffer();

    await Promise.all(
      Array.from({ length: 50 }, (_, i) => buffer.append(punto(`p-${i}`))),
    );

    expect(await buffer.size()).toBe(50);
  });

  it('un drop concurrente con appends deja el buffer consistente', async () => {
    const buffer = await freshBuffer();
    for (let i = 0; i < 10; i++) await buffer.append(punto(`p-${i}`));

    await Promise.all([
      buffer.drop(5),
      buffer.append(punto('nuevo-a')),
      buffer.append(punto('nuevo-b')),
    ]);

    const restantes = await buffer.peek(100);
    expect(restantes).toHaveLength(7);
    // Los nuevos nunca se pierden.
    expect(restantes.map((p) => p.ts)).toContain('nuevo-a');
    expect(restantes.map((p) => p.ts)).toContain('nuevo-b');
  });
});

describe('buffer · datos corruptos', () => {
  it('un JSON inválido en disco se trata como buffer vacío', async () => {
    store.clear();
    vi.resetModules();
    store.set(KEY, '{no es json');
    const buffer = await import('../src/tracking/buffer');

    expect(await buffer.size()).toBe(0);
    // Y sigue siendo usable.
    expect(await buffer.append(punto('10:00'))).toBe(1);
  });

  it('un JSON válido que no es un arreglo se descarta', async () => {
    store.clear();
    vi.resetModules();
    store.set(KEY, '{"puntos": 3}');
    const buffer = await import('../src/tracking/buffer');

    expect(await buffer.size()).toBe(0);
  });
});
