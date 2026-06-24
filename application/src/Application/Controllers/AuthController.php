<?php

declare(strict_types=1);

namespace Satrak\Application\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Satrak\Application\Support\Auth;
use Satrak\Application\Support\Flash;
use Satrak\Application\Support\Session;
use Satrak\Application\Support\Validator;
use Satrak\Domain\Repositories\AuditRepository;
use Satrak\Domain\Services\AuthService;
use Slim\Views\Twig;

/**
 * Login / logout y recupero de contraseña.
 */
final class AuthController
{
    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private AuthService $authService,
        private Session $session,
        private Flash $flash,
        private AuditRepository $audit
    ) {
    }

    private function redirect(Response $response, string $to, int $status = 302): Response
    {
        return $response->withHeader('Location', $to)->withStatus($status);
    }

    /**
     * Render de páginas públicas de auth inyectando los flashes pendientes
     * (en estas rutas no corre ViewGlobalsMiddleware).
     *
     * @param array<string,mixed> $data
     */
    private function renderAuth(Response $response, string $template, array $data = []): Response
    {
        $data['flashes'] = $this->flash->consume();

        return $this->twig->render($response, $template, $data);
    }

    // --- Login -----------------------------------------------------------------

    public function showLogin(Request $request, Response $response): Response
    {
        if ($this->auth->check()) {
            return $this->redirect($response, '/dashboard');
        }

        return $this->renderAuth($response, 'pages/auth/login.twig', [
            'old_email' => '',
        ]);
    }

    public function login(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        $v = new Validator($data);
        $v->required('email', 'El email')->email('email')->required('password', 'La contraseña');
        if ($v->fails()) {
            $this->flash->error('Completá email y contraseña.');

            return $this->renderAuth($response->withStatus(422), 'pages/auth/login.twig', [
                'old_email' => $email,
                'errors'    => $v->errors(),
            ]);
        }

        $result = $this->authService->attemptLogin($email, $password, client_ip());

        if (!$result['ok']) {
            $this->flash->error($result['error'] ?? 'No se pudo iniciar sesión.');

            return $this->renderAuth($response->withStatus(401), 'pages/auth/login.twig', [
                'old_email' => $email,
            ]);
        }

        // Sesión nueva tras autenticar (previene fijación de sesión).
        $this->session->regenerate();
        $this->auth->login($result['user']);
        $this->flash->success('¡Bienvenido, ' . $result['user']['name'] . '!');

        return $this->redirect($response, '/dashboard');
    }

    public function logout(Request $request, Response $response): Response
    {
        if ($this->auth->check()) {
            $this->audit->log(
                $this->auth->ownCompanyId(),
                $this->auth->id(),
                'logout',
                'user',
                $this->auth->id(),
                null,
                client_ip()
            );
        }
        $this->auth->logout();
        $this->session->regenerate();
        $this->flash->info('Cerraste sesión.');

        return $this->redirect($response, '/login');
    }

    // --- Recupero de contraseña ------------------------------------------------

    public function showForgot(Request $request, Response $response): Response
    {
        return $this->renderAuth($response, 'pages/auth/forgot.twig');
    }

    public function sendForgot(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $email = trim((string) ($data['email'] ?? ''));

        if ($email !== '') {
            $this->authService->requestPasswordReset($email, client_ip());
        }
        // Respuesta uniforme: no revela si el email existe.
        $this->flash->info('Si el email está registrado, te enviamos un enlace para recuperar la contraseña.');

        return $this->redirect($response, '/login');
    }

    public function showReset(Request $request, Response $response): Response
    {
        $token = (string) ($request->getQueryParams()['token'] ?? '');
        if ($token === '') {
            $this->flash->error('Enlace de recupero inválido.');

            return $this->redirect($response, '/login');
        }

        return $this->renderAuth($response, 'pages/auth/reset.twig', ['token' => $token]);
    }

    public function doReset(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $token = (string) ($data['token'] ?? '');
        $password = (string) ($data['password'] ?? '');

        $v = new Validator($data);
        $v->required('password', 'La contraseña')->minLength('password', 8, 'La contraseña')
          ->matches('password_confirm', 'password', 'Las contraseñas no coinciden.');

        if ($token === '' || $v->fails()) {
            $this->flash->error($v->fails() ? implode(' ', $v->errors()) : 'Enlace inválido.');

            return $this->renderAuth($response->withStatus(422), 'pages/auth/reset.twig', ['token' => $token]);
        }

        if (!$this->authService->resetPassword($token, $password, client_ip())) {
            $this->flash->error('El enlace venció o ya fue usado. Pedí uno nuevo.');

            return $this->redirect($response, '/forgot');
        }

        $this->flash->success('Contraseña actualizada. Ya podés iniciar sesión.');

        return $this->redirect($response, '/login');
    }
}
