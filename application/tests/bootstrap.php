<?php

declare(strict_types=1);

/**
 * Bootstrap de los tests.
 *
 * No carga `settings.php` ni abre conexión: los tests unitarios no tocan la
 * base. Lo que necesitan del entorno de la app son los helpers globales
 * (`str_random`, `e`, `client_ip`, …), que el autoloader trae vía `files`.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('America/Argentina/Buenos_Aires');
mb_internal_encoding('UTF-8');
