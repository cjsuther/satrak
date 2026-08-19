<?php

declare(strict_types=1);

namespace Satrak\Domain\Services;

use Satrak\Domain\Repositories\AlertRepository;
use Satrak\Domain\Repositories\AlertRuleRepository;
use Satrak\Domain\Repositories\GeofenceRepository;
use Satrak\Domain\Repositories\CompanyRepository;
use Satrak\Domain\Repositories\NotificationRepository;
use Satrak\Domain\Repositories\PersonRepository;
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
        // Personas
        'panic'          => 'critical',
        'no_movement'    => 'critical',
        'off_post'       => 'warning',
        'mission_late'   => 'warning',
        'mission_missed' => 'warning',
        'low_battery'    => 'warning',
        'app_offline'    => 'warning',
        'out_of_shift'   => 'info',
    ];
    private const TITLE = [
        'speed'          => 'Exceso de velocidad',
        'geofence_enter' => 'Entrada a geocerca',
        'geofence_exit'  => 'Salida de geocerca',
        'idle'           => 'Ralentí prolongado',
        'offline'        => 'Dispositivo sin señal',
        'sos'            => 'SOS / botón de pánico',
        'panic'          => 'BOTÓN DE PÁNICO',
        'no_movement'    => 'Persona sin movimiento',
        'off_post'       => 'Fuera de puesto',
        'mission_late'   => 'Misión demorada',
        'mission_missed' => 'Misión no iniciada',
        'low_battery'    => 'Batería baja',
        'app_offline'    => 'App sin reportar',
        'out_of_shift'   => 'Posición fuera de turno',
    ];

    /**
     * Alertas que además del canal configurado se mandan SIEMPRE al email de
     * guardia de la empresa (`companies.emergency_email`). Es lo pactado para
     * emergencias: no dependen de que exista una regla con destinatarios.
     */
    private const EMERGENCY = ['panic', 'sos', 'no_movement'];

    /** @var array<int,array<int,array<string,mixed>>> rules por empresa */
    private array $rulesCache = [];
    /** @var array<int,array<int,array<string,mixed>>> geocercas por empresa */
    private array $geoCache = [];
    /** @var array<int,array<int,array{id:int,name:string,email:string}>> destinatarios por empresa */
    private array $recipientsCache = [];
    /** @var array<int,?string> plate por vehicle_id */
    private array $plateCache = [];
    /** @var array<int,?string> nombre por person_id */
    private array $personNameCache = [];
    /** @var array<int,?string> email de guardia por empresa */
    private array $emergencyEmailCache = [];

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
        private ?PersonRepository $people = null,
        private ?CompanyRepository $companies = null,
        private int $noMovementMinutes = 15,
        private int $minStepMeters = 25,
        private int $appOfflineMinutes = 15,
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
                $applies = $geo !== null && $this->geofenceAppliesTo($geo, 'vehicle', $ctx['vehicle_id']);
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
        $type = (string) ($event['event_type'] ?? '');
        $raw = $this->decode($event['raw'] ?? null);

        $messages = [
            'sos'         => 'SOS recibido del dispositivo',
            'panic'       => 'BOTÓN DE PÁNICO accionado desde la app',
            'low_battery' => 'Batería baja en el equipo',
        ];
        if (!isset($messages[$type])) {
            return;
        }

        // El pánico y el SOS no necesitan una regla configurada para avisar: son
        // emergencias. Si no hay regla, se dispara igual con rule_id NULL.
        $rule = $this->firstOfType($this->rulesFor($ctx['company_id']), $type)
            ?? (in_array($type, ['panic', 'sos'], true) ? ['id' => null, 'channels' => '["inapp","email"]'] : null);
        if ($rule === null) {
            return;
        }

        $message = $messages[$type];
        if ($type === 'low_battery' && isset($raw['battery_pct'])) {
            $message .= ' (' . (int) $raw['battery_pct'] . '%)';
        }

        $this->fire($ctx['company_id'], $rule, [
            'device_id'  => $ctx['device_id'],
            'vehicle_id' => $ctx['vehicle_id'] ?? null,
            'driver_id'  => $ctx['driver_id'] ?? null,
            'person_id'  => $ctx['person_id'] ?? null,
            'type'       => $type,
            'lat'        => isset($raw['lat']) ? (float) $raw['lat'] : null,
            'lon'        => isset($raw['lon']) ? (float) $raw['lon'] : null,
            'ts'         => (string) $event['ts'],
        ], $message);
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


    // =========================================================================
    // Personas
    // =========================================================================

    /**
     * Evalúa una posición de una PERSONA. No corren `speed` ni `idle`: dependen
     * de la ignición y del vehículo.
     *
     * Tipos: `geofence_enter` / `geofence_exit` (por alcance de persona),
     * `off_post` (en jornada, con puesto y sin misión, fuera de la geocerca más
     * de la tolerancia) y `no_movement` (sin desplazarse: posible hombre caído).
     *
     * @param array{company_id:int,device_id:int,person_id:int,post:?array<string,mixed>,has_mission:bool} $ctx
     * @param array<string,mixed> $pos
     * @param array<string,mixed> $state
     */
    public function onPersonPosition(array $ctx, array $pos, array &$state): void
    {
        $companyId = $ctx['company_id'];
        $personId = $ctx['person_id'];
        $lat = (float) $pos['lat'];
        $lon = (float) $pos['lon'];
        $ts = (string) $pos['ts'];

        $rules = $this->rulesFor($companyId);

        // --- geocercas: mismas reglas, alcance de persona --------------------
        $geoRules = array_filter(
            $rules,
            static fn ($r) => in_array($r['type'], ['geofence_enter', 'geofence_exit'], true)
        );
        if ($geoRules !== []) {
            $inside = array_map('intval', $state['inside'] ?? []);
            $newInside = [];
            $entered = [];
            $exited = [];

            foreach (array_unique(array_map(fn ($r) => (int) ($this->params($r)['geofence_id'] ?? 0), $geoRules)) as $gid) {
                if ($gid <= 0) {
                    continue;
                }
                $geo = $this->geofence($companyId, $gid);
                $applies = $geo !== null && $this->geofenceAppliesTo($geo, 'person', $personId);
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
                $name = $this->geofence($companyId, $gid)['name'] ?? ('geocerca #' . $gid);
                if ($rule['type'] === 'geofence_enter' && !empty($entered[$gid])) {
                    $this->firePerson($companyId, $rule, $ctx, 'geofence_enter', $lat, $lon, $ts,
                        "Entró a la geocerca «{$name}»");
                } elseif ($rule['type'] === 'geofence_exit' && !empty($exited[$gid])) {
                    $this->firePerson($companyId, $rule, $ctx, 'geofence_exit', $lat, $lon, $ts,
                        "Salió de la geocerca «{$name}»");
                }
            }
            $state['inside'] = array_values(array_unique($newInside));
        }

        // --- fuera de puesto -------------------------------------------------
        // Una misión vigente autoriza a estar afuera: es todo el sentido de las
        // misiones, así que ni siquiera se cuenta el tiempo.
        $postRule = $this->firstOfType($rules, 'off_post');
        $post = $ctx['post'] ?? null;
        if ($postRule !== null && $post !== null && !$ctx['has_mission']) {
            $insidePost = GeofenceMath::contains(
                ['shape' => $post['shape'], 'geometry' => $post['geometry']],
                $lat,
                $lon
            );
            if ($insidePost) {
                $state['off_post_since'] = null;
                $state['off_post_fired'] = false;
            } else {
                $grace = (int) ($post['grace_min'] ?? 10) * 60;
                if (empty($state['off_post_since'])) {
                    $state['off_post_since'] = $ts;
                    $state['off_post_fired'] = false;
                } elseif (empty($state['off_post_fired'])
                    && (strtotime($ts) - strtotime((string) $state['off_post_since'])) >= $grace) {
                    $mins = (int) round((strtotime($ts) - strtotime((string) $state['off_post_since'])) / 60);
                    $name = $post['geofence_name'] ?? 'su puesto';
                    $this->firePerson($companyId, $postRule, $ctx, 'off_post', $lat, $lon, $ts,
                        "Fuera de «{$name}» hace {$mins} min, sin misión asignada");
                    $state['off_post_fired'] = true;
                }
            }
        } else {
            $state['off_post_since'] = null;
            $state['off_post_fired'] = false;
        }

        // --- sin movimiento (hombre caído) -----------------------------------
        $stillRule = $this->firstOfType($rules, 'no_movement');
        if ($stillRule !== null) {
            $threshold = (int) ($this->params($stillRule)['minutes'] ?? $this->noMovementMinutes) * 60;
            $refLat = $state['still_lat'] ?? null;
            $refLon = $state['still_lon'] ?? null;

            $moved = $refLat === null
                || Geo::haversineKm((float) $refLat, (float) $refLon, $lat, $lon) * 1000 >= $this->minStepMeters;

            if ($moved) {
                $state['still_lat'] = $lat;
                $state['still_lon'] = $lon;
                $state['still_since'] = $ts;
                $state['no_move_fired'] = false;
            } elseif (empty($state['no_move_fired'])
                && (strtotime($ts) - strtotime((string) ($state['still_since'] ?? $ts))) >= $threshold) {
                $mins = (int) round((strtotime($ts) - strtotime((string) $state['still_since'])) / 60);
                $this->firePerson($companyId, $stillRule, $ctx, 'no_movement', $lat, $lon, $ts,
                    "Sin moverse hace {$mins} min durante la jornada");
                $state['no_move_fired'] = true;
            }
        }
    }

    /**
     * La app dejó de reportar durante la jornada. Se chequea al cierre del run,
     * como el offline de flota, y no se repite hasta que vuelva a reportar.
     *
     * @param array{company_id:int,device_id:int,person_id:int,last_seen_at:?string} $ctx
     */
    public function checkAppOffline(array $ctx): void
    {
        $rule = $this->firstOfType($this->rulesFor($ctx['company_id']), 'app_offline');
        if ($rule === null) {
            return;
        }
        $minutes = (int) ($this->params($rule)['minutes'] ?? $this->appOfflineMinutes);
        $lastSeen = $ctx['last_seen_at'] ?? null;
        if ($lastSeen === null || strtotime((string) $lastSeen) >= time() - $minutes * 60) {
            return;
        }
        if ($this->alerts->existsSince($ctx['device_id'], 'app_offline', (string) $lastSeen)) {
            return;
        }

        $this->firePerson(
            $ctx['company_id'],
            $rule,
            $ctx,
            'app_offline',
            null,
            null,
            date('Y-m-d H:i:s'),
            "La app no reporta hace más de {$minutes} min (última: {$lastSeen})"
        );
    }

    /**
     * Misión vencida: `pending` que nunca arrancó (`mission_missed`) o
     * `in_progress` que no llegó a destino (`mission_late`). En los dos casos la
     * misión queda cerrada como `missed`: pasada la ventana ya no se cumplió.
     *
     * @param array{id:int,company_id:int,person_id:int,status:string,scheduled_end:string} $mission
     */
    public function checkMission(array $mission): string
    {
        $type = $mission['status'] === 'pending' ? 'mission_missed' : 'mission_late';
        $rule = $this->firstOfType($this->rulesFor((int) $mission['company_id']), $type);

        if ($rule !== null) {
            $message = $type === 'mission_missed'
                ? "No inició la misión #{$mission['id']} (vencía {$mission['scheduled_end']})"
                : "No llegó a destino en la misión #{$mission['id']} (vencía {$mission['scheduled_end']})";

            $this->firePerson(
                (int) $mission['company_id'],
                $rule,
                ['company_id' => (int) $mission['company_id'], 'device_id' => null,
                 'person_id' => (int) $mission['person_id']],
                $type,
                null,
                null,
                date('Y-m-d H:i:s'),
                $message
            );
        }

        return 'missed';
    }

    /**
     * Atajo de {@see fire()} para alertas de persona (sin vehículo ni conductor).
     *
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $ctx
     */
    private function firePerson(
        int $companyId,
        array $rule,
        array $ctx,
        string $type,
        ?float $lat,
        ?float $lon,
        string $ts,
        string $message
    ): void {
        $this->fire($companyId, $rule, [
            'device_id'  => $ctx['device_id'] ?? null,
            'vehicle_id' => null,
            'driver_id'  => null,
            'person_id'  => $ctx['person_id'],
            'type'       => $type,
            'lat'        => $lat,
            'lon'        => $lon,
            'ts'         => $ts,
        ], $message);
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

        // Etiqueta: nombre de la persona si la alerta es de personal, patente si
        // es de flota. Sin esto una alerta de persona salía sin identificar.
        $personId = $info['person_id'] ?? null;
        $label = $personId !== null
            ? $this->personName((int) $personId)
            : $this->plate($info['vehicle_id']);
        $fullMsg = ($label !== null ? $label . ' · ' : '') . $message;

        $alertId = $this->alerts->create($companyId, [
            // Puede no haber regla: pánico y SOS avisan aunque nadie las configure.
            'rule_id'    => isset($rule['id']) && $rule['id'] !== null ? (int) $rule['id'] : null,
            'device_id'  => $info['device_id'],
            'vehicle_id' => $info['vehicle_id'],
            'driver_id'  => $info['driver_id'],
            'person_id'  => $personId,
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

        // Emergencias: el email de guardia entra siempre, aunque la regla no
        // tenga el canal email ni destinatarios extra.
        $emergencyEmail = in_array($type, self::EMERGENCY, true)
            ? $this->emergencyEmail($companyId)
            : null;

        if (in_array('email', $channels, true) || $emergencyEmail !== null) {
            $emails = in_array('email', $channels, true)
                ? array_map(static fn ($r) => ['email' => $r['email'], 'name' => $r['name']], $recipients)
                : [];
            if (in_array('email', $channels, true)) {
                foreach ($this->decode($rule['recipients'] ?? null) as $extra) {
                    $emails[] = ['email' => (string) $extra, 'name' => ''];
                }
            }
            if ($emergencyEmail !== null) {
                $emails[] = ['email' => $emergencyEmail, 'name' => 'Guardia'];
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
            foreach ($this->geofences->activeWithTargets($companyId) as $g) {
                $this->geoCache[$companyId][(int) $g['id']] = $g;
            }
        }

        return $this->geoCache[$companyId][$gid] ?? null;
    }

    /**
     * Alcance de la geocerca, POR TIPO: sin targets de un tipo, aplica a todos
     * los de ese tipo. Así una geocerca de flota no alcanza al personal.
     *
     * @param array<string,mixed> $geofence
     */
    private function geofenceAppliesTo(array $geofence, string $type, ?int $targetId): bool
    {
        $ids = ($type === 'person' ? $geofence['person_ids'] : $geofence['vehicle_ids']) ?? [];
        if ($ids === []) {
            return true;
        }

        return $targetId !== null && in_array($targetId, $ids, true);
    }

    private function personName(?int $personId): ?string
    {
        if ($personId === null || $this->people === null) {
            return null;
        }
        if (!array_key_exists($personId, $this->personNameCache)) {
            $p = $this->people->findById($personId);
            $this->personNameCache[$personId] = $p !== null
                ? trim($p['first_name'] . ' ' . $p['last_name'])
                : null;
        }

        return $this->personNameCache[$personId];
    }

    /** Email de guardia de la empresa (destino garantizado de las emergencias). */
    private function emergencyEmail(int $companyId): ?string
    {
        if ($this->companies === null) {
            return null;
        }
        if (!array_key_exists($companyId, $this->emergencyEmailCache)) {
            $c = $this->companies->find($companyId);
            $mail = trim((string) ($c['emergency_email'] ?? ''));
            $this->emergencyEmailCache[$companyId] = $mail !== '' ? $mail : null;
        }

        return $this->emergencyEmailCache[$companyId];
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
