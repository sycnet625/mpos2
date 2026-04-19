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
    try { $pdo->exec("ALTER TABLE mermas_cabecera ADD COLUMN id_almacen INT"); } catch(Exception $e){}
} catch (Exception $e) {}

// --- 1. CONFIGURACIÓN ---
require_once 'config_loader.php';
$ALM_ID = intval($config['id_almacen']);
$SUC_ID = intval($config['id_sucursal']);
$EMP_ID = intval($config['id_empresa']);

// --- CARGAR ALMACENES DE LA SUCURSAL ---
$almacenes = [];
try {
    $stmtA = $pdo->prepare("SELECT id, nombre FROM almacenes WHERE id_sucursal = ? AND activo = 1 ORDER BY nombre ASC");
    $stmtA->execute([$SUC_ID]);
    $almacenes = $stmtA->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// --- API: BUSCADOR AJAX ---
if (isset($_GET['action']) && $_GET['action'] === 'search_products') {
    ob_clean(); header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    
    if (strlen($q) < 2) { echo json_encode([]); exit; }

    try {
        $sql = "SELECT p.codigo, p.nombre,
                COALESCE(ps.precio_costo, p.costo) AS costo
                FROM productos p
                LEFT JOIN productos_precios_sucursal ps
                    ON ps.codigo_producto = p.codigo AND ps.id_sucursal = ?
                WHERE (p.nombre LIKE ? OR p.codigo LIKE ?)
                AND p.id_empresa = ? AND p.activo = 1
                LIMIT 20";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$SUC_ID, "%$q%", "%$q%", $EMP_ID]);
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

            $stmtCheck = $pdo->prepare("SELECT estado, id_almacen FROM mermas_cabecera WHERE id = ?");
            $stmtCheck->execute([$idMerma]);
            $mermaData = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if ($mermaData['estado'] === 'CANCELADA') throw new Exception("Esta merma ya fue revertida.");
            $mermaAlmId = !empty($mermaData['id_almacen']) ? intval($mermaData['id_almacen']) : $ALM_ID;

            $stmtItems = $pdo->prepare("SELECT * FROM mermas_detalle WHERE id_merma = ?");
            $stmtItems->execute([$idMerma]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            foreach ($items as $item) {
                // Devolver al stock
                KardexEngine::registrarMovimiento(
                    $pdo,
                    $item['id_producto'], $mermaAlmId, $item['cantidad'], 'ENTRADA', 
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
        $mermaAlmId = isset($input['id_almacen']) ? intval($input['id_almacen']) : $ALM_ID;

        // MODIFICADO: Guardar id_sucursal e id_almacen
        $stmtHead = $pdo->prepare("INSERT INTO mermas_cabecera (usuario, motivo_general, total_costo_perdida, estado, id_sucursal, id_almacen, fecha_registro) VALUES (?, ?, ?, 'PROCESADA', ?, ?, ?)");
        $stmtHead->execute([$_SESSION['user_id']??'Admin', $motivo_gral, $input['total'], $SUC_ID, $mermaAlmId, $fecha_salida]);
        $idMerma = $pdo->lastInsertId();

        foreach ($input['items'] as $item) {
            $sku = $item['sku'];
            $qty = floatval($item['cantidad']);
            
            $stmtCosto = $pdo->prepare(
                "SELECT COALESCE(ps.precio_costo, p.costo) AS costo
                 FROM productos p
                 LEFT JOIN productos_precios_sucursal ps
                     ON ps.codigo_producto = p.codigo AND ps.id_sucursal = ?
                 WHERE p.codigo = ? AND p.id_empresa = ?"
            );
            $stmtCosto->execute([$SUC_ID, $sku, $EMP_ID]);
            $costo = floatval($stmtCosto->fetchColumn() ?: 0);

            $pdo->prepare("INSERT INTO mermas_detalle (id_merma, id_producto, cantidad, costo_al_momento, motivo_especifico) VALUES (?, ?, ?, ?, ?)")
                ->execute([$idMerma, $sku, $qty, $costo, $item['motivo']]);

            // Salida de stock
            KardexEngine::registrarMovimiento(
                $pdo,
                $sku, $mermaAlmId, ($qty * -1), 'SALIDA', 
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
    $stmtH = $pdo->query("SELECT m.*, a.nombre as nombre_almacen FROM mermas_cabecera m LEFT JOIN almacenes a ON m.id_almacen = a.id ORDER BY m.id DESC LIMIT 15");
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
    <link rel="stylesheet" href="assets/css/inventory-suite.css">
    <script src="assets/js/vue.min.js"></script>
    <style>
        .shell { max-width: 1480px; }
        .cursor-pointer{cursor:pointer;}
        .list-group-item:hover{background-color:#f8f9fa;}
        .rotate-icon { transition: transform 0.3s; }
        .rotated { transform: rotate(180deg); }
        .row-cancelled { background-color: rgba(180,35,24,.08) !important; color: #b02a37; }
    </style>
</head>
<body class="pb-5 inventory-suite">
    <div id="app" class="container-fluid shell inventory-shell py-4 py-lg-5">
        <section class="glass-card inventory-hero inventory-hero-danger p-4 p-lg-5 mb-4">
            <div class="d-flex flex-column flex-lg-row justify-content-between gap-4 align-items-start">
                <div>
                    <div class="section-title text-white-50 mb-2">Inventario / Control</div>
                    <h1 class="h2 fw-bold mb-2"><i class="fas fa-trash-alt me-2"></i>Gestión de Mermas</h1>
                    <p class="mb-3 text-white-50">Registro y reversión de pérdidas de inventario con trazabilidad y contexto por almacén.</p>
                    <div class="d-flex flex-wrap gap-2">
                        <span class="kpi-chip"><i class="fas fa-warehouse me-1"></i>Almacén <?= (int)$ALM_ID ?></span>
                        <span class="kpi-chip"><i class="fas fa-building me-1"></i>Sucursal <?= (int)$SUC_ID ?></span>
                        <span class="kpi-chip"><i class="fas fa-clock-rotate-left me-1"></i>{{recentList.length}} registros recientes</span>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a href="pos_purchases.php" class="btn btn-light"><i class="fas fa-dolly-flatbed me-1"></i>Compras</a>
                    <a href="dashboard.php" class="btn btn-outline-light"><i class="fas fa-arrow-left me-1"></i>Volver</a>
                </div>
            </div>
        </section>

        <div class="glass-card mb-5">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
                    <div>
                        <div class="section-title">Registro</div>
                        <div class="fw-bold fs-5">Nueva merma</div>
                    </div>
                    <span class="soft-pill"><i class="fas fa-layer-group"></i>{{cart.length}} líneas</span>
                </div>
                
                <!-- FECHA, ALMACÉN Y MOTIVO -->
                <div class="row g-3 mb-3 pb-3 border-bottom">
                    <div class="col-md-3">
                        <label class="fw-bold text-muted small"><i class="fas fa-calendar-alt me-1"></i> FECHA DE SALIDA</label>
                        <input type="date" class="form-control" v-model="fecha">
                    </div>
                    <div class="col-md-3">
                        <label class="fw-bold text-muted small"><i class="fas fa-warehouse me-1"></i> ALMACÉN</label>
                        <select class="form-select" v-model="selectedAlmacen">
                            <option v-for="a in almacenes" :value="a.id">{{a.nombre}}</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="fw-bold text-muted small"><i class="fas fa-tag me-1"></i> MOTIVO DE LA MERMA (APLICA A TODA LA CARGA)</label>
                        <select class="form-select fw-bold border-danger" v-model="reason">
                            <option>Vencimiento</option><option>Daño / Rotura</option><option>Robo / Pérdida</option><option>Consumo Interno</option><option>Error de Entrada</option><option>Calidad / Mal Estado</option>
                        </select>
                    </div>
                </div>

                <div class="row g-3 mb-4 align-items-end">
                    <div class="col-md-9 position-relative">
                        <label class="fw-bold text-muted small">PRODUCTO</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white text-danger"><i class="fas fa-search"></i></span>
                            <input type="text" class="form-control form-control-lg" v-model="search" @input="filterProds" placeholder="Buscar por Nombre o SKU..." autocomplete="off">
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

                    <div class="col-md-2">
                        <label class="fw-bold text-muted small">CANTIDAD</label>
                        <input type="number" class="form-control form-control-lg text-center fw-bold" v-model.number="qty" min="0.1" step="0.01">
                    </div>
                    
                    <div class="col-md-1">
                        <button class="btn btn-danger btn-lg w-100" @click="add" :disabled="!selectedSku || qty<=0">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Código</th>
                                <th>Producto</th>
                                <th class="text-center">Cant</th>
                                <th class="text-end">Costo U.</th>
                                <th class="text-end">Costo Total</th>
                                <th class="text-center">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="cart.length === 0"><td colspan="6" class="text-center text-muted py-5">
                                <i class="fas fa-shopping-basket fa-3x mb-3 opacity-25 d-block"></i>
                                La lista está vacía. Busca un producto arriba para comenzar.
                            </td></tr>
                            <tr v-for="(item, i) in cart">
                                <td class="small text-muted">{{item.sku}}</td>
                                <td class="fw-bold">{{item.nombre}}</td>
                                <td class="text-center fw-bold">{{item.cantidad}}</td>
                                <td class="text-end text-muted">${{item.costo.toFixed(2)}}</td>
                                <td class="text-end fw-bold text-danger">${{(item.cantidad*item.costo).toFixed(2)}}</td>
                                <td class="text-center"><button class="btn btn-sm btn-link text-danger" @click="cart.splice(i,1)"><i class="fas fa-trash-alt"></i></button></td>
                            </tr>
                        </tbody>
                        <tfoot v-if="cart.length > 0">
                            <tr class="table-active">
                                <td colspan="4" class="text-end fw-bold fs-5">TOTAL PÉRDIDA ESTIMADA:</td>
                                <td class="text-end fw-bold text-danger fs-4">${{totalLoss.toFixed(2)}}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="d-grid mt-4">
                    <button class="btn btn-danger btn-lg py-3 fw-bold shadow-sm" @click="submit" :disabled="cart.length==0">
                        <i class="fas fa-check-circle me-2"></i> CONFIRMAR BAJA DE INVENTARIO
                    </button>
                </div>
            </div>
        </div>

        <div class="glass-card">
            <div class="card-header bg-transparent border-0 py-4 px-4"><h5 class="mb-0 fw-bold"><i class="fas fa-history text-secondary me-2"></i> Últimas 15 Mermas</h5></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0 align-middle">
                        <thead class="table-light"><tr><th style="width:50px"></th><th>ID</th><th>Fecha</th><th>Usuario</th><th>Almacén</th><th>Motivo Gral</th><th class="text-end">Total Pérdida</th><th class="text-center">Estado</th><th class="text-center">Impr.</th></tr></thead>
                        <tbody>
                            <tr v-if="recentList.length===0"><td colspan="9" class="text-center py-4 text-muted">No hay historial reciente.</td></tr>
                            <template v-for="m in recentList">
                                <tr :class="{'row-cancelled': m.estado === 'CANCELADA'}">
                                    <td class="text-center cursor-pointer text-muted" @click="m.expanded = !m.expanded"><i class="fas fa-chevron-down rotate-icon" :class="{'rotated': m.expanded}"></i></td>
                                    <td class="fw-bold">#{{m.id}}</td>
                                    <td>{{formatDate(m.fecha_registro)}}</td>
                                    <td>{{m.usuario}}</td>
                                    <td>{{m.nombre_almacen || 'Principal'}}</td>
                                    <td>{{m.motivo_general}}</td>
                                    <td class="text-end fw-bold text-danger">${{parseFloat(m.total_costo_perdida).toFixed(2)}}</td>
                                    <td class="text-center">
                                        <span v-if="m.estado === 'CANCELADA'" class="badge bg-danger">REVERTIDA</span>
                                        <button v-else @click="cancelMerma(m.id)" class="btn btn-outline-secondary btn-sm" title="Revertir y devolver stock"><i class="fas fa-undo"></i></button>
                                    </td>
                                    <td class="text-center">
                                        <button @click="printMerma(m)" class="btn btn-outline-primary btn-sm" title="Imprimir acta oficial"><i class="fas fa-print"></i></button>
                                    </td>
                                </tr>
                                <tr v-if="m.expanded">
                                    <td colspan="9" class="p-0">
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
    const EMPRESA_DATA = <?php echo json_encode([
        'nombre'   => $config['marca_empresa_nombre'] ?? $config['tienda_nombre'] ?? 'Empresa',
        'direccion'=> $config['direccion'] ?? '',
        'telefono' => $config['telefono'] ?? '',
        'email'    => $config['email'] ?? '',
        'nit'      => $config['nit'] ?? '',
        'suc_id'   => $SUC_ID,
        'alm_id'   => $ALM_ID,
    ]); ?>;

    new Vue({
        el: '#app',
        data: {
            search: '', filteredProds: [], isLoadingSearch: false, debounceTimer: null,
            selectedSku: '', currentProd: null, qty: 1, 
            reason: 'Vencimiento', fecha: new Date().toISOString().split('T')[0],
            cart: [],
            almacenes: <?php echo json_encode($almacenes); ?>,
            selectedAlmacen: <?php echo (int)$ALM_ID; ?>,
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
                this.cart.push({ 
                    sku: this.selectedSku, 
                    nombre: this.currentProd.nombre, 
                    costo: parseFloat(this.currentProd.costo), 
                    cantidad: this.qty, 
                    motivo: this.reason 
                });
                this.selectedSku = ''; this.currentProd = null; this.search = ''; this.qty = 1;
            },
            async submit() {
                if(!confirm('⚠️ ¿Confirmar salida de inventario?')) return;
                try {
                    const res = await fetch('pos_shrinkage.php', { 
                        method: 'POST', 
                        body: JSON.stringify({ 
                            motivo: this.reason, 
                            fecha: this.fecha, 
                            total: this.totalLoss, 
                            items: this.cart, 
                            id_almacen: this.selectedAlmacen 
                        }) 
                    });
                    const d = await res.json();
                    if(d.status==='success') { 
                        alert('✅ Merma procesada con éxito'); 
                        window.location.reload(); 
                    } else { 
                        alert('❌ Error: ' + d.msg); 
                    }
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
            formatDate(d) { return d ? new Date(d).toLocaleString('es-ES') : '-'; },
            printMerma(m) {
                const fmt = d => d ? new Date(d).toLocaleString('es-ES') : '-';
                const totalPerdida = parseFloat(m.total_costo_perdida).toFixed(2);
                const almacen = m.nombre_almacen || 'Principal';
                const estado = m.estado === 'CANCELADA' ? 'REVERTIDA' : 'PROCESADA';

                let itemsRows = '';
                let sumTotal = 0;
                (m.details || []).forEach((d, i) => {
                    const subtotal = parseFloat(d.cantidad) * parseFloat(d.costo_al_momento);
                    sumTotal += subtotal;
                    itemsRows += `<tr>
                        <td class="tc">${i+1}</td>
                        <td>${d.nombre || ''} <span class="sku">(${d.id_producto})</span></td>
                        <td class="tc">${d.motivo_especifico || '—'}</td>
                        <td class="tr">${parseFloat(d.cantidad).toFixed(2)}</td>
                        <td class="tr">$${parseFloat(d.costo_al_momento).toFixed(2)}</td>
                        <td class="tr fw">$${subtotal.toFixed(2)}</td>
                    </tr>`;
                });

                const html = `<!DOCTYPE html><html lang="es"><head>
<meta charset="UTF-8">
<title>Acta Merma #${m.id}</title>
<style>
  @page { size: A4; margin: 18mm 15mm 20mm 15mm; }
  *{ box-sizing:border-box; margin:0; padding:0; }
  body{ font-family:'Arial',sans-serif; font-size:11px; color:#111; }
  .page{ width:100%; }
  /* --- HEADER --- */
  .hdr{ display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2.5px solid #c0392b; padding-bottom:10px; margin-bottom:12px; }
  .hdr-left h1{ font-size:16px; font-weight:700; color:#c0392b; margin-bottom:2px; }
  .hdr-left p{ font-size:10px; color:#555; line-height:1.5; }
  .hdr-right{ text-align:right; }
  .hdr-right .doc-title{ font-size:19px; font-weight:900; color:#c0392b; text-transform:uppercase; letter-spacing:1px; }
  .hdr-right .doc-num{ font-size:13px; font-weight:700; margin-top:2px; }
  .hdr-right .doc-estado{ display:inline-block; margin-top:4px; padding:2px 8px; border-radius:3px; font-size:10px; font-weight:700; background:${m.estado==='CANCELADA'?'#c0392b':'#1a7a40'}; color:#fff; }
  /* --- META --- */
  .meta-grid{ display:grid; grid-template-columns:1fr 1fr 1fr; gap:6px 16px; margin-bottom:14px; }
  .meta-box{ border:1px solid #ddd; border-radius:4px; padding:5px 8px; }
  .meta-box .lbl{ font-size:9px; text-transform:uppercase; letter-spacing:.5px; color:#888; }
  .meta-box .val{ font-size:12px; font-weight:700; color:#111; margin-top:1px; }
  /* --- TABLE --- */
  table{ width:100%; border-collapse:collapse; margin-bottom:10px; }
  thead tr{ background:#c0392b; color:#fff; }
  thead th{ padding:6px 8px; font-size:10px; text-align:left; }
  tbody tr:nth-child(even){ background:#fdf2f2; }
  tbody td{ padding:5px 8px; border-bottom:1px solid #f0d8d8; font-size:11px; }
  .tc{ text-align:center; }
  .tr{ text-align:right; }
  .fw{ font-weight:700; }
  .sku{ color:#888; font-size:9px; }
  tfoot td{ padding:6px 8px; border-top:2px solid #c0392b; font-size:12px; }
  /* --- TOTALS --- */
  .total-box{ display:flex; justify-content:flex-end; margin-bottom:16px; }
  .total-inner{ border:2px solid #c0392b; border-radius:6px; padding:8px 20px; text-align:right; }
  .total-inner .lbl{ font-size:10px; text-transform:uppercase; color:#888; }
  .total-inner .val{ font-size:22px; font-weight:900; color:#c0392b; }
  /* --- SIGNATURES --- */
  .sigs{ display:grid; grid-template-columns:1fr 1fr 1fr; gap:20px; margin-top:28px; }
  .sig-box{ text-align:center; }
  .sig-line{ border-top:1.5px solid #333; margin-bottom:4px; margin-top:36px; }
  .sig-label{ font-size:10px; color:#555; }
  /* --- FOOTER --- */
  .footer{ margin-top:18px; border-top:1px solid #ddd; padding-top:8px; font-size:9px; color:#999; text-align:center; }
  .stamp-area{ border:1.5px dashed #c0392b; border-radius:6px; width:90px; height:70px; margin:20px auto 0; display:flex; align-items:center; justify-content:center; color:#c0392b; font-size:9px; text-align:center; opacity:.5; }
  @media print{ body{ -webkit-print-color-adjust:exact; print-color-adjust:exact; } }
</style></head><body>
<div class="page">
  <div class="hdr">
    <div class="hdr-left">
      <h1>${EMPRESA_DATA.nombre}</h1>
      <p>${EMPRESA_DATA.direccion}${EMPRESA_DATA.telefono ? ' &bull; Tel: '+EMPRESA_DATA.telefono : ''}${EMPRESA_DATA.email ? ' &bull; '+EMPRESA_DATA.email : ''}</p>
      ${EMPRESA_DATA.nit ? `<p><strong>NIT/RUC:</strong> ${EMPRESA_DATA.nit}</p>` : ''}
    </div>
    <div class="hdr-right">
      <div class="doc-title">Acta de Baja de Inventario</div>
      <div class="doc-num">N&deg; MERMA-${String(m.id).padStart(6,'0')}</div>
      <div class="doc-estado">${estado}</div>
    </div>
  </div>

  <div class="meta-grid">
    <div class="meta-box"><div class="lbl">Fecha y Hora</div><div class="val">${fmt(m.fecha_registro)}</div></div>
    <div class="meta-box"><div class="lbl">Almacén</div><div class="val">${almacen}</div></div>
    <div class="meta-box"><div class="lbl">Responsable</div><div class="val">${m.usuario || '—'}</div></div>
    <div class="meta-box"><div class="lbl">Sucursal</div><div class="val">N&deg; ${EMPRESA_DATA.suc_id}</div></div>
    <div class="meta-box"><div class="lbl">Motivo General</div><div class="val">${m.motivo_general}</div></div>
    <div class="meta-box"><div class="lbl">Generado el</div><div class="val">${new Date().toLocaleString('es-ES')}</div></div>
  </div>

  <table>
    <thead>
      <tr>
        <th class="tc" style="width:30px">#</th>
        <th>Producto / Código</th>
        <th style="width:120px">Motivo Específico</th>
        <th class="tr" style="width:60px">Cant.</th>
        <th class="tr" style="width:80px">Costo U.</th>
        <th class="tr" style="width:90px">Subtotal</th>
      </tr>
    </thead>
    <tbody>${itemsRows}</tbody>
  </table>

  <div class="total-box">
    <div class="total-inner">
      <div class="lbl">Total Pérdida de Inventario</div>
      <div class="val">$${totalPerdida}</div>
    </div>
  </div>

  <div class="sigs">
    <div class="sig-box">
      <div class="sig-line"></div>
      <div class="sig-label"><strong>Responsable de Almacén</strong><br>Firma y sello</div>
    </div>
    <div class="sig-box">
      <div class="sig-line"></div>
      <div class="sig-label"><strong>Responsable Administrativo</strong><br>Firma y sello</div>
    </div>
    <div class="sig-box">
      <div class="stamp-area">SELLO<br>OFICIAL</div>
    </div>
  </div>

  <div class="footer">
    Documento generado por ${EMPRESA_DATA.nombre} &mdash; Sistema PalWeb POS &mdash; ${new Date().toLocaleString('es-ES')} &mdash; Este documento es un comprobante oficial de baja de inventario.
  </div>
</div>
<script>window.onload=()=>{window.print();}<\/script>
</body></html>`;

                const w = window.open('', '_blank', 'width=900,height=700');
                if (w) { w.document.write(html); w.document.close(); }
            }
        }
    });
</script>

<?php include_once 'menu_master.php'; ?>
</body>
</html>
