<?php
// config/config.example.php
// Copia este archivo como config/config.php y ajusta tus datos.
// config.php está en .gitignore: no se sube al repositorio.
// Configuración general del sistema de Inventario Físico.
// Este archivo NO se puede abrir desde el navegador (ver config/.htaccess).
declare(strict_types=1);

return [
    // ---------------- Base de datos (MySQL de XAMPP) ----------------
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'inventario_fisico',
        'user' => 'root',
        'pass' => '',
    ],

    // Nombre que se muestra en pantallas y marbetes
    'app_nombre' => 'Inventario Físico — Empresa',

    // URL base del sistema. Déjalo en null para detectarlo solo
    // (ej. http://servidor/inventario  →  '/inventario').
    'base_url' => null,

    'zona_horaria' => 'America/Mexico_City',

    // Minutos sin actividad para cerrar sesión (el escáner actual usa 15)
    'minutos_inactividad' => 30,

    // ---------------- Reglas de conteo ----------------
    'reglas' => [
        // PT: una tarima solo puede
        // llevar una parte y un job. Si en inventario hay tarimas mezcladas,
        // cambia a false.
        'pt_una_parte_por_tarima' => true,
        'pt_un_job_por_tarima'    => false,

        // PT: la parte del QR debe existir en el catálogo de partes activas
        // sincronizado de Epicor.
        'pt_validar_parte_en_catalogo' => true,

        // PT: el "lote" que se compara contra Epicor es el JOB del QR.
        'pt_lote_es_job' => true,

        // El 2do conteo (y reconteos) los debe hacer una persona distinta
        // a la que hizo el conteo anterior.
        'reconteo_otro_usuario' => true,

        // MP: permitir capturar un lote que no está en Epicor (queda marcado
        // en amarillo para que el supervisor lo revise). En false, el lote
        // se tiene que elegir forzosamente de la lista de Epicor.
        'mp_permitir_lote_fuera_epicor' => true,

        // Diferencia mínima para considerar que dos conteos "no coinciden"
        // (sirve para kilos con decimales). 0 = deben ser exactos.
        'tolerancia' => 0.0,

        // Reimprimir el marbete en el 2do conteo / reconteos
        'imprimir_reconteo' => true,

    ],

    // Prefijo del folio/QR de marbete. El QR del marbete es, por ejemplo, MB-000123
    'prefijo_marbete' => 'MB-',
];
