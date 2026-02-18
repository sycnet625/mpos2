<?php
// ARCHIVO: print_report.php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) { die("Acceso denegado"); }

require_once 'db.php';

// CONFIGURACIÓN MÍNIMA
$id_sucursal = 1; 
$pct_reserva = 10;
// Intentar cargar config real
$configFile = 'pos.cfg';
if (file_exists($configFile)) {
    $loaded = json_decode(file_get_contents($configFile), true);
    if ($loaded) {
        $id_sucursal = $loaded['id_sucursal'];
        $pct_reserva = $loaded['reserva_limpieza_pct'] ?? 10;
    }
}

$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-d');
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-d');

// --- LÓGICA DE DATOS (Copia simplificada para solo lectura) ---
try {
    // 1. Desglose
    $sqlDaily = "SELECT 
                    dates.dia,
                    COALESCE((SELECT SUM(total) FROM ventas_cabecera WHERE id_sucursal = ? AND DATE(fecha) = dates.dia), 0) as total_venta,
                    COALESCE((SELECT SUM(vd.cantidad * p.costo) 
                              FROM ventas_detalle vd JOIN ventas_cabecera vc ON vd.id_venta_cabecera = vc.id JOIN productos p ON vd.id_producto = p.codigo
                              WHERE vc.id_sucursal = ? AND DATE(vc.fecha) = dates.dia), 0) as total_costo,
                    COALESCE((SELECT SUM(monto) FROM gastos_historial WHERE id_sucursal = ? AND DATE(fecha) = dates.dia), 0) as total_gasto
                 FROM (
                    SELECT DISTINCT DATE(fecha) as dia FROM ventas_cabecera WHERE id_sucursal = ? AND DATE(fecha) BETWEEN ? AND ?
                    UNION
                    SELECT DISTINCT DATE(fecha) as dia FROM gastos_historial WHERE id_sucursal = ? AND DATE(fecha) BETWEEN ? AND ?
                 ) as dates ORDER BY dates.dia ASC";
    $stmt = $pdo->prepare($sqlDaily);
    $stmt->execute([$id_sucursal, $id_sucursal, $id_sucursal, $id_sucursal, $fecha_inicio, $fecha_fin, $id_sucursal, $fecha_inicio, $fecha_fin]);
    $dailySummary = $stmt->fetchAll();

    $venta_total = 0; $costo_total = 0; $gastos_totales = 0;
    foreach($dailySummary as $row) {
        $venta_total += $row['total_venta'];
        $costo_total += $row['total_costo'];
        $gastos_totales += $row['total_gasto'];
    }
    $ganancia_bruta = $venta_total - $costo_total;
    $reserva_monto = $venta_total * ($pct_reserva / 100);
    $ganancia_limpia = $ganancia_bruta - $reserva_monto - $gastos_totales;
    $pct_margen = $venta_total > 0 ? ($ganancia_bruta / $venta_total)*100 : 0;

    // 2. Pagos
    $stmt = $pdo->prepare("SELECT vp.metodo_pago, SUM(vp.monto) as total FROM ventas_pagos vp JOIN ventas_cabecera vc ON vp.id_venta_cabecera = vc.id WHERE vc.id_sucursal = ? AND DATE(vc.fecha) BETWEEN ? AND ? GROUP BY vp.metodo_pago");
    $stmt->execute([$id_sucursal, $fecha_inicio, $fecha_fin]);
    $payments = $stmt->fetchAll();

} catch (Exception $e) { die("Error DB: " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte Impresión</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        @media print {
            @page { size: A4; margin: 10mm; }
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; background: white; }
            .no-print { display: none !important; }
            .card { border: 1px solid #ddd !important; box-shadow: none !important; break-inside: avoid; }
            .bg-gradient-primary { background: #0d6efd !important; color: white !important; }
            .bg-gradient-success { background: #198754 !important; color: white !important; }
            .bg-gradient-danger { background: #dc3545 !important; color: white !important; }
            .bg-dark { background: #212529 !important; color: white !important; }
        }
        body { font-family: 'Segoe UI', sans-serif; background: #fff; }
        .header-box { border-bottom: 2px solid #000; margin-bottom: 20px; padding-bottom: 10px; }
    </style>
</head>
<body onload="window.print()">

<div class="container-fluid p-4">
    <div class="header-box d-flex justify-content-between align-items-end">
        <div>
            <h1 class="fw-bold mb-0">PALWEB POS</h1>
            <p class="mb-0 text-muted">Reporte de Cierre de Negocio</p>
        </div>
        <div class="text-end">
            <h5 class="fw-bold">Sucursal #<?php echo $id_sucursal; ?></h5>
            <p class="mb-0 small">Generado: <?php echo date('d/m/Y H:i'); ?></p>
            <p class="mb-0 fw-bold">Periodo: <?php echo date('d/m/Y', strtotime($fecha_inicio)); ?> - <?php echo date('d/m/Y', strtotime($fecha_fin)); ?></p>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-3">
            <div class="p-3 bg-light border rounded text-center">
                <small class="text-uppercase fw-bold text-muted">VENTAS</small>
                <h3 class="fw-bold text-primary mb-0">$<?php echo number_format($venta_total, 2); ?></h3>
            </div>
        </div>
        <div class="col-3">
            <div class="p-3 bg-light border rounded text-center">
                <small class="text-uppercase fw-bold text-muted">COSTOS</small>
                <h3 class="fw-bold text-secondary mb-0">$<?php echo number_format($costo_total, 2); ?></h3>
            </div>
        </div>
        <div class="col-3">
            <div class="p-3 bg-light border rounded text-center">
                <small class="text-uppercase fw-bold text-muted">GASTOS</small>
                <h3 class="fw-bold text-danger mb-0">$<?php echo number_format($gastos_totales, 2); ?></h3>
            </div>
        </div>
        <div class="col-3">
            <div class="p-3 bg-dark text-white border rounded text-center">
                <small class="text-uppercase fw-bold text-warning">NETO FINAL</small>
                <h3 class="fw-bold mb-0">$<?php echo number_format($ganancia_limpia, 2); ?></h3>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-8">
            <h5 class="fw-bold border-bottom pb-2">Detalle de Operaciones</h5>
            <table class="table table-sm table-bordered">
                <thead class="table-light">
                    <tr>
                        <th>Fecha</th>
                        <th class="text-end">Venta</th>
                        <th class="text-end">Costo</th>
                        <th class="text-end">Gasto</th>
                        <th class="text-end">Neto</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($dailySummary as $row): 
                        $gb = $row['total_venta'] - $row['total_costo'];
                        $neto = $gb - $row['total_gasto'];
                    ?>
                    <tr>
                        <td><?php echo $row['dia']; ?></td>
                        <td class="text-end">$<?php echo number_format($row['total_venta'], 2); ?></td>
                        <td class="text-end">$<?php echo number_format($row['total_costo'], 2); ?></td>
                        <td class="text-end">$<?php echo number_format($row['total_gasto'], 2); ?></td>
                        <td class="text-end fw-bold">$<?php echo number_format($neto, 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="col-4">
            <h5 class="fw-bold border-bottom pb-2">Desglose de Pagos</h5>
            <ul class="list-group list-group-flush mb-4">
                <?php foreach($payments as $p): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                    <?php echo $p['metodo_pago']; ?>
                    <span class="fw-bold">$<?php echo number_format($p['total'], 2); ?></span>
                </li>
                <?php endforeach; ?>
            </ul>

            <div class="alert alert-secondary">
                <small class="fw-bold">RESERVA (<?php echo $pct_reserva; ?>%)</small><br>
                <span class="h5 fw-bold">$<?php echo number_format($reserva_monto, 2); ?></span>
            </div>
        </div>
    </div>

    <div class="fixed-bottom p-3 text-center no-print">
        <button onclick="window.close()" class="btn btn-secondary">Cerrar</button>
    </div>
</div>
<center><small>Generado por PALWEB POS 3.0</small></center>
</body>
</html>

