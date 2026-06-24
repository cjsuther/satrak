<?php
/**
 * Definiciones del contenedor DI (PHP-DI).
 *
 * Devuelve el array de definiciones que index.php carga en el ContainerBuilder.
 * El autowiring de PHP-DI resuelve repositorios/controladores/middlewares por
 * type-hint; acá se definen los servicios que necesitan configuración explícita.
 */

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Satrak\Application\Support\Auth;
use Satrak\Application\Support\Csrf;
use Satrak\Application\Support\Database;
use Satrak\Application\Support\Rbac;
use Satrak\Application\Support\RateLimiter;
use Satrak\Application\Support\Session;
use Satrak\Domain\Services\Mailer;
use Slim\Views\Twig;

return [
    'config'      => $config,
    'permissions' => require dirname(__DIR__) . '/config/permissions.php',

    PDO::class => fn () => Database::connect($config['db']),

    Session::class => fn () => new Session(
        (int) $config['app']['session_timeout_min'],
        str_starts_with((string) $config['app']['base_url'], 'https://')
    ),

    Rbac::class => fn (ContainerInterface $c) => new Rbac($c->get('permissions')),

    RateLimiter::class => fn () => new RateLimiter(
        dirname(__DIR__) . '/storage/ratelimit',
        5,
        900
    ),

    Mailer::class => fn () => new Mailer(
        $config['smtp'],
        dirname(__DIR__) . '/storage/logs'
    ),

    // DriverController necesita los límites de PIN desde config.
    Satrak\Application\Controllers\DriverController::class => fn (ContainerInterface $c) =>
        new Satrak\Application\Controllers\DriverController(
            $c->get(Slim\Views\Twig::class),
            $c->get(Satrak\Application\Support\Auth::class),
            $c->get(Satrak\Application\Support\Flash::class),
            $c->get(Satrak\Domain\Repositories\AuditRepository::class),
            $c->get(Satrak\Domain\Repositories\DriverRepository::class),
            (int) ($config['pin']['min_length'] ?? 4),
            (int) ($config['pin']['max_length'] ?? 10)
        ),

    // AuthService necesita el base_url para construir el link de recupero.
    Satrak\Domain\Services\AuthService::class => fn (ContainerInterface $c) =>
        new Satrak\Domain\Services\AuthService(
            $c->get(Satrak\Domain\Repositories\UserRepository::class),
            $c->get(Satrak\Domain\Repositories\PasswordResetRepository::class),
            $c->get(Satrak\Domain\Repositories\AuditRepository::class),
            $c->get(Mailer::class),
            $c->get(RateLimiter::class),
            (string) $config['app']['base_url']
        ),

    Twig::class => function (ContainerInterface $c) use ($config): Twig {
        $twig = Twig::create(dirname(__DIR__) . '/templates', [
            'cache'            => false,
            'autoescape'       => 'html',
            'debug'            => (bool) $config['app']['debug'],
            'strict_variables' => false,
        ]);

        $env = $twig->getEnvironment();
        $env->addGlobal('app', [
            'name'     => 'Satrak',
            'base_url' => $config['app']['base_url'],
            'env'      => $config['app']['env'],
            'year'     => (int) date('Y'),
        ]);

        // Token CSRF como string y como campo oculto listo para usar.
        $env->addFunction(new \Twig\TwigFunction('csrf_token', fn () => $c->get(Csrf::class)->token()));
        $env->addFunction(new \Twig\TwigFunction('csrf_field', function () use ($c): string {
            $csrf = $c->get(Csrf::class);

            return '<input type="hidden" name="' . $csrf->fieldName() . '" value="'
                . htmlspecialchars($csrf->token(), ENT_QUOTES) . '">';
        }, ['is_safe' => ['html']]));

        // can('permiso') para mostrar/ocultar ítems de menú según rol.
        $env->addFunction(new \Twig\TwigFunction('can', function (string $permission) use ($c): bool {
            return $c->get(Rbac::class)->roleCan($c->get(Auth::class)->role(), $permission);
        }));

        return $twig;
    },
];
