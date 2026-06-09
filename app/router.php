<?php
/**
 * Mapa de rutas → vista + metadata. URLs limpias, sin .php.
 * Devuelve el HTML final o maneja el POST de contacto.
 */

require __DIR__ . '/helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Normaliza la URI: saca query string y trailing slash (salvo raíz).
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$uri = rawurldecode($uri);
if ($uri !== '/' && str_ends_with($uri, '/')) {
    header('Location: ' . rtrim($uri, '/'), true, 301);
    exit;
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$site = content('site');

// Endpoint de envío del formulario (POST).
if ($uri === '/contacto/enviar' && $method === 'POST') {
    require __DIR__ . '/controllers/ContactController.php';
    (new ContactController())->submit();
    exit;
}

// Tabla de rutas GET: ruta → [page, meta].
$routes = [
    '/' => [
        'page' => 'home',
        'meta' => [
            'title'       => 'Satrak — Seguimiento satelital de vehículos y personal en Argentina',
            'description' => 'Seguimiento satelital en tiempo real, con cobertura donde otros no llegan. Rastreo de vehículos y personal en toda Argentina. Pedí tu cotización.',
        ],
    ],
    '/servicios/vehiculos' => [
        'page' => 'vehiculos',
        'meta' => [
            'title'       => 'Rastreo de vehículos | Satrak',
            'description' => 'Localización en tiempo real, geocercas, alertas de velocidad y corte remoto de motor para tu flota o vehículo particular.',
        ],
    ],
    '/servicios/personal' => [
        'page' => 'personal',
        'meta' => [
            'title'       => 'Rastreo de personal | Satrak',
            'description' => 'Seguimiento de personal de campo y cuadrillas con botón SOS y zonas seguras. Cobertura en terreno difícil.',
        ],
    ],
    '/cobertura' => [
        'page' => 'cobertura',
        'meta' => [
            'title'       => 'Cobertura donde otros no llegan | Satrak',
            'description' => 'Tecnología 4G con SIM multicarrier para operar en zonas remotas como la cuenca neuquina y Vaca Muerta.',
        ],
    ],
    '/planes' => [
        'page' => 'planes',
        'meta' => [
            'title'       => 'Planes y precios | Satrak',
            'description' => 'Planes de seguimiento satelital para particulares, flotas y empresas. Cotización a medida según tus unidades.',
        ],
    ],
    '/nosotros' => [
        'page' => 'nosotros',
        'meta' => [
            'title'       => 'Quiénes somos | Satrak',
            'description' => 'Somos Satrak: tecnología de rastreo satelital con soporte local y cobertura real en las zonas más exigentes de Argentina.',
        ],
    ],
    '/contacto' => [
        'page' => 'contacto',
        'meta' => [
            'title'       => 'Contacto y cotización | Satrak',
            'description' => 'Pedí tu cotización de seguimiento satelital. Te contactamos a la brevedad por mail o WhatsApp.',
        ],
    ],
    '/faq' => [
        'page' => 'faq',
        'meta' => [
            'title'       => 'Preguntas frecuentes | Satrak',
            'description' => 'Respuestas sobre cobertura, instalación, corte remoto, rastreo de personal y precios de Satrak.',
        ],
    ],
    '/privacidad' => [
        'page' => 'privacidad',
        'meta' => [
            'title'       => 'Política de privacidad | Satrak',
            'description' => 'Política de protección de datos personales de Satrak conforme a la Ley 25.326.',
        ],
    ],
    '/terminos' => [
        'page' => 'terminos',
        'meta' => [
            'title'       => 'Términos y condiciones | Satrak',
            'description' => 'Términos y condiciones de uso del sitio y los servicios de Satrak.',
        ],
    ],
];

// Si tras enviar el form se redirigió a /contacto?ok=1, la página de contacto lo maneja.
if (isset($routes[$uri])) {
    $route = $routes[$uri];
    echo render($route['page'], $route['meta']);
    exit;
}

// 404
http_response_code(404);
echo render('404', [
    'title'       => 'Página no encontrada | Satrak',
    'description' => 'La página que buscás no existe.',
]);
