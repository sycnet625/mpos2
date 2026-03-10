<?php
// ARCHIVO: /var/www/palweb/api/pos2.php
// VERSIÓN: 4.4 - LAYOUT CAJERO + MODULARIZACIÓN
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

require_once 'db.php';

// ENDPOINTS INTERNOS (AJAX)
if (isset($_GET['ping'])) {
    header('Content-Type: application/json');
    echo json_encode(['pong' => true, 't' => microtime(true)]);
    exit;
}

if (isset($_GET['load_history'])) {
    header('Content-Type: application/json');
    try {
        $idSesion = $_GET['session_id'] ?? 0;
        // Obtener cabeceras
        $sql = "SELECT id, fecha, total, metodo_pago, cliente_nombre, tipo_servicio, mensajero_nombre as mensajero 
                FROM ventas_cabecera 
                WHERE id_caja = ? ORDER BY id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$idSesion]);
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Obtener detalles para el Master/Detail
        $sqlDet = "SELECT d.id, d.id_venta_cabecera, d.nombre_producto as nombre, d.cantidad, d.precio, d.id_producto 
                   FROM ventas_detalle d 
                   JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id 
                   WHERE v.id_caja = ?";
        $stmtDet = $pdo->prepare($sqlDet);
        $stmtDet->execute([$idSesion]);
        $detalles = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

        // Calcular totales generales para KPIs
        $totalVenta = 0; $totalDev = 0; $countDev = 0;
        foreach($tickets as $t) {
            if($t['total'] < 0) { $totalDev += abs($t['total']); $countDev++; }
            else { $totalVenta += $t['total']; }
        }

        echo json_encode([
            'status' => 'success', 
            'tickets' => $tickets, 
            'detalles' => $detalles,
            'totales' => ['total' => $totalVenta, 'devoluciones' => $countDev, 'valor_devoluciones' => $totalDev, 'count' => count($tickets)]
        ]);
    } catch (Exception $e) { echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]); }
    exit;
}

// CARGA DE PRODUCTOS Y DATOS
$configFile = 'pos.cfg';
$config = ["id_almacen" => 1, "id_sucursal" => 1, "tienda_nombre" => "POS"];
if (file_exists($configFile)) { $loaded = json_decode(file_get_contents($configFile), true); if ($loaded) $config = array_merge($config, $loaded); }

$clientsData = []; $mensajeros = []; $prods = []; $cats = [];
try {
    $stmtCli = $pdo->query("SELECT id, nombre, telefono, direccion, nit_ci FROM clientes WHERE activo = 1 ORDER BY nombre ASC");
    $clientsData = $stmtCli->fetchAll(PDO::FETCH_ASSOC);
    try { $stmtMsj = $pdo->query("SELECT nombre FROM clientes WHERE activo = 1 AND es_mensajero = 1 ORDER BY nombre ASC"); $mensajeros = $stmtMsj->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) { $mensajeros = []; }
    
    $almacenID = intval($config['id_almacen']);
    $sqlProd = "SELECT p.codigo, p.nombre, p.precio, p.categoria, p.es_elaborado, p.es_servicio, 
                (SELECT COALESCE(SUM(s.cantidad), 0) FROM stock_almacen s WHERE s.id_producto = p.codigo AND s.id_almacen = $almacenID) as stock 
                FROM productos p WHERE p.activo = 1 ORDER BY p.nombre";
    $prods = $pdo->query($sqlProd)->fetchAll(PDO::FETCH_ASSOC);
    $cats = array_unique(array_column($prods, 'categoria')); sort($cats);
    $localPath = '/home/marinero/product_images/';
    foreach ($prods as &$p) { 
        $p['has_image'] = file_exists($localPath . $p['codigo'] . '.jpg'); 
        $p['color'] = '#' . substr(dechex(crc32($p['nombre'])), 0, 6); 
        $p['stock'] = floatval($p['stock']); 
    } unset($p);
} catch (Exception $e) {}

// DATOS SESIÓN
$id_sucursal = $_SESSION['sucursal_id'] ?? 1;
$id_usuario = $_SESSION['user_id'];
$stmtSesion = $pdo->prepare("SELECT id, nombre_cajero FROM caja_sesiones WHERE id_sucursal = ? AND id_usuario = ? AND estado = 'ABIERTA' ORDER BY id DESC LIMIT 1");
$stmtSesion->execute([$id_sucursal, $id_usuario]);
$sesionActiva = $stmtSesion->fetch(PDO::FETCH_ASSOC);
$sessionId = $sesionActiva ? $sesionActiva['id'] : 0;
$cajeroNombre = $sesionActiva ? $sesionActiva['nombre_cajero'] : ($_SESSION['admin_user'] ?? 'Cajero');

$initialJs = "<script>const CURRENT_SESSION_ID = " . $sessionId . "; const CURRENT_USER_ID = " . $id_usuario . "; const CURRENT_CASHIER_NAME = '" . htmlspecialchars($cajeroNombre) . "';</script>";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>POS | <?php echo htmlspecialchars($config['tienda_nombre']); ?></title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <style>
        /* ESTILOS GENERALES POS */
        html, body { height: 100%; margin: 0; padding: 0; overflow: hidden; background-color: #e9ecef; font-family: 'Segoe UI', sans-serif; touch-action: manipulation; user-select: none; }
        .pos-container { display: flex; height: 100vh; width: 100vw; overflow: hidden; }
        .left-panel { background: white; border-right: 1px solid #ddd; display: flex; flex-direction: column; z-index: 20; box-shadow: 2px 0 5px rgba(0,0,0,0.05); height: 100%; overflow-y: auto; }
        .right-panel { flex-grow: 1; display: flex; flex-direction: column; background: #e9ecef; padding: 10px; overflow: hidden; }
        .cart-list { flex-grow: 1; overflow-y: auto; background: #fff; border-bottom: 1px solid #eee; min-height: 150px; }
        .controls-wrapper { padding: 8px; padding-bottom: 60px; background: #fff; flex-shrink: 0; border-top: 1px solid #eee; }
        .product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(135px, 1fr)); gap: 10px; overflow-y: auto; padding-bottom: 80px; }
        .product-card { background: white; border-radius: 10px; overflow: hidden; cursor: pointer; box-shadow: 0 2px 5px rgba(0,0,0,0.08); display: flex; flex-direction: column; position: relative !important; min-height: 180px; transition: transform 0.1s; }
        .product-card:active { transform: scale(0.98); }
        .product-card.disabled-card { opacity: 0.85; filter: grayscale(0.5); }
        .stock-badge { position: absolute !important; top: 5px; right: 5px; width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.75rem; z-index: 10; box-shadow: 0 2px 4px rgba(0,0,0,0.3); }
        .stock-ok { background: #ffc107; color: black; } 
        .stock-zero { background: #dc3545; color: white; } 
        .product-img-container { width: 100%; aspect-ratio: 4/3; background: #eee; display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .product-img { width: 100%; height: 100%; object-fit: cover; }
        .placeholder-text { font-size: 1.5rem; color: white; font-weight: bold; text-shadow: 0 1px 2px rgba(0,0,0,0.3); }
        .product-info { padding: 8px; flex-grow: 1; display: flex; flex-direction: column; justify-content: space-between; }
        .product-name { font-weight: 600; font-size: 0.9rem; line-height: 1.2; margin-bottom: 5px; color: #333; }
        .product-price { color: #0d6efd; font-weight: 800; font-size: 1rem; text-align: right; }
        .category-bar { display: flex; overflow-x: auto; gap: 8px; margin-bottom: 8px; padding-bottom: 2px; scrollbar-width: none; }
        .category-btn { padding: 8px 16px; border: none; border-radius: 20px; font-weight: 600; background: white; color: #555; white-space: nowrap; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .category-btn.active { background: #0d6efd; color: white; }
        .cart-item { padding: 8px 12px; border-bottom: 1px solid #f0f0f0; cursor: pointer; position: relative; font-size: 0.95rem; }
        .cart-item.selected { background: #e8f0fe; border-left: 4px solid #0d6efd; }
        .btn-ctrl { height: 45px; border: 1px solid #ced4da; border-radius: 8px; font-weight: bold; font-size: 1rem; display: flex; align-items: center; justify-content: center; background: white; }
        .btn-pay { height: 55px; font-size: 1.4rem; width: 100%; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .keypad-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; margin-bottom: 8px; }
        .action-row { display: flex; gap: 6px; margin-bottom: 6px; }
        .bg-dark-blue { background-color: #2c3e50 !important; }
        .cash-status { font-size: 0.7rem; padding: 2px 8px; border-radius: 4px; display: inline-block; vertical-align: middle; }
        .cash-open { background: #d1e7dd; color: #0f5132; } .cash-closed { background: #f8d7da; color: #842029; }
        #pinOverlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); z-index: 9999; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(5px); }
        .pin-box { background: white; padding: 30px; border-radius: 15px; text-align: center; width: 320px; }
        .pin-btn { height: 60px; font-size: 1.5rem; border-radius: 10px; border: 1px solid #ccc; background: #f8f9fa; }
        .pin-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-top: 15px; }
        .btn-filter-active { background-color: #198754 !important; color: white !important; border-color: #198754 !important; }
        body.mode-no-images .product-img { display: none !important; }
        body.mode-no-images .placeholder-text { display: flex !important; font-size: 2rem; }
        body.mode-no-images .product-img-container { background: linear-gradient(135deg, var(--product-color, #6c757d), var(--product-color-dark, #495057)); }
        #progressOverlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); z-index: 99999; align-items: center; justify-content: center; backdrop-filter: blur(5px); }
        #progressOverlay.active { display: flex; }
        .progress-card { background: white; padding: 30px; border-radius: 15px; width: 90%; max-width: 450px; box-shadow: 0 10px 40px rgba(0,0,0,0.5); }
        .progress-title { margin: 0 0 20px 0; color: #333; font-size: 1.2rem; font-weight: 600; }
        .progress-bar-container { background: #e0e0e0; height: 28px; border-radius: 14px; overflow: hidden; margin-bottom: 15px; position: relative; }
        .progress-bar-fill { background: linear-gradient(90deg, #198754, #20c997); height: 100%; width: 0%; transition: width 0.3s ease; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 0.95rem; }
        @media (orientation: landscape) { .pos-container { flex-direction: row; } .left-panel { width: 35%; min-width: 340px; max-width: 420px; } .right-panel { width: 65%; } #keypadContainer { display: grid !important; } #toggleKeypadBtn { display: none !important; } }
        @media (orientation: portrait) { .pos-container { flex-direction: column; } .left-panel { width: 100%; height: 45%; border-right: none; border-bottom: 2px solid #ccc; order: 1; } .right-panel { width: 100%; height: 55%; order: 2; padding: 5px; } #keypadContainer { display: none; } #toggleKeypadBtn { display: block; width: 100%; margin-bottom: 5px; } .controls-wrapper { padding-bottom: 10px; } }
        /* COLORES BOTONES */
        .c-yellow { background-color: #ffc107; color: black; border-color: #ffc107; }
        .c-green { background-color: #198754 !important; color: white !important; border-color: #198754 !important; }
        .c-red { background-color: #dc3545 !important; color: white !important; border-color: #dc3545 !important; }
        .c-grey { background-color: #6c757d; color: white; border-color: #6c757d; }
        .c-blue { background-color: #0d6efd; color: white; border-color: #0d6efd; }
        .c-purple { background-color: #6f42c1; color: white; border-color: #6f42c1; }
        .c-orange { background-color: #fd7e14; color: white; border-color: #fd7e14; }
    </style>
    <?php echo $initialJs; ?>
</head>
<body>

<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastContainer"></div>

<div id="pinOverlay">
    <div class="pin-box">
        <h3 class="mb-2">Acceso</h3>
        <div class="fs-1 mb-3" id="pinDisplay">••••</div>
        <div class="pin-grid">
            <button class="pin-btn" onclick="typePin(1)">1</button><button class="pin-btn" onclick="typePin(2)">2</button><button class="pin-btn" onclick="typePin(3)">3</button>
            <button class="pin-btn" onclick="typePin(4)">4</button><button class="pin-btn" onclick="typePin(5)">5</button><button class="pin-btn" onclick="typePin(6)">6</button>
            <button class="pin-btn" onclick="typePin(7)">7</button><button class="pin-btn" onclick="typePin(8)">8</button><button class="pin-btn" onclick="typePin(9)">9</button>
            <button class="pin-btn c-red" onclick="typePin('C')">C</button><button class="pin-btn" onclick="typePin(0)">0</button><button class="pin-btn c-green" onclick="verifyPin()">OK</button>
        </div>
    </div>
</div>

<div class="pos-container">
    <div class="left-panel">
        <div class="p-2 bg-dark-blue text-white top-bar shadow-sm" style="height: 75px; display: flex; flex-direction: column; justify-content: space-between;">
            <div class="d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <i class="fas fa-user-circle me-2 fs-5"></i>
                    <span id="cashierName" class="fw-bold me-2" style="font-size: 1rem;"><?php echo htmlspecialchars($cajeroNombre); ?></span>
                    <span id="cashStatusBadge" class="cash-status cash-closed">CERRADA</span>
                </div>
                
                <div class="d-flex align-items-center gap-1 flex-nowrap"> 
                    <button id="btnSync" onclick="syncOfflineQueue()" class="btn btn-sm btn-warning text-dark px-2 d-none" title="Sync"><i class="fas fa-sync"></i></button>
                    <span id="netStatus" class="badge bg-secondary" style="font-size: 0.7rem;"><i class="fas fa-wifi"></i> ...</span>
                    <button onclick="checkCashRegister()" class="btn btn-sm btn-light text-primary px-2" title="Caja"><i class="fas fa-cash-register"></i></button>
                    <button onclick="showParkedOrders()" class="btn btn-sm btn-light text-warning px-2" title="Pausados"><i class="fas fa-pause"></i></button>
                    <a href="reportes_caja.php" class="btn btn-sm btn-light text-primary px-2" title="Reportes"><i class="fas fa-chart-line"></i></a>
                </div>
            </div>
            
            <div class="d-flex align-items-center" style="gap: 8px;">
                <span class="badge bg-info" style="font-size: 0.75rem; padding: 4px 8px;">
                    <i class="fas fa-building"></i> Suc: <?php echo intval($config['id_sucursal'] ?? 1); ?>
                </span>
                <span class="badge bg-info" style="font-size: 0.75rem; padding: 4px 8px;">
                    <i class="fas fa-warehouse"></i> Alm: <?php echo intval($config['id_almacen']); ?>
                </span>
                <button onclick="refreshProducts()" class="btn btn-sm btn-light px-2" title="Recargar"><i class="fas fa-sync-alt"></i></button>
                <button onclick="toggleImages()" class="btn btn-sm btn-light px-2" title="Imágenes"><i class="fas fa-image"></i></button>
                <button id="stockFilterBtn" onclick="toggleStockFilter()" class="btn btn-sm btn-light px-2" title="Filtrar"><i class="fas fa-boxes"></i></button>
            </div>
        </div>

        <div class="cart-list" id="cartContainer">
            <div class="text-center text-muted h-100 d-flex flex-column align-items-center justify-content-center">
                <i class="fas fa-shopping-basket fa-3x mb-2 opacity-25"></i><p class="small">Carrito Vacío</p>
            </div>
        </div>

        <div class="px-3 py-2 bg-light border-top totals-area">
            <div class="d-flex justify-content-between align-items-end">
                <div class="small text-muted">Items: <span id="totalItems">0</span></div>
                <div class="fs-3 fw-bold text-dark text-end" id="totalAmount">$0.00</div>
            </div>
        </div>

        <div class="controls-wrapper">
            <button id="toggleKeypadBtn" class="btn btn-sm btn-outline-secondary mb-2" style="width:100%; display:none;" onclick="toggleMobileKeypad()"><i class="fas fa-keyboard"></i> Teclado</button>
            <div id="keypadContainer">
                <div class="action-row">
                    <button class="btn-ctrl c-yellow flex-grow-1" onclick="modifyQty(-1)"><i class="fas fa-minus"></i></button>
                    <button class="btn-ctrl c-green flex-grow-1" onclick="modifyQty(1)"><i class="fas fa-plus"></i></button>
                    <button class="btn-ctrl c-red flex-grow-1" onclick="removeItem()"><i class="fas fa-trash"></i></button>
                </div>
                <div class="keypad-grid">
                    <button class="btn-ctrl c-grey" onclick="askQty()">Cnt</button>
                    <button class="btn-ctrl c-purple" onclick="applyDiscount()">% Item</button>
                    <button class="btn-ctrl c-purple" onclick="applyGlobalDiscount()">% Total</button>
                    <button class="btn-ctrl c-blue" onclick="addNote()"><i class="fas fa-pen"></i></button>
                    <button class="btn-ctrl c-orange" onclick="parkOrder()"><i class="fas fa-pause"></i></button>
                    <button class="btn-ctrl c-red" onclick="clearCart()">Vaciar</button>
                    <button class="btn-ctrl" style="background:#17a2b8; color:white; border-color:#17a2b8;" onclick="showHistorialModal()"><i class="fas fa-history"></i> HIST</button>
                    <button id="btnSyncKeypad" class="btn-ctrl" style="background:#fd7e14; color:white; border-color:#fd7e14; opacity:0.4;" onclick="syncManual()" disabled><i class="fas fa-cloud-upload-alt"></i> 0</button>
                </div>
            </div>
            <button class="btn btn-primary btn-pay fw-bold shadow-sm" onclick="openPaymentModal()"><i class="fas fa-check-circle me-2"></i> COBRAR</button>
        </div>
    </div>

    <div class="right-panel">
        <div class="input-group mb-2 shadow-sm" style="flex-shrink:0;">
            <button id="btnRefresh" onclick="refreshProducts()" class="btn btn-primary border-0"><i class="fas fa-sync-alt"></i></button>
            <button id="btnForceDownload" onclick="forceDownloadProducts()" class="btn btn-danger border-0" title="Forzar"><i class="fas fa-cloud-download-alt"></i></button>
            <button id="btnStockFilter" class="btn btn-light border-0" onclick="toggleStockFilter()" title="Stock"><i class="fas fa-cubes"></i></button>
            <button id="btnToggleImages" class="btn btn-light border-0" onclick="toggleImages()" title="Imágenes"><i class="fas fa-image"></i></button>
            <span class="input-group-text bg-white border-0"><i class="fas fa-search"></i></span>
            <input type="text" id="searchInput" class="form-control border-0" placeholder="Buscar / Escanear..." onkeyup="filterProducts()">
            <button class="btn btn-light border-0" onclick="document.getElementById('searchInput').value='';filterProducts()">X</button>
        </div>
        <div class="category-bar" id="categoryBar">
            <button class="category-btn active" onclick="filterCategory('all', this)">TODOS</button>
            <?php foreach($cats as $cat): ?>
                <button class="category-btn" onclick="filterCategory('<?php echo htmlspecialchars($cat); ?>', this)"><?php echo htmlspecialchars($cat); ?></button>
            <?php endforeach; ?>
        </div>
        <div class="product-grid" id="productContainer"></div>
    </div>
</div>

<?php include 'modal_payment.php'; ?>
<?php include 'modal_close_register.php'; ?>
<?php include 'modal_history.php'; ?>

<div class="modal fade" id="cashModal" tabindex="-1"><div class="modal-dialog modal-sm"><div class="modal-content"><div class="modal-header bg-dark text-white"><h5 class="modal-title">Apertura Caja</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body" id="cashModalBody"></div></div></div></div>
<div class="modal fade" id="newClientModal" tabindex="-1"><div class="modal-dialog modal-sm"><div class="modal-content"><div class="modal-header bg-success text-white py-2"><h6 class="modal-title">Nuevo Cliente</h6><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body p-3"><input type="text" id="ncNombre" class="form-control form-control-sm mb-2" placeholder="Nombre *" required><input type="text" id="ncTel" class="form-control form-control-sm mb-2" placeholder="Teléfono"><input type="text" id="ncDir" class="form-control form-control-sm mb-2" placeholder="Dirección"><input type="text" id="ncNit" class="form-control form-control-sm mb-2" placeholder="NIT/CI"><button class="btn btn-success w-100 btn-sm fw-bold" onclick="saveNewClient()">Guardar</button></div></div></div></div>
<div class="modal fade" id="parkModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header bg-warning"><h5 class="modal-title">Ordenes Pausadas</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body p-0"><div class="list-group list-group-flush" id="parkList"></div></div></div></div></div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    const PRODUCTS_DATA = <?php echo json_encode($prods); ?>;
    const CAJEROS_CONFIG = <?php echo json_encode($config['cajeros'] ?? []); ?>;
    let CLIENTS_DATA = <?php echo json_encode($clientsData); ?>;

    window.fillClientData = function(select) {
        const opt = select.options[select.selectedIndex];
        if(!opt.value) { document.getElementById('cliPhone').value=''; document.getElementById('cliAddr').value=''; return; }
        document.getElementById('cliPhone').value = opt.getAttribute('data-tel')||''; 
        document.getElementById('cliAddr').value = opt.getAttribute('data-dir')||''; 
    };
    window.openNewClientModal = function() { new bootstrap.Modal(document.getElementById('newClientModal')).show(); };
    window.saveNewClient = function() {
        const d = { nombre: document.getElementById('ncNombre').value, telefono: document.getElementById('ncTel').value, direccion: document.getElementById('ncDir').value, nit_ci: document.getElementById('ncNit').value };
        if(!d.nombre) return alert("Nombre obligatorio");
        fetch('pos2.php?api_client=1',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(d)})
        .then(r=>r.json()).then(res=>{
            if(res.status==='success'){
                CLIENTS_DATA.push(res.client);
                const l = document.getElementById('cliName');
                const o = document.createElement('option');
                o.value=res.client.nombre; o.text=res.client.nombre; o.setAttribute('data-tel',res.client.telefono); o.setAttribute('data-dir',res.client.direccion);
                l.add(o); l.value=res.client.nombre; fillClientData(l);
                bootstrap.Modal.getInstance(document.getElementById('newClientModal')).hide();
                Swal.fire('Guardado','Cliente creado correctamente','success');
            } else alert("Error: "+res.message);
        });
    };
</script>
<script src="pos.js"></script>
<script src="pos-offline-system.js"></script>

<div id="progressOverlay"><div class="progress-card"><h4 class="progress-title">Cargando...</h4><div class="progress-bar-container"><div id="progressBar" class="progress-bar-fill">0%</div></div></div></div>

<?php include_once 'menu_master.php'; ?>
</body>
</html>

