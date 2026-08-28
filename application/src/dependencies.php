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

    // Módulos contratados por la empresa en contexto. Una sola instancia por
    // request: la llena TenantMiddleware y la leen RbacMiddleware y can().
    Satrak\Application\Support\Entitlements::class => fn () => new Satrak\Application\Support\Entitlements(),

    // PersonController necesita los límites de PIN desde config (perfil de conductor).
    Satrak\Application\Controllers\PersonController::class => fn (ContainerInterface $c) =>
        new Satrak\Application\Controllers\PersonController(
            $c->get(Slim\Views\Twig::class),
            $c->get(Satrak\Application\Support\Auth::class),
            $c->get(Satrak\Application\Support\Flash::class),
            $c->get(Satrak\Domain\Repositories\AuditRepository::class),
            $c->get(Satrak\Domain\Repositories\PersonRepository::class),
            $c->get(Satrak\Domain\Repositories\DriverRepository::class),
            $c->get(Satrak\Domain\Repositories\CompanyRepository::class),
            (int) ($config['pin']['min_length'] ?? 4),
            (int) ($config['pin']['max_length'] ?? 10)
        ),

    // AppApiController necesita los intervalos de muestreo de la app desde config.
    Satrak\Application\Controllers\AppApiController::class => fn (ContainerInterface $c) =>
        new Satrak\Application\Controllers\AppApiController(
            $c->get(Satrak\Domain\Repositories\PersonRepository::class),
            $c->get(Satrak\Domain\Repositories\PersonAppSessionRepository::class),
            $c->get(Satrak\Domain\Repositories\DeviceRepository::class),
            $c->get(Satrak\Domain\Repositories\DevicePersonAssignmentRepository::class),
            $c->get(Satrak\Domain\Repositories\CompanyRepository::class),
            $c->get(Satrak\Domain\Repositories\PositionRepository::class),
            $c->get(Satrak\Domain\Repositories\DeviceEventRepository::class),
            $c->get(Satrak\Domain\Repositories\MissionRepository::class),
            $c->get(Satrak\Domain\Repositories\PersonPostRepository::class),
            $c->get(Satrak\Domain\Repositories\PersonShiftRepository::class),
            $c->get(Satrak\Domain\Services\ShiftGuard::class),
            $c->get(Satrak\Domain\Repositories\AuditRepository::class),
            $c->get(RateLimiter::class),
            (int) ($config['people']['moving_sample_seconds'] ?? 60),
            (int) ($config['people']['stopped_sample_seconds'] ?? 300)
        ),

    // MapController necesita los umbrales de monitoreo desde config y, para el
    // mapa unificado, el contexto de personas (puesto, misión, jornada).
    Satrak\Application\Controllers\MapController::class => fn (ContainerInterface $c) =>
        new Satrak\Application\Controllers\MapController(
            $c->get(Slim\Views\Twig::class),
            $c->get(Satrak\Domain\Repositories\MonitoringRepository::class),
            $c->get(Satrak\Domain\Repositories\DeviceRepository::class),
            (int) ($config['tracking']['offline_minutes'] ?? 30),
            (int) ($config['map']['live_poll_seconds'] ?? 15),
            5,
            $c->get(Satrak\Application\Support\Entitlements::class),
            $c->get(Satrak\Domain\Repositories\PersonPostRepository::class),
            $c->get(Satrak\Domain\Repositories\MissionRepository::class),
            $c->get(Satrak\Domain\Services\ShiftGuard::class)
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

        // asset('/assets/js/map.js') → '/assets/js/map.js?v=1756400000'
        //
        // El deploy es un scp sobre archivos con el mismo nombre, así que sin
        // versionar la URL el navegador se queda con la copia vieja. Pasó al
        // migrar a MapLibre: el CSS nuevo estaba en el servidor y el navegador
        // seguía usando el anterior, con lo cual el contenedor del mapa quedaba
        // con altura 0 y no se veía nada.
        //
        // La versión es el mtime del archivo: cambia sola en cada deploy y no
        // hay que acordarse de nada.
        $env->addFunction(new \Twig\TwigFunction('asset', static function (string $path) use ($config): string {
            $file = dirname(__DIR__) . '/public' . $path;
            $stamp = is_file($file) ? filemtime($file) : null;

            return $stamp ? $path . '?v=' . $stamp : $path;
        }));

        // Token CSRF como string y como campo oculto listo para usar.
        $env->addFunction(new \Twig\TwigFunction('csrf_token', fn () => $c->get(Csrf::class)->token()));
        $env->addFunction(new \Twig\TwigFunction('csrf_field', function () use ($c): string {
            $csrf = $c->get(Csrf::class);

            return '<input type="hidden" name="' . $csrf->fieldName() . '" value="'
                . htmlspecialchars($csrf->token(), ENT_QUOTES) . '">';
        }, ['is_safe' => ['html']]));

        // can('permiso') para mostrar/ocultar ítems de menú. Mismo criterio que
        // RbacMiddleware: el rol tiene que poder Y la empresa tiene que haberlo
        // contratado, para no ofrecer links que después dan 403.
        $env->addFunction(new \Twig\TwigFunction('can', function (string $permission) use ($c): bool {
            return $c->get(Rbac::class)->roleCan($c->get(Auth::class)->role(), $permission)
                && $c->get(Satrak\Application\Support\Entitlements::class)->allows($permission);
        }));

        // json_decode: para leer columnas JSON (params/channels de reglas) en vistas.
        $env->addFilter(new \Twig\TwigFilter('json_decode', static function ($json) {
            if (is_array($json)) {
                return $json;
            }
            $d = is_string($json) && $json !== '' ? json_decode($json, true) : null;

            return is_array($d) ? $d : [];
        }));

        return $twig;
    },
];
