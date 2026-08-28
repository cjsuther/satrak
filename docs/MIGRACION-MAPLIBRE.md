# Migración de mapas: Leaflet/OSM raster → MapLibre GL + Terra Draw

Spec de implementación. Las decisiones de stack ya están tomadas y validadas con un
prototipo funcional — no re-evaluar alternativas, no proponer Google Maps ni Mapbox.
Implementar exactamente lo que se describe acá, adaptándolo a las convenciones de
este repo.

## Stack decidido (versiones validadas)

| Pieza | Paquete | Versión | Rol |
|---|---|---|---|
| Renderer | `maplibre-gl` | ^5 (validado con 5.24) | Mapa vectorial, GPU, sin API key |
| Dibujo de zonas | `terra-draw` | ^1.32 | Modos polígono / mano alzada / círculo / rectángulo / select |
| Adapter | `terra-draw-maplibre-gl-adapter` | ^1.4 | Puente Terra Draw ↔ MapLibre |
| Geometría client-side | `@turf/boolean-point-in-polygon` | ^7 | Preview de matches en el cliente (NO instalar `@turf/turf` completo) |
| Tiles | OpenFreeMap | — | `https://tiles.openfreemap.org/styles/positron` (o `liberty` si se quiere más color). Gratis, sin registro, sin límites |

**Prohibido:** `mapbox-gl` (licencia), `@mapbox/mapbox-gl-draw` (incompatible con
MapLibre moderno), `leaflet-draw` (es lo que estamos abandonando), tiles raster de
`tile.openstreetmap.org` (uso restringido para producción).

## Arquitectura

1. **Un componente de mapa reutilizable** (`ZoneMap` o el nombre que siga la
   convención del repo) que encapsula: init de MapLibre, init de Terra Draw,
   toolbar de modos, y expone callbacks `onZonesChange(featureCollection)` y
   `onFeatureClick(feature)`. Nada de lógica de negocio adentro.
2. **Datos como GeoJSON source + capas `circle`**, nunca marcadores DOM
   individuales (no escala). Estado visual vía feature properties + expresiones.
3. **Las zonas dibujadas se serializan como `FeatureCollection` de Polygons** y
   son el payload hacia el backend. Círculos y mano alzada llegan YA como
   polígonos — el backend trata todo uniforme, sin casos especiales.
4. **Filtrado definitivo server-side** (ver contrato). El client-side con turf es
   solo para feedback inmediato mientras se dibuja.

## Detalles de implementación validados (no descubrir de nuevo)

- Inicializar Terra Draw **dentro de `map.on("load")`**, nunca antes.
- Expresiones MapLibre con propiedades booleanas: usar SIEMPRE
  `["case", ["boolean", ["get", "prop"], false], A, B]`. El `["get"]` pelado
  dentro de `case` tira `Expected boolean but found value instead`.
- Suscribirse a `draw.on("change")` con throttle vía `requestAnimationFrame`
  (dispara en cada mousemove durante el dibujo).
- `draw.getSnapshot()` devuelve también features auxiliares: filtrar por
  `f.geometry.type === "Polygon"` antes de usar.
- Envolver `booleanPointInPolygon` en try/catch: durante el dibujo hay polígonos
  transitorios inválidos (< 4 vértices).
- Select mode con flags:
  ```js
  new TerraDrawSelectMode({
    flags: {
      polygon:   { feature: { draggable: true, coordinates: { midpoints: true, draggable: true, deletable: true } } },
      freehand:  { feature: { draggable: true } },
      circle:    { feature: { draggable: true } },
      rectangle: { feature: { draggable: true, coordinates: { draggable: true } } },
    },
  })
  ```
- Estilos por modo (mismo objeto para los 4 modos de dibujo):
  `{ fillColor, fillOpacity, outlineColor, outlineWidth }` — colores en hex.
  Tomar los colores del design system del repo.
- MapLibre usa Web Workers: funciona en cualquier hosting normal, pero NO en
  sandboxes que bloquean workers desde blob URLs.
- En React: instanciar el mapa en un `useEffect` con cleanup (`map.remove()`),
  guardar `map` y `draw` en refs, NUNCA en estado (re-render destruye el mapa).
  Si el repo ya usa `react-map-gl`, usar su build para MapLibre
  (`react-map-gl/maplibre`); si no, integración directa — no agregar wrapper
  solo para esto.

## Contrato backend

Endpoint de búsqueda existente extendido (o nuevo) que acepta:

```json
{ "zonas": { "type": "FeatureCollection", "features": [ { "type": "Feature", "geometry": { "type": "Polygon", "coordinates": [...] } } ] } }
```

Semántica: match si el punto cae dentro de **al menos una** zona (OR entre zonas).

Con PostGIS (preferido — habilitar extensión si no está):

```sql
SELECT ... FROM propiedades p
WHERE EXISTS (
  SELECT 1 FROM jsonb_array_elements(:zonas::jsonb -> 'features') AS z
  WHERE ST_Contains(
    ST_SetSRID(ST_GeomFromGeoJSON(z -> 'geometry'), 4326),
    p.ubicacion  -- geometry(Point, 4326)
  )
);
```

Índice: `CREATE INDEX ... USING GIST (ubicacion);` si no existe.

Validación server-side del payload: máximo 10 zonas, máximo 500 vértices por
zona, coordenadas dentro de bounds razonables del país. Rechazar con 422.

Si el proyecto guarda lat/lng en columnas sueltas y PostGIS no es viable ahora:
generar columna `geometry` con migración y backfill. No implementar
point-in-polygon a mano en PHP/Python.

## Migración desde Leaflet (si aplica en este repo)

1. Inventariar usos de Leaflet (`grep -r "L\.map\|leaflet"`).
2. Migrar pantalla por pantalla; no convivir con las dos libs en la misma vista.
3. Eliminar `leaflet` y plugins del package.json al final, no antes.
4. Mantener la misma URL/props públicas de cada pantalla: la migración es
   interna, no cambia rutas ni API pública salvo el contrato de zonas.

## Criterios de aceptación

- [ ] Dibujar polígono, mano alzada, círculo y rectángulo; múltiples zonas simultáneas.
- [ ] Editar: mover zona, mover vértices, agregar vértice por midpoint, borrar zona con Supr.
- [ ] Los puntos dentro de zonas cambian de estilo en < 1 frame mientras se dibuja (preview client-side).
- [ ] El submit de búsqueda manda el FeatureCollection y el backend filtra con PostGIS.
- [ ] Sin API keys en el código; sin requests a dominios de Google/Mapbox.
- [ ] Funciona en mobile (touch: dibujo a mano alzada con el dedo).
- [ ] Leaflet completamente removido de las vistas migradas (si aplica).

## Notas por proyecto

**MiSocia** (búsquedas inmobiliarias): las zonas dibujadas se persisten junto a la
búsqueda del cliente (columna `jsonb` con el FeatureCollection) para re-ejecutarla
cuando entran propiedades nuevas. La UI de dibujo entra en el flujo de crear/editar
búsqueda. Los pines muestran popup con tipo, m², barrio y precio.

**Satrak** (GPS tracking): mismo stack, dos diferencias: (1) los puntos son
vehículos con posición en vivo — actualizar vía `source.setData()` sobre el mismo
GeoJSON source, jamás recrear capas; (2) las zonas dibujadas son **geocercas**
persistidas, y la evaluación punto-en-polígono corre server-side en el pipeline de
ingesta de posiciones para disparar alertas de entrada/salida. La UI de dibujo es
la misma pieza reutilizable.
