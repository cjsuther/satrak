<?php

declare(strict_types=1);

namespace Satrak\Application\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Satrak\Application\Support\Auth;
use Satrak\Application\Support\Listing;
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
        $cid = $companyId !== null ? (int) $companyId : null;

        $q = $request->getQueryParams();
        // Orden por fecha descendente por defecto (lo más reciente primero).
        $search = trim((string) ($q['q'] ?? ''));
        $sort = (string) ($q['sort'] ?? 'date');
        $dir = strtolower((string) ($q['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $page = max(1, (int) ($q['page'] ?? 1));
        $listing = new Listing($search, $sort, $dir, $page, 25);

        $action = trim((string) ($q['action'] ?? ''));
        $from = $this->normalizeDate((string) ($q['from'] ?? ''));
        $to = $this->normalizeDate((string) ($q['to'] ?? ''));

        return $this->twig->render($response, 'pages/audit/index.twig', [
            'page'     => $this->audit->listPaginated($cid, $listing, $action ?: null, $from, $to),
            'actions'  => $this->audit->distinctActions($cid),
            'q'        => $search,
            'action'   => $action,
            'from'     => $q['from'] ?? '',
            'to'       => $q['to'] ?? '',
            'isGlobal' => $companyId === null,
        ]);
    }

    /** Acepta 'YYYY-MM-DDTHH:MM' (datetime-local) y lo pasa a formato SQL. */
    private function normalizeDate(string $v): ?string
    {
        if ($v === '') {
            return null;
        }
        $ts = strtotime(str_replace('T', ' ', $v));

        return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    }
}
