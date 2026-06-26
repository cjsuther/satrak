<?php

declare(strict_types=1);

namespace Satrak\Domain\Repositories;

use PDO;

/**
 * Reglas de alerta (`alert_rules`). `params`, `channels` y `recipients` se
 * guardan como JSON; el motor de alertas (§12) las evalúa por tipo.
 */
final class AlertRuleRepository
{
    public function __construct(private PDO $db)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function forCompany(int $companyId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM alert_rules WHERE company_id = ? ORDER BY type, id');
        $stmt->execute([$companyId]);

        return $stmt->fetchAll();
    }

    /** Reglas activas (las consume el procesador). @return array<int,array<string,mixed>> */
    public function activeForCompany(int $companyId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM alert_rules WHERE company_id = ? AND active = 1');
        $stmt->execute([$companyId]);

        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function findScoped(int $id, int $companyId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM alert_rules WHERE id = ? AND company_id = ? LIMIT 1');
        $stmt->execute([$id, $companyId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @param array<string,mixed>|null $params
     * @param string[]                 $channels
     * @param string[]                 $recipients
     */
    public function create(int $companyId, string $type, ?array $params, array $channels, array $recipients): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO alert_rules (company_id, type, params, channels, recipients, active)
             VALUES (?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $companyId,
            $type,
            $params !== null ? json_encode($params, JSON_UNESCAPED_UNICODE) : null,
            json_encode($channels),
            $recipients !== [] ? json_encode($recipients, JSON_UNESCAPED_UNICODE) : null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array<string,mixed>|null $params
     * @param string[]                 $channels
     * @param string[]                 $recipients
     */
    public function update(int $id, ?array $params, array $channels, array $recipients, bool $active): void
    {
        $stmt = $this->db->prepare(
            'UPDATE alert_rules SET params = ?, channels = ?, recipients = ?, active = ? WHERE id = ?'
        );
        $stmt->execute([
            $params !== null ? json_encode($params, JSON_UNESCAPED_UNICODE) : null,
            json_encode($channels),
            $recipients !== [] ? json_encode($recipients, JSON_UNESCAPED_UNICODE) : null,
            $active ? 1 : 0,
            $id,
        ]);
    }

    public function delete(int $id, int $companyId): void
    {
        $this->db->prepare('DELETE FROM alert_rules WHERE id = ? AND company_id = ?')->execute([$id, $companyId]);
    }
}
