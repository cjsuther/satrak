<?php

declare(strict_types=1);

namespace Satrak\Application\Controllers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Satrak\Application\Support\Auth;
use Satrak\Application\Support\Flash;
use Satrak\Application\Support\Listing;
use Satrak\Application\Support\Validator;
use Satrak\Domain\Repositories\AuditRepository;
use Satrak\Domain\Repositories\CompanyRepository;
use Satrak\Domain\Repositories\UserRepository;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * ABM de empresas (solo super admin): alta/edición, cupo de dispositivos,
 * estado, y creación del primer Admin de Empresa. Todo auditado.
 */
final class CompanyController
{
    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private Flash $flash,
        private AuditRepository $audit,
        private CompanyRepository $companies,
        private UserRepository $users,
        private PDO $db
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
        $listing = Listing::fromRequest($request, 'name');

        return $this->twig->render($response, 'pages/companies/index.twig', [
            'page' => $this->companies->listPaginated($listing),
            'q'    => $listing->search,
        ]);
    }

    public function createForm(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'pages/companies/form.twig', [
            'mode'    => 'create',
            'company' => ['status' => 'active', 'device_quota' => 0, 'panic_enabled' => 1,
                          'timezone' => 'America/Argentina/Buenos_Aires'],
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $d = (array) $request->getParsedBody();

        $v = new Validator($d);
        $v->required('name', 'El nombre');
        // El admin es opcional, pero si se completa algo, validamos el set.
        $wantsAdmin = trim((string) ($d['admin_email'] ?? '')) !== ''
            || trim((string) ($d['admin_name'] ?? '')) !== ''
            || trim((string) ($d['admin_password'] ?? '')) !== '';
        if ($wantsAdmin) {
            $v->required('admin_name', 'El nombre del admin')
              ->required('admin_email', 'El email del admin')->email('admin_email')
              ->required('admin_password', 'La contraseña')->minLength('admin_password', 8, 'La contraseña');
        }

        $errors = $v->errors();
        if ($wantsAdmin && !isset($errors['admin_email']) && $this->users->emailTaken((string) $d['admin_email'])) {
            $errors['admin_email'] = 'Ese email ya está en uso.';
        }

        if ($errors !== []) {
            $this->flash->error('Revisá los datos del formulario.');

            return $this->twig->render($response->withStatus(422), 'pages/companies/form.twig', [
                'mode' => 'create', 'company' => $d, 'errors' => $errors,
            ]);
        }

        $name = trim((string) $d['name']);
        $slug = $this->companies->uniqueSlug($name);

        $this->db->beginTransaction();
        try {
            $companyId = $this->companies->create([
                'name'         => $name,
                'slug'         => $slug,
                'status'       => ($d['status'] ?? 'active') === 'suspended' ? 'suspended' : 'active',
                'device_quota' => (int) ($d['device_quota'] ?? 0),
                'person_quota' => (int) ($d['person_quota'] ?? 0),
                'modules'      => (array) ($d['modules'] ?? []),
                'emergency_email' => trim((string) ($d['emergency_email'] ?? '')),
                'panic_enabled' => !empty($d['panic_enabled']),
                'timezone'     => trim((string) ($d['timezone'] ?? 'America/Argentina/Buenos_Aires')),
            ]);

            $adminId = null;
            if ($wantsAdmin) {
                $adminId = $this->users->create([
                    'company_id'    => $companyId,
                    'name'          => trim((string) $d['admin_name']),
                    'email'         => (string) $d['admin_email'],
                    'password_hash' => password_hash((string) $d['admin_password'], $this->hashAlgo()),
                    'role'          => 'company_admin',
                    'status'        => 'active',
                ]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $this->audit->log($companyId, $this->auth->id(), 'company.create', 'company', $companyId,
            ['name' => $name, 'admin_user_id' => $adminId], client_ip());
        $this->flash->success('Empresa "' . $name . '" creada' . ($adminId ? ' con su admin.' : '.'));

        return $this->redirect($response, '/empresas');
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $company = $this->companies->find((int) $args['id']);
        if ($company === null) {
            throw new HttpNotFoundException($request);
        }

        return $this->twig->render($response, 'pages/companies/form.twig', [
            'mode' => 'edit', 'company' => $company,
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $company = $this->companies->find($id);
        if ($company === null) {
            throw new HttpNotFoundException($request);
        }

        $d = (array) $request->getParsedBody();
        $v = new Validator($d);
        $v->required('name', 'El nombre');
        if ($v->fails()) {
            $this->flash->error('Revisá los datos del formulario.');

            return $this->twig->render($response->withStatus(422), 'pages/companies/form.twig', [
                'mode' => 'edit', 'company' => array_merge($company, $d), 'errors' => $v->errors(),
            ]);
        }

        $name = trim((string) $d['name']);
        $this->companies->update($id, [
            'name'         => $name,
            // El slug NO se regenera al editar: es la credencial de empresa con
            // la que las personas entran a la app (`company_slug` en
            // /api/app/login). Regenerarlo desde el nombre dejaba a TODA la
            // empresa sin poder loguearse, y encima como efecto colateral de
            // guardar cualquier otro campo. Pasó en producción el 2026-08-28:
            // guardar el toggle de pánico cambió 'transportes-comahue' por
            // 'transportes-del-comahue' y tiró abajo el login de la app.
            // Sólo se genera si por alguna razón la fila no tuviera slug.
            'slug'         => ($company['slug'] ?? '') !== ''
                                ? (string) $company['slug']
                                : $this->companies->uniqueSlug($name, $id),
            'status'       => ($d['status'] ?? 'active') === 'suspended' ? 'suspended' : 'active',
            'device_quota' => (int) ($d['device_quota'] ?? 0),
            'person_quota' => (int) ($d['person_quota'] ?? 0),
            'modules'      => (array) ($d['modules'] ?? []),
            'emergency_email' => trim((string) ($d['emergency_email'] ?? '')),
            'panic_enabled' => !empty($d['panic_enabled']),
            'timezone'     => trim((string) ($d['timezone'] ?? $company['timezone'])),
        ]);

        $this->audit->log($id, $this->auth->id(), 'company.update', 'company', $id, ['name' => $name], client_ip());
        $this->flash->success('Empresa actualizada.');

        return $this->redirect($response, '/empresas');
    }

    public function toggleStatus(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $company = $this->companies->find($id);
        if ($company === null) {
            throw new HttpNotFoundException($request);
        }

        $new = $company['status'] === 'active' ? 'suspended' : 'active';
        // Se re-envían cupos y módulos tal como están: `update()` reescribe la
        // fila entera y omitirlos los volvería a los valores por defecto.
        $this->companies->update($id, [
            'name' => $company['name'], 'slug' => $company['slug'], 'status' => $new,
            'device_quota' => (int) $company['device_quota'],
            'person_quota' => (int) $company['person_quota'],
            'modules'      => (string) $company['modules'],
            'emergency_email' => (string) ($company['emergency_email'] ?? ''),
            'panic_enabled' => (int) ($company['panic_enabled'] ?? 1) === 1,
            'timezone'     => $company['timezone'],
        ]);

        $this->audit->log($id, $this->auth->id(), 'company.status', 'company', $id,
            ['from' => $company['status'], 'to' => $new], client_ip());
        $this->flash->success('Empresa ' . ($new === 'active' ? 'reactivada' : 'suspendida') . '.');

        return $this->redirect($response, '/empresas');
    }
}
