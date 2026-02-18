<?php
// ARCHIVO: /var/www/palweb/api/dashboard.php

// ---------------------------------------------------------
// 🔒 SEGURIDAD: VERIFICACIÓN DE SESIÓN
// ---------------------------------------------------------
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// 1. CONFIGURACIÓN Y CONEXIÓN
ini_set('display_errors', 0);
error_reporting(E_ALL);

try {
    require_once 'db.php';
    date_default_timezone_set('America/Havana');
    $pdo->exec("SET time_zone = '-05:00';");
} catch (Exception $e) {
    die("Error crítico de base de datos: " . $e->getMessage());
}

require_once 'config_loader.php';

$EMP_ID = intval($config['id_empresa']);
$SUC_ID = intval($config['id_sucursal']);
$ALM_ID = intval($config['id_almacen']);

// --- DETERMINAR ALCANCE (SCOPE) ---
// global = Toda la empresa | local = Sucursal/Almacén actual
$scope = isset($_GET['scope']) ? $_GET['scope'] : 'local'; 

// --- FUNCIONES AUXILIARES ---
function getScalar($pdo, $sql, $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() ?: 0;
    } catch (Exception $e) { return 0; }
}

function getRows($pdo, $sql, $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

// --- CHEQUEO DE SISTEMA ---
function getSystemStatus() {
    $imgDir = '/home/marinero/product_images/';
    return [
        'gd' => extension_loaded('gd'),
        'zip' => class_exists('ZipArchive'),
        'pdo' => extension_loaded('pdo_mysql'),
        'folder' => is_writable($imgDir),
        'img_path' => $imgDir
    ];
}

// --- PARÁMETROS Y FILTROS DE FECHA ---
$fechaFinDef = date('Y-m-d');
$fechaIniDef = date('Y-m-d', strtotime('-7 days'));
$fechaInicio = isset($_GET['start']) && !empty($_GET['start']) ? $_GET['start'] : $fechaIniDef;
$fechaFin    = isset($_GET['end']) && !empty($_GET['end']) ? $_GET['end'] : $fechaFinDef;

// ============================================================================
//   2. LÓGICA DE ESTADÍSTICAS (KPIs)
// ============================================================================

// --- CONSTRUCCIÓN DE FILTROS SQL SEGÚN SCOPE ---
// Filtros Base
$sqlEmpresa = " AND p.id_empresa = $EMP_ID ";

// Filtros Inventario
$sqlAlmacen = ($scope === 'local') ? " AND s.id_almacen = $ALM_ID " : ""; 

// Filtros Ventas
$sqlSucursal = ($scope === 'local') ? " AND v.id_sucursal = $SUC_ID " : "";
$sqlDateRange = " AND DATE(v.fecha) BETWEEN ? AND ? ";

// A. Inventario (COLLATE fix: stock_almacen.id_producto usa uca1400, productos.codigo usa unicode_ci)
$sqlInvBase = "SELECT SUM(s.cantidad * %FIELD%) FROM stock_almacen s JOIN productos p ON s.id_producto = p.codigo WHERE 1=1 $sqlEmpresa $sqlAlmacen";
$valorInventarioCosto = getScalar($pdo, str_replace('%FIELD%', 'p.costo', $sqlInvBase));
$valorInventarioVenta = getScalar($pdo, str_replace('%FIELD%', 'p.precio', $sqlInvBase));

$sqlStockCritico = "SELECT COUNT(*) FROM stock_almacen s JOIN productos p ON s.id_producto = p.codigo WHERE s.cantidad <= p.stock_minimo $sqlEmpresa $sqlAlmacen";
$stockCritico = getScalar($pdo, $sqlStockCritico);
$margenPotencial = $valorInventarioVenta - $valorInventarioCosto;

// B. Ventas (Periodo)
$paramsDate = [$fechaInicio, $fechaFin];

$sqlVentasBase = "SELECT SUM(v.total) FROM ventas_cabecera v WHERE v.id_empresa = $EMP_ID $sqlSucursal $sqlDateRange";
$ventasPeriodo = getScalar($pdo, $sqlVentasBase, $paramsDate);

$sqlGanancia = "SELECT SUM((d.precio - p.costo) * d.cantidad)
                FROM ventas_detalle d
                JOIN productos p ON d.id_producto = p.codigo
                JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id
                WHERE v.id_empresa = $EMP_ID $sqlSucursal $sqlDateRange";
$gananciaPeriodo = getScalar($pdo, $sqlGanancia, $paramsDate);

// --- C. MÉTRICAS WEB (AMPLIADO) ---
$totalVisitas = getScalar($pdo, "SELECT COUNT(*) FROM metricas_web");
$visitasHoy = getScalar($pdo, "SELECT COUNT(*) FROM metricas_web WHERE DATE(fecha) = CURRENT_DATE");
$ipsUnicas = getScalar($pdo, "SELECT COUNT(DISTINCT ip) FROM metricas_web");
$usuariosRegistrados = getScalar($pdo, "SELECT COUNT(*) FROM clientes_tienda");
$totalVistasProductos = getScalar($pdo, "SELECT COUNT(*) FROM vistas_productos");

// Rankings
$paginasPopulares = getRows($pdo, "SELECT url_visitada, COUNT(*) as visitas FROM metricas_web GROUP BY url_visitada ORDER BY visitas DESC LIMIT 5");
$topIps = getRows($pdo, "SELECT ip, COUNT(*) as hits FROM metricas_web GROUP BY ip ORDER BY hits DESC LIMIT 5");
$topPaises = getRows($pdo, "SELECT pais, COUNT(*) as visitas FROM metricas_web GROUP BY pais ORDER BY visitas DESC LIMIT 5");
$topVistos = getRows($pdo, "SELECT p.nombre, COUNT(v.id) as vistas 
                            FROM vistas_productos v 
                            JOIN productos p ON v.codigo_producto = p.codigo 
                            GROUP BY v.codigo_producto ORDER BY vistas DESC LIMIT 5");

$visitantesRecientes = getRows($pdo, "SELECT ip, url_visitada, fecha FROM metricas_web ORDER BY fecha DESC LIMIT 5");
$carritosAbandonadosCount = getScalar($pdo, "SELECT COUNT(*) FROM carritos_abandonados WHERE recuperado = 0 AND fecha_actualizacion < (NOW() - INTERVAL 1 HOUR)");
$carritosTotalValor = getScalar($pdo, "SELECT SUM(total) FROM carritos_abandonados WHERE recuperado = 0");

// Tasa de Conversión (Ventas Web / Visitas Únicas)
$ventasWebCount = getScalar($pdo, "SELECT COUNT(*) FROM pedidos_cabecera WHERE id_empresa = $EMP_ID");
$tasaConversion = ($ipsUnicas > 0) ? ($ventasWebCount / $ipsUnicas) * 100 : 0;

// URL más visitada (Solo el path relativo)
$urlMasVisitada = getScalar($pdo, "SELECT url_visitada FROM metricas_web GROUP BY url_visitada ORDER BY COUNT(*) DESC LIMIT 1");
$urlMasVisitada = basename($urlMasVisitada) ?: '/';

// ============================================================================
//   2.5 ANÁLISIS DE LOGS DE SERVIDOR (NGINX)
// ============================================================================
function parseLogStats($cmd) {
    $output = shell_exec($cmd);
    if (!$output) return [];
    $lines = explode("\n", trim($output));
    $data = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        // Formato esperado de 'uniq -c': "count value"
        if (preg_match('/^(\d+)\s+(.*)$/', $line, $matches)) {
            $data[] = ['count' => $matches[1], 'value' => $matches[2]];
        } else {
            $data[] = ['value' => $line];
        }
    }
    return $data;
}

$accessLogPath = '/var/log/nginx/palweb_access.log';
$errorLogPath = '/var/log/nginx/palweb_error.log';

$logIps = parseLogStats("tail -n 10000 $accessLogPath | awk '{print \$1}' | sort | uniq -c | sort -nr | head -n 10");
$logPages = parseLogStats("tail -n 10000 $accessLogPath | awk '{print \$7}' | sort | uniq -c | sort -nr | head -n 10");
$logBrowsers = parseLogStats("tail -n 10000 $accessLogPath | awk -F'\"' '{print \$6}' | sort | uniq -c | sort -nr | head -n 10");
$logAttacks = shell_exec("grep -Ei 'union.*select|sqlmap|etc/passwd|phpinfo|wp-login|config\.php|\.env' $accessLogPath | awk '{print \$1 \" -> \" \$7}' | tail -n 10");
$logErrors = shell_exec("tail -n 50 $errorLogPath | grep 'error' | tail -n 5");


$sqlTrans = "SELECT COUNT(*) FROM ventas_cabecera v WHERE v.id_empresa = $EMP_ID $sqlSucursal $sqlDateRange";
$totalTransacciones = getScalar($pdo, $sqlTrans, $paramsDate);
$ticketPromedio = ($totalTransacciones > 0) ? $ventasPeriodo / $totalTransacciones : 0;

// C. Pendientes (Pedidos Web - Globalmente o por sucursal si se implementara asignación)
$countPendientes = getScalar($pdo, "SELECT COUNT(*) FROM pedidos_cabecera WHERE estado = 'pendiente'", []);

// D. Datos para Gráficas
$sqlPagos = "SELECT COALESCE(metodo_pago, 'Efectivo') as metodo, SUM(total) as total 
             FROM ventas_cabecera v 
             WHERE v.id_empresa = $EMP_ID $sqlSucursal $sqlDateRange 
             GROUP BY metodo_pago ORDER BY total DESC";
$pagosData = getRows($pdo, $sqlPagos, $paramsDate);

$topProductos = getRows($pdo, "SELECT p.nombre, SUM(d.cantidad) as vendidos, SUM(d.cantidad * (d.precio - p.costo)) as ganancia
                               FROM ventas_detalle d
                               JOIN productos p ON d.id_producto = p.codigo
                               JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id
                               WHERE v.id_empresa = $EMP_ID $sqlSucursal $sqlDateRange
                               GROUP BY p.codigo ORDER BY vendidos DESC LIMIT 5", $paramsDate);

// ============================================================================
//   3. NUEVAS TARJETAS SOLICITADAS (ANÁLISIS PRODUCTO)
// ============================================================================

// 1. Productos con Mayor Ganancia Neta (Absoluta)
$sqlTopProfit = "SELECT p.nombre, SUM(d.cantidad * (d.precio - p.costo)) as total_ganancia
                 FROM ventas_detalle d
                 JOIN productos p ON d.id_producto = p.codigo
                 JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id
                 WHERE v.id_empresa = $EMP_ID $sqlSucursal $sqlDateRange
                 GROUP BY p.codigo
                 ORDER BY total_ganancia DESC LIMIT 5";
$topProfitProds = getRows($pdo, $sqlTopProfit, $paramsDate);

// 2. Productos con Menor Margen % (Que tengan stock > 0)
// Margen = ((Precio - Costo) / Precio) * 100
$sqlLowMargin = "SELECT p.nombre, p.precio, p.costo,
                 ((p.precio - p.costo) / NULLIF(p.precio,0)) * 100 as margen_porc
                 FROM stock_almacen s
                 JOIN productos p ON s.id_producto = p.codigo
                 WHERE p.id_empresa = $EMP_ID $sqlAlmacen AND s.cantidad > 0 AND p.precio > 0
                 ORDER BY margen_porc ASC LIMIT 5";
$lowMarginProds = getRows($pdo, $sqlLowMargin);

// 3. Lento Movimiento (Con stock, sin ventas en últimos 7 días)
// Ignora el filtro de fecha del dashboard, usa "Last 7 Days" fijo
$sevenDaysAgo = date('Y-m-d', strtotime('-7 days'));
$sqlSlow = "SELECT p.nombre, s.cantidad
            FROM stock_almacen s
            JOIN productos p ON s.id_producto = p.codigo
            WHERE p.id_empresa = $EMP_ID $sqlAlmacen AND s.cantidad > 0
            AND p.codigo NOT IN (
                SELECT d.id_producto
                FROM ventas_detalle d
                JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id
                WHERE v.fecha >= ? $sqlSucursal
            )
            LIMIT 5";
$slowMovingProds = getRows($pdo, $sqlSlow, [$sevenDaysAgo]);

// ============================================================================
//   4. NUEVAS TARJETAS: CATEGORÍAS
// ============================================================================

// 4.1 Ventas por Categoría
$sqlCatSales = "SELECT p.categoria, SUM(d.cantidad * d.precio) as total_venta
                FROM ventas_detalle d
                JOIN productos p ON d.id_producto = p.codigo
                JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id
                WHERE v.id_empresa = $EMP_ID $sqlSucursal $sqlDateRange
                GROUP BY p.categoria
                ORDER BY total_venta DESC";
$catSalesData = getRows($pdo, $sqlCatSales, $paramsDate);

// 4.2 Ganancias por Categoría
$sqlCatProfit = "SELECT p.categoria, SUM(d.cantidad * (d.precio - p.costo)) as total_ganancia
                 FROM ventas_detalle d
                 JOIN productos p ON d.id_producto = p.codigo
                 JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id
                 WHERE v.id_empresa = $EMP_ID $sqlSucursal $sqlDateRange
                 GROUP BY p.categoria
                 ORDER BY total_ganancia DESC";
$catProfitData = getRows($pdo, $sqlCatProfit, $paramsDate);

// 4.3 Inventarios por Categoría (Cantidad y Valor Costo)
$sqlInvByCategory = "SELECT p.categoria, SUM(s.cantidad) as total_cantidad, SUM(s.cantidad * p.costo) as total_costo_valor
                     FROM stock_almacen s
                     JOIN productos p ON s.id_producto = p.codigo
                     WHERE 1=1 $sqlEmpresa $sqlAlmacen
                     GROUP BY p.categoria
                     ORDER BY total_costo_valor DESC"; // Order by total cost value
$invByCategoryData = getRows($pdo, $sqlInvByCategory);


// E. Lista de Pedidos
$sqlOrders = "SELECT * FROM pedidos_cabecera ORDER BY CASE WHEN estado = 'pendiente' THEN 1 ELSE 2 END, fecha DESC LIMIT 50";
$pedidos = getRows($pdo, $sqlOrders, []);

// F. Sistema
$sysStatus = getSystemStatus();

function getStatusBadge($estado) {
    switch($estado) {
        case 'pendiente': return '<span class="badge bg-warning text-dark"><i class="fas fa-bell"></i> Nuevo</span>';
        case 'proceso':   return '<span class="badge bg-info text-dark"><i class="fas fa-fire"></i> Cocina</span>';
        case 'camino':    return '<span class="badge bg-primary"><i class="fas fa-motorcycle"></i> En Camino</span>';
        case 'completado':return '<span class="badge bg-success"><i class="fas fa-check"></i> Entregado</span>';
        case 'cancelado': return '<span class="badge bg-danger"><i class="fas fa-times"></i> Cancelado</span>';
        default:          return '<span class="badge bg-secondary">'.$estado.'</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin | PalWeb POS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style id="kpi-custom-styles">
        body { background-color: #f0f2f5 !important; font-family: 'Segoe UI', sans-serif; padding-bottom: 60px; }
        
        /* Selectores de máxima prioridad */
        body .card.card-stat { border: none !important; border-radius: 12px !important; transition: transform 0.2s !important; position: relative !important; overflow: hidden !important; }
        
        /* Clases de color con !important y prefijo de body para ganar especificidad */
        body .kpi-venta { background-color: #eef2ff !important; border-left: 5px solid #6366f1 !important; color: #1e1b4b !important; }
        body .kpi-costo { background-color: #f8fafc !important; border-left: 5px solid #94a3b8 !important; color: #1e293b !important; }
        body .kpi-margen { background-color: #f0fdf4 !important; border-left: 5px solid #22c55e !important; color: #14532d !important; }
        body .kpi-critico { background-color: #fef2f2 !important; border-left: 5px solid #ef4444 !important; color: #7f1d1d !important; }
        
        body .kpi-total-v { background-color: #4f46e5 !important; color: white !important; }
        body .kpi-ganancia { background-color: #10b981 !important; color: white !important; }
        body .kpi-trans { background-color: #f59e0b !important; color: white !important; }
        body .kpi-ticket { background-color: #8b5cf6 !important; color: white !important; }
        body .kpi-urls { background-color: #06b6d4 !important; color: white !important; }
        
        /* Nuevo estilo solicitado: Naranja con letras negras */
        body .kpi-orange { background-color: #ffc107 !important; color: #000 !important; }
        
        /* Nuevo estilo solicitado: Verde forzado */
        body .kpi-green { background-color: #28a745 !important; color: white !important; }

        .icon-stat { font-size: 2.5rem !important; opacity: 0.15 !important; position: absolute !important; right: 20px !important; top: 20px !important; }

        .table-orders tbody tr { transition: background 0.2s; }
        .table-orders tbody tr:hover { background-color: #f8f9fa; }
        .scheduled-date { background-color: #e3f2fd; color: #0d6efd; padding: 4px 8px; border-radius: 6px; font-weight: 600; display: inline-block; margin-top: 4px; font-size: 0.85rem; }
        .urgent-date { background-color: #ffe0e0; color: #d63384; }
        .sys-dot { height: 10px; width: 10px; border-radius: 50%; display: inline-block; margin-right: 5px; }
        .bg-ok { background-color: #198754; }
        .bg-fail { background-color: #dc3545; }

        /* Estilos para Tabs con deslizamiento (Slide Effect) */
        .tab-content { 
            position: relative; 
            overflow: hidden; 
            width: 100%;
            min-height: 500px;
        }
        .tab-pane { 
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            width: 100%;
        }
        /* Estado inicial/oculto: desplazado a la derecha y transparente */
        .tab-pane:not(.active) { 
            display: block; 
            position: absolute;
            top: 0;
            opacity: 0; 
            transform: translateX(50px);
            pointer-events: none;
        }
        /* Estado activo: posición original y visible */
        .tab-pane.active { 
            display: block; 
            position: relative;
            opacity: 1; 
            transform: translateX(0);
        }
        
        /* Opcional: Pequeña animación para los botones de las tabs */
        .nav-pills .nav-link { 
            border-radius: 50px; 
            padding: 8px 25px; 
            margin-right: 5px;
            border: 1px solid transparent;
        }
        .nav-pills .nav-link.active {
            background-color: #0d6efd !important;
            color: white !important;
            box-shadow: 0 4px 15px rgba(13, 110, 253, 0.3);
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top shadow-sm">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="#"><i class="fas fa-tachometer-alt me-2"></i>PalWeb Admin</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item"><a class="nav-link" href="pos_config.php"><i class="fas fa-cogs"></i> Configuración</a></li>
                <li class="nav-item ms-lg-3"> <a class="btn btn-danger btn-sm fw-bold" href="logout.php"><i class="fas fa-sign-out-alt me-1"></i> Salir </a></li>            
            </ul>
        </div>
    </div>
</nav>

<div class="container-fluid p-4">

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <!-- Navegación por Tabs -->
        <ul class="nav nav-pills bg-light p-1 rounded shadow-sm" id="dashboardTabs" role="tablist">
            <li class="nav-item">
                <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#tab-negocios" type="button"><i class="fas fa-chart-line me-1"></i> Negocios</button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-reservas" type="button"><i class="fas fa-calendar-check me-1"></i> Reservas</button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-web" type="button"><i class="fas fa-shopping-cart me-1"></i> Web & Ecommerce</button>
            </li>
        </ul>

        <div class="d-flex gap-2">
            <div class="btn-group shadow-sm">
                <a href="?scope=local&start=<?php echo $fechaInicio; ?>&end=<?php echo $fechaFin; ?>" class="btn btn-sm <?php echo $scope === 'local' ? 'btn-primary fw-bold' : 'btn-outline-secondary bg-white'; ?>">
                    <i class="fas fa-store me-1"></i> Sucursal Actual (#<?php echo $SUC_ID; ?>)
                </a>
                <a href="?scope=global&start=<?php echo $fechaInicio; ?>&end=<?php echo $fechaFin; ?>" class="btn btn-sm <?php echo $scope === 'global' ? 'btn-primary fw-bold' : 'btn-outline-secondary bg-white'; ?>">
                    <i class="fas fa-globe me-1"></i> Global / Todos
                </a>
            </div>
            
            <form method="GET" class="d-flex align-items-center gap-2 bg-white p-1 px-2 rounded shadow-sm border">
                <input type="hidden" name="scope" value="<?php echo $scope; ?>">
                <input type="date" name="start" class="form-control form-control-sm w-auto border-0" value="<?php echo $fechaInicio; ?>">
                <span class="text-muted">-</span>
                <input type="date" name="end" class="form-control form-control-sm w-auto border-0" value="<?php echo $fechaFin; ?>">
                <button type="submit" class="btn btn-primary btn-sm rounded-circle"><i class="fas fa-filter"></i></button>
            </form>
        </div>
    </div>

    <div class="tab-content">
        <!-- TAB 1: NEGOCIOS -->
        <div class="tab-pane fade show active" id="tab-negocios">
            <h6 class="text-uppercase text-muted fw-bold fs-7 mb-3 ps-1"><i class="fas fa-boxes me-2"></i> Estado del Inventario (<?php echo ucfirst($scope); ?>)</h6>
            <div class="row g-4 mb-4">
                <div class="col-md-3">
                    <div class="card card-stat kpi-venta h-100 shadow-sm">
                        <div class="card-body">
                            <h6 class="text-muted fw-bold small text-uppercase">Valor (Venta)</h6>
                            <h3 class="fw-bold text-dark mb-0">$<?php echo number_format($valorInventarioVenta, 0); ?></h3>
                            <i class="fas fa-tags icon-stat text-primary"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-stat kpi-costo h-100 shadow-sm">
                        <div class="card-body">
                            <h6 class="text-muted fw-bold small text-uppercase">Valor (Costo)</h6>
                            <h3 class="fw-bold text-dark mb-0">$<?php echo number_format($valorInventarioCosto, 0); ?></h3>
                            <i class="fas fa-warehouse icon-stat text-secondary"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-stat kpi-margen h-100 shadow-sm">
                        <div class="card-body">
                            <h6 class="text-muted fw-bold small text-uppercase">Margen Potencial</h6>
                            <h3 class="fw-bold text-success mb-0">$<?php echo number_format($margenPotencial, 0); ?></h3>
                            <i class="fas fa-hand-holding-usd icon-stat text-success"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-stat kpi-critico h-100 shadow-sm">
                        <div class="card-body">
                            <h6 class="text-muted fw-bold small text-uppercase">Stock Bajo</h6>
                            <h3 class="fw-bold text-danger mb-0"><?php echo $stockCritico; ?> <small class="fs-6 text-muted">items</small></h3>
                            <i class="fas fa-exclamation-triangle icon-stat text-danger"></i>
                        </div>
                    </div>
                </div>
            </div>

            <h6 class="text-uppercase text-muted fw-bold fs-7 mb-3 ps-1"><i class="fas fa-chart-line me-2"></i> Rendimiento del Periodo</h6>
            <div class="row g-4 mb-4">
                <div class="col-md-3">
                    <div class="card card-stat kpi-ganancia text-white h-100 shadow-sm">
                        <div class="card-body">
                            <h6 class="text-uppercase fw-bold small opacity-75">Venta Total</h6>
                            <h2 class="display-6 fw-bold mb-0">$<?php echo number_format($ventasPeriodo, 0); ?></h2>
                            <i class="fas fa-dollar-sign icon-stat text-white"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-stat kpi-ganancia text-white h-100 shadow-sm">
                        <div class="card-body">
                            <h6 class="text-uppercase fw-bold small opacity-75">Ganancia Neta</h6>
                            <h2 class="display-6 fw-bold mb-0">$<?php echo number_format($gananciaPeriodo, 0); ?></h2>
                            <i class="fas fa-wallet icon-stat text-white"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-stat kpi-ticket text-white h-100 shadow-sm">
                        <div class="card-body">
                            <h6 class="text-uppercase fw-bold small opacity-75">Ticket Promedio</h6>
                            <h2 class="display-6 fw-bold mb-0">$<?php echo number_format($ticketPromedio, 2); ?></h2>
                            <i class="fas fa-receipt icon-stat text-white"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-stat kpi-trans text-white h-100 shadow-sm">
                        <div class="card-body">
                            <h6 class="text-uppercase fw-bold small opacity-75">Operaciones</h6>
                            <h2 class="display-6 fw-bold mb-0"><?php echo number_format($totalTransacciones); ?></h2>
                            <i class="fas fa-shopping-cart icon-stat text-white"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-md-6">
                    <div class="card shadow-sm h-100 border-0">
                        <div class="card-header bg-white py-3 fw-bold"><i class="fas fa-wallet text-primary me-2"></i> Métodos de Pago</div>
                        <div class="card-body">
                            <div style="height: 250px;"><canvas id="chartPagos"></canvas></div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card shadow-sm h-100 border-0">
                        <div class="card-header bg-white py-3 fw-bold"><i class="fas fa-crown text-warning me-2"></i> Más Vendidos (Cantidad)</div>
                        <ul class="list-group list-group-flush">
                            <?php foreach($topProductos as $idx => $prod): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <span class="badge bg-light text-dark border me-2">#<?php echo $idx + 1; ?></span>
                                    <?php echo htmlspecialchars($prod['nombre']); ?>
                                </div>
                                <div>
                                    <span class="badge bg-primary rounded-pill me-2"><?php echo $prod['vendidos']; ?> un</span>
                                    <small class="text-success fw-bold">+$<?php echo number_format($prod['ganancia'],0); ?></small>
                                </div>
                            </li>
                            <?php endforeach; ?>
                            <?php if(empty($topProductos)) echo '<li class="list-group-item text-center text-muted">Sin ventas en este periodo</li>'; ?>
                        </ul>
                    </div>
                </div>
            </div>

            <h6 class="text-uppercase text-muted fw-bold fs-7 mb-3 ps-1"><i class="fas fa-search-dollar me-2"></i> Análisis de Producto (Top 5)</h6>
            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="card shadow-sm h-100 border-0 border-top border-success border-4">
                        <div class="card-header bg-white fw-bold text-success"><i class="fas fa-money-bill-wave me-1"></i> Mayor Ganancia Total</div>
                        <ul class="list-group list-group-flush small">
                            <?php if(empty($topProfitProds)): ?>
                                <li class="list-group-item text-center text-muted py-4">Sin datos</li>
                            <?php else: ?>
                                <?php foreach($topProfitProds as $p): ?>
                                <li class="list-group-item d-flex justify-content-between">
                                    <span class="text-truncate"><?php echo htmlspecialchars($p['nombre']); ?></span>
                                    <span class="fw-bold text-success">+$<?php echo number_format($p['total_ganancia'], 0); ?></span>
                                </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card shadow-sm h-100 border-0 border-top border-danger border-4">
                        <div class="card-header bg-white fw-bold text-danger"><i class="fas fa-percent me-1"></i> Menor Margen %</div>
                        <ul class="list-group list-group-flush small">
                            <?php if(empty($lowMarginProds)): ?>
                                <li class="list-group-item text-center text-muted py-4">Sin datos</li>
                            <?php else: ?>
                                <?php foreach($lowMarginProds as $p): ?>
                                <li class="list-group-item d-flex justify-content-between">
                                    <span class="text-truncate" title="P: $<?php echo $p['precio']; ?> C: $<?php echo $p['costo']; ?>"><?php echo htmlspecialchars($p['nombre']); ?></span>
                                    <span class="fw-bold text-danger"><?php echo number_format($p['margen_porc'], 1); ?>%</span>
                                </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card shadow-sm h-100 border-0 border-top border-warning border-4">
                        <div class="card-header bg-white fw-bold text-warning"><i class="fas fa-hourglass-half me-1"></i> Lento Movimiento (7 días)</div>
                        <ul class="list-group list-group-flush small">
                            <?php if(empty($slowMovingProds)): ?>
                                <li class="list-group-item text-center text-muted py-4">Todo se mueve bien 👍</li>
                            <?php else: ?>
                                <?php foreach($slowMovingProds as $p): ?>
                                <li class="list-group-item d-flex justify-content-between">
                                    <span class="text-truncate"><?php echo htmlspecialchars($p['nombre']); ?></span>
                                    <span class="badge bg-secondary">Stock: <?php echo $p['cantidad']; ?></span>
                                </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>

            <h6 class="text-uppercase text-muted fw-bold fs-7 mb-3 ps-1"><i class="fas fa-tags me-2"></i> Rendimiento por Categorías</h6>
            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="card shadow-sm h-100 border-0">
                        <div class="card-header bg-white fw-bold"><i class="fas fa-shopping-basket text-primary me-2"></i> Ventas por Categoría</div>
                        <ul class="list-group list-group-flush small">
                            <?php if(empty($catSalesData)): ?>
                                <li class="list-group-item text-center text-muted py-4">Sin datos</li>
                            <?php else: ?>
                                <?php foreach($catSalesData as $c): ?>
                                <li class="list-group-item d-flex justify-content-between">
                                    <span><?php echo htmlspecialchars($c['categoria']); ?></span>
                                    <span class="fw-bold text-dark">$<?php echo number_format($c['total_venta'], 2); ?></span>
                                </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card shadow-sm h-100 border-0">
                        <div class="card-header bg-white fw-bold"><i class="fas fa-chart-pie text-success me-2"></i> Ganancia por Categoría</div>
                        <ul class="list-group list-group-flush small">
                            <?php if(empty($catProfitData)): ?>
                                <li class="list-group-item text-center text-muted py-4">Sin datos</li>
                            <?php else: ?>
                                <?php foreach($catProfitData as $c): ?>
                                <li class="list-group-item d-flex justify-content-between">
                                    <span><?php echo htmlspecialchars($c['categoria']); ?></span>
                                    <span class="fw-bold text-success">+$<?php echo number_format($c['total_ganancia'], 2); ?></span>
                                </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card shadow-sm h-100 border-0">
                        <div class="card-header bg-white fw-bold"><i class="fas fa-boxes text-info me-2"></i> Inventario por Categoría</div>
                        <ul class="list-group list-group-flush small">
                            <?php if(empty($invByCategoryData)): ?>
                                <li class="list-group-item text-center text-muted py-4">Sin datos de inventario por categoría</li>
                            <?php else: ?>
                                <?php foreach($invByCategoryData as $c): ?>
                                <li class="list-group-item d-flex justify-content-between">
                                    <span><?php echo htmlspecialchars($c['categoria']); ?></span>
                                    <span>
                                        <span class="badge bg-secondary me-2"><?php echo number_format($c['total_cantidad'], 0); ?> un</span>
                                        <span class="fw-bold text-dark">$<?php echo number_format($c['total_costo_valor'], 2); ?></span>
                                    </span>
                                </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 2: RESERVAS -->
        <div class="tab-pane fade" id="tab-reservas">
            <div class="card shadow border-0 mb-5">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="m-0 fw-bold text-dark">
                        <i class="fas fa-list-ul me-2 text-primary"></i> Pedidos Web / Reservas
                        <?php if($countPendientes > 0): ?>
                            <span class="badge bg-danger ms-2"><?php echo $countPendientes; ?> Nuevos</span>
                        <?php endif; ?>
                    </h5>
                    <button class="btn btn-sm btn-outline-secondary" onclick="location.reload()"><i class="fas fa-sync-alt"></i></button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-orders align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">#ID</th>
                                    <th>Cliente</th>
                                    <th>Items</th>
                                    <th>Fechas / Reserva</th>
                                    <th>Total</th>
                                    <th>Estado</th>
                                    <th class="text-end pe-3">Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($pedidos)): ?>
                                    <tr><td colspan="7" class="text-center py-5 text-muted">No hay pedidos registrados.</td></tr>
                                <?php else: ?>
                                    <?php foreach($pedidos as $row): 
                                        $stmtDet = $pdo->prepare("SELECT d.cantidad, p.nombre FROM pedidos_detalle d JOIN productos p ON d.id_producto = p.codigo WHERE d.id_pedido = ?");
                                        $stmtDet->execute([$row['id']]);
                                        $items = $stmtDet->fetchAll(PDO::FETCH_ASSOC);
                                        $itemsStr = "";
                                        foreach($items as $it) {
                                            $itemsStr .= "<div><small><strong>" . (float)$it['cantidad'] . "x</strong> " . htmlspecialchars($it['nombre']) . "</small></div>";
                                        }

                                        $fechaCrea = strtotime($row['fecha']);
                                        $fechaProg = $row['fecha_programada'] ? strtotime($row['fecha_programada']) : null;
                                        $isFuture = $fechaProg && $fechaProg > time();
                                    ?>
                                    <tr class="<?php echo ($row['estado'] == 'pendiente') ? 'table-warning' : ''; ?>">
                                        <td class="ps-3 fw-bold">#<?php echo $row['id']; ?></td>
                                        <td>
                                            <div class="fw-bold"><?php echo htmlspecialchars($row['cliente_nombre']); ?></div>
                                            <div class="small text-muted"><b>Notas: </b> <?php echo htmlspecialchars($row['notas']); ?></div>
                                            <div class="small text-muted"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($row['cliente_direccion']); ?></div>
                                            <div class="small text-primary"><i class="fab fa-whatsapp"></i> <?php echo htmlspecialchars($row['cliente_telefono']); ?></div>
                                        </td>
                                        <td><?php echo $itemsStr; ?></td>
                                        <td>
                                            <div class="small text-muted">Creado: <?php echo date('d/m H:i', $fechaCrea); ?></div>
                                            <?php if($fechaProg): ?>
                                                <div class="scheduled-date shadow-sm <?php echo !$isFuture ? 'urgent-date' : ''; ?>">
                                                    <i class="far fa-calendar-alt"></i> Reserva: <?php echo date('d/m h:i A', $fechaProg); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="fw-bold text-success">$<?php echo number_format($row['total'], 2); ?></td>
                                        <td><?php echo getStatusBadge($row['estado']); ?></td>
                                        <td class="text-end pe-3">
                                            <button class="btn btn-sm btn-primary shadow-sm" 
                                                    onclick="openManageModal(
                                                        <?php echo $row['id']; ?>, 
                                                        '<?php echo $row['estado']; ?>', 
                                                        `<?php echo addslashes($row['notas_admin'] ?? ''); ?>`
                                                    )">
                                                <i class="fas fa-edit"></i> Gestionar
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 3: WEB & ECOMMERCE -->
        <div class="tab-pane fade" id="tab-web">
            <h6 class="text-uppercase text-muted fw-bold fs-7 mb-3 ps-1"><i class="fas fa-mouse-pointer me-2"></i> Tráfico y Conversión</h6>
            <div class="row g-4 mb-4">
                <div class="col-md-3">
                    <div class="card card-stat kpi-orange h-100 shadow-sm">
                        <div class="card-body">
                            <h6 class="fw-bold small text-uppercase">Vistas Productos</h6>
                            <h3 class="fw-bold mb-0"><?php echo number_format($totalVistasProductos); ?></h3>
                            <i class="fas fa-eye icon-stat text-dark"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-stat kpi-orange h-100 shadow-sm">
                        <div class="card-body">
                            <h6 class="fw-bold small text-uppercase">Clientes Registrados</h6>
                            <h3 class="fw-bold mb-0"><?php echo number_format($usuariosRegistrados); ?></h3>
                            <i class="fas fa-users icon-stat text-dark"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-stat kpi-green h-100 shadow-sm">
                        <div class="card-body">
                            <h6 class="text-white opacity-75 fw-bold small text-uppercase">Visitantes (IPs Únicas)</h6>
                            <h3 class="fw-bold mb-0"><?php echo number_format($ipsUnicas); ?></h3>
                            <i class="fas fa-network-wired icon-stat text-white"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-stat kpi-green h-100 shadow-sm">
                        <div class="card-body">
                            <h6 class="text-white opacity-75 fw-bold small text-uppercase">Tasa de Conversión</h6>
                            <h3 class="fw-bold mb-0"><?php echo number_format($tasaConversion, 1); ?>%</h3>
                            <i class="fas fa-percentage icon-stat text-white"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-md-6">
                    <div class="card card-stat kpi-urls text-white h-100 shadow-sm">
                        <div class="card-body">
                            <h6 class="text-white opacity-75 fw-bold small text-uppercase">Página más popular</h6>
                            <h3 class="fw-bold mb-0"><?php echo htmlspecialchars($urlMasVisitada); ?></h3>
                            <i class="fas fa-link icon-stat text-white"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card shadow-sm h-100 border-0 border-top border-danger border-4">
                        <div class="card-header bg-white py-3 fw-bold d-flex justify-content-between align-items-center">
                            <span><i class="fas fa-shopping-cart text-danger me-2"></i> Carritos Perdidos</span>
                            <span class="badge bg-danger"><?php echo $carritosAbandonadosCount; ?></span>
                        </div>
                        <div class="card-body d-flex align-items-center justify-content-between py-2">
                            <div>
                                <h2 class="text-danger fw-bold mb-0">$<?php echo number_format($carritosTotalValor, 0); ?></h2>
                                <p class="text-muted small mb-0">Valor potencial no concretado</p>
                            </div>
                            <div class="text-end">
                                <i class="fas fa-ghost fa-3x text-light"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-5">
                <div class="col-md-4">
                    <div class="card shadow-sm h-100 border-0">
                        <div class="card-header bg-white py-3 fw-bold"><i class="fas fa-globe-americas text-primary me-2"></i> Origen de Visitas</div>
                        <div class="card-body p-0">
                            <table class="table table-sm table-hover mb-0 small">
                                <thead class="bg-light"><tr><th class="ps-3">País / IP</th><th class="text-end pe-3">Visitas</th></tr></thead>
                                <tbody>
                                    <?php foreach($topPaises as $p): ?>
                                    <tr>
                                        <td class="ps-3"><span class="badge bg-light text-dark border me-1">🌍</span> <?php echo $p['pais'] ?: 'Local/Desconocido'; ?></td>
                                        <td class="text-end pe-3 fw-bold"><?php echo $p['visitas']; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php foreach($topIps as $ip): ?>
                                    <tr class="text-muted">
                                        <td class="ps-3 small"><i class="fas fa-map-marker-alt me-1"></i> <?php echo $ip['ip']; ?></td>
                                        <td class="text-end pe-3"><?php echo $ip['hits']; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card shadow-sm h-100 border-0">
                        <div class="card-header bg-white py-3 fw-bold"><i class="fas fa-fire text-danger me-2"></i> Productos más vistos</div>
                        <div class="card-body p-0">
                            <table class="table table-sm table-hover mb-0 small">
                                <thead class="bg-light"><tr><th class="ps-3">Producto</th><th class="text-end pe-3">Vistas</th></tr></thead>
                                <tbody>
                                    <?php if(empty($topVistos)): ?>
                                        <tr><td colspan="2" class="text-center py-4 text-muted">Sin datos aún</td></tr>
                                    <?php else: ?>
                                        <?php foreach($topVistos as $v): ?>
                                        <tr>
                                            <td class="ps-3 text-truncate" style="max-width:150px;"><?php echo htmlspecialchars($v['nombre']); ?></td>
                                            <td class="text-end pe-3 fw-bold text-danger"><?php echo $v['vistas']; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card shadow-sm h-100 border-0 border-top border-warning border-4">
                        <div class="card-header bg-white py-3 fw-bold"><i class="fas fa-user-secret text-warning me-2"></i> Seguridad: Ataques</div>
                        <div class="card-body p-2">
                            <pre class="small text-danger bg-light p-2 rounded mb-0" style="font-size: 0.65rem; height: 180px; overflow-y: auto;"><?php echo htmlspecialchars($logAttacks ?: 'Sin amenazas detectadas'); ?></pre>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="card shadow-sm h-100 border-0">
                        <div class="card-header bg-white py-3 fw-bold"><i class="fas fa-network-wired text-primary me-2"></i> Top IPs (Log)</div>
                        <div class="card-body p-0">
                            <table class="table table-sm table-hover mb-0 small">
                                <tbody>
                                    <?php foreach($logIps as $ip): ?>
                                    <tr><td class="ps-3"><?php echo $ip['value']; ?></td><td class="text-end pe-3 fw-bold"><?php echo $ip['count']; ?></td></tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card shadow-sm h-100 border-0">
                        <div class="card-header bg-white py-3 fw-bold"><i class="fas fa-file-alt text-success me-2"></i> Recursos Top</div>
                        <div class="card-body p-0">
                            <table class="table table-sm table-hover mb-0 small">
                                <tbody>
                                    <?php foreach($logPages as $p): ?>
                                    <tr><td class="ps-3 text-truncate" style="max-width: 150px;"><?php echo htmlspecialchars($p['value']); ?></td><td class="text-end pe-3 fw-bold"><?php echo $p['count']; ?></td></tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card shadow-sm h-100 border-0">
                        <div class="card-header bg-white py-3 fw-bold"><i class="fas fa-laptop text-info me-2"></i> Browsers/Bots</div>
                        <div class="card-body p-0">
                            <table class="table table-sm table-hover mb-0 small">
                                <tbody>
                                    <?php foreach($logBrowsers as $b): ?>
                                    <tr><td class="ps-3 text-truncate" style="max-width: 150px;"><?php echo htmlspecialchars($b['value']); ?></td><td class="text-end pe-3 fw-bold"><?php echo $b['count']; ?></td></tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0 border-top border-danger border-4 mb-5">
                <div class="card-header bg-white py-3 fw-bold text-danger"><i class="fas fa-exclamation-circle me-2"></i> Errores del Servidor</div>
                <div class="card-body p-2">
                    <pre class="small bg-dark text-light p-3 rounded mb-0" style="font-size: 0.7rem; overflow-x: auto;"><?php echo htmlspecialchars($logErrors ?: 'No hay errores recientes.'); ?></pre>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="manageModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold">Gestionar Pedido #<span id="modalOrderId"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="inputOrderId">
                <div class="mb-4">
                    <label class="form-label fw-bold">Estado del Pedido</label>
                    <select class="form-select form-select-lg" id="inputOrderState">
                        <option value="pendiente">🟡 Pendiente (Recibido)</option>
                        <option value="proceso">🔵 En Cocina / Preparación</option>
                        <option value="camino">🛵 En Camino / Listo</option>
                        <option value="completado">🟢 Completado (Entregado)</option>
                        <option value="cancelado">🔴 Cancelado</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Nota para el Cliente</label>
                    <div class="alert alert-info py-2 small"><i class="fas fa-info-circle"></i> Visible en el rastreador.</div>
                    <textarea class="form-control" id="inputOrderNote" rows="3" placeholder="Ej: Motorista Juan en camino..."></textarea>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success fw-bold" onclick="saveChanges()">Guardar</button>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
    // Configuración del Gráfico de Pastel
    const ctxPagos = document.getElementById('chartPagos').getContext('2d');
    const pagosLabels = <?php echo json_encode(array_column($pagosData, 'metodo')); ?>;
    const pagosValues = <?php echo json_encode(array_column($pagosData, 'total')); ?>;
    
    // Convertir valores a números para asegurar compatibilidad con ChartJS
    const pagosValuesNum = pagosValues.map(Number);

    new Chart(ctxPagos, {
        type: 'doughnut',
        data: {
            labels: pagosLabels,
            datasets: [{
                data: pagosValuesNum,
                backgroundColor: ['#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b', '#858796'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'right' } },
            cutout: '70%'
        }
    });

    const manageModal = new bootstrap.Modal(document.getElementById('manageModal'));

    function openManageModal(id, estado, nota) {
        document.getElementById('inputOrderId').value = id;
        document.getElementById('modalOrderId').innerText = id;
        document.getElementById('inputOrderState').value = estado;
        document.getElementById('inputOrderNote').value = nota;
        manageModal.show();
    }

    async function saveChanges() {
        const id = document.getElementById('inputOrderId').value;
        const estado = document.getElementById('inputOrderState').value;
        const nota = document.getElementById('inputOrderNote').value;
        
        const btn = event.currentTarget;
        const oldHtml = btn.innerHTML;
        btn.disabled = true; btn.innerHTML = 'Guardando...';

        try {
            const resp = await fetch('update_order.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id, estado, nota })
            });
            const result = await resp.json();
            
            if(result.status === 'success') {
                location.reload(); 
            } else {
                alert('Error: ' + (result.msg || 'Desconocido'));
                btn.disabled = false; btn.innerHTML = oldHtml;
            }
        } catch (error) {
            alert('Error de conexión');
            btn.disabled = false; btn.innerHTML = oldHtml;
        }
    }

    setInterval(() => {
        if(!document.querySelector('.modal.show')) location.reload();
    }, 60000);
</script>



<?php include_once 'menu_master.php'; ?>
</body>
</html>

