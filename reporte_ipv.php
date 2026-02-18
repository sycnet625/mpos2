<?php
// ARCHIVO: /var/www/palweb/api/reporte_ipv.php
ini_set('display_errors', 0);
require_once 'db.php';

// 1. CARGAR CONFIGURACIÓN (Almacén y Sucursal Actuales)
require_once 'config_loader.php';

$id_almacen = intval($config['id_almacen']);
$id_sucursal = intval($config['id_sucursal']);

// 2. PARÁMETROS DE FILTRO
$start = $_GET['start'] ?? date('Y-m-d', strtotime('-7 days'));
$end   = $_GET['end']   ?? date('Y-m-d');

// Ajustar horas para cubrir todo el día
$startDateTime = $start . " 00:00:00";
$endDateTime   = $end . " 23:59:59";

// MODIFICADO: Manejo del filtro (0=Todos, 1=Ventas, 2=Movimientos)
$filter_mode = isset($_GET['sales_only']) ? $_GET['sales_only'] : '0';

try {
    // 3. OBTENER LISTA DE PRODUCTOS ACTIVOS (Filtrado por Sucursal)
    $columnaSucursal = "es_suc" . $id_sucursal;
    
    $sqlProds = "SELECT codigo, nombre, costo, precio 
                 FROM productos 
                 WHERE activo = 1 AND $columnaSucursal = 1 
                 ORDER BY nombre ASC";
    $allProducts = $pdo->query($sqlProds)->fetchAll(PDO::FETCH_ASSOC);

    // 4. CONSULTAR KARDEX PARA MOVIMIENTOS EN EL RANGO (Filtrado por Almacén)
    $sqlMovs = "SELECT id_producto, tipo_movimiento, SUM(cantidad) as total_qty, SUM(ABS(cantidad) * costo_unitario) as total_costo
                FROM kardex 
                WHERE fecha BETWEEN ? AND ? AND id_almacen = ?
                GROUP BY id_producto, tipo_movimiento";
    $stmtM = $pdo->prepare($sqlMovs);
    $stmtM->execute([$startDateTime, $endDateTime, $id_almacen]);
    $movs = $stmtM->fetchAll(PDO::FETCH_GROUP|PDO::FETCH_ASSOC); 

    // 5. CONSULTAR STOCK INICIAL (HISTÓRICO) (Filtrado por Almacén)
    $sqlIni = "SELECT k.id_producto, k.saldo_actual 
               FROM kardex k
               INNER JOIN (
                   SELECT id_producto, MAX(id) as max_id
                   FROM kardex 
                   WHERE fecha < ? AND id_almacen = ?
                   GROUP BY id_producto
               ) max_k ON k.id = max_k.max_id";
    $stmtIni = $pdo->prepare($sqlIni);
    $stmtIni->execute([$startDateTime, $id_almacen]);
    $stockInicialMap = $stmtIni->fetchAll(PDO::FETCH_KEY_PAIR); // [SKU => saldo]

    // 6. OBTENER VENTAS ($ Dinero) REALES (Filtrado por Almacén Y Sucursal)
    $sqlVentas = "SELECT d.id_producto, SUM(d.cantidad * d.precio) as monto_venta_real
                  FROM ventas_detalle d
                  JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id
                  WHERE v.fecha BETWEEN ? AND ? 
                  AND v.id_almacen = ? 
                  AND v.id_sucursal = ?
                  GROUP BY d.id_producto";
    $stmtV = $pdo->prepare($sqlVentas);
    $stmtV->execute([$startDateTime, $endDateTime, $id_almacen, $id_sucursal]);
    $ventasDineroMap = $stmtV->fetchAll(PDO::FETCH_KEY_PAIR);

    // 7. PROCESAR DATA
    $reportData = [];
    $totales = ['venta' => 0, 'inventario_costo' => 0, 'ganancia' => 0];

    foreach ($allProducts as $p) {
        $sku = $p['codigo'];
        
        // Stock Inicial
        $stk_inic = floatval($stockInicialMap[$sku] ?? 0);

        // Procesar Movimientos del Kardex
        $entras = 0;
        $salidas = 0;
        $ventas_qty = 0;
        
        if (isset($movs[$sku])) {
            foreach ($movs[$sku] as $m) {
                $qty = floatval($m['total_qty']);
                $tipo = $m['tipo_movimiento'];

                if ($tipo === 'VENTA') {
                    $ventas_qty += abs($qty);
                } elseif ($qty > 0) {
                    $entras += $qty;
                } else {
                    $salidas += abs($qty);
                }
            }
        }

        // Stock Final Calculado
        $stk_final = $stk_inic + $entras - $salidas - $ventas_qty;

        // --- LÓGICA DE FILTRADO ---
        $tiene_movimiento = ($entras > 0 || $salidas > 0 || $ventas_qty > 0);

        // Modo 1: Solo con Ventas
        if ($filter_mode == '1' && $ventas_qty <= 0) continue;
        
        // Modo 2: Ventas + Movimientos (Cualquier actividad en el periodo)
        if ($filter_mode == '2' && !$tiene_movimiento) continue;

        // Dinero
        $monto_venta = floatval($ventasDineroMap[$sku] ?? 0);
        $costo_venta = $ventas_qty * $p['costo'];
        $ganancia = $monto_venta - $costo_venta;

        $reportData[] = [
            'nombre' => $p['nombre'],
            'costo' => $p['costo'],
            'precio' => $p['precio'],
            'inic' => $stk_inic,
            'entras' => $entras,
            'salidas' => $salidas,
            'ventas' => $ventas_qty,
            'final' => $stk_final,
            'total_v' => $monto_venta,
            'ganancia' => $ganancia
        ];

        $totales['venta'] += $monto_venta;
        $totales['inventario_costo'] += ($stk_final * $p['costo']);
        $totales['ganancia'] += $ganancia;
    }

    $margen_global = ($totales['venta'] > 0) ? ($totales['ganancia'] / $totales['venta']) * 100 : 0;

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte IPV - PalWeb (Kardex)</title>
    <style>
        body { font-family: "Calibri", "Arial", sans-serif; font-size: 12px; color: #000; margin: 20px; background: #fff; }
        h1 { color: #2F75B5; text-align: center; font-size: 18px; margin-bottom: 5px; text-transform: uppercase; }
        .meta { text-align: center; color: #444; margin-bottom: 20px; font-size: 12px; font-weight: bold; }
        .no-print { background: #f8f9fa; padding: 15px; border: 1px solid #ddd; margin-bottom: 20px; border-radius: 5px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background-color: #2F75B5; color: white; border: 1px solid #9BC2E6; padding: 6px; text-align: center; font-weight: normal; font-size: 13px; }
        td { border: 1px solid #D9D9D9; padding: 5px; vertical-align: middle; }
        .center { text-align: center; } .right { text-align: right; } .left { text-align: left; } .bold { font-weight: bold; }
        .total-section { margin-top: 20px; float: right; width: 320px; }
        .total-table td { border: 1px solid #D9D9D9; padding: 8px; }
        .label-cell { font-weight: bold; text-align: right; background-color: #f2f2f2; width: 60%; }
        @media print { .no-print { display: none; } body { margin: 0; } }
    </style>
</head>
<body>

<div class="no-print">
    <form method="GET" class="row g-3 align-items-center">
        <strong>Rango: </strong>
        <input type="date" name="start" value="<?php echo $start; ?>">
        <input type="date" name="end" value="<?php echo $end; ?>">
        
        <select name="sales_only">
            <option value="0" <?php echo $filter_mode == '0' ? 'selected':''; ?>>Todos los productos</option>
            <option value="1" <?php echo $filter_mode == '1' ? 'selected':''; ?>>Solo con ventas</option>
            <option value="2" <?php echo $filter_mode == '2' ? 'selected':''; ?>>Ventas + Movimientos</option>
        </select>
        
        <button type="submit">Generar Reporte Kardex</button>
        <button type="button" onclick="window.print()">Imprimir PDF</button>
    </form>
</div>

<h1>IPV - BALANCE DE INVENTARIO Y VENTAS (KARDEX)</h1>
<div class="meta">Periodo: <?php echo date('d/m/Y', strtotime($start)); ?> al <?php echo date('d/m/Y', strtotime($end)); ?></div>
<div class="meta" style="margin-top:-15px; font-size:11px; color:#666;">
    Sucursal: <?php echo $id_sucursal; ?> | Almacén: <?php echo $id_almacen; ?>
</div>

<table>
    <thead>
        <tr>
            <th style="width:3%">No</th>
            <th style="width:22%" class="left">Producto</th>
            <th style="width:7%">Costo</th>
            <th style="width:7%">Precio</th>
            <th style="width:7%; background-color:#E2EFDA; color:#000;">Inic.</th>
            <th style="width:7%; color:green;">Entras</th>
            <th style="width:7%; color:#CC0000;">Salidas</th>
            <th style="width:7%; font-weight:bold;">Ventas</th>
            <th style="width:7%; background-color:#FCE4D6; color:#000;">Final</th>
            <th style="width:10%">Total Venta</th>
            <th style="width:10%">Ganancia</th>
        </tr>
    </thead>
    <tbody>
        <?php $i=1; foreach($reportData as $row): ?>
        <tr>
            <td class="center"><?php echo $i++; ?></td>
            <td class="left"><?php echo htmlspecialchars($row['nombre']); ?></td>
            <td class="right" style="color:#666"><?php echo number_format($row['costo'], 2); ?></td>
            <td class="right"><?php echo number_format($row['precio'], 2); ?></td>
            <td class="center" style="background-color:#F2F2F2;"><?php echo $row['inic']; ?></td>
            <td class="center bold" style="color:green;"><?php echo $row['entras'] > 0 ? '+'.$row['entras'] : '-'; ?></td>
            <td class="center bold" style="color:#CC0000;"><?php echo $row['salidas'] > 0 ? '-'.$row['salidas'] : '-'; ?></td>
            <td class="center bold"><?php echo $row['ventas'] > 0 ? $row['ventas'] : '0'; ?></td>
            <td class="center bold" style="background-color:#FFF2CC;"><?php echo $row['final']; ?></td>
            <td class="right">$ <?php echo number_format($row['total_v'], 2); ?></td>
            <td class="right bold" style="color:<?php echo $row['ganancia'] >= 0 ? 'green':'red'; ?>">
                <?php echo $row['ganancia'] > 0 ? number_format($row['ganancia'], 2) : '-'; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<div class="total-section">
    <table class="total-table">
        <tr>
            <td class="label-cell">💰 VENTA TOTAL</td>
            <td class="right bold" style="font-size:14px">$ <?php echo number_format($totales['venta'], 2); ?></td>
        </tr>
        <tr>
            <td class="label-cell" style="color:#2F75B5;">📦 Valor Inv. Final (Costo)</td>
            <td class="right bold" style="color:#2F75B5;">$ <?php echo number_format($totales['inventario_costo'], 2); ?></td>
        </tr>
        <tr>
            <td class="label-cell">Margen Global</td>
            <td class="right"><?php echo number_format($margen_global, 2); ?> %</td>
        </tr>
        <tr style="background-color:#dff0d8">
            <td class="label-cell" style="color:green; font-size:15px">GANANCIA NETA</td>
            <td class="right bold" style="color:green; font-size:15px">$ <?php echo number_format($totales['ganancia'], 2); ?></td>
        </tr>
    </table>
</div>

<div style="clear:both; font-size:10px; color:#999; margin-top:10px;">
    * Datos basados en movimientos históricos del Kardex.
</div>
<div style="margin-top:20px; font-size:10px; color:#999; text-align:center;">
    Generado por PalWeb POS v2.1 (Kardex Engine) - <?php echo date('d/m/Y H:i'); ?>
</div>



<?php include_once 'menu_master.php'; ?>
</body>
</html>

