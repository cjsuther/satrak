<?php
/** Home. */
$servicios = content('servicios');
$planes    = content('planes');
?>
<section class="hero">
  <div class="hero__grid" aria-hidden="true"></div>
  <div class="container hero__inner">
    <div class="hero__content">
      <span class="eyebrow">Seguimiento satelital · Argentina</span>
      <h1 class="hero__title">Seguimiento satelital en tiempo real, <span class="text-teal">donde otros no llegan.</span></h1>
      <p class="hero__subtitle">Rastreo de vehículos y personal con cobertura real, incluso en las zonas más exigentes del país. Vos sabés dónde está todo; nosotros nos ocupamos de la señal.</p>
      <div class="hero__actions">
        <a class="btn btn-primary" href="<?= e(url('/contacto')) ?>">Pedí una cotización</a>
        <a class="btn btn-secondary" href="<?= e(whatsapp_url()) ?>" target="_blank" rel="noopener">
          <svg viewBox="0 0 24 24" aria-hidden="true" width="20" height="20"><path fill="currentColor" d="M.057 24l1.687-6.163a11.867 11.867 0 01-1.587-5.946C.16 5.335 5.495 0 12.05 0a11.82 11.82 0 018.413 3.488 11.82 11.82 0 013.48 8.414c-.003 6.557-5.338 11.892-11.893 11.892a11.9 11.9 0 01-5.688-1.448L.057 24zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884a9.86 9.86 0 001.51 5.26l-.999 3.648 3.768-.989zm9.182-5.55c-.075-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>
          Hablar por WhatsApp
        </a>
      </div>
      <div class="hero__readout readout">
        <span class="live-dot" aria-hidden="true"></span>
        <span>LIVE · LAT -34.55 · LON -58.49</span>
      </div>
    </div>
  </div>
</section>

<!-- Tira de confianza -->
<section class="trust">
  <div class="container trust__grid">
    <div class="trust__item">
      <span class="trust__icon" aria-hidden="true">⏱</span>
      <h3>Tiempo real</h3>
      <p>Posición actualizada al instante en tu panel y app.</p>
    </div>
    <div class="trust__item">
      <span class="trust__icon" aria-hidden="true">⚡</span>
      <h3>Corte remoto de motor</h3>
      <p>Bloqueá el arranque a distancia ante un robo.</p>
    </div>
    <div class="trust__item">
      <span class="trust__icon" aria-hidden="true">📡</span>
      <h3>Cobertura en zonas críticas</h3>
      <p>Señal donde el servicio masivo suele fallar.</p>
    </div>
    <div class="trust__item">
      <span class="trust__icon" aria-hidden="true">🤝</span>
      <h3>Soporte local</h3>
      <p>Atención cercana, en tu idioma y tu zona horaria.</p>
    </div>
  </div>
</section>

<!-- Servicios -->
<section class="section">
  <div class="container">
    <?= section_heading('Qué ofrecemos', 'Dos servicios, una sola plataforma') ?>
    <div class="cards-2">
      <?php foreach (['vehiculos', 'personal'] as $key): $s = $servicios[$key]; ?>
      <a class="card card--service" href="<?= e(url($s['url'])) ?>">
        <span class="card__icon" aria-hidden="true"><?= $key === 'vehiculos' ? '🚚' : '🦺' ?></span>
        <h3><?= e($s['nombre']) ?></h3>
        <p><?= e($s['resumen']) ?></p>
        <span class="card__link">Ver más →</span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- Diferencial de cobertura -->
<section class="section coverage-feature">
  <div class="container coverage-feature__inner">
    <div class="coverage-feature__text">
      <?= section_heading('El diferencial', 'Cobertura donde otros no llegan') ?>
      <p>Operamos con tecnología 4G y SIMs multicarrier que se conectan a la red disponible más fuerte en cada punto. Por eso tenemos señal en zonas críticas como la cuenca petrolera de Neuquén y Vaca Muerta, donde el servicio masivo se cae.</p>
      <a class="btn btn-secondary" href="<?= e(url('/cobertura')) ?>">Ver cobertura</a>
    </div>
    <div class="coverage-feature__art" aria-hidden="true">
      <?php partial('trace-art'); ?>
    </div>
  </div>
</section>

<!-- Cómo funciona -->
<section class="section section--alt">
  <div class="container">
    <?= section_heading('Simple de poner en marcha', 'Cómo funciona') ?>
    <ol class="steps">
      <li class="step">
        <span class="step__num">01</span>
        <h3>Instalás el equipo</h3>
        <p>Coordinamos la instalación del dispositivo en tu vehículo o lo entregamos para personal. Menos de una hora por unidad.</p>
      </li>
      <li class="step">
        <span class="step__num">02</span>
        <h3>Ves todo en tu panel</h3>
        <p>Accedés a la ubicación en tiempo real desde el panel web o la app móvil, cuando quieras y desde donde estés.</p>
      </li>
      <li class="step">
        <span class="step__num">03</span>
        <h3>Recibís alertas</h3>
        <p>Geocercas, velocidad, movimiento y SOS: te avisamos al instante para que puedas actuar.</p>
      </li>
    </ol>
  </div>
</section>

<!-- Planes resumen -->
<section class="section">
  <div class="container">
    <?= section_heading('Para cada necesidad', 'Planes') ?>
    <div class="cards-3">
      <?php foreach ($planes as $p): ?>
      <div class="card card--plan<?= !empty($p['destacado']) ? ' card--featured' : '' ?>">
        <?php if (!empty($p['destacado'])): ?><span class="card__badge">Más elegido</span><?php endif; ?>
        <h3><?= e($p['nombre']) ?></h3>
        <p class="card--plan__para"><?= e($p['para']) ?></p>
        <p class="card--plan__price"><?= e($p['precio']) ?></p>
        <ul class="feature-list">
          <?php foreach (array_slice($p['features'], 0, 4) as $f): ?>
          <li><?= e($f) ?></li>
          <?php endforeach; ?>
        </ul>
        <a class="btn btn-secondary btn-block" href="<?= e(url('/planes')) ?>">Ver plan</a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php partial('cta', ['variant' => 'teal']); ?>
