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
use Satrak\Domain\Repositories\VehicleRepository;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * ABM de vehículos. Patente única por empresa. Scopeado y auditado.
 */
final class VehicleController
{
    private const TYPES = ['auto', 'moto', 'camion', 'utilitario', 'otro'];

    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private Flash $flash,
        private AuditRepository $audit,
        private VehicleRepository $vehicles
    ) {
    }

    private function redirect(Response $r, string $to): Response
    {
        return $r->withHeader('Location', $to)->withStatus(302);
    }

    public function index(Request $request, Response $response): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $listing = Listing::fromRequest($request, 'plate');

        return $this->twig->render($response, 'pages/vehicles/index.twig', [
            'page' => $this->vehicles->listPaginated($companyId, $listing),
            'q'    => $listing->search,
        ]);
    }

    public function createForm(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'pages/vehicles/form.twig', [
            'mode' => 'create', 'v' => ['type' => 'auto', 'status' => 'active'], 'types' => self::TYPES,
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $d = (array) $request->getParsedBody();

        $errors = $this->validate($d, $companyId, null);
        if ($errors !== []) {
            return $this->renderForm($response->withStatus(422), 'create', $d, $errors);
        }

        $id = $this->vehicles->create($companyId, $this->normalize($d));
        $this->audit->log($companyId, $this->auth->id(), 'vehicle.create', 'vehicle', $id,
            ['plate' => mb_strtoupper(trim((string) $d['plate']))], client_ip());
        $this->flash->success('Vehículo creado.');

        return $this->redirect($response, '/vehiculos');
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $vehicle = $this->vehicles->findScoped((int) $args['id'], $companyId);
        if ($vehicle === null) {
            throw new HttpNotFoundException($request);
        }

        return $this->renderForm($response, 'edit', $vehicle, []);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $vehicle = $this->vehicles->findScoped((int) $args['id'], $companyId);
        if ($vehicle === null) {
            throw new HttpNotFoundException($request);
        }

        $d = (array) $request->getParsedBody();
        $errors = $this->validate($d, $companyId, (int) $vehicle['id']);
        if ($errors !== []) {
            return $this->renderForm($response->withStatus(422), 'edit', array_merge($vehicle, $d), $errors);
        }

        $data = $this->normalize($d);
        $data['status'] = ($d['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        $this->vehicles->update((int) $vehicle['id'], $data);

        $this->audit->log($companyId, $this->auth->id(), 'vehicle.update', 'vehicle', (int) $vehicle['id'], null, client_ip());
        $this->flash->success('Vehículo actualizado.');

        return $this->redirect($response, '/vehiculos');
    }

    public function toggleStatus(Request $request, Response $response, array $args): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $vehicle = $this->vehicles->findScoped((int) $args['id'], $companyId);
        if ($vehicle === null) {
            throw new HttpNotFoundException($request);
        }

        $new = $vehicle['status'] === 'active' ? 'inactive' : 'active';
        $data = $this->normalize($vehicle);
        $data['status'] = $new;
        $this->vehicles->update((int) $vehicle['id'], $data);

        $this->audit->log($companyId, $this->auth->id(), 'vehicle.status', 'vehicle', (int) $vehicle['id'],
            ['to' => $new], client_ip());
        $this->flash->success('Vehículo ' . ($new === 'active' ? 'activado' : 'desactivado') . '.');

        return $this->redirect($response, '/vehiculos');
    }

    // --- Helpers ---------------------------------------------------------------

    /** @param array<string,mixed> $d @return array<string,mixed> */
    private function normalize(array $d): array
    {
        return [
            'plate'  => trim((string) $d['plate']),
            'brand'  => trim((string) ($d['brand'] ?? '')),
            'model'  => trim((string) ($d['model'] ?? '')),
            'year'   => (int) ($d['year'] ?? 0) ?: null,
            'type'   => in_array($d['type'] ?? '', self::TYPES, true) ? $d['type'] : 'auto',
            'color'  => trim((string) ($d['color'] ?? '')),
            'status' => $d['status'] ?? 'active',
        ];
    }

    /**
     * @param array<string,mixed> $d
     * @return array<string,string>
     */
    private function validate(array $d, int $companyId, ?int $exceptId): array
    {
        $v = new Validator($d);
        $v->required('plate', 'La patente')->maxLength('plate', 15, 'La patente');
        $errors = $v->errors();

        $plate = trim((string) ($d['plate'] ?? ''));
        if ($plate !== '' && !isset($errors['plate']) && $this->vehicles->plateTaken($plate, $companyId, $exceptId)) {
            $errors['plate'] = 'Esa patente ya está registrada en la empresa.';
        }
        if (!in_array($d['type'] ?? '', self::TYPES, true)) {
            $errors['type'] = 'Tipo inválido.';
        }
        $year = (int) ($d['year'] ?? 0);
        if ($year !== 0 && ($year < 1950 || $year > (int) date('Y') + 1)) {
            $errors['year'] = 'Año inválido.';
        }

        return $errors;
    }

    /**
     * @param array<string,mixed> $v
     * @param array<string,string> $errors
     */
    private function renderForm(Response $response, string $mode, array $v, array $errors): Response
    {
        if ($errors !== []) {
            $this->flash->error('Revisá los datos del formulario.');
        }

        return $this->twig->render($response, 'pages/vehicles/form.twig', [
            'mode' => $mode, 'v' => $v, 'errors' => $errors, 'types' => self::TYPES,
        ]);
    }
}
