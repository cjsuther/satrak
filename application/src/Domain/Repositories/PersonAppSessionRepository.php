<?php

declare(strict_types=1);

namespace Satrak\Domain\Repositories;

use PDO;

/**
 * Sesiones de la app móvil. Una sola activa por persona: iniciar sesión en un
 * teléfono nuevo revoca la anterior (decisión §2.2 del spec del módulo).
 *
 * El token se guarda **hasheado** (sha256): en la base nunca hay un bearer
 * utilizable. El token en claro sólo existe en la respuesta del login.
 */
final class PersonAppSessionRepository
{
    public function __construct(private PDO $db)
    {
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Sesión vigente por token, con los datos de la persona. Es el lookup del
     * middleware de la API en cada request.
     *
     * @return array<string,mixed>|null
     */
    public function findByToken(string $token): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT s.id AS session_id, s.company_id, s.person_id, s.device_id, s.install_id,
                    s.platform, s.app_version,
                    p.first_name, p.last_name, p.dni, p.status AS person_status
             FROM person_app_sessions s
             JOIN people p ON p.id = s.person_id
             WHERE s.token_hash = ? AND s.revoked_at IS NULL
             LIMIT 1"
        );
        $stmt->execute([self::hashToken($token)]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function activeForPerson(int $personId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM person_app_sessions
             WHERE person_id = ? AND revoked_at IS NULL ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$personId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Revoca todas las sesiones vigentes de la persona. Devuelve cuántas cerró. */
    public function revokeAllForPerson(int $personId): int
    {
        $stmt = $this->db->prepare(
            'UPDATE person_app_sessions SET revoked_at = NOW()
             WHERE person_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$personId]);

        return $stmt->rowCount();
    }

    public function revoke(int $sessionId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE person_app_sessions SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$sessionId]);
    }

    /**
     * Crea la sesión. `install_id` es único por empresa: si el mismo teléfono
     * vuelve a loguear, se reusa la fila anterior con un token nuevo.
     *
     * @param array<string,mixed> $data
     */
    public function create(int $companyId, int $personId, int $deviceId, string $tokenHash, array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO person_app_sessions
                (company_id, person_id, device_id, install_id, token_hash, platform,
                 os_version, app_version, model, last_seen_at)
             VALUES (:company_id, :person_id, :device_id, :install_id, :token_hash, :platform,
                 :os_version, :app_version, :model, NOW())
             ON DUPLICATE KEY UPDATE
                person_id  = VALUES(person_id),
                device_id  = VALUES(device_id),
                token_hash = VALUES(token_hash),
                platform   = VALUES(platform),
                os_version = VALUES(os_version),
                app_version= VALUES(app_version),
                model      = VALUES(model),
                revoked_at = NULL,
                last_seen_at = NOW()'
        );
        $stmt->execute([
            ':company_id'  => $companyId,
            ':person_id'   => $personId,
            ':device_id'   => $deviceId,
            ':install_id'  => $data['install_id'],
            ':token_hash'  => $tokenHash,
            ':platform'    => $data['platform'],
            ':os_version'  => $data['os_version'] ?: null,
            ':app_version' => $data['app_version'] ?: null,
            ':model'       => $data['model'] ?: null,
        ]);

        $id = (int) $this->db->lastInsertId();
        if ($id > 0) {
            return $id;
        }

        // ON DUPLICATE KEY UPDATE no devuelve lastInsertId útil: se relee.
        $find = $this->db->prepare(
            'SELECT id FROM person_app_sessions WHERE company_id = ? AND install_id = ? LIMIT 1'
        );
        $find->execute([$companyId, $data['install_id']]);

        return (int) $find->fetchColumn();
    }

    /** Latido de la app: batería, permisos y última señal. */
    public function touch(int $sessionId, ?int $batteryPct, ?bool $permsOk): void
    {
        $stmt = $this->db->prepare(
            'UPDATE person_app_sessions
             SET last_seen_at = NOW(),
                 battery_pct = COALESCE(:battery, battery_pct),
                 perms_ok    = COALESCE(:perms, perms_ok)
             WHERE id = :id'
        );
        $stmt->execute([
            ':battery' => $batteryPct,
            ':perms'   => $permsOk === null ? null : ($permsOk ? 1 : 0),
            ':id'      => $sessionId,
        ]);
    }

    /**
     * Estado de la sesión para la ficha de la persona en el panel.
     *
     * @return array<string,mixed>|null
     */
    public function statusForPerson(int $personId, int $companyId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, install_id, platform, os_version, app_version, model,
                    battery_pct, perms_ok, last_seen_at, created_at
             FROM person_app_sessions
             WHERE person_id = ? AND company_id = ? AND revoked_at IS NULL
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$personId, $companyId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}
