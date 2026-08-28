/**
 * Contrato de `/api/app/*` (ver application/satrak-modulo-personas-spec.md §5).
 *
 * Estos tipos son el espejo de lo que devuelve el backend: si cambia allá,
 * cambia acá. Todas las respuestas vienen con el envelope { ok, data, error }.
 */

export interface Envelope<T> {
  ok: boolean;
  data: T | null;
  error: string | null;
}

export type Platform = 'android' | 'ios';

export interface ShiftWindow {
  /** 1 = lunes … 7 = domingo (ISO-8601) */
  weekday: number;
  /** 'HH:MM' */
  from: string;
  /** 'HH:MM'. Si es <= `from`, la ventana cruza la medianoche. */
  to: string;
}

export interface ShiftException {
  /** 'YYYY-MM-DD' */
  date: string;
  kind: 'off' | 'extra';
  from: string | null;
  to: string | null;
}

export interface Geometry {
  lat?: number;
  lon?: number;
  radius_m?: number;
  [key: string]: unknown;
}

export interface Post {
  geofence_id: number;
  name: string;
  shape: 'circle' | 'polygon';
  geometry: Geometry | Array<[number, number]>;
  grace_min: number;
}

export type MissionStatus = 'pending' | 'in_progress' | 'completed' | 'missed' | 'cancelled';

export interface MissionPlace {
  geofence_id: number;
  name: string;
  shape: 'circle' | 'polygon';
  geometry: Geometry | Array<[number, number]>;
}

export interface Mission {
  id: number;
  status: MissionStatus;
  scheduled_start: string;
  scheduled_end: string;
  notes: string | null;
  destination: MissionPlace;
  /** Opcional: no todas las misiones tienen origen cargado. */
  origin: MissionPlace | null;
}

export interface TrackingConfig {
  moving_sample_seconds: number;
  stopped_sample_seconds: number;
  max_batch: number;
  /**
   * Lo habilita la empresa. Puede faltar si el servidor es anterior a esta
   * opción: en ese caso se asume habilitado, que era el comportamiento previo.
   */
  panic_enabled?: boolean;
}

/** Lo que devuelven tanto `/login` como `/sync`. */
export interface SyncPayload {
  server_time: string;
  timezone: string;
  shifts: ShiftWindow[];
  shift_exceptions: ShiftException[];
  post: Post | null;
  missions: Mission[];
  config: TrackingConfig;
}

export interface LoginPayload extends SyncPayload {
  token: string;
  session: { id: number; device_id: number };
  person: { id: number; first_name: string; last_name: string; company: string };
}

export interface PositionInput {
  /** 'YYYY-MM-DD HH:MM:SS' en la hora del equipo */
  ts: string;
  lat: number;
  lon: number;
  speed: number | null;
  heading: number | null;
  accuracy_m: number | null;
  battery_pct: number | null;
}

export interface PositionsResult {
  received: number;
  stored: number;
  duplicated: number;
  invalid: number;
  out_of_shift: number;
}
