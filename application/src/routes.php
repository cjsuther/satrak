<?php
/**
 * Definición de rutas de Satrak.
 *
 * Recibe $app (Slim\App) y $container (PSR-11) por scope desde index.php.
 * Públicas: login/recupero. Autenticadas: grupo con Auth → Tenant → ViewGlobals.
 * ABM scopeado: subgrupo que además exige contexto de empresa.
 */

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Satrak\Application\Controllers\AlertController;
use Satrak\Application\Controllers\AlertRuleController;
use Satrak\Application\Controllers\AppApiController;
use Satrak\Application\Controllers\AssignmentController;
use Satrak\Application\Controllers\AuditController;
use Satrak\Application\Controllers\AuthController;
use Satrak\Application\Controllers\CompanyController;
use Satrak\Application\Controllers\ContextController;
use Satrak\Application\Controllers\DashboardController;
use Satrak\Application\Controllers\DeviceController;
use Satrak\Application\Controllers\DriverController;
use Satrak\Application\Controllers\DriverPortalController;
use Satrak\Application\Controllers\GeofenceController;
use Satrak\Application\Controllers\MapController;
use Satrak\Application\Controllers\MissionController;
use Satrak\Application\Controllers\NotificationController;
use Satrak\Application\Controllers\PersonController;
use Satrak\Application\Controllers\PersonPortalController;
use Satrak\Application\Controllers\PersonScheduleController;
use Satrak\Application\Controllers\ProfileController;
use Satrak\Application\Controllers\ReportController;
use Satrak\Application\Controllers\SuperAdminController;
use Satrak\Application\Controllers\UserController;
use Satrak\Application\Controllers\VehicleController;
use Satrak\Application\Middleware\AppAuthMiddleware;
use Satrak\Application\Middleware\AuthMiddleware;
use Satrak\Application\Middleware\RbacMiddleware;
use Satrak\Application\Middleware\RequireCompanyContextMiddleware;
use Satrak\Application\Middleware\TenantMiddleware;
use Satrak\Application\Middleware\ViewGlobalsMiddleware;
use Satrak\Application\Support\Auth;
use Satrak\Application\Support\Entitlements;
use Satrak\Application\Support\Perm;
use Satrak\Application\Support\Rbac;
use Slim\Routing\RouteCollectorProxy;

/** Fábrica de middleware RBAC por permiso (rol + módulo contratado). */
$requires = fn (string $permission): RbacMiddleware => new RbacMiddleware(
    $container->get(Auth::class),
    $container->get(Rbac::class),
    $permission,
    $container->get(Entitlements::class)
);

// --- Raíz + healthcheck (públicas) -----------------------------------------
$app->get('/', function (Request $request, Response $response) use ($container): Response {
    $to = $container->get(Auth::class)->check() ? '/dashboard' : '/login';

    return $response->withHeader('Location', $to)->withStatus(302);
});

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

// --- Autenticación (públicas) ----------------------------------------------
$app->get('/login', [AuthController::class, 'showLogin']);
$app->post('/login', [AuthController::class, 'login']);
$app->get('/forgot', [AuthController::class, 'showForgot']);
$app->post('/forgot', [AuthController::class, 'sendForgot']);
$app->get('/reset', [AuthController::class, 'showReset']);
$app->post('/reset', [AuthController::class, 'doReset']);

// --- API de la app móvil ----------------------------------------------------
// Stateless (Bearer), sin sesión web ni CSRF. El login es público; el resto pasa
// por AppAuthMiddleware, que inyecta person_id / company_id / device_id.
$app->post('/api/app/login', [AppApiController::class, 'login']);

$app->group('/api/app', function (RouteCollectorProxy $api) {
    $api->post('/logout', [AppApiController::class, 'logout']);
    $api->get('/sync', [AppApiController::class, 'sync']);
    $api->post('/positions', [AppApiController::class, 'positions']);
    $api->post('/panic', [AppApiController::class, 'panic']);
    $api->post('/events', [AppApiController::class, 'events']);
    $api->post('/heartbeat', [AppApiController::class, 'heartbeat']);
    $api->post('/missions/{id:[0-9]+}/start', [AppApiController::class, 'startMission']);
    $api->post('/missions/{id:[0-9]+}/arrive', [AppApiController::class, 'arriveMission']);
})->add($container->get(AppAuthMiddleware::class));

// --- Grupo autenticado ------------------------------------------------------
$app->group('', function (RouteCollectorProxy $group) use ($requires, $container) {
    $group->post('/logout', [AuthController::class, 'logout']);
    $group->get('/dashboard', [DashboardController::class, 'index']);

    // Notificaciones in-app del usuario (campana). Disponibles para todo usuario
    // autenticado; no requieren contexto de empresa.
    $group->get('/api/notifications/unread', [NotificationController::class, 'unread']);
    $group->post('/api/notifications/{id:[0-9]+}/read', [NotificationController::class, 'read']);
    $group->post('/api/notifications/read-all', [NotificationController::class, 'readAll']);

    // Auditoría: global para super admin (sin contexto), por empresa para el resto.
    // Fuera del grupo de contexto para que el super admin la vea en vista global.
    $group->get('/auditoria', [AuditController::class, 'index'])->add($requires(Perm::AUDIT_COMPANY));

    // Perfil de la cuenta propia (cualquier rol): nombre + cambio de contraseña.
    $group->get('/perfil', [ProfileController::class, 'show'])->add($requires(Perm::PROFILE_EDIT));
    $group->post('/perfil', [ProfileController::class, 'update'])->add($requires(Perm::PROFILE_EDIT));

    // Context switch del super admin.
    $group->post('/context/{id:[0-9]+}', [ContextController::class, 'enter'])->add($requires(Perm::CONTEXT_SWITCH));
    $group->post('/context/exit', [ContextController::class, 'exit'])->add($requires(Perm::CONTEXT_SWITCH));

    // Empresas (super admin, vista global).
    $group->group('/empresas', function (RouteCollectorProxy $g) {
        $g->get('', [CompanyController::class, 'index']);
        $g->get('/nueva', [CompanyController::class, 'createForm']);
        $g->post('', [CompanyController::class, 'store']);
        $g->get('/{id:[0-9]+}/editar', [CompanyController::class, 'editForm']);
        $g->post('/{id:[0-9]+}', [CompanyController::class, 'update']);
        $g->post('/{id:[0-9]+}/estado', [CompanyController::class, 'toggleStatus']);
    })->add($requires(Perm::COMPANIES_MANAGE));

    // Super Admins (usuarios globales de Satrak). Sólo super admin.
    $group->group('/super-admins', function (RouteCollectorProxy $g) {
        $g->get('', [SuperAdminController::class, 'index']);
        $g->get('/nuevo', [SuperAdminController::class, 'createForm']);
        $g->post('', [SuperAdminController::class, 'store']);
        $g->post('/{id:[0-9]+}/estado', [SuperAdminController::class, 'toggleStatus']);
    })->add($requires(Perm::COMPANIES_MANAGE));

    // --- ABM scopeado a empresa (requiere contexto) -------------------------
    $group->group('', function (RouteCollectorProxy $g) use ($requires) {
        // Monitoreo: mapa en vivo, historial y endpoints JSON (monitoring.view).
        $g->group('', function (RouteCollectorProxy $m) {
            $m->get('/mapa', [MapController::class, 'live']);
            $m->get('/historial', [MapController::class, 'history']);
            $m->get('/api/live/positions', [MapController::class, 'livePositions']);
            $m->get('/api/devices/{id:[0-9]+}/track', [MapController::class, 'track']);
        })->add($requires(Perm::MONITORING_VIEW));

        // Alertas: pantalla + ACK + endpoints JSON (alerts.ack).
        $g->group('', function (RouteCollectorProxy $a) {
            $a->get('/alertas', [AlertController::class, 'index']);
            $a->post('/alertas/{id:[0-9]+}/ack', [AlertController::class, 'ack']);
            $a->get('/api/alerts/recent', [AlertController::class, 'recent']);
            $a->post('/api/alerts/{id:[0-9]+}/ack', [AlertController::class, 'ackJson']);
        })->add($requires(Perm::ALERTS_ACK));

        // Geocercas y reglas de alerta (geofences.manage).
        $g->group('', function (RouteCollectorProxy $gf) {
            $gf->get('/geocercas', [GeofenceController::class, 'index']);
            $gf->get('/geocercas/nueva', [GeofenceController::class, 'createForm']);
            $gf->post('/geocercas', [GeofenceController::class, 'store']);
            $gf->get('/geocercas/{id:[0-9]+}/editar', [GeofenceController::class, 'editForm']);
            $gf->post('/geocercas/{id:[0-9]+}', [GeofenceController::class, 'update']);
            $gf->post('/geocercas/{id:[0-9]+}/eliminar', [GeofenceController::class, 'delete']);

            $gf->get('/reglas-alerta', [AlertRuleController::class, 'index']);
            $gf->get('/reglas-alerta/nueva', [AlertRuleController::class, 'createForm']);
            $gf->post('/reglas-alerta', [AlertRuleController::class, 'store']);
            $gf->get('/reglas-alerta/{id:[0-9]+}/editar', [AlertRuleController::class, 'editForm']);
            $gf->post('/reglas-alerta/{id:[0-9]+}', [AlertRuleController::class, 'update']);
            $gf->post('/reglas-alerta/{id:[0-9]+}/eliminar', [AlertRuleController::class, 'delete']);
        })->add($requires(Perm::GEOFENCES_MANAGE));

        // Reportes: por vehículo / conductor / alertas + export CSV (reports.view).
        $g->group('', function (RouteCollectorProxy $rp) {
            $rp->get('/reportes', [ReportController::class, 'index']);
            $rp->get('/reportes/export', [ReportController::class, 'export']);
        })->add($requires(Perm::REPORTS_VIEW));

        // Portal del conductor: scope estricto al driver_id del usuario (driver.portal).
        $g->group('/portal', function (RouteCollectorProxy $p) {
            $p->get('', [DriverPortalController::class, 'activity']);
            $p->get('/actividad', [DriverPortalController::class, 'activity']);
            $p->get('/viajes/{id:[0-9]+}', [DriverPortalController::class, 'trip']);
            $p->get('/viajes/{id:[0-9]+}/track', [DriverPortalController::class, 'tripTrack']);
            $p->get('/ubicacion', [DriverPortalController::class, 'lastPosition']);
            $p->get('/perfil', [DriverPortalController::class, 'profile']);
            $p->post('/perfil', [DriverPortalController::class, 'updateProfile']);
        })->add($requires(Perm::DRIVER_PORTAL));

        // Portal de la persona: scope estricto al person_id del usuario.
        $g->group('/mi', function (RouteCollectorProxy $mi) {
            $mi->get('', [PersonPortalController::class, 'today']);
            $mi->get('/ubicacion', [PersonPortalController::class, 'location']);
            $mi->get('/misiones', [PersonPortalController::class, 'missions']);
            $mi->get('/perfil', [PersonPortalController::class, 'profile']);
            $mi->post('/perfil', [PersonPortalController::class, 'updateProfile']);
        })->add($requires(Perm::PERSON_PORTAL));

        // Usuarios.
        $g->group('/usuarios', function (RouteCollectorProxy $u) {
            $u->get('', [UserController::class, 'index']);
            $u->get('/nuevo', [UserController::class, 'createForm']);
            $u->post('', [UserController::class, 'store']);
            $u->get('/{id:[0-9]+}', [UserController::class, 'show']);
            $u->get('/{id:[0-9]+}/editar', [UserController::class, 'editForm']);
            $u->post('/{id:[0-9]+}', [UserController::class, 'update']);
            $u->post('/{id:[0-9]+}/estado', [UserController::class, 'toggleStatus']);
        })->add($requires(Perm::USERS_MANAGE));

        // Personas: el maestro del módulo de personal (incluye el perfil de
        // conducción de cada persona). Módulo 'people'.
        $g->group('/personas', function (RouteCollectorProxy $pe) {
            $pe->get('', [PersonController::class, 'index']);
            $pe->get('/nueva', [PersonController::class, 'createForm']);
            $pe->post('', [PersonController::class, 'store']);
            $pe->get('/{id:[0-9]+}/editar', [PersonController::class, 'editForm']);
            $pe->post('/{id:[0-9]+}', [PersonController::class, 'update']);
            $pe->post('/{id:[0-9]+}/estado', [PersonController::class, 'toggleStatus']);

            // Jornada, excepciones, puesto y sesión de la app.
            $pe->get('/{id:[0-9]+}/jornada', [PersonScheduleController::class, 'show']);
            $pe->post('/{id:[0-9]+}/jornada', [PersonScheduleController::class, 'saveShifts']);
            $pe->post('/{id:[0-9]+}/jornada/excepciones', [PersonScheduleController::class, 'addException']);
            $pe->post('/{id:[0-9]+}/jornada/excepciones/{exc:[0-9]+}/eliminar', [PersonScheduleController::class, 'deleteException']);
            $pe->post('/{id:[0-9]+}/jornada/puesto', [PersonScheduleController::class, 'savePost']);
            $pe->post('/{id:[0-9]+}/jornada/sesion/revocar', [PersonScheduleController::class, 'revokeSession']);
        })->add($requires(Perm::PEOPLE_MANAGE));

        // Misiones: las carga el operador; la persona sólo inicia y marca llegada.
        $g->group('/misiones', function (RouteCollectorProxy $ms) {
            $ms->get('', [MissionController::class, 'index']);
            $ms->get('/nueva', [MissionController::class, 'createForm']);
            $ms->post('', [MissionController::class, 'store']);
            $ms->get('/{id:[0-9]+}/editar', [MissionController::class, 'editForm']);
            $ms->post('/{id:[0-9]+}', [MissionController::class, 'update']);
            $ms->post('/{id:[0-9]+}/estado', [MissionController::class, 'setStatus']);
        })->add($requires(Perm::MISSIONS_MANAGE));

        // Conductores: listado del perfil de conducción. El alta y la edición
        // viven en el formulario de la persona (la persona es el maestro).
        $g->group('/conductores', function (RouteCollectorProxy $c) {
            $c->get('', [DriverController::class, 'index']);
            $c->get('/nuevo', [DriverController::class, 'createForm']);
            $c->get('/{id:[0-9]+}/editar', [DriverController::class, 'editForm']);
            $c->post('/{id:[0-9]+}/estado', [DriverController::class, 'toggleStatus']);
        })->add($requires(Perm::FLEET_MANAGE));

        $g->group('/vehiculos', function (RouteCollectorProxy $v) {
            $v->get('', [VehicleController::class, 'index']);
            $v->get('/nuevo', [VehicleController::class, 'createForm']);
            $v->post('', [VehicleController::class, 'store']);
            $v->get('/{id:[0-9]+}/editar', [VehicleController::class, 'editForm']);
            $v->post('/{id:[0-9]+}', [VehicleController::class, 'update']);
            $v->post('/{id:[0-9]+}/estado', [VehicleController::class, 'toggleStatus']);
        })->add($requires(Perm::FLEET_MANAGE));

        $g->group('/dispositivos', function (RouteCollectorProxy $dv) use ($requires) {
            $dv->get('', [DeviceController::class, 'index']);
            $dv->get('/nuevo', [DeviceController::class, 'createForm']);
            $dv->post('', [DeviceController::class, 'store']);
            $dv->get('/{id:[0-9]+}/editar', [DeviceController::class, 'editForm']);
            $dv->post('/{id:[0-9]+}', [DeviceController::class, 'update']);
            $dv->post('/{id:[0-9]+}/estado', [DeviceController::class, 'toggleStatus']);

            // Asignaciones del dispositivo (FLEET + ASSIGNMENTS).
            $dv->get('/{id:[0-9]+}/asignaciones', [AssignmentController::class, 'manage'])->add($requires(Perm::ASSIGNMENTS_MANAGE));
            $dv->post('/{id:[0-9]+}/asignar-vehiculo', [AssignmentController::class, 'assignVehicle'])->add($requires(Perm::ASSIGNMENTS_MANAGE));
            $dv->post('/{id:[0-9]+}/desasignar-vehiculo', [AssignmentController::class, 'unassignVehicle'])->add($requires(Perm::ASSIGNMENTS_MANAGE));
            $dv->post('/{id:[0-9]+}/conductores', [AssignmentController::class, 'linkDriver'])->add($requires(Perm::ASSIGNMENTS_MANAGE));
            $dv->post('/{id:[0-9]+}/conductores/{link:[0-9]+}/quitar', [AssignmentController::class, 'unlinkDriver'])->add($requires(Perm::ASSIGNMENTS_MANAGE));
        })->add($requires(Perm::FLEET_MANAGE));
    })->add($container->get(RequireCompanyContextMiddleware::class));
})
    ->add($container->get(ViewGlobalsMiddleware::class))
    ->add($container->get(TenantMiddleware::class))
    ->add($container->get(AuthMiddleware::class));
