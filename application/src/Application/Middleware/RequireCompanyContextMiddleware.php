<?php

declare(strict_types=1);

namespace Satrak\Application\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Satrak\Application\Support\Auth;
use Satrak\Application\Support\Flash;
use Slim\Psr7\Response as SlimResponse;

/**
 * Exige un `company_id` efectivo no nulo (contexto de empresa).
 *
 * Los usuarios de empresa siempre lo tienen. El super admin en vista global
 * (sin contexto) es redirigido al dashboard con un aviso: primero debe "entrar
 * en contexto" de una empresa para gestionar sus entidades.
 */
final class RequireCompanyContextMiddleware implements MiddlewareInterface
{
    public function __construct(private Auth $auth, private Flash $flash)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        if ($request->getAttribute('company_id') === null) {
            $this->flash->info('Entrá en el contexto de una empresa para gestionar sus datos.');

            return (new SlimResponse())->withHeader('Location', '/dashboard')->withStatus(302);
        }

        return $handler->handle($request);
    }
}
