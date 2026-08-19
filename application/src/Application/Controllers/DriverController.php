<?php

declare(strict_types=1);

namespace Satrak\Application\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Satrak\Application\Support\Auth;
use Satrak\Application\Support\Flash;
use Satrak\Application\Support\Listing;
use Satrak\Domain\Repositories\AuditRepository;
use Satrak\Domain\Repositories\DriverRepository;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * Listado de conductores — el **perfil de conducción** de una persona.
 *
 * Desde el módulo de Personas, la persona es el maestro: el alta y la edición
 * (licencia, PIN, datos personales) se hacen en `/personas`. Acá quedan el
 * listado, la activación/desactivación rápida y los enlaces a la persona.
 * Scopeado por company_id y auditado.
 */
final class DriverController
{
    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private Flash $flash,
        private AuditRepository $audit,
        private DriverRepository $drivers
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

    /**
     * El alta ya no vive acá: un conductor es el perfil de conducción de una
     * persona, así que se crea desde el formulario de la persona.
     */
    public function createForm(Request $request, Response $response): Response
    {
        $this->flash->success('Creá la persona y activá su perfil de conductor.');

        return $this->redirect($response, '/personas/nueva');
    }

    /** Redirige a la persona titular del perfil. */
    public function editForm(Request $request, Response $response, array $args): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $driver = $this->drivers->findScoped((int) $args['id'], $companyId);
        if ($driver === null) {
            throw new HttpNotFoundException($request);
        }

        if ($driver['person_id'] === null) {
            $this->flash->error('Este conductor todavía no está vinculado a una persona.');

            return $this->redirect($response, '/personas');
        }

        return $this->redirect($response, '/personas/' . (int) $driver['person_id'] . '/editar');
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
}
