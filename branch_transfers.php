<?php
// ARCHIVO: branch_transfers.php
// DESCRIPCIÓN: Módulo de Transferencias y Facturación entre Sucursales.
// VERSIÓN: 2.1 (ALMACÉN ORIGEN SELECCIONABLE EN INTRA-TRANSFER)
require_once 'db.php';
require_once 'kardex_engine.php';
require_once 'inventory_suite_layout.php';
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

require_once 'config_loader.php';
$ALM_ID = intval($config['id_almacen']);
$SUC_ID = intval($config['id_sucursal']);
$EMP_ID = intval($config['id_empresa']);

// --- OBTENER INFO DE SUCURSAL ORIGEN ---
$stmtOrig = $pdo->prepare("SELECT * FROM sucursales WHERE id = ?");
$stmtOrig->execute([$SUC_ID]);
$sucOrigData = $stmtOrig->fetch(PDO::FETCH_ASSOC);

// --- API: BUSCADOR DE PRODUCTOS CON STOCK ---
if (isset($_GET['action']) && $_GET['action'] === 'search') {
    header('Content-Type: application/json');
    $q = $_GET['q'] ?? '';
    $searchAlmID = isset($_GET['alm_id']) ? intval($_GET['alm_id']) : $ALM_ID;
    try {
        $sql = "SELECT p.codigo, p.nombre, p.precio, p.costo, p.precio_mayorista, p.unidad_medida,
                (SELECT COALESCE(SUM(s.cantidad), 0) FROM stock_almacen s WHERE s.id_producto = p.codigo AND s.id_almacen = ?) as stock
                FROM productos p 
                WHERE (p.nombre LIKE ? OR p.codigo LIKE ?) AND p.id_empresa = ? AND p.activo = 1 
                LIMIT 15";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$searchAlmID, "%$q%", "%$q%", $EMP_ID]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) { echo json_encode([]); }
    exit;
}

// --- API: OBTENER INFO DE SUCURSAL DESTINO ---
if (isset($_GET['action']) && $_GET['action'] === 'get_suc_info') {
    header('Content-Type: application/json');
    $id = intval($_GET['id']);
    $stmtS = $pdo->prepare("SELECT * FROM sucursales WHERE id = ?");
    $stmtS->execute([$id]);
    echo json_encode($stmtS->fetch(PDO::FETCH_ASSOC));
    exit;
}

// --- API: OBTENER ALMACENES DE LA SUCURSAL ACTUAL ---
$almacenesSucursal = [];
try {
    $stmtAlm = $pdo->prepare("SELECT id, nombre FROM almacenes WHERE id_sucursal = ? AND activo = 1");
    $stmtAlm->execute([$SUC_ID]);
    $almacenesSucursal = $stmtAlm->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $almacenesSucursal = []; }

// --- API: OBTENER SUCURSALES ---
$sucursales = [];
try {
    $stmtS = $pdo->prepare("SELECT id, nombre FROM sucursales WHERE id_empresa = ? AND id != ?");
    $stmtS->execute([$EMP_ID, $SUC_ID]);
    $sucursales = $stmtS->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $sucursales = []; }

// --- PROCESAR TRANSFERENCIA (POST) ---
$input = json_decode(file_get_contents('php://input'), true);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($input['action']) && $input['action'] === 'process_transfer') {
    header('Content-Type: application/json');
    
    try {
        $pdo->beginTransaction();
        
        $destSucID = intval($input['dest_sucursal']);
        $stmtAlm = $pdo->prepare("SELECT id FROM almacenes WHERE id_sucursal = ? LIMIT 1");
        $stmtAlm->execute([$destSucID]);
        $destAlmID = $stmtAlm->fetchColumn();
        
        if (!$destAlmID) throw new Exception("La sucursal destino no tiene almacén configurado.");

        $uuid = bin2hex(random_bytes(16));
        $totalTransfer = 0;
        
        $stmtHead = $pdo->prepare("INSERT INTO transferencias_cabecera (uuid_transf, id_empresa, id_almacen_origen, id_almacen_destino, fecha, estado) VALUES (?, ?, ?, ?, NOW(), 'COMPLETADO')");
        $stmtHead->execute([$uuid, $EMP_ID, $ALM_ID, $destAlmID]);
        $idTransf = $pdo->lastInsertId();

        $itemsFactura = [];

        foreach ($input['items'] as $item) {
            $skuOrig = $item['sku'];
            $skuDest = $item['sku_dest'] ?: $skuOrig;
            $qty = floatval($item['qty']);
            $price = floatval($item['price']);
            
            $stmtStock = $pdo->prepare("SELECT cantidad FROM stock_almacen WHERE id_producto = ? AND id_almacen = ?");
            $stmtStock->execute([$skuOrig, $ALM_ID]);
            $currentStock = floatval($stmtStock->fetchColumn() ?: 0);
            
            if ($currentStock < $qty) throw new Exception("Stock insuficiente para $skuOrig ($currentStock < $qty)");

            $stmtDet = $pdo->prepare("INSERT INTO transferencias_detalle (id_transf_cabecera, id_producto, cantidad) VALUES (?, ?, ?)");
            $stmtDet->execute([$idTransf, $skuOrig, $qty]);

            KardexEngine::registrarMovimiento($pdo, $skuOrig, $ALM_ID, -$qty, 'TRANSFERENCIA_SALIDA', "Transf #$idTransf a Suc #$destSucID", $price, $SUC_ID);
            KardexEngine::registrarMovimiento($pdo, $skuDest, $destAlmID, $qty, 'TRANSFERENCIA_ENTRADA', "Transf #$idTransf desde Suc #$SUC_ID", $price, $destSucID);
            
            // SI LOS SKU SON DISTINTOS, ACTUALIZAR COSTO EN DESTINO
            if ($skuOrig !== $skuDest) {
                $stmtUpd = $pdo->prepare("UPDATE productos SET costo = ? WHERE codigo = ? AND id_empresa = ?");
                $stmtUpd->execute([$price, $skuDest, $EMP_ID]);
            }

            $totalTransfer += ($qty * $price);
            $itemsFactura[] = [
                'desc' => $item['nombre'],
                'um' => $item['um'] ?: 'UND',
                'cant' => $qty,
                'precio' => $price,
                'importe' => ($qty * $price)
            ];
        }

        $numFactura = date('Ymd') . str_pad($idTransf, 4, '0', STR_PAD_LEFT);
        $cliNombre = "SUCURSAL: " . ($input['dest_suc_nombre'] ?? $destSucID);
        
        $stmtFact = $pdo->prepare("INSERT INTO facturas (numero_factura, fecha_emision, cliente_nombre, subtotal, total, creado_por, estado_pago, metodo_pago) VALUES (?, NOW(), ?, ?, ?, ?, 'PENDIENTE', 'Transferencia')");
        $stmtFact->execute([$numFactura, $cliNombre, $totalTransfer, $totalTransfer, $_SESSION['admin_name'] ?? 'Admin']);
        $idFactura = $pdo->lastInsertId();

        $stmtFactDet = $pdo->prepare("INSERT INTO facturas_detalle (id_factura, descripcion, unidad_medida, cantidad, precio_unitario, importe) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($itemsFactura as $if) {
            $stmtFactDet->execute([$idFactura, $if['desc'], $if['um'], $if['cant'], $if['precio'], $if['importe']]);
        }

        $pdo->commit();
        echo json_encode(['status' => 'success', 'id' => $idTransf, 'id_factura' => $idFactura]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

// --- PROCESAR TRANSFERENCIA INTRA-SUCURSAL (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($input['action']) && $input['action'] === 'process_intra_transfer') {
    header('Content-Type: application/json');
    
    try {
        $pdo->beginTransaction();
        
        $origAlmID = intval($input['orig_almacen'] ?? $ALM_ID);
        $destAlmID = intval($input['dest_almacen']);
        
        if ($destAlmID === $origAlmID) throw new Exception("El almacén destino no puede ser el mismo que el origen.");
        
        $stmtCheck = $pdo->prepare("SELECT id FROM almacenes WHERE id = ? AND id_sucursal = ? AND activo = 1");
        $stmtCheck->execute([$destAlmID, $SUC_ID]);
        if (!$stmtCheck->fetchColumn()) throw new Exception("Almacén destino inválido.");

        $uuid = bin2hex(random_bytes(16));
        
        $stmtHead = $pdo->prepare("INSERT INTO transferencias_cabecera (uuid_transf, id_empresa, id_almacen_origen, id_almacen_destino, fecha, estado) VALUES (?, ?, ?, ?, NOW(), 'COMPLETADO')");
        $stmtHead->execute([$uuid, $EMP_ID, $origAlmID, $destAlmID]);
        $idTransf = $pdo->lastInsertId();

        foreach ($input['items'] as $item) {
            $sku = $item['sku'];
            $qty = floatval($item['qty']);
            $price = floatval($item['price']);
            
            $stmtStock = $pdo->prepare("SELECT cantidad FROM stock_almacen WHERE id_producto = ? AND id_almacen = ?");
            $stmtStock->execute([$sku, $origAlmID]);
            $currentStock = floatval($stmtStock->fetchColumn() ?: 0);
            
            if ($currentStock < $qty) throw new Exception("Stock insuficiente para $sku ($currentStock < $qty)");

            $stmtDet = $pdo->prepare("INSERT INTO transferencias_detalle (id_transf_cabecera, id_producto, cantidad) VALUES (?, ?, ?)");
            $stmtDet->execute([$idTransf, $sku, $qty]);

            KardexEngine::registrarMovimiento($pdo, $sku, $origAlmID, -$qty, 'TRANSFERENCIA_SALIDA', "Transf #$idTransf Alm→#$destAlmID", $price, $SUC_ID);
            KardexEngine::registrarMovimiento($pdo, $sku, $destAlmID, $qty, 'TRANSFERENCIA_ENTRADA', "Transf #$idTransf Alm#$origAlmID→", $price, $SUC_ID);
        }

        $pdo->commit();
        echo json_encode(['status' => 'success', 'id' => $idTransf]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

// --- VISTA DE IMPRESIÓN (GET) ---
if (isset($_GET['print_id'])) {
    $id = intval($_GET['print_id']);
    $stmtT = $pdo->prepare("SELECT c.*, s_dest.nombre as sucursal_destino, s_orig.nombre as sucursal_origen 
                            FROM transferencias_cabecera c
                            JOIN almacenes a_dest ON c.id_almacen_destino = a_dest.id
                            JOIN sucursales s_dest ON a_dest.id_sucursal = s_dest.id
                            JOIN almacenes a_orig ON c.id_almacen_origen = a_orig.id
                            JOIN sucursales s_orig ON a_orig.id_sucursal = s_orig.id
                            WHERE c.id = ?");
    $stmtT->execute([$id]);
    $transf = $stmtT->fetch(PDO::FETCH_ASSOC);
    
    $stmtD = $pdo->prepare("SELECT d.*, p.nombre FROM transferencias_detalle d JOIN productos p ON d.id_producto = p.codigo WHERE d.id_transf_cabecera = ?");
    $stmtD->execute([$id]);
    $details = $stmtD->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Comprobante de Transferencia #<?php echo $id; ?></title>
        <style>
            body { font-family: sans-serif; margin: 0; padding: 20px; font-size: 12px; }
            .half-a4 { width: 148mm; height: 210mm; border: 1px dashed #ccc; padding: 10mm; box-sizing: border-box; margin: auto; }
            .header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 15px; }
            .info { display: flex; justify-content: space-between; margin-bottom: 15px; }
            table { width: 100%; border-collapse: collapse; margin-top: 10px; }
            th, td { border: 1px solid #000; padding: 5px; text-align: left; }
            th { background: #eee; }
            .footer { margin-top: 30px; display: flex; justify-content: space-around; text-align: center; }
            .sign { border-top: 1px solid #000; width: 40%; padding-top: 5px; margin-top: 40px; }
            @media print { .no-print { display: none; } }
        </style>
    </head>
    <body>
        <div class="no-print text-center mb-3"><button onclick="window.print()" style="padding:10px 20px; cursor:pointer;">IMPRIMIR COMPROBANTE</button></div>
        <div class="half-a4">
            <div class="header">
                <h2 style="margin:0;">COMPROBANTE DE TRANSFERENCIA</h2>
                <strong>ID: #<?php echo $id; ?></strong> | Fecha: <?php echo $transf['fecha']; ?>
            </div>
            <div class="info">
                <div><strong>Origen:</strong><br><?php echo $transf['sucursal_origen']; ?></div>
                <div style="text-align:right;"><strong>Destino:</strong><br><?php echo $transf['sucursal_destino']; ?></div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Descripción</th>
                        <th style="text-align:center;">Cant.</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($details as $d): ?>
                    <tr>
                        <td><?php echo $d['id_producto']; ?></td>
                        <td><?php echo $d['nombre']; ?></td>
                        <td style="text-align:center;"><?php echo $d['cantidad'] + 0; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="footer">
                <div class="sign">Entregado por</div>
                <div class="sign">Recibido por</div>
            </div>
            <div style="margin-top:20px; text-align:center; color:#888; font-size:10px;">Generado por <?= htmlspecialchars(config_loader_system_name()) ?></div>
        </div>
    </body>
    </html>
    <?php
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Transferencias entre Sucursales</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <?php require_once __DIR__ . '/theme.php'; ?>
    <link rel="stylesheet" href="assets/css/inventory-suite.css">
    <style>
        .table thead th { white-space: nowrap; }
        .search-results { position: absolute; width: 100%; z-index: 1000; max-height: 300px; overflow-y: auto; }
        .btn-price { font-size: 0.72rem; padding: 2px 6px; }
    </style>
</head>
<body class="pb-5 inventory-suite">
<div id="app" class="container-fluid shell inventory-shell py-4 py-lg-5">

    <section class="glass-card inventory-hero p-4 p-lg-5 mb-4 inventory-fade-in">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-4 align-items-start">
            <div>
                <div class="section-title text-white-50 mb-2">Inventario / Logística</div>
                <h1 class="h2 fw-bold mb-2"><i class="fas fa-exchange-alt me-2"></i>Transferencias e Inter-Facturación</h1>
                <p class="mb-3 text-white-50">Movimiento entre sucursales y almacenes con comprobante interno, vista previa documental y factura inter-sucursal.</p>
                <div class="d-flex flex-wrap gap-2">
                    <span class="kpi-chip"><i class="fas fa-building me-1"></i>Sucursal <?= (int)$SUC_ID ?> (<?= htmlspecialchars((string)($sucOrigData['nombre'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?>)</span>
                    <span class="kpi-chip"><i class="fas fa-warehouse me-1"></i>Contexto Actual: Almacén <?= (int)$ALM_ID ?></span>
                    <span class="kpi-chip"><i class="fas fa-code-branch me-1"></i><?= count($sucursales) ?> sucursales destino</span>
                    <span class="kpi-chip"><i class="fas fa-boxes-stacked me-1"></i><?= count($almacenesSucursal) ?> almacenes locales</span>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="pos_purchases.php" class="btn btn-light"><i class="fas fa-dolly-flatbed me-1"></i>Compras</a>
                <a href="dashboard.php" class="btn btn-outline-light"><i class="fas fa-arrow-left me-1"></i>Volver</a>
            </div>
        </div>
    </section>

    <div class="inventory-tablist d-inline-flex mb-4" role="tablist">
        <button class="nav-link px-4 py-2 fw-bold" :class="{'active': mode==='inter'}" @click="setMode('inter')" role="tab">
            <i class="fas fa-building me-1"></i> Entre Sucursales
        </button>
        <button class="nav-link px-4 py-2 fw-bold" :class="{'active': mode==='intra', 'disabled': almacenesSucursal.length <= 1}" :disabled="almacenesSucursal.length <= 1" @click="setMode('intra')" role="tab">
            <i class="fas fa-warehouse me-1"></i> Entre Almacenes
            <span class="badge bg-warning text-dark ms-1" v-if="almacenesSucursal.length <= 1">1 solo</span>
        </button>
    </div>

    <div class="row g-4 align-items-stretch">
        <div class="col-12 col-lg-8">
            <div class="glass-card p-4 h-100 inventory-fade-in">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div>
                        <div class="section-title">Operación</div>
                        <h2 class="h5 fw-bold mb-0">Armar transferencia</h2>
                    </div>
                    <span class="soft-pill"><i class="fas fa-truck-ramp-box"></i>{{cart.length}} líneas</span>
                </div>
                <div class="row g-3 mb-3">
                    <!-- MODE INTER -->
                    <div class="col-md-6" v-if="mode==='inter'">
                        <label class="form-label small fw-bold">1. Sucursal destino</label>
                        <select class="form-select" v-model="destSucursal" @change="fetchDestInfo">
                            <option value="">Seleccione destino...</option>
                            <?php foreach ($sucursales as $s): ?>
                            <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- MODE INTRA -->
                    <div class="col-md-6 d-flex gap-2" v-if="mode==='intra'">
                        <div class="flex-grow-1">
                            <label class="form-label small fw-bold">1. Almacén origen</label>
                            <select class="form-select" v-model="almacenOrigId" @change="onAlmacenOrigChange">
                                <option v-for="alm in almacenesSucursal" :value="alm.id" :key="alm.id">{{alm.nombre}}</option>
                            </select>
                        </div>
                        <div class="flex-grow-1">
                            <label class="form-label small fw-bold">2. Almacén destino</label>
                            <select class="form-select" v-model="destAlmacen">
                                <option value="">Seleccione destino...</option>
                                <option v-for="alm in almacenesDestino" :value="alm.id" :key="alm.id">{{alm.nombre}}</option>
                            </select>
                        </div>
                    </div>

                    <!-- SEARCH (BOTH MODES) -->
                    <div class="col-md-6 position-relative">
                        <label class="form-label small fw-bold">{{ mode === 'intra' ? '3.' : '2.' }} Buscar producto origen</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                            <input type="text" class="form-control border-start-0" placeholder="Nombre o SKU..." v-model="query" @input="searchProducts">
                        </div>
                        <div class="list-group search-results shadow" v-if="results.length > 0">
                            <button v-for="p in results" class="list-group-item list-group-item-action" @click="addToCart(p)">
                                <div class="d-flex justify-content-between">
                                    <span><strong>{{p.nombre}}</strong> <small class="text-muted">[{{p.codigo}}]</small></span>
                                    <span class="badge bg-success" v-if="p.stock > 0">Stock: {{p.stock}}</span>
                                    <span class="badge bg-danger" v-else>Sin Stock</span>
                                </div>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small fw-bold">
                            <tr>
                                <th class="ps-4">Producto / SKU Origen</th>
                                <th v-if="mode==='inter'" style="width: 180px;">Buscador SKU Destino</th>
                                <th style="width: 80px;" class="text-center">Cant.</th>
                                <th style="width: 200px;">Precio Unit.</th>
                                <th class="text-end pe-4">Subtotal</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="cart.length === 0">
                                <td :colspan="mode==='inter' ? 6 : 5" class="text-center py-5 text-muted">
                                    <i class="fas fa-shopping-cart fa-2x mb-2 opacity-50"></i><br>
                                    Agregue productos para iniciar la transferencia.
                                </td>
                            </tr>
                            <tr v-for="(item, index) in cart" :key="index">
                                <td class="ps-4">
                                    <div class="fw-bold">{{item.nombre}}</div>
                                    <div class="small text-muted">SKU: <strong>{{item.sku}}</strong> | Stock: {{item.stock_max}}</div>
                                </td>
                                <td v-if="mode==='inter'" class="position-relative">
                                    <input type="text" class="form-control form-control-sm" v-model="item.sku_dest_search" 
                                           @input="searchDestSku(index)" :placeholder="item.sku">
                                    <div class="list-group search-results shadow-sm" v-if="item.dest_results && item.dest_results.length > 0" style="font-size: 0.75rem;">
                                        <button v-for="rp in item.dest_results" class="list-group-item list-group-item-action py-1" @click="setDestSku(index, rp)">
                                            {{rp.nombre}} ({{rp.codigo}})
                                        </button>
                                    </div>
                                    <div class="small text-primary mt-1" v-if="item.sku_dest">Dest: <strong>{{item.sku_dest}}</strong></div>
                                </td>
                                <td><input type="number" class="form-control form-control-sm text-center" v-model.number="item.qty" @input="validateQty(item)" min="1"></td>
                                <td>
                                    <div class="input-group input-group-sm mb-1">
                                        <span class="input-group-text">$</span>
                                        <input type="number" class="form-control" v-model.number="item.price" step="0.01">
                                    </div>
                                    <div class="d-flex gap-1">
                                        <button class="btn btn-outline-secondary btn-price" @click="setPrice(item, 'cost')">Costo</button>
                                        <button class="btn btn-outline-secondary btn-price" @click="setPrice(item, 'wholesale')">Mayor.</button>
                                        <button class="btn btn-outline-secondary btn-price" @click="setPrice(item, 'retail')">Min. ({{retailProfit}}%)</button>
                                    </div>
                                </td>
                                <td class="text-end pe-4 fw-bold text-primary">${{(item.qty * item.price).toFixed(2)}}</td>
                                <td class="pe-4 text-end"><button class="btn btn-sm btn-outline-danger border-0" @click="cart.splice(index, 1)">&times;</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-4">
            <div class="glass-card p-4 h-100 inventory-fade-in">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <div class="section-title">Contexto</div>
                        <h2 class="h5 fw-bold mb-0">Resumen de operación</h2>
                    </div>
                </div>

                <div class="mb-3">
                    <div class="stat-box">
                        <div class="small text-muted mb-1"><i class="fas fa-warehouse me-1"></i>Origen</div>
                        <div class="fw-bold">{{almacenOrigName}}</div>
                        <div class="small text-muted mt-1 mb-1"><i class="fas fa-arrow-right me-1"></i>Destino</div>
                        <div v-if="mode==='inter'">
                            <div class="fw-bold" v-if="destSucursal">{{sucDest.nombre}}</div>
                            <div class="text-muted" v-else>Sin seleccionar sucursal</div>
                        </div>
                        <div v-else>
                            <div class="fw-bold" v-if="destAlmacen">{{getAlmacenName(destAlmacen)}}</div>
                            <div class="text-muted" v-else>Sin seleccionar almacén</div>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">Margen Mayorista (%)</label>
                    <input type="number" class="form-control form-control-sm" v-model.number="wholesaleProfit" min="0">
                </div>

                <div class="stat-box mb-3">
                    <div class="d-flex justify-content-between mb-2">
                        <span>Total productos</span>
                        <span class="fw-bold">{{cart.length}}</span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="fs-6">Total</span>
                        <span class="summary-total text-success">${{total.toFixed(2)}}</span>
                    </div>
                </div>

                <div class="d-grid gap-2">
                    <button class="btn btn-outline-info fw-bold" @click="showDocPreview" :disabled="cart.length==0">
                        <i class="fas fa-file-alt me-2"></i> VISTA PREVIA MOVIMIENTO
                    </button>
                    <button v-if="mode==='inter'" class="btn btn-outline-primary fw-bold" @click="showInvPreview" :disabled="cart.length==0">
                        <i class="fas fa-file-invoice-dollar me-2"></i> VISTA PREVIA FACTURA
                    </button>
                    <hr>
                    <button class="btn btn-success btn-lg py-3 fw-bold shadow-sm" :disabled="!isReady" @click="submitTransfer">
                        <i class="fas fa-check-circle me-2"></i> CONFIRMAR OPERACIÓN
                    </button>
                </div>
            </div>
        </div>
    </div>

</div>

    <script src="assets/js/vue.min.js"></script>
    <script src="assets/js/bootstrap.bundle.min.js"></script>
    <script>
        new Vue({
            el: '#app',
            data: {
                mode: 'inter',
                query: '',
                results: [],
                cart: [],
                destSucursal: '',
                destAlmacen: '',
                wholesaleProfit: 10,
                retailProfit: 0,
                isProcessing: false,
                sucOrig: <?php echo json_encode($sucOrigData); ?>,
                sucDest: { nombre: 'Seleccione Sucursal...', id: '?' },
                almacenesSucursal: <?php echo json_encode($almacenesSucursal); ?>,
                almacenOrigId: <?php echo $ALM_ID; ?>
            },
            computed: {
                total() {
                    return this.cart.reduce((acc, item) => acc + (item.qty * item.price), 0);
                },
                isReady() {
                    if (this.cart.length === 0 || this.isProcessing) return false;
                    if (this.mode === 'inter') return !!this.destSucursal;
                    return !!this.destAlmacen && this.destAlmacen != this.almacenOrigId;
                },
                almacenOrigName() {
                    const alm = this.almacenesSucursal.find(a => a.id == this.almacenOrigId);
                    return alm ? alm.nombre : 'Almacén ' + this.almacenOrigId;
                },
                almacenesDestino() {
                    return this.almacenesSucursal.filter(a => a.id != this.almacenOrigId);
                }
            },

            methods: {
                setMode(m) {
                    if (m === 'intra' && this.almacenesSucursal.length <= 1) return;
                    this.mode = m;
                    this.destSucursal = '';
                    this.destAlmacen = '';
                    this.sucDest = { nombre: 'Seleccione Sucursal...', id: '?' };
                },
                getAlmacenName(id) {
                    const alm = this.almacenesSucursal.find(a => a.id == id);
                    return alm ? alm.nombre : 'Almacén ' + id;
                },
                onAlmacenOrigChange() {
                    this.cart = [];
                    this.query = '';
                    this.results = [];
                    this.destAlmacen = '';
                },
                async fetchDestInfo() {
                    if(!this.destSucursal) { this.sucDest = { nombre: 'Seleccione Sucursal...', id: '?' }; return; }
                    const res = await fetch(`branch_transfers.php?action=get_suc_info&id=${this.destSucursal}`);
                    this.sucDest = await res.json();
                },
                async searchProducts() {
                    if (this.query.length < 2) { this.results = []; return; }
                    const res = await fetch(`branch_transfers.php?action=search&q=${this.query}&alm_id=${this.almacenOrigId}`);
                    this.results = await res.json();
                },
                async searchDestSku(index) {
                    const item = this.cart[index];
                    if (item.sku_dest_search.length < 2) { item.dest_results = []; return; }
                    const res = await fetch(`branch_transfers.php?action=search&q=${item.sku_dest_search}`);
                    item.dest_results = await res.json();
                },
                setDestSku(index, p) {
                    this.cart[index].sku_dest = p.codigo;
                    this.cart[index].sku_dest_search = p.nombre;
                    this.cart[index].dest_results = [];
                },
                addToCart(p) {
                    if (p.stock <= 0) { alert("Sin stock disponible."); return; }
                    this.cart.push({
                        sku: p.codigo,
                        nombre: p.nombre,
                        sku_dest: '',
                        sku_dest_search: '',
                        dest_results: [],
                        qty: 1,
                        stock_max: parseFloat(p.stock),
                        price: parseFloat(p.precio_mayorista || p.costo || 0),
                        cost: parseFloat(p.costo || 0),
                        retail: parseFloat(p.precio || 0),
                        um: p.unidad_medida
                    });
                    this.query = '';
                    this.results = [];
                },
                validateQty(item) {
                    if (item.qty > item.stock_max) item.qty = item.stock_max;
                    if (item.qty < 1) item.qty = 1;
                },
                setPrice(item, type) {
                    if (type === 'cost') item.price = item.cost;
                    if (type === 'retail') item.price = item.retail;
                    if (type === 'wholesale') {
                        item.price = parseFloat((item.cost * (1 + this.wholesaleProfit / 100)).toFixed(2));
                    }
                },
                showDocPreview() {
                    const origen = this.mode === 'inter'
                        ? (this.sucOrig.nombre || '') + ' (Suc #' + (this.sucOrig.id || '') + ')'
                        : this.almacenOrigName;
                    const destino = this.mode === 'inter'
                        ? (this.sucDest.nombre || '---') + ' (Suc #' + (this.sucDest.id || '?') + ')'
                        : (this.destAlmacen ? this.getAlmacenName(this.destAlmacen) : '---');
                    const fecha = new Date().toLocaleDateString('es-ES');
                    let filas = '';
                    this.cart.forEach(it => {
                        filas += `<tr><td>${it.sku}</td><td>${it.nombre}</td><td style="text-align:center">${it.qty}</td></tr>`;
                    });
                    const html = `<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
                        <title>Vista Previa - Comprobante</title>
                        <style>
                            body{font-family:sans-serif;margin:0;padding:20px;font-size:12px;background:#f0f0f0;}
                            .doc{width:148mm;min-height:210mm;background:#fff;border:1px dashed #aaa;padding:10mm;box-sizing:border-box;margin:auto;}
                            .header{text-align:center;border-bottom:2px solid #000;padding-bottom:8px;margin-bottom:14px;}
                            .info{display:flex;justify-content:space-between;margin-bottom:12px;}
                            table{width:100%;border-collapse:collapse;margin-top:10px;}
                            th,td{border:1px solid #000;padding:5px;text-align:left;}
                            th{background:#eee;}
                            .footer{margin-top:30px;display:flex;justify-content:space-around;text-align:center;}
                            .sign{border-top:1px solid #000;width:40%;padding-top:5px;margin-top:40px;}
                            .no-print{text-align:center;margin-bottom:12px;}
                            @media print{.no-print{display:none;}}
                        </style></head><body>
                        <div class="no-print"><button onclick="window.print()" style="padding:8px 20px;cursor:pointer;">IMPRIMIR</button></div>
                        <div class="doc">
                            <div class="header">
                                <h3 style="margin:0">COMPROBANTE DE TRANSFERENCIA</h3>
                                <div>DOCUMENTO INTERNO NO FISCAL &nbsp;|&nbsp; ${fecha}</div>
                            </div>
                            <div class="info">
                                <div><strong>Origen:</strong><br>${origen}</div>
                                <div style="text-align:right"><strong>Destino:</strong><br>${destino}</div>
                            </div>
                            <table>
                                <thead><tr><th>SKU</th><th>Descripción</th><th style="text-align:center">Cant.</th></tr></thead>
                                <tbody>${filas}</tbody>
                            </table>
                            <div class="footer">
                                <div class="sign">Entregado por</div>
                                <div class="sign">Recibido por</div>
                            </div>
                            <div style="margin-top:20px;text-align:center;color:#888;font-size:10px">Generado por <?= htmlspecialchars(config_loader_system_name()) ?></div>
                        </div>
                    </body></html>`;
                    const w = window.open('', '_blank', 'width=700,height=800');
                    w.document.write(html);
                    w.document.close();
                },
                showInvPreview() {
                    const fecha = new Date().toLocaleDateString('es-ES');
                    let filas = '';
                    let total = 0;
                    this.cart.forEach(it => {
                        const sub = it.qty * it.price;
                        total += sub;
                        filas += `<tr>
                            <td>${it.nombre}</td>
                            <td style="text-align:center">${it.qty}</td>
                            <td style="text-align:right">$${it.price.toFixed(2)}</td>
                            <td style="text-align:right">$${sub.toFixed(2)}</td>
                        </tr>`;
                    });
                    const origen = this.sucOrig.nombre || '';
                    const destino = this.sucDest.nombre || '---';
                    const html = `<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
                        <title>Vista Previa - Factura</title>
                        <style>
                            body{font-family:'Calibri',sans-serif;margin:0;padding:20px;font-size:13px;background:#f0f0f0;}
                            .doc{width:21cm;min-height:20cm;background:#fff;border:1px solid #ccc;padding:20px 25px;box-sizing:border-box;margin:auto;}
                            .head{display:flex;justify-content:space-between;border-bottom:2px solid #1a56db;padding-bottom:12px;margin-bottom:16px;}
                            .title-fac{font-size:2.2rem;font-weight:900;opacity:.15;margin:0;}
                            .billing{display:flex;gap:20px;margin-bottom:20px;}
                            .billing-box{flex:1;border:1px solid #ddd;border-radius:6px;padding:10px;}
                            .billing-box h6{background:#1a56db;color:#fff;margin:-10px -10px 8px;padding:5px 10px;border-radius:4px 4px 0 0;font-size:.8rem;}
                            table{width:100%;border-collapse:collapse;}
                            thead tr{background:#1a56db;color:#fff;}
                            th,td{border:1px solid #ccc;padding:6px 8px;}
                            tfoot td{font-weight:bold;font-size:1.1rem;}
                            .no-print{text-align:center;margin-bottom:12px;}
                            @media print{.no-print{display:none;}}
                        </style></head><body>
                        <div class="no-print"><button onclick="window.print()" style="padding:8px 20px;cursor:pointer;">IMPRIMIR</button></div>
                        <div class="doc">
                            <div class="head">
                                <div>
                                    <div style="font-size:1.4rem;font-weight:900;color:#1a56db">PALWEB SURL</div>
                                    <div>${origen}</div>
                                </div>
                                <div style="text-align:right">
                                    <div class="title-fac">FACTURA</div>
                                    <div style="font-size:1rem;font-weight:bold">VISTA PREVIA</div>
                                    <div>${fecha}</div>
                                </div>
                            </div>
                            <div class="billing">
                                <div class="billing-box"><h6>FACTURAR A:</h6><strong>${destino}</strong><br>Sucursal Destino ID: ${this.sucDest.id || '?'}</div>
                                <div class="billing-box"><h6>DETALLES DE PAGO:</h6>Términos: Transferencia Interna<br>Estado: Pendiente de Conciliación</div>
                            </div>
                            <table>
                                <thead><tr><th>Descripción</th><th style="text-align:center">Cant.</th><th style="text-align:right">Precio</th><th style="text-align:right">Total</th></tr></thead>
                                <tbody>${filas}</tbody>
                                <tfoot><tr><td colspan="3" style="text-align:right">TOTAL FACTURA:</td><td style="text-align:right;color:#1a56db">$${total.toFixed(2)}</td></tr></tfoot>
                            </table>
                        </div>
                    </body></html>`;
                    const w = window.open('', '_blank', 'width=900,height=800');
                    w.document.write(html);
                    w.document.close();
                },
                async submitTransfer() {
                    if (this.isProcessing) return;
                    
                    if (this.mode === 'inter') {
                        if (!this.destSucursal) return alert("Seleccione sucursal destino");
                        if (!confirm("¿Está seguro de procesar esta transferencia y generar la factura correspondiente?")) return;
                    } else {
                        if (!this.destAlmacen || this.destAlmacen == this.almacenOrigId) return alert("Seleccione un almacén destino distinto al origen");
                        if (!confirm("¿Está seguro de mover estos productos al almacén " + this.getAlmacenName(this.destAlmacen) + "?")) return;
                    }
                    
                    this.isProcessing = true;
                    try {
                        const action = this.mode === 'inter' ? 'process_transfer' : 'process_intra_transfer';
                        const payload = { action: action, items: this.cart };
                        if (this.mode === 'inter') {
                            payload.dest_sucursal = this.destSucursal;
                            payload.dest_suc_nombre = this.sucDest.nombre;
                        } else {
                            payload.orig_almacen = this.almacenOrigId;
                            payload.dest_almacen = this.destAlmacen;
                        }
                        const res = await fetch('branch_transfers.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(payload)
                        });
                        const data = await res.json();
                        if (data.status === 'success') {
                            window.open(`branch_transfers.php?print_id=${data.id}`, 'Print', 'width=900,height=800');
                            alert("Transferencia completada correctamente.");
                            location.reload();
                        } else {
                            alert("Error: " + data.msg);
                        }
                    } catch (e) {
                        alert("Error de conexión.");
                    } finally {
                        this.isProcessing = false;
                    }
                }
            }
        });
    </script>
    
<?php include_once 'menu_master.php'; ?>
</body>
</html>
