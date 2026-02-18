<?php
require_once 'db.php';

try {
    $pdo->beginTransaction();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Iniciando corrección de saldos en la tabla kardex...
";

    // 1. Obtener todas las combinaciones únicas de producto y almacén
    $stmtCombinaciones = $pdo->query("SELECT DISTINCT id_producto, id_almacen FROM kardex");
    $combinaciones = $stmtCombinaciones->fetchAll(PDO::FETCH_ASSOC);

    $totalProductosAlmacenes = count($combinaciones);
    echo "Se encontraron {$totalProductosAlmacenes} combinaciones únicas de producto/almacén.
";
    
    $processedCount = 0;

    foreach ($combinaciones as $combinacion) {
        $producto_id = $combinacion['id_producto'];
        $almacen_id = $combinacion['id_almacen'];
        $processedCount++;

        echo "
Procesando [$processedCount/$totalProductosAlmacenes]: Producto {$producto_id}, Almacén {$almacen_id}...
";

        // 2. Obtener todos los movimientos para esta combinación, ordenados cronológicamente
        $stmtMovimientos = $pdo->prepare("SELECT id, cantidad FROM kardex 
                                            WHERE id_producto = ? AND id_almacen = ? 
                                            ORDER BY fecha ASC, id ASC FOR UPDATE");
        $stmtMovimientos->execute([$producto_id, $almacen_id]);
        $movimientos = $stmtMovimientos->fetchAll(PDO::FETCH_ASSOC);

        $current_saldo = 0.00;

        if (empty($movimientos)) {
            echo "  Sin movimientos para esta combinación, saltando.
";
            continue;
        }

        echo "  Recalculando saldos para " . count($movimientos) . " movimientos...
";

        foreach ($movimientos as $index => $movimiento) {
            $id_kardex = $movimiento['id'];
            $cantidad = floatval($movimiento['cantidad']);

            $saldo_anterior = $current_saldo;
            $current_saldo += $cantidad;
            $nuevo_saldo_actual = $current_saldo;

            // 3. Actualizar el registro de kardex
            $stmtUpdate = $pdo->prepare("UPDATE kardex 
                                        SET saldo_anterior = ?, saldo_actual = ? 
                                        WHERE id = ?");
            $stmtUpdate->execute([$saldo_anterior, $nuevo_saldo_actual, $id_kardex]);

            if (($index + 1) % 100 === 0) {
                echo "  " . ($index + 1) . " movimientos actualizados.
";
            }
        }
        echo "  Todos los movimientos para Producto {$producto_id}, Almacén {$almacen_id} actualizados.
";
        
        // 4. Actualizar el stock_almacen para el saldo final
        $stmtUpdateStock = $pdo->prepare("INSERT INTO stock_almacen (id_almacen, id_producto, cantidad, id_sucursal, ultima_actualizacion)
                                           SELECT ?, ?, ?, id_sucursal, NOW() FROM almacenes WHERE id = ?
                                           ON DUPLICATE KEY UPDATE cantidad = ?, ultima_actualizacion = NOW()");
        $stmtUpdateStock->execute([
            $almacen_id,
            $producto_id,
            $current_saldo, // El saldo final recalculado
            $almacen_id,
            $current_saldo
        ]);
        echo "  Stock final en stock_almacen actualizado a {$current_saldo}.
";
    }

    $pdo->commit();
    echo "
Proceso de corrección de saldos en kardex completado con éxito.
";
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "
ERROR crítico: " . $e->getMessage() . "
";
    error_log("Error en fix_kardex_saldos.php: " . $e->getMessage());
}

