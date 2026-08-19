<?php

declare(strict_types=1);

namespace Satrak\Application\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Satrak\Application\Support\Auth;
use Satrak\Application\Support\Flash;
use Satrak\Application\Support\Validator;
use Satrak\Domain\Repositories\AuditRepository;
use Satrak\Domain\Repositories\MissionRepository;
use Satrak\Domain\Repositories\PersonPostRepository;
use Satrak\Domain\Repositories\PersonRepository;
use Satrak\Domain\Repositories\PersonShiftRepository;
use Satrak\Domain\Repositories\PositionRepository;
use Satrak\Domain\Repositories\UserRepository;
use Satrak\Domain\Services\ShiftGuard;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * Portal de la persona (`/mi`): ve **sólo lo suyo**.
 *
 * Scope estricto por el `person_id` del usuario logueado, igual que el portal
 * del conductor. Si el usuario no tiene persona asociada, 404: no se filtra la
 * existencia de datos ajenos.
 *
 * Es también la pantalla donde la persona ve qué se está registrando de ella
 * —jornada, puesto, ubicación— que es parte de lo que exige el consentimiento
 * informado (Ley 25.326).
 */
final class PersonPortalController
{
    private const WEEKDAYS = [
        1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves',
        5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo',
    ];

    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private Flash $flash,
        private AuditRepository $audit,
        private PersonRepository $people,
        private PersonShiftRepository $shifts,
        private PersonPostRepository $posts,
        private MissionRepository $missions,
        private PositionRepository $positions,
        private UserRepository $users,
        private ShiftGuard $shiftGuard,
    ) {
    }

    /** person_id del usuario, o 404. */
    private function personId(Request $request): int
    {
        $id = $this->auth->personId();
        if ($id === null) {
            throw new HttpNotFoundException($request);
        }

        return $id;
    }

    private function companyId(Request $request): int
    {
        return (int) $request->getAttribute('company_id');
    }

    /** @return array<string,mixed> */
    private function person(Request $request): array
    {
        $person = $this->people->findScoped($this->personId($request), $this->companyId($request));
        if ($person === null) {
            throw new HttpNotFoundException($request);
        }

        return $person;
    }

    /** Hoy: jornada, puesto, misiones y si en este momento se la está rastreando. */
    public function today(Request $request, Response $response): Response
    {
        $personId = $this->personId($request);
        $companyId = $this->companyId($request);
        $today = date('Y-m-d');

        return $this->twig->render($response, 'pages/my/today.twig', [
            'person'    => $this->person($request),
            'weekdays'  => self::WEEKDAYS,
            'shifts'    => $this->shifts->activeForPerson($personId, $companyId),
            'on_shift'  => $this->shiftGuard->isWithinShift($personId, $companyId, date('Y-m-d H:i:s')),
            'post'      => $this->posts->currentForPerson($personId, $companyId),
            'missions'  => $this->missions->forPersonBetween(
                $personId,
                $companyId,
                $today . ' 00:00:00',
                $today . ' 23:59:59'
            ),
            'lastPos'   => $this->positions->lastForPerson($personId, $companyId),
        ]);
    }

    public function location(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'pages/my/location.twig', [
            'pos'      => $this->positions->lastForPerson($this->personId($request), $this->companyId($request)),
            'on_shift' => $this->shiftGuard->isWithinShift(
                $this->personId($request),
                $this->companyId($request),
                date('Y-m-d H:i:s')
            ),
        ]);
    }

    /** Próximas misiones asignadas (las de hoy en adelante). */
    public function missions(Request $request, Response $response): Response
    {
        $personId = $this->personId($request);
        $companyId = $this->companyId($request);

        return $this->twig->render($response, 'pages/my/missions.twig', [
            'missions' => $this->missions->forPersonBetween(
                $personId,
                $companyId,
                date('Y-m-d 00:00:00'),
                date('Y-m-d 23:59:59', time() + 14 * 86400)
            ),
        ]);
    }

    public function profile(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'pages/my/profile.twig', [
            'person' => $this->person($request),
            'user'   => $this->auth->user(),
            'errors' => [],
        ]);
    }

    /**
     * Actualiza contacto y, opcionalmente, la contraseña del panel. La clave de
     * la app se administra desde el panel de la empresa, no acá.
     */
    public function updateProfile(Request $request, Response $response): Response
    {
        $personId = $this->personId($request);
        $companyId = $this->companyId($request);
        $person = $this->person($request);

        $d = (array) $request->getParsedBody();
        $errors = [];

        $email = trim((string) ($d['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Email inválido.';
        }
        $phone = trim((string) ($d['phone'] ?? ''));

        $new = (string) ($d['new_password'] ?? '');
        $changePassword = $new !== '';
        if ($changePassword) {
            $user = $this->users->findById((int) $this->auth->id());
            if ($user === null || !password_verify((string) ($d['current_password'] ?? ''), (string) $user['password_hash'])) {
                $errors['current_password'] = 'La contraseña actual no es correcta.';
            }
            $v = new Validator($d);
            $v->minLength('new_password', 8, 'La nueva contraseña')
              ->matches('new_password', 'confirm_password', 'Las contraseñas no coinciden.');
            $errors += $v->errors();
        }

        if ($errors !== []) {
            $this->flash->error('Revisá los datos del perfil.');

            return $this->twig->render($response->withStatus(422), 'pages/my/profile.twig', [
                'person' => array_merge($person, ['phone' => $phone, 'email' => $email]),
                'user'   => $this->auth->user(),
                'errors' => $errors,
            ]);
        }

        $this->people->updateContact($personId, $companyId, $phone, $email);
        if ($changePassword) {
            $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
            $this->users->updatePasswordHash((int) $this->auth->id(), password_hash($new, $algo));
        }

        $this->audit->log($companyId, $this->auth->id(), 'person.profile_update', 'person', $personId,
            ['password_changed' => $changePassword], client_ip());
        $this->flash->success('Perfil actualizado.');

        return $response->withHeader('Location', '/mi/perfil')->withStatus(302);
    }
}
