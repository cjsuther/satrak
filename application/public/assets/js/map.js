/* Satrak — Mapa en vivo + historial/reproducción (Leaflet, vanilla). */
(function () {
  'use strict';

  var mapEl = document.getElementById('map');
  if (!mapEl || typeof L === 'undefined') return;

  // Colores de estado (tokens de identidad).
  var COLORS = {
    movimiento: '#1FE0C4', // teal
    detenido:   '#6B7C93', // acero
    alerta:     '#FFB23E', // ámbar
    offline:    '#3A4A5E'  // atenuado
  };
  var DEFAULT_CENTER = [-38.9516, -68.0591]; // Neuquén
  var DEFAULT_ZOOM = 12;

  function buildMap() {
    var map = L.map(mapEl, { zoomControl: true }).setView(DEFAULT_CENTER, DEFAULT_ZOOM);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap'
    }).addTo(map);
    return map;
  }

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
    var h = Math.round(m / 60);
    return 'hace ' + h + ' h';
  }

  if (mapEl.dataset.mode === 'live') initLive();
  else if (mapEl.dataset.mode === 'history') initHistory();
  else if (mapEl.dataset.mode === 'point') initPoint();

  /* ======================================================================
   * MODO PUNTO — una sola posición (portal: "mi última posición")
   * ==================================================================== */
  function initPoint() {
    var lat = parseFloat(mapEl.dataset.lat);
    var lon = parseFloat(mapEl.dataset.lon);
    if (isNaN(lat) || isNaN(lon)) return;
    var map = buildMap();
    map.setView([lat, lon], 15);
    var m = L.circleMarker([lat, lon], { radius: 9, weight: 2, color: '#050E1F', fillColor: COLORS.movimiento, fillOpacity: 1 }).addTo(map);
    if (mapEl.dataset.label) m.bindPopup(mapEl.dataset.label).openPopup();
  }

  /* ======================================================================
   * MODO VIVO — polling + marcadores por estado
   * ==================================================================== */
  function initLive() {
    var map = buildMap();
    var endpoint = mapEl.dataset.endpoint;
    var pollMs = (parseInt(mapEl.dataset.poll, 10) || 15) * 1000;

    var markers = {};            // device_id -> circleMarker
    var units = [];              // último snapshot
    var filter = 'all';
    var search = '';
    var fitted = false;
    var lastSyncMs = 0;

    var listEl = document.querySelector('[data-unit-list]');
    var syncEl = document.querySelector('[data-sync-label]');
    var searchEl = document.querySelector('[data-unit-search]');

    if (searchEl) searchEl.addEventListener('input', function () {
      search = searchEl.value.trim().toLowerCase();
      renderList();
    });
    document.querySelectorAll('[data-filter]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        document.querySelectorAll('[data-filter]').forEach(function (b) { b.classList.remove('is-active'); });
        btn.classList.add('is-active');
        filter = btn.dataset.filter;
        renderList();
        applyMarkerVisibility();
      });
    });

    function matches(u) {
      if (filter !== 'all' && u.state !== filter) return false;
      if (search) {
        var hay = ((u.plate || '') + ' ' + (u.label || '')).toLowerCase();
        if (hay.indexOf(search) === -1) return false;
      }
      return true;
    }

    function popupHtml(u) {
      var when = parseTs(u.ts);
      return '<div class="map-popup">' +
        '<strong>' + esc(u.plate || u.label) + '</strong><br>' +
        'Conductor: ' + esc(u.driver || 'No identificado') + '<br>' +
        '<span class="mono">' + (u.speed || 0) + ' km/h · ign ' + (u.ignition ? 'ON' : 'OFF') + '</span><br>' +
        '<span class="mono muted">' + (when ? ago(Date.now() - when.getTime()) : 's/d') + '</span>' +
        '</div>';
    }

    function upsertMarker(u) {
      var m = markers[u.device_id];
      if (!m) {
        m = L.circleMarker([u.lat, u.lon], { radius: 8, weight: 2, color: '#050E1F' });
        m.addTo(map);
        markers[u.device_id] = m;
      } else {
        m.setLatLng([u.lat, u.lon]);
      }
      m.setStyle({ fillColor: COLORS[u.state] || COLORS.detenido, fillOpacity: u.state === 'offline' ? 0.45 : 1 });
      m.bindPopup(popupHtml(u));
      m._satState = u.state;
      m._satKey = ((u.plate || '') + ' ' + (u.label || '')).toLowerCase();
    }

    function applyMarkerVisibility() {
      Object.keys(markers).forEach(function (id) {
        var m = markers[id];
        var visible = (filter === 'all' || m._satState === filter)
          && (!search || m._satKey.indexOf(search) !== -1);
        var el = m.getElement();
        if (el) el.style.display = visible ? '' : 'none';
      });
    }

    function renderList() {
      if (!listEl) return;
      var shown = units.filter(matches);
      if (!shown.length) {
        listEl.innerHTML = '<li class="unit-empty muted">Sin unidades para el filtro.</li>';
        return;
      }
      listEl.innerHTML = shown.map(function (u) {
        var when = parseTs(u.ts);
        return '<li class="unit-item" data-go="' + u.device_id + '">' +
          '<span class="unit-dot" style="background:' + (COLORS[u.state] || COLORS.detenido) + '"></span>' +
          '<span class="unit-main"><span class="unit-name">' + esc(u.plate || u.label) + '</span>' +
          '<span class="unit-sub mono muted">' + (u.speed || 0) + ' km/h · ' + esc(u.driver || 'no identif.') + '</span></span>' +
          '<span class="unit-when mono muted">' + (when ? ago(Date.now() - when.getTime()) : '—') + '</span></li>';
      }).join('');
      listEl.querySelectorAll('[data-go]').forEach(function (li) {
        li.addEventListener('click', function () {
          var m = markers[li.dataset.go];
          if (m) { map.setView(m.getLatLng(), Math.max(map.getZoom(), 14)); m.openPopup(); }
        });
      });
    }

    function render(snapshot) {
      units = snapshot || [];
      var present = {};
      units.forEach(function (u) { present[u.device_id] = true; upsertMarker(u); });
      // Saca marcadores de dispositivos que ya no vienen.
      Object.keys(markers).forEach(function (id) {
        if (!present[id]) { map.removeLayer(markers[id]); delete markers[id]; }
      });
      if (!fitted && units.length) {
        var bounds = L.latLngBounds(units.map(function (u) { return [u.lat, u.lon]; }));
        map.fitBounds(bounds.pad(0.2));
        fitted = true;
      }
      renderList();
      applyMarkerVisibility();
    }

    function setSync(ok) {
      if (!syncEl) return;
      if (ok) { lastSyncMs = Date.now(); syncEl.textContent = 'sincronizado recién'; }
      else { syncEl.textContent = 'sin conexión — reintentando…'; }
    }
    // Refresco del "hace Xs" cada segundo.
    setInterval(function () {
      if (syncEl && lastSyncMs) syncEl.textContent = 'última sync ' + ago(Date.now() - lastSyncMs);
    }, 1000);

    function tick() {
      fetch(endpoint, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
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
    var map = buildMap();
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
    var cursor = null;     // marcador móvil
    var idx = 0;
    var playing = false;
    var speed = 1;
    var timer = null;

    fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
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
      var latlngs = points.map(function (p) { return [p.lat, p.lon]; });

      L.polyline(latlngs, { color: COLORS.movimiento, weight: 4, opacity: 0.85 }).addTo(map);

      // Inicio (teal) y fin (acero).
      L.circleMarker(latlngs[0], { radius: 7, color: '#050E1F', weight: 2, fillColor: COLORS.movimiento, fillOpacity: 1 })
        .addTo(map).bindPopup('Inicio');
      L.circleMarker(latlngs[latlngs.length - 1], { radius: 7, color: '#050E1F', weight: 2, fillColor: COLORS.detenido, fillOpacity: 1 })
        .addTo(map).bindPopup('Fin');

      // Paradas: inicio de cada detención (velocidad 0 tras estar en movimiento).
      for (var i = 1; i < points.length; i++) {
        if ((points[i].speed || 0) === 0 && (points[i - 1].speed || 0) > 0) {
          L.circleMarker([points[i].lat, points[i].lon],
            { radius: 5, color: '#050E1F', weight: 1, fillColor: COLORS.alerta, fillOpacity: 0.9 })
            .addTo(map).bindPopup('Parada · ' + esc(points[i].ts));
        }
      }

      map.fitBounds(L.latLngBounds(latlngs).pad(0.15));

      cursor = L.circleMarker(latlngs[0], { radius: 9, color: '#fff', weight: 2, fillColor: COLORS.movimiento, fillOpacity: 1 }).addTo(map);
      if (bar) bar.hidden = false;
      if (scrub) scrub.max = String(points.length - 1);
      seek(0);
      wireControls();
      wireTripList();
    }

    function seek(i) {
      idx = Math.max(0, Math.min(points.length - 1, i));
      var p = points[idx];
      if (cursor) cursor.setLatLng([p.lat, p.lon]);
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
          if (seg.length) {
            map.fitBounds(L.latLngBounds(seg.map(function (p) { return [p.lat, p.lon]; })).pad(0.15));
            // Posiciona la reproducción al inicio del viaje.
            var start = points.indexOf(seg[0]);
            pause();
            seek(start < 0 ? 0 : start);
          }
        });
      });
    }
  }
})();
