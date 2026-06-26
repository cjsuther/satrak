<?php

declare(strict_types=1);

namespace Satrak\Application\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Satrak\Domain\Repositories\DeviceRepository;
use Satrak\Domain\Repositories\MonitoringRepository;
use Slim\Views\Twig;

/**
 * Monitoreo: mapa en vivo (§10) e historial / reproducción (§11).
 *
 * Las páginas renderizan el contenedor del mapa; el JS (`map.js`) consume los
 * endpoints JSON de acá por polling (vivo) o a demanda (historial). Todo
 * scopeado a la empresa en contexto y protegido por `monitoring.view`.
 */
final class MapController
{
    public function __construct(
        private Twig $twig,
        private MonitoringRepository $monitoring,
        private DeviceRepository $devices,
        private int $offlineMinutes,
        private int $livePollSeconds,
        private int $movingSpeedKmh = 5,
    ) {
    }

    // -- Páginas --------------------------------------------------------------

    public function live(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'pages/monitoring/live.twig', [
            'poll_seconds' => $this->livePollSeconds,
        ]);
    }

    public function history(Request $request, Response $response): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $q = $request->getQueryParams();

        $deviceList = $this->monitoring->devicesForSelect($companyId);

        $deviceId = isset($q['device_id']) ? (int) $q['device_id'] : 0;
        // Rango por defecto: últimas 24 horas.
        [$from, $to] = $this->normalizeRange($q['from'] ?? null, $q['to'] ?? null);

        $trips = [];
        $valid = false;
        if ($deviceId > 0 && $this->devices->findScoped($deviceId, $companyId) !== null) {
            $valid = true;
            $trips = $this->monitoring->tripsInRange($deviceId, $companyId, $from, $to);
        }

        return $this->twig->render($response, 'pages/monitoring/history.twig', [
            'devices'    => $deviceList,
            'device_id'  => $deviceId,
            'has_device' => $valid,
            'from'       => $from,
            'to'         => $to,
            'trips'      => $trips,
        ]);
    }

    // -- Endpoints JSON -------------------------------------------------------

    public function livePositions(Request $request, Response $response): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $rows = $this->monitoring->livePositions($companyId);

        $units = [];
        foreach ($rows as $r) {
            if ($r['lat'] === null || $r['lon'] === null) {
                continue; // todavía sin señal: no se puede ubicar en el mapa.
            }
            $units[] = [
                'device_id' => (int) $r['device_id'],
                'plate'     => $r['plate'],
                'label'     => $r['label'] ?? $r['imei'],
                'lat'       => (float) $r['lat'],
                'lon'       => (float) $r['lon'],
                'speed'     => (int) ($r['speed'] ?? 0),
                'heading'   => (int) ($r['heading'] ?? 0),
                'ignition'  => $r['ignition'] !== null ? (int) $r['ignition'] : null,
                'driver'    => $this->driverName($r),
                'ts'        => $r['ts'],
                'state'     => $this->deviceState($r),
            ];
        }

        return $this->json($response, ['units' => $units, 'server_time' => date('c')]);
    }

    public function track(Request $request, Response $response, array $args): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $deviceId = (int) ($args['id'] ?? 0);

        if ($deviceId <= 0 || $this->devices->findScoped($deviceId, $companyId) === null) {
            return $this->json($response, null, 'Dispositivo no encontrado', 404);
        }

        $q = $request->getQueryParams();
        [$from, $to] = $this->normalizeRange($q['from'] ?? null, $q['to'] ?? null);

        $points = $this->monitoring->track($deviceId, $companyId, $from, $to);
        $out = array_map(static fn ($p) => [
            'ts'    => $p['ts'],
            'lat'   => (float) $p['lat'],
            'lon'   => (float) $p['lon'],
            'speed' => (int) ($p['speed'] ?? 0),
            'ign'   => $p['ignition'] !== null ? (int) $p['ignition'] : null,
        ], $points);

        return $this->json($response, ['device_id' => $deviceId, 'from' => $from, 'to' => $to, 'points' => $out]);
    }

    // -- Helpers --------------------------------------------------------------

    /** Estado del dispositivo para el color del marcador (§10). */
    private function deviceState(array $r): string
    {
        $lastSeen = $r['last_seen_at'] ?? null;
        if ($lastSeen === null || strtotime((string) $lastSeen) < time() - $this->offlineMinutes * 60) {
            return 'offline';
        }
        // 'alerta' se incorpora en la Fase 6 (motor de alertas).
        $moving = (int) ($r['ignition'] ?? 0) === 1 && (int) ($r['speed'] ?? 0) >= $this->movingSpeedKmh;

        return $moving ? 'movimiento' : 'detenido';
    }

    private function driverName(array $r): ?string
    {
        if ($r['current_driver_id'] === null) {
            return null; // no identificado
        }

        return trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: null;
    }

    /**
     * Normaliza el rango temporal. Acepta 'YYYY-MM-DD HH:MM:SS' o 'YYYY-MM-DDTHH:MM'
     * (input datetime-local). Default: últimas 24 horas. Garantiza from <= to.
     *
     * @return array{0:string,1:string}
     */
    private function normalizeRange(?string $from, ?string $to): array
    {
        $toTs = $to !== null && $to !== '' ? strtotime(str_replace('T', ' ', $to)) : false;
        $fromTs = $from !== null && $from !== '' ? strtotime(str_replace('T', ' ', $from)) : false;

        $toTs = $toTs !== false ? $toTs : time();
        $fromTs = $fromTs !== false ? $fromTs : $toTs - 24 * 3600;

        if ($fromTs > $toTs) {
            [$fromTs, $toTs] = [$toTs, $fromTs];
        }

        return [date('Y-m-d H:i:s', $fromTs), date('Y-m-d H:i:s', $toTs)];
    }

    /** Respuesta JSON consistente { ok, data, error } (§15). */
    private function json(Response $response, mixed $data, ?string $error = null, int $status = 200): Response
    {
        $payload = ['ok' => $error === null, 'data' => $data, 'error' => $error];
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withStatus($status);
    }
}
