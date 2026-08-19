/**
 * Buffer de posiciones sin subir.
 *
 * En el campo la señal se corta: los puntos se guardan en disco y se suben por
 * lotes cuando vuelve la red. Se usa `Preferences` (SharedPreferences en Android,
 * UserDefaults en iOS) en vez de SQLite porque el volumen es chico —a lo sumo
 * unos miles de puntos— y evita una dependencia nativa más en equipos viejos.
 *
 * El buffer está **acotado**: si se llena, se descartan los puntos MÁS VIEJOS.
 * Perder rastro antiguo es preferible a perder el actual, que es el que importa
 * en una emergencia.
 */

import { Preferences } from '@capacitor/preferences';
import type { PositionInput } from '../api/types';

const KEY = 'satrak.buffer.positions';
const MAX_POINTS = 5_000;

let cache: PositionInput[] | null = null;
/** Serializa las escrituras: el watcher y el uploader corren en paralelo. */
let queue: Promise<unknown> = Promise.resolve();

async function load(): Promise<PositionInput[]> {
  if (cache) return cache;
  const { value } = await Preferences.get({ key: KEY });
  if (!value) {
    cache = [];
    return cache;
  }
  try {
    const parsed = JSON.parse(value);
    cache = Array.isArray(parsed) ? (parsed as PositionInput[]) : [];
  } catch {
    cache = [];
  }
  return cache;
}

async function persist(points: PositionInput[]): Promise<void> {
  cache = points;
  await Preferences.set({ key: KEY, value: JSON.stringify(points) });
}

/** Encola una operación para que no se pisen dos escrituras concurrentes. */
function serialize<T>(op: () => Promise<T>): Promise<T> {
  const next = queue.then(op, op);
  queue = next.catch(() => undefined);
  return next;
}

export function append(point: PositionInput): Promise<number> {
  return serialize(async () => {
    const points = (await load()).slice();
    points.push(point);
    const trimmed = points.length > MAX_POINTS ? points.slice(points.length - MAX_POINTS) : points;
    await persist(trimmed);
    return trimmed.length;
  });
}

/** Los primeros `limit` puntos, en orden cronológico, sin sacarlos del buffer. */
export function peek(limit: number): Promise<PositionInput[]> {
  return serialize(async () => (await load()).slice(0, limit));
}

/** Quita del frente los `count` puntos ya confirmados por el servidor. */
export function drop(count: number): Promise<void> {
  return serialize(async () => {
    const points = await load();
    await persist(points.slice(count));
  });
}

export function size(): Promise<number> {
  return serialize(async () => (await load()).length);
}

export function clear(): Promise<void> {
  return serialize(async () => {
    await persist([]);
  });
}
