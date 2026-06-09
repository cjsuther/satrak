<?php
/** Header sticky con nav y menú mobile. $route = ruta actual. */
$cur = parse_url($route ?? '/', PHP_URL_PATH) ?: '/';
?>
<header class="site-header" id="site-header">
  <div class="container site-header__inner">
    <a class="site-header__logo" href="<?= e(url('/')) ?>" aria-label="Satrak — inicio">
      <?php partial('logo', ['reversa' => true]); ?>
    </a>

    <nav class="site-nav" id="site-nav" aria-label="Navegación principal">
      <ul class="site-nav__list">
        <li class="has-dropdown">
          <a href="<?= e(url('/servicios/vehiculos')) ?>"<?= nav_active('/servicios', $cur) ?> aria-haspopup="true">Servicios</a>
          <ul class="dropdown">
            <li><a href="<?= e(url('/servicios/vehiculos')) ?>">Rastreo de vehículos</a></li>
            <li><a href="<?= e(url('/servicios/personal')) ?>">Rastreo de personal</a></li>
          </ul>
        </li>
        <li><a href="<?= e(url('/cobertura')) ?>"<?= nav_active('/cobertura', $cur) ?>>Cobertura</a></li>
        <li><a href="<?= e(url('/planes')) ?>"<?= nav_active('/planes', $cur) ?>>Planes</a></li>
        <li><a href="<?= e(url('/nosotros')) ?>"<?= nav_active('/nosotros', $cur) ?>>Nosotros</a></li>
        <li><a href="<?= e(url('/faq')) ?>"<?= nav_active('/faq', $cur) ?>>FAQ</a></li>
      </ul>
    </nav>

    <div class="site-header__actions">
      <a class="btn btn-secondary btn-sm" href="<?= e($site['plataforma_url'] ?: '#') ?>" target="_blank" rel="noopener">Acceso clientes</a>
      <a class="btn btn-primary btn-sm" href="<?= e(url('/contacto')) ?>">Contacto</a>
    </div>

    <button class="nav-toggle" id="nav-toggle" aria-label="Abrir menú" aria-expanded="false" aria-controls="site-nav">
      <span></span><span></span><span></span>
    </button>
  </div>
</header>
