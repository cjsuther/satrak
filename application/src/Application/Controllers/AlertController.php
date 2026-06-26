<?php

declare(strict_types=1);

namespace Satrak\Application\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Satrak\Application\Support\Auth;
use Satrak\Application\Support\Flash;
use Satrak\Domain\Repositories\AlertRepository;
use Satrak\Domain\Repositories\AuditRepository;
use Slim\Views\Twig;

/**
 * Alertas: pantalla de listado con reconocimiento (ACK) y endpoints JSON
 * (campana / refresco). Scopeado por empresa y auditado (§9.3, §15).
 */
final class AlertController
{
    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private Flash $flash,
        private AuditRepository $audit,
        private AlertRepository $alerts,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $status = $request->getQueryParams()['status'] ?? 'open';
        $status = in_array($status, ['open', 'ack', 'all'], true) ? $status : 'open';

        return $this->twig->render($response, 'pages/alerts/index.twig', [
            'alerts'  => $this->alerts->recent($companyId, 100, $status === 'all' ? null : $status),
            'status'  => $status,
            'unacked' => $this->alerts->unackedCount($companyId),
        ]);
    }

    /** ACK desde la pantalla (form POST, sin JS). Redirige con flash. */
    public function ack(Request $request, Response $response, array $args): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $id = (int) $args['id'];

        if ($this->alerts->findScoped($id, $companyId) === null) {
            $this->flash->error('Alerta no encontrada.');

            return $response->withHeader('Location', '/alertas')->withStatus(302);
        }

        if ($this->alerts->acknowledge($id, $companyId, (int) $this->auth->id())) {
            $this->audit->log($companyId, $this->auth->id(), 'alert.ack', 'alert', $id, null, client_ip());
            $this->flash->success('Alerta reconocida.');
        }

        return $response->withHeader('Location', '/alertas')->withStatus(302);
    }

    // -- JSON (§15) -----------------------------------------------------------

    public function recent(Request $request, Response $response): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $rows = $this->alerts->recent($companyId, 30, 'open');

        $data = array_map(static fn ($a) => [
            'id'       => (int) $a['id'],
            'type'     => $a['type'],
            'severity' => $a['severity'],
            'message'  => $a['message'],
            'ts'       => $a['ts'],
        ], $rows);

        return $this->json($response, ['alerts' => $data, 'unacked' => $this->alerts->unackedCount($companyId)]);
    }

    /** ACK por AJAX (campana / lista en vivo). */
    public function ackJson(Request $request, Response $response, array $args): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $id = (int) $args['id'];

        if ($this->alerts->findScoped($id, $companyId) === null) {
            return $this->json($response, null, 'Alerta no encontrada', 404);
        }

        $ok = $this->alerts->acknowledge($id, $companyId, (int) $this->auth->id());
        if ($ok) {
            $this->audit->log($companyId, $this->auth->id(), 'alert.ack', 'alert', $id, null, client_ip());
        }

        return $this->json($response, ['acknowledged' => $ok]);
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
