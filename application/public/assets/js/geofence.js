/* Satrak — Editor de geocercas (Leaflet, dibujo de círculo/polígono, sin plugins). */
(function () {
  'use strict';

  var mapEl = document.getElementById('geofence-map');
  if (!mapEl || typeof L === 'undefined') return;

  var form = document.querySelector('[data-geofence-form]');
  var shapeInput = form.querySelector('[data-shape]');
  var geomInput = form.querySelector('[data-geometry]');
  var shapeSelect = form.querySelector('[data-shape-select]');
  var radiusWrap = form.querySelector('[data-radius-wrap]');
  var radiusInput = form.querySelector('[data-radius]');
  var radiusLabel = form.querySelector('[data-radius-label]');
  var hint = form.querySelector('[data-draw-hint]');
  var clearBtn = form.querySelector('[data-clear]');

  var TEAL = '#1FE0C4';
  var map = L.map(mapEl).setView([-38.9516, -68.0591], 12);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);

  var shape = shapeInput.value || 'circle';
  var layer = null;       // capa dibujada
  var center = null;      // círculo: centro {lat,lng}
  var vertices = [];      // polígono: lista de latlng

  function setGeometry(obj) { geomInput.value = obj ? JSON.stringify(obj) : ''; }

  function radius() { return parseInt(radiusInput && radiusInput.value, 10) || 600; }

  function redraw() {
    if (layer) { map.removeLayer(layer); layer = null; }
    if (shape === 'circle') {
      if (!center) return;
      layer = L.circle(center, { radius: radius(), color: TEAL, fillColor: TEAL, fillOpacity: 0.15 }).addTo(map);
      setGeometry({ lat: +center.lat.toFixed(7), lon: +center.lng.toFixed(7), radius_m: radius() });
    } else {
      if (vertices.length < 1) return;
      layer = L.polygon(vertices, { color: TEAL, fillColor: TEAL, fillOpacity: 0.15 }).addTo(map);
      if (vertices.length >= 3) {
        setGeometry(vertices.map(function (v) { return [+v.lat.toFixed(7), +v.lng.toFixed(7)]; }));
      } else {
        setGeometry(null);
      }
    }
  }

  function syncMode() {
    shapeInput.value = shape;
    if (radiusWrap) radiusWrap.hidden = shape !== 'circle';
    if (hint) hint.textContent = shape === 'circle'
      ? 'Hacé clic para fijar el centro; ajustá el radio con el control.'
      : 'Hacé clic para agregar vértices (mínimo 3).';
  }

  function clearAll() {
    if (layer) { map.removeLayer(layer); layer = null; }
    center = null; vertices = []; setGeometry(null);
  }

  // Carga de geometría existente (modo edición).
  (function loadExisting() {
    var raw = geomInput.value && geomInput.value.trim();
    if (!raw) return;
    try {
      var g = JSON.parse(raw);
      if (shape === 'circle' && g && g.lat != null) {
        center = L.latLng(g.lat, g.lon);
        if (radiusInput && g.radius_m) { radiusInput.value = g.radius_m; if (radiusLabel) radiusLabel.textContent = g.radius_m + ' m'; }
        redraw();
        map.setView(center, 14);
      } else if (shape === 'polygon' && Array.isArray(g)) {
        vertices = g.map(function (p) { return L.latLng(p[0], p[1]); });
        redraw();
        if (layer) map.fitBounds(layer.getBounds().pad(0.2));
      }
    } catch (e) { /* geometría inválida: se ignora */ }
  })();

  map.on('click', function (e) {
    if (shape === 'circle') { center = e.latlng; }
    else { vertices.push(e.latlng); }
    redraw();
  });

  if (radiusInput) radiusInput.addEventListener('input', function () {
    if (radiusLabel) radiusLabel.textContent = radius() + ' m';
    redraw();
  });

  if (shapeSelect) shapeSelect.addEventListener('change', function () {
    shape = shapeSelect.value;
    clearAll();
    syncMode();
  });

  if (clearBtn) clearBtn.addEventListener('click', clearAll);

  syncMode();
})();
