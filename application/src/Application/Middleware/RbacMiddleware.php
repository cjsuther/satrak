<?php

declare(strict_types=1);

namespace Satrak\Application\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Satrak\Application\Support\Auth;
use Satrak\Application\Support\Entitlements;
use Satrak\Application\Support\Rbac;
use Slim\Exception\HttpForbiddenException;

/**
 * Exige un permiso concreto (matriz §5) para la ruta.
 *
 * Se instancia por ruta con el permiso requerido:
 *   ->add(new RbacMiddleware($auth, $rbac, Perm::FLEET_MANAGE, $entitlements))
 *
 * Dos chequeos independientes:
 *   1. el ROL tiene el permiso (matriz RBAC);
 *   2. la EMPRESA contrató el módulo que lo habilita ({@see Entitlements}).
 *
 * Cualquiera de los dos que falle ⇒ 403. (El aislamiento entre empresas se
 * resuelve aparte con 404 a nivel de entidad para no filtrar existencia.)
 */
final class RbacMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Auth $auth,
        private Rbac $rbac,
        private string $permission,
        private ?Entitlements $entitlements = null
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        if (!$this->rbac->roleCan($this->auth->role(), $this->permission)) {
            throw new HttpForbiddenException($request, 'No tenés permiso para esta acción.');
        }

        if ($this->entitlements !== null && !$this->entitlements->allows($this->permission)) {
            throw new HttpForbiddenException($request, 'Tu empresa no tiene contratado este módulo.');
        }

        return $handler->handle($request);
    }
}
