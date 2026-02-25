<?php
// ARCHIVO: /var/www/palweb/api/pos.php

// ── Cabeceras de Seguridad HTTP ──────────────────────────────────────────────
// pos.php usa autenticación por PIN (sin sesión PHP), por tanto no se
// configura session_set_cookie_params aquí.
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://www.googletagmanager.com https://www.google-analytics.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; media-src 'self' https://assets.mixkit.co; connect-src 'self' https://www.google-analytics.com https://analytics.google.com https://stats.g.doubleclick.net; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
header("Alt-Svc: h2=\":443\"; ma=86400");
header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
// ─────────────────────────────────────────────────────────────────────────────

ini_set('display_errors', 1);
require_once 'db.php';

// ---------------------------------------------------------
// API INTERNA: GESTIÓN DE CLIENTES (NUEVO)
// ---------------------------------------------------------
if (isset($_GET['api_client']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    
    try {
        if (empty($input['nombre'])) throw new Exception("El nombre es obligatorio");
        
        $stmt = $pdo->prepare("INSERT INTO clientes (nombre, telefono, direccion, nit_ci, activo) VALUES (?, ?, ?, ?, 1)");
        $stmt->execute([
            trim($input['nombre']), 
            $input['telefono'] ?? '', 
            $input['direccion'] ?? '', 
            $input['nit_ci'] ?? ''
        ]);
        
        $newId = $pdo->lastInsertId();
        
        echo json_encode([
            'status' => 'success', 
            'client' => [
                'id' => $newId,
                'nombre' => $input['nombre'],
                'telefono' => $input['telefono'] ?? '',
                'direccion' => $input['direccion'] ?? '',
                'nit_ci' => $input['nit_ci'] ?? ''
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

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
                    COALESCE(p.favorito,0) as favorito,
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
        $localPath = '/var/www/assets/product_images/';
        foreach ($prods as &$p) {
            $b = $localPath . $p['codigo'];
            $p['has_image'] = false; $p['img_version'] = 0;
            foreach (['.avif','.webp','.jpg'] as $_e) {
                if (file_exists($b.$_e)) { $p['has_image'] = true; $p['img_version'] = filemtime($b.$_e); break; }
            }
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

// ENDPOINT: Toggle favorito de producto
if (isset($_GET['toggle_fav']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $inp = json_decode(file_get_contents('php://input'), true);
    $codigo = trim($inp['codigo'] ?? '');
    if ($codigo) {
        $pdo->prepare("UPDATE productos SET favorito = 1 - favorito WHERE codigo = ?")->execute([$codigo]);
        $favStmt = $pdo->prepare("SELECT favorito FROM productos WHERE codigo = ?");
        $favStmt->execute([$codigo]);
        echo json_encode(['status' => 'success', 'favorito' => (int)$favStmt->fetchColumn()]);
    } else {
        echo json_encode(['status' => 'error']);
    }
    exit;
}

// ENDPOINT: Inventario desde POS — opera sobre el carrito completo
if (isset($_GET['inventario_api']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    ini_set('display_errors', 0);
    $input   = json_decode(file_get_contents('php://input'), true) ?: [];
    $accion  = $input['accion']  ?? '';
    $motivo  = trim($input['motivo']  ?? '');
    $usuario = trim($input['usuario'] ?? 'POS-Admin');
    $items   = $input['items']   ?? [];
    $fechaRaw = trim($input['fecha'] ?? '');
    $fecha   = ($fechaRaw && strlen($fechaRaw) >= 10) ? (substr($fechaRaw,0,10).' '.date('H:i:s')) : date('Y-m-d H:i:s');

    $cfgFile = 'pos.cfg';
    $cfg = ["id_almacen"=>1,"id_sucursal"=>1,"id_empresa"=>1];
    if (file_exists($cfgFile)) { $tmp = json_decode(file_get_contents($cfgFile),true); if($tmp) $cfg = array_merge($cfg,$tmp); }
    $ALM = intval($cfg['id_almacen']);
    $SUC = intval($cfg['id_sucursal']);
    $EMP = intval($cfg['id_empresa']);

    require_once 'db.php';
    require_once 'kardex_engine.php';

    // Consulta bulk de stock para el conteo visual (sin modificar datos)
    if ($accion === 'consultar_bulk') {
        $skus = $input['skus'] ?? [];
        $stocks = [];
        foreach ($skus as $s) {
            $s = trim($s);
            if (!$s) continue;
            $q = $pdo->prepare("SELECT cantidad FROM stock_almacen WHERE id_producto=? AND id_almacen=?");
            $q->execute([$s, $ALM]);
            $stocks[$s] = floatval($q->fetchColumn() ?: 0);
        }
        echo json_encode(['status'=>'success','stocks'=>$stocks]);
        exit;
    }

    if (empty($items)) { echo json_encode(['status'=>'error','msg'=>'Carrito vacío']); exit; }

    $ke = new KardexEngine($pdo);
    $results = [];
    $stocks_updated = [];

    try {
        // Crear tablas merma si hacen falta (solo una vez)
        if ($accion === 'merma') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS mermas_cabecera (id INT AUTO_INCREMENT PRIMARY KEY, usuario VARCHAR(100), motivo_general TEXT, total_costo_perdida DECIMAL(12,2) DEFAULT 0, estado VARCHAR(20) DEFAULT 'PROCESADA', id_sucursal INT DEFAULT 1, fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS mermas_detalle (id INT AUTO_INCREMENT PRIMARY KEY, id_merma INT, id_producto VARCHAR(50), cantidad DECIMAL(10,3), costo_al_momento DECIMAL(12,2), motivo_especifico TEXT) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $totalCosto = 0;
            foreach ($items as $it) {
                $p = $pdo->prepare("SELECT costo FROM productos WHERE codigo=? AND id_empresa=?");
                $p->execute([trim($it['sku']??''), $EMP]);
                $totalCosto += abs(floatval($it['cantidad']??0)) * floatval($p->fetchColumn()?:0);
            }
            $pdo->prepare("INSERT INTO mermas_cabecera (usuario,motivo_general,total_costo_perdida,id_sucursal,fecha_registro) VALUES(?,?,?,?,?)")->execute([$usuario,$motivo,$totalCosto,$SUC,$fecha]);
            $idMerma = $pdo->lastInsertId();
        }

        if ($accion === 'transferencia') {
            $destino = trim($input['destino'] ?? '');
            if (!$destino) { echo json_encode(['status'=>'error','msg'=>'Indica la sucursal destino']); exit; }
            $pdo->exec("CREATE TABLE IF NOT EXISTS transfer_pendiente (id INT AUTO_INCREMENT PRIMARY KEY, sku VARCHAR(50), cantidad DECIMAL(10,3), costo DECIMAL(12,2), sucursal_origen INT, destino_nombre VARCHAR(100), usuario VARCHAR(100), motivo TEXT, estado VARCHAR(20) DEFAULT 'PENDIENTE', fecha DATETIME DEFAULT CURRENT_TIMESTAMP) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        foreach ($items as $item) {
            $sku = trim($item['sku'] ?? '');
            $qty = floatval($item['cantidad'] ?? 0);
            if (!$sku || $qty <= 0) continue;

            $pq = $pdo->prepare("SELECT codigo, nombre, costo FROM productos WHERE codigo=? AND id_empresa=?");
            $pq->execute([$sku, $EMP]);
            $prod = $pq->fetch(PDO::FETCH_ASSOC);
            if (!$prod) { $results[] = "SKU no encontrado: $sku"; continue; }
            $costo = floatval($prod['costo']);

            if ($accion === 'entrada') {
                $ke->registrarMovimiento($sku,$ALM,$SUC,'ENTRADA',abs($qty),"ENTRADA POS: $motivo",$costo,$usuario,$fecha);
                $pdo->prepare("UPDATE stock_almacen SET cantidad=cantidad+? WHERE id_producto=? AND id_almacen=?")->execute([abs($qty),$sku,$ALM]);
                $results[] = "+".abs($qty)." ".$prod['nombre'];

            } elseif ($accion === 'merma') {
                $pdo->prepare("INSERT INTO mermas_detalle (id_merma,id_producto,cantidad,costo_al_momento,motivo_especifico) VALUES(?,?,?,?,?)")->execute([$idMerma,$sku,abs($qty),$costo,$motivo]);
                $ke->registrarMovimiento($sku,$ALM,$SUC,'MERMA',abs($qty),"MERMA #$idMerma POS",$costo,$usuario,$fecha);
                $pdo->prepare("UPDATE stock_almacen SET cantidad=cantidad-? WHERE id_producto=? AND id_almacen=?")->execute([abs($qty),$sku,$ALM]);
                $results[] = "-".abs($qty)." ".$prod['nombre'];

            } elseif ($accion === 'ajuste') {
                $ke->registrarMovimiento($sku,$ALM,$SUC,'AJUSTE',$qty,"AJUSTE POS: $motivo",$costo,$usuario,$fecha);
                $pdo->prepare("UPDATE stock_almacen SET cantidad=cantidad+? WHERE id_producto=? AND id_almacen=?")->execute([$qty,$sku,$ALM]);
                $results[] = ($qty>0?"+":"").$qty." ".$prod['nombre'];

            } elseif ($accion === 'conteo') {
                $sq = $pdo->prepare("SELECT cantidad FROM stock_almacen WHERE id_producto=? AND id_almacen=?");
                $sq->execute([$sku,$ALM]);
                $stockAnt = floatval($sq->fetchColumn()?:0);
                $delta = $qty - $stockAnt;
                $ke->registrarMovimiento($sku,$ALM,$SUC,'AJUSTE',$delta,"CONTEO FISICO POS: $stockAnt→$qty",$costo,$usuario,$fecha);
                $pdo->prepare("UPDATE stock_almacen SET cantidad=? WHERE id_producto=? AND id_almacen=?")->execute([$qty,$sku,$ALM]);
                $results[] = $prod['nombre'].": ".($delta>=0?"+$delta":"$delta");

            } elseif ($accion === 'transferencia') {
                $ke->registrarMovimiento($sku,$ALM,$SUC,'AJUSTE',-abs($qty),"TRANSFER→$destino POS: $motivo",$costo,$usuario,$fecha);
                $pdo->prepare("UPDATE stock_almacen SET cantidad=cantidad-? WHERE id_producto=? AND id_almacen=?")->execute([abs($qty),$sku,$ALM]);
                $pdo->prepare("INSERT INTO transfer_pendiente (sku,cantidad,costo,sucursal_origen,destino_nombre,usuario,motivo,fecha) VALUES(?,?,?,?,?,?,?,?)")->execute([$sku,abs($qty),$costo,$SUC,$destino,$usuario,$motivo,$fecha]);
                $results[] = "-".abs($qty)." ".$prod['nombre']." → $destino";
            }

            // Stock actualizado para caché JS
            $sq2 = $pdo->prepare("SELECT cantidad FROM stock_almacen WHERE id_producto=? AND id_almacen=?");
            $sq2->execute([$sku,$ALM]);
            $stocks_updated[] = ['sku'=>$sku, 'nuevo_stock'=>floatval($sq2->fetchColumn()?:0)];
        }

        $n = count($results);
        $preview = implode(', ', array_slice($results,0,3)) . ($n>3 ? '…':'');
        echo json_encode(['status'=>'success','msg'=>"$n items procesados: $preview",'stocks_updated'=>$stocks_updated]);

    } catch (Exception $e) {
        echo json_encode(['status'=>'error','msg'=>$e->getMessage()]);
    }
    exit;
}

// Config
$configFile = 'pos.cfg';
$config = [ "tienda_nombre" => "MI TIENDA", "cajeros" => [["nombre"=>"Admin", "pin"=>"0000"]], "id_almacen" => 1, "id_sucursal" => 1, "mostrar_materias_primas" => false, "mostrar_servicios" => true, "categorias_ocultas" => [] ];
if (file_exists($configFile)) {
    $loaded = json_decode(file_get_contents($configFile), true);
    if ($loaded) $config = array_merge($config, $loaded);
}

// Asegurar columna favorito en productos
try { $pdo->exec("ALTER TABLE productos ADD COLUMN favorito TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}

// Carga de Datos
$prods = [];
$clientsData = [];
$mensajeros = []; // Array para mensajeros

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
    $stmtCat = $pdo->prepare("SELECT DISTINCT p.categoria as nombre_categoria, c.color, c.emoji FROM productos p LEFT JOIN categorias c ON p.categoria = c.nombre WHERE $where ORDER BY p.categoria");
    $stmtCat->execute($params);
    $catsData = $stmtCat->fetchAll(PDO::FETCH_ASSOC);

    // Productos
    $sqlProd = "SELECT p.codigo as id, p.codigo, p.nombre, p.precio, p.categoria, p.es_elaborado, p.es_servicio,
                COALESCE(p.favorito,0) as favorito,
                (SELECT COALESCE(SUM(s.cantidad), 0)
                 FROM stock_almacen s
                 WHERE s.id_producto = p.codigo AND s.id_almacen = $almacenID) as stock
                FROM productos p
                WHERE $where
                ORDER BY p.nombre";
    
    $stmtProd = $pdo->prepare($sqlProd);
    $stmtProd->execute($params);
    $prods = $stmtProd->fetchAll(PDO::FETCH_ASSOC);

    // CARGA DE CLIENTES (NUEVO - YA ORDENADO)
    $stmtCli = $pdo->query("SELECT id, nombre, telefono, direccion, nit_ci FROM clientes WHERE activo = 1 ORDER BY nombre ASC");
    $clientsData = $stmtCli->fetchAll(PDO::FETCH_ASSOC);

    // CARGA DE MENSAJEROS (NUEVO - FILTRADO)
    try {
        $stmtMsj = $pdo->query("SELECT nombre FROM clientes WHERE activo = 1 AND es_mensajero = 1 ORDER BY nombre ASC");
        $mensajeros = $stmtMsj->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) { $mensajeros = []; } // Si falla (campo no existe aún) queda vacío

} catch (Exception $e) { 
    error_log("POS DB Error: " . $e->getMessage());
    $prods = [];
}

// Procesamiento visual
$localPath = '/var/www/assets/product_images/';
foreach ($prods as &$p) {
    $b = $localPath . $p['codigo'];
    $p['has_image'] = false; $p['img_version'] = 0;
    foreach (['.avif','.webp','.jpg'] as $_e) {
        if (file_exists($b.$_e)) { $p['has_image'] = true; $p['img_version'] = filemtime($b.$_e); break; }
    }
    $p['color'] = '#' . substr(dechex(crc32($p['nombre'])), 0, 6);
    $p['stock'] = floatval($p['stock']);
} unset($p);

// Query de más vendidos (para sugerencias cuando no hay favoritos)
$mostSoldCodes = [];
try {
    $stmtMs = $pdo->query(
        "SELECT codigo_producto FROM ventas_detalle
         GROUP BY codigo_producto ORDER BY SUM(cantidad) DESC LIMIT 10"
    );
    $mostSoldCodes = $stmtMs->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) { $mostSoldCodes = []; }
?>
<!DOCTYPE html>
<html lang="es">
<head>
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-ZM015S9N6M"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  gtag('config', 'G-ZM015S9N6M');
</script>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>POS | <?php echo htmlspecialchars($config['tienda_nombre']); ?></title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/all.min.css" rel="stylesheet">
    <style>
        html, body { height: 100%; margin: 0; padding: 0; overflow: hidden; background-color: #e9ecef; font-family: 'Segoe UI', sans-serif; touch-action: manipulation; user-select: none; }
        .pos-container { display: flex; height: 100vh; width: 100vw; overflow: hidden; }
        .left-panel { background: white; border-right: 1px solid #ddd; display: flex; flex-direction: column; z-index: 20; box-shadow: 2px 0 5px rgba(0,0,0,0.05); height: 100%; overflow-y: auto; }
        .top-bar { flex-shrink: 0; }
        .cart-list { flex-grow: 1; overflow-y: auto; background: #fff; border-bottom: 1px solid #eee; min-height: 150px; }
        .controls-wrapper { padding: 8px; padding-bottom: 60px; background: #fff; flex-shrink: 0; border-top: 1px solid #eee; }
        .right-panel { flex-grow: 1; display: flex; flex-direction: column; background: #e9ecef; padding: 10px; overflow: hidden; position: relative; }
        @media (orientation: landscape) { .pos-container { flex-direction: row; } .left-panel { width: 35%; min-width: 340px; max-width: 420px; } .right-panel { width: 65%; } #keypadContainer { display: grid !important; } #toggleKeypadBtn { display: none !important; } }
        @media (orientation: portrait) { .pos-container { flex-direction: column; } .left-panel { width: 100%; height: 45%; border-right: none; border-bottom: 2px solid #ccc; order: 1; } .right-panel { width: 100%; height: 55%; order: 2; padding: 5px; } #keypadContainer { display: none; } #toggleKeypadBtn { display: block; width: 100%; margin-bottom: 5px; } .controls-wrapper { padding-bottom: 10px; } }
        .cart-item { padding: 8px 12px; border-bottom: 1px solid #f0f0f0; cursor: pointer; position: relative; font-size: 0.95rem; }
        .cart-item.selected { background: #e8f0fe; border-left: 4px solid #0d6efd; }
        .cart-note { font-size: 0.75rem; color: #d63384; font-style: italic; display: block; }
        .discount-tag { font-size: 0.7rem; background: #dc3545; color: white; padding: 1px 4px; border-radius: 3px; margin-left: 5px; }
        .btn-ctrl {
            height: 48px; border-radius: 10px; font-weight: 700; font-size: 0.95rem;
            display: flex; align-items: center; justify-content: center; cursor: pointer;
            background: linear-gradient(175deg, #ffffff 0%, #e9ecef 100%);
            border: 1px solid #c0c8d0;
            color: #333;
            box-shadow: 0 2px 5px rgba(0,0,0,0.12), inset 0 1px 0 rgba(255,255,255,0.9);
            transition: transform 0.1s, box-shadow 0.1s, filter 0.1s;
            letter-spacing: 0.02em;
        }
        .btn-ctrl:hover  { transform: translateY(-1px); filter: brightness(1.06); box-shadow: 0 4px 10px rgba(0,0,0,0.18), inset 0 1px 0 rgba(255,255,255,0.9); }
        .btn-ctrl:active { transform: translateY(1px) scale(0.97); filter: brightness(0.92); box-shadow: 0 1px 3px rgba(0,0,0,0.15); }
        .btn-pay {
            height: 58px; font-size: 1.35rem; width: 100%; border-radius: 6px; cursor: pointer;
            background-color: #0d6efd;
            background-image: linear-gradient(0deg, #0936be, #1049f4);
            border: 1px solid #07288d;
            color: white;
            box-shadow: 0 1px 0 0 rgba(255,255,255,0.2) inset, 0 1px 3px 0 rgba(0,0,0,0.4);
            transition: box-shadow 0.15s, filter 0.15s, transform 0.1s;
        }
        .btn-pay:hover  { filter: brightness(1.08); box-shadow: 0 1px 0 0 rgba(255,255,255,0.2) inset, 0 3px 8px 0 rgba(0,0,0,0.35); }
        .btn-pay:active { filter: none; box-shadow: 0 2px 4px -2px rgba(0,0,0,0.2) inset, 0 1px 3px 0 rgba(0,0,0,0.12); transform: scale(0.98); }
        .keypad-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; margin-bottom: 8px; }
        .action-row { display: flex; gap: 6px; margin-bottom: 6px; }
        .c-yellow {
            background: linear-gradient(175deg, #ffe066 0%, #ffc107 60%, #e0a800 100%) !important;
            color: #212529 !important; border-color: #c79200 !important;
            box-shadow: 0 2px 8px rgba(255,193,7,0.5), inset 0 1px 0 rgba(255,255,255,0.4) !important;
        }
        .c-green {
            background: linear-gradient(175deg, #34c47a 0%, #198754 60%, #136b42 100%) !important;
            color: white !important; border-color: #0e5233 !important;
            box-shadow: 0 2px 8px rgba(25,135,84,0.5), inset 0 1px 0 rgba(255,255,255,0.2) !important;
        }
        .c-red {
            background: linear-gradient(175deg, #f06070 0%, #dc3545 60%, #b02a37 100%) !important;
            color: white !important; border-color: #8f2230 !important;
            box-shadow: 0 2px 8px rgba(220,53,69,0.5), inset 0 1px 0 rgba(255,255,255,0.2) !important;
        }
        .c-grey {
            background: linear-gradient(175deg, #909aa3 0%, #6c757d 60%, #545b62 100%) !important;
            color: white !important; border-color: #424950 !important;
            box-shadow: 0 2px 8px rgba(108,117,125,0.45), inset 0 1px 0 rgba(255,255,255,0.2) !important;
        }
        .c-blue {
            background: linear-gradient(175deg, #4d96ff 0%, #0d6efd 60%, #0a54d4 100%) !important;
            color: white !important; border-color: #0849a4 !important;
            box-shadow: 0 2px 8px rgba(13,110,253,0.5), inset 0 1px 0 rgba(255,255,255,0.2) !important;
        }
        .c-purple {
            background: linear-gradient(175deg, #9b6fe0 0%, #6f42c1 60%, #582fa0 100%) !important;
            color: white !important; border-color: #472690 !important;
            box-shadow: 0 2px 8px rgba(111,66,193,0.5), inset 0 1px 0 rgba(255,255,255,0.2) !important;
        }
        .c-orange {
            background: linear-gradient(175deg, #ff9f43 0%, #fd7e14 60%, #d96304 100%) !important;
            color: white !important; border-color: #b85300 !important;
            box-shadow: 0 2px 8px rgba(253,126,20,0.5), inset 0 1px 0 rgba(255,255,255,0.2) !important;
        }
        .c-teal {
            background: linear-gradient(175deg, #3dd6f5 0%, #0dcaf0 60%, #0aa8ca 100%) !important;
            color: #212529 !important; border-color: #0891ae !important;
            box-shadow: 0 2px 8px rgba(13,202,240,0.45), inset 0 1px 0 rgba(255,255,255,0.3) !important;
        }

        /* ================================================================
           ALPHA — action-row (botones - / + / Vaciar / trash)
           Estilo: 3D raised, sombra inferior gruesa, sin border visible
           Ref: loupbrun.ca/buttons — Alpha
        ================================================================ */
        .action-row .btn-ctrl {
            border-width: 0 !important;
            border-radius: 8px !important;
            position: relative !important; overflow: hidden !important;
            transition: box-shadow 0.2s, transform 0.2s !important;
            filter: none !important;
        }
        /* Glossy crystal — capa superior translúcida */
        .action-row .btn-ctrl::after {
            content: ""; display: block; position: absolute; top: 0; left: 0;
            width: 100%; height: 52%; pointer-events: none;
            background: linear-gradient(180deg, rgba(255,255,255,0.28) 0%, rgba(255,255,255,0.06) 60%, rgba(255,255,255,0) 100%);
            border-radius: 8px 8px 0 0;
        }
        /* Hover: glow gris difuso alrededor */
        .action-row .btn-ctrl:hover  { transform: none !important; filter: none !important; }
        .action-row .btn-ctrl.c-yellow:hover { box-shadow: 0 3px 0 0 #a07400, 0 4px 4px -1px rgba(0,0,0,0.4), 0 18px 32px -2px rgba(255,255,255,0.12) inset, 0 0 20px 6px rgba(200,200,200,0.32) !important; }
        .action-row .btn-ctrl.c-green:hover  { box-shadow: 0 3px 0 0 #0e5233, 0 4px 4px -1px rgba(0,0,0,0.4), 0 18px 32px -2px rgba(255,255,255,0.12) inset, 0 0 20px 6px rgba(200,200,200,0.32) !important; }
        .action-row .btn-ctrl.c-red:hover    { box-shadow: 0 3px 0 0 #8f2230, 0 4px 4px -1px rgba(0,0,0,0.4), 0 18px 32px -2px rgba(255,255,255,0.12) inset, 0 0 20px 6px rgba(200,200,200,0.32) !important; }
        .action-row .btn-ctrl.c-blue:hover   { box-shadow: 0 3px 0 0 #0849a4, 0 4px 4px -1px rgba(0,0,0,0.4), 0 18px 32px -2px rgba(255,255,255,0.12) inset, 0 0 20px 6px rgba(200,200,200,0.32) !important; }
        .action-row .btn-ctrl.c-orange:hover { box-shadow: 0 3px 0 0 #b85300, 0 4px 4px -1px rgba(0,0,0,0.4), 0 18px 32px -2px rgba(255,255,255,0.12) inset, 0 0 20px 6px rgba(200,200,200,0.32) !important; }
        .action-row .btn-ctrl:active { transform: translateY(3px) !important; filter: none !important; }

        .action-row .btn-ctrl.c-yellow {
            background: linear-gradient(-45deg, #ffc107, #ffe066) !important; color: #212529 !important;
            box-shadow: 0 3px 0 0 #a07400, 0 4px 4px -1px rgba(0,0,0,0.55), 0 4px 6px 1px rgba(0,0,0,0.28), 0 18px 32px -2px rgba(255,255,255,0.12) inset !important;
        }
        .action-row .btn-ctrl.c-yellow:active { box-shadow: 0 0 0 0 #7a5800, 0 1px 2px 1px rgba(0,0,0,0.5) inset, 0 -18px 32px -2px rgba(255,255,255,0.1) inset !important; color: #a07400 !important; }
        .action-row .btn-ctrl.c-green {
            background: linear-gradient(-45deg, #198754, #34c47a) !important; color: white !important;
            box-shadow: 0 3px 0 0 #0e5233, 0 4px 4px -1px rgba(0,0,0,0.55), 0 4px 6px 1px rgba(0,0,0,0.28), 0 18px 32px -2px rgba(255,255,255,0.12) inset !important;
        }
        .action-row .btn-ctrl.c-green:active  { box-shadow: 0 0 0 0 #07361f, 0 1px 2px 1px rgba(0,0,0,0.5) inset, 0 -18px 32px -2px rgba(255,255,255,0.1) inset !important; color: #0e5233 !important; }
        .action-row .btn-ctrl.c-red {
            background: linear-gradient(-45deg, #dc3545, #f06070) !important; color: white !important;
            box-shadow: 0 3px 0 0 #8f2230, 0 4px 4px -1px rgba(0,0,0,0.55), 0 4px 6px 1px rgba(0,0,0,0.28), 0 18px 32px -2px rgba(255,255,255,0.12) inset !important;
        }
        .action-row .btn-ctrl.c-red:active    { box-shadow: 0 0 0 0 #621520, 0 1px 2px 1px rgba(0,0,0,0.5) inset, 0 -18px 32px -2px rgba(255,255,255,0.1) inset !important; color: #8f2230 !important; }
        .action-row .btn-ctrl.c-blue {
            background: linear-gradient(-45deg, #0d6efd, #4d96ff) !important; color: white !important;
            box-shadow: 0 3px 0 0 #0849a4, 0 4px 4px -1px rgba(0,0,0,0.55), 0 4px 6px 1px rgba(0,0,0,0.28), 0 18px 32px -2px rgba(255,255,255,0.12) inset !important;
        }
        .action-row .btn-ctrl.c-blue:active   { box-shadow: 0 0 0 0 #052d6a, 0 1px 2px 1px rgba(0,0,0,0.5) inset, 0 -18px 32px -2px rgba(255,255,255,0.1) inset !important; color: #0849a4 !important; }
        .action-row .btn-ctrl.c-orange {
            background: linear-gradient(-45deg, #fd7e14, #ff9f43) !important; color: white !important;
            box-shadow: 0 3px 0 0 #b85300, 0 4px 4px -1px rgba(0,0,0,0.55), 0 4px 6px 1px rgba(0,0,0,0.28), 0 18px 32px -2px rgba(255,255,255,0.12) inset !important;
        }
        .action-row .btn-ctrl.c-orange:active { box-shadow: 0 0 0 0 #7a3600, 0 1px 2px 1px rgba(0,0,0,0.5) inset, 0 -18px 32px -2px rgba(255,255,255,0.1) inset !important; color: #b85300 !important; }

        /* ================================================================
           ETA — keypad-grid (Cnt / % Item / % Total / Nota / Pause / etc.)
           Estilo: vidrio profundo, brillo diagonal, borde doble inferior
           Ref: loupbrun.ca/buttons — Eta
        ================================================================ */
        .keypad-grid .btn-ctrl {
            border-width: 2px !important; border-style: solid !important; border-radius: 7px !important;
            position: relative !important; overflow: hidden !important;
            text-shadow: 0 -1px 0 rgba(0,0,0,0.2) !important;
            transition: box-shadow 0.4s cubic-bezier(0.23,1,0.32,1) !important;
            transform: none !important; filter: none !important;
        }
        .keypad-grid .btn-ctrl::after {
            content: ""; display: block; position: absolute; top: 0; left: 0;
            height: 100%; width: 100%; pointer-events: none;
            transform: rotate(-19deg) translateY(-1.3em) scale(1.05); filter: blur(1px);
            background-image: linear-gradient(-90deg, rgba(255,255,255,0.13) 20%, rgba(255,255,255,0));
        }
        .keypad-grid .btn-ctrl:hover  { transform: none !important; filter: none !important; }
        .keypad-grid .btn-ctrl:active { transform: none !important; filter: none !important; text-shadow: 0 1px 0 rgba(255,255,255,0.2) !important; transition-duration: 0.1s !important; }
        .keypad-grid .btn-ctrl:active::after { background-image: linear-gradient(-90deg, rgba(255,255,255,0.02) 20%, rgba(255,255,255,0)); }

        .keypad-grid .btn-ctrl.c-grey {
            background-color: #6c757d !important; color: white !important;
            border-color: #454d55 !important; border-bottom-color: #2e3338 !important;
            box-shadow: 0 1px 1px -1px rgba(255,255,255,.9) inset, 0 40px 20px -20px rgba(255,255,255,.15) inset, 0 -1px 1px -1px rgba(0,0,0,.7) inset, 0 -40px 20px -20px rgba(0,0,0,.06) inset, 0 9px 8px -4px rgba(0,0,0,.4), 0 2px 1px -1px rgba(0,0,0,.3), 7px 7px 8px -4px rgba(0,0,0,.1), -7px 7px 8px -4px rgba(0,0,0,.1), 0 -4px 12px 2px rgba(108,117,125,.2) !important;
        }
        .keypad-grid .btn-ctrl.c-grey:active   { background-color: #5f666d !important; color: #2e3338 !important; box-shadow: 0 -1px 1px -1px rgba(255,255,255,.4) inset, 0 -40px 20px -20px rgba(255,255,255,.1) inset, 0 1px 1px -1px rgba(0,0,0,.7) inset, 0 40px 20px -20px rgba(0,0,0,.06) inset, 0 7px 8px -4px rgba(0,0,0,.4), 7px 7px 8px -4px rgba(0,0,0,.05), -7px 7px 8px -4px rgba(0,0,0,.05), 0 -4px 12px 2px rgba(108,117,125,.1) !important; }
        .keypad-grid .btn-ctrl.c-purple {
            background-color: #6f42c1 !important; color: white !important;
            border-color: #472690 !important; border-bottom-color: #2e195c !important;
            box-shadow: 0 1px 1px -1px rgba(255,255,255,.9) inset, 0 40px 20px -20px rgba(255,255,255,.15) inset, 0 -1px 1px -1px rgba(0,0,0,.7) inset, 0 -40px 20px -20px rgba(0,0,0,.06) inset, 0 9px 8px -4px rgba(0,0,0,.4), 0 2px 1px -1px rgba(0,0,0,.3), 7px 7px 8px -4px rgba(0,0,0,.1), -7px 7px 8px -4px rgba(0,0,0,.1), 0 -4px 12px 2px rgba(111,66,193,.2) !important;
        }
        .keypad-grid .btn-ctrl.c-purple:active { background-color: #6238ae !important; color: #2e195c !important; box-shadow: 0 -1px 1px -1px rgba(255,255,255,.4) inset, 0 -40px 20px -20px rgba(255,255,255,.1) inset, 0 1px 1px -1px rgba(0,0,0,.7) inset, 0 40px 20px -20px rgba(0,0,0,.06) inset, 0 7px 8px -4px rgba(0,0,0,.4), 7px 7px 8px -4px rgba(0,0,0,.05), -7px 7px 8px -4px rgba(0,0,0,.05), 0 -4px 12px 2px rgba(111,66,193,.1) !important; }
        .keypad-grid .btn-ctrl.c-blue {
            background-color: #0d6efd !important; color: white !important;
            border-color: #0849a4 !important; border-bottom-color: #052d6a !important;
            box-shadow: 0 1px 1px -1px rgba(255,255,255,.9) inset, 0 40px 20px -20px rgba(255,255,255,.15) inset, 0 -1px 1px -1px rgba(0,0,0,.7) inset, 0 -40px 20px -20px rgba(0,0,0,.06) inset, 0 9px 8px -4px rgba(0,0,0,.4), 0 2px 1px -1px rgba(0,0,0,.3), 7px 7px 8px -4px rgba(0,0,0,.1), -7px 7px 8px -4px rgba(0,0,0,.1), 0 -4px 12px 2px rgba(13,110,253,.2) !important;
        }
        .keypad-grid .btn-ctrl.c-blue:active   { background-color: #0c63e4 !important; color: #052d6a !important; box-shadow: 0 -1px 1px -1px rgba(255,255,255,.4) inset, 0 -40px 20px -20px rgba(255,255,255,.1) inset, 0 1px 1px -1px rgba(0,0,0,.7) inset, 0 40px 20px -20px rgba(0,0,0,.06) inset, 0 7px 8px -4px rgba(0,0,0,.4), 7px 7px 8px -4px rgba(0,0,0,.05), -7px 7px 8px -4px rgba(0,0,0,.05), 0 -4px 12px 2px rgba(13,110,253,.1) !important; }
        .keypad-grid .btn-ctrl.c-red {
            background-color: #dc3545 !important; color: white !important;
            border-color: #8f2230 !important; border-bottom-color: #621520 !important;
            box-shadow: 0 1px 1px -1px rgba(255,255,255,.9) inset, 0 40px 20px -20px rgba(255,255,255,.15) inset, 0 -1px 1px -1px rgba(0,0,0,.7) inset, 0 -40px 20px -20px rgba(0,0,0,.06) inset, 0 9px 8px -4px rgba(0,0,0,.4), 0 2px 1px -1px rgba(0,0,0,.3), 7px 7px 8px -4px rgba(0,0,0,.1), -7px 7px 8px -4px rgba(0,0,0,.1), 0 -4px 12px 2px rgba(220,53,69,.2) !important;
        }
        .keypad-grid .btn-ctrl.c-red:active    { background-color: #c22d3d !important; color: #8f2230 !important; box-shadow: 0 -1px 1px -1px rgba(255,255,255,.4) inset, 0 -40px 20px -20px rgba(255,255,255,.1) inset, 0 1px 1px -1px rgba(0,0,0,.7) inset, 0 40px 20px -20px rgba(0,0,0,.06) inset, 0 7px 8px -4px rgba(0,0,0,.4), 7px 7px 8px -4px rgba(0,0,0,.05), -7px 7px 8px -4px rgba(0,0,0,.05), 0 -4px 12px 2px rgba(220,53,69,.1) !important; }
        .keypad-grid .btn-ctrl.c-teal {
            background-color: #0dcaf0 !important; color: #212529 !important;
            border-color: #0891ae !important; border-bottom-color: #055e73 !important;
            box-shadow: 0 1px 1px -1px rgba(255,255,255,.9) inset, 0 40px 20px -20px rgba(255,255,255,.15) inset, 0 -1px 1px -1px rgba(0,0,0,.7) inset, 0 -40px 20px -20px rgba(0,0,0,.06) inset, 0 9px 8px -4px rgba(0,0,0,.4), 0 2px 1px -1px rgba(0,0,0,.3), 7px 7px 8px -4px rgba(0,0,0,.1), -7px 7px 8px -4px rgba(0,0,0,.1), 0 -4px 12px 2px rgba(13,202,240,.2) !important;
        }
        .keypad-grid .btn-ctrl.c-teal:active   { background-color: #0bb5d7 !important; color: #055e73 !important; box-shadow: 0 -1px 1px -1px rgba(255,255,255,.4) inset, 0 -40px 20px -20px rgba(255,255,255,.1) inset, 0 1px 1px -1px rgba(0,0,0,.7) inset, 0 40px 20px -20px rgba(0,0,0,.06) inset, 0 7px 8px -4px rgba(0,0,0,.4), 7px 7px 8px -4px rgba(0,0,0,.05), -7px 7px 8px -4px rgba(0,0,0,.05), 0 -4px 12px 2px rgba(13,202,240,.1) !important; }
        .keypad-grid .btn-ctrl.c-orange {
            background-color: #fd7e14 !important; color: white !important;
            border-color: #b85300 !important; border-bottom-color: #7a3600 !important;
            box-shadow: 0 1px 1px -1px rgba(255,255,255,.9) inset, 0 40px 20px -20px rgba(255,255,255,.15) inset, 0 -1px 1px -1px rgba(0,0,0,.7) inset, 0 -40px 20px -20px rgba(0,0,0,.06) inset, 0 9px 8px -4px rgba(0,0,0,.4), 0 2px 1px -1px rgba(0,0,0,.3), 7px 7px 8px -4px rgba(0,0,0,.1), -7px 7px 8px -4px rgba(0,0,0,.1), 0 -4px 12px 2px rgba(253,126,20,.2) !important;
        }
        .keypad-grid .btn-ctrl.c-orange:active { background-color: #e2720f !important; color: #7a3600 !important; box-shadow: 0 -1px 1px -1px rgba(255,255,255,.4) inset, 0 -40px 20px -20px rgba(255,255,255,.1) inset, 0 1px 1px -1px rgba(0,0,0,.7) inset, 0 40px 20px -20px rgba(0,0,0,.06) inset, 0 7px 8px -4px rgba(0,0,0,.4), 7px 7px 8px -4px rgba(0,0,0,.05), -7px 7px 8px -4px rgba(0,0,0,.05), 0 -4px 12px 2px rgba(253,126,20,.1) !important; }

        /* ================================================================
           ZETA — inv-btn (panel de inventario)
           Estilo: minimalista, gradiente fino 0deg, sombra plana, sin radius
           Ref: loupbrun.ca/buttons — Zeta
        ================================================================ */
        .inv-btn {
            border-width: 1px !important; border-style: solid !important;
            border-radius: 5px !important;
            box-shadow: 0 1px 0 0 rgba(255,255,255,0.2) inset, 0 1px 3px 0 rgba(0,0,0,0.45) !important;
            transition: filter 0.15s, transform 0.1s !important;
        }
        .inv-btn:hover  { filter: brightness(1.1) !important; transform: translateY(-1px) !important; box-shadow: 0 1px 0 0 rgba(255,255,255,0.2) inset, 0 3px 6px 0 rgba(0,0,0,0.35) !important; }
        .inv-btn:active { transform: scale(0.97) !important; filter: none !important; box-shadow: 0 2px 4px -2px rgba(0,0,0,0.2) inset, 0 1px 3px 0 rgba(0,0,0,0.12) !important; }
        .top-bar .inv-btn { height: auto !important; padding: 2px 6px !important; flex-direction: row !important; border-radius: 5px !important; font-size: 0.8rem !important; gap: 0 !important; }
        .top-bar .inv-btn i { font-size: 0.8rem !important; }
        .category-bar { display: flex; overflow-x: auto; overflow-y: hidden; gap: 8px; margin-bottom: 8px; padding-bottom: 5px; scrollbar-width: none; height: 52px; align-items: center; flex-shrink: 0; }
        body.pos-bars-hidden .category-bar,
        body.pos-bars-hidden #favoritesBar { display: none !important; }
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

        /* FIX: BOTÓN SYNC VISIBLE */
        #btnSync { z-index: 100; position: relative; margin-right: 5px; box-shadow: 0 2px 4px rgba(0,0,0,0.2); }

        /* Reloj digital 7 segmentos */
        #posClock {
            font-family: 'Courier New', 'Lucida Console', monospace;
            font-size: 1.3rem;
            font-weight: bold;
            letter-spacing: 0.12em;
            background: #0a100a;
            color: #00e676;
            text-shadow: 0 0 6px #00e676, 0 0 14px rgba(0,230,118,0.45);
            padding: 0 14px;
            border-radius: 8px;
            white-space: nowrap;
            flex-shrink: 0;
            min-width: 108px;
            text-align: center;
            border: 1px solid #143314;
            box-shadow: inset 0 2px 5px rgba(0,0,0,0.7), 0 1px 3px rgba(0,0,0,0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            user-select: none;
        }
        #posClock.blink-colon .clock-sep { opacity: 0.2; }

        /* Panel de atajos de teclado */
        #hotkeyPanel { position: absolute; top: 50px; right: 10px; z-index: 1000;
            background: white; border-radius: 10px; padding: 12px 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            min-width: 280px; font-size: 0.8rem; display: none; }
        #hotkeyPanel.show { display: block; }
        #hotkeyPanel kbd { background: #2c3e50; color: white; border: 1px solid #1a252f;
            border-radius: 4px; padding: 2px 6px; font-size: 0.75rem; }

        /* Barra de favoritos */
        .fav-card { flex-shrink: 0; width: 88px; min-height: 54px;
            background: white; border-radius: 8px; cursor: pointer;
            box-shadow: 0 1px 4px rgba(0,0,0,0.1); padding: 5px 6px;
            border: 2px solid transparent; transition: border-color 0.15s, transform 0.1s;
            display: flex; flex-direction: column; justify-content: center; }
        .fav-card:hover { border-color: #ffc107; transform: translateY(-1px); }
        .fav-card .fav-name { font-size: 0.68rem; font-weight: 600; line-height: 1.2;
            overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2;
            -webkit-box-orient: vertical; color: #333; }
        .fav-card .fav-price { font-size: 0.75rem; color: #0d6efd; font-weight: 800; margin-top: 2px; }
        .fav-card.out-of-stock { opacity: 0.5; }

        /* Estrella ★ en card principal */
        .star-btn { position: absolute; top: 5px; left: 5px; z-index: 11;
            background: rgba(255,255,255,0.88); border: none; border-radius: 50%;
            width: 22px; height: 22px; display: flex; align-items: center; justify-content: center;
            font-size: 0.85rem; cursor: pointer; padding: 0; line-height: 1;
            transition: transform 0.1s; }
        .star-btn:hover { transform: scale(1.25); }
        .star-btn.active { color: #ffc107; }
        .star-btn.inactive { color: #bbb; }
        
        /* Panel de inventario */
        #inventarioPanel { display: none; }
        .stock-inv { background: #6f42c1 !important; color: #fff !important; }  /* badge morado en modo inv para stock=0 */
        .inv-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; margin-bottom: 8px; }
        .inv-btn {
            height: 68px; border-radius: 12px; font-weight: 700; font-size: 0.78rem;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 4px; cursor: pointer;
            border: 1px solid rgba(0,0,0,0.2);
            box-shadow: 0 3px 10px rgba(0,0,0,0.2), inset 0 1px 0 rgba(255,255,255,0.2);
            transition: filter 0.15s, transform 0.12s, box-shadow 0.12s;
            letter-spacing: 0.02em;
        }
        .inv-btn:hover  { filter: brightness(1.1); transform: translateY(-1px); box-shadow: 0 5px 14px rgba(0,0,0,0.28), inset 0 1px 0 rgba(255,255,255,0.2); }
        .inv-btn:active { transform: scale(0.95) translateY(1px); filter: brightness(0.88); box-shadow: 0 1px 4px rgba(0,0,0,0.2); }
        .inv-btn i { font-size: 1.3rem; }
        #btnInventario.inv-active { background: #2c3e50 !important; color: #ffc107 !important;
                                    border-color: #ffc107 !important; }

        /* Estilos adicionales para modal de pago grande */
        .total-display-large { font-size: 3rem; font-weight: 800; color: #198754; text-shadow: 1px 1px 0px #fff; }
        .bg-primary-custom { background-color: #2c3e50 !important; }
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

<script>
// Stub temporal: evita ReferenceError si el usuario toca el PIN antes de que pos1.js cargue.
// function typePin() en pos1.js sobreescribe window.typePin automáticamente al cargar.
window._pinStub = '';
window.typePin = function(v) {
    if (v === 'C') window._pinStub = '';
    else if (window._pinStub.length < 4) window._pinStub += String(v);
    var d = document.getElementById('pinDisplay');
    if (d) d.innerText = '\u2022'.repeat(window._pinStub.length);
};
window.verifyPin = function() { /* se activa tras cargar pos1.js */ };
</script>

<div id="pinOverlay">
    <div class="pin-box">
        <h3 class="mb-2">Acceso POS</h3>
        <div class="fs-1 mb-2" id="pinDisplay">••••</div>
        <div id="pinAttemptsDots" class="mb-2 text-muted small" style="min-height:1.2em;"></div>
        <div id="pinLockMsg" class="alert alert-danger py-1 mb-2 d-none small"></div>
        <div class="pin-grid" id="pinGrid">
            <button class="pin-btn" onclick="typePin(1)">1</button><button class="pin-btn" onclick="typePin(2)">2</button><button class="pin-btn" onclick="typePin(3)">3</button>
            <button class="pin-btn" onclick="typePin(4)">4</button><button class="pin-btn" onclick="typePin(5)">5</button><button class="pin-btn" onclick="typePin(6)">6</button>
            <button class="pin-btn" onclick="typePin(7)">7</button><button class="pin-btn" onclick="typePin(8)">8</button><button class="pin-btn" onclick="typePin(9)">9</button>
            <button class="pin-btn c-red" onclick="typePin('C')">C</button><button class="pin-btn" onclick="typePin(0)">0</button><button class="pin-btn c-green" onclick="verifyPin()">OK</button>
        </div>
    </div>
</div>

<div class="pos-container">
    <div class="left-panel">
        <div class="px-2 pt-2 pb-1 bg-dark-blue text-white top-bar shadow-sm" style="display:flex; flex-direction:column; gap:6px;">
            <!-- Fila 1: Cajero + Estado de conexión -->
            <div class="d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-1">
                    <i class="fas fa-user" style="font-size:0.8rem; opacity:0.75;"></i>
                    <span id="cashierName" style="font-weight:700; font-size:0.9rem; line-height:1;">Cajero</span>
                    <span id="cashStatusBadge" class="cash-status cash-closed">CERRADA</span>
                </div>
                <div class="d-flex align-items-center gap-1">
                    <button id="btnSync" onclick="syncOfflineQueue()" class="btn btn-sm btn-warning text-dark px-2 d-none" title="Sincronizar cola offline">
                        <i class="fas fa-sync"></i>
                    </button>
                    <span id="netStatus" class="badge bg-success px-2" style="font-size:0.7rem;">
                        <i class="fas fa-wifi"></i>
                    </span>
                </div>
            </div>
            <!-- Fila 2: Sucursal/Almacén + Botones de acción -->
            <div class="d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-1">
                    <span class="badge bg-secondary" style="font-size:0.68rem; padding:3px 6px;" title="Sucursal">
                        <i class="fas fa-building"></i> <?php echo intval($config['id_sucursal'] ?? 1); ?>
                    </span>
                    <span class="badge bg-secondary" style="font-size:0.68rem; padding:3px 6px;" title="Almacén">
                        <i class="fas fa-warehouse"></i> <?php echo intval($config['id_almacen']); ?>
                    </span>
                </div>
                <div class="d-flex align-items-center gap-1">
                    <a href="customer_display.php" target="_blank" class="btn btn-sm btn-outline-info px-2 inv-btn" title="Pantalla del cliente">
                        <i class="fas fa-desktop"></i>
                    </a>
                    <button class="btn btn-sm btn-outline-warning position-relative px-2 animate__animated inv-btn" id="btnSelfOrders" onclick="openSelfOrdersModal()" title="Autopedidos">
                        <i class="fas fa-mobile-alt"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none" id="selfOrderBadge" style="font-size:0.6rem;">0</span>
                    </button>
                    <button id="btnCaja" onclick="checkCashRegister()" class="btn btn-sm btn-light text-primary px-2 inv-btn" title="Caja">
                        <i class="fas fa-cash-register"></i>
                    </button>
                    <button onclick="showParkedOrders()" class="btn btn-sm btn-light text-warning px-2 inv-btn" title="Pausados">
                        <i class="fas fa-pause"></i>
                    </button>
                    <a href="reportes_caja.php" class="btn btn-sm btn-light text-primary px-2 inv-btn" title="Reportes">
                        <i class="fas fa-chart-line"></i>
                    </a>
                </div>
            </div>
        </div>

        <!-- Banner visible solo en modo inventario -->
        <div id="invModeBanner" style="display:none;background:#2c3e50;color:#ffc107;padding:7px 12px;font-weight:700;font-size:0.82rem;text-align:center;letter-spacing:0.04em;">
            <i class="fas fa-boxes me-1"></i> MODO INVENTARIO — Agrega los productos con sus cantidades y usa los botones de abajo
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

            <!-- Botón toggle inventario — solo admin -->
            <button id="btnInventario" class="btn btn-sm btn-outline-warning w-100 mb-2 fw-bold" style="display:none;" onclick="toggleInventarioMode()">
                <i class="fas fa-boxes me-1"></i> INVENTARIO
            </button>

            <!-- Panel POS clásico -->
            <div id="posPanel">
                <div id="keypadContainer">
                    <div class="action-row">
                        <button class="btn-ctrl c-yellow flex-grow-1" onclick="modifyQty(-1)"><i class="fas fa-minus"></i></button>
                        <button class="btn-ctrl c-green flex-grow-1" onclick="modifyQty(1)"><i class="fas fa-plus"></i></button>
                        <button class="btn-ctrl c-red flex-grow-1" onclick="removeItem()"><i class="fas fa-trash"></i></button>
                    </div>
                    <div class="keypad-grid">
                        <button class="btn-ctrl c-grey" onclick="askQty()">Cnt</button>
                        <button class="btn-ctrl c-purple" onclick="applyDiscount()">% Item</button>
                        <button id="btnGlobalDiscount" class="btn-ctrl c-purple" onclick="applyGlobalDiscount()">% Total</button>
                        <button class="btn-ctrl c-blue" onclick="addNote()"><i class="fas fa-pen"></i></button>
                        <button class="btn-ctrl c-orange" onclick="parkOrder()"><i class="fas fa-pause"></i></button>
                        <button class="btn-ctrl c-red" onclick="clearCart()">Vaciar</button>
                        <button class="btn-ctrl c-teal" onclick="showHistorialModal()"><i class="fas fa-history"></i> HIST</button>
                        <button id="btnSyncKeypad" class="btn-ctrl c-orange" style="opacity:0.4;" onclick="syncManual()" disabled><i class="fas fa-cloud-upload-alt"></i> 0</button>
                    </div>
                </div>
                <button class="btn btn-primary btn-pay fw-bold shadow-sm" onclick="openPaymentModal()">
                    <i class="fas fa-check-circle me-2"></i> COBRAR
                </button>
            </div>

            <!-- Panel de inventario -->
            <div id="inventarioPanel">
                <div class="action-row mb-2">
                    <button class="btn-ctrl c-yellow flex-grow-1" onclick="modifyQty(-1)" title="Restar cantidad"><i class="fas fa-minus"></i></button>
                    <button class="btn-ctrl c-green flex-grow-1" onclick="modifyQty(1)" title="Sumar cantidad"><i class="fas fa-plus"></i></button>
                    <button class="btn-ctrl c-red flex-grow-1" onclick="clearCart()" title="Vaciar carrito">Vaciar</button>
                </div>
                <div class="inv-grid">
                    <button class="inv-btn" style="background:linear-gradient(175deg,#34c47a 0%,#198754 60%,#136b42 100%);color:white;" onclick="openInvModal('entrada')">
                        <i class="fas fa-truck-loading"></i> Entrada
                    </button>
                    <button class="inv-btn" style="background:linear-gradient(175deg,#f06070 0%,#dc3545 60%,#b02a37 100%);color:white;" onclick="openInvModal('merma')">
                        <i class="fas fa-trash-alt"></i> Merma
                    </button>
                    <button class="inv-btn" style="background:linear-gradient(175deg,#ffe066 0%,#ffc107 60%,#e0a800 100%);color:#212529;" onclick="openInvModal('ajuste')">
                        <i class="fas fa-sliders-h"></i> Ajuste
                    </button>
                    <button class="inv-btn" style="background:linear-gradient(175deg,#3dd6f5 0%,#0dcaf0 60%,#0aa8ca 100%);color:#212529;" onclick="openInvModal('conteo')">
                        <i class="fas fa-barcode"></i> Conteo
                    </button>
                    <button class="inv-btn" style="background:linear-gradient(175deg,#4d96ff 0%,#0d6efd 60%,#0a54d4 100%);color:white;" onclick="openInvModal('transferencia')">
                        <i class="fas fa-exchange-alt"></i> Transferir
                    </button>
                    <button class="inv-btn" style="background:linear-gradient(175deg,#909aa3 0%,#6c757d 60%,#545b62 100%);color:white;" onclick="openInvModal('consultar')">
                        <i class="fas fa-search"></i> Consultar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="right-panel">
        <div class="d-flex align-items-stretch gap-2 mb-2" style="flex-shrink:0;">
            <div class="input-group shadow-sm" style="flex:1;">
                <button id="btnRefresh" onclick="refreshProducts()" class="btn btn-primary border-0"><i class="fas fa-sync-alt"></i></button>

                <button id="btnForceDownload" onclick="forceDownloadProducts()" class="btn btn-danger border-0" title="Forzar descarga del servidor">
                    <i class="fas fa-cloud-download-alt"></i>
                </button>

                <button id="btnStockFilter" class="btn btn-light border-0" onclick="toggleStockFilter()" title="Solo con existencias">
                    <i class="fas fa-cubes"></i>
                </button>

                <button id="btnToggleImages" class="btn btn-light border-0" onclick="toggleImages()" title="Mostrar/Ocultar imagenes">
                    <i class="fas fa-image"></i>
                </button>

                <button id="btnToggleBars" class="btn btn-light border-0" onclick="toggleBars()" title="Mostrar/Ocultar barras de categorías y favoritos">
                    <i class="fas fa-bars"></i>
                </button>

                <span class="input-group-text bg-white border-0"><i class="fas fa-search"></i></span>
                <input type="text" id="searchInput" class="form-control border-0" placeholder="Buscar / Escanear..." onkeyup="filterProducts()" autocomplete="off" autocorrect="off" spellcheck="false">
                <button class="btn btn-light border-0" onclick="document.getElementById('searchInput').value='';filterProducts()">X</button>
                <button id="btnHotkeyHelp" class="btn btn-light border-0" onclick="toggleHotkeyPanel()" title="Atajos de teclado">⌨</button>
            </div>
            <div id="posClock"><span class="clock-h">12</span><span class="clock-sep">:</span><span class="clock-m">00</span><span class="clock-ampm" style="font-size:0.7rem; letter-spacing:0; margin-left:3px; opacity:0.85;">AM</span></div>
        </div>
        <div id="hotkeyPanel">
            <div class="fw-bold mb-2 text-secondary" style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em;">Atajos de Teclado</div>
            <div class="row g-1">
                <div class="col-6"><kbd>F1</kbd> Abrir/Cerrar Caja</div>
                <div class="col-6"><kbd>F2</kbd> Descuento Global</div>
                <div class="col-6"><kbd>F3</kbd> Autopedidos</div>
                <div class="col-6"><kbd>F4</kbd> Pausar Pedido</div>
                <div class="col-6"><kbd>F5</kbd> Ir a Búsqueda</div>
                <div class="col-6"><kbd>F6</kbd> Historial Tickets</div>
                <div class="col-6"><kbd>F7</kbd> Nuevo Cliente</div>
                <div class="col-6"><kbd>F8</kbd> Forzar Descarga</div>
                <div class="col-6"><kbd>F9</kbd> Descuento Ítem</div>
                <div class="col-6"><kbd>F10</kbd> Modificar Cantidad</div>
                <div class="col-6"><kbd>Del</kbd> Eliminar Ítem</div>
                <div class="col-6"><kbd>Enter</kbd> Cobrar</div>
            </div>
        </div>
        <div class="category-bar" id="categoryBar">
            <button class="category-btn active" onclick="filterCategory('all', this)">TODOS</button>
            <?php foreach($catsData as $catData): ?>
                <?php
                    $bgColor = htmlspecialchars($catData['color'] ?? '#ffffff');
                    $textColor = (isset($catData['color']) && sscanf($catData['color'], "#%02x%02x%02x") == 3 && array_sum(sscanf($catData['color'], "#%02x%02x%02x")) < 382) ? '#ffffff' : '#000000';
                    $emoji = htmlspecialchars($catData['emoji'] ?? '');
                ?>
                <button class="category-btn" style="background-color: <?php echo $bgColor; ?>; color: <?php echo $textColor; ?>;" onclick="filterCategory('<?php echo htmlspecialchars($catData['nombre_categoria']); ?>', this)">
                    <?php echo $emoji . ' ' . htmlspecialchars($catData['nombre_categoria']); ?>
                </button>
            <?php endforeach; ?>
        </div>
        <div id="favoritesBar" style="flex-shrink:0; display:none;">
            <div class="d-flex align-items-center gap-2 mb-1 px-1" style="min-height:32px;">
                <span id="favBarLabel" class="text-warning fw-bold" style="font-size:0.72rem; white-space:nowrap; flex-shrink:0;">
                    <i class="fas fa-star"></i> FAVORITOS
                </span>
                <div id="favCardsRow" class="d-flex gap-2 pb-1" style="overflow-x:auto; scrollbar-width:none; flex:1;"></div>
            </div>
            <div style="height:1px; background:#dee2e6; margin-bottom:6px;"></div>
        </div>
        <div class="product-grid" id="productContainer"></div>
    </div>
</div>

<div class="modal fade" id="newClientModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-success text-white py-2">
                <h6 class="modal-title">Nuevo Cliente</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div class="mb-2">
                    <label class="small fw-bold">Nombre *</label>
                    <input type="text" id="ncNombre" class="form-control form-control-sm" required>
                </div>
                <div class="mb-2">
                    <label class="small">Teléfono</label>
                    <input type="text" id="ncTel" class="form-control form-control-sm">
                </div>
                <div class="mb-2">
                    <label class="small">Dirección</label>
                    <input type="text" id="ncDir" class="form-control form-control-sm">
                </div>
                <div class="mb-2">
                    <label class="small">NIT/CI</label>
                    <input type="text" id="ncNit" class="form-control form-control-sm">
                </div>
                <button class="btn btn-success w-100 btn-sm fw-bold" onclick="saveNewClient()">Guardar</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="cashModal" tabindex="-1"><div class="modal-dialog modal-sm"><div class="modal-content"><div class="modal-header bg-dark text-white"><h5 class="modal-title">Caja</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body" id="cashModalBody"></div></div></div></div>
<div class="modal fade" id="parkModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header bg-warning"><h5 class="modal-title">Espera</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body p-0"><div class="list-group list-group-flush" id="parkList"></div></div></div></div></div>

<!-- MODAL ANULAR VENTA ─────────────────────────────────────────────────────
     Audit trail: registra cajero + motivo + timestamp en auditoria_pos.
     Solo ventas de la sesión activa. Requiere PIN del cajero. -->
<div class="modal fade" id="voidModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white py-2">
                <h6 class="modal-title fw-bold"><i class="fas fa-ban me-2"></i>Anular Venta #<span id="voidTicketId">-</span></h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pb-2">
                <p class="text-muted small mb-3">Esta acción es permanente y quedará registrada en el audit trail con su nombre y hora exacta.</p>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Motivo de Anulación <span class="text-danger">*</span></label>
                    <textarea id="voidMotivo" class="form-control form-control-sm" rows="3"
                        placeholder="Ej: Error en precio, cliente canceló, producto incorrecto..."
                        maxlength="200"></textarea>
                    <div class="form-text text-muted" style="font-size:0.7rem">Mínimo 5 caracteres. Quedará firmado con su cajero.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Su PIN de Cajero</label>
                    <input type="password" id="voidPin" class="form-control form-control-sm text-center fw-bold"
                        maxlength="8" placeholder="••••" autocomplete="off">
                </div>
                <button class="btn btn-danger w-100 fw-bold btn-void-confirm" onclick="confirmVoid()">
                    <i class="fas fa-ban me-1"></i> CONFIRMAR ANULACIÓN
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="historialModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-info text-white py-2">
                <h5 class="modal-title"><i class="fas fa-history me-2"></i>Historial de Tickets - Sesión Actual</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" id="historialModalBody">
                <div class="text-center p-4">
                    <i class="fas fa-spinner fa-spin fa-2x text-muted"></i>
                    <p class="mt-2 text-muted">Cargando tickets...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    /* Estilos para el historial */
    .ticket-row { cursor: pointer; transition: background 0.1s; border-left: 5px solid transparent; }
    .ticket-row:hover { background-color: #f1f3f5; }
    .row-efectivo { border-left-color: #198754; } 
    .row-transfer { border-left-color: #0d6efd; } 
    .row-gasto { border-left-color: #fd7e14; } 
    .row-reserva { border-left-color: #6f42c1; background-color: #f3f0ff; }
    .row-llevar { border-left-color: #0dcaf0; }
    .row-refund { border-left-color: #dc3545; background-color: #ffeaea !important; }
    .badge-pago { font-size: 0.8rem; font-weight: 500; width: 90px; display: inline-block; text-align: center; }
    .bg-efectivo { background-color: #d1e7dd; color: #0f5132; }
    .bg-transfer { background-color: #cfe2ff; color: #084298; }
    .bg-gasto { background-color: #ffe5d0; color: #994d07; }
    .bg-refund-badge { background-color: #f8d7da; color: #842029; }
    .detail-row { background-color: #fafafa; border-left: 5px solid #ccc; font-size: 0.9rem; }
    .kpi-mini { background: white; border-radius: 8px; padding: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
</style>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
    // INYECCIÓN DE DATOS GLOBALES
    let currentSaleTotal = 0;
    let currentPaymentMode = 'cash';
    const PRODUCTS_DATA = <?php echo json_encode($prods); ?>;
    const CAJEROS_CONFIG = <?php echo json_encode($config['cajeros'] ?? []); ?>;
    const MOST_SOLD_CODES = <?php echo json_encode($mostSoldCodes); ?>;
    let CLIENTS_DATA = <?php echo json_encode($clientsData); ?>; // Lista inicial

    // --- LÓGICA DE CLIENTES ---
    
    function fillClientData(select) {
        const opt = select.options[select.selectedIndex];
        if(!opt.value) {
            document.getElementById('cliPhone').value = '';
            document.getElementById('cliAddr').value = '';
            return;
        }
        document.getElementById('cliPhone').value = opt.getAttribute('data-tel') || '';
        document.getElementById('cliAddr').value = opt.getAttribute('data-dir') || '';
    }

    function openNewClientModal() {
        const modal = new bootstrap.Modal(document.getElementById('newClientModal'));
        document.getElementById('ncNombre').value = ""; 
        document.getElementById('ncTel').value = '';
        document.getElementById('ncDir').value = '';
        document.getElementById('ncNit').value = '';
        modal.show();
    }

    function toggleHotkeyPanel() {
        const panel = document.getElementById('hotkeyPanel');
        if (panel) panel.classList.toggle('show');
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const panel = document.getElementById('hotkeyPanel');
            if (panel) panel.classList.remove('show');
        }
    });

    function saveNewClient() {
        const data = {
            nombre: document.getElementById('ncNombre').value,
            telefono: document.getElementById('ncTel').value,
            direccion: document.getElementById('ncDir').value,
            nit_ci: document.getElementById('ncNit').value
        };

        if(!data.nombre) return alert("Nombre obligatorio");

        fetch('pos.php?api_client=1', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        })
        .then(r => r.json())
        .then(res => {
            if(res.status === 'success') {
                CLIENTS_DATA.push(res.client);
                const list = document.getElementById('cliName');
                const opt = document.createElement('option');
                opt.value = res.client.nombre;
                opt.text = res.client.nombre;
                opt.setAttribute('data-tel', res.client.telefono);
                opt.setAttribute('data-dir', res.client.direccion);
                list.add(opt);
                list.value = res.client.nombre;
                fillClientData(list);
                bootstrap.Modal.getInstance(document.getElementById('newClientModal')).hide();
                showToast('Cliente guardado');
            } else {
                alert("Error: " + res.message);
            }
        })
        .catch(e => alert("Error de conexión"));
    }
</script>

<script src="pos1.js"></script>
<script src="pos-offline-system.js"></script>

<div id="progressOverlay">
    <div class="progress-card">
        <h4 id="progressMessage" class="progress-title">Cargando...</h4>
        <div class="progress-bar-container">
            <div id="progressBar" class="progress-bar-fill">0%</div>
        </div>
        <p id="progressDetail" class="progress-detail"></p>
    </div>
</div>

<!-- Modal Inventario POS -->
<div class="modal fade" id="invModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header py-2">
                <h6 class="modal-title fw-bold" id="invModalTitle"></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <!-- Resumen del carrito -->
                <div id="invCartSummary" class="mb-3" style="max-height:200px;overflow-y:auto;"></div>

                <!-- Comparación conteo vs BD (solo conteo) -->
                <div id="invConteoInfo" class="mb-3" style="display:none;max-height:200px;overflow-y:auto;"></div>

                <!-- Fecha + destino transferencia -->
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-bold">Fecha del movimiento</label>
                        <input type="date" id="invFechaInput" class="form-control">
                    </div>
                    <div class="col-6" id="invDestinoCol" style="display:none;">
                        <label class="form-label small fw-bold">Sucursal destino <span class="text-danger">*</span></label>
                        <input type="text" id="invDestinoInput" class="form-control" placeholder="Nombre o ID de sucursal" autocomplete="off">
                    </div>
                </div>

                <!-- Motivo -->
                <div class="mb-1">
                    <label class="form-label small fw-bold">Motivo <span class="text-danger">*</span></label>
                    <input type="text" id="invMotivoInput" class="form-control" placeholder="Describe el motivo..." autocomplete="off">
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" id="btnInvConfirmar" class="btn btn-primary fw-bold" onclick="invConfirmar()">
                    <i class="fas fa-check me-1"></i> Confirmar
                </button>
            </div>
        </div>
    </div>
</div>

<?php include_once 'modal_payment.php'; ?>
<!-- MODAL AUTOPEDIDOS -->
<div class="modal fade" id="selfOrdersModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-warning text-dark border-0">
                <h5 class="modal-title fw-bold"><i class="fas fa-mobile-alt me-2"></i> Autopedidos de Clientes</h5>
                <div class="ms-auto d-flex align-items-center me-3">
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" id="switchAcceptOrders" checked onchange="toggleAcceptOrders(this.checked)">
                        <label class="form-check-label small fw-bold" for="switchAcceptOrders">Aceptar Pedidos</label>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3"># Ticket</th>
                                <th>Cliente / Mesa</th>
                                <th>Hora</th>
                                <th>Total</th>
                                <th class="text-end pe-3">Acción</th>
                            </tr>
                        </thead>
                        <tbody id="selfOrdersTableBody">
                            <!-- Se llena vía JS -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<audio id="alertSound" src="https://assets.mixkit.co/active_storage/sfx/2869/2869-preview.mp3" preload="auto"></audio>

<script>
    let lastSelfOrderId = 0;
    
    async function checkNewSelfOrders() {
        try {
            const res = await fetch(`self_order_api.php?action=check_new&last_id=${lastSelfOrderId}`);
            const data = await res.json();
            if(data.status === 'success' && data.orders.length > 0) {
                data.orders.forEach(o => { if(o.id > lastSelfOrderId) lastSelfOrderId = o.id; });
                updateSelfOrderBadge(data.orders.length);
                playAlertSound();
            }
        } catch(e) { console.error("Error checking self orders", e); }
    }

    function updateSelfOrderBadge(count) {
        const badge = document.getElementById('selfOrderBadge');
        const btn = document.getElementById('btnSelfOrders');
        if(count > 0) {
            badge.innerText = count;
            badge.classList.remove('d-none');
            btn.classList.add('animate__shakeX', 'btn-warning');
            btn.classList.remove('btn-outline-warning');
        } else {
            badge.classList.add('d-none');
            btn.classList.remove('animate__shakeX', 'btn-warning');
            btn.classList.add('btn-outline-warning');
        }
    }

    function playAlertSound() {
        document.getElementById('alertSound').play().catch(e => console.log("Audio block", e));
    }

    async function openSelfOrdersModal() {
        // Cargar estado actual del switch
        try {
            const resCfg = await fetch('self_order_api.php?action=get_config');
            const dataCfg = await resCfg.json();
            if(dataCfg.status === 'success') {
                document.getElementById('switchAcceptOrders').checked = dataCfg.config.kiosco_aceptar_pedidos !== false;
            }
        } catch(e) { console.error("Error loading config", e); }

        const res = await fetch('self_order_api.php?action=get_pending');
        const data = await res.json();
        const tbody = document.getElementById('selfOrdersTableBody');
        tbody.innerHTML = '';
        
        if(data.orders.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted">No hay pedidos pendientes</td></tr>';
        }

        data.orders.forEach(o => {
            tbody.innerHTML += `
                <tr>
                    <td class="ps-3 fw-bold">#${o.id}</td>
                    <td class="fw-bold">${o.cliente_nombre}</td>
                    <td>${o.fecha.split(' ')[1]}</td>
                    <td class="text-success fw-bold">$${parseFloat(o.total).toFixed(2)}</td>
                    <td class="text-end pe-3">
                        <button class="btn btn-sm btn-primary rounded-pill" onclick="importSelfOrder(${o.id})">
                            <i class="fas fa-file-import me-1"></i> Cargar al Carrito
                        </button>
                    </td>
                </tr>
            `;
        });
        
        new bootstrap.Modal(document.getElementById('selfOrdersModal')).show();
    }

    async function toggleAcceptOrders(accept) {
        try {
            await fetch('self_order_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'set_config', aceptar_pedidos: accept })
            });
            if(typeof showToast === 'function') showToast(accept ? "Pedidos activados" : "Pedidos bloqueados");
        } catch(e) { 
            console.error("Error setting config", e);
            alert("Error al guardar configuración");
        }
    }

    async function importSelfOrder(id) {
        if(!confirm("¿Cargar este pedido al carrito actual?")) return;
        
        const res = await fetch(`self_order_api.php?action=get_details&id=${id}`);
        const data = await res.json();
        
        if(data.status === 'success') {
            data.items.forEach(it => {
                // Buscar producto real en el catálogo local del POS
                const product = productsDB.find(p => p.codigo == it.codigo);
                if(product) {
                    // Llamar a la función nativa de pos.js para añadir al carrito
                    // Se puede llamar múltiples veces si la cantidad es > 1
                    for(let i = 0; i < parseFloat(it.cantidad); i++) {
                        window.addToCart(product);
                    }
                } else {
                    console.error("Producto no encontrado en el catálogo POS:", it.codigo);
                }
            });
            
            // Marcar pedido como completado en la DB para que no aparezca más
            await fetch('self_order_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'complete', id: id })
            });

            bootstrap.Modal.getInstance(document.getElementById('selfOrdersModal')).hide();
            checkNewSelfOrders(); // Refrescar badge
        }
    }

    // Iniciar el polling
    setInterval(checkNewSelfOrders, 10000);
</script>

<?php include_once 'modal_close_register.php'; ?>

</body>
</html>

