<?php
// ARCHIVO: shop.php - VERSIÓN PREMIUM SHOPGRIDS
// Diseño profesional e-commerce responsive con carrusel y notas

ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'config_loader.php';

$EMP_ID = intval($config['id_empresa']);
$SUC_ID = intval($config['id_sucursal']);
$ALM_ID = intval($config['id_almacen']);
$TARIFA_KM = floatval($config['mensajeria_tarifa_km'] ?? 150);

try {
    require_once 'db.php';
    date_default_timezone_set('America/Havana');
    $pdo->exec("SET time_zone = '-05:00';");
} catch (Exception $e) { die("Error DB: " . $e->getMessage()); }

// =========================================================
//  API: GEOLOCALIZACIÓN
// =========================================================
if (isset($_GET['action_geo'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache');
    
    $act = $_GET['action_geo'];
    $locations = [
        "Cerro" => ["El Canal" => 0.5, "Pilar-Atares" => 2.0, "Cerro" => 1.5, "Las Cañas" => 2.5, "Palatino" => 3.0, "Armada" => 2.0, "Latinoamericano" => 1.0],
        "Plaza" => ["Rampa" => 4.0, "Vedado" => 4.5, "Carmelo" => 5.5, "Príncipe" => 2.5, "Plaza" => 3.0, "Nuevo Vedado" => 4.0],
        "Centro Habana" => ["Cayo Hueso" => 3.0, "Pueblo Nuevo" => 3.5, "Los Sitios" => 3.0, "Dragones" => 3.5, "Colón" => 4.0],
        "Habana Vieja" => ["Prado" => 4.5, "Catedral" => 5.0, "Plaza Vieja" => 5.0, "Belén" => 5.0, "San Isidro" => 5.5],
        "Diez de Octubre" => ["Luyanó" => 4.0, "Jesús del Monte" => 3.5, "Lawton" => 5.0, "Víbora" => 4.5, "Santos Suárez" => 3.5, "Sevillano" => 4.5],
        "Playa" => ["Miramar" => 7.0, "Buena Vista" => 6.0, "Ceiba" => 5.0, "Siboney" => 9.0, "Santa Fe" => 12.0],
        "Marianao" => ["Los Ángeles" => 6.0, "Pocito" => 7.0, "Zamora" => 6.5, "Libertad" => 6.0],
        "La Lisa" => ["La Lisa" => 9.0, "El Cano" => 11.0, "Punta Brava" => 12.0, "San Agustín" => 10.0],
        "Boyeros" => ["Santiago de las Vegas" => 15.0, "Boyeros" => 12.0, "Wajay" => 11.0, "Calabazar" => 9.0, "Altahabana" => 7.0],
        "Arroyo Naranjo" => ["Los Pinos" => 7.0, "Poey" => 8.0, "Vibora Park" => 7.0, "Mantilla" => 9.0, "Managua" => 15.0],
        "San Miguel" => ["Rocafort" => 6.0, "Luyanó Moderno" => 7.0, "Diezmero" => 8.0, "San Francisco" => 10.0],
        "Cotorro" => ["San Pedro" => 16.0, "Cuatro Caminos" => 18.0, "Santa Maria" => 14.0],
        "Regla" => ["Guaicanamar" => 8.0, "Casablanca" => 9.0],
        "Guanabacoa" => ["Villa I" => 10.0, "Chibas" => 9.5, "Minas" => 12.0],
        "Habana del Este" => ["Camilo Cienfuegos" => 9.0, "Cojímar" => 11.0, "Guiteras" => 10.0, "Alamar" => 12.0, "Guanabo" => 25.0]
    ];

    if ($act === 'list_mun') echo json_encode(array_keys($locations));
    elseif ($act === 'list_bar') echo json_encode(isset($locations[$_GET['m'] ?? '']) ? array_keys($locations[$_GET['m']]) : []);
    elseif ($act === 'calc') {
        $dist = $locations[$_GET['m'] ?? ''][$_GET['b'] ?? ''] ?? 0;
        echo json_encode(['costo' => round(100 + ($dist * $TARIFA_KM), 2)]);
    }
    exit;
}

// =========================================================
//  API: BÚSQUEDA AJAX
// =========================================================
if (isset($_GET['ajax_search'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache');
    
    $q = trim($_GET['ajax_search'] ?? '');
    if (strlen($q) < 1) { echo json_encode([]); exit; }

    try {
        $sql = "SELECT p.codigo, p.nombre, p.precio, p.descripcion, p.categoria, p.unidad_medida, p.color,
                COALESCE((SELECT SUM(s.cantidad) FROM stock_almacen s WHERE s.id_producto = p.codigo AND s.id_almacen = ?), 0) as stock
                FROM productos p WHERE p.es_web = 1 AND p.activo = 1 AND p.id_empresa = ?
                AND (p.nombre LIKE ? OR p.codigo LIKE ? OR p.descripcion LIKE ?)
                ORDER BY CASE WHEN p.nombre = ? THEN 1 WHEN p.codigo = ? THEN 2 WHEN p.nombre LIKE ? THEN 3 ELSE 4 END, p.nombre ASC LIMIT 15";
        
        $searchPattern = "%$q%";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$ALM_ID, $EMP_ID, $searchPattern, $searchPattern, $searchPattern, $q, $q, "$q%"]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($results as &$r) {
            $r['precio'] = floatval($r['precio']);
            $r['stock'] = floatval($r['stock']);
            $r['hasStock'] = $r['stock'] > 0;
            $localPath = __DIR__ . '/../product_images/' . $r['codigo'] . '.jpg';
            $r['hasImg'] = file_exists($localPath);
            $r['imgUrl'] = $r['hasImg'] ? "image.php?code=" . urlencode($r['codigo']) : null;
            $r['bg'] = "#" . substr(md5($r['nombre']), 0, 6);
            $r['initials'] = mb_strtoupper(mb_substr($r['nombre'], 0, 2));
        }
        
        echo json_encode($results, JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) { 
        http_response_code(500);
        echo json_encode(['error' => true, 'message' => $e->getMessage()]); 
    }
    exit;
}

// CARGA INICIAL
$sort = $_GET['sort'] ?? 'categoria_asc';
$catFilter = trim($_GET['cat'] ?? '');
$localImgPath = '/var/www/assets/product_images/';

try {
    $stmtCats = $pdo->query("SELECT DISTINCT categoria FROM productos WHERE activo=1 AND es_web=1 AND es_materia_prima=0 AND id_empresa=$EMP_ID ORDER BY categoria ASC");
    $categoriasDB = $stmtCats->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) { $categoriasDB = []; }

try {
    $sql = "SELECT p.*, p.codigo as id, (SELECT COALESCE(SUM(s.cantidad), 0) FROM stock_almacen s WHERE s.id_producto = p.codigo AND s.id_almacen = $ALM_ID) as stock_total 
            FROM productos p WHERE p.activo = 1 AND p.es_web = 1 AND p.es_materia_prima = 0 AND p.id_empresa = $EMP_ID";
    
    $params = [];
    if ($catFilter) { $sql .= " AND p.categoria = :cat"; $params[':cat'] = $catFilter; }

    switch ($sort) {
        case 'price_asc': $sql .= " ORDER BY p.precio ASC"; break;
        case 'price_desc': $sql .= " ORDER BY p.precio DESC"; break;
        default: $sql .= " ORDER BY p.categoria ASC, p.nombre ASC"; break;
    }
    $sql .= " LIMIT 200";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $productos = []; }

ob_end_flush();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PalWeb Shop - Sucursal <?= $SUC_ID ?></title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        :root {
            --primary: #0d6efd;
            --secondary: #6c757d;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --light: #f8f9fa;
            --dark: #1f2937;
            --border: #e5e7eb;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; 
            background: #f9fafb;
            color: var(--dark);
            line-height: 1.6;
        }

        /* ===== NAVBAR PREMIUM ===== */
        .navbar-premium {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        .navbar-brand-premium {
            font-size: 1.5rem;
            font-weight: 800;
            color: white !important;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }
        
        .badge-sucursal {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            border: 1px solid rgba(255,255,255,0.3);
        }

        /* ===== BUSCADOR PREMIUM ===== */
        .search-wrapper {
            position: relative;
            max-width: 600px;
            width: 100%;
        }
        
        .search-input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 3rem;
            border: none;
            border-radius: 50px;
            background: rgba(255,255,255,0.9);
            font-size: 0.95rem;
            transition: all 0.3s;
            backdrop-filter: blur(10px);
        }
        
        .search-input:focus {
            outline: none;
            background: white;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #6b7280;
        }
        
        .search-results {
            position: absolute;
            top: calc(100% + 0.5rem);
            left: 0;
            right: 0;
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            max-height: 400px;
            overflow-y: auto;
            z-index: 9999;
            display: none;
        }
        
        .search-item {
            padding: 0.875rem 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border-bottom: 1px solid var(--border);
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .search-item:hover { background: #f3f4f6; }
        .search-item:last-child { border-bottom: none; }
        
        .search-thumb {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            object-fit: cover;
            flex-shrink: 0;
        }
        
        .search-placeholder {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: white;
            flex-shrink: 0;
        }
        
        .search-info {
            flex: 1;
            min-width: 0;
        }
        
        .search-name {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.125rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .search-price {
            font-weight: 700;
            color: var(--primary);
            font-size: 1.125rem;
        }

        /* ===== CARRUSEL OFERTAS ===== */
        .promo-carousel {
            margin: 2rem 0;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
        }
        
        .promo-slide {
            height: 93px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            padding: 1rem;
            text-align: center;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .promo-slide::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: pulse 8s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 0.5; }
            50% { transform: scale(1.1); opacity: 0.8; }
        }
        
        .promo-slide h2 {
            font-size: 1.25rem;
            font-weight: 800;
            margin-bottom: 0.25rem;
            position: relative;
            z-index: 1;
        }
        
        .promo-slide p {
            font-size: 0.75rem;
            font-weight: 500;
            position: relative;
            z-index: 1;
        }
        
        .gradient-1 { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .gradient-2 { background: linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%); }
        .gradient-3 { background: linear-gradient(135deg, #84fab0 0%, #8fd3f4 100%); }
        .gradient-4 { background: linear-gradient(135deg, #fccb90 0%, #d57eeb 100%); }

        /* ===== FILTROS ===== */
        .filter-section {
            background: white;
            padding: 1.5rem;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin: 2rem 0;
        }
        
        .category-pills {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }
        
        .cat-pill {
            padding: 0.5rem 1.25rem;
            border-radius: 50px;
            background: var(--light);
            color: var(--dark);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s;
            border: 2px solid transparent;
        }
        
        .cat-pill:hover, .cat-pill.active {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(13,110,253,0.3);
        }

        /* ===== PRODUCTOS GRID ===== */
        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 1.5rem;
            margin: 2rem 0;
        }
        
        @media (min-width: 1400px) {
            .products-grid {
                grid-template-columns: repeat(5, 1fr);
            }
        }
        
        @media (min-width: 992px) and (max-width: 1399px) {
            .products-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }
        
        .product-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: all 0.3s;
            cursor: pointer;
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        
        .product-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 24px rgba(0,0,0,0.15);
        }
        
        .product-image-wrapper {
            position: relative;
            height: 240px;
            overflow: hidden;
            background: #f3f4f6;
        }
        
        .product-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s;
        }
        
        .product-card:hover .product-image {
            transform: scale(1.1);
        }
        
        .product-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            font-weight: 800;
            color: white;
        }
        
        .stock-badge {
            position: absolute;
            top: 0.75rem;
            right: 0.75rem;
            padding: 0.375rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 700;
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .stock-badge.in-stock { color: var(--success); }
        .stock-badge.out-of-stock { color: var(--danger); }
        
        .product-body {
            padding: 0.875rem;
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        
        .product-category {
            color: #6b7280;
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 0.25rem;
        }
        
        .product-name {
            font-size: 0.875rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.5rem;
            line-height: 1.3;
        }
        
        .product-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: auto;
            gap: 0.5rem;
        }
        
        .product-price {
            font-size: 1.125rem;
            font-weight: 800;
            color: var(--primary);
        }
        
        .btn-add-cart {
            padding: 0.5rem 0.875rem;
            border-radius: 50px;
            background: var(--primary);
            color: white;
            border: none;
            font-weight: 600;
            font-size: 0.75rem;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .btn-add-cart:hover:not(:disabled) {
            background: #0b5ed7;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(13,110,253,0.4);
        }
        
        .btn-add-cart:disabled {
            background: #9ca3af;
            cursor: not-allowed;
        }

        /* ===== CARRITO FLOTANTE ===== */
        .cart-float {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary) 0%, #0b5ed7 100%);
            color: white;
            border: none;
            box-shadow: 0 8px 16px rgba(13,110,253,0.4);
            z-index: 999;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .cart-float:hover {
            transform: scale(1.1);
            box-shadow: 0 12px 24px rgba(13,110,253,0.5);
        }
        
        .cart-badge {
            position: absolute;
            top: -4px;
            right: -4px;
            background: var(--danger);
            color: white;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 700;
            border: 2px solid white;
        }

        /* ===== MODAL CHECKOUT CON SCROLL ===== */
        .modal-scrollable {
            max-height: 70vh;
            overflow-y: auto;
            padding-right: 8px;
        }
        
        .modal-scrollable::-webkit-scrollbar {
            width: 8px;
        }
        
        .modal-scrollable::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        .modal-scrollable::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }
        
        .modal-scrollable::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
        
        .form-group {
            margin-bottom: 1.25rem;
        }
        
        .form-label {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
            display: block;
        }
        
        .form-control {
            border-radius: 12px;
            border: 2px solid var(--border);
            padding: 0.75rem 1rem;
            font-size: 0.95rem;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(13,110,253,0.1);
            outline: none;
        }
        
        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }
        
        .delivery-box {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 12px;
            margin: 1rem 0;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            font-size: 1rem;
        }
        
        .summary-total {
            border-top: 2px solid var(--border);
            margin-top: 1rem;
            padding-top: 1rem;
            font-size: 1.5rem;
            font-weight: 800;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .products-grid {
                grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
                gap: 1rem;
            }
            
            .promo-slide { height: 80px; padding: 0.75rem; }
            .promo-slide h2 { font-size: 1rem; margin-bottom: 0.125rem; }
            .promo-slide p { font-size: 0.65rem; }
            .product-image-wrapper { height: 180px; }
            .cart-float { width: 56px; height: 56px; bottom: 1rem; right: 1rem; }
        }
    </style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar-premium">
    <div class="container">
        <div class="d-flex align-items-center justify-content-between w-100 flex-wrap gap-3">
            <a href="shop.php" class="navbar-brand-premium">
                <i class="fas fa-store"></i>
                <span>PalWeb Shop</span>
                <span class="badge-sucursal">Sucursal <?= $SUC_ID ?></span>
            </a>
            
            <div class="search-wrapper">
                <i class="fas fa-search search-icon"></i>
                <input type="text" id="ajaxSearchInput" class="search-input" placeholder="Buscar productos..." autocomplete="off">
                <div id="searchResults" class="search-results"></div>
            </div>
            
            <a href="dashboard.php" class="btn btn-light btn-sm px-3">
                <i class="fas fa-cog me-1"></i> Admin
            </a>
        </div>
    </div>
</nav>

<!-- CARRUSEL OFERTAS -->
<div class="container">
    <div id="promoCarousel" class="carousel slide promo-carousel" data-bs-ride="carousel">
        <div class="carousel-indicators">
            <button type="button" data-bs-target="#promoCarousel" data-bs-slide-to="0" class="active"></button>
            <button type="button" data-bs-target="#promoCarousel" data-bs-slide-to="1"></button>
            <button type="button" data-bs-target="#promoCarousel" data-bs-slide-to="2"></button>
            <button type="button" data-bs-target="#promoCarousel" data-bs-slide-to="3"></button>
        </div>
        <div class="carousel-inner">
            <div class="carousel-item active">
                <div class="promo-slide gradient-1">
                    <h2>🚀 Envíos a La Habana</h2>
                    <p>Calcula el costo de envío a tu barrio</p>
                </div>
            </div>
            <div class="carousel-item">
                <div class="promo-slide gradient-2">
                    <h2>🍰 Dulces Frescos</h2>
                    <p>Hechos cada mañana con amor</p>
                </div>
            </div>
            <div class="carousel-item">
                <div class="promo-slide gradient-3">
                    <h2>🛒 Ofertas Semanales</h2>
                    <p>Descuentos en productos seleccionados</p>
                </div>
            </div>
            <div class="carousel-item">
                <div class="promo-slide gradient-4">
                    <h2>🍕 Comida Caliente</h2>
                    <p>Lista para llevar o entregar</p>
                </div>
            </div>
        </div>
        <button class="carousel-control-prev" type="button" data-bs-target="#promoCarousel" data-bs-slide="prev">
            <span class="carousel-control-prev-icon"></span>
        </button>
        <button class="carousel-control-next" type="button" data-bs-target="#promoCarousel" data-bs-slide="next">
            <span class="carousel-control-next-icon"></span>
        </button>
    </div>
</div>

<!-- FILTROS -->
<div class="container">
    <div class="filter-section">
        <div class="row align-items-center">
            <div class="col-md-6">
                <h5 class="mb-3 mb-md-0 fw-bold">
                    <i class="fas fa-filter me-2"></i>Categorías
                </h5>
            </div>
            <div class="col-md-6 text-md-end">
                <select class="form-select d-inline-block w-auto" onchange="location.href='?cat=<?= urlencode($catFilter) ?>&sort='+this.value">
                    <option value="categoria_asc" <?= $sort==='categoria_asc'?'selected':'' ?>>Ordenar: Categoría</option>
                    <option value="price_asc" <?= $sort==='price_asc'?'selected':'' ?>>Precio: Menor a Mayor</option>
                    <option value="price_desc" <?= $sort==='price_desc'?'selected':'' ?>>Precio: Mayor a Menor</option>
                </select>
            </div>
        </div>
        
        <div class="category-pills">
            <a href="?sort=<?= htmlspecialchars($sort) ?>" class="cat-pill <?= empty($catFilter)?'active':'' ?>">
                <i class="fas fa-th me-1"></i> Todas
            </a>
            <?php foreach($categoriasDB as $c): ?>
                <a href="?cat=<?= urlencode($c) ?>&sort=<?= htmlspecialchars($sort) ?>" 
                   class="cat-pill <?= $c===$catFilter?'active':'' ?>">
                    <?= htmlspecialchars($c) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- PRODUCTOS GRID -->
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold mb-0">Productos Disponibles</h4>
        <span class="badge bg-primary" style="font-size: 1rem; padding: 0.5rem 1rem;">
            <?= count($productos) ?> productos
        </span>
    </div>
    
    <div class="products-grid">
        <?php foreach($productos as $p): 
            $imgPath = $localImgPath . $p['codigo'] . '.jpg';
            $hasImg = file_exists($imgPath);
            $imgUrl = $hasImg ? 'image.php?code=' . urlencode($p['codigo']) : null;
            $stock = floatval($p['stock_total'] ?? 0);
            $hasStock = $stock > 0;
            $bg = "#" . substr(md5($p['nombre']), 0, 6);
            $initials = mb_strtoupper(mb_substr($p['nombre'], 0, 2));
        ?>
        <div class="product-card" onclick='openProductDetail(<?= json_encode([
            "id" => $p['codigo'],
            "name" => $p['nombre'],
            "price" => floatval($p['precio']),
            "desc" => $p['descripcion'] ?? '',
            "img" => $imgUrl,
            "bg" => $bg,
            "initials" => $initials,
            "hasImg" => $hasImg,
            "hasStock" => $hasStock,
            "unit" => $p['unidad_medida'] ?? '',
            "cat" => $p['categoria'] ?? '',
            "stock" => $stock
        ], JSON_UNESCAPED_UNICODE) ?>)'>
            <div class="product-image-wrapper">
                <?php if($hasImg): ?>
                    <img src="<?= $imgUrl ?>" class="product-image" alt="<?= htmlspecialchars($p['nombre']) ?>">
                <?php else: ?>
                    <div class="product-placeholder" style="background: <?= $bg ?>cc"><?= $initials ?></div>
                <?php endif; ?>
                
                <div class="stock-badge <?= $hasStock ? 'in-stock' : 'out-of-stock' ?>">
                    <?= $hasStock ? '✓ Stock: '.number_format($stock, 0) : '✗ Agotado' ?>
                </div>
            </div>
            
            <div class="product-body">
                <div class="product-category"><?= htmlspecialchars($p['categoria'] ?? 'General') ?></div>
                <h6 class="product-name"><?= htmlspecialchars($p['nombre']) ?></h6>
                
                <div class="product-footer">
                    <div class="product-price">$<?= number_format($p['precio'], 2) ?></div>
                    <button class="btn-add-cart" 
                            onclick="event.stopPropagation(); addToCart('<?= $p['codigo'] ?>', '<?= htmlspecialchars($p['nombre']) ?>', <?= $p['precio'] ?>)"
                            <?= !$hasStock ? 'disabled' : '' ?>>
                        <?= $hasStock ? '+ Agregar' : 'Agotado' ?>
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- CARRITO FLOTANTE -->
<button class="cart-float" onclick="openCart()">
    <i class="fas fa-shopping-cart"></i>
    <span id="cartBadge" class="cart-badge d-none">0</span>
</button>

<!-- MODAL DETALLE PRODUCTO -->
<div class="modal fade" id="modalDetail" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="border-radius: 20px; border: none;">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold" id="detailName">Producto</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <img id="detailImg" class="d-none" style="max-height: 350px; width: 100%; object-fit: cover; border-radius: 16px; margin-bottom: 1.5rem;">
                <div id="detailPlaceholder" class="d-none" style="height: 350px; border-radius: 16px; margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: center; font-size: 4rem; font-weight: 800; color: white;"></div>
                <h3 id="detailPrice" class="fw-bold mb-3" style="color: var(--primary);">$0.00</h3>
                <p id="detailDesc" class="text-muted"></p>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                <button type="button" id="btnAddDetail" class="btn btn-primary btn-lg px-4">AGREGAR AL CARRITO</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL CARRITO -->
<div class="modal fade" id="modalCart" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content" style="border-radius: 20px; border: none;">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold"><i class="fas fa-shopping-cart me-2"></i>Tu Carrito</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            
            <!-- VISTA 1: ITEMS -->
            <div id="cartItemsView">
                <div class="modal-body">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Producto</th>
                                <th class="text-center">Cantidad</th>
                                <th class="text-end">Subtotal</th>
                                <th class="text-end"></th>
                            </tr>
                        </thead>
                        <tbody id="cartTableBody"></tbody>
                    </table>
                    <div class="text-end fs-3 fw-bold">Total: $<span id="cartTotal">0.00</span></div>
                </div>
                <div class="modal-footer border-0">
                    <button class="btn btn-outline-danger" onclick="clearCart()">
                        <i class="fas fa-trash me-1"></i> Vaciar Carrito
                    </button>
                    <button class="btn btn-primary btn-lg px-4" onclick="showCheckout()">
                        Proceder al Pago <i class="fas fa-arrow-right ms-2"></i>
                    </button>
                </div>
            </div>
            
            <!-- VISTA 2: CHECKOUT CON SCROLL -->
            <div id="checkoutView" style="display:none;">
                <form onsubmit="submitOrder(event)">
                    <div class="modal-body modal-scrollable">
                        <h5 class="fw-bold mb-4"><i class="fas fa-user me-2"></i>Datos del Cliente</h5>
                        
                        <div class="form-group">
                            <label class="form-label">Nombre Completo *</label>
                            <input type="text" id="cliName" class="form-control" placeholder="Ej: Juan Pérez" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Teléfono *</label>
                            <input type="tel" id="cliTel" class="form-control" placeholder="Ej: +53 5555-5555" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Dirección *</label>
                            <textarea id="cliDir" class="form-control" rows="2" placeholder="Calle, número, entre calles..." required></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Fecha de Entrega/Recogida *</label>
                            <input type="date" id="cliDate" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Notas Adicionales</label>
                            <textarea id="cliNotes" class="form-control" rows="3" placeholder="Ej: El cake de relleno chocolate y color rosado el merengue. Entregar antes de las 3 PM."></textarea>
                            <small class="text-muted">Especifica detalles importantes como colores, sabores, horarios, etc.</small>
                        </div>
                        
                        <div class="form-check mb-3" style="padding: 1rem; background: #f8f9fa; border-radius: 12px;">
                            <input class="form-check-input" type="checkbox" id="delHome" onchange="toggleDelivery()">
                            <label class="form-check-label fw-bold" for="delHome">
                                <i class="fas fa-truck me-2"></i>Entrega a domicilio
                            </label>
                        </div>
                        
                        <div id="deliveryBox" class="delivery-box" style="display:none;">
                            <h6 class="fw-bold mb-3"><i class="fas fa-map-marker-alt me-2"></i>Ubicación de Entrega</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Municipio</label>
                                    <select id="cMun" class="form-control" onchange="loadBarrios()">
                                        <option value="">Seleccione municipio...</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Barrio</label>
                                    <select id="cBar" class="form-control" onchange="calcShip()">
                                        <option value="">Seleccione barrio...</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-4 p-4" style="background: #f8f9fa; border-radius: 12px;">
                            <h6 class="fw-bold mb-3">Resumen del Pedido</h6>
                            <div class="summary-row">
                                <span>Subtotal Productos:</span>
                                <span class="fw-bold">$<span id="cartTotal2">0.00</span></span>
                            </div>
                            <div class="summary-row">
                                <span>Costo de Envío:</span>
                                <span class="fw-bold">$<span id="shipCost">0.00</span></span>
                            </div>
                            <div class="summary-row summary-total">
                                <span>TOTAL A PAGAR:</span>
                                <span style="color: var(--primary);">$<span id="grandTotal">0.00</span></span>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-secondary" onclick="showCartView()">
                            <i class="fas fa-arrow-left me-1"></i> Volver
                        </button>
                        <button type="submit" class="btn btn-success btn-lg px-4">
                            <i class="fas fa-check-circle me-2"></i> Confirmar Pedido
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- VISTA 3: ÉXITO -->
            <div id="successView" style="display:none;">
                <div class="modal-body text-center py-5">
                    <div style="font-size: 5rem; margin-bottom: 1rem;">✅</div>
                    <h3 class="fw-bold mb-3">¡Pedido Recibido!</h3>
                    <p class="text-muted fs-5">Gracias por tu compra. Te contactaremos pronto para confirmar tu orden.</p>
                </div>
                <div class="modal-footer border-0 justify-content-center">
                    <button class="btn btn-primary btn-lg px-5" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
    const modalDetail = new bootstrap.Modal(document.getElementById('modalDetail'));
    const modalCart = new bootstrap.Modal(document.getElementById('modalCart'));
    let cart = JSON.parse(localStorage.getItem('palweb_cart') || '[]');

    // ===== BUSCADOR AJAX =====
    const searchInput = document.getElementById('ajaxSearchInput');
    const searchResults = document.getElementById('searchResults');
    let searchTimeout = null;
    let currentRequest = null;

    searchInput.addEventListener('input', function() {
        const query = this.value.trim();
        clearTimeout(searchTimeout);
        
        if (query.length < 1) {
            searchResults.style.display = 'none';
            searchResults.innerHTML = '';
            return;
        }

        searchResults.innerHTML = '<div class="p-3 text-center text-muted">🔍 Buscando...</div>';
        searchResults.style.display = 'block';

        searchTimeout = setTimeout(async () => {
            try {
                if (currentRequest) currentRequest.abort();
                const controller = new AbortController();
                currentRequest = controller;
                
                const response = await fetch(`shop.php?ajax_search=${encodeURIComponent(query)}`, {
                    signal: controller.signal
                });
                
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    throw new Error('Respuesta inválida');
                }
                
                const data = await response.json();
                searchResults.innerHTML = '';
                
                if (data.error) {
                    searchResults.innerHTML = `<div class="p-3 text-center text-danger">❌ ${data.message}</div>`;
                    return;
                }
                
                if (data.length > 0) {
                    data.forEach(item => {
                        const div = document.createElement('div');
                        div.className = 'search-item';
                        
                        const imgHTML = item.hasImg 
                            ? `<img src="${item.imgUrl}" class="search-thumb" alt="${item.nombre}">`
                            : `<div class="search-placeholder" style="background: ${item.bg}">${item.initials}</div>`;
                        
                        const stockHTML = item.hasStock 
                            ? `<small class="text-success">✓ En stock: ${item.stock}</small>`
                            : `<small class="text-danger">✗ Agotado</small>`;
                        
                        div.innerHTML = `
                            ${imgHTML}
                            <div class="search-info">
                                <div class="search-name">${item.nombre}</div>
                                <small class="text-muted">${item.categoria || ''}</small>
                                ${stockHTML}
                            </div>
                            <div class="search-price">$${parseFloat(item.precio).toFixed(2)}</div>
                        `;
                        
                        div.addEventListener('click', function(e) {
                            e.stopPropagation();
                            searchInput.value = '';
                            searchResults.style.display = 'none';
                            openProductDetail({
                                id: item.codigo, name: item.nombre, price: item.precio,
                                desc: item.descripcion || '', img: item.imgUrl, bg: item.bg,
                                initials: item.initials, hasImg: item.hasImg, hasStock: item.hasStock,
                                unit: item.unidad_medida || '', cat: item.categoria || '', stock: item.stock
                            });
                        });
                        
                        searchResults.appendChild(div);
                    });
                    searchResults.style.display = 'block';
                } else {
                    searchResults.innerHTML = '<div class="p-3 text-center text-muted">No se encontraron productos</div>';
                    searchResults.style.display = 'block';
                }
            } catch (error) {
                if (error.name !== 'AbortError') {
                    console.error('Error:', error);
                    searchResults.innerHTML = '<div class="p-3 text-center text-danger">Error al buscar</div>';
                }
            }
        }, 300);
    });

    document.addEventListener('click', function(e) {
        if (!document.querySelector('.search-wrapper').contains(e.target)) {
            searchResults.style.display = 'none';
        }
    });

    // ===== GEOLOCALIZACIÓN =====
    async function loadMunicipios() {
        const res = await fetch('shop.php?action_geo=list_mun');
        const muns = await res.json();
        const sel = document.getElementById('cMun');
        sel.innerHTML = '<option value="">Seleccione municipio...</option>';
        muns.forEach(m => sel.innerHTML += `<option value="${m}">${m}</option>`);
    }
    
    async function loadBarrios() {
        const mun = document.getElementById('cMun').value;
        const res = await fetch(`shop.php?action_geo=list_bar&m=${encodeURIComponent(mun)}`);
        const bars = await res.json();
        const sel = document.getElementById('cBar');
        sel.innerHTML = '<option value="">Seleccione barrio...</option>';
        bars.forEach(b => sel.innerHTML += `<option value="${b}">${b}</option>`);
        calcShip();
    }
    
    async function calcShip() {
        const mun = document.getElementById('cMun').value;
        const bar = document.getElementById('cBar').value;
        if (mun && bar) {
            const res = await fetch(`shop.php?action_geo=calc&m=${encodeURIComponent(mun)}&b=${encodeURIComponent(bar)}`);
            const data = await res.json();
            document.getElementById('shipCost').innerText = data.costo.toFixed(2);
        } else {
            document.getElementById('shipCost').innerText = '0.00';
        }
        calcTotal();
    }
    
    function toggleDelivery() {
        const isDel = document.getElementById('delHome').checked;
        document.getElementById('deliveryBox').style.display = isDel ? 'block' : 'none';
        if (isDel && document.getElementById('cMun').options.length === 1) loadMunicipios();
        if (!isDel) document.getElementById('shipCost').innerText = '0.00';
        calcTotal();
    }
    
    function calcTotal() {
        const prod = parseFloat(document.getElementById('cartTotal').innerText);
        const ship = parseFloat(document.getElementById('shipCost').innerText);
        document.getElementById('grandTotal').innerText = (prod + ship).toFixed(2);
        document.getElementById('cartTotal2').innerText = prod.toFixed(2);
    }

    // ===== CARRITO =====
    function updateCounters() {
        const q = cart.reduce((a, b) => a + b.qty, 0);
        const badge = document.getElementById('cartBadge');
        badge.innerText = q;
        badge.classList.toggle('d-none', q === 0);
        localStorage.setItem('palweb_cart', JSON.stringify(cart));
    }
    
    function addToCart(id, name, price) {
        const existing = cart.find(i => i.id === id);
        if (existing) existing.qty++;
        else cart.push({ id, name, price, qty: 1 });
        updateCounters();
    }
    
    function renderCart() {
        const tbody = document.getElementById('cartTableBody');
        tbody.innerHTML = '';
        let total = 0;
        
        if (cart.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-muted">Carrito vacío</td></tr>';
        } else {
            cart.forEach((item, index) => {
                const subtotal = item.qty * item.price;
                total += subtotal;
                tbody.innerHTML += `
                    <tr>
                        <td><div class="fw-bold">${item.name}</div></td>
                        <td class="text-center">
                            <div class="btn-group">
                                <button class="btn btn-sm btn-outline-secondary" onclick="modQty(${index}, -1)">-</button>
                                <span class="btn btn-sm btn-outline-secondary disabled">${item.qty}</span>
                                <button class="btn btn-sm btn-outline-secondary" onclick="modQty(${index}, 1)">+</button>
                            </div>
                        </td>
                        <td class="text-end fw-bold">$${subtotal.toFixed(2)}</td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-danger" onclick="remItem(${index})">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });
        }
        
        document.getElementById('cartTotal').innerText = total.toFixed(2);
        calcTotal();
    }
    
    function modQty(index, delta) {
        cart[index].qty += delta;
        if (cart[index].qty <= 0) cart.splice(index, 1);
        updateCounters();
        renderCart();
    }
    
    function remItem(index) {
        cart.splice(index, 1);
        updateCounters();
        renderCart();
        if (cart.length === 0) showCartView();
    }
    
    function clearCart() {
        if (confirm('¿Vaciar el carrito?')) {
            cart = [];
            updateCounters();
            renderCart();
        }
    }
    
    function openProductDetail(data) {
        document.getElementById('detailName').innerText = data.name;
        document.getElementById('detailPrice').innerText = '$' + parseFloat(data.price).toFixed(2);
        document.getElementById('detailDesc').innerText = data.desc || 'Sin descripción';
        
        const img = document.getElementById('detailImg');
        const placeholder = document.getElementById('detailPlaceholder');
        
        if (data.hasImg) {
            img.src = data.img;
            img.classList.remove('d-none');
            placeholder.classList.add('d-none');
        } else {
            placeholder.innerText = data.initials;
            placeholder.style.background = data.bg + 'cc';
            img.classList.add('d-none');
            placeholder.classList.remove('d-none');
        }
        
        const btn = document.getElementById('btnAddDetail');
        if (data.hasStock) {
            btn.disabled = false;
            btn.innerText = "AGREGAR AL CARRITO";
            btn.onclick = () => { addToCart(data.id, data.name, data.price); modalDetail.hide(); };
        } else {
            btn.disabled = true;
            btn.innerText = "PRODUCTO AGOTADO";
        }
        
        modalDetail.show();
    }

    function openCart() { showCartView(); renderCart(); modalCart.show(); }
    function showCartView() {
        document.getElementById('cartItemsView').style.display = 'block';
        document.getElementById('checkoutView').style.display = 'none';
        document.getElementById('successView').style.display = 'none';
    }
    
    function showCheckout() {
        if (cart.length === 0) return;
        document.getElementById('cartItemsView').style.display = 'none';
        document.getElementById('checkoutView').style.display = 'block';
        if (document.getElementById('delHome').checked && document.getElementById('cMun').options.length === 1) {
            loadMunicipios();
        }
    }

    async function submitOrder(e) {
        e.preventDefault();
        const btn = e.target.querySelector('button[type="submit"]');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Enviando...';
        
        let address = document.getElementById('cliDir').value;
        let shipCost = 0;
        const notes = document.getElementById('cliNotes').value;
        
        if (document.getElementById('delHome').checked) {
            const mun = document.getElementById('cMun').value;
            const bar = document.getElementById('cBar').value;
            shipCost = parseFloat(document.getElementById('shipCost').innerText);
            address = `[MENSAJERÍA: ${mun} - ${bar}] ${address}`;
        } else {
            address = "[RECOGIDA] " + address;
        }

        const data = {
            customer: {
                nombre: document.getElementById('cliName').value,
                telefono: document.getElementById('cliTel').value,
                direccion: address,
                fecha_programada: document.getElementById('cliDate').value,
                envio_costo: shipCost,
                notas: notes
            },
            items: cart,
            total: parseFloat(document.getElementById('grandTotal').innerText)
        };

        try {
            const res = await fetch('place_order.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            const json = await res.json();
            
            if (json.status === 'success') {
                cart = [];
                updateCounters();
                document.getElementById('checkoutView').style.display = 'none';
                document.getElementById('successView').style.display = 'block';
            } else {
                alert('Error: ' + json.error);
            }
        } catch (error) {
            alert('Error de red al enviar el pedido');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check-circle me-2"></i>Confirmar Pedido';
        }
    }
    
    updateCounters();
</script>
<?php include_once 'menu_master.php'; ?>
</body>
</html>

