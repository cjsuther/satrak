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

require __DIR__ . '/../vendor/autoload.php';

/* ------------------------------------------------------------------ Config */
$settings = require __DIR__ . '/../src/settings.php';

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
(require __DIR__ . '/../src/routes.php')($app);

$app->run();
