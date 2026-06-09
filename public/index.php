<?php
/**
 * Front controller — único punto de entrada del sitio.
 * El DocumentRoot apunta a /public; toda petición no estática llega acá.
 */
declare(strict_types=1);

// Servidor embebido (php -S): servir archivos estáticos existentes tal cual.
// En Apache esto lo resuelve .htaccess (RewriteCond -f), así que solo aplica en dev.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($file)) {
        return false;
    }
}

// Resolver dónde vive la carpeta app/. Soporta dos layouts de hosting sin editar nada:
//  1) app/ es HERMANO de public_html  → código fuera de la web (recomendado, más seguro)
//  2) todo el proyecto dentro de public_html (app/ junto a este index.php)
$appRoot = null;
foreach ([dirname(__DIR__), __DIR__] as $candidate) {
    if (is_file($candidate . '/app/router.php')) {
        $appRoot = $candidate;
        break;
    }
}
if ($appRoot === null) {
    http_response_code(500);
    exit('Error de configuración: no se encontró la carpeta app/. Revisá el deploy en el README.');
}

// Carga configuración para definir el modo de errores antes de cualquier salida.
$cfgPath = $appRoot . '/config/config.php';
$cfg = is_file($cfgPath) ? require $cfgPath : require $appRoot . '/config/config.example.php';

if (!empty($cfg['app']['debug'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', $appRoot . '/storage/php-error.log');
}

require $appRoot . '/app/router.php';
