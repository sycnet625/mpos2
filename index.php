<?php
// ARCHIVO: /var/www/palweb/api/index.php
// VERSIÓN: COMPATIBILIDAD KARDEX V2 (9 PARAMS)

ini_set('display_errors', 0); 
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Capturar errores fatales para el log
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && ($error['type'] === E_ERROR || $error['type'] === E_PARSE)) {
        error_log("FATAL ERROR en index.php: " . $error['message'] . " en " . $error['file'] . ":" . $error['line']);
    }
});

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET");

define('API_TOKEN_SECRETO', 'tu_token_seguro'); 

// 1. INCLUSIONES Y CONFIGURACIÓN
try {
    require_once 'db.php';
    if (!file_exists('kardex_engine.php')) {
        throw new Exception("kardex_engine.php no encontrado");
    }
    require_once 'kardex_engine.php'; 
    
    // El objeto $pdo viene de db.php
    if (!isset($pdo)) {
        throw new Exception("Variable \$pdo no definida en db.php");
    }
    
    $kardex = new KardexEngine($pdo);

} catch (Exception $e) {
    header("Content-Type: application/json; charset=UTF-8");
    http_response_code(500); 
    echo json_encode(["error" => "Error de inicializacion: " . $e->getMessage()]); 
    exit;
}

// 2. FUNCIONES AUXILIARES
function fixDateForMySQL($dateStr) {
    if (!$dateStr) return null;
    if (strpos($dateStr, '-') !== false && preg_match('/^\d{4}-\d{2}-\d{2}/', $dateStr)) return substr($dateStr, 0, 10);
    if (strpos($dateStr, '/') !== false) {
        $parts = explode('/', $dateStr);
        if (count($parts) == 3) return sprintf("%04d-%02d-%02d", $parts[2], $parts[1], $parts[0]);
    }
    return null;
}

// 3. SEGURIDAD Y TOKEN
$uri = $_SERVER['REQUEST_URI'];
$method = $_SERVER['REQUEST_METHOD'];
// Solo leer input si es POST o PUT
$input = [];
if ($method == 'POST' || $method == 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true);
}

// Función compatible para obtener headers
function get_request_headers() {
    if (function_exists('getallheaders')) return getallheaders();
    $headers = [];
    foreach ($_SERVER as $name => $value) {
        if (substr($name, 0, 5) == 'HTTP_') {
            $header_name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
            $headers[$header_name] = $value;
        }
    }
    return $headers;
}

$headers = get_request_headers();
$auth = $headers['Authorization'] ?? ($headers['HTTP-Authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));

// Si se accede a la raíz o a index.php directamente sin ruta de API y sin Auth, redirigir a shop.php
$is_api_call = (strpos($uri, '/products') !== false || strpos($uri, '/sync') !== false || strpos($uri, '/health') !== false);

if (!$is_api_call && empty($auth) && !isset($_GET['version'])) {
    header("Location: shop.php");
    exit;
}

// A partir de aquí, asumimos que es una respuesta JSON
header("Content-Type: application/json; charset=UTF-8");

if (strpos($uri, '/health') !== false && $method == 'GET') {
    while (ob_get_level()) ob_end_clean();
    http_response_code(200); echo json_encode(["status" => "ok"]); exit;
}

if (!preg_match('/Bearer\s(\S+)/', $auth, $matches) || $matches[1] !== API_TOKEN_SECRETO) {
    if ($is_api_call) {
        http_response_code(401); 
        echo json_encode(["error" => "No autorizado. Token invalido."]); 
        exit;
    }
    header("Location: shop.php");
    exit;
}

$empresaId = isset($headers['X-Empresa-ID']) ? intval($headers['X-Empresa-ID']) : 1;
if ($empresaId <= 0) $empresaId = 1;


// ============================================================================
//   RUTAS DE LA API
// ============================================================================

// ----------------------------------------------------------------------------
// A. GET /products (COMPATIBILIDAD)
// ----------------------------------------------------------------------------
if (strpos($uri, '/products') !== false && $method == 'GET' && strpos($uri, 'upload') === false) {
    try {
        $ver = isset($_GET['version']) ? intval($_GET['version']) : 0;
        
        $sql = "SELECT p.*, COALESCE(s.cantidad, 0) as stock_servidor 
                FROM productos p 
                LEFT JOIN stock_almacen s ON p.codigo = s.id_producto AND s.id_almacen = 1 
                WHERE p.version_row > :ver AND p.id_empresa = :emp 
                ORDER BY p.version_row ASC LIMIT 2000";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':ver', $ver, PDO::PARAM_INT);
        $stmt->bindValue(':emp', $empresaId, PDO::PARAM_INT);
        $stmt->execute();

        $result = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result[] = [
                'id' => $row['codigo'], 
                'codigo' => $row['codigo'],
                'nombre' => $row['nombre'],
                'categoria' => $row['categoria'],
                'precio' => floatval($row['precio']),
                'costo' => floatval($row['costo']),
                'stock_min' => floatval($row['stock_minimo']),
                'vencimiento' => $row['fecha_vencimiento'] ?: "",
                'activo' => intval($row['activo']),
                'version' => intval($row['version_row']),
                'es_elaborado' => (int)($row['es_elaborado'] ?? 1),
                'es_materia_prima' => (int)($row['es_materia_prima'] ?? 0),
                'es_servicio' => (int)($row['es_servicio'] ?? 0),
                'stock_actual' => floatval($row['stock_servidor'])
            ];
        }
        echo json_encode($result);
    } catch (Exception $e) { http_response_code(500); echo json_encode(["error" => $e->getMessage()]); }
    exit;
}

// ----------------------------------------------------------------------------
// B. POST /sync/full (COMPATIBILIDAD): Registro de Ventas Externas
// ----------------------------------------------------------------------------
elseif (strpos($uri, '/sync/full') !== false && $method == 'POST') {
    if (!is_array($input)) { http_response_code(400); exit; }
    try {
        $pdo->beginTransaction();
        
        $stmtHead = $pdo->prepare("INSERT INTO ventas_cabecera (uuid_venta, fecha, total, id_empresa, id_sucursal, id_almacen, id_caja, metodo_pago) VALUES (:u, :f, :t, :e, :s, :a, :c, :mp)");
        $stmtDet  = $pdo->prepare("INSERT INTO ventas_detalle (id_venta_cabecera, id_producto, cantidad, precio) VALUES (:h, :p, :c, :pr)");

        $count = 0;
        foreach ($input as $sale) {
            $uuid = $sale['uuid_venta'] ?? '';
            if (!$uuid) continue;
            
            $check = $pdo->prepare("SELECT id FROM ventas_cabecera WHERE uuid_venta = ?");
            $check->execute([$uuid]);
            if ($check->fetch()) continue; 

            $idAlmacen = $sale['id_almacen'] ?? 1;
            $idSucursal = intval($sale['id_sucursal'] ?? 1); // Default Sucursal 1

            $stmtHead->execute([
                ':u' => $uuid, ':f' => $sale['fecha'], ':t' => floatval($sale['total']),
                ':e' => $empresaId, ':s' => $idSucursal, ':a' => $idAlmacen,
                ':c' => $sale['id_caja']??1, ':mp'=> 'Efectivo'
            ]);
            $idCab = $pdo->lastInsertId();

            if (isset($sale['items']) && is_array($sale['items'])) {
                foreach ($sale['items'] as $item) {
                    $prodCode = $item['codigo_producto'] ?? ''; 
                    $qty = floatval($item['cantidad']);
                    $price = floatval($item['precio_unitario']);
                    
                    if(!$prodCode) continue;

                    $stmtDet->execute([':h' => $idCab, ':p' => $prodCode, ':c' => $qty, ':pr'=> $price]);

                    $cantidadSalida = $qty * -1;
                    
                    try {
                        // KARDEX CORREGIDO (9 PARAMETROS)
                        $kardex->registrarMovimiento(
                            $prodCode,                  // 1. SKU
                            $idAlmacen,                 // 2. Almacen
                            $idSucursal,                // 3. Sucursal (NUEVO)
                            'VENTA',                    // 4. Tipo
                            $cantidadSalida,            // 5. Cantidad
                            "API_SYNC_" . $uuid,        // 6. Ref
                            0,                          // 7. Costo
                            "API_USER",                 // 8. Usuario
                            $sale['fecha']              // 9. FECHA (Crucial para sincro histórica)
                        );
                    } catch (Exception $e) {
                        // Continuar si falla stock en sync histórico
                    }
                }
            }
            $count++;
        }
        $pdo->commit();
        echo json_encode(["status" => "success", "processed" => $count]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500); echo json_encode(["error" => $e->getMessage()]);
    }
    exit;
}

// ----------------------------------------------------------------------------
// C. POST /products/upload_full (COMPATIBILIDAD): Carga Masiva
// ----------------------------------------------------------------------------
elseif (strpos($uri, '/products/upload_full') !== false && $method == 'POST') {
    if (!is_array($input)) { http_response_code(400); exit; }
    try {
        $pdo->beginTransaction();
        
        $sqlProd = "INSERT INTO productos (
                        codigo, id_empresa, nombre, categoria, precio, costo, stock_minimo, 
                        fecha_vencimiento, activo, version_row, 
                        es_elaborado, es_materia_prima, es_servicio
                    ) VALUES (
                        :cod, :emp, :nom, :cat, :pr, :cost, :min, 
                        :ven, :act, :ver, :elab, :mat, :serv
                    ) 
                    ON DUPLICATE KEY UPDATE 
                    nombre = VALUES(nombre), categoria = VALUES(categoria), precio = VALUES(precio), 
                    costo = VALUES(costo), activo = VALUES(activo), version_row = VALUES(version_row),
                    es_elaborado = VALUES(es_elaborado), es_materia_prima = VALUES(es_materia_prima), es_servicio = VALUES(es_servicio)";
        
        $stmtProd = $pdo->prepare($sqlProd);
        $stmtGetStock = $pdo->prepare("SELECT cantidad FROM stock_almacen WHERE id_producto = ? AND id_almacen = ?");

        $nowUnix = time();
        $defaultSuc = 1; // Default Sucursal

        foreach ($input as $obj) {
            $cod = $obj['codigo'] ?? '';
            if (!$cod) continue;

            $stmtProd->execute([
                ':cod' => $cod, ':emp' => $empresaId, ':nom' => $obj['nombre'] ?? '', ':cat' => $obj['categoria'] ?? '',
                ':pr'  => floatval($obj['precio']), ':cost'=> floatval($obj['costo'] ?? 0), ':min' => floatval($obj['stock_min'] ?? 0),
                ':ven' => fixDateForMySQL($obj['vencimiento'] ?? null), ':act' => intval($obj['activo'] ?? 1),
                ':ver' => $nowUnix,
                ':elab'=> (!empty($obj['es_elaborado'])) ? 1 : 0, ':mat' => (!empty($obj['es_materia_prima'])) ? 1 : 0,
                ':serv'=> (!empty($obj['es_servicio'])) ? 1 : 0
            ]);

            if (isset($obj['stock_inicial'])) {
                $stockEntrante = floatval($obj['stock_inicial']);
                $idAlm = intval($obj['id_almacen_origen'] ?? 1);

                $stmtGetStock->execute([$cod, $idAlm]);
                $stockActualDB = floatval($stmtGetStock->fetchColumn() ?: 0);

                $diferencia = $stockEntrante - $stockActualDB;

                if (abs($diferencia) > 0.0001) {
                    // KARDEX CORREGIDO (9 PARAMETROS)
                    $kardex->registrarMovimiento(
                        $cod,                                   // 1
                        $idAlm,                                 // 2
                        $defaultSuc,                            // 3 (Sucursal 1 por defecto en import masivo legacy)
                        'AJUSTE',                               // 4
                        $diferencia,                            // 5
                        "IMPORTACION_MASIVA_" . date('Ymd_His'),// 6
                        floatval($obj['costo'] ?? 0),           // 7
                        "API_UPLOAD",                           // 8
                        date('Y-m-d H:i:s')                     // 9. FECHA
                    );
                }
            }
        }
        $pdo->commit();
        echo json_encode(["status" => "full_upload_success_kardex"]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500); echo json_encode(["error" => $e->getMessage()]);
    }
    exit;
}

// ----------------------------------------------------------------------------
// D. POST /inventory/movement (API DIRECTA KARDEX)
// ----------------------------------------------------------------------------
elseif (strpos($uri, '/inventory/movement') !== false && $method == 'POST') {
    try {
        if (empty($input['sku']) || empty($input['tipo']) || !isset($input['cantidad'])) {
            throw new Exception("Datos incompletos (sku, tipo, cantidad requeridos)");
        }

        $pdo->beginTransaction();

        $cant = floatval($input['cantidad']);
        $tipo = strtoupper($input['tipo']);
        $sucID = intval($input['sucursal_id'] ?? 1);

        if (in_array($tipo, ['SALIDA', 'VENTA', 'MERMA']) && $cant > 0) {
            $cant = $cant * -1;
        }

        // KARDEX CORREGIDO (9 PARAMETROS)
        $kardex->registrarMovimiento(
            $input['sku'],                  // 1
            intval($input['almacen_id'] ?? 1), // 2
            $sucID,                         // 3 (Sucursal)
            $tipo,                          // 4
            $cant,                          // 5
            $input['referencia'] ?? 'API_MOV', // 6
            floatval($input['costo'] ?? 0), // 7
            $input['usuario'] ?? 'API_USER', // 8
            date('Y-m-d H:i:s')             // 9. FECHA
        );

        $pdo->commit();
        echo json_encode(["status" => "success", "msg" => "Movimiento registrado"]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(400); echo json_encode(["error" => $e->getMessage()]);
    }
    exit;
}

// ----------------------------------------------------------------------------
// E. GET /inventory/history (CONSULTA KARDEX)
// ----------------------------------------------------------------------------
elseif (strpos($uri, '/inventory/history') !== false && $method == 'GET') {
    try {
        $sku = $_GET['sku'] ?? '';
        if (!$sku) throw new Exception("SKU requerido");

        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;

        $stmt = $pdo->prepare("SELECT fecha, tipo_movimiento, cantidad, saldo_anterior, saldo_actual, referencia, usuario 
                               FROM kardex 
                               WHERE id_producto = ? 
                               ORDER BY fecha DESC LIMIT " . $limit);
        $stmt->execute([$sku]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));

    } catch (Exception $e) {
        http_response_code(400); echo json_encode(["error" => $e->getMessage()]);
    }
    exit;
}

else {
    http_response_code(404); echo json_encode(["error" => "Endpoint no encontrado"]);
}
?>

