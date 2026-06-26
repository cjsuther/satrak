<?php

declare(strict_types=1);

namespace Satrak\Application\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Satrak\Application\Support\Auth;
use Satrak\Domain\Repositories\NotificationRepository;

/**
 * Notificaciones in-app del usuario (campana del topbar, §16). Cada usuario sólo
 * ve y marca las suyas.
 */
final class NotificationController
{
    public function __construct(
        private Auth $auth,
        private NotificationRepository $notifications,
    ) {
    }

    public function unread(Request $request, Response $response): Response
    {
        $userId = (int) $this->auth->id();
        $rows = $this->notifications->unread($userId, 20);

        $data = array_map(static fn ($n) => [
            'id'         => (int) $n['id'],
            'alert_id'   => $n['alert_id'] !== null ? (int) $n['alert_id'] : null,
            'title'      => $n['title'],
            'body'       => $n['body'],
            'created_at' => $n['created_at'],
        ], $rows);

        return $this->json($response, ['notifications' => $data, 'count' => $this->notifications->unreadCount($userId)]);
    }

    public function read(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $this->auth->id();
        $ok = $this->notifications->markRead((int) $args['id'], $userId);

        return $this->json($response, ['read' => $ok, 'count' => $this->notifications->unreadCount($userId)]);
    }

    public function readAll(Request $request, Response $response): Response
    {
        $userId = (int) $this->auth->id();
        $this->notifications->markAllRead($userId);

        return $this->json($response, ['count' => 0]);
    }

    private function json(Response $response, mixed $data, ?string $error = null, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode(
            ['ok' => $error === null, 'data' => $data, 'error' => $error],
            JSON_UNESCAPED_UNICODE
        ));

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withStatus($status);
    }
}
