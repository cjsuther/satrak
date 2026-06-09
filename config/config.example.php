<?php
/**
 * Plantilla de configuración. Copiar a config.php y completar credenciales reales.
 * config.php NO se versiona (ver .gitignore).
 */
return [
    'app' => [
        'env'      => 'production',   // 'local' | 'production'
        'base_url' => 'https://satrak.online',
        'debug'    => false,          // true solo en local
    ],
    'contact' => [
        'to_email'   => 'hola@satrak.online',   // casilla donde llegan los leads
        'whatsapp'   => '5492995388574',   // formato internacional sin +
        'wa_prefill' => 'Hola Satrak, quiero una cotización de rastreo.',
    ],
    'smtp' => [
        'host'      => 'smtp.hostinger.com',
        'port'      => 587,
        'user'      => 'hola@satrak.online',
        'pass'      => '',               // completar con la contraseña del email en Hostinger
        'secure'    => 'tls',            // 587 = STARTTLS (verificado OK). 465 = ssl
        'from'      => 'hola@satrak.online',
        'from_name' => 'Satrak',
    ],
    'db' => [   // opcional; si host vacío, se saltea la persistencia
        'host'    => '',
        'name'    => '',
        'user'    => '',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],
];
