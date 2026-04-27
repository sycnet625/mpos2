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
    require_once 'business_metrics.php';
    date_default_timezone_set('America/Havana');
    $pdo->exec("SET time_zone = '-05:00';");

    require_once 'config_loader.php';

    $EMP_ID = intval($config['id_empresa']);
    $SUC_ID = intval($config['id_sucursal']);

    $viewMode   = $_GET['view'] ?? 'local';
    $isGlobal   = ($viewMode === 'global');
    $excludeRaw = !empty($_GET['exclude_raw']);

    if ($isGlobal) {
        $tituloVista = "VISTA GLOBAL (Todas las Sucursales)";
        $btnText  = "🏠 Ver SUCURSAL ACTUAL";
        $btnLink  = "?view=local" . ($excludeRaw ? "&exclude_raw=1" : "");
        $btnClass = "btn-warning";
    } else {
        $tituloVista = "SUCURSAL #$SUC_ID (Local)";
        $btnText  = "🌍 Ver GLOBAL";
        $btnLink  = "?view=global" . ($excludeRaw ? "&exclude_raw=1" : "");
        $btnClass = "btn-info text-white";
    }

    $fechaInicio = date('Y-m-d', strtotime('-29 days'));
    $fechaFin    = date('Y-m-d');

    $m = bm_calcular($pdo, [
        'fecha_inicio' => $fechaInicio,
        'fecha_fin'    => $fechaFin,
        'id_empresa'   => $EMP_ID,
        'id_sucursal'  => $isGlobal ? null : $SUC_ID,
        'secciones'    => [BM_VENTAS, BM_SERIES, BM_PRODUCTOS],
        'limite_top'   => 10,
    ]);

    // KPIs principales
    $kpiSales  = $m[BM_VENTAS]['total'];
    $kpiProfit = $m[BM_VENTAS]['ganancia_bruta'];
    $kpiMargin = $m[BM_VENTAS]['margen_bruto_pct'];

    // Construir serie de 30 días (incluye días sin actividad como ceros)
    $dates = [];
    for ($i = 29; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $dates[$date] = ['venta' => 0.0, 'ganancia' => 0.0, 'label' => date('d/m', strtotime($date))];
    }
    foreach ($m[BM_SERIES] as $row) {
        if (isset($dates[$row['fecha']])) {
            $dates[$row['fecha']]['venta']    = $row['venta'];
            $dates[$row['fecha']]['ganancia'] = $row['ganancia'];
        }
    }
    $chartLabels = array_column($dates, 'label');
    $chartSales  = array_column($dates, 'venta');
    $chartProfit = array_column($dates, 'ganancia');

    // Productos top/bottom — con filtro es_elaborado si aplica
    $topGanancia = $m[BM_PRODUCTOS]['top_por_ganancia'];
    $botGanancia = $m[BM_PRODUCTOS]['bottom_por_ganancia'];
    if ($excludeRaw) {
        $topGanancia = array_values(array_filter($topGanancia, fn($p) => !empty($p['es_elaborado'])));
        $botGanancia = array_values(array_filter($botGanancia, fn($p) => !empty($p['es_elaborado'])));
    }
    $topProducts = array_slice($topGanancia, 0, 10);
    $lowProducts = array_slice($botGanancia, 0, 10);

    // Adaptar claves para compatibilidad con la vista HTML (unidades, ganancia_total)
    $topProducts = array_map(fn($p) => array_merge($p, ['unidades' => $p['cantidad'], 'ganancia_total' => $p['ganancia']]), $topProducts);
    $lowProducts = array_map(fn($p) => array_merge($p, ['unidades' => $p['cantidad'], 'ganancia_total' => $p['ganancia']]), $lowProducts);

} catch (Exception $e) { die("Error: " . $e->getMessage()); }
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Analisis de Rentabilidad</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="assets/css/inventory-suite.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background-color: #f8f9fc; }
        .table thead th { white-space: nowrap; }
    </style>
</head>
<body class="pb-5 inventory-suite">
<div class="container-fluid shell inventory-shell py-4 py-lg-5">
    <section class="glass-card inventory-hero p-4 p-lg-5 mb-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-4 align-items-start">
            <div>
                <div class="section-title text-white-50 mb-2">Finanzas / KPI</div>
                <h1 class="h2 fw-bold mb-2"><i class="fas fa-chart-pie me-2"></i>Analisis de Rentabilidad</h1>
                <p class="mb-3 text-white-50">Ventas y ganancias de los ultimos 30 dias por fecha contable.</p>
                <div class="d-flex flex-wrap gap-2">
                    <span class="kpi-chip"><i class="fas fa-building me-1"></i><?php echo htmlspecialchars($tituloVista); ?></span>
                    <span class="kpi-chip"><i class="fas fa-calendar me-1"></i>Ultimos 30 dias</span>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="<?php echo $btnLink; ?>" class="btn <?php echo $btnClass; ?> fw-bold">
                    <?php echo $btnText; ?>
                </a>
                <form method="GET" id="filterForm" class="d-flex align-items-center bg-white p-2 rounded shadow-sm border m-0">
                    <input type="hidden" name="view" value="<?php echo $viewMode; ?>">
                    <div class="form-check form-switch m-0">
                        <input class="form-check-input" type="checkbox" id="excludeRaw" name="exclude_raw" value="1" <?php echo $excludeRaw ? 'checked' : ''; ?> onchange="document.getElementById('filterForm').submit()">
                        <label class="form-check-label fw-bold text-dark small text-nowrap" for="excludeRaw">Solo Prod. Finales</label>
                    </div>
                </form>
                <a href="dashboard.php" class="btn btn-outline-light"><i class="fas fa-arrow-left me-1"></i>Volver</a>
            </div>
        </div>
    </section>

    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="glass-card p-4 h-100">
                <div class="stat-box">
                    <div class="tiny text-muted">Ventas Totales</div>
                    <div class="summary-total text-primary">$<?php echo number_format($kpiSales, 2); ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="glass-card p-4 h-100">
                <div class="stat-box">
                    <div class="tiny text-muted">Ganancia Neta</div>
                    <div class="summary-total text-success">$<?php echo number_format($kpiProfit, 2); ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="glass-card p-4 h-100">
                <div class="stat-box">
                    <div class="tiny text-muted">Rentabilidad</div>
                    <div class="summary-total text-warning"><?php echo number_format($kpiMargin, 1); ?>%</div>
                </div>
            </div>
        </div>
    </div>

    <div class="glass-card p-4 mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <div class="section-title">Grafico</div>
                <h2 class="h5 fw-bold mb-0">Evolucion Diaria Administrativa</h2>
            </div>
            <span class="soft-pill"><?php echo $tituloVista; ?></span>
        </div>
        <div style="height: 350px;"><canvas id="profitChart"></canvas></div>
    </div>

    <div class="row g-4">
        <div class="col-md-6">
            <div class="glass-card p-4 h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <div class="section-title">Top</div>
                        <h2 class="h5 fw-bold mb-0 text-success">Top 10 Mas Rentables</h2>
                    </div>
                    <span class="soft-pill"><i class="fas fa-crown me-1"></i>Mayores ganancias</span>
                </div>
                <div class="table-responsive border rounded-4 bg-white" style="max-height: 400px; overflow:auto;">
                    <table class="table align-middle mb-0">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th>Producto</th>
                                <th class="text-center">Cant.</th>
                                <th class="text-end">Ganancia</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($topProducts)): ?>
                            <tr><td colspan="3" class="text-center py-4 text-muted">Sin datos disponibles.</td></tr>
                            <?php else: foreach ($topProducts as $p): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($p['nombre']); ?></div>
                                    <div class="tiny text-muted"><?php echo htmlspecialchars($p['categoria']); ?></div>
                                </td>
                                <td class="text-center"><?php echo number_format($p['unidades']); ?></td>
                                <td class="text-end text-success fw-bold">+$<?php echo number_format($p['ganancia_total'], 2); ?></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="glass-card p-4 h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <div class="section-title">Top</div>
                        <h2 class="h5 fw-bold mb-0 text-danger">Top 10 Menos Rentables</h2>
                    </div>
                    <span class="soft-pill"><i class="fas fa-exclamation-triangle me-1"></i>Menores ganancias</span>
                </div>
                <div class="table-responsive border rounded-4 bg-white" style="max-height: 400px; overflow:auto;">
                    <table class="table align-middle mb-0">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th>Producto</th>
                                <th class="text-center">Cant.</th>
                                <th class="text-end">Ganancia</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($lowProducts)): ?>
                            <tr><td colspan="3" class="text-center py-4 text-muted">Sin datos disponibles.</td></tr>
                            <?php else: foreach ($lowProducts as $p): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($p['nombre']); ?></div>
                                    <div class="tiny text-muted"><?php echo htmlspecialchars($p['categoria']); ?></div>
                                </td>
                                <td class="text-center"><?php echo number_format($p['unidades']); ?></td>
                                <td class="text-end text-danger fw-bold">$<?php echo number_format($p['ganancia_total'], 2); ?></td>
                            </tr>
                            <?php endforeach; endif; ?>
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
