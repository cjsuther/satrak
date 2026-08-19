/**
 * Sesión persistida de la app: token, persona, empresa e `install_id`.
 *
 * El `install_id` identifica esta instalación en el sistema (ocupa el lugar del
 * IMEI en `devices`). Se genera una vez y se conserva: si cambiara en cada
 * arranque, cada login crearía un dispositivo nuevo en el panel.
 */

import { Device } from '@capacitor/device';
import { Preferences } from '@capacitor/preferences';
import type { LoginPayload, Platform } from '../api/types';

const KEY_SESSION = 'satrak.session';
const KEY_INSTALL = 'satrak.install_id';
const KEY_BASE_URL = 'satrak.base_url';

/**
 * Backend por defecto.
 *
 * En `npm run dev` queda vacío a propósito: las llamadas salen relativas y las
 * toma el proxy de Vite hacia el backend local. Apuntar a producción desde el
 * navegador daría CORS, porque la API no manda cabeceras cross-origin (la app
 * empaquetada no las necesita: Capacitor sirve desde el propio origen).
 */
export const DEFAULT_BASE_URL = import.meta.env.DEV ? '' : 'https://app.satrak.online';

export interface StoredSession {
  token: string;
  companySlug: string;
  person: LoginPayload['person'];
}

export async function loadSession(): Promise<StoredSession | null> {
  const { value } = await Preferences.get({ key: KEY_SESSION });
  if (!value) return null;
  try {
    return JSON.parse(value) as StoredSession;
  } catch {
    return null;
  }
}

export async function saveSession(session: StoredSession): Promise<void> {
  await Preferences.set({ key: KEY_SESSION, value: JSON.stringify(session) });
}

export async function clearSession(): Promise<void> {
  await Preferences.remove({ key: KEY_SESSION });
}

/** Id de instalación estable. Se crea la primera vez y no cambia. */
export async function getInstallId(): Promise<string> {
  const stored = await Preferences.get({ key: KEY_INSTALL });
  if (stored.value) return stored.value;

  let id: string;
  try {
    id = (await Device.getId()).identifier;
  } catch {
    id = randomId();
  }
  if (!id) id = randomId();

  await Preferences.set({ key: KEY_INSTALL, value: id });
  return id;
}

/** URL del backend. Configurable para poder apuntar a un entorno de prueba. */
export async function getBaseUrl(): Promise<string> {
  const { value } = await Preferences.get({ key: KEY_BASE_URL });
  return value || DEFAULT_BASE_URL;
}

export async function setBaseUrl(url: string): Promise<void> {
  await Preferences.set({ key: KEY_BASE_URL, value: url });
}

export interface DeviceInfo {
  platform: Platform;
  osVersion: string;
  model: string;
}

export async function getDeviceInfo(): Promise<DeviceInfo> {
  try {
    const info = await Device.getInfo();
    const platform: Platform = info.platform === 'ios' ? 'ios' : 'android';
    return {
      platform,
      osVersion: info.osVersion ?? '',
      model: [info.manufacturer, info.model].filter(Boolean).join(' ').trim(),
    };
  } catch {
    // En el navegador (dev) no hay plugin nativo: se asume android.
    return { platform: 'android', osVersion: '', model: 'navegador' };
  }
}

function randomId(): string {
  return 'web-' + Math.random().toString(36).slice(2) + Date.now().toString(36);
}
