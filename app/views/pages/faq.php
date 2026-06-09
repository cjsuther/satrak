<?php /** FAQ — acordeón. */ $faqs = content('faqs'); ?>
<section class="page-hero page-hero--sm">
  <div class="page-hero__grid" aria-hidden="true"></div>
  <div class="container page-hero__inner">
    <span class="eyebrow">Ayuda</span>
    <h1>Preguntas frecuentes</h1>
    <p class="page-hero__subtitle">Lo que más nos consultan. ¿No encontrás tu respuesta? Escribinos.</p>
  </div>
</section>

<section class="section">
  <div class="container narrow">
    <div class="accordion" id="faq-accordion">
      <?php foreach ($faqs as $i => $f): ?>
      <div class="accordion__item">
        <h3 class="accordion__header">
          <button type="button" class="accordion__trigger" aria-expanded="false" aria-controls="faq-panel-<?= $i ?>" id="faq-trigger-<?= $i ?>">
            <span><?= e($f['pregunta']) ?></span>
            <span class="accordion__icon" aria-hidden="true"></span>
          </button>
        </h3>
        <div class="accordion__panel" id="faq-panel-<?= $i ?>" role="region" aria-labelledby="faq-trigger-<?= $i ?>" hidden>
          <p><?= e($f['respuesta']) ?></p>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- JSON-LD FAQPage para SEO -->
<script type="application/ld+json">
<?= json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => array_map(fn($f) => [
        '@type' => 'Question',
        'name'  => $f['pregunta'],
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['respuesta']],
    ], $faqs),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
</script>

<?php partial('cta'); ?>
