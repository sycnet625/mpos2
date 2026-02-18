<?php
// ARCHIVO: /var/www/palweb/api/pos_purchases.php
// VERSIÓN: V2.9 (FIX VISUALIZACIÓN HISTORIAL + COMBO CATEGORÍAS)

// ---------------------------------------------------------
// 🔒 SEGURIDAD: VERIFICACIÓN DE SESIÓN
// ---------------------------------------------------------
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

header('Content-Type: text/html; charset=utf-8');

require_once 'db.php';
require_once 'kardex_engine.php';
require_once 'pos_audit.php';

// --- 0. AUTO-CORRECCIÓN DE BASE DE DATOS ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS compras_cabecera (
        id INT AUTO_INCREMENT PRIMARY KEY,
        proveedor VARCHAR(100),
        total DECIMAL(12,2),
        usuario VARCHAR(50),
        notas TEXT,
        fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
        numero_factura VARCHAR(50),
        estado VARCHAR(20) DEFAULT 'PROCESADA'
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS compras_detalle (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_compra INT NOT NULL,
        id_producto VARCHAR(50) NOT NULL,
        cantidad DECIMAL(12,2) NOT NULL,
        costo_unitario DECIMAL(12,2) NOT NULL,
        subtotal DECIMAL(12,2) NOT NULL,
        INDEX (id_compra)
    )");

    try { $pdo->exec("ALTER TABLE compras_cabecera ADD COLUMN numero_factura VARCHAR(50) NULL"); } catch(Exception $e){}
    try { $pdo->exec("ALTER TABLE compras_cabecera ADD COLUMN fecha DATETIME DEFAULT CURRENT_TIMESTAMP"); } catch(Exception $e){}
    try { $pdo->exec("ALTER TABLE compras_cabecera ADD COLUMN estado VARCHAR(20) DEFAULT 'PROCESADA'"); } catch(Exception $e){}

} catch (Exception $e) { 
    error_log("DB FIX ERROR: " . $e->getMessage());
}

// --- 1. CONFIGURACIÓN ---
require_once 'config_loader.php';

$EMP_ID = intval($config['id_empresa']);
$SUC_ID = intval($config['id_sucursal']);
$ALM_ID = intval($config['id_almacen']);

// --- API BACKEND (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' || (isset($_GET['action']) && $_GET['action'] === 'get_next_sku')) {
    ob_clean(); 
    header('Content-Type: application/json');
    
    // API: SKU AUTOMÁTICO
    if (isset($_GET['action']) && $_GET['action'] === 'get_next_sku') {
        try {
            $prefix = str_repeat((string)$SUC_ID, 2); 
            $lenPrefix = strlen($prefix);
            
            $sql = "SELECT MAX(CAST(SUBSTRING(codigo, :len + 1) AS UNSIGNED)) as max_seq
                    FROM productos 
                    WHERE codigo LIKE CONCAT(:prefix, '%') 
                    AND id_empresa = :emp";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':len' => $lenPrefix, ':prefix' => $prefix, ':emp' => $EMP_ID]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $nextSeq = ($row['max_seq']) ? intval($row['max_seq']) + 1 : 1; 
            
            echo json_encode(['status' => 'success', 'prefix' => $prefix, 'next_seq' => $nextSeq]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        }
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    
    // API: CANCELAR COMPRA
    if (isset($input['action']) && $input['action'] === 'cancel') {
        try {
            $idCancel = intval($input['id']);
            $pdo->beginTransaction();
            $kardex = new KardexEngine($pdo);

            $stmtState = $pdo->prepare("SELECT estado FROM compras_cabecera WHERE id = ?");
            $stmtState->execute([$idCancel]);
            $compraData = $stmtState->fetch(PDO::FETCH_ASSOC);

            if (!$compraData) throw new Exception("Compra no encontrada.");
            if ($compraData['estado'] === 'CANCELADA') throw new Exception("Esta compra ya fue cancelada.");

            $stmtItems = $pdo->prepare("SELECT * FROM compras_detalle WHERE id_compra = ?");
            $stmtItems->execute([$idCancel]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            foreach ($items as $item) {
                $motivo = "CANCELACION COMPRA #$idCancel";
                $kardex->registrarMovimiento(
                    $item['id_producto'], $ALM_ID, $SUC_ID, 'SALIDA', 
                    -$item['cantidad'], $motivo, $item['costo_unitario'], 
                    $_SESSION['user_id'] ?? 'Admin'
                );
            }

            $stmtUpdate = $pdo->prepare("UPDATE compras_cabecera SET estado = 'CANCELADA' WHERE id = ?");
            $stmtUpdate->execute([$idCancel]);

            $pdo->commit();
            log_audit($pdo, 'COMPRA_CANCELADA', 'Admin', ['id'=>$idCancel]);
            echo json_encode(['status' => 'success', 'msg' => "Compra cancelada correctamente."]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        }
        exit;
    }

    // API: PROCESAR COMPRA PENDIENTE (LISTA DE COMPRA -> COMPRA REAL)
    if (isset($input['action']) && $input['action'] === 'process_pending') {
        try {
            $idCompra = intval($input['id']);
            $pdo->beginTransaction();
            $kardex = new KardexEngine($pdo);

            $stmtHead = $pdo->prepare("SELECT * FROM compras_cabecera WHERE id = ?");
            $stmtHead->execute([$idCompra]);
            $h = $stmtHead->fetch(PDO::FETCH_ASSOC);

            if (!$h) throw new Exception("Lista no encontrada.");
            if ($h['estado'] !== 'PENDIENTE') throw new Exception("Esta compra ya fue procesada o cancelada.");

            $stmtItems = $pdo->prepare("SELECT * FROM compras_detalle WHERE id_compra = ?");
            $stmtItems->execute([$idCompra]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            foreach ($items as $item) {
                $sku = $item['id_producto'];
                $cantidad = floatval($item['cantidad']);
                $costoNuevo = floatval($item['costo_unitario']);

                // 1. Actualizar Costo (Último)
                $pdo->prepare("UPDATE productos SET costo = ? WHERE codigo = ? AND id_empresa = ?")->execute([$costoNuevo, $sku, $EMP_ID]);

                // 2. Registrar Kardex
                $referenciaKardex = "COMPRA #$idCompra (PROCESADA DESDE LISTA)";
                $kardex->registrarMovimiento(
                    $sku, $ALM_ID, $SUC_ID, 'ENTRADA', $cantidad, $referenciaKardex, $costoNuevo, 
                    $_SESSION['user_id'] ?? 'Admin', $h['fecha']
                );
            }

            // 3. Marcar como PROCESADA
            $stmtUpdate = $pdo->prepare("UPDATE compras_cabecera SET estado = 'PROCESADA' WHERE id = ?");
            $stmtUpdate->execute([$idCompra]);

            $pdo->commit();
            log_audit($pdo, 'COMPRA_PENDIENTE_PROCESADA', 'Admin', ['id'=>$idCompra]);
            echo json_encode(['status' => 'success', 'msg' => "Lista #$idCompra convertida en compra real."]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        }
        exit;
    }

    // API: REVERTIR ITEM INDIVIDUAL
    if (isset($input['action']) && $input['action'] === 'revert_item') {
        try {
            $idCompra = intval($input['id_compra']);
            $idDetalle = intval($input['id_detalle']);
            
            $pdo->beginTransaction();
            $kardex = new KardexEngine($pdo);

            // Verificar que la compra no esté cancelada
            $stmtState = $pdo->prepare("SELECT estado FROM compras_cabecera WHERE id = ?");
            $stmtState->execute([$idCompra]);
            $compraData = $stmtState->fetch(PDO::FETCH_ASSOC);

            if (!$compraData) throw new Exception("Compra no encontrada.");
            if ($compraData['estado'] === 'CANCELADA') throw new Exception("Esta compra ya fue cancelada completamente.");

            // Obtener el item a revertir
            $stmtItem = $pdo->prepare("SELECT * FROM compras_detalle WHERE id = ? AND id_compra = ?");
            $stmtItem->execute([$idDetalle, $idCompra]);
            $item = $stmtItem->fetch(PDO::FETCH_ASSOC);

            if (!$item) throw new Exception("Detalle de compra no encontrado.");

            // Registrar salida en kardex
            $motivo = "REVERSION PARCIAL COMPRA #$idCompra (Item #$idDetalle)";
            $kardex->registrarMovimiento(
                $item['id_producto'], $ALM_ID, $SUC_ID, 'SALIDA', 
                -$item['cantidad'], $motivo, $item['costo_unitario'], 
                $_SESSION['user_id'] ?? 'Admin'
            );

            // Eliminar el detalle
            $stmtDelete = $pdo->prepare("DELETE FROM compras_detalle WHERE id = ?");
            $stmtDelete->execute([$idDetalle]);

            // Actualizar el total de la compra
            $stmtUpdateTotal = $pdo->prepare("UPDATE compras_cabecera SET total = total - ? WHERE id = ?");
            $stmtUpdateTotal->execute([$item['subtotal'], $idCompra]);

            // Si no quedan items, marcar como cancelada
            $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM compras_detalle WHERE id_compra = ?");
            $stmtCount->execute([$idCompra]);
            if ($stmtCount->fetchColumn() == 0) {
                $stmtCancel = $pdo->prepare("UPDATE compras_cabecera SET estado = 'CANCELADA' WHERE id = ?");
                $stmtCancel->execute([$idCompra]);
            }

            $pdo->commit();
            log_audit($pdo, 'COMPRA_ITEM_REVERTIDO', 'Admin', ['id_compra'=>$idCompra, 'id_detalle'=>$idDetalle, 'sku'=>$item['id_producto']]);
            echo json_encode(['status' => 'success', 'msg' => "Producto revertido correctamente."]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        }
        exit;
    }

    // API: GUARDAR COMPRA
    try {
        if (empty($input['items'])) throw new Exception("Lista vacía");

        $pdo->beginTransaction();
        $kardex = new KardexEngine($pdo);

        $fechaCompra = $input['fecha'] ? $input['fecha'] . ' ' . date('H:i:s') : date('Y-m-d H:i:s');
        $numFactura  = $input['factura'] ?? '';
        $proveedor   = $input['proveedor'] ?? 'Proveedor General';
        $notas       = ($input['notas'] ?? '') . " [Suc:$SUC_ID Alm:$ALM_ID]";
        $estado      = $input['estado'] ?? 'PROCESADA';
        
        $sqlHead = "INSERT INTO compras_cabecera (proveedor, total, usuario, notas, fecha, numero_factura, estado) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmtHead = $pdo->prepare($sqlHead);
        $stmtHead->execute([$proveedor, floatval($input['total']), $_SESSION['user_id'] ?? 'Admin', $notas, $fechaCompra, $numFactura, $estado]);
        $idCompra = $pdo->lastInsertId();

        foreach ($input['items'] as $item) {
            $sku = trim($item['sku']);
            $costoNuevo = floatval($item['costo']);
            $cantidad = floatval($item['cantidad']);
            $precioVenta = floatval($item['precio_venta'] ?? 0);
            $tipoCosto = $item['tipo_costo']; 
            $nombre = $item['nombre'];
            $cat = $item['categoria'] ?? 'General';

            // Verificar existencia
            $stmtCheck = $pdo->prepare("SELECT costo FROM productos WHERE codigo = ? AND id_empresa = ?");
            $stmtCheck->execute([$sku, $EMP_ID]);
            $prodExistente = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if (!$prodExistente) {
                // Si no existe, lo creamos igual (necesario para el detalle)
                $sqlNew = "INSERT INTO productos (codigo, nombre, costo, precio, precio_mayorista, categoria, activo, id_empresa) VALUES (?, ?, ?, ?, ?, ?, 1, ?)";
                $pdo->prepare($sqlNew)->execute([$sku, $nombre, $costoNuevo, $precioVenta, floatval($costoNuevo * 1.10), $cat, $EMP_ID]);
                $prodExistente = true;
            } 
            
            // SOLO AFECTAR COSTO Y KARDEX SI ESTA PROCESADA
            if ($estado === 'PROCESADA') {
                if ($prodExistente) {
                    $costoFinal = $costoNuevo;
                    if ($tipoCosto === 'promedio') {
                        $stmtStk = $pdo->prepare("SELECT cantidad FROM stock_almacen WHERE id_producto = ? AND id_almacen = ?");
                        $stmtStk->execute([$sku, $ALM_ID]); 
                        $stkActual = floatval($stmtStk->fetchColumn() ?: 0);
                        
                        if (($stkActual + $cantidad) > 0 && $stkActual > 0) {
                            $costoDb = ($prodExistente === true) ? $costoNuevo : $prodExistente['costo']; 
                            $costoFinal = (($stkActual * $costoDb) + ($cantidad * $costoNuevo)) / ($stkActual + $cantidad);
                        }
                    }
                    $pdo->prepare("UPDATE productos SET costo = ? WHERE codigo = ? AND id_empresa = ?")->execute([$costoFinal, $sku, $EMP_ID]);
                }

                $referenciaKardex = "COMPRA #$idCompra" . ($numFactura ? " ($numFactura)" : "");
                $kardex->registrarMovimiento(
                    $sku, $ALM_ID, $SUC_ID, 'ENTRADA', $cantidad, $referenciaKardex, $costoNuevo, 
                    $_SESSION['user_id'] ?? 'Admin', $fechaCompra
                );
            }

            $pdo->prepare("INSERT INTO compras_detalle (id_compra, id_producto, cantidad, costo_unitario, subtotal) VALUES (?, ?, ?, ?, ?)")
                ->execute([$idCompra, $sku, $cantidad, $costoNuevo, $cantidad * $costoNuevo]);
        }

        $pdo->commit();
        $auditAction = ($estado === 'PENDIENTE') ? 'LISTA_COMPRA_GUARDADA' : 'COMPRA_REGISTRADA';
        log_audit($pdo, $auditAction, 'Admin', ['id'=>$idCompra]);
        echo json_encode(['status' => 'success', 'msg' => ($estado === 'PENDIENTE' ? "Lista guardada #$idCompra" : "Compra registrada #$idCompra")]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

// --- CARGA DE DATOS ---
try {
    // Productos
    $stmtP = $pdo->prepare("SELECT codigo, nombre, costo, categoria, precio FROM productos WHERE activo=1 AND id_empresa = ? ORDER BY nombre ASC");
    $stmtP->execute([$EMP_ID]);
    $prods = $stmtP->fetchAll(PDO::FETCH_ASSOC);
    
    // Categorías desde la nueva tabla
    $stmtC = $pdo->prepare("SELECT nombre FROM categorias ORDER BY nombre ASC");
    $stmtC->execute();
    $categories = $stmtC->fetchAll(PDO::FETCH_COLUMN);
    if(empty($categories)) $categories = ['General']; // Fallback

} catch (Exception $e) { $prods = []; $categories = ['General']; }

// Historial (Últimas 15) - CON FIX DE COLLATION
$recentPurchases = [];
$historyError = "";

try {
    $stmtHist = $pdo->query("SELECT * FROM compras_cabecera ORDER BY id DESC LIMIT 15");
    $headers = $stmtHist->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($headers as $h) {
        // Fix de Collation
        $stmtDet = $pdo->prepare("
            SELECT d.*, p.nombre 
            FROM compras_detalle d 
            LEFT JOIN productos p ON d.id_producto = p.codigo
            WHERE d.id_compra = ?");
        $stmtDet->execute([$h['id']]);
        $details = $stmtDet->fetchAll(PDO::FETCH_ASSOC);
        
        $h['details'] = $details;
        $h['expanded'] = false; 
        
        if (!isset($h['estado']) || $h['estado'] === null) {
            $h['estado'] = 'PROCESADA';
        }
        
        $recentPurchases[] = $h;
    }
} catch (Exception $e) { 
    $historyError = $e->getMessage();
    $recentPurchases = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8"><title>Entrada de Mercancía</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <script src="assets/js/vue.min.js"></script>
    <style>
        .cursor-pointer{cursor:pointer;}
        .list-group-item:hover{background-color:#f8f9fa;}
        .detail-row { background-color: #f8f9fa; border-left: 4px solid #0d6efd; }
        .rotate-icon { transition: transform 0.3s; }
        .rotated { transform: rotate(180deg); }
        .row-cancelled { background-color: #ffeaea !important; color: #b02a37; text-decoration: line-through; }
        .row-pending { background-color: #fff9e6 !important; }
        .btn-cancel { font-size: 0.8rem; padding: 2px 8px; }
        .badge-pending { background-color: #ffc107; color: #000; }
    </style>
</head>
<body class="bg-light pb-5">
<div id="app" class="container py-4">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-primary mb-0"><i class="fas fa-truck-loading me-2"></i> Entrada / Compra</h3>
            <div class="text-muted small mt-1"><i class="fas fa-building"></i> Sucursal: <strong><?php echo $SUC_ID; ?></strong> | <i class="fas fa-warehouse"></i> Almacén: <strong><?php echo $ALM_ID; ?></strong></div>
        </div>
        <div>
            <button type="button" class="btn btn-outline-primary me-2" onclick="openCategoriesModal()"><i class="fas fa-tags"></i> Categorías</button>
            <button type="button" class="btn btn-success" onclick="openProductCreator(alCrearProducto)"><i class="fas fa-plus"></i> Nuevo Prod</button>
            <a href="dashboard.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i> Volver</a>
        </div>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-lg-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white fw-bold border-bottom"><i class="fas fa-plus-circle text-success me-1"></i> Item</div>
                <div class="card-body">
                    <div class="mb-3 position-relative">
                        <label class="small fw-bold text-muted">BUSCAR PRODUCTO</label>
                        <input type="text" class="form-control" v-model="search" @input="filterProds" placeholder="SKU o Nombre..." autocomplete="off">
                        <ul class="list-group position-absolute w-100 shadow" style="z-index:1000;max-height:250px;overflow:auto;" v-if="filteredProds.length > 0">
                            <li class="list-group-item list-group-item-action cursor-pointer" v-for="p in filteredProds" @click="selectProd(p)">
                                <div><strong>{{p.nombre}}</strong> <small class="text-muted">{{p.codigo}}</small></div>
                            </li>
                        </ul>
                        <div class="form-text text-primary cursor-pointer" v-if="search.length > 2 && filteredProds.length === 0" @click="setNewProduct">
                            <i class="fas fa-magic"></i> Generar Nuevo (Auto SKU): "{{search}}"
                        </div>
                    </div>
                    <div class="mb-2"><label class="small fw-bold">SKU</label><input type="text" class="form-control" v-model="form.sku" :readonly="isExisting"></div>
                    <div class="mb-2"><label class="small fw-bold">NOMBRE</label><input type="text" class="form-control" v-model="form.nombre" :readonly="isExisting"></div>
                    <div class="row g-2 mb-2">
                        <div class="col-6"><label class="small fw-bold text-danger">COSTO</label><input type="number" class="form-control" v-model.number="form.costo" step="0.01"></div>
                        <div class="col-6"><label class="small fw-bold text-success">CANTIDAD</label><input type="number" class="form-control" v-model.number="form.cantidad" step="0.01"></div>
                    </div>
                    
                    <div v-if="!isExisting" class="p-2 bg-light rounded border mb-2">
                        <div class="mb-2"><label class="small">Precio Venta</label><input type="number" class="form-control form-control-sm" v-model.number="form.precio_venta"></div>
                        
                        <div class="mb-1">
                            <label class="small fw-bold">Categoría</label>
                            <select class="form-select form-select-sm" v-model="form.categoria">
                                <option v-for="cat in categories" :value="cat">{{cat}}</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3" v-if="isExisting">
                        <label class="small fw-bold">COSTO</label>
                        <select class="form-select form-select-sm" v-model="form.tipo_costo"><option value="promedio">Promedio</option><option value="ultimo">Último</option></select>
                    </div>
                    <button class="btn btn-success w-100 fw-bold" @click="addItem" :disabled="!form.sku"><i class="fas fa-arrow-right"></i> AGREGAR</button>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white fw-bold border-bottom d-flex justify-content-between"><span>Resumen</span><span class="badge bg-primary">{{cart.length}} Items</span></div>
                <div class="card-body d-flex flex-column">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6"><label class="small fw-bold">Fecha Compra</label><input type="date" class="form-control" v-model="header.fecha"></div>
                        <div class="col-md-6"><label class="small fw-bold">N° Factura</label><input type="text" class="form-control" v-model="header.factura" placeholder="F-000123"></div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6"><label class="small">Proveedor</label><input type="text" class="form-control" v-model="header.proveedor" placeholder="Nombre Proveedor"></div>
                        <div class="col-md-6"><label class="small">Notas</label><input type="text" class="form-control" v-model="header.notas" placeholder="Detalles adicionales"></div>
                    </div>
                    <div class="table-responsive flex-grow-1 border rounded mb-3">
                        <table class="table table-striped table-hover align-middle mb-0">
                            <thead class="table-dark small"><tr><th>SKU</th><th>PRODUCTO</th><th>CANT</th><th>COSTO</th><th>TOTAL</th><th></th></tr></thead>
                            <tbody>
                                <tr v-for="(item, idx) in cart" :key="idx">
                                    <td class="small">{{item.sku}}</td><td>{{item.nombre}}</td><td class="fw-bold">{{item.cantidad}}</td><td>${{item.costo}}</td><td class="fw-bold text-primary">${{(item.cantidad*item.costo).toFixed(2)}}</td>
                                    <td><button class="btn btn-sm btn-outline-danger border-0" @click="cart.splice(idx, 1)">&times;</button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded border">
                        <div><small>TOTAL</small><span class="h2 fw-bold text-dark mb-0">${{totalCart.toFixed(2)}}</span></div>
                        <div class="btn-group">
                            <button class="btn btn-outline-warning btn-lg fw-bold px-3" @click="submitPurchase('PENDIENTE')" :disabled="cart.length===0 || isProcessing">LISTA COMPRA</button>
                            <button class="btn btn-primary btn-lg fw-bold px-4" @click="submitPurchase('PROCESADA')" :disabled="cart.length===0 || isProcessing">PROCESAR</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-5">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold"><i class="fas fa-history text-secondary me-2"></i> Últimas 15 Compras</h5>
            <button class="btn btn-sm btn-outline-secondary" onclick="location.reload()"><i class="fas fa-sync-alt"></i></button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table mb-0 table-hover align-middle">
                    <thead class="table-light"><tr><th style="width: 50px;"></th><th>#ID</th><th>Fecha</th><th>Factura</th><th>Proveedor</th><th>Usuario</th><th class="text-end">Total</th><th class="text-center" style="width:120px;">Acción</th></tr></thead>
                    
                    <tbody v-if="!recentList || recentList.length === 0">
                        <tr><td colspan="8" class="text-center py-5 text-muted h6">No hay historial de compras reciente.</td></tr>
                    </tbody>
                    
                    <tbody v-for="compra in recentList" :key="compra.id" :class="{'row-cancelled': compra.estado === 'CANCELADA', 'row-pending': compra.estado === 'PENDIENTE'}">
                        <tr class="cursor-pointer" @click="toggleDetails(compra)">
                            <td class="text-center text-muted"><i class="fas fa-chevron-down rotate-icon" :class="{'rotated': compra.expanded}"></i></td>
                            <td class="fw-bold">#{{compra.id}}</td>
                            <td>{{formatDate(compra.fecha)}}</td>
                            <td><span class="badge bg-light text-dark border">{{compra.numero_factura || 'S/N'}}</span></td>
                            <td>{{compra.proveedor || 'General'}}</td>
                            <td class="small text-muted">{{compra.usuario}}</td>
                            <td class="text-end fw-bold text-success" :class="{'text-danger': compra.estado === 'CANCELADA', 'text-warning': compra.estado === 'PENDIENTE'}">${{parseFloat(compra.total).toFixed(2)}}</td>
                            <td class="text-center" @click.stop>
                                <span v-if="compra.estado === 'CANCELADA'" class="badge bg-danger">CANCELADA</span>
                                <div v-else-if="compra.estado === 'PENDIENTE'" class="btn-group btn-group-sm">
                                    <button @click="processPending(compra.id)" class="btn btn-success" title="Convertir a Compra Real">
                                        <i class="fas fa-check"></i> PROCESAR
                                    </button>
                                    <button @click="cancelPurchase(compra.id)" class="btn btn-outline-danger" title="Eliminar Lista">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                                <button v-else @click="cancelPurchase(compra.id)" class="btn btn-outline-danger btn-sm btn-cancel">
                                    <i class="fas fa-undo me-1"></i> Revertir
                                </button>
                            </td>
                        </tr>
                        <tr v-if="compra.expanded" class="bg-light">
                            <td colspan="8" class="p-0">
                                <div class="detail-row p-3 shadow-inset">
                                    <h6 class="small fw-bold text-muted mb-2"><i class="fas fa-list-ul me-1"></i> Detalle de productos:</h6>
                                    <table class="table table-sm table-bordered bg-white mb-0 small">
                                        <thead><tr><th>SKU</th><th>Producto</th><th class="text-end">Cant</th><th class="text-end">Costo U.</th><th class="text-end">Subtotal</th><th class="text-center" style="width:80px;">Acción</th></tr></thead>
                                        <tbody>
                                            <tr v-for="det in compra.details" :key="det.id">
                                                <td class="text-muted">{{det.id_producto}}</td>
                                                <td>{{det.nombre || 'Producto Eliminado'}}</td>
                                                <td class="text-end fw-bold">{{det.cantidad}}</td>
                                                <td class="text-end">${{parseFloat(det.costo_unitario).toFixed(2)}}</td>
                                                <td class="text-end">${{parseFloat(det.subtotal).toFixed(2)}}</td>
                                                <td class="text-center">
                                                    <button v-if="compra.estado !== 'CANCELADA'" 
                                                        @click.stop="revertItem(compra.id, det.id, det.id_producto, det.nombre, det.cantidad)" 
                                                        class="btn btn-outline-warning btn-sm py-0 px-1" 
                                                        title="Revertir solo este producto">
                                                        <i class="fas fa-undo fa-sm"></i>
                                                    </button>
                                                    <span v-else class="text-muted">-</span>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <div class="mt-2 text-muted small fst-italic">Notas: {{compra.notas || 'Sin notas'}}</div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>
<script>
    // INYECCIÓN DE DATOS PHP A JS (BLINDADA)
    const allProds = <?php echo json_encode($prods, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
    const allCats = <?php echo json_encode($categories, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
    const recentPurchases = <?php echo !empty($recentPurchases) ? json_encode($recentPurchases, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) : '[]'; ?>;
    const historyErr = "<?php echo addslashes($historyError); ?>";
    const today = new Date().toISOString().split('T')[0];

    // Debug en consola si hay error de SQL
    if(historyErr) {
        console.error("SQL History Error:", historyErr);
        console.log("Tip: Verifica la tabla compras_cabecera.");
    }

    const app = new Vue({
        el: '#app',
        data: {
            search: '', filteredProds: [], isExisting: false, isProcessing: false,
            form: { sku:'', nombre:'', costo:0, cantidad:1, tipo_costo:'promedio', precio_venta:0, categoria: allCats[0] || 'General' },
            header: { proveedor:'', notas:'', fecha: today, factura: '' }, 
            cart: [],
            recentList: recentPurchases, 
            categories: allCats
        },
        computed: { totalCart() { return this.cart.reduce((a, i) => a + (i.cantidad * i.costo), 0); } },
        methods: {
            async reloadCats() {
                try {
                    const res = await fetch('categories_api.php');
                    const data = await res.json();
                    this.categories = data.map(c => c.nombre);
                } catch(e) { console.error(e); }
            },
            filterProds() {
                if(this.search.length < 2) { this.filteredProds = []; return; }
                const q = this.search.toLowerCase();
                this.filteredProds = allProds.filter(p => p.codigo.toLowerCase().includes(q) || p.nombre.toLowerCase().includes(q)).slice(0, 8);
            },
            selectProd(p) {
                this.form.sku = p.codigo; this.form.nombre = p.nombre; this.form.costo = parseFloat(p.costo);
                this.form.precio_venta = parseFloat(p.precio); this.form.categoria = p.categoria;
                this.isExisting = true; this.filteredProds = []; this.search = '';
            },
            
            // GENERAR SKU AUTOMÁTICO (CON RELLENO DE CEROS)
            async setNewProduct() {
                this.resetForm(); 
                this.form.nombre = this.search;
                this.form.sku = "Generando..."; 

                try {
                    const res = await fetch('pos_purchases.php?action=get_next_sku');
                    const data = await res.json();
                    
                    if (data.status === 'success') {
                        let prefix = data.prefix;
                        let seq = parseInt(data.next_seq);
                        
                        // Función formadora: Prefijo + Secuencia rellena a 4 dígitos con ceros
                        // Ej: 44 + 0001 = 440001
                        let formatSku = (s) => prefix + s.toString().padStart(4, '0');
                        let candidate = formatSku(seq);
                        
                        // Evitar repetidos en carrito actual
                        const inCart = (s) => this.cart.some(item => item.sku == s);
                        while (inCart(candidate)) { 
                            seq++; 
                            candidate = formatSku(seq); 
                        }

                        this.form.sku = candidate;
                        this.search = ''; 
                        this.filteredProds = [];
                    } else { alert("Error obteniendo secuencia: " + data.msg); }
                } catch (e) { console.error(e); alert("Error de conexión"); this.form.sku = ""; }
            },

            resetForm() {
                this.isExisting = false; this.form = { sku:'', nombre:'', costo:0, cantidad:1, tipo_costo:'promedio', precio_venta:0, categoria: this.categories[0] || 'General' }; this.search = '';
            },
            addItem() {
                if(!this.form.sku) return alert("SKU obligatorio");
                if (this.cart.some(i => i.sku === this.form.sku)) {
                    if(!confirm('El SKU ' + this.form.sku + ' ya está en la lista. ¿Agregar otra línea?')) return;
                }
                this.cart.push({ ...this.form, isExisting: this.isExisting }); this.resetForm();
            },
            toggleDetails(compra) {
                compra.expanded = !compra.expanded;
                this.$forceUpdate();
            },
            formatDate(d) {
                if(!d) return '-';
                return new Date(d).toLocaleDateString('es-ES') + ' ' + new Date(d).toLocaleTimeString('es-ES', {hour:'2-digit', minute:'2-digit'});
            },
            async submitPurchase(estado = 'PROCESADA') {
                const msg = estado === 'PENDIENTE' ? '¿Guardar como lista de compra?' : '¿Confirmar entrada de mercancía?';
                if(!confirm(msg)) return;
                this.isProcessing = true;
                try {
                    const res = await fetch('pos_purchases.php', { 
                        method: 'POST', 
                        body: JSON.stringify({ ...this.header, total: this.totalCart, items: this.cart, estado: estado }) 
                    });
                    const data = await res.json();
                    if(data.status === 'success') { alert('✅ Éxito'); location.reload(); } else { alert('❌ ' + data.msg); }
                } catch (e) { alert('Error de conexión'); } finally { this.isProcessing = false; }
            },
            async processPending(id) {
                if(!confirm('¿Deseas PROCESAR esta lista ahora?\n\nEsto AUMENTARÁ las existencias en el inventario.')) return;
                try {
                    const res = await fetch('pos_purchases.php', { 
                        method: 'POST', 
                        body: JSON.stringify({ action: 'process_pending', id: id }) 
                    });
                    const data = await res.json();
                    if(data.status === 'success') { alert('✅ Compra procesada correctamente'); location.reload(); } else { alert('❌ ' + data.msg); }
                } catch (e) { alert('Error de conexión'); }
            },
            async cancelPurchase(id) {
                if(!confirm('⚠️ ¿Estás SEGURO de REVERTIR esta entrada?\n\nEsto RESTARÁ las existencias del inventario.')) return;
                try {
                    const res = await fetch('pos_purchases.php', { method: 'POST', body: JSON.stringify({ action: 'cancel', id: id }) });
                    const data = await res.json();
                    if(data.status === 'success') { alert('✅ Revertida correctamente'); location.reload(); } else { alert('❌ ' + data.msg); }
                } catch (e) { alert('Error de conexión'); }
            },
            async revertItem(compraId, detalleId, sku, nombre, cantidad) {
                if(!confirm('⚠️ ¿Revertir solo este producto?\n\nProducto: ' + (nombre || sku) + '\nCantidad: ' + cantidad + '\n\nEsto RESTARÁ ' + cantidad + ' unidades del inventario.')) return;
                try {
                    const res = await fetch('pos_purchases.php', { method: 'POST', body: JSON.stringify({ action: 'revert_item', id_compra: compraId, id_detalle: detalleId }) });
                    const data = await res.json();
                    if(data.status === 'success') { alert('✅ Producto revertido correctamente'); location.reload(); } else { alert('❌ ' + data.msg); }
                } catch (e) { alert('Error de conexión'); }
            }
        }
    });

    function alCrearProducto(nuevoProducto) { console.log("Producto recibido:", nuevoProducto); }

    function reloadCategorySelects() {
        if (typeof app !== 'undefined' && typeof app.reloadCats === 'function') {
            app.reloadCats();
        }
        if (typeof loadCategoriesQP === 'function') {
            loadCategoriesQP();
        }
    }
</script>
<script src="assets/js/bootstrap.bundle.min.js"></script>

<?php include_once 'pos_newprod.php'; ?>
<?php include_once 'modal_categories.php'; ?>
<?php include_once 'menu_master.php'; ?>
</body>
</html>

