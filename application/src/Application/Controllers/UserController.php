<?php

declare(strict_types=1);

namespace Satrak\Application\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Satrak\Domain\Repositories\UserRepository;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * Vista mínima de usuarios (read-only) en la Fase 2: sirve para demostrar el
 * scope multi-tenant y el 404 cross-empresa. El ABM completo llega en la Fase 3.
 */
final class UserController
{
    public function __construct(private Twig $twig, private UserRepository $users)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $companyId = $request->getAttribute('company_id');

        return $this->twig->render($response, 'pages/users/index.twig', [
            'users' => $this->users->listByCompany($companyId),
        ]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $companyId = $request->getAttribute('company_id');
        $user = $this->users->findScoped((int) $args['id'], $companyId);

        // Otra empresa (o inexistente) ⇒ 404, nunca 403.
        if ($user === null) {
            throw new HttpNotFoundException($request);
        }

        return $this->twig->render($response, 'pages/users/show.twig', ['u' => $user]);
    }
}
