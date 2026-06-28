<?php

declare(strict_types=1);

namespace Satrak\Application\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Satrak\Application\Support\Auth;
use Satrak\Application\Support\Flash;
use Satrak\Application\Support\Validator;
use Satrak\Domain\Repositories\AuditRepository;
use Satrak\Domain\Repositories\UserRepository;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * Gestión de usuarios globales (Super Admins de Satrak, §9.1). Sólo accesible
 * por super admin. Crear/listar y habilitar/deshabilitar; sin empresa asociada.
 */
final class SuperAdminController
{
    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private Flash $flash,
        private AuditRepository $audit,
        private UserRepository $users,
    ) {
    }

    private function redirect(Response $r, string $to): Response
    {
        return $r->withHeader('Location', $to)->withStatus(302);
    }

    private function hashAlgo(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'pages/super_admins/index.twig', [
            'admins'   => $this->users->listSuperAdmins(),
            'selfId'   => $this->auth->id(),
        ]);
    }

    public function createForm(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'pages/super_admins/form.twig', [
            'sa'     => ['name' => '', 'email' => ''],
            'errors' => [],
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $d = (array) $request->getParsedBody();

        $v = new Validator($d);
        $v->required('name', 'El nombre')
          ->required('email', 'El email')->email('email')
          ->required('password', 'La contraseña')->minLength('password', 8, 'La contraseña');
        $errors = $v->errors();

        $email = mb_strtolower(trim((string) ($d['email'] ?? '')));
        if ($email !== '' && !isset($errors['email']) && $this->users->emailTaken($email)) {
            $errors['email'] = 'Ese email ya está en uso.';
        }

        if ($errors !== []) {
            $this->flash->error('Revisá los datos.');

            return $this->twig->render($response->withStatus(422), 'pages/super_admins/form.twig', [
                'sa'     => ['name' => $d['name'] ?? '', 'email' => $d['email'] ?? ''],
                'errors' => $errors,
            ]);
        }

        $id = $this->users->create([
            'company_id'    => null,
            'driver_id'     => null,
            'name'          => trim((string) $d['name']),
            'email'         => $email,
            'password_hash' => password_hash((string) $d['password'], $this->hashAlgo()),
            'role'          => 'super_admin',
            'status'        => 'active',
        ]);

        $this->audit->log(null, $this->auth->id(), 'super_admin.create', 'user', $id,
            ['email' => $email], client_ip());
        $this->flash->success('Super Admin creado.');

        return $this->redirect($response, '/super-admins');
    }

    public function toggleStatus(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $admin = $this->users->findSuperAdmin($id);
        if ($admin === null) {
            throw new HttpNotFoundException($request);
        }
        if ($id === $this->auth->id()) {
            $this->flash->error('No podés deshabilitar tu propio usuario.');

            return $this->redirect($response, '/super-admins');
        }

        $new = $admin['status'] === 'active' ? 'disabled' : 'active';
        $this->users->setStatus($id, $new);
        $this->audit->log(null, $this->auth->id(), 'super_admin.status', 'user', $id, ['to' => $new], client_ip());
        $this->flash->success('Super Admin ' . ($new === 'active' ? 'habilitado' : 'deshabilitado') . '.');

        return $this->redirect($response, '/super-admins');
    }
}
