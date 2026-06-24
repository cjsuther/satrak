<?php

declare(strict_types=1);

namespace Satrak\Domain\Services;

use Satrak\Domain\Repositories\DeviceDriverLinkRepository;
use Satrak\Domain\Repositories\DriverRepository;

/**
 * Resolución de `driver_id` por PIN (spec §8) — comportamiento diferencial del
 * sistema. Dada una posición/evento de un dispositivo y el PIN vigente, decide
 * a qué conductor se atribuye (o si queda "no identificado").
 *
 * Algoritmo (§8):
 *   - Dispositivo CON PIN:
 *       · con PIN vigente reconocido Y en la allowlist activa → ese conductor.
 *       · PIN ausente, desconocido o no habilitado → NULL (no identificado).
 *   - Dispositivo SIN PIN:
 *       · conductor por defecto activo del dispositivo (o NULL si no hay).
 *
 * No mantiene estado: el PIN vigente lo administra el procesador y se lo pasa
 * resuelto en cada llamada.
 */
final class PinResolver
{
    public function __construct(
        private DriverRepository $drivers,
        private DeviceDriverLinkRepository $links,
    ) {
    }

    /**
     * Conductor atribuido a una posición/evento, o NULL si no identificado.
     *
     * @param string|null $pin PIN vigente aplicable a esta posición (ya resuelto
     *                         por el procesador), o NULL si no hay.
     */
    public function resolve(int $deviceId, int $companyId, bool $hasPin, ?string $pin): ?int
    {
        if (!$hasPin) {
            // Dispositivo sin PIN: atribuye siempre al conductor por defecto.
            return $this->links->activeDefaultDriverId($deviceId, $companyId);
        }

        // Dispositivo con PIN: hace falta un PIN vigente no vacío.
        if ($pin === null || $pin === '') {
            return null;
        }

        $driver = $this->drivers->findByPin($companyId, $pin);
        if ($driver === null) {
            return null; // PIN no reconocido en la empresa.
        }

        $driverId = (int) $driver['id'];

        // El conductor existe pero debe estar habilitado en este dispositivo.
        if (!$this->links->isInAllowlist($deviceId, $driverId, $companyId)) {
            return null;
        }

        return $driverId;
    }
}
