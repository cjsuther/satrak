<?php

declare(strict_types=1);

namespace Satrak\Application\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Satrak\Domain\Repositories\DriverRepository;
use Satrak\Domain\Repositories\VehicleRepository;
use Satrak\Domain\Services\ReportService;
use Slim\Views\Twig;

/**
 * Reportes (§13): por vehículo, por conductor (incluye "no identificado") y de
 * alertas; con filtros por fecha/vehículo/conductor y exportación a CSV.
 */
final class ReportController
{
    private const KINDS = ['vehicle', 'driver', 'alerts'];

    public function __construct(
        private Twig $twig,
        private ReportService $reports,
        private VehicleRepository $vehicles,
        private DriverRepository $drivers,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $q = $request->getQueryParams();
        $kind = in_array($q['r'] ?? '', self::KINDS, true) ? $q['r'] : 'vehicle';
        [$from, $to] = $this->range($q);

        $vehicleId = !empty($q['vehicle_id']) ? (int) $q['vehicle_id'] : null;
        $driverId = !empty($q['driver_id']) ? (int) $q['driver_id'] : null;

        $rows = $this->rowsFor($kind, $companyId, $from, $to, $q, $vehicleId, $driverId);

        return $this->twig->render($response, 'pages/reports/index.twig', [
            'kind'       => $kind,
            'rows'       => $rows,
            'from'       => $from,
            'to'         => $to,
            'vehicle_id' => $vehicleId,
            'driver_id'  => $driverId,
            'type'       => $q['type'] ?? '',
            'severity'   => $q['severity'] ?? '',
            'status'     => $q['status'] ?? '',
            'vehicles'   => $this->vehicles->activeForCompany($companyId),
            'drivers'    => $this->drivers->activeForCompany($companyId),
            'totals'     => $this->totals($kind, $rows),
        ]);
    }

    /** Exporta el reporte vigente a CSV (mismos filtros que la pantalla). */
    public function export(Request $request, Response $response): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $q = $request->getQueryParams();
        $kind = in_array($q['r'] ?? '', self::KINDS, true) ? $q['r'] : 'vehicle';
        [$from, $to] = $this->range($q);
        $vehicleId = !empty($q['vehicle_id']) ? (int) $q['vehicle_id'] : null;
        $driverId = !empty($q['driver_id']) ? (int) $q['driver_id'] : null;

        $rows = $this->rowsFor($kind, $companyId, $from, $to, $q, $vehicleId, $driverId);

        [$headers, $mapper] = $this->csvShape($kind);
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $headers);
        foreach ($rows as $r) {
            fputcsv($fh, $mapper($r));
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        // BOM para que Excel reconozca UTF-8.
        $response->getBody()->write("\xEF\xBB\xBF" . $csv);
        $filename = 'satrak-reporte-' . $kind . '-' . date('Ymd-His') . '.csv';

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-store');
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * @param array<string,mixed> $q
     * @return array<int,array<string,mixed>>
     */
    private function rowsFor(string $kind, int $companyId, string $from, string $to, array $q, ?int $vehicleId, ?int $driverId): array
    {
        return match ($kind) {
            'driver' => $this->reports->byDriver($companyId, $from, $to, $driverId),
            'alerts' => $this->reports->alerts($companyId, $from, $to, $q['type'] ?? null, $q['severity'] ?? null, $q['status'] ?? null),
            default  => $this->reports->byVehicle($companyId, $from, $to, $vehicleId),
        };
    }

    /**
     * Define cabecera y fila CSV por tipo de reporte.
     *
     * @return array{0:string[],1:callable}
     */
    private function csvShape(string $kind): array
    {
        if ($kind === 'alerts') {
            return [
                ['Fecha', 'Tipo', 'Severidad', 'Vehículo', 'Conductor', 'Mensaje', 'Estado'],
                static fn ($r) => [
                    $r['ts'], $r['type'], $r['severity'], $r['plate'] ?? '',
                    $r['driver_name'] ?: 'No identificado', $r['message'],
                    $r['acknowledged_at'] ? 'reconocida' : 'sin reconocer',
                ],
            ];
        }
        if ($kind === 'driver') {
            return [
                ['Conductor', 'Viajes', 'Km', 'Tiempo (min)', 'Vel. máx', 'Vel. prom', 'Excesos'],
                static fn ($r) => [
                    $r['label'], $r['trips'], $r['km'], (int) round($r['duration_sec'] / 60),
                    $r['max_speed'], $r['avg_speed'], $r['speed_alerts'],
                ],
            ];
        }

        return [
            ['Vehículo', 'Viajes', 'Km', 'Tiempo (min)', 'Vel. máx', 'Vel. prom', 'Excesos', 'Geocercas'],
            static fn ($r) => [
                $r['label'], $r['trips'], $r['km'], (int) round($r['duration_sec'] / 60),
                $r['max_speed'], $r['avg_speed'], $r['speed_alerts'], $r['geofence_alerts'],
            ],
        ];
    }

    /**
     * Totales de pie de tabla (no aplica a alertas).
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,int|float>
     */
    private function totals(string $kind, array $rows): array
    {
        if ($kind === 'alerts') {
            return ['count' => count($rows)];
        }
        $t = ['trips' => 0, 'km' => 0.0, 'duration_sec' => 0, 'speed_alerts' => 0];
        foreach ($rows as $r) {
            $t['trips'] += (int) $r['trips'];
            $t['km'] += (float) $r['km'];
            $t['duration_sec'] += (int) $r['duration_sec'];
            $t['speed_alerts'] += (int) $r['speed_alerts'];
        }
        $t['km'] = round($t['km'], 2);

        return $t;
    }

    /**
     * Normaliza el rango. Default: últimos 30 días.
     *
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
}
