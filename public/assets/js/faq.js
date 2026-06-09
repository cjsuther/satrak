/* Acordeón FAQ accesible. JS vanilla. */
(function () {
  'use strict';
  var accordion = document.getElementById('faq-accordion');
  if (!accordion) return;

  var triggers = accordion.querySelectorAll('.accordion__trigger');

  triggers.forEach(function (trigger) {
    trigger.addEventListener('click', function () {
      var expanded = trigger.getAttribute('aria-expanded') === 'true';
      var panel = document.getElementById(trigger.getAttribute('aria-controls'));
      trigger.setAttribute('aria-expanded', String(!expanded));
      if (panel) panel.hidden = expanded;
    });
  });
})();
