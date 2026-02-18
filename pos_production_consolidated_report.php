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

    $costoTotalPlanificacion = 0;

} catch (Exception $e) { die("Error: " . $e->getMessage()); }
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

        @media print { .no-print { display: none; } body { margin: 0; } }
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
                <th class="center">Estado</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($insumos as $i): 
                $req = floatval($i['cantidad_requerida_total']);
                $stock = floatval($i['stock_actual']);
                $diff = $stock - $req;
                $costoLinea = $req * $i['costo_actual'];
                $costoTotalPlanificacion += $costoLinea;
                
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
                    <div class="bar-container"><div class="bar-fill" style="width:<?php echo $percent; ?>%; background-color:<?php echo $barColor; ?>;"></div></div>
                    <?php echo $status; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            
            <tr class="total-row">
                <td colspan="4" class="right">COSTO TOTAL MATERIA PRIMA REQUERIDA:</td>
                <td class="right">$<?php echo number_format($costoTotalPlanificacion, 2); ?></td>
                <td></td>
            </tr>
        </tbody>
    </table>

    <div style="background: #f8f9fa; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
        <strong>Nota:</strong> Este reporte asume la producción de 1 lote de cada receta seleccionada. Si desea producir múltiples lotes, multiplique los requerimientos manualmente o ajuste la planificación.
    </div>

<?php include_once 'menu_master.php'; ?>
</body>
</html>

