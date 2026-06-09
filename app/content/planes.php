<?php
/**
 * Planes. Precio editable: "a consultar" o "desde $X/mes".
 */
return [
    [
        'slug'     => 'particular',
        'nombre'   => 'Particular',
        'para'     => 'Autos y motos',
        'precio'   => 'a consultar',
        'destacado'=> false,
        'features' => ['Tiempo real', 'App móvil', 'Corte remoto de motor', 'Alertas de movimiento', '1 equipo'],
    ],
    [
        'slug'     => 'flota',
        'nombre'   => 'Flota',
        'para'     => 'Empresas y logística',
        'precio'   => 'a consultar',
        'destacado'=> true,
        'features' => ['Panel multi-unidad', 'Geocercas', 'Reportes de flota', 'Alertas de velocidad', 'Historial de recorridos', 'Soporte prioritario'],
    ],
    [
        'slug'     => 'empresarial',
        'nombre'   => 'Empresarial',
        'para'     => 'Operaciones críticas y personal',
        'precio'   => 'a consultar',
        'destacado'=> false,
        'features' => ['Todo lo de Flota', 'Rastreo de personal', 'Botón SOS y zonas seguras', 'Cobertura multicarrier', 'Integraciones a medida', 'Cuenta dedicada'],
    ],
];
