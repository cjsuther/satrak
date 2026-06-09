<?php
/** Isotipo + logotipo. $reversa => isotipo sobre fondo oscuro (path blanco). */
$reversa = $reversa ?? false;
$pathFill = $reversa ? '#FFFFFF' : 'url(#satPin)';
?>
<span class="logo">
  <svg class="logo__mark" viewBox="0 0 120 140" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
    <defs><linearGradient id="satPin" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#11335C"/><stop offset="1" stop-color="#0A2342"/></linearGradient></defs>
    <path d="M60 133 C 39 100, 22 84.6 22 56 a 38 38 0 1 1 76 0 C 98 84.6, 81 100, 60 133 Z" fill="<?= $pathFill ?>"/>
    <ellipse cx="60" cy="56" rx="46" ry="15" fill="none" stroke="#1FE0C4" stroke-width="5" transform="rotate(-25 60 56)"/>
    <circle cx="60" cy="56" r="14" fill="none" stroke="#0A2342" stroke-width="6"/>
    <circle cx="60" cy="56" r="11" fill="#1FE0C4"/>
  </svg>
  <span class="logo__type">satrak<span class="logo__dot">.</span></span>
</span>
