import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  build: {
    // Techo ES2015 y bundle chico: el WebView de un Android 5/6 sin actualizar
    // no digiere sintaxis moderna, y la app tiene que abrir rápido en equipos lentos.
    target: 'es2015',
    outDir: 'dist',
    sourcemap: false,
  },
  server: {
    port: 5173,
    // En `npm run dev` la API se proxea al backend local para probar contra
    // el Slim de `application/` sin tocar CORS.
    proxy: {
      '/api': { target: 'http://127.0.0.1:8099', changeOrigin: true },
    },
  },
});
