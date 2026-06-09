<?php /** Ilustración "traza": recorrido punteado que termina en el isotipo. */ ?>
<svg class="trace-art" viewBox="0 0 400 320" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Recorrido satelital estilizado">
  <defs>
    <linearGradient id="traceGrad" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#1FE0C4"/><stop offset="1" stop-color="#11335C"/>
    </linearGradient>
    <pattern id="dots" width="22" height="22" patternUnits="userSpaceOnUse">
      <circle cx="2" cy="2" r="1.4" fill="#1FE0C4" opacity="0.18"/>
    </pattern>
  </defs>
  <rect width="400" height="320" fill="url(#dots)"/>
  <path d="M30 270 C 110 240, 90 150, 170 140 S 290 110, 330 70"
        fill="none" stroke="#1FE0C4" stroke-width="2.5" stroke-dasharray="2 12" stroke-linecap="round" opacity="0.9"/>
  <circle cx="30" cy="270" r="6" fill="#FFB23E"/>
  <circle cx="170" cy="140" r="5" fill="#1FE0C4" opacity="0.7"/>
  <!-- isotipo al final del recorrido -->
  <g transform="translate(300 22) scale(0.55)">
    <path d="M60 133 C 39 100, 22 84.6 22 56 a 38 38 0 1 1 76 0 C 98 84.6, 81 100, 60 133 Z" fill="url(#traceGrad)"/>
    <ellipse cx="60" cy="56" rx="46" ry="15" fill="none" stroke="#1FE0C4" stroke-width="5" transform="rotate(-25 60 56)"/>
    <circle cx="60" cy="56" r="14" fill="none" stroke="#0A2342" stroke-width="6"/>
    <circle cx="60" cy="56" r="11" fill="#1FE0C4"/>
  </g>
</svg>
