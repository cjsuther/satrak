<?php

declare(strict_types=1);

/**
 * Definición de rutas. Devuelve un closure que recibe la App de Slim.
 * En Fase 0 solo existe la home (verificación de bootstrap + identidad + PDO).
 * Las rutas de auth, panel, API, etc. se suman en las fases siguientes.
 */

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Satrak\Infrastructure\Database;
use Slim\App;
use Slim\Views\Twig;

return function (App $app): void {
    $container = $app->getContainer();

    // Home — pantalla simple con identidad Satrak y diagnóstico de Fase 0.
    $app->get('/', function (Request $request, Response $response) use ($container): Response {
        /** @var Database $db */
        $db = $container->get(Database::class);

        return Twig::fromRequest($request)->render($response, 'pages/home.twig', [
            'db_status'  => $db->status(),
            'php_version' => PHP_VERSION,
            'phase'      => 'Fase 0 — Bootstrap',
        ]);
    });

    // Healthcheck simple en JSON (útil para monitoreo / pruebas).
    $app->get('/health', function (Request $request, Response $response) use ($container): Response {
        /** @var Database $db */
        $db = $container->get(Database::class);
        $payload = [
            'ok'   => true,
            'app'  => 'satrak',
            'db'   => $db->status(),
            'time' => date('c'),
        ];
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    });
};
