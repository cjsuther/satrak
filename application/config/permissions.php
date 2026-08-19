<?php
/**
 * Satrak — Matriz RBAC (spec §5).
 *
 * Mapa rol → permisos. Versionado (no es secreto) y editable.
 * Las constantes de permiso viven en Satrak\Application\Support\Perm.
 *
 * super_admin: '*' = todos los permisos (opera en contexto de una empresa para
 * las capacidades a nivel empresa; ver TenantMiddleware).
 */

use Satrak\Application\Support\Perm;

return [
    'super_admin' => ['*'],

    'company_admin' => [
        Perm::USERS_MANAGE,
        Perm::FLEET_MANAGE,
        Perm::ASSIGNMENTS_MANAGE,
        Perm::MONITORING_VIEW,
        Perm::GEOFENCES_MANAGE,
        Perm::ALERTS_ACK,
        Perm::REPORTS_VIEW,
        Perm::PROFILE_EDIT,
        Perm::AUDIT_COMPANY,
        Perm::PEOPLE_MANAGE,
        Perm::PEOPLE_MONITOR,
        Perm::MISSIONS_MANAGE,
    ],

    'operator' => [
        Perm::MONITORING_VIEW,
        Perm::GEOFENCES_MANAGE,
        Perm::ALERTS_ACK,
        Perm::REPORTS_VIEW,
        Perm::PROFILE_EDIT,
        Perm::PEOPLE_MONITOR,
        Perm::MISSIONS_MANAGE,
    ],

    'driver' => [
        Perm::DRIVER_PORTAL,
        Perm::PROFILE_EDIT,
    ],

    'person' => [
        Perm::PERSON_PORTAL,
        Perm::PROFILE_EDIT,
    ],
];
