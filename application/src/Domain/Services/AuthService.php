<?php

declare(strict_types=1);

namespace Satrak\Domain\Services;

use Satrak\Application\Support\RateLimiter;
use Satrak\Domain\Repositories\AuditRepository;
use Satrak\Domain\Repositories\PasswordResetRepository;
use Satrak\Domain\Repositories\UserRepository;

/**
 * Lógica de autenticación: login con rate-limit + auditoría, y recupero de
 * contraseña por token de un solo uso enviado por email.
 */
final class AuthService
{
    public function __construct(
        private UserRepository $users,
        private PasswordResetRepository $resets,
        private AuditRepository $audit,
        private Mailer $mailer,
        private RateLimiter $limiter,
        private string $baseUrl
    ) {
    }

    private function preferredAlgo(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    /**
     * Intenta autenticar. Devuelve:
     *   ['ok'=>true, 'user'=>array]
     *   ['ok'=>false, 'locked'=>bool, 'retry_after'=>int, 'error'=>string]
     *
     * @return array{ok:bool,user?:array<string,mixed>,locked?:bool,retry_after?:int,error?:string}
     */
    public function attemptLogin(string $email, string $password, string $ip): array
    {
        $email = mb_strtolower(trim($email));
        $key = $email . '|' . $ip;

        if ($this->limiter->tooManyAttempts($key)) {
            $retry = $this->limiter->retryAfter($key);
            $this->audit->log(null, null, 'login.blocked', 'user', null, ['email' => $email], $ip);

            return ['ok' => false, 'locked' => true, 'retry_after' => $retry,
                    'error' => 'Demasiados intentos. Probá de nuevo en ' . ceil($retry / 60) . ' min.'];
        }

        $user = $this->users->findByEmail($email);
        $valid = $user !== null
            && $user['status'] === 'active'
            && password_verify($password, $user['password_hash']);

        if (!$valid) {
            $this->limiter->hit($key);
            $this->audit->log(
                $user['company_id'] ?? null,
                $user['id'] ?? null,
                'login.failed',
                'user',
                isset($user['id']) ? (int) $user['id'] : null,
                ['email' => $email],
                $ip
            );

            return ['ok' => false, 'locked' => false, 'error' => 'Email o contraseña incorrectos.'];
        }

        // Éxito: limpiar contador, rehash si cambió el costo/algoritmo, auditar.
        $this->limiter->clear($key);

        if (password_needs_rehash($user['password_hash'], $this->preferredAlgo())) {
            $newHash = password_hash($password, $this->preferredAlgo());
            $this->users->updatePasswordHash((int) $user['id'], $newHash);
        }

        $this->users->touchLastLogin((int) $user['id']);
        $this->audit->log(
            $user['company_id'] !== null ? (int) $user['company_id'] : null,
            (int) $user['id'],
            'login.success',
            'user',
            (int) $user['id'],
            null,
            $ip
        );

        return ['ok' => true, 'user' => $user];
    }

    /**
     * Inicia el recupero: si el email corresponde a un usuario activo, genera un
     * token y envía el link. Devuelve el mismo resultado siempre (no filtra
     * existencia de cuentas).
     */
    public function requestPasswordReset(string $email, string $ip): void
    {
        $email = mb_strtolower(trim($email));
        $user = $this->users->findByEmail($email);

        if ($user === null || $user['status'] !== 'active') {
            return;   // silencioso a propósito
        }

        $this->resets->invalidateForEmail($email);

        $token = str_random(32);
        $tokenHash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', time() + 3600);   // 1 h
        $this->resets->create($email, $tokenHash, $expiresAt);

        $link = rtrim($this->baseUrl, '/') . '/reset?token=' . urlencode($token);
        $html = $this->resetEmailHtml($user['name'], $link);
        $this->mailer->send($email, (string) $user['name'], 'Recuperá tu contraseña · Satrak', $html);

        $this->audit->log(
            $user['company_id'] !== null ? (int) $user['company_id'] : null,
            (int) $user['id'],
            'password.reset_requested',
            'user',
            (int) $user['id'],
            null,
            $ip
        );
    }

    /**
     * Completa el recupero con un token válido. Devuelve true si se cambió.
     */
    public function resetPassword(string $token, string $newPassword, string $ip): bool
    {
        $tokenHash = hash('sha256', $token);
        $row = $this->resets->findValidByHash($tokenHash);
        if ($row === null) {
            return false;
        }

        $user = $this->users->findByEmail($row['email']);
        if ($user === null) {
            return false;
        }

        $this->users->updatePasswordHash((int) $user['id'], password_hash($newPassword, $this->preferredAlgo()));
        $this->resets->markUsed((int) $row['id']);

        $this->audit->log(
            $user['company_id'] !== null ? (int) $user['company_id'] : null,
            (int) $user['id'],
            'password.reset_done',
            'user',
            (int) $user['id'],
            null,
            $ip
        );

        return true;
    }

    private function resetEmailHtml(string $name, string $link): string
    {
        $name = e($name);
        $link = e($link);

        return <<<HTML
            <div style="font-family:Inter,Arial,sans-serif;color:#0E1B2E">
              <h2 style="color:#0A2342">Recuperá tu contraseña</h2>
              <p>Hola {$name}, recibimos un pedido para restablecer tu contraseña en Satrak.</p>
              <p><a href="{$link}" style="background:#1FE0C4;color:#0A2342;padding:10px 18px;
                 border-radius:8px;text-decoration:none;font-weight:600">Crear nueva contraseña</a></p>
              <p style="color:#6B7C93;font-size:13px">El enlace vence en 1 hora. Si no fuiste vos, ignorá este correo.</p>
            </div>
            HTML;
    }
}
