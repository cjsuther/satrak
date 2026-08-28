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
use Slim\Exception\HttpException;
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

    // OJO: en producción el servidor de Hostinger inyecta su propia CSP y PISA
    // ésta; la que realmente llega al navegador es la de `public/.htaccess`.
    // Se mantienen iguales a propósito: si tocás una, tocá la otra.
    //
    // MapLibre necesita `worker-src blob:` (levanta sus workers desde un blob)
    // y `tiles.openfreemap.org` para estilo, glyphs, sprites y tiles. Ya no
    // hace falta unpkg: las librerías se sirven desde /assets/vendor/.
    $csp = implode('; ', [
        "default-src 'self'",
        "img-src 'self' data: blob: https://tiles.openfreemap.org",
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
        "script-src 'self'",
        "worker-src 'self' blob:",
        "child-src 'self' blob:",
        "font-src 'self' https://fonts.gstatic.com",
        "connect-src 'self' https://tiles.openfreemap.org",
        "frame-ancestors 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        "object-src 'none'",
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

// La app móvil no sabe leer la página de error HTML de Slim: para /api/* el
// error se devuelve con el mismo envelope {ok,data,error} que el resto.
$errorMiddleware->setDefaultErrorHandler(
    function (
        Request $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails
    ) use ($app, $debug): Response {
        $isApi = str_starts_with($request->getUri()->getPath(), '/api/');

        if (!$isApi) {
            $handler = new Slim\Handlers\ErrorHandler(
                $app->getCallableResolver(),
                $app->getResponseFactory()
            );

            return $handler($request, $exception, $displayErrorDetails, $logErrors, $logErrorDetails);
        }

        $status = $exception instanceof Slim\Exception\HttpException
            ? $exception->getCode()
            : 500;
        if ($status < 400 || $status > 599) {
            $status = 500;
        }

        // En producción no se filtra el detalle interno al cliente.
        $message = $exception instanceof Slim\Exception\HttpException || $debug
            ? $exception->getMessage()
            : 'Error interno del servidor.';

        if (!$exception instanceof Slim\Exception\HttpException) {
            error_log('[api] ' . $exception->getMessage() . ' @ '
                . $exception->getFile() . ':' . $exception->getLine());
        }

        $response = $app->getResponseFactory()->createResponse($status);
        $response->getBody()->write((string) json_encode(
            ['ok' => false, 'data' => null, 'error' => $message],
            JSON_UNESCAPED_UNICODE
        ));

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }
);

/* --------------------------------------------------------------------------
 * Rutas
 * ------------------------------------------------------------------------ */
require dirname(__DIR__) . '/src/routes.php';

$app->run();
