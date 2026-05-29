<?php
// ARCHIVO: print_report.php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) { die("Acceso denegado"); }

require_once 'db.php';
require_once 'config_loader.php';
require_once 'accounting_helpers.php';

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

function print_report_ticket_session_id(array $ticket): int {
    $sessionId = intval($ticket['id_sesion_caja'] ?? 0);
    if ($sessionId > 0) {
        return $sessionId;
    }
    return intval($ticket['id_caja'] ?? 0);
}

// --- LÓGICA DE DATOS (Copia simplificada para solo lectura) ---
try {
    // 1. Desglose
    $wReal   = ventas_reales_where_clause('v');
    $wRealVc = ventas_reales_where_clause('vc');
    $sqlDaily = "SELECT
                    dates.dia,
                    COALESCE((SELECT SUM(v.total) 
                              FROM ventas_cabecera v LEFT JOIN caja_sesiones s ON v.id_caja = s.id 
                              WHERE v.id_sucursal = ? AND IFNULL(s.fecha_contable, DATE(v.fecha)) = dates.dia AND $wReal), 0) as total_venta,
                    COALESCE((SELECT SUM(vd.cantidad * p.costo)
                              FROM ventas_detalle vd 
                              JOIN ventas_cabecera vc ON vd.id_venta_cabecera = vc.id 
                              LEFT JOIN caja_sesiones sc ON vc.id_caja = sc.id
                              JOIN productos p ON vd.id_producto = p.codigo
                              WHERE vc.id_sucursal = ? AND IFNULL(sc.fecha_contable, DATE(vc.fecha)) = dates.dia AND $wRealVc), 0) as total_costo,
                    COALESCE((SELECT SUM(monto) FROM gastos_historial WHERE id_sucursal = ? AND DATE(fecha) = dates.dia), 0) as total_gasto
                 FROM (
                    SELECT DISTINCT IFNULL(s.fecha_contable, DATE(v.fecha)) as dia 
                    FROM ventas_cabecera v LEFT JOIN caja_sesiones s ON v.id_caja = s.id
                    WHERE v.id_sucursal = ? AND IFNULL(s.fecha_contable, DATE(v.fecha)) BETWEEN ? AND ? AND $wReal
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
    $stmt = $pdo->prepare("SELECT vp.metodo_pago, SUM(vp.monto) as total 
                           FROM ventas_pagos vp 
                           JOIN ventas_cabecera vc ON vp.id_venta_cabecera = vc.id 
                           LEFT JOIN caja_sesiones sc ON vc.id_caja = sc.id
                           WHERE vc.id_sucursal = ? AND IFNULL(sc.fecha_contable, DATE(vc.fecha)) BETWEEN ? AND ? 
                           AND " . ventas_reales_where_clause('vc') . " 
                           GROUP BY vp.metodo_pago");
    $stmt->execute([$id_sucursal, $fecha_inicio, $fecha_fin]);
    $payments = $stmt->fetchAll();

    $sessionSummary = [];
    $ticketDetailsBySale = [];
    $productSummary = [];

    $stmt = $pdo->prepare("SELECT v.*
                           FROM ventas_cabecera v
                           LEFT JOIN caja_sesiones s ON v.id_caja = s.id
                           WHERE v.id_sucursal = ? AND IFNULL(s.fecha_contable, DATE(v.fecha)) BETWEEN ? AND ? 
                           AND " . ventas_reales_where_clause('v') . "
                           ORDER BY IFNULL(s.fecha_contable, DATE(v.fecha)) ASC, v.id ASC");
    $stmt->execute([$id_sucursal, $fecha_inicio, $fecha_fin]);
    $sessionTickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($sessionTickets)) {
        $saleIds = array_map(static function ($ticket) {
            return intval($ticket['id']);
        }, $sessionTickets);
        $placeholders = implode(',', array_fill(0, count($saleIds), '?'));

        $stmt = $pdo->prepare("SELECT id_venta_cabecera, nombre_producto, cantidad, precio
                               FROM ventas_detalle
                               WHERE id_venta_cabecera IN ($placeholders)
                               ORDER BY id_venta_cabecera ASC, id ASC");
        $stmt->execute($saleIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $detail) {
            $saleId = intval($detail['id_venta_cabecera']);
            if (!isset($ticketDetailsBySale[$saleId])) {
                $ticketDetailsBySale[$saleId] = [];
            }
            $ticketDetailsBySale[$saleId][] = $detail;
        }

        foreach ($sessionTickets as $ticket) {
            $sessionId = print_report_ticket_session_id($ticket);
            if ($sessionId <= 0) {
                continue;
            }
            if (!isset($sessionSummary[$sessionId])) {
                $sessionSummary[$sessionId] = [
                    'id' => $sessionId,
                    'nombre_cajero' => '',
                    'fecha_apertura' => null,
                    'fecha_cierre' => null,
                    'fecha_contable' => null,
                    'estado' => '',
                    'tickets' => [],
                    'total' => 0.0,
                ];
            }
            $sessionSummary[$sessionId]['tickets'][] = $ticket;
            $tVal = floatval($ticket['total'] ?? 0);
            if ($tVal > 0) {
                $sessionSummary[$sessionId]['total'] += $tVal;
            }
        }

        if (!empty($sessionSummary)) {
            $sessionIds = array_keys($sessionSummary);
            $sessionPlaceholders = implode(',', array_fill(0, count($sessionIds), '?'));
            $stmt = $pdo->prepare("SELECT id, nombre_cajero, fecha_apertura, fecha_cierre, fecha_contable, estado
                                   FROM caja_sesiones
                                   WHERE id IN ($sessionPlaceholders)
                                   ORDER BY fecha_apertura ASC, id ASC");
            $stmt->execute($sessionIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $sessionId = intval($row['id']);
                if (!isset($sessionSummary[$sessionId])) {
                    continue;
                }
                $sessionSummary[$sessionId]['nombre_cajero'] = $row['nombre_cajero'] ?? '';
                $sessionSummary[$sessionId]['fecha_apertura'] = $row['fecha_apertura'] ?? null;
                $sessionSummary[$sessionId]['fecha_cierre'] = $row['fecha_cierre'] ?? null;
                $sessionSummary[$sessionId]['fecha_contable'] = $row['fecha_contable'] ?? null;
                $sessionSummary[$sessionId]['estado'] = $row['estado'] ?? '';
            }

            foreach ($sessionSummary as $sessionId => $sessionData) {
                $tickets = $sessionData['tickets'] ?? [];
                $ticketsById = [];
                $reversalsByOriginal = [];

                foreach ($tickets as $ticket) {
                    $ticketId = intval($ticket['id'] ?? 0);
                    if ($ticketId <= 0) continue;
                    $ticketsById[$ticketId] = $ticket;

                    $originalId = intval($ticket['id_venta_original'] ?? 0);
                    $ticketTotal = floatval($ticket['total'] ?? 0);
                    if ($originalId > 0 && $ticketTotal < 0) {
                        if (!isset($reversalsByOriginal[$originalId])) {
                            $reversalsByOriginal[$originalId] = [];
                        }
                        $reversalsByOriginal[$originalId][] = $ticketId;
                    }
                }

                $fullCanceledIds = [];
                foreach ($ticketsById as $ticketId => $ticket) {
                    $ticketTotal = floatval($ticket['total'] ?? 0);
                    if ($ticketTotal <= 0) continue;

                    foreach (($reversalsByOriginal[$ticketId] ?? []) as $reversalId) {
                        if (!isset($ticketsById[$reversalId])) continue;
                        $reversalTotal = floatval($ticketsById[$reversalId]['total'] ?? 0);
                        if (abs(abs($reversalTotal) - abs($ticketTotal)) <= 0.01) {
                            $fullCanceledIds[$ticketId] = true;
                            $fullCanceledIds[$reversalId] = true;
                            break;
                        }
                    }
                }

                $annotatedTickets = [];
                $totalReal = 0.0;
                foreach ($tickets as $ticket) {
                    $ticketId = intval($ticket['id'] ?? 0);
                    $isFullCanceled = isset($fullCanceledIds[$ticketId]);
                    $ticketTotal = floatval($ticket['total'] ?? 0);

                    if ($isFullCanceled) {
                        $ticket['cancelado_completo'] = true;
                    } else {
                        $totalReal += $ticketTotal;
                    }
                    $annotatedTickets[] = $ticket;
                }

                $sessionSummary[$sessionId]['tickets'] = $annotatedTickets;
                $sessionSummary[$sessionId]['total_real'] = $totalReal;
            }
        }

        $stmt = $pdo->prepare(
            "SELECT
                p.nombre,
                p.categoria,
                SUM(vd.cantidad) AS total_qty,
                SUM(vd.cantidad * vd.precio) AS total_val
             FROM ventas_detalle vd
             JOIN ventas_cabecera v ON vd.id_venta_cabecera = v.id
             LEFT JOIN caja_sesiones s ON v.id_caja = s.id
             JOIN productos p ON vd.id_producto = p.codigo
             WHERE v.id_sucursal = ?
               AND IFNULL(s.fecha_contable, DATE(v.fecha)) BETWEEN ? AND ?
               AND " . ventas_reales_where_clause('v') . "
             GROUP BY p.codigo, p.nombre, p.categoria
             ORDER BY p.categoria ASC, p.nombre ASC"
        );
        $stmt->execute([$id_sucursal, $fecha_inicio, $fecha_fin]);
        $productSummary = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (Exception $e) { die("Error DB: " . $e->getMessage()); }

function print_report_family_label(string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    if ($name === '') {
        return 'SIN FAMILIA';
    }

    $parts = preg_split('/\s+/u', $name);
    $family = trim((string)($parts[0] ?? ''));
    $family = preg_replace('/^[^\pL\pN]+|[^\pL\pN]+$/u', '', $family);
    if ($family === '') {
        return 'SIN FAMILIA';
    }

    return function_exists('mb_strtoupper') ? mb_strtoupper($family, 'UTF-8') : strtoupper($family);
}
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
            .bg-light-warning { background: #fff3cd !important; color: #000 !important; }
        }
        body { font-family: 'Segoe UI', sans-serif; background: #fff; }
        .header-box { border-bottom: 2px solid #000; margin-bottom: 20px; padding-bottom: 10px; }
        .session-block { border: 1px solid #d9dee5; border-radius: 8px; overflow: hidden; margin-top: 18px; page-break-inside: avoid; }
        .session-block-head { background: #f8f9fa; padding: 10px 14px; border-bottom: 1px solid #d9dee5; }
        .session-meta { display: flex; flex-wrap: wrap; gap: 12px; font-size: 0.82rem; color: #6c757d; margin-top: 4px; }
        .ticket-items-list { font-size: 0.8rem; color: #495057; line-height: 1.45; }
        .row-cancelado { text-decoration: line-through; color: #adb5bd !important; }
        .badge-cancelado { font-size: 0.65rem; padding: 2px 5px; border: 1px solid #adb5bd; color: #adb5bd; text-transform: uppercase; font-weight: bold; border-radius: 3px; display: inline-block; margin-top: 2px; text-decoration: none !important; }
    </style>
</head>
<body onload="window.print()">

<div class="container-fluid p-4">
    <div class="header-box d-flex justify-content-between align-items-end">
        <div>
            <h1 class="fw-bold mb-0"><?= htmlspecialchars(config_loader_system_name()) ?></h1>
            <p class="mb-0 text-muted">Reporte de Cierre de Negocio</p>
        </div>
        <div class="text-end">
            <h5 class="fw-bold">Sucursal #<?php echo $id_sucursal; ?></h5>
            <p class="mb-0 small">Generado: <?php echo date('d/m/Y H:i'); ?></p>
            <p class="mb-0 fw-bold">Periodo: <?php echo date('d/m/Y', strtotime($fecha_inicio)); ?> - <?php echo date('d/m/Y', strtotime($fecha_fin)); ?></p>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-2 col-md-4 col-6">
            <div class="p-3 bg-light border rounded text-center">
                <small class="text-uppercase fw-bold text-muted">VENTAS</small>
                <h3 class="fw-bold text-primary mb-0">$<?php echo number_format($venta_total, 2); ?></h3>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6">
            <div class="p-3 bg-light border rounded text-center">
                <small class="text-uppercase fw-bold text-muted">COSTOS</small>
                <h3 class="fw-bold text-secondary mb-0">$<?php echo number_format($costo_total, 2); ?></h3>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6">
            <div class="p-3 bg-light border rounded text-center">
                <small class="text-uppercase fw-bold text-muted">GASTOS</small>
                <h3 class="fw-bold text-danger mb-0">$<?php echo number_format($gastos_totales, 2); ?></h3>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6">
            <div class="p-3 bg-light-warning border rounded text-center">
                <small class="text-uppercase fw-bold text-dark">NETO FINAL</small>
                <h3 class="fw-bold mb-0 text-dark">$<?php echo number_format($ganancia_limpia, 2); ?></h3>
                <div class="small text-dark mt-1">Restando reserva (<?php echo $pct_reserva; ?>%): -$<?php echo number_format($reserva_monto, 2); ?></div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6">
            <div class="p-3 bg-light border rounded text-center">
                <small class="text-uppercase fw-bold text-muted">GANANCIA BRUTA</small>
                <h3 class="fw-bold text-success mb-0">$<?php echo number_format($ganancia_bruta, 2); ?></h3>
                <div class="small text-success fw-bold"><?php echo number_format($pct_margen, 1); ?>%</div>
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

    <div class="mt-4">
        <h5 class="fw-bold border-bottom pb-2">Resumen por Sesiones de Caja</h5>
        <?php if (!empty($sessionSummary)): ?>
            <?php foreach ($sessionSummary as $session): ?>
                <div class="session-block">
                    <div class="session-block-head">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="fw-bold">Sesión #<?php echo intval($session['id']); ?><?php if (!empty($session['nombre_cajero'])): ?> - <?php echo htmlspecialchars($session['nombre_cajero']); ?><?php endif; ?></div>
                                <div class="session-meta">
                                    <span>Fecha contable: <?php echo !empty($session['fecha_contable']) ? date('d/m/Y', strtotime($session['fecha_contable'])) : 'N/D'; ?></span>
                                    <span>Apertura: <?php echo !empty($session['fecha_apertura']) ? date('d/m/Y H:i', strtotime($session['fecha_apertura'])) : 'N/D'; ?></span>
                                    <span>Cierre: <?php echo !empty($session['fecha_cierre']) ? date('d/m/Y H:i', strtotime($session['fecha_cierre'])) : 'Abierta'; ?></span>
                                    <span>Estado: <?php echo htmlspecialchars($session['estado'] ?: 'N/D'); ?></span>
                                </div>
                            </div>
                            <div class="text-end">
                                <small class="text-muted d-block">Total sesión (Bruto)</small>
                                <div class="fw-bold">$<?php echo number_format($session['total'], 2); ?></div>
                                <small class="text-muted d-block mt-1">Total Real (Neto)</small>
                                <div class="fw-bold text-success">$<?php echo number_format($session['total_real'] ?? $session['total'], 2); ?></div>
                            </div>
                        </div>
                    </div>
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="table-light">
                            <tr>
                                <th># Ticket</th>
                                <th>Hora</th>
                                <th>Cliente</th>
                                <th>Detalle agrupado</th>
                                <th class="text-end">Valor</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($session['tickets'] as $ticket): ?>
                                <?php
                                $isCancelado = !empty($ticket['cancelado_completo']);
                                $details = $ticketDetailsBySale[intval($ticket['id'])] ?? [];
                                $groupedItems = [];
                                foreach ($details as $detail) {
                                    $name = trim((string)($detail['nombre_producto'] ?? 'Producto'));
                                    $qty = floatval($detail['cantidad'] ?? 0);
                                    $price = floatval($detail['precio'] ?? 0);
                                    $key = $name . '|' . number_format($price, 2, '.', '');
                                    if (!isset($groupedItems[$key])) {
                                        $groupedItems[$key] = [
                                            'name' => $name,
                                            'qty' => 0.0,
                                            'price' => $price,
                                        ];
                                    }
                                    $groupedItems[$key]['qty'] += $qty;
                                }
                                ?>
                                <tr class="<?php echo $isCancelado ? 'row-cancelado' : ''; ?>">
                                    <td>
                                        #<?php echo intval($ticket['id']); ?>
                                        <?php if ($isCancelado): ?>
                                            <br><span class="badge-cancelado">Cancelado</span>
                                        <?php elseif (floatval($ticket['total'] ?? 0) < 0): ?>
                                            <br><span class="badge-cancelado">Devolución</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('d/m H:i', strtotime($ticket['fecha'])); ?></td>
                                    <td><?php echo htmlspecialchars($ticket['cliente_nombre'] ?: 'Cliente general'); ?></td>
                                    <td class="ticket-items-list">
                                        <?php if (empty($groupedItems)): ?>
                                            Sin detalle
                                        <?php else: ?>
                                            <?php
                                            $lines = [];
                                            foreach ($groupedItems as $item) {
                                                $lines[] = htmlspecialchars($item['name']) . ' x' . number_format($item['qty'], 2) . ' @ $' . number_format($item['price'], 2);
                                            }
                                            echo implode('<br>', $lines);
                                            ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end fw-bold">$<?php echo number_format($ticket['total'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="4" class="text-end">Total sesión (Bruto):</th>
                                <th class="text-end">$<?php echo number_format($session['total'], 2); ?></th>
                            </tr>
                            <tr>
                                <th colspan="4" class="text-end">TOTAL REAL (NETO):</th>
                                <th class="text-end text-success h5 mb-0">$<?php echo number_format($session['total_real'] ?? $session['total'], 2); ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="alert alert-light border">No hay tickets asociados a sesiones de caja en este periodo.</div>
        <?php endif; ?>
    </div>

    <div class="mt-4">
        <h5 class="fw-bold border-bottom pb-2">Resumen de Productos Vendidos</h5>
        <table class="table table-sm table-bordered">
            <thead class="table-light">
                <tr>
                    <th>Categoría</th>
                    <th>Familia</th>
                    <th>Producto</th>
                    <th class="text-end">Cant. Total</th>
                    <th class="text-end">Total Recaudado</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $currentCategory = null;
                $currentFamily = null;
                $categoryQty = 0.0;
                $categoryVal = 0.0;
                $familyQty = 0.0;
                $familyVal = 0.0;
                $grandQty = 0.0;
                $grandVal = 0.0;

                $flushFamily = function () use (&$currentFamily, &$familyQty, &$familyVal) {
                    if ($currentFamily === null) {
                        return;
                    }
                    ?>
                    <tr class="table-warning">
                        <td colspan="3" class="fw-bold text-uppercase">Subtotal familia: <?php echo htmlspecialchars($currentFamily); ?></td>
                        <td class="text-end fw-bold"><?php echo number_format($familyQty, 2); ?></td>
                        <td class="text-end fw-bold">$<?php echo number_format($familyVal, 2); ?></td>
                    </tr>
                    <?php
                    $currentFamily = null;
                    $familyQty = 0.0;
                    $familyVal = 0.0;
                };

                $flushCategory = function () use (&$currentCategory, &$categoryQty, &$categoryVal) {
                    if ($currentCategory === null) {
                        return;
                    }
                    ?>
                    <tr class="table-secondary">
                        <td colspan="3" class="fw-bold text-uppercase">Subtotal categoría: <?php echo htmlspecialchars($currentCategory); ?></td>
                        <td class="text-end fw-bold"><?php echo number_format($categoryQty, 2); ?></td>
                        <td class="text-end fw-bold">$<?php echo number_format($categoryVal, 2); ?></td>
                    </tr>
                    <?php
                    $currentCategory = null;
                    $categoryQty = 0.0;
                    $categoryVal = 0.0;
                };

                if (empty($productSummary)) {
                    echo '<tr><td colspan="5" class="text-center text-muted">No hay productos vendidos en este periodo.</td></tr>';
                } else {
                    foreach ($productSummary as $prod) {
                        $category = trim((string)($prod['categoria'] ?? ''));
                        if ($category === '') {
                            $category = 'SIN CATEGORÍA';
                        }
                        $family = print_report_family_label((string)($prod['nombre'] ?? ''));
                        $qty = floatval($prod['total_qty'] ?? 0);
                        $val = floatval($prod['total_val'] ?? 0);

                        if ($currentCategory !== $category) {
                            $flushFamily();
                            $flushCategory();
                            $currentCategory = $category;
                            ?>
                            <tr class="table-dark">
                                <td colspan="5" class="fw-bold text-uppercase">📂 <?php echo htmlspecialchars($currentCategory); ?></td>
                            </tr>
                            <?php
                        }

                        if ($currentFamily !== $family) {
                            $flushFamily();
                            $currentFamily = $family;
                            ?>
                            <tr class="table-light">
                                <td></td>
                                <td colspan="4" class="fw-bold text-uppercase text-muted">Familia: <?php echo htmlspecialchars($currentFamily); ?></td>
                            </tr>
                            <?php
                        }

                        $grandQty += $qty;
                        $grandVal += $val;
                        $categoryQty += $qty;
                        $categoryVal += $val;
                        $familyQty += $qty;
                        $familyVal += $val;
                        ?>
                        <tr>
                            <td></td>
                            <td><?php echo htmlspecialchars($currentFamily); ?></td>
                            <td style="padding-left: 20px;"><?php echo htmlspecialchars($prod['nombre'] ?? ''); ?></td>
                            <td class="text-end"><?php echo number_format($qty, 2); ?></td>
                            <td class="text-end">$<?php echo number_format($val, 2); ?></td>
                        </tr>
                        <?php
                    }

                    $flushFamily();
                    $flushCategory();
                    ?>
                    <tr style="border-top: 2px solid #333; font-weight: bold;">
                        <td colspan="3">TOTAL GENERAL PRODUCTOS</td>
                        <td class="text-end"><?php echo number_format($grandQty, 2); ?></td>
                        <td class="text-end">$<?php echo number_format($grandVal, 2); ?></td>
                    </tr>
                    <?php
                }
                ?>
            </tbody>
        </table>
    </div>

    <div class="fixed-bottom p-3 text-center no-print">
        <button onclick="window.close()" class="btn btn-secondary">Cerrar</button>
    </div>
</div>
<center><small>Generado por <?= htmlspecialchars(config_loader_system_name()) ?> 3.0</small></center>
</body>
</html>
