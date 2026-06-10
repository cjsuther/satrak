<?php

declare(strict_types=1);

/**
 * Satrak — Plataforma de Tracking
 * Único punto de entrada web (front controller).
 *
 * Bootstrap: autoload Composer -> config -> PHP-DI container -> Slim app ->
 * Twig -> rutas -> error handler. La lógica de cada capa se irá sumando por fase.
 */

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Satrak\Infrastructure\Database;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

// Servidor embebido (php -S): servir archivos estáticos existentes tal cual.
// En Apache esto lo resuelve .htaccess (RewriteCond -f); acá solo aplica en dev.
if (PHP_SAPI === 'cli-server') {
    $static = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($static)) {
        return false;
    }
}

/* ----------------------------------------------- Resolver la raíz privada */
// Carpetas privadas (src/, vendor/, config/, templates/, storage/) que NO se
// sirven por web. Soporta dos layouts en Hostinger:
//   A) Split (recomendado): el doc root del subdominio es public_html/app y
//      contiene solo public/. Los privados viven fuera de la web; la ruta se
//      indica con la env SATRAK_APP_ROOT (SetEnv en .htaccess) o el archivo
//      _satrak_root.php que escribe el script de deploy.
//   B) Intacto: el doc root apunta a satrak-app/public (privados son hermanos).
$privateRoot = '';
$rootFile = __DIR__ . '/_satrak_root.php';
foreach ([
    (string) (getenv('SATRAK_APP_ROOT') ?: ($_SERVER['SATRAK_APP_ROOT'] ?? '')),
    is_file($rootFile) ? (string) (require $rootFile) : '',
    dirname(__DIR__),                       // layout intacto / dev local
] as $candidate) {
    if ($candidate !== '' && is_file($candidate . '/src/settings.php') && is_file($candidate . '/vendor/autoload.php')) {
        $privateRoot = $candidate;
        break;
    }
}
if ($privateRoot === '') {
    http_response_code(500);
    exit('Error de configuración: no se encontró la raíz privada de la app (src/vendor). Revisá SATRAK_APP_ROOT o el deploy (ver deploy/DEPLOY-HOSTINGER.md).');
}

require $privateRoot . '/vendor/autoload.php';

/* ------------------------------------------------------------------ Config */
$settings = require $privateRoot . '/src/settings.php';

// Entorno y zona horaria.
$GLOBALS['satrak_env'] = $settings['app']['env'] ?? 'production';
date_default_timezone_set($settings['app']['tz'] ?? 'UTC');

$debug = (bool) ($settings['app']['debug'] ?? false);

// En producción, errores ocultos y logueados fuera del document root.
if (!$debug) {
    ini_set('display_errors', '0');
    @mkdir($settings['paths']['logs'], 0775, true);
    ini_set('log_errors', '1');
    ini_set('error_log', $settings['paths']['logs'] . '/php-error.log');
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

/* --------------------------------------------------------------- Container */
$builder = new ContainerBuilder();
$builder->addDefinitions([
    'settings' => $settings,

    Database::class => fn (ContainerInterface $c) => new Database($c->get('settings')['db']),

    Twig::class => function (ContainerInterface $c): Twig {
        $cfg  = $c->get('settings');
        $twig = Twig::create($cfg['paths']['templates'], [
            'cache'       => false,            // se activará cache en prod en una fase posterior
            'autoescape'  => 'html',           // salida siempre escapada (§6)
            'debug'       => (bool) $cfg['app']['debug'],
        ]);
        // Variables globales disponibles en todas las plantillas.
        $env = $twig->getEnvironment();
        $env->addGlobal('app_name', 'Satrak');
        $env->addGlobal('app_env', $cfg['app']['env']);
        $env->addGlobal('base_url', $cfg['app']['base_url']);
        return $twig;
    },
]);
$container = $builder->build();

/* --------------------------------------------------------------- Slim app */
AppFactory::setContainer($container);
$app = AppFactory::create();

// Middlewares base. (Auth/Tenant/Rbac/Csrf se suman en Fase 2.)
$app->addBodyParsingMiddleware();
$app->add(TwigMiddleware::create($app, $container->get(Twig::class)));
$app->addRoutingMiddleware();

// Error middleware al final de la pila (se ejecuta primero). Detalle solo en debug.
$errorMiddleware = $app->addErrorMiddleware($debug, true, true);

/* ------------------------------------------------------------------ Rutas */
(require $privateRoot . '/src/routes.php')($app);

$app->run();
