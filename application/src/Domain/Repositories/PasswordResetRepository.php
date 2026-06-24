<?php

declare(strict_types=1);

namespace Satrak\Domain\Repositories;

use PDO;

/**
 * Tokens de recupero de contraseña (`password_resets`).
 * Se guarda solo el hash del token; el token plano viaja por email una vez.
 */
final class PasswordResetRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function create(string $email, string $tokenHash, string $expiresAt): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO password_resets (email, token_hash, expires_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([mb_strtolower(trim($email)), $tokenHash, $expiresAt]);
    }

    /**
     * Token vigente (no usado, no expirado) que coincida con el hash dado.
     *
     * @return array<string,mixed>|null
     */
    public function findValidByHash(string $tokenHash): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM password_resets
             WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function markUsed(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Invalida tokens previos del email (al pedir uno nuevo).
     */
    public function invalidateForEmail(string $email): void
    {
        $stmt = $this->db->prepare(
            'UPDATE password_resets SET used_at = NOW() WHERE email = ? AND used_at IS NULL'
        );
        $stmt->execute([mb_strtolower(trim($email))]);
    }
}
