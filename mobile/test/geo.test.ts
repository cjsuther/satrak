/**
 * Distancias que ve la persona en pantalla.
 *
 * Es una ayuda visual, no una validación —la llegada la decide el servidor—
 * pero si miente, la persona confía en un dato equivocado justo cuando está
 * tratando de verificar que su GPS anda bien. El caso más peligroso es que
 * diga «estás adentro» cuando está afuera.
 */

import { describe, expect, it } from 'vitest';
import { distanceToGeofence, formatDistance, haversineMeters } from '../src/tracking/geo';
import type { Geometry } from '../src/api/types';

/** Polígono real de la demo, con el primer vértice duplicado por un doble clic. */
const TRABAJO_ANA: Array<[number, number]> = [
  [-34.5631168, -58.4560633],
  [-34.5631168, -58.4560633],
  [-34.5631521, -58.4562349],
  [-34.5644951, -58.458252],
  [-34.5665448, -58.4568787],
  [-34.5651312, -58.4544325],
];

const BASE_NEUQUEN: Geometry = { lat: -38.9396, lon: -68.0676, radius_m: 600 };

describe('haversineMeters', () => {
  it('da cero entre el mismo punto', () => {
    expect(haversineMeters({ lat: -34.6, lon: -58.38 }, { lat: -34.6, lon: -58.38 })).toBe(0);
  });

  it('un grado de latitud son ~111,2 km', () => {
    const m = haversineMeters({ lat: 0, lon: 0 }, { lat: 1, lon: 0 });
    expect(m).toBeGreaterThan(111_100);
    expect(m).toBeLessThan(111_300);
  });

  it('un grado de longitud se acorta con el coseno de la latitud', () => {
    const ecuador = haversineMeters({ lat: 0, lon: 0 }, { lat: 0, lon: 1 });
    const a60 = haversineMeters({ lat: 60, lon: 0 }, { lat: 60, lon: 1 });
    expect(a60).toBeCloseTo(ecuador / 2, -3);
  });

  it('es simétrica', () => {
    const a = { lat: -34.6, lon: -58.38 };
    const b = { lat: -38.95, lon: -68.05 };
    expect(haversineMeters(a, b)).toBeCloseTo(haversineMeters(b, a), 6);
  });

  it('cruza el antimeridiano sin dar la vuelta al mundo', () => {
    const m = haversineMeters({ lat: 0, lon: 179 }, { lat: 0, lon: -179 });
    expect(m).toBeGreaterThan(220_000);
    expect(m).toBeLessThan(225_000);
  });

  /**
   * El mismo par de puntos que el test de PHP: cliente y servidor tienen que
   * medir igual, o la app dirá una distancia y el backend decidirá con otra.
   */
  it('coincide con el haversine del backend (Buenos Aires → Neuquén ≈ 987 km)', () => {
    const km = haversineMeters({ lat: -34.6037, lon: -58.3816 }, { lat: -38.9516, lon: -68.0591 }) / 1000;
    expect(km).toBeGreaterThan(972);
    expect(km).toBeLessThan(1002);
  });
});

describe('distanceToGeofence · círculo', () => {
  it('el centro está adentro y a cero metros', () => {
    const d = distanceToGeofence({ lat: -38.9396, lon: -68.0676 }, 'circle', BASE_NEUQUEN);
    expect(d).toEqual({ meters: 0, inside: true });
  });

  it('mide hasta el BORDE, no hasta el centro', () => {
    // 1 km al norte del centro, radio 600 m → ~400 m del borde.
    const d = distanceToGeofence({ lat: -38.9306, lon: -68.0676 }, 'circle', BASE_NEUQUEN);
    expect(d?.inside).toBe(false);
    expect(d!.meters).toBeGreaterThan(330);
    expect(d!.meters).toBeLessThan(470);
  });

  it('devuelve null si faltan las coordenadas del centro', () => {
    expect(distanceToGeofence({ lat: 0, lon: 0 }, 'circle', { radius_m: 100 })).toBeNull();
  });

  it('devuelve null si le pasan una lista de vértices como si fuera círculo', () => {
    expect(distanceToGeofence({ lat: 0, lon: 0 }, 'circle', TRABAJO_ANA)).toBeNull();
  });

  it('sin radio, todo punto que no sea el centro queda afuera', () => {
    const d = distanceToGeofence({ lat: -38.94, lon: -68.07 }, 'circle', { lat: -38.9396, lon: -68.0676 });
    expect(d?.inside).toBe(false);
    expect(d!.meters).toBeGreaterThan(0);
  });
});

describe('distanceToGeofence · polígono', () => {
  it('la última posición real de Ana cae adentro', () => {
    const d = distanceToGeofence({ lat: -34.5646477, lon: -58.4558096 }, 'polygon', TRABAJO_ANA);
    expect(d).toEqual({ meters: 0, inside: true });
  });

  it('un punto al norte queda afuera, con distancia al borde', () => {
    const d = distanceToGeofence({ lat: -34.555, lon: -58.4558 }, 'polygon', TRABAJO_ANA);
    expect(d?.inside).toBe(false);
    expect(d!.meters).toBeGreaterThan(300);
  });

  it('el vértice duplicado no altera el resultado', () => {
    const sinDuplicado = TRABAJO_ANA.slice(1);
    const punto = { lat: -34.5646477, lon: -58.4558096 };

    expect(distanceToGeofence(punto, 'polygon', TRABAJO_ANA))
      .toEqual(distanceToGeofence(punto, 'polygon', sinDuplicado));
  });

  it('devuelve null con menos de tres vértices', () => {
    expect(distanceToGeofence({ lat: 0, lon: 0 }, 'polygon', [])).toBeNull();
    expect(distanceToGeofence({ lat: 0, lon: 0 }, 'polygon', [[0, 0]])).toBeNull();
    expect(distanceToGeofence({ lat: 0, lon: 0 }, 'polygon', [[0, 0], [1, 1]])).toBeNull();
  });

  it('descarta vértices malformados en vez de romper', () => {
    const sucio = [
      [-34.5631168, -58.4560633],
      null,
      ['x'],
      [-34.5665448, -58.4568787],
      [-34.5651312, -58.4544325],
    ] as unknown as Array<[number, number]>;

    expect(() => distanceToGeofence({ lat: -34.564, lon: -58.455 }, 'polygon', sucio)).not.toThrow();
  });

  /** Un polígono cóncavo: el hueco no está adentro aunque esté en el bounding box. */
  it('un polígono en forma de C excluye su hueco', () => {
    const c: Array<[number, number]> = [
      [0, 0], [0, 3], [1, 3], [1, 1], [2, 1], [2, 3], [3, 3], [3, 0],
    ];
    expect(distanceToGeofence({ lat: 0.5, lon: 1.5 }, 'polygon', c)?.inside).toBe(true);
    expect(distanceToGeofence({ lat: 1.5, lon: 2.0 }, 'polygon', c)?.inside).toBe(false);
  });

  /** El simulador reporta desde San Francisco: ~10.000 km de Buenos Aires. */
  it('resuelve distancias intercontinentales', () => {
    const d = distanceToGeofence({ lat: 37.785834, lon: -122.406417 }, 'polygon', TRABAJO_ANA);
    expect(d!.meters).toBeGreaterThan(9_000_000);
  });
});

describe('formatDistance', () => {
  it('no finge precisión que el GPS no tiene', () => {
    // Con ±20 m de error típico, «237 m» sería una exactitud inventada.
    expect(formatDistance(4)).toBe('menos de 10 m');
    expect(formatDistance(9.9)).toBe('menos de 10 m');
    expect(formatDistance(237)).toBe('240 m');
    expect(formatDistance(995)).toBe('1000 m');
  });

  it('pasa a kilómetros con coma decimal (es-AR)', () => {
    expect(formatDistance(1000)).toBe('1,0 km');
    expect(formatDistance(2350)).toBe('2,4 km');
    expect(formatDistance(9999)).toBe('10,0 km');
  });

  it('sobre 10 km redondea a entero', () => {
    expect(formatDistance(15_400)).toBe('15 km');
    expect(formatDistance(983_000)).toBe('983 km');
  });

  it('nunca devuelve un valor negativo ni NaN para entradas razonables', () => {
    for (const m of [0, 1, 10, 999, 1000, 100_000]) {
      expect(formatDistance(m)).not.toMatch(/NaN|-/);
    }
  });
});
