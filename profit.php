<?php
// ARCHIVO: profit.php
// VERSIÓN MULTI-SUCURSAL CON VISTA GLOBAL
ini_set('display_errors', 0);
error_reporting(E_ALL);

// ---------------------------------------------------------
// 🔒 SEGURIDAD: VERIFICACIÓN DE SESIÓN
// ---------------------------------------------------------
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}


try {
    require_once 'db.php';
    date_default_timezone_set('America/Havana');
    $pdo->exec("SET time_zone = '-05:00';");

    // 1. CARGAR CONFIGURACIÓN LOCAL (Para saber la sucursal actual)
    require_once 'config_loader.php';
    
    $EMP_ID = intval($config['id_empresa']);
    $SUC_ID = intval($config['id_sucursal']);

    // 2. FILTROS DE VISTA (GLOBAL vs LOCAL)
    $viewMode = $_GET['view'] ?? 'local'; // 'local' por defecto
    $isGlobal = ($viewMode === 'global');
    $excludeRaw = isset($_GET['exclude_raw']) ? true : false;

    // Construcción de condiciones SQL
    $sqlFilterProduct = $excludeRaw ? " AND p.es_elaborado = 1 " : "";
    
    // Filtro de Sucursal Dinámico
    if ($isGlobal) {
        // Si es global, NO filtramos por sucursal (vemos todo lo de la empresa)
        $sqlFilterSucursal = ""; 
        $queryParams = [':emp' => $EMP_ID];
        $tituloVista = "VISTA GLOBAL (Todas las Sucursales)";
        $btnText = "🏠 Ver SUCURSAL ACTUAL";
        $btnLink = "?view=local" . ($excludeRaw ? "&exclude_raw=1" : "");
        $btnClass = "btn-warning";
    } else {
        // Si es local, filtramos por la sucursal del archivo de config
        $sqlFilterSucursal = " AND v.id_sucursal = :suc ";
        $queryParams = [':emp' => $EMP_ID, ':suc' => $SUC_ID];
        $tituloVista = "SUCURSAL #$SUC_ID (Local)";
        $btnText = "🌍 Ver GLOBAL";
        $btnLink = "?view=global" . ($excludeRaw ? "&exclude_raw=1" : "");
        $btnClass = "btn-info text-white";
    }

    $dates = [];
    $kpiSales = 0;
    $kpiProfit = 0;

    for ($i = 29; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $dates[$date] = ['venta' => 0, 'ganancia' => 0, 'label' => date('d/m', strtotime($date))];
    }

    // CONSULTA GRÁFICO USANDO FECHA CONTABLE + FILTRO SUCURSAL
    $sqlGraph = "SELECT 
                s.fecha_contable as dia,
                SUM(d.precio * d.cantidad) as total_venta,
                SUM((d.precio - p.costo) * d.cantidad) as total_ganancia
            FROM ventas_cabecera v
            JOIN caja_sesiones s ON v.id_caja = s.id
            JOIN ventas_detalle d ON v.id = d.id_venta_cabecera
            JOIN productos p ON d.id_producto = p.codigo
            WHERE v.id_empresa = :emp 
              AND s.fecha_contable >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
              $sqlFilterProduct
              $sqlFilterSucursal
            GROUP BY s.fecha_contable";

    $stmt = $pdo->prepare($sqlGraph);
    $stmt->execute($queryParams);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (isset($dates[$row['dia']])) {
            $dates[$row['dia']]['venta'] = floatval($row['total_venta']);
            $dates[$row['dia']]['ganancia'] = floatval($row['total_ganancia']);
            $kpiSales += $dates[$row['dia']]['venta'];
            $kpiProfit += $dates[$row['dia']]['ganancia'];
        }
    }

    $kpiMargin = ($kpiSales > 0) ? ($kpiProfit / $kpiSales) * 100 : 0;
    $chartLabels = array_column($dates, 'label');
    $chartSales = array_column($dates, 'venta');
    $chartProfit = array_column($dates, 'ganancia');

    // TOP PRODUCTOS USANDO FECHA CONTABLE + FILTRO SUCURSAL
    $sqlBaseProducts = "SELECT 
                p.nombre, p.categoria,
                SUM(d.cantidad) as unidades,
                SUM((d.precio - p.costo) * d.cantidad) as ganancia_total
            FROM ventas_detalle d
            JOIN productos p ON d.id_producto = p.codigo
            JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id
            JOIN caja_sesiones s ON v.id_caja = s.id
            WHERE v.id_empresa = :emp 
              AND s.fecha_contable >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
              $sqlFilterProduct
              $sqlFilterSucursal
            GROUP BY p.codigo, p.nombre, p.categoria ";

    $stmtTop = $pdo->prepare($sqlBaseProducts . " ORDER BY ganancia_total DESC LIMIT 10");
    $stmtTop->execute($queryParams);
    $topProducts = $stmtTop->fetchAll(PDO::FETCH_ASSOC);

    $stmtLow = $pdo->prepare($sqlBaseProducts . " ORDER BY ganancia_total ASC LIMIT 10");
    $stmtLow->execute($queryParams);
    $lowProducts = $stmtLow->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) { die("Error: " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Análisis de Rentabilidad 💰</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background-color: #f8f9fc; font-family: 'Segoe UI', sans-serif; }
        .card-kpi { border: none; border-radius: 15px; overflow: hidden; transition: transform 0.2s; }
        .bg-gradient-blue { background: linear-gradient(135deg, #4e73df 0%, #224abe 100%); color: white; }
        .bg-gradient-green { background: linear-gradient(135deg, #1cc88a 0%, #13855c 100%); color: white; }
        .bg-gradient-purple { background: linear-gradient(135deg, #6f42c1 0%, #59359a 100%); color: white; }
        .kpi-value { font-size: 2rem; font-weight: 800; }
    </style>
</head>
<body class="p-4">
<div class="container-fluid">
    <div class="row mb-4 align-items-center">
        <div class="col-md-5">
            <h2 class="fw-bold text-dark mb-0"><i class="fas fa-chart-pie text-success"></i> Rentabilidad</h2>
            <p class="text-muted mb-0">
                <span class="badge bg-dark"><?php echo $tituloVista; ?></span> 
                <small class="ms-2">Últimos 30 días (Fecha Contable)</small>
            </p>
        </div>
        <div class="col-md-7 d-flex justify-content-end align-items-center gap-2 flex-wrap">
            <a href="<?php echo $btnLink; ?>" class="btn <?php echo $btnClass; ?> fw-bold shadow-sm">
                <?php echo $btnText; ?>
            </a>

            <form method="GET" id="filterForm" class="d-flex align-items-center bg-white p-2 rounded shadow-sm border m-0">
                <input type="hidden" name="view" value="<?php echo $viewMode; ?>">
                
                <div class="form-check form-switch m-0">
                    <input class="form-check-input" type="checkbox" id="excludeRaw" name="exclude_raw" value="1" <?php echo $excludeRaw ? 'checked' : ''; ?> onchange="document.getElementById('filterForm').submit()">
                    <label class="form-check-label fw-bold text-dark small text-nowrap" for="excludeRaw">Solo Prod. Finales</label>
                </div>
            </form>
            <a href="dashboard.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Volver</a>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-md-4"><div class="card card-kpi bg-gradient-blue shadow h-100 p-4"><div class="text-uppercase opacity-75 fw-bold">Ventas Totales</div><div class="kpi-value">$<?php echo number_format($kpiSales, 2); ?></div></div></div>
        <div class="col-md-4"><div class="card card-kpi bg-gradient-green shadow h-100 p-4"><div class="text-uppercase opacity-75 fw-bold">Ganancia Neta</div><div class="kpi-value">$<?php echo number_format($kpiProfit, 2); ?></div></div></div>
        <div class="col-md-4"><div class="card card-kpi bg-gradient-purple shadow h-100 p-4"><div class="text-uppercase opacity-75 fw-bold">Rentabilidad</div><div class="kpi-value"><?php echo number_format($kpiMargin, 1); ?>%</div></div></div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3 bg-white d-flex justify-content-between">
            <h6 class="m-0 fw-bold text-primary">📊 Evolución Diaria Administrativa</h6>
            <span class="badge bg-light text-dark border"><?php echo $tituloVista; ?></span>
        </div>
        <div class="card-body"><div style="height: 350px;"><canvas id="profitChart"></canvas></div></div>
    </div>

    <div class="row g-4">
        <div class="col-md-6">
            <div class="card shadow h-100 border-start border-success border-5">
                <div class="card-header bg-white"><h6 class="m-0 fw-bold text-success">Top 10 Más Rentables</h6></div>
                <div class="card-body p-0">
                    <table class="table table-striped mb-0">
                        <thead><tr><th>Producto</th><th class="text-center">Cant.</th><th class="text-end">Ganancia</th></tr></thead>
                        <tbody>
                            <?php foreach ($topProducts as $p): ?>
                            <tr><td><strong><?php echo htmlspecialchars($p['nombre']); ?></strong></td><td class="text-center"><?php echo number_format($p['unidades']); ?></td><td class="text-end text-success">+$<?php echo number_format($p['ganancia_total'], 2); ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card shadow h-100 border-start border-danger border-5">
                <div class="card-header bg-white"><h6 class="m-0 fw-bold text-danger">Top 10 Menos Rentables</h6></div>
                <div class="card-body p-0">
                    <table class="table table-striped mb-0">
                        <thead><tr><th>Producto</th><th class="text-center">Cant.</th><th class="text-end">Ganancia</th></tr></thead>
                        <tbody>
                            <?php foreach ($lowProducts as $p): ?>
                            <tr><td><strong><?php echo htmlspecialchars($p['nombre']); ?></strong></td><td class="text-center"><?php echo number_format($p['unidades']); ?></td><td class="text-end text-danger">$<?php echo number_format($p['ganancia_total'], 2); ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
    const ctx = document.getElementById('profitChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($chartLabels); ?>,
            datasets: [
                { label: 'Ventas ($)', data: <?php echo json_encode($chartSales); ?>, backgroundColor: 'rgba(78, 115, 223, 0.8)', borderRadius: 4 },
                { label: 'Ganancia ($)', data: <?php echo json_encode($chartProfit); ?>, backgroundColor: 'rgba(28, 200, 138, 0.9)', borderRadius: 4 }
            ]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
    });
</script>

<?php include_once 'menu_master.php'; ?>
</body>
</html>
