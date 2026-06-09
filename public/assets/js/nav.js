/* Menú mobile + header con scroll. JS vanilla, accesible. */
(function () {
  'use strict';

  // Header: clase al hacer scroll para fondo sólido.
  var header = document.getElementById('site-header');
  if (header) {
    var onScroll = function () {
      header.classList.toggle('is-scrolled', window.scrollY > 10);
    };
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
  }

  // Menú hamburguesa.
  var toggle = document.getElementById('nav-toggle');
  var nav = document.getElementById('site-nav');
  if (!toggle || !nav) return;

  var open = function () {
    nav.classList.add('is-open');
    toggle.setAttribute('aria-expanded', 'true');
    toggle.setAttribute('aria-label', 'Cerrar menú');
    document.body.classList.add('nav-open');
  };
  var close = function () {
    nav.classList.remove('is-open');
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-label', 'Abrir menú');
    document.body.classList.remove('nav-open');
  };

  toggle.addEventListener('click', function () {
    if (toggle.getAttribute('aria-expanded') === 'true') {
      close();
    } else {
      open();
    }
  });

  // Cerrar al hacer click en un link.
  nav.addEventListener('click', function (e) {
    if (e.target.closest('a')) close();
  });

  // Cerrar con Esc.
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && nav.classList.contains('is-open')) {
      close();
      toggle.focus();
    }
  });

  // Cerrar al volver a desktop.
  window.addEventListener('resize', function () {
    if (window.innerWidth > 900) close();
  });
})();
