<?php
// ARCHIVO: /var/www/palweb/api/pos_production_report.php
// REPORTE DE PRODUCCIÓN - ESTILO FICHA TÉCNICA CON COSTOS INDIRECTOS

ini_set('display_errors', 0);
require_once 'db.php';

// Cargar Config
require_once 'config_loader.php';
$ALM_ID = intval($config['id_almacen']);
$EMP_ID = intval($config['id_empresa']);

// Parámetros de Costo Indirecto (Por defecto 0 si no existen)
$pctSalario = floatval($config['salario_elaborador_pct'] ?? 0);
$pctReserva = floatval($config['reserva_negocio_pct'] ?? 0);
$pctDepre   = floatval($config['depreciacion_equipos_pct'] ?? 0);

$idReceta = $_GET['id'] ?? 0;

try {
    // 1. Cabecera
    $stmt = $pdo->prepare("SELECT r.*, p.nombre as nombre_prod, p.precio as precio_venta, p.codigo as sku_final 
                           FROM recetas_cabecera r 
                           LEFT JOIN productos p ON r.id_producto_final = p.codigo 
                           WHERE r.id = ?");
    $stmt->execute([$idReceta]);
    $receta = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$receta) die("Receta no encontrada.");

    // 2. Detalles + Stock en Tiempo Real
    $sqlDet = "SELECT d.*, p.nombre, p.unidad_medida, p.costo as costo_actual,
               (SELECT COALESCE(SUM(s.cantidad), 0) FROM stock_almacen s WHERE s.id_producto = d.id_ingrediente AND s.id_almacen = ?) as stock_real
               FROM recetas_detalle d 
               LEFT JOIN productos p ON d.id_ingrediente = p.codigo 
               WHERE d.id_receta = ?";
    $stmtD = $pdo->prepare($sqlDet);
    $stmtD->execute([$ALM_ID, $idReceta]);
    $detalles = $stmtD->fetchAll(PDO::FETCH_ASSOC);

    // Cálculos Materia Prima
    $costoTotal = 0;
    $totalCantidadFormula = 0; // Este es el $totalCantidadConsolidado solicitado
    $maxLotesPosibles = 999999;

    // Loop pre-cálculo para obtener el total consolidado de la fórmula
    foreach ($detalles as $d) {
        $costoTotal           += $d['cantidad'] * $d['costo_actual'];
        $totalCantidadFormula += floatval($d['cantidad']);

        // Calcular para cuántos lotes alcanza este ingrediente
        $lotesPosibles = ($d['cantidad'] > 0) ? ($d['stock_real'] / $d['cantidad']) : 0;
        if ($lotesPosibles < $maxLotesPosibles) $maxLotesPosibles = $lotesPosibles;
    }
    
    // Si no hay ingredientes, no se puede producir
    if(empty($detalles)) $maxLotesPosibles = 0;
    $maxLotesPosibles = floor($maxLotesPosibles);

    // --- CÁLCULOS DE RENTABILIDAD AVANZADA ---
    $precioVenta = floatval($receta['precio_venta']);
    $costoUnitarioMP = floatval($receta['costo_unitario']);
    $utilidadBruta = $precioVenta - $costoUnitarioMP;
    $margenBruto = ($precioVenta > 0) ? ($utilidadBruta / $precioVenta) * 100 : 0;

    // Calcular Costos Indirectos por Unidad (basado en % del precio venta)
    $costoSalario = $precioVenta * ($pctSalario / 100);
    $costoReserva = $precioVenta * ($pctReserva / 100);
    $costoDepre   = $precioVenta * ($pctDepre / 100);
    
    $totalIndirectos = $costoSalario + $costoReserva + $costoDepre;
    $utilidadNeta = $utilidadBruta - $totalIndirectos;
    $margenNeto = ($precioVenta > 0) ? ($utilidadNeta / $precioVenta) * 100 : 0;

    // 3. Reservas pendientes que necesitan el producto final de esta receta
    $stmtRes = $pdo->prepare("
        SELECT vc.id, vc.cliente_nombre, vc.cliente_telefono, vc.fecha_reserva,
               vd.cantidad AS cant_reservada,
               COALESCE(vc.canal_origen, 'POS') AS canal_origen,
               COALESCE(vc.estado_reserva, 'PENDIENTE') AS estado_reserva
        FROM ventas_cabecera vc
        JOIN ventas_detalle vd ON vd.id_venta_cabecera = vc.id
        WHERE vd.id_producto = ?
          AND vc.tipo_servicio = 'reserva'
          AND (vc.estado_reserva = 'PENDIENTE' OR vc.estado_reserva IS NULL)
        ORDER BY vc.fecha_reserva ASC
        LIMIT 50");
    $stmtRes->execute([$receta['id_producto_final']]);
    $reservasPendientes = $stmtRes->fetchAll(PDO::FETCH_ASSOC);
    $totalReservado = array_sum(array_column($reservasPendientes, 'cant_reservada'));

} catch (Exception $e) { die("Error: " . $e->getMessage()); }

$canalMap = [
    'Web'        => ['#0ea5e9', '🌐', 'Web'],
    'POS'        => ['#6366f1', '🖥️', 'POS'],
    'WhatsApp'   => ['#22c55e', '💬', 'WhatsApp'],
    'Teléfono'   => ['#f59e0b', '📞', 'Teléfono'],
    'Kiosko'     => ['#8b5cf6', '📱', 'Kiosko'],
    'Presencial' => ['#475569', '🙋', 'Presencial'],
    'ICS'        => ['#94a3b8', '📥', 'Importado'],
    'Otro'       => ['#94a3b8', '❓', 'Otro'],
];
function getCanalBadgePR($canal, $map) {
    [$bg, $emoji, $label] = $map[$canal] ?? $map['Otro'];
    return "<span style=\"display:inline-flex;align-items:center;gap:4px;background-color:{$bg}!important;color:white!important;padding:2px 9px;border-radius:20px;font-size:10px;font-weight:700;white-space:nowrap;print-color-adjust:exact;-webkit-print-color-adjust:exact;\">{$emoji} {$label}</span>";
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Ficha Técnica #<?php echo $idReceta; ?></title>
    <style>
        body { font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; color: #333; font-size: 12px; margin: 30px; background: #fff; }
        .header { display: flex; justify-content: space-between; border-bottom: 3px solid #2F75B5; padding-bottom: 10px; margin-bottom: 20px; }
        .title { font-size: 24px; color: #2F75B5; font-weight: bold; text-transform: uppercase; }
        .subtitle { font-size: 14px; color: #666; margin-top: 5px; }
        .card-container { display: flex; gap: 15px; margin-bottom: 20px; }
        .card { flex: 1; background: #f8f9fa; border: 1px solid #ddd; border-radius: 5px; padding: 15px; text-align: center; }
        .card-val { font-size: 18px; font-weight: bold; color: #2F75B5; }
        .card-lbl { font-size: 11px; color: #666; text-transform: uppercase; font-weight: 600; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th { background-color: #2F75B5; color: white; padding: 10px; text-align: left; text-transform: uppercase; font-size: 11px; }
        td { padding: 8px; border-bottom: 1px solid #eee; }
        .right { text-align: right; }
        .center { text-align: center; }
        .total-row { background-color: #e9ecef; font-weight: bold; font-size: 14px; }
        .bar-container { background-color: #e9ecef; border-radius: 3px; height: 8px; width: 60px; display: inline-block; margin-right: 5px; }
        .bar-fill { height: 100%; border-radius: 3px; }
        .stock-ok { color: green; font-weight: bold; }
        .stock-low { color: orange; font-weight: bold; }
        .stock-crit { color: red; font-weight: bold; background: #ffe6e6; padding: 5px; border-radius: 4px; }
        
        .rent-table td { border: none; padding: 4px 0; }
        .rent-row-sub { color: #666; font-size: 11px; border-bottom: 1px dashed #eee !important; }
        .rent-val-neg { color: #d9534f; }
        .final-profit { font-size: 16px; font-weight: bold; border-top: 2px solid #333; padding-top: 8px; margin-top: 8px; }
        
        .reservas-section { margin-top: 30px; }
        .reservas-section h3 { color: #2F75B5; border-bottom: 1px solid #ccc; padding-bottom: 5px; margin-bottom: 12px; }
        .reservas-section table th { background-color: #374151; }
        .canal-cell { white-space: nowrap; }
        .sin-reservas { color: #888; font-style: italic; padding: 12px; background: #f9f9f9; border: 1px dashed #ddd; border-radius: 4px; text-align: center; }
        .total-reservado { background: #fff3cd; font-weight: bold; }
        @media print {
            .no-print { display: none; }
            body { margin: 0; }
            * { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        }
    </style>
</head>
<body>

    <div class="no-print" style="margin-bottom: 20px;">
        <button onclick="window.print()" style="padding: 10px 20px; background: #2F75B5; color: white; border: none; border-radius: 5px; cursor: pointer; font-weight: bold;">🖨️ IMPRIMIR REPORTE</button>
        <button onclick="window.close()" style="padding: 10px 20px; background: #666; color: white; border: none; border-radius: 5px; cursor: pointer; margin-left: 10px;">CERRAR</button>
    </div>

    <div class="header">
        <div>
            <div class="title"><?php echo htmlspecialchars($receta['nombre_receta']); ?></div>
            <div class="subtitle">Producto Final: <strong><?php echo htmlspecialchars($receta['nombre_prod']); ?></strong></div>
        </div>
        <div class="right">
            <div style="font-size: 20px; font-weight: bold; color: #444;">ID: <?php echo $idReceta; ?></div>
            <div><?php echo date('d/m/Y H:i'); ?></div>
        </div>
    </div>

    <div class="card-container">
        <div class="card">
            <div class="card-val"><?php echo floatval($receta['unidades_resultantes']); ?></div>
            <div class="card-lbl">Unidades por Lote</div>
        </div>
        <div class="card">
            <div class="card-val">$<?php echo number_format($costoTotal, 2); ?></div>
            <div class="card-lbl">Costo MP Lote</div>
        </div>
        <div class="card">
            <div class="card-val">$<?php echo number_format($receta['costo_unitario'], 2); ?></div>
            <div class="card-lbl">Costo Unitario MP</div>
        </div>
        <div class="card" style="border-color: #2F75B5; background: #f0f7ff;">
            <div class="card-val"><?php echo $maxLotesPosibles; ?></div>
            <div class="card-lbl">Lotes Disponibles (Stock)</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Ingrediente / Insumo</th>
                <th class="center">Requerido (Lote)</th>
                <th class="center">Stock Actual</th>
                <th class="right">Costo Unit.</th>
                <th class="right">Subtotal</th>
                <th class="center">% Fórmula</th>
                <th class="center">Cobertura</th>
                <th class="center">Estado</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($detalles as $d): 
                $req = floatval($d['cantidad']);
                $stock = floatval($d['stock_real']);
                $percent = ($req > 0) ? ($stock / $req) * 100 : 0;
                if ($percent > 100) $percent = 100;
                $colorBar = ($stock >= $req) ? '#2F75B5' : '#dc3545';
                $status = ($stock >= $req) ? '<span class="stock-ok">OK</span>' : '<span class="stock-crit">FALTA</span>';
            ?>
            <tr>
                <td><?php echo htmlspecialchars($d['nombre']); ?> <span style="color:#888; font-size:10px;">(<?php echo $d['id_ingrediente']; ?>)</span></td>
                <td class="center"><b><?php echo $req; ?></b> <?php echo $d['unidad_medida']; ?></td>
                <td class="center"><?php echo $stock; ?></td>
                <td class="right">$<?php echo number_format($d['costo_actual'], 2); ?></td>
                <td class="right">$<?php echo number_format($req * $d['costo_actual'], 2); ?></td>
                <td class="center"><?php
                    // Cálculo basado en el peso del insumo sobre el total consolidado
                    $pctFormula = ($totalCantidadFormula > 0) ? ($req / $totalCantidadFormula) * 100 : 0;
                    $barPct = min($pctFormula, 100);
                    echo '<strong style="color:#1a5fa8;">' . number_format($pctFormula, 1) . '%</strong>';
                    echo '<div style="background:#e9ecef;border-radius:3px;height:5px;width:50px;display:inline-block;margin-left:5px;vertical-align:middle;">';
                    echo '<div style="height:100%;border-radius:3px;width:' . $barPct . '%;background:#2F75B5;"></div></div>';
                ?></td>
                <td class="center">
                    <div class="bar-container"><div class="bar-fill" style="width:<?php echo $percent; ?>%; background-color:<?php echo $colorBar; ?>;"></div></div>
                </td>
                <td class="center"><?php echo $status; ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="4" class="right">COSTO TOTAL MATERIA PRIMA (LOTE):</td>
                <td class="right">$<?php echo number_format($costoTotal, 2); ?></td>
                <td class="center"><strong>100.0%</strong></td>
                <td colspan="2"></td>
            </tr>
        </tbody>
    </table>

    <div style="display:flex; gap:30px;">
        <div style="flex:1;">
            <h3 style="color:#2F75B5; border-bottom:1px solid #ccc; padding-bottom:5px;">Análisis de Rentabilidad (Por Unidad)</h3>
            <table class="rent-table" style="width:100%; border:none;">
                <tr>
                    <td>Precio de Venta Sugerido:</td>
                    <td class="right" style="font-size:14px;"><b>$<?php echo number_format($precioVenta, 2); ?></b></td>
                </tr>
                <tr>
                    <td>(-) Costo Materia Prima:</td>
                    <td class="right rent-val-neg">-$<?php echo number_format($costoUnitarioMP, 2); ?></td>
                </tr>
                <tr style="background:#f0f0f0;">
                    <td><strong>= Utilidad Bruta:</strong></td>
                    <td class="right"><strong>$<?php echo number_format($utilidadBruta, 2); ?></strong> <small>(<?php echo number_format($margenBruto, 1); ?>%)</small></td>
                </tr>
                
                <tr><td colspan="2" style="padding-top:10px; font-weight:bold; font-size:11px; text-transform:uppercase; color:#666;">Deducciones / Costos Indirectos</td></tr>
                
                <tr class="rent-row-sub">
                    <td>(-) Salario Elaborador (<?php echo $pctSalario; ?>%):</td>
                    <td class="right rent-val-neg">-$<?php echo number_format($costoSalario, 2); ?></td>
                </tr>
                <tr class="rent-row-sub">
                    <td>(-) Reserva Negocio (<?php echo $pctReserva; ?>%):</td>
                    <td class="right rent-val-neg">-$<?php echo number_format($costoReserva, 2); ?></td>
                </tr>
                <tr class="rent-row-sub">
                    <td>(-) Depreciación Equipos (<?php echo $pctDepre; ?>%):</td>
                    <td class="right rent-val-neg">-$<?php echo number_format($costoDepre, 2); ?></td>
                </tr>

                <tr>
                    <td style="vertical-align:bottom; padding-top:10px;"><strong>= GANANCIA NETA (LIMPIA):</strong></td>
                    <td class="right">
                        <div class="final-profit" style="color:<?php echo $utilidadNeta>=0?'#198754':'#dc3545'; ?>;">
                            $<?php echo number_format($utilidadNeta, 2); ?>
                        </div>
                        <small style="color:<?php echo $utilidadNeta>=0?'#198754':'#dc3545'; ?>;">Margen Neto: <?php echo number_format($margenNeto, 1); ?>%</small>
                    </td>
                </tr>
            </table>
        </div>
        
        <div style="flex:1;">
            <h3 style="color:#2F75B5; border-bottom:1px solid #ccc; padding-bottom:5px;">Instrucciones de Elaboración</h3>
            <div style="padding:15px; background:#f9f9f9; border:1px solid #eee; border-radius:5px; min-height:150px; line-height:1.6;">
                <?php echo nl2br(htmlspecialchars($receta['descripcion'])); ?>
            </div>
        </div>
    </div>

    <!-- ── Reservas pendientes que esperan este producto ───────────────────── -->
    <div class="reservas-section">
        <h3>
            📋 Reservas Pendientes que Requieren «<?php echo htmlspecialchars($receta['nombre_prod']); ?>»
            <?php if ($totalReservado > 0): ?>
                <span style="font-size:13px;color:#dc2626;margin-left:10px;">
                    — Total comprometido: <strong><?php echo number_format($totalReservado, 2); ?> u.</strong>
                </span>
            <?php endif; ?>
        </h3>

        <?php if (empty($reservasPendientes)): ?>
            <div class="sin-reservas">✓ No hay reservas pendientes que requieran este producto.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>#Reserva</th>
                        <th>Cliente</th>
                        <th>Teléfono</th>
                        <th class="center">Fecha Entrega</th>
                        <th class="center">Cant. Reservada</th>
                        <th class="center">Canal Origen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $now = new DateTime();
                    foreach ($reservasPendientes as $res):
                        $fechaRes = $res['fecha_reserva'] ? new DateTime($res['fecha_reserva']) : null;
                        $esHoy    = $fechaRes && $fechaRes->format('Y-m-d') === $now->format('Y-m-d');
                        $vencida  = $fechaRes && $now > $fechaRes && !$esHoy;
                        $rowStyle = $vencida ? 'background:#fff1f0;' : ($esHoy ? 'background:#fffbeb;' : '');
                    ?>
                    <tr style="<?php echo $rowStyle; ?>">
                        <td class="center fw-bold">#<?php echo $res['id']; ?></td>
                        <td><?php echo htmlspecialchars($res['cliente_nombre']); ?></td>
                        <td style="color:#64748b;"><?php echo htmlspecialchars($res['cliente_telefono'] ?: '—'); ?></td>
                        <td class="center">
                            <?php if ($fechaRes): ?>
                                <?php echo $fechaRes->format('d/m/Y H:i'); ?>
                                <?php if ($esHoy): ?>
                                    <span style="background:#f59e0b;color:white;padding:1px 6px;border-radius:10px;font-size:9px;font-weight:bold;margin-left:4px;">HOY</span>
                                <?php elseif ($vencida): ?>
                                    <span style="background:#dc2626;color:white;padding:1px 6px;border-radius:10px;font-size:9px;font-weight:bold;margin-left:4px;">VENCIDA</span>
                                <?php endif; ?>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="center"><strong><?php echo number_format(floatval($res['cant_reservada']), 2); ?></strong></td>
                        <td class="center canal-cell"><?php echo getCanalBadgePR($res['canal_origen'], $canalMap); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="total-reservado">
                        <td colspan="4" class="right">TOTAL COMPROMETIDO EN RESERVAS:</td>
                        <td class="center"><?php echo number_format($totalReservado, 2); ?> u.</td>
                        <td></td>
                    </tr>
                    <?php
                    $stockDisponible = 0;
                    foreach ($detalles as $d) { /* ya tenemos maxLotesPosibles */ }
                    $unidadesProducibles = $maxLotesPosibles * floatval($receta['unidades_resultantes']);
                    $deficit = max(0, $totalReservado - $unidadesProducibles);
                    ?>
                    <?php if ($deficit > 0): ?>
                    <tr style="background:#fef2f2;">
                        <td colspan="4" class="right" style="color:#dc2626;font-weight:bold;">⚠ DÉFICIT (reservado - producible con stock actual):</td>
                        <td class="center" style="color:#dc2626;font-weight:bold;"><?php echo number_format($deficit, 2); ?> u.</td>
                        <td></td>
                    </tr>
                    <?php else: ?>
                    <tr style="background:#f0fdf4;">
                        <td colspan="4" class="right" style="color:#166534;font-weight:bold;">✓ Stock suficiente para cubrir todas las reservas pendientes</td>
                        <td colspan="2"></td>
                    </tr>
                    <?php endif; ?>
                </tfoot>
            </table>
        <?php endif; ?>
    </div>

<?php include_once 'menu_master.php'; ?>
</body>
</html>

