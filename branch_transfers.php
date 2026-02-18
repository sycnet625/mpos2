<?php
// ARCHIVO: branch_transfers.php
// DESCRIPCIÓN: Módulo de Transferencias y Facturación entre Sucursales.
// VERSIÓN: 2.0 (AJAX SKU DESTINO + VISTAS PREVIAS)
require_once 'db.php';
require_once 'kardex_engine.php';
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
    try {
        $sql = "SELECT p.codigo, p.nombre, p.precio, p.costo, p.precio_mayorista, p.unidad_medida,
                (SELECT COALESCE(SUM(s.cantidad), 0) FROM stock_almacen s WHERE s.id_producto = p.codigo AND s.id_almacen = ?) as stock
                FROM productos p 
                WHERE (p.nombre LIKE ? OR p.codigo LIKE ?) AND p.id_empresa = ? AND p.activo = 1 
                LIMIT 15";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$ALM_ID, "%$q%", "%$q%", $EMP_ID]);
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
            <div style="margin-top:20px; text-align:center; color:#888; font-size:10px;">Generado por PalWeb ERP</div>
        </div>
    <?php include_once 'menu_master.php'; ?>
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
    <title>Transferencias entre Sucursales</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', sans-serif; font-size: 0.9rem; }
        .search-results { position: absolute; width: 100%; z-index: 1000; max-height: 300px; overflow-y: auto; }
        .card-transfer { border: none; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .btn-price { font-size: 0.7rem; padding: 1px 4px; }
        .header-status { background: #2c3e50; color: white; padding: 10px 20px; border-radius: 8px; margin-bottom: 20px; font-size: 0.85rem; }
        .badge-info-sys { background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.2); }
        .preview-modal .modal-lg { max-width: 900px; }
        .invoice-preview { border: 1px solid #ccc; padding: 30px; background: white; font-family: 'Calibri', sans-serif; }
    </style>
</head>
<body class="p-4">
    <div id="app" class="container-fluid">
        
        <div class="header-status d-flex justify-content-between align-items-center no-print">
            <div class="d-flex gap-3">
                <div class="px-3 py-1 rounded badge-info-sys"><i class="fas fa-warehouse text-warning me-2"></i>Almacén: <strong><?php echo $ALM_ID; ?></strong></div>
                <div class="px-3 py-1 rounded badge-info-sys"><i class="fas fa-building text-info me-2"></i>Sucursal: <strong><?php echo $SUC_ID; ?> (<?php echo $sucOrigData['nombre'] ?? 'N/A'; ?>)</strong></div>
            </div>
            <div class="fw-bold text-uppercase opacity-75">Módulo de Inter-Transferencia</div>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3 class="fw-bold m-0"><i class="fas fa-exchange-alt text-primary me-2"></i> Transferencias e Inter-Facturación</h3>
            <a href="dashboard.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-home"></i> Volver</a>
        </div>

        <div class="row g-4">
            <!-- CARRITO Y CONFIGURACIÓN -->
            <div class="col-md-8">
                <div class="card card-transfer mb-4">
                    <div class="card-header bg-white py-3">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">1. SUCURSAL DESTINO</label>
                                <select class="form-select" v-model="destSucursal" @change="fetchDestInfo">
                                    <option value="">Seleccione destino...</option>
                                    <?php foreach ($sucursales as $s): ?>
                                    <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['nombre']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 position-relative">
                                <label class="form-label fw-bold small">2. BUSCAR PRODUCTO ORIGEN</label>
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
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light small fw-bold">
                                    <tr>
                                        <th class="ps-4">Producto / SKU Origen</th>
                                        <th style="width: 180px;">Buscador SKU Destino</th>
                                        <th style="width: 80px;" class="text-center">Cant.</th>
                                        <th style="width: 200px;">Precio Unit.</th>
                                        <th class="text-end pe-4">Subtotal</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-if="cart.length === 0">
                                        <td colspan="6" class="text-center py-5 text-muted">
                                            <i class="fas fa-shopping-cart fa-2x mb-2 opacity-50"></i><br>
                                            Agregue productos para iniciar la transferencia.
                                        </td>
                                    </tr>
                                    <tr v-for="(item, index) in cart" :key="index">
                                        <td class="ps-4">
                                            <div class="fw-bold">{{item.nombre}}</div>
                                            <div class="small text-muted">SKU: <strong>{{item.sku}}</strong> | Stock: {{item.stock_max}}</div>
                                        </td>
                                        <td class="position-relative">
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
            </div>

            <!-- RESUMEN Y ACCIÓN -->
            <div class="col-md-4">
                <div class="card card-transfer">
                    <div class="card-body">
                        <h5 class="fw-bold mb-4 border-bottom pb-2">Resumen de Operación</h5>
                        
                        <div class="mb-4">
                            <label class="form-label small fw-bold text-muted">Margen Mayorista (%)</label>
                            <input type="number" class="form-control form-control-sm" v-model.number="wholesaleProfit" min="0">
                        </div>

                        <div class="d-flex justify-content-between mb-2">
                            <span>Total Productos:</span>
                            <span class="fw-bold">{{cart.length}}</span>
                        </div>
                        <div class="d-flex justify-content-between mb-4">
                            <span class="fs-5">TOTAL FACTURADO:</span>
                            <span class="fs-4 fw-bold text-success">${{total.toFixed(2)}}</span>
                        </div>

                        <div class="d-grid gap-2">
                            <button class="btn btn-outline-info fw-bold" @click="showDocPreview" :disabled="cart.length==0">
                                <i class="fas fa-file-alt me-2"></i> VISTA PREVIA MOVIMIENTO
                            </button>
                            <button class="btn btn-outline-primary fw-bold" @click="showInvPreview" :disabled="cart.length==0">
                                <i class="fas fa-file-invoice-dollar me-2"></i> VISTA PREVIA FACTURA
                            </button>
                            <hr>
                            <button class="btn btn-primary btn-lg py-3 fw-bold shadow" :disabled="!isReady" @click="submitTransfer">
                                <i class="fas fa-check-circle me-2"></i> CONFIRMAR OPERACIÓN
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- MODAL PREVIEW MOVIMIENTO -->
        <div class="modal fade preview-modal" id="docPreviewModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-dark text-white">
                        <h5 class="modal-title">Vista Previa: Comprobante de Movimiento</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body bg-light">
                        <div id="docPreviewArea" class="bg-white shadow-sm mx-auto p-4" style="width: 148mm; min-height: 210mm;">
                            <div class="text-center border-bottom pb-3 mb-4">
                                <h4 class="m-0 fw-bold">COMPROBANTE DE TRANSFERENCIA</h4>
                                <div class="text-muted small">DOCUMENTO INTERNO NO FISCAL</div>
                            </div>
                            <div class="row mb-4 small">
                                <div class="col-6">
                                    <div class="fw-bold text-uppercase border-bottom mb-1">Sucursal Origen</div>
                                    <div><strong>{{sucOrig.nombre}}</strong></div>
                                    <div>ID Suc: {{sucOrig.id}}</div>
                                </div>
                                <div class="col-6 text-end">
                                    <div class="fw-bold text-uppercase border-bottom mb-1">Sucursal Destino</div>
                                    <div><strong>{{sucDest.nombre}}</strong></div>
                                    <div>ID Suc: {{sucDest.id}}</div>
                                </div>
                            </div>
                            <table class="table table-bordered table-sm small">
                                <thead class="table-light">
                                    <tr><th>SKU</th><th>Descripción</th><th class="text-center">Cant</th></tr>
                                </thead>
                                <tbody>
                                    <tr v-for="it in cart">
                                        <td>{{it.sku}}</td><td>{{it.nombre}}</td><td class="text-center">{{it.qty}}</td>
                                    </tr>
                                </tbody>
                            </table>
                            <div class="row mt-5 pt-5 text-center small">
                                <div class="col-6"><div class="border-top pt-2">Entrega: _________________</div></div>
                                <div class="col-6"><div class="border-top pt-2">Recibe: _________________</div></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- MODAL PREVIEW FACTURA -->
        <div class="modal fade preview-modal" id="invPreviewModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title">Vista Previa: Factura Inter-Sucursal</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body bg-light">
                        <div class="invoice-preview mx-auto shadow-sm" style="width: 21cm; min-height: 20cm;">
                            <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-4">
                                <div>
                                    <h2 class="m-0 fw-bold text-primary">PALWEB SURL</h2>
                                    <div class="small">Casa Matriz - ID Sucursal: {{sucOrig.id}}</div>
                                    <div class="small">Sucursal: {{sucOrig.nombre}}</div>
                                </div>
                                <div class="text-end">
                                    <h1 class="m-0 fw-bold opacity-25">FACTURA</h1>
                                    <div class="fw-bold fs-5"># 2026XXXXXXXX</div>
                                    <div class="small">Fecha: <?php echo date('d/m/Y'); ?></div>
                                </div>
                            </div>
                            <div class="row mb-5">
                                <div class="col-6">
                                    <div class="bg-primary text-white px-2 py-1 small fw-bold mb-2">FACTURAR A:</div>
                                    <div class="fw-bold">{{sucDest.nombre}}</div>
                                    <div class="small">Sucursal Destino ID: {{sucDest.id}}</div>
                                </div>
                                <div class="col-6">
                                    <div class="bg-primary text-white px-2 py-1 small fw-bold mb-2">DETALLES DE PAGO:</div>
                                    <div class="small">Términos: Transferencia Interna</div>
                                    <div class="small">Estado: Pendiente de Conciliación</div>
                                </div>
                            </div>
                            <table class="table table-sm table-bordered">
                                <thead class="table-primary text-white">
                                    <tr><th>Descripción</th><th class="text-center">Cant</th><th class="text-end">Precio</th><th class="text-end">Total</th></tr>
                                </thead>
                                <tbody>
                                    <tr v-for="it in cart">
                                        <td>{{it.nombre}}</td><td class="text-center">{{it.qty}}</td>
                                        <td class="text-end">${{it.price.toFixed(2)}}</td>
                                        <td class="text-end">${{(it.qty * it.price).toFixed(2)}}</td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr class="fw-bold fs-5"><td colspan="3" class="text-end">TOTAL FACTURA:</td><td class="text-end text-primary">${{total.toFixed(2)}}</td></tr>
                                </tfoot>
                            </table>
                        </div>
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
                query: '',
                results: [],
                cart: [],
                destSucursal: '',
                wholesaleProfit: 10,
                retailProfit: 0, // Añadido para evitar ReferenceError en el template
                isProcessing: false,
                sucOrig: <?php echo json_encode($sucOrigData); ?>,
                sucDest: { nombre: 'Seleccione Sucursal...', id: '?' }
            },
            computed: {
                total() {
                    return this.cart.reduce((acc, item) => acc + (item.qty * item.price), 0);
                },
                isReady() {
                    return this.destSucursal && this.cart.length > 0 && !this.isProcessing;
                }
            },
            methods: {
                async fetchDestInfo() {
                    if(!this.destSucursal) { this.sucDest = { nombre: 'Seleccione Sucursal...', id: '?' }; return; }
                    const res = await fetch(`branch_transfers.php?action=get_suc_info&id=${this.destSucursal}`);
                    this.sucDest = await res.json();
                },
                async searchProducts() {
                    if (this.query.length < 2) { this.results = []; return; }
                    const res = await fetch(`branch_transfers.php?action=search&q=${this.query}`);
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
                    new bootstrap.Modal(document.getElementById('docPreviewModal')).show();
                },
                showInvPreview() {
                    new bootstrap.Modal(document.getElementById('invPreviewModal')).show();
                },
                async submitTransfer() {
                    if (this.isProcessing) return;
                    if (!this.destSucursal) return alert("Seleccione sucursal destino");
                    if (!confirm("¿Está seguro de procesar esta transferencia y generar la factura correspondiente?")) return;
                    
                    this.isProcessing = true;
                    try {
                        const res = await fetch('branch_transfers.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                action: 'process_transfer',
                                dest_sucursal: this.destSucursal,
                                dest_suc_nombre: this.sucDest.nombre,
                                items: this.cart
                            })
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
