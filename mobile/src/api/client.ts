/**
 * Cliente HTTP de la API de la app.
 *
 * Sin dependencias: `fetch` alcanza y en un WebView viejo cada KB de bundle
 * cuenta. El token va por `Authorization: Bearer`; no hay cookies, así que
 * tampoco hay CSRF (el backend exime `/api/app/*`).
 */

import type {
  Envelope,
  LoginPayload,
  Mission,
  Platform,
  PositionInput,
  PositionsResult,
  SyncPayload,
} from './types';

/** Sesión expirada o revocada desde el panel: hay que volver a loguear. */
export class UnauthorizedError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'UnauthorizedError';
  }
}

export class ApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

export interface LoginInput {
  company_slug: string;
  dni: string;
  password: string;
  install_id: string;
  platform: Platform;
  os_version?: string;
  app_version?: string;
  model?: string;
}

export class ApiClient {
  private token: string | null = null;

  constructor(private baseUrl: string) {
    this.baseUrl = baseUrl.replace(/\/+$/, '');
  }

  setToken(token: string | null): void {
    this.token = token;
  }

  setBaseUrl(baseUrl: string): void {
    this.baseUrl = baseUrl.replace(/\/+$/, '');
  }

  getBaseUrl(): string {
    return this.baseUrl;
  }

  // -- Endpoints -------------------------------------------------------------

  login(input: LoginInput): Promise<LoginPayload> {
    return this.request<LoginPayload>('POST', '/api/app/login', input, false);
  }

  logout(): Promise<unknown> {
    return this.request('POST', '/api/app/logout');
  }

  sync(): Promise<SyncPayload> {
    return this.request<SyncPayload>('GET', '/api/app/sync');
  }

  /** Sube un lote de posiciones. El backend descarta lo que caiga fuera de turno. */
  positions(points: PositionInput[]): Promise<PositionsResult> {
    return this.request<PositionsResult>('POST', '/api/app/positions', { points });
  }

  /** El pánico se acepta siempre, dentro o fuera de la jornada. */
  panic(ts: string, lat: number | null, lon: number | null): Promise<unknown> {
    return this.request('POST', '/api/app/panic', { ts, lat, lon });
  }

  event(type: 'low_battery' | 'app_permission_lost' | 'app_permission_ok', extra: Record<string, unknown> = {}): Promise<unknown> {
    return this.request('POST', '/api/app/events', { type, ...extra });
  }

  heartbeat(batteryPct: number | null, permsOk: boolean | null): Promise<unknown> {
    return this.request('POST', '/api/app/heartbeat', { battery_pct: batteryPct, perms_ok: permsOk });
  }

  startMission(id: Mission['id']): Promise<unknown> {
    return this.request('POST', `/api/app/missions/${id}/start`);
  }

  arriveMission(id: Mission['id'], lat: number, lon: number): Promise<unknown> {
    return this.request('POST', `/api/app/missions/${id}/arrive`, { lat, lon });
  }

  // -- Transporte ------------------------------------------------------------

  private async request<T>(
    method: string,
    path: string,
    body?: unknown,
    auth = true,
  ): Promise<T> {
    const headers: Record<string, string> = { Accept: 'application/json' };
    if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
    }
    if (auth) {
      if (!this.token) {
        throw new UnauthorizedError('No hay sesión iniciada.');
      }
      headers.Authorization = `Bearer ${this.token}`;
    }

    let response: Response;
    try {
      response = await fetch(this.baseUrl + path, {
        method,
        headers,
        body: body !== undefined ? JSON.stringify(body) : undefined,
      });
    } catch {
      // Sin red: quien llama decide si reintenta (las posiciones se bufferean).
      throw new ApiError('Sin conexión con el servidor.', 0);
    }

    let payload: Envelope<T> | null = null;
    try {
      payload = (await response.json()) as Envelope<T>;
    } catch {
      payload = null;
    }

    if (response.status === 401) {
      throw new UnauthorizedError(payload?.error ?? 'Sesión cerrada.');
    }
    if (!response.ok || !payload?.ok) {
      throw new ApiError(payload?.error ?? `Error ${response.status}.`, response.status);
    }

    return payload.data as T;
  }
}
