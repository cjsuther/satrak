<?php

declare(strict_types=1);

namespace Satrak\Application\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Satrak\Application\Support\Auth;
use Satrak\Application\Support\Flash;
use Satrak\Domain\Repositories\CompanyRepository;
use Slim\Views\Twig;

/**
 * Inyecta en Twig los datos comunes a todas las vistas autenticadas:
 * usuario actual, contexto de empresa y mensajes flash. La ruta activa se
 * deriva del path para marcar el ítem del sidebar.
 */
final class ViewGlobalsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private Flash $flash,
        private CompanyRepository $companies
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $env = $this->twig->getEnvironment();

        $contextCompany = null;
        if ($this->auth->isSuperAdmin() && $this->auth->companyContext() !== null) {
            $contextCompany = $this->companies->find((int) $this->auth->companyContext());
        }

        $env->addGlobal('current_user', $this->auth->user());
        $env->addGlobal('is_super_admin', $this->auth->isSuperAdmin());
        $env->addGlobal('context_company', $contextCompany);
        $env->addGlobal('current_path', $request->getUri()->getPath());
        $env->addGlobal('flashes', $this->flash->consume());

        return $handler->handle($request);
    }
}
