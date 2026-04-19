<?php
// ARCHIVO: /var/www/palweb/api/reportes_caja.php
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

// 1. CARGAR CONFIG
require_once 'config_loader.php';

$sucursalID = intval($config['id_sucursal']);

// 2. DETERMINAR SESIÓN
$idSesion = isset($_GET['id']) ? intval($_GET['id']) : 0;
$sesion = null;

if ($idSesion > 0) {
    $stmt = $pdo->prepare("SELECT * FROM caja_sesiones WHERE id = ?");
    $stmt->execute([$idSesion]);
    $sesion = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("SELECT * FROM caja_sesiones WHERE estado = 'ABIERTA' AND id_sucursal = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$sucursalID]);
    $sesion = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$sesion) {
    $stmt = $pdo->prepare("SELECT * FROM caja_sesiones WHERE id_sucursal = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$sucursalID]);
    $sesion = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$sesion) die("<h1>No hay registros de caja disponibles.</h1>");
$idSesion = $sesion['id'];

$canalMap = [
    'Web'        => ['#0ea5e9', '🌐', 'Web'],
    'POS'        => ['#6366f1', '🖥️', 'POS'],
    'WhatsApp'   => ['#22c55e', '💬', 'WhatsApp'],
    'Teléfono'   => ['#f59e0b', '📞', 'Tel.'],
    'Kiosko'     => ['#8b5cf6', '📱', 'Kiosko'],
    'Presencial' => ['#475569', '🙋', 'Presencial'],
    'ICS'        => ['#94a3b8', '📥', 'ICS'],
    'Otro'       => ['#94a3b8', '❓', 'Otro'],
];
function getCanalBadgeRC($canal, $map) {
    [$bg, $emoji, $label] = $map[$canal] ?? $map['Otro'];
    return "<span style=\"display:inline-flex;align-items:center;gap:4px;"
         . "background-color:{$bg}!important;color:white!important;"
         . "padding:2px 9px;border-radius:20px;font-size:.65rem;font-weight:700;"
         . "white-space:nowrap;print-color-adjust:exact;-webkit-print-color-adjust:exact;\">"
         . "{$emoji} {$label}</span>";
}

// 3. OBTENER DATOS
$sqlTickets = "SELECT * FROM ventas_cabecera WHERE id_caja = ? AND id_sucursal = ? ORDER BY id DESC";
$stmtT = $pdo->prepare($sqlTickets);
$stmtT->execute([$idSesion, $sucursalID]);
$tickets = $stmtT->fetchAll(PDO::FETCH_ASSOC);

// Detalles
// CORRECCIÓN: JOIN por p.codigo (El ID numérico ya no existe)
$sqlDetalles = "SELECT d.*, p.nombre, p.codigo, p.costo, p.categoria 
                FROM ventas_detalle d
                JOIN productos p ON d.id_producto = p.codigo 
                JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id
                WHERE v.id_caja = ? AND v.id_sucursal = ?";
$stmtD = $pdo->prepare($sqlDetalles);
$stmtD->execute([$idSesion, $sucursalID]);
$allDetalles = $stmtD->fetchAll(PDO::FETCH_ASSOC);

$detallesPorTicket = [];
$totalCosto = 0;
$totalVentaBruta = 0;

foreach ($allDetalles as $d) {
    $detallesPorTicket[$d['id_venta_cabecera']][] = $d;
    $totalCosto += ($d['cantidad'] * $d['costo']);
    $totalVentaBruta += ($d['cantidad'] * $d['precio']);
}

// 4. KPIs
$totalVentaNeta = 0;        // Suma de todos los pagos registrados (bruto)
$ventasReales = 0;          // Suma de tickets positivos (neto después de devoluciones)
$metodosPago = [];
$conteoTickets = 0;
$cantDevoluciones = 0;
$valorDevoluciones = 0;

$horaInicio = strtotime($sesion['fecha_apertura']);
$horaFin = $sesion['fecha_cierre'] ? strtotime($sesion['fecha_cierre']) : time();
$horasOperacion = max(1, round(($horaFin - $horaInicio) / 3600, 2));

// Calcular venta neta desde ventas_pagos (todos los pagos registrados)
$sqlTotalPagos = "SELECT SUM(monto) as total FROM ventas_pagos WHERE id_venta_cabecera IN (SELECT id FROM ventas_cabecera WHERE id_caja = ?)";
$stmtTP = $pdo->prepare($sqlTotalPagos);
$stmtTP->execute([$idSesion]);
$totalVentaNeta = floatval($stmtTP->fetchColumn() ?? 0);

foreach ($tickets as $t) {
    // Ventas reales = solo tickets positivos
    if ($t['total'] > 0) {
        $ventasReales += $t['total'];
        $conteoTickets++;
    }

    if ($t['total'] < 0 || $t['cliente_nombre'] === 'DEVOLUCIÓN') {
        $cantDevoluciones++;
        $valorDevoluciones += abs($t['total']);
    }

    if (!isset($metodosPago[$t['metodo_pago']])) $metodosPago[$t['metodo_pago']] = 0;
    $metodosPago[$t['metodo_pago']] += $t['total'];
}

// Ganancia = solo de tickets positivos (sin incluir devoluciones)
$totalCostoPositivos = 0;
$totalVentaBrutaPositivos = 0;
foreach ($allDetalles as $d) {
    if ($d['cantidad'] > 0) {  // Solo items positivos
        $totalCostoPositivos += ($d['cantidad'] * $d['costo']);
        $totalVentaBrutaPositivos += ($d['cantidad'] * $d['precio']);
    }
}

$ganancia = $totalVentaBrutaPositivos - $totalCostoPositivos;
$margen = ($totalVentaBrutaPositivos != 0) ? ($ganancia / $totalVentaBrutaPositivos) * 100 : 0;

// KPIs modificados según solicitud:
$ticketPromedio = $ventasReales * 0.20; // 20% para Crecimiento Negoc.
$ventasPorHora  = $ventasReales * 0.30; // 30% para nuevo KPI de Velocidad
$variacion      = $ventasReales * 0.10; // 10% para nuevo KPI de Variación

// Mantener el cálculo de ventas ayer por si se usa, pero la variable $variacion se sobreescribe arriba
$fechaAyer = date('Y-m-d', strtotime('-1 day', strtotime($sesion['fecha_contable'])));
$sqlVentasAyer = "SELECT COALESCE(SUM(monto), 0) as total FROM ventas_pagos
                  WHERE id_venta_cabecera IN (
                    SELECT id FROM ventas_cabecera
                    WHERE DATE(fecha) = ? AND id_sucursal = ?
                  )";
$stmtAyer = $pdo->prepare($sqlVentasAyer);
$stmtAyer->execute([$fechaAyer, $sucursalID]);
$ventasAyer = floatval($stmtAyer->fetchColumn() ?? 0);
// La variable $variacion ya fue calculada como el 10% de ventasReales arriba.
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reporte Caja #<?php echo $idSesion; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@700;900&display=swap" rel="stylesheet">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body { background-color: #f4f6f9; font-family: 'Segoe UI', sans-serif; }
        .kpi-card { border: none; border-radius: 12px; padding: 20px; background: white; box-shadow: 0 4px 6px rgba(0,0,0,0.05); transition: transform 0.2s; height: 100%; position: relative; overflow: hidden; }
        .kpi-card:hover { transform: translateY(-3px); }
        .kpi-icon { width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; margin-bottom: 10px; }
        .ticket-row { cursor: pointer; transition: background 0.1s; border-left: 5px solid transparent; }
        .ticket-row:hover { background-color: #f1f3f5; }
        .row-efectivo { border-left-color: #198754; } 
        .row-transfer { border-left-color: #0d6efd; } 
        .row-gasto { border-left-color: #fd7e14; } 
        .row-reserva { border-left-color: #6f42c1; background-color: #f3f0ff; }
        .row-llevar { border-left-color: #0dcaf0; }
        .row-refund { border-left-color: #dc3545; background-color: #ffeaea !important; }
        .badge-pago { font-size: 0.8rem; font-weight: 500; width: 100px; display: inline-block; text-align: center; }
        .bg-efectivo { background-color: #d1e7dd; color: #0f5132; }
        .bg-transfer { background-color: #cfe2ff; color: #084298; }
        .bg-gasto { background-color: #ffe5d0; color: #994d07; }
        .bg-refund-badge { background-color: #f8d7da; color: #842029; }
        .detail-row { background-color: #fafafa; border-left: 5px solid #ccc; font-size: 0.9rem; }
        .accounting-date-badge {
            background-color: #ffc107;
            color: #000;
            padding: 8px 12px;
            border-radius: 5px;
            font-weight: bold;
            box-shadow: 0 0 15px rgba(255, 193, 7, 0.8);
            display: inline-block;
            margin-top: 10px;
        }
        @media print {
            * { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
            @page { size: A4 landscape; margin: 5mm 4mm; }
            body { background-color: #fff !important; font-size: 8px; padding: 0 !important; margin: 0 !important; }
            .no-print, .btn, #palweb-float-nav, .fas.fa-chevron-down, .kpi-icon, .text-center i { display: none !important; }
            .container-fluid { width: 100% !important; max-width: 100% !important; padding: 0 !important; margin: 0 !important; }

            /* Compactar Cabecera */
            .mb-4 { margin-bottom: 4px !important; }
            h4 { font-size: 13px !important; display: inline-block; margin-right: 8px !important; }
            .accounting-date-badge { padding: 1px 4px !important; font-size: 9px !important; margin: 0 !important; box-shadow: none !important; border: 1px solid #000 !important; }
            .text-muted.small.mt-1 { display: inline-block; font-size: 8.5px !important; }

            /* KPIs Grid */
            .row { display: flex !important; flex-wrap: wrap !important; --bs-gutter-x: 3px !important; --bs-gutter-y: 3px !important; margin-bottom: 3px !important; }
            .col-md-4 { width: 33.33% !important; flex: 0 0 auto !important; }

            .kpi-card { padding: 3px 5px !important; border: 1px solid #bbb !important; box-shadow: none !important; height: auto !important; min-height: auto !important; }
            h3 { font-size: 12px !important; font-weight: 800 !important; margin: 0 !important; }
            small { font-size: 7.5px !important; }

            /* Fila de Resaltado (Venta Neta, Reales, Ganancia) */
            .kpi-highlight-row .kpi-card { border: 1px solid #000 !important; background-color: #f8f9fa !important; padding: 6px !important; }
            .kpi-highlight-row h3 { font-size: 18px !important; color: #000 !important; }
            .kpi-highlight-row small { font-size: 9px !important; font-weight: bold !important; color: #000 !important; }

            /* Tabla principal ultra-compacta
               Columnas visibles tras ocultar col1 (expand) y col6 (origen):
               col2=Ticket  col3=Hora  col4=Cliente  col5=TipoSvc  col7=Pago  col8=Total */
            .table { font-size: 7.5px !important; table-layout: fixed; width: 100% !important; border: 1px solid #ddd !important; }
            .table th, .table td { padding: 1px 3px !important; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .ticket-row td { font-size: 8px !important; }
            .fs-5 { font-size: 10px !important; }

            /* Ocultar col1 (expand) y col6 (origen/canal) de la tabla principal */
            .card > .table-responsive > table th:nth-child(1),
            .card > .table-responsive > table td:nth-child(1),
            .card > .table-responsive > table th:nth-child(6),
            .card > .table-responsive > table td:nth-child(6) { display: none !important; }

            /* Anchos de columnas de la tabla principal (sobre 100% sin col1 y col6) */
            .card > .table-responsive > table th:nth-child(2),
            .card > .table-responsive > table td:nth-child(2) { width: 8% !important; }   /* Ticket */
            .card > .table-responsive > table th:nth-child(3),
            .card > .table-responsive > table td:nth-child(3) { width: 8% !important; }   /* Hora */
            .card > .table-responsive > table th:nth-child(4),
            .card > .table-responsive > table td:nth-child(4) { width: 38% !important; }  /* Cliente */
            .card > .table-responsive > table th:nth-child(5),
            .card > .table-responsive > table td:nth-child(5) { width: 14% !important; }  /* Tipo Svc */
            .card > .table-responsive > table th:nth-child(7),
            .card > .table-responsive > table td:nth-child(7) { width: 16% !important; }  /* Forma Pago */
            .card > .table-responsive > table th:nth-child(8),
            .card > .table-responsive > table td:nth-child(8) { width: 16% !important; text-align: right !important; font-size: 11px !important; font-weight: 900 !important; font-family: 'Roboto', Arial, sans-serif !important; } /* Total */

            /* Sub-tabla de detalle: ocultar columna Acción (última) */
            .detail-row table th:last-child,
            .detail-row table td:last-child { display: none !important; }
            .detail-row { padding: 1px !important; }
        }
    </style>
</head>
<body class="p-3">

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold mb-0">
                <i class="fas fa-cash-register text-primary"></i> Caja #<?php echo $idSesion; ?>
                <?php if($sesion['estado']=='ABIERTA'): ?>
                    <span class="badge bg-success ms-2">ABIERTA</span>
                <?php else: ?>
                    <span class="badge bg-secondary ms-2">CERRADA</span>
                <?php endif; ?>
            </h4>
            <div class="mt-2 mb-3">
                <span class="accounting-date-badge">FECHA CONTABLE: <?php echo date('d/m/Y', strtotime($sesion['fecha_contable'])); ?></span>
            </div>
            <div class="text-muted small mt-1">
                <i class="fas fa-user"></i> <?php echo htmlspecialchars($sesion['nombre_cajero']); ?> | 
                <i class="far fa-clock"></i> <?php echo date('d/m/Y h:i A', strtotime($sesion['fecha_apertura'])); ?>
            </div>
        </div>
        <div class="no-print">
            <button onclick="window.print()" class="btn btn-success btn-sm"><i class="fas fa-print"></i> Imprimir A4 Horizontal</button>
            <a href="sales_history.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-history"></i> Historial</a>
            <a href="pos.php" class="btn btn-primary btn-sm"><i class="fas fa-desktop"></i> POS</a>
        </div>
    </div>

    <div class="row g-3 mb-2">
        <div class="col-md-4">
            <div class="kpi-card border-start border-4 border-danger">
                <div class="d-flex justify-content-between align-items-center">
                    <div><small class="text-danger fw-bold">CANTIDAD DEVOLUCIONES</small><h3 class="fw-bold mb-0 text-danger"><?php echo $cantDevoluciones; ?></h3></div>
                    <div class="kpi-icon bg-danger bg-opacity-10 text-danger"><i class="fas fa-undo"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="kpi-card border-start border-4 border-danger">
                <div class="d-flex justify-content-between align-items-center">
                    <div><small class="text-danger fw-bold">VALOR REEMBOLSADO</small><h3 class="fw-bold mb-0 text-danger">$<?php echo number_format($valorDevoluciones, 2); ?></h3></div>
                    <div class="kpi-icon bg-danger bg-opacity-10 text-danger"><i class="fas fa-money-bill-wave"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="kpi-card border-start border-4 border-primary">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <small class="text-primary fw-bold">RESUMEN POR PAGO</small>
                    <div class="text-primary"><i class="fas fa-wallet"></i></div>
                </div>
                <div>
                    <?php if(empty($metodosPago)): ?>
                        <div class="text-muted small">Sin movimientos</div>
                    <?php else: ?>
                        <?php foreach($metodosPago as $metodo => $valor): ?>
                        <div class="d-flex justify-content-between border-bottom border-light pb-0 mb-0 small">
                            <span class="text-muted text-uppercase" style="font-size: 0.65rem;"><?php echo htmlspecialchars($metodo); ?></span>
                            <span class="fw-bold" style="font-size: 0.75rem;">$<?php echo number_format($valor, 2); ?></span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-2">
        <div class="col-md-4 col-sm-6"><div class="kpi-card"><div class="kpi-icon bg-info bg-opacity-10 text-info"><i class="fas fa-chart-line"></i></div><small class="text-muted fw-bold">CRECIMIENTO NEGOC.</small><h3 class="fw-bold mb-0">$<?php echo number_format($ticketPromedio, 2); ?></h3><small class="text-muted">(20%)</small></div></div>
        <div class="col-md-4 col-sm-6"><div class="kpi-card"><div class="kpi-icon bg-warning bg-opacity-10 text-warning"><i class="fas fa-percentage"></i></div><small class="text-muted fw-bold">30% VENTA</small><h3 class="fw-bold mb-0">$<?php echo number_format($ventasPorHora, 2); ?></h3></div></div>
        <div class="col-md-4 col-sm-6"><div class="kpi-card border-start border-4 border-success"><div class="kpi-icon bg-success bg-opacity-10 text-success"><i class="fas fa-coins"></i></div><small class="text-muted fw-bold">10% VENTA</small><h3 class="fw-bold mb-0 text-success">$<?php echo number_format($variacion, 2); ?></h3></div></div>
    </div>

    <div class="row g-3 mb-3 kpi-highlight-row">
        <div class="col-md-4 col-sm-6"><div class="kpi-card border-start border-4 border-primary"><div class="kpi-icon bg-primary bg-opacity-10 text-primary"><i class="fas fa-dollar-sign"></i></div><small class="text-muted fw-bold">VENTA NETA</small><h3 class="fw-bold mb-0">$<?php echo number_format($totalVentaNeta, 2); ?></h3><small class="text-muted">(Total movimientos)</small></div></div>
        <div class="col-md-4 col-sm-6"><div class="kpi-card border-start border-4 border-success"><div class="kpi-icon bg-success bg-opacity-10 text-success"><i class="fas fa-wallet"></i></div><small class="text-muted fw-bold">VENTAS REALES</small><h3 class="fw-bold mb-0 text-success">$<?php echo number_format($ventasReales, 2); ?></h3><small class="text-muted">(Neto)</small></div></div>
        <div class="col-md-4 col-sm-6"><div class="kpi-card border-start border-4 border-success"><div class="kpi-icon bg-success bg-opacity-10 text-success"><i class="fas fa-chart-line"></i></div><small class="text-muted fw-bold">GANANCIA</small><h3 class="fw-bold mb-0 text-success">$<?php echo number_format($ganancia, 2); ?></h3><small class="text-muted">Margen: <?php echo number_format($margen, 1); ?>%</small></div></div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3"><h5 class="mb-0 fw-bold">Movimientos de Caja</h5></div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light"><tr><th width="50"></th><th>Ticket</th><th>Hora</th><th>Cliente / Concepto</th><th>Tipo Servicio</th><th class="text-center">Origen</th><th>Forma Pago</th><th>Total</th></tr></thead>
                <tbody>
                    <?php foreach($tickets as $t): 
                        $tid = $t['id']; 
                        $items = $detallesPorTicket[$tid] ?? []; 
                        
                        $isRefund = ($t['total'] < 0);
                        $rowClass = '';
                        $badgePagoClass = 'bg-light text-dark border';

                        if ($isRefund) { $rowClass = 'row-refund'; $badgePagoClass = 'bg-refund-badge'; } 
                        elseif ($t['tipo_servicio'] === 'reserva') { $rowClass = 'row-reserva'; } 
                        elseif ($t['tipo_servicio'] === 'llevar') { $rowClass = 'row-llevar'; } 
                        else {
                            switch(strtolower($t['metodo_pago'])) {
                                case 'efectivo': $rowClass = 'row-efectivo'; $badgePagoClass = 'bg-efectivo'; break;
                                case 'transferencia': $rowClass = 'row-transfer'; $badgePagoClass = 'bg-transfer'; break;
                                case 'gasto casa': $rowClass = 'row-gasto'; $badgePagoClass = 'bg-gasto'; break;
                            }
                        }
                    ?>
                    <tr class="ticket-row <?php echo $rowClass; ?>" data-bs-toggle="collapse" data-bs-target="#detail-<?php echo $tid; ?>">
                        <td class="text-center text-muted"><i class="fas fa-chevron-down"></i></td>
                        <td class="fw-bold">#<?php echo str_pad($tid, 6, '0', STR_PAD_LEFT); ?></td>
                        <td><?php echo date('h:i A', strtotime($t['fecha'])); ?></td>
                        <td>
                            <?php echo htmlspecialchars($t['cliente_nombre']); ?>
                            <?php if($isRefund) echo " <span class='badge bg-danger'>DEVOLUCIÓN</span>"; ?>
                        </td>
                        <td><?php echo strtoupper($t['tipo_servicio']); ?></td>
                        <td class="text-center"><?php echo getCanalBadgeRC($t['canal_origen'] ?? 'POS', $canalMap); ?></td>
                        <td><span class="badge badge-pago <?php echo $badgePagoClass; ?>"><?php echo $t['metodo_pago']; ?></span></td>
                        <td class="text-end fw-bold fs-5">$<?php echo number_format($t['total'], 2); ?></td>
                    </tr>
                    <tr class="collapse" id="detail-<?php echo $tid; ?>">
                        <td colspan="8" class="p-0">
                            <div class="detail-row p-3">
                                <table class="table table-sm mb-0 bg-white border">
                                    <thead class="text-muted small">
                                        <tr><th>Producto</th><th class="text-end">Cant.</th><th class="text-end">Precio</th><th class="text-end">Subtotal</th><th class="text-end">Acción</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($items as $item): 
                                            // Verificación de si el item ya tiene la marca de reembolsado
                                            $isItemRefunded = isset($item['reembolsado']) && $item['reembolsado'] == 1;
                                            $subtotal = $item['cantidad'] * $item['precio'];
                                            $isNegativeItem = $item['cantidad'] < 0; 
                                        ?>
                                        <tr>
                                            <td>
                                                <?php echo htmlspecialchars($item['nombre']); ?>
                                                <?php if($isItemRefunded && !$isNegativeItem) echo " <span class='badge bg-secondary'>Origen Devolución</span>"; ?>
                                            </td>
                                            <td class="text-end fw-bold <?php echo $isNegativeItem ? 'text-danger':''; ?>">
                                                <?php echo floatval($item['cantidad']); ?>
                                            </td>
                                            <td class="text-end">$<?php echo number_format($item['precio'], 2); ?></td>
                                            <td class="text-end fw-bold <?php echo $isNegativeItem ? 'text-danger':''; ?>">
                                                $<?php echo number_format($subtotal, 2); ?>
                                            </td>
                                            <td class="text-end">
                                                <?php if(!$isRefund && !$isItemRefunded && !$isNegativeItem): ?>
                                                    <button class="btn btn-sm btn-outline-danger py-0" onclick="refundItem(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['nombre']); ?>')">
                                                        <i class="fas fa-undo"></i> Devolver
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
    async function refundItem(id, name) {
        if (!confirm(`¿Generar DEVOLUCIÓN para: ${name}?`)) return;
        try {
            const resp = await fetch('pos_refund.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: id })
            });
            const res = await resp.json();
            if (res.status === 'success') { alert('Devolución generada.'); location.reload(); } 
            else { alert('Error: ' + res.msg); }
        } catch (e) { alert('Error de conexión'); }
    }
</script>


<?php include_once 'menu_master.php'; ?>
</body>
</html>
