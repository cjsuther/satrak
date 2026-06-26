<?php

declare(strict_types=1);

namespace Satrak\Application\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Satrak\Application\Support\Auth;
use Satrak\Application\Support\Flash;
use Satrak\Domain\Repositories\AlertRuleRepository;
use Satrak\Domain\Repositories\AuditRepository;
use Satrak\Domain\Repositories\GeofenceRepository;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * ABM de reglas de alerta (§12). Los `params` varían por tipo; `channels` define
 * los canales (in-app / email) y `recipients` emails extra. Scopeado y auditado.
 */
final class AlertRuleController
{
    private const TYPES = ['speed', 'geofence_enter', 'geofence_exit', 'idle', 'offline', 'sos'];
    private const LABELS = [
        'speed'          => 'Exceso de velocidad',
        'geofence_enter' => 'Entrada a geocerca',
        'geofence_exit'  => 'Salida de geocerca',
        'idle'           => 'Ralentí',
        'offline'        => 'Sin señal (offline)',
        'sos'            => 'SOS / pánico',
    ];

    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private Flash $flash,
        private AuditRepository $audit,
        private AlertRuleRepository $rules,
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
        $geofenceNames = [];
        foreach ($this->geofences->forCompany($companyId) as $g) {
            $geofenceNames[(int) $g['id']] = $g['name'];
        }

        return $this->twig->render($response, 'pages/alert_rules/index.twig', [
            'rules'         => $this->rules->forCompany($companyId),
            'labels'        => self::LABELS,
            'geofenceNames' => $geofenceNames,
        ]);
    }

    public function createForm(Request $request, Response $response): Response
    {
        $companyId = (int) $request->getAttribute('company_id');

        return $this->twig->render($response, 'pages/alert_rules/form.twig', [
            'mode'      => 'create',
            'r'         => ['type' => 'speed', 'params' => [], 'channels' => ['inapp'], 'recipients' => [], 'active' => 1],
            'types'     => self::TYPES,
            'labels'    => self::LABELS,
            'geofences' => $this->geofences->forCompany($companyId),
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $d = (array) $request->getParsedBody();
        $type = (string) ($d['type'] ?? '');

        $errors = $this->validate($d, $companyId, $type);
        if ($errors !== []) {
            return $this->renderForm($response->withStatus(422), 'create', $d, $companyId, $errors);
        }

        $id = $this->rules->create(
            $companyId,
            $type,
            $this->paramsFor($type, $d),
            $this->channels($d),
            $this->recipients($d)
        );
        $this->audit->log($companyId, $this->auth->id(), 'alert_rule.create', 'alert_rule', $id,
            ['type' => $type], client_ip());
        $this->flash->success('Regla de alerta creada.');

        return $this->redirect($response, '/reglas-alerta');
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $rule = $this->rules->findScoped((int) $args['id'], $companyId);
        if ($rule === null) {
            throw new HttpNotFoundException($request);
        }

        return $this->twig->render($response, 'pages/alert_rules/form.twig', [
            'mode'      => 'edit',
            'r'         => $this->decodeRule($rule),
            'types'     => self::TYPES,
            'labels'    => self::LABELS,
            'geofences' => $this->geofences->forCompany($companyId),
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $rule = $this->rules->findScoped((int) $args['id'], $companyId);
        if ($rule === null) {
            throw new HttpNotFoundException($request);
        }

        $d = (array) $request->getParsedBody();
        $type = (string) $rule['type']; // el tipo no cambia al editar
        $d['type'] = $type;
        $errors = $this->validate($d, $companyId, $type);
        if ($errors !== []) {
            return $this->renderForm($response->withStatus(422), 'edit', array_merge($this->decodeRule($rule), $d), $companyId, $errors);
        }

        $this->rules->update(
            (int) $rule['id'],
            $this->paramsFor($type, $d),
            $this->channels($d),
            $this->recipients($d),
            ($d['active'] ?? '1') === '1'
        );
        $this->audit->log($companyId, $this->auth->id(), 'alert_rule.update', 'alert_rule', (int) $rule['id'], null, client_ip());
        $this->flash->success('Regla actualizada.');

        return $this->redirect($response, '/reglas-alerta');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $rule = $this->rules->findScoped((int) $args['id'], $companyId);
        if ($rule === null) {
            throw new HttpNotFoundException($request);
        }

        $this->rules->delete((int) $rule['id'], $companyId);
        $this->audit->log($companyId, $this->auth->id(), 'alert_rule.delete', 'alert_rule', (int) $rule['id'], null, client_ip());
        $this->flash->success('Regla eliminada.');

        return $this->redirect($response, '/reglas-alerta');
    }

    // -- Helpers --------------------------------------------------------------

    /** @param array<string,mixed> $d @return array<string,mixed>|null */
    private function paramsFor(string $type, array $d): ?array
    {
        return match ($type) {
            'speed'          => ['max_kmh' => max(1, (int) ($d['max_kmh'] ?? 0))],
            'idle'           => ['minutes' => max(1, (int) ($d['minutes'] ?? 0))],
            'offline'        => ['minutes' => max(1, (int) ($d['minutes'] ?? 0))],
            'geofence_enter', 'geofence_exit' => ['geofence_id' => (int) ($d['geofence_id'] ?? 0)],
            default          => null, // sos
        };
    }

    /** @param array<string,mixed> $d @return string[] */
    private function channels(array $d): array
    {
        $ch = [];
        foreach (['inapp', 'email'] as $c) {
            if (!empty($d['ch_' . $c])) {
                $ch[] = $c;
            }
        }

        return $ch !== [] ? $ch : ['inapp'];
    }

    /** @param array<string,mixed> $d @return string[] */
    private function recipients(array $d): array
    {
        $raw = (string) ($d['recipients'] ?? '');
        $out = [];
        foreach (preg_split('/[,\s;]+/', $raw) ?: [] as $e) {
            $e = trim($e);
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $out[] = $e;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array<string,mixed> $d
     * @return array<string,string>
     */
    private function validate(array $d, int $companyId, string $type): array
    {
        $errors = [];
        if (!in_array($type, self::TYPES, true)) {
            $errors['type'] = 'Tipo inválido.';

            return $errors;
        }
        if ($type === 'speed' && (int) ($d['max_kmh'] ?? 0) <= 0) {
            $errors['max_kmh'] = 'Indicá la velocidad máxima (km/h).';
        }
        if (in_array($type, ['idle', 'offline'], true) && (int) ($d['minutes'] ?? 0) <= 0) {
            $errors['minutes'] = 'Indicá los minutos.';
        }
        if (in_array($type, ['geofence_enter', 'geofence_exit'], true)) {
            $gid = (int) ($d['geofence_id'] ?? 0);
            if ($gid <= 0 || $this->geofences->findScoped($gid, $companyId) === null) {
                $errors['geofence_id'] = 'Elegí una geocerca.';
            }
        }

        return $errors;
    }

    /**
     * Decodifica los JSON de una fila para el formulario.
     *
     * @param array<string,mixed> $rule
     * @return array<string,mixed>
     */
    private function decodeRule(array $rule): array
    {
        $rule['params'] = $rule['params'] ? (json_decode((string) $rule['params'], true) ?: []) : [];
        $rule['channels'] = $rule['channels'] ? (json_decode((string) $rule['channels'], true) ?: []) : [];
        $rule['recipients'] = $rule['recipients'] ? (json_decode((string) $rule['recipients'], true) ?: []) : [];

        return $rule;
    }

    /**
     * @param array<string,mixed> $r
     * @param array<string,string> $errors
     */
    private function renderForm(Response $response, string $mode, array $r, int $companyId, array $errors): Response
    {
        $this->flash->error('Revisá los datos de la regla.');
        // Normaliza para el template (params/channels en claves directas).
        $r['params'] = is_array($r['params'] ?? null) ? $r['params'] : [];
        $r['channels'] = array_values(array_filter(['inapp', 'email'], static fn ($c) => !empty($r['ch_' . $c])))
            ?: ($r['channels'] ?? ['inapp']);

        return $this->twig->render($response, 'pages/alert_rules/form.twig', [
            'mode'      => $mode,
            'r'         => $r,
            'types'     => self::TYPES,
            'labels'    => self::LABELS,
            'geofences' => $this->geofences->forCompany($companyId),
        ]);
    }
}
