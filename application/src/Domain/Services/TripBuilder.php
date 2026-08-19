<?php

declare(strict_types=1);

namespace Satrak\Domain\Services;

use Satrak\Domain\Repositories\AssignmentRepository;
use Satrak\Domain\Repositories\DevicePersonAssignmentRepository;
use Satrak\Domain\Repositories\DeviceEventRepository;
use Satrak\Domain\Repositories\MissionRepository;
use Satrak\Domain\Repositories\PersonPostRepository;
use Satrak\Domain\Repositories\DeviceRepository;
use Satrak\Domain\Repositories\PositionRepository;
use Satrak\Domain\Repositories\ProcessorStateRepository;
use Satrak\Domain\Repositories\TripRepository;

/**
 * Construye los viajes (`trips`) de un dispositivo a partir de sus posiciones y
 * eventos nuevos, atribuyendo cada posición a un conductor (vía {@see PinResolver}).
 *
 * Reglas (spec §8 y §12):
 *  - Mantiene el **PIN vigente** por dispositivo: lo fija un evento `pin_set` o un
 *    `pin_code` explícito en una posición, y lo limpia `pin_cleared`/`ignition_off`.
 *  - Un viaje se **abre** con la primera posición en movimiento y se **cierra** al
 *    detenerse el tiempo configurado (`trip_stop_minutes`), al `ignition_off`, o al
 *    **cambiar el conductor** (un cambio de PIN parte el viaje en dos).
 *  - Es **idempotente**: arranca desde el cursor guardado en `processor_state` y
 *    sólo procesa posiciones/eventos nuevos.
 *
 * Procesa cada dispositivo en su propia transacción para que una falla aislada no
 * deje el estado global a medias.
 */
final class TripBuilder
{
    public function __construct(
        private PositionRepository $positions,
        private DeviceEventRepository $events,
        private TripRepository $trips,
        private ProcessorStateRepository $state,
        private DeviceRepository $devices,
        private AssignmentRepository $assignments,
        private PinResolver $resolver,
        private int $tripStopSeconds,
        private ?AlertEngine $alerts = null,
        // --- Sólo para dispositivos de persona (kind='person') ---------------
        private ?DevicePersonAssignmentRepository $personAssignments = null,
        private ?PersonPostRepository $posts = null,
        private ?MissionRepository $missions = null,
        private int $personStopSeconds = 600,
        private int $walkSpeedKmh = 2,
        private int $minStepMeters = 25,
        private int $maxAccuracyMeters = 100,
    ) {
    }

    /**
     * Procesa un dispositivo. Devuelve contadores para el resumen de la corrida.
     *
     * @param array{id:int,company_id:int,has_pin:int|bool} $device
     * @return array{positions:int,events:int,trips_opened:int,trips_closed:int}
     */
    public function processDevice(array $device): array
    {
        $deviceId  = (int) $device['id'];
        $companyId = (int) $device['company_id'];
        $hasPin    = (bool) $device['has_pin'];
        $isPerson  = ($device['kind'] ?? 'vehicle') === 'person';

        // Una persona no se atribuye por PIN: el equipo es suyo. Y su recorrido
        // se corta con un umbral más largo (estar parada trabajando no lo termina).
        $personId = $isPerson && $this->personAssignments !== null
            ? $this->personAssignments->activePersonId($deviceId, $companyId)
            : null;
        $stopSeconds = $isPerson ? $this->personStopSeconds : $this->tripStopSeconds;

        $st = $this->state->get($deviceId, $companyId);

        $newPositions = $this->positions->newSince($deviceId, $st['last_position_id']);
        $newEvents    = $this->events->unprocessed($deviceId);

        // Sin novedades: igual intentamos cerrar un viaje que haya quedado abierto
        // y ya esté "viejo" (caso histórico), sin tocar nada más.
        $stats = ['positions' => 0, 'events' => 0, 'trips_opened' => 0, 'trips_closed' => 0];

        $vehicleId = $this->assignments->activeVehicleId($deviceId, $companyId);

        // Estado vigente que arrastramos a lo largo del timeline.
        $currentPin   = $st['current_pin'];
        $driverNow    = $st['current_driver_id'];
        $alertState   = $st['alert_state'];            // motor de alertas (se persiste con el resto)
        $openTripId   = $st['open_trip_id'];
        $openTripDrv  = $st['current_driver_id'];      // invariante: el viaje abierto lleva este conductor
        $openTripFrom = null;                          // started_at del viaje abierto (se carga si hace falta)

        // Contexto de persona: puesto vigente y misión que la autoriza a moverse.
        $post = null;
        $missionId = null;
        if ($isPerson && $personId !== null) {
            $post = $this->posts?->currentForPerson($personId, $companyId);
            $mission = $this->missions?->activeForPerson($personId, $companyId);
            $missionId = $mission !== null ? (int) $mission['id'] : null;
        }
        // Última posición considerada "en movimiento" (filtro de ruido GPS a pie).
        $lastMoveLat = null;
        $lastMoveLon = null;

        // Posición previa (para detectar huecos y fijar el fin del viaje al punto anterior).
        $lastPosId  = $st['last_position_id'];
        $lastPosTs  = null;
        if ($openTripId !== null) {
            $openTrip = $this->trips->find($openTripId);
            $openTripFrom = $openTrip !== null ? (string) $openTrip['started_at'] : null;
            if ($lastPosId > 0) {
                $prev = $this->positions->find($lastPosId);
                $lastPosTs = $prev !== null ? (string) $prev['ts'] : null;
            }
        }

        // Sin nada que procesar y sin viaje abierto -> terminamos rápido.
        if ($newPositions === [] && $newEvents === [] && $openTripId === null) {
            return $stats;
        }

        // Timeline combinado: eventos y posiciones ordenados por ts. A igual ts,
        // los eventos van primero (un pin_set debe aplicar a la posición de ese instante).
        $timeline = [];
        foreach ($newEvents as $e) {
            $timeline[] = ['kind' => 'event', 'ts' => (string) $e['ts'], 'ord' => 0, 'id' => (int) $e['id'], 'row' => $e];
        }
        foreach ($newPositions as $p) {
            $timeline[] = ['kind' => 'pos', 'ts' => (string) $p['ts'], 'ord' => 1, 'id' => (int) $p['id'], 'row' => $p];
        }
        usort($timeline, static function (array $a, array $b): int {
            return [$a['ts'], $a['ord'], $a['id']] <=> [$b['ts'], $b['ord'], $b['id']];
        });

        foreach ($timeline as $item) {
            if ($item['kind'] === 'event') {
                $ev = $item['row'];
                $type = (string) $ev['event_type'];

                if ($type === 'pin_set') {
                    $pin = $ev['pin_code'] !== null ? (string) $ev['pin_code'] : null;
                    if ($pin !== null && $pin !== '') {
                        $currentPin = $pin;
                    }
                } elseif ($type === 'pin_cleared') {
                    $currentPin = null;
                } elseif ($type === 'ignition_off') {
                    $currentPin = null;
                    if ($openTripId !== null) {
                        $endTs = $lastPosTs ?? (string) $ev['ts'];
                        $this->closeTrip($deviceId, $openTripId, (string) $openTripFrom, $endTs);
                        $stats['trips_closed']++;
                        $openTripId = null;
                        $openTripFrom = null;
                    }
                }
                // ignition_on / power_cut / low_battery: sin efecto en viajes.
                // SOS dispara alerta crítica (motor de alertas, §12).
                if (in_array($type, ['sos', 'panic', 'low_battery'], true)) {
                    $this->alerts?->onEvent(
                        [
                            'company_id' => $companyId,
                            'device_id'  => $deviceId,
                            'vehicle_id' => $vehicleId,
                            'driver_id'  => $driverNow,
                            'person_id'  => $personId,
                        ],
                        $ev
                    );
                }

                $this->events->markProcessed($item['id']);
                $stats['events']++;
                continue;
            }

            // --- Posición ---------------------------------------------------
            $pos = $item['row'];
            $posId = (int) $pos['id'];
            $ts    = (string) $pos['ts'];
            $lat   = (float) $pos['lat'];
            $lon   = (float) $pos['lon'];

            // Un pin_code explícito en la posición fija el PIN vigente.
            $posPin = $pos['pin_code'] !== null ? (string) $pos['pin_code'] : null;
            if ($posPin !== null && $posPin !== '') {
                $currentPin = $posPin;
            }

            $driverId = $this->resolver->resolve($deviceId, $companyId, $hasPin, $currentPin);

            // 1) Hueco temporal: si pasó más que el umbral de parada, el viaje
            //    abierto terminó en el punto anterior.
            if ($openTripId !== null && $lastPosTs !== null
                && $this->secondsBetween($lastPosTs, $ts) >= $stopSeconds) {
                $this->closeTrip($deviceId, $openTripId, (string) $openTripFrom, $lastPosTs);
                $stats['trips_closed']++;
                $openTripId = null;
                $openTripFrom = null;
            }

            // 2) Cambio de conductor (cambio de PIN): parte el viaje.
            if ($openTripId !== null && $driverId !== $openTripDrv) {
                $this->closeTrip($deviceId, $openTripId, (string) $openTripFrom, $lastPosTs ?? $ts);
                $stats['trips_closed']++;
                $openTripId = null;
                $openTripFrom = null;
            }

            // 3) Apertura: primera posición en movimiento sin viaje en curso.
            $moving = $isPerson
                ? $this->personIsMoving($pos, $lastMoveLat, $lastMoveLon)
                : $this->isMoving($pos);

            if ($moving && $openTripId === null) {
                $openTripId = $this->trips->open($companyId, $deviceId, $vehicleId, $driverId, $ts, $lat, $lon);
                $openTripFrom = $ts;
                $openTripDrv = $driverId;
                $stats['trips_opened']++;
                if ($isPerson && $personId !== null) {
                    $this->trips->setPerson($openTripId, $personId, $missionId);
                }
            }
            if ($moving) {
                $lastMoveLat = $lat;
                $lastMoveLon = $lon;
            }

            $this->positions->setDriver($posId, $driverId);
            $driverNow = $driverId;

            // Motor de alertas.
            if ($isPerson) {
                if ($personId !== null) {
                    $this->alerts?->onPersonPosition(
                        [
                            'company_id'  => $companyId,
                            'device_id'   => $deviceId,
                            'person_id'   => $personId,
                            'post'        => $post,
                            'has_mission' => $missionId !== null,
                        ],
                        $pos,
                        $alertState
                    );
                }
            } else {
                $this->alerts?->onPosition(
                    ['company_id' => $companyId, 'device_id' => $deviceId, 'vehicle_id' => $vehicleId, 'driver_id' => $driverId],
                    $pos,
                    $alertState
                );
            }

            $lastPosId = $posId;
            $lastPosTs = $ts;
            $stats['positions']++;
        }

        // Cierre del viaje abierto si el dispositivo ya lleva detenido más que el
        // umbral respecto del "ahora" (datos históricos). En modo vivo, las
        // posiciones recientes lo dejan abierto.
        if ($openTripId !== null && $lastPosTs !== null
            && $this->secondsBetween($lastPosTs, $this->nowString()) >= $stopSeconds) {
            $this->closeTrip($deviceId, $openTripId, (string) $openTripFrom, $lastPosTs);
            $stats['trips_closed']++;
            $openTripId = null;
        }

        // Denormaliza última posición vista (mapa en vivo).
        if ($lastPosId > 0 && $lastPosTs !== null) {
            $this->devices->updateLastPosition($deviceId, $lastPosId, $lastPosTs);
        }

        $this->state->save($deviceId, $companyId, $currentPin, $driverNow, $lastPosId, $openTripId, $alertState);

        return $stats;
    }

    /**
     * ¿La persona se está moviendo?
     *
     * A pie no hay ignición y `speed > 0` sobra: el ruido de GPS abriría
     * recorridos falsos con la persona quieta. Se exige velocidad de caminata Y
     * un desplazamiento real respecto del último punto en movimiento, y se
     * descartan los puntos de mala precisión.
     *
     * @param array<string,mixed> $pos
     */
    private function personIsMoving(array $pos, ?float $refLat, ?float $refLon): bool
    {
        $accuracy = $pos['accuracy_m'] ?? null;
        if ($accuracy !== null && (int) $accuracy > $this->maxAccuracyMeters) {
            return false;
        }

        if ((int) ($pos['speed'] ?? 0) < $this->walkSpeedKmh) {
            return false;
        }

        if ($refLat === null || $refLon === null) {
            return true;   // primer punto con velocidad: arranca el recorrido
        }

        $meters = Geo::haversineKm($refLat, $refLon, (float) $pos['lat'], (float) $pos['lon']) * 1000;

        return $meters >= $this->minStepMeters;
    }

    /** ¿La posición está en movimiento? Prioriza ignición; cae a velocidad. */
    private function isMoving(array $pos): bool
    {
        if ($pos['ignition'] !== null) {
            return (int) $pos['ignition'] === 1;
        }

        return (int) ($pos['speed'] ?? 0) > 0;
    }

    /**
     * Cierra un viaje calculando sus agregados desde las posiciones de la ventana
     * [startTs, endTs] del dispositivo (distancia haversine, velocidades, duración).
     */
    private function closeTrip(int $deviceId, int $tripId, string $startTs, string $endTs): void
    {
        $pts = $this->positions->inWindow($deviceId, $startTs, $endTs);

        $distance = 0.0;
        $maxSpeed = 0;
        $sumSpeed = 0;
        $count = count($pts);
        $endLat = null;
        $endLon = null;

        $prevLat = null;
        $prevLon = null;
        foreach ($pts as $p) {
            $lat = (float) $p['lat'];
            $lon = (float) $p['lon'];
            $spd = (int) ($p['speed'] ?? 0);

            if ($prevLat !== null) {
                $distance += Geo::haversineKm($prevLat, $prevLon, $lat, $lon);
            }
            $maxSpeed = max($maxSpeed, $spd);
            $sumSpeed += $spd;
            $prevLat = $lat;
            $prevLon = $lon;
            $endLat = $lat;
            $endLon = $lon;
        }

        $avgSpeed = $count > 0 ? (int) round($sumSpeed / $count) : 0;
        $duration = max(0, $this->secondsBetween($startTs, $endTs));

        $this->trips->close($tripId, [
            'ended_at'     => $endTs,
            'end_lat'      => $endLat ?? 0.0,
            'end_lon'      => $endLon ?? 0.0,
            'distance_km'  => round($distance, 2),
            'max_speed'    => $maxSpeed,
            'avg_speed'    => $avgSpeed,
            'duration_sec' => $duration,
            'points_count' => $count,
        ]);
    }

    /** Segundos entre dos DATETIME (b - a). */
    private function secondsBetween(string $a, string $b): int
    {
        return strtotime($b) - strtotime($a);
    }

    private function nowString(): string
    {
        return date('Y-m-d H:i:s');
    }
}
