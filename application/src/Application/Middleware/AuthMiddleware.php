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
 * Exige sesión autenticada. Si no hay usuario, redirige a /login.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private Auth $auth, private Flash $flash)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        if (!$this->auth->check()) {
            $this->flash->error('Necesitás iniciar sesión.');
            $response = new SlimResponse();

            return $response
                ->withHeader('Location', '/login')
                ->withStatus(302);
        }

        return $handler->handle($request);
    }
}
