/**
 * Registro del plugin nativo de geolocalización en segundo plano.
 *
 * El paquete `@capacitor-community/background-geolocation` sólo publica tipos:
 * la instancia se obtiene con `registerPlugin`. Se centraliza acá para que el
 * resto del código lo importe tipado y en un solo lugar.
 */

import { registerPlugin } from '@capacitor/core';
import type { BackgroundGeolocationPlugin } from '@capacitor-community/background-geolocation';

export type { Location, CallbackError } from '@capacitor-community/background-geolocation';

export const BackgroundGeolocation =
  registerPlugin<BackgroundGeolocationPlugin>('BackgroundGeolocation');

/** Abre los ajustes del sistema para que la persona corrija los permisos. */
export async function openAppSettings(): Promise<void> {
  try {
    await BackgroundGeolocation.openSettings();
  } catch {
    // En el navegador no existe: no es un error que le importe al usuario.
  }
}
