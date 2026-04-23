<?php
// ARCHIVO: /var/www/palweb/api/report_print.php
ini_set('display_errors', 0);
require_once 'db.php';

// Config
require_once 'config_loader.php';

$mode = $_GET['mode'] ?? 'range'; 
$title = "";
$tickets = [];
$summaryCats = [];
$finanzas = ['venta'=>0, 'costo'=>0];
$cashSessions = [];

// Variables para Devoluciones
$refundItems = [];
$totalRefundQty = 0;
$totalRefundVal = 0;

function ticket_session_id(array $ticket): int {
    $sessionId = intval($ticket['id_sesion_caja'] ?? 0);
    if ($sessionId > 0) {
        return $sessionId;
    }
    return intval($ticket['id_caja'] ?? 0);
}

// LOGICA SEGUN MODO
if ($mode === 'session') {
    $id = intval($_GET['id']);
    $stmtS = $pdo->prepare("SELECT * FROM caja_sesiones WHERE id = ?");
    $stmtS->execute([$id]);
    $sesion = $stmtS->fetch(PDO::FETCH_ASSOC);
    $title = "Cierre Caja #" . $id . " - " . ($sesion['nombre_cajero'] ?? 'Cajero');

    $stmtT = $pdo->prepare("SELECT * FROM ventas_cabecera WHERE (id_sesion_caja = ? OR id_caja = ?) ORDER BY id ASC");
    $stmtT->execute([$id, $id]);
    $tickets = $stmtT->fetchAll(PDO::FETCH_ASSOC);

    $whereClause = "(v.id_sesion_caja = ? OR v.id_caja = ?)";
    $params = [$id, $id];

} else {
    $start = $_GET['start']; $end = $_GET['end'];
    $title = "Reporte General: $start al $end";

    $stmt = $pdo->prepare("SELECT v.*, s.nombre_cajero FROM ventas_cabecera v LEFT JOIN caja_sesiones s ON v.id_caja = s.id WHERE DATE(v.fecha) BETWEEN ? AND ? ORDER BY v.id DESC");
    $stmt->execute([$start, $end]);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $whereClause = "DATE(v.fecha) BETWEEN ? AND ?";
    $params = [$start, $end];
}

// 1. FINANZAS (Ganancia)
$sqlFin = "SELECT SUM(d.cantidad * d.precio) as venta, SUM(d.cantidad * p.costo) as costo 
           FROM ventas_detalle d JOIN productos p ON d.id_producto = p.codigo JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id 
           WHERE $whereClause";
$stmtF = $pdo->prepare($sqlFin);
$stmtF->execute($params);
$finanzas = $stmtF->fetch(PDO::FETCH_ASSOC);
$ganancia = $finanzas['venta'] - $finanzas['costo'];
$margen = ($finanzas['venta'] > 0) ? ($ganancia / $finanzas['venta']) * 100 : 0;

// 2. CATEGORIAS (Agrupado) - MODIFICADO CON GANANCIA
$sqlCat = "SELECT p.categoria, SUM(d.cantidad) as cant, SUM(d.cantidad * d.precio) as total, SUM(d.cantidad * (d.precio - p.costo)) as ganancia 
           FROM ventas_detalle d JOIN productos p ON d.id_producto = p.codigo JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id 
           WHERE $whereClause GROUP BY p.categoria";
$stmtC = $pdo->prepare($sqlCat);
$stmtC->execute($params);
$summaryCats = $stmtC->fetchAll(PDO::FETCH_ASSOC);

// 3. METODOS PAGO
$pagos = [];
foreach($tickets as $t) {
    if(!isset($pagos[$t['metodo_pago']])) $pagos[$t['metodo_pago']] = 0;
    $pagos[$t['metodo_pago']] += $t['total'];
}

// 4. ANALISIS DE DEVOLUCIONES
$sqlRef = "SELECT p.nombre, SUM(d.cantidad) as cant, SUM(d.cantidad * d.precio) as total 
           FROM ventas_detalle d 
           JOIN productos p ON d.id_producto = p.codigo 
           JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id 
           WHERE $whereClause AND d.cantidad < 0 
           GROUP BY p.nombre";
$stmtRef = $pdo->prepare($sqlRef);
$stmtRef->execute($params);
$refundItems = $stmtRef->fetchAll(PDO::FETCH_ASSOC);

foreach ($refundItems as $ri) {
    $totalRefundQty += abs($ri['cant']);
    $totalRefundVal += $ri['total'];
}

// 5. LISTA DETALLADA DE PRODUCTOS (MODIFICADO CON GANANCIA)
$sqlProds = "SELECT p.nombre, p.categoria, SUM(d.cantidad) as total_qty, SUM(d.cantidad * d.precio) as total_val, SUM(d.cantidad * (d.precio - p.costo)) as total_profit 
             FROM ventas_detalle d 
             JOIN productos p ON d.id_producto = p.codigo 
             JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id 
             WHERE $whereClause 
             GROUP BY p.codigo, p.nombre, p.categoria 
             ORDER BY p.categoria ASC, p.nombre ASC";
$stmtProds = $pdo->prepare($sqlProds);
$stmtProds->execute($params);
$soldProducts = $stmtProds->fetchAll(PDO::FETCH_ASSOC);

$ticketDetailsBySale = [];
if (!empty($tickets)) {
    $saleIds = array_map(static function ($ticket) {
        return intval($ticket['id']);
    }, $tickets);
    $placeholders = implode(',', array_fill(0, count($saleIds), '?'));
    $stmtTicketDetails = $pdo->prepare(
        "SELECT id_venta_cabecera, nombre_producto, cantidad, precio
         FROM ventas_detalle
         WHERE id_venta_cabecera IN ($placeholders)
         ORDER BY id_venta_cabecera ASC, id ASC"
    );
    $stmtTicketDetails->execute($saleIds);
    foreach ($stmtTicketDetails->fetchAll(PDO::FETCH_ASSOC) as $detail) {
        $saleId = intval($detail['id_venta_cabecera']);
        if (!isset($ticketDetailsBySale[$saleId])) {
            $ticketDetailsBySale[$saleId] = [];
        }
        $ticketDetailsBySale[$saleId][] = $detail;
    }

    foreach ($tickets as $ticket) {
        $sessionId = ticket_session_id($ticket);
        if ($sessionId <= 0) {
            continue;
        }
        if (!isset($cashSessions[$sessionId])) {
            $cashSessions[$sessionId] = [
                'id' => $sessionId,
                'nombre_cajero' => $ticket['nombre_cajero'] ?? '',
                'fecha_apertura' => null,
                'fecha_cierre' => null,
                'fecha_contable' => null,
                'estado' => '',
                'tickets' => [],
                'total' => 0.0,
            ];
        }
        $cashSessions[$sessionId]['tickets'][] = $ticket;
        $cashSessions[$sessionId]['total'] += floatval($ticket['total'] ?? 0);
    }

    if (!empty($cashSessions)) {
        $sessionIds = array_keys($cashSessions);
        $sessionPlaceholders = implode(',', array_fill(0, count($sessionIds), '?'));
        $stmtSessions = $pdo->prepare(
            "SELECT id, nombre_cajero, fecha_apertura, fecha_cierre, fecha_contable, estado
             FROM caja_sesiones
             WHERE id IN ($sessionPlaceholders)
             ORDER BY fecha_apertura ASC, id ASC"
        );
        $stmtSessions->execute($sessionIds);
        foreach ($stmtSessions->fetchAll(PDO::FETCH_ASSOC) as $sessionRow) {
            $sessionId = intval($sessionRow['id']);
            if (!isset($cashSessions[$sessionId])) {
                continue;
            }
            $cashSessions[$sessionId]['nombre_cajero'] = $sessionRow['nombre_cajero'] ?? $cashSessions[$sessionId]['nombre_cajero'];
            $cashSessions[$sessionId]['fecha_apertura'] = $sessionRow['fecha_apertura'] ?? null;
            $cashSessions[$sessionId]['fecha_cierre'] = $sessionRow['fecha_cierre'] ?? null;
            $cashSessions[$sessionId]['fecha_contable'] = $sessionRow['fecha_contable'] ?? null;
            $cashSessions[$sessionId]['estado'] = $sessionRow['estado'] ?? '';
        }

        uasort($cashSessions, static function ($a, $b) {
            $left = strtotime($a['fecha_apertura'] ?? '') ?: 0;
            $right = strtotime($b['fecha_apertura'] ?? '') ?: 0;
            if ($left === $right) {
                return $a['id'] <=> $b['id'];
            }
            return $left <=> $right;
        });
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?php echo $title; ?></title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; color: #333; background: #eee; padding: 20px; }
        .page { background: white; max-width: 210mm; margin: 0 auto; padding: 15mm; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        h1, h2, h3 { margin: 0; }
        .header { text-align: center; border-bottom: 2px solid #333; padding-bottom: 15px; margin-bottom: 20px; }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 0.85rem; }
        th { text-align: left; border-bottom: 2px solid #ddd; padding: 5px; background: #f9f9f9; }
        td { border-bottom: 1px solid #eee; padding: 5px; }
        .text-end { text-align: right; }
        .fw-bold { font-weight: bold; }
        
        .box-container { display: flex; gap: 20px; margin-bottom: 20px; }
        .box { flex: 1; background: #f8f9fa; padding: 10px; border: 1px solid #ddd; border-radius: 5px; }
        .profit-box { background: #e8f5e9; border-color: #c8e6c9; }
        
        .refund-box { background: #fff5f5; border: 1px solid #ffc9c9; border-radius: 5px; padding: 10px; margin-bottom: 20px; page-break-inside: avoid; }
        .refund-title { color: #dc3545; font-weight: bold; margin-bottom: 10px; display: block; border-bottom: 1px solid #ffc9c9; padding-bottom: 5px; }
        .text-danger { color: #dc3545; }
        .text-success { color: #198754; }

        .actions { position: fixed; top: 20px; right: 20px; }
        .btn { padding: 10px 20px; background: #333; color: white; border: none; cursor: pointer; border-radius: 5px; }

        .cat-header { background-color: #e9ecef; font-weight: bold; text-transform: uppercase; font-size: 0.8rem; padding-top: 10px; padding-bottom: 5px; }
        .session-card { border: 1px solid #dbe4f0; border-radius: 8px; margin-bottom: 18px; overflow: hidden; page-break-inside: avoid; }
        .session-head { background: #edf4ff; padding: 10px 12px; border-bottom: 1px solid #dbe4f0; }
        .session-meta { display: flex; flex-wrap: wrap; gap: 12px; font-size: 0.8rem; color: #4b5563; margin-top: 4px; }
        .ticket-detail-table { margin-bottom: 0; font-size: 0.82rem; }
        .ticket-items { color: #555; font-size: 0.78rem; line-height: 1.45; }
        .session-total-row td { background: #f8fafc; font-weight: bold; }

        @media print { body { background: white; padding: 0; } .page { box-shadow: none; margin: 0; padding: 0; width: 100%; } .actions { display: none; } }
    </style>
</head>
<body>

<div class="actions"><button class="btn" onclick="window.print()">🖨️ Imprimir</button></div>

<div class="page">
    <div class="header">
        <h1><?php echo htmlspecialchars($config['tienda_nombre']); ?></h1>
        <h3><?php echo $title; ?></h3>
        <small>Generado: <?php echo date('d/m/Y H:i'); ?></small>
    </div>

    <div class="box-container">
        <div class="box">
            <h4>💰 Desglose Pagos</h4>
            <table>
                <?php $sumPagos=0; foreach($pagos as $metodo => $monto): $sumPagos+=$monto; ?>
                <tr>
                    <td><?php echo $metodo; ?></td>
                    <td class="text-end">$<?php echo number_format($monto, 2); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr>
                    <td class="fw-bold">TOTAL NETO:</td>
                    <td class="text-end fw-bold">$<?php echo number_format($sumPagos, 2); ?></td>
                </tr>
            </table>
        </div>

        <div class="box profit-box">
            <h4>📈 Rentabilidad</h4>
            <table>
                <tr><td>Venta Neta:</td><td class="text-end">$<?php echo number_format($finanzas['venta'], 2); ?></td></tr>
                <tr><td>Costo Mercancía:</td><td class="text-end text-danger">-$<?php echo number_format($finanzas['costo'], 2); ?></td></tr>
                <tr style="border-top: 1px solid #999;">
                    <td class="fw-bold">GANANCIA:</td>
                    <td class="text-end fw-bold text-success">$<?php echo number_format($ganancia, 2); ?></td>
                </tr>
                <tr><td>Margen:</td><td class="text-end"><?php echo number_format($margen, 1); ?>%</td></tr>
            </table>
        </div>
    </div>

    <?php if (!empty($refundItems)): ?>
    <div class="refund-box">
        <span class="refund-title">⚠️ AUDITORÍA DE DEVOLUCIONES</span>
        <table>
            <thead>
                <tr>
                    <th style="color:#dc3545">Producto Devuelto</th>
                    <th class="text-end" style="color:#dc3545">Cant.</th>
                    <th class="text-end" style="color:#dc3545">Valor Restado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($refundItems as $ri): ?>
                <tr>
                    <td><?php echo htmlspecialchars($ri['nombre']); ?></td>
                    <td class="text-end"><?php echo abs($ri['cant']); ?></td>
                    <td class="text-end text-danger">$<?php echo number_format($ri['total'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr style="border-top: 1px dashed #dc3545;">
                    <td class="fw-bold text-danger">TOTALES</td>
                    <td class="text-end fw-bold text-danger"><?php echo $totalRefundQty; ?></td>
                    <td class="text-end fw-bold text-danger">$<?php echo number_format($totalRefundVal, 2); ?></td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <h4>📦 Ventas por Categoría (Neto)</h4>
    <table>
        <thead>
            <tr>
                <th>Categoría</th>
                <th class="text-end">Cant.</th>
                <th class="text-end">Venta</th>
                <th class="text-end">Ganancia</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($summaryCats as $c): ?>
            <tr>
                <td><?php echo htmlspecialchars($c['categoria']); ?></td>
                <td class="text-end"><?php echo number_format($c['cant'], 0); ?></td>
                <td class="text-end">$<?php echo number_format($c['total'], 2); ?></td>
                <td class="text-end text-success">$<?php echo number_format($c['ganancia'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h4 style="margin-top: 30px;">🧾 Detalle de Tickets</h4>
    <table>
        <thead>
            <tr>
                <th>#ID</th>
                <th>Hora</th>
                <th>Cliente</th>
                <th>Tipo Servicio</th>
                <th>Forma Pago</th> <th class="text-end">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($tickets as $t): 
                $isRefund = $t['total'] < 0;
                $rowStyle = $isRefund ? 'color: #dc3545;' : '';
            ?>
            <tr style="<?php echo $rowStyle; ?>">
                <td><?php echo $t['id']; ?></td>
                <td><?php echo date('d/m H:i', strtotime($t['fecha'])); ?></td>
                <td>
                    <?php echo htmlspecialchars($t['cliente_nombre']); ?>
                    <?php if($isRefund) echo " (DEVOLUCIÓN)"; ?>
                </td>
                <td><?php echo strtoupper($t['tipo_servicio']); ?></td>
                <td><?php echo htmlspecialchars($t['metodo_pago']); ?></td> <td class="text-end fw-bold">$<?php echo number_format($t['total'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if (!empty($cashSessions)): ?>
    <h4 style="margin-top: 30px;">🗂️ Resumen por Sesión de Caja</h4>
    <?php foreach ($cashSessions as $session): ?>
    <div class="session-card">
        <div class="session-head">
            <div class="fw-bold">
                Sesión #<?php echo intval($session['id']); ?>
                <?php if (!empty($session['nombre_cajero'])): ?>
                    - <?php echo htmlspecialchars($session['nombre_cajero']); ?>
                <?php endif; ?>
            </div>
            <div class="session-meta">
                <span>Fecha contable: <?php echo !empty($session['fecha_contable']) ? date('d/m/Y', strtotime($session['fecha_contable'])) : 'N/D'; ?></span>
                <span>Apertura: <?php echo !empty($session['fecha_apertura']) ? date('d/m/Y H:i', strtotime($session['fecha_apertura'])) : 'N/D'; ?></span>
                <span>Cierre: <?php echo !empty($session['fecha_cierre']) ? date('d/m/Y H:i', strtotime($session['fecha_cierre'])) : 'Abierta'; ?></span>
                <span>Estado: <?php echo htmlspecialchars($session['estado'] ?: 'N/D'); ?></span>
                <span>Tickets: <?php echo count($session['tickets']); ?></span>
                <span>Total: $<?php echo number_format($session['total'], 2); ?></span>
            </div>
        </div>
        <table class="ticket-detail-table">
            <thead>
                <tr>
                    <th>#Ticket</th>
                    <th>Hora</th>
                    <th>Cliente</th>
                    <th>Detalle agrupado</th>
                    <th class="text-end">Valor</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($session['tickets'] as $ticket): ?>
                <?php
                    $ticketId = intval($ticket['id']);
                    $details = $ticketDetailsBySale[$ticketId] ?? [];
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
                <tr>
                    <td><?php echo $ticketId; ?></td>
                    <td><?php echo date('d/m H:i', strtotime($ticket['fecha'])); ?></td>
                    <td><?php echo htmlspecialchars($ticket['cliente_nombre'] ?: 'Cliente general'); ?></td>
                    <td class="ticket-items">
                        <?php if (empty($groupedItems)): ?>
                            Sin detalle
                        <?php else: ?>
                            <?php
                            $chunks = [];
                            foreach ($groupedItems as $item) {
                                $chunks[] = htmlspecialchars($item['name']) . ' x' . number_format($item['qty'], 2) . ' @ $' . number_format($item['price'], 2);
                            }
                            echo implode('<br>', $chunks);
                            ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-end fw-bold">$<?php echo number_format($ticket['total'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="session-total-row">
                    <td colspan="4">Total sesión #<?php echo intval($session['id']); ?></td>
                    <td class="text-end">$<?php echo number_format($session['total'], 2); ?></td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <h4 style="margin-top: 30px;">🛒 Resumen Detallado de Productos Vendidos</h4>
    <table>
        <thead>
            <tr>
                <th>Producto</th>
                <th class="text-end">Cant. Total</th>
                <th class="text-end">Total Recaudado</th>
                <th class="text-end">Ganancia</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $currentCat = null;
            if (empty($soldProducts)) {
                echo "<tr><td colspan='4' class='text-center text-muted'>No hay productos vendidos en este periodo.</td></tr>";
            }
            foreach($soldProducts as $prod): 
                // Opcional: Saltar productos con cantidad 0 si hubo devoluciones exactas
                // if ($prod['total_qty'] == 0) continue; 

                // Imprimir Cabecera de Categoría si cambia
                if ($prod['categoria'] !== $currentCat): 
                    $currentCat = $prod['categoria'];
            ?>
                <tr>
                    <td colspan="4" class="cat-header">
                        📂 <?php echo htmlspecialchars($currentCat ?: 'SIN CATEGORÍA'); ?>
                    </td>
                </tr>
            <?php endif; ?>
            
            <tr>
                <td style="padding-left: 20px;"><?php echo htmlspecialchars($prod['nombre']); ?></td>
                <td class="text-end"><?php echo number_format($prod['total_qty'], 2); ?></td>
                <td class="text-end">$<?php echo number_format($prod['total_val'], 2); ?></td>
                <td class="text-end text-success">$<?php echo number_format($prod['total_profit'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <div style="text-align: center; margin-top: 50px; font-size: 0.8rem; color: #777;">
        --- Fin del Reporte ---
    </div>
</div>

</body>
</html>
