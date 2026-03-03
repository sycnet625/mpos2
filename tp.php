<?php
// ARCHIVO: /var/www/palweb/api/test_pos_system.php
header('Content-Type: text/html; charset=utf-8');
require_once 'db.php';

echo "<h2>🧪 Probando Sincronización del Sistema POS</h2>";

try {
    $pdo->beginTransaction();

    // 1. SIMULAR APERTURA DE CAJA (Con fecha contable y nota)
    echo "1. Creando sesión de caja... ";
    $fechaContable = "2026-01-20"; // Fecha retroactiva de prueba
    $stmtSesion = $pdo->prepare("INSERT INTO caja_sesiones 
        (nombre_cajero, monto_inicial, fecha_contable, estado, fecha_apertura, nota) 
        VALUES ('Probador Alfa', 100.00, ?, 'ABIERTA', NOW(), 'Sesión de prueba técnica')");
    $stmtSesion->execute([$fechaContable]);
    $idSesion = $pdo->lastInsertId();
    echo "✅ (ID: $idSesion)<br>";

    // 2. SIMULAR PRODUCTO (Servicio y Elaborado)
    echo "2. Verificando/Creando producto de prueba... ";
    $skuPrueba = "TEST-999";
    $pdo->prepare("DELETE FROM productos WHERE codigo = ?")->execute([$skuPrueba]); // Limpiar si existe
    $stmtProd = $pdo->prepare("INSERT INTO productos 
        (codigo, nombre, precio, costo, categoria, activo, es_elaborado, es_servicio) 
        VALUES (?, 'Producto de Prueba', 50.00, 20.00, 'Pruebas', 1, 1, 0)");
    $stmtProd->execute([$skuPrueba]);
    echo "✅ (SKU: $skuPrueba)<br>";

    // 3. SIMULAR VENTA COMPLETA (Con todos los campos nuevos)
    echo "3. Insertando venta compleja (Delivery/Reserva)... ";
    $uuid = "test-" . uniqid();
    $sqlVenta = "INSERT INTO ventas_cabecera (
                    uuid_venta, fecha, total, metodo_pago, id_sucursal, 
                    tipo_servicio, cliente_nombre, cliente_telefono, 
                    cliente_direccion, id_sesion_caja, abono, fecha_reserva
                ) VALUES (?, ?, ?, 'Efectivo', 1, 'reserva', 'Juan Pérez', '555-0199', 'Calle Falsa 123', ?, 10.00, NOW())";
    
    $stmtVenta = $pdo->prepare($sqlVenta);
    // Usamos la fecha contable del turno
    $stmtVenta->execute([$uuid, $fechaContable . " " . date('H:i:s'), 50.00, $idSesion]);
    $idVenta = $pdo->lastInsertId();
    echo "✅ (Venta ID: $idVenta)<br>";

    // 4. INSERTAR DETALLE
    echo "4. Insertando detalle de venta... ";
    $pdo->prepare("INSERT INTO ventas_detalle (id_venta_cabecera, id_producto, cantidad, precio) VALUES (?, ?, 1, 50.00)")
        ->execute([$idVenta, $skuPrueba]);
    echo "✅<br>";

    $pdo->commit();
    echo "<h3 style='color:green;'>¡Prueba Exitosa! 🎉</h3>";
    echo "<p>El sistema ha guardado correctamente todos los campos nuevos en la base de datos.</p>";
    
    echo "<a href='ticket_view.php?id=$idVenta' target='_blank'>Ver Ticket Generado 📄</a> | ";
    echo "<a href='reportes_caja.php?id=$idSesion'>Ver Reporte de Caja 💰</a>";

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "<h3 style='color:red;'>❌ Error en la Prueba</h3>";
    echo "<p>Mensaje: " . $e->getMessage() . "</p>";
    echo "<p>Si recibes un error de 'Column not found', asegúrate de haber ejecutado los comandos SQL de ALTER TABLE previos.</p>";
}

