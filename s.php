<?php
// ===================================================================
// ARCHIVO CORREGIDO - shop.php
// PROBLEMA SOLUCIONADO: FIND_IN_SET con sucursales_web
// ===================================================================

session_start();
ob_start();

// Configuración
require_once 'config_loader.php';

$EMP_ID = intval($config['id_empresa']);
$SUC_ID = intval($config['id_sucursal']);
$ALM_ID = intval($config['id_almacen']);
$TARIFA_KM = floatval($config['mensajeria_tarifa_km'] ?? 150);

try {
    require_once 'db.php';
    date_default_timezone_set('America/Havana');
    $pdo->exec("SET time_zone = '-05:00';");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) { 
    die("Error DB: " . $e->getMessage());
}

// =========================================================
//  API: GEOLOCALIZACIÓN
// =========================================================
if (isset($_GET['action_geo'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    
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
    elseif ($act === 'list_bar') {
        $m = $_GET['m'] ?? '';
        echo json_encode(isset($locations[$m]) ? array_keys($locations[$m]) : []);
    }
    elseif ($act === 'calc') {
        $m = $_GET['m'] ?? '';
        $b = $_GET['b'] ?? '';
        $dist = $locations[$m][$b] ?? 0;
        echo json_encode(['costo' => round(100 + ($dist * $TARIFA_KM), 2)]);
    }
    exit;
}

// =========================================================
//  API: BÚSQUEDA AJAX (✅ CORREGIDA)
// =========================================================
if (isset($_GET['ajax_search'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    
    $q = trim($_GET['ajax_search'] ?? '');
    if (strlen($q) < 1) { echo json_encode([]); exit; }

    try {
        // ✅ SOLUCIÓN: Simplificar el filtro de sucursales
        $sql = "SELECT p.codigo, p.nombre, p.precio, p.descripcion, p.categoria, p.unidad_medida, p.color,
                COALESCE((SELECT SUM(s.cantidad) FROM stock_almacen s 
                WHERE s.id_producto = p.codigo AND s.id_almacen = ?), 0) as stock
                FROM productos p 
                WHERE p.es_web = 1 
                  AND p.activo = 1 
                  AND p.id_empresa = ?
                  AND (p.sucursales_web = '' OR p.sucursales_web IS NULL OR FIND_IN_SET(?, p.sucursales_web) > 0)
                  AND (p.nombre LIKE ? OR p.codigo LIKE ? OR p.descripcion LIKE ?)
                ORDER BY CASE 
                    WHEN p.nombre = ? THEN 1 
                    WHEN p.codigo = ? THEN 2 
                    WHEN p.nombre LIKE ? THEN 3 
                    ELSE 4 END, p.nombre ASC 
                LIMIT 15";
        
        $searchPattern = "%$q%";
        $searchStart = "$q%";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $ALM_ID,          // 1
            $EMP_ID,          // 2
            $SUC_ID,          // 3
            $searchPattern,   // 4
            $searchPattern,   // 5
            $searchPattern,   // 6
            $q,               // 7
            $q,               // 8
            $searchStart      // 9
        ]);
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

// =========================================================
//  CARGA INICIAL (✅ CORREGIDA)
// =========================================================
$catFilter = trim($_GET['cat'] ?? '');
$sort = $_GET['sort'] ?? 'categoria_asc';
$localImgPath = '/home/marinero/product_images/';

// Obtener categorías
try {
    $catSql = "SELECT DISTINCT categoria FROM productos 
               WHERE activo=1 AND es_web=1 AND id_empresa=? 
               AND (sucursales_web = '' OR sucursales_web IS NULL OR FIND_IN_SET(?, sucursales_web) > 0)
               ORDER BY categoria";
    $catStmt = $pdo->prepare($catSql);
    $catStmt->execute([$EMP_ID, $SUC_ID]);
    $cats = $catStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $cats = [];
}

// ✅ CORREGIDO: Obtener productos con filtro simplificado
try {
    $sql = "SELECT p.*, 
            (SELECT COALESCE(SUM(s.cantidad), 0) 
             FROM stock_almacen s 
             WHERE s.id_producto = p.codigo AND s.id_almacen = ?) as stock_total 
            FROM productos p 
            WHERE p.activo = 1 
              AND p.es_web = 1 
              AND p.id_empresa = ?
              AND (p.sucursales_web = '' OR p.sucursales_web IS NULL OR FIND_IN_SET(?, p.sucursales_web) > 0)";

    $params = [$ALM_ID, $EMP_ID, $SUC_ID];
    
    if ($catFilter) {
        $sql .= " AND p.categoria = ?";
        $params[] = $catFilter;
    }

    $sortMap = [
        'categoria_asc' => 'p.categoria ASC, p.nombre ASC',
        'price_asc' => 'p.precio ASC',
        'price_desc' => 'p.precio DESC'
    ];
    
    $sql .= " ORDER BY " . ($sortMap[$sort] ?? 'p.categoria ASC, p.nombre ASC');
    $sql .= " LIMIT 200";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    echo "<!-- ERROR: " . $e->getMessage() . " -->\n";
    $productos = [];
}

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
    <link rel="stylesheet" href="assets/css/animate.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --primary-light: #e0e7ff;
            --secondary: #6c757d;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --dark: #1e293b;
            --light: #f8fafc;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            --gray-900: #0f172a;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 14px;
            --radius-xl: 18px;
        }
        
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
        }
        
        body { 
            font-family: 'Inter', sans-serif; 
            background: linear-gradient(135deg, var(--gray-50) 0%, var(--gray-100) 100%);
            color: var(--gray-900);
            line-height: 1.6;
            min-height: 100vh;
            font-size: 0.9375rem;
        }

        /* Toast Notifications */
        .toast-container {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-width: 360px;
        }

        .custom-toast {
            background: white;
            border-radius: var(--radius-md);
            padding: 14px 16px;
            box-shadow: var(--shadow-lg);
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideInRight 0.3s ease-out, fadeOut 0.5s ease 4.5s forwards;
            border-left: 3px solid var(--primary);
            opacity: 0;
            transform: translateX(100px);
            transition: all 0.3s ease;
        }

        .custom-toast.show {
            opacity: 1;
            transform: translateX(0);
        }

        .custom-toast.success {
            border-left-color: var(--success);
        }

        .custom-toast.error {
            border-left-color: var(--danger);
        }

        .custom-toast.warning {
            border-left-color: var(--warning);
        }

        .custom-toast.info {
            border-left-color: var(--primary);
        }

        .toast-icon {
            font-size: 20px;
            flex-shrink: 0;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: var(--gray-100);
        }

        .toast-success .toast-icon { 
            color: var(--success);
            background: rgba(16, 185, 129, 0.1);
        }

        .toast-error .toast-icon { 
            color: var(--danger);
            background: rgba(239, 68, 68, 0.1);
        }

        .toast-warning .toast-icon { 
            color: var(--warning);
            background: rgba(245, 158, 11, 0.1);
        }

        .toast-info .toast-icon { 
            color: var(--primary);
            background: rgba(67, 97, 238, 0.1);
        }

        .toast-content {
            flex: 1;
            min-width: 0;
        }

        .toast-title {
            font-weight: 600;
            font-size: 0.875rem;
            margin-bottom: 2px;
            color: var(--gray-900);
            line-height: 1.3;
        }

        .toast-message {
            font-size: 0.8125rem;
            color: var(--gray-600);
            line-height: 1.4;
        }

        .toast-close {
            background: none;
            border: none;
            color: var(--gray-400);
            cursor: pointer;
            font-size: 16px;
            padding: 0;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.2s;
            flex-shrink: 0;
        }

        .toast-close:hover {
            background: var(--gray-100);
            color: var(--gray-700);
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(100px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes fadeOut {
            to {
                opacity: 0;
                transform: translateX(100px);
            }
        }

        /* Premium Navbar */
        .navbar-premium {
            background: linear-gradient(135deg, var(--gray-900) 0%, var(--gray-800) 100%);
            box-shadow: var(--shadow-lg);
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 1000;
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }
        
        .navbar-brand-premium {
            font-size: 1.5rem;
            font-weight: 700;
            color: white !important;
            display: flex;
            align-items: center;
            gap: 0.625rem;
            text-decoration: none;
            letter-spacing: -0.025em;
        }
        
        .badge-sucursal {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 0.375rem 0.875rem;
            border-radius: 50px;
            font-size: 0.8125rem;
            font-weight: 600;
            border: none;
            box-shadow: var(--shadow-md);
        }

        /* Enhanced Search */
        .search-wrapper {
            position: relative;
            max-width: 500px;
            width: 100%;
            transition: all 0.3s ease;
        }
        
        .search-wrapper:focus-within {
            transform: translateY(-1px);
        }
        
        .search-input {
            width: 100%;
            padding: 0.875rem 0.875rem 0.875rem 3rem;
            border: 1.5px solid rgba(255, 255, 255, 0.1);
            border-radius: 50px;
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(10px);
            font-size: 0.9375rem;
            color: white;
            transition: all 0.3s ease;
            font-weight: 400;
        }
        
        .search-input::placeholder {
            color: rgba(255, 255, 255, 0.6);
            font-weight: 400;
        }
        
        .search-input:focus {
            outline: none;
            background: rgba(255, 255, 255, 0.12);
            border-color: rgba(255, 255, 255, 0.2);
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.1);
        }
        
        .search-icon {
            position: absolute;
            left: 1.125rem;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255, 255, 255, 0.8);
            font-size: 1rem;
        }
        
        .search-results {
            position: absolute;
            top: calc(100% + 0.5rem);
            left: 0;
            right: 0;
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-xl);
            max-height: 400px;
            overflow-y: auto;
            z-index: 9999;
            display: none;
            border: 1px solid var(--gray-200);
            animation: slideDown 0.2s ease-out;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-8px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .search-item {
            padding: 0.875rem 1rem;
            display: flex;
            align-items: center;
            gap: 0.875rem;
            border-bottom: 1px solid var(--gray-100);
            cursor: pointer;
            transition: all 0.15s ease;
        }
        
        .search-item:hover { 
            background: var(--gray-50); 
        }
        
        .search-item:last-child {
            border-bottom: none;
        }
        
        .search-thumb {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-md);
            object-fit: cover;
            border: 1px solid var(--gray-200);
        }
        
        .search-placeholder {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1rem;
            color: white;
            border: 1px solid var(--gray-200);
        }

        /* Hero Section */
        .hero-section {
            margin: 1.5rem 0 2.5rem;
        }

        .promo-carousel {
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--gray-200);
        }
        
        .promo-slide {
            height: 140px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            padding: 1.5rem;
            text-align: center;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .promo-slide::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(0,0,0,0.15) 0%, rgba(0,0,0,0.08) 100%);
        }
        
        .promo-slide h2 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.375rem;
            position: relative;
            z-index: 1;
            text-shadow: 0 1px 2px rgba(0,0,0,0.2);
        }
        
        .promo-slide p {
            font-size: 0.9375rem;
            font-weight: 500;
            position: relative;
            z-index: 1;
            opacity: 0.95;
            max-width: 80%;
            margin: 0 auto;
        }
        
        .gradient-1 { background: linear-gradient(135deg, #4361ee 0%, #3a0ca3 100%); }
        .gradient-2 { background: linear-gradient(135deg, #f72585 0%, #7209b7 100%); }
        .gradient-3 { background: linear-gradient(135deg, #4cc9f0 0%, #4361ee 100%); }
        .gradient-4 { background: linear-gradient(135deg, #f48c06 0%, #dc2f02 100%); }

        /* =========================================== */
        /* NUEVO DISEÑO COMPACTO PARA FILTROS DE CATEGORÍAS */
        /* =========================================== */
        
        .category-filter-section {
            background: white;
            padding: 1.25rem 1.5rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            margin: 2rem 0;
            border: 1px solid var(--gray-200);
            position: sticky;
            top: 76px;
            z-index: 900;
            backdrop-filter: blur(8px);
            background: rgba(255, 255, 255, 0.95);
        }
        
        .filter-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
            padding-bottom: 0.875rem;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .filter-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--gray-900);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
        }
        
        .filter-title i {
            color: var(--primary);
            font-size: 1rem;
        }
        
        /* Selector de ordenación mejorado */
        .sort-select {
            padding: 0.5rem 2.5rem 0.5rem 1rem;
            border-radius: var(--radius-md);
            border: 1.5px solid var(--gray-300);
            background-color: white;
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--gray-800);
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23475569' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 16px;
            transition: all 0.2s ease;
            min-width: 160px;
        }
        
        .sort-select:hover {
            border-color: var(--gray-400);
        }
        
        .sort-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }
        
        /* Contenedor compacto de categorías */
        .category-container {
            position: relative;
            overflow: visible;
        }
        
        .category-scroll-wrapper {
            display: flex;
            gap: 0.5rem;
            overflow-x: auto;
            overflow-y: hidden;
            padding: 0.25rem 0.5rem;
            scrollbar-width: thin;
            scrollbar-color: var(--gray-300) transparent;
            -webkit-overflow-scrolling: touch;
        }
        
        .category-scroll-wrapper::-webkit-scrollbar {
            height: 4px;
        }
        
        .category-scroll-wrapper::-webkit-scrollbar-track {
            background: transparent;
            border-radius: 2px;
        }
        
        .category-scroll-wrapper::-webkit-scrollbar-thumb {
            background: var(--gray-300);
            border-radius: 2px;
        }
        
        .category-scroll-wrapper::-webkit-scrollbar-thumb:hover {
            background: var(--gray-400);
        }
        
        /* Botones de categoría compactos */
        .category-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.375rem;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.8125rem;
            font-weight: 500;
            text-decoration: none;
            white-space: nowrap;
            transition: all 0.2s ease;
            border: 1.5px solid transparent;
            background: var(--gray-100);
            color: var(--gray-700);
            min-height: 36px;
            flex-shrink: 0;
        }
        
        .category-btn i {
            font-size: 0.75rem;
            opacity: 0.7;
        }
        
        .category-btn:hover {
            background: var(--gray-200);
            color: var(--gray-900);
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
            border-color: var(--gray-300);
        }
        
        .category-btn.active {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            border-color: var(--primary);
            box-shadow: 0 2px 8px rgba(67, 97, 238, 0.25);
            font-weight: 600;
        }
        
        .category-btn.active i {
            color: white;
            opacity: 1;
        }
        
        /* Botón "Todas" especial */
        .category-all {
            position: relative;
            background: linear-gradient(135deg, var(--gray-100) 0%, var(--gray-200) 100%);
            font-weight: 600;
        }
        
        .category-all.active {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
        }
        
        /* Indicador visual para categoría activa */
        .category-btn.active::after {
            content: '';
            position: absolute;
            bottom: -6px;
            left: 50%;
            transform: translateX(-50%);
            width: 4px;
            height: 4px;
            background: var(--primary);
            border-radius: 50%;
            opacity: 0;
            animation: fadeIn 0.3s ease forwards;
        }
        
        @keyframes fadeIn {
            to { opacity: 1; }
        }
        
        /* Flechas de navegación para móvil */
        .category-nav {
            display: none;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid var(--gray-200);
        }
        
        .category-nav-btn {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            border: 1.5px solid var(--gray-300);
            background: white;
            color: var(--gray-600);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.875rem;
        }
        
        .category-nav-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--primary-light);
        }
        
        .category-nav-btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
            border-color: var(--gray-300);
            color: var(--gray-400);
            background: var(--gray-100);
        }
        
        /* Versión móvil más compacta */
        @media (max-width: 768px) {
            .category-filter-section {
                padding: 1rem 1.25rem;
                margin: 1.5rem 0;
                top: 70px;
            }
            
            .filter-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.75rem;
                margin-bottom: 0.75rem;
            }
            
            .sort-select {
                width: 100%;
                max-width: 100%;
            }
            
            .category-scroll-wrapper {
                padding: 0.25rem 0.25rem;
                gap: 0.375rem;
            }
            
            .category-btn {
                padding: 0.4375rem 0.875rem;
                font-size: 0.75rem;
                min-height: 32px;
            }
            
            .category-nav {
                display: flex;
            }
        }
        
        /* Product Grid Header */
        .products-header {
            background: white;
            padding: 1.25rem 1.5rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            margin-bottom: 1.5rem;
            border: 1px solid var(--gray-200);
        }
        
        .products-count {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 0.5rem 1.25rem;
            border-radius: 50px;
            font-size: 0.9375rem;
            font-weight: 600;
            box-shadow: var(--shadow-md);
        }
        
        /* Products Grid */
        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 1.75rem;
            margin: 2.5rem 0;
        }
        
        @media (min-width: 1400px) {
            .products-grid { grid-template-columns: repeat(5, 1fr); }
        }
        
        @media (min-width: 1200px) and (max-width: 1399px) {
            .products-grid { grid-template-columns: repeat(4, 1fr); }
        }
        
        @media (min-width: 992px) and (max-width: 1199px) {
            .products-grid { grid-template-columns: repeat(3, 1fr); }
        }
        
        @media (min-width: 768px) and (max-width: 991px) {
            .products-grid { grid-template-columns: repeat(2, 1fr); }
        }
        
        @media (max-width: 480px) {
            .products-grid {
                grid-template-columns: 1fr;
                gap: 1.25rem;
            }
        }
        
        /* Enhanced Product Cards */
        .product-card {
            background: white;
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-md);
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            cursor: pointer;
            height: 100%;
            display: flex;
            flex-direction: column;
            border: 1px solid var(--gray-200);
            position: relative;
        }
        
        .product-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--primary) 0%, var(--primary-dark) 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .product-card:hover {
            transform: translateY(-6px);
            box-shadow: var(--shadow-lg);
        }
        
        .product-card:hover::before {
            opacity: 1;
        }
        
        .product-image-wrapper {
            position: relative;
            height: 220px;
            overflow: hidden;
            background: linear-gradient(135deg, var(--gray-100) 0%, var(--gray-200) 100%);
        }
        
        .product-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }
        
        .product-card:hover .product-image {
            transform: scale(1.05);
        }
        
        .product-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            font-weight: 700;
            color: white;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
        }
        
        .stock-badge {
            position: absolute;
            top: 0.875rem;
            right: 0.875rem;
            padding: 0.375rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(8px);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .stock-badge.in-stock { 
            color: var(--success); 
            border-color: rgba(16, 185, 129, 0.3);
        }
        
        .stock-badge.out-of-stock { 
            color: var(--danger); 
            border-color: rgba(239, 68, 68, 0.3);
        }
        
        .product-body {
            padding: 1.25rem;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .product-category {
            color: var(--primary);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 0.125rem;
        }
        
        .product-name {
            font-size: 1rem;
            font-weight: 600;
            color: var(--gray-900);
            line-height: 1.4;
            flex: 1;
            margin-bottom: 0.25rem;
        }
        
        .product-description {
            color: var(--gray-600);
            font-size: 0.8125rem;
            line-height: 1.5;
            margin-top: 0.25rem;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .product-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 0.75rem;
            gap: 0.75rem;
        }
        
        .product-price {
            font-size: 1.375rem;
            font-weight: 700;
            color: var(--primary);
            letter-spacing: -0.025em;
        }
        
        .btn-add-cart {
            padding: 0.625rem 1.25rem;
            border-radius: 50px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            border: none;
            font-weight: 600;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.375rem;
            box-shadow: var(--shadow-sm);
        }
        
        .btn-add-cart:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
            background: linear-gradient(135deg, var(--primary-dark) 0%, #2d44b8 100%);
        }
        
        .btn-add-cart:disabled {
            background: var(--gray-300);
            color: var(--gray-700);
            cursor: not-allowed;
            box-shadow: none;
        }

        /* Enhanced Floating Cart Button */
        .cart-float {
            position: fixed;
            bottom: 1.5rem;
            right: 1.5rem;
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            border: none;
            box-shadow: 0 8px 20px rgba(67, 97, 238, 0.4);
            z-index: 990;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        
        .cart-float:hover {
            transform: scale(1.08);
            box-shadow: 0 12px 24px rgba(67, 97, 238, 0.5);
        }
        
        .cart-badge {
            position: absolute;
            top: -6px;
            right: -6px;
            background: linear-gradient(135deg, var(--danger) 0%, #dc2626 100%);
            color: white;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 700;
            border: 2.5px solid white;
            box-shadow: var(--shadow-sm);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        
        /* Enhanced Form Controls */
        .form-control, .form-select {
            border: 1.5px solid var(--gray-300);
            border-radius: var(--radius-md);
            padding: 0.75rem 1rem;
            font-size: 0.9375rem;
            transition: all 0.2s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        /* Enhanced Tables */
        .table {
            border-collapse: separate;
            border-spacing: 0;
            border-radius: var(--radius-md);
            overflow: hidden;
            border: 1px solid var(--gray-200);
            font-size: 0.9375rem;
        }

        .table thead th {
            background: var(--gray-100);
            border-bottom: 2px solid var(--gray-200);
            font-weight: 600;
            color: var(--gray-900);
            padding: 0.875rem 1rem;
            font-size: 0.875rem;
        }

        .table tbody td {
            padding: 0.875rem 1rem;
            vertical-align: middle;
            border-top: 1px solid var(--gray-200);
        }

        .table tbody tr:hover {
            background: var(--gray-50);
        }

        /* Enhanced Buttons */
        .btn {
            border-radius: var(--radius-md);
            font-weight: 600;
            padding: 0.625rem 1.25rem;
            transition: all 0.2s ease;
            font-size: 0.9375rem;
        }

        .btn-lg {
            padding: 0.875rem 1.75rem;
            font-size: 1rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border: none;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        /* Custom Alert */
        .custom-alert {
            border-radius: var(--radius-md);
            border-left: 3px solid;
            padding: 1.25rem;
            margin: 1rem 0;
            background: white;
            box-shadow: var(--shadow-sm);
        }

        .alert-info {
            border-left-color: var(--primary);
            background: linear-gradient(135deg, rgba(67, 97, 238, 0.03) 0%, rgba(67, 97, 238, 0.01) 100%);
        }
        
        /* Responsive Design */
        @media (max-width: 576px) {
            .navbar-premium {
                padding: 0.875rem 0;
            }
            
            .navbar-brand-premium {
                font-size: 1.25rem;
            }
            
            .badge-sucursal {
                padding: 0.3125rem 0.75rem;
                font-size: 0.75rem;
            }
            
            .search-input {
                padding: 0.75rem 0.75rem 0.75rem 2.75rem;
                font-size: 0.875rem;
            }
            
            .search-icon {
                left: 1rem;
                font-size: 0.9375rem;
            }
            
            .promo-slide { 
                height: 110px; 
                padding: 1.25rem;
            }
            
            .promo-slide h2 { 
                font-size: 1.25rem; 
            }
            
            .promo-slide p { 
                font-size: 0.875rem; 
            }
            
            .products-grid {
                margin: 2rem 0;
            }
            
            .cart-float {
                width: 56px;
                height: 56px;
                font-size: 1.375rem;
                bottom: 1rem;
                right: 1rem;
            }
            
            .cart-badge {
                width: 22px;
                height: 22px;
                font-size: 0.6875rem;
                top: -4px;
                right: -4px;
            }
        }
    </style>
</head>
<body>

<!-- Toast Container -->
<div class="toast-container"></div>

<!-- Premium Navbar -->
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
                <input type="text" id="searchInput" class="search-input" placeholder="Buscar productos..." autocomplete="off">
                <div id="searchResults" class="search-results"></div>
            </div>
        </div>
    </div>
</nav>

<!-- Hero Section -->
<div class="container hero-section">
    <div id="promoCarousel" class="carousel slide promo-carousel" data-bs-ride="carousel">
        <div class="carousel-inner">
            <div class="carousel-item active">
                <div class="promo-slide gradient-1">
                    <h2>🚀 Envíos a Toda La Habana</h2>
                    <p>Calcula el costo a tu barrio en tiempo real</p>
                </div>
            </div>
            <div class="carousel-item">
                <div class="promo-slide gradient-2">
                    <h2>🍰 Dulces Frescos Diarios</h2>
                    <p>Hechos artesanalmente cada mañana</p>
                </div>
            </div>
            <div class="carousel-item">
                <div class="promo-slide gradient-3">
                    <h2>🎯 Ofertas Exclusivas</h2>
                    <p>Grandes descuentos solo para ti</p>
                </div>
            </div>
            <div class="carousel-item">
                <div class="promo-slide gradient-4">
                    <h2>🍕 Comida Caliente al Instante</h2>
                    <p>Preparada al momento para máxima frescura</p>
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

<!-- =========================================== -->
<!-- NUEVO FILTRO DE CATEGORÍAS COMPACTO -->
<!-- =========================================== -->
<div class="container category-filter-section">
    <div class="filter-header">
        <h3 class="filter-title">
            <i class="fas fa-filter"></i>
            Filtros
        </h3>
        
        <select class="sort-select" onchange="location.href='?cat=<?= urlencode($catFilter) ?>&sort='+this.value">
            <option value="categoria_asc" <?= $sort==='categoria_asc'?'selected':'' ?>>Ordenar: Categoría</option>
            <option value="price_asc" <?= $sort==='price_asc'?'selected':'' ?>>Precio: Menor a Mayor</option>
            <option value="price_desc" <?= $sort==='price_desc'?'selected':'' ?>>Precio: Mayor a Menor</option>
        </select>
    </div>
    
    <div class="category-container">
        <div class="category-scroll-wrapper" id="categoryScroll">
            <!-- Botón "Todas" -->
            <a href="?sort=<?= htmlspecialchars($sort) ?>" class="category-btn category-all <?= empty($catFilter)?'active':'' ?>">
                <i class="fas fa-th"></i>
                Todas
            </a>
            
            <!-- Categorías disponibles -->
            <?php foreach($cats as $c): ?>
                <a href="?cat=<?= urlencode($c) ?>&sort=<?= htmlspecialchars($sort) ?>" 
                   class="category-btn <?= $c===$catFilter?'active':'' ?>">
                    <i class="fas fa-tag"></i>
                    <?= htmlspecialchars($c) ?>
                </a>
            <?php endforeach; ?>
        </div>
        
        <!-- Navegación para móvil (solo se muestra cuando sea necesario) -->
        <div class="category-nav">
            <button class="category-nav-btn" onclick="scrollCategories(-100)" id="scrollLeftBtn">
                <i class="fas fa-chevron-left"></i>
            </button>
            <span class="text-muted small px-2">Desliza para ver más</span>
            <button class="category-nav-btn" onclick="scrollCategories(100)" id="scrollRightBtn">
                <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    </div>
</div>

<!-- Products Section -->
<div class="container">
    <div class="products-header">
        <div class="d-flex justify-content-between align-items-center">
            <h4 class="fw-bold mb-0"><i class="fas fa-box-open me-2"></i>Productos Disponibles</h4>
            <span class="products-count">
                <?= count($productos) ?> productos
            </span>
        </div>
    </div>
    
    <div class="products-grid">
        <?php if (count($productos) == 0): ?>
            <div class="col-12">
                <div class="custom-alert alert-info">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-info-circle me-3" style="font-size: 1.75rem; color: var(--primary);"></i>
                        <div>
                            <h5 class="fw-bold mb-2">No hay productos disponibles</h5>
                            <p class="mb-0">Verifica que los productos cumplan con los siguientes criterios:</p>
                            <ul class="mt-2 mb-0">
                                <li><code>activo = 1</code></li>
                                <li><code>es_web = 1</code></li>
                                <li><code>id_empresa = <?= $EMP_ID ?></code></li>
                                <li><code>sucursales_web</code> vacío o contenga "<?= $SUC_ID ?>"</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
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
            "stock" => $stock
        ], JSON_UNESCAPED_UNICODE) ?>)'>
            <div class="product-image-wrapper">
                <?php if($hasImg): ?>
                    <img src="<?= $imgUrl ?>" class="product-image" alt="<?= htmlspecialchars($p['nombre']) ?>">
                <?php else: ?>
                    <div class="product-placeholder" style="background: linear-gradient(135deg, <?= $bg ?> 0%, <?= $bg ?>cc 100%)">
                        <?= $initials ?>
                    </div>
                <?php endif; ?>
                
                <div class="stock-badge <?= $hasStock ? 'in-stock' : 'out-of-stock' ?>">
                    <i class="fas <?= $hasStock ? 'fa-check-circle' : 'fa-times-circle' ?>"></i>
                    <?= $hasStock ? number_format($stock, 0) : 'Agotado' ?>
                </div>
            </div>
            
            <div class="product-body">
                <div class="product-category"><?= htmlspecialchars($p['categoria'] ?? 'General') ?></div>
                <h6 class="product-name"><?= htmlspecialchars($p['nombre']) ?></h6>
                
                <?php if(!empty($p['descripcion'])): ?>
                    <div class="product-description"><?= htmlspecialchars(mb_substr($p['descripcion'], 0, 100)) . (strlen($p['descripcion']) > 100 ? '...' : '') ?></div>
                <?php endif; ?>
                
                <div class="product-footer">
                    <div class="product-price">$<?= number_format($p['precio'], 2) ?></div>
                    <button class="btn-add-cart" 
                            onclick="addToCart('<?= $p['codigo'] ?>', '<?= htmlspecialchars($p['nombre'], ENT_QUOTES) ?>', <?= $p['precio'] ?>)"
                            <?= !$hasStock ? 'disabled' : '' ?>>
                        <i class="fas <?= $hasStock ? 'fa-cart-plus' : 'fa-ban' ?>"></i>
                        <?= $hasStock ? 'Agregar' : 'Agotado' ?>
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Floating Cart Button -->
<button class="cart-float" onclick="openCart()">
    <i class="fas fa-shopping-cart"></i>
    <span id="cartBadge" class="cart-badge d-none">0</span>
</button>

<!-- MODAL DETALLE PRODUCTO -->
<div class="modal fade" id="modalDetail" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="border-radius: var(--radius-lg); border: none;">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold" id="detailName">Producto</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <img id="detailImg" class="d-none" style="max-height: 320px; width: 100%; object-fit: cover; border-radius: var(--radius-md); margin-bottom: 1.5rem;">
                <div id="detailPlaceholder" class="d-none" style="height: 320px; border-radius: var(--radius-md); margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: center; font-size: 3.5rem; font-weight: 700; color: white;"></div>
                <h3 id="detailPrice" class="fw-bold mb-3" style="color: var(--primary);">$0.00</h3>
                <p id="detailDesc" class="text-muted mb-3"></p>
                <p id="detailStock" class="text-success fw-bold mb-0"></p>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                <button type="button" id="btnAddDetail" class="btn btn-primary btn-lg px-4">
                    <i class="fas fa-cart-plus me-2"></i>AGREGAR AL CARRITO
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL CARRITO -->
<div class="modal fade" id="modalCart" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content" style="border-radius: var(--radius-lg); border: none;">
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
                                <th class="text-end">Precio Unitario</th>
                                <th class="text-end">Subtotal</th>
                                <th class="text-end"></th>
                            </tr>
                        </thead>
                        <tbody id="cartTableBody"></tbody>
                    </table>
                    <div class="text-end fs-3 fw-bold mt-4">Total: $<span id="cartTotal">0.00</span></div>
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
            
            <!-- VISTA 2: CHECKOUT -->
            <div id="checkoutView" style="display:none;">
                <form onsubmit="submitOrder(event)">
                    <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                        <h5 class="fw-bold mb-4"><i class="fas fa-user me-2"></i>Datos del Cliente</h5>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Nombre Completo *</label>
                            <input type="text" id="cliName" class="form-control" placeholder="Ej: Juan Pérez" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Teléfono *</label>
                            <input type="tel" id="cliTel" class="form-control" placeholder="Ej: +53 5555-5555" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Dirección *</label>
                            <textarea id="cliDir" class="form-control" rows="2" placeholder="Calle, número, entre calles..." required></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Fecha de Entrega/Recogida *</label>
                            <input type="date" id="cliDate" class="form-control" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Notas Adicionales</label>
                            <textarea id="cliNotes" class="form-control" rows="3" placeholder="Ej: El cake de relleno chocolate y color rosado el merengue. Entregar antes de las 3 PM."></textarea>
                            <small class="text-muted">Especifica detalles importantes como colores, sabores, horarios, etc.</small>
                        </div>
                        
                        <div class="form-check mb-3" style="padding: 1rem; background: var(--gray-100); border-radius: var(--radius-md);">
                            <input class="form-check-input" type="checkbox" id="delHome" onchange="toggleDelivery()">
                            <label class="form-check-label fw-bold" for="delHome">
                                <i class="fas fa-truck me-2"></i>Entrega a domicilio
                            </label>
                        </div>
                        
                        <div id="deliveryBox" style="display:none; background: var(--gray-100); padding: 1.5rem; border-radius: var(--radius-md); margin-bottom: 1rem;">
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
                        
                        <div class="mt-4 p-4" style="background: var(--gray-100); border-radius: var(--radius-md);">
                            <h6 class="fw-bold mb-3">Resumen del Pedido</h6>
                            <div class="d-flex justify-content-between py-2">
                                <span>Subtotal Productos:</span>
                                <span class="fw-bold">$<span id="cartTotal2">0.00</span></span>
                            </div>
                            <div class="d-flex justify-content-between py-2">
                                <span>Costo de Envío:</span>
                                <span class="fw-bold">$<span id="shipCost">0.00</span></span>
                            </div>
                            <hr>
                            <div class="d-flex justify-content-between fs-4">
                                <span class="fw-bold">TOTAL A PAGAR:</span>
                                <span class="fw-bold" style="color: var(--primary);">$<span id="grandTotal">0.00</span></span>
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
                    <div style="font-size: 4rem; margin-bottom: 1rem; color: var(--success);">✅</div>
                    <h3 class="fw-bold mb-3">¡Pedido Recibido Exitosamente!</h3>
                    <p class="text-muted fs-5">Gracias por tu compra. Te contactaremos pronto para confirmar tu orden.</p>
                    <p class="text-muted">Número de pedido: <span id="orderNumber" class="fw-bold">#0000</span></p>
                </div>
                <div class="modal-footer border-0 justify-content-center">
                    <button class="btn btn-primary btn-lg px-5" data-bs-dismiss="modal" onclick="location.reload()">Cerrar</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
    // TOAST NOTIFICATION SYSTEM
    class Toast {
        static show({ title, message, type = 'info', duration = 5000 }) {
            const toastContainer = document.querySelector('.toast-container');
            const toastId = 'toast-' + Date.now();
            
            const icons = {
                success: '✓',
                error: '✗',
                warning: '⚠',
                info: 'ℹ'
            };
            
            const toast = document.createElement('div');
            toast.id = toastId;
            toast.className = `custom-toast ${type}`;
            toast.innerHTML = `
                <div class="toast-icon">
                    ${icons[type]}
                </div>
                <div class="toast-content">
                    <div class="toast-title">${title}</div>
                    <div class="toast-message">${message}</div>
                </div>
                <button class="toast-close" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            `;
            
            toastContainer.appendChild(toast);
            
            setTimeout(() => toast.classList.add('show'), 10);
            
            if (duration > 0) {
                setTimeout(() => {
                    const toastElement = document.getElementById(toastId);
                    if (toastElement) {
                        toastElement.classList.remove('show');
                        setTimeout(() => toastElement.remove(), 300);
                    }
                }, duration);
            }
            
            return toastId;
        }
        
        static success(message, title = '¡Éxito!') {
            return this.show({ title, message, type: 'success' });
        }
        
        static error(message, title = 'Error') {
            return this.show({ title, message, type: 'error' });
        }
        
        static warning(message, title = 'Advertencia') {
            return this.show({ title, message, type: 'warning' });
        }
        
        static info(message, title = 'Información') {
            return this.show({ title, message, type: 'info' });
        }
    }

    // INICIALIZACIÓN
    const modalDetail = new bootstrap.Modal(document.getElementById('modalDetail'));
    const modalCart = new bootstrap.Modal(document.getElementById('modalCart'));
    let cart = JSON.parse(localStorage.getItem('palweb_cart') || '[]');
    
    // ===== BUSCADOR AJAX =====
    const searchInput = document.getElementById('searchInput');
    const searchResults = document.getElementById('searchResults');
    let searchTimeout = null;

    searchInput.addEventListener('input', function() {
        const query = this.value.trim();
        clearTimeout(searchTimeout);
        
        if (query.length < 1) {
            searchResults.style.display = 'none';
            return;
        }

        searchResults.innerHTML = '<div class="p-4 text-center"><div class="spinner-border spinner-border-sm text-primary me-2"></div>Buscando productos...</div>';
        searchResults.style.display = 'block';

        searchTimeout = setTimeout(async () => {
            try {
                const response = await fetch(`shop.php?ajax_search=${encodeURIComponent(query)}`);
                const data = await response.json();
                
                searchResults.innerHTML = '';
                
                if (data.error) {
                    Toast.error(data.message, 'Error de búsqueda');
                    searchResults.innerHTML = `<div class="p-4 text-center text-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>Error al buscar productos
                    </div>`;
                    return;
                }
                
                if (data.length > 0) {
                    data.forEach(item => {
                        const div = document.createElement('div');
                        div.className = 'search-item';
                        
                        const imgHTML = item.hasImg 
                            ? `<img src="${item.imgUrl}" class="search-thumb" alt="${item.nombre}">`
                            : `<div class="search-placeholder" style="background: linear-gradient(135deg, ${item.bg} 0%, ${item.bg}cc 100%)">${item.initials}</div>`;
                        
                        div.innerHTML = `
                            ${imgHTML}
                            <div style="flex: 1;">
                                <div style="font-weight: 600; font-size: 0.9375rem;">${item.nombre}</div>
                                <small class="text-muted">${item.categoria || ''}</small>
                                <div class="mt-1">
                                    <small class="${item.hasStock ? 'text-success' : 'text-danger'}">
                                        <i class="fas ${item.hasStock ? 'fa-check-circle' : 'fa-times-circle'} me-1"></i>
                                        ${item.hasStock ? 'En stock' : 'Agotado'}
                                    </small>
                                </div>
                            </div>
                            <div style="font-weight: 700; color: var(--primary);">$${parseFloat(item.precio).toFixed(2)}</div>
                        `;
                        
                        div.onclick = function() {
                            searchInput.value = '';
                            searchResults.style.display = 'none';
                            openProductDetail({
                                id: item.codigo,
                                name: item.nombre,
                                price: item.precio,
                                desc: item.descripcion || '',
                                img: item.imgUrl,
                                bg: item.bg,
                                initials: item.initials,
                                hasImg: item.hasImg,
                                hasStock: item.hasStock,
                                stock: item.stock
                            });
                        };
                        
                        searchResults.appendChild(div);
                    });
                } else {
                    searchResults.innerHTML = '<div class="p-4 text-center text-muted">'
                        + '<i class="fas fa-search me-2"></i>No se encontraron productos'
                        + '<div class="mt-2 small">Prueba con otros términos de búsqueda</div>'
                        + '</div>';
                }
            } catch (error) {
                console.error('Error:', error);
                Toast.error('Error al buscar productos', 'Error de conexión');
                searchResults.innerHTML = '<div class="p-4 text-center text-danger">'
                    + '<i class="fas fa-exclamation-circle me-2"></i>Error de conexión'
                    + '</div>';
            }
        }, 300);
    });

    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
            searchResults.style.display = 'none';
        }
    });

    // ===== FUNCIONES DEL CARRITO =====
    function updateCounters() {
        const qty = cart.reduce((a, b) => a + b.qty, 0);
        const badge = document.getElementById('cartBadge');
        badge.innerText = qty;
        badge.classList.toggle('d-none', qty === 0);
        localStorage.setItem('palweb_cart', JSON.stringify(cart));
    }
    
    function addToCart(id, name, price) {
        event.stopPropagation();
        const existing = cart.find(i => i.id === id);
        if (existing) {
            existing.qty++;
            Toast.success(`${name} - Cantidad actualizada: ${existing.qty}`, 'Carrito Actualizado');
        } else {
            cart.push({ id, name, price, qty: 1 });
            Toast.success(`${name} agregado al carrito`, 'Producto Agregado');
        }
        updateCounters();
    }
    
    function renderCart() {
        const tbody = document.getElementById('cartTableBody');
        tbody.innerHTML = '';
        let total = 0;
        
        if (cart.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="5" class="text-center py-5">
                        <div class="text-muted">
                            <i class="fas fa-shopping-cart fa-3x mb-3" style="opacity: 0.3;"></i>
                            <div class="fw-bold fs-5">Tu carrito está vacío</div>
                            <div class="small">Agrega productos para continuar</div>
                        </div>
                    </td>
                </tr>
            `;
        } else {
            cart.forEach((item, index) => {
                const subtotal = item.qty * item.price;
                total += subtotal;
                tbody.innerHTML += `
                    <tr>
                        <td>
                            <div class="fw-bold">${item.name}</div>
                        </td>
                        <td class="text-center">
                            <div class="btn-group">
                                <button class="btn btn-sm btn-outline-secondary" onclick="modQty(${index}, -1)">
                                    <i class="fas fa-minus"></i>
                                </button>
                                <span class="btn btn-sm btn-outline-secondary disabled px-3">${item.qty}</span>
                                <button class="btn btn-sm btn-outline-secondary" onclick="modQty(${index}, 1)">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                        </td>
                        <td class="text-end">$${parseFloat(item.price).toFixed(2)}</td>
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
        if (cart[index].qty <= 0) {
            Toast.info(`${cart[index].name} eliminado del carrito`, 'Producto Eliminado');
            cart.splice(index, 1);
        } else {
            Toast.info(`${cart[index].name} - Cantidad: ${cart[index].qty}`, 'Cantidad Actualizada');
        }
        updateCounters();
        renderCart();
    }
    
    function remItem(index) {
        const itemName = cart[index].name;
        cart.splice(index, 1);
        updateCounters();
        renderCart();
        Toast.info(`${itemName} eliminado del carrito`, 'Producto Eliminado');
        if (cart.length === 0) showCartView();
    }
    
    function clearCart() {
        if (cart.length === 0) {
            Toast.warning('El carrito ya está vacío', 'Carrito Vacío');
            return;
        }
        
        if (confirm('¿Estás seguro de que quieres vaciar el carrito? Se perderán todos los productos.')) {
            Toast.info('Carrito vaciado correctamente', 'Carrito Vaciado');
            cart = [];
            updateCounters();
            renderCart();
            showCartView();
        }
    }
    
    function openProductDetail(data) {
        document.getElementById('detailName').innerText = data.name;
        document.getElementById('detailPrice').innerText = '$' + parseFloat(data.price).toFixed(2);
        document.getElementById('detailDesc').innerText = data.desc || 'Sin descripción disponible.';
        document.getElementById('detailStock').innerHTML = data.hasStock 
            ? `<i class="fas fa-check-circle me-2"></i><span class="text-success">En stock: ${data.stock} unidades</span>`
            : `<i class="fas fa-times-circle me-2"></i><span class="text-danger">Producto agotado</span>`;
        
        const img = document.getElementById('detailImg');
        const placeholder = document.getElementById('detailPlaceholder');
        
        if (data.hasImg) {
            img.src = data.img;
            img.classList.remove('d-none');
            placeholder.classList.add('d-none');
        } else {
            placeholder.innerText = data.initials;
            placeholder.style.background = `linear-gradient(135deg, ${data.bg} 0%, ${data.bg}cc 100%)`;
            img.classList.add('d-none');
            placeholder.classList.remove('d-none');
        }
        
        const btn = document.getElementById('btnAddDetail');
        if (data.hasStock) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-cart-plus me-2"></i>AGREGAR AL CARRITO';
            btn.onclick = () => { 
                addToCart(data.id, data.name, data.price); 
                modalDetail.hide(); 
            };
        } else {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-ban me-2"></i>PRODUCTO AGOTADO';
        }
        
        modalDetail.show();
    }

    function openCart() { 
        showCartView(); 
        renderCart(); 
        modalCart.show(); 
    }
    
    function showCartView() {
        document.getElementById('cartItemsView').style.display = 'block';
        document.getElementById('checkoutView').style.display = 'none';
        document.getElementById('successView').style.display = 'none';
    }
    
    function showCheckout() {
        if (cart.length === 0) {
            Toast.warning('Agrega productos al carrito antes de proceder al pago', 'Carrito Vacío');
            return;
        }
        document.getElementById('cartItemsView').style.display = 'none';
        document.getElementById('checkoutView').style.display = 'block';
        // Establecer fecha mínima como mañana
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        document.getElementById('cliDate').min = tomorrow.toISOString().split('T')[0];
        // Setear fecha por defecto (mañana)
        const defaultDate = new Date(tomorrow);
        defaultDate.setDate(defaultDate.getDate());
        document.getElementById('cliDate').value = defaultDate.toISOString().split('T')[0];
    }

    // ===== GEOLOCALIZACIÓN =====
    async function loadMunicipios() {
        try {
            const res = await fetch('shop.php?action_geo=list_mun');
            const muns = await res.json();
            const sel = document.getElementById('cMun');
            sel.innerHTML = '<option value="">Seleccione municipio...</option>';
            muns.forEach(m => sel.innerHTML += `<option value="${m}">${m}</option>`);
        } catch(e) {
            console.error('Error cargando municipios:', e);
            Toast.error('Error al cargar municipios', 'Error de Conexión');
        }
    }
    
    async function loadBarrios() {
        try {
            const mun = document.getElementById('cMun').value;
            if (!mun) return;
            
            const res = await fetch(`shop.php?action_geo=list_bar&m=${encodeURIComponent(mun)}`);
            const bars = await res.json();
            const sel = document.getElementById('cBar');
            sel.innerHTML = '<option value="">Seleccione barrio...</option>';
            bars.forEach(b => sel.innerHTML += `<option value="${b}">${b}</option>`);
            calcShip();
        } catch(e) {
            console.error('Error cargando barrios:', e);
            Toast.error('Error al cargar barrios', 'Error de Conexión');
        }
    }
    
    async function calcShip() {
        try {
            const mun = document.getElementById('cMun').value;
            const bar = document.getElementById('cBar').value;
            if (mun && bar) {
                const res = await fetch(`shop.php?action_geo=calc&m=${encodeURIComponent(mun)}&b=${encodeURIComponent(bar)}`);
                const data = await res.json();
                document.getElementById('shipCost').innerText = data.costo.toFixed(2);
                Toast.info(`Costo de envío: $${data.costo.toFixed(2)}`, 'Envío Calculado');
            } else {
                document.getElementById('shipCost').innerText = '0.00';
            }
            calcTotal();
        } catch(e) {
            console.error('Error calculando envío:', e);
            Toast.error('Error al calcular costo de envío', 'Error de Conexión');
        }
    }
    
    function toggleDelivery() {
        const isDel = document.getElementById('delHome').checked;
        document.getElementById('deliveryBox').style.display = isDel ? 'block' : 'none';
        if (isDel && document.getElementById('cMun').options.length === 1) loadMunicipios();
        if (!isDel) document.getElementById('shipCost').innerText = '0.00';
        calcTotal();
        
        if (isDel) {
            Toast.info('Selecciona municipio y barrio para calcular el envío', 'Envío Habilitado');
        }
    }
    
    function calcTotal() {
        const prod = parseFloat(document.getElementById('cartTotal').innerText);
        const ship = parseFloat(document.getElementById('shipCost').innerText);
        const total = prod + ship;
        document.getElementById('grandTotal').innerText = total.toFixed(2);
        document.getElementById('cartTotal2').innerText = prod.toFixed(2);
    }

    // ===== ENVIAR PEDIDO =====
    async function submitOrder(e) {
        e.preventDefault();
        const btn = e.target.querySelector('button[type="submit"]');
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Procesando pedido...';
        
        // Validaciones básicas
        const name = document.getElementById('cliName').value.trim();
        const phone = document.getElementById('cliTel').value.trim();
        const address = document.getElementById('cliDir').value.trim();
        const date = document.getElementById('cliDate').value;
        
        if (!name || !phone || !address || !date) {
            Toast.error('Por favor completa todos los campos obligatorios', 'Campos Incompletos');
            btn.disabled = false;
            btn.innerHTML = originalText;
            return;
        }
        
        let finalAddress = address;
        let shipCost = 0;
        const notes = document.getElementById('cliNotes').value;
        
        if (document.getElementById('delHome').checked) {
            const mun = document.getElementById('cMun').value;
            const bar = document.getElementById('cBar').value;
            if (!mun || !bar) {
                Toast.error('Por favor selecciona municipio y barrio para el envío', 'Ubicación Requerida');
                btn.disabled = false;
                btn.innerHTML = originalText;
                return;
            }
            shipCost = parseFloat(document.getElementById('shipCost').innerText);
            finalAddress = `[MENSAJERÍA: ${mun} - ${bar}] ${address}`;
        } else {
            finalAddress = "[RECOGIDA] " + address;
        }

        const data = {
            customer: {
                nombre: name,
                telefono: phone,
                direccion: finalAddress,
                fecha_programada: date,
                envio_costo: shipCost,
                notas: notes
            },
            items: cart,
            total: parseFloat(document.getElementById('grandTotal').innerText)
        };

        try {
            Toast.info('Enviando pedido...', 'Procesando');
            const res = await fetch('place_order.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            const json = await res.json();
            
            if (json.status === 'success') {
                // Generar número de pedido aleatorio
                const orderNum = 'PED' + Date.now().toString().substr(-6);
                document.getElementById('orderNumber').textContent = orderNum;
                
                cart = [];
                updateCounters();
                document.getElementById('checkoutView').style.display = 'none';
                document.getElementById('successView').style.display = 'block';
                
                Toast.success(`Pedido #${orderNum} recibido exitosamente`, '¡Pedido Confirmado!');
                
                // Cerrar automáticamente después de 5 segundos
                setTimeout(() => {
                    if (modalCart._isShown) {
                        modalCart.hide();
                        location.reload();
                    }
                }, 5000);
            } else {
                Toast.error(json.error || 'Error desconocido al procesar el pedido', 'Error en Pedido');
            }
        } catch (error) {
            console.error('Error:', error);
            Toast.error('Error de red al enviar el pedido. Verifica tu conexión.', 'Error de Conexión');
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }
    
    // ===== FUNCIONALIDAD PARA EL MENÚ DE CATEGORÍAS =====
    function scrollCategories(direction) {
        const scrollContainer = document.getElementById('categoryScroll');
        scrollContainer.scrollLeft += direction;
        
        // Actualizar estado de botones de navegación
        updateNavButtons();
    }
    
    function updateNavButtons() {
        const scrollContainer = document.getElementById('categoryScroll');
        const scrollLeftBtn = document.getElementById('scrollLeftBtn');
        const scrollRightBtn = document.getElementById('scrollRightBtn');
        
        // Deshabilitar botón izquierdo si estamos al inicio
        scrollLeftBtn.disabled = scrollContainer.scrollLeft <= 10;
        
        // Deshabilitar botón derecho si estamos al final
        const maxScroll = scrollContainer.scrollWidth - scrollContainer.clientWidth;
        scrollRightBtn.disabled = scrollContainer.scrollLeft >= (maxScroll - 10);
    }
    
    // Inicializar botones de navegación
    document.addEventListener('DOMContentLoaded', function() {
        const scrollContainer = document.getElementById('categoryScroll');
        if (scrollContainer) {
            scrollContainer.addEventListener('scroll', updateNavButtons);
            updateNavButtons();
            
            // Solo mostrar navegación si hay scroll horizontal
            const needsScroll = scrollContainer.scrollWidth > scrollContainer.clientWidth;
            document.querySelector('.category-nav').style.display = needsScroll ? 'flex' : 'none';
        }
    });
    
    // Hacer funciones globales accesibles desde onclick
    window.addToCart = addToCart;
    window.openProductDetail = openProductDetail;
    window.openCart = openCart;
    window.clearCart = clearCart;
    window.showCartView = showCartView;
    window.showCheckout = showCheckout;
    window.modQty = modQty;
    window.remItem = remItem;
    window.loadMunicipios = loadMunicipios;
    window.loadBarrios = loadBarrios;
    window.calcShip = calcShip;
    window.toggleDelivery = toggleDelivery;
    window.submitOrder = submitOrder;
    window.scrollCategories = scrollCategories;
    window.Toast = Toast;
    
    // Inicializar
    updateCounters();
    
    // Mostrar mensaje de bienvenida
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(() => {
            Toast.success('Bienvenido a PalWeb Shop', '¡Hola!');
        }, 1000);
    });
</script>
<?php include_once 'menu_master.php'; ?>
</body>
</html>
