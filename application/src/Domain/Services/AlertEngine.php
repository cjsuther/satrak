<?php

declare(strict_types=1);

namespace Satrak\Domain\Services;

use Satrak\Domain\Repositories\AlertRepository;
use Satrak\Domain\Repositories\AlertRuleRepository;
use Satrak\Domain\Repositories\GeofenceRepository;
use Satrak\Domain\Repositories\NotificationRepository;
use Satrak\Domain\Repositories\UserRepository;
use Satrak\Domain\Repositories\VehicleRepository;

/**
 * Motor de alertas (§12). Lo invoca el procesador mientras recorre las posiciones
 * y eventos nuevos de cada dispositivo. Por cada alerta: inserta en `alerts`,
 * crea `notifications` in-app y manda email según el canal de la regla.
 *
 * Mantiene un estado por dispositivo (`alert_state` en `processor_state`) para
 * disparar en el **flanco** del evento y no repetir:
 *   - `speeding[ruleId]`  : si la última posición ya excedía esa regla.
 *   - `inside[]`          : geocercas (por id) que contienen al vehículo ahora.
 *   - `idle_since`        : desde cuándo está detenido con motor encendido.
 *   - `idle_fired`        : si ya se avisó por este episodio de ralentí.
 *
 * Tipos: speed, geofence_enter, geofence_exit, idle (por posición), sos (por
 * evento) y offline (chequeo periódico al cierre del run).
 */
final class AlertEngine
{
    private const SEVERITY = [
        'speed'          => 'warning',
        'geofence_enter' => 'warning',
        'geofence_exit'  => 'warning',
        'idle'           => 'warning',
        'offline'        => 'warning',
        'sos'            => 'critical',
    ];
    private const TITLE = [
        'speed'          => 'Exceso de velocidad',
        'geofence_enter' => 'Entrada a geocerca',
        'geofence_exit'  => 'Salida de geocerca',
        'idle'           => 'Ralentí prolongado',
        'offline'        => 'Dispositivo sin señal',
        'sos'            => 'SOS / botón de pánico',
    ];

    /** @var array<int,array<int,array<string,mixed>>> rules por empresa */
    private array $rulesCache = [];
    /** @var array<int,array<int,array<string,mixed>>> geocercas por empresa */
    private array $geoCache = [];
    /** @var array<int,array<int,array{id:int,name:string,email:string}>> destinatarios por empresa */
    private array $recipientsCache = [];
    /** @var array<int,?string> plate por vehicle_id */
    private array $plateCache = [];

    private int $fired = 0;

    public function __construct(
        private AlertRuleRepository $rules,
        private GeofenceRepository $geofences,
        private AlertRepository $alerts,
        private NotificationRepository $notifications,
        private UserRepository $users,
        private VehicleRepository $vehicles,
        private Mailer $mailer,
        private int $offlineMinutes,
        private int $idleMinutes,
    ) {
    }

    public function firedCount(): int
    {
        return $this->fired;
    }

    /**
     * Evalúa una posición contra las reglas speed / geofence / idle.
     * Muta `$state` (alert_state del dispositivo) por referencia.
     *
     * @param array{company_id:int,device_id:int,vehicle_id:?int,driver_id:?int} $ctx
     * @param array<string,mixed> $pos  posición (lat, lon, speed, ignition, ts)
     * @param array<string,mixed> $state
     */
    public function onPosition(array $ctx, array $pos, array &$state): void
    {
        $companyId = $ctx['company_id'];
        $rules = $this->rulesFor($companyId);
        if ($rules === []) {
            return;
        }

        $lat = (float) $pos['lat'];
        $lon = (float) $pos['lon'];
        $speed = (int) ($pos['speed'] ?? 0);
        $ign = $pos['ignition'] !== null ? (int) $pos['ignition'] : null;
        $ts = (string) $pos['ts'];

        $state['speeding'] = $state['speeding'] ?? [];
        $state['inside'] = $state['inside'] ?? [];

        // --- speed: dispara al cruzar el umbral hacia arriba -----------------
        foreach ($rules as $rule) {
            if ($rule['type'] !== 'speed') {
                continue;
            }
            $max = (int) ($this->params($rule)['max_kmh'] ?? 0);
            if ($max <= 0) {
                continue;
            }
            $key = (string) $rule['id'];
            $was = !empty($state['speeding'][$key]);
            $now = $speed > $max;
            if ($now && !$was) {
                $this->fire($companyId, $rule, [
                    'device_id'  => $ctx['device_id'],
                    'vehicle_id' => $ctx['vehicle_id'],
                    'driver_id'  => $ctx['driver_id'],
                    'type'       => 'speed',
                    'lat'        => $lat,
                    'lon'        => $lon,
                    'ts'         => $ts,
                ], "Exceso de velocidad: {$speed} km/h (límite {$max})");
            }
            $state['speeding'][$key] = $now;
        }

        // --- geofence enter/exit: transición de contención por geocerca ------
        $geoRules = array_filter($rules, static fn ($r) => in_array($r['type'], ['geofence_enter', 'geofence_exit'], true));
        if ($geoRules !== []) {
            $inside = array_map('intval', $state['inside']);
            $newInside = [];
            $entered = [];
            $exited = [];

            // Containment actual por cada geocerca referenciada que aplique al vehículo.
            $neededGids = array_unique(array_map(fn ($r) => (int) ($this->params($r)['geofence_id'] ?? 0), $geoRules));
            foreach ($neededGids as $gid) {
                if ($gid <= 0) {
                    continue;
                }
                $geo = $this->geofence($companyId, $gid);
                $applies = $geo !== null && $this->geofenceAppliesTo($geo, $ctx['vehicle_id']);
                $cur = $applies && GeofenceMath::contains($geo, $lat, $lon);
                $was = in_array($gid, $inside, true);
                if ($cur) {
                    $newInside[] = $gid;
                }
                if ($cur && !$was) {
                    $entered[$gid] = true;
                }
                if (!$cur && $was) {
                    $exited[$gid] = true;
                }
            }

            foreach ($geoRules as $rule) {
                $gid = (int) ($this->params($rule)['geofence_id'] ?? 0);
                $geo = $this->geofence($companyId, $gid);
                $name = $geo['name'] ?? ('geocerca #' . $gid);
                if ($rule['type'] === 'geofence_enter' && !empty($entered[$gid])) {
                    $this->fire($companyId, $rule, [
                        'device_id' => $ctx['device_id'], 'vehicle_id' => $ctx['vehicle_id'],
                        'driver_id' => $ctx['driver_id'], 'type' => 'geofence_enter',
                        'lat' => $lat, 'lon' => $lon, 'ts' => $ts,
                    ], "Entró a la geocerca «{$name}»");
                } elseif ($rule['type'] === 'geofence_exit' && !empty($exited[$gid])) {
                    $this->fire($companyId, $rule, [
                        'device_id' => $ctx['device_id'], 'vehicle_id' => $ctx['vehicle_id'],
                        'driver_id' => $ctx['driver_id'], 'type' => 'geofence_exit',
                        'lat' => $lat, 'lon' => $lon, 'ts' => $ts,
                    ], "Salió de la geocerca «{$name}»");
                }
            }
            $state['inside'] = array_values(array_unique($newInside));
        }

        // --- idle: detenido con motor encendido más de X minutos -------------
        $idleRule = $this->firstOfType($rules, 'idle');
        if ($idleRule !== null) {
            $threshold = (int) ($this->params($idleRule)['minutes'] ?? $this->idleMinutes) * 60;
            $stopped = $ign === 1 && $speed === 0;
            if ($stopped) {
                if (empty($state['idle_since'])) {
                    $state['idle_since'] = $ts;
                    $state['idle_fired'] = false;
                } elseif (empty($state['idle_fired'])
                    && (strtotime($ts) - strtotime((string) $state['idle_since'])) >= $threshold) {
                    $mins = (int) round((strtotime($ts) - strtotime((string) $state['idle_since'])) / 60);
                    $this->fire($companyId, $idleRule, [
                        'device_id' => $ctx['device_id'], 'vehicle_id' => $ctx['vehicle_id'],
                        'driver_id' => $ctx['driver_id'], 'type' => 'idle',
                        'lat' => $lat, 'lon' => $lon, 'ts' => $ts,
                    ], "Ralentí: {$mins} min detenido con motor encendido");
                    $state['idle_fired'] = true;
                }
            } else {
                $state['idle_since'] = null;
                $state['idle_fired'] = false;
            }
        }
    }

    /**
     * Evalúa un evento del dispositivo. Sólo SOS en esta fase.
     *
     * @param array{company_id:int,device_id:int,vehicle_id:?int,driver_id:?int} $ctx
     * @param array<string,mixed> $event
     */
    public function onEvent(array $ctx, array $event): void
    {
        if (($event['event_type'] ?? '') !== 'sos') {
            return;
        }
        $rule = $this->firstOfType($this->rulesFor($ctx['company_id']), 'sos');
        if ($rule === null) {
            return;
        }
        $this->fire($ctx['company_id'], $rule, [
            'device_id' => $ctx['device_id'], 'vehicle_id' => $ctx['vehicle_id'],
            'driver_id' => $ctx['driver_id'], 'type' => 'sos',
            'lat' => null, 'lon' => null, 'ts' => (string) $event['ts'],
        ], 'SOS recibido del dispositivo');
    }

    /**
     * Chequeo de offline para un dispositivo (al cierre del run). Se dispara una
     * sola vez por episodio: no repite si ya hay una alerta offline desde la
     * última señal.
     *
     * @param array{id:int,company_id:int,last_seen_at:?string,vehicle_id?:?int} $device
     */
    public function checkOffline(array $device): void
    {
        $rule = $this->firstOfType($this->rulesFor((int) $device['company_id']), 'offline');
        if ($rule === null) {
            return;
        }
        $minutes = (int) ($this->params($rule)['minutes'] ?? $this->offlineMinutes);
        $lastSeen = $device['last_seen_at'] ?? null;
        if ($lastSeen === null) {
            return; // nunca reportó: no es "se cayó".
        }
        if (strtotime((string) $lastSeen) >= time() - $minutes * 60) {
            return; // sigue online.
        }
        // De-dup: ¿ya avisamos desde la última señal?
        if ($this->alerts->existsSince((int) $device['id'], 'offline', (string) $lastSeen)) {
            return;
        }
        $this->fire((int) $device['company_id'], $rule, [
            'device_id'  => (int) $device['id'],
            'vehicle_id' => $device['vehicle_id'] ?? null,
            'driver_id'  => null,
            'type'       => 'offline',
            'lat'        => null,
            'lon'        => null,
            'ts'         => date('Y-m-d H:i:s'),
        ], "Sin señal hace más de {$minutes} min (última: {$lastSeen})");
    }

    // -- Disparo común --------------------------------------------------------

    /**
     * @param array<string,mixed> $rule
     * @param array{device_id:int,vehicle_id:?int,driver_id:?int,type:string,lat:?float,lon:?float,ts:string} $info
     */
    private function fire(int $companyId, array $rule, array $info, string $message): void
    {
        $type = $info['type'];
        $severity = self::SEVERITY[$type] ?? 'warning';
        $plate = $this->plate($info['vehicle_id']);
        $fullMsg = ($plate !== null ? $plate . ' · ' : '') . $message;

        $alertId = $this->alerts->create($companyId, [
            'rule_id'    => (int) $rule['id'],
            'device_id'  => $info['device_id'],
            'vehicle_id' => $info['vehicle_id'],
            'driver_id'  => $info['driver_id'],
            'type'       => $type,
            'severity'   => $severity,
            'message'    => mb_substr($fullMsg, 0, 255),
            'lat'        => $info['lat'],
            'lon'        => $info['lon'],
            'ts'         => $info['ts'],
        ]);
        $this->fired++;

        $channels = $this->decode($rule['channels'] ?? null) ?: ['inapp'];
        $recipients = $this->recipientsFor($companyId);
        $title = self::TITLE[$type] ?? 'Alerta';

        if (in_array('inapp', $channels, true)) {
            $this->notifications->createForUsers(
                $companyId,
                array_map(static fn ($r) => $r['id'], $recipients),
                $alertId,
                $title,
                $fullMsg
            );
        }

        if (in_array('email', $channels, true)) {
            $emails = array_map(static fn ($r) => ['email' => $r['email'], 'name' => $r['name']], $recipients);
            foreach ($this->decode($rule['recipients'] ?? null) as $extra) {
                $emails[] = ['email' => (string) $extra, 'name' => ''];
            }
            $html = '<p><strong>' . htmlspecialchars($title) . '</strong></p><p>'
                . htmlspecialchars($fullMsg) . '</p><p>' . htmlspecialchars($info['ts']) . '</p>';
            foreach ($emails as $to) {
                if ($to['email'] !== '') {
                    $this->mailer->send($to['email'], $to['name'], "[Satrak] {$title}", $html, $fullMsg);
                }
            }
        }
    }

    // -- Cachés / helpers -----------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    private function rulesFor(int $companyId): array
    {
        return $this->rulesCache[$companyId] ??= $this->rules->activeForCompany($companyId);
    }

    /** @return array<int,array{id:int,name:string,email:string}> */
    private function recipientsFor(int $companyId): array
    {
        return $this->recipientsCache[$companyId] ??= $this->users->alertRecipients($companyId);
    }

    /** @return array<string,mixed>|null */
    private function geofence(int $companyId, int $gid): ?array
    {
        if (!isset($this->geoCache[$companyId])) {
            $this->geoCache[$companyId] = [];
            foreach ($this->geofences->activeWithVehicles($companyId) as $g) {
                $this->geoCache[$companyId][(int) $g['id']] = $g;
            }
        }

        return $this->geoCache[$companyId][$gid] ?? null;
    }

    /** @param array{vehicle_ids:int[]} $geofence */
    private function geofenceAppliesTo(array $geofence, ?int $vehicleId): bool
    {
        $ids = $geofence['vehicle_ids'] ?? [];
        if ($ids === []) {
            return true; // sin lista explícita: aplica a todos.
        }

        return $vehicleId !== null && in_array($vehicleId, $ids, true);
    }

    private function plate(?int $vehicleId): ?string
    {
        if ($vehicleId === null) {
            return null;
        }
        if (!array_key_exists($vehicleId, $this->plateCache)) {
            $v = $this->vehicles->findById($vehicleId);
            $this->plateCache[$vehicleId] = $v !== null ? (string) $v['plate'] : null;
        }

        return $this->plateCache[$vehicleId];
    }

    /** @param array<int,array<string,mixed>> $rules @return array<string,mixed>|null */
    private function firstOfType(array $rules, string $type): ?array
    {
        foreach ($rules as $r) {
            if ($r['type'] === $type) {
                return $r;
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function params(array $rule): array
    {
        return $this->decode($rule['params'] ?? null);
    }

    /** @return array<int|string,mixed> */
    private function decode(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }
        if (!is_string($json) || $json === '') {
            return [];
        }
        $d = json_decode($json, true);

        return is_array($d) ? $d : [];
    }
}
