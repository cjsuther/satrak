<?php
/**
 * Servicios y sus features. Usado en home, páginas de servicio y nav.
 */
return [
    'vehiculos' => [
        'slug'     => 'vehiculos',
        'nombre'   => 'Rastreo de vehículos',
        'resumen'  => 'Localización en tiempo real, control de flota y corte remoto de motor.',
        'icono'    => 'truck',
        'url'      => '/servicios/vehiculos',
        'hero_titulo'   => 'Tu flota bajo control, en tiempo real.',
        'hero_subtitulo'=> 'Sabé dónde está cada vehículo, recibí alertas al instante y actuá ante cualquier eventualidad desde tu panel o tu celular.',
        'beneficios' => [
            ['titulo' => 'Tiempo real', 'desc' => 'Posición actualizada de cada unidad en el mapa, sin demoras.'],
            ['titulo' => 'Geocercas', 'desc' => 'Definí zonas y recibí avisos cuando un vehículo entra o sale.'],
            ['titulo' => 'Alertas de velocidad', 'desc' => 'Notificaciones automáticas ante excesos de velocidad.'],
            ['titulo' => 'Corte remoto de motor', 'desc' => 'Bloqueá el arranque a distancia ante un robo o uso indebido.'],
            ['titulo' => 'Historial de recorridos', 'desc' => 'Reconstruí cada viaje con fecha, hora y trayecto completo.'],
            ['titulo' => 'Reportes de flota', 'desc' => 'Kilometraje, tiempos de uso y comportamiento de conducción.'],
        ],
        'casos' => [
            ['titulo' => 'Flotas', 'desc' => 'Empresas con muchas unidades que necesitan visibilidad total y reportes.'],
            ['titulo' => 'Logística', 'desc' => 'Distribución y transporte que requieren cumplir tiempos y rutas.'],
            ['titulo' => 'Particular', 'desc' => 'Autos y motos con protección antirrobo y tranquilidad para tu familia.'],
        ],
    ],
    'personal' => [
        'slug'     => 'personal',
        'nombre'   => 'Rastreo de personal',
        'resumen'  => 'Seguimiento de personal de campo, botón SOS y zonas seguras.',
        'icono'    => 'user',
        'url'      => '/servicios/personal',
        'hero_titulo'   => 'Cuidá a tu gente, esté donde esté.',
        'hero_subtitulo'=> 'Seguimiento de personal de campo y cuadrillas con botón de emergencia y zonas seguras, pensado para operaciones en terreno difícil.',
        'beneficios' => [
            ['titulo' => 'Ubicación del personal', 'desc' => 'Conocé la posición de cada persona en operaciones de campo.'],
            ['titulo' => 'Botón SOS', 'desc' => 'Alerta inmediata de emergencia con la ubicación del trabajador.'],
            ['titulo' => 'Zonas seguras', 'desc' => 'Definí perímetros y recibí avisos al ingresar o salir de ellos.'],
            ['titulo' => 'Cobertura en terreno difícil', 'desc' => 'Funciona donde la señal masiva falla, como yacimientos remotos.'],
            ['titulo' => 'Historial de actividad', 'desc' => 'Registro de recorridos para auditoría y seguridad laboral.'],
        ],
        'casos' => [
            ['titulo' => 'Cuadrillas de campo', 'desc' => 'Equipos en yacimientos, obras o zonas rurales aisladas.'],
            ['titulo' => 'Seguridad laboral', 'desc' => 'Protocolos de hombre solo (lone worker) y respuesta a emergencias.'],
        ],
        // Uso responsable / consentimiento
        'nota_legal' => 'El rastreo de personal debe realizarse con el consentimiento informado de la persona involucrada y conforme a la legislación laboral y de protección de datos vigente (Ley 25.326). Satrak provee la tecnología; el uso responsable es responsabilidad del contratante.',
    ],
];
