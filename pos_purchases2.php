<?php
// ARCHIVO: /var/www/palweb/api/pos_purchases.php
// VERSIÓN: V2.4 (CON LÓGICA DE CANCELACIÓN Y RESTA DE STOCK)


// ---------------------------------------------------------
// 🔒 SEGURIDAD: VERIFICACIÓN DE SESIÓN
// ---------------------------------------------------------
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}


header('Content-Type: text/html; charset=utf-8');
session_start();

require_once 'db.php';
require_once 'kardex_engine.php';
require_once 'pos_audit.php';

// --- 0. AUTO-CORRECCIÓN DE BASE DE DATOS (ESTRUCTURA) ---
try {
    // 1. Asegurar tablas base
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

    // 2. Parches para columnas faltantes (si la tabla ya existía sin ellas)
    try { $pdo->exec("ALTER TABLE compras_cabecera ADD COLUMN numero_factura VARCHAR(50) NULL"); } catch(Exception $e){}
    try { $pdo->exec("ALTER TABLE compras_cabecera ADD COLUMN fecha DATETIME DEFAULT CURRENT_TIMESTAMP"); } catch(Exception $e){}
    try { $pdo->exec("ALTER TABLE compras_cabecera ADD COLUMN estado VARCHAR(20) DEFAULT 'PROCESADA'"); } catch(Exception $e){}

} catch (Exception $e) { 
    error_log("DB FIX ERROR: " . $e->getMessage());
}

// --- 1. CARGAR CONFIGURACIÓN ---
$configFile = __DIR__ . '/pos.cfg';
$config = ["id_empresa" => 1, "id_sucursal" => 1, "id_almacen" => 1];
if (file_exists($configFile)) {
    $loaded = json_decode(file_get_contents($configFile), true);
    if ($loaded) $config = array_merge($config, $loaded);
}

$EMP_ID = intval($config['id_empresa']);
$SUC_ID = intval($config['id_sucursal']);
$ALM_ID = intval($config['id_almacen']);

// --- API BACKEND (GUARDAR / CANCELAR) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean(); 
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    
    // --- ACCIÓN: CANCELAR COMPRA (RESTA STOCK) ---
    if (isset($input['action']) && $input['action'] === 'cancel') {
        try {
            $idCancel = intval($input['id']);
            $pdo->beginTransaction();
            $kardex = new KardexEngine($pdo);

            // 1. Verificar estado actual
            $stmtState = $pdo->prepare("SELECT estado FROM compras_cabecera WHERE id = ?");
            $stmtState->execute([$idCancel]);
            $compraData = $stmtState->fetch(PDO::FETCH_ASSOC);

            if (!$compraData) throw new Exception("Compra no encontrada.");
            if ($compraData['estado'] === 'CANCELADA') throw new Exception("Esta compra ya fue cancelada.");

            // 2. Obtener items para revertir Kardex (DAR SALIDA)
            $stmtItems = $pdo->prepare("SELECT * FROM compras_detalle WHERE id_compra = ?");
            $stmtItems->execute([$idCancel]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            foreach ($items as $item) {
                // AQUÍ ESTÁ LA LÓGICA DE RESTA: 'SALIDA'
                $motivo = "CANCELACION COMPRA #$idCancel (POR ERROR)";
                
                $kardex->registrarMovimiento(
                    $item['id_producto'], 
                    $ALM_ID, 
                    $SUC_ID, 
                    'SALIDA', // <--- ESTO RESTA EL STOCK
                    -$item['cantidad'], 
                    $motivo, 
                    $item['costo_unitario'], 
                    $_SESSION['user_id'] ?? 'Admin'
                );
            }

            // 3. Actualizar estado a CANCELADA
            $stmtUpdate = $pdo->prepare("UPDATE compras_cabecera SET estado = 'CANCELADA' WHERE id = ?");
            $stmtUpdate->execute([$idCancel]);

            $pdo->commit();
            log_audit($pdo, 'COMPRA_CANCELADA', 'Admin', ['id'=>$idCancel]);
            echo json_encode(['status' => 'success', 'msg' => "Compra #$idCancel cancelada y stock descontado."]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        }
        exit;
    }

    // --- ACCIÓN: NUEVA COMPRA (DEFAULT) ---
    try {
        if (empty($input['items'])) throw new Exception("Lista vacía");

        $pdo->beginTransaction();
        $kardex = new KardexEngine($pdo);

        // Datos
        $fechaCompra = $input['fecha'] ? $input['fecha'] . ' ' . date('H:i:s') : date('Y-m-d H:i:s');
        $numFactura  = $input['factura'] ?? '';
        $proveedor   = $input['proveedor'] ?? 'Proveedor General';
        $notas       = ($input['notas'] ?? '') . " [Suc:$SUC_ID Alm:$ALM_ID]";
        
        // Insertar Cabecera (Estado default PROCESADA)
        $sqlHead = "INSERT INTO compras_cabecera (proveedor, total, usuario, notas, fecha, numero_factura, estado) VALUES (?, ?, ?, ?, ?, ?, 'PROCESADA')";
        $stmtHead = $pdo->prepare($sqlHead);
        $stmtHead->execute([
            $proveedor, 
            floatval($input['total']), 
            $_SESSION['user_id'] ?? 'Admin', 
            $notas,
            $fechaCompra,
            $numFactura
        ]);
        $idCompra = $pdo->lastInsertId();

        // Items
        foreach ($input['items'] as $item) {
            $sku = trim($item['sku']);
            $costoNuevo = floatval($item['costo']);
            $cantidad = floatval($item['cantidad']);
            $precioVenta = floatval($item['precio_venta'] ?? 0);
            $tipoCosto = $item['tipo_costo']; 
            $nombre = $item['nombre'];
            $cat = $item['categoria'] ?? 'General';

            // Verificar/Crear Producto
            $stmtCheck = $pdo->prepare("SELECT costo FROM productos WHERE codigo = ? AND id_empresa = ?");
            $stmtCheck->execute([$sku, $EMP_ID]);
            $prodExistente = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if (!$prodExistente) {
                $sqlNew = "INSERT INTO productos (codigo, nombre, costo, precio, categoria, activo, id_empresa) VALUES (?, ?, ?, ?, ?, 1, ?)";
                $pdo->prepare($sqlNew)->execute([$sku, $nombre, $costoNuevo, $precioVenta, $cat, $EMP_ID]);
            } else {
                $costoFinal = $costoNuevo;
                if ($tipoCosto === 'promedio') {
                    $stmtStk = $pdo->prepare("SELECT cantidad FROM stock_almacen WHERE id_producto = ? AND id_almacen = ?");
                    $stmtStk->execute([$sku, $ALM_ID]); 
                    $stkActual = floatval($stmtStk->fetchColumn() ?: 0);
                    
                    if (($stkActual + $cantidad) > 0 && $stkActual > 0) {
                        $costoFinal = (($stkActual * $prodExistente['costo']) + ($cantidad * $costoNuevo)) / ($stkActual + $cantidad);
                    }
                }
                $pdo->prepare("UPDATE productos SET costo = ? WHERE codigo = ? AND id_empresa = ?")->execute([$costoFinal, $sku, $EMP_ID]);
            }

            // Insertar Detalle
            $pdo->prepare("INSERT INTO compras_detalle (id_compra, id_producto, cantidad, costo_unitario, subtotal) VALUES (?, ?, ?, ?, ?)")
                ->execute([$idCompra, $sku, $cantidad, $costoNuevo, $cantidad * $costoNuevo]);

            // Registrar Kardex (ENTRADA)
            $referenciaKardex = "COMPRA #$idCompra" . ($numFactura ? " ($numFactura)" : "");
            $kardex->registrarMovimiento(
                $sku, $ALM_ID, $SUC_ID, 'ENTRADA', $cantidad, $referenciaKardex, $costoNuevo, 
                $_SESSION['user_id'] ?? 'Admin', $fechaCompra
            );
        }

        $pdo->commit();
        log_audit($pdo, 'COMPRA_REGISTRADA', 'Admin', ['id'=>$idCompra]);
        echo json_encode(['status' => 'success', 'msg' => "Compra #$idCompra registrada."]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

// --- CARGAR DATOS INICIALES ---

// 1. Productos
try {
    $stmtP = $pdo->prepare("SELECT codigo, nombre, costo, categoria, precio FROM productos WHERE activo=1 AND id_empresa = ? ORDER BY nombre ASC");
    $stmtP->execute([$EMP_ID]);
    $prods = $stmtP->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $prods = []; }

// 2. Últimas 5 Compras (Historial)
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
            LEFT JOIN productos p ON d.id_producto COLLATE utf8mb4_unicode_ci = p.codigo COLLATE utf8mb4_unicode_ci
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
        .row-cancelled { background-color: #ffeaea !important; color: #b02a37; }
        .btn-cancel { font-size: 0.8rem; padding: 2px 8px; }
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
                        <div class="form-text text-primary cursor-pointer" v-if="search.length > 2 && filteredProds.length === 0" @click="setNewProduct"><i class="fas fa-plus"></i> Crear nuevo: "{{search}}"</div>
                    </div>
                    <div class="mb-2"><label class="small fw-bold">SKU</label><input type="text" class="form-control" v-model="form.sku" :readonly="isExisting"></div>
                    <div class="mb-2"><label class="small fw-bold">NOMBRE</label><input type="text" class="form-control" v-model="form.nombre" :readonly="isExisting"></div>
                    <div class="row g-2 mb-2">
                        <div class="col-6"><label class="small fw-bold text-danger">COSTO</label><input type="number" class="form-control" v-model.number="form.costo" step="0.01"></div>
                        <div class="col-6"><label class="small fw-bold text-success">CANTIDAD</label><input type="number" class="form-control" v-model.number="form.cantidad" step="0.01"></div>
                    </div>
                    <div v-if="!isExisting" class="p-2 bg-light rounded border mb-2">
                        <div class="mb-2"><label class="small">Precio Venta</label><input type="number" class="form-control form-control-sm" v-model.number="form.precio_venta"></div>
                        <div class="mb-1"><label class="small">Categoría</label><input type="text" class="form-control form-control-sm" v-model="form.categoria"></div>
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
                        <button class="btn btn-primary btn-lg fw-bold px-5" @click="submitPurchase" :disabled="cart.length===0 || isProcessing">PROCESAR</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0 fw-bold"><i class="fas fa-history text-secondary me-2"></i> Últimas 5 Compras</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 50px;"></th>
                            <th>#ID</th>
                            <th>Fecha</th>
                            <th>Factura</th>
                            <th>Proveedor</th>
                            <th>Usuario</th>
                            <th class="text-end">Total</th>
                            <th class="text-center" style="width:120px;">Estado</th>
                        </tr>
                    </thead>
                    <tbody v-if="recentList.length === 0">
                        <tr><td colspan="8" class="text-center py-4 text-muted">No hay compras registradas recientemente.</td></tr>
                    </tbody>
                    <tbody v-for="compra in recentList" :key="compra.id" :class="{'row-cancelled': compra.estado === 'CANCELADA'}">
                        <tr class="cursor-pointer">
                            <td class="text-center text-muted" @click="toggleDetails(compra)">
                                <i class="fas fa-chevron-down rotate-icon" :class="{'rotated': compra.expanded}"></i>
                            </td>
                            <td class="fw-bold">#{{compra.id}}</td>
                            <td>{{formatDate(compra.fecha)}}</td>
                            <td><span class="badge bg-light text-dark border">{{compra.numero_factura || 'S/N'}}</span></td>
                            <td>{{compra.proveedor}}</td>
                            <td class="small text-muted">{{compra.usuario}}</td>
                            <td class="text-end fw-bold text-success" :class="{'text-danger': compra.estado === 'CANCELADA'}">${{parseFloat(compra.total).toFixed(2)}}</td>
                            <td class="text-center">
                                <span v-if="compra.estado === 'CANCELADA'" class="badge bg-danger">CANCELADA</span>
                                <button v-else @click="cancelPurchase(compra.id)" class="btn btn-outline-danger btn-sm btn-cancel">
                                    <i class="fas fa-ban me-1"></i> Cancelar
                                </button>
                            </td>
                        </tr>
                        <tr v-if="compra.expanded">
                            <td colspan="8" class="p-0">
                                <div class="detail-row p-3">
                                    <h6 class="small fw-bold text-muted mb-2">Detalle de productos:</h6>
                                    <table class="table table-sm table-bordered bg-white mb-0 small">
                                        <thead><tr><th>Producto</th><th class="text-end">Cant</th><th class="text-end">Costo U.</th><th class="text-end">Subtotal</th></tr></thead>
                                        <tbody>
                                            <tr v-for="det in compra.details">
                                                <td>{{det.nombre}} <span class="text-muted">({{det.id_producto}})</span></td>
                                                <td class="text-end fw-bold">{{det.cantidad}}</td>
                                                <td class="text-end">${{parseFloat(det.costo_unitario).toFixed(2)}}</td>
                                                <td class="text-end">${{parseFloat(det.subtotal).toFixed(2)}}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <div class="mt-2 text-muted small" v-if="compra.notas">
                                        <i class="fas fa-sticky-note me-1"></i> Notas: {{compra.notas}}
                                    </div>
                                    <div v-if="compra.estado === 'CANCELADA'" class="mt-2 text-danger small fw-bold">
                                        <i class="fas fa-info-circle"></i> Esta compra fue anulada y el stock fue restado del inventario (Motivo: Error).
                                    </div>
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
    const allProds = <?php echo json_encode($prods); ?>;
    const recentPurchases = <?php echo json_encode($recentPurchases); ?>;
    const historyErr = "<?php echo addslashes($historyError); ?>";
    const today = new Date().toISOString().split('T')[0];

    // Debug en consola si hay error de SQL
    if(historyErr) {
        console.error("SQL History Error:", historyErr);
        console.log("Tip: Verifica la tabla compras_cabecera.");
    }

    new Vue({
        el: '#app',
        data: {
            search: '', filteredProds: [], isExisting: false, isProcessing: false,
            form: { sku:'', nombre:'', costo:0, cantidad:1, tipo_costo:'promedio', precio_venta:0, categoria:'General' },
            header: { proveedor:'', notas:'', fecha: today, factura: '' }, 
            cart: [],
            recentList: recentPurchases
        },
        computed: { totalCart() { return this.cart.reduce((a, i) => a + (i.cantidad * i.costo), 0); } },
        methods: {
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
            setNewProduct() {
                this.resetForm(); this.form.nombre = this.search; 
                this.form.sku = 'NEW-' + Math.floor(Math.random() * 10000); this.search = ''; this.filteredProds = [];
            },
            resetForm() {
                this.isExisting = false; this.form = { sku:'', nombre:'', costo:0, cantidad:1, tipo_costo:'promedio', precio_venta:0, categoria:'General' }; this.search = '';
            },
            addItem() {
                if(!this.form.sku) return alert("SKU obligatorio");
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
            async submitPurchase() {
                if(!confirm('¿Confirmar entrada de mercancía?')) return;
                this.isProcessing = true;
                try {
                    const res = await fetch('pos_purchases.php', { method: 'POST', body: JSON.stringify({ ...this.header, total: this.totalCart, items: this.cart }) });
                    const data = await res.json();
                    if(data.status === 'success') { alert('✅ Éxito'); location.reload(); } else { alert('❌ ' + data.msg); }
                } catch (e) { alert('Error de conexión'); } finally { this.isProcessing = false; }
            },
            async cancelPurchase(id) {
                if(!confirm('⚠️ ¿Estás SEGURO de cancelar esta entrada?\n\nEsto RESTARÁ las existencias del inventario y marcará la compra como cancelada.')) return;
                
                try {
                    const res = await fetch('pos_purchases.php', { 
                        method: 'POST', 
                        body: JSON.stringify({ action: 'cancel', id: id }) 
                    });
                    const data = await res.json();
                    
                    if(data.status === 'success') { 
                        alert('✅ Compra cancelada y stock revertido.'); 
                        location.reload(); 
                    } else { 
                        alert('❌ Error: ' + data.msg); 
                    }
                } catch (e) { alert('Error de conexión al cancelar'); }
            }
        }
    });

    function alCrearProducto(nuevoProducto) {
        console.log("Producto recibido:", nuevoProducto);
    }

</script>
<script src="assets/js/bootstrap.bundle.min.js"></script>
<?php include_once 'menu_master.php'; ?>
<?php include_once 'pos_newprod.php'; ?>
</body>
</html>




