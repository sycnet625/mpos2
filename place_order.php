<?php
// ARCHIVO: /var/www/palweb/api/place_order.php
// VERSIÓN: SOPORTE PARA NOTAS DE CLIENTE Y MENSAJERÍA (KARDEX FIXED)

header("Content-Type: application/json");
ini_set('display_errors', 0);
require_once 'db.php';
require_once 'kardex_engine.php';

// 1. Cargar Configuración
require_once 'config_loader.php';

$EMP_ID = intval($config['id_empresa']);
$SUC_ID = intval($config['id_sucursal']); 
$ALM_ID = intval($config['id_almacen']); 

// 2. Recibir JSON
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { 
    http_response_code(400); 
    echo json_encode(["error" => "Datos inválidos"]); 
    exit; 
}

try {
    $pdo->beginTransaction();
    $kardex = new KardexEngine($pdo);

    // 3. Procesar Datos del Cliente
    $fechaProg = !empty($input['customer']['fecha_programada']) ? $input['customer']['fecha_programada'] : null;
    $envioCosto = floatval($input['customer']['envio_costo'] ?? 0);
    $totalVenta = floatval($input['total']);
    
    // NUEVO: Capturar las notas del cliente (Ej: "Cake rosado...")
    $notasCliente = isset($input['customer']['notas']) ? trim($input['customer']['notas']) : '';

    // Notas internas del sistema (Costo de envío, ubicación técnica)
    $notasAdmin = "Envío calculado: $" . number_format($envioCosto, 2);

    // 4. Insertar Cabecera del Pedido
    $sqlHead = "INSERT INTO pedidos_cabecera (
                    cliente_nombre, cliente_direccion, cliente_telefono, cliente_email, 
                    fecha_programada, total, estado, id_empresa, id_sucursal, 
                    notas, notas_admin
                ) VALUES (
                    :nom, :dir, :tel, :email, 
                    :fecha, :total, 'pendiente', :emp, :suc, 
                    :notasCli, :notasAdm
                )";
    
    try {
        $stmt = $pdo->prepare($sqlHead);
        $stmt->execute([
            ':nom' => $input['customer']['nombre'],
            ':dir' => $input['customer']['direccion'],
            ':tel' => $input['customer']['telefono'],
            ':email'=> $input['customer']['email'] ?? '',
            ':fecha'=> $fechaProg,
            ':total'=> $totalVenta,
            ':emp' => $EMP_ID,
            ':suc' => $SUC_ID,
            ':notasCli' => $notasCliente, 
            ':notasAdm' => $notasAdmin    
        ]);
    } catch (PDOException $e) {
        // Fallback para tablas antiguas
        $notaCombinada = "Nota Cliente: $notasCliente | $notasAdmin";
        $stmtLegacy = $pdo->prepare("INSERT INTO pedidos_cabecera (cliente_nombre, cliente_direccion, cliente_telefono, fecha_programada, total, estado, notas_admin) VALUES (?, ?, ?, ?, ?, 'pendiente', ?)");
        $stmtLegacy->execute([
            $input['customer']['nombre'],
            $input['customer']['direccion'],
            $input['customer']['telefono'],
            $fechaProg,
            $totalVenta,
            $notaCombinada
        ]);
    }

    $pedidoId = $pdo->lastInsertId();

    // 5. Insertar Detalles y Kardex
    $stmtDet = $pdo->prepare("INSERT INTO pedidos_detalle (id_pedido, id_producto, cantidad, precio_unitario) VALUES (?, ?, ?, ?)");
    
    foreach ($input['items'] as $item) {
        $stmtDet->execute([$pedidoId, $item['id'], $item['qty'], $item['price']]);
        
        // Registrar salida de inventario (CORREGIDO 9 PARAMS)
        $kardex->registrarMovimiento(
            $item['id'],                    // 1. SKU
            $ALM_ID,                        // 2. Almacén
            $SUC_ID,                        // 3. Sucursal
            'VENTA',                        // 4. Tipo
            ($item['qty'] * -1),            // 5. Cantidad
            "PEDIDO WEB #$pedidoId",        // 6. Referencia
            $item['price'],                 // 7. Costo/Precio
            "CLIENTE_WEB",                  // 8. Usuario
            date('Y-m-d H:i:s')             // 9. FECHA (¡Agregado!)
        );
    }

    $pdo->commit();
    echo json_encode(["status" => "success", "order_id" => $pedidoId]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(["error" => "Error servidor: " . $e->getMessage()]);
}
?>

