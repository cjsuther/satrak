<?php
/** Layout base. Recibe $meta (array) y $content (HTML de la página). */
$site = content('site');
?>
<!DOCTYPE html>
<html lang="es-AR">
<head>
<?php partial('head-meta', ['meta' => $meta, 'site' => $site]); ?>
</head>
<body>
<a class="skip-link" href="#main">Saltar al contenido</a>
<?php partial('header', ['site' => $site, 'route' => $meta['route']]); ?>
<main id="main">
<?= $content ?>
</main>
<?php partial('footer', ['site' => $site]); ?>
<script src="<?= e(asset('js/nav.js')) ?>" defer></script>
<?php if (in_array($meta['route'] ?? '', ['/contacto'], true) || str_starts_with($meta['route'] ?? '', '/contacto')): ?>
<script src="<?= e(asset('js/form.js')) ?>" defer></script>
<?php endif; ?>
<?php if (($meta['route'] ?? '') === '/faq'): ?>
<script src="<?= e(asset('js/faq.js')) ?>" defer></script>
<?php endif; ?>
</body>
</html>
