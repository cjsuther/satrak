<?php /** Nosotros. */ ?>
<section class="page-hero page-hero--sm">
  <div class="page-hero__grid" aria-hidden="true"></div>
  <div class="container page-hero__inner">
    <span class="eyebrow">Quiénes somos</span>
    <h1>Tecnología de rastreo con los pies en el terreno</h1>
    <p class="page-hero__subtitle">Nacimos para resolver un problema concreto: que el seguimiento satelital funcione de verdad, también donde el servicio masivo se cae.</p>
  </div>
</section>

<section class="section">
  <div class="container narrow">
    <?= section_heading('Nuestra misión', 'Por qué Satrak') ?>
    <p>Satrak ofrece seguimiento satelital de vehículos y personal en Argentina, con un foco claro: la cobertura real. Trabajamos en zonas exigentes —como la cuenca petrolera de Neuquén y Vaca Muerta— donde una operación no puede permitirse perder la señal.</p>
    <p>Combinamos equipos confiables, conectividad multicarrier y un soporte cercano para que empresas, flotas, logística y particulares tengan control y tranquilidad sobre lo que más les importa.</p>
  </div>
</section>

<section class="section section--alt">
  <div class="container">
    <?= section_heading('Lo que nos guía', 'Nuestros valores') ?>
    <div class="cards-3">
      <div class="card">
        <span class="card__icon" aria-hidden="true">🎯</span>
        <h3>Precisión</h3>
        <p>Datos confiables en tiempo real. Decisiones basadas en información, no en suposiciones.</p>
      </div>
      <div class="card">
        <span class="card__icon" aria-hidden="true">🛡️</span>
        <h3>Confianza</h3>
        <p>Manejamos información sensible con responsabilidad y transparencia.</p>
      </div>
      <div class="card">
        <span class="card__icon" aria-hidden="true">🤝</span>
        <h3>Soporte local</h3>
        <p>Atención humana y cercana, que conoce el terreno donde operás.</p>
      </div>
    </div>
  </div>
</section>

<?php partial('cta'); ?>
