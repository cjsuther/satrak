<?php

declare(strict_types=1);

namespace Satrak\Application\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Satrak\Application\Support\Auth;
use Satrak\Application\Support\Flash;
use Satrak\Domain\Repositories\AuditRepository;
use Satrak\Domain\Repositories\CompanyRepository;
use Slim\Exception\HttpNotFoundException;

/**
 * Context switch del Super Admin: entrar/salir del contexto de una empresa.
 * Entrar a una empresa inexistente ⇒ 404 (no se filtra existencia). Auditado.
 */
final class ContextController
{
    public function __construct(
        private Auth $auth,
        private CompanyRepository $companies,
        private AuditRepository $audit,
        private Flash $flash
    ) {
    }

    public function enter(Request $request, Response $response, array $args): Response
    {
        $companyId = (int) ($args['id'] ?? 0);
        $company = $this->companies->find($companyId);
        if ($company === null) {
            throw new HttpNotFoundException($request);
        }

        $this->auth->setCompanyContext($companyId);
        $this->audit->log($companyId, $this->auth->id(), 'context.enter', 'company', $companyId, null, client_ip());
        $this->flash->success('Operando en contexto de ' . $company['name'] . '.');

        return $response->withHeader('Location', '/dashboard')->withStatus(302);
    }

    public function exit(Request $request, Response $response): Response
    {
        $prev = $this->auth->companyContext();
        $this->auth->setCompanyContext(null);
        if ($prev !== null) {
            $this->audit->log($prev, $this->auth->id(), 'context.exit', 'company', $prev, null, client_ip());
        }
        $this->flash->info('Volviste a la vista global.');

        return $response->withHeader('Location', '/dashboard')->withStatus(302);
    }
}
