<?php
// ARCHIVO: /var/www/palweb/api/pos.php
ini_set('display_errors', 1);
require_once 'db.php';

// --- CONFIGURACIÓN ---
$configFile = 'pos.cfg';
$config = [ 
    "tienda_nombre" => "MI TIENDA", 
    "cajeros" => [["nombre"=>"Admin", "pin"=>"0000"]], 
    "id_almacen" => 1, 
    "id_sucursal" => 1, 
    "id_empresa" => 1,
    "mostrar_materias_primas" => false, 
    "mostrar_servicios" => true, 
    "categorias_ocultas" => [] 
];

if (file_exists($configFile)) {
    $loaded = json_decode(file_get_contents($configFile), true);
    if ($loaded) $config = array_merge($config, $loaded);
}

$almacenID = intval($config['id_almacen']);
$sucursalID = intval($config['id_sucursal']);
$empresaID = isset($config['id_empresa']) ? intval($config['id_empresa']) : 1;

// --- CARGA DE PRODUCTOS ---
$prods = [];
try {
    $cond = [];
    $cond[] = "p.activo = 1";
    $cond[] = "p.es_pos = 1"; 
    $cond[] = "p.es_suc$sucursalID = 1"; 
    $cond[] = "p.id_empresa = $empresaID"; 

    if (!$config['mostrar_materias_primas']) $cond[] = "p.es_materia_prima = 0";
    if (!$config['mostrar_servicios']) $cond[] = "p.es_servicio = 0";
    
    if (!empty($config['categorias_ocultas'])) {
        $placeholders = implode(',', array_fill(0, count($config['categorias_ocultas']), '?'));
        $cond[] = "p.categoria NOT IN ($placeholders)";
    }
    
    $where = implode(" AND ", $cond);
    $params = $config['categorias_ocultas'];

    $stmtCat = $pdo->prepare("SELECT DISTINCT categoria FROM productos p WHERE $where ORDER BY categoria");
    $stmtCat->execute($params);
    $cats = $stmtCat->fetchAll(PDO::FETCH_COLUMN);

    $sqlProd = "SELECT p.codigo as id, p.codigo, p.nombre, p.precio, p.categoria, p.es_elaborado, p.es_servicio,
                (SELECT COALESCE(SUM(s.cantidad), 0) 
                 FROM stock_almacen s 
                 WHERE s.id_producto = p.codigo AND s.id_almacen = $almacenID) as stock
                FROM productos p 
                WHERE $where 
                ORDER BY p.nombre";
    
    $stmtProd = $pdo->prepare($sqlProd);
    $stmtProd->execute($params);
    $prods = $stmtProd->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) { 
    error_log("POS DB Error: " . $e->getMessage());
    $prods = [];
}

$localPath = '/home/marinero/product_images/';
foreach ($prods as &$p) {
    $p['has_image'] = file_exists($localPath . $p['codigo'] . '.jpg');
    $p['color'] = '#' . substr(dechex(crc32($p['nombre'])), 0, 6);
    $p['stock'] = floatval($p['stock']);
} unset($p);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>POS | <?php echo htmlspecialchars($config['tienda_nombre']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ESTILOS GENERALES */
        html, body { height: 100%; margin: 0; padding: 0; overflow: hidden; background-color: #e9ecef; font-family: 'Segoe UI', sans-serif; touch-action: manipulation; user-select: none; }
        .pos-container { display: flex; height: 100vh; width: 100vw; overflow: hidden; }
        .left-panel { background: white; border-right: 1px solid #ddd; display: flex; flex-direction: column; z-index: 20; box-shadow: 2px 0 5px rgba(0,0,0,0.05); height: 100%; overflow-y: auto; }
        .top-bar { flex-shrink: 0; background-color: #2c3e50 !important; color: white; }
        .cart-list { flex-grow: 1; overflow-y: auto; background: #fff; border-bottom: 1px solid #eee; min-height: 150px; }
        .controls-wrapper { padding: 8px; padding-bottom: 60px; background: #fff; flex-shrink: 0; border-top: 1px solid #eee; }
        .right-panel { flex-grow: 1; display: flex; flex-direction: column; background: #e9ecef; padding: 10px; overflow: hidden; }
        
        /* RESPONSIVE */
        @media (orientation: landscape) { .pos-container { flex-direction: row; } .left-panel { width: 35%; min-width: 340px; max-width: 420px; } .right-panel { width: 65%; } #keypadContainer { display: grid !important; } #toggleKeypadBtn { display: none !important; } }
        @media (orientation: portrait) { .pos-container { flex-direction: column; } .left-panel { width: 100%; height: 45%; border-right: none; border-bottom: 2px solid #ccc; order: 1; } .right-panel { width: 100%; height: 55%; order: 2; padding: 5px; } #keypadContainer { display: none; } #toggleKeypadBtn { display: block; width: 100%; margin-bottom: 5px; } .controls-wrapper { padding-bottom: 10px; } }
        
        /* CARRITO Y PRODUCTOS */
        .cart-item { padding: 8px 12px; border-bottom: 1px solid #f0f0f0; cursor: pointer; position: relative; font-size: 0.95rem; }
        .cart-item.selected { background: #e8f0fe; border-left: 4px solid #0d6efd; }
        .cart-note { font-size: 0.75rem; color: #d63384; font-style: italic; display: block; }
        .discount-tag { font-size: 0.7rem; background: #dc3545; color: white; padding: 1px 4px; border-radius: 3px; margin-left: 5px; }
        
        .btn-ctrl { height: 45px; border: 1px solid #ced4da; border-radius: 8px; font-weight: bold; font-size: 1rem; display: flex; align-items: center; justify-content: center; background: white; }
        .btn-pay { height: 55px; font-size: 1.4rem; width: 100%; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .keypad-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; margin-bottom: 8px; }
        .action-row { display: flex; gap: 6px; margin-bottom: 6px; }
        .c-yellow { background-color: #ffc107; color: black; border-color: #ffc107; }
        .c-green { background-color: #198754 !important; color: white !important; border-color: #198754 !important; }
        .c-red { background-color: #dc3545 !important; color: white !important; border-color: #dc3545 !important; }
        .c-grey { background-color: #6c757d; color: white; border-color: #6c757d; }
        .c-blue { background-color: #0d6efd; color: white; border-color: #0d6efd; }
        .c-purple { background-color: #6f42c1; color: white; border-color: #6f42c1; }
        .c-orange { background-color: #fd7e14; color: white; border-color: #fd7e14; }
        
        .category-bar { display: flex; overflow-x: auto; gap: 8px; margin-bottom: 8px; padding-bottom: 2px; scrollbar-width: none; }
        .category-btn { padding: 8px 16px; border: none; border-radius: 20px; font-weight: 600; background: white; color: #555; white-space: nowrap; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .category-btn.active { background: #0d6efd; color: white; }
        .product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(135px, 1fr)); gap: 10px; overflow-y: auto; padding-bottom: 80px; }
        .product-card { background: white; border-radius: 10px; overflow: hidden; cursor: pointer; box-shadow: 0 2px 5px rgba(0,0,0,0.08); display: flex; flex-direction: column; position: relative !important; min-height: 180px; }
        .product-card.disabled { opacity: 0.5; pointer-events: none; }
        .product-img-container { width: 100%; aspect-ratio: 4/3; background: #eee; display: flex; align-items: center; justify-content: center; overflow: hidden; position: relative; }
        .product-img { width: 100%; height: 100%; object-fit: cover; position: relative; z-index: 2; }
        .placeholder-text { font-size: 1.5rem; color: white; font-weight: bold; text-shadow: 0 1px 2px rgba(0,0,0,0.3); position: absolute; z-index: 1; }
        .product-info { padding: 8px; flex-grow: 1; display: flex; flex-direction: column; justify-content: space-between; }
        .product-name { font-weight: 600; font-size: 0.9rem; line-height: 1.2; margin-bottom: 5px; color: #333; }
        .product-price { color: #0d6efd; font-weight: 800; font-size: 1rem; text-align: right; }
        
        .stock-badge { position: absolute !important; top: 5px; right: 5px; border-radius: 50%; width: 26px; height: 26px; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.75rem; z-index: 10; box-shadow: 0 2px 4px rgba(0,0,0,0.3); }
        .stock-ok { background: #ffc107; color: black; } 
        .stock-zero { background: #dc3545; color: white; }
        
        /* PIN, TOAST, MODAL DE CIERRE */
        #pinOverlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); z-index: 9999; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(5px); }
        .pin-box { background: white; padding: 30px; border-radius: 15px; text-align: center; width: 320px; }
        .pin-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-top: 15px; }
        .pin-btn { height: 60px; font-size: 1.5rem; border-radius: 10px; border: 1px solid #ccc; background: #f8f9fa; }
        .toast-container { z-index: 10000; }
        .toast { background-color: rgba(255,255,255,0.95); box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15); }
        .cash-status { font-size: 0.7rem; margin-left: 5px; padding: 1px 6px; border-radius: 4px; display: inline-block; vertical-align: middle; }
        .cash-open { background: #d1e7dd; color: #0f5132; } .cash-closed { background: #f8d7da; color: #842029; }
        .bg-dark-blue { background-color: #2c3e50 !important; }
        .btn-filter-active { background-color: #198754 !important; color: white !important; border-color: #198754 !important; }

        /* ESTILOS NUEVOS PARA MODAL DE CIERRE */
        .modal-total-box {
            background-color: #e9ecef;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            margin-bottom: 20px;
        }
        .modal-total-amount {
            font-size: 2.5rem;
            font-weight: 800;
            color: #495057;
            line-height: 1;
        }
        .stat-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.9rem;
            border-bottom: 1px dashed #eee;
            padding: 6px 0;
        }
    </style>
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

<div class="pos-container" id="posContainer" style="display:none;">
    <div class="left-panel">
        <div class="p-2 top-bar shadow-sm" style="height: 75px; display: flex; flex-direction: column; justify-content: space-between;">
            <div class="d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <i class="fas fa-user me-1"></i>
                    <span id="cashierName" style="font-weight: 600; font-size: 0.95rem;">Cajero</span>
                    <span id="cashStatusBadge" class="cash-status cash-closed ms-2" style="font-size: 0.7rem;">CERRADA</span>
                </div>
                
                <div class="d-flex align-items-center gap-1">
                    <button id="btnSync" onclick="syncOfflineQueue()" class="btn btn-sm btn-warning text-dark px-2 d-none" title="Sync Offline"><i class="fas fa-sync"></i></button>
                    <span id="netStatus" class="badge bg-success" style="font-size: 0.7rem;"><i class="fas fa-wifi"></i></span>
                    <button onclick="checkCashRegister()" class="btn btn-sm btn-light text-primary px-2" title="Caja"><i class="fas fa-cash-register"></i></button>
                    <button onclick="showParkedOrders()" class="btn btn-sm btn-light text-warning px-2" title="Pausados"><i class="fas fa-pause"></i></button>
                    <a href="reportes_caja.php" class="btn btn-sm btn-light text-primary px-2" title="Reportes"><i class="fas fa-chart-line"></i></a>
                </div>
            </div>
            <div class="d-flex align-items-center" style="gap: 8px;">
                <span class="badge bg-info" style="font-size: 0.75rem;">Sucursal: <?php echo $sucursalID; ?></span>
                <span class="badge bg-info" style="font-size: 0.75rem;">Almacén: <?php echo $almacenID; ?></span>
            </div>
        </div>

        <div class="cart-list" id="cartContainer"></div>

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
                    <button class="btn-ctrl c-orange" id="btnSyncKeypad" style="background:#fd7e14; color:white; border-color:#fd7e14; opacity:0.5;" onclick="syncManual()"><i class="fas fa-cloud-upload-alt"></i></button>

                </div>
            </div>
            <button class="btn btn-primary btn-pay fw-bold shadow-sm" onclick="openPaymentModal()"><i class="fas fa-check-circle me-2"></i> COBRAR</button>
        </div>
    </div>

    <div class="right-panel">
        <div class="input-group mb-2 shadow-sm" style="flex-shrink:0;">
            <button id="btnRefresh" onclick="refreshProducts()" class="btn btn-primary border-0"><i class="fas fa-sync-alt"></i></button>
            <button id="btnStockFilter" class="btn btn-light border-0" onclick="toggleStockFilter()" title="Solo con existencias"><i class="fas fa-cubes"></i></button>
            <span class="input-group-text bg-white border-0"><i class="fas fa-search"></i></span>
            <input type="text" id="searchInput" class="form-control border-0" placeholder="Buscar / Escanear..." onkeyup="filterProducts()">
            <button class="btn btn-light border-0" onclick="document.getElementById('searchInput').value='';filterProducts()">X</button>
        </div>
        <div class="category-bar">
            <button class="category-btn active" onclick="filterCategory('all', this)">TODOS</button>
            <?php foreach($cats as $cat): ?>
                <button class="category-btn" onclick="filterCategory('<?php echo htmlspecialchars($cat); ?>', this)"><?php echo htmlspecialchars($cat); ?></button>
            <?php endforeach; ?>
        </div>
        <div class="product-grid" id="productContainer"></div>
    </div>
</div>

<div class="modal fade" id="paymentModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white py-2"><h5 class="modal-title fs-6">Finalizar Venta</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <div class="modal-body p-3">
                <div class="text-center mb-3"><h2 class="fw-bold text-success mb-0" id="modalTotal">$0.00</h2></div>
                <div class="mb-2"><input type="text" id="cliName" class="form-control form-control-sm mb-1" placeholder="Cliente"><div class="row g-1"><div class="col-6"><input type="text" id="cliPhone" class="form-control form-control-sm" placeholder="Teléfono"></div><div class="col-6"><input type="text" id="cliAddr" class="form-control form-control-sm" placeholder="Dirección"></div></div></div>
                <div class="mb-2"><select class="form-select form-select-sm" id="serviceType" onchange="toggleServiceOptions()"><option value="consumir_aqui">🍽️ Aquí</option><option value="llevar">🥡 Llevar</option><option value="mensajeria">🛵 Delivery</option><option value="reserva">📅 Reserva</option></select></div>
                <div class="mb-2 d-none" id="deliveryDiv"><input type="text" class="form-control form-control-sm border-primary" id="deliveryDriver" placeholder="Mensajero"></div>
                <div class="mb-2 d-none" id="reservationDiv"><div class="row g-1"><div class="col-7"><input type="datetime-local" class="form-control form-control-sm border-danger" id="reservationDate"></div><div class="col-5"><input type="number" class="form-control form-control-sm border-danger fw-bold" id="reservationAbono" placeholder="Abono"></div></div></div>
                <div class="row g-1 mb-2">
                    <div class="col-4"><input type="radio" class="btn-check" name="payMethod" id="payCash" value="Efectivo" checked><label class="btn btn-outline-success w-100 btn-sm" for="payCash">Efec.</label></div>
                    <div class="col-4"><input type="radio" class="btn-check" name="payMethod" id="payTransfer" value="Transferencia"><label class="btn btn-outline-primary w-100 btn-sm" for="payTransfer">Transf.</label></div>
                    <div class="col-4"><input type="radio" class="btn-check" name="payMethod" id="payHouse" value="Gasto Casa"><label class="btn btn-outline-warning w-100 btn-sm" for="payHouse">Gasto</label></div>
                </div>
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="printTicket" checked><label class="form-check-label small" for="printTicket">Ticket</label></div>
            </div>
            <div class="modal-footer py-1"><button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">X</button><button class="btn btn-primary fw-bold flex-grow-1" onclick="confirmPayment()">PAGAR</button></div>
        </div>
    </div>
</div>

<div class="modal fade" id="cashModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white py-2">
                <h5 class="modal-title fs-6 fw-bold">Caja</h5>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3" id="cashModalBody">
                </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    const CACHE_KEY = 'products_cache_v1';
    const QUEUE_KEY = 'pos_pending_sales';
    const PARKED_ORDERS_KEY = 'pos_parked_orders';
    const CACHE_DURATION = 5 * 60 * 1000;

    window.PRODUCTS_DATA = <?php echo json_encode($prods); ?>;
    window.CAJEROS = <?php echo json_encode($config['cajeros']); ?>;

    let productsDB = []; 
    let cart = []; 
    let selectedIndex = -1; 
    let enteredPin = "";
    let currentCashier = "Cajero"; 
    let cashId = 0; 
    let cashOpen = false;
    let accountingDate = ""; 
    let barcodeBuffer = ""; 
    let barcodeTimeout; 
    let globalDiscountPct = 0;
    let stockFilterActive = false;

    // AUDIO ENGINE
    const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    const Synth = {
        playTone: (freq, type, duration, vol = 0.1) => {
            if(audioCtx.state === 'suspended') audioCtx.resume();
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = type;
            osc.frequency.setValueAtTime(freq, audioCtx.currentTime);
            gain.gain.setValueAtTime(vol, audioCtx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + duration);
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.start();
            osc.stop(audioCtx.currentTime + duration);
        },
        beep: () => Synth.playTone(1200, 'sine', 0.1, 0.12),
        error: () => { Synth.playTone(150, 'sawtooth', 0.3, 0.15); setTimeout(() => Synth.playTone(100, 'sawtooth', 0.3, 0.15), 100); },
        click: () => Synth.playTone(800, 'triangle', 0.05, 0.08),
        cash: () => { Synth.playTone(800, 'sine', 0.1, 0.12); setTimeout(() => Synth.playTone(1000, 'sine', 0.1, 0.12), 100); setTimeout(() => Synth.playTone(1200, 'sine', 0.15, 0.15), 200); },
        tada: () => { const now = audioCtx.currentTime; [523.25, 659.25, 783.99, 1046.50].forEach((freq, i) => { const osc = audioCtx.createOscillator(); const gain = audioCtx.createGain(); osc.frequency.value = freq; gain.gain.setValueAtTime(0.08, now + i*0.08); gain.gain.exponentialRampToValueAtTime(0.001, now + i*0.08 + 0.3); osc.connect(gain); gain.connect(audioCtx.destination); osc.start(now + i*0.08); osc.stop(now + i*0.08 + 0.3); }); },
        refund: () => { const osc = audioCtx.createOscillator(); const gain = audioCtx.createGain(); osc.type = 'sawtooth'; osc.frequency.setValueAtTime(400, audioCtx.currentTime); osc.frequency.linearRampToValueAtTime(100, audioCtx.currentTime + 0.5); gain.gain.setValueAtTime(0.1, audioCtx.currentTime); gain.gain.linearRampToValueAtTime(0, audioCtx.currentTime + 0.5); osc.connect(gain); gain.connect(audioCtx.destination); osc.start(); osc.stop(audioCtx.currentTime + 0.5); },
        addCart: () => Synth.playTone(1000, 'sine', 0.06, 0.1),
        removeCart: () => Synth.playTone(500, 'sine', 0.08, 0.12),
        category: () => Synth.playTone(700, 'triangle', 0.04, 0.06),
        discount: () => Synth.playTone(900, 'sine', 0.08, 0.1),
        increment: () => Synth.playTone(850, 'sine', 0.04, 0.07),
        clear: () => Synth.playTone(400, 'sine', 0.08, 0.1)
    };

    function showToast(msg, type = 'success') {
        const container = document.getElementById('toastContainer');
        let color = 'text-bg-success', icon = '<i class="fas fa-check-circle"></i>';
        if (type === 'error') { color = 'text-bg-danger'; icon = '<i class="fas fa-exclamation-circle"></i>'; Synth.error(); }
        else if (type === 'warning') { color = 'text-bg-warning'; icon = '<i class="fas fa-cloud-upload-alt"></i>'; }
        
        const div = document.createElement('div');
        div.innerHTML = `<div class="toast align-items-center ${color} border-0 mb-2 shadow" role="alert"><div class="d-flex"><div class="toast-body fw-bold fs-6">${icon} ${msg}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>`;
        const toastEl = div.firstElementChild;
        container.appendChild(toastEl);
        new bootstrap.Toast(toastEl, { delay: 3000 }).show();
        toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
    }

    document.addEventListener('DOMContentLoaded', () => {
        if (window.PRODUCTS_DATA && window.PRODUCTS_DATA.length > 0) { 
            productsDB = window.PRODUCTS_DATA; 
            renderProducts('all'); 
        } else { 
            refreshProducts(); 
        }
        updatePinDisplay();
        checkCashStatusSilent();
        
        document.addEventListener('keydown', handleBarcodeScanner);
        document.body.addEventListener('click', () => { if(audioCtx.state === 'suspended') audioCtx.resume(); }, {once:true});
        window.addEventListener('online',  () => { updateOnlineStatus(); syncOfflineQueue(); });
        window.addEventListener('offline', () => updateOnlineStatus());
        updateOnlineStatus();
        
        const saved = sessionStorage.getItem('cajero');
        if (saved) {
            try {
                const u = JSON.parse(saved);
                document.getElementById('pinOverlay').style.display = 'none';
                document.getElementById('posContainer').style.display = 'flex';
                document.getElementById('cashierName').innerText = u.nombre;
                currentCashier = u.nombre;
            } catch(e) {}
        }
    });

    function updateOnlineStatus() {
        const badge = document.getElementById('netStatus');
        const btnSync = document.getElementById('btnSync');
        const queue = JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]');
        if (navigator.onLine) {
            if(queue.length > 0) {
                if(badge) { badge.className = 'badge bg-warning text-dark'; badge.innerHTML = `<i class="fas fa-exclamation-triangle"></i> Pend: ${queue.length}`; }
                if(btnSync) btnSync.classList.remove('d-none');
            } else {
                if(badge) { badge.className = 'badge bg-success'; badge.innerHTML = '<i class="fas fa-wifi"></i> Online'; }
                if(btnSync) btnSync.classList.add('d-none');
            }
        } else {
            if(badge) { badge.className = 'badge bg-danger'; badge.innerHTML = `<i class="fas fa-plane"></i> Offline (${queue.length})`; }
            if(btnSync) btnSync.classList.add('d-none');
        }
    }

    async function syncOfflineQueue() {
        if (!navigator.onLine) return showToast("Sin internet", "error");
        const queue = JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]');
        if (queue.length === 0) return;
        
        const btn = document.getElementById('btnSync'); if(btn) btn.innerHTML = '<i class="fas fa-spin fa-spinner"></i>';
        const failedQueue = []; let syncedCount = 0;
        
        for (const sale of queue) {
            try {
                const resp = await fetch('pos_save.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(sale) });
                const res = await resp.json();
                if (res.status === 'success') syncedCount++; else failedQueue.push(sale);
            } catch (e) { failedQueue.push(sale); }
        }
        localStorage.setItem(QUEUE_KEY, JSON.stringify(failedQueue));
        updateOnlineStatus();
        if (syncedCount > 0) { showToast(`${syncedCount} Sincronizadas`, 'success'); Synth.tada(); refreshProducts(); }
        if (btn) btn.innerHTML = '<i class="fas fa-sync"></i>';
    }

    async function refreshProducts() {
        const btn = document.getElementById('btnRefresh'); if (btn) { btn.innerHTML = '<i class="fas fa-spin fa-spinner"></i>'; btn.disabled = true; }
        try {
            const resp = await fetch('get_products.php?t=' + Date.now()); 
            if(resp.ok) location.reload();
        } catch(e) { showToast("Error al actualizar", "error"); } 
        finally { if (btn) { btn.innerHTML = '<i class="fas fa-sync-alt"></i>'; btn.disabled = false; } }
    }

    function toggleStockFilter() {
        stockFilterActive = !stockFilterActive;
        const btn = document.getElementById('btnStockFilter');
        if (stockFilterActive) btn.classList.add('btn-filter-active');
        else btn.classList.remove('btn-filter-active');
        renderProducts();
    }

    function renderProducts(category, term = '') {
        if (typeof category === 'undefined') {
            const activeBtn = document.querySelector('.category-btn.active');
            category = activeBtn ? (activeBtn.innerText === 'TODOS' ? 'all' : activeBtn.innerText) : 'all';
        }
        if(!term) term = document.getElementById('searchInput').value.toLowerCase();

        const grid = document.getElementById('productContainer');
        grid.innerHTML = '';

        if (!Array.isArray(productsDB) || productsDB.length === 0) {
            grid.innerHTML = '<div class="text-center text-muted p-4"><i class="fas fa-box-open fa-3x mb-2"></i><p>No hay productos</p></div>';
            return;
        }

        const filtered = productsDB.filter(p => {
            const matchCat = category === 'all' || p.categoria === category;
            const matchSearch = p.nombre.toLowerCase().includes(term) || p.codigo.toLowerCase().includes(term);
            const matchStock = stockFilterActive ? (parseFloat(p.stock) > 0 || p.es_servicio == 1) : true;
            return matchCat && matchSearch && matchStock;
        });

        filtered.forEach(p => {
            const s = parseFloat(p.stock); 
            const ok = s > 0 || p.es_servicio == 1;
            const card = document.createElement('div'); 
            card.className = `product-card ${ok ? '' : 'disabled'}`;
            
            let imgHTML = '';
            if(p.has_image) {
                imgHTML = `
                    <div class="product-img-container" style="background:${p.color}; position:relative;">
                        <span class="placeholder-text" style="position:absolute; z-index:1;">${p.nombre.substring(0,2)}</span>
                        <img src="/product_images/${p.codigo}.jpg" 
                             class="product-img" 
                             style="position:relative; z-index:2; width:100%; height:100%; object-fit:cover;"
                             onerror="this.style.display='none'">
                    </div>`;
            } else {
                imgHTML = `<div class="product-img-container" style="background:${p.color}">
                               <span class="placeholder-text">${p.nombre.substring(0,2)}</span>
                           </div>`;
            }

            card.innerHTML = `
                <div class="stock-badge ${ok ? 'stock-ok' : 'stock-zero'}">${p.es_servicio == 1 ? '∞' : s}</div>
                ${imgHTML}
                <div class="product-info">
                    <div class="product-name text-dark">${p.nombre}</div>
                    <div class="product-price">$${parseFloat(p.precio).toFixed(2)}</div>
                </div>
            `;
            
            if(ok) card.onclick = () => addToCart(p);
            grid.appendChild(card);
        });
    }

    function filterCategory(cat, btn) {
        Synth.category();
        document.querySelectorAll('.category-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        renderProducts(cat);
    }
    
    function filterProducts() {
        renderProducts(undefined, document.getElementById('searchInput').value);
    }

    function addToCart(p) {
        if(parseFloat(p.stock) <= 0 && p.es_servicio == 0) return showToast("Agotado", "error");
        
        const idx = cart.findIndex(i => i.id === p.codigo && !i.note);
        if(idx >= 0) { 
            if(p.es_servicio == 0 && (cart[idx].qty + 1) > parseFloat(p.stock)) return showToast("Stock insuficiente", "error");
            cart[idx].qty++; 
            selectedIndex = idx; 
        } else { 
            cart.push({ id: p.codigo, name: p.nombre, price: parseFloat(p.precio), qty: 1, discountPct: 0, note: '' }); 
            selectedIndex = cart.length - 1; 
        }
        Synth.addCart(); 
        renderCart();
    }

    function renderCart() {
        const c = document.getElementById('cartContainer'); 
        const totalItemsEl = document.getElementById('totalItems');
        const totalAmountEl = document.getElementById('totalAmount');
        c.innerHTML = '';
        
        let sub = 0; 
        let items = 0;

        if (cart.length === 0) {
            c.innerHTML = `
                <div class="text-center text-muted h-100 d-flex flex-column align-items-center justify-content-center">
                    <img src="00LOGO OFICIAL.jpg" style="width: 120px; margin-bottom: 15px; opacity: 0.8;" alt="PalWeb">
                    <i class="fas fa-shopping-basket fa-3x mb-2 opacity-25"></i>
                    <p class="small">Carrito Vacío</p>
                </div>`;
            if(totalItemsEl) totalItemsEl.innerText = '0';
            if(totalAmountEl) totalAmountEl.innerText = '$0.00';
            return;
        }

        cart.forEach((i, idx) => {
            const lineT = (i.price * (1 - i.discountPct/100)) * i.qty;
            sub += lineT; 
            items += i.qty;
            
            const d = document.createElement('div'); 
            d.className = `cart-item ${idx === selectedIndex ? 'selected' : ''}`; 
            d.onclick = () => { selectedIndex = idx; renderCart(); };
            
            let bdg = i.discountPct > 0 ? `<span class="discount-tag">-${i.discountPct}%</span>` : ''; 
            let nt = i.note ? `<span class=\"cart-note\">📝 ${i.note}</span>` : '';
            
            d.innerHTML = `
                <div class="d-flex justify-content-between fw-bold">
                    <span>${i.qty} x ${i.name}${bdg}</span>
                    <span>$${lineT.toFixed(2)}</span>
                </div>
                <div class="small text-muted">$${(i.price*(1-i.discountPct/100)).toFixed(2)}</div>
                ${nt}`;
            c.appendChild(d);
        });

        const total = sub * (1 - globalDiscountPct/100);
        
        if (globalDiscountPct > 0) {
            totalAmountEl.innerHTML = `<small class="text-muted fs-6"><s>$${sub.toFixed(2)}</s> -${globalDiscountPct}%</small><br>$${total.toFixed(2)}`;
        } else {
            totalAmountEl.innerText = '$' + total.toFixed(2);
        }
        totalItemsEl.innerText = items;
    }

    function modifyQty(d) {
        if(selectedIndex < 0) return;
        const item = cart[selectedIndex];
        const prod = productsDB.find(x => x.codigo == item.id);
        if(d > 0 && prod && prod.es_servicio == 0 && (item.qty + d) > parseFloat(prod.stock)) return showToast("Sin más stock", "error");
        
        item.qty += d;
        if(item.qty <= 0) { cart.splice(selectedIndex, 1); selectedIndex = -1; }
        renderCart(); Synth.click();
    }
    
    function removeItem() { if(selectedIndex >= 0 && confirm('¿Eliminar producto?')) { Synth.removeCart(); cart.splice(selectedIndex, 1); selectedIndex = -1; renderCart(); } }
    function clearCart() { if(cart.length > 0 && confirm('¿Vaciar carrito?')) { Synth.clear(); cart = []; globalDiscountPct = 0; selectedIndex = -1; renderCart(); } }
    function askQty() { if(selectedIndex < 0) return showToast("Seleccione producto", "warning"); let q = prompt("Cantidad:", cart[selectedIndex].qty); if(q && !isNaN(q) && q > 0) { cart[selectedIndex].qty = Number(q); Synth.increment(); renderCart(); } }
    function applyDiscount() { if(selectedIndex < 0) return showToast('Seleccione item', 'warning'); let p = prompt("% Descuento Item:", cart[selectedIndex].discountPct); if(p !== null) { let v = parseFloat(p)||0; if(v<0||v>100) return; cart[selectedIndex].discountPct = v; renderCart(); Synth.discount(); } }
    function applyGlobalDiscount() { if(cart.length === 0) return; let p = prompt("% Descuento GLOBAL:", globalDiscountPct); if(p !== null) { let v = parseFloat(p)||0; if(v<0||v>100) return; globalDiscountPct = v; renderCart(); Synth.discount(); } }
    function addNote() { if(selectedIndex < 0) return showToast('Seleccione producto', 'warning'); let n = prompt("Nota de preparación:", cart[selectedIndex].note); if(n !== null) { cart[selectedIndex].note = n; renderCart(); } }

    const cashModal = new bootstrap.Modal(document.getElementById('cashModal'));
    const payModal = new bootstrap.Modal(document.getElementById('paymentModal'));

    async function checkCashStatusSilent() {
        try {
            const r = await fetch('pos_cash.php?action=status'); const d = await r.json(); const b = document.getElementById('cashStatusBadge');
            if(d.status === 'open') { 
                cashOpen = true; cashId = d.data.id; accountingDate = d.data.fecha_contable;
                b.className = 'cash-status cash-open'; b.innerText = `ABIERTA (${accountingDate})`; 
            } else { 
                cashOpen = false; cashId = 0; b.className = 'cash-status cash-closed'; b.innerText = 'CERRADA'; 
            }
        } catch (e) {}
    }

    function checkCashRegister() { if(cashOpen) showCloseCashModal(); else showOpenCashModal(); }

    function showOpenCashModal() { 
        const today = new Date().toISOString().split('T')[0];
        document.getElementById('cashModalBody').innerHTML = `
            <div class="text-center mb-4">
                <h5 class="fw-bold">Apertura de Caja</h5>
                <p class="text-muted small">Inicie un nuevo turno operativo</p>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-bold">Fecha Contable</label>
                <input type="date" id="cashDate" class="form-control" value="${today}">
            </div>
            <div class="mb-4">
                <label class="form-label small fw-bold">Fondo Inicial ($)</label>
                <input type="number" id="cashInitial" class="form-control form-control-lg border-primary text-center fw-bold" placeholder="0.00">
            </div>
            <button onclick="openCash()" class="btn btn-success w-100 fw-bold py-2">ABRIR TURNO</button>`; 
        cashModal.show(); 
    }

    async function openCash() { 
        const a = document.getElementById('cashInitial').value; const f = document.getElementById('cashDate').value;
        if(!a) return showToast('Ingrese Monto', 'warning');
        await fetch('pos_cash.php?action=open', { method: 'POST', body: JSON.stringify({ cajero: currentCashier, monto: a, fecha: f }) }); 
        cashModal.hide(); Synth.cash(); checkCashStatusSilent(); showToast("Caja Abierta"); 
    }

    // --- NUEVO MODAL DE CIERRE CON DESGLOSE ---
    async function showCloseCashModal() {
        const r = await fetch('pos_cash.php?action=status'); 
        const d = await r.json();
        
        let total = 0; 
        let paymentsHTML = '<ul class="list-group list-group-flush small">';
        let statsHTML = '<ul class="list-group list-group-flush small">';
        
        // Pagos
        (d.ventas || []).forEach(v => { 
            paymentsHTML += `<li class="stat-item"><span>${v.metodo_pago}</span><strong>$${parseFloat(v.total).toFixed(2)}</strong></li>`; 
            total += parseFloat(v.total); 
        });
        paymentsHTML += '</ul>';

        // Stats Servicios (Simulado/Placeholders si el backend no lo envía)
        // Nota: Agregamos esto para cumplir con el diseño, se llenará si el backend evoluciona
        const delivCnt = d.conteo_delivery || 0; 
        const resCnt = d.conteo_reserva || 0;
        
        statsHTML += `
            <li class="stat-item"><span><i class="fas fa-motorcycle text-info"></i> Deliveries</span><strong>${delivCnt}</strong></li>
            <li class="stat-item"><span><i class="fas fa-calendar-alt text-warning"></i> Reservas</span><strong>${resCnt}</strong></li>
        </ul>`;

        document.getElementById('cashModalBody').innerHTML = `
            <div class="text-center mb-3">
                <h5 class="fw-bold mb-1">Cierre de Turno Administrativo</h5>
                <span class="badge bg-primary">📅 Fecha Contable: ${d.data.fecha_contable}</span>
            </div>
            
            <div class="modal-total-box">
                <small class="d-block text-uppercase text-muted fw-bold mb-1">VENTAS TOTALES EN SISTEMA</small>
                <div class="modal-total-amount">$${total.toFixed(2)}</div>
            </div>

            <div class="row mb-3">
                <div class="col-6 border-end">
                    <h6 class="small fw-bold text-muted border-bottom pb-1">Por Pagos</h6>
                    ${paymentsHTML}
                </div>
                <div class="col-6">
                    <h6 class="small fw-bold text-muted border-bottom pb-1">Servicios</h6>
                    ${statsHTML}
                </div>
            </div>

            <div class="mb-2">
                <label class="small fw-bold">Efectivo Real en Físico:</label>
                <input type="number" id="cashFinal" class="form-control form-control-lg border-danger text-center fw-bold" placeholder="0.00">
            </div>
            <div class="mb-3">
                <label class="small fw-bold">Observaciones del Turno:</label>
                <textarea id="cashNote" class="form-control text-muted" rows="2" placeholder="Notas sobre el cierre..."></textarea>
            </div>
            <button onclick="closeCash(${d.data.id},${total})" class="btn btn-danger w-100 py-2 fw-bold shadow-sm">
                <i class="fas fa-lock me-2"></i> REGISTRAR CIERRE FINAL
            </button>`;
        cashModal.show();
    }

    async function closeCash(id, sys) {
        const r = parseFloat(document.getElementById('cashFinal').value);
        if(isNaN(r)) return showToast('Ingrese monto real', 'warning');
        if((r - sys) !== 0 && !confirm(`Diferencia $${(r-sys).toFixed(2)}. ¿Cerrar?`)) return;
        await fetch('pos_cash.php?action=close', { method: 'POST', body: JSON.stringify({ id: id, sistema: sys, real: r, nota: document.getElementById('cashNote').value }) });
        cashModal.hide(); checkCashStatusSilent(); showToast('Turno Cerrado');
    }

    function openPaymentModal() { 
        if(cart.length === 0) return showToast('Carrito vacío', 'warning'); 
        if(!cashOpen) return showToast('DEBE ABRIR CAJA', 'error'); 
        document.getElementById('modalTotal').innerHTML = document.getElementById('totalAmount').innerHTML; 
        payModal.show(); 
    }

    function toggleServiceOptions() { 
        const t = document.getElementById('serviceType').value; 
        document.getElementById('reservationDiv').classList.toggle('d-none', t !== 'reserva');
        document.getElementById('deliveryDiv').classList.toggle('d-none', t !== 'mensajeria');
    }

    async function confirmPayment() {
        let sub = cart.reduce((acc, i) => acc + ((i.price * (1 - i.discountPct/100)) * i.qty), 0);
        let tot = sub * (1 - globalDiscountPct/100);
        
        const payload = { 
            uuid: crypto.randomUUID(), 
            items: cart.map(i => ({ id: i.id, name: i.name, qty: i.qty, price: i.price, note: i.note })), 
            total: tot, 
            metodo_pago: document.querySelector('input[name="payMethod"]:checked').value, 
            tipo_servicio: document.getElementById('serviceType').value, 
            fecha_reserva: document.getElementById('reservationDate').value, 
            cliente_nombre: document.getElementById('cliName').value.trim() || 'Consumidor Final', 
            cliente_telefono: document.getElementById('cliPhone').value.trim(), 
            cliente_direccion: document.getElementById('cliAddr').value.trim(), 
            mensajero_nombre: document.getElementById('deliveryDriver').value.trim(), 
            abono: document.getElementById('reservationAbono').value, 
            id_caja: cashId 
        };

        const print = document.getElementById('printTicket').checked;
        payModal.hide();

        try {
            const r = await fetch('pos_save.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload) });
            const res = await r.json();
            if (res.status === 'success') {
                if(tot < 0) Synth.refund(); else Synth.cash();
                if(print) window.open(`ticket_view.php?id=${res.id}`, 'Ticket', 'width=380,height=600'); 
                else showToast(`Venta #${res.id} registrada`);
                cart = []; globalDiscountPct = 0; selectedIndex = -1; renderCart(); refreshProducts();
            } else showToast('Error: ' + res.msg, 'error');
        } catch (e) {
            saveOffline(payload);
        }
    }

    function saveOffline(p) {
        const q = JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]'); q.push(p); localStorage.setItem(QUEUE_KEY, JSON.stringify(q));
        updateOnlineStatus(); showToast('Guardado OFFLINE', 'warning'); 
        cart = []; globalDiscountPct = 0; selectedIndex = -1; renderCart();
    }

    function handleBarcodeScanner(e) { 
        if(e.target.tagName === 'INPUT' && e.target.id !== 'searchInput') return; 
        if(e.key === 'Enter') { if(barcodeBuffer.length > 0) { e.preventDefault(); processBarcode(barcodeBuffer); barcodeBuffer = ""; } } 
        else if(e.key.length === 1) { clearTimeout(barcodeTimeout); barcodeBuffer += e.key; barcodeTimeout = setTimeout(() => barcodeBuffer = "", 100); } 
    }
    function processBarcode(c) { const p = productsDB.find(x => x.codigo == c); if(p) { addToCart(p); Synth.beep(); document.getElementById('searchInput').value = ""; } else Synth.error(); }

    function typePin(v) { Synth.click(); if(v === 'C') enteredPin = ""; else if(enteredPin.length < 4) enteredPin += v; updatePinDisplay(); }
    function updatePinDisplay() { document.getElementById('pinDisplay').innerText = "•".repeat(enteredPin.length); }
    async function verifyPin() {
        if(enteredPin.length < 4) return;
        try {
            const r = await fetch('pos_cash.php?action=login', { method: 'POST', body: JSON.stringify({ pin: enteredPin }) });
            const d = await r.json();
            if(d.status === 'success') { 
                currentCashier = d.cajero; 
                sessionStorage.setItem('cajero', JSON.stringify({nombre:d.cajero}));
                document.getElementById('cashierName').innerText = currentCashier; 
                document.getElementById('pinOverlay').style.display = 'none'; 
                document.getElementById('posContainer').style.display = 'flex';
                Synth.tada(); 
                checkCashStatusSilent();
            } else { showToast('PIN Incorrecto', 'error'); enteredPin = ""; updatePinDisplay(); }
        } catch(e) { showToast("Error Login", "error"); }
    }

    function parkOrder() {
        if (cart.length === 0) return showToast('Vacío', 'warning');
        const name = prompt('Nombre Orden:', `Mesa ${Date.now().toString().slice(-4)}`);
        if (!name) return;
        const parked = JSON.parse(localStorage.getItem(PARKED_ORDERS_KEY) || '[]');
        parked.push({ id: Date.now(), name, items: [...cart], discount: globalDiscountPct, time: new Date().toISOString() });
        localStorage.setItem(PARKED_ORDERS_KEY, JSON.stringify(parked));
        Synth.click(); showToast('Orden Pausada'); cart = []; renderCart();
    }

    function showParkedOrders() {
        const parked = JSON.parse(localStorage.getItem(PARKED_ORDERS_KEY) || '[]');
        if (parked.length === 0) return showToast('No hay órdenes', 'warning');
        
        let html = '<div class="list-group">';
        parked.forEach((o, i) => {
            html += `<div class="list-group-item d-flex justify-content-between align-items-center">
                <div><strong>${o.name}</strong><br><small>${o.items.length} items</small></div>
                <button class="btn btn-primary btn-sm" onclick="loadParked(${i})">Recuperar</button></div>`;
        });
        html += '</div>';
        
        let m = document.getElementById('parkModalDynamic');
        if(!m) {
            document.body.insertAdjacentHTML('beforeend', `<div class="modal fade" id="parkModalDynamic"><div class="modal-dialog"><div class="modal-content"><div class="modal-header bg-warning"><h5 class="modal-title">Pausadas</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" id="parkBody"></div></div></div></div>`);
            m = document.getElementById('parkModalDynamic');
        }
        document.getElementById('parkBody').innerHTML = html;
        new bootstrap.Modal(m).show();
    }

    window.loadParked = function(i) {
        const parked = JSON.parse(localStorage.getItem(PARKED_ORDERS_KEY) || '[]');
        const o = parked[i];
        cart = o.items; globalDiscountPct = o.discount; renderCart();
        parked.splice(i, 1); localStorage.setItem(PARKED_ORDERS_KEY, JSON.stringify(parked));
        bootstrap.Modal.getInstance(document.getElementById('parkModalDynamic')).hide();
        Synth.beep();
    };

    // Auto-wrapper
    (function() {
        const _addToCart = window.addToCart;
        if (typeof _addToCart === 'function') {
            window.addToCart = function(...args) {
                _addToCart.apply(this, args);
            };
        }
    })();

</script>
</body>
</html>


IDM

