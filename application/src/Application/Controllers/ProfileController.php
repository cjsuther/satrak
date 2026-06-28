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
use Slim\Views\Twig;

/**
 * Perfil de la cuenta propia (cualquier rol): editar el nombre y cambiar la
 * contraseña. El email es la identidad de login y no se edita acá.
 *
 * Para conductores existe además el portal (`/portal/perfil`) con sus datos de
 * contacto; este perfil es el de la cuenta de acceso.
 */
final class ProfileController
{
    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private Flash $flash,
        private AuditRepository $audit,
        private UserRepository $users,
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'pages/profile/edit.twig', [
            'user'   => $this->auth->user(),
            'errors' => [],
        ]);
    }

    public function update(Request $request, Response $response): Response
    {
        $userId = (int) $this->auth->id();
        $user = $this->users->findById($userId);
        if ($user === null) {
            // Sesión inconsistente: cerrar.
            return $response->withHeader('Location', '/logout')->withStatus(302);
        }

        $d = (array) $request->getParsedBody();
        $errors = [];

        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = 'Poné tu nombre.';
        }

        // Cambio de contraseña (opcional): exige la actual.
        $new = (string) ($d['new_password'] ?? '');
        $changePassword = $new !== '';
        if ($changePassword) {
            $current = (string) ($d['current_password'] ?? '');
            if (!password_verify($current, (string) $user['password_hash'])) {
                $errors['current_password'] = 'La contraseña actual no es correcta.';
            }
            $v = new Validator($d);
            $v->minLength('new_password', 8, 'La nueva contraseña')
              ->matches('new_password', 'confirm_password', 'Las contraseñas no coinciden.');
            $errors += $v->errors();
        }

        if ($errors !== []) {
            $this->flash->error('Revisá los datos del perfil.');

            return $this->twig->render($response->withStatus(422), 'pages/profile/edit.twig', [
                'user'   => array_merge($user, ['name' => $name]),
                'errors' => $errors,
            ]);
        }

        if ($name !== (string) $user['name']) {
            $this->users->updateName($userId, $name);
            $this->auth->refreshName($name);
        }
        if ($changePassword) {
            $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
            $this->users->updatePasswordHash($userId, password_hash($new, $algo));
        }

        $this->audit->log(
            $this->auth->effectiveCompanyId(),
            $userId,
            'profile.update',
            'user',
            $userId,
            ['password_changed' => $changePassword],
            client_ip()
        );
        $this->flash->success($changePassword ? 'Perfil y contraseña actualizados.' : 'Perfil actualizado.');

        return $response->withHeader('Location', '/perfil')->withStatus(302);
    }
}
