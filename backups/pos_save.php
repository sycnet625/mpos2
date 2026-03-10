<?php
// ARCHIVO: /var/www/palweb/api/pos_save.php
// VERSIÓN: V4.0 (INTEGRACIÓN CORRECTA CON KARDEX ENGINE)

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

require_once 'db.php';
require_once 'kardex_engine.php';

// Función auxiliar segura
function safe_str($str, $len = 250) {
    return substr((string)($str ?? ''), 0, $len);
}

try {
    // 1. Recibir Datos
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    
    if (!$input) throw new Exception("Datos vacíos o JSON inválido.");

    // 2. Configuración
    $configFile = __DIR__ . '/pos.cfg';
    $config = ["id_almacen" => 1, "id_sucursal" => 1, "id_empresa" => 1];
    if (file_exists($configFile)) {
        $loaded = json_decode(file_get_contents($configFile), true);
        if ($loaded) $config = array_merge($config, $loaded);
    }

    $idAlmacen = intval($config['id_almacen']);
    $idSucursal = intval($config['id_sucursal']);
    $idEmpresa = intval($config['id_empresa']);

    // 3. Preparar Datos Generales
    $fechaVenta = date('Y-m-d H:i:s');
    if (!empty($input['timestamp'])) {
        $fechaVenta = date('Y-m-d H:i:s', $input['timestamp'] / 1000);
    }

    $uuid = $input['uuid'] ?? uniqid('pos_', true);
    $tipoServicio = $input['tipo_servicio'] ?? 'mostrador';
    
    // Sesión de Caja
    $idSesion = intval($input['id_caja'] ?? 0);
    $usuarioNombre = 'Sistema';
    
    if ($idSesion === 0) {
        try {
            $stmtSesion = $pdo->query("SELECT id, nombre_cajero FROM caja_sesiones WHERE estado = 'ABIERTA' ORDER BY id DESC LIMIT 1");
            if ($row = $stmtSesion->fetch(PDO::FETCH_ASSOC)) {
                $idSesion = $row['id'];
                $usuarioNombre = $row['nombre_cajero'];
            }
        } catch(Exception $e) {}
    }

    // 4. TRANSACCIÓN
    // Iniciamos la transacción AQUÍ. KardexEngine detectará esto y no abrirá otra.
    $pdo->beginTransaction();

    // Inicializar motor pasando el PDO con la transacción activa
    $kardex = new KardexEngine($pdo);

    // A. Verificar Duplicados (Idempotencia)
    $stmtCheck = $pdo->prepare("SELECT id FROM ventas_cabecera WHERE uuid_venta = ?");
    $stmtCheck->execute([$uuid]);
    if ($stmtCheck->fetch()) {
        $pdo->commit();
        echo json_encode(['status' => 'success', 'id' => 0, 'msg' => 'Venta ya registrada']);
        exit;
    }

    // B. Insertar Cabecera
    $sqlCab = "INSERT INTO ventas_cabecera (
        uuid_venta, fecha, total, metodo_pago, id_sucursal, id_almacen, id_caja,
        tipo_servicio, cliente_nombre, cliente_telefono, cliente_direccion, 
        id_empresa, mensajero_nombre, fecha_reserva, sincronizado, id_sesion_caja,
        abono
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)";

    $stmtCab = $pdo->prepare($sqlCab);
    $stmtCab->execute([
        $uuid,
        $fechaVenta,
        floatval($input['total']),
        safe_str($input['metodo_pago'] ?? 'Efectivo', 50),
        $idSucursal,
        $idAlmacen,
        $idSesion,
        safe_str($tipoServicio, 50),
        safe_str($input['cliente_nombre'] ?? 'Mostrador', 100),
        safe_str($input['cliente_telefono'] ?? '', 50),
        safe_str($input['cliente_direccion'] ?? '', 200),
        $idEmpresa,
        safe_str($input['mensajero_nombre'] ?? '', 100),
        !empty($input['fecha_reserva']) ? $input['fecha_reserva'] : null,
        $idSesion,
        floatval($input['abono'] ?? 0)
    ]);

    $idVenta = $pdo->lastInsertId();

    // C. Procesar Detalles e Inventario
    $sqlDet = "INSERT INTO ventas_detalle (
        id_venta_cabecera, id_producto, cantidad, precio, 
        nombre_producto, codigo_producto
    ) VALUES (?, ?, ?, ?, ?, ?)";
    $stmtDet = $pdo->prepare($sqlDet);

    $itemsCocina = [];

    foreach ($input['items'] as $item) {
        $sku = $item['id'];
        $qty = floatval($item['qty']);
        $price = floatval($item['price']);
        $name = safe_str($item['name'] ?? $sku, 150);

        // 1. Insertar Detalle
        $stmtDet->execute([$idVenta, $sku, $qty, $price, $name, $sku]);

        // 2. Verificar Tipo Producto
        $stmtProd = $pdo->prepare("SELECT es_servicio, es_elaborado FROM productos WHERE codigo = ?");
        $stmtProd->execute([$sku]);
        $prodData = $stmtProd->fetch(PDO::FETCH_ASSOC);
        
        $esServicio = $prodData ? intval($prodData['es_servicio']) : 0;
        $esElaborado = $prodData ? intval($prodData['es_elaborado']) : 0;

        // 3. Mover Inventario usando KardexEngine
        // Solo si NO es un servicio
        if ($esServicio === 0) {
            // El motor detectará la transacción activa y la usará
            $kardex->registrarVenta($sku, $qty, $idVenta, $usuarioNombre, $fechaVenta);
        }

        // 4. Preparar Comanda
        if ($esElaborado === 1) {
            $itemsCocina[] = [
                'qty' => $qty, 
                'name' => $name, 
                'note' => safe_str($item['note'] ?? '', 100)
            ];
        }
    }

    // D. Guardar Comanda
    if (!empty($itemsCocina) && $tipoServicio !== 'reserva') {
        $stmtCom = $pdo->prepare("INSERT INTO comandas (id_venta, items_json, estado, fecha_creacion) VALUES (?, ?, 'pendiente', ?)");
        $stmtCom->execute([$idVenta, json_encode($itemsCocina), $fechaVenta]);
    }

    $pdo->commit();
    echo json_encode(['status' => 'success', 'id' => $idVenta]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    // Respuesta JSON válida en caso de error
    echo json_encode(['status' => 'error', 'msg' => 'Error: ' . $e->getMessage()]);
}
?>

