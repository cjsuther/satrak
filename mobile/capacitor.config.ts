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
  },
};

export default config;
