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
})();
