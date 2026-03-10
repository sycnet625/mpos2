<?php
// ARCHIVO: /var/www/palweb/api/pos.php
ini_set('display_errors', 1);
require_once 'db.php';

// ENDPOINT: Carga de productos para caché
if (isset($_GET['load_products'])) {
    header('Content-Type: application/json');
    
    // Leer configuración
    $configFile = 'pos.cfg';
    $config = [ 
        "id_almacen" => 1, 
        "id_sucursal" => 1, 
        "mostrar_materias_primas" => false, 
        "mostrar_servicios" => true, 
        "categorias_ocultas" => [] 
    ];
    
    if (file_exists($configFile)) {
        $loaded = json_decode(file_get_contents($configFile), true);
        if ($loaded) $config = array_merge($config, $loaded);
    }
    
    try {
        $cond = ["p.activo = 1"];
        if (!$config['mostrar_materias_primas']) $cond[] = "p.es_materia_prima = 0";
        if (!$config['mostrar_servicios']) $cond[] = "p.es_servicio = 0";
        if (!empty($config['categorias_ocultas'])) {
            $placeholders = implode(',', array_fill(0, count($config['categorias_ocultas']), '?'));
            $cond[] = "p.categoria NOT IN ($placeholders)";
        }
        
        $where = implode(" AND ", $cond);
        $almacenID = intval($config['id_almacen']);
        $params = $config['categorias_ocultas'];
        
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
        
        // Procesar para incluir colores e imágenes
        $localPath = '/home/marinero/product_images/';
        foreach ($prods as &$p) {
            $p['has_image'] = file_exists($localPath . $p['codigo'] . '.jpg');
            $p['color'] = '#' . substr(dechex(crc32($p['nombre'])), 0, 6);
            $p['stock'] = floatval($p['stock']);
        }
        unset($p);
        
        echo json_encode(['status' => 'success', 'products' => $prods, 'timestamp' => time()]);
        exit;
        
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// ENDPOINT: Ping para medir velocidad
if (isset($_GET['ping'])) {
    header('Content-Type: application/json');
    echo json_encode(['pong' => true, 'timestamp' => time()]);
    exit;
}

// Config
$configFile = 'pos.cfg';
$config = [ "tienda_nombre" => "MI TIENDA", "cajeros" => [["nombre"=>"Admin", "pin"=>"0000"]], "id_almacen" => 1, "id_sucursal" => 1, "mostrar_materias_primas" => false, "mostrar_servicios" => true, "categorias_ocultas" => [] ];
if (file_exists($configFile)) {
    $loaded = json_decode(file_get_contents($configFile), true);
    if ($loaded) $config = array_merge($config, $loaded);
}

// Carga de Datos
$prods = [];
try {
    $cond = ["p.activo = 1"];
    if (!$config['mostrar_materias_primas']) $cond[] = "p.es_materia_prima = 0";
    if (!$config['mostrar_servicios']) $cond[] = "p.es_servicio = 0";
    if (!empty($config['categorias_ocultas'])) {
        $placeholders = implode(',', array_fill(0, count($config['categorias_ocultas']), '?'));
        $cond[] = "p.categoria NOT IN ($placeholders)";
    }
    
    $where = implode(" AND ", $cond);
    $almacenID = intval($config['id_almacen']);
    $params = $config['categorias_ocultas'];

    // Categorias
    $stmtCat = $pdo->prepare("SELECT DISTINCT categoria FROM productos p WHERE $where ORDER BY categoria");
    $stmtCat->execute($params);
    $cats = $stmtCat->fetchAll(PDO::FETCH_COLUMN);

    // Productos
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

// Procesamiento visual
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
        html, body { height: 100%; margin: 0; padding: 0; overflow: hidden; background-color: #e9ecef; font-family: 'Segoe UI', sans-serif; touch-action: manipulation; user-select: none; }
        .pos-container { display: flex; height: 100vh; width: 100vw; overflow: hidden; }
        .left-panel { background: white; border-right: 1px solid #ddd; display: flex; flex-direction: column; z-index: 20; box-shadow: 2px 0 5px rgba(0,0,0,0.05); height: 100%; overflow-y: auto; }
        .top-bar { flex-shrink: 0; }
        .cart-list { flex-grow: 1; overflow-y: auto; background: #fff; border-bottom: 1px solid #eee; min-height: 150px; }
        .controls-wrapper { padding: 8px; padding-bottom: 60px; background: #fff; flex-shrink: 0; border-top: 1px solid #eee; }
        .right-panel { flex-grow: 1; display: flex; flex-direction: column; background: #e9ecef; padding: 10px; overflow: hidden; }
        @media (orientation: landscape) { .pos-container { flex-direction: row; } .left-panel { width: 35%; min-width: 340px; max-width: 420px; } .right-panel { width: 65%; } #keypadContainer { display: grid !important; } #toggleKeypadBtn { display: none !important; } }
        @media (orientation: portrait) { .pos-container { flex-direction: column; } .left-panel { width: 100%; height: 45%; border-right: none; border-bottom: 2px solid #ccc; order: 1; } .right-panel { width: 100%; height: 55%; order: 2; padding: 5px; } #keypadContainer { display: none; } #toggleKeypadBtn { display: block; width: 100%; margin-bottom: 5px; } .controls-wrapper { padding-bottom: 10px; } }
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
        .category-bar { display: flex; overflow-x: auto; gap: 8px; margin-bottom: 8px; padding-bottom: 2px; scrollbar-width: none; }
        .category-btn { padding: 8px 16px; border: none; border-radius: 20px; font-weight: 600; background: white; color: #555; white-space: nowrap; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .category-btn.active { background: #0d6efd; color: white; }
        .product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(135px, 1fr)); gap: 10px; overflow-y: auto; padding-bottom: 80px; }
        .product-card { background: white; border-radius: 10px; overflow: hidden; cursor: pointer; box-shadow: 0 2px 5px rgba(0,0,0,0.08); display: flex; flex-direction: column; position: relative !important; min-height: 180px; }
        .product-card.disabled { opacity: 0.5; pointer-events: none; }
        .product-img-container { width: 100%; aspect-ratio: 4/3; background: #eee; display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .product-img { width: 100%; height: 100%; object-fit: cover; }
        .placeholder-text { font-size: 1.5rem; color: white; font-weight: bold; text-shadow: 0 1px 2px rgba(0,0,0,0.3); }
        .product-info { padding: 8px; flex-grow: 1; display: flex; flex-direction: column; justify-content: space-between; }
        .product-name { font-weight: 600; font-size: 0.9rem; line-height: 1.2; margin-bottom: 5px; color: #333; }
        .product-price { color: #0d6efd; font-weight: 800; font-size: 1rem; text-align: right; }
        .stock-badge { position: absolute !important; top: 5px; right: 5px; border-radius: 50%; width: 26px; height: 26px; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.75rem; z-index: 10; box-shadow: 0 2px 4px rgba(0,0,0,0.3); }
        .stock-ok { background: #ffc107; color: black; } .stock-zero { background: #dc3545; color: white; }
        #pinOverlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); z-index: 9999; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(5px); }
        .pin-box { background: white; padding: 30px; border-radius: 15px; text-align: center; width: 320px; }
        .cash-status { font-size: 0.7rem; margin-left: 5px; padding: 1px 6px; border-radius: 4px; display: inline-block; vertical-align: middle; }
        .cash-open { background: #d1e7dd; color: #0f5132; } .cash-closed { background: #f8d7da; color: #842029; }
        .pin-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-top: 15px; }
        .pin-btn { height: 60px; font-size: 1.5rem; border-radius: 10px; border: 1px solid #ccc; background: #f8f9fa; }
        .toast-container { z-index: 10000; }
        .toast { background-color: rgba(255,255,255,0.95); box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15); }
        
        /* MODO SIN IMÁGENES */
        body.mode-no-images .product-img { display: none !important; }
        body.mode-no-images .placeholder-text { display: flex !important; font-size: 2rem; }
        body.mode-no-images .product-img-container { background: linear-gradient(135deg, var(--product-color, #6c757d), var(--product-color-dark, #495057)); }
        
        /* BARRA DE PROGRESO */
        #progressOverlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); z-index: 99999; align-items: center; justify-content: center; backdrop-filter: blur(5px); }
        #progressOverlay.active { display: flex; }
        .progress-card { background: white; padding: 30px; border-radius: 15px; width: 90%; max-width: 450px; box-shadow: 0 10px 40px rgba(0,0,0,0.5); }
        .progress-title { margin: 0 0 20px 0; color: #333; font-size: 1.2rem; font-weight: 600; }
        .progress-bar-container { background: #e0e0e0; height: 28px; border-radius: 14px; overflow: hidden; margin-bottom: 15px; position: relative; }
        .progress-bar-fill { background: linear-gradient(90deg, #198754, #20c997); height: 100%; width: 0%; transition: width 0.3s ease; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 0.95rem; }
        .progress-detail { margin: 0; text-align: center; color: #666; font-size: 0.9rem; }
        
        /* BADGES ADICIONALES */
        .offline-badge { background: #dc3545; color: white; padding: 2px 8px; border-radius: 10px; font-size: 0.7rem; margin-left: 5px; display: none; }
        .offline-badge.active { display: inline-block; }
        
        /* NUEVOS ESTILOS */
        .bg-dark-blue { background-color: #2c3e50 !important; }
        .btn-filter-active { background-color: #198754 !important; color: white !important; border-color: #198754 !important; }
    </style>

<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#2c3e50">
<link rel="apple-touch-icon" href="icon-192.png">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

<script>
    // Registro del Service Worker
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('service-worker.js')
                .then(reg => console.log('SW registrado: ', reg))
                .catch(err => console.log('SW error: ', err));
        });
    }
</script>


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
                    <i class="fas fa-user me-1"></i>
                    <span id="cashierName" style="font-weight: 600; font-size: 0.95rem;">Cajero</span>
                    <span id="cashStatusBadge" class="cash-status cash-closed ms-2" style="font-size: 0.7rem;">CERRADA</span>
                </div>
                
                <div class="d-flex align-items-center gap-1">
                    <button id="btnSync" onclick="syncOfflineQueue()" class="btn btn-sm btn-warning text-dark px-2 d-none" title="Sync">
                        <i class="fas fa-sync"></i>
                    </button>
                    <span id="netStatus" class="badge bg-success" style="font-size: 0.7rem;">
                        <i class="fas fa-wifi"></i>
                    </span>
                    <button onclick="checkCashRegister()" class="btn btn-sm btn-light text-primary px-2" title="Caja">
                        <i class="fas fa-cash-register"></i>
                    </button>
                    <button onclick="showParkedOrders()" class="btn btn-sm btn-light text-warning px-2" title="Pausados">
                        <i class="fas fa-pause"></i>
                    </button>
                    <a href="reportes_caja.php" class="btn btn-sm btn-light text-primary px-2" title="Reportes">
                        <i class="fas fa-chart-line"></i>
                    </a>
                </div>
            </div>
            
            <div class="d-flex align-items-center" style="gap: 8px;">
                <span class="badge bg-info" style="font-size: 0.75rem; padding: 4px 8px;">
                    <i class="fas fa-building"></i> Sucursal: <?php echo intval($config['id_sucursal'] ?? 1); ?>
                </span>
                <span class="badge bg-info" style="font-size: 0.75rem; padding: 4px 8px;">
                    <i class="fas fa-warehouse"></i> Almacén: <?php echo intval($config['id_almacen']); ?>
                </span>
                
                <!-- NUEVO: Indicador de velocidad -->
                <span id="connectionSpeedBadge" class="badge bg-secondary" style="font-size: 0.7rem; padding: 4px 8px;">
                    📡 Midiendo...
                </span>
                
                <!-- NUEVO: Contador de ventas offline -->
                <span id="offlineSalesBadge" class="offline-badge" title="Ventas pendientes de sincronizar">
                    0
                </span>
                
                <!-- NUEVO: Botón recarga manual -->
                <button onclick="reloadProducts()" class="btn btn-sm btn-light px-2" title="Recargar productos" style="font-size: 0.7rem;">
                    <i class="fas fa-sync-alt"></i>
                </button>
                
                <!-- NUEVO: Toggle imágenes -->
                <button onclick="toggleImageMode()" class="btn btn-sm btn-light px-2" title="Activar/Desactivar imágenes" style="font-size: 0.7rem;">
                    <i class="fas fa-image"></i>
                </button>
                
                <!-- NUEVO: Filtro de stock -->
                <button id="stockFilterBtn" onclick="toggleStockFilter()" class="btn btn-sm btn-light px-2" title="Filtrar productos" style="font-size: 0.7rem;">
                    <i class="fas fa-boxes"></i>
                </button>
            </div>
        </div>

        <div class="cart-list" id="cartContainer">
            <div class="text-center text-muted h-100 d-flex flex-column align-items-center justify-content-center">
                <img src="00LOGO OFICIAL.jpg" style="width: 120px; margin-bottom: 15px; opacity: 0.8;" alt="PalWeb">
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
            <button id="toggleKeypadBtn" class="btn btn-sm btn-outline-secondary mb-2" style="width:100%; display:none;" onclick="toggleMobileKeypad()">
                <i class="fas fa-keyboard"></i> Teclado
            </button>

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
                </div>
            </div>

            <button class="btn btn-primary btn-pay fw-bold shadow-sm" onclick="openPaymentModal()">
                <i class="fas fa-check-circle me-2"></i> COBRAR
            </button>
        </div>
    </div>

    <div class="right-panel">
        <div class="input-group mb-2 shadow-sm" style="flex-shrink:0;">
            <button id="btnRefresh" onclick="refreshProducts()" class="btn btn-primary border-0"><i class="fas fa-sync-alt"></i></button>
            
            <button id="btnStockFilter" class="btn btn-light border-0" onclick="toggleStockFilter()" title="Solo con existencias">
                <i class="fas fa-cubes"></i>
            </button>

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
<div class="modal fade" id="cashModal" tabindex="-1"><div class="modal-dialog modal-sm"><div class="modal-content"><div class="modal-header bg-dark text-white"><h5 class="modal-title">Caja</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body" id="cashModalBody"></div></div></div></div>
<div class="modal fade" id="parkModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header bg-warning"><h5 class="modal-title">Espera</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body p-0"><div class="list-group list-group-flush" id="parkList"></div></div></div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // INYECCIÓN DE DATOS
    const PRODUCTS_DATA = <?php echo json_encode($prods); ?>;
</script>
<script src="pos.js"></script>

<script>
    // 1. Estado del filtro de stock
    window.stockFilterActive = false;

    // 2. Función para alternar el filtro
    window.toggleStockFilter = function() {
        window.stockFilterActive = !window.stockFilterActive;
        const btn = document.getElementById('btnStockFilter');
        
        if (window.stockFilterActive) {
            btn.classList.add('btn-filter-active');
        } else {
            btn.classList.remove('btn-filter-active');
        }
        
        // Re-renderizar productos con el nuevo filtro
        window.renderProducts();
    };

    // 3. Sobrescribir renderProducts para incluir la lógica de stock y el logo en vacío
    // Nota: Esto asume que pos.js define renderProducts. Lo sobrescribimos para agregar la funcionalidad.
    // Si pos.js usa una estructura diferente, esto adapta la lógica estándar.
    const originalRenderProducts = window.renderProducts;

    window.renderProducts = function(category) {
        // Si no se pasa categoría, intenta mantener la actual (lógica interna) o 'all'
        if (typeof category === 'undefined') {
            const activeBtn = document.querySelector('.category-btn.active');
            category = activeBtn ? (activeBtn.innerText === 'TODOS' ? 'all' : activeBtn.innerText) : 'all';
        }

        const grid = document.getElementById('productContainer');
        if (!grid) return;
        
        grid.innerHTML = '';
        
        const searchInput = document.getElementById('searchInput');
        const term = searchInput ? searchInput.value.toLowerCase() : '';

        // Usar productsDB o PRODUCTS_DATA, lo que esté disponible
        const sourceData = window.productsDB || window.PRODUCTS_DATA || [];
        
        if (!Array.isArray(sourceData) || sourceData.length === 0) {
            grid.innerHTML = '<div class="text-center text-muted p-4"><i class="fas fa-box-open fa-3x mb-2"></i><p>Cargando productos...</p></div>';
            return;
        }

        // Filtrado
        let filtered = sourceData.filter(p => {
            const matchCat = category === 'all' || p.categoria === category;
            const matchSearch = !term || p.nombre.toLowerCase().includes(term) || p.codigo.toLowerCase().includes(term);
            return matchCat && matchSearch;
        });
        
        // Aplicar filtro de stock si la función existe
        if (typeof window.shouldShowProduct === 'function') {
            filtered = filtered.filter(p => window.shouldShowProduct(p));
        }

        // Renderizado
        filtered.forEach(p => {
            const stock = parseFloat(p.stock) || 0;
            const hasStock = stock > 0 || p.es_servicio == 1;
            
            const card = document.createElement('div');
            card.className = 'product-card';
            if (!hasStock) card.classList.add('stock-zero-card');
            
            // Badge de Stock
            let badgeClass = hasStock ? 'stock-ok' : 'stock-zero';
            let stockDisplay = p.es_servicio == 1 ? '∞' : stock;
            let badgeHtml = `<div class="stock-badge ${badgeClass}">${stockDisplay}</div>`;

            // Imagen
            let imgHTML = `<div class="product-img-container" style="background:${p.color}">
                               <span class="placeholder-text">${p.nombre.substring(0,2).toUpperCase()}</span>
                           </div>`;
            if(p.has_image) {
                imgHTML = `<div class="product-img-container"><img src="image.php?code=${p.codigo}" class="product-img" onerror="this.style.display='none'"></div>`;
            }

            card.innerHTML = `
                ${badgeHtml}
                ${imgHTML}
                <div class="product-info">
                    <div class="product-name text-dark">${p.nombre}</div>
                    <div class="product-price">$${parseFloat(p.precio).toFixed(2)}</div>
                </div>
            `;
            
            // Evento click - Solo para productos con stock o servicios
            if (hasStock) {
                card.onclick = () => {
                    if (typeof window.addToCart === 'function') {
                        window.addToCart(p);  // Pasar el objeto completo
                    }
                };
                card.style.cursor = 'pointer';
            } else {
                card.style.cursor = 'not-allowed';
            }
            
            grid.appendChild(card);
        });
        
        // Si no hay productos después de filtrar
        if (filtered.length === 0) {
            grid.innerHTML = '<div class="text-center text-muted p-4"><i class="fas fa-search fa-3x mb-2"></i><p>No se encontraron productos</p></div>';
        }
    };

    // 4. NO sobrescribir renderCart - dejarlo funcionar normalmente
    // El renderCart de pos.js ya funciona correctamente
    // Solo agregamos un pequeño ajuste si es necesario después de que pos.js cargue
    
    // Esperar a que pos.js defina renderCart
    setTimeout(() => {
        if (typeof window.renderCart === 'function') {
            const originalRenderCart = window.renderCart;
            
            window.renderCart = function() {
                // Siempre llamar a la función original
                originalRenderCart();
            };
        }
    }, 500);

</script>

<!-- BARRA DE PROGRESO -->
<div id="progressOverlay">
    <div class="progress-card">
        <h4 id="progressMessage" class="progress-title">Cargando...</h4>
        <div class="progress-bar-container">
            <div id="progressBar" class="progress-bar-fill">0%</div>
        </div>
        <p id="progressDetail" class="progress-detail"></p>
    </div>
</div>

<script src="pos-offline-system.js"></script>
<script src="pos.js"></script>

<?php include_once 'menu_master.php'; ?>

</body>
</html>
