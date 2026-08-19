<?php

declare(strict_types=1);

namespace Satrak\Application\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Satrak\Domain\Repositories\PersonAppSessionRepository;
use Slim\Psr7\Response as Psr7Response;

/**
 * Autenticación de la app móvil: `Authorization: Bearer <token>`.
 *
 * No usa la sesión web ni cookies — es stateless y por eso queda exenta de CSRF
 * (ver {@see CsrfMiddleware}). Inyecta en el request los atributos que usan los
 * endpoints: `app_session`, `person_id`, `company_id`, `device_id`.
 *
 * Responde SIEMPRE en JSON: del otro lado hay una app, no un navegador.
 */
final class AppAuthMiddleware implements MiddlewareInterface
{
    public function __construct(private PersonAppSessionRepository $sessions)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $token = $this->bearer($request);
        if ($token === null) {
            return $this->deny('Falta el token de sesión.');
        }

        $session = $this->sessions->findByToken($token);
        if ($session === null) {
            return $this->deny('Sesión inválida o cerrada.');
        }

        // La persona pudo haber sido dada de baja después del login.
        if ($session['person_status'] !== 'active') {
            return $this->deny('La persona está inactiva.');
        }

        $request = $request
            ->withAttribute('app_session', $session)
            ->withAttribute('session_id', (int) $session['session_id'])
            ->withAttribute('person_id', (int) $session['person_id'])
            ->withAttribute('company_id', (int) $session['company_id'])
            ->withAttribute('device_id', (int) $session['device_id']);

        return $handler->handle($request);
    }

    private function bearer(Request $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');
        if ($header === '' || !preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
            return null;
        }
        $token = trim($m[1]);

        return $token !== '' ? $token : null;
    }

    private function deny(string $message): Response
    {
        $response = new Psr7Response();
        $response->getBody()->write((string) json_encode(
            ['ok' => false, 'data' => null, 'error' => $message],
            JSON_UNESCAPED_UNICODE
        ));

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withStatus(401);
    }
}
