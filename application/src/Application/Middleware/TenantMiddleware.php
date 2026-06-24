<?php

declare(strict_types=1);

namespace Satrak\Application\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Satrak\Application\Support\Auth;
use Satrak\Domain\Repositories\CompanyRepository;

/**
 * Inyecta el `company_id` efectivo (scope multi-tenant) en el request.
 *
 * - Usuarios de empresa: su propia empresa, siempre.
 * - Super admin: la empresa de "contexto" elegida (o NULL = vista global).
 *
 * El `company_id` NUNCA se toma de un parámetro del request; se deriva de la
 * sesión vía Auth. Si el super admin tiene un contexto que ya no existe, se limpia.
 */
final class TenantMiddleware implements MiddlewareInterface
{
    public function __construct(private Auth $auth, private CompanyRepository $companies)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        // Validación defensiva del contexto del super admin.
        if ($this->auth->isSuperAdmin()) {
            $ctx = $this->auth->companyContext();
            if ($ctx !== null && $this->companies->find($ctx) === null) {
                $this->auth->setCompanyContext(null);
            }
        }

        $companyId = $this->auth->effectiveCompanyId();
        $request = $request->withAttribute('company_id', $companyId);

        return $handler->handle($request);
    }
}
