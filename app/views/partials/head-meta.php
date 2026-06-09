<?php
/** Metadatos del <head>: SEO, OG, JSON-LD, fuentes, favicons. */
$title = $meta['title'] ?? $site['nombre'];
$desc  = $meta['description'] ?? ($site['descripcion'] ?? '');
$canonical = $meta['canonical'] ?? url('/');
$ogImage   = $meta['og_image'] ?? asset('img/og-image.png');
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<link rel="canonical" href="<?= e($canonical) ?>">
<meta name="theme-color" content="#0A2342">

<!-- Open Graph -->
<meta property="og:type" content="website">
<meta property="og:site_name" content="Satrak">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<meta property="og:url" content="<?= e($canonical) ?>">
<meta property="og:image" content="<?= e($ogImage) ?>">
<meta property="og:locale" content="es_AR">

<!-- Twitter -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e($title) ?>">
<meta name="twitter:description" content="<?= e($desc) ?>">
<meta name="twitter:image" content="<?= e($ogImage) ?>">

<!-- Favicons -->
<link rel="icon" href="<?= e(url('favicon.ico')) ?>" sizes="any">
<link rel="icon" type="image/svg+xml" href="<?= e(asset('img/favicon.svg')) ?>">
<link rel="icon" type="image/png" sizes="32x32" href="<?= e(asset('img/favicon-32.png')) ?>">
<link rel="apple-touch-icon" sizes="180x180" href="<?= e(asset('img/apple-touch-icon-180.png')) ?>">
<link rel="manifest" href="<?= e(url('site.webmanifest')) ?>">

<!-- Fuentes -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;500;600&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">

<!-- Estilos -->
<link rel="stylesheet" href="<?= e(asset('css/tokens.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/styles.css')) ?>">

<!-- Datos estructurados -->
<script type="application/ld+json">
<?= json_encode([
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'Organization',
            '@id'   => url('/') . '#org',
            'name'  => 'Satrak',
            'url'   => url('/'),
            'logo'  => asset('img/favicon-32.png'),
            'description' => $site['descripcion'] ?? '',
            'email' => $site['email'] ?? '',
            'areaServed' => 'AR',
        ],
        [
            '@type' => 'LocalBusiness',
            '@id'   => url('/') . '#business',
            'name'  => 'Satrak',
            'image' => $ogImage,
            'url'   => url('/'),
            'telephone' => $site['telefono'] ?? '',
            'email' => $site['email'] ?? '',
            'address' => [
                '@type' => 'PostalAddress',
                'addressLocality' => 'Neuquén',
                'addressCountry'  => 'AR',
            ],
            'areaServed' => [
                '@type' => 'Country',
                'name'  => 'Argentina',
            ],
        ],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
</script>
