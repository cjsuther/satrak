/* Satrak — JS del panel (vanilla, sin dependencias). */
(function () {
  'use strict';

  // Cerrar mensajes flash.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.flash-close');
    if (btn) {
      var flash = btn.closest('.flash');
      if (flash) flash.remove();
    }
  });

  // Auto-ocultar flashes de éxito/info a los 6s.
  document.querySelectorAll('.flash-success, .flash-info').forEach(function (el) {
    setTimeout(function () { el.style.opacity = '0'; setTimeout(function () { el.remove(); }, 300); }, 6000);
  });

  // Items "próximamente": evitar navegación y avisar.
  document.querySelectorAll('[data-soon]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      e.preventDefault();
      el.classList.add('is-soon-pulse');
      setTimeout(function () { el.classList.remove('is-soon-pulse'); }, 400);
    });
  });

  // Cerrar el menú de usuario al hacer click afuera.
  document.addEventListener('click', function (e) {
    document.querySelectorAll('.user-menu details[open]').forEach(function (d) {
      if (!d.contains(e.target)) d.removeAttribute('open');
    });
  });

  // Mostrar/ocultar el campo "conductor asociado" según el rol elegido.
  document.querySelectorAll('[data-role-select]').forEach(function (sel) {
    var field = document.querySelector('[data-driver-field]');
    if (!field) return;
    var sync = function () {
      if (sel.value === 'driver') { field.removeAttribute('hidden'); }
      else { field.setAttribute('hidden', ''); }
    };
    sel.addEventListener('change', sync);
    sync();
  });

  // Modal de confirmación para formularios con data-confirm.
  var pendingForm = null;
  function buildModal() {
    var el = document.createElement('div');
    el.className = 'modal-overlay';
    el.innerHTML =
      '<div class="modal" role="dialog" aria-modal="true">' +
      '  <p class="modal-msg"></p>' +
      '  <div class="modal-actions">' +
      '    <button type="button" class="btn btn-secondary" data-modal-cancel>Cancelar</button>' +
      '    <button type="button" class="btn btn-primary" data-modal-ok>Confirmar</button>' +
      '  </div>' +
      '</div>';
    document.body.appendChild(el);
    el.addEventListener('click', function (e) {
      if (e.target === el || e.target.hasAttribute('data-modal-cancel')) hideModal();
      if (e.target.hasAttribute('data-modal-ok') && pendingForm) {
        var f = pendingForm; pendingForm = null; f.submit();
      }
    });
    return el;
  }
  var modal = null;
  function hideModal() { if (modal) modal.classList.remove('is-open'); pendingForm = null; }

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (form.matches('[data-confirm]') && !form.dataset.confirmed) {
      e.preventDefault();
      pendingForm = form;
      if (!modal) modal = buildModal();
      modal.querySelector('.modal-msg').textContent = form.getAttribute('data-confirm');
      modal.classList.add('is-open');
    }
  });

  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') hideModal(); });

  // --- Campana de notificaciones (§16): polling + marcar leídas --------------
  (function bell() {
    var root = document.querySelector('[data-bell]');
    if (!root) return;
    var badge = root.querySelector('[data-bell-count]');
    var list = root.querySelector('[data-bell-list]');
    var readAll = root.querySelector('[data-bell-readall]');
    var meta = document.querySelector('meta[name="csrf-token"]');
    var token = meta ? meta.getAttribute('content') : '';

    function esc(s) {
      return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
      });
    }

    function render(data) {
      var n = data.count || 0;
      if (badge) {
        badge.textContent = n > 99 ? '99+' : n;
        badge.hidden = n === 0;
      }
      if (!list) return;
      var items = data.notifications || [];
      if (!items.length) {
        list.innerHTML = '<li class="bell-empty muted">Sin notificaciones nuevas.</li>';
        return;
      }
      list.innerHTML = items.map(function (it) {
        return '<li class="bell-item" data-id="' + it.id + '">' +
          '<span class="bell-title">' + esc(it.title) + '</span>' +
          '<span class="bell-body muted">' + esc(it.body || '') + '</span></li>';
      }).join('');
      list.querySelectorAll('[data-id]').forEach(function (li) {
        li.addEventListener('click', function () { markRead(li.getAttribute('data-id')); });
      });
    }

    function poll() {
      fetch('/api/notifications/unread', { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) { if (res.ok) render(res.data); })
        .catch(function () {});
    }

    function markRead(id) {
      fetch('/api/notifications/' + id + '/read', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-CSRF-Token': token }
      }).then(function () { poll(); }).catch(function () {});
    }

    if (readAll) readAll.addEventListener('click', function () {
      fetch('/api/notifications/read-all', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-CSRF-Token': token }
      }).then(function () { poll(); }).catch(function () {});
    });

    document.addEventListener('click', function (e) { if (!root.contains(e.target)) root.removeAttribute('open'); });

    poll();
    setInterval(poll, 20000);
  })();
})();
