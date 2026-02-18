<?php
// ARCHIVO: sales_history.php
// VERSIÓN: 3.5 (FEAT: BOTÓN REPORTE DE SESIÓN HABILITADO)
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db.php';

// ---------------------------------------------------------
// 🔒 SEGURIDAD Y CONFIGURACIÓN
// ---------------------------------------------------------
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// Cargar Configuración
require_once 'config_loader.php';
$ALM_ID = intval($config['id_almacen']);

// ---------------------------------------------------------
// 🧠 LÓGICA DE CONTABILIZACIÓN (POST)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'contabilizar_sesion') {
    try {
        $sessionId = intval($_POST['session_id']);
        $fechaContablePost = $_POST['fecha_contable']; 
        $cajero = $_POST['cajero'];
        
        // 1. Verificar duplicados
        $refCheck = "Cierre de Caja #$sessionId";
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM contabilidad_diario WHERE detalle LIKE ?");
        $stmtCheck->execute(["%$refCheck%"]);
        
        if ($stmtCheck->fetchColumn() > 0) {
            header("Location: sales_history.php?msg=duplicate");
            exit;
        }

        // 2. Calcular Totales
        $stmtTotales = $pdo->prepare("
            SELECT metodo_pago, SUM(total) as total 
            FROM ventas_cabecera 
            WHERE id_caja = ? 
            GROUP BY metodo_pago
        ");
        $stmtTotales->execute([$sessionId]);
        $pagos = $stmtTotales->fetchAll(PDO::FETCH_KEY_PAIR);

        // 3. Preparar Asiento
        $asientoID = date('Ymd-His') . '-CC' . $sessionId;
        $pdo->beginTransaction();

        $totalVenta = 0;
        $insertSQL = "INSERT INTO contabilidad_diario (asiento_id, fecha, cuenta, detalle, debe, haber, creado_por) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmtIns = $pdo->prepare($insertSQL);

        // A. DEBE (Entrada de Dinero)
        foreach ($pagos as $metodo => $monto) {
            if ($monto > 0) {
                $totalVenta += $monto;
                // Mapeo de cuentas según método
                $cuentaDebe = (stripos($metodo, 'Transferencia') !== false) ? '104.01' : '101.01'; 
                $descDebe = "Ingreso $metodo - $refCheck";
                $stmtIns->execute([$asientoID, $fechaContablePost, $cuentaDebe, $descDebe, $monto, 0, $_SESSION['admin_name']]);
            }
        }

        // B. HABER (Ingreso Venta)
        if ($totalVenta > 0) {
            $cuentaHaber = '401.01'; // Ventas
            $descHaber = "Ventas del Día - $cajero ($refCheck)";
            $stmtIns->execute([$asientoID, $fechaContablePost, $cuentaHaber, $descHaber, 0, $totalVenta, $_SESSION['admin_name']]);
        }

        $pdo->commit();
        header("Location: sales_history.php?msg=contabilizado");
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        die("Error al contabilizar: " . $e->getMessage());
    }
}

// ---------------------------------------------------------
// 📊 PREPARACIÓN DE DATOS (LECTURA)
// ---------------------------------------------------------

// Filtros de Fecha
$start = $_GET['start'] ?? date('Y-m-d', strtotime('-7 days'));
$end = $_GET['end'] ?? date('Y-m-d');

// CALCULAR SIGUIENTE NUMERO DE FACTURA
$stmtNextInv = $pdo->query("SELECT id FROM facturas ORDER BY id DESC LIMIT 1");
$lastInvId = $stmtNextInv->fetchColumn();
$nextInvoiceNum = date('Ymd') . str_pad(($lastInvId ? $lastInvId + 1 : 1), 3, '0', STR_PAD_LEFT);

try {
    // --- QUERY MAESTRA DE FECHA CONTABLE ---
    $sqlDateLogic = "IF(v.id_caja > 0, s.fecha_contable, DATE(v.fecha))";

    // 1. RESUMEN FINANCIERO
    $sqlFinanzas = "SELECT 
                        SUM(d.cantidad * d.precio) as venta_total,
                        SUM(d.cantidad * p.costo) as costo_total,
                        SUM(d.cantidad) as total_items
                    FROM ventas_detalle d
                    JOIN productos p ON d.id_producto = p.codigo
                    JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id
                    LEFT JOIN caja_sesiones s ON v.id_caja = s.id
                    WHERE $sqlDateLogic BETWEEN ? AND ?";
    
    $stmtF = $pdo->prepare($sqlFinanzas);
    $stmtF->execute([$start, $end]);
    $finanzas = $stmtF->fetch(PDO::FETCH_ASSOC);

    $ventaTotal = floatval($finanzas['venta_total'] ?? 0);
    $costoTotal = floatval($finanzas['costo_total'] ?? 0);
    $totalItems = floatval($finanzas['total_items'] ?? 0);
    $ganancia   = $ventaTotal - $costoTotal;
    $margen     = ($ventaTotal > 0) ? ($ganancia / $ventaTotal) * 100 : 0;

    // 2. MÉTODOS DE PAGO
    $sqlPagos = "SELECT v.metodo_pago, SUM(v.total) as total 
                 FROM ventas_cabecera v
                 LEFT JOIN caja_sesiones s ON v.id_caja = s.id
                 WHERE $sqlDateLogic BETWEEN ? AND ? 
                 GROUP BY v.metodo_pago";
    $stmtP = $pdo->prepare($sqlPagos);
    $stmtP->execute([$start, $end]);
    $pagos = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    // 3. SESIONES DE CAJA
    $sqlSesiones = "SELECT s.*, 
                    (SELECT COUNT(*) FROM contabilidad_diario cd WHERE cd.detalle LIKE CONCAT('%Cierre de Caja #', s.id, '%')) as es_contabilizado
                    FROM caja_sesiones s
                    WHERE s.fecha_contable BETWEEN ? AND ? 
                    ORDER BY s.fecha_contable DESC, s.id DESC";
    $stmt = $pdo->prepare($sqlSesiones);
    $stmt->execute([$start, $end]);
    $sesiones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. TICKETS
    $sqlTickets = "SELECT v.*, $sqlDateLogic as fecha_contable_calc 
                   FROM ventas_cabecera v 
                   LEFT JOIN caja_sesiones s ON v.id_caja = s.id
                   WHERE $sqlDateLogic BETWEEN ? AND ? 
                   ORDER BY v.id DESC";
    $stmtT = $pdo->prepare($sqlTickets);
    $stmtT->execute([$start, $end]);
    $allTickets = $stmtT->fetchAll(PDO::FETCH_ASSOC);
    
    // --- PROCESAMIENTO DE KPIS ---
    $cntReservas = 0; $cntDelivery = 0; $cntReembolsos = 0;
    $sumaTransferencia = 0; $sumaVentaPositiva = 0; $maxVenta = 0;
    $deliveryStats = [];
    $grouped = [];
    $orphans = []; 
    
    foreach ($allTickets as $t) {
        $sid = intval($t['id_caja']);
        if ($sid > 0) $grouped[$sid][] = $t;
        else $orphans[] = $t;

        if ($t['total'] < 0) {
            $cntReembolsos++;
        } else {
            $sumaVentaPositiva += $t['total'];
            if($t['total'] > $maxVenta) $maxVenta = $t['total'];

            if ($t['tipo_servicio'] === 'reserva') $cntReservas++;
            if ($t['tipo_servicio'] === 'mensajeria') {
                $cntDelivery++;
                $driver = ($t['mensajero']) ? $t['mensajero'] : 'General';
                if (!isset($deliveryStats[$driver])) $deliveryStats[$driver] = 0;
                $deliveryStats[$driver] += $t['total'];
            }
            if (stripos($t['metodo_pago'], 'transferencia') !== false) $sumaTransferencia += $t['total'];
        }
    }
    $pctTransferencia = ($sumaVentaPositiva > 0) ? ($sumaTransferencia / $sumaVentaPositiva) * 100 : 0;
    $ticketPromedio = (count($allTickets) > 0) ? $ventaTotal / count($allTickets) : 0;

    // KPI WEB
    $kpiWeb = $pdo->query("SELECT COUNT(*) as cant, SUM(total) as total FROM pedidos_cabecera WHERE estado = 'completado' AND DATE(fecha) BETWEEN '$start' AND '$end'")->fetch(PDO::FETCH_ASSOC);
    
    // KPI RESERVAS
    $stmtRes = $pdo->prepare("
        SELECT SUM(d.cantidad * (d.precio - p.costo)) as ganancia, SUM(d.cantidad * d.precio) as venta 
        FROM ventas_detalle d 
        JOIN productos p ON d.id_producto = p.codigo 
        JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id 
        LEFT JOIN caja_sesiones s ON v.id_caja = s.id
        WHERE v.tipo_servicio = 'reserva' AND $sqlDateLogic BETWEEN ? AND ?
    ");
    $stmtRes->execute([$start, $end]);
    $kpiReserva = $stmtRes->fetch(PDO::FETCH_ASSOC);

    // PROMEDIOS DIARIOS
    $stmtActive = $pdo->prepare("SELECT $sqlDateLogic as dia, SUM(v.total) as venta_dia FROM ventas_cabecera v LEFT JOIN caja_sesiones s ON v.id_caja = s.id WHERE $sqlDateLogic BETWEEN ? AND ? GROUP BY dia HAVING venta_dia >= 1");
    $stmtActive->execute([$start, $end]);
    $activeDays = $stmtActive->fetchAll(PDO::FETCH_ASSOC);
    
    $numActiveDays = count($activeDays);
    $promedioVentaDiaria = ($numActiveDays > 0) ? array_sum(array_column($activeDays, 'venta_dia')) / $numActiveDays : 0;
    $promedioGananciaDiaria = ($numActiveDays > 0) ? $ganancia / $numActiveDays : 0;

    // ---------------------------------------------------------
    // 5. DATOS PARA GRÁFICO (FECHA CONTABLE)
    // ---------------------------------------------------------
    $chartLabels = []; $chartVentas = []; $chartGanancias = []; $chartInventario = [];
    $dataMap = [];

    $currentDate = strtotime($start);
    $lastDate = strtotime($end);
    while ($currentDate <= $lastDate) {
        $d = date('Y-m-d', $currentDate);
        $chartLabels[] = date('d/m', $currentDate);
        $dataMap[$d] = ['venta' => 0, 'ganancia' => 0, 'inventario' => 0];
        $currentDate = strtotime('+1 day', $currentDate);
    }

    $stmtChart = $pdo->prepare("
        SELECT 
            $sqlDateLogic as dia, 
            SUM(d.cantidad * d.precio) as venta, 
            SUM(d.cantidad * (d.precio - p.costo)) as ganancia 
        FROM ventas_detalle d 
        JOIN productos p ON d.id_producto = p.codigo 
        JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id 
        LEFT JOIN caja_sesiones s ON v.id_caja = s.id
        WHERE $sqlDateLogic BETWEEN ? AND ? 
        GROUP BY dia
    ");
    $stmtChart->execute([$start, $end]);
    while ($row = $stmtChart->fetch(PDO::FETCH_ASSOC)) {
        if (isset($dataMap[$row['dia']])) {
            $dataMap[$row['dia']]['venta'] = floatval($row['venta']);
            $dataMap[$row['dia']]['ganancia'] = floatval($row['ganancia']);
        }
    }

    // INVENTARIO (Físico - Kardex)
    $sqlInvInicial = "SELECT SUM(k.cantidad * p.costo) FROM kardex k JOIN productos p ON k.id_producto = p.codigo WHERE k.fecha < ? AND k.id_almacen = ?";
    $stmtInvIni = $pdo->prepare($sqlInvInicial);
    $stmtInvIni->execute([$start . ' 00:00:00', $ALM_ID]);
    $valorInv = floatval($stmtInvIni->fetchColumn() ?: 0);

    $sqlInvChg = "SELECT DATE(k.fecha) as dia, SUM(k.cantidad * p.costo) as cambio FROM kardex k JOIN productos p ON k.id_producto = p.codigo WHERE k.fecha BETWEEN ? AND ? AND k.id_almacen = ? GROUP BY DATE(k.fecha)";
    $stmtInvChg = $pdo->prepare($sqlInvChg);
    $stmtInvChg->execute([$start . ' 00:00:00', $end . ' 23:59:59', $ALM_ID]);
    $cambios = $stmtInvChg->fetchAll(PDO::FETCH_KEY_PAIR);

    foreach ($dataMap as $date => &$vals) {
        if (isset($cambios[$date])) $valorInv += floatval($cambios[$date]);
        $vals['inventario'] = $valorInv;
    }

    foreach ($dataMap as $val) {
        $chartVentas[] = $val['venta'];
        $chartGanancias[] = $val['ganancia'];
        $chartInventario[] = $val['inventario'];
    }

} catch (Exception $e) { die("Error DB: " . $e->getMessage()); }
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte Financiero Contable</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
    <style>
        body { background-color: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        .session-card { border-left: 5px solid #0d6efd; margin-bottom: 20px; transition: all 0.2s; }
        .session-card:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .session-closed { border-left-color: #198754; } 
        .session-open { border-left-color: #ffc107; } 
        .ticket-row { font-size: 0.9rem; }
        .card-stat { border: none; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .icon-box { width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: 8px; font-size: 1.2rem; }
    </style>
</head>
<body class="p-4">

<div class="container">
    
    <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-3 rounded shadow-sm">
        <div>
            <h4 class="fw-bold mb-0"><i class="fas fa-chart-line text-primary"></i> Reporte Financiero</h4>
            <p class="text-muted mb-0 small">
                Almacén: <strong><?php echo $ALM_ID; ?></strong> | 
                <span class="text-primary"><i class="fas fa-calendar-alt"></i> Fecha Contable Activa</span>
            </p>
        </div>
        <div class="d-flex gap-2 align-items-end">
            <form class="d-flex gap-2 align-items-end">
                <div class="form-check form-switch me-2 mb-1">
                    <input class="form-check-input" type="checkbox" id="toggleInventory" checked onchange="toggleChartDataset(2)">
                    <label class="form-check-label small fw-bold text-muted" for="toggleInventory">📉 Inventario</label>
                </div>
                <div><label class="small fw-bold">Desde</label><input type="date" name="start" class="form-control form-control-sm" value="<?php echo $start; ?>"></div>
                <div><label class="small fw-bold">Hasta</label><input type="date" name="end" class="form-control form-control-sm" value="<?php echo $end; ?>"></div>
                <button class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button>
            </form>
            <button class="btn btn-dark btn-sm align-self-end" onclick="printRange()"><i class="fas fa-print"></i> PDF</button>
            <button class="btn btn-success btn-sm align-self-end" onclick="openInvoiceModal()"><i class="fas fa-file-invoice-dollar"></i> Facturar</button>
            <a href="pos.php" class="btn btn-outline-secondary btn-sm align-self-end">Volver</a>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="card card-stat h-100 p-3">
                <div class="d-flex justify-content-between align-items-start">
                    <div><small class="text-muted fw-bold">VENTA TOTAL</small><h3 class="fw-bold text-dark mb-0">$<?php echo number_format($ventaTotal, 2); ?></h3></div>
                    <div class="icon-box bg-light text-primary"><i class="fas fa-dollar-sign"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-stat h-100 p-3 border-bottom border-4 border-success">
                <div class="d-flex justify-content-between align-items-start">
                    <div><small class="text-muted fw-bold">GANANCIA BRUTA</small><h3 class="fw-bold text-success mb-0">$<?php echo number_format($ganancia, 2); ?></h3></div>
                    <div class="icon-box bg-light text-success"><i class="fas fa-wallet"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-stat h-100 p-3">
                <div class="d-flex justify-content-between align-items-start">
                    <div><small class="text-muted fw-bold">MARGEN (%)</small><h3 class="fw-bold text-info mb-0"><?php echo number_format($margen, 1); ?>%</h3></div>
                    <div class="icon-box bg-light text-info"><i class="fas fa-percentage"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-stat h-100 p-2">
                <small class="text-muted fw-bold ps-2">MÉTODOS DE PAGO</small>
                <ul class="list-group list-group-flush small mt-1">
                    <?php foreach($pagos as $p): ?>
                    <li class="list-group-item d-flex justify-content-between py-1 px-2 border-0">
                        <span><?php echo $p['metodo_pago']; ?></span><span class="fw-bold">$<?php echo number_format($p['total'], 0); ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>

    <div class="card card-stat mb-4 shadow-sm">
        <div class="card-header bg-white fw-bold text-muted border-0">
            <i class="fas fa-chart-area me-2"></i> Evolución Financiera (Base Contable)
        </div>
        <div class="card-body">
            <div style="height: 350px;"><canvas id="salesChart"></canvas></div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card card-stat h-100 shadow-sm">
                <div class="card-header bg-white fw-bold text-primary"><i class="fas fa-motorcycle me-1"></i> Mensajería</div>
                <div class="card-body p-2">
                    <div class="d-flex justify-content-between border-bottom pb-2 mb-2 px-2">
                        <span class="text-muted">Total Envíos:</span><span class="fw-bold"><?php echo $cntDelivery; ?></span>
                    </div>
                    <ul class="list-group list-group-flush small">
                        <?php if(empty($deliveryStats)): ?><li class="list-group-item text-center text-muted border-0">Sin envíos</li>
                        <?php else: foreach($deliveryStats as $driver => $total): ?>
                            <li class="list-group-item d-flex justify-content-between py-1 border-0"><span><?php echo htmlspecialchars($driver); ?></span><span class="fw-bold text-dark">$<?php echo number_format($total, 2); ?></span></li>
                        <?php endforeach; endif; ?>
                    </ul>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-stat h-100 shadow-sm border-start border-4 border-info">
                <div class="card-body text-center d-flex flex-column justify-content-center">
                    <h6 class="text-muted text-uppercase fw-bold mb-3"><i class="fas fa-globe me-2"></i> Ventas Web</h6>
                    <h2 class="fw-bold text-info mb-0">$<?php echo number_format($kpiWeb['total'] ?? 0, 2); ?></h2>
                    <div class="mt-2 text-muted small"><span class="badge bg-info text-white"><?php echo $kpiWeb['cant'] ?? 0; ?></span> Ordenes completadas</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-stat h-100 shadow-sm border-start border-4 border-warning">
                <div class="card-body">
                    <h6 class="text-muted text-uppercase fw-bold mb-3"><i class="fas fa-calendar-check me-2"></i> Reservas Entregadas</h6>
                    <div class="d-flex justify-content-between align-items-end mb-2"><span>Ingreso Total</span><span class="fs-4 fw-bold text-dark">$<?php echo number_format($kpiReserva['venta'] ?? 0, 2); ?></span></div>
                    <div class="d-flex justify-content-between align-items-end"><span>Ganancia Neta</span><span class="fs-5 fw-bold text-success">+$<?php echo number_format($kpiReserva['ganancia'] ?? 0, 2); ?></span></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card card-stat h-100 p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div><small class="text-muted fw-bold">RESERVAS (Cant)</small><h3 class="fw-bold mb-0" style="color: #6f42c1;"><?php echo $cntReservas; ?></h3></div>
                    <div class="icon-box" style="background-color: #f3f0ff; color: #6f42c1;"><i class="fas fa-calendar-alt"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-stat h-100 p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div><small class="text-muted fw-bold">REEMBOLSOS</small><h3 class="fw-bold text-danger mb-0"><?php echo $cntReembolsos; ?></h3></div>
                    <div class="icon-box bg-danger bg-opacity-10 text-danger"><i class="fas fa-undo"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-stat h-100 p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div><small class="text-muted fw-bold">% TRANSFERENCIA</small><h3 class="fw-bold text-primary mb-0"><?php echo number_format($pctTransferencia, 1); ?>%</h3></div>
                    <div class="icon-box bg-primary bg-opacity-10 text-primary"><i class="fas fa-university"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-stat h-100 p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-muted fw-bold">PROM. DIARIO</small>
                        <h3 class="fw-bold text-dark mb-0">$<?php echo number_format($promedioVentaDiaria, 2); ?></h3>
                        <small class="text-success fw-bold" style="font-size: 0.8rem;">+<?php echo number_format($promedioGananciaDiaria, 2); ?> ganancia</small>
                    </div>
                    <div class="icon-box bg-warning bg-opacity-10 text-dark"><i class="fas fa-calendar-day"></i></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card card-stat h-100 p-3 border-start border-4 border-primary">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-muted fw-bold text-uppercase">Ticket Promedio</small>
                        <h3 class="fw-bold text-dark mb-0">$<?php echo number_format($ticketPromedio, 2); ?></h3>
                        <small class="text-muted">Gasto medio por cliente</small>
                    </div>
                    <div class="icon-box bg-primary bg-opacity-10 text-primary"><i class="fas fa-receipt"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-stat h-100 p-3 border-start border-4 border-success">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-muted fw-bold text-uppercase">Venta Máxima</small>
                        <h3 class="fw-bold text-success mb-0">$<?php echo number_format($maxVenta, 2); ?></h3>
                        <small class="text-muted">Ticket más alto del periodo</small>
                    </div>
                    <div class="icon-box bg-success bg-opacity-10 text-success"><i class="fas fa-trophy"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-stat h-100 p-3 border-start border-4 border-info">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-muted fw-bold text-uppercase">Rotación (Items)</small>
                        <h3 class="fw-bold text-info mb-0"><?php echo number_format($totalItems, 0); ?></h3>
                        <small class="text-muted">Productos totales vendidos</small>
                    </div>
                    <div class="icon-box bg-info bg-opacity-10 text-info"><i class="fas fa-boxes"></i></div>
                </div>
            </div>
        </div>
    </div>

    <?php if(isset($_GET['msg']) && $_GET['msg']=='contabilizado'): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i> <strong>¡Éxito!</strong> La sesión ha sido enviada a Contabilidad (Fecha correcta).
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (empty($sesiones) && empty($orphans)): ?><div class="alert alert-info text-center">No hay registros en estas fechas contables.</div><?php endif; ?>

    <?php if (!empty($orphans)): ?>
    <div class="card session-card border-left-warning shadow-sm mb-4">
        <div class="card-header bg-warning bg-opacity-10 fw-bold text-dark d-flex justify-content-between">
            <span><i class="fas fa-exclamation-triangle"></i> Ventas Sin Sesión (Huérfanas)</span>
            <span class="badge bg-warning text-dark"><?php echo count($orphans); ?> tickets</span>
        </div>
        <div class="card-body p-0"><?php renderTicketTable($orphans); ?></div>
    </div>
    <?php endif; ?>

    <?php foreach ($sesiones as $s): 
        $sid = $s['id']; 
        $sessionTickets = isset($grouped[$sid]) ? $grouped[$sid] : [];
        $totalSession = array_sum(array_column($sessionTickets, 'total'));
        $isOpen = ($s['estado'] == 'ABIERTA');
        $isContabilizado = ($s['es_contabilizado'] > 0);
        $fechaContableStr = date('d/m/Y', strtotime($s['fecha_contable']));
        $fechaContableISO = $s['fecha_contable'];
        $horaApertura = date('H:i', strtotime($s['fecha_apertura']));
    ?>
    <div class="card session-card <?php echo $isOpen?'session-open':'session-closed'; ?> shadow-sm">
        <div class="card-header bg-white">
            <div class="row align-items-center">
                <div class="col-md-4">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge <?php echo $isOpen?'bg-warning text-dark':'bg-success'; ?>">
                            <?php echo $isOpen ? 'ABIERTA' : 'CERRADA'; ?>
                        </span>
                        <h5 class="mb-0 fw-bold text-dark"><?php echo htmlspecialchars($s['nombre_cajero']); ?></h5>
                    </div>
                    <div class="text-muted small mt-1">
                        Caja #<?php echo $sid; ?> | <span class="text-primary fw-bold">Fecha Contable: <?php echo $fechaContableStr; ?></span> (Apertura: <?php echo $horaApertura; ?>)
                    </div>
                </div>
                
                <div class="col-md-4 text-center">
                    <?php if($isContabilizado): ?>
                        <span class="badge bg-info text-dark"><i class="fas fa-check-double"></i> Contabilizado</span>
                    <?php elseif(!$isOpen): ?>
                        <form method="POST" onsubmit="return confirm('¿Generar asiento contable para esta sesión?\nFecha: <?php echo $fechaContableStr; ?>');">
                            <input type="hidden" name="action" value="contabilizar_sesion">
                            <input type="hidden" name="session_id" value="<?php echo $sid; ?>">
                            <input type="hidden" name="cajero" value="<?php echo htmlspecialchars($s['nombre_cajero']); ?>">
                            <input type="hidden" name="fecha_contable" value="<?php echo $fechaContableISO; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-primary shadow-sm">
                                <i class="fas fa-book"></i> Contabilizar Ahora
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="col-md-4 text-end">
                    <div class="fs-4 fw-bold text-dark">$<?php echo number_format($totalSession, 2); ?></div>
                    <div class="small text-muted"><?php echo count($sessionTickets); ?> ventas registradas</div>
                    <button class="btn btn-sm btn-outline-primary mt-1" onclick="printSession(<?php echo $sid; ?>)"><i class="fas fa-file-invoice"></i> Reporte</button>
                </div>
            </div>
        </div>
        
        <div class="collapse show" id="collapse<?php echo $sid; ?>">
            <div class="card-body p-0">
                <?php if(empty($sessionTickets)): ?>
                    <div class="p-3 text-muted text-center small">Sin ventas registradas en esta sesión.</div>
                <?php else: renderTicketTable($sessionTickets); endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

</div>

<div class="modal fade" id="invoiceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-file-invoice"></i> Generar Factura</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="invoice_generator.php" method="POST" target="_blank">
                <div class="modal-body">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">ID Ticket (Origen)</label>
                            <input type="number" name="ticket_id" id="invTicketId" class="form-control" placeholder="Opcional">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Fecha Emisión</label>
                            <input type="date" name="fecha" id="invDate" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label small fw-bold">Número Factura</label>
                            <input type="text" name="numero_factura" class="form-control bg-light" value="<?php echo $nextInvoiceNum; ?>">
                        </div>
                        <div class="col-12 mt-3 border-top pt-2"><label class="fw-bold text-primary">Cliente</label></div>
                        <div class="col-12">
                            <input type="text" name="cliente_nombre" id="invClient" class="form-control mb-1" placeholder="Nombre completo" required>
                            <input type="text" name="cliente_direccion" class="form-control mb-1" placeholder="Dirección">
                            <input type="text" name="cliente_telefono" class="form-control" placeholder="Teléfono">
                        </div>
                        <div class="col-12 mt-3 border-top pt-2"><label class="fw-bold text-primary">Transporte</label></div>
                        <div class="col-md-6"><input type="text" name="mensajero" id="invMsj" class="form-control" placeholder="Mensajero"></div>
                        <div class="col-md-6">
                            <select name="vehiculo" class="form-select">
                                <option value="">- Vehículo -</option>
                                <option value="Moto Eléctrica">Moto Eléctrica</option>
                                <option value="Moto Gasolina">Moto Gasolina</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Generar PDF</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php function renderTicketTable($tickets) { ?>
<div class="table-responsive">
    <table class="table table-hover table-sm mb-0 ticket-row">
        <thead class="table-light">
            <tr>
                <th class="ps-3">ID</th>
                <th>Hora Real</th>
                <th>Cliente</th>
                <th>Tipo</th>
                <th>Pago</th>
                <th class="text-end pe-3">Total</th>
                <th class="text-end">Acciones</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach($tickets as $t): 
            $safeClient = htmlspecialchars($t['cliente_nombre'], ENT_QUOTES);
            $safeMsj = isset($t['mensajero']) ? htmlspecialchars($t['mensajero'], ENT_QUOTES) : '';
            $dateInv = isset($t['fecha_contable_calc']) ? $t['fecha_contable_calc'] : date('Y-m-d', strtotime($t['fecha']));
        ?>
            <tr>
                <td class="ps-3 fw-bold">#<?php echo $t['id']; ?></td>
                <td><?php echo date('H:i', strtotime($t['fecha'])); ?></td>
                <td><?php echo htmlspecialchars($t['cliente_nombre']); ?></td>
                <td><?php echo strtoupper($t['tipo_servicio']); ?></td>
                <td><?php echo $t['metodo_pago']; ?></td>
                <td class="text-end pe-3 fw-bold text-dark">$<?php echo number_format($t['total'], 2); ?></td>
                <td class="text-end">
                    <button class="btn btn-sm btn-link text-success p-0 me-2" title="Facturar" 
                            onclick="openInvoiceModal(<?php echo $t['id']; ?>, '<?php echo $safeClient; ?>', '<?php echo $dateInv; ?>', '<?php echo $safeMsj; ?>')">
                        <i class="fas fa-file-invoice"></i>
                    </button>
                    <button class="btn btn-sm btn-link text-muted p-0" onclick="viewTicket(<?php echo $t['id']; ?>)"><i class="fas fa-eye"></i></button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php } ?>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
    Chart.register(ChartDataLabels);
    let salesChart; 

    function viewTicket(id) { window.open(`ticket_view.php?id=${id}`, 'Ticket', 'width=380,height=600'); }
    
    function printRange() { 
        const start = document.querySelector('input[name="start"]').value;
        const end = document.querySelector('input[name="end"]').value;
        window.open(`report_print.php?mode=range&start=${start}&end=${end}`, 'Reporte Global', 'width=900,height=800,scrollbars=yes'); 
    }
    
    function printSession(id) { 
        window.open(`report_print.php?mode=session&id=${id}`, 'Reporte', 'width=900,height=800,scrollbars=yes'); 
    }

    function openInvoiceModal(ticketId = '', clientName = '', date = '', messenger = '') {
        const modalEl = document.getElementById('invoiceModal');
        const modal = new bootstrap.Modal(modalEl);
        document.getElementById('invTicketId').value = ticketId;
        if(clientName) document.getElementById('invClient').value = clientName;
        if(date) document.getElementById('invDate').value = date;
        if(messenger) document.getElementById('invMsj').value = messenger;
        modal.show();
    }

    function toggleChartDataset(datasetIndex) {
        const isChecked = document.getElementById('toggleInventory').checked;
        if (salesChart) {
            salesChart.data.datasets[datasetIndex].hidden = !isChecked;
            salesChart.update();
        }
    }

    // --- GRÁFICO ---
    const ctx = document.getElementById('salesChart').getContext('2d');
    const chartLabels = <?php echo json_encode($chartLabels); ?>;
    const chartVentas = <?php echo json_encode($chartVentas); ?>;
    const chartGanancias = <?php echo json_encode($chartGanancias); ?>;
    const chartInventario = <?php echo json_encode($chartInventario); ?>;

    salesChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: chartLabels,
            datasets: [
                {
                    label: 'Ventas (Fecha Contable)',
                    data: chartVentas,
                    borderColor: '#0d6efd',
                    backgroundColor: 'rgba(13, 110, 253, 0.1)',
                    borderWidth: 3,
                    tension: 0.3,
                    fill: true,
                    datalabels: { display: false }
                },
                {
                    label: 'Ganancia Bruta',
                    data: chartGanancias,
                    borderColor: '#198754',
                    backgroundColor: 'rgba(25, 135, 84, 0.1)',
                    borderWidth: 3,
                    tension: 0.3,
                    fill: true,
                    datalabels: { display: false }
                },
                {
                    label: 'Valor Inventario ($)',
                    data: chartInventario,
                    borderColor: '#6f42c1',
                    backgroundColor: 'rgba(111, 66, 193, 0.05)',
                    borderWidth: 2,
                    borderDash: [5, 5],
                    tension: 0.1,
                    fill: false,
                    pointRadius: 4,
                    hidden: false, 
                    datalabels: {
                        display: true,
                        align: 'top',
                        color: '#6f42c1',
                        font: { weight: 'bold', size: 10 },
                        formatter: function(value) { return '$' + Math.round(value/1000) + 'k'; }
                    }
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'top' } },
            interaction: { mode: 'nearest', axis: 'x', intersect: false },
            scales: { y: { beginAtZero: true }, x: { grid: { display: false } } }
        }
    });
</script>

<?php include_once 'menu_master.php'; ?>
</body>
</html>

