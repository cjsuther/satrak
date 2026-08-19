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
use Satrak\Domain\Repositories\GeofenceRepository;
use Satrak\Domain\Repositories\MissionRepository;
use Satrak\Domain\Repositories\PersonRepository;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * ABM de misiones: los traslados que autorizan a una persona a estar fuera de su
 * puesto durante la jornada.
 *
 * Las carga siempre el operador (decisión §11.3): la persona sólo puede
 * iniciarlas y marcar llegada desde la app. Por eso acá no hay autoasignación.
 */
final class MissionController
{
    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private Flash $flash,
        private AuditRepository $audit,
        private MissionRepository $missions,
        private PersonRepository $people,
        private GeofenceRepository $geofences,
    ) {
    }

    private function redirect(Response $r, string $to): Response
    {
        return $r->withHeader('Location', $to)->withStatus(302);
    }

    public function index(Request $request, Response $response): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $listing = Listing::fromRequest($request, 'start');
        $status = (string) ($request->getQueryParams()['status'] ?? '');

        return $this->twig->render($response, 'pages/missions/index.twig', [
            'page'   => $this->missions->listPaginated($companyId, $listing, $status ?: null),
            'q'      => $listing->search,
            'status' => $status,
        ]);
    }

    public function createForm(Request $request, Response $response): Response
    {
        $companyId = (int) $request->getAttribute('company_id');

        return $this->renderForm($response, 'create', [
            'scheduled_start' => date('Y-m-d\TH:i'),
            'scheduled_end'   => date('Y-m-d\TH:i', time() + 3600),
        ], [], $companyId);
    }

    public function store(Request $request, Response $response): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $d = (array) $request->getParsedBody();

        $errors = $this->validate($d, $companyId);
        if ($errors !== []) {
            return $this->renderForm($response->withStatus(422), 'create', $d, $errors, $companyId);
        }

        $id = $this->missions->create($companyId, $this->normalize($d) + ['created_by' => $this->auth->id()]);
        $this->audit->log($companyId, $this->auth->id(), 'mission.create', 'mission', $id,
            ['person_id' => (int) $d['person_id']], client_ip());
        $this->flash->success('Misión creada.');

        return $this->redirect($response, '/misiones');
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $mission = $this->mission($request, $args, $companyId);

        // Los datetime-local del form usan 'T' entre fecha y hora.
        $mission['scheduled_start'] = str_replace(' ', 'T', substr((string) $mission['scheduled_start'], 0, 16));
        $mission['scheduled_end'] = str_replace(' ', 'T', substr((string) $mission['scheduled_end'], 0, 16));

        return $this->renderForm($response, 'edit', $mission, [], $companyId);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $mission = $this->mission($request, $args, $companyId);

        if (in_array($mission['status'], ['completed', 'cancelled'], true)) {
            $this->flash->error('Una misión cerrada no se edita.');

            return $this->redirect($response, '/misiones');
        }

        $d = (array) $request->getParsedBody();
        $errors = $this->validate($d, $companyId);
        if ($errors !== []) {
            return $this->renderForm(
                $response->withStatus(422),
                'edit',
                array_merge($mission, $d),
                $errors,
                $companyId
            );
        }

        $this->missions->update((int) $mission['id'], $this->normalize($d));
        $this->audit->log($companyId, $this->auth->id(), 'mission.update', 'mission', (int) $mission['id'],
            null, client_ip());
        $this->flash->success('Misión actualizada.');

        return $this->redirect($response, '/misiones');
    }

    /** Cierre manual desde el panel: cancelar o dar por cumplida. */
    public function setStatus(Request $request, Response $response, array $args): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $mission = $this->mission($request, $args, $companyId);

        $d = (array) $request->getParsedBody();
        $to = (string) ($d['status'] ?? '');
        if (!in_array($to, ['cancelled', 'completed', 'in_progress'], true)) {
            $this->flash->error('Estado inválido.');

            return $this->redirect($response, '/misiones');
        }

        $this->missions->setStatus((int) $mission['id'], $to);
        $this->audit->log($companyId, $this->auth->id(), 'mission.status', 'mission', (int) $mission['id'],
            ['from' => $mission['status'], 'to' => $to], client_ip());
        $this->flash->success('Misión actualizada.');

        return $this->redirect($response, '/misiones');
    }

    // --- Helpers ---------------------------------------------------------------

    /** @return array<string,mixed> */
    private function mission(Request $request, array $args, int $companyId): array
    {
        $mission = $this->missions->findScoped((int) ($args['id'] ?? 0), $companyId);
        if ($mission === null) {
            throw new HttpNotFoundException($request);
        }

        return $mission;
    }

    /** @param array<string,mixed> $d @return array<string,mixed> */
    private function normalize(array $d): array
    {
        return [
            'person_id'          => (int) $d['person_id'],
            'origin_geofence_id' => (int) ($d['origin_geofence_id'] ?? 0) ?: null,
            'dest_geofence_id'   => (int) $d['dest_geofence_id'],
            'scheduled_start'    => $this->toDateTime((string) $d['scheduled_start']),
            'scheduled_end'      => $this->toDateTime((string) $d['scheduled_end']),
            'vehicle_id'         => (int) ($d['vehicle_id'] ?? 0) ?: null,
            'notes'              => trim((string) ($d['notes'] ?? '')),
        ];
    }

    private function toDateTime(string $value): string
    {
        $t = strtotime(str_replace('T', ' ', trim($value)));

        return date('Y-m-d H:i:s', $t !== false ? $t : time());
    }

    /**
     * @param array<string,mixed> $d
     * @return array<string,string>
     */
    private function validate(array $d, int $companyId): array
    {
        $v = new Validator($d);
        $v->required('person_id', 'La persona')
          ->required('dest_geofence_id', 'El destino')
          ->required('scheduled_start', 'El inicio')
          ->required('scheduled_end', 'El fin');
        $errors = $v->errors();

        $personId = (int) ($d['person_id'] ?? 0);
        if ($personId > 0 && $this->people->findScoped($personId, $companyId) === null) {
            $errors['person_id'] = 'Esa persona no es de la empresa.';
        }

        foreach (['dest_geofence_id' => 'El destino', 'origin_geofence_id' => 'El origen'] as $field => $label) {
            $gid = (int) ($d[$field] ?? 0);
            if ($gid > 0 && $this->geofences->findScoped($gid, $companyId) === null) {
                $errors[$field] = "{$label} no es una geocerca de la empresa.";
            }
        }

        $start = strtotime(str_replace('T', ' ', (string) ($d['scheduled_start'] ?? '')));
        $end = strtotime(str_replace('T', ' ', (string) ($d['scheduled_end'] ?? '')));
        if ($start !== false && $end !== false && $end <= $start) {
            $errors['scheduled_end'] = 'El fin tiene que ser posterior al inicio.';
        }

        return $errors;
    }

    /**
     * @param array<string,mixed>  $m
     * @param array<string,string> $errors
     */
    private function renderForm(Response $response, string $mode, array $m, array $errors, int $companyId): Response
    {
        if ($errors !== []) {
            $this->flash->error('Revisá los datos de la misión.');
        }

        return $this->twig->render($response, 'pages/missions/form.twig', [
            'mode'      => $mode,
            'm'         => $m,
            'errors'    => $errors,
            'people'    => $this->people->activeForCompany($companyId),
            'geofences' => $this->geofences->activeForCompany($companyId),
        ]);
    }
}
