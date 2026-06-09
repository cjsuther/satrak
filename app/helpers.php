<?php
/**
 * Helpers globales: escape, assets, urls, CSRF, config, contenido.
 */

/**
 * Escapa salida para HTML. Usar SIEMPRE al imprimir datos.
 */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Devuelve el array de configuración (cacheado).
 */
function config(?string $key = null)
{
    static $config = null;
    if ($config === null) {
        $path = dirname(__DIR__) . '/config/config.php';
        $config = is_file($path) ? require $path : require dirname(__DIR__) . '/config/config.example.php';
    }
    if ($key === null) {
        return $config;
    }
    $value = $config;
    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return null;
        }
        $value = $value[$segment];
    }
    return $value;
}

/**
 * Carga un archivo de contenido de /app/content (cacheado).
 */
function content(string $name)
{
    static $cache = [];
    if (!isset($cache[$name])) {
        $path = __DIR__ . '/content/' . $name . '.php';
        $cache[$name] = is_file($path) ? require $path : [];
    }
    return $cache[$name];
}

/**
 * URL absoluta dentro del sitio. Acepta rutas con o sin slash inicial.
 */
function url(string $path = '/'): string
{
    $base = rtrim((string) config('app.base_url'), '/');
    return $base . '/' . ltrim($path, '/');
}

/**
 * URL de un asset estático.
 */
function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/'));
}

/**
 * Devuelve (y crea si hace falta) el token CSRF de la sesión.
 */
function csrf_token(): string
{
    if (empty($_SESSION['_token'])) {
        $_SESSION['_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_token'];
}

/**
 * Campo hidden con el token CSRF, listo para imprimir en un form.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

/**
 * Valida un token recibido contra el de la sesión.
 */
function csrf_verify(?string $token): bool
{
    return !empty($_SESSION['_token']) && is_string($token)
        && hash_equals($_SESSION['_token'], $token);
}

/**
 * Link de WhatsApp con mensaje precargado desde config.
 */
function whatsapp_url(?string $mensaje = null): string
{
    $numero  = preg_replace('/\D/', '', (string) config('contact.whatsapp'));
    $mensaje = $mensaje ?? (string) config('contact.wa_prefill');
    return 'https://wa.me/' . $numero . '?text=' . rawurlencode($mensaje);
}

/**
 * Marca activo el item de navegación según la ruta actual.
 */
function nav_active(string $path, string $current): string
{
    if ($path === '/') {
        return $current === '/' ? ' aria-current="page"' : '';
    }
    return str_starts_with($current, $path) ? ' aria-current="page"' : '';
}

/**
 * Renderiza una vista de página dentro del layout base.
 */
function render(string $page, array $meta = [], array $data = []): string
{
    $defaults = [
        'title'       => 'Satrak — Seguimiento satelital',
        'description' => content('site')['descripcion'] ?? '',
        'canonical'   => url(($_SERVER['REQUEST_URI'] ?? '/')),
        'og_image'    => asset('img/og-image.png'),
        'route'       => $_SERVER['REQUEST_URI'] ?? '/',
    ];
    $meta = array_merge($defaults, $meta);

    // El contenido de la página se captura para inyectarlo en el layout.
    // $site está disponible tanto en las páginas como en el layout.
    $site = content('site');
    $viewPath = __DIR__ . '/views/pages/' . $page . '.php';
    ob_start();
    extract($data, EXTR_SKIP);
    require $viewPath;
    $content = ob_get_clean();

    ob_start();
    require __DIR__ . '/views/layouts/base.php';
    return ob_get_clean();
}

/**
 * Renderiza un partial.
 */
function partial(string $name, array $data = []): void
{
    extract($data, EXTR_SKIP);
    require __DIR__ . '/views/partials/' . $name . '.php';
}

/**
 * Eyebrow + título de sección (patrón del manual de marca).
 */
function section_heading(string $eyebrow, string $titulo, ?string $level = 'h2'): string
{
    return '<div class="section-heading">'
        . '<span class="eyebrow">' . e($eyebrow) . '</span>'
        . '<' . $level . ' class="section-title">' . e($titulo) . '</' . $level . '>'
        . '</div>';
}
