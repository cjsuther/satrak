<?php
/** Contacto + formulario de captura de leads. */
$ok     = isset($_GET['ok']);
$errors = $_SESSION['_form_errors'] ?? [];
$old    = $_SESSION['_form_old'] ?? [];
unset($_SESSION['_form_errors'], $_SESSION['_form_old']);
$val = fn(string $k) => e($old[$k] ?? '');
$err = fn(string $k) => isset($errors[$k]) ? '<span class="field-error">' . e($errors[$k]) . '</span>' : '';
$planPrefill = $_GET['plan'] ?? '';
?>
<section class="page-hero page-hero--sm">
  <div class="page-hero__grid" aria-hidden="true"></div>
  <div class="container page-hero__inner">
    <span class="eyebrow">Contacto</span>
    <h1>Pedí tu cotización</h1>
    <p class="page-hero__subtitle">Completá el formulario o escribinos por WhatsApp. Te respondemos a la brevedad.</p>
  </div>
</section>

<section class="section">
  <div class="container contact-layout">
    <div class="contact-form-wrap" id="formulario">
      <?php if ($ok): ?>
      <div class="alert alert--success" role="status">
        <strong>¡Recibimos tu consulta!</strong> Te vamos a contactar a la brevedad. Si es urgente, escribinos por WhatsApp.
      </div>
      <?php endif; ?>
      <?php if (!empty($errors['general'])): ?>
      <div class="alert alert--error" role="alert">
        <?= e($errors['general']) ?>
      </div>
      <?php endif; ?>

      <form class="contact-form" id="contact-form" method="post" action="<?= e(url('/contacto/enviar')) ?>" novalidate>
        <?= csrf_field() ?>
        <!-- Honeypot: debe quedar vacío -->
        <div class="hp" aria-hidden="true">
          <label>No completar este campo<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label for="nombre">Nombre y apellido <span class="req">*</span></label>
            <input type="text" id="nombre" name="nombre" value="<?= $val('nombre') ?>" required minlength="2" maxlength="80" autocomplete="name">
            <?= $err('nombre') ?>
          </div>
          <div class="form-group">
            <label for="email">Email <span class="req">*</span></label>
            <input type="email" id="email" name="email" value="<?= $val('email') ?>" required maxlength="120" autocomplete="email">
            <?= $err('email') ?>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label for="telefono">Teléfono / WhatsApp <span class="req">*</span></label>
            <input type="tel" id="telefono" name="telefono" value="<?= $val('telefono') ?>" required autocomplete="tel">
            <?= $err('telefono') ?>
          </div>
          <div class="form-group">
            <label for="empresa">Empresa</label>
            <input type="text" id="empresa" name="empresa" value="<?= $val('empresa') ?>" maxlength="100" autocomplete="organization">
            <?= $err('empresa') ?>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label for="servicio">Servicio de interés <span class="req">*</span></label>
            <select id="servicio" name="servicio" required>
              <?php
                $selServ = $old['servicio'] ?? ($planPrefill === 'particular' ? 'vehiculos' : '');
                $opts = ['' => 'Elegí una opción', 'vehiculos' => 'Vehículos', 'personal' => 'Personal', 'ambos' => 'Ambos'];
                foreach ($opts as $v => $label):
              ?>
              <option value="<?= e($v) ?>"<?= $selServ === $v ? ' selected' : '' ?><?= $v === '' ? ' disabled' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
            <?= $err('servicio') ?>
          </div>
          <div class="form-group">
            <label for="unidades">Cantidad de unidades</label>
            <select id="unidades" name="unidades">
              <?php
                $selUni = $old['unidades'] ?? '';
                $uopts = ['' => 'A definir', '1' => '1', '2-10' => '2 a 10', '11-50' => '11 a 50', '50+' => 'Más de 50'];
                foreach ($uopts as $v => $label):
              ?>
              <option value="<?= e($v) ?>"<?= $selUni === $v ? ' selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
            <?= $err('unidades') ?>
          </div>
        </div>

        <div class="form-group">
          <label for="mensaje">Mensaje</label>
          <textarea id="mensaje" name="mensaje" rows="4" maxlength="1000" placeholder="Contanos sobre tu operación, zona y qué necesitás."><?= $val('mensaje') ?></textarea>
          <?= $err('mensaje') ?>
        </div>

        <button type="submit" class="btn btn-primary btn-lg">Enviar consulta</button>
        <p class="form-note">Al enviar aceptás nuestra <a href="<?= e(url('/privacidad')) ?>">política de privacidad</a>.</p>
      </form>
    </div>

    <aside class="contact-aside">
      <div class="contact-card">
        <h2>Otros canales</h2>
        <a class="btn btn-whatsapp btn-block" href="<?= e(whatsapp_url()) ?>" target="_blank" rel="noopener">
          <svg viewBox="0 0 24 24" aria-hidden="true" width="20" height="20"><path fill="currentColor" d="M.057 24l1.687-6.163a11.867 11.867 0 01-1.587-5.946C.16 5.335 5.495 0 12.05 0a11.82 11.82 0 018.413 3.488 11.82 11.82 0 013.48 8.414c-.003 6.557-5.338 11.892-11.893 11.892a11.9 11.9 0 01-5.688-1.448L.057 24zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884a9.86 9.86 0 001.51 5.26l-.999 3.648 3.768-.989zm9.182-5.55c-.075-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>
          Escribir por WhatsApp
        </a>
        <ul class="contact-list">
          <li><span>Email</span><a href="mailto:<?= e($site['email']) ?>"><?= e($site['email']) ?></a></li>
          <li><span>Teléfono</span><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $site['telefono'])) ?>"><?= e($site['telefono']) ?></a></li>
          <li><span>Horario</span><?= e($site['horario']) ?></li>
          <li><span>Zona</span><?= e($site['direccion']) ?></li>
        </ul>
      </div>
      <div class="contact-card contact-card--steps">
        <h2>Qué pasa después</h2>
        <ol>
          <li>Recibimos tu consulta y la revisamos.</li>
          <li>Te contactamos para entender tu operación.</li>
          <li>Te enviamos una cotización a medida.</li>
        </ol>
      </div>
    </aside>
  </div>
</section>
