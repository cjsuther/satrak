<?php

declare(strict_types=1);

namespace Satrak\Infrastructure;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Fábrica de conexión PDO a MySQL/MariaDB.
 *
 * - PDO con prepared statements reales (emulación desactivada) y errores como excepciones.
 * - Conexión perezosa: recién se abre al pedir pdo(), para que la app arranque
 *   aunque la base todavía no esté configurada (Fase 0 sin DB local).
 */
final class Database
{
    private ?PDO $pdo = null;

    /** @param array<string,mixed> $cfg Sección 'db' de la config. */
    public function __construct(private array $cfg)
    {
    }

    /** ¿Hay datos mínimos para intentar conectar? */
    public function isConfigured(): bool
    {
        return !empty($this->cfg['name']) && !empty($this->cfg['host']);
    }

    /**
     * Devuelve la conexión PDO (la crea la primera vez).
     *
     * @throws RuntimeException si la base no está configurada o falla la conexión.
     */
    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        if (!$this->isConfigured()) {
            throw new RuntimeException('La base de datos no está configurada (config db.name / db.host).');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->cfg['host'],
            (int) ($this->cfg['port'] ?? 3306),
            $this->cfg['name'],
            $this->cfg['charset'] ?? 'utf8mb4'
        );

        try {
            $this->pdo = new PDO($dsn, (string) $this->cfg['user'], (string) $this->cfg['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            // No filtramos credenciales al exterior; el detalle va al log del caller.
            throw new RuntimeException('No se pudo conectar a la base de datos: ' . $e->getMessage(), 0, $e);
        }

        return $this->pdo;
    }

    /**
     * Ping liviano para diagnósticos (home Fase 0). No lanza: devuelve estado.
     *
     * @return array{ok:bool,message:string}
     */
    public function status(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'Sin configurar (completá db.* en config.php)'];
        }

        try {
            $this->pdo()->query('SELECT 1');
            return ['ok' => true, 'message' => 'Conectada'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
