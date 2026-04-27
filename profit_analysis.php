<?php
// ARCHIVO: /var/www/palweb/api/profit_analysis.php
// VERSIÓN FINAL: FILTRADO POR SUCURSAL & BI COMPLETO

ini_set('display_errors', 0);
require_once 'db.php';
require_once 'accounting_helpers.php';
require_once 'business_metrics.php';


// ---------------------------------------------------------
// 🔒 SEGURIDAD: VERIFICACIÓN DE SESIÓN
// ---------------------------------------------------------
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}


// ---------------------------------------------------------
// 1. CARGAR CONFIGURACIÓN (MULTISUCURSAL)
// ---------------------------------------------------------
require_once 'config_loader.php';

function profitTableHasColumn(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    $cache[$key] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$key];
}

// Variables de Entorno
$EMP_ID = intval($config['id_empresa']);
$SUC_ID = intval($config['id_sucursal']); // <--- VARIABLE CLAVE AHORA
$ALM_ID = intval($config['id_almacen']);

// 2. FILTROS DE VISTA (GLOBAL vs LOCAL) - IGUAL QUE profit.php
$viewMode = $_GET['view'] ?? 'local'; // 'local' por defecto
$isGlobal = ($viewMode === 'global');

// Construcción de condiciones SQL
if ($isGlobal) {
    // Si es global, NO filtramos por sucursal (vemos todo lo de la empresa)
    $sqlFilterSucursal = ""; 
    $tituloVista = "VISTA GLOBAL (Todas las Sucursales)";
    $btnText = "🏠 Ver SUCURSAL ACTUAL";
    $btnLink = "?view=local&start=$start&end=$end";
    $btnClass = "btn-warning";
} else {
    // Si es local, filtramos por la sucursal del archivo de config
    $sqlFilterSucursal = " AND id_sucursal = $SUC_ID ";
    $tituloVista = "SUCURSAL #$SUC_ID (Local)";
    $btnText = "🌐 Ver GLOBAL";
    $btnLink = "?view=global&start=$start&end=$end";
    $btnClass = "btn-info text-white";
}

// Filtros de Fecha
$start = $_GET['start'] ?? date('Y-m-01');
$end   = $_GET['end']   ?? date('Y-m-t');

try {
    $m = bm_calcular($pdo, [
        'fecha_inicio' => $start,
        'fecha_fin'    => $end,
        'id_empresa'   => $EMP_ID,
        'id_sucursal'  => $isGlobal ? null : $SUC_ID,
        'id_almacen'   => $isGlobal ? null : $ALM_ID,
        'secciones'    => [BM_VENTAS, BM_GASTOS, BM_INVENTARIO],
    ]);

    // Estado de Resultados (P&L)
    // Ingresos netos = ventas brutas − devoluciones (estándar contable)
    $ingresosVentas   = $m[BM_VENTAS]['total'] - $m[BM_VENTAS]['devoluciones']['valor'];
    $costoVentas      = $m[BM_VENTAS]['costo'];
    $utilidadBruta    = $ingresosVentas - $costoVentas;
    $totalGastos      = $m[BM_GASTOS]['total'];
    $totalMermas      = $m[BM_GASTOS]['mermas'];
    $utilidadOperativa = $utilidadBruta - $totalGastos - $totalMermas;
    $margenNeto       = $ingresosVentas > 0 ? ($utilidadOperativa / $ingresosVentas) * 100 : 0.0;
    $gastosDetalle    = array_map(
        fn($cat, $total) => ['categoria' => $cat, 'total' => $total],
        array_keys($m[BM_GASTOS]['por_categoria']),
        array_values($m[BM_GASTOS]['por_categoria'])
    );

    // Balance (inventario snapshot)
    $valorInventario = $m[BM_INVENTARIO]['valor_costo'];
    $cajaEstimada    = 5000;

    // Flujo de efectivo — compras se recalculan usando el mismo helper que bm_calcular
    $salidaCompras = $m[BM_GASTOS]['compras'];
    $flujoNeto     = $ingresosVentas - ($salidaCompras + $totalGastos);

} catch (Exception $e) {
    die("Error en Reporte Financiero: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>BI & Finanzas de Sucursal</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <?php require_once __DIR__ . '/theme.php'; ?>
    <link rel="stylesheet" href="assets/css/inventory-suite.css">
    <style>
        .card-fin { border:none; border-radius:15px; box-shadow:0 5px 15px rgba(0,0,0,0.05); transition:transform 0.2s; height: 100%; }
        .card-fin:hover { transform: translateY(-5px); }
        .metric-value { font-size: 1.8rem; font-weight: 800; }
        .metric-label { text-transform: uppercase; letter-spacing: 1px; font-size: 0.75rem; color: #6c757d; font-weight: bold; }
        .header-title { border-left: 5px solid #0d6efd; padding-left: 15px; }
    </style>
</head>
<body class="pb-5 inventory-suite">
<div class="container-fluid shell inventory-shell py-4 py-lg-5">

    <section class="glass-card inventory-hero p-4 p-lg-5 mb-4 inventory-fade-in">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-4 align-items-start">
            <div>
                <div class="section-title text-white-50 mb-2">Business Intelligence / Finanzas</div>
                <h1 class="h2 fw-bold mb-2"><i class="fas fa-chart-pie me-2"></i>Inteligencia Financiera</h1>
                <p class="mb-3 text-white-50">Estado de resultados, flujo de efectivo y situación financiera por sucursal.</p>
                <div class="d-flex flex-wrap gap-2">
                    <span class="kpi-chip"><i class="fas fa-building me-1"></i><?php echo $tituloVista; ?></span>
                    <?php if (!$isGlobal): ?>
                    <span class="kpi-chip"><i class="fas fa-warehouse me-1"></i>Almacén <?php echo $ALM_ID; ?></span>
                    <?php else: ?>
                    <span class="kpi-chip"><i class="fas fa-globe me-1"></i>Todas las Sucursales</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="<?php echo $btnLink; ?>" class="btn <?php echo $btnClass; ?> fw-bold">
                    <?php echo $btnText; ?>
                </a>
                <a href="dashboard.php" class="btn btn-outline-light"><i class="fas fa-home me-1"></i>Volver</a>
            </div>
        </div>
    </section>

    <form class="inventory-tablist d-inline-flex mb-4 flex-wrap gap-2">
        <input type="hidden" name="view" value="<?php echo $viewMode; ?>">
        <label class="form-label small fw-bold text-muted me-2">Periodo:</label>
        <input type="date" name="start" class="form-control form-control-sm" value="<?php echo $start; ?>">
        <input type="date" name="end" class="form-control form-control-sm" value="<?php echo $end; ?>">
        <button class="btn btn-primary btn-sm fw-bold"><i class="fas fa-filter me-1"></i>Filtrar</button>
    </form>

    <h5 class="text-secondary fw-bold mb-3 header-title">
        ESTADO DE RESULTADOS (P&L) - <?php echo $isGlobal ? 'TODAS LAS SUCURSALES' : "SUCURSAL $SUC_ID"; ?>
    </h5>
    <div class="row g-3 mb-5">
        
        <div class="col-md-3">
            <div class="card card-fin glass-card p-4 border-bottom border-5 border-primary">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="metric-label">Ingresos por Ventas</div>
                        <div class="metric-value text-primary">$<?php echo number_format($ingresosVentas, 2); ?></div>
                    </div>
                    <i class="fas fa-cash-register fa-2x text-primary opacity-25"></i>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card card-fin glass-card p-4 border-bottom border-5 border-warning">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="metric-label">Costo de Ventas (COGS)</div>
                        <div class="metric-value text-warning">-$<?php echo number_format($costoVentas, 2); ?></div>
                    </div>
                    <i class="fas fa-boxes fa-2x text-warning opacity-25"></i>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card card-fin glass-card p-4 border-bottom border-5 border-info">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="metric-label">Utilidad Bruta</div>
                        <div class="metric-value text-info">$<?php echo number_format($utilidadBruta, 2); ?></div>
                    </div>
                    <i class="fas fa-balance-scale-right fa-2x text-info opacity-25"></i>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card card-fin glass-card p-4 border-bottom border-5 border-success">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="metric-label">Utilidad Neta (Operativa)</div>
                        <div class="metric-value <?php echo $utilidadOperativa>=0?'text-success':'text-danger'; ?>">
                            $<?php echo number_format($utilidadOperativa, 2); ?>
                        </div>
                        <div class="badge bg-success bg-opacity-10 text-success mt-1">Margen: <?php echo number_format($margenNeto, 1); ?>%</div>
                    </div>
                    <i class="fas fa-trophy fa-2x text-success opacity-25"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-md-6">
            <div class="glass-card">
                <div class="card-header bg-transparent fw-bold py-3 border-bottom">
                    <i class="fas fa-file-invoice-dollar text-danger me-2"></i> Desglose de Gastos & Pérdidas
                </div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush">
                        <?php if(empty($gastosDetalle) && $totalMermas == 0): ?>
                            <li class="list-group-item text-center text-muted py-4">No hay gastos registrados en este periodo.</li>
                        <?php else: ?>
                            <?php foreach($gastosDetalle as $g): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-4 py-3">
                                <span><i class="fas fa-tag me-2 text-muted"></i> <?php echo htmlspecialchars($g['categoria']); ?></span>
                                <span class="fw-bold text-danger">-$<?php echo number_format($g['total'], 2); ?></span>
                            </li>
                            <?php endforeach; ?>
                            
                            <?php if($totalMermas > 0): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-4 py-3 bg-light">
                                <span><i class="fas fa-trash-alt me-2 text-secondary"></i> Mermas de Inventario</span>
                                <span class="fw-bold text-danger">-$<?php echo number_format($totalMermas, 2); ?></span>
                            </li>
                            <?php endif; ?>
                        <?php endif; ?>
                    </ul>
                </div>
                <div class="card-footer bg-transparent text-end fw-bold text-danger">
                    Total Gastos: -$<?php echo number_format($totalGastos + $totalMermas, 2); ?>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="glass-card">
                <div class="card-header bg-transparent fw-bold py-3 border-bottom">
                    <i class="fas fa-exchange-alt text-dark me-2"></i> Flujo de Efectivo (Cash Flow)
                </div>
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-success"><i class="fas fa-arrow-circle-up me-2"></i> Entradas (Ventas)</span>
                        <span class="fw-bold text-success fs-5">+$<?php echo number_format($ingresosVentas, 2); ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-danger"><i class="fas fa-arrow-circle-down me-2"></i> Salidas (Compras Stock)</span>
                        <span class="fw-bold text-danger fs-5">-$<?php echo number_format($salidaCompras, 2); ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-danger"><i class="fas fa-arrow-circle-down me-2"></i> Salidas (Gastos Op.)</span>
                        <span class="fw-bold text-danger fs-5">-$<?php echo number_format($totalGastos, 2); ?></span>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between align-items-center mt-4">
                        <span class="fw-bold text-dark">FLUJO NETO DEL PERIODO</span>
                        <span class="fw-bold fs-3 <?php echo $flujoNeto>=0?'text-success':'text-danger'; ?>">
                            <?php echo ($flujoNeto > 0 ? '+' : '') . "$" . number_format($flujoNeto, 2); ?>
                        </span>
                    </div>
                    <div class="text-end small text-muted mt-2">
                        * Indica la liquidez generada o consumida.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <h5 class="text-secondary fw-bold mb-3 header-title">SITUACIÓN FINANCIERA (SNAPSHOT ACTUAL)</h5>
    <div class="row">
        <div class="col-md-6">
            <div class="card bg-primary text-white mb-3 border-0 shadow">
                <div class="card-body text-center p-4">
                    <h5 class="card-title text-uppercase opacity-75 small fw-bold">Activo Corriente (Inventario)</h5>
                    <p class="display-5 fw-bold mb-0">$<?php echo number_format($valorInventario, 2); ?></p>
                    <small class="opacity-75">
                        <?php if ($isGlobal): ?>
                            Valor total del inventario de todas las sucursales
                        <?php else: ?>
                            Valor del stock en Almacén <?php echo $ALM_ID; ?>
                        <?php endif; ?>
                    </small>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card bg-dark text-white mb-3 border-0 shadow">
                <div class="card-body text-center p-4">
                    <h5 class="card-title text-uppercase opacity-75 small fw-bold">Salud Financiera</h5>
                    <p class="display-5 fw-bold mb-0">
                        <?php if($flujoNeto > 0): ?>
                            <i class="fas fa-check-circle text-success"></i> SOLVENTE
                        <?php else: ?>
                            <i class="fas fa-exclamation-circle text-danger"></i> DÉFICIT
                        <?php endif; ?>
                    </p>
                    <small class="opacity-75">Basado en el flujo de caja del periodo seleccionado</small>
                </div>
            </div>
        </div>
    </div>

</div>

<?php include_once 'menu_master.php'; ?>
</body>
</html>
