<?php
// ARCHIVO: /var/www/palweb/api/profit_analysis.php
// VERSIÓN FINAL: FILTRADO POR SUCURSAL & BI COMPLETO

ini_set('display_errors', 0);
require_once 'db.php';


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
$start = $_GET['start'] ?? date('Y-m-01'); // Inicio de mes
$end   = $_GET['end']   ?? date('Y-m-t');  // Fin de mes

try {
    // =========================================================
    // 2. CÁLCULOS DEL ESTADO DE RESULTADOS (P&L)
    // =========================================================

    // A. VENTAS NETAS (Filtradas por SUCURSAL o GLOBAL)
    // CAMBIO: Filtro dinámico según modo de vista
    $stmtVentas = $pdo->prepare("SELECT SUM(total) FROM ventas_cabecera WHERE DATE(fecha) BETWEEN ? AND ? $sqlFilterSucursal");
    if ($isGlobal) {
        $stmtVentas->execute([$start, $end]);
    } else {
        $stmtVentas->execute([$start, $end]);
    }
    $ingresosVentas = floatval($stmtVentas->fetchColumn() ?: 0);

    // B. COSTO DE VENTA - COGS (Filtrado DINÁMICO)
    $sqlCogs = "SELECT SUM(d.cantidad * p.costo) 
                FROM ventas_detalle d 
                JOIN productos p ON d.id_producto = p.codigo 
                JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id 
                WHERE DATE(v.fecha) BETWEEN ? AND ? $sqlFilterSucursal";
    $stmtCogs = $pdo->prepare($sqlCogs);
    $stmtCogs->execute([$start, $end]);
    $costoVentas = floatval($stmtCogs->fetchColumn() ?: 0);

    // C. GASTOS OPERATIVOS
    // Nota: Se asume que los gastos registrados aquí corresponden a la operación local o se muestran globales.
    $stmtGastos = $pdo->prepare("SELECT categoria, SUM(monto) as total FROM gastos_historial WHERE fecha BETWEEN ? AND ? GROUP BY categoria");
    $stmtGastos->execute([$start, $end]);
    $gastosDetalle = $stmtGastos->fetchAll(PDO::FETCH_ASSOC);
    $totalGastos = array_sum(array_column($gastosDetalle, 'total'));

    // D. PÉRDIDAS POR MERMAS
    // Mermas globales en fecha (si se requiere por sucursal, la tabla mermas debería tener id_sucursal)
    $stmtMermas = $pdo->prepare("SELECT SUM(total_costo_perdida) FROM mermas_cabecera WHERE DATE(fecha) BETWEEN ? AND ?");
    $stmtMermas->execute([$start, $end]);
    $totalMermas = floatval($stmtMermas->fetchColumn() ?: 0);

    // RESULTADOS
    $utilidadBruta = $ingresosVentas - $costoVentas;
    $utilidadOperativa = $utilidadBruta - $totalGastos - $totalMermas;
    $margenNeto = ($ingresosVentas > 0) ? ($utilidadOperativa / $ingresosVentas) * 100 : 0;

    // =========================================================
    // 3. BALANCE GENERAL (SITUACIÓN ACTUAL SNAPSHOT)
    // =========================================================

    // ACTIVO: Inventario Valorado (Dinámico según vista)
    if ($isGlobal) {
        // Vista global: todo el inventario de la empresa
        $stmtInv = $pdo->prepare("SELECT SUM(s.cantidad * p.costo) 
                                  FROM stock_almacen s 
                                  JOIN productos p ON s.id_producto = p.codigo 
                                  WHERE p.id_empresa = ?");
        $stmtInv->execute([$EMP_ID]);
    } else {
        // Vista local: solo el almacén de esta sucursal
        $stmtInv = $pdo->prepare("SELECT SUM(s.cantidad * p.costo) 
                                  FROM stock_almacen s 
                                  JOIN productos p ON s.id_producto = p.codigo 
                                  WHERE s.id_almacen = ? AND p.id_empresa = ?");
        $stmtInv->execute([$ALM_ID, $EMP_ID]);
    }
    $valorInventario = floatval($stmtInv->fetchColumn() ?: 0);
    
    // ACTIVO: Caja Estimada (Placeholder)
    $cajaEstimada = 5000; 

    // =========================================================
    // 4. FLUJO DE EFECTIVO (ENTRADAS VS SALIDAS REALES)
    // =========================================================
    
    // Salidas por Compras de Mercancía
    $stmtCompras = $pdo->prepare("SELECT SUM(total) FROM compras_cabecera WHERE DATE(fecha) BETWEEN ? AND ?");
    $stmtCompras->execute([$start, $end]);
    $salidaCompras = floatval($stmtCompras->fetchColumn() ?: 0);
    
    // Flujo Neto
    $flujoNeto = $ingresosVentas - ($salidaCompras + $totalGastos);

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
    <style>
        body { background-color: #f0f2f5; font-family: 'Segoe UI', sans-serif; padding-bottom: 80px; }
        .card-fin { border:none; border-radius:15px; box-shadow:0 5px 15px rgba(0,0,0,0.05); transition:transform 0.2s; height: 100%; }
        .card-fin:hover { transform: translateY(-5px); }
        .metric-value { font-size: 1.8rem; font-weight: 800; }
        .metric-label { text-transform: uppercase; letter-spacing: 1px; font-size: 0.75rem; color: #6c757d; font-weight: bold; }
        .header-title { border-left: 5px solid #0d6efd; padding-left: 15px; }
    </style>
</head>
<body class="p-4">
<div class="container">
    
    <div class="d-flex justify-content-between align-items-center mb-5">
        <div>
            <h3 class="fw-bold text-primary mb-0"><i class="fas fa-chart-pie me-2"></i> Inteligencia Financiera</h3>
            <small class="text-muted">
                <span class="badge bg-dark"><?php echo $tituloVista; ?></span>
                <span class="mx-2">|</span>
                <?php if (!$isGlobal): ?>
                    <i class="fas fa-building text-success"></i> Sucursal: <strong><?php echo $SUC_ID; ?></strong> 
                    <span class="mx-2">|</span> 
                    <i class="fas fa-warehouse text-warning"></i> Almacén: <strong><?php echo $ALM_ID; ?></strong>
                <?php else: ?>
                    <i class="fas fa-globe text-primary"></i> <strong>Todas las Sucursales</strong>
                <?php endif; ?>
            </small>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <a href="<?php echo $btnLink; ?>" class="btn <?php echo $btnClass; ?> fw-bold shadow-sm">
                <?php echo $btnText; ?>
            </a>
            <form class="d-flex gap-2 bg-white p-2 rounded shadow-sm m-0">
                <input type="hidden" name="view" value="<?php echo $viewMode; ?>">
                <input type="date" name="start" class="form-control" value="<?php echo $start; ?>">
                <input type="date" name="end" class="form-control" value="<?php echo $end; ?>">
                <button class="btn btn-primary fw-bold"><i class="fas fa-filter me-1"></i> Filtrar</button>
            </form>
        </div>
    </div>

    <h5 class="text-secondary fw-bold mb-3 header-title">
        ESTADO DE RESULTADOS (P&L) - <?php echo $isGlobal ? 'TODAS LAS SUCURSALES' : "SUCURSAL $SUC_ID"; ?>
    </h5>
    <div class="row g-3 mb-5">
        
        <div class="col-md-3">
            <div class="card card-fin bg-white p-4 border-bottom border-5 border-primary">
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
            <div class="card card-fin bg-white p-4 border-bottom border-5 border-warning">
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
            <div class="card card-fin bg-white p-4 border-bottom border-5 border-info">
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
            <div class="card card-fin bg-white p-4 border-bottom border-5 border-success">
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
            <div class="card card-fin">
                <div class="card-header bg-white fw-bold py-3 border-bottom">
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
                <div class="card-footer bg-white text-end fw-bold text-danger">
                    Total Gastos: -$<?php echo number_format($totalGastos + $totalMermas, 2); ?>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card card-fin">
                <div class="card-header bg-white fw-bold py-3 border-bottom">
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

