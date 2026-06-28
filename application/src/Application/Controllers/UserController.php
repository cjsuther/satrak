<?php

declare(strict_types=1);

namespace Satrak\Application\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Satrak\Application\Support\Auth;
use Satrak\Application\Support\Flash;
use Satrak\Application\Support\Listing;
use Satrak\Application\Support\Validator;
use Satrak\Domain\Repositories\AuditRepository;
use Satrak\Domain\Repositories\DriverRepository;
use Satrak\Domain\Repositories\UserRepository;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * ABM de usuarios de la empresa (operador / conductor). Scopeado por company_id
 * y auditado. El conductor puede tener un usuario para el portal (driver_id).
 */
final class UserController
{
    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private Flash $flash,
        private AuditRepository $audit,
        private UserRepository $users,
        private DriverRepository $drivers
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

    /**
     * Roles asignables desde el ABM de la empresa. El super admin (operando en
     * contexto) puede crear admins de empresa; un admin de empresa sólo crea
     * operadores y conductores (matriz §5: company_admins.manage es del super admin).
     *
     * @return string[]
     */
    private function assignableRoles(): array
    {
        return $this->auth->isSuperAdmin()
            ? ['company_admin', 'operator', 'driver']
            : ['operator', 'driver'];
    }

    public function index(Request $request, Response $response): Response
    {
        $companyId = $request->getAttribute('company_id');
        $listing = Listing::fromRequest($request, 'name');

        return $this->twig->render($response, 'pages/users/index.twig', [
            'page' => $this->users->listPaginated($companyId, $listing),
            'q'    => $listing->search,
        ]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $companyId = $request->getAttribute('company_id');
        $user = $this->users->findScoped((int) $args['id'], $companyId);
        if ($user === null) {
            throw new HttpNotFoundException($request);
        }

        return $this->twig->render($response, 'pages/users/show.twig', ['u' => $user]);
    }

    public function createForm(Request $request, Response $response): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $preDriver = (int) ($request->getQueryParams()['driver_id'] ?? 0);

        return $this->twig->render($response, 'pages/users/form.twig', [
            'mode'    => 'create',
            'u'       => ['role' => $preDriver ? 'driver' : 'operator', 'status' => 'active', 'driver_id' => $preDriver ?: null],
            'drivers' => $this->drivers->activeForCompany($companyId),
            'roles'   => $this->assignableRoles(),
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $d = (array) $request->getParsedBody();

        $errors = $this->validate($d, $companyId, null);
        if ($errors !== []) {
            return $this->renderForm($response->withStatus(422), 'create', $d, $companyId, $errors);
        }

        $role = (string) $d['role'];
        $id = $this->users->create([
            'company_id'    => $companyId,
            'driver_id'     => $role === 'driver' ? (int) $d['driver_id'] : null,
            'name'          => trim((string) $d['name']),
            'email'         => (string) $d['email'],
            'password_hash' => password_hash((string) $d['password'], $this->hashAlgo()),
            'role'          => $role,
            'status'        => 'active',
        ]);

        $this->audit->log($companyId, $this->auth->id(), 'user.create', 'user', $id,
            ['email' => mb_strtolower(trim((string) $d['email'])), 'role' => $role], client_ip());
        $this->flash->success('Usuario creado.');

        return $this->redirect($response, '/usuarios');
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $user = $this->users->findScoped((int) $args['id'], $companyId);
        if ($user === null) {
            throw new HttpNotFoundException($request);
        }

        return $this->renderForm($response, 'edit', $user, $companyId, []);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $user = $this->users->findScoped((int) $args['id'], $companyId);
        if ($user === null) {
            throw new HttpNotFoundException($request);
        }

        $d = (array) $request->getParsedBody();
        $errors = $this->validate($d, $companyId, (int) $user['id'], isEdit: true);
        if ($errors !== []) {
            return $this->renderForm($response->withStatus(422), 'edit', array_merge($user, $d), $companyId, $errors);
        }

        $role = (string) $d['role'];
        $this->users->update((int) $user['id'], [
            'name'      => trim((string) $d['name']),
            'email'     => (string) $d['email'],
            'role'      => $role,
            'status'    => ($d['status'] ?? 'active') === 'disabled' ? 'disabled' : 'active',
            'driver_id' => $role === 'driver' ? (int) $d['driver_id'] : null,
        ]);

        if (trim((string) ($d['password'] ?? '')) !== '') {
            $this->users->updatePasswordHash((int) $user['id'], password_hash((string) $d['password'], $this->hashAlgo()));
        }

        $this->audit->log($companyId, $this->auth->id(), 'user.update', 'user', (int) $user['id'],
            ['email' => mb_strtolower(trim((string) $d['email'])), 'role' => $role], client_ip());
        $this->flash->success('Usuario actualizado.');

        return $this->redirect($response, '/usuarios');
    }

    public function toggleStatus(Request $request, Response $response, array $args): Response
    {
        $companyId = (int) $request->getAttribute('company_id');
        $user = $this->users->findScoped((int) $args['id'], $companyId);
        if ($user === null) {
            throw new HttpNotFoundException($request);
        }
        if ((int) $user['id'] === $this->auth->id()) {
            $this->flash->error('No podés deshabilitar tu propio usuario.');

            return $this->redirect($response, '/usuarios');
        }

        $new = $user['status'] === 'active' ? 'disabled' : 'active';
        $this->users->update((int) $user['id'], [
            'name' => $user['name'], 'email' => $user['email'], 'role' => $user['role'],
            'status' => $new, 'driver_id' => $user['driver_id'],
        ]);

        $this->audit->log($companyId, $this->auth->id(), 'user.status', 'user', (int) $user['id'],
            ['to' => $new], client_ip());
        $this->flash->success('Usuario ' . ($new === 'active' ? 'habilitado' : 'deshabilitado') . '.');

        return $this->redirect($response, '/usuarios');
    }

    // --- Helpers ---------------------------------------------------------------

    /**
     * @param array<string,mixed> $d
     * @return array<string,string>
     */
    private function validate(array $d, int $companyId, ?int $exceptId, bool $isEdit = false): array
    {
        $v = new Validator($d);
        $v->required('name', 'El nombre')
          ->required('email', 'El email')->email('email');

        if (!$isEdit) {
            $v->required('password', 'La contraseña')->minLength('password', 8, 'La contraseña');
        } elseif (trim((string) ($d['password'] ?? '')) !== '') {
            $v->minLength('password', 8, 'La contraseña');
        }

        $errors = $v->errors();

        $role = (string) ($d['role'] ?? '');
        if (!in_array($role, $this->assignableRoles(), true)) {
            $errors['role'] = 'Rol inválido.';
        }
        if ($role === 'driver' && (int) ($d['driver_id'] ?? 0) <= 0) {
            $errors['driver_id'] = 'Elegí el conductor asociado a este usuario.';
        }
        if (!isset($errors['email']) && $this->users->emailTaken((string) $d['email'], $exceptId)) {
            $errors['email'] = 'Ese email ya está en uso.';
        }

        return $errors;
    }

    /**
     * @param array<string,mixed> $u
     * @param array<string,string> $errors
     */
    private function renderForm(Response $response, string $mode, array $u, int $companyId, array $errors): Response
    {
        if ($errors !== []) {
            $this->flash->error('Revisá los datos del formulario.');
        }

        return $this->twig->render($response, 'pages/users/form.twig', [
            'mode'    => $mode,
            'u'       => $u,
            'drivers' => $this->drivers->activeForCompany($companyId),
            'roles'   => $this->assignableRoles(),
            'errors'  => $errors,
        ]);
    }
}
