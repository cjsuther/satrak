<?php

declare(strict_types=1);

namespace Satrak\Application\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Satrak\Application\Support\Auth;
use Satrak\Application\Support\Flash;
use Satrak\Application\Support\Validator;
use Satrak\Domain\Repositories\AuditRepository;
use Satrak\Domain\Repositories\DriverRepository;
use Satrak\Domain\Repositories\PositionRepository;
use Satrak\Domain\Repositories\TripRepository;
use Satrak\Domain\Repositories\UserRepository;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * Portal del conductor (§9.4): "mi actividad", "mi última posición" y "mi perfil".
 *
 * Scope ESTRICTO: todo se filtra por el `driver_id` del usuario logueado. Si el
 * usuario no es un conductor con `driver_id`, o pide un viaje que no es suyo,
 * responde 404 (no se filtra la existencia de datos ajenos).
 */
final class DriverPortalController
{
    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private Flash $flash,
        private AuditRepository $audit,
        private DriverRepository $drivers,
        private TripRepository $trips,
        private PositionRepository $positions,
        private UserRepository $users,
    ) {
    }

    /** driver_id del usuario o 404 si no corresponde. */
    private function driverId(Request $request): int
    {
        $id = $this->auth->driverId();
        if ($id === null) {
            throw new HttpNotFoundException($request);
        }

        return $id;
    }

    private function companyId(Request $request): int
    {
        return (int) $request->getAttribute('company_id');
    }

    public function activity(Request $request, Response $response): Response
    {
        $driverId = $this->driverId($request);
        $companyId = $this->companyId($request);
        [$from, $to] = $this->range($request->getQueryParams());

        return $this->twig->render($response, 'pages/portal/activity.twig', [
            'trips'   => $this->trips->forDriver($driverId, $companyId, $from, $to),
            'lastPos' => $this->positions->lastForDriver($driverId, $companyId),
            'from'    => $from,
            'to'      => $to,
        ]);
    }

    public function trip(Request $request, Response $response, array $args): Response
    {
        $driverId = $this->driverId($request);
        $companyId = $this->companyId($request);
        $trip = $this->trips->findForDriver((int) $args['id'], $driverId, $companyId);
        if ($trip === null) {
            throw new HttpNotFoundException($request);
        }

        return $this->twig->render($response, 'pages/portal/trip.twig', ['trip' => $trip]);
    }

    /** Track del viaje (JSON) — sólo si el viaje es del conductor. */
    public function tripTrack(Request $request, Response $response, array $args): Response
    {
        $driverId = $this->driverId($request);
        $companyId = $this->companyId($request);
        $trip = $this->trips->findForDriver((int) $args['id'], $driverId, $companyId);
        if ($trip === null) {
            return $this->json($response, null, 'Viaje no encontrado', 404);
        }

        $points = $this->positions->trackForDriver(
            (int) $trip['device_id'],
            $driverId,
            (string) $trip['started_at'],
            (string) ($trip['ended_at'] ?? date('Y-m-d H:i:s'))
        );
        $out = array_map(static fn ($p) => [
            'ts'    => $p['ts'],
            'lat'   => (float) $p['lat'],
            'lon'   => (float) $p['lon'],
            'speed' => (int) ($p['speed'] ?? 0),
            'ign'   => $p['ignition'] !== null ? (int) $p['ignition'] : null,
        ], $points);

        return $this->json($response, ['points' => $out]);
    }

    public function lastPosition(Request $request, Response $response): Response
    {
        $driverId = $this->driverId($request);
        $companyId = $this->companyId($request);

        return $this->twig->render($response, 'pages/portal/last_position.twig', [
            'pos' => $this->positions->lastForDriver($driverId, $companyId),
        ]);
    }

    public function profile(Request $request, Response $response): Response
    {
        $driverId = $this->driverId($request);
        $companyId = $this->companyId($request);
        $driver = $this->drivers->findScoped($driverId, $companyId);
        if ($driver === null) {
            throw new HttpNotFoundException($request);
        }

        return $this->twig->render($response, 'pages/portal/profile.twig', [
            'driver' => $driver,
            'user'   => $this->auth->user(),
            'errors' => [],
        ]);
    }

    public function updateProfile(Request $request, Response $response): Response
    {
        $driverId = $this->driverId($request);
        $companyId = $this->companyId($request);
        $driver = $this->drivers->findScoped($driverId, $companyId);
        if ($driver === null) {
            throw new HttpNotFoundException($request);
        }

        $d = (array) $request->getParsedBody();
        $errors = [];

        // Contacto.
        $email = trim((string) ($d['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Email inválido.';
        }
        $phone = trim((string) ($d['phone'] ?? ''));

        // Cambio de contraseña (opcional): exige la actual.
        $new = (string) ($d['new_password'] ?? '');
        $changePassword = $new !== '';
        if ($changePassword) {
            $current = (string) ($d['current_password'] ?? '');
            $user = $this->users->findById((int) $this->auth->id());
            if ($user === null || !password_verify($current, (string) $user['password_hash'])) {
                $errors['current_password'] = 'La contraseña actual no es correcta.';
            }
            $v = new Validator($d);
            $v->minLength('new_password', 8, 'La nueva contraseña')
              ->matches('new_password', 'confirm_password', 'Las contraseñas no coinciden.');
            $errors += $v->errors();
        }

        if ($errors !== []) {
            $this->flash->error('Revisá los datos del perfil.');

            return $this->twig->render($response->withStatus(422), 'pages/portal/profile.twig', [
                'driver' => array_merge($driver, ['phone' => $phone, 'email' => $email]),
                'user'   => $this->auth->user(),
                'errors' => $errors,
            ]);
        }

        $this->drivers->updateContact($driverId, $companyId, $phone, $email);
        if ($changePassword) {
            $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
            $this->users->updatePasswordHash((int) $this->auth->id(), password_hash($new, $algo));
        }

        $this->audit->log($companyId, $this->auth->id(), 'driver.profile_update', 'driver', $driverId,
            ['password_changed' => $changePassword], client_ip());
        $this->flash->success('Perfil actualizado.');

        return $response->withHeader('Location', '/portal/perfil')->withStatus(302);
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * @param array<string,mixed> $q
     * @return array{0:string,1:string}
     */
    private function range(array $q): array
    {
        $toTs = !empty($q['to']) ? strtotime(str_replace('T', ' ', (string) $q['to'])) : false;
        $fromTs = !empty($q['from']) ? strtotime(str_replace('T', ' ', (string) $q['from'])) : false;
        $toTs = $toTs !== false ? $toTs : time();
        $fromTs = $fromTs !== false ? $fromTs : $toTs - 30 * 24 * 3600;
        if ($fromTs > $toTs) {
            [$fromTs, $toTs] = [$toTs, $fromTs];
        }

        return [date('Y-m-d H:i:s', $fromTs), date('Y-m-d H:i:s', $toTs)];
    }

    private function json(Response $response, mixed $data, ?string $error = null, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode(
            ['ok' => $error === null, 'data' => $data, 'error' => $error],
            JSON_UNESCAPED_UNICODE
        ));

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withStatus($status);
    }
}
