<?php
// ARCHIVO: config_loader.php
// Carga centralizada de la configuración desde pos.cfg
// Uso: require_once 'config_loader.php'; (provee $config global)

if (!isset($config)) {
    $configFile = __DIR__ . '/pos.cfg';
    $config = [
        "tienda_nombre" => "MI TIENDA",
        "direccion" => "",
        "telefono" => "",
        "mensaje_final" => "¡Gracias por su compra!",
        "id_empresa" => 1,
        "id_sucursal" => 1,
        "id_almacen" => 1,
        "mostrar_materias_primas" => false,
        "mostrar_servicios" => true,
        "categorias_ocultas" => [],
        "mensajeria_tarifa_km" => 150,
        "cajeros" => [],
        "numero_tarjeta" => "",
        "titular_tarjeta" => "",
        "banco_tarjeta" => "Bandec / BPA",
        // Diseño del ticket
        "ticket_logo" => "",
        "ticket_slogan" => "",
        "ticket_mostrar_uuid" => false,
        "ticket_mostrar_canal" => true,
        "ticket_mostrar_cajero" => true,
        "ticket_mostrar_qr" => true,
        "ticket_mostrar_items_count" => true,
        // Multi-divisa y métodos de pago
        "tipo_cambio_usd"    => 385.00,
        "tipo_cambio_mlc"    => 310.00,
        "moneda_default_pos" => "CUP",
        "metodos_pago" => [
            ["id"=>"Efectivo",      "nombre"=>"Efectivo",      "icono"=>"fa-money-bill-wave","color_bootstrap"=>"success","activo"=>true,"requiere_codigo"=>false,"aplica_pos"=>true, "aplica_shop"=>true, "es_transferencia"=>false],
            ["id"=>"Transferencia", "nombre"=>"Transferencia", "icono"=>"fa-university",     "color_bootstrap"=>"primary","activo"=>true,"requiere_codigo"=>true, "aplica_pos"=>true, "aplica_shop"=>true, "es_transferencia"=>true],
            ["id"=>"Tarjeta",       "nombre"=>"Tarjeta/Gasto", "icono"=>"fa-credit-card",    "color_bootstrap"=>"warning","activo"=>true,"requiere_codigo"=>false,"aplica_pos"=>true, "aplica_shop"=>false,"es_transferencia"=>false],
        ],
    ];

    if (file_exists($configFile)) {
        $loaded = json_decode(file_get_contents($configFile), true);
        if ($loaded) $config = array_merge($config, $loaded);
    }
}
