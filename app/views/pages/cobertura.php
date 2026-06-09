<?php /** Cobertura — argumento de venta fuerte. */ ?>
<section class="page-hero">
  <div class="page-hero__grid" aria-hidden="true"></div>
  <div class="container page-hero__inner">
    <span class="eyebrow">El diferencial</span>
    <h1>Cobertura donde otros <span class="text-teal">no llegan.</span></h1>
    <p class="page-hero__subtitle">La señal es el corazón del rastreo. Por eso construimos nuestra conectividad para funcionar justo donde el servicio masivo falla.</p>
  </div>
</section>

<section class="section">
  <div class="container coverage-feature__inner">
    <div class="coverage-feature__text">
      <?= section_heading('Tecnología', '4G + SIM multicarrier') ?>
      <p>Nuestros equipos usan SIMs multicarrier que no dependen de un solo operador: se conectan automáticamente a la red disponible más fuerte en cada punto del recorrido. Si una red se cae o no llega, el equipo salta a otra sin que tengas que hacer nada.</p>
      <p>Esto es lo que nos permite mantener el seguimiento en zonas remotas y de operación intensiva, como la cuenca neuquina y los yacimientos de Vaca Muerta, donde un único operador no alcanza.</p>
    </div>
    <div class="coverage-feature__art" aria-hidden="true">
      <?php partial('trace-art'); ?>
    </div>
  </div>
</section>

<section class="section section--alt">
  <div class="container">
    <?= section_heading('Por qué importa', 'Pensado para terreno difícil') ?>
    <div class="cards-3">
      <div class="card">
        <span class="card__icon" aria-hidden="true">🛰️</span>
        <h3>Redundancia de red</h3>
        <p>Si un operador falla, el equipo cambia de red automáticamente. Menos puntos ciegos.</p>
      </div>
      <div class="card">
        <span class="card__icon" aria-hidden="true">⛰️</span>
        <h3>Zonas remotas</h3>
        <p>Experiencia real operando en la Patagonia y la cuenca petrolera de Neuquén.</p>
      </div>
      <div class="card">
        <span class="card__icon" aria-hidden="true">🔋</span>
        <h3>Continuidad</h3>
        <p>Seguimiento estable para que las alertas lleguen cuando más las necesitás.</p>
      </div>
    </div>
    <p class="note">¿Querés saber si llegamos a tu zona de operación? <a href="<?= e(url('/contacto')) ?>">Consultanos por tu área</a> y lo verificamos.</p>
  </div>
</section>

<?php partial('cta'); ?>
