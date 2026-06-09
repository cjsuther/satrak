<?php
/** Detalle de un servicio. Recibe $s (array del servicio). */
$otroKey = $s['slug'] === 'vehiculos' ? 'personal' : 'vehiculos';
$otro = content('servicios')[$otroKey];
?>
<section class="page-hero">
  <div class="page-hero__grid" aria-hidden="true"></div>
  <div class="container page-hero__inner">
    <span class="eyebrow"><?= e($s['nombre']) ?></span>
    <h1><?= e($s['hero_titulo']) ?></h1>
    <p class="page-hero__subtitle"><?= e($s['hero_subtitulo']) ?></p>
    <div class="page-hero__actions">
      <a class="btn btn-primary" href="<?= e(url('/contacto')) ?>">Pedí una cotización</a>
      <a class="btn btn-secondary" href="<?= e(whatsapp_url()) ?>" target="_blank" rel="noopener">Hablar por WhatsApp</a>
    </div>
  </div>
</section>

<section class="section">
  <div class="container">
    <?= section_heading('Funciones', 'Todo lo que incluye') ?>
    <div class="benefits-grid">
      <?php foreach ($s['beneficios'] as $b): ?>
      <div class="benefit">
        <h3><?= e($b['titulo']) ?></h3>
        <p><?= e($b['desc']) ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section section--alt">
  <div class="container">
    <?= section_heading('Casos de uso', '¿Para quién es?') ?>
    <div class="cards-3">
      <?php foreach ($s['casos'] as $c): ?>
      <div class="card">
        <h3><?= e($c['titulo']) ?></h3>
        <p><?= e($c['desc']) ?></p>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if (!empty($s['nota_legal'])): ?>
    <div class="note note--legal">
      <strong>Uso responsable.</strong> <?= e($s['nota_legal']) ?>
    </div>
    <?php endif; ?>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="cross-link">
      <p>¿También te interesa <strong><?= e($otro['nombre']) ?></strong>?</p>
      <a class="btn btn-secondary" href="<?= e(url($otro['url'])) ?>"><?= e($otro['resumen']) ?> →</a>
    </div>
  </div>
</section>

<?php partial('cta'); ?>
