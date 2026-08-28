/**
 * Motor de rastreo: enciende y apaga la captura según la jornada, bufferea los
 * puntos y los sube por lotes.
 *
 * La ubicación en segundo plano la maneja un **plugin nativo**, no el WebView:
 * la app sigue reportando con la pantalla apagada y la UI cerrada. El WebView
 * sólo dibuja la interfaz.
 *
 * Ciclo:
 *   1. Cada `TICK_MS` se evalúa la jornada (`isWithinShift`).
 *   2. Dentro de la jornada arranca el watcher; fuera, lo apaga. No se captura
 *      un solo punto fuera de turno.
 *   3. Cada punto va al buffer en disco.
 *   4. Cada `UPLOAD_MS`, si hay red, se sube un lote y se descarta lo confirmado.
 */

import { Device } from '@capacitor/device';
import { Network } from '@capacitor/network';
import type { ApiClient } from '../api/client';
import { UnauthorizedError } from '../api/client';
import type { PositionInput, TrackingConfig } from '../api/types';
import * as buffer from './buffer';
import { BackgroundGeolocation, type CallbackError, type Location } from './plugin';
import { isWithinShift, toApiTimestamp, type ShiftSchedule } from './shift';

const TICK_MS = 30_000;
const UPLOAD_MS = 60_000;

export interface TrackerStatus {
  onShift: boolean;
  watching: boolean;
  pending: number;
  lastFixAt: Date | null;
  lastUploadAt: Date | null;
  batteryPct: number | null;
  permissionDenied: boolean;
  lastError: string | null;
  /**
   * Última posición conocida, sólo para mostrarle a la persona a qué distancia
   * está de su puesto o de una misión. Se actualiza aunque esté fuera de
   * jornada —así puede verificar el GPS en cualquier momento— pero en ese caso
   * NO se bufferea ni se sube nada: la regla de descartar fuera de turno sigue
   * intacta, esto no sale del teléfono.
   */
  lastLat: number | null;
  lastLon: number | null;
  lastAccuracyM: number | null;
}

export type StatusListener = (status: TrackerStatus) => void;

export class Tracker {
  private watcherId: string | null = null;
  private tickTimer: ReturnType<typeof setInterval> | null = null;
  private uploadTimer: ReturnType<typeof setInterval> | null = null;
  private schedule: ShiftSchedule | null = null;
  private config: TrackingConfig | null = null;
  private listeners: StatusListener[] = [];
  private uploading = false;

  private status: TrackerStatus = {
    onShift: false,
    watching: false,
    pending: 0,
    lastFixAt: null,
    lastUploadAt: null,
    batteryPct: null,
    permissionDenied: false,
    lastError: null,
    lastLat: null,
    lastLon: null,
    lastAccuracyM: null,
  };

  constructor(
    private api: ApiClient,
    /** Se avisa cuando el servidor rechaza la sesión (login en otro teléfono). */
    private onSessionLost: () => void,
  ) {}

  onStatus(listener: StatusListener): () => void {
    this.listeners.push(listener);
    listener(this.status);
    return () => {
      this.listeners = this.listeners.filter((l) => l !== listener);
    };
  }

  getStatus(): TrackerStatus {
    return this.status;
  }

  private emit(patch: Partial<TrackerStatus>): void {
    this.status = { ...this.status, ...patch };
    for (const listener of this.listeners) listener(this.status);
  }

  /** Config de jornada y muestreo. Se llama al loguear y en cada `/sync`. */
  configure(schedule: ShiftSchedule, config: TrackingConfig): void {
    this.schedule = schedule;
    this.config = config;
    void this.evaluate();
  }

  start(): void {
    if (this.tickTimer) return;
    this.tickTimer = setInterval(() => void this.evaluate(), TICK_MS);
    this.uploadTimer = setInterval(() => void this.flush(), UPLOAD_MS);
    void this.evaluate();
    void this.flush();
  }

  async stop(): Promise<void> {
    if (this.tickTimer) clearInterval(this.tickTimer);
    if (this.uploadTimer) clearInterval(this.uploadTimer);
    this.tickTimer = null;
    this.uploadTimer = null;
    await this.stopWatcher();
  }

  // -- Jornada ---------------------------------------------------------------

  /** Enciende o apaga la captura según corresponda en este momento. */
  private async evaluate(): Promise<void> {
    if (!this.schedule) return;

    const onShift = isWithinShift(this.schedule);
    if (onShift !== this.status.onShift) {
      this.emit({ onShift });
    }

    if (onShift && !this.watcherId) {
      await this.startWatcher();
    } else if (!onShift && this.watcherId) {
      // Fuera de turno no se captura NADA: es la regla del módulo.
      await this.stopWatcher();
    }

    void this.refreshBattery();
    this.emit({ pending: await buffer.size() });
  }

  private async startWatcher(): Promise<void> {
    if (this.watcherId) return;

    try {
      const id = await BackgroundGeolocation.addWatcher(
        {
          // Android exige una notificación persistente para el foreground service.
          backgroundMessage: 'Satrak registra tu ubicación durante tu jornada.',
          backgroundTitle: 'Satrak Campo · en jornada',
          requestPermissions: true,
          stale: false,
          distanceFilter: 15,
        },
        (location?: Location, error?: CallbackError) => {
          if (error) {
            const denied = error.code === 'NOT_AUTHORIZED';
            this.emit({ permissionDenied: denied, lastError: error.message ?? String(error) });
            if (denied) {
              void this.api.event('app_permission_lost').catch(() => undefined);
            }
            return;
          }
          if (!location) return;
          void this.record(location);
        },
      );
      this.watcherId = id;
      this.emit({ watching: true, permissionDenied: false, lastError: null });
    } catch (e) {
      this.emit({ watching: false, lastError: e instanceof Error ? e.message : String(e) });
    }
  }

  private async stopWatcher(): Promise<void> {
    if (!this.watcherId) return;
    const id = this.watcherId;
    this.watcherId = null;
    try {
      await BackgroundGeolocation.removeWatcher({ id });
    } catch {
      // Si ya no existía, da igual: el objetivo es que no quede capturando.
    }
    this.emit({ watching: false });
  }

  // -- Captura ---------------------------------------------------------------

  private async record(location: Location): Promise<void> {
    if (!this.schedule) return;

    const at = location.time ? new Date(location.time) : new Date();

    // La posición para mostrar en pantalla se refresca siempre, dentro o fuera
    // de jornada: sirve para que la persona verifique su GPS. Queda en memoria
    // del teléfono; lo que se descarta fuera de turno es guardarla y subirla.
    this.emit({
      lastLat: round(location.latitude, 7),
      lastLon: round(location.longitude, 7),
      lastAccuracyM: location.accuracy !== null ? Math.round(location.accuracy) : null,
    });

    // Doble control: entre que llegó el fix y se procesa pudo terminar el turno.
    if (!isWithinShift(this.schedule, at)) return;

    const point: PositionInput = {
      ts: toApiTimestamp(at, this.schedule.offsetMinutes),
      lat: round(location.latitude, 7),
      lon: round(location.longitude, 7),
      // El plugin da m/s; la API espera km/h.
      speed: location.speed !== null ? Math.max(0, Math.round(location.speed * 3.6)) : null,
      heading: location.bearing !== null ? Math.round(location.bearing) : null,
      accuracy_m: location.accuracy !== null ? Math.round(location.accuracy) : null,
      battery_pct: this.status.batteryPct,
    };

    const pending = await buffer.append(point);
    this.emit({ pending, lastFixAt: at });
  }

  // -- Subida ----------------------------------------------------------------

  /** Sube un lote si hay red y algo pendiente. Reentrante-seguro. */
  async flush(): Promise<void> {
    if (this.uploading) return;

    const { connected } = await Network.getStatus();
    if (!connected) return;

    const max = this.config?.max_batch ?? 200;
    const batch = await buffer.peek(max);
    if (batch.length === 0) return;

    this.uploading = true;
    try {
      await this.api.positions(batch);
      // Sólo se descarta lo que el servidor confirmó haber recibido.
      await buffer.drop(batch.length);
      this.emit({ pending: await buffer.size(), lastUploadAt: new Date(), lastError: null });
    } catch (e) {
      if (e instanceof UnauthorizedError) {
        await this.stop();
        this.onSessionLost();
        return;
      }
      // Se deja el lote en el buffer para el próximo intento.
      this.emit({ lastError: e instanceof Error ? e.message : String(e) });
    } finally {
      this.uploading = false;
    }
  }

  private async refreshBattery(): Promise<void> {
    try {
      const info = await Device.getBatteryInfo();
      // iOS devuelve -1 cuando el nivel no está disponible (simulador, o un
      // equipo con el monitoreo de batería apagado). Sin este control se
      // mostraba «-100%» y se mandaba un negativo a un TINYINT UNSIGNED.
      const raw = info.batteryLevel !== undefined ? Math.round(info.batteryLevel * 100) : null;
      const pct = raw !== null && raw >= 0 && raw <= 100 ? raw : null;
      if (pct !== this.status.batteryPct) {
        this.emit({ batteryPct: pct });
      }
    } catch {
      // Sin batería reportable (navegador de escritorio): no es un problema.
    }
  }
}

function round(value: number, decimals: number): number {
  const f = Math.pow(10, decimals);
  return Math.round(value * f) / f;
}
