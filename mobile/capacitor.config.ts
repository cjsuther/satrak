import type { CapacitorConfig } from '@capacitor/cli';

const config: CapacitorConfig = {
  appId: 'online.satrak.campo',
  appName: 'Satrak Campo',
  webDir: 'dist',
  // Android 5.1 (API 22) / iOS 13: el piso que fija Capacitor 6. Ver
  // application/satrak-modulo-personas-spec.md §9.
  android: {
    allowMixedContent: false,
  },
  plugins: {
    BackgroundGeolocation: {},
    // Redirige fetch/XHR al HTTP nativo. Sin esto el WebView (origen
    // http://localhost) hace una petición cross-origin contra la API y la
    // bloquea por CORS: el backend no responde OPTIONS ni emite
    // Access-Control-Allow-*. Por nativo no hay CORS y no hay que abrir la API.
    CapacitorHttp: {
      enabled: true,
    },
  },
};

export default config;
