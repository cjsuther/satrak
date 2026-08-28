/**
 * Empaqueta las librerías de mapa a `public/assets/vendor/`.
 *
 * Por qué existe: la plataforma web es PHP + Twig sin bundler, y hasta ahora
 * cargaba Leaflet desde unpkg. MapLibre publica un UMD que se puede copiar tal
 * cual, pero Terra Draw, su adapter y turf son ESM: sin empaquetarlas habría
 * que traerlas de un CDN de ESM y ensanchar la CSP. Se sirven desde el propio
 * dominio para que `script-src` pueda quedar en 'self'.
 *
 * Los archivos generados SÍ se versionan: el hosting no corre ningún paso de
 * build en el deploy.
 */
import * as esbuild from 'esbuild';
import { copyFileSync, mkdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const out = resolve(root, 'public/assets/vendor');
mkdirSync(out, { recursive: true });

const kb = (p) => `${(statSync(p).size / 1024).toFixed(0)} kB`;

// 1) MapLibre: UMD listo para usar, se copia sin tocar. Expone `maplibregl`.
for (const f of ['maplibre-gl.js', 'maplibre-gl.css']) {
  const from = resolve(root, 'node_modules/maplibre-gl/dist', f);
  copyFileSync(from, resolve(out, f));
  console.log(`  maplibre-gl → ${f}  (${kb(resolve(out, f))})`);
}

// 2) Terra Draw + adapter + turf: ESM, se empaquetan en un global `SatrakDraw`.
const bundle = resolve(out, 'terra-draw.js');
await esbuild.build({
  stdin: {
    contents: `
      export { TerraDraw, TerraDrawPolygonMode, TerraDrawFreehandMode,
               TerraDrawCircleMode, TerraDrawRectangleMode, TerraDrawSelectMode,
               TerraDrawRenderMode } from 'terra-draw';
      export { TerraDrawMapLibreGLAdapter } from 'terra-draw-maplibre-gl-adapter';
      export { default as booleanPointInPolygon } from '@turf/boolean-point-in-polygon';
    `,
    resolveDir: root,
    loader: 'js',
  },
  bundle: true,
  format: 'iife',
  globalName: 'SatrakDraw',
  target: ['es2019'],
  minify: true,
  outfile: bundle,
  legalComments: 'none',
});
console.log(`  terra-draw + adapter + turf → terra-draw.js  (${kb(bundle)})`);

const version = JSON.parse(readFileSync(resolve(root, 'node_modules/maplibre-gl/package.json'), 'utf8')).version;
console.log(`\nlisto · maplibre-gl ${version}`);
