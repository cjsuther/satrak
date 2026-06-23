<?php
/**
 * Satrak — Punto de entrada web único.
 *
 * Bootstrap de Slim 4: contenedor DI (PHP-DI) + Twig + error handler + headers
 * de seguridad. Todo el tráfico del subdominio app.satrak.online pasa por acá
 * (ver public/.htaccess para el rewrite).
 */

declare(strict_types=1);

use DI\ContainerBuilder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Satrak\Application\Support\Database;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

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
$builder->addDefinitions([
    'config' => $config,

    PDO::class => function () use ($config): PDO {
        return Database::connect($config['db']);
    },

    Twig::class => function () use ($config): Twig {
        $twig = Twig::create(dirname(__DIR__) . '/templates', [
            'cache'       => false,           // sin build step; cache opcional en prod
            'autoescape'  => 'html',          // salida escapada por defecto
            'debug'       => $config['app']['debug'],
            'strict_variables' => false,
        ]);

        $env = $twig->getEnvironment();
        $env->addGlobal('app', [
            'name'      => 'Satrak',
            'base_url'  => $config['app']['base_url'],
            'env'       => $config['app']['env'],
            'year'      => (int) date('Y'),
        ]);

        return $twig;
    },
]);

$container = $builder->build();

/* --------------------------------------------------------------------------
 * Aplicación Slim
 * ------------------------------------------------------------------------ */
AppFactory::setContainer($container);
$app = AppFactory::create();

$app->add(TwigMiddleware::createFromContainer($app, Twig::class));

// Headers de seguridad en toda respuesta (CSP permite OSM tiles + Leaflet CDN).
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
$errorMiddleware = $app->addErrorMiddleware($debug, true, true);

/* --------------------------------------------------------------------------
 * Rutas (Fase 0: home de identidad + healthcheck)
 * ------------------------------------------------------------------------ */
$app->get('/', function (Request $request, Response $response) use ($container): Response {
    $twig = $container->get(Twig::class);

    // Verificación de conexión a la base para mostrar el estado en la home.
    $dbOk = false;
    $dbError = null;
    try {
        $container->get(PDO::class)->query('SELECT 1');
        $dbOk = true;
    } catch (\Throwable $e) {
        $dbError = $e->getMessage();
    }

    return $twig->render($response, 'pages/home.twig', [
        'db_ok'    => $dbOk,
        'db_error' => $dbError,
    ]);
});

// Healthcheck JSON simple (útil para monitoreo / deploy).
$app->get('/health', function (Request $request, Response $response) use ($container): Response {
    $dbOk = false;
    try {
        $container->get(PDO::class)->query('SELECT 1');
        $dbOk = true;
    } catch (\Throwable $e) {
        $dbOk = false;
    }

    $payload = json_encode(['ok' => $dbOk, 'service' => 'satrak', 'db' => $dbOk]);
    $response->getBody()->write((string) $payload);

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus($dbOk ? 200 : 503);
});

$app->run();
