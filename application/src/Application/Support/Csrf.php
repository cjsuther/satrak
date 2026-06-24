<?php

declare(strict_types=1);

namespace Satrak\Application\Support;

/**
 * Token CSRF por sesión (sincronizador). Un único token persistente por sesión,
 * validado en toda mutación (POST/PUT/PATCH/DELETE) por CsrfMiddleware.
 */
final class Csrf
{
    private const KEY = '_csrf_token';
    private const FIELD = '_token';

    /**
     * Devuelve el token actual, generándolo si no existe.
     */
    public function token(): string
    {
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = str_random(32);
        }

        return $_SESSION[self::KEY];
    }

    /**
     * Compara en tiempo constante el token recibido contra el de sesión.
     */
    public function validate(?string $given): bool
    {
        $expected = $_SESSION[self::KEY] ?? '';

        return $expected !== '' && is_string($given) && hash_equals($expected, $given);
    }

    public function fieldName(): string
    {
        return self::FIELD;
    }
}
