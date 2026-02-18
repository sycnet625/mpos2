<?php
require_once 'db.php';
require_once 'kardex_engine.php';

// Producto específico a reconstruir
$producto_a_reconstruir = '660101';

try {
    $pdo->beginTransaction();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Iniciando reconstrucción de Kardex para el Producto: {$producto_a_reconstruir}...
";

    // 1. Eliminar entradas existentes para el producto en kardex y stock_almacen
    echo "  Eliminando entradas antiguas en kardex para producto {$producto_a_reconstruir}...
";
    $stmtDeleteKardex = $pdo->prepare("DELETE FROM kardex WHERE id_producto = ?");
    $stmtDeleteKardex->execute([$producto_a_reconstruir]);
    echo "  " . $stmtDeleteKardex->rowCount() . " entradas eliminadas de kardex.
";

    echo "  Eliminando entrada en stock_almacen para producto {$producto_a_reconstruir}...
";
    $stmtDeleteStock = $pdo->prepare("DELETE FROM stock_almacen WHERE id_producto = ?");
    $stmtDeleteStock->execute([$producto_a_reconstruir]);
    echo "  " . $stmtDeleteStock->rowCount() . " entradas eliminadas de stock_almacen.
";

    // 2. Obtener todos los movimientos relevantes de ventas_detalle (ventas y devoluciones)
    // Y otros movimientos directos del kardex (entradas, ajustes, etc.)
    $rawMovimientos = [];

    // Movimientos de Venta/Devolución (ventas_detalle)
    $stmtVentasDetalle = $pdo->prepare("SELECT 
                                        vd.id, 
                                        vc.fecha, 
                                        vd.cantidad as qty_original, 
                                        vc.id_almacen,
                                        vc.id_sucursal,
                                        'VENTA' as base_type, -- Tipo base para facilitar el manejo
                                        CONCAT('Venta #', vc.id, ' (', vc.metodo_pago, ')') as referencia
                                    FROM ventas_detalle vd
                                    JOIN ventas_cabecera vc ON vd.id_venta_cabecera = vc.id
                                    WHERE vd.id_producto = ?
                                    ORDER BY vc.fecha ASC, vd.id ASC");
    $stmtVentasDetalle->execute([$producto_a_reconstruir]);
    foreach ($stmtVentasDetalle->fetchAll(PDO::FETCH_ASSOC) as $mov) {
        $cantidad_kardex = floatval($mov['qty_original']);
        $tipo_movimiento_kardex = 'VENTA';

        if ($cantidad_kardex < 0) {
            // Si la cantidad en ventas_detalle es negativa, es una devolución.
            $cantidad_kardex = abs($cantidad_kardex); // La devolución suma stock
            $tipo_movimiento_kardex = 'DEVOLUCION';
        } else {
            // Si la cantidad es positiva, es una venta normal.
            $cantidad_kardex = -$cantidad_kardex; // La venta resta stock
        }

        $rawMovimientos[] = [
            'id' => $mov['id'],
            'fecha' => $mov['fecha'],
            'tipo' => $tipo_movimiento_kardex,
            'cantidad' => $cantidad_kardex,
            'referencia' => $mov['referencia'],
            'id_almacen' => $mov['id_almacen'],
            'id_sucursal' => $mov['id_sucursal']
        ];
    }

    // Movimientos directos del Kardex que no sean VENTA o DEVOLUCION (como ENTRADA, AJUSTE, etc.)
    $stmtOtrosKardex = $pdo->prepare("SELECT 
                                        id, 
                                        fecha, 
                                        tipo_movimiento, 
                                        cantidad, 
                                        referencia,
                                        id_almacen,
                                        id_sucursal
                                    FROM kardex 
                                    WHERE id_producto = ? AND tipo_movimiento IN ('ENTRADA', 'AJUSTE_POSITIVO', 'AJUSTE_NEGATIVO', 'TRANSFERENCIA', 'INVENTARIO_INICIAL')
                                    ORDER BY fecha ASC, id ASC");
    $stmtOtrosKardex->execute([$producto_a_reconstruir]);
    foreach ($stmtOtrosKardex->fetchAll(PDO::FETCH_ASSOC) as $mov) {
        $rawMovimientos[] = [
            'id' => $mov['id'],
            'fecha' => $mov['fecha'],
            'tipo' => $mov['tipo_movimiento'],
            'cantidad' => floatval($mov['cantidad']),
            'referencia' => $mov['referencia'],
            'id_almacen' => $mov['id_almacen'],
            'id_sucursal' => $mov['id_sucursal']
        ];
    }

    // Reordenar todos los movimientos por fecha y luego ID para asegurar la secuencia correcta
    usort($rawMovimientos, function($a, $b) {
        if ($a['fecha'] == $b['fecha']) {
            return $a['id'] <=> $b['id'];
        }
        return $a['fecha'] <=> $b['fecha'];
    });

    echo "  Procesando " . count($rawMovimientos) . " movimientos reconstruidos...
";

    $kardexEngine = new KardexEngine($pdo);
    $current_saldo = 0.00;

    foreach ($rawMovimientos as $movimiento) {
        $saldo_anterior = $current_saldo;
        $current_saldo += $movimiento['cantidad'];

        // Registrar el movimiento en Kardex
        $kardexEngine->registrarMovimiento(
            $pdo,
            $producto_a_reconstruir,
            $movimiento['id_almacen'],
            $movimiento['cantidad'],
            $movimiento['tipo'],
            $movimiento['referencia'],
            null, // costo_unitario, asumimos que viene del producto o no es crítico para el saldo
            $movimiento['id_sucursal'],
            $movimiento['fecha']
        );
    }

    $pdo->commit();
    echo "
Reconstrucción completada con éxito para el Producto: {$producto_a_reconstruir}.
";

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "
ERROR crítico durante la reconstrucción: " . $e->getMessage() . "
";
    error_log("Error en rebuild_kardex_product.php: " . $e->getMessage());
}
