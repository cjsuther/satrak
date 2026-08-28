/* Satrak — ZoneMap: mapa vectorial reutilizable (MapLibre GL + Terra Draw).
 *
 * Pieza compartida por el mapa en vivo, el historial, los portales y el editor
 * de geocercas. No tiene lógica de negocio: recibe puntos y zonas, avisa cuando
 * algo cambia, y quien lo usa decide qué significan.
 *
 * Dos decisiones que vienen de la spec de migración y conviene no revisar:
 *
 *  - Los puntos van SIEMPRE como un source GeoJSON con capas `circle`, nunca
 *    como marcadores del DOM. Un marcador por vehículo no escala, y actualizar
 *    posiciones en vivo se hace con `source.setData()` sobre el mismo source:
 *    recrear capas en cada tick tira el mapa abajo.
 *  - El estado visual se resuelve con expresiones de MapLibre sobre las
 *    propiedades de cada feature, no manipulando capas desde JS.
 *
 * Tiles: OpenFreeMap (vectorial, gratis, sin registro ni API key). Las
 * librerías se sirven desde el propio dominio para que la CSP siga en 'self'.
 */
(function (global) {
  'use strict';

  var D = global.SatrakDraw;   // bundle de terra-draw + adapter + turf

  /* Identidad Satrak. Mismos tokens que el resto de la plataforma. */
  var C = {
    teal:   '#1FE0C4',
    amber:  '#FFB23E',
    slate:  '#6B7C93',
    blue:   '#5AA9FF',
    red:    '#FF5A5A',
    navy:   '#0A2342',
    dim:    '#3A4A5E',
    white:  '#FFFFFF'
  };

  /* Color por estado de unidad. Flota y personal comparten escala: teal es
     "como debe ser", ámbar es "mirá esto", apagado es "sin información". */
  var STATE_COLORS = [
    'movimiento',      C.teal,
    'en_puesto',       C.teal,
    'en_mision',       C.blue,
    'fuera_de_puesto', C.amber,
    'alerta',          C.amber,
    'detenido',        C.slate,
    'activa',          C.slate,
    'offline',         C.dim,
    'sin_senal',       C.dim,
    'fuera_de_turno',  C.dim
  ];

  var STYLE_URL = 'https://tiles.openfreemap.org/styles/positron';
  var DEFAULT_CENTER = [-68.0591, -38.9516];   // Neuquén, en orden GeoJSON [lon,lat]
  var DEFAULT_ZOOM = 11;

  var SRC_POINTS = 'satrak-points';
  var LYR_POINTS = 'satrak-points-circle';
  var LYR_HALO   = 'satrak-points-halo';
  var LYR_LABEL  = 'satrak-points-label';
  var SRC_TRACK  = 'satrak-track';
  var LYR_TRACK  = 'satrak-track-line';

  /* Estilo compartido por los cuatro modos de dibujo. */
  var DRAW_STYLE = {
    fillColor:    C.teal,
    fillOpacity:  0.18,
    outlineColor: C.teal,
    outlineWidth: 2
  };

  function empty() { return { type: 'FeatureCollection', features: [] }; }

  /* --------------------------------------------------------------------
     Conversión con el formato que guarda Satrak.

     La base guarda `[lat, lon]` para polígonos y `{lat, lon, radius_m}` para
     círculos; GeoJSON usa `[lon, lat]`. Invertir el orden es EL error clásico
     de esta migración, así que la conversión vive en un solo lugar.
     -------------------------------------------------------------------- */

  /** Geometría de Satrak → Polygon GeoJSON. Devuelve null si no se entiende. */
  function toPolygon(shape, geometry) {
    if (typeof geometry === 'string') {
      try { geometry = JSON.parse(geometry); } catch (e) { return null; }
    }
    if (!geometry) return null;

    if (shape === 'circle') {
      var lat = Number(geometry.lat), lon = Number(geometry.lon);
      var r = Number(geometry.radius_m || 0);
      if (!isFinite(lat) || !isFinite(lon) || r <= 0) return null;
      return circleToPolygon(lon, lat, r);
    }

    if (!Array.isArray(geometry) || geometry.length < 3) return null;
    var ring = [];
    for (var i = 0; i < geometry.length; i++) {
      var p = geometry[i];
      if (!Array.isArray(p) || p.length < 2) continue;
      ring.push([Number(p[1]), Number(p[0])]);   // [lat,lon] → [lon,lat]
    }
    if (ring.length < 3) return null;
    ring.push(ring[0].slice());                   // GeoJSON cierra el anillo
    return { type: 'Polygon', coordinates: [ring] };
  }

  /** Círculo → polígono de 64 lados, para poder dibujarlo como cualquier zona. */
  function circleToPolygon(lon, lat, radiusM, steps) {
    steps = steps || 64;
    var ring = [];
    var latRad = lat * Math.PI / 180;
    var dLat = radiusM / 111320;
    var dLon = radiusM / (111320 * Math.max(0.01, Math.cos(latRad)));
    for (var i = 0; i < steps; i++) {
      var a = (i / steps) * 2 * Math.PI;
      ring.push([lon + dLon * Math.cos(a), lat + dLat * Math.sin(a)]);
    }
    ring.push(ring[0].slice());
    return { type: 'Polygon', coordinates: [ring] };
  }

  /** Polygon GeoJSON → el `[[lat,lon],…]` que guarda Satrak. */
  function polygonToSatrak(polygon) {
    if (!polygon || polygon.type !== 'Polygon' || !polygon.coordinates.length) return null;
    var ring = polygon.coordinates[0].slice();
    // El anillo GeoJSON repite el primer punto al final; Satrak no lo guarda.
    if (ring.length > 1) {
      var a = ring[0], b = ring[ring.length - 1];
      if (a[0] === b[0] && a[1] === b[1]) ring.pop();
    }
    if (ring.length < 3) return null;
    return ring.map(function (c) {
      return [+Number(c[1]).toFixed(7), +Number(c[0]).toFixed(7)];   // [lon,lat] → [lat,lon]
    });
  }

  function bboxOf(fc) {
    var b = [Infinity, Infinity, -Infinity, -Infinity], found = false;
    (fc.features || []).forEach(function (f) {
      each(f.geometry, function (lon, lat) {
        found = true;
        if (lon < b[0]) b[0] = lon;
        if (lat < b[1]) b[1] = lat;
        if (lon > b[2]) b[2] = lon;
        if (lat > b[3]) b[3] = lat;
      });
    });
    return found ? b : null;
  }

  function each(geom, fn) {
    if (!geom) return;
    if (geom.type === 'Point') return fn(geom.coordinates[0], geom.coordinates[1]);
    if (geom.type === 'LineString') return geom.coordinates.forEach(function (c) { fn(c[0], c[1]); });
    if (geom.type === 'Polygon') return geom.coordinates.forEach(function (r) {
      r.forEach(function (c) { fn(c[0], c[1]); });
    });
  }

  /* ====================================================================== */

  function create(el, options) {
    if (!el || !global.maplibregl) return null;
    var opts = options || {};

    var map = new global.maplibregl.Map({
      container: el,
      style: STYLE_URL,
      center: opts.center || DEFAULT_CENTER,
      zoom: opts.zoom == null ? DEFAULT_ZOOM : opts.zoom,
      attributionControl: { compact: true }
    });
    map.addControl(new global.maplibregl.NavigationControl({ showCompass: false }), 'top-left');

    var draw = null;
    var ready = false;
    var pending = { points: null, track: null, zones: null, fit: null };
    var popup = null;
    var rafId = null;

    /* ---- Capas de puntos ------------------------------------------- */
    function addPointLayers() {
      map.addSource(SRC_POINTS, { type: 'geojson', data: empty() });

      // Halo: sólo para lo que cae dentro de una zona mientras se dibuja.
      // OJO con la expresión: `["get","in_zone"]` pelado dentro de un `case`
      // tira "Expected boolean but found value instead". Hay que envolverlo
      // en `["boolean", …, false]` siempre.
      map.addLayer({
        id: LYR_HALO,
        type: 'circle',
        source: SRC_POINTS,
        paint: {
          'circle-radius': ['case', ['boolean', ['get', 'in_zone'], false], 13, 0],
          'circle-color': C.teal,
          'circle-opacity': 0.25
        }
      });

      map.addLayer({
        id: LYR_POINTS,
        type: 'circle',
        source: SRC_POINTS,
        paint: {
          'circle-radius': ['case', ['boolean', ['get', 'selected'], false], 9, 7],
          'circle-color': ['match', ['get', 'state']].concat(STATE_COLORS).concat([C.slate]),
          'circle-stroke-width': 2,
          'circle-stroke-color': ['case', ['boolean', ['get', 'in_zone'], false], C.white, C.navy],
          'circle-opacity': ['case', ['boolean', ['get', 'muted'], false], 0.35, 1]
        }
      });

      map.addLayer({
        id: LYR_LABEL,
        type: 'symbol',
        source: SRC_POINTS,
        layout: {
          'text-field': ['coalesce', ['get', 'label'], ''],
          'text-size': 11,
          'text-offset': [0, 1.3],
          'text-anchor': 'top',
          'text-allow-overlap': false,
          'text-font': ['Noto Sans Regular']
        },
        paint: {
          'text-color': C.navy,
          'text-halo-color': C.white,
          'text-halo-width': 1.4,
          'text-opacity': ['case', ['boolean', ['get', 'muted'], false], 0.4, 1]
        }
      });

      map.on('click', LYR_POINTS, function (e) {
        var f = e.features && e.features[0];
        if (f && typeof opts.onFeatureClick === 'function') opts.onFeatureClick(f, e.lngLat);
      });
      map.on('mouseenter', LYR_POINTS, function () { map.getCanvas().style.cursor = 'pointer'; });
      map.on('mouseleave', LYR_POINTS, function () { map.getCanvas().style.cursor = ''; });
    }

    /* ---- Capa de recorrido ------------------------------------------ */
    function addTrackLayers() {
      map.addSource(SRC_TRACK, { type: 'geojson', data: empty() });
      map.addLayer({
        id: LYR_TRACK,
        type: 'line',
        source: SRC_TRACK,
        layout: { 'line-cap': 'round', 'line-join': 'round' },
        paint: { 'line-color': C.teal, 'line-width': 3, 'line-opacity': 0.85 }
      }, LYR_HALO);
    }

    /* ---- Dibujo de zonas -------------------------------------------- */
    function initDraw() {
      if (!D || !opts.draw) return;

      var styles = {
        fillColor: DRAW_STYLE.fillColor,
        fillOpacity: DRAW_STYLE.fillOpacity,
        outlineColor: DRAW_STYLE.outlineColor,
        outlineWidth: DRAW_STYLE.outlineWidth
      };

      draw = new D.TerraDraw({
        adapter: new D.TerraDrawMapLibreGLAdapter({ map: map, lib: global.maplibregl }),
        modes: [
          new D.TerraDrawPolygonMode({ styles: styles }),
          new D.TerraDrawFreehandMode({ styles: styles }),
          new D.TerraDrawCircleMode({ styles: styles }),
          new D.TerraDrawRectangleMode({ styles: styles }),
          new D.TerraDrawSelectMode({
            flags: {
              polygon:   { feature: { draggable: true, coordinates: { midpoints: true, draggable: true, deletable: true } } },
              freehand:  { feature: { draggable: true } },
              circle:    { feature: { draggable: true } },
              rectangle: { feature: { draggable: true, coordinates: { draggable: true } } }
            }
          })
        ]
      });
      draw.start();
      draw.setMode(opts.mode || 'polygon');

      // `change` dispara en cada mousemove del dibujo: se agrupa por frame.
      draw.on('change', function () {
        if (rafId) return;
        rafId = global.requestAnimationFrame(function () {
          rafId = null;
          emitZones();
        });
      });
    }

    function currentZones() {
      if (!draw) return empty();
      var fc = empty();
      // getSnapshot() devuelve también features auxiliares (puntos de arrastre,
      // midpoints): sólo sirven los polígonos.
      draw.getSnapshot().forEach(function (f) {
        if (f && f.geometry && f.geometry.type === 'Polygon') {
          fc.features.push({ type: 'Feature', id: f.id, properties: f.properties || {}, geometry: f.geometry });
        }
      });
      return fc;
    }

    function emitZones() {
      var zones = currentZones();
      markPointsInZones(zones);
      if (typeof opts.onZonesChange === 'function') opts.onZonesChange(zones);
    }

    /* Preview client-side: marca qué puntos caen dentro. Es sólo feedback
       visual — el filtrado que vale lo hace el servidor. */
    var lastPoints = empty();
    function markPointsInZones(zones) {
      if (!lastPoints.features.length) return;
      var polys = zones.features;
      var changed = false;

      lastPoints.features.forEach(function (p) {
        var inside = false;
        if (polys.length && p.geometry && p.geometry.type === 'Point') {
          for (var i = 0; i < polys.length; i++) {
            try {
              if (D.booleanPointInPolygon(p, polys[i])) { inside = true; break; }
            } catch (e) {
              // Mientras se dibuja hay polígonos transitorios inválidos
              // (menos de 4 vértices): se ignoran, no son un error.
            }
          }
        }
        if (!!p.properties.in_zone !== inside) { p.properties.in_zone = inside; changed = true; }
      });

      if (changed && map.getSource(SRC_POINTS)) map.getSource(SRC_POINTS).setData(lastPoints);
    }

    /* ---- API pública -------------------------------------------------- */
    var api = {
      map: map,

      /** Reemplaza los puntos. Es lo que se llama en cada tick del vivo. */
      setPoints: function (fc) {
        lastPoints = fc || empty();
        if (!ready) { pending.points = lastPoints; return api; }
        map.getSource(SRC_POINTS).setData(lastPoints);
        return api;
      },

      /** Recorrido (historial / viaje) como LineString. */
      setTrack: function (fc) {
        if (!ready) { pending.track = fc; return api; }
        if (map.getSource(SRC_TRACK)) map.getSource(SRC_TRACK).setData(fc || empty());
        return api;
      },

      /** Carga zonas existentes en el editor. */
      setZones: function (fc) {
        if (!ready || !draw) { pending.zones = fc; return api; }
        draw.clear();
        var feats = (fc && fc.features ? fc.features : []).filter(function (f) {
          return f.geometry && f.geometry.type === 'Polygon';
        });
        if (feats.length) {
          draw.addFeatures(feats.map(function (f) {
            return {
              type: 'Feature',
              geometry: f.geometry,
              properties: Object.assign({ mode: 'polygon' }, f.properties || {})
            };
          }));
        }
        markPointsInZones(currentZones());
        return api;
      },

      getZones: currentZones,

      clearZones: function () {
        if (draw) { draw.clear(); emitZones(); }
        return api;
      },

      setMode: function (mode) {
        if (draw) draw.setMode(mode);
        return api;
      },

      /** Encuadra el mapa sobre una FeatureCollection. */
      fitTo: function (fc, padding) {
        if (!ready) { pending.fit = { fc: fc, padding: padding }; return api; }
        var b = bboxOf(fc || empty());
        if (!b) return api;
        if (b[0] === b[2] && b[1] === b[3]) map.easeTo({ center: [b[0], b[1]], zoom: 15 });
        else map.fitBounds(b, { padding: padding == null ? 60 : padding, duration: 400, maxZoom: 16 });
        return api;
      },

      showPopup: function (lngLat, html) {
        if (popup) popup.remove();
        popup = new global.maplibregl.Popup({ closeButton: true, maxWidth: '280px' })
          .setLngLat(lngLat).setHTML(html).addTo(map);
        return api;
      },

      closePopup: function () { if (popup) { popup.remove(); popup = null; } return api; },

      resize: function () { map.resize(); return api; },

      destroy: function () {
        if (rafId) global.cancelAnimationFrame(rafId);
        if (draw) { try { draw.stop(); } catch (e) { /* ya detenido */ } }
        if (popup) popup.remove();
        map.remove();
      }
    };

    /* Terra Draw y las capas se inicializan DENTRO de `load`: antes de eso el
       estilo no existe y addSource/addLayer fallan. */
    map.on('load', function () {
      addPointLayers();
      addTrackLayers();
      initDraw();
      ready = true;

      if (pending.points) map.getSource(SRC_POINTS).setData(pending.points);
      if (pending.track) map.getSource(SRC_TRACK).setData(pending.track);
      if (pending.zones) api.setZones(pending.zones);
      if (pending.fit) api.fitTo(pending.fit.fc, pending.fit.padding);
      pending = { points: null, track: null, zones: null, fit: null };

      if (typeof opts.onReady === 'function') opts.onReady(api);
    });

    return api;
  }

  global.SatrakZoneMap = {
    create: create,
    toPolygon: toPolygon,
    polygonToSatrak: polygonToSatrak,
    circleToPolygon: circleToPolygon,
    empty: empty,
    COLORS: C
  };
})(window);
