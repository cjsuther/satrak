<?php

declare(strict_types=1);

namespace Satrak\Application\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Satrak\Application\Support\Auth;
use Satrak\Application\Support\Rbac;
use Slim\Exception\HttpForbiddenException;

/**
 * Exige un permiso concreto (matriz §5) para la ruta.
 *
 * Se instancia por ruta con el permiso requerido:
 *   ->add(new RbacMiddleware($auth, $rbac, Perm::FLEET_MANAGE))
 *
 * Sin permiso ⇒ 403. (El aislamiento entre empresas se resuelve aparte con 404
 * a nivel de entidad para no filtrar existencia; ver TenantScope en repos.)
 */
final class RbacMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Auth $auth,
        private Rbac $rbac,
        private string $permission
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        if (!$this->rbac->roleCan($this->auth->role(), $this->permission)) {
            throw new HttpForbiddenException($request, 'No tenés permiso para esta acción.');
        }

        return $handler->handle($request);
    }
}
