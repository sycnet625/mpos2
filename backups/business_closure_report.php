<?php
// ARCHIVO: business_closure_report.php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

require_once 'db.php';

// 1. CARGAR CONFIGURACIÓN
$configFile = 'pos.cfg';
$config = ["semana_inicio_dia" => 1, "id_sucursal" => 1, "id_almacen" => 1, "reserva_limpieza_pct" => 10];
if (file_exists($configFile)) {
    $loaded = json_decode(file_get_contents($configFile), true);
    if ($loaded) $config = array_merge($config, $loaded);
}

$id_sucursal = intval($config['id_sucursal']);
$id_almacen = intval($config['id_almacen']);
$week_start_day = intval($config['semana_inicio_dia'] ?? 1);
$pct_reserva = floatval($config['reserva_limpieza_pct'] ?? 10);

// 2. MANEJO DE FECHAS
$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-d');
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-d');

if (isset($_GET['periodo'])) {
    $p = $_GET['periodo'];
    $today = new DateTime();
    if ($p === 'week') {
        $current_day_of_week = $today->format('w');
        $diff = ($current_day_of_week - $week_start_day + 7) % 7;
        $start = clone $today;
        $start->modify("-$diff days");
        $fecha_inicio = $start->format('Y-m-d');
        $fecha_fin = $today->format('Y-m-d');
    } elseif ($p === 'month') {
        $fecha_inicio = $today->format('Y-m-01');
        $fecha_fin = $today->format('Y-m-t');
    } elseif ($p === 'year') {
        $fecha_inicio = $today->format('Y-01-01');
        $fecha_fin = $today->format('Y-12-31');
    } elseif ($p === '30d') {
        $start = clone $today;
        $start->modify("-30 days");
        $fecha_inicio = $start->format('Y-m-d');
        $fecha_fin = $today->format('Y-m-d');
    } elseif ($p === '180d') {
        $start = clone $today;
        $start->modify("-180 days");
        $fecha_inicio = $start->format('Y-m-d');
        $fecha_fin = $today->format('Y-m-d');
    }
}

// 3. OBTENER DATOS (UNA SOLA FUENTE DE VERDAD)
try {
    // DESGLOSE DIARIO (La base para todo)
    $sqlDaily = "SELECT 
                    dates.dia,
                    COALESCE((SELECT SUM(total) FROM ventas_cabecera WHERE id_sucursal = ? AND DATE(fecha) = dates.dia), 0) as total_venta,
                    COALESCE((SELECT SUM(vd.cantidad * p.costo) 
                              FROM ventas_detalle vd 
                              JOIN ventas_cabecera vc ON vd.id_venta_cabecera = vc.id 
                              JOIN productos p ON vd.id_producto = p.codigo
                              WHERE vc.id_sucursal = ? AND DATE(vc.fecha) = dates.dia), 0) as total_costo,
                    COALESCE((SELECT SUM(monto) FROM gastos_historial WHERE id_sucursal = ? AND DATE(fecha) = dates.dia), 0) as total_gasto
                 FROM (
                    SELECT DISTINCT DATE(fecha) as dia FROM ventas_cabecera WHERE id_sucursal = ? AND DATE(fecha) BETWEEN ? AND ?
                    UNION
                    SELECT DISTINCT DATE(fecha) as dia FROM gastos_historial WHERE id_sucursal = ? AND DATE(fecha) BETWEEN ? AND ?
                 ) as dates
                 ORDER BY dates.dia ASC";
    $stmt = $pdo->prepare($sqlDaily);
    $stmt->execute([$id_sucursal, $id_sucursal, $id_sucursal, $id_sucursal, $fecha_inicio, $fecha_fin, $id_sucursal, $fecha_inicio, $fecha_fin]);
    $dailySummary = $stmt->fetchAll();

    // TOTALES CONSOLIDADOS DESDE EL ARRAY (Para evitar discrepancias)
    $venta_total = 0;
    $costo_total = 0;
    $gastos_totales = 0;
    foreach($dailySummary as $row) {
        $venta_total += floatval($row['total_venta']);
        $costo_total += floatval($row['total_costo']);
        $gastos_totales += floatval($row['total_gasto']);
    }

    $ganancia_bruta = $venta_total - $costo_total;
    $reserva_monto = $venta_total * ($pct_reserva / 100);
    $ganancia_limpia = $ganancia_bruta - $reserva_monto - $gastos_totales;
    $pct_margen_bruto = $venta_total > 0 ? ($ganancia_bruta / $venta_total) * 100 : 0;

    // PAGOS POR MÉTODO
    $stmt = $pdo->prepare("SELECT vp.metodo_pago, SUM(vp.monto) as total 
                           FROM ventas_pagos vp
                           JOIN ventas_cabecera vc ON vp.id_venta_cabecera = vc.id
                           WHERE vc.id_sucursal = ? AND DATE(vc.fecha) BETWEEN ? AND ?
                           GROUP BY vp.metodo_pago");
    $stmt->execute([$id_sucursal, $fecha_inicio, $fecha_fin]);
    $payments = $stmt->fetchAll();

    // INVENTARIO MÁX/MÍN
    $stmt = $pdo->prepare("SELECT DATE(fecha) as dia, SUM(cantidad_despues) as stock_total 
                           FROM kardex WHERE id_sucursal = ? AND DATE(fecha) BETWEEN ? AND ?
                           GROUP BY DATE(fecha)");
    $stmt->execute([$id_sucursal, $fecha_inicio, $fecha_fin]);
    $stockLog = $stmt->fetchAll();
    
    $max_stock = ['dia' => '-', 'valor' => 0];
    $min_stock = ['dia' => '-', 'valor' => 0];
    if (count($stockLog) > 0) {
        $vals = array_column($stockLog, 'stock_total');
        $max_idx = array_search(max($vals), $vals);
        $min_idx = array_search(min($vals), $vals);
        $max_stock = ['dia' => $stockLog[$max_idx]['dia'], 'valor' => $stockLog[$max_idx]['stock_total']];
        $min_stock = ['dia' => $stockLog[$min_idx]['dia'], 'valor' => $stockLog[$min_idx]['stock_total']];
    }

    // ROTACIÓN
    $stmt = $pdo->prepare("SELECT AVG(s) FROM (SELECT DATE(fecha), SUM(cantidad_despues) as s FROM kardex WHERE id_sucursal = ? GROUP BY DATE(fecha)) as t");
    $stmt->execute([$id_sucursal]);
    $avgStock = floatval($stmt->fetchColumn() ?: 1);
    $rotation = $costo_total / ($avgStock ?: 1);

} catch (Exception $e) { $error = $e->getMessage(); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cierre de Negocio | PalWeb</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background-color: #f1f3f9; font-family: 'Segoe UI', sans-serif; }
        .kpi-card { border: none; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .bg-gradient-primary { background: linear-gradient(135deg, #0d6efd, #0a58ca); color: white; }
        .bg-gradient-success { background: linear-gradient(135deg, #198754, #146c43); color: white; }
        .bg-gradient-danger { background: linear-gradient(135deg, #dc3545, #b02a37); color: white; }
        .bg-gradient-dark { background: linear-gradient(135deg, #212529, #000000); color: white; }
        .table-resumen thead th { background: #e9ecef; text-transform: uppercase; font-size: 0.7rem; letter-spacing: 0.5px; }
        .badge-header { font-size: 0.75rem; padding: 5px 12px; border-radius: 20px; background: rgba(255,255,255,0.2); }
        .small-pct { font-size: 0.85rem; background: rgba(255,255,255,0.25); padding: 2px 8px; border-radius: 10px; margin-left: 5px; font-weight: bold; }
    </style>
</head>
<body class="p-3">

<div class="container-fluid">
    <div class="bg-gradient-dark p-3 rounded-3 shadow-sm mb-4 d-flex justify-content-between align-items-center">
        <div>
            <h3 class="m-0 fw-bold"><i class="fas fa-chart-line text-warning me-2"></i> CIERRE DE NEGOCIO</h3>
            <div class="mt-2">
                <span class="badge-header me-2"><i class="fas fa-store"></i> Sucursal: <?php echo $id_sucursal; ?></span>
                <span class="badge-header"><i class="fas fa-warehouse"></i> Almacén: <?php echo $id_almacen; ?></span>
            </div>
        </div>
        <div class="text-end">
            <a href="dashboard.php" class="btn btn-outline-light btn-sm me-2"><i class="fas fa-home"></i></a>
            <button class="btn btn-warning btn-sm fw-bold" onclick="window.print()"><i class="fas fa-print"></i> IMPRIMIR</button>
        </div>
    </div>

    <!-- FILTROS -->
    <div class="card mb-4 kpi-card">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="small fw-bold">Desde</label>
                    <input type="date" name="fecha_inicio" class="form-control form-control-sm" value="<?php echo $fecha_inicio; ?>">
                </div>
                <div class="col-md-2">
                    <label class="small fw-bold">Hasta</label>
                    <input type="date" name="fecha_fin" class="form-control form-control-sm" value="<?php echo $fecha_fin; ?>">
                </div>
                <div class="col-md-8 text-end">
                    <div class="btn-group btn-group-sm">
                        <a href="?periodo=week" class="btn btn-outline-primary">Semana</a>
                        <a href="?periodo=month" class="btn btn-outline-primary">Mes</a>
                        <a href="?periodo=year" class="btn btn-outline-primary">Año</a>
                        <a href="?periodo=30d" class="btn btn-outline-primary">30 Días</a>
                        <a href="?periodo=180d" class="btn btn-outline-primary">180 Días</a>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm ms-2 px-4 fw-bold">FILTRAR</button>
                </div>
            </form>
        </div>
    </div>

    <!-- FILA 1: KPIs PRINCIPALES (BASADOS EN TOTALES CONSOLIDADOS) -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card kpi-card bg-gradient-primary h-100">
                <div class="card-body">
                    <h6 class="small text-uppercase opacity-75">Venta Total</h6>
                    <h2 class="fw-bold mb-0">$<?php echo number_format($venta_total, 2); ?></h2>
                    <small class="opacity-75">Ingresos brutos</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card kpi-card bg-gradient-success h-100">
                <div class="card-body">
                    <h6 class="small text-uppercase opacity-75">Ganancia Bruta <span class="small-pct"><?php echo number_format($pct_margen_bruto, 1); ?>%</span></h6>
                    <h2 class="fw-bold mb-0">$<?php echo number_format($ganancia_bruta, 2); ?></h2>
                    <small class="opacity-75">Venta - Costo Mercancía</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card kpi-card bg-gradient-danger h-100">
                <div class="card-body">
                    <h6 class="small text-uppercase opacity-75">Gastos Operativos</h6>
                    <h2 class="fw-bold mb-0">$<?php echo number_format($gastos_totales, 2); ?></h2>
                    <small class="opacity-75">Total de gastos</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card kpi-card bg-dark text-white h-100 border-warning border-start border-4">
                <div class="card-body">
                    <h6 class="small text-uppercase text-warning fw-bold">Ganancia Limpia</h6>
                    <h2 class="fw-bold mb-0">$<?php echo number_format($ganancia_limpia, 2); ?></h2>
                    <small class="text-white-50">Reserva (<?php echo $pct_reserva; ?>%): $<?php echo number_format($reserva_monto, 2); ?></small>
                </div>
            </div>
        </div>
    </div>

    <!-- FILA: DESGLOSE DIARIO DE OPERACIONES -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card kpi-card shadow-sm">
                <div class="card-header bg-white fw-bold">
                    <i class="fas fa-calendar-alt text-primary me-2"></i> DESGLOSE DIARIO DE OPERACIONES
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-resumen mb-0">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th class="text-end">Ventas ($)</th>
                                    <th class="text-end text-muted">Costo Merc. ($)</th>
                                    <th class="text-end">Ganancia Bruta ($)</th>
                                    <th class="text-end text-danger">Gastos ($)</th>
                                    <th class="text-end fw-bold text-success">Saldo Neto ($)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($dailySummary as $row): 
                                    $v = floatval($row['total_venta']);
                                    $c = floatval($row['total_costo']);
                                    $e = floatval($row['total_gasto']);
                                    $gb = $v - $c;
                                    $sn = $gb - $e;
                                ?>
                                <tr>
                                    <td class="small fw-bold"><?php echo $row['dia']; ?></td>
                                    <td class="text-end">$<?php echo number_format($v, 2); ?></td>
                                    <td class="text-end text-muted">$<?php echo number_format($c, 2); ?></td>
                                    <td class="text-end">$<?php echo number_format($gb, 2); ?></td>
                                    <td class="text-end text-danger">$<?php echo number_format($e, 2); ?></td>
                                    <td class="text-end fw-bold text-success">$<?php echo number_format($sn, 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="bg-light fw-bold border-top">
                                <tr>
                                    <td>TOTALES</td>
                                    <td class="text-end">$<?php echo number_format($venta_total, 2); ?></td>
                                    <td class="text-end text-muted">$<?php echo number_format($costo_total, 2); ?></td>
                                    <td class="text-end">$<?php echo number_format($ganancia_bruta, 2); ?></td>
                                    <td class="text-end text-danger">$<?php echo number_format($gastos_totales, 2); ?></td>
                                    <td class="text-end text-success">$<?php echo number_format($ganancia_bruta - $gastos_totales, 2); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- FILA 3: GRÁFICOS Y ESTADÍSTICAS -->
    <div class="row g-3">
        <div class="col-md-8">
            <div class="card kpi-card mb-4">
                <div class="card-header bg-white fw-bold">Tendencia de Ventas Diarias</div>
                <div class="card-body">
                    <canvas id="dailyChart" height="100"></canvas>
                </div>
            </div>
            
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="card kpi-card">
                        <div class="card-header bg-white fw-bold">Métodos de Pago</div>
                        <div class="card-body">
                            <canvas id="paymentChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card kpi-card">
                        <div class="card-header bg-white fw-bold">Estadísticas de Inventario</div>
                        <div class="card-body">
                            <div class="mb-3 text-center">
                                <h1 class="fw-bold text-info m-0"><?php echo number_format($rotation, 2); ?>x</h1>
                                <small class="text-muted text-uppercase fw-bold">Rotación de Inventario</small>
                            </div>
                            <hr>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span><i class="fas fa-arrow-up text-success me-2"></i> Inventario Máximo:</span>
                                <span class="fw-bold"><?php echo number_format($max_stock['valor'], 0); ?> uds <small class="text-muted">(<?php echo $max_stock['dia']; ?>)</small></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-arrow-down text-danger me-2"></i> Inventario Mínimo:</span>
                                <span class="fw-bold"><?php echo number_format($min_stock['valor'], 0); ?> uds <small class="text-muted">(<?php echo $min_stock['dia']; ?>)</small></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card kpi-card h-100">
                <div class="card-header bg-white fw-bold">Resumen Detallado de Pagos</div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Método</th>
                                <th class="text-end">Monto Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($payments as $p): ?>
                            <tr>
                                <td class="fw-bold"><?php echo $p['metodo_pago']; ?></td>
                                <td class="text-end fw-bold">$<?php echo number_format($p['total'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="p-3 bg-light mt-auto border-top">
                        <small class="text-muted d-block mb-1">Deducción para Reserva (<?php echo $pct_reserva; ?>%):</small>
                        <h5 class="fw-bold text-danger">$<?php echo number_format($reserva_monto, 2); ?></h5>
                        <p class="small text-muted m-0">Basado en la venta bruta del periodo.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    new Chart(document.getElementById('dailyChart'), {
        type: 'line',
        data: {
            labels: <?php echo json_encode(array_column($dailySummary, 'dia')); ?>,
            datasets: [{
                label: 'Ventas ($)',
                data: <?php echo json_encode(array_column($dailySummary, 'total_venta')); ?>,
                borderColor: '#0d6efd',
                backgroundColor: 'rgba(13, 110, 253, 0.1)',
                fill: true,
                tension: 0.2
            }]
        },
        options: { responsive: true, plugins: { legend: { display: false } } }
    });

    new Chart(document.getElementById('paymentChart'), {
        type: 'pie',
        data: {
            labels: <?php echo json_encode(array_column($payments, 'metodo_pago')); ?>,
            datasets: [{
                data: <?php echo json_encode(array_column($payments, 'total')); ?>,
                backgroundColor: ['#0d6efd', '#198754', '#ffc107', '#dc3545', '#0dcaf0', '#6610f2']
            }]
        },
        options: { plugins: { legend: { position: 'bottom' } } }
    });
</script>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<?php include_once 'menu_master.php'; ?>
</body>
</html>