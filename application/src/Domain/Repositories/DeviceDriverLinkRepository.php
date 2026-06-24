<?php

declare(strict_types=1);

namespace Satrak\Domain\Repositories;

use PDO;

/**
 * Vínculo dispositivo ↔ conductor (`device_driver_links`).
 *
 * - Dispositivo CON PIN: 0..N vínculos activos con is_default=0 = allowlist.
 * - Dispositivo SIN PIN: exactamente 1 vínculo activo con is_default=1 = conductor
 *   por defecto. Setear uno nuevo cierra el anterior.
 */
final class DeviceDriverLinkRepository
{
    public function __construct(private PDO $db)
    {
    }

    /** Vínculos activos del dispositivo, con nombre/pin del conductor. */
    public function activeLinks(int $deviceId, int $companyId): array
    {
        $stmt = $this->db->prepare(
            'SELECT l.*, dr.first_name, dr.last_name, dr.pin
             FROM device_driver_links l
             JOIN drivers dr ON dr.id = l.driver_id
             WHERE l.device_id = ? AND l.company_id = ? AND l.unlinked_at IS NULL
             ORDER BY l.is_default DESC, dr.last_name'
        );
        $stmt->execute([$deviceId, $companyId]);

        return $stmt->fetchAll();
    }

    /** Conductor por defecto activo (dispositivos sin PIN). @return array<string,mixed>|null */
    public function activeDefault(int $deviceId, int $companyId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT l.*, dr.first_name, dr.last_name FROM device_driver_links l
             JOIN drivers dr ON dr.id = l.driver_id
             WHERE l.device_id = ? AND l.company_id = ? AND l.is_default = 1 AND l.unlinked_at IS NULL LIMIT 1'
        );
        $stmt->execute([$deviceId, $companyId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** ¿El conductor está en la allowlist activa del dispositivo (is_default=0)? */
    public function isInAllowlist(int $deviceId, int $driverId, int $companyId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM device_driver_links
             WHERE device_id = ? AND driver_id = ? AND company_id = ?
               AND is_default = 0 AND unlinked_at IS NULL LIMIT 1'
        );
        $stmt->execute([$deviceId, $driverId, $companyId]);

        return $stmt->fetch() !== false;
    }

    /** Id del conductor por defecto activo (dispositivos sin PIN), o null. */
    public function activeDefaultDriverId(int $deviceId, int $companyId): ?int
    {
        $row = $this->activeDefault($deviceId, $companyId);

        return $row !== null ? (int) $row['driver_id'] : null;
    }

    public function hasActiveLink(int $deviceId, int $driverId, int $companyId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM device_driver_links
             WHERE device_id = ? AND driver_id = ? AND company_id = ? AND unlinked_at IS NULL LIMIT 1'
        );
        $stmt->execute([$deviceId, $driverId, $companyId]);

        return $stmt->fetch() !== false;
    }

    /** Agrega un conductor a la allowlist (is_default = 0). */
    public function addToAllowlist(int $companyId, int $deviceId, int $driverId): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO device_driver_links (company_id, device_id, driver_id, is_default)
             VALUES (?, ?, ?, 0)'
        );
        $stmt->execute([$companyId, $deviceId, $driverId]);
    }

    /** Cierra (desvincula) un link activo, scopeado a la empresa. */
    public function unlink(int $linkId, int $companyId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE device_driver_links SET unlinked_at = NOW()
             WHERE id = ? AND company_id = ? AND unlinked_at IS NULL'
        );
        $stmt->execute([$linkId, $companyId]);
    }

    /**
     * Setea el conductor por defecto (dispositivos sin PIN): cierra el default
     * activo previo e inserta el nuevo. Atómico.
     */
    public function setDefault(int $companyId, int $deviceId, int $driverId): void
    {
        $this->db->beginTransaction();
        try {
            $close = $this->db->prepare(
                'UPDATE device_driver_links SET unlinked_at = NOW()
                 WHERE company_id = ? AND device_id = ? AND unlinked_at IS NULL'
            );
            $close->execute([$companyId, $deviceId]);

            $ins = $this->db->prepare(
                'INSERT INTO device_driver_links (company_id, device_id, driver_id, is_default)
                 VALUES (?, ?, ?, 1)'
            );
            $ins->execute([$companyId, $deviceId, $driverId]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
