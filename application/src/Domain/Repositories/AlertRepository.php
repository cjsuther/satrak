<?php

declare(strict_types=1);

namespace Satrak\Domain\Repositories;

use PDO;

/**
 * Alertas generadas (`alerts`). Las escribe el motor de alertas (§12) y las
 * consumen la pantalla de alertas, la campana y los reportes.
 */
final class AlertRepository
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * @param array{rule_id:?int,device_id:?int,vehicle_id:?int,driver_id:?int,person_id?:?int,type:string,severity:string,message:string,lat:?float,lon:?float,ts:string} $a
     */
    public function create(int $companyId, array $a): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO alerts
                (company_id, rule_id, device_id, vehicle_id, driver_id, person_id, type, severity, message, lat, lon, ts)
             VALUES (:company_id, :rule_id, :device_id, :vehicle_id, :driver_id, :person_id, :type, :severity, :message, :lat, :lon, :ts)'
        );
        $stmt->execute([
            ':company_id' => $companyId,
            ':rule_id'    => $a['rule_id'],
            ':device_id'  => $a['device_id'],
            ':vehicle_id' => $a['vehicle_id'],
            ':driver_id'  => $a['driver_id'],
            ':person_id'  => $a['person_id'] ?? null,
            ':type'       => $a['type'],
            ':severity'   => $a['severity'],
            ':message'    => $a['message'],
            ':lat'        => $a['lat'],
            ':lon'        => $a['lon'],
            ':ts'         => $a['ts'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * ¿Existe una alerta de este tipo para el dispositivo desde `sinceTs`?
     * Se usa para no repetir alertas de un mismo episodio (p. ej. offline).
     */
    public function existsSince(int $deviceId, string $type, string $sinceTs): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM alerts WHERE device_id = ? AND type = ? AND ts >= ? LIMIT 1'
        );
        $stmt->execute([$deviceId, $type, $sinceTs]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Alertas recientes con patente y conductor (campana / lista).
     *
     * @return array<int,array<string,mixed>>
     */
    public function recent(int $companyId, int $limit = 50, ?string $status = null): array
    {
        $where = 'a.company_id = :cid';
        if ($status === 'open') {
            $where .= ' AND a.acknowledged_at IS NULL';
        } elseif ($status === 'ack') {
            $where .= ' AND a.acknowledged_at IS NOT NULL';
        }

        $stmt = $this->db->prepare(
            "SELECT a.*, v.plate,
                    TRIM(CONCAT(COALESCE(dr.first_name,''),' ',COALESCE(dr.last_name,''))) AS driver_name
             FROM alerts a
             LEFT JOIN vehicles v ON v.id = a.vehicle_id
             LEFT JOIN drivers dr ON dr.id = a.driver_id
             WHERE {$where}
             ORDER BY a.ts DESC LIMIT :lim"
        );
        $stmt->bindValue(':cid', $companyId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function unackedCount(int $companyId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM alerts WHERE company_id = ? AND acknowledged_at IS NULL');
        $stmt->execute([$companyId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    public function findScoped(int $id, int $companyId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM alerts WHERE id = ? AND company_id = ? LIMIT 1');
        $stmt->execute([$id, $companyId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Reconoce una alerta (idempotente: no pisa un ACK previo). */
    public function acknowledge(int $id, int $companyId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE alerts SET acknowledged_at = NOW(), acknowledged_by = ?
             WHERE id = ? AND company_id = ? AND acknowledged_at IS NULL'
        );
        $stmt->execute([$userId, $id, $companyId]);

        return $stmt->rowCount() > 0;
    }
}
