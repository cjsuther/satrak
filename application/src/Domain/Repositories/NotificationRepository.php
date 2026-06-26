<?php

declare(strict_types=1);

namespace Satrak\Domain\Repositories;

use PDO;

/**
 * Notificaciones in-app (`notifications`). Una por usuario destinatario; la
 * campana del topbar lee las no leídas (§16).
 */
final class NotificationRepository
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * Crea una notificación por cada usuario destinatario.
     *
     * @param int[] $userIds
     */
    public function createForUsers(int $companyId, array $userIds, ?int $alertId, string $title, ?string $body): void
    {
        if ($userIds === []) {
            return;
        }
        $ins = $this->db->prepare(
            'INSERT INTO notifications (company_id, user_id, alert_id, title, body)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach (array_unique($userIds) as $uid) {
            $ins->execute([$companyId, (int) $uid, $alertId, $title, $body]);
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function unread(int $userId, int $limit = 20): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, alert_id, title, body, created_at FROM notifications
             WHERE user_id = :uid AND read_at IS NULL ORDER BY created_at DESC LIMIT :lim'
        );
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function unreadCount(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    public function markRead(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL'
        );
        $stmt->execute([$id, $userId]);

        return $stmt->rowCount() > 0;
    }

    public function markAllRead(int $userId): void
    {
        $this->db->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL')
            ->execute([$userId]);
    }
}
