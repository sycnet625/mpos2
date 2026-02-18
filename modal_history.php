<?php
// ARCHIVO: modal_history.php
// LÓGICA AUTÓNOMA PHP (AJAX)
if (isset($_GET['render_mode'])) {
    session_start();
    require_once 'db.php'; 
    ini_set('display_errors', 0);
    error_reporting(E_ALL);

    try {
        // 1. Detectar Sesión Activa
        $idSesion = 0;
        $stmtCaja = $pdo->query("SELECT id FROM caja_sesiones WHERE estado = 'ABIERTA' ORDER BY id DESC LIMIT 1");
        $idSesion = $stmtCaja->fetchColumn();

        $filtroSql = "";
        $params = [];

        if ($idSesion) {
            $filtroSql = "WHERE v.id_caja = ?";
            $params[] = $idSesion;
        } else {
            $filtroSql = "WHERE DATE(v.fecha) = CURDATE()";
        }

        // 2. Consulta Tickets (Lista principal)
        $sqlCab = "SELECT v.id, v.fecha, v.total, v.metodo_pago, v.cliente_nombre, v.tipo_servicio, v.mensajero_nombre 
                   FROM ventas_cabecera v 
                   $filtroSql 
                   ORDER BY v.id DESC";
        $stmtCab = $pdo->prepare($sqlCab);
        $stmtCab->execute($params);
        $tickets = $stmtCab->fetchAll(PDO::FETCH_ASSOC);

        // 3. Consulta Detalles (RESTABLECIDA)
        $sqlDet = "SELECT d.id, d.id_venta_cabecera, d.nombre_producto, d.cantidad, d.precio 
                   FROM ventas_detalle d 
                   JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id 
                   $filtroSql";
        $stmtDet = $pdo->prepare($sqlDet);
        $stmtDet->execute($params);
        $allDetalles = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

        $detallesMap = [];
        foreach ($allDetalles as $d) {
            $detallesMap[$d['id_venta_cabecera']][] = $d;
        }

        // 4. Obtener desglose de métodos de pago para cada ticket (Para la tabla)
        $sqlTicketPagos = "SELECT id_venta_cabecera, metodo_pago FROM ventas_pagos WHERE id_venta_cabecera IN (SELECT id FROM ventas_cabecera v $filtroSql)";
        $stmtTP = $pdo->prepare($sqlTicketPagos);
        $stmtTP->execute($params);
        $ticketPagosData = $stmtTP->fetchAll(PDO::FETCH_ASSOC);
        $ticketPagosMap = [];
        foreach($ticketPagosData as $tp) {
            $ticketPagosMap[$tp['id_venta_cabecera']][] = $tp['metodo_pago'];
        }

        // 4. CONSULTA DE TOTALES DESGLOSADOS (La fuente de verdad)
        $sqlPagos = "SELECT metodo_pago, SUM(monto_ajustado) as total_metodo
                     FROM (
                        /* Caso A: Detalle de pagos mixtos/simples registrados */
                        SELECT p.metodo_pago, 
                               (p.monto * v.total / NULLIF((SELECT SUM(m2.monto) FROM ventas_pagos m2 WHERE m2.id_venta_cabecera = v.id), 0)) as monto_ajustado
                        FROM ventas_pagos p
                        JOIN ventas_cabecera v ON p.id_venta_cabecera = v.id
                        $filtroSql
                        
                        UNION ALL
                        
                        /* Caso B: Tickets antiguos/legacy sin desglose en ventas_pagos */
                        SELECT v.metodo_pago, 
                               v.total as monto_ajustado
                        FROM ventas_cabecera v
                        $filtroSql
                        AND NOT EXISTS (SELECT 1 FROM ventas_pagos p2 WHERE p2.id_venta_cabecera = v.id)
                     ) as desglose
                     GROUP BY metodo_pago";
        
        $paramsPagos = array_merge($params, $params);
        $stmtPagos = $pdo->prepare($sqlPagos);
        $stmtPagos->execute($paramsPagos);
        $resumenCrudo = $stmtPagos->fetchAll(PDO::FETCH_ASSOC);

        // NORMALIZACIÓN EN PHP (BUCKETS)
        $buckets = ['Efectivo' => 0, 'Transferencia' => 0, 'Tarjeta/Gasto' => 0];
        foreach ($resumenCrudo as $rp) {
            $m = strtolower($rp['metodo_pago']);
            $val = floatval($rp['total_metodo']);
            if (strpos($m, 'efectivo') !== false) $buckets['Efectivo'] += $val;
            elseif (strpos($m, 'transferencia') !== false) $buckets['Transferencia'] += $val;
            elseif (strpos($m, 'tarjeta') !== false || strpos($m, 'gasto') !== false) $buckets['Tarjeta/Gasto'] += $val;
            else $buckets['Efectivo'] += $val; // Fallback para etiquetas raras o Mixto sin desglose
        }

        // Calcular Totales Generales
        $totalVenta = 0; $totalDev = 0;
        foreach ($tickets as $t) {
            $monto = floatval($t['total']);
            if ($monto < 0) $totalDev += abs($monto);
            else $totalVenta += $monto;
        }
        $totalNeto = $totalVenta - $totalDev;

        // RENDERIZADO HTML
        ?>
        <div class="sticky-top bg-white shadow-sm border-bottom z-index-2 p-3">
            <div class="row g-2 align-items-stretch">
                <!-- Ventas Netas -->
                <div class="col-6 col-md-3">
                    <div class="card h-100 border-0 bg-light shadow-sm text-center p-2">
                        <div class="text-muted small fw-bold text-uppercase">Ventas Netas</div>
                        <div class="fs-4 fw-bold text-success">$<?php echo number_format($totalNeto, 2); ?></div>
                    </div>
                </div>
                <!-- Tickets -->
                <div class="col-6 col-md-2">
                    <div class="card h-100 border-0 bg-light shadow-sm text-center p-2">
                        <div class="text-muted small fw-bold text-uppercase">Tickets</div>
                        <div class="fs-4 fw-bold text-dark"><?php echo count($tickets); ?></div>
                    </div>
                </div>
                <!-- Devoluciones -->
                <div class="col-6 col-md-2">
                    <div class="card h-100 border-0 bg-light shadow-sm text-center p-2">
                        <div class="text-muted small fw-bold text-uppercase">Devoluciones</div>
                        <div class="fs-4 fw-bold text-danger">$<?php echo number_format($totalDev, 2); ?></div>
                    </div>
                </div>
                <!-- Resumen de Ingresos Reales (LISTA) -->
                <div class="col-6 col-md-5">
                    <div class="card h-100 border-0 shadow-sm rounded-3 overflow-hidden">
                        <div class="bg-dark text-white py-1 px-2 fw-bold text-uppercase text-center" style="font-size:0.6rem; letter-spacing:1px;">
                            <i class="fas fa-wallet me-1 text-warning"></i> Ingresos Reales
                        </div>
                        <div class="p-0">
                            <ul class="list-group list-group-flush small">
                                <?php foreach ($buckets as $nombre => $monto): 
                                    if ($monto <= 0) continue;
                                    $icon = 'fa-money-bill-wave text-success';
                                    $textClass = 'text-success';
                                    if ($nombre === 'Transferencia') { $icon = 'fa-university text-primary'; $textClass = 'text-primary'; }
                                    elseif ($nombre === 'Tarjeta/Gasto') { $icon = 'fa-credit-card text-warning'; $textClass = 'text-warning'; }
                                ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-1 px-3 border-0">
                                    <span class="fw-bold text-muted text-uppercase" style="font-size:0.65rem;">
                                        <i class="fas <?php echo $icon; ?> me-1"></i> <?php echo $nombre; ?>
                                    </span>
                                    <span class="fw-bold <?php echo $textClass; ?>" style="font-size:0.9rem;">$<?php echo number_format($monto, 2); ?></span>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table mb-0 align-middle" style="font-size:0.92rem;">
                <thead class="bg-light text-secondary">
                    <tr><th width="40"></th><th>ID</th><th>Hora</th><th>Cliente</th><th>Tipo</th><th>Total</th><th>Pago Real</th><th class="text-end">Acción</th></tr>
                </thead>
                <tbody>
                <?php if (empty($tickets)): ?>
                    <tr><td colspan="8" class="text-center p-5 text-muted"><i class="fas fa-receipt fa-2x mb-3 opacity-50"></i><br>Sin movimientos</td></tr>
                <?php else: ?>
                    <?php foreach ($tickets as $t): 
                        $total = floatval($t['total']);
                        $isRef = $total < 0;
                        
                        // Determinar etiquetas de pago para la tabla
                        $metodosReales = $ticketPagosMap[$t['id']] ?? [$t['metodo_pago']];
                        $metodosDisplay = implode(' + ', array_unique($metodosReales));

                        $rowClass = 'bg-white';
                        $badgeClass = 'bg-secondary';
                        
                        if ($isRef) { $rowClass = 'row-devolucion'; $badgeClass = 'bg-danger'; }
                        elseif (stripos($metodosDisplay, 'efectivo') !== false && count(array_unique($metodosReales)) == 1) { $rowClass = 'row-efectivo'; $badgeClass = 'bg-success'; }
                        elseif (stripos($metodosDisplay, 'transferencia') !== false && count(array_unique($metodosReales)) == 1) { $rowClass = 'row-transferencia'; $badgeClass = 'bg-primary'; }
                        else { $rowClass = 'row-mixto'; $badgeClass = 'bg-dark text-white'; }
                    ?>
                    <tr class="<?php echo $rowClass; ?> ticket-row" onclick="toggleDetail(<?php echo $t['id']; ?>)">
                        <td class="text-center"><i class="fas fa-chevron-right text-muted icon-collapse-<?php echo $t['id']; ?>"></i></td>
                        <td class="fw-bold">#<?php echo $t['id']; ?></td>
                        <td><?php echo date('H:i', strtotime($t['fecha'])); ?></td>
                        <td><?php echo htmlspecialchars($t['cliente_nombre'] ?: 'General'); ?></td>
                        <td><small class="text-uppercase text-muted fw-bold" style="font-size:0.65rem;"><?php echo $t['tipo_servicio']; ?></small></td>
                        <td class="fw-bold <?php echo $isRef ? 'text-danger' : 'text-dark'; ?>">$<?php echo number_format(abs($total), 2); ?></td>
                        <td><span class="badge <?php echo $badgeClass; ?> badge-pago" style="font-size:0.7rem"><?php echo $metodosDisplay; ?></span></td>
                        <td class="text-end" onclick="event.stopPropagation()">
                            <?php if (!$isRef): ?>
                                <button class="btn btn-sm btn-danger py-0 px-2 shadow-sm me-1" onclick="refundTicketComplete(<?php echo $t['id']; ?>)" title="Devolver Todo"><i class="fas fa-undo"></i></button>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-dark py-0 px-2 shadow-sm" onclick="window.open('ticket_view.php?id=<?php echo $t['id']; ?>','T','width=380,height=600')"><i class="fas fa-print"></i></button>
                        </td>
                    </tr>
                    <tr class="collapse bg-white" id="det-row-<?php echo $t['id']; ?>">
                        <td colspan="8" class="p-0 border-0">
                            <div class="p-3 border-bottom shadow-inner bg-light">
                                <table class="table table-sm table-borderless mb-0 small">
                                    <thead class="text-muted border-bottom"><tr><th>Producto</th><th class="text-end">Cant.</th><th class="text-end">Precio</th><th class="text-end">Subtotal</th><th class="text-end">Acción</th></tr></thead>
                                    <tbody>
                                    <?php 
                                    $items = $detallesMap[$t['id']] ?? [];
                                    if (empty($items)): ?>
                                        <tr><td colspan="5" class="text-center text-muted py-2">Sin detalles registrados</td></tr>
                                    <?php else: 
                                        foreach ($items as $item):
                                            $cant = floatval($item['cantidad']);
                                            $sub = $cant * floatval($item['precio']);
                                            $isItemRef = $cant < 0;
                                    ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($item['nombre_producto']); ?></td>
                                            <td class="text-end fw-bold <?php echo $isItemRef ? 'text-danger' : ''; ?>"><?php echo abs($cant); ?></td>
                                            <td class="text-end">$<?php echo number_format($item['precio'], 2); ?></td>
                                            <td class="text-end <?php echo $isItemRef ? 'text-danger' : ''; ?>">$<?php echo number_format(abs($sub), 2); ?></td>
                                            <td class="text-end">
                                                <?php if (!$isRef && !$isItemRef): ?>
                                                    <button class="btn btn-xs btn-outline-danger py-0 px-2" style="font-size: 0.65rem;" onclick="refundItemFromHistorial(<?php echo $item['id']; ?>, '<?php echo addslashes($item['nombre_producto']); ?>')">DEVOLVER</button>
                                                <?php else: ?>
                                                    <span class="text-muted small">-</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                    </tbody>
                                </table>
                                <?php if (!empty($t['mensajero_nombre'])): ?>
                                    <div class="mt-2 small alert alert-info py-1 px-2 mb-0"><i class="fas fa-motorcycle me-1"></i> Mensajero: <strong><?php echo $t['mensajero_nombre']; ?></strong></div>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        exit;
    } catch (Exception $e) {
        echo '<div class="p-5 text-center text-danger"><i class="fas fa-exclamation-circle fa-2x mb-2"></i><br>Error: ' . $e->getMessage() . '</div>';
        exit;
    }
}
?>

<div class="modal fade" id="historialModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-info text-white py-2 shadow-sm">
                <h5 class="modal-title fw-bold"><i class="fas fa-history me-2"></i>Historial de Tickets</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" id="historialModalBody">
                <div class="text-center p-5">
                    <i class="fas fa-circle-notch fa-spin fa-3x text-muted mb-3"></i>
                    <p class="text-muted">Iniciando historial...</p>
                </div>
            </div>
            <div class="modal-footer py-1 bg-light">
                <small class="text-muted ms-auto">Datos en tiempo real</small>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<style>

    /* Colores por tipo de pago mejorados */

    .row-efectivo { background-color: #f0fff4 !important; border-left: 5px solid #28a745 !important; }      

    .row-transferencia { background-color: #f0f7ff !important; border-left: 5px solid #0d6efd !important; } 

    .row-mixto { background-color: #f8f9fa !important; border-left: 5px solid #6c757d !important; }         

    .row-devolucion { background-color: #fff5f5 !important; border-left: 5px solid #dc3545 !important; }    

    

    .ticket-row { transition: all 0.2s; border-bottom: 1px solid #eee; }

    .ticket-row:hover { filter: brightness(0.98); cursor: pointer; }

    .badge-pago { min-width: 90px; }

    .z-index-2 { z-index: 1020; }

</style>



<!-- Script eliminado de aquí porque ahora reside en pos1.js para compatibilidad AJAX -->
