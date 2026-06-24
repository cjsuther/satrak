<?php

declare(strict_types=1);

namespace Satrak\Domain\Repositories;

use PDO;

/**
 * Registro de auditoría (`audit_log`) de escrituras sensibles y login.
 */
final class AuditRepository
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * @param array<string,mixed>|null $changes diff/contexto serializable a JSON
     */
    public function log(
        ?int $companyId,
        ?int $userId,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $changes = null,
        ?string $ip = null
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO audit_log (company_id, user_id, action, entity_type, entity_id, changes, ip)
             VALUES (:company_id, :user_id, :action, :entity_type, :entity_id, :changes, :ip)'
        );
        $stmt->execute([
            ':company_id'  => $companyId,
            ':user_id'     => $userId,
            ':action'      => $action,
            ':entity_type' => $entityType,
            ':entity_id'   => $entityId,
            ':changes'     => $changes !== null ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null,
            ':ip'          => $ip,
        ]);
    }

    /**
     * Últimas entradas, opcionalmente scopeadas a una empresa.
     *
     * @return array<int,array<string,mixed>>
     */
    public function recent(?int $companyId, int $limit = 50): array
    {
        if ($companyId === null) {
            $stmt = $this->db->prepare(
                'SELECT a.*, u.name AS user_name FROM audit_log a
                 LEFT JOIN users u ON u.id = a.user_id
                 ORDER BY a.created_at DESC LIMIT ?'
            );
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        } else {
            $stmt = $this->db->prepare(
                'SELECT a.*, u.name AS user_name FROM audit_log a
                 LEFT JOIN users u ON u.id = a.user_id
                 WHERE a.company_id = ?
                 ORDER BY a.created_at DESC LIMIT ?'
            );
            $stmt->bindValue(1, $companyId, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
