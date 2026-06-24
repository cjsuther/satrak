<?php

declare(strict_types=1);

namespace Satrak\Application\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Satrak\Application\Support\Csrf;
use Slim\Exception\HttpBadRequestException;

/**
 * Valida el token CSRF en toda mutación (POST/PUT/PATCH/DELETE).
 * Acepta el token por campo de formulario `_token` o header `X-CSRF-Token`.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const MUTATING = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private Csrf $csrf)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        if (in_array(strtoupper($request->getMethod()), self::MUTATING, true)) {
            $parsed = $request->getParsedBody();
            $field = $this->csrf->fieldName();
            $token = is_array($parsed) ? ($parsed[$field] ?? null) : null;
            if ($token === null) {
                $token = $request->getHeaderLine('X-CSRF-Token') ?: null;
            }

            if (!$this->csrf->validate(is_string($token) ? $token : null)) {
                throw new HttpBadRequestException($request, 'Token CSRF inválido o ausente.');
            }
        }

        return $handler->handle($request);
    }
}
