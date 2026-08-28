/**
 * Distancias contra las geocercas que manda el servidor.
 *
 * Existe para que la persona pueda VERIFICAR su ubicación: ver «estás a 120 m
 * de tu puesto» es la forma más directa de darse cuenta de que el GPS agarró
 * mal, sin tener que abrir un mapa ni saber leer coordenadas.
 *
 * Todo el cálculo es local: no se manda nada al servidor ni se guarda. La
 * validación real de llegada la sigue haciendo el backend (`GeofenceMath`);
 * esto es sólo una ayuda visual y no debe usarse para decidir nada.
 */

import type { Geometry } from '../api/types';

export interface LatLon {
  lat: number;
  lon: number;
}

export type Shape = 'circle' | 'polygon';

const EARTH_RADIUS_M = 6_371_000;

const toRad = (deg: number): number => (deg * Math.PI) / 180;

/** Distancia en metros entre dos puntos sobre la esfera. */
export function haversineMeters(a: LatLon, b: LatLon): number {
  const dLat = toRad(b.lat - a.lat);
  const dLon = toRad(b.lon - a.lon);
  const lat1 = toRad(a.lat);
  const lat2 = toRad(b.lat);

  const h =
    Math.sin(dLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLon / 2) ** 2;

  return 2 * EARTH_RADIUS_M * Math.asin(Math.min(1, Math.sqrt(h)));
}

/**
 * Proyección plana local en metros, con el origen en `ref`. A las distancias
 * que maneja una geocerca (cientos de metros) el error es despreciable, y
 * permite tratar el polígono con geometría cartesiana simple.
 */
function toPlane(p: LatLon, ref: LatLon): { x: number; y: number } {
  const mPerDegLat = 111_320;
  const mPerDegLon = 111_320 * Math.cos(toRad(ref.lat));

  return {
    x: (p.lon - ref.lon) * mPerDegLon,
    y: (p.lat - ref.lat) * mPerDegLat,
  };
}

/** Distancia de un punto al segmento AB, todo en metros del plano local. */
function pointToSegment(
  p: { x: number; y: number },
  a: { x: number; y: number },
  b: { x: number; y: number },
): number {
  const dx = b.x - a.x;
  const dy = b.y - a.y;
  const lenSq = dx * dx + dy * dy;

  // Segmento degenerado (vértices repetidos): es un punto.
  if (lenSq === 0) return Math.hypot(p.x - a.x, p.y - a.y);

  let t = ((p.x - a.x) * dx + (p.y - a.y) * dy) / lenSq;
  t = Math.max(0, Math.min(1, t));

  return Math.hypot(p.x - (a.x + t * dx), p.y - (a.y + t * dy));
}

/** Ray casting sobre el plano local. */
function insidePolygon(p: { x: number; y: number }, ring: Array<{ x: number; y: number }>): boolean {
  let inside = false;
  for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
    const yi = ring[i].y;
    const yj = ring[j].y;
    if (yi > p.y !== yj > p.y) {
      const xAt = ((ring[j].x - ring[i].x) * (p.y - yi)) / (yj - yi) + ring[i].x;
      if (p.x < xAt) inside = !inside;
    }
  }

  return inside;
}

/** Normaliza los dos formatos que manda el backend a una lista de puntos. */
function toRing(geometry: Geometry | Array<[number, number]>): LatLon[] {
  if (!Array.isArray(geometry)) return [];

  return geometry
    .filter((v) => Array.isArray(v) && v.length >= 2)
    .map(([lat, lon]) => ({ lat: Number(lat), lon: Number(lon) }))
    .filter((p) => Number.isFinite(p.lat) && Number.isFinite(p.lon));
}

export interface GeofenceDistance {
  /** Metros hasta el borde. 0 si está adentro. */
  meters: number;
  inside: boolean;
}

/**
 * Distancia al borde de una geocerca. `null` si la geometría no se entiende
 * (mejor no mostrar nada que mostrar un número inventado).
 */
export function distanceToGeofence(
  from: LatLon,
  shape: Shape,
  geometry: Geometry | Array<[number, number]>,
): GeofenceDistance | null {
  if (shape === 'circle') {
    if (Array.isArray(geometry)) return null;
    const lat = Number(geometry?.lat);
    const lon = Number(geometry?.lon);
    const radius = Number(geometry?.radius_m ?? 0);
    if (!Number.isFinite(lat) || !Number.isFinite(lon)) return null;

    const toCenter = haversineMeters(from, { lat, lon });
    const inside = toCenter <= radius;

    return { meters: inside ? 0 : toCenter - radius, inside };
  }

  const ring = toRing(geometry);
  if (ring.length < 3) return null;

  const p = toPlane(from, from);
  const plane = ring.map((v) => toPlane(v, from));
  const inside = insidePolygon(p, plane);
  if (inside) return { meters: 0, inside: true };

  let min = Infinity;
  for (let i = 0, j = plane.length - 1; i < plane.length; j = i++) {
    min = Math.min(min, pointToSegment(p, plane[j], plane[i]));
  }

  return Number.isFinite(min) ? { meters: min, inside: false } : null;
}

/**
 * Texto corto para pantalla. Redondea con la precisión que el dato soporta: el
 * GPS de un celular ronda los 10-30 m, así que decir «237 m» es fingir una
 * exactitud que no existe.
 */
export function formatDistance(meters: number): string {
  if (meters < 10) return 'menos de 10 m';
  if (meters < 1000) return `${Math.round(meters / 10) * 10} m`;
  if (meters < 10_000) return `${(meters / 1000).toFixed(1).replace('.', ',')} km`;

  return `${Math.round(meters / 1000)} km`;
}
