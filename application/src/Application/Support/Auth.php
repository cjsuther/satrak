<?php

declare(strict_types=1);

namespace Satrak\Application\Support;

/**
 * Estado de autenticación en sesión.
 *
 * Guarda un snapshot mínimo del usuario y, para el super admin, el "contexto de
 * empresa" elegido. El `company_id` efectivo (scope multi-tenant) se deriva
 * SIEMPRE de acá, nunca de un parámetro del request.
 */
final class Auth
{
    private const USER = '_user';
    private const CONTEXT = '_company_context';

    /**
     * Registra al usuario autenticado (snapshot) en la sesión.
     *
     * @param array<string,mixed> $user fila de `users`
     */
    public function login(array $user): void
    {
        $_SESSION[self::USER] = [
            'id'         => (int) $user['id'],
            'name'       => $user['name'],
            'email'      => $user['email'],
            'role'       => $user['role'],
            'company_id' => $user['company_id'] !== null ? (int) $user['company_id'] : null,
            'driver_id'  => $user['driver_id'] !== null ? (int) $user['driver_id'] : null,
            'person_id'  => isset($user['person_id']) && $user['person_id'] !== null
                ? (int) $user['person_id']
                : null,
        ];
        unset($_SESSION[self::CONTEXT]);
    }

    public function logout(): void
    {
        unset($_SESSION[self::USER], $_SESSION[self::CONTEXT]);
    }

    /** Refresca el nombre del snapshot en sesión (tras editar el perfil propio). */
    public function refreshName(string $name): void
    {
        if (isset($_SESSION[self::USER])) {
            $_SESSION[self::USER]['name'] = $name;
        }
    }

    public function check(): bool
    {
        return isset($_SESSION[self::USER]);
    }

    /** @return array<string,mixed>|null */
    public function user(): ?array
    {
        return $_SESSION[self::USER] ?? null;
    }

    public function id(): ?int
    {
        return isset($_SESSION[self::USER]) ? (int) $_SESSION[self::USER]['id'] : null;
    }

    public function role(): ?string
    {
        return $_SESSION[self::USER]['role'] ?? null;
    }

    public function isSuperAdmin(): bool
    {
        return $this->role() === 'super_admin';
    }

    /**
     * company_id del usuario (NULL para super admin).
     */
    public function ownCompanyId(): ?int
    {
        return $_SESSION[self::USER]['company_id'] ?? null;
    }

    public function driverId(): ?int
    {
        return $_SESSION[self::USER]['driver_id'] ?? null;
    }

    /** Persona asociada al usuario (rol `person`). */
    public function personId(): ?int
    {
        return $_SESSION[self::USER]['person_id'] ?? null;
    }

    // --- Contexto de empresa (solo super admin) -----------------------------

    /**
     * Setea la empresa en la que opera el super admin (o NULL para vista global).
     */
    public function setCompanyContext(?int $companyId): void
    {
        if ($companyId === null) {
            unset($_SESSION[self::CONTEXT]);
        } else {
            $_SESSION[self::CONTEXT] = $companyId;
        }
    }

    public function companyContext(): ?int
    {
        return $_SESSION[self::CONTEXT] ?? null;
    }

    /**
     * `company_id` efectivo para el scope multi-tenant:
     *  - super admin: la empresa de contexto elegida (o NULL = global).
     *  - resto: su propia empresa, siempre.
     */
    public function effectiveCompanyId(): ?int
    {
        return $this->isSuperAdmin() ? $this->companyContext() : $this->ownCompanyId();
    }
}
