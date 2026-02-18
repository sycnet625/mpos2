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
        "cajeros" => []
    ];

    if (file_exists($configFile)) {
        $loaded = json_decode(file_get_contents($configFile), true);
        if ($loaded) $config = array_merge($config, $loaded);
    }
}
