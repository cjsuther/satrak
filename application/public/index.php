<?php
/**
 * Satrak — Punto de entrada web único.
 *
 * Bootstrap de Slim 4: contenedor DI (PHP-DI) + Twig + sesión endurecida +
 * middlewares (CSRF, seguridad) + error handler. Todo el tráfico del subdominio
 * app.satrak.online pasa por acá (ver public/.htaccess para el rewrite).
 */

declare(strict_types=1);

use DI\ContainerBuilder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Satrak\Application\Middleware\CsrfMiddleware;
use Satrak\Application\Support\Session;
use Slim\Factory\AppFactory;
use Slim\Views\TwigMiddleware;
use Slim\Views\Twig;

require dirname(__DIR__) . '/vendor/autoload.php';

/* --------------------------------------------------------------------------
 * Configuración y entorno
 * ------------------------------------------------------------------------ */
$config = require dirname(__DIR__) . '/src/settings.php';

date_default_timezone_set($config['app']['tz']);

$debug = (bool) $config['app']['debug'];
if ($debug) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', dirname(__DIR__) . '/storage/logs/php-error.log');
}

/* --------------------------------------------------------------------------
 * Contenedor DI
 * ------------------------------------------------------------------------ */
$builder = new ContainerBuilder();
$builder->addDefinitions(require dirname(__DIR__) . '/src/dependencies.php');
$container = $builder->build();

/* --------------------------------------------------------------------------
 * Sesión endurecida (HttpOnly/Secure/SameSite + timeout por inactividad)
 * ------------------------------------------------------------------------ */
$container->get(Session::class)->start();

/* --------------------------------------------------------------------------
 * Aplicación Slim
 * ------------------------------------------------------------------------ */
AppFactory::setContainer($container);
$app = AppFactory::create();

$app->add(TwigMiddleware::createFromContainer($app, Twig::class));

// CSRF en toda mutación (POST/PUT/PATCH/DELETE).
$app->add($container->get(CsrfMiddleware::class));

// Headers de seguridad + CSP (permite OSM tiles + Leaflet CDN).
$app->add(function (Request $request, $handler) use ($config): Response {
    $response = $handler->handle($request);

    $csp = implode('; ', [
        "default-src 'self'",
        "img-src 'self' data: https://*.tile.openstreetmap.org https://unpkg.com",
        "style-src 'self' 'unsafe-inline' https://unpkg.com https://fonts.googleapis.com",
        "script-src 'self' https://unpkg.com",
        "font-src 'self' https://fonts.gstatic.com",
        "connect-src 'self'",
        "frame-ancestors 'none'",
    ]);

    return $response
        ->withHeader('X-Content-Type-Options', 'nosniff')
        ->withHeader('X-Frame-Options', 'DENY')
        ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->withHeader('Content-Security-Policy', $csp);
});

$app->addRoutingMiddleware();

// Error handler: en prod oculta detalles; en dev los muestra.
$app->addErrorMiddleware($debug, true, true);

/* --------------------------------------------------------------------------
 * Rutas
 * ------------------------------------------------------------------------ */
require dirname(__DIR__) . '/src/routes.php';

$app->run();
