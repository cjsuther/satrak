<?php
/**
 * Helpers globales de Satrak.
 *
 * Cargados vía Composer (autoload.files). Funciones puras y utilitarias que no
 * justifican una clase. Las funciones se definen con guardas para tolerar doble carga.
 */

declare(strict_types=1);

if (!function_exists('e')) {
    /**
     * Escape para salida HTML. Twig autoescapa por su cuenta; esto es para usos
     * puntuales en PHP plano (ej. CLI o respuestas manuales).
     */
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('str_random')) {
    /**
     * Genera una cadena hexadecimal aleatoria criptográficamente segura.
     * Usada para tokens CSRF, de sesión y de recupero de contraseña.
     */
    function str_random(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }
}

if (!function_exists('slugify')) {
    /**
     * Convierte un texto a slug (a-z0-9 y guiones). Para slugs de empresa.
     */
    function slugify(string $text): string
    {
        $text = (string) preg_replace('/[^\p{L}\p{N}]+/u', '-', $text);
        $text = trim($text, '-');
        $text = mb_strtolower($text, 'UTF-8');
        // Translitera acentos comunes a ASCII. //IGNORE descarta lo que no tiene
        // equivalente (CJK, emoji) en vez de emitir un notice y cortar la
        // conversión a la mitad; el filtro de abajo limpia lo que quede.
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $text = $ascii !== false ? $ascii : $text;
        $text = (string) preg_replace('/[^a-z0-9-]+/', '', $text);
        $text = (string) preg_replace('/-+/', '-', $text);

        return trim($text, '-') ?: 'empresa';
    }
}

if (!function_exists('client_ip')) {
    /**
     * IP del cliente para auditoría / rate-limit. No confía en headers de proxy
     * salvo que se documente explícitamente en deploy.
     */
    function client_ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
