<?php
// config/epicor_config.example.php
// Conexión a Epicor Kinetic (REST API v2 / OData BAQ).
// Copia este archivo como config/epicor_config.php y llena tus datos.
// epicor_config.php está en .gitignore: NUNCA lo subas al repositorio.
//
// IMPORTANTE: tiene credenciales. Solo lo lee PHP; el .htaccess de esta
// carpeta bloquea el acceso desde el navegador.
declare(strict_types=1);

$base = 'https://TU-SERVIDOR.epicorsaas.com/TU-INSTANCIA/api/v2/odata/TU-COMPANIA/BaqSvc/';

return [
    'usuario'  => '',
    'password' => '',
    'api_key'  => '',

    // ------------------------------------------------------------------
    // 3 BAQs que hay que crear en Epicor (ver LEEME.md, sección Epicor).
    // En 'campos' va el nombre de la columna tal como la regresa el BAQ
    // (Tabla_Campo). Si usas otros alias, solo cámbialos aquí.
    // ------------------------------------------------------------------

    // 1) Partes ACTIVAS  (tabla Part, criterio Part.InActive = false)
    'baq_partes' => [
        'url'    => $base . 'BAQ_INV_Partes/Data',
        'campos' => [
            'parte'       => 'Part_PartNum',
            'descripcion' => 'Part_PartDescription',
            'clase'       => 'Part_ClassID',
            'tipo'        => 'Part_TypeCode',
            'uom'         => 'Part_IUM',
            'lleva_lote'  => 'Part_TrackLots',
            'costo'       => 'Calculated_CostoUnitario',
        ],
    ],

    // 2) Existencias por almacén / ubicación / lote  (tabla PartBin)
    'baq_existencias' => [
        'url'    => $base . 'BAQ_INV_Existencias/Data',
        'campos' => [
            'parte'     => 'PartBin_PartNum',
            'almacen'   => 'PartBin_WarehouseCode',
            'ubicacion' => 'PartBin_BinNum',
            'lote'      => 'PartBin_LotNum',
            'cantidad'  => 'PartBin_OnhandQty',
            'uom'       => 'Part_IUM',
        ],
    ],

    // 3) Lotes por parte  (tabla PartLot). Si lo dejas con url vacía, la
    //    lista de lotes se arma solo con los lotes que tienen existencia.
    'baq_lotes' => [
        'url'    => $base . 'BAQ_INV_Lotes/Data',
        'campos' => [
            'parte' => 'PartLot_PartNum',
            'lote'  => 'PartLot_LotNum',
        ],
    ],

    'page_size'  => 1000,   // registros por página ($top / $skip)
    'timeout'    => 90,     // segundos por petición
    'reintentos' => 3,
    'espera_seg' => 2,

    // Déjalo en true. Si en XAMPP falla por certificado, configura
    // curl.cainfo en php.ini en lugar de desactivarlo.
    'verificar_ssl' => true,
];
