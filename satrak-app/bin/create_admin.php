<?php

declare(strict_types=1);

/**
 * Satrak — Alta del primer Super Admin (CLI).
 *
 * Crea un usuario con role='super_admin' y company_id NULL (no pertenece a
 * ninguna empresa: opera el panel global de Satrak). Pensado para correr una
 * sola vez tras importar database/schema.sql.
 *
 * Uso (interactivo):
 *     php bin/create_admin.php
 *
 * Uso (no interactivo, p.ej. en deploy):
 *     php bin/create_admin.php --name="Carlos Sutherland" --email=admin@satrak.online --password='••••'
 *
 * Opciones:
 *     --name, --email, --password   Datos del admin (si faltan, se piden por consola).
 *     --force                       Crea otro super admin aunque ya exista alguno.
 *     -h, --help                    Muestra esta ayuda.
 *
 * Salida: código 0 si crea el usuario; !=0 ante error (uso, validación, DB).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Este script solo se ejecuta por línea de comandos.\n");
}

/* ----------------------------------------------- Resolver la raíz privada
 * Mismo criterio que public/index.php: soporta layout intacto (dev) y split
 * (Hostinger). En el servidor, bin/ vive junto a src/ y vendor/ dentro de la
 * raíz privada, así que dirname(__DIR__) basta; igual probamos la env por las
 * dudas (deploy avanzado). */
$privateRoot = '';
foreach ([
    (string) (getenv('SATRAK_APP_ROOT') ?: ''),
    dirname(__DIR__),
] as $candidate) {
    if ($candidate !== '' && is_file($candidate . '/src/settings.php') && is_file($candidate . '/vendor/autoload.php')) {
        $privateRoot = $candidate;
        break;
    }
}
if ($privateRoot === '') {
    fwrite(STDERR, "Error: no se encontró la raíz privada (src/settings.php + vendor/autoload.php).\n");
    exit(1);
}

require $privateRoot . '/vendor/autoload.php';

use Satrak\Infrastructure\Database;

$settings = require $privateRoot . '/src/settings.php';
date_default_timezone_set($settings['app']['tz'] ?? 'UTC');

/* ------------------------------------------------------------- Argumentos */
$opts = getopt('h', ['name:', 'email:', 'password:', 'force', 'help']);

if (isset($opts['h']) || isset($opts['help'])) {
    fwrite(STDOUT, <<<TXT
    Satrak — Alta del primer Super Admin

      php bin/create_admin.php [--name="..."] [--email=...] [--password=...] [--force]

      --name      Nombre completo del super admin.
      --email     Email de login (único en el sistema).
      --password  Contraseña (mín. 10 caracteres). Si se omite, se pide por consola.
      --force     Permite crear otro super admin aunque ya exista.
      -h, --help  Esta ayuda.

    Si no pasás --name/--email/--password, se piden de forma interactiva.

    TXT);
    exit(0);
}

/* -------------------------------------------------------- Helpers de consola */
/** Lee una línea de STDIN con prompt. */
function prompt_line(string $label): string
{
    fwrite(STDOUT, $label);
    $line = fgets(STDIN);
    return $line === false ? '' : trim($line);
}

/** Lee una contraseña sin eco cuando es posible (POSIX); fallback con eco. */
function prompt_secret(string $label): string
{
    fwrite(STDOUT, $label);
    // En sistemas con `stty` (Linux/macOS, también Hostinger) ocultamos el tipeo.
    if (DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec') && @shell_exec('command -v stty')) {
        @shell_exec('stty -echo');
        $line = fgets(STDIN);
        @shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    } else {
        $line = fgets(STDIN);
    }
    return $line === false ? '' : trim($line);
}

/* ------------------------------------------------------ Recolectar y validar */
$name = isset($opts['name']) ? trim((string) $opts['name']) : prompt_line('Nombre completo: ');
$email = isset($opts['email']) ? trim((string) $opts['email']) : prompt_line('Email: ');
$email = strtolower($email);

$password = isset($opts['password']) ? (string) $opts['password'] : '';
if ($password === '') {
    $password = prompt_secret('Contraseña (mín. 10): ');
    $confirm  = prompt_secret('Repetir contraseña: ');
    if ($password !== $confirm) {
        fwrite(STDERR, "✗ Las contraseñas no coinciden.\n");
        exit(2);
    }
}

$errors = [];
if ($name === '' || mb_strlen($name) > 120) {
    $errors[] = 'El nombre es obligatorio (máx. 120 caracteres).';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
    $errors[] = 'El email no es válido (máx. 150 caracteres).';
}
if (strlen($password) < 10) {
    $errors[] = 'La contraseña debe tener al menos 10 caracteres.';
}
if ($errors !== []) {
    fwrite(STDERR, "✗ Datos inválidos:\n  - " . implode("\n  - ", $errors) . "\n");
    exit(2);
}

/* --------------------------------------------------------------- Conexión DB */
$db = new Database($settings['db']);
if (!$db->isConfigured()) {
    fwrite(STDERR, "✗ La base no está configurada. Completá db.* en config/config.php (host=localhost).\n");
    exit(3);
}

try {
    $pdo = $db->pdo();
} catch (\Throwable $e) {
    fwrite(STDERR, '✗ No se pudo conectar a la base: ' . $e->getMessage() . "\n");
    exit(3);
}

/* ----------------------------------------------------------- Reglas previas */
try {
    // ¿Ya existe algún super admin? (a menos que se fuerce)
    if (!isset($opts['force'])) {
        $count = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'super_admin'")->fetchColumn();
        if ($count > 0) {
            fwrite(STDERR, "✗ Ya existe al menos un super admin. Usá --force si querés crear otro.\n");
            exit(4);
        }
    }

    // Email único en toda la tabla users.
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ((int) $stmt->fetchColumn() > 0) {
        fwrite(STDERR, "✗ Ya hay un usuario con ese email.\n");
        exit(4);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, '✗ Error consultando la base (¿importaste schema.sql?): ' . $e->getMessage() . "\n");
    exit(3);
}

/* ----------------------------------------------------------- Hash + insert */
// argon2id si está disponible; si no, el algoritmo por defecto (bcrypt).
$algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
$hash = password_hash($password, $algo);
if ($hash === false) {
    fwrite(STDERR, "✗ No se pudo hashear la contraseña.\n");
    exit(5);
}

try {
    $stmt = $pdo->prepare(
        'INSERT INTO users (company_id, driver_id, name, email, password_hash, role, status)
         VALUES (NULL, NULL, :name, :email, :hash, :role, :status)'
    );
    $stmt->execute([
        ':name'   => $name,
        ':email'  => $email,
        ':hash'   => $hash,
        ':role'   => 'super_admin',
        ':status' => 'active',
    ]);
    $id = (int) $pdo->lastInsertId();
} catch (\Throwable $e) {
    fwrite(STDERR, '✗ No se pudo crear el usuario: ' . $e->getMessage() . "\n");
    exit(5);
}

fwrite(STDOUT, sprintf(
    "✓ Super Admin creado (id=%d): %s <%s>.\n  Ya podés loguearte cuando esté lista la Fase 2.\n",
    $id,
    $name,
    $email
));
exit(0);
