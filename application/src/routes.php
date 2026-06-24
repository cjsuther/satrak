<?php
/**
 * Definición de rutas de Satrak.
 *
 * Recibe $app (Slim\App) y $container (PSR-11) por scope desde index.php.
 * Las rutas públicas (login/recupero) van sueltas; el resto pasa por el grupo
 * autenticado con Auth → Tenant → ViewGlobals.
 */

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Satrak\Application\Controllers\AuthController;
use Satrak\Application\Controllers\ContextController;
use Satrak\Application\Controllers\DashboardController;
use Satrak\Application\Controllers\UserController;
use Satrak\Application\Middleware\AuthMiddleware;
use Satrak\Application\Middleware\RbacMiddleware;
use Satrak\Application\Middleware\TenantMiddleware;
use Satrak\Application\Middleware\ViewGlobalsMiddleware;
use Satrak\Application\Support\Auth;
use Satrak\Application\Support\Perm;
use Satrak\Application\Support\Rbac;
use Slim\Routing\RouteCollectorProxy;

/** Fábrica de middleware RBAC por permiso. */
$requires = fn (string $permission): RbacMiddleware => new RbacMiddleware(
    $container->get(Auth::class),
    $container->get(Rbac::class),
    $permission
);

// --- Raíz: redirige según estado de sesión ---------------------------------
$app->get('/', function (Request $request, Response $response) use ($container): Response {
    $to = $container->get(Auth::class)->check() ? '/dashboard' : '/login';

    return $response->withHeader('Location', $to)->withStatus(302);
});

// --- Healthcheck (público) -------------------------------------------------
$app->get('/health', function (Request $request, Response $response) use ($container): Response {
    $ok = true;
    try {
        $container->get(PDO::class)->query('SELECT 1');
    } catch (\Throwable $e) {
        $ok = false;
    }
    $response->getBody()->write((string) json_encode(['ok' => $ok, 'service' => 'satrak', 'db' => $ok]));

    return $response->withHeader('Content-Type', 'application/json')->withStatus($ok ? 200 : 503);
});

// --- Rutas públicas de autenticación ---------------------------------------
$app->get('/login', [AuthController::class, 'showLogin']);
$app->post('/login', [AuthController::class, 'login']);
$app->get('/forgot', [AuthController::class, 'showForgot']);
$app->post('/forgot', [AuthController::class, 'sendForgot']);
$app->get('/reset', [AuthController::class, 'showReset']);
$app->post('/reset', [AuthController::class, 'doReset']);

// --- Grupo autenticado ------------------------------------------------------
$app->group('', function (RouteCollectorProxy $group) use ($requires) {
    $group->post('/logout', [AuthController::class, 'logout']);

    $group->get('/dashboard', [DashboardController::class, 'index']);

    // Context switch del super admin (mutaciones ⇒ POST + CSRF).
    $group->post('/context/{id:[0-9]+}', [ContextController::class, 'enter'])
        ->add($requires(Perm::CONTEXT_SWITCH));
    $group->post('/context/exit', [ContextController::class, 'exit'])
        ->add($requires(Perm::CONTEXT_SWITCH));

    // Usuarios (read-only en Fase 2; demuestra scope + 404 cross-empresa).
    $group->get('/usuarios', [UserController::class, 'index'])
        ->add($requires(Perm::USERS_MANAGE));
    $group->get('/usuarios/{id:[0-9]+}', [UserController::class, 'show'])
        ->add($requires(Perm::USERS_MANAGE));
})
    ->add($container->get(ViewGlobalsMiddleware::class))   // 3º: globals de vista (necesita usuario)
    ->add($container->get(TenantMiddleware::class))        // 2º: company_id efectivo
    ->add($container->get(AuthMiddleware::class));          // 1º: exige sesión (outermost)
