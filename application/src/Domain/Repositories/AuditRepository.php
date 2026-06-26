<?php

declare(strict_types=1);

namespace Satrak\Domain\Repositories;

use PDO;
use Satrak\Application\Support\Listing;

/**
 * Registro de auditoría (`audit_log`) de escrituras sensibles y login.
 *
 * Extiende BaseRepository para la búsqueda/orden/paginación seguras del visor.
 */
final class AuditRepository extends BaseRepository
{
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

    /**
     * Listado paginado con búsqueda (acción/entidad/usuario/IP) y filtros por
     * acción y rango de fechas. `companyId` null = vista global (super admin).
     *
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int,sort:string,dir:string}
     */
    public function listPaginated(
        ?int $companyId,
        Listing $listing,
        ?string $action = null,
        ?string $from = null,
        ?string $to = null
    ): array {
        $whereSql = [];
        $bind = [];

        if ($companyId !== null) {
            $whereSql[] = 'a.company_id = :cid';
            $bind[':cid'] = $companyId;
        }
        if ($action !== null && $action !== '') {
            $whereSql[] = 'a.action = :action';
            $bind[':action'] = $action;
        }
        if ($from !== null && $from !== '') {
            $whereSql[] = 'a.created_at >= :from';
            $bind[':from'] = $from;
        }
        if ($to !== null && $to !== '') {
            $whereSql[] = 'a.created_at <= :to';
            $bind[':to'] = $to;
        }

        return $this->paginate(
            'audit_log a LEFT JOIN users u ON u.id = a.user_id',
            ['a.id', 'a.company_id', 'a.user_id', 'a.action', 'a.entity_type', 'a.entity_id',
                'a.changes', 'a.ip', 'a.created_at', 'u.name AS user_name'],
            $whereSql,
            $bind,
            ['a.action', 'a.entity_type', 'u.name', 'a.ip'],
            ['date' => 'a.created_at', 'action' => 'a.action', 'user' => 'u.name'],
            $listing,
            'date'
        );
    }

    /**
     * Acciones distintas presentes (para el filtro desplegable).
     *
     * @return string[]
     */
    public function distinctActions(?int $companyId): array
    {
        if ($companyId === null) {
            $stmt = $this->db->query('SELECT DISTINCT action FROM audit_log ORDER BY action');
        } else {
            $stmt = $this->db->prepare('SELECT DISTINCT action FROM audit_log WHERE company_id = ? ORDER BY action');
            $stmt->execute([$companyId]);
        }

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
