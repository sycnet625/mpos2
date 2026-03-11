<?php
// ARCHIVO: /var/www/palweb/api/api_locations.php
// API de Geolocalización y Cálculo de Envíos para La Habana

header('Content-Type: application/json');
ini_set('display_errors', 0);

// 1. Cargar Tarifa desde Configuración
require_once 'config_loader.php';
require_once __DIR__ . '/habana_delivery.php';
$tarifa_km = floatval($config['mensajeria_tarifa_km'] ?? 150);
$locations = palweb_habana_locations();

// 3. Router de Acciones
$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    // Retorna solo municipios
    echo json_encode(array_keys($locations));
    exit;
}

if ($action === 'barrios') {
    // Retorna barrios de un municipio
    $mun = $_GET['municipio'] ?? '';
    if (isset($locations[$mun])) {
        echo json_encode(array_keys($locations[$mun]));
    } else {
        echo json_encode([]);
    }
    exit;
}

if ($action === 'calculate') {
    // Calcula costo final
    $mun = $_GET['municipio'] ?? '';
    $bar = $_GET['barrio'] ?? '';
    
    $distancia = 0;
    if (isset($locations[$mun][$bar])) {
        $distancia = $locations[$mun][$bar];
    }
    
    // FÓRMULA DE NEGOCIO: 100 Base + (KM * Tarifa)
    $costo_base = 100;
    $costo_envio = $costo_base + ($distancia * $tarifa_km);
    
    echo json_encode([
        'municipio' => $mun,
        'barrio' => $bar,
        'distancia_km' => $distancia,
        'tarifa_km' => $tarifa_km,
        'costo_base' => $costo_base,
        'costo_envio' => round($costo_envio, 2)
    ]);
    exit;
}
?>
