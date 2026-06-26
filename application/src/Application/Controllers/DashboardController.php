<?php

declare(strict_types=1);

namespace Satrak\Application\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;
use Satrak\Application\Support\Auth;
use Satrak\Domain\Repositories\CompanyRepository;
use Slim\Views\Twig;

/**
 * Dashboard de entrada, consciente del rol y del contexto de empresa.
 * Los KPIs reales por entidad se completan en fases siguientes; en la Fase 2
 * muestra conteos básicos y el estado de la sesión.
 */
final class DashboardController
{
    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private CompanyRepository $companies,
        private PDO $db
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        // El conductor tiene su propio portal acotado; no ve el dashboard general.
        if ($this->auth->role() === 'driver') {
            return $response->withHeader('Location', '/portal/actividad')->withStatus(302);
        }

        $companyId = $request->getAttribute('company_id');   // scope efectivo

        $kpis = [];
        if ($this->auth->isSuperAdmin() && $companyId === null) {
            // Vista global del super admin.
            $kpis = [
                'empresas' => $this->companies->count(),
                'usuarios' => (int) $this->db->query('SELECT COUNT(*) FROM users')->fetchColumn(),
            ];
        } else {
            // Scopeado a la empresa.
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM users WHERE company_id = ?');
            $stmt->execute([$companyId]);
            $kpis = [
                'usuarios' => (int) $stmt->fetchColumn(),
            ];
        }

        return $this->twig->render($response, 'pages/dashboard.twig', [
            'kpis' => $kpis,
        ]);
    }
}
