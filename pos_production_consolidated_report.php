<?php
// ARCHIVO: /var/www/palweb/api/pos_production_consolidated_report.php
// REPORTE CONSOLIDADO DE INSUMOS PARA MÚLTIPLES RECETAS

ini_set('display_errors', 0);
require_once 'db.php';

// Cargar Config
require_once 'config_loader.php';
$ALM_ID = intval($config['id_almacen']);

$ids = $_GET['ids'] ?? '';
if (empty($ids)) die("No se seleccionaron recetas.");

// Validar IDs (seguridad básica)
$idArray = explode(',', $ids);
$idArray = array_map('intval', $idArray);
$idList = implode(',', $idArray);

try {
    // 1. Obtener nombres de las recetas seleccionadas
    $stmtNames = $pdo->query("SELECT nombre_receta FROM recetas_cabecera WHERE id IN ($idList)");
    $recetasNombres = $stmtNames->fetchAll(PDO::FETCH_COLUMN);

    // 2. Consulta Maestra: Agrupar ingredientes de todas las recetas
    $sql = "SELECT 
                p.codigo, 
                p.nombre, 
                p.unidad_medida, 
                p.costo as costo_actual,
                SUM(rd.cantidad) as cantidad_requerida_total,
                (SELECT COALESCE(SUM(s.cantidad), 0) FROM stock_almacen s WHERE s.id_producto = rd.id_ingrediente AND s.id_almacen = $ALM_ID) as stock_actual
            FROM recetas_detalle rd
            JOIN productos p ON rd.id_ingrediente = p.codigo
            WHERE rd.id_receta IN ($idList)
            GROUP BY rd.id_ingrediente
            ORDER BY p.nombre ASC";
    
    $stmt = $pdo->query($sql);
    $insumos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Pre-calcular totales para mostrar % por peso y costo
    $costoTotalPlanificacion  = 0;
    $totalCantidadConsolidado = 0;
    foreach ($insumos as $i) {
        $costoTotalPlanificacion  += floatval($i['cantidad_requerida_total']) * floatval($i['costo_actual']);
        $totalCantidadConsolidado += floatval($i['cantidad_requerida_total']);
    }

    // 3. Desglose detallado por receta (con pct_formula)
    $sqlDesglose = "SELECT
                rc.id            AS receta_id,
                rc.nombre_receta,
                rc.unidades_resultantes,
                rc.costo_total_lote,
                rc.costo_unitario,
                rd.id_ingrediente,
                rd.cantidad,
                COALESCE(rd.pct_formula, 0) AS pct_formula,
                COALESCE(p.nombre, 'ITEM BORRADO')      AS nombre_ingrediente,
                COALESCE(p.unidad_medida, 'U')          AS unidad_medida,
                COALESCE(p.costo, 0)                    AS costo_actual,
                COALESCE(pf.nombre, '')                 AS nombre_producto_final
            FROM recetas_cabecera rc
            JOIN recetas_detalle rd ON rc.id = rd.id_receta
            LEFT JOIN productos p  ON rd.id_ingrediente = p.codigo
            LEFT JOIN productos pf ON rc.id_producto_final = pf.codigo
            WHERE rc.id IN ($idList)
            ORDER BY FIELD(rc.id, $idList), p.nombre ASC";

    $desgloseRows = $pdo->query($sqlDesglose)->fetchAll(PDO::FETCH_ASSOC);

    // Agrupar por receta manteniendo el orden original
    $desgloseByReceta = [];
    foreach ($desgloseRows as $row) {
        $rid = $row['receta_id'];
        if (!isset($desgloseByReceta[$rid])) {
            $desgloseByReceta[$rid] = [
                'nombre_receta'       => $row['nombre_receta'],
                'nombre_producto_final' => $row['nombre_producto_final'],
                'unidades_resultantes'=> floatval($row['unidades_resultantes']),
                'costo_total_lote'    => floatval($row['costo_total_lote']),
                'costo_unitario'      => floatval($row['costo_unitario']),
                'ingredientes'        => [],
            ];
        }
        $desgloseByReceta[$rid]['ingredientes'][] = $row;
    }

    // 4. Reservas pendientes por cada producto final de las recetas seleccionadas
    $sqlReservas = "
        SELECT
            rc.id            AS receta_id,
            rc.nombre_receta,
            rc.id_producto_final,
            COALESCE(pf.nombre, rc.id_producto_final) AS nombre_producto_final,
            vc.id            AS reserva_id,
            vc.cliente_nombre,
            vc.cliente_telefono,
            vc.fecha_reserva,
            vd.cantidad      AS cant_reservada,
            COALESCE(vc.canal_origen, 'POS') AS canal_origen
        FROM recetas_cabecera rc
        JOIN productos pf ON pf.codigo = rc.id_producto_final
        JOIN ventas_detalle vd ON vd.id_producto = rc.id_producto_final
        JOIN ventas_cabecera vc ON vc.id = vd.id_venta_cabecera
        WHERE rc.id IN ($idList)
          AND vc.tipo_servicio = 'reserva'
          AND (vc.estado_reserva = 'PENDIENTE' OR vc.estado_reserva IS NULL)
        ORDER BY rc.id ASC, vc.fecha_reserva ASC";

    $reservasByReceta = [];   // [receta_id => ['nombre_producto_final', 'rows' => [...], 'total' => n]]
    foreach ($pdo->query($sqlReservas)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rid = $row['receta_id'];
        if (!isset($reservasByReceta[$rid])) {
            $reservasByReceta[$rid] = [
                'nombre_producto_final' => $row['nombre_producto_final'],
                'rows'  => [],
                'total' => 0,
            ];
        }
        $reservasByReceta[$rid]['rows'][]  = $row;
        $reservasByReceta[$rid]['total']  += floatval($row['cant_reservada']);
    }

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
function getCanalBadgeCR($canal, $map) {
    [$bg, $emoji, $label] = $map[$canal] ?? $map['Otro'];
    return "<span style=\"display:inline-flex;align-items:center;gap:4px;"
         . "background-color:{$bg}!important;color:white!important;"
         . "padding:2px 9px;border-radius:20px;font-size:10px;font-weight:700;"
         . "white-space:nowrap;print-color-adjust:exact;-webkit-print-color-adjust:exact;\">"
         . "{$emoji} {$label}</span>";
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Reporte Consolidado de Insumos</title>
    <style>
        body { font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; color: #333; font-size: 12px; margin: 30px; background: #fff; }
        .header { border-bottom: 3px solid #ffc107; padding-bottom: 10px; margin-bottom: 20px; }
        .title { font-size: 22px; color: #333; font-weight: bold; text-transform: uppercase; }
        .subtitle { font-size: 12px; color: #666; margin-top: 5px; font-style: italic; }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th { background-color: #333; color: white; padding: 8px; text-align: left; text-transform: uppercase; font-size: 11px; }
        td { padding: 8px; border-bottom: 1px solid #eee; }
        
        .right { text-align: right; }
        .center { text-align: center; }
        .total-row { background-color: #f8f9fa; font-weight: bold; font-size: 14px; border-top: 2px solid #333; }
        
        .status-ok { color: green; font-weight: bold; background: #e6fffa; padding: 2px 6px; border-radius: 4px; }
        .status-alert { color: #d9534f; font-weight: bold; background: #ffe6e6; padding: 2px 6px; border-radius: 4px; }
        
        .bar-container { background-color: #e9ecef; border-radius: 3px; height: 6px; width: 50px; display: inline-block; margin-right: 5px; vertical-align: middle; }
        .bar-fill { height: 100%; border-radius: 3px; }

        .recipe-tags { margin-bottom: 15px; }
        .tag { display: inline-block; background: #e9ecef; color: #555; padding: 2px 8px; border-radius: 12px; font-size: 11px; margin-right: 5px; margin-bottom: 5px; border: 1px solid #ddd; }

        /* Desglose por receta */
        .section-title { font-size: 16px; font-weight: bold; color: #333; text-transform: uppercase; border-bottom: 3px solid #ffc107; padding-bottom: 6px; margin: 30px 0 18px; }
        .recipe-block { margin-bottom: 28px; border: 1px solid #ddd; border-radius: 6px; overflow: hidden; page-break-inside: avoid; }
        .recipe-block-header { background: #1e293b; color: #fff; padding: 10px 15px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
        .recipe-block-header .rname { font-size: 14px; font-weight: bold; }
        .recipe-block-header .rmeta { font-size: 11px; color: #94a3b8; }
        .recipe-block-header .rstat { display: flex; gap: 18px; }
        .recipe-block-header .rstat span { font-size: 11px; text-align: center; }
        .recipe-block-header .rstat strong { display: block; font-size: 15px; color: #fff; }
        .det-table { width: 100%; border-collapse: collapse; }
        .det-table th { background: #f1f5f9; color: #475569; padding: 7px 10px; text-align: left; font-size: 10px; text-transform: uppercase; border-bottom: 1px solid #ddd; }
        .det-table td { padding: 7px 10px; border-bottom: 1px solid #f1f5f9; font-size: 12px; }
        .det-table tr:last-child td { border-bottom: none; }
        .det-table .total-det { background: #f8f9fa; font-weight: bold; border-top: 2px solid #ddd; }
        .pct-bar-wrap { display: inline-flex; align-items: center; gap: 4px; }
        .pct-mini-bar { background: #e9ecef; border-radius: 2px; height: 6px; display: inline-block; vertical-align: middle; }
        .pct-mini-fill { height: 100%; border-radius: 2px; background: #2F75B5; }
        .badge-pct { display: inline-block; min-width: 42px; text-align: right; font-weight: bold; color: #1a5fa8; }
        .badge-pct-calc { color: #888; font-style: italic; }

        .res-section-title { font-size:16px; font-weight:bold; color:#333; text-transform:uppercase;
            border-bottom:3px solid #22c55e; padding-bottom:6px; margin:34px 0 18px; }
        .res-block { margin-bottom:24px; border:1px solid #ddd; border-radius:6px; overflow:hidden; page-break-inside:avoid; }
        .res-block-header { background:#14532d; color:#fff; padding:9px 15px;
            display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap; }
        .res-block-header .rbn { font-size:13px; font-weight:bold; }
        .res-block-header .rbs { font-size:11px; color:#86efac; }
        .res-table { width:100%; border-collapse:collapse; }
        .res-table th { background:#f1f5f9; color:#475569; padding:6px 10px;
            font-size:10px; text-transform:uppercase; border-bottom:1px solid #ddd; text-align:left; }
        .res-table td { padding:7px 10px; border-bottom:1px solid #f1f5f9; font-size:11px; }
        .res-table tr:last-child td { border-bottom:none; }
        .res-total-row { background:#f8f9fa; font-weight:bold; border-top:2px solid #ddd; }
        .res-deficit-row { background:#fef2f2; color:#dc2626; font-weight:bold; }
        .res-ok-row     { background:#f0fdf4; color:#166534; font-weight:bold; }
        .badge-res-count { display:inline-flex; align-items:center; gap:4px;
            background:#22c55e; color:white; padding:2px 8px; border-radius:10px;
            font-size:10px; font-weight:700; white-space:nowrap; }
        .sin-reservas-cr { color:#888; font-style:italic; padding:12px 15px;
            background:#f9f9f9; font-size:11px; }
        @media print {
            .no-print { display: none; }
            body { margin: 0; }
            .recipe-block { page-break-inside: avoid; }
            * { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        }
    </style>
</head>
<body>

    <div class="no-print" style="margin-bottom: 20px;">
        <button onclick="window.print()" style="padding: 10px 20px; background: #333; color: white; border: none; border-radius: 5px; cursor: pointer; font-weight: bold;">🖨️ IMPRIMIR</button>
        <button onclick="window.close()" style="padding: 10px 20px; background: #ccc; color: black; border: none; border-radius: 5px; cursor: pointer; margin-left: 10px;">CERRAR</button>
    </div>

    <div class="header">
        <div class="title">Planificación de Insumos (1 Lote c/u)</div>
        <div class="subtitle">Análisis de stock para <?php echo count($idArray); ?> recetas seleccionadas.</div>
        <div style="margin-top:5px; font-size:11px;">Fecha: <?php echo date('d/m/Y H:i'); ?> | Almacén ID: <?php echo $ALM_ID; ?></div>
    </div>

    <div class="recipe-tags">
        <strong>Recetas incluidas:</strong><br>
        <?php foreach($recetasNombres as $nombre): ?>
            <span class="tag"><?php echo htmlspecialchars($nombre); ?></span>
        <?php endforeach; ?>
    </div>

    <table>
        <thead>
            <tr>
                <th>Insumo / Materia Prima</th>
                <th class="center">Requerido Total</th>
                <th class="center">Stock Disponible</th>
                <th class="center">Diferencia</th>
                <th class="right">Costo Estimado</th>
                <th class="center">% Fórmula</th>
                <th class="center">Estado</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($insumos as $i):
                $req      = floatval($i['cantidad_requerida_total']);
                $stock    = floatval($i['stock_actual']);
                $diff     = $stock - $req;
                $costoLinea = $req * floatval($i['costo_actual']);

                $pctCosto = ($totalCantidadConsolidado > 0) ? ($req / $totalCantidadConsolidado) * 100 : 0;

                $percent = ($req > 0) ? ($stock / $req) * 100 : 0;
                if($percent > 100) $percent = 100;

                $barColor = ($diff >= 0) ? '#198754' : '#dc3545';
                $status = ($diff >= 0) ? '<span class="status-ok">SUFICIENTE</span>' : '<span class="status-alert">FALTA ' . abs(round($diff, 2)) . '</span>';
            ?>
            <tr>
                <td>
                    <b><?php echo htmlspecialchars($i['nombre']); ?></b>
                    <br><span style="color:#888; font-size:10px;"><?php echo $i['codigo']; ?></span>
                </td>
                <td class="center" style="background:#fffbe6;">
                    <b><?php echo number_format($req, 2); ?></b> <?php echo $i['unidad_medida']; ?>
                </td>
                <td class="center"><?php echo number_format($stock, 2); ?></td>
                <td class="center" style="color: <?php echo $diff < 0 ? 'red' : 'green'; ?>;">
                    <?php echo ($diff > 0 ? '+' : '') . number_format($diff, 2); ?>
                </td>
                <td class="right">$<?php echo number_format($costoLinea, 2); ?></td>
                <td class="center">
                    <strong style="color:#1a5fa8;"><?php echo number_format($pctCosto, 1); ?>%</strong>
                    <div class="bar-container" style="width:60px;">
                        <div class="bar-fill" style="width:<?php echo min($pctCosto, 100); ?>%; background-color:#2F75B5;"></div>
                    </div>
                </td>
                <td class="center">
                    <div class="bar-container"><div class="bar-fill" style="width:<?php echo $percent; ?>%; background-color:<?php echo $barColor; ?>;"></div></div>
                    <?php echo $status; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            
            <tr class="total-row">
                <td colspan="4" class="right">COSTO TOTAL MATERIA PRIMA REQUERIDA:</td>
                <td class="right">$<?php echo number_format($costoTotalPlanificacion, 2); ?></td>
                <td class="center">100%</td>
                <td></td>
            </tr>
        </tbody>
    </table>

    <div style="background: #f8f9fa; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
        <strong>Nota:</strong> Este reporte asume la producción de 1 lote de cada receta seleccionada. Si desea producir múltiples lotes, multiplique los requerimientos manualmente o ajuste la planificación.
    </div>

    <!-- ===== DESGLOSE POR RECETA ===== -->
    <div class="section-title">Desglose por Receta</div>

    <?php foreach ($desgloseByReceta as $rid => $receta):
        $costoLote       = $receta['costo_total_lote'];
        $totalCantReceta = array_sum(array_column($receta['ingredientes'], 'cantidad'));
        $numReservas     = count($reservasByReceta[$rid]['rows'] ?? []);
        $totalReservado  = $reservasByReceta[$rid]['total'] ?? 0;
    ?>
    <div class="recipe-block">

        <div class="recipe-block-header">
            <div>
                <div class="rname"><?php echo htmlspecialchars($receta['nombre_receta']); ?></div>
                <?php if ($receta['nombre_producto_final']): ?>
                <div class="rmeta">Producto final: <?php echo htmlspecialchars($receta['nombre_producto_final']); ?></div>
                <?php endif; ?>
            </div>
            <div class="rstat">
                <span><strong><?php echo number_format($receta['unidades_resultantes'], 2); ?></strong>und/lote</span>
                <span><strong>$<?php echo number_format($costoLote, 2); ?></strong>costo lote</span>
                <span><strong>$<?php echo number_format($receta['costo_unitario'], 2); ?></strong>costo unit.</span>
                <?php if ($numReservas > 0): ?>
                <span class="badge-res-count" style="print-color-adjust:exact;-webkit-print-color-adjust:exact;">
                    📋 <?php echo $numReservas; ?> reserva<?php echo $numReservas > 1 ? 's' : ''; ?>
                    · <?php echo number_format($totalReservado, 2); ?> u.
                </span>
                <?php endif; ?>
                <span style="font-size:10px; background:#0e7490; padding:2px 7px; border-radius:10px; align-self:center;">% peso</span>
            </div>
        </div>

        <table class="det-table">
            <thead>
                <tr>
                    <th style="width:38%">Ingrediente</th>
                    <th style="text-align:center; width:16%">Cantidad</th>
                    <th style="text-align:center; width:14%">% Fórmula</th>
                    <th style="text-align:right; width:14%">Costo Unit.</th>
                    <th style="text-align:right; width:14%">Subtotal</th>
                    <th style="text-align:right; width:4%"></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $costoLoteReal = 0;
                foreach ($receta['ingredientes'] as $ing):
                    $cant      = floatval($ing['cantidad']);
                    $costoU    = floatval($ing['costo_actual']);
                    $subtotal  = $cant * $costoU;
                    $costoLoteReal += $subtotal;
                    $pctMostrar = ($totalCantReceta > 0) ? ($cant / $totalCantReceta) * 100 : 0;
                    $barWidth   = min($pctMostrar, 100);
                ?>
                <tr>
                    <td>
                        <strong><?php echo htmlspecialchars($ing['nombre_ingrediente']); ?></strong>
                        <span style="color:#aaa; font-size:10px; margin-left:4px;"><?php echo $ing['id_ingrediente']; ?></span>
                    </td>
                    <td style="text-align:center;">
                        <strong><?php echo number_format($cant, 3); ?></strong>
                        <span style="color:#888; font-size:10px;"> <?php echo $ing['unidad_medida']; ?></span>
                    </td>
                    <td style="text-align:center;">
                        <div class="pct-bar-wrap">
                            <span class="badge-pct"><?php echo number_format($pctMostrar, 1); ?>%</span>
                            <span class="pct-mini-bar" style="width:40px;">
                                <span class="pct-mini-fill" style="width:<?php echo $barWidth; ?>%;"></span>
                            </span>
                        </div>
                    </td>
                    <td style="text-align:right; color:#666;">$<?php echo number_format($costoU, 2); ?></td>
                    <td style="text-align:right; font-weight:bold; color:#1a5fa8;">$<?php echo number_format($subtotal, 2); ?></td>
                    <td></td>
                </tr>
                <?php endforeach; ?>

                <tr class="total-det">
                    <td colspan="2" style="text-align:right; color:#555; font-size:11px; text-transform:uppercase;">Costo total lote</td>
                    <td style="text-align:center; color:#0e7490; font-size:11px;">∑ 100%</td>
                    <td></td>
                    <td style="text-align:right; color:#1a5fa8;">$<?php echo number_format($costoLoteReal, 2); ?></td>
                    <td></td>
                </tr>
            </tbody>
        </table>

    </div>
    <?php endforeach; ?>

    <!-- ===== RESERVAS PENDIENTES POR PRODUCTO FINAL ===== -->
    <div class="res-section-title">📋 Reservas Pendientes por Producto Final</div>

    <?php if (empty($reservasByReceta)): ?>
        <div class="sin-reservas-cr">✓ No hay reservas pendientes que requieran los productos de estas recetas.</div>
    <?php else: ?>
        <?php foreach ($reservasByReceta as $rid => $resData):
            $recetaInfo = $desgloseByReceta[$rid] ?? null;
            $now = new DateTime();
        ?>
        <div class="res-block">
            <div class="res-block-header">
                <div>
                    <div class="rbn">
                        <?php echo htmlspecialchars($resData['nombre_producto_final']); ?>
                    </div>
                    <?php if ($recetaInfo): ?>
                    <div class="rbs">Receta: <?php echo htmlspecialchars($recetaInfo['nombre_receta']); ?></div>
                    <?php endif; ?>
                </div>
                <div style="display:flex; gap:14px; flex-wrap:wrap; align-items:center;">
                    <span style="font-size:11px; text-align:center;">
                        <strong style="display:block; font-size:15px;"><?php echo count($resData['rows']); ?></strong>
                        reservas
                    </span>
                    <span style="font-size:11px; text-align:center;">
                        <strong style="display:block; font-size:15px;"><?php echo number_format($resData['total'], 2); ?></strong>
                        u. comprometidas
                    </span>
                </div>
            </div>

            <table class="res-table">
                <thead>
                    <tr>
                        <th>#Reserva</th>
                        <th>Cliente</th>
                        <th>Teléfono</th>
                        <th class="center">Fecha Entrega</th>
                        <th class="center">Cant.</th>
                        <th class="center">Canal Origen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($resData['rows'] as $res):
                        $fechaRes = $res['fecha_reserva'] ? new DateTime($res['fecha_reserva']) : null;
                        $esHoy    = $fechaRes && $fechaRes->format('Y-m-d') === $now->format('Y-m-d');
                        $vencida  = $fechaRes && $now > $fechaRes && !$esHoy;
                        $rowStyle = $vencida ? 'background:#fff1f0;' : ($esHoy ? 'background:#fffbeb;' : '');
                    ?>
                    <tr style="<?php echo $rowStyle; ?>">
                        <td class="center" style="font-weight:bold;">#<?php echo $res['reserva_id']; ?></td>
                        <td><?php echo htmlspecialchars($res['cliente_nombre']); ?></td>
                        <td style="color:#64748b;"><?php echo htmlspecialchars($res['cliente_telefono'] ?: '—'); ?></td>
                        <td class="center">
                            <?php if ($fechaRes): ?>
                                <?php echo $fechaRes->format('d/m/Y H:i'); ?>
                                <?php if ($esHoy): ?>
                                    <span style="background:#f59e0b;color:white;padding:1px 6px;border-radius:10px;font-size:9px;font-weight:bold;margin-left:3px;">HOY</span>
                                <?php elseif ($vencida): ?>
                                    <span style="background:#dc2626;color:white;padding:1px 6px;border-radius:10px;font-size:9px;font-weight:bold;margin-left:3px;">VENCIDA</span>
                                <?php endif; ?>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="center"><strong><?php echo number_format(floatval($res['cant_reservada']), 2); ?></strong></td>
                        <td class="center"><?php echo getCanalBadgeCR($res['canal_origen'], $canalMap); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="res-total-row">
                        <td colspan="4" style="text-align:right;">TOTAL COMPROMETIDO:</td>
                        <td class="center"><?php echo number_format($resData['total'], 2); ?> u.</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

<?php include_once 'menu_master.php'; ?>
</body>
</html>

