import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

/**
 * Vite marca los assets con `crossorigin`. En Android da igual (el WebView
 * sirve desde http://localhost), pero iOS sirve desde `capacitor://localhost`:
 * un esquema propio cuyo handler de WKWebView no devuelve headers CORS, así
 * que el WebView descarta el script y el CSS EN SILENCIO y queda la pantalla
 * en blanco, sin error en consola. Sacar el atributo es la única forma.
 */
const stripCrossorigin = {
  name: 'strip-crossorigin',
  transformIndexHtml(html: string) {
    return html.replace(/\s+crossorigin/g, '');
  },
};

export default defineConfig({
  plugins: [react(), stripCrossorigin],
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
