<?php /** 404. */ ?>
<section class="page-hero error-hero">
  <div class="page-hero__grid" aria-hidden="true"></div>
  <div class="container page-hero__inner">
    <span class="eyebrow">Error 404</span>
    <h1>Perdimos la señal de esta página</h1>
    <p class="page-hero__subtitle">La página que buscás no existe o se movió. Volvé al inicio o escribinos si necesitás ayuda.</p>
    <div class="page-hero__actions">
      <a class="btn btn-primary" href="<?= e(url('/')) ?>">Volver al inicio</a>
      <a class="btn btn-secondary" href="<?= e(url('/contacto')) ?>">Contacto</a>
    </div>
    <div class="readout" style="margin-top:1.5rem">
      <span class="live-dot" aria-hidden="true"></span>
      <span>SIGNAL LOST · 404</span>
    </div>
  </div>
</section>
