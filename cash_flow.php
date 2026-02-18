<?php
// ARCHIVO: cash_flow.php
// DESCRIPCIÓN: Flujo de Caja Consolidado (Multisucursal)
// VERSIÓN: 7.0
require_once 'db.php';
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

require_once 'config_loader.php';
$ALM_ID = intval($config['id_almacen']);
$SUC_ID = intval($config['id_sucursal']);
$EMP_ID = intval($config['id_empresa']);

// AUTO-INSTALACIÓN
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS flujo_caja_mensual (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fecha DATE NOT NULL,
        concepto_key VARCHAR(50) NOT NULL,
        valor TEXT, -- Cambiado a TEXT para soportar notas
        id_sucursal INT DEFAULT 1,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_celda (fecha, concepto_key, id_sucursal)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (PDOException $e) {}

$mes = isset($_GET['mes']) ? intval($_GET['mes']) : intval(date('m'));
$anio = isset($_GET['anio']) ? intval($_GET['anio']) : intval(date('Y'));
$sucursal_contexto = isset($_GET['sucursal']) ? intval($_GET['sucursal']) : $SUC_ID;

$numDias = cal_days_in_month(CAL_GREGORIAN, $mes, $anio);
$dias = [];
for ($i = 1; $i <= $numDias; $i++) {
    $timestamp = mktime(0, 0, 0, $mes, $i, $anio);
    $dias[$i] = ['fecha' => date('Y-m-d', $timestamp), 'label' => 'Día ' . $i];
}

// MANEJO DE PETICIONES GET (EXPORTAR RAW)
if (isset($_GET['action']) && $_GET['action'] === 'export_raw') {
    $stmtLoad = $pdo->prepare("SELECT fecha, concepto_key, valor FROM flujo_caja_mensual WHERE MONTH(fecha) = ? AND YEAR(fecha) = ? AND id_sucursal = ?");
    $stmtLoad->execute([$mes, $anio, $sucursal_contexto]);
    $data = $stmtLoad->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="cash_flow_backup_'.$mes.'_'.$anio.'.json"');
    echo json_encode(['mes' => $mes, 'anio' => $anio, 'sucursal' => $sucursal_contexto, 'data' => $data]);
    exit;
}

// MANEJO DE PETICIONES POST (RESTAURAR)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);

    if (isset($input['action']) && $input['action'] === 'import_raw') {
        try {
            $pdo->beginTransaction();
            foreach ($input['data'] as $row) {
                $stmt = $pdo->prepare("INSERT INTO flujo_caja_mensual (fecha, concepto_key, valor, id_sucursal) 
                                       VALUES (?, ?, ?, ?) 
                                       ON DUPLICATE KEY UPDATE valor = ?");
                $stmt->execute([$row['fecha'], $row['concepto_key'], $row['valor'], $sucursal_contexto, $row['valor']]);
            }
            $pdo->commit();
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) { 
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]); 
        }
        exit;
    }
    
    if (isset($input['action']) && $input['action'] === 'save_cell') {
        try {
            $stmt = $pdo->prepare("INSERT INTO flujo_caja_mensual (fecha, concepto_key, valor, id_sucursal) 
                                   VALUES (?, ?, ?, ?) 
                                   ON DUPLICATE KEY UPDATE valor = ?");
            $stmt->execute([$input['fecha'], $input['key'], $input['val'], $sucursal_contexto, $input['val']]);
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) { echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]); }
        exit;
    }

    if (isset($input['action']) && $input['action'] === 'fetch_system') {
        $fecha = $input['fecha'];
        $data = [];
        try {
            // Consultar Ventas Informativas por cada sucursal (1 a 6)
            for ($s = 1; $s <= 6; $s++) {
                $stmtV = $pdo->prepare("SELECT SUM(v.total) FROM ventas_cabecera v INNER JOIN caja_sesiones s ON v.id_sesion_caja = s.id WHERE s.fecha_contable = ? AND v.id_sucursal = ? AND (v.estado_reserva IS NULL OR v.estado_reserva != 'ANULADA')");
                $stmtV->execute([$fecha, $s]);
                $data['ventas_suc_'.$s] = floatval($stmtV->fetchColumn() ?: 0);
            }

            // Consultar Inventarios de las 6 Sucursales
            for ($i = 1; $i <= 6; $i++) {
                $sqlK = "SELECT SUM(k.saldo_actual * COALESCE(NULLIF(k.costo_unitario, 0), p.costo, 0)) 
                         FROM kardex k INNER JOIN productos p ON k.id_producto = p.codigo
                         INNER JOIN (SELECT id_producto, id_almacen, MAX(id) as max_id FROM kardex WHERE fecha <= ? AND id_sucursal = ? GROUP BY id_producto, id_almacen) latest ON k.id = latest.max_id
                         WHERE k.id_sucursal = ?";
                $stmtK = $pdo->prepare($sqlK);
                $stmtK->execute([$fecha . ' 23:59:59', $i, $i]);
                $data['inv_'.$i] = floatval($stmtK->fetchColumn() ?: 0);
            }
            echo json_encode(['status' => 'success', 'data' => $data]);
        } catch (Exception $e) { echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]); }
        exit;
    }
}

// CARGAR DATOS
$stmtLoad = $pdo->prepare("SELECT fecha, concepto_key, valor FROM flujo_caja_mensual WHERE MONTH(fecha) = ? AND YEAR(fecha) = ? AND id_sucursal = ?");
$stmtLoad->execute([$mes, $anio, $sucursal_contexto]);
$savedData = [];
while ($row = $stmtLoad->fetch(PDO::FETCH_ASSOC)) $savedData[$row['fecha']][$row['concepto_key']] = $row['valor'];

function getVal($savedData, $fecha, $key) { return isset($savedData[$fecha][$key]) ? $savedData[$fecha][$key] : ''; }

function getDiaSemana($fecha) {
    $dias = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
    return $dias[date('w', strtotime($fecha))];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8"><title>Flujo de Caja Consolidado</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root { --bg-inv: #f1f8e9; --bg-ing: #e3f2fd; --bg-gst: #fff3e0; --header-bg: #2c3e50; }
        body { background-color: #f4f7f6; font-family: 'Segoe UI', sans-serif; font-size: 0.8rem; }
        .navbar-custom { background: var(--header-bg); color: white; padding: 0.5rem 1rem; }
        .excel-wrapper { overflow-x: auto; background: white; border-radius: 8px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); margin-bottom: 20px; }
        table { border-collapse: separate; border-spacing: 0; width: 100%; }
        th, td { border: 1px solid #dee2e6; padding: 4px 8px; min-width: 100px; text-align: right; }
        th:first-child, td:first-child { position: sticky; left: 0; background: #fff; z-index: 10; min-width: 250px; text-align: left; font-weight: 600; border-right: 2px solid #ddd; }
        thead th { position: sticky; top: 0; background: #eee; z-index: 5; text-align: center; }
        .cell-input { width: 100%; border: none; background: transparent; text-align: right; outline: none; font-family: monospace; }
        .cell-input:focus { background: #fff; box-shadow: inset 0 0 0 2px #3498db; }
        .bg-inv { background-color: var(--bg-inv); }
        .bg-ing { background-color: var(--bg-ing); }
        .bg-gst { background-color: var(--bg-gst); }
        .row-subtotal { background-color: #f8f9fa; font-weight: bold; border-top: 2px solid #ccc; }
        .row-total-main { background-color: #2c3e50; color: white; font-weight: bold; }
        .row-total-main td:first-child { background-color: #2c3e50; color: white; }
        .row-total-main input { color: white; }
        .row-info { background-color: #e1f5fe; color: #0277bd; font-weight: bold; }
        .row-notes { background-color: #fafafa; font-style: italic; }
        .row-notes input { text-align: left; font-family: sans-serif; }
        
        /* Collapsible styles */
        .group-header { cursor: pointer; background-color: #eceff1 !important; user-select: none; }
        .group-header:hover { background-color: #cfd8dc !important; }
        .group-header i { transition: transform 0.2s; margin-right: 8px; }
        .group-header.collapsed i { transform: rotate(-90deg); }
        .hidden-row { display: none !important; }

        @media print {
            .no-print, .btn-add-cost, .navbar-custom { display: none !important; }
            .excel-wrapper { overflow: visible !important; box-shadow: none; }
            th, td { font-size: 7pt; padding: 2px !important; }
            th:first-child, td:first-child { position: relative !important; min-width: 150px !important; }
            body { background: white; padding: 0; }
            @page { size: landscape; margin: 0.5cm; }
        }

        /* Estilos para Multi-Entradas de Costos */
        .cost-container { display: flex; flex-direction: column; gap: 2px; align-items: flex-end; }
        .cost-entry { display: flex; align-items: center; gap: 2px; width: 100%; justify-content: flex-end; }
        .btn-add-cost { padding: 0 4px; font-size: 0.7rem; color: #2e7d32; cursor: pointer; border: 1px solid #2e7d32; border-radius: 3px; background: white; }
        .btn-add-cost:hover { background: #e8f5e9; }
        .cost-pill { font-size: 0.7rem; padding: 1px 4px; background: #fff3e0; border: 1px solid #ffe0b2; border-radius: 4px; cursor: help; }
    </style>
</head>
<body>

<nav class="navbar-custom d-flex justify-content-between align-items-center no-print">
    <div class="d-flex gap-3 align-items-center">
        <h6 class="m-0 fw-bold"><i class="fas fa-file-invoice-dollar me-2"></i> FLUJO CONSOLIDADO</h6>
        <span class="badge bg-light text-dark">Sucursal: <?php echo $SUC_ID; ?></span>
    </div>
    <div class="d-flex gap-2">
        <div class="dropdown no-print">
            <button class="btn btn-outline-light btn-sm dropdown-toggle fw-bold" type="button" data-bs-toggle="dropdown">
                <i class="fas fa-file-export me-1"></i> Acciones
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow">
                <li><a class="dropdown-item" href="#" onclick="exportToExcel()"><i class="fas fa-file-excel me-2 text-success"></i> Exportar a Excel</a></li>
                <li><a class="dropdown-item" href="#" onclick="window.print()"><i class="fas fa-file-pdf me-2 text-danger"></i> Exportar a PDF</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="?action=export_raw&mes=<?php echo $mes; ?>&anio=<?php echo $anio; ?>&sucursal=<?php echo $sucursal_contexto; ?>"><i class="fas fa-database me-2 text-primary"></i> Respaldar Datos (JSON)</a></li>
                <li><a class="dropdown-item" href="#" onclick="document.getElementById('importFile').click()"><i class="fas fa-upload me-2 text-warning"></i> Restaurar Datos</a></li>
            </ul>
            <input type="file" id="importFile" style="display:none" onchange="importData(this)">
        </div>
        <button onclick="syncAll()" class="btn btn-warning btn-sm fw-bold"><i class="fas fa-sync me-1"></i> Sincronizar Mes</button>
        <form class="d-flex gap-1">
            <select name="mes" class="form-select form-select-sm">
                <?php $mN = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
                foreach($mN as $k=>$v) echo "<option value='".($k+1)."' ".($k+1==$mes?'selected':'').">$v</option>"; ?>
            </select>
            <button class="btn btn-primary btn-sm">Ver</button>
        </form>
    </div>
</nav>

<div class="container-fluid p-3">
    <div class="excel-wrapper">
        <table id="cashFlowTable">
            <thead>
                <tr>
                    <th>CONCEPTO / DÍA</th>
                    <?php foreach($dias as $n=>$i): ?>
                        <th class="text-center">
                            <div class="small opacity-75"><?php echo getDiaSemana($i['fecha']); ?></div>
                            <div class="fs-6"><?php echo $n; ?></div>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <!-- GRUPO VENTAS (INFORMATIVO) -->
                <tr class="group-header" onclick="toggleGroup('ventas')">
                    <td colspan="<?php echo $numDias+1; ?>"><i class="fas fa-chevron-down"></i> <i class="fas fa-info-circle me-1"></i> VENTAS POR SUCURSAL (INFORMATIVO)</td>
                </tr>
                <?php for($s=1; $s<=6; $s++): ?>
                <tr class="row-info group-ventas">
                    <td>Ventas Sucursal #<?php echo $s; ?></td>
                    <?php foreach($dias as $n=>$i): ?>
                        <td><input type="number" step="0.01" readonly class="cell-input col-<?php echo $n; ?>" id="ventas_suc_<?php echo $s; ?>_<?php echo $n; ?>" data-fecha="<?php echo $i['fecha']; ?>" data-key="ventas_suc_<?php echo $s; ?>" value="<?php echo getVal($savedData, $i['fecha'], "ventas_suc_$s"); ?>"></td>
                    <?php endforeach; ?>
                </tr>
                <?php endfor; ?>
                <tr class="row-subtotal group-ventas">
                    <td>SUMA TOTAL VENTAS</td>
                    <?php foreach($dias as $n=>$i): ?><td id="sub_ventas_<?php echo $n; ?>">0.00</td><?php endforeach; ?>
                </tr>

                <!-- GRUPO INVENTARIOS -->
                <tr class="group-header" onclick="toggleGroup('inventarios')">
                    <td colspan="<?php echo $numDias+1; ?>"><i class="fas fa-chevron-down"></i> 📦 INVENTARIOS SUCURSALES</td>
                </tr>
                <?php for($s=1; $s<=6; $s++): ?>
                <tr class="bg-inv group-inventarios">
                    <td>Inventario Sucursal #<?php echo $s; ?></td>
                    <?php foreach($dias as $n=>$i): ?>
                        <td><input type="number" step="0.01" class="cell-input inv-val col-<?php echo $n; ?>" id="inv_<?php echo $s; ?>_<?php echo $n; ?>" data-fecha="<?php echo $i['fecha']; ?>" data-key="inv_<?php echo $s; ?>" value="<?php echo getVal($savedData, $i['fecha'], "inv_$s"); ?>"></td>
                    <?php endforeach; ?>
                </tr>
                <?php endfor; ?>
                <tr class="row-subtotal group-inventarios">
                    <td>SUMA TOTAL INVENTARIOS</td>
                    <?php foreach($dias as $n=>$i): ?><td id="sub_inv_<?php echo $n; ?>">0.00</td><?php endforeach; ?>
                </tr>

                <!-- GRUPO INGRESOS -->
                <tr class="group-header" onclick="toggleGroup('ingresos')">
                    <td colspan="<?php echo $numDias+1; ?>"><i class="fas fa-chevron-down"></i> 💰 DISPONIBILIDAD (INGRESOS REALES)</td>
                </tr>
                <tr class="bg-ing group-ingresos">
                    <td><span style="color: #2e7d32;"><i class="fas fa-money-bill-wave me-1"></i>Recaudación Marinero</span></td>
                    <?php foreach($dias as $n=>$i): ?><td><input type="number" step="0.01" class="cell-input ing-val ing-efectivo col-<?php echo $n; ?>" data-fecha="<?php echo $i['fecha']; ?>" data-key="recaudacion_marinero" value="<?php echo getVal($savedData, $i['fecha'], 'recaudacion_marinero'); ?>"></td><?php endforeach; ?>
                </tr>
                <tr class="bg-ing group-ingresos">
                    <td><span style="color: #2e7d32;"><i class="fas fa-money-bill-wave me-1"></i>Recaudación Magnolia</span></td>
                    <?php foreach($dias as $n=>$i): ?><td><input type="number" step="0.01" class="cell-input ing-val ing-efectivo col-<?php echo $n; ?>" data-fecha="<?php echo $i['fecha']; ?>" data-key="recaudacion_magnolia" value="<?php echo getVal($savedData, $i['fecha'], 'recaudacion_magnolia'); ?>"></td><?php endforeach; ?>
                </tr>
                <tr class="bg-ing group-ingresos">
                    <td><span style="color: #1565c0;"><i class="fas fa-exchange-alt me-1"></i>Ingresos en Transferencias</span></td>
                    <?php foreach($dias as $n=>$i): ?><td><input type="number" step="0.01" class="cell-input ing-val ing-banco col-<?php echo $n; ?>" data-fecha="<?php echo $i['fecha']; ?>" data-key="ingresos_transferencias" value="<?php echo getVal($savedData, $i['fecha'], 'ingresos_transferencias'); ?>"></td><?php endforeach; ?>
                </tr>
                <tr class="bg-ing group-ingresos">
                    <td><span style="color: #1565c0;"><i class="fas fa-university me-1"></i>Saldo en Bancos</span></td>
                    <?php foreach($dias as $n=>$i): ?><td><input type="number" step="0.01" class="cell-input ing-val ing-banco col-<?php echo $n; ?>" data-fecha="<?php echo $i['fecha']; ?>" data-key="banco" value="<?php echo getVal($savedData, $i['fecha'], 'banco'); ?>"></td><?php endforeach; ?>
                </tr>
                <tr class="bg-ing group-ingresos">
                    <td><span style="color: #2e7d32;"><i class="fas fa-cash-register me-1"></i>Saldo en Caja</span></td>
                    <?php foreach($dias as $n=>$i): ?><td><input type="number" step="0.01" class="cell-input ing-val ing-efectivo col-<?php echo $n; ?>" data-fecha="<?php echo $i['fecha']; ?>" data-key="caja" value="<?php echo getVal($savedData, $i['fecha'], 'caja'); ?>"></td><?php endforeach; ?>
                </tr>
                <tr class="bg-ing group-ingresos">
                    <td>
                        <span style="color: #2e7d32;"><i class="fas fa-money-bill-wave me-1"></i>Otros Ingresos</span>
                        <small class="text-muted d-block" style="font-size: 0.65rem;">Detalle de entradas extras</small>
                    </td>
                    <?php foreach($dias as $n=>$i): 
                        $valRawIng = getVal($savedData, $i['fecha'], 'otros_ing');
                        $entriesIng = json_decode($valRawIng, true) ?: [];
                        $totalDiaIng = 0;
                        foreach($entriesIng as $e) $totalDiaIng += floatval($e['monto'] ?? 0);
                    ?>
                        <td class="ing-multi-cell" data-fecha="<?php echo $i['fecha']; ?>" data-key="otros_ing">
                            <div class="cost-container" id="container_otros_ing_<?php echo $n; ?>">
                                <?php foreach($entriesIng as $idx => $e): ?>
                                    <div class="cost-pill bg-primary border-primary text-white" style="background-color: #e3f2fd !important; color: #0d6efd !important; border-color: #bbdefb !important;" title="<?php echo htmlspecialchars($e['nota'] ?? ''); ?>" onclick="editOtherEntry(<?php echo $n; ?>, <?php echo $idx; ?>)">
                                        $<?php echo number_format($e['monto'], 2); ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-1">
                                <button class="btn-add-cost" style="color: #0d6efd; border-color: #0d6efd;" onclick="addOtherEntry(<?php echo $n; ?>)">+</button>
                                <input type="hidden" class="cell-input ing-val ing-efectivo col-<?php echo $n; ?>" id="otros_ing_<?php echo $n; ?>"
                                       data-fecha="<?php echo $i['fecha']; ?>"
                                       data-key="otros_ing"
                                       data-json='<?php echo htmlspecialchars($valRawIng ?: "[]", ENT_QUOTES); ?>'
                                       value="<?php echo $totalDiaIng; ?>">
                                <span class="fw-bold small" id="total_otros_ing_label_<?php echo $n; ?>"><?php echo number_format($totalDiaIng, 2); ?></span>
                            </div>
                        </td>
                    <?php endforeach; ?>
                </tr>
                <tr class="row-notes group-ingresos">
                    <td><i class="fas fa-edit me-1"></i> Notas Ingresos</td>
                    <?php foreach($dias as $n=>$i): ?>
                        <td><input type="text" class="cell-input col-<?php echo $n; ?>" data-fecha="<?php echo $i['fecha']; ?>" data-key="notas_ingresos" value="<?php echo getVal($savedData, $i['fecha'], 'notas_ingresos'); ?>" placeholder="..."></td>
                    <?php endforeach; ?>
                </tr>
                <tr class="row-subtotal group-ingresos" style="color: #2e7d32;">
                    <td><i class="fas fa-money-bill-wave me-1"></i>SUBTOTAL EFECTIVO</td>
                    <?php foreach($dias as $n=>$i): ?><td id="sub_efectivo_<?php echo $n; ?>">0.00</td><?php endforeach; ?>
                </tr>
                <tr class="row-subtotal group-ingresos" style="color: #1565c0;">
                    <td><i class="fas fa-university me-1"></i>SUBTOTAL BANCO</td>
                    <?php foreach($dias as $n=>$i): ?><td id="sub_banco_<?php echo $n; ?>">0.00</td><?php endforeach; ?>
                </tr>
                <tr class="row-subtotal group-ingresos" style="color: #2e7d32;">
                    <td>TOTAL DISPONIBLE</td>
                    <?php foreach($dias as $n=>$i): ?><td id="sub_ing_<?php echo $n; ?>">0.00</td><?php endforeach; ?>
                </tr>

                <!-- GRUPO GASTOS -->
                <tr class="group-header" onclick="toggleGroup('gastos')">
                    <td colspan="<?php echo $numDias+1; ?>"><i class="fas fa-chevron-down"></i> 💸 GASTOS Y EGRESOS</td>
                </tr>
                <tr class="bg-gst group-gastos">
                    <td>
                        Costos Mercancía (Compras)
                        <small class="text-muted d-block" style="font-size: 0.65rem;">Haz clic en el valor para editar/borrar</small>
                    </td>
                    <?php foreach($dias as $n=>$i): 
                        $valRaw = getVal($savedData, $i['fecha'], 'costos');
                        $entries = json_decode($valRaw, true) ?: [];
                        $totalDia = 0;
                        foreach($entries as $e) $totalDia += floatval($e['monto'] ?? 0);
                    ?>
                        <td class="gst-multi-cell" data-fecha="<?php echo $i['fecha']; ?>" data-key="costos">
                            <div class="cost-container" id="container_costos_<?php echo $n; ?>">
                                <?php foreach($entries as $idx => $e): ?>
                                    <div class="cost-pill" title="<?php echo htmlspecialchars($e['nota'] ?? ''); ?>" onclick="editCostEntry(<?php echo $n; ?>, <?php echo $idx; ?>)">
                                        $<?php echo number_format($e['monto'], 2); ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-1">
                                <button class="btn-add-cost" onclick="addCostEntry(<?php echo $n; ?>)">+</button>
                                <input type="hidden" class="cell-input gst-val col-<?php echo $n; ?>" id="costos_<?php echo $n; ?>" 
                                       data-fecha="<?php echo $i['fecha']; ?>" 
                                       data-key="costos" 
                                       data-json='<?php echo htmlspecialchars($valRaw ?: "[]", ENT_QUOTES); ?>'
                                       value="<?php echo $totalDia; ?>">
                                <span class="fw-bold small" id="total_costos_label_<?php echo $n; ?>"><?php echo number_format($totalDia, 2); ?></span>
                            </div>
                        </td>
                    <?php endforeach; ?>
                </tr>
                <tr class="bg-gst group-gastos">
                    <td>
                        Salarios
                        <small class="text-muted d-block" style="font-size: 0.65rem;">Haz clic en el valor para editar/borrar</small>
                    </td>
                    <?php foreach($dias as $n=>$i):
                        $valRawSal = getVal($savedData, $i['fecha'], 'salarios');
                        $entriesSal = json_decode($valRawSal, true) ?: [];
                        $totalDiaSal = 0;
                        foreach($entriesSal as $e) $totalDiaSal += floatval($e['monto'] ?? 0);
                    ?>
                        <td class="gst-multi-cell" data-fecha="<?php echo $i['fecha']; ?>" data-key="salarios">
                            <div class="cost-container" id="container_salarios_<?php echo $n; ?>">
                                <?php foreach($entriesSal as $idx => $e): ?>
                                    <div class="cost-pill" title="<?php echo htmlspecialchars($e['nota'] ?? ''); ?>" onclick="editSalarioEntry(<?php echo $n; ?>, <?php echo $idx; ?>)">
                                        $<?php echo number_format($e['monto'], 2); ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-1">
                                <button class="btn-add-cost" onclick="addSalarioEntry(<?php echo $n; ?>)">+</button>
                                <input type="hidden" class="cell-input gst-val col-<?php echo $n; ?>" id="salarios_<?php echo $n; ?>"
                                       data-fecha="<?php echo $i['fecha']; ?>"
                                       data-key="salarios"
                                       data-json='<?php echo htmlspecialchars($valRawSal ?: "[]", ENT_QUOTES); ?>'
                                       value="<?php echo $totalDiaSal; ?>">
                                <span class="fw-bold small" id="total_salarios_label_<?php echo $n; ?>"><?php echo number_format($totalDiaSal, 2); ?></span>
                            </div>
                        </td>
                    <?php endforeach; ?>
                </tr>
                <tr class="bg-gst group-gastos">
                    <td>Gastos Operativos / Otros</td>
                    <?php foreach($dias as $n=>$i): ?><td><input type="number" step="0.01" class="cell-input gst-val col-<?php echo $n; ?>" data-fecha="<?php echo $i['fecha']; ?>" data-key="gastos" value="<?php echo getVal($savedData, $i['fecha'], 'gastos'); ?>"></td><?php endforeach; ?>
                </tr>
                <tr class="row-notes group-gastos">
                    <td><i class="fas fa-edit me-1"></i> Notas Gastos</td>
                    <?php foreach($dias as $n=>$i): ?>
                        <td><input type="text" class="cell-input col-<?php echo $n; ?>" data-fecha="<?php echo $i['fecha']; ?>" data-key="notas" value="<?php echo getVal($savedData, $i['fecha'], 'notas'); ?>" placeholder="..."></td>
                    <?php endforeach; ?>
                </tr>
                <tr class="row-subtotal group-gastos" style="color: #c62828;">
                    <td>TOTAL GASTOS</td>
                    <?php foreach($dias as $n=>$i): ?><td id="sub_gst_<?php echo $n; ?>">0.00</td><?php endforeach; ?>
                </tr>

                <tr class="row-subtotal" style="color: #2e7d32; background-color: #e8f5e9;">
                    <td><i class="fas fa-money-bill-wave me-1"></i>EFECTIVO DISPONIBLE (Efectivo - Gastos)</td>
                    <?php foreach($dias as $n=>$i): ?><td id="efectivo_disponible_<?php echo $n; ?>">0.00</td><?php endforeach; ?>
                </tr>
                <tr class="row-total-main">
                    <td>SALDO FINAL (Inv + Disponibilidad - Gastos)</td>
                    <?php foreach($dias as $n=>$i): ?><td><input type="text" readonly id="total_fin_<?php echo $n; ?>" class="cell-input"></td><?php endforeach; ?>
                </tr>
                <!-- Fila de fechas al final similar a la cabecera -->
                <tr class="table-light text-center fw-bold">
                    <td>DÍA / SEMANA</td>
                    <?php foreach($dias as $n=>$i): ?>
                        <td>
                            <div class="small opacity-75"><?php echo getDiaSemana($i['fecha']); ?></div>
                            <div><?php echo $n; ?></div>
                        </td>
                    <?php endforeach; ?>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="card border-0 shadow-sm"><div class="card-body"><canvas id="flowChart" style="height:300px;"></canvas></div></div>
</div>

<!-- Modal para Costos -->
<div class="modal fade" id="costModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content shadow">
            <div class="modal-header py-2 bg-success text-white">
                <h6 class="modal-title">Detalle de Compra</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-2">
                <input type="hidden" id="modal_day">
                <input type="hidden" id="modal_index">
                <div class="mb-2">
                    <label class="small fw-bold">Monto $</label>
                    <input type="number" step="0.01" id="modal_monto" class="form-control form-control-sm">
                </div>
                <div>
                    <label class="small fw-bold">Nota / Proveedor</label>
                    <textarea id="modal_nota" class="form-control form-control-sm" rows="2" placeholder="Ej: Compra Cárnicos..."></textarea>
                </div>
            </div>
            <div class="modal-footer py-1 d-flex justify-content-between">
                <button type="button" id="btnDeleteCost" class="btn btn-outline-danger btn-sm" onclick="deleteEntry()">Borrar</button>
                <button type="button" class="btn btn-success btn-sm" onclick="saveEntry()">Guardar</button>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
    const numDias = <?php echo $numDias; ?>;
    let chart;
    const costModal = new bootstrap.Modal(document.getElementById('costModal'));

    document.addEventListener('DOMContentLoaded', () => {
        recalcAll();
        initChart();
        document.querySelectorAll('.cell-input:not([id^="costos_"]):not([id^="otros_ing_"]):not([id^="salarios_"])').forEach(inp => {
            inp.addEventListener('input', () => { recalcColumn(getCol(inp)); updateChart(); });
            inp.addEventListener('change', () => saveToDB(inp));
        });
    });

    function getCol(el) { return el.className.split(' ').find(c => c.startsWith('col-')).split('-')[1]; }
    
    function recalcAll() { 
        for(let i=1; i<=numDias; i++) recalcColumn(i); 
        if(chart) updateChart(); 
    }

    function toggleGroup(groupName) {
        const header = event.currentTarget;
        const rows = document.querySelectorAll('.group-' + groupName);
        header.classList.toggle('collapsed');
        rows.forEach(r => r.classList.toggle('hidden-row'));
    }

    function recalcColumn(d) {
        let vts = 0, inv = 0, ing = 0, gst = 0, ingEfectivo = 0, ingBanco = 0;
        // Ventas
        for(let s=1; s<=6; s++) {
            const elV = document.getElementById(`ventas_suc_${s}_${d}`);
            if(elV) vts += parseFloat(elV.value)||0;
        }
        // Inventarios
        document.querySelectorAll(`.inv-val.col-${d}`).forEach(i => inv += parseFloat(i.value)||0);
        // Ingresos Efectivo
        document.querySelectorAll(`.ing-efectivo.col-${d}`).forEach(i => ingEfectivo += parseFloat(i.value)||0);
        // Ingresos Banco
        document.querySelectorAll(`.ing-banco.col-${d}`).forEach(i => ingBanco += parseFloat(i.value)||0);
        // Total Ingresos
        ing = ingEfectivo + ingBanco;
        // Gastos
        document.querySelectorAll(`.gst-val.col-${d}`).forEach(i => gst += parseFloat(i.value)||0);

        const subV = document.getElementById(`sub_ventas_${d}`);
        if(subV) subV.innerText = vts.toLocaleString('es-ES',{minimumFractionDigits:2});

        const subI = document.getElementById(`sub_inv_${d}`);
        if(subI) subI.innerText = inv.toLocaleString('es-ES',{minimumFractionDigits:2});

        const subEf = document.getElementById(`sub_efectivo_${d}`);
        if(subEf) subEf.innerText = ingEfectivo.toLocaleString('es-ES',{minimumFractionDigits:2});

        const subBa = document.getElementById(`sub_banco_${d}`);
        if(subBa) subBa.innerText = ingBanco.toLocaleString('es-ES',{minimumFractionDigits:2});

        const subIng = document.getElementById(`sub_ing_${d}`);
        if(subIng) subIng.innerText = ing.toLocaleString('es-ES',{minimumFractionDigits:2});

        const subG = document.getElementById(`sub_gst_${d}`);
        if(subG) subG.innerText = gst.toLocaleString('es-ES',{minimumFractionDigits:2});

        const efDisp = document.getElementById(`efectivo_disponible_${d}`);
        if(efDisp) efDisp.innerText = (ingEfectivo - gst).toLocaleString('es-ES',{minimumFractionDigits:2});

        const totalFin = document.getElementById(`total_fin_${d}`);
        if(totalFin) totalFin.value = (inv + ing - gst).toFixed(2);
    }

    function addCostEntry(day) {
        document.getElementById('modal_day').value = day;
        document.getElementById('modal_index').value = -1;
        document.getElementById('modal_monto').value = '';
        document.getElementById('modal_nota').value = '';
        document.getElementById('costModal').querySelector('.modal-header').className = 'modal-header py-2 bg-success text-white';
        document.getElementById('costModal').querySelector('.modal-title').innerText = 'Nueva Compra';
        document.getElementById('costModal').dataset.context = 'costos';
        document.getElementById('btnDeleteCost').style.display = 'none';
        costModal.show();
    }

    function editCostEntry(day, index) {
        fetchEntries(day, 'costos').then(entries => {
            const entry = entries[index];
            document.getElementById('modal_day').value = day;
            document.getElementById('modal_index').value = index;
            document.getElementById('modal_monto').value = entry.monto;
            document.getElementById('modal_nota').value = entry.nota;
            document.getElementById('costModal').querySelector('.modal-header').className = 'modal-header py-2 bg-success text-white';
            document.getElementById('costModal').querySelector('.modal-title').innerText = 'Editar Compra';
            document.getElementById('costModal').dataset.context = 'costos';
            document.getElementById('btnDeleteCost').style.display = 'block';
            costModal.show();
        });
    }

    function addOtherEntry(day) {
        document.getElementById('modal_day').value = day;
        document.getElementById('modal_index').value = -1;
        document.getElementById('modal_monto').value = '';
        document.getElementById('modal_nota').value = '';
        document.getElementById('costModal').querySelector('.modal-header').className = 'modal-header py-2 bg-primary text-white';
        document.getElementById('costModal').querySelector('.modal-title').innerText = 'Otro Ingreso';
        document.getElementById('costModal').dataset.context = 'otros_ing';
        document.getElementById('btnDeleteCost').style.display = 'none';
        costModal.show();
    }

    function editOtherEntry(day, index) {
        fetchEntries(day, 'otros_ing').then(entries => {
            const entry = entries[index];
            document.getElementById('modal_day').value = day;
            document.getElementById('modal_index').value = index;
            document.getElementById('modal_monto').value = entry.monto;
            document.getElementById('modal_nota').value = entry.nota;
            document.getElementById('costModal').querySelector('.modal-header').className = 'modal-header py-2 bg-primary text-white';
            document.getElementById('costModal').querySelector('.modal-title').innerText = 'Editar Ingreso';
            document.getElementById('costModal').dataset.context = 'otros_ing';
            document.getElementById('btnDeleteCost').style.display = 'block';
            costModal.show();
        });
    }

    function addSalarioEntry(day) {
        document.getElementById('modal_day').value = day;
        document.getElementById('modal_index').value = -1;
        document.getElementById('modal_monto').value = '';
        document.getElementById('modal_nota').value = '';
        document.getElementById('costModal').querySelector('.modal-header').className = 'modal-header py-2 bg-warning text-dark';
        document.getElementById('costModal').querySelector('.modal-title').innerText = 'Nuevo Salario';
        document.getElementById('costModal').dataset.context = 'salarios';
        document.getElementById('btnDeleteCost').style.display = 'none';
        costModal.show();
    }

    function editSalarioEntry(day, index) {
        fetchEntries(day, 'salarios').then(entries => {
            const entry = entries[index];
            document.getElementById('modal_day').value = day;
            document.getElementById('modal_index').value = index;
            document.getElementById('modal_monto').value = entry.monto;
            document.getElementById('modal_nota').value = entry.nota;
            document.getElementById('costModal').querySelector('.modal-header').className = 'modal-header py-2 bg-warning text-dark';
            document.getElementById('costModal').querySelector('.modal-title').innerText = 'Editar Salario';
            document.getElementById('costModal').dataset.context = 'salarios';
            document.getElementById('btnDeleteCost').style.display = 'block';
            costModal.show();
        });
    }

    async function fetchEntries(day, key) {
        const input = document.getElementById(`${key}_${day}`);
        const jsonStr = input ? (input.getAttribute('data-json') || '[]') : '[]';
        return JSON.parse(jsonStr);
    }

    async function saveEntry() {
        const day = document.getElementById('modal_day').value;
        const index = parseInt(document.getElementById('modal_index').value);
        const monto = parseFloat(document.getElementById('modal_monto').value) || 0;
        let nota = document.getElementById('modal_nota').value;
        const context = document.getElementById('costModal').dataset.context;

        // Limpiar saltos de línea que rompen el JSON en algunos parsers de DB
        nota = nota.replace(/\n/g, " ").replace(/\r/g, "");

        if(monto <= 0) return alert("Ingresa un monto válido");

        let entries = await fetchEntries(day, context);
        if(index === -1) {
            entries.push({monto, nota});
        } else {
            entries[index] = {monto, nota};
        }

        updateUI(day, entries, context);
        costModal.hide();
    }

    async function deleteEntry() {
        const day = document.getElementById('modal_day').value;
        const index = parseInt(document.getElementById('modal_index').value);
        const context = document.getElementById('costModal').dataset.context;
        let entries = await fetchEntries(day, context);
        entries.splice(index, 1);
        updateUI(day, entries, context);
        costModal.hide();
    }

    function updateUI(day, entries, context) {
        const container = document.getElementById(`container_${context}_${day}`);
        const input = document.getElementById(`${context}_${day}`);
        const label = document.getElementById(`total_${context}_label_${day}`);
        
        if(!container || !input) return;

        container.innerHTML = '';
        let total = 0;
        entries.forEach((e, idx) => {
            total += parseFloat(e.monto);
            const pill = document.createElement('div');
            pill.className = 'cost-pill';
            if(context === 'otros_ing') {
                pill.style = 'background-color: #e3f2fd !important; color: #0d6efd !important; border-color: #bbdefb !important;';
                pill.onclick = () => editOtherEntry(day, idx);
            } else if(context === 'salarios') {
                pill.style = 'background-color: #fff8e1 !important; color: #f57f17 !important; border-color: #ffecb3 !important;';
                pill.onclick = () => editSalarioEntry(day, idx);
            } else {
                pill.onclick = () => editCostEntry(day, idx);
            }
            pill.title = e.nota;
            pill.innerText = '$' + parseFloat(e.monto).toFixed(2);
            container.appendChild(pill);
        });

        const jsonString = JSON.stringify(entries);
        input.value = total;
        input.setAttribute('data-json', jsonString);
        if(label) label.innerText = total.toLocaleString('es-ES', {minimumFractionDigits:2});

        // Forzamos el guardado del JSON completo en la DB
        saveToDB({
            dataset: { fecha: input.dataset.fecha, key: context },
            value: jsonString
        });

        recalcColumn(day);
        updateChart();
    }

    async function saveToDB(inp) {
        try { 
            const response = await fetch('cash_flow.php', { 
                method:'POST', 
                headers:{'Content-Type':'application/json'}, 
                body:JSON.stringify({
                    action:'save_cell', 
                    fecha:inp.dataset.fecha, 
                    key:inp.dataset.key, 
                    val:inp.value
                })
            }); 
            const res = await response.json();
            if(res.status !== 'success') console.error("Error guardando:", res.msg);
        } catch(e){ console.error("Error de conexión al guardar:", e); }
    }

    async function syncAll() {
        if(!confirm("Sincronizar ventas e inventarios de todas las sucursales...")) return;

        const overlay = document.createElement('div');
        overlay.style = "position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.85);z-index:9999;display:flex;flex-direction:column;justify-content:center;align-items:center;color:white;backdrop-filter:blur(5px);";
        overlay.innerHTML = `
            <div class="spinner-border text-warning mb-4" style="width: 3rem; height: 3rem;"></div>
            <h3 class="fw-bold mb-2">Sincronizando Datos Consolidados</h3>
            <p id="sync-progress" class="fs-4 fw-light">Día 0 de ${numDias}</p>
            <div class="progress w-25 mt-3" style="height: 10px;">
                <div id="sync-bar" class="progress-bar progress-bar-striped progress-bar-animated bg-warning" style="width: 0%"></div>
            </div>
        `;
        document.body.appendChild(overlay);

        for(let i=1; i<=numDias; i++) {
            document.getElementById('sync-progress').innerText = `Día ${i} de ${numDias}`;
            document.getElementById('sync-bar').style.width = `${(i/numDias)*100}%`;

            const firstInput = document.querySelector(`.col-${i}`);
            if(!firstInput) continue;
            const fecha = firstInput.dataset.fecha;
            
            try {
                const res = await fetch('cash_flow.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'fetch_system', fecha:fecha})});
                const json = await res.json();
                if(json.status==='success') {
                    const d = json.data;
                    for(let s=1; s<=6; s++) {
                        const elV = document.getElementById(`ventas_suc_${s}_${i}`);
                        if(elV) { elV.value = d['ventas_suc_'+s]; saveToDB(elV); }
                        const elI = document.getElementById(`inv_${s}_${i}`);
                        if(elI) { elI.value = d['inv_'+s]; saveToDB(elI); }
                    }
                    recalcColumn(i);
                }
            } catch(e) { console.error("Error en día " + i); }
        }
        
        document.body.removeChild(overlay);
        updateChart();
        alert("✅ Sincronización mensual completada.");
    }

    function initChart() {
        chart = new Chart(document.getElementById('flowChart'), {
            type: 'line',
            data: { labels: Array.from({length:numDias}, (_,i)=>i+1), datasets: [
                {label:'Saldo Neto', data:[], borderColor:'#2c3e50', fill:false},
                {label:'Inventario Total', data:[], borderColor:'#2e7d32', borderDash:[5,5]}
            ]},
            options: { responsive:true, maintainAspectRatio:false }
        });
        updateChart();
    }

    function updateChart() {
        const netos = [], invs = [];
        for(let i=1; i<=numDias; i++) {
            const totalFin = document.getElementById(`total_fin_${i}`);
            const subInv = document.getElementById(`sub_inv_${i}`);
            netos.push(parseFloat(totalFin ? totalFin.value : 0)||0);
            invs.push(parseFloat(subInv ? subInv.innerText.replace(/\./g,'').replace(',','.') : 0)||0);
        }
        chart.data.datasets[0].data = netos;
        chart.data.datasets[1].data = invs;
        chart.update();
    }
</script>

    // Inicializar data-json al cargar
    document.querySelectorAll('.gst-val[data-key="costos"]').forEach(inp => {
        // Necesitamos pasar el valor inicial que es JSON desde PHP al atributo
        // Vamos a hacer una pequeña corrección en el PHP anterior para que esto funcione
    });


    function initChart() {
        chart = new Chart(document.getElementById('flowChart'), {
            type: 'line',
            data: { labels: Array.from({length:numDias}, (_,i)=>i+1), datasets: [
                {label:'Saldo Neto', data:[], borderColor:'#2c3e50', fill:false},
                {label:'Inventario Total', data:[], borderColor:'#2e7d32', borderDash:[5,5]}
            ]},
            options: { responsive:true, maintainAspectRatio:false }
        });
        updateChart();
    }

    function updateChart() {
        const netos = [], invs = [];
        for(let i=1; i<=numDias; i++) {
            netos.push(parseFloat(document.getElementById(`total_fin_${i}`).value)||0);
            invs.push(parseFloat(document.getElementById(`sub_inv_${i}`).innerText.replace(/\./g,'').replace(',','.'))||0);
        }
        chart.data.datasets[0].data = netos;
        chart.data.datasets[1].data = invs;
        chart.update();
    }
</script>
<?php include_once 'menu_master.php'; ?>
</body>
</html>
