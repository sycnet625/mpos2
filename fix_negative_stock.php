<?php
require_once 'db.php';
require_once 'kardex_engine.php';

try {
    $pdo->beginTransaction();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Iniciando corrección de saldos negativos en stock_almacen y kardex...
";

    $kardexEngine = new KardexEngine($pdo);
    $fechaAjuste = date('Y-m-d H:i:s');
    $usuarioAjuste = 'Sistema (Corrección Saldos Negativos)';

    // 1. Encontrar productos con stock negativo
    $stmtNegativeStock = $pdo->query("SELECT id_producto, id_almacen, cantidad FROM stock_almacen WHERE cantidad < 0 FOR UPDATE");
    $negativeStocks = $stmtNegativeStock->fetchAll(PDO::FETCH_ASSOC);

    if (empty($negativeStocks)) {
        echo "No se encontraron productos con stock negativo. Proceso completado.
";
        $pdo->commit();
        exit;
    }

    echo "Se encontraron " . count($negativeStocks) . " productos con stock negativo.
";

    foreach ($negativeStocks as $stock) {
        $producto_id = $stock['id_producto'];
        $almacen_id = $stock['id_almacen'];
        $cantidad_negativa = floatval($stock['cantidad']);

        echo "  Corrigiendo Producto {$producto_id} en Almacén {$almacen_id} (Cantidad actual: {$cantidad_negativa})...
";

        // Calcular la cantidad necesaria para llevar a cero
        $cantidad_ajuste = abs($cantidad_negativa); 

        // Registrar el movimiento de ajuste en Kardex
        // Aquí la cantidad es POSITIVA para 'sumar' y llevar a cero
        $kardexEngine->registrarMovimiento(
            $pdo,
            $producto_id,
            $almacen_id,
            $cantidad_ajuste, // Cantidad positiva para el ajuste
            'AJUSTE_POSITIVO', // Tipo de movimiento
            "Corrección de stock negativo (a 0) - Ajuste {$usuarioAjuste}",
            null, // Costo: Usar el del sistema
            null, // Sucursal: Detectar automática
            $fechaAjuste
        );
        
        echo "  Movimiento AJUSTE_POSITIVO registrado en Kardex para Producto {$producto_id}, Almacén {$almacen_id}.
";
    }

    $pdo->commit();
    echo "
Proceso de corrección de saldos negativos completado con éxito.
";

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "
ERROR crítico al corregir saldos negativos: " . $e->getMessage() . "
";
    error_log("Error en fix_negative_stock.php: " . $e->getMessage());
}
