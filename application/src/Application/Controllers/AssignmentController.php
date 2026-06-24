<?php

declare(strict_types=1);

namespace Satrak\Application\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Satrak\Application\Support\Auth;
use Satrak\Application\Support\Flash;
use Satrak\Domain\Repositories\AssignmentRepository;
use Satrak\Domain\Repositories\AuditRepository;
use Satrak\Domain\Repositories\DeviceDriverLinkRepository;
use Satrak\Domain\Repositories\DeviceRepository;
use Satrak\Domain\Repositories\DriverRepository;
use Satrak\Domain\Repositories\VehicleRepository;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * Asignaciones centradas en el dispositivo:
 *  - device ↔ vehículo: 1:1 activa con historial (reasignar cierra la previa).
 *  - device ↔ conductores: para equipos CON PIN, allowlist; para equipos SIN PIN,
 *    un conductor por defecto.
 * Todo scopeado por company_id y auditado.
 */
final class AssignmentController
{
    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private Flash $flash,
        private AuditRepository $audit,
        private DeviceRepository $devices,
        private VehicleRepository $vehicles,
        private DriverRepository $drivers,
        private AssignmentRepository $assignments,
        private DeviceDriverLinkRepository $links
    ) {
    }

    private function redirect(Response $r, string $to): Response
    {
        return $r->withHeader('Location', $to)->withStatus(302);
    }

    /** Carga el dispositivo desde el arg de ruta, validando scope. */
    private function loadDevice(Request $request, array $args): array
    {
        $companyId = (int) $request->getAttribute('company_id');
        $device = $this->devices->findScoped((int) $args['id'], $companyId);
        if ($device === null) {
            throw new HttpNotFoundException($request);
        }

        return [$companyId, $device];
    }

    public function manage(Request $request, Response $response, array $args): Response
    {
        [$companyId, $device] = $this->loadDevice($request, $args);

        return $this->twig->render($response, 'pages/devices/assignments.twig', [
            'dev'            => $device,
            'vehicle_active' => $this->assignments->activeForDevice((int) $device['id'], $companyId),
            'history'        => $this->assignments->historyForDevice((int) $device['id'], $companyId),
            'links'          => $this->links->activeLinks((int) $device['id'], $companyId),
            'vehicles'       => $this->vehicles->activeForCompany($companyId),
            'drivers'        => $this->drivers->activeForCompany($companyId),
        ]);
    }

    // --- Device ↔ vehículo ------------------------------------------------------

    public function assignVehicle(Request $request, Response $response, array $args): Response
    {
        [$companyId, $device] = $this->loadDevice($request, $args);
        $vehicleId = (int) (((array) $request->getParsedBody())['vehicle_id'] ?? 0);

        $vehicle = $this->vehicles->findScoped($vehicleId, $companyId);
        if ($vehicle === null || $vehicle['status'] !== 'active') {
            $this->flash->error('Vehículo inválido.');

            return $this->redirect($response, "/dispositivos/{$device['id']}/asignaciones");
        }

        $this->assignments->assign($companyId, (int) $device['id'], $vehicleId);
        $this->audit->log($companyId, $this->auth->id(), 'assignment.vehicle', 'device', (int) $device['id'],
            ['vehicle_id' => $vehicleId, 'plate' => $vehicle['plate']], client_ip());
        $this->flash->success('Dispositivo asignado a ' . $vehicle['plate'] . '.');

        return $this->redirect($response, "/dispositivos/{$device['id']}/asignaciones");
    }

    public function unassignVehicle(Request $request, Response $response, array $args): Response
    {
        [$companyId, $device] = $this->loadDevice($request, $args);

        $this->assignments->unassignDevice($companyId, (int) $device['id']);
        $this->audit->log($companyId, $this->auth->id(), 'assignment.vehicle_off', 'device', (int) $device['id'], null, client_ip());
        $this->flash->success('Asignación de vehículo liberada.');

        return $this->redirect($response, "/dispositivos/{$device['id']}/asignaciones");
    }

    // --- Device ↔ conductores ---------------------------------------------------

    public function linkDriver(Request $request, Response $response, array $args): Response
    {
        [$companyId, $device] = $this->loadDevice($request, $args);
        $driverId = (int) (((array) $request->getParsedBody())['driver_id'] ?? 0);

        $driver = $this->drivers->findScoped($driverId, $companyId);
        if ($driver === null || $driver['status'] !== 'active') {
            $this->flash->error('Conductor inválido.');

            return $this->redirect($response, "/dispositivos/{$device['id']}/asignaciones");
        }

        if ((int) $device['has_pin'] === 1) {
            // Allowlist: evitar duplicados activos.
            if ($this->links->hasActiveLink((int) $device['id'], $driverId, $companyId)) {
                $this->flash->info('Ese conductor ya está en la lista.');

                return $this->redirect($response, "/dispositivos/{$device['id']}/asignaciones");
            }
            $this->links->addToAllowlist($companyId, (int) $device['id'], $driverId);
            $action = 'assignment.allowlist_add';
            $msg = 'Conductor agregado a la allowlist.';
        } else {
            // Sin PIN: conductor por defecto (reemplaza el anterior).
            $this->links->setDefault($companyId, (int) $device['id'], $driverId);
            $action = 'assignment.default_driver';
            $msg = 'Conductor por defecto actualizado.';
        }

        $this->audit->log($companyId, $this->auth->id(), $action, 'device', (int) $device['id'],
            ['driver_id' => $driverId], client_ip());
        $this->flash->success($msg);

        return $this->redirect($response, "/dispositivos/{$device['id']}/asignaciones");
    }

    public function unlinkDriver(Request $request, Response $response, array $args): Response
    {
        [$companyId, $device] = $this->loadDevice($request, $args);
        $linkId = (int) $args['link'];

        $this->links->unlink($linkId, $companyId);
        $this->audit->log($companyId, $this->auth->id(), 'assignment.driver_unlink', 'device', (int) $device['id'],
            ['link_id' => $linkId], client_ip());
        $this->flash->success('Vínculo de conductor quitado.');

        return $this->redirect($response, "/dispositivos/{$device['id']}/asignaciones");
    }
}
