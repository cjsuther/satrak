<?php

declare(strict_types=1);

namespace Satrak\Application\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Satrak\Application\Support\Auth;
use Satrak\Domain\Repositories\AuditRepository;
use Slim\Views\Twig;

/**
 * Visor de auditoría (§9.1 / §9.2). Sólo lectura.
 *
 * Scope: el super admin en vista global ve todo; en contexto de empresa (o un
 * admin de empresa) ve sólo su empresa. Se deriva del `company_id` del request
 * (TenantMiddleware), nunca de un parámetro.
 */
final class AuditController
{
    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private AuditRepository $audit,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $companyId = $request->getAttribute('company_id'); // null = vista global (super admin)

        return $this->twig->render($response, 'pages/audit/index.twig', [
            'entries'  => $this->audit->recent($companyId !== null ? (int) $companyId : null, 200),
            'isGlobal' => $companyId === null,
        ]);
    }
}
