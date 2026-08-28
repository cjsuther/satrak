<?php

declare(strict_types=1);

namespace Satrak\Application\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Satrak\Application\Support\Auth;
use Satrak\Application\Support\Flash;
use Satrak\Domain\Repositories\AuditRepository;
use Satrak\Domain\Repositories\GeofenceRepository;
use Satrak\Domain\Repositories\PersonRepository;
use Satrak\Domain\Repositories\VehicleRepository;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * ABM de geocercas (círculo / polígono dibujados en Leaflet) y su alcance.
 *
 * El alcance es por tipo: una geocerca puede apuntar a vehículos y/o a personas.
 * Si no se elige ninguno de un tipo, aplica a todos los de ese tipo. Scopeado por
 * empresa y auditado (§9.2). La geometría llega serializada desde el editor de
 * mapa y se valida en servidor.
 */
use Satrak\Domain\Services\ZonePayload;

final class GeofenceController
{
    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private Flash $flash,
        private AuditRepository $audit,
        private GeofenceRepository $geofences,
        private VehicleRepository $vehicles,
        private PersonRepository $people,
    ) {
    }

    private function redirect(Response $r, string $to): Response
    {
        return $r->withHeader('Location', $to)->withStatus(302);
    }

    public function index(Request $request, Response $response): Response
    {
        $companyId = (int) $request->getAttribute('company_id');

        return $this->twig->render($response, 'pages/geofences/index.twig', [
            'geofences' => $this->geofences->forCompany($companyId),
        ]);
    }

    public function createForm(Request $request, Response $response): Response
    {
        $companyId = (int) $request->getAttribute('company_id');

        return $this->twig->render($response, 'pages/geofences/form.twig', [
            'mode'        => 'create',
            'g'           => ['name' => '', 'shape' => 'circle', 'geometry' => '', 'active' => 1],
            'vehicles'    => $this->vehicleOptions($companyId),
            'people'      => $this->people->activeForCompany($companyId),
            'selectedIds' => [],
            'selectedPeopleIds' => [],
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $d = (array) $request->getParsedBody();

        $errors = $this->validate($d);
        if ($errors !== []) {
            return $this->renderForm($response->withStatus(422), 'create', $d, $companyId, $errors);
        }

        [$shape, $geometry] = $this->resolveGeometry($d);
        $id = $this->geofences->create(
            $companyId,
            trim((string) $d['name']),
            $shape,
            $geometry,
            $this->vehicleIdsScoped($d, $companyId),
            $this->personIdsScoped($d, $companyId)
        );
        $this->audit->log($companyId, $this->auth->id(), 'geofence.create', 'geofence', $id,
            ['name' => trim((string) $d['name']), 'shape' => $shape], client_ip());
        $this->flash->success('Geocerca creada.');

        return $this->redirect($response, '/geocercas');
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $g = $this->geofences->findScoped((int) $args['id'], $companyId);
        if ($g === null) {
            throw new HttpNotFoundException($request);
        }

        return $this->twig->render($response, 'pages/geofences/form.twig', [
            'mode'        => 'edit',
            'g'           => $g,
            'vehicles'    => $this->vehicleOptions($companyId),
            'people'      => $this->people->activeForCompany($companyId),
            'selectedIds' => $this->geofences->vehicleIds((int) $g['id']),
            'selectedPeopleIds' => $this->geofences->personIds((int) $g['id']),
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $g = $this->geofences->findScoped((int) $args['id'], $companyId);
        if ($g === null) {
            throw new HttpNotFoundException($request);
        }

        $d = (array) $request->getParsedBody();
        // El shape no se cambia al editar: se conserva el original.
        $d['shape'] = $g['shape'];
        $errors = $this->validate($d);
        if ($errors !== []) {
            return $this->renderForm($response->withStatus(422), 'edit', array_merge($g, $d), $companyId, $errors);
        }

        [$shape, $geometry] = $this->resolveGeometry($d);
        $this->geofences->update(
            (int) $g['id'],
            trim((string) $d['name']),
            $geometry,
            $this->vehicleIdsScoped($d, $companyId),
            ($d['active'] ?? '1') === '1',
            $this->personIdsScoped($d, $companyId),
            $shape
        );
        $this->audit->log($companyId, $this->auth->id(), 'geofence.update', 'geofence', (int) $g['id'], null, client_ip());
        $this->flash->success('Geocerca actualizada.');

        return $this->redirect($response, '/geocercas');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $g = $this->geofences->findScoped((int) $args['id'], $companyId);
        if ($g === null) {
            throw new HttpNotFoundException($request);
        }

        $this->geofences->delete((int) $g['id'], $companyId);
        $this->audit->log($companyId, $this->auth->id(), 'geofence.delete', 'geofence', (int) $g['id'], null, client_ip());
        $this->flash->success('Geocerca eliminada.');

        return $this->redirect($response, '/geocercas');
    }

    // -- Helpers --------------------------------------------------------------

    /** @return array<int,array{id:int,plate:string}> */
    private function vehicleOptions(int $companyId): array
    {
        return array_map(static fn ($v) => ['id' => (int) $v['id'], 'plate' => (string) ($v['plate'] ?? $v['id'])],
            $this->vehicles->activeForCompany($companyId));
    }

    /**
     * Vehículos elegidos, filtrados a los de la empresa (evita inyectar ids ajenos).
     *
     * @param array<string,mixed> $d
     * @return int[]
     */
    private function vehicleIdsScoped(array $d, int $companyId): array
    {
        $ids = array_map('intval', (array) ($d['vehicle_ids'] ?? []));
        if ($ids === []) {
            return [];
        }
        $valid = array_map(static fn ($v) => (int) $v['id'], $this->vehicles->activeForCompany($companyId));

        return array_values(array_intersect($ids, $valid));
    }

    /**
     * Personas elegidas, filtradas a las de la empresa.
     *
     * @param array<string,mixed> $d
     * @return int[]
     */
    private function personIdsScoped(array $d, int $companyId): array
    {
        $ids = array_map('intval', (array) ($d['person_ids'] ?? []));
        if ($ids === []) {
            return [];
        }
        $valid = array_map(static fn ($p) => (int) $p['id'], $this->people->activeForCompany($companyId));

        return array_values(array_intersect($ids, $valid));
    }

    /**
     * @param array<string,mixed> $d
     * @return array<string,string>
     */
    /**
     * Forma y geometría definitivas a guardar.
     *
     * Prioriza el FeatureCollection del editor; si no vino (se guardó sin
     * tocar el dibujo), se conservan `shape`/`geometry` tal como estaban.
     *
     * @param array<string,mixed> $d
     * @return array{0:string,1:string} [shape, geometryJson]
     */
    private function resolveGeometry(array $d): array
    {
        $zones = trim((string) ($d['zones'] ?? ''));
        if ($zones !== '') {
            $payload = ZonePayload::fromFeatureCollection($zones);
            if (!$payload->failed()) {
                // Todo lo que dibuja el editor es un polígono, incluidos los
                // círculos y rectángulos.
                return ['polygon', (string) json_encode($payload->polygon)];
            }
        }

        return [(string) ($d['shape'] ?? 'polygon'), (string) ($d['geometry'] ?? '')];
    }

    private function validate(array $d): array
    {
        $errors = [];
        if (trim((string) ($d['name'] ?? '')) === '') {
            $errors['name'] = 'Poné un nombre.';
        }
        $shape = $d['shape'] ?? '';
        if (!in_array($shape, ['circle', 'polygon'], true)) {
            $errors['shape'] = 'Forma inválida.';
        }

        // Contrato nuevo: si el editor mandó el FeatureCollection, ése manda.
        // `geometry`/`shape` se siguen aceptando para no romper una geocerca
        // que se guarda sin tocar el dibujo (ver geofence.js) ni un cliente
        // viejo con la página cacheada.
        $zones = trim((string) ($d['zones'] ?? ''));
        if ($zones !== '') {
            $payload = ZonePayload::fromFeatureCollection($zones);
            if ($payload->failed()) {
                $errors['geometry'] = $payload->error;
            }

            return $errors;
        }

        $geom = json_decode((string) ($d['geometry'] ?? ''), true);
        if (!is_array($geom)) {
            $errors['geometry'] = 'Dibujá la geocerca en el mapa.';
        } elseif ($shape === 'circle') {
            if (!isset($geom['lat'], $geom['lon'], $geom['radius_m']) || (float) $geom['radius_m'] <= 0) {
                $errors['geometry'] = 'El círculo necesita centro y radio.';
            }
        } elseif ($shape === 'polygon') {
            if (count($geom) < 3) {
                $errors['geometry'] = 'El polígono necesita al menos 3 vértices.';
            }
        }

        return $errors;
    }

    /**
     * @param array<string,mixed> $g
     * @param array<string,string> $errors
     */
    private function renderForm(Response $response, string $mode, array $g, int $companyId, array $errors): Response
    {
        $this->flash->error('Revisá los datos de la geocerca.');

        return $this->twig->render($response, 'pages/geofences/form.twig', [
            'mode'        => $mode,
            'g'           => $g,
            'errors'      => $errors,
            'vehicles'    => $this->vehicleOptions($companyId),
            'people'      => $this->people->activeForCompany($companyId),
            'selectedIds' => array_map('intval', (array) ($g['vehicle_ids'] ?? [])),
            'selectedPeopleIds' => array_map('intval', (array) ($g['person_ids'] ?? [])),
        ]);
    }
}
