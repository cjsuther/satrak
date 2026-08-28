/* Satrak — Mapa en vivo + historial/reproducción (MapLibre GL, vanilla).
 *
 * Tres modos, uno por pantalla: `live` (monitoreo), `history` (recorrido con
 * reproducción) y `point` (una sola posición, para los portales).
 *
 * Migrado de Leaflet. La diferencia de fondo: las unidades ya NO son
 * marcadores del DOM sino features de un source GeoJSON, y cada tick del
 * polling llama a `setPoints()`, que por debajo es un `source.setData()` sobre
 * el mismo source. Con un marcador por unidad, cien vehículos son cien nodos
 * del DOM moviéndose; así es una sola capa que el GPU redibuja.
 *
 * El estado (en movimiento, fuera de puesto, sin señal…) viaja como propiedad
 * de cada feature y lo resuelve una expresión de MapLibre en ZoneMap. Nada de
 * tocar estilos unidad por unidad desde JS.
 */
(function () {
  'use strict';

  var mapEl = document.getElementById('map');
  if (!mapEl || !window.SatrakZoneMap) return;

  var ZM = window.SatrakZoneMap;

  var STATE_LABELS = {
    movimiento: 'en movimiento', detenido: 'detenido', offline: 'sin señal',
    en_puesto: 'en su puesto', en_mision: 'en misión', fuera_de_puesto: 'fuera de puesto',
    activa: 'activa', fuera_de_turno: 'fuera de turno', sin_senal: 'sin señal'
  };

  /* Estados que se muestran apagados: no hay dato fresco de esa unidad. */
  var FADED = { offline: 1, sin_senal: 1, fuera_de_turno: 1 };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  function parseTs(ts) { return ts ? new Date(String(ts).replace(' ', 'T')) : null; }

  function ago(ms) {
    var s = Math.max(0, Math.round(ms / 1000));
    if (s < 60) return 'hace ' + s + ' s';
    var m = Math.round(s / 60);
    if (m < 60) return 'hace ' + m + ' min';
    return 'hace ' + Math.round(m / 60) + ' h';
  }

  /** Feature de punto. OJO: GeoJSON es [lon, lat], al revés que Satrak. */
  function point(lon, lat, props) {
    return { type: 'Feature', properties: props || {}, geometry: { type: 'Point', coordinates: [lon, lat] } };
  }

  function fc(features) { return { type: 'FeatureCollection', features: features || [] }; }

  var mode = mapEl.dataset.mode;
  if (mode === 'live') initLive();
  else if (mode === 'history') initHistory();
  else if (mode === 'point') initPoint();

  /* ======================================================================
   * MODO PUNTO — una sola posición (portales)
   * ==================================================================== */
  function initPoint() {
    var lat = parseFloat(mapEl.dataset.lat);
    var lon = parseFloat(mapEl.dataset.lon);
    if (isNaN(lat) || isNaN(lon)) return;

    var zm = ZM.create(mapEl, { center: [lon, lat], zoom: 15 });
    if (!zm) return;

    zm.setPoints(fc([point(lon, lat, { state: 'movimiento', selected: true })]));
    if (mapEl.dataset.label) {
      zm.map.on('load', function () {
        zm.showPopup([lon, lat], '<div class="map-popup">' + esc(mapEl.dataset.label) + '</div>');
      });
    }
  }

  /* ======================================================================
   * MODO VIVO — polling y unidades como source GeoJSON
   * ==================================================================== */
  function initLive() {
    var endpoint = mapEl.dataset.endpoint;
    var pollMs = (parseInt(mapEl.dataset.poll, 10) || 15) * 1000;

    var units = [];
    var byId = {};
    var filter = 'all';
    var kindFilter = 'all';
    var search = '';
    var fitted = false;
    var lastSyncMs = 0;

    var listEl = document.querySelector('[data-unit-list]');
    var syncEl = document.querySelector('[data-sync-label]');
    var searchEl = document.querySelector('[data-unit-search]');

    var zm = ZM.create(mapEl, {
      onFeatureClick: function (feature, lngLat) {
        var u = byId[feature.properties.device_id];
        if (u) zm.showPopup(lngLat, popupHtml(u));
      }
    });
    if (!zm) return;

    if (searchEl) searchEl.addEventListener('input', function () {
      search = searchEl.value.trim().toLowerCase();
      refresh();
    });
    bindFilters('[data-filter]', function (btn) { filter = btn.dataset.filter; });
    bindFilters('[data-kind-filter]', function (btn) { kindFilter = btn.dataset.kindFilter; });

    function bindFilters(selector, apply) {
      document.querySelectorAll(selector).forEach(function (btn) {
        btn.addEventListener('click', function () {
          document.querySelectorAll(selector).forEach(function (b) { b.classList.remove('is-active'); });
          btn.classList.add('is-active');
          apply(btn);
          refresh();
        });
      });
    }

    function unitKey(u) {
      return ((u.name || '') + ' ' + (u.plate || '') + ' ' + (u.label || '')).toLowerCase();
    }

    // 'movimiento' y 'detenido' sólo tienen sentido para flota; el filtro de
    // estado no debe esconder personas cuando se filtra por tipo.
    function matchesState(u) {
      if (filter === 'all') return true;
      if (filter === 'offline') return u.state === 'offline' || u.state === 'sin_senal';
      return u.state === filter;
    }

    function matches(u) {
      if (kindFilter !== 'all' && (u.kind || 'vehicle') !== kindFilter) return false;
      if (!matchesState(u)) return false;
      if (search && unitKey(u).indexOf(search) === -1) return false;
      return true;
    }

    function popupHtml(u) {
      var when = parseTs(u.ts);
      var head = '<strong>' + esc(u.name || u.plate || u.label) + '</strong><br>';
      var body;

      if ((u.kind || 'vehicle') === 'person') {
        body = 'Estado: ' + esc(STATE_LABELS[u.state] || u.state) + '<br>' +
          '<span class="mono">' + (u.speed || 0) + ' km/h' +
          (u.battery !== null && u.battery !== undefined ? ' · batería ' + u.battery + '%' : '') +
          '</span><br>';
      } else {
        body = 'Conductor: ' + esc(u.driver || 'No identificado') + '<br>' +
          '<span class="mono">' + (u.speed || 0) + ' km/h · ign ' + (u.ignition ? 'ON' : 'OFF') + '</span><br>';
      }

      return '<div class="map-popup">' + head + body +
        '<span class="mono muted">' + (when ? ago(Date.now() - when.getTime()) : 's/d') + '</span></div>';
    }

    /** Las unidades filtradas, como features. Lo que no matchea no se manda. */
    function toFeatures(list) {
      return list.map(function (u) {
        return point(u.lon, u.lat, {
          device_id: String(u.device_id),
          state: u.state || 'detenido',
          label: u.name || u.plate || u.label || '',
          muted: !!FADED[u.state]
        });
      });
    }

    function refresh() {
      var shown = units.filter(matches);
      zm.setPoints(fc(toFeatures(shown)));
      renderList(shown);
    }

    function renderList(shown) {
      if (!listEl) return;
      if (!shown.length) {
        listEl.innerHTML = '<li class="unit-empty muted">Sin unidades para el filtro.</li>';
        return;
      }
      var colors = ZM.COLORS;
      var stateColor = {
        movimiento: colors.teal, en_puesto: colors.teal, en_mision: colors.blue,
        fuera_de_puesto: colors.amber, alerta: colors.amber,
        detenido: colors.slate, activa: colors.slate,
        offline: colors.dim, sin_senal: colors.dim, fuera_de_turno: colors.dim
      };

      listEl.innerHTML = shown.map(function (u) {
        var when = parseTs(u.ts);
        var sub = (u.kind || 'vehicle') === 'person'
          ? esc(STATE_LABELS[u.state] || u.state) +
            (u.battery !== null && u.battery !== undefined ? ' · ' + u.battery + '%' : '')
          : (u.speed || 0) + ' km/h · ' + esc(u.driver || 'no identif.');
        var icon = (u.kind || 'vehicle') === 'person' ? '☺' : '⛟';

        return '<li class="unit-item" data-go="' + esc(u.device_id) + '">' +
          '<span class="unit-dot" style="background:' + (stateColor[u.state] || colors.slate) + '"></span>' +
          '<span class="unit-main"><span class="unit-name">' + icon + ' ' + esc(u.name || u.plate || u.label) + '</span>' +
          '<span class="unit-sub mono muted">' + sub + '</span></span>' +
          '<span class="unit-when mono muted">' + (when ? ago(Date.now() - when.getTime()) : '—') + '</span></li>';
      }).join('');

      listEl.querySelectorAll('[data-go]').forEach(function (li) {
        li.addEventListener('click', function () {
          var u = byId[li.dataset.go];
          if (!u) return;
          zm.map.easeTo({ center: [u.lon, u.lat], zoom: Math.max(zm.map.getZoom(), 14) });
          zm.showPopup([u.lon, u.lat], popupHtml(u));
        });
      });
    }

    function render(snapshot) {
      units = snapshot || [];
      byId = {};
      units.forEach(function (u) { byId[String(u.device_id)] = u; });

      if (!fitted && units.length) {
        zm.fitTo(fc(toFeatures(units)), 70);
        fitted = true;
      }
      refresh();
    }

    function setSync(ok) {
      if (!syncEl) return;
      if (ok) { lastSyncMs = Date.now(); syncEl.textContent = 'sincronizado recién'; }
      else { syncEl.textContent = 'sin conexión — reintentando…'; }
    }
    setInterval(function () {
      if (syncEl && lastSyncMs) syncEl.textContent = 'última sync ' + ago(Date.now() - lastSyncMs);
    }, 1000);

    function tick() {
      fetch(endpoint, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (!res.ok) throw new Error(res.error || 'error');
          render(res.data.units);
          setSync(true);
        })
        .catch(function () { setSync(false); });
    }

    tick();
    setInterval(tick, pollMs);
  }

  /* ======================================================================
   * MODO HISTORIAL — traza + reproducción
   * ==================================================================== */
  function initHistory() {
    var deviceId = mapEl.dataset.device;
    var from = mapEl.dataset.from;
    var to = mapEl.dataset.to;
    // El portal del conductor pasa su propia URL (scopeada al driver); si no,
    // se arma el endpoint de monitoreo por dispositivo.
    var url = mapEl.dataset.trackUrl || ('/api/devices/' + encodeURIComponent(deviceId) + '/track'
      + '?from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to));

    var bar = document.querySelector('[data-replay]');
    var toggleBtn = document.querySelector('[data-replay-toggle]');
    var scrub = document.querySelector('[data-replay-scrub]');
    var readout = document.querySelector('[data-replay-readout]');

    var points = [];
    var marks = [];        // hitos fijos: inicio, fin y paradas
    var idx = 0;
    var playing = false;
    var speed = 1;
    var timer = null;

    var zm = ZM.create(mapEl, {
      onFeatureClick: function (feature, lngLat) {
        if (feature.properties.popup) {
          zm.showPopup(lngLat, '<div class="map-popup">' + feature.properties.popup + '</div>');
        }
      }
    });
    if (!zm) return;

    fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.ok) throw new Error(res.error || 'error');
        draw(res.data.points || []);
      })
      .catch(function () {
        if (readout) readout.textContent = 'Error cargando el recorrido';
      });

    function draw(pts) {
      points = pts;
      if (!points.length) {
        if (readout) readout.textContent = 'Sin posiciones en el rango';
        return;
      }

      var coords = points.map(function (p) { return [p.lon, p.lat]; });
      var linea = fc([{ type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: coords } }]);
      zm.setTrack(linea);

      marks = [];
      marks.push(point(points[0].lon, points[0].lat, { state: 'movimiento', popup: 'Inicio' }));
      var last = points[points.length - 1];
      marks.push(point(last.lon, last.lat, { state: 'detenido', popup: 'Fin' }));

      // Paradas: primer punto detenido tras haber estado en movimiento.
      for (var i = 1; i < points.length; i++) {
        if ((points[i].speed || 0) === 0 && (points[i - 1].speed || 0) > 0) {
          marks.push(point(points[i].lon, points[i].lat, {
            state: 'alerta', popup: 'Parada · ' + esc(points[i].ts)
          }));
        }
      }

      zm.fitTo(linea, 60);

      if (bar) bar.hidden = false;
      if (scrub) scrub.max = String(points.length - 1);
      seek(0);
      wireControls();
      wireTripList();
    }

    /** El cursor de reproducción es una feature más del mismo source. */
    function paint() {
      var p = points[idx];
      var cursor = point(p.lon, p.lat, { state: 'movimiento', selected: true });
      zm.setPoints(fc(marks.concat([cursor])));
    }

    function seek(i) {
      idx = Math.max(0, Math.min(points.length - 1, i));
      var p = points[idx];
      paint();
      if (scrub) scrub.value = String(idx);
      if (readout) readout.textContent = (p.ts ? String(p.ts).slice(11, 19) : '') + ' · ' + (p.speed || 0) + ' km/h';
    }

    function step() {
      if (idx >= points.length - 1) { pause(); return; }
      seek(idx + 1);
    }

    function play() {
      if (playing || points.length < 2) return;
      if (idx >= points.length - 1) seek(0);
      playing = true;
      if (toggleBtn) toggleBtn.textContent = '❚❚';
      timer = setInterval(step, 700 / speed);
    }

    function pause() {
      playing = false;
      if (toggleBtn) toggleBtn.textContent = '▶';
      if (timer) { clearInterval(timer); timer = null; }
    }

    function wireControls() {
      if (toggleBtn) toggleBtn.addEventListener('click', function () { playing ? pause() : play(); });
      if (scrub) scrub.addEventListener('input', function () { pause(); seek(parseInt(scrub.value, 10) || 0); });
      document.querySelectorAll('[data-speed]').forEach(function (b) {
        b.addEventListener('click', function () {
          document.querySelectorAll('[data-speed]').forEach(function (x) { x.classList.remove('is-active'); });
          b.classList.add('is-active');
          speed = parseInt(b.dataset.speed, 10) || 1;
          if (playing) { pause(); play(); }
        });
      });
    }

    // Clic en un viaje: enfoca su tramo (por ventana temporal).
    function wireTripList() {
      document.querySelectorAll('[data-trip]').forEach(function (li) {
        li.addEventListener('click', function () {
          document.querySelectorAll('[data-trip]').forEach(function (x) { x.classList.remove('is-active'); });
          li.classList.add('is-active');
          var a = parseTs(li.dataset.from), b = parseTs(li.dataset.to);
          var seg = points.filter(function (p) {
            var t = parseTs(p.ts);
            return t && (!a || t >= a) && (!b || t <= b);
          });
          if (!seg.length) return;

          zm.fitTo(fc([{
            type: 'Feature', properties: {},
            geometry: { type: 'LineString', coordinates: seg.map(function (p) { return [p.lon, p.lat]; }) }
          }]), 60);

          var start = points.indexOf(seg[0]);
          pause();
          seek(start < 0 ? 0 : start);
        });
      });
    }
  }
})();
