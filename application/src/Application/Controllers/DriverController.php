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
use Satrak\Domain\Repositories\DriverRepository;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * ABM de conductores. PIN opcional, 4–10 caracteres alfanuméricos, único por
 * empresa (decisión §2). Scopeado por company_id y auditado.
 */
final class DriverController
{
    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private Flash $flash,
        private AuditRepository $audit,
        private DriverRepository $drivers,
        private int $pinMin = 4,
        private int $pinMax = 10
    ) {
    }

    private function redirect(Response $r, string $to): Response
    {
        return $r->withHeader('Location', $to)->withStatus(302);
    }

    public function index(Request $request, Response $response): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $listing = Listing::fromRequest($request, 'name');

        return $this->twig->render($response, 'pages/drivers/index.twig', [
            'page' => $this->drivers->listPaginated($companyId, $listing),
            'q'    => $listing->search,
        ]);
    }

    public function createForm(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'pages/drivers/form.twig', [
            'mode' => 'create', 'd' => ['status' => 'active'], 'pin_min' => $this->pinMin, 'pin_max' => $this->pinMax,
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

        $id = $this->drivers->create($companyId, $this->normalize($d));
        $this->audit->log($companyId, $this->auth->id(), 'driver.create', 'driver', $id,
            ['name' => trim($d['first_name'] . ' ' . $d['last_name']), 'has_pin' => trim((string) ($d['pin'] ?? '')) !== ''], client_ip());
        $this->flash->success('Conductor creado.');

        return $this->redirect($response, '/conductores');
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $driver = $this->drivers->findScoped((int) $args['id'], $companyId);
        if ($driver === null) {
            throw new HttpNotFoundException($request);
        }

        return $this->renderForm($response, 'edit', $driver, []);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $driver = $this->drivers->findScoped((int) $args['id'], $companyId);
        if ($driver === null) {
            throw new HttpNotFoundException($request);
        }

        $d = (array) $request->getParsedBody();
        $errors = $this->validate($d, $companyId, (int) $driver['id']);
        if ($errors !== []) {
            return $this->renderForm($response->withStatus(422), 'edit', array_merge($driver, $d), $errors);
        }

        $data = $this->normalize($d);
        $data['status'] = ($d['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        $this->drivers->update((int) $driver['id'], $data);

        $this->audit->log($companyId, $this->auth->id(), 'driver.update', 'driver', (int) $driver['id'], null, client_ip());
        $this->flash->success('Conductor actualizado.');

        return $this->redirect($response, '/conductores');
    }

    public function toggleStatus(Request $request, Response $response, array $args): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $driver = $this->drivers->findScoped((int) $args['id'], $companyId);
        if ($driver === null) {
            throw new HttpNotFoundException($request);
        }

        $new = $driver['status'] === 'active' ? 'inactive' : 'active';
        $data = $this->normalize($driver);
        $data['status'] = $new;
        $this->drivers->update((int) $driver['id'], $data);

        $this->audit->log($companyId, $this->auth->id(), 'driver.status', 'driver', (int) $driver['id'],
            ['to' => $new], client_ip());
        $this->flash->success('Conductor ' . ($new === 'active' ? 'activado' : 'desactivado') . '.');

        return $this->redirect($response, '/conductores');
    }

    // --- Helpers ---------------------------------------------------------------

    /** @param array<string,mixed> $d @return array<string,mixed> */
    private function normalize(array $d): array
    {
        return [
            'first_name'     => trim((string) $d['first_name']),
            'last_name'      => trim((string) $d['last_name']),
            'dni'            => trim((string) ($d['dni'] ?? '')),
            'license_number' => trim((string) ($d['license_number'] ?? '')),
            'phone'          => trim((string) ($d['phone'] ?? '')),
            'email'          => trim((string) ($d['email'] ?? '')),
            'pin'            => trim((string) ($d['pin'] ?? '')),
            'status'         => $d['status'] ?? 'active',
        ];
    }

    /**
     * @param array<string,mixed> $d
     * @return array<string,string>
     */
    private function validate(array $d, int $companyId, ?int $exceptId): array
    {
        $v = new Validator($d);
        $v->required('first_name', 'El nombre')->required('last_name', 'El apellido');
        if (trim((string) ($d['email'] ?? '')) !== '') {
            $v->email('email');
        }
        $errors = $v->errors();

        $pin = trim((string) ($d['pin'] ?? ''));
        if ($pin !== '') {
            if (!preg_match('/^[A-Za-z0-9]{' . $this->pinMin . ',' . $this->pinMax . '}$/', $pin)) {
                $errors['pin'] = "El PIN debe ser alfanumérico de {$this->pinMin} a {$this->pinMax} caracteres.";
            } elseif ($this->drivers->pinTaken($pin, $companyId, $exceptId)) {
                $errors['pin'] = 'Ese PIN ya está usado por otro conductor.';
            }
        }

        return $errors;
    }

    /**
     * @param array<string,mixed> $d
     * @param array<string,string> $errors
     */
    private function renderForm(Response $response, string $mode, array $d, array $errors): Response
    {
        if ($errors !== []) {
            $this->flash->error('Revisá los datos del formulario.');
        }

        return $this->twig->render($response, 'pages/drivers/form.twig', [
            'mode' => $mode, 'd' => $d, 'errors' => $errors, 'pin_min' => $this->pinMin, 'pin_max' => $this->pinMax,
        ]);
    }
}
