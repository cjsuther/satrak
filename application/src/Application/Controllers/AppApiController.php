<?php

declare(strict_types=1);

namespace Satrak\Application\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Satrak\Application\Support\RateLimiter;
use Satrak\Domain\Repositories\AuditRepository;
use Satrak\Domain\Repositories\CompanyRepository;
use Satrak\Domain\Repositories\DeviceEventRepository;
use Satrak\Domain\Repositories\DevicePersonAssignmentRepository;
use Satrak\Domain\Repositories\DeviceRepository;
use Satrak\Domain\Repositories\MissionRepository;
use Satrak\Domain\Repositories\PersonAppSessionRepository;
use Satrak\Domain\Repositories\PersonPostRepository;
use Satrak\Domain\Repositories\PersonRepository;
use Satrak\Domain\Repositories\PersonShiftRepository;
use Satrak\Domain\Repositories\PositionRepository;
use Satrak\Domain\Services\Geo;
use Satrak\Domain\Services\GeofenceMath;
use Satrak\Domain\Services\ShiftGuard;

/**
 * API de la app móvil (`/api/app/*`).
 *
 * Es el "módulo de captura" del módulo de personas: la app se registra como un
 * `device` (`kind='person'`, `source='app'`) y escribe en `positions` y
 * `device_events` con el mismo contrato que usará el hardware. De ahí en más el
 * procesador, los viajes, las geocercas y el mapa funcionan sin cambios.
 *
 * Reglas que se hacen cumplir acá:
 *  - **Sesión única**: un login nuevo revoca el anterior de esa persona.
 *  - **Sólo se rastrea en jornada**: las posiciones fuera de turno se descartan
 *    (segunda capa; la app tampoco debería mandarlas).
 *  - **El pánico se acepta siempre**, dentro o fuera de turno.
 *
 * Todas las respuestas usan el envelope `{ok, data, error}` (§15 del spec base).
 */
final class AppApiController
{
    /** Máximo de puntos por lote: acota memoria y tiempo de request. */
    private const MAX_BATCH = 200;
    /** Deriva máxima aceptada del reloj del equipo, en segundos. */
    private const MAX_CLOCK_DRIFT = 86400;
    /** Radio de tolerancia para dar por llegada una misión, en metros. */
    private const ARRIVE_RADIUS_M = 150;
    /** Tope del identificador de instalación (ancho de `devices.imei`). */
    private const MAX_INSTALL_ID = 64;

    public function __construct(
        private PersonRepository $people,
        private PersonAppSessionRepository $sessions,
        private DeviceRepository $devices,
        private DevicePersonAssignmentRepository $assignments,
        private CompanyRepository $companies,
        private PositionRepository $positions,
        private DeviceEventRepository $events,
        private MissionRepository $missions,
        private PersonPostRepository $posts,
        private PersonShiftRepository $shifts,
        private ShiftGuard $shiftGuard,
        private AuditRepository $audit,
        private RateLimiter $limiter,
        private int $movingSampleSeconds = 60,
        private int $stoppedSampleSeconds = 300,
    ) {
    }

    // =========================================================================
    // Público (sin token)
    // =========================================================================

    /**
     * Login: empresa + DNI + contraseña. Devuelve el bearer y todo lo que la app
     * necesita para arrancar (jornada, puesto, misiones, config de muestreo).
     */
    public function login(Request $request, Response $response): Response
    {
        $d = $this->body($request);

        $slug = trim((string) ($d['company_slug'] ?? ''));
        $dni = trim((string) ($d['dni'] ?? ''));
        $password = (string) ($d['password'] ?? '');
        $installId = trim((string) ($d['install_id'] ?? ''));
        $platform = (string) ($d['platform'] ?? '');

        if ($slug === '' || $dni === '' || $password === '' || $installId === '') {
            return $this->json($response, null, 'Faltan datos de acceso.', 422);
        }
        if (!in_array($platform, ['android', 'ios'], true)) {
            return $this->json($response, null, 'Plataforma inválida.', 422);
        }
        if (mb_strlen($installId) > self::MAX_INSTALL_ID) {
            return $this->json($response, null, 'Identificador de instalación inválido.', 422);
        }

        // Rate-limit por instalación + IP: frena fuerza bruta sobre el DNI.
        $key = 'app|' . $installId . '|' . client_ip();
        if ($this->limiter->tooManyAttempts($key)) {
            return $this->json($response, ['retry_after' => $this->limiter->retryAfter($key)],
                'Demasiados intentos. Probá más tarde.', 429);
        }

        $company = $this->companies->findBySlug($slug);
        $person = $company !== null
            ? $this->people->findByDni((int) $company['id'], $dni)
            : null;

        $valid = $company !== null
            && $company['status'] === 'active'
            && $person !== null
            && $person['status'] === 'active'
            && $person['password_hash'] !== null
            && password_verify($password, (string) $person['password_hash']);

        if (!$valid) {
            $this->limiter->hit($key);
            // Mensaje único: no se revela si falló la empresa, el DNI o la clave.
            return $this->json($response, null, 'Empresa, DNI o contraseña incorrectos.', 401);
        }

        $this->limiter->clear($key);

        $companyId = (int) $company['id'];
        $personId = (int) $person['id'];

        // Sesión única: se cierra cualquier otra antes de abrir la nueva.
        $revoked = $this->sessions->revokeAllForPerson($personId);

        $label = trim($person['first_name'] . ' ' . $person['last_name']);
        $deviceId = $this->devices->upsertAppDevice(
            $companyId,
            $installId,
            $label,
            trim((string) ($d['model'] ?? '')) ?: null
        );
        $this->assignments->assign($companyId, $deviceId, $personId);

        $token = str_random(32);
        $sessionId = $this->sessions->create(
            $companyId,
            $personId,
            $deviceId,
            PersonAppSessionRepository::hashToken($token),
            [
                'install_id'  => $installId,
                'platform'    => $platform,
                'os_version'  => trim((string) ($d['os_version'] ?? '')),
                'app_version' => trim((string) ($d['app_version'] ?? '')),
                'model'       => trim((string) ($d['model'] ?? '')),
            ]
        );

        $this->audit->log($companyId, null, 'app.login', 'person', $personId, [
            'install_id'        => $installId,
            'platform'          => $platform,
            'revoked_sessions'  => $revoked,
        ], client_ip());

        return $this->json($response, [
            'token'   => $token,
            'session' => ['id' => $sessionId, 'device_id' => $deviceId],
            'person'  => [
                'id'         => $personId,
                'first_name' => $person['first_name'],
                'last_name'  => $person['last_name'],
                'company'    => $company['name'],
            ],
        ] + $this->syncPayload($companyId, $personId));
    }

    // =========================================================================
    // Autenticado (Bearer)
    // =========================================================================

    public function logout(Request $request, Response $response): Response
    {
        $this->sessions->revoke((int) $request->getAttribute('session_id'));
        $this->audit->log(
            (int) $request->getAttribute('company_id'),
            null,
            'app.logout',
            'person',
            (int) $request->getAttribute('person_id'),
            null,
            client_ip()
        );

        return $this->json($response, ['ok' => true]);
    }

    /** Configuración y asignaciones vigentes. La app lo pollea. */
    public function sync(Request $request, Response $response): Response
    {
        return $this->json($response, $this->syncPayload(
            (int) $request->getAttribute('company_id'),
            (int) $request->getAttribute('person_id')
        ));
    }

    /**
     * Ingesta de posiciones por lote.
     *
     * Descarta, informando cuántos: puntos malformados, con deriva de reloj
     * excesiva, o **fuera de la jornada** (regla legal del módulo).
     */
    public function positions(Request $request, Response $response): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $personId = (int) $request->getAttribute('person_id');
        $deviceId = (int) $request->getAttribute('device_id');

        $d = $this->body($request);
        $raw = $d['points'] ?? null;
        if (!is_array($raw)) {
            return $this->json($response, null, 'Se esperaba un arreglo `points`.', 422);
        }
        if (count($raw) > self::MAX_BATCH) {
            return $this->json($response, null, 'El lote supera ' . self::MAX_BATCH . ' puntos.', 422);
        }

        $now = time();
        $clean = [];
        $invalid = 0;
        $outOfShift = 0;

        foreach ($raw as $p) {
            if (!is_array($p)) {
                $invalid++;
                continue;
            }
            $point = $this->normalizePoint($p, $now);
            if ($point === null) {
                $invalid++;
                continue;
            }
            if (!$this->shiftGuard->isWithinShift($personId, $companyId, $point['ts'])) {
                $outOfShift++;
                continue;
            }
            $clean[] = $point;
        }

        usort($clean, static fn (array $a, array $b): int => strcmp($a['ts'], $b['ts']));
        $stored = $this->positions->insertBatch($companyId, $deviceId, $clean);

        $this->sessions->touch(
            (int) $request->getAttribute('session_id'),
            $this->lastBattery($clean),
            null
        );

        return $this->json($response, [
            'received'     => count($raw),
            'stored'       => $stored,
            'duplicated'   => count($clean) - $stored,
            'invalid'      => $invalid,
            'out_of_shift' => $outOfShift,
        ]);
    }

    /**
     * Botón de pánico. Se acepta SIEMPRE — dentro o fuera de turno, con o sin
     * puesto —, porque es lo único que la persona puede usar en una emergencia.
     */
    public function panic(Request $request, Response $response): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $personId = (int) $request->getAttribute('person_id');
        $deviceId = (int) $request->getAttribute('device_id');

        $d = $this->body($request);
        $ts = $this->normalizeTs($d['ts'] ?? null, time()) ?? date('Y-m-d H:i:s');

        // La app reintenta sin conexión: no duplicamos el mismo pánico.
        if ($this->events->existsAt($deviceId, 'panic', $ts)) {
            return $this->json($response, ['duplicated' => true, 'ts' => $ts]);
        }

        $lat = $this->floatOrNull($d['lat'] ?? null);
        $lon = $this->floatOrNull($d['lon'] ?? null);

        $eventId = $this->events->create($companyId, $deviceId, $ts, 'panic', [
            'person_id' => $personId,
            'lat'       => $lat,
            'lon'       => $lon,
            'source'    => 'app',
        ]);

        $this->audit->log($companyId, null, 'app.panic', 'person', $personId,
            ['ts' => $ts, 'lat' => $lat, 'lon' => $lon], client_ip());

        return $this->json($response, ['event_id' => $eventId, 'ts' => $ts]);
    }

    /** Eventos secundarios: batería baja, pérdida de permisos, etc. */
    public function events(Request $request, Response $response): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $deviceId = (int) $request->getAttribute('device_id');

        $d = $this->body($request);
        $type = (string) ($d['type'] ?? '');
        $allowed = ['low_battery', 'app_permission_lost', 'app_permission_ok'];
        if (!in_array($type, $allowed, true)) {
            return $this->json($response, null, 'Tipo de evento no admitido.', 422);
        }

        $ts = $this->normalizeTs($d['ts'] ?? null, time()) ?? date('Y-m-d H:i:s');
        $eventId = $this->events->create($companyId, $deviceId, $ts, $type, [
            'battery_pct' => $this->intOrNull($d['battery_pct'] ?? null),
            'source'      => 'app',
        ]);

        return $this->json($response, ['event_id' => $eventId]);
    }

    /** Latido: batería y estado de permisos, para detectar equipos "mudos". */
    public function heartbeat(Request $request, Response $response): Response
    {
        $d = $this->body($request);
        $perms = $d['perms_ok'] ?? null;

        $this->sessions->touch(
            (int) $request->getAttribute('session_id'),
            $this->intOrNull($d['battery_pct'] ?? null),
            $perms === null ? null : (bool) $perms
        );

        return $this->json($response, ['ok' => true, 'server_time' => date('c')]);
    }

    /** La persona inicia una misión asignada. */
    public function startMission(Request $request, Response $response, array $args): Response
    {
        $mission = $this->ownMission($request, (int) ($args['id'] ?? 0));
        if ($mission === null) {
            return $this->json($response, null, 'Misión no encontrada.', 404);
        }
        if ($mission['status'] !== 'pending') {
            return $this->json($response, null, 'La misión no está pendiente.', 409);
        }

        $this->missions->setStatus((int) $mission['id'], 'in_progress');

        return $this->json($response, ['id' => (int) $mission['id'], 'status' => 'in_progress']);
    }

    /**
     * La persona marca llegada. Se valida contra la geocerca de destino: si el
     * equipo no está cerca, no se da por cumplida.
     */
    public function arriveMission(Request $request, Response $response, array $args): Response
    {
        $mission = $this->ownMission($request, (int) ($args['id'] ?? 0));
        if ($mission === null) {
            return $this->json($response, null, 'Misión no encontrada.', 404);
        }
        if ($mission['status'] !== 'in_progress') {
            return $this->json($response, null, 'La misión no está en curso.', 409);
        }

        $d = $this->body($request);
        $lat = $this->floatOrNull($d['lat'] ?? null);
        $lon = $this->floatOrNull($d['lon'] ?? null);
        if ($lat === null || $lon === null) {
            return $this->json($response, null, 'Faltan las coordenadas de llegada.', 422);
        }

        $geofence = ['shape' => $mission['dest_shape'], 'geometry' => $mission['dest_geometry']];
        if (!GeofenceMath::contains($geofence, $lat, $lon) && !$this->nearGeofence($geofence, $lat, $lon)) {
            return $this->json($response, null, 'Todavía no estás en el destino.', 409);
        }

        $this->missions->setStatus((int) $mission['id'], 'completed');

        return $this->json($response, ['id' => (int) $mission['id'], 'status' => 'completed']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Todo lo que la app necesita saber para operar: jornada, puesto, misiones
     * del día y parámetros de muestreo.
     *
     * @return array<string,mixed>
     */
    private function syncPayload(int $companyId, int $personId): array
    {
        $company = $this->companies->find($companyId);
        $tz = (string) ($company['timezone'] ?? 'America/Argentina/Buenos_Aires');

        $post = $this->posts->currentForPerson($personId, $companyId);
        $today = date('Y-m-d');

        return [
            'server_time' => date('c'),
            'timezone'    => $tz,
            'shifts'      => array_map(static fn (array $s): array => [
                'weekday' => (int) $s['weekday'],
                'from'    => substr((string) $s['start_time'], 0, 5),
                'to'      => substr((string) $s['end_time'], 0, 5),
            ], $this->shifts->activeForPerson($personId, $companyId)),
            'shift_exceptions' => array_map(static fn (array $e): array => [
                'date' => (string) $e['date'],
                'kind' => (string) $e['kind'],
                'from' => $e['start_time'] !== null ? substr((string) $e['start_time'], 0, 5) : null,
                'to'   => $e['end_time'] !== null ? substr((string) $e['end_time'], 0, 5) : null,
            ], $this->shifts->upcomingExceptions($personId, $companyId)),
            'post' => $post === null ? null : [
                'geofence_id' => (int) $post['geofence_id'],
                'name'        => $post['geofence_name'],
                'shape'       => $post['shape'],
                'geometry'    => $this->decodeJson($post['geometry']),
                'grace_min'   => (int) $post['grace_min'],
            ],
            'missions' => array_map(fn (array $m): array => [
                'id'              => (int) $m['id'],
                'status'          => $m['status'],
                'scheduled_start' => $m['scheduled_start'],
                'scheduled_end'   => $m['scheduled_end'],
                'notes'           => $m['notes'],
                'destination'     => [
                    'geofence_id' => (int) $m['dest_geofence_id'],
                    'name'        => $m['dest_name'],
                    'shape'       => $m['dest_shape'],
                    'geometry'    => $this->decodeJson($m['dest_geometry']),
                ],
            ], $this->missions->forPersonBetween(
                $personId,
                $companyId,
                $today . ' 00:00:00',
                $today . ' 23:59:59'
            )),
            'config' => [
                'moving_sample_seconds'  => $this->movingSampleSeconds,
                'stopped_sample_seconds' => $this->stoppedSampleSeconds,
                'max_batch'              => self::MAX_BATCH,
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    private function ownMission(Request $request, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return $this->missions->findForPerson(
            $id,
            (int) $request->getAttribute('person_id'),
            (int) $request->getAttribute('company_id')
        );
    }

    /**
     * Valida y normaliza un punto. Devuelve NULL si no sirve.
     *
     * @param array<string,mixed> $p
     * @return array<string,mixed>|null
     */
    private function normalizePoint(array $p, int $now): ?array
    {
        $lat = $this->floatOrNull($p['lat'] ?? null);
        $lon = $this->floatOrNull($p['lon'] ?? null);
        if ($lat === null || $lon === null || abs($lat) > 90 || abs($lon) > 180) {
            return null;
        }

        $ts = $this->normalizeTs($p['ts'] ?? null, $now);
        if ($ts === null) {
            return null;
        }

        $speed = $this->intOrNull($p['speed'] ?? null);
        $heading = $this->intOrNull($p['heading'] ?? null);

        return [
            'ts'          => $ts,
            'lat'         => round($lat, 7),
            'lon'         => round($lon, 7),
            'speed'       => $speed !== null ? max(0, min(999, $speed)) : null,
            'heading'     => $heading !== null ? (($heading % 360) + 360) % 360 : null,
            'accuracy_m'  => $this->clampOrNull($p['accuracy_m'] ?? null, 0, 65535),
            'battery_pct' => $this->clampOrNull($p['battery_pct'] ?? null, 0, 100),
        ];
    }

    /**
     * Normaliza un timestamp del equipo a 'Y-m-d H:i:s'. Rechaza los que se
     * apartan más de un día del reloj del servidor (equipo con la hora rota).
     */
    private function normalizeTs(mixed $value, int $now): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $t = strtotime(str_replace('T', ' ', trim($value)));
        if ($t === false || abs($t - $now) > self::MAX_CLOCK_DRIFT) {
            return null;
        }

        return date('Y-m-d H:i:s', $t);
    }

    /** Tolerancia alrededor de la geocerca de destino para dar por llegada. */
    private function nearGeofence(array $geofence, float $lat, float $lon): bool
    {
        $geometry = $this->decodeJson($geofence['geometry']);
        if (($geofence['shape'] ?? '') !== 'circle' || !isset($geometry['lat'], $geometry['lon'])) {
            return false;
        }
        $meters = Geo::haversineKm(
            (float) $geometry['lat'],
            (float) $geometry['lon'],
            $lat,
            $lon
        ) * 1000;

        return $meters <= ((float) ($geometry['radius_m'] ?? 0) + self::ARRIVE_RADIUS_M);
    }

    private function lastBattery(array $points): ?int
    {
        for ($i = count($points) - 1; $i >= 0; $i--) {
            if ($points[$i]['battery_pct'] !== null) {
                return (int) $points[$i]['battery_pct'];
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function body(Request $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed)) {
            return $parsed;
        }
        $decoded = json_decode((string) $request->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string,mixed> */
    private function decodeJson(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }
        $d = is_string($json) && $json !== '' ? json_decode($json, true) : null;

        return is_array($d) ? $d : [];
    }

    private function floatOrNull(mixed $v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }

    private function intOrNull(mixed $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }

    private function clampOrNull(mixed $v, int $min, int $max): ?int
    {
        return is_numeric($v) ? max($min, min($max, (int) $v)) : null;
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
