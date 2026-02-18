<?php
// ARCHIVO: /var/www/palweb/api/api_locations.php
// API de Geolocalización y Cálculo de Envíos para La Habana

header('Content-Type: application/json');
ini_set('display_errors', 0);

// 1. Cargar Tarifa desde Configuración
require_once 'config_loader.php';
$tarifa_km = floatval($config['mensajeria_tarifa_km'] ?? 150);

// 2. Base de Datos de Distancias (KM estimados desde El Canal, Cerro)
// Formato: Municipio => [ Consejo Popular => Distancia KM ]
$locations = [
    "Cerro" => [
        "El Canal" => 0.5, "Pilar-Atares" => 2.0, "Cerro" => 1.5, "Las Cañas" => 2.5, 
        "Palatino" => 3.0, "Armada" => 2.0, "Latinoamericano" => 1.0
    ],
    "Plaza de la Revolución" => [
        "Rampa" => 4.0, "Vedado" => 4.5, "Carmelo" => 5.5, "Príncipe" => 2.5, 
        "Plaza" => 3.0, "Nuevo Vedado" => 4.0, "Colón" => 3.5, "Puentes Grandes" => 4.5
    ],
    "Centro Habana" => [
        "Cayo Hueso" => 3.0, "Pueblo Nuevo" => 3.5, "Los Sitios" => 3.0, "Dragones" => 3.5, 
        "Colón" => 4.0
    ],
    "La Habana Vieja" => [
        "Prado" => 4.5, "Catedral" => 5.0, "Plaza Vieja" => 5.0, "Belén" => 5.0, 
        "San Isidro" => 5.5, "Jesús María" => 5.0, "Tallapiedra" => 4.5
    ],
    "Diez de Octubre" => [
        "Luyanó" => 4.0, "Jesús del Monte" => 3.5, "Lawton" => 5.0, "Víbora" => 4.5, 
        "Santos Suárez" => 3.5, "Sevillano" => 4.5, "Vista Alegre" => 5.0, "Tamarindo" => 3.0, "Acosta" => 4.0
    ],
    "Playa" => [
        "Miramar" => 7.0, "Buena Vista" => 6.0, "Ceiba" => 5.0, "Ampliación Almendares" => 6.0,
        "Siboney" => 9.0, "Atabey" => 10.0, "Santa Fe" => 12.0, "Jaimanitas" => 11.0
    ],
    "Marianao" => [
        "CAI - Los Ángeles" => 6.0, "Pocito - Palmar" => 7.0, "Zamora - Cocosolo" => 6.5, 
        "Libertad" => 6.0, "Pogolotti - Finlay" => 6.5, "Santa Felicia" => 6.0
    ],
    "La Lisa" => [
        "Alturas de La Lisa" => 9.0, "Balcón Arimao" => 9.5, "El Cano" => 11.0, 
        "Punta Brava" => 12.0, "Arroyo Arenas" => 10.0, "San Agustín" => 10.0, "Versalles" => 8.5
    ],
    "Boyeros" => [
        "Santiago de las Vegas" => 15.0, "Nuevo Santiago" => 14.0, "Boyeros" => 12.0, 
        "Wajay" => 11.0, "Calabazar" => 9.0, "Altahabana" => 7.0, "Armada" => 5.0
    ],
    "Arroyo Naranjo" => [
        "Los Pinos" => 7.0, "Poey" => 8.0, "Vibora Park" => 7.0, "Mantilla" => 9.0, 
        "Párraga" => 10.0, "Callejas" => 6.5, "Guinera" => 8.0, "Managua" => 15.0, "Eléctrico" => 11.0
    ],
    "San Miguel del Padrón" => [
        "Rocafort" => 6.0, "Luyanó Moderno" => 7.0, "Diezmero" => 8.0, "San Francisco de Paula" => 10.0, 
        "Dolores" => 6.5, "Jacomino" => 6.0
    ],
    "Cotorro" => [
        "San Pedro" => 16.0, "Cuatro Caminos" => 18.0, "Magdalena" => 15.0, "Alberro" => 16.0,
        "Santa Maria del Rosario" => 14.0, "Lotería" => 15.0
    ],
    "Regla" => [
        "Guaicanamar" => 8.0, "Loma Modelo" => 8.5, "Casablanca" => 9.0
    ],
    "Guanabacoa" => [
        "Villa I" => 10.0, "Villa II" => 10.5, "Chibas" => 9.5, "D'Beche" => 10.0, 
        "Mañana" => 10.0, "Minas" => 12.0, "Peñalver" => 14.0
    ],
    "La Habana del Este" => [
        "Camilo Cienfuegos" => 9.0, "Cojímar" => 11.0, "Guiteras" => 10.0, "Alamar" => 12.0, 
        "Guanabo" => 25.0, "Campo Florido" => 20.0
    ]
];

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
