<?php /** Planes y precios. */ $planes = content('planes'); ?>
<section class="page-hero page-hero--sm">
  <div class="page-hero__grid" aria-hidden="true"></div>
  <div class="container page-hero__inner">
    <span class="eyebrow">Planes</span>
    <h1>Un plan para cada operación</h1>
    <p class="page-hero__subtitle">Elegí el punto de partida. La cotización final depende de tus unidades y necesidades; te la armamos a medida.</p>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="cards-3 cards-3--plans">
      <?php foreach ($planes as $p): ?>
      <div class="card card--plan<?= !empty($p['destacado']) ? ' card--featured' : '' ?>">
        <?php if (!empty($p['destacado'])): ?><span class="card__badge">Más elegido</span><?php endif; ?>
        <h3><?= e($p['nombre']) ?></h3>
        <p class="card--plan__para"><?= e($p['para']) ?></p>
        <p class="card--plan__price"><?= e($p['precio']) ?></p>
        <ul class="feature-list">
          <?php foreach ($p['features'] as $f): ?>
          <li><?= e($f) ?></li>
          <?php endforeach; ?>
        </ul>
        <a class="btn btn-primary btn-block" href="<?= e(url('/contacto')) ?>?plan=<?= e($p['slug']) ?>">Consultar</a>
      </div>
      <?php endforeach; ?>
    </div>
    <p class="note">La instalación y el equipo se cotizan aparte. Los precios pueden variar según la cantidad de unidades y el tipo de operación.</p>
  </div>
</section>

<?php partial('cta'); ?>
