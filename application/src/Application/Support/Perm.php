<?php

declare(strict_types=1);

namespace Satrak\Application\Support;

/**
 * Constantes de permiso (capacidades de la matriz §5).
 *
 * Se usan como claves estables en config/permissions.php y en las rutas
 * (RbacMiddleware). No confundir con roles: un rol agrupa varios permisos.
 */
final class Perm
{
    // Super Admin (Satrak)
    public const COMPANIES_MANAGE      = 'companies.manage';        // ABM empresas + cupo
    public const COMPANY_ADMINS_MANAGE = 'company_admins.manage';   // crear/editar admins de empresa
    public const CONTEXT_SWITCH        = 'context.switch';          // operar en contexto de una empresa
    public const AUDIT_GLOBAL          = 'audit.global';

    // Admin de Empresa / Operador
    public const USERS_MANAGE       = 'users.manage';        // ABM operadores/conductores
    public const FLEET_MANAGE       = 'fleet.manage';        // ABM vehículos/dispositivos/conductores
    public const ASSIGNMENTS_MANAGE = 'assignments.manage';  // asignaciones device↔vehículo y PINs
    public const MONITORING_VIEW    = 'monitoring.view';     // mapa en vivo / historial
    public const GEOFENCES_MANAGE   = 'geofences.manage';    // crear/editar geocercas y reglas
    public const ALERTS_ACK         = 'alerts.ack';          // reconocer alertas
    public const REPORTS_VIEW       = 'reports.view';        // ver reportes
    public const AUDIT_COMPANY      = 'audit.company';

    // Común
    public const PROFILE_EDIT  = 'profile.edit';   // ver/editar su propio perfil
    public const DRIVER_PORTAL = 'driver.portal';  // portal del conductor (solo lo propio)

    private function __construct()
    {
    }
}
