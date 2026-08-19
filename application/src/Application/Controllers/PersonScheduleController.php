<?php

declare(strict_types=1);

namespace Satrak\Application\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Satrak\Application\Support\Auth;
use Satrak\Application\Support\Flash;
use Satrak\Domain\Repositories\AuditRepository;
use Satrak\Domain\Repositories\GeofenceRepository;
use Satrak\Domain\Repositories\PersonAppSessionRepository;
use Satrak\Domain\Repositories\PersonPostRepository;
use Satrak\Domain\Repositories\PersonRepository;
use Satrak\Domain\Repositories\PersonShiftRepository;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * Jornada y puesto de una persona (`/personas/{id}/jornada`).
 *
 * Las dos cosas que definen dónde puede estar y cuándo se la rastrea:
 *  - **Jornada**: ventanas semanales + excepciones. Fuera de eso no se captura.
 *  - **Puesto**: la geocerca donde debe estar; si sale sin misión, hay alerta.
 *
 * Desde acá también se ve y se revoca la sesión de la app.
 */
final class PersonScheduleController
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
        private GeofenceRepository $geofences,
        private PersonAppSessionRepository $sessions,
    ) {
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $person = $this->person($request, $args, $companyId);
        $personId = (int) $person['id'];

        return $this->twig->render($response, 'pages/people/schedule.twig', [
            'p'          => $person,
            'weekdays'   => self::WEEKDAYS,
            'shifts'     => $this->groupByWeekday($this->shifts->allForPerson($personId, $companyId)),
            'exceptions' => $this->shifts->upcomingExceptions($personId, $companyId),
            'post'       => $this->posts->currentForPerson($personId, $companyId),
            'geofences'  => $this->geofences->activeForCompany($companyId),
            'session'    => $this->sessions->statusForPerson($personId, $companyId),
        ]);
    }

    /**
     * Guarda la jornada completa. El form manda una fila por día con hora de
     * inicio y fin; los días vacíos quedan sin ventana (no se trabaja).
     */
    public function saveShifts(Request $request, Response $response, array $args): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $person = $this->person($request, $args, $companyId);
        $personId = (int) $person['id'];

        $d = (array) $request->getParsedBody();
        $windows = [];
        $invalid = 0;

        foreach (array_keys(self::WEEKDAYS) as $weekday) {
            $from = trim((string) ($d['from'][$weekday] ?? ''));
            $to = trim((string) ($d['to'][$weekday] ?? ''));
            if ($from === '' && $to === '') {
                continue;
            }
            if (!$this->isTime($from) || !$this->isTime($to)) {
                $invalid++;
                continue;
            }
            $windows[] = [
                'weekday'    => $weekday,
                'start_time' => $from . ':00',
                'end_time'   => $to . ':00',
            ];
        }

        if ($invalid > 0) {
            $this->flash->error('Hay días con horario incompleto o inválido: revisá que estén las dos horas.');

            return $this->back($response, $personId);
        }

        $this->shifts->replaceShifts($companyId, $personId, $windows);
        $this->audit->log($companyId, $this->auth->id(), 'person.shifts_update', 'person', $personId,
            ['windows' => count($windows)], client_ip());
        $this->flash->success(
            $windows === []
                ? 'Jornada vacía: esta persona no se rastrea en ningún horario.'
                : 'Jornada guardada (' . count($windows) . ' ventana' . (count($windows) === 1 ? '' : 's') . ').'
        );

        return $this->back($response, $personId);
    }

    public function addException(Request $request, Response $response, array $args): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $person = $this->person($request, $args, $companyId);
        $personId = (int) $person['id'];

        $d = (array) $request->getParsedBody();
        $date = trim((string) ($d['date'] ?? ''));
        $kind = ($d['kind'] ?? 'off') === 'extra' ? 'extra' : 'off';
        $from = trim((string) ($d['start_time'] ?? ''));
        $to = trim((string) ($d['end_time'] ?? ''));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->flash->error('Fecha inválida.');

            return $this->back($response, $personId);
        }
        if ($kind === 'extra' && (!$this->isTime($from) || !$this->isTime($to))) {
            $this->flash->error('Un turno extra necesita hora de inicio y de fin.');

            return $this->back($response, $personId);
        }

        $this->shifts->addException($companyId, $personId, [
            'date'       => $date,
            'kind'       => $kind,
            'start_time' => $from . ':00',
            'end_time'   => $to . ':00',
            'note'       => trim((string) ($d['note'] ?? '')),
        ]);
        $this->audit->log($companyId, $this->auth->id(), 'person.shift_exception', 'person', $personId,
            ['date' => $date, 'kind' => $kind], client_ip());
        $this->flash->success('Excepción agregada.');

        return $this->back($response, $personId);
    }

    public function deleteException(Request $request, Response $response, array $args): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $person = $this->person($request, $args, $companyId);
        $personId = (int) $person['id'];

        $this->shifts->deleteException((int) $args['exc'], $personId, $companyId);
        $this->flash->success('Excepción eliminada.');

        return $this->back($response, $personId);
    }

    public function savePost(Request $request, Response $response, array $args): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $person = $this->person($request, $args, $companyId);
        $personId = (int) $person['id'];

        $d = (array) $request->getParsedBody();
        $geofenceId = (int) ($d['geofence_id'] ?? 0);
        $grace = max(0, min(240, (int) ($d['grace_min'] ?? 10)));

        if ($geofenceId === 0) {
            $this->posts->clear($personId, $companyId);
            $this->audit->log($companyId, $this->auth->id(), 'person.post_clear', 'person', $personId,
                null, client_ip());
            $this->flash->success('La persona quedó sin puesto asignado.');

            return $this->back($response, $personId);
        }

        // La geocerca tiene que ser de la misma empresa.
        if ($this->geofences->findScoped($geofenceId, $companyId) === null) {
            $this->flash->error('Geocerca inválida.');

            return $this->back($response, $personId);
        }

        $this->posts->assign($companyId, $personId, $geofenceId, $grace);
        $this->audit->log($companyId, $this->auth->id(), 'person.post_assign', 'person', $personId,
            ['geofence_id' => $geofenceId, 'grace_min' => $grace], client_ip());
        $this->flash->success('Puesto asignado.');

        return $this->back($response, $personId);
    }

    /** Cierra la sesión de la app (teléfono perdido, cambio de equipo). */
    public function revokeSession(Request $request, Response $response, array $args): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $person = $this->person($request, $args, $companyId);
        $personId = (int) $person['id'];

        $revoked = $this->sessions->revokeAllForPerson($personId);
        $this->audit->log($companyId, $this->auth->id(), 'person.app_session_revoke', 'person', $personId,
            ['revoked' => $revoked], client_ip());
        $this->flash->success($revoked > 0 ? 'Sesión de la app cerrada.' : 'No había sesión activa.');

        return $this->back($response, $personId);
    }

    // --- Helpers ---------------------------------------------------------------

    /** @return array<string,mixed> */
    private function person(Request $request, array $args, int $companyId): array
    {
        $person = $this->people->findScoped((int) ($args['id'] ?? 0), $companyId);
        if ($person === null) {
            throw new HttpNotFoundException($request);
        }

        return $person;
    }

    private function back(Response $response, int $personId): Response
    {
        return $response->withHeader('Location', "/personas/{$personId}/jornada")->withStatus(302);
    }

    private function isTime(string $value): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value);
    }

    /**
     * Una ventana por día para el formulario (la grilla es un renglón por día).
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,string>>
     */
    private function groupByWeekday(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['weekday']] = [
                'from' => substr((string) $r['start_time'], 0, 5),
                'to'   => substr((string) $r['end_time'], 0, 5),
            ];
        }

        return $out;
    }
}
