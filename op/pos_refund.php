<?php
// ARCHIVO: /var/www/palweb/api/pos_refund.php
// VERSIÓN: CON KARDEX ENGINE (TRANSACCIONES SEGURAS)

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL); 
header('Content-Type: application/json');

require_once 'db.php';
require_once 'kardex_engine.php'; // Ahora sí lo usamos

// 1. CONFIGURACIÓN
require_once 'config_loader.php';

$SUC_ID = intval($config['id_sucursal']);
$ALM_ID = intval($config['id_almacen']);
$EMP_ID = intval($config['id_empresa']);

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $idDetalle = isset($input['id']) ? intval($input['id']) : 0;

    if ($idDetalle <= 0) throw new Exception("ID de venta inválido");

    // INICIO TRANSACCIÓN GLOBAL
    $pdo->beginTransaction();
    
    // Instanciar motor (detectará la transacción abierta automáticamente)
    $kardex = new KardexEngine($pdo);

    // 1. OBTENER DATOS ORIGINALES
    $sqlItem = "SELECT d.*, v.id_caja 
                FROM ventas_detalle d 
                JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id 
                WHERE d.id = ? FOR UPDATE";
    $stmt = $pdo->prepare($sqlItem);
    $stmt->execute([$idDetalle]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) throw new Exception("Venta no encontrada o ya procesada");

    // 2. CREAR CABECERA DEVOLUCIÓN
    $uuid = uniqid('ref_');
    $montoDevolucion = -1 * ($item['cantidad'] * $item['precio']); 

    $sqlHead = "INSERT INTO ventas_cabecera 
                (uuid_venta, fecha, total, metodo_pago, id_sucursal, id_empresa, id_almacen, 
                 tipo_servicio, cliente_nombre, id_caja) 
                VALUES (?, NOW(), ?, 'Devolución', ?, ?, ?, 'mostrador', 'DEVOLUCIÓN', ?)";
    
    $stmtHead = $pdo->prepare($sqlHead);
    $stmtHead->execute([
        $uuid, $montoDevolucion, $SUC_ID, $EMP_ID, $ALM_ID, $item['id_caja']
    ]);
    $newHeadId = $pdo->lastInsertId();

    // 3. CREAR DETALLE NEGATIVO
    $sqlDet = "INSERT INTO ventas_detalle (id_venta_cabecera, id_producto, cantidad, precio, nombre_producto) VALUES (?, ?, ?, ?, ?)";
    $stmtDet = $pdo->prepare($sqlDet);
    $stmtDet->execute([
        $newHeadId, 
        $item['id_producto'], 
        -1 * $item['cantidad'], 
        $item['precio'],
        "DEVOLUCIÓN: " . ($item['nombre_producto'] ?? $item['id_producto'])
    ]);

    // 4. MOVER KARDEX (USANDO LA CLASE)
    // Devolución = Entrada positiva al almacén
    // Usamos firma nueva (con sucursal)
    $cantidadEntrada = floatval($item['cantidad']); 
    
    $kardex->registrarMovimiento(
        $item['id_producto'],           // SKU
        $ALM_ID,                        // Almacén
        $SUC_ID,                        // Sucursal (Firma Nueva)
        'DEVOLUCION',                   // Tipo
        $cantidadEntrada,               // Cantidad
        "REFUND_ITEM_" . $idDetalle,    // Referencia
        0,                              // Costo
        'CAJERO_REFUND',                // Usuario
        date('Y-m-d H:i:s')             // Fecha
    );

    $pdo->commit();
    echo json_encode(['status' => 'success']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    // Respuesta JSON válida en error
    http_response_code(200); 
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
}
?>

