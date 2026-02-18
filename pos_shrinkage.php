<?php
// ARCHIVO: /var/www/palweb/api/pos_shrinkage.php
// VERSIÓN: V2.8 (MERMAS CON ID_SUCURSAL + HISTORIAL + REVERSIÓN)
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

// --- 0. AUTO-CORRECCIÓN BD ---
try {
    try { $pdo->exec("ALTER TABLE mermas_cabecera ADD COLUMN fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP"); } catch(Exception $e){}
    try { $pdo->exec("ALTER TABLE mermas_cabecera ADD COLUMN estado VARCHAR(20) DEFAULT 'PROCESADA'"); } catch(Exception $e){}
    // Asegurar que existe la columna id_sucursal
    try { $pdo->exec("ALTER TABLE mermas_cabecera ADD COLUMN id_sucursal INT DEFAULT 1"); } catch(Exception $e){}
} catch (Exception $e) {}

// --- 1. CONFIGURACIÓN ---
require_once 'config_loader.php';
$ALM_ID = intval($config['id_almacen']);
$SUC_ID = intval($config['id_sucursal']);
$EMP_ID = intval($config['id_empresa']);

// --- API: BUSCADOR AJAX ---
if (isset($_GET['action']) && $_GET['action'] === 'search_products') {
    ob_clean(); header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    
    if (strlen($q) < 2) { echo json_encode([]); exit; }

    try {
        $sql = "SELECT codigo, nombre, costo 
                FROM productos 
                WHERE (nombre LIKE ? OR codigo LIKE ?) 
                AND id_empresa = ? AND activo = 1 
                LIMIT 20";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(["%$q%", "%$q%", $EMP_ID]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) { echo json_encode([]); }
    exit;
}

// --- API: POST (GUARDAR / CANCELAR) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean(); header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    
    // ACCIÓN: CANCELAR (REVERTIR) MERMA
    if (isset($input['action']) && $input['action'] === 'cancel') {
        try {
            $idMerma = intval($input['id']);
            $pdo->beginTransaction();
            $kardex = new KardexEngine($pdo);

            $stmtCheck = $pdo->prepare("SELECT estado FROM mermas_cabecera WHERE id = ?");
            $stmtCheck->execute([$idMerma]);
            $estado = $stmtCheck->fetchColumn();

            if ($estado === 'CANCELADA') throw new Exception("Esta merma ya fue revertida.");

            $stmtItems = $pdo->prepare("SELECT * FROM mermas_detalle WHERE id_merma = ?");
            $stmtItems->execute([$idMerma]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            foreach ($items as $item) {
                // Devolver al stock
                KardexEngine::registrarMovimiento(
                    $pdo,
                    $item['id_producto'], $ALM_ID, $item['cantidad'], 'ENTRADA', 
                    "REVERSION MERMA #$idMerma", $item['costo_al_momento'],
                    $SUC_ID
                );
            }

            $pdo->prepare("UPDATE mermas_cabecera SET estado = 'CANCELADA' WHERE id = ?")->execute([$idMerma]);
            
            $pdo->commit();
            log_audit($pdo, 'MERMA_CANCELADA', 'Admin', ['id'=>$idMerma]);
            echo json_encode(['status' => 'success', 'msg' => 'Merma revertida y stock restaurado.']);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        }
        exit;
    }

    // ACCIÓN: NUEVA MERMA
    try {
        $pdo->beginTransaction();
        
        $fecha_salida = (!empty($input['fecha'])) ? $input['fecha'] . ' ' . date('H:i:s') : date('Y-m-d H:i:s');
        $motivo_gral = $input['motivo'] ?? 'General';

        // MODIFICADO: Guardar id_sucursal obtenida de pos.cfg
        $stmtHead = $pdo->prepare("INSERT INTO mermas_cabecera (usuario, motivo_general, total_costo_perdida, estado, id_sucursal, fecha_registro) VALUES (?, ?, ?, 'PROCESADA', ?, ?)");
        $stmtHead->execute([$_SESSION['user_id']??'Admin', $motivo_gral, $input['total'], $SUC_ID, $fecha_salida]);
        $idMerma = $pdo->lastInsertId();

        foreach ($input['items'] as $item) {
            $sku = $item['sku'];
            $qty = floatval($item['cantidad']);
            
            $stmtCosto = $pdo->prepare("SELECT costo FROM productos WHERE codigo = ? AND id_empresa = ?");
            $stmtCosto->execute([$sku, $EMP_ID]);
            $costo = floatval($stmtCosto->fetchColumn() ?: 0);

            $pdo->prepare("INSERT INTO mermas_detalle (id_merma, id_producto, cantidad, costo_al_momento, motivo_especifico) VALUES (?, ?, ?, ?, ?)")
                ->execute([$idMerma, $sku, $qty, $costo, $item['motivo']]);

            // Salida de stock
            KardexEngine::registrarMovimiento(
                $pdo,
                $sku, $ALM_ID, ($qty * -1), 'SALIDA', 
                "MERMA #$idMerma", $costo, 
                $SUC_ID,
                $fecha_salida
            );
        }

        $pdo->commit();
        log_audit($pdo, 'MERMA_REGISTRADA', 'Admin', ['id'=>$idMerma]);
        echo json_encode(['status' => 'success']);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

// --- CARGAR HISTORIAL ---
$recentMermas = [];
try {
    $stmtH = $pdo->query("SELECT * FROM mermas_cabecera ORDER BY id DESC LIMIT 15");
    $headers = $stmtH->fetchAll(PDO::FETCH_ASSOC);

    foreach ($headers as $h) {
        $stmtD = $pdo->prepare("
            SELECT d.*, p.nombre 
            FROM mermas_detalle d 
            LEFT JOIN productos p ON d.id_producto = p.codigo
            WHERE d.id_merma = ?
        ");
        $stmtD->execute([$h['id']]);
        $h['details'] = $stmtD->fetchAll(PDO::FETCH_ASSOC);
        
        $h['expanded'] = false;
        if (empty($h['estado'])) $h['estado'] = 'PROCESADA';
        $recentMermas[] = $h;
    }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8"><title>Mermas</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <script src="assets/js/vue.min.js"></script>
    <style>
        .cursor-pointer{cursor:pointer;}
        .list-group-item:hover{background-color:#f8f9fa;}
        .rotate-icon { transition: transform 0.3s; }
        .rotated { transform: rotate(180deg); }
        .row-cancelled { background-color: #ffeaea !important; color: #b02a37; }
    </style>
</head>
<body class="bg-light p-4">
    <div id="app" class="container">
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="text-danger fw-bold m-0"><i class="fas fa-trash-alt me-2"></i> Gestión de Mermas</h4>
            <a href="dashboard.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left"></i> Volver</a>
        </div>

        <div class="alert alert-danger py-2 mb-4 shadow-sm">
             <i class="fas fa-warehouse"></i> Almacén: <strong><?php echo $ALM_ID; ?></strong> | Sucursal: <strong><?php echo $SUC_ID; ?></strong>
        </div>

        <div class="card shadow-lg border-0 border-top border-5 border-danger mb-5">
            <div class="card-body">
                
                <!-- FECHA Y MOTIVO GENERAL -->
                <div class="row g-3 mb-3 pb-3 border-bottom">
                    <div class="col-md-3">
                        <label class="fw-bold text-muted small"><i class="fas fa-calendar-alt me-1"></i> FECHA DE SALIDA</label>
                        <input type="date" class="form-control" v-model="fecha">
                    </div>
                    <div class="col-md-3">
                        <label class="fw-bold text-muted small"><i class="fas fa-tag me-1"></i> MOTIVO GENERAL (CABECERA)</label>
                        <select class="form-select" v-model="generalReason">
                            <option>Vencimiento</option><option>Daño</option><option>Robo</option><option>Consumo</option><option>Error de Entrada</option>
                        </select>
                    </div>
                </div>

                <div class="row g-3 mb-4 align-items-end">
                    
                    <div class="col-md-6 position-relative">
                        <label class="fw-bold text-muted small">PRODUCTO</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white text-danger"><i class="fas fa-search"></i></span>
                            <input type="text" class="form-control" v-model="search" @input="filterProds" placeholder="Buscar por Nombre o SKU..." autocomplete="off">
                            <span class="input-group-text bg-white" v-if="isLoadingSearch"><i class="fas fa-spinner fa-spin text-danger"></i></span>
                        </div>
                        <ul class="list-group position-absolute w-100 shadow" style="z-index:1000;max-height:250px;overflow:auto;" v-if="filteredProds.length > 0">
                            <li class="list-group-item list-group-item-action cursor-pointer" v-for="p in filteredProds" @click="selectProd(p)">
                                <div class="d-flex justify-content-between">
                                    <strong>{{p.nombre}}</strong><small class="text-muted">{{p.codigo}}</small>
                                </div>
                                <div class="small text-danger">Costo: ${{p.costo}}</div>
                            </li>
                        </ul>
                    </div>

                    <div class="col-md-2"><label class="fw-bold text-muted small">CANTIDAD</label><input type="number" class="form-control" v-model.number="qty" min="0.1" step="0.01"></div>
                    <div class="col-md-3"><label class="fw-bold text-muted small">MOTIVO</label>
                        <select class="form-select" v-model="reason">
                            <option>Vencimiento</option><option>Daño</option><option>Robo</option><option>Consumo</option><option>Error de Entrada</option>
                        </select>
                    </div>
                    <div class="col-md-1"><button class="btn btn-danger w-100" @click="add" :disabled="!selectedSku || qty<=0"><i class="fas fa-plus"></i></button></div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light"><tr><th>Código</th><th>Producto</th><th>Causa</th><th class="text-center">Cant</th><th class="text-end">Costo Total</th><th></th></tr></thead>
                        <tbody>
                            <tr v-if="cart.length === 0"><td colspan="6" class="text-center text-muted py-4">Lista vacía.</td></tr>
                            <tr v-for="(item, i) in cart">
                                <td class="small text-muted">{{item.sku}}</td><td class="fw-bold">{{item.nombre}}</td><td><span class="badge bg-secondary">{{item.motivo}}</span></td>
                                <td class="text-center">{{item.cantidad}}</td><td class="text-end fw-bold text-danger">${{(item.cantidad*item.costo).toFixed(2)}}</td>
                                <td class="text-end"><button class="btn btn-sm btn-outline-danger border-0" @click="cart.splice(i,1)">&times;</button></td>
                            </tr>
                        </tbody>
                        <tfoot v-if="cart.length > 0"><tr class="table-active"><td colspan="4" class="text-end fw-bold">TOTAL:</td><td class="text-end fw-bold text-danger fs-5">${{totalLoss.toFixed(2)}}</td><td></td></tr></tfoot>
                    </table>
                </div>
                <div class="d-grid mt-4"><button class="btn btn-dark btn-lg" @click="submit" :disabled="cart.length==0"><i class="fas fa-check-circle me-2"></i> CONFIRMAR BAJA</button></div>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3"><h5 class="mb-0 fw-bold"><i class="fas fa-history text-secondary me-2"></i> Últimas 15 Mermas</h5></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0 align-middle">
                        <thead class="table-light"><tr><th style="width:50px"></th><th>ID</th><th>Fecha</th><th>Usuario</th><th>Motivo Gral</th><th class="text-end">Total Pérdida</th><th class="text-center">Estado</th></tr></thead>
                        <tbody>
                            <tr v-if="recentList.length===0"><td colspan="7" class="text-center py-4 text-muted">No hay historial reciente.</td></tr>
                            <template v-for="m in recentList">
                                <tr :class="{'row-cancelled': m.estado === 'CANCELADA'}">
                                    <td class="text-center cursor-pointer text-muted" @click="m.expanded = !m.expanded"><i class="fas fa-chevron-down rotate-icon" :class="{'rotated': m.expanded}"></i></td>
                                    <td class="fw-bold">#{{m.id}}</td>
                                    <td>{{formatDate(m.fecha_registro)}}</td>
                                    <td>{{m.usuario}}</td>
                                    <td>{{m.motivo_general}}</td>
                                    <td class="text-end fw-bold text-danger">${{parseFloat(m.total_costo_perdida).toFixed(2)}}</td>
                                    <td class="text-center">
                                        <span v-if="m.estado === 'CANCELADA'" class="badge bg-danger">REVERTIDA</span>
                                        <button v-else @click="cancelMerma(m.id)" class="btn btn-outline-secondary btn-sm" title="Revertir y devolver stock"><i class="fas fa-undo"></i></button>
                                    </td>
                                </tr>
                                <tr v-if="m.expanded">
                                    <td colspan="7" class="p-0">
                                        <div class="bg-light p-3 border-start border-4 border-danger">
                                            <h6 class="small fw-bold text-muted mb-2">Detalle de productos:</h6>
                                            <table class="table table-sm table-bordered bg-white mb-0 small">
                                                <thead><tr><th>Producto</th><th>Motivo Esp.</th><th class="text-end">Cant</th><th class="text-end">Costo U.</th></tr></thead>
                                                <tbody>
                                                    <tr v-for="d in m.details">
                                                        <td>{{d.nombre}} <span class="text-muted">({{d.id_producto}})</span></td>
                                                        <td>{{d.motivo_especifico}}</td>
                                                        <td class="text-end fw-bold">{{d.cantidad}}</td>
                                                        <td class="text-end">${{parseFloat(d.costo_al_momento).toFixed(2)}}</td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                            <div v-if="m.estado === 'CANCELADA'" class="mt-2 text-danger small fw-bold"><i class="fas fa-info-circle"></i> Esta merma fue revertida y el stock devuelto.</div>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

<script>
    new Vue({
        el: '#app',
        data: {
            search: '', filteredProds: [], isLoadingSearch: false, debounceTimer: null,
            selectedSku: '', currentProd: null, qty: 1, reason: 'Vencimiento', 
            generalReason: 'Vencimiento', fecha: new Date().toISOString().split('T')[0],
            cart: [],
            recentList: <?php echo json_encode($recentMermas); ?>
        },
        computed: { totalLoss() { return this.cart.reduce((a, b) => a + (b.cantidad * b.costo), 0); } },
        methods: {
            filterProds() {
                if(this.search.length < 2) { this.filteredProds = []; this.isLoadingSearch = false; return; }
                this.isLoadingSearch = true; clearTimeout(this.debounceTimer);
                this.debounceTimer = setTimeout(async () => {
                    try {
                        const res = await fetch(`pos_shrinkage.php?action=search_products&q=${encodeURIComponent(this.search)}`);
                        this.filteredProds = await res.json();
                    } catch (e) {} finally { this.isLoadingSearch = false; }
                }, 300);
            },
            selectProd(p) {
                this.selectedSku = p.codigo; this.currentProd = p; this.search = p.nombre; this.filteredProds = [];
            },
            add() {
                if (!this.currentProd) return;
                this.cart.push({ sku: this.selectedSku, nombre: this.currentProd.nombre, costo: parseFloat(this.currentProd.costo), cantidad: this.qty, motivo: this.reason });
                // Si el motivo general es el default, actualizarlo al motivo del primer item
                if (this.cart.length === 1) this.generalReason = this.reason;
                this.selectedSku = ''; this.currentProd = null; this.search = ''; this.qty = 1;
            },
            async submit() {
                if(!confirm('⚠️ ¿Confirmar salida de inventario?')) return;
                try {
                    const res = await fetch('pos_shrinkage.php', { method: 'POST', body: JSON.stringify({ motivo: this.generalReason, fecha: this.fecha, total: this.totalLoss, items: this.cart }) });
                    const d = await res.json();
                    if(d.status==='success') { alert('✅ Listo'); window.location.reload(); } else { alert('❌ Error: ' + d.msg); }
                } catch(e) { alert('Error de conexión'); }
            },
            async cancelMerma(id) {
                if(!confirm('⚠️ ¿Estás SEGURO de revertir esta merma?\nSe devolverán los productos al stock.')) return;
                try {
                    const res = await fetch('pos_shrinkage.php', { method: 'POST', body: JSON.stringify({ action: 'cancel', id: id }) });
                    const d = await res.json();
                    if(d.status==='success') { alert('✅ Merma revertida.'); window.location.reload(); } else { alert('❌ Error: ' + d.msg); }
                } catch(e) { alert('Error de conexión'); }
            },
            formatDate(d) { return d ? new Date(d).toLocaleString('es-ES') : '-'; }
        }
    });
</script>

<?php include_once 'menu_master.php'; ?>
</body>
</html>

