<?php
/**
 * Importa database/schema.sql usando la conexión de config/config.php.
 *
 * Conveniencia para entornos sin acceso al cliente `mysql` por CLI.
 * En Hostinger se puede importar igualmente desde phpMyAdmin o con:
 *   mysql -u USER -p NOMBRE_DB < database/schema.sql
 *
 * Uso:  php bin/import_schema.php
 */

declare(strict_types=1);

/** @var array $config @var PDO $pdo */
[$config, $pdo] = require __DIR__ . '/bootstrap.php';

$schemaFile = dirname(__DIR__) . '/database/schema.sql';
if (!is_file($schemaFile)) {
    fwrite(STDERR, "No se encontró database/schema.sql\n");
    exit(1);
}

$sql = file_get_contents($schemaFile);
if ($sql === false || trim($sql) === '') {
    fwrite(STDERR, "schema.sql vacío o ilegible\n");
    exit(1);
}

echo "Importando schema en base '{$config['db']['name']}'...\n";

try {
    // PDO ejecuta el script completo (múltiples sentencias) en una sola llamada.
    $pdo->exec($sql);
} catch (\PDOException $e) {
    fwrite(STDERR, "ERROR al importar: {$e->getMessage()}\n");
    exit(1);
}

// Verificación: contar tablas creadas.
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo 'OK · ' . count($tables) . " tablas en la base:\n";
foreach ($tables as $t) {
    echo "  - {$t}\n";
}
