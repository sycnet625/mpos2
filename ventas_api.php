<?php
// ARCHIVO: /var/www/palweb/api/ventas_api.php
// API para procesar ventas del POS con FECHA CONTABLE

header('Content-Type: application/json');
require_once 'db.php';
require_once 'kardex_engine.php';

// Recibir datos del POST
$data = json_decode(file_get_contents('php://input'), true);

// Validar datos requeridos
if (!$data || !isset($data['items']) || !isset($data['id_sucursal'])) {
    echo json_encode(['success' => false, 'error' => 'Datos incompletos']);
    exit;
}

// Extraer variables
$idSucursal = intval($data['id_sucursal']);
$idAlmacen = intval($data['id_almacen']);
// Usamos la fecha contable del turno o la actual como fallback
$fechaContable = $data['fecha_contable'] ?? date('Y-m-d H:i:s'); 

$items = $data['items'];
$total = floatval($data['total']);
$cajero = $data['cajero'] ?? 'Sistema';
$clienteNombre = $data['cliente_nombre'] ?? 'Mostrador';
$metodoPago = $data['metodo_pago'] ?? 'Efectivo';

// Iniciar transacción
$pdo->beginTransaction();

try {
    // 1. Generar UUID único para la venta
    $uuid = uniqid('V-', true);
    
    // 2. Insertar venta en cabecera
    $stmtVenta = $pdo->prepare("
        INSERT INTO ventas_cabecera 
        (uuid_venta, fecha, total, id_sucursal, id_almacen, cliente_nombre, metodo_pago) 
        VALUES (?, NOW(), ?, ?, ?, ?, ?)
    ");
    
    $stmtVenta->execute([
        $uuid, 
        $total, 
        $idSucursal, 
        $idAlmacen, 
        $clienteNombre,
        $metodoPago
    ]);
    
    $idVenta = $pdo->lastInsertId();
    
    // 3. Inicializar motor de kardex
    $kardex = new KardexEngine($pdo);
    
    // 4. Procesar cada item del carrito
    $stmtDetalle = $pdo->prepare("
        INSERT INTO ventas_detalle 
        (id_venta_cabecera, id_producto, cantidad, precio, nombre_producto, codigo_producto) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    foreach ($items as $item) {
        // Insertar detalle de venta
        $stmtDetalle->execute([
            $idVenta,
            $item['codigo'],
            $item['qty'],
            $item['price'],
            $item['name'],
            $item['codigo']
        ]);
        
        // Registrar movimiento en kardex CON FECHA CONTABLE
        // SE CORRIGIÓ EL PARÁMETRO 7 (Precio en lugar de Costo, ya que costo suele ser null desde el front)
        $kardex->registrarMovimiento(
            $item['codigo'],                // 1. SKU del producto
            $idAlmacen,                     // 2. ID Almacén
            $idSucursal,                    // 3. ID Sucursal
            'VENTA',                        // 4. Tipo de movimiento
            -floatval($item['qty']),        // 5. Cantidad negativa (sale del inventario)
            "Venta #{$idVenta}",            // 6. Referencia
            floatval($item['price']),       // 7. Precio Referencial (Costo)
            $cajero,                        // 8. Usuario/Cajero
            $fechaContable                  // 9. FECHA CONTABLE
        );
    }
    
    // Commit de la transacción
    $pdo->commit();
    
    // Respuesta exitosa
    echo json_encode([
        'success' => true,
        'id_venta' => $idVenta,
        'uuid' => $uuid,
        'total' => $total,
        'fecha_contable_usada' => $fechaContable,
        'mensaje' => "Venta registrada con fecha contable: $fechaContable"
    ]);
    
} catch (Exception $e) {
    // Rollback en caso de error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    // Log del error
    error_log("Error en ventas_api.php: " . $e->getMessage());
    
    // Respuesta de error
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>

