<?php

declare(strict_types=1);

namespace Satrak\Application\Support;

/**
 * Arranque y endurecimiento de la sesión PHP nativa.
 *
 * Cookie HttpOnly + SameSite=Lax + Secure (en HTTPS); timeout por inactividad;
 * regeneración de id en login. Se invoca una vez al inicio del request.
 */
final class Session
{
    private int $timeoutSeconds;
    private bool $secure;

    public function __construct(int $timeoutMinutes, bool $secure)
    {
        $this->timeoutSeconds = max(60, $timeoutMinutes * 60);
        $this->secure = $secure;
    }

    /**
     * Inicia la sesión con parámetros seguros y aplica el timeout de inactividad.
     */
    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_cookie_params([
            'lifetime' => 0,              // cookie de sesión
            'path'     => '/',
            'httponly' => true,
            'secure'   => $this->secure,
            'samesite' => 'Lax',
        ]);
        session_name('satrak_sess');
        session_start();

        $this->enforceIdleTimeout();
    }

    /**
     * Cierra la sesión por inactividad si superó el timeout.
     */
    private function enforceIdleTimeout(): void
    {
        $now = time();
        $last = $_SESSION['_last_activity'] ?? null;

        if ($last !== null && ($now - (int) $last) > $this->timeoutSeconds) {
            $this->destroy();
            session_start();   // sesión nueva limpia
        }

        $_SESSION['_last_activity'] = $now;
    }

    /**
     * Regenera el id de sesión preservando los datos (llamar tras login).
     */
    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * Destruye por completo la sesión y su cookie.
     */
    public function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'httponly' => true,
                'secure'   => $this->secure,
                'samesite' => 'Lax',
            ]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
