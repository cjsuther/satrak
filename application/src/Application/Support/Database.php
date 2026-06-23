<?php

declare(strict_types=1);

namespace Satrak\Application\Support;

use PDO;
use RuntimeException;

/**
 * Fábrica de la conexión PDO a MySQL/MariaDB.
 *
 * Centraliza el DSN y las opciones seguras (prepared statements reales, modo
 * excepción, fetch asociativo). Se registra una única instancia en el contenedor DI.
 */
final class Database
{
    /**
     * @param array{host:string,name:string,user:string,pass:string,charset?:string} $db
     */
    public static function connect(array $db): PDO
    {
        $charset = $db['charset'] ?? 'utf8mb4';
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $db['host'], $db['name'], $charset);

        try {
            $pdo = new PDO($dsn, $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (\PDOException $e) {
            // No filtramos credenciales; mensaje útil para el operador.
            throw new RuntimeException(
                'No se pudo conectar a la base de datos: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }

        // Zona horaria de sesión coherente con la app (no depende del server).
        $pdo->exec("SET time_zone = '-03:00'");

        return $pdo;
    }
}
