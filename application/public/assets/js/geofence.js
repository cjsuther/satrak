/* Satrak — Editor de geocercas (MapLibre GL + Terra Draw).
 *
 * Cuatro formas de dibujar la misma cosa: polígono a clics, mano alzada,
 * círculo y rectángulo. Todas producen un polígono, así que del lado del
 * servidor no hay casos especiales.
 *
 * Una geocerca es UN área: dibujar una nueva reemplaza la anterior. El
 * componente ZoneMap soporta varias zonas —lo necesita el caso de búsqueda por
 * zonas—, pero el modelo de Satrak tiene una geometría por geocerca, y
 * cambiarlo tocaría el motor de alertas, el contrato de la app y sus tests.
 *
 * Si la geocerca ya existía y no se toca el dibujo, se envía la geometría
 * original tal cual: abrir el formulario para cambiar el nombre no debe
 * convertir un círculo guardado en un polígono de 64 lados.
 */
(function () {
  'use strict';

  var mapEl = document.getElementById('geofence-map');
  if (!mapEl || !window.SatrakZoneMap) return;

  var form = document.querySelector('[data-geofence-form]');
  if (!form) return;

  var shapeInput = form.querySelector('[data-shape]');
  var geomInput = form.querySelector('[data-geometry]');
  var zonesInput = form.querySelector('[data-zones]');
  var hint = form.querySelector('[data-draw-hint]');
  var clearBtn = form.querySelector('[data-clear]');
  var modeBtns = Array.prototype.slice.call(form.querySelectorAll('[data-mode]'));

  var HINTS = {
    polygon:   'Hacé clic para marcar cada vértice. Doble clic para cerrar.',
    freehand:  'Mantené apretado y dibujá el contorno a pulso.',
    circle:    'Hacé clic en el centro y arrastrá para fijar el radio.',
    rectangle: 'Arrastrá de una esquina a la opuesta.',
    select:    'Hacé clic en la zona para moverla o arrastrar sus vértices. Supr la borra.'
  };

  var originalShape = shapeInput.value || 'polygon';
  var originalGeometry = geomInput.value || '';
  var dirty = false;

  /* Geometría guardada → polígono para mostrar. Un círculo se dibuja como
     polígono, pero mientras no se lo toque se guarda como círculo. */
  var existing = window.SatrakZoneMap.toPolygon(originalShape, originalGeometry);

  var zm = window.SatrakZoneMap.create(mapEl, {
    draw: true,
    mode: 'polygon',
    onZonesChange: function (fc) {
      dirty = true;
      applyZones(fc);
    },
    onReady: function (api) {
      if (existing) {
        api.setZones({ type: 'FeatureCollection', features: [{ type: 'Feature', properties: {}, geometry: existing }] });
        api.fitTo({ type: 'FeatureCollection', features: [{ type: 'Feature', properties: {}, geometry: existing }] }, 70);
        // setZones dispara `change`; el estado real sigue siendo "sin tocar".
        dirty = false;
      }
    }
  });

  if (!zm) return;

  /* Una sola zona: si quedan varias, se conserva la última dibujada. */
  function applyZones(fc) {
    var polys = (fc.features || []).filter(function (f) {
      return f.geometry && f.geometry.type === 'Polygon';
    });

    if (polys.length > 1) {
      var ultima = polys[polys.length - 1];
      // Reemplazar en vez de acumular; vuelve a entrar por onZonesChange.
      zm.setZones({ type: 'FeatureCollection', features: [ultima] });
      return;
    }

    if (!polys.length) {
      shapeInput.value = originalShape;
      geomInput.value = '';
      if (zonesInput) zonesInput.value = '';
      setHint('Todavía no dibujaste la geocerca.');
      return;
    }

    var satrak = window.SatrakZoneMap.polygonToSatrak(polys[0].geometry);
    if (!satrak) return;   // polígono transitorio durante el dibujo

    shapeInput.value = 'polygon';
    geomInput.value = JSON.stringify(satrak);
    // Contrato nuevo: el payload también viaja como FeatureCollection.
    if (zonesInput) {
      zonesInput.value = JSON.stringify({ type: 'FeatureCollection', features: [polys[0]] });
    }
    setHint(satrak.length + ' vértices. Podés editarla con «Seleccionar».');
  }

  function setHint(text) { if (hint) hint.textContent = text; }

  function setMode(mode) {
    zm.setMode(mode);
    modeBtns.forEach(function (b) {
      var on = b.getAttribute('data-mode') === mode;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    setHint(HINTS[mode] || '');
  }

  modeBtns.forEach(function (b) {
    b.addEventListener('click', function () { setMode(b.getAttribute('data-mode')); });
  });

  if (clearBtn) clearBtn.addEventListener('click', function () {
    dirty = true;
    zm.clearZones();
    setMode('polygon');
  });

  /* Si no se tocó el dibujo, se restauran los valores originales: editar el
     nombre no debe reescribir la geometría. */
  form.addEventListener('submit', function () {
    if (!dirty) {
      shapeInput.value = originalShape;
      geomInput.value = originalGeometry;
      if (zonesInput) zonesInput.value = '';
    }
  });

  setMode('polygon');
  if (existing) setHint('Geocerca cargada. Usá «Seleccionar» para editarla, o dibujá una nueva.');
})();
