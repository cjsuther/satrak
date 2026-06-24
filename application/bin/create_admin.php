<?php
/**
 * Crea el primer Super Admin de Satrak (company_id = NULL, role = super_admin).
 *
 * Modo interactivo:   php bin/create_admin.php
 * Modo no interactivo: php bin/create_admin.php --name="Cris" --email="a@b.com" --password="secreto12"
 *
 * La contraseña se hashea con argon2id si está disponible, si no bcrypt.
 */

declare(strict_types=1);

/** @var array $config @var PDO $pdo */
[$config, $pdo] = require __DIR__ . '/bootstrap.php';

// ---- Parseo de flags opcionales -------------------------------------------
$opts = getopt('', ['name:', 'email:', 'password:']);

/**
 * Lee una línea de la entrada estándar con un prompt.
 */
function prompt(string $label): string
{
    fwrite(STDOUT, $label);
    $line = fgets(STDIN);

    return $line === false ? '' : trim($line);
}

/**
 * Lee una contraseña sin eco en terminal (si stty está disponible).
 */
function prompt_password(string $label): string
{
    fwrite(STDOUT, $label);
    $hasStty = function_exists('shell_exec') && stripos(PHP_OS, 'WIN') === false;
    if ($hasStty) {
        shell_exec('stty -echo 2>/dev/null');
    }
    $line = fgets(STDIN);
    if ($hasStty) {
        shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, "\n");
    }

    return $line === false ? '' : trim($line);
}

// ---- Recolección de datos --------------------------------------------------
$name  = $opts['name']  ?? prompt('Nombre del super admin: ');
$email = $opts['email'] ?? prompt('Email: ');

$email = mb_strtolower(trim($email));
if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Nombre y email válidos son obligatorios.\n");
    exit(1);
}

// ¿El email ya existe?
$stmt = $pdo->prepare('SELECT id, role FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
if ($existing = $stmt->fetch()) {
    fwrite(STDERR, "Ya existe un usuario con ese email (id {$existing['id']}, rol {$existing['role']}).\n");
    exit(1);
}

if (isset($opts['password'])) {
    $password = (string) $opts['password'];
} else {
    $password = prompt_password('Contraseña (mín. 8): ');
    $confirm  = prompt_password('Repetir contraseña: ');
    if ($password !== $confirm) {
        fwrite(STDERR, "Las contraseñas no coinciden.\n");
        exit(1);
    }
}

if (strlen($password) < 8) {
    fwrite(STDERR, "La contraseña debe tener al menos 8 caracteres.\n");
    exit(1);
}

// argon2id si el build de PHP lo soporta; si no, bcrypt (PASSWORD_DEFAULT).
$algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
$hash = password_hash($password, $algo);

// ---- Inserción -------------------------------------------------------------
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
echo "Super Admin creado · id {$id} · {$email}\n";
echo "Ya podés loguear en " . ($config['app']['base_url'] ?: '(base_url no configurada)') . "\n";
