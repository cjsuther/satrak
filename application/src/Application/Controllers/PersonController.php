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
use Satrak\Domain\Repositories\DriverRepository;
use Satrak\Domain\Repositories\PersonRepository;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * ABM de personas — el maestro del módulo de personal.
 *
 * Desde acá se administra también el **perfil de conducción** (crear/editar el
 * `driver` vinculado con licencia y PIN): la persona es el maestro y el conductor
 * es un rol suyo, así que no hay alta de conductor independiente.
 *
 * La contraseña que se setea acá es la de la **app móvil** (login empresa + DNI +
 * contraseña). No da acceso al panel web: para eso se crea un usuario con
 * `role='person'` desde /usuarios.
 *
 * Scopeado por `company_id`, con cupo (`companies.person_quota`) y auditado.
 */
final class PersonController
{
    private const MIN_APP_PASSWORD = 8;

    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private Flash $flash,
        private AuditRepository $audit,
        private PersonRepository $people,
        private DriverRepository $drivers,
        private CompanyRepository $companies,
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
        $company = $this->companies->find($companyId);

        return $this->twig->render($response, 'pages/people/index.twig', [
            'page'  => $this->people->listPaginated($companyId, $listing),
            'q'     => $listing->search,
            'quota' => (int) ($company['person_quota'] ?? 0),
            'count' => $this->people->countForCompany($companyId),
        ]);
    }

    public function createForm(Request $request, Response $response): Response
    {
        $companyId = (int) $request->getAttribute('company_id');

        $over = $this->quotaExceeded($companyId);
        if ($over !== null) {
            $this->flash->error($over);

            return $this->redirect($response, '/personas');
        }

        return $this->renderForm($response, 'create', ['status' => 'active'], null, [], false);
    }

    public function store(Request $request, Response $response): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $d = (array) $request->getParsedBody();

        // Cupo: server-side, no se confía en el front.
        $over = $this->quotaExceeded($companyId);
        if ($over !== null) {
            $this->flash->error($over);

            return $this->redirect($response, '/personas');
        }

        $errors = $this->validate($d, $companyId, null, null);
        if ($errors !== []) {
            return $this->renderForm($response->withStatus(422), 'create', $d, null, $errors, $this->wantsDriver($d));
        }

        $data = $this->normalize($d);
        $data['consent_at'] = $this->consentValue($d, null);
        $id = $this->people->create($companyId, $data);

        // Contraseña de la app (opcional en el alta).
        $password = (string) ($d['app_password'] ?? '');
        if ($password !== '') {
            $this->people->setAppPassword($id, password_hash($password, $this->algo()));
        }

        // Perfil de conducción (opcional).
        $person = $this->people->findScoped($id, $companyId);
        if ($this->wantsDriver($d) && $person !== null) {
            $driverId = $this->drivers->createForPerson($companyId, $id, $person, $this->driverProfile($d));
            $this->audit->log($companyId, $this->auth->id(), 'driver.create', 'driver', $driverId,
                ['person_id' => $id, 'from' => 'person_form'], client_ip());
        }

        $this->audit->log($companyId, $this->auth->id(), 'person.create', 'person', $id, [
            'name'         => trim($data['first_name'] . ' ' . $data['last_name']),
            'is_driver'    => $this->wantsDriver($d),
            'app_password' => $password !== '',
        ], client_ip());
        $this->flash->success('Persona creada.');

        return $this->redirect($response, '/personas');
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $person = $this->people->findScoped((int) $args['id'], $companyId);
        if ($person === null) {
            throw new HttpNotFoundException($request);
        }

        $driver = $this->drivers->findByPersonId((int) $person['id'], $companyId);

        return $this->renderForm(
            $response,
            'edit',
            $person,
            $driver,
            [],
            $driver !== null && $driver['status'] === 'active'
        );
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $person = $this->people->findScoped((int) $args['id'], $companyId);
        if ($person === null) {
            throw new HttpNotFoundException($request);
        }
        $personId = (int) $person['id'];
        $driver = $this->drivers->findByPersonId($personId, $companyId);

        $d = (array) $request->getParsedBody();
        $errors = $this->validate($d, $companyId, $personId, $driver !== null ? (int) $driver['id'] : null);
        if ($errors !== []) {
            return $this->renderForm(
                $response->withStatus(422),
                'edit',
                array_merge($person, $d),
                $driver,
                $errors,
                $this->wantsDriver($d)
            );
        }

        $data = $this->normalize($d);
        $data['status'] = ($d['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        $data['consent_at'] = $this->consentValue($d, $person['consent_at'] ?? null);
        $this->people->update($personId, $data);

        // Contraseña de la app: en blanco = sin cambios.
        $password = (string) ($d['app_password'] ?? '');
        $cleared = !empty($d['clear_app_password']);
        if ($cleared) {
            $this->people->setAppPassword($personId, null);
        } elseif ($password !== '') {
            $this->people->setAppPassword($personId, password_hash($password, $this->algo()));
        }

        // Perfil de conducción: crear, actualizar o desactivar.
        $fresh = $this->people->findScoped($personId, $companyId) ?? $person;
        $profile = $this->driverProfile($d);
        if ($this->wantsDriver($d)) {
            if ($driver === null) {
                $driverId = $this->drivers->createForPerson($companyId, $personId, $fresh, $profile);
                $this->audit->log($companyId, $this->auth->id(), 'driver.create', 'driver', $driverId,
                    ['person_id' => $personId, 'from' => 'person_form'], client_ip());
            } else {
                $this->drivers->syncFromPerson((int) $driver['id'], $fresh, $profile);
            }
        } elseif ($driver !== null) {
            // No se borra: el conductor tiene viajes y alertas históricas. Se
            // desactiva y se le quita el PIN para que no siga atribuyendo.
            $this->drivers->syncFromPerson((int) $driver['id'], $fresh, [
                'license_number' => (string) ($driver['license_number'] ?? ''),
                'pin'            => '',
                'status'         => 'inactive',
            ]);
            $this->audit->log($companyId, $this->auth->id(), 'driver.status', 'driver', (int) $driver['id'],
                ['to' => 'inactive', 'reason' => 'perfil de conducción desactivado desde la persona'], client_ip());
        }

        $this->audit->log($companyId, $this->auth->id(), 'person.update', 'person', $personId, [
            'app_password_changed' => $password !== '' || $cleared,
            'is_driver'            => $this->wantsDriver($d),
        ], client_ip());
        $this->flash->success('Persona actualizada.');

        return $this->redirect($response, '/personas');
    }

    public function toggleStatus(Request $request, Response $response, array $args): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $person = $this->people->findScoped((int) $args['id'], $companyId);
        if ($person === null) {
            throw new HttpNotFoundException($request);
        }

        $new = $person['status'] === 'active' ? 'inactive' : 'active';
        $data = $this->normalize($person);
        $data['consent_at'] = $person['consent_at'];
        $data['status'] = $new;
        $this->people->update((int) $person['id'], $data);

        // El perfil de conducción sigue a la persona. El PIN se conserva: la
        // atribución ya la corta el estado (`DriverRepository::findByPin` sólo
        // mira conductores activos), así que reactivarla lo deja como estaba.
        $driver = $this->drivers->findByPersonId((int) $person['id'], $companyId);
        if ($driver !== null && $driver['status'] !== $new) {
            $this->drivers->syncFromPerson((int) $driver['id'], array_merge($person, ['status' => $new]), [
                'license_number' => (string) ($driver['license_number'] ?? ''),
                'pin'            => (string) ($driver['pin'] ?? ''),
                'status'         => $new,
            ]);
        }

        $this->audit->log($companyId, $this->auth->id(), 'person.status', 'person', (int) $person['id'],
            ['to' => $new, 'driver_synced' => $driver !== null], client_ip());
        $this->flash->success('Persona ' . ($new === 'active' ? 'activada' : 'desactivada') . '.');

        return $this->redirect($response, '/personas');
    }

    // --- Helpers ---------------------------------------------------------------

    private function algo(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    /** Mensaje de cupo completo, o NULL si hay lugar. */
    private function quotaExceeded(int $companyId): ?string
    {
        $company = $this->companies->find($companyId);
        $quota = (int) ($company['person_quota'] ?? 0);
        $count = $this->people->countForCompany($companyId);

        return $count >= $quota
            ? "Cupo de personas completo ({$count}/{$quota}). Ampliá el cupo de la empresa."
            : null;
    }

    /** @param array<string,mixed> $d */
    private function wantsDriver(array $d): bool
    {
        return !empty($d['is_driver']);
    }

    /**
     * @param array<string,mixed> $d
     * @return array{license_number:string,pin:string,status:string}
     */
    private function driverProfile(array $d): array
    {
        return [
            'license_number' => trim((string) ($d['license_number'] ?? '')),
            'pin'            => trim((string) ($d['pin'] ?? '')),
            'status'         => ($d['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
        ];
    }

    /**
     * Fecha de consentimiento resultante: se sella al tildarlo por primera vez y
     * se borra al destildarlo. No se re-sella si ya estaba dado.
     *
     * @param array<string,mixed> $d
     */
    private function consentValue(array $d, mixed $current): ?string
    {
        if (empty($d['consent'])) {
            return null;
        }

        return $current !== null && $current !== '' ? (string) $current : date('Y-m-d H:i:s');
    }

    /** @param array<string,mixed> $d @return array<string,mixed> */
    private function normalize(array $d): array
    {
        return [
            'first_name'   => trim((string) $d['first_name']),
            'last_name'    => trim((string) $d['last_name']),
            'dni'          => trim((string) ($d['dni'] ?? '')),
            'phone'        => trim((string) ($d['phone'] ?? '')),
            'email'        => trim((string) ($d['email'] ?? '')),
            'job_title'    => trim((string) ($d['job_title'] ?? '')),
            'consent_note' => trim((string) ($d['consent_note'] ?? '')),
            'status'       => $d['status'] ?? 'active',
        ];
    }

    /**
     * @param array<string,mixed> $d
     * @return array<string,string>
     */
    private function validate(array $d, int $companyId, ?int $exceptPersonId, ?int $exceptDriverId): array
    {
        $v = new Validator($d);
        $v->required('first_name', 'El nombre')->required('last_name', 'El apellido');
        if (trim((string) ($d['email'] ?? '')) !== '') {
            $v->email('email');
        }
        $errors = $v->errors();

        $dni = trim((string) ($d['dni'] ?? ''));
        if ($dni !== '' && $this->people->dniTaken($dni, $companyId, $exceptPersonId)) {
            $errors['dni'] = 'Ya hay otra persona con ese DNI en la empresa.';
        }

        // La app se loguea con empresa + DNI: sin DNI no puede iniciar sesión.
        $password = (string) ($d['app_password'] ?? '');
        if ($password !== '') {
            if (mb_strlen($password) < self::MIN_APP_PASSWORD) {
                $errors['app_password'] = 'La contraseña debe tener al menos ' . self::MIN_APP_PASSWORD . ' caracteres.';
            } elseif ($dni === '') {
                $errors['dni'] = 'Para usar la app hace falta el DNI: es el usuario de acceso.';
            }
            if (($d['app_password_confirm'] ?? null) !== null
                && (string) $d['app_password_confirm'] !== $password) {
                $errors['app_password_confirm'] = 'Las contraseñas no coinciden.';
            }
        }

        if ($this->wantsDriver($d)) {
            $pin = trim((string) ($d['pin'] ?? ''));
            if ($pin !== '') {
                if (!preg_match('/^[A-Za-z0-9]{' . $this->pinMin . ',' . $this->pinMax . '}$/', $pin)) {
                    $errors['pin'] = "El PIN debe ser alfanumérico de {$this->pinMin} a {$this->pinMax} caracteres.";
                } elseif ($this->drivers->pinTaken($pin, $companyId, $exceptDriverId)) {
                    $errors['pin'] = 'Ese PIN ya está usado por otro conductor.';
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<string,mixed>      $person
     * @param array<string,mixed>|null $driver
     * @param array<string,string>     $errors
     */
    private function renderForm(
        Response $response,
        string $mode,
        array $person,
        ?array $driver,
        array $errors,
        bool $isDriver = false
    ): Response {
        if ($errors !== []) {
            $this->flash->error('Revisá los datos del formulario.');
        }

        return $this->twig->render($response, 'pages/people/form.twig', [
            'mode'      => $mode,
            'p'         => $person,
            'driver'    => $driver,
            'is_driver' => $isDriver,
            'errors'    => $errors,
            'pin_min'   => $this->pinMin,
            'pin_max'   => $this->pinMax,
        ]);
    }
}
