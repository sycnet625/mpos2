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
$totalVentaNeta = 0;
$metodosPago = [];
$conteoTickets = 0;
$cantDevoluciones = 0;
$valorDevoluciones = 0;

$horaInicio = strtotime($sesion['fecha_apertura']);
$horaFin = $sesion['fecha_cierre'] ? strtotime($sesion['fecha_cierre']) : time();
$horasOperacion = max(1, round(($horaFin - $horaInicio) / 3600, 2));

foreach ($tickets as $t) {
    $totalVentaNeta += $t['total'];
    
    if ($t['total'] < 0 || $t['cliente_nombre'] === 'DEVOLUCIÓN') {
        $cantDevoluciones++;
        $valorDevoluciones += abs($t['total']); 
    } else {
        $conteoTickets++; 
    }

    if (!isset($metodosPago[$t['metodo_pago']])) $metodosPago[$t['metodo_pago']] = 0;
    $metodosPago[$t['metodo_pago']] += $t['total'];
}

$ganancia = $totalVentaBruta - $totalCosto;
$margen = ($totalVentaBruta != 0) ? ($ganancia / $totalVentaBruta) * 100 : 0; 

$ticketPromedio = ($conteoTickets > 0) ? $totalVentaNeta / $conteoTickets : 0;
$ventasPorHora = $totalVentaNeta / $horasOperacion;
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reporte Caja #<?php echo $idSesion; ?></title>
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
            <div class="text-muted small mt-1">
                <i class="fas fa-user"></i> <?php echo htmlspecialchars($sesion['nombre_cajero']); ?> | 
                <i class="far fa-clock"></i> <?php echo date('d/m/Y h:i A', strtotime($sesion['fecha_apertura'])); ?>
            </div>
        </div>
        <div>
            <a href="sales_history.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-history"></i> Historial</a>
            <a href="pos.php" class="btn btn-primary btn-sm"><i class="fas fa-desktop"></i> POS</a>
        </div>
    </div>

    <div class="row g-3 mb-3">
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
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <small class="text-primary fw-bold">RESUMEN POR PAGO</small>
                    <div class="text-primary"><i class="fas fa-wallet"></i></div>
                </div>
                <div>
                    <?php if(empty($metodosPago)): ?>
                        <div class="text-muted small">Sin movimientos</div>
                    <?php else: ?>
                        <?php foreach($metodosPago as $metodo => $valor): ?>
                        <div class="d-flex justify-content-between border-bottom border-light pb-1 mb-1 small">
                            <span class="text-muted text-uppercase"><?php echo htmlspecialchars($metodo); ?></span>
                            <span class="fw-bold">$<?php echo number_format($valor, 2); ?></span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3 col-sm-6"><div class="kpi-card"><div class="kpi-icon bg-primary bg-opacity-10 text-primary"><i class="fas fa-dollar-sign"></i></div><small class="text-muted fw-bold">VENTA NETA</small><h3 class="fw-bold mb-0">$<?php echo number_format($totalVentaNeta, 2); ?></h3></div></div>
        <div class="col-md-3 col-sm-6"><div class="kpi-card"><div class="kpi-icon bg-success bg-opacity-10 text-success"><i class="fas fa-chart-line"></i></div><small class="text-muted fw-bold">GANANCIA</small><h3 class="fw-bold mb-0 text-success">$<?php echo number_format($ganancia, 2); ?></h3><small class="text-muted">Margen: <?php echo number_format($margen, 1); ?>%</small></div></div>
        <div class="col-md-3 col-sm-6"><div class="kpi-card"><div class="kpi-icon bg-info bg-opacity-10 text-info"><i class="fas fa-shopping-bag"></i></div><small class="text-muted fw-bold">TICKET PROM.</small><h3 class="fw-bold mb-0">$<?php echo number_format($ticketPromedio, 2); ?></h3></div></div>
        <div class="col-md-3 col-sm-6"><div class="kpi-card"><div class="kpi-icon bg-warning bg-opacity-10 text-warning"><i class="fas fa-stopwatch"></i></div><small class="text-muted fw-bold">VELOCIDAD</small><h3 class="fw-bold mb-0">$<?php echo number_format($ventasPorHora, 2); ?></h3><small class="text-muted">/ hora</small></div></div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3"><h5 class="mb-0 fw-bold">Movimientos de Caja</h5></div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light"><tr><th width="50"></th><th>Ticket</th><th>Hora</th><th>Cliente / Concepto</th><th>Tipo Servicio</th><th>Forma Pago</th><th>Total</th></tr></thead>
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
                        <td><span class="badge badge-pago <?php echo $badgePagoClass; ?>"><?php echo $t['metodo_pago']; ?></span></td>
                        <td class="text-end fw-bold fs-5">$<?php echo number_format($t['total'], 2); ?></td>
                    </tr>
                    <tr class="collapse" id="detail-<?php echo $tid; ?>">
                        <td colspan="7" class="p-0">
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
