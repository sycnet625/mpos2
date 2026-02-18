<?php
// ARCHIVO: /var/www/palweb/api/inventory_report.php
// DESCRIPCIÓN: Informe Avanzado de Valoración de Inventario y Rentabilidad

session_start();
if (!isset($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }

ini_set('display_errors', 0);
require_once 'db.php';

// 1. CARGAR CONFIGURACIÓN
require_once 'config_loader.php';

$EMP_ID = intval($config['id_empresa']);
$ALM_ID = intval($config['id_almacen']);
$SUC_ID = intval($config['id_sucursal']);

// 2. FILTROS
$filterStock = $_GET['stock_mode'] ?? 'positive'; // 'positive' | 'all'
$orderBy     = $_GET['orderby'] ?? 'nombre';      // 'nombre', 'codigo', 'precio_desc', 'precio_asc'

// Construcción de la Query
// IMPORTANTE: El JOIN filtra por id_almacen Y id_sucursal para asegurar integridad
$sql = "SELECT p.codigo, p.nombre, p.categoria, p.costo, p.precio, p.stock_minimo,
               COALESCE(s.cantidad, 0) as stock_actual
        FROM productos p
        LEFT JOIN stock_almacen s ON p.codigo = s.id_producto 
                                  AND s.id_almacen = :alm 
                                  AND s.id_sucursal = :suc
        WHERE p.id_empresa = :emp AND p.activo = 1";

// Filtro de Stock
if ($filterStock === 'positive') {
    $sql .= " AND s.cantidad > 0";
}

// Ordenamiento (Siempre agrupado por categoría primero)
$sql .= " ORDER BY p.categoria ASC";

switch ($orderBy) {
    case 'codigo':      $sql .= ", p.codigo ASC"; break;
    case 'precio_desc': $sql .= ", p.precio DESC"; break;
    case 'precio_asc':  $sql .= ", p.precio ASC"; break;
    default:            $sql .= ", p.nombre ASC"; break; // Por defecto alfabético
}

$stmt = $pdo->prepare($sql);
$stmt->execute([':alm' => $ALM_ID, ':suc' => $SUC_ID, ':emp' => $EMP_ID]);
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. CÁLCULOS Y PROCESAMIENTO
$resumenGeneral = [
    'total_items_fisicos' => 0,
    'valor_costo_total'   => 0,
    'valor_venta_total'   => 0,
    'ganancia_potencial'  => 0,
    'items_bajo_minimo'   => 0,
    'items_sin_stock'     => 0
];

$resumenCategorias = [];
$dataAgrupada = [];

foreach ($productos as $p) {
    $stock = floatval($p['stock_actual']);
    $costo = floatval($p['costo']);
    $precio = floatval($p['precio']);
    
    // Cálculos por Item
    $gananciaUnit = $precio - $costo;
    $margenPorc   = ($precio > 0) ? ($gananciaUnit / $precio) * 100 : 0;
    
    $valorCostoItem = $stock * $costo;
    $valorVentaItem = $stock * $precio;

    // Acumuladores Generales (Solo suman si hay stock, excepto conteo de alertas)
    if ($stock > 0) {
        $resumenGeneral['total_items_fisicos'] += $stock;
        $resumenGeneral['valor_costo_total']   += $valorCostoItem;
        $resumenGeneral['valor_venta_total']   += $valorVentaItem;
        $resumenGeneral['ganancia_potencial']  += ($valorVentaItem - $valorCostoItem);
    }

    // Alertas
    if ($stock <= $p['stock_minimo']) $resumenGeneral['items_bajo_minimo']++;
    if ($stock <= 0) $resumenGeneral['items_sin_stock']++;

    // Agrupación por Categoría para la Tabla
    $cat = $p['categoria'] ?: 'SIN CATEGORIA';
    
    // Inicializar resumen de categoría si no existe
    if (!isset($resumenCategorias[$cat])) {
        $resumenCategorias[$cat] = ['stock' => 0, 'valor_costo' => 0, 'valor_venta' => 0];
    }
    
    // Sumar a la categoría
    if ($stock > 0) {
        $resumenCategorias[$cat]['stock'] += $stock;
        $resumenCategorias[$cat]['valor_costo'] += $valorCostoItem;
        $resumenCategorias[$cat]['valor_venta'] += $valorVentaItem;
    }

    // Guardar datos procesados para la vista
    $p['ganancia_unit'] = $gananciaUnit;
    $p['margen_porc']   = $margenPorc;
    $dataAgrupada[$cat][] = $p;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Inventario Valorado</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
        .kpi-card { background: white; border-radius: 10px; padding: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border-left: 4px solid #0d6efd; height: 100%; }
        .kpi-title { font-size: 0.8rem; text-transform: uppercase; color: #6c757d; font-weight: bold; }
        .kpi-value { font-size: 1.5rem; font-weight: bold; color: #212529; }
        .table-group-header { background-color: #e9ecef !important; font-weight: bold; text-transform: uppercase; color: #495057; }
        .badge-margin-high { background-color: #d1e7dd; color: #0f5132; } /* > 40% */
        .badge-margin-med { background-color: #fff3cd; color: #664d03; }  /* 20-40% */
        .badge-margin-low { background-color: #f8d7da; color: #842029; }  /* < 20% */
        @media print {
            .no-print { display: none !important; }
            .kpi-card { border: 1px solid #ccc; box-shadow: none; }
        }
    </style>
</head>
<body class="p-4">

<div class="container-fluid">
    
    <div class="d-flex justify-content-between align-items-center mb-4 no-print">
        <div>
            <h4 class="mb-0 fw-bold"><i class="fas fa-boxes text-primary"></i> Valoración de Inventario</h4>
            <small class="text-muted">Sucursal: <?php echo $SUC_ID; ?> | Almacén: <?php echo $ALM_ID; ?></small>
        </div>
        <form class="d-flex gap-2 align-items-end" method="GET">
            <div>
                <label class="small fw-bold">Visualizar</label>
                <select name="stock_mode" class="form-select form-select-sm">
                    <option value="positive" <?php echo $filterStock=='positive'?'selected':''; ?>>Solo con Stock (>0)</option>
                    <option value="all" <?php echo $filterStock=='all'?'selected':''; ?>>Todos los Productos</option>
                </select>
            </div>
            <div>
                <label class="small fw-bold">Ordenar Por</label>
                <select name="orderby" class="form-select form-select-sm">
                    <option value="nombre" <?php echo $orderBy=='nombre'?'selected':''; ?>>Nombre (A-Z)</option>
                    <option value="codigo" <?php echo $orderBy=='codigo'?'selected':''; ?>>Código SKU</option>
                    <option value="precio_desc" <?php echo $orderBy=='precio_desc'?'selected':''; ?>>Precio (Mayor a Menor)</option>
                    <option value="precio_asc" <?php echo $orderBy=='precio_asc'?'selected':''; ?>>Precio (Menor a Mayor)</option>
                </select>
            </div>
            <button class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Actualizar</button>
            <button type="button" class="btn btn-dark btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Imprimir</button>
            <a href="products_table.php" class="btn btn-secondary btn-sm">Volver</a>
        </form>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-2 col-sm-6">
            <div class="kpi-card" style="border-color: #0d6efd;">
                <div class="kpi-title">Valor Costo (Inversión)</div>
                <div class="kpi-value text-primary">$<?php echo number_format($resumenGeneral['valor_costo_total'], 2); ?></div>
            </div>
        </div>
        <div class="col-md-2 col-sm-6">
            <div class="kpi-card" style="border-color: #198754;">
                <div class="kpi-title">Valor Venta (Potencial)</div>
                <div class="kpi-value text-success">$<?php echo number_format($resumenGeneral['valor_venta_total'], 2); ?></div>
            </div>
        </div>
        <div class="col-md-2 col-sm-6">
            <div class="kpi-card" style="border-color: #fd7e14;">
                <div class="kpi-title">Ganancia Esperada</div>
                <div class="kpi-value" style="color: #fd7e14;">$<?php echo number_format($resumenGeneral['ganancia_potencial'], 2); ?></div>
            </div>
        </div>
        <div class="col-md-2 col-sm-6">
            <div class="kpi-card" style="border-color: #6c757d;">
                <div class="kpi-title">Total Unidades Físicas</div>
                <div class="kpi-value"><?php echo number_format($resumenGeneral['total_items_fisicos'], 0); ?></div>
            </div>
        </div>
        <div class="col-md-2 col-sm-6">
            <div class="kpi-card" style="border-color: #ffc107;">
                <div class="kpi-title">Prod. Bajo Mínimo</div>
                <div class="kpi-value text-warning"><?php echo $resumenGeneral['items_bajo_minimo']; ?></div>
            </div>
        </div>
        <div class="col-md-2 col-sm-6">
            <div class="kpi-card" style="border-color: #dc3545;">
                <div class="kpi-title">Sin Stock (Agotados)</div>
                <div class="kpi-value text-danger"><?php echo $resumenGeneral['items_sin_stock']; ?></div>
            </div>
        </div>
    </div>

    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-header bg-white fw-bold">Resumen por Categorías</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0 text-center">
                    <thead class="table-light">
                        <tr>
                            <th>Categoría</th>
                            <th>Unidades</th>
                            <th>Valor Costo</th>
                            <th>Valor Venta</th>
                            <th>% del Inventario ($)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($resumenCategorias as $cat => $vals): 
                            $porcentaje = ($resumenGeneral['valor_costo_total'] > 0) ? ($vals['valor_costo'] / $resumenGeneral['valor_costo_total']) * 100 : 0;
                        ?>
                        <tr>
                            <td class="text-start fw-bold"><?php echo $cat; ?></td>
                            <td><?php echo number_format($vals['stock'], 0); ?></td>
                            <td>$<?php echo number_format($vals['valor_costo'], 2); ?></td>
                            <td>$<?php echo number_format($vals['valor_venta'], 2); ?></td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="progress flex-grow-1" style="height: 5px;">
                                        <div class="progress-bar" style="width: <?php echo $porcentaje; ?>%"></div>
                                    </div>
                                    <span class="ms-2 small"><?php echo number_format($porcentaje, 1); ?>%</span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-bold">Detalle de Productos</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0">
                    <thead>
                        <tr class="table-dark text-white">
                            <th>SKU</th>
                            <th>Producto</th>
                            <th class="text-center">Stock</th>
                            <th class="text-end">Costo</th>
                            <th class="text-end">Venta</th>
                            <th class="text-end">Ganancia/U</th>
                            <th class="text-center">Margen %</th>
                            <th class="text-end">Valor Total (Costo)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dataAgrupada as $categoria => $items): ?>
                            <tr class="table-group-header">
                                <td colspan="8"><?php echo $categoria; ?></td>
                            </tr>
                            
                            <?php foreach ($items as $p): 
                                $margenClass = 'badge-margin-med';
                                if ($p['margen_porc'] >= 40) $margenClass = 'badge-margin-high';
                                if ($p['margen_porc'] < 20) $margenClass = 'badge-margin-low';
                                
                                $totalLinea = floatval($p['stock_actual']) * floatval($p['costo']);
                            ?>
                            <tr>
                                <td><code><?php echo $p['codigo']; ?></code></td>
                                <td>
                                    <?php echo htmlspecialchars($p['nombre']); ?>
                                    <?php if($p['stock_actual'] <= $p['stock_minimo']): ?>
                                        <i class="fas fa-exclamation-circle text-warning small" title="Stock Bajo"></i>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center fw-bold <?php echo ($p['stock_actual']<=0)?'text-danger':''; ?>">
                                    <?php echo number_format($p['stock_actual'], 2); ?>
                                </td>
                                <td class="text-end text-muted">$<?php echo number_format($p['costo'], 2); ?></td>
                                <td class="text-end fw-bold">$<?php echo number_format($p['precio'], 2); ?></td>
                                <td class="text-end text-success">+$<?php echo number_format($p['ganancia_unit'], 2); ?></td>
                                <td class="text-center">
                                    <span class="badge <?php echo $margenClass; ?> border text-dark">
                                        <?php echo number_format($p['margen_porc'], 1); ?>%
                                    </span>
                                </td>
                                <td class="text-end fw-bold">$<?php echo number_format($totalLinea, 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <div class="mt-3 text-muted small text-end no-print">
        Generado el: <?php echo date('d/m/Y H:i:s'); ?>
    </div>

</div>



<?php include_once 'menu_master.php'; ?>
</body>
</html>

