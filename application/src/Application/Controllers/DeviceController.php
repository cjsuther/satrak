<?php

declare(strict_types=1);

namespace Satrak\Application\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Satrak\Application\Support\Auth;
use Satrak\Application\Support\Flash;
use Satrak\Application\Support\Listing;
use Satrak\Application\Support\Validator;
use Satrak\Domain\Repositories\AuditRepository;
use Satrak\Domain\Repositories\CompanyRepository;
use Satrak\Domain\Repositories\DeviceRepository;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * ABM de dispositivos. IMEI único global; el alta valida el cupo de la empresa
 * (`device_quota`) contra los dispositivos activos. Flag has_pin. Auditado.
 */
final class DeviceController
{
    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private Flash $flash,
        private AuditRepository $audit,
        private DeviceRepository $devices,
        private CompanyRepository $companies
    ) {
    }

    private function redirect(Response $r, string $to): Response
    {
        return $r->withHeader('Location', $to)->withStatus(302);
    }

    public function index(Request $request, Response $response): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $listing = Listing::fromRequest($request, 'label');
        $company = $this->companies->find($companyId);

        return $this->twig->render($response, 'pages/devices/index.twig', [
            'page'         => $this->devices->listPaginated($companyId, $listing),
            'q'            => $listing->search,
            'quota'        => (int) ($company['device_quota'] ?? 0),
            'active_count' => $this->devices->countActive($companyId),
        ]);
    }

    public function createForm(Request $request, Response $response): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $company = $this->companies->find($companyId);
        $active = $this->devices->countActive($companyId);
        $quota = (int) ($company['device_quota'] ?? 0);

        if ($active >= $quota) {
            $this->flash->error("Cupo de dispositivos completo ({$active}/{$quota}). Ampliá el cupo de la empresa.");

            return $this->redirect($response, '/dispositivos');
        }

        return $this->twig->render($response, 'pages/devices/form.twig', [
            'mode' => 'create', 'dev' => ['status' => 'active', 'has_pin' => 0],
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $company = $this->companies->find($companyId);
        $d = (array) $request->getParsedBody();

        // Validación de cupo (server-side, no confiar en el front).
        $active = $this->devices->countActive($companyId);
        $quota = (int) ($company['device_quota'] ?? 0);
        if ($active >= $quota) {
            $this->flash->error("Cupo de dispositivos completo ({$active}/{$quota}).");

            return $this->redirect($response, '/dispositivos');
        }

        $errors = $this->validate($d, null);
        if ($errors !== []) {
            return $this->renderForm($response->withStatus(422), 'create', $d, $errors);
        }

        $id = $this->devices->create($companyId, $this->normalize($d));
        $this->audit->log($companyId, $this->auth->id(), 'device.create', 'device', $id,
            ['imei' => trim((string) $d['imei']), 'has_pin' => !empty($d['has_pin'])], client_ip());
        $this->flash->success('Dispositivo creado.');

        return $this->redirect($response, '/dispositivos');
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $device = $this->devices->findScoped((int) $args['id'], $companyId);
        if ($device === null) {
            throw new HttpNotFoundException($request);
        }

        return $this->renderForm($response, 'edit', $device, []);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $device = $this->devices->findScoped((int) $args['id'], $companyId);
        if ($device === null) {
            throw new HttpNotFoundException($request);
        }

        $d = (array) $request->getParsedBody();
        $errors = $this->validate($d, (int) $device['id']);
        if ($errors !== []) {
            return $this->renderForm($response->withStatus(422), 'edit', array_merge($device, $d), $errors);
        }

        $data = $this->normalize($d);
        $data['status'] = ($d['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        $this->devices->update((int) $device['id'], $data);

        $this->audit->log($companyId, $this->auth->id(), 'device.update', 'device', (int) $device['id'], null, client_ip());
        $this->flash->success('Dispositivo actualizado.');

        return $this->redirect($response, '/dispositivos');
    }

    public function toggleStatus(Request $request, Response $response, array $args): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $device = $this->devices->findScoped((int) $args['id'], $companyId);
        if ($device === null) {
            throw new HttpNotFoundException($request);
        }

        $new = $device['status'] === 'active' ? 'inactive' : 'active';
        // Reactivar respeta el cupo.
        if ($new === 'active') {
            $company = $this->companies->find($companyId);
            $active = $this->devices->countActive($companyId);
            if ($active >= (int) ($company['device_quota'] ?? 0)) {
                $this->flash->error('No se puede reactivar: cupo completo.');

                return $this->redirect($response, '/dispositivos');
            }
        }

        $data = $this->normalize($device);
        $data['status'] = $new;
        $this->devices->update((int) $device['id'], $data);

        $this->audit->log($companyId, $this->auth->id(), 'device.status', 'device', (int) $device['id'],
            ['to' => $new], client_ip());
        $this->flash->success('Dispositivo ' . ($new === 'active' ? 'activado' : 'desactivado') . '.');

        return $this->redirect($response, '/dispositivos');
    }

    // --- Helpers ---------------------------------------------------------------

    /** @param array<string,mixed> $d @return array<string,mixed> */
    private function normalize(array $d): array
    {
        return [
            'imei'      => trim((string) $d['imei']),
            'label'     => trim((string) ($d['label'] ?? '')),
            'model'     => trim((string) ($d['model'] ?? '')),
            'protocol'  => trim((string) ($d['protocol'] ?? '')),
            'sim_iccid' => trim((string) ($d['sim_iccid'] ?? '')),
            'has_pin'   => !empty($d['has_pin']) ? 1 : 0,
            'status'    => $d['status'] ?? 'active',
        ];
    }

    /**
     * @param array<string,mixed> $d
     * @return array<string,string>
     */
    private function validate(array $d, ?int $exceptId): array
    {
        $v = new Validator($d);
        $v->required('imei', 'El IMEI')->maxLength('imei', 20, 'El IMEI');
        $errors = $v->errors();

        $imei = trim((string) ($d['imei'] ?? ''));
        if ($imei !== '' && !preg_match('/^[0-9]{6,20}$/', $imei)) {
            $errors['imei'] = 'El IMEI debe ser numérico (6 a 20 dígitos).';
        } elseif ($imei !== '' && !isset($errors['imei']) && $this->devices->imeiTaken($imei, $exceptId)) {
            $errors['imei'] = 'Ese IMEI ya está registrado.';
        }

        return $errors;
    }

    /**
     * @param array<string,mixed> $dev
     * @param array<string,string> $errors
     */
    private function renderForm(Response $response, string $mode, array $dev, array $errors): Response
    {
        if ($errors !== []) {
            $this->flash->error('Revisá los datos del formulario.');
        }

        return $this->twig->render($response, 'pages/devices/form.twig', [
            'mode' => $mode, 'dev' => $dev, 'errors' => $errors,
        ]);
    }
}
