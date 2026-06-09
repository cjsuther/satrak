/* Validación cliente (no bloqueante) + envío por fetch con degradación a POST. */
(function () {
  'use strict';
  var form = document.getElementById('contact-form');
  if (!form) return;

  var rules = {
    nombre: function (v) { return v.trim().length >= 2 && v.trim().length <= 80; },
    email: function (v) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.trim()); },
    telefono: function (v) { var d = v.replace(/\D/g, ''); return d.length >= 6 && d.length <= 20; },
    servicio: function (v) { return ['vehiculos', 'personal', 'ambos'].indexOf(v) !== -1; }
  };
  var messages = {
    nombre: 'Ingresá tu nombre y apellido (2 a 80 caracteres).',
    email: 'Ingresá un email válido.',
    telefono: 'Ingresá un teléfono válido (6 a 20 dígitos).',
    servicio: 'Elegí un servicio de interés.'
  };

  function setError(field, msg) {
    var group = field.closest('.form-group');
    if (!group) return;
    group.classList.toggle('has-error', !!msg);
    var existing = group.querySelector('.field-error');
    if (msg) {
      if (!existing) {
        existing = document.createElement('span');
        existing.className = 'field-error';
        group.appendChild(existing);
      }
      existing.textContent = msg;
      field.setAttribute('aria-invalid', 'true');
    } else {
      if (existing) existing.remove();
      field.removeAttribute('aria-invalid');
    }
  }

  function validateField(name) {
    var field = form.elements[name];
    if (!field || !rules[name]) return true;
    var valid = rules[name](field.value);
    setError(field, valid ? '' : messages[name]);
    return valid;
  }

  function validateAll() {
    var ok = true;
    Object.keys(rules).forEach(function (name) {
      if (!validateField(name)) ok = false;
    });
    return ok;
  }

  // Validar al salir del campo.
  Object.keys(rules).forEach(function (name) {
    var field = form.elements[name];
    if (field) {
      field.addEventListener('blur', function () { validateField(name); });
    }
  });

  form.addEventListener('submit', function (e) {
    if (!validateAll()) {
      e.preventDefault();
      var firstError = form.querySelector('.has-error input, .has-error select, .has-error textarea');
      if (firstError) firstError.focus();
      return;
    }

    // Envío por fetch (sin recarga). Degrada a POST normal si fetch falla o no existe.
    if (!window.fetch) return; // deja seguir el submit clásico
    e.preventDefault();

    var submitBtn = form.querySelector('[type="submit"]');
    if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Enviando…'; }

    fetch(form.action, {
      method: 'POST',
      body: new FormData(form),
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json().then(function (d) { return { status: r.status, data: d }; }); })
      .then(function (res) {
        if (res.data && res.data.ok) {
          showSuccess();
        } else {
          showServerErrors(res.data && res.data.errors);
        }
      })
      .catch(function () {
        // Si el fetch falla, hacemos submit clásico como respaldo.
        form.submit();
      })
      .finally(function () {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Enviar consulta'; }
      });
  });

  function showSuccess() {
    var wrap = document.getElementById('formulario');
    var alert = document.createElement('div');
    alert.className = 'alert alert--success';
    alert.setAttribute('role', 'status');
    alert.innerHTML = '<strong>¡Recibimos tu consulta!</strong> Te vamos a contactar a la brevedad.';
    form.reset();
    form.parentNode.insertBefore(alert, form);
    form.querySelectorAll('.has-error').forEach(function (g) { g.classList.remove('has-error'); });
    alert.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  function showServerErrors(errors) {
    if (!errors) {
      window.alert('No pudimos enviar tu consulta. Probá de nuevo o escribinos por WhatsApp.');
      return;
    }
    Object.keys(errors).forEach(function (name) {
      var field = form.elements[name];
      if (field) {
        setError(field, errors[name]);
      } else if (name === 'general' || name === '_token') {
        window.alert(errors[name]);
      }
    });
    var firstError = form.querySelector('.has-error input, .has-error select, .has-error textarea');
    if (firstError) firstError.focus();
  }
})();
