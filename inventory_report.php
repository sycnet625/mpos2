<?php
// ARCHIVO: /var/www/palweb/api/inventory_report.php
// DESCRIPCIÓN: Informe Avanzado de Valoración de Inventario y Rentabilidad

session_start();
if (!isset($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }

ini_set('display_errors', 0);
require_once 'db.php';
require_once 'accounting_helpers.php';

// 1. CARGAR CONFIGURACIÓN
require_once 'config_loader.php';
require_once 'combo_helper.php';

$EMP_ID = intval($config['id_empresa']);
$ALM_ID = intval($config['id_almacen']);
$SUC_ID = intval($config['id_sucursal']);
$weekStartDay = intval($config['semana_inicio_dia'] ?? 1);

function inventory_report_default_week_range(int $weekStartDay): array {
    $today = new DateTime();
    $currentDayOfWeek = intval($today->format('w'));
    $diff = ($currentDayOfWeek - $weekStartDay + 7) % 7;
    $start = clone $today;
    $start->modify("-$diff days");
    return [$start->format('Y-m-d'), $today->format('Y-m-d')];
}

function inventory_report_product_family(string $name): string {
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    if ($name === '') {
        return 'SIN FAMILIA';
    }
    $parts = preg_split('/\s+/u', $name);
    $family = trim((string)($parts[0] ?? ''));
    $family = preg_replace('/^[^\pL\pN]+|[^\pL\pN]+$/u', '', $family);
    if ($family === '') {
        $family = 'SIN FAMILIA';
    }
    if (function_exists('mb_strtoupper')) {
        return mb_strtoupper($family, 'UTF-8');
    }
    return strtoupper($family);
}

function inventory_report_safe_id(string $value): string {
    $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim($value));
    $safe = trim($safe, '_');
    if ($safe === '') {
        $safe = 'item_' . substr(md5($value), 0, 8);
    }
    return $safe;
}

$viewMode = $_GET['view'] ?? 'inventory';
$isModelView = ($viewMode === 'model');

[$defaultWeekStart, $defaultWeekEnd] = inventory_report_default_week_range($weekStartDay);
$fechaInicioModelo = $_GET['fecha_inicio'] ?? $defaultWeekStart;
$fechaFinModelo = $_GET['fecha_fin'] ?? $defaultWeekEnd;

if ($fechaInicioModelo > $fechaFinModelo) {
    [$fechaInicioModelo, $fechaFinModelo] = [$fechaFinModelo, $fechaInicioModelo];
}

// 2. FILTROS
$filterStock = $_GET['stock_mode'] ?? 'positive'; // 'positive' | 'all'
$orderBy     = $_GET['orderby'] ?? 'nombre';      // 'nombre', 'codigo', 'precio_desc', 'precio_asc'

// Construcción de la Query
// IMPORTANTE: El JOIN filtra por id_almacen Y id_sucursal para asegurar integridad
$sql = "SELECT p.codigo, p.nombre, p.categoria,
               COALESCE(p.es_combo, 0) AS es_combo,
               p.costo as costo_real,
               COALESCE(ps.precio_costo, p.costo) as costo_sucursal,
               COALESCE(ps.precio_venta, p.precio) as precio, 
               p.stock_minimo,
               COALESCE(s.cantidad, 0) as stock_actual
        FROM productos p
        LEFT JOIN stock_almacen s ON p.codigo = s.id_producto 
                                  AND s.id_almacen = :alm 
                                  AND s.id_sucursal = :suc_stock
        LEFT JOIN productos_precios_sucursal ps ON p.codigo = ps.codigo_producto 
                                               AND ps.id_sucursal = :suc_price
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
$stmt->execute([':alm' => $ALM_ID, ':suc_stock' => $SUC_ID, ':suc_price' => $SUC_ID, ':emp' => $EMP_ID]);
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
$productos = combo_apply_product_rows($pdo, $productos, $EMP_ID, $ALM_ID);

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
    $costo = floatval($p['costo_real'] ?? $p['costo'] ?? 0);
    $costoSucursal = floatval($p['costo_sucursal'] ?? $costo);
    $precio = floatval($p['precio']);
    $esCombo = intval($p['es_combo'] ?? 0) === 1;
    
    // Cálculos por Item
    $gananciaUnit = $precio - $costo;
    $margenPorc   = ($precio > 0) ? ($gananciaUnit / $precio) * 100 : 0;
    
    $valorCostoItem = $stock * $costo;
    $valorVentaItem = $stock * $precio;

    // Acumuladores Generales (Solo suman si hay stock, excepto conteo de alertas)
    if ($stock > 0 && !$esCombo) {
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
    if ($stock > 0 && !$esCombo) {
        $resumenCategorias[$cat]['stock'] += $stock;
        $resumenCategorias[$cat]['valor_costo'] += $valorCostoItem;
        $resumenCategorias[$cat]['valor_venta'] += $valorVentaItem;
    }

    // Guardar datos procesados para la vista
    $p['ganancia_unit'] = $gananciaUnit;
    $p['margen_porc']   = $margenPorc;
    $p['costo_real']    = $costo;
    $p['costo_sucursal']= $costoSucursal;
    $dataAgrupada[$cat][] = $p;
}

$modelProducts = [];
$modelCategories = [];
$modelSummary = [
    'qty' => 0.0,
    'venta_real' => 0.0,
    'venta_simulada' => 0.0,
    'ganancia_real' => 0.0,
    'ganancia_simulada' => 0.0,
    'delta_ganancia' => 0.0,
    'margen_real' => 0.0,
    'margen_simulado' => 0.0,
    'productos' => 0,
];

if ($isModelView) {
    $sqlModel = "SELECT
                    COALESCE(p.codigo, d.id_producto) AS codigo,
                    COALESCE(p.nombre, d.nombre_producto, d.id_producto) AS nombre,
                    COALESCE(p.categoria, 'SIN CATEGORIA') AS categoria,
                    COALESCE(ps.precio_venta, p.precio, d.precio, 0) AS precio_actual,
                    COALESCE(p.costo, 0) AS costo_real,
                    SUM(d.cantidad) AS cantidad_neta,
                    SUM(d.cantidad * d.precio) AS venta_real,
                    SUM(d.cantidad * (d.precio - COALESCE(p.costo, 0))) AS ganancia_real
                FROM ventas_cabecera v
                LEFT JOIN caja_sesiones cs ON (v.id_caja = cs.id OR v.id_sesion_caja = cs.id)
                JOIN ventas_detalle d ON d.id_venta_cabecera = v.id
                LEFT JOIN productos p ON d.id_producto = p.codigo AND p.id_empresa = :emp
                LEFT JOIN productos_precios_sucursal ps ON ps.codigo_producto = COALESCE(p.codigo, d.id_producto) AND ps.id_sucursal = :suc_price
                WHERE IFNULL(cs.fecha_contable, DATE(v.fecha)) BETWEEN :inicio AND :fin
                  AND v.id_empresa = :emp_where
                  AND v.id_sucursal = :suc
                  AND v.id_almacen = :alm
                  AND " . ventas_reales_where_clause('v') . "
                GROUP BY COALESCE(p.codigo, d.id_producto), COALESCE(p.nombre, d.nombre_producto, d.id_producto), COALESCE(p.categoria, 'SIN CATEGORIA'), COALESCE(ps.precio_venta, p.precio, d.precio, 0), COALESCE(p.costo, 0)
                HAVING ABS(SUM(d.cantidad)) > 0
                ORDER BY categoria ASC, nombre ASC";

    $stmtModel = $pdo->prepare($sqlModel);
    $stmtModel->execute([
        ':emp' => $EMP_ID,
        ':emp_where' => $EMP_ID,
        ':suc_price' => $SUC_ID,
        ':inicio' => $fechaInicioModelo,
        ':fin' => $fechaFinModelo,
        ':suc' => $SUC_ID,
        ':alm' => $ALM_ID,
    ]);
    $modelProductsRaw = $stmtModel->fetchAll(PDO::FETCH_ASSOC);

    foreach ($modelProductsRaw as $row) {
        $qty = floatval($row['cantidad_neta'] ?? 0);
        if (abs($qty) < 0.000001) {
            continue;
        }

        $categoria = trim((string)($row['categoria'] ?? '')) ?: 'SIN CATEGORIA';
        $family = inventory_report_product_family((string)($row['nombre'] ?? ''));
        $catKey = md5($categoria);
        $codigo = (string)($row['codigo'] ?? '');
        $rowId = inventory_report_safe_id($codigo);
        $nombre = (string)($row['nombre'] ?? $codigo);
        $costo = floatval($row['costo_real'] ?? 0);
        $precioActual = floatval($row['precio_actual'] ?? 0);
        $ventaReal = floatval($row['venta_real'] ?? 0);
        $gananciaReal = floatval($row['ganancia_real'] ?? 0);
        $ventaSimulada = $qty * $precioActual;
        $gananciaSimulada = $qty * ($precioActual - $costo);
        $deltaGanancia = $gananciaSimulada - $gananciaReal;

        $modelProducts[] = [
            'codigo' => $codigo,
            'nombre' => $nombre,
            'categoria' => $categoria,
            'cat_key' => $catKey,
            'family' => $family,
            'qty' => $qty,
            'costo' => $costo,
            'precio_actual' => $precioActual,
            'venta_real' => $ventaReal,
            'ganancia_real' => $gananciaReal,
            'precio_nuevo' => $precioActual,
            'venta_simulada' => $ventaSimulada,
            'ganancia_simulada' => $gananciaSimulada,
            'delta_ganancia' => $deltaGanancia,
            'row_id' => $rowId,
        ];

        if (!isset($modelCategories[$catKey])) {
            $modelCategories[$catKey] = [
                'categoria' => $categoria,
                'qty' => 0.0,
                'venta_real' => 0.0,
                'venta_simulada' => 0.0,
                'ganancia_real' => 0.0,
                'ganancia_simulada' => 0.0,
                'delta_ganancia' => 0.0,
            ];
        }

        $modelCategories[$catKey]['qty'] += $qty;
        $modelCategories[$catKey]['venta_real'] += $ventaReal;
        $modelCategories[$catKey]['venta_simulada'] += $ventaSimulada;
        $modelCategories[$catKey]['ganancia_real'] += $gananciaReal;
        $modelCategories[$catKey]['ganancia_simulada'] += $gananciaSimulada;
        $modelCategories[$catKey]['delta_ganancia'] += $deltaGanancia;

        $modelSummary['qty'] += $qty;
        $modelSummary['venta_real'] += $ventaReal;
        $modelSummary['venta_simulada'] += $ventaSimulada;
        $modelSummary['ganancia_real'] += $gananciaReal;
        $modelSummary['ganancia_simulada'] += $gananciaSimulada;
        $modelSummary['delta_ganancia'] += $deltaGanancia;
        $modelSummary['productos']++;
    }

    $modelSummary['margen_real'] = $modelSummary['venta_real'] > 0 ? ($modelSummary['ganancia_real'] / $modelSummary['venta_real']) * 100 : 0;
    $modelSummary['margen_simulado'] = $modelSummary['venta_simulada'] > 0 ? ($modelSummary['ganancia_simulada'] / $modelSummary['venta_simulada']) * 100 : 0;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?php echo $isModelView ? 'Modelaje de Precios Tentativos' : 'Reporte de Inventario Valorado'; ?></title>
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
        .model-row input[type="number"] { min-width: 120px; }
        .subtotal-row { background: #eef4ff !important; font-weight: 700; }
        .category-row { background: #343a40 !important; color: #fff; font-weight: 700; text-transform: uppercase; }
        @media print {
            .no-print { display: none !important; }
            .kpi-card { border: 1px solid #ccc; box-shadow: none; }
            body { background: #fff; }
            .table-responsive { overflow: visible !important; }
            .model-row input { border: 0; background: transparent; padding: 0; }
        }
    </style>
</head>
<body class="p-4">

<div class="container-fluid">
    
    <div class="d-flex justify-content-between align-items-center mb-4 no-print">
        <div>
            <h4 class="mb-0 fw-bold"><i class="fas fa-boxes text-primary"></i> <?php echo $isModelView ? 'Modelaje de Precios Tentativos' : 'Valoración de Inventario'; ?></h4>
            <small class="text-muted">Sucursal: <?php echo $SUC_ID; ?> | Almacén: <?php echo $ALM_ID; ?> | Empresa: <?php echo $EMP_ID; ?></small>
        </div>
        <div class="d-flex flex-column gap-2 align-items-end">
            <div class="btn-group no-print" role="group">
                <a href="inventory_report.php?view=inventory" class="btn btn<?php echo !$isModelView ? '-primary' : '-outline-primary'; ?> btn-sm"><i class="fas fa-boxes me-1"></i>Inventario</a>
                <a href="inventory_report.php?view=model" class="btn btn<?php echo $isModelView ? '-primary' : '-outline-primary'; ?> btn-sm"><i class="fas fa-flask me-1"></i>Modelaje</a>
            </div>
            <form class="d-flex gap-2 align-items-end no-print" method="GET">
                <input type="hidden" name="view" value="<?php echo $isModelView ? 'model' : 'inventory'; ?>">
                <?php if ($isModelView): ?>
                    <div>
                        <label class="small fw-bold">Desde</label>
                        <input type="date" name="fecha_inicio" class="form-control form-control-sm" value="<?php echo htmlspecialchars($fechaInicioModelo, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div>
                        <label class="small fw-bold">Hasta</label>
                        <input type="date" name="fecha_fin" class="form-control form-control-sm" value="<?php echo htmlspecialchars($fechaFinModelo, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                <?php else: ?>
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
                <?php endif; ?>
                <button class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Actualizar</button>
                <button type="button" class="btn btn-dark btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Imprimir</button>
                <a href="products_table.php" class="btn btn-secondary btn-sm">Volver</a>
            </form>
        </div>
    </div>

    <?php if (!$isModelView): ?>
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
                                <th class="text-end">Costo Real</th>
                                <th class="text-end">Costo Sucursal</th>
                                <th class="text-end">Venta</th>
                                <th class="text-end">Ganancia/U</th>
                                <th class="text-center">Margen %</th>
                                <th class="text-end">Valor Total (Costo)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dataAgrupada as $categoria => $items): ?>
                                <tr class="table-group-header">
                                    <td colspan="9"><?php echo $categoria; ?></td>
                                </tr>
                                
                                <?php foreach ($items as $p): 
                                    $margenClass = 'badge-margin-med';
                                    if ($p['margen_porc'] >= 40) $margenClass = 'badge-margin-high';
                                    if ($p['margen_porc'] < 20) $margenClass = 'badge-margin-low';
                                    
                                    $totalLinea = floatval($p['stock_actual']) * floatval($p['costo_real'] ?? $p['costo'] ?? 0);
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
                                    <td class="text-end text-muted">$<?php echo number_format($p['costo_real'] ?? $p['costo'] ?? 0, 2); ?></td>
                                    <td class="text-end text-muted">$<?php echo number_format($p['costo_sucursal'] ?? $p['costo_real'] ?? $p['costo'] ?? 0, 2); ?></td>
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
    <?php else: ?>
        <div class="row g-3 mb-4">
            <div class="col-md-2 col-sm-6">
                <div class="kpi-card" style="border-color: #0d6efd;">
                    <div class="kpi-title">Venta Real</div>
                    <div class="kpi-value text-primary" id="kpiVentaReal">$<?php echo number_format($modelSummary['venta_real'], 2); ?></div>
                </div>
            </div>
            <div class="col-md-2 col-sm-6">
                <div class="kpi-card" style="border-color: #198754;">
                    <div class="kpi-title">Venta Simulada</div>
                    <div class="kpi-value text-success" id="kpiVentaSimulada">$<?php echo number_format($modelSummary['venta_simulada'], 2); ?></div>
                </div>
            </div>
            <div class="col-md-2 col-sm-6">
                <div class="kpi-card" style="border-color: #fd7e14;">
                    <div class="kpi-title">Ganancia Real</div>
                    <div class="kpi-value" style="color: #fd7e14;" id="kpiGananciaReal">$<?php echo number_format($modelSummary['ganancia_real'], 2); ?></div>
                </div>
            </div>
            <div class="col-md-2 col-sm-6">
                <div class="kpi-card" style="border-color: #6610f2;">
                    <div class="kpi-title">Ganancia Simulada</div>
                    <div class="kpi-value text-purple" id="kpiGananciaSimulada" style="color:#6610f2;">$<?php echo number_format($modelSummary['ganancia_simulada'], 2); ?></div>
                </div>
            </div>
            <div class="col-md-2 col-sm-6">
                <div class="kpi-card" style="border-color: #20c997;">
                    <div class="kpi-title">Delta Ganancia</div>
                    <div class="kpi-value" style="color:#20c997;" id="kpiDeltaGanancia">$<?php echo number_format($modelSummary['delta_ganancia'], 2); ?></div>
                </div>
            </div>
            <div class="col-md-2 col-sm-6">
                <div class="kpi-card" style="border-color: #6c757d;">
                    <div class="kpi-title">Productos Modelados</div>
                    <div class="kpi-value" id="kpiProductosModelados"><?php echo number_format($modelSummary['productos'], 0); ?></div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-3 col-sm-6">
                <div class="kpi-card">
                    <div class="kpi-title">Margen Real</div>
                    <div class="kpi-value" id="kpiMargenReal"><?php echo number_format($modelSummary['margen_real'], 1); ?>%</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="kpi-card">
                    <div class="kpi-title">Margen Simulado</div>
                    <div class="kpi-value" id="kpiMargenSimulado"><?php echo number_format($modelSummary['margen_simulado'], 1); ?>%</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="kpi-card">
                    <div class="kpi-title">Unidades Neta Vendidas</div>
                    <div class="kpi-value" id="kpiUnidadesNetas"><?php echo number_format($modelSummary['qty'], 0); ?></div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="kpi-card">
                    <div class="kpi-title">Incremento vs Real</div>
                    <div class="kpi-value" id="kpiIncrementoPct">0.0%</div>
                </div>
            </div>
        </div>

        <div class="card mb-3 border-0 shadow-sm no-print">
            <div class="card-body d-flex flex-wrap gap-2 justify-content-between align-items-center">
                <div>
                    <div class="fw-bold">Escenario guardado en el navegador</div>
                    <div class="text-muted small">Cambias precios tentativos sin modificar la base de datos. El escenario queda por rango de fechas.</div>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btnResetScenario"><i class="fas fa-undo me-1"></i>Restablecer escenario</button>
                    <button type="button" class="btn btn-dark btn-sm" onclick="window.print()"><i class="fas fa-print me-1"></i>Imprimir resumen</button>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
                <span>Productos vendidos en el periodo</span>
                <span class="small text-muted"><?php echo htmlspecialchars($fechaInicioModelo, ENT_QUOTES, 'UTF-8'); ?> al <?php echo htmlspecialchars($fechaFinModelo, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0 align-middle" id="modelTable">
                        <thead>
                            <tr class="table-dark text-white">
                                <th>SKU</th>
                                <th>Producto</th>
                                <th>Categoría</th>
                                <th class="text-center">Cant.</th>
                                <th class="text-end">Costo</th>
                                <th class="text-end">Precio actual</th>
                                <th class="text-end">Precio nuevo</th>
                                <th class="text-end">Venta real</th>
                                <th class="text-end">Venta simulada</th>
                                <th class="text-end">Ganancia real</th>
                                <th class="text-end">Ganancia simulada</th>
                                <th class="text-end">Delta</th>
                                <th class="text-center">Margen sim.</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($modelCategories as $catKey => $catVals): ?>
                                <tr class="category-row">
                                    <td colspan="13"><?php echo htmlspecialchars($catVals['categoria'], ENT_QUOTES, 'UTF-8'); ?></td>
                                </tr>
                                <?php foreach ($modelProducts as $row): ?>
                                    <?php if ($row['cat_key'] !== $catKey) continue; ?>
                                    <tr class="model-row" data-catkey="<?php echo htmlspecialchars($catKey, ENT_QUOTES, 'UTF-8'); ?>" data-code="<?php echo htmlspecialchars($row['codigo'], ENT_QUOTES, 'UTF-8'); ?>" data-rowid="<?php echo htmlspecialchars($row['row_id'], ENT_QUOTES, 'UTF-8'); ?>" data-qty="<?php echo htmlspecialchars((string)$row['qty'], ENT_QUOTES, 'UTF-8'); ?>" data-cost="<?php echo htmlspecialchars((string)$row['costo'], ENT_QUOTES, 'UTF-8'); ?>" data-realprice="<?php echo htmlspecialchars((string)$row['precio_actual'], ENT_QUOTES, 'UTF-8'); ?>" data-real-sale="<?php echo htmlspecialchars((string)$row['venta_real'], ENT_QUOTES, 'UTF-8'); ?>" data-real-profit="<?php echo htmlspecialchars((string)$row['ganancia_real'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <td><code><?php echo htmlspecialchars($row['codigo'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                                        <td>
                                            <?php echo htmlspecialchars($row['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                                            <div class="small text-muted"><?php echo htmlspecialchars($row['family'], ENT_QUOTES, 'UTF-8'); ?></div>
                                        </td>
                                        <td><?php echo htmlspecialchars($row['categoria'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="text-center fw-bold"><?php echo number_format($row['qty'], 2); ?></td>
                                        <td class="text-end text-muted">$<?php echo number_format($row['costo'], 2); ?></td>
                                        <td class="text-end fw-bold">$<?php echo number_format($row['precio_actual'], 2); ?></td>
                                        <td class="text-end">
                                            <input type="number" step="0.01" min="0" class="form-control form-control-sm text-end tentative-price-input" value="<?php echo number_format($row['precio_nuevo'], 2, '.', ''); ?>" data-code="<?php echo htmlspecialchars($row['codigo'], ENT_QUOTES, 'UTF-8'); ?>">
                                        </td>
                                        <td class="text-end" id="rowVentaReal_<?php echo htmlspecialchars($row['row_id'], ENT_QUOTES, 'UTF-8'); ?>">$<?php echo number_format($row['venta_real'], 2); ?></td>
                                        <td class="text-end fw-bold" id="rowVentaSim_<?php echo htmlspecialchars($row['row_id'], ENT_QUOTES, 'UTF-8'); ?>">$<?php echo number_format($row['venta_simulada'], 2); ?></td>
                                        <td class="text-end text-success" id="rowGanReal_<?php echo htmlspecialchars($row['row_id'], ENT_QUOTES, 'UTF-8'); ?>">$<?php echo number_format($row['ganancia_real'], 2); ?></td>
                                        <td class="text-end text-success fw-bold" id="rowGanSim_<?php echo htmlspecialchars($row['row_id'], ENT_QUOTES, 'UTF-8'); ?>">$<?php echo number_format($row['ganancia_simulada'], 2); ?></td>
                                        <td class="text-end" id="rowDelta_<?php echo htmlspecialchars($row['row_id'], ENT_QUOTES, 'UTF-8'); ?>">$<?php echo number_format($row['delta_ganancia'], 2); ?></td>
                                        <td class="text-center" id="rowMargin_<?php echo htmlspecialchars($row['row_id'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo number_format($row['precio_actual'] > 0 ? (($row['ganancia_simulada'] / $row['venta_simulada']) * 100) : 0, 1); ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="subtotal-row" data-subtotal-for="<?php echo htmlspecialchars($catKey, ENT_QUOTES, 'UTF-8'); ?>">
                                    <td colspan="3" class="text-end">Subtotal <?php echo htmlspecialchars($catVals['categoria'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="text-center" id="catQty_<?php echo htmlspecialchars($catKey, ENT_QUOTES, 'UTF-8'); ?>"><?php echo number_format($catVals['qty'], 2); ?></td>
                                    <td></td>
                                    <td></td>
                                    <td></td>
                                    <td class="text-end" id="catVentaReal_<?php echo htmlspecialchars($catKey, ENT_QUOTES, 'UTF-8'); ?>">$<?php echo number_format($catVals['venta_real'], 2); ?></td>
                                    <td class="text-end fw-bold" id="catVentaSim_<?php echo htmlspecialchars($catKey, ENT_QUOTES, 'UTF-8'); ?>">$<?php echo number_format($catVals['venta_simulada'], 2); ?></td>
                                    <td class="text-end text-success" id="catGanReal_<?php echo htmlspecialchars($catKey, ENT_QUOTES, 'UTF-8'); ?>">$<?php echo number_format($catVals['ganancia_real'], 2); ?></td>
                                    <td class="text-end text-success fw-bold" id="catGanSim_<?php echo htmlspecialchars($catKey, ENT_QUOTES, 'UTF-8'); ?>">$<?php echo number_format($catVals['ganancia_simulada'], 2); ?></td>
                                    <td class="text-end" id="catDelta_<?php echo htmlspecialchars($catKey, ENT_QUOTES, 'UTF-8'); ?>">$<?php echo number_format($catVals['delta_ganancia'], 2); ?></td>
                                    <td class="text-center" id="catMargin_<?php echo htmlspecialchars($catKey, ENT_QUOTES, 'UTF-8'); ?>"><?php echo number_format($catVals['venta_simulada'] > 0 ? ($catVals['ganancia_simulada'] / $catVals['venta_simulada']) * 100 : 0, 1); ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                            <tr style="border-top: 2px solid #333; font-weight: bold; background: #f8f9fa;">
                                <td colspan="3" class="text-end">TOTAL GENERAL</td>
                                <td class="text-center" id="totalQtyModel"><?php echo number_format($modelSummary['qty'], 2); ?></td>
                                <td></td>
                                <td></td>
                                <td></td>
                                <td class="text-end" id="totalVentaReal">$<?php echo number_format($modelSummary['venta_real'], 2); ?></td>
                                <td class="text-end fw-bold" id="totalVentaSimulada">$<?php echo number_format($modelSummary['venta_simulada'], 2); ?></td>
                                <td class="text-end text-success" id="totalGananciaReal">$<?php echo number_format($modelSummary['ganancia_real'], 2); ?></td>
                                <td class="text-end text-success fw-bold" id="totalGananciaSimulada">$<?php echo number_format($modelSummary['ganancia_simulada'], 2); ?></td>
                                <td class="text-end" id="totalDeltaGanancia">$<?php echo number_format($modelSummary['delta_ganancia'], 2); ?></td>
                                <td class="text-center" id="totalMargenSimulado"><?php echo number_format($modelSummary['margen_simulado'], 1); ?>%</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="mt-3 text-muted small text-end no-print">
            Generado el: <?php echo date('d/m/Y H:i:s'); ?> | Escenario: <?php echo htmlspecialchars($fechaInicioModelo, ENT_QUOTES, 'UTF-8'); ?> a <?php echo htmlspecialchars($fechaFinModelo, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

</div>

<?php if ($isModelView): ?>
<script>
(function() {
    const storageKey = 'inventory_price_model_scenarios_<?php echo (int)$EMP_ID; ?>_<?php echo (int)$SUC_ID; ?>_<?php echo (int)$ALM_ID; ?>';
    const scenarioKey = '<?php echo htmlspecialchars($fechaInicioModelo . '|' . $fechaFinModelo, ENT_QUOTES, 'UTF-8'); ?>';

    function money(value) {
        const n = Number(value || 0);
        return '$' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function percent(value) {
        const n = Number(value || 0);
        return n.toLocaleString('en-US', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '%';
    }

    function loadStore() {
        try {
            return JSON.parse(localStorage.getItem(storageKey) || '{}') || {};
        } catch (e) {
            return {};
        }
    }

    function saveStore(store) {
        localStorage.setItem(storageKey, JSON.stringify(store));
    }

    function getScenarioPrices() {
        const store = loadStore();
        return store[scenarioKey] || {};
    }

    function setScenarioPrices(values) {
        const store = loadStore();
        store[scenarioKey] = values;
        saveStore(store);
    }

    function resetScenario() {
        const store = loadStore();
        delete store[scenarioKey];
        saveStore(store);
        document.querySelectorAll('.tentative-price-input').forEach((input) => {
            input.value = Number(input.dataset.realprice || 0).toFixed(2);
        });
        recalc();
    }

    function recalc() {
        const rows = Array.from(document.querySelectorAll('.model-row'));
        const categories = {};
        let totalQty = 0;
        let totalVentaReal = 0;
        let totalVentaSim = 0;
        let totalGanReal = 0;
        let totalGanSim = 0;

        rows.forEach((row) => {
            const code = row.dataset.code;
            const rowId = row.dataset.rowid || code;
            const catKey = row.dataset.catkey;
            const qty = Number(row.dataset.qty || 0);
            const cost = Number(row.dataset.cost || 0);
            const realPrice = Number(row.dataset.realprice || 0);
            const input = row.querySelector('.tentative-price-input');
            const tentative = Math.max(0, Number(input && input.value ? input.value : realPrice) || 0);
            if (input && input.value === '') {
                input.value = tentative.toFixed(2);
            }

            const ventaReal = Number(row.dataset.realSale || 0);
            const gananciaReal = Number(row.dataset.realProfit || 0);
            const ventaSim = qty * tentative;
            const gananciaSim = qty * (tentative - cost);
            const delta = gananciaSim - gananciaReal;
            const margenSim = ventaSim > 0 ? (gananciaSim / ventaSim) * 100 : 0;

            totalQty += qty;
            totalVentaReal += ventaReal;
            totalVentaSim += ventaSim;
            totalGanReal += gananciaReal;
            totalGanSim += gananciaSim;

            categories[catKey] = categories[catKey] || {
                qty: 0, ventaReal: 0, ventaSim: 0, ganReal: 0, ganSim: 0, delta: 0
            };
            categories[catKey].qty += qty;
            categories[catKey].ventaReal += ventaReal;
            categories[catKey].ventaSim += ventaSim;
            categories[catKey].ganReal += gananciaReal;
            categories[catKey].ganSim += gananciaSim;
            categories[catKey].delta += delta;

            const ventaRealCell = document.getElementById('rowVentaReal_' + rowId);
            const ventaSimCell = document.getElementById('rowVentaSim_' + rowId);
            const ganRealCell = document.getElementById('rowGanReal_' + rowId);
            const ganSimCell = document.getElementById('rowGanSim_' + rowId);
            const deltaCell = document.getElementById('rowDelta_' + rowId);
            const marginCell = document.getElementById('rowMargin_' + rowId);

            if (ventaRealCell) ventaRealCell.textContent = money(ventaReal);
            if (ventaSimCell) ventaSimCell.textContent = money(ventaSim);
            if (ganRealCell) ganRealCell.textContent = money(gananciaReal);
            if (ganSimCell) ganSimCell.textContent = money(gananciaSim);
            if (deltaCell) deltaCell.textContent = money(delta);
            if (marginCell) marginCell.textContent = percent(margenSim);
        });

        Object.keys(categories).forEach((catKey) => {
            const vals = categories[catKey];
            const qtyCell = document.getElementById('catQty_' + catKey);
            const ventaRealCell = document.getElementById('catVentaReal_' + catKey);
            const ventaSimCell = document.getElementById('catVentaSim_' + catKey);
            const ganRealCell = document.getElementById('catGanReal_' + catKey);
            const ganSimCell = document.getElementById('catGanSim_' + catKey);
            const deltaCell = document.getElementById('catDelta_' + catKey);
            const marginCell = document.getElementById('catMargin_' + catKey);

            if (qtyCell) qtyCell.textContent = vals.qty.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            if (ventaRealCell) ventaRealCell.textContent = money(vals.ventaReal);
            if (ventaSimCell) ventaSimCell.textContent = money(vals.ventaSim);
            if (ganRealCell) ganRealCell.textContent = money(vals.ganReal);
            if (ganSimCell) ganSimCell.textContent = money(vals.ganSim);
            if (deltaCell) deltaCell.textContent = money(vals.delta);
            if (marginCell) marginCell.textContent = percent(vals.ventaSim > 0 ? (vals.ganSim / vals.ventaSim) * 100 : 0);
        });

        const totalDelta = totalGanSim - totalGanReal;
        const totalMargin = totalVentaSim > 0 ? (totalGanSim / totalVentaSim) * 100 : 0;
        const incrementoPct = totalGanReal !== 0 ? (totalDelta / Math.abs(totalGanReal)) * 100 : 0;

        const map = {
            kpiVentaReal: totalVentaReal,
            kpiVentaSimulada: totalVentaSim,
            kpiGananciaReal: totalGanReal,
            kpiGananciaSimulada: totalGanSim,
            kpiDeltaGanancia: totalDelta,
            kpiProductosModelados: rows.length,
            kpiMargenReal: totalVentaReal > 0 ? (totalGanReal / totalVentaReal) * 100 : 0,
            kpiMargenSimulado: totalMargin,
            kpiUnidadesNetas: totalQty,
        };

        Object.keys(map).forEach((id) => {
            const el = document.getElementById(id);
            if (!el) return;
            if (id === 'kpiProductosModelados') {
                el.textContent = Number(map[id]).toLocaleString('en-US', { maximumFractionDigits: 0 });
            } else if (id === 'kpiUnidadesNetas') {
                el.textContent = Number(map[id]).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
            } else if (id === 'kpiMargenReal' || id === 'kpiMargenSimulado') {
                el.textContent = percent(map[id]);
            } else {
                el.textContent = money(map[id]);
            }
        });
        const inc = document.getElementById('kpiIncrementoPct');
        if (inc) inc.textContent = percent(incrementoPct);

        setScenarioPrices(Object.fromEntries(rows.map((row) => {
            const code = row.dataset.code;
            const input = row.querySelector('.tentative-price-input');
            const realPrice = Number(row.dataset.realprice || 0);
            const value = Math.max(0, Number(input && input.value ? input.value : realPrice) || 0);
            return [code, value];
        })));

        const totalQtyCell = document.getElementById('totalQtyModel');
        const totalVentaRealCell = document.getElementById('totalVentaReal');
        const totalVentaSimCell = document.getElementById('totalVentaSimulada');
        const totalGanRealCell = document.getElementById('totalGananciaReal');
        const totalGanSimCell = document.getElementById('totalGananciaSimulada');
        const totalDeltaCell = document.getElementById('totalDeltaGanancia');
        const totalMarginCell = document.getElementById('totalMargenSimulado');
        if (totalQtyCell) totalQtyCell.textContent = totalQty.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        if (totalVentaRealCell) totalVentaRealCell.textContent = money(totalVentaReal);
        if (totalVentaSimCell) totalVentaSimCell.textContent = money(totalVentaSim);
        if (totalGanRealCell) totalGanRealCell.textContent = money(totalGanReal);
        if (totalGanSimCell) totalGanSimCell.textContent = money(totalGanSim);
        if (totalDeltaCell) totalDeltaCell.textContent = money(totalDelta);
        if (totalMarginCell) totalMarginCell.textContent = percent(totalMargin);
    }

    document.querySelectorAll('.tentative-price-input').forEach((input) => {
        input.addEventListener('input', recalc);
    });

    const saved = getScenarioPrices();
    document.querySelectorAll('.tentative-price-input').forEach((input) => {
        const code = input.dataset.code;
        if (saved && Object.prototype.hasOwnProperty.call(saved, code)) {
            input.value = Number(saved[code] || 0).toFixed(2);
        }
    });

    const resetBtn = document.getElementById('btnResetScenario');
    if (resetBtn) {
        resetBtn.addEventListener('click', resetScenario);
    }

    recalc();
})();
</script>
<?php endif; ?>


<?php include_once 'menu_master.php'; ?>
</body>
</html>
