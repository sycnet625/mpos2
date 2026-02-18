<?php
require_once 'db.php';

try {
    $pdo->beginTransaction();

    echo "Corrigiendo fechas de ventas_cabecera...
";
    // Actualizar ventas_cabecera usando la fecha_contable de su sesión
    $sqlVentas = "UPDATE ventas_cabecera v
                  JOIN caja_sesiones c ON v.id_caja = c.id
                  SET v.fecha = CONCAT(c.fecha_contable, ' ', TIME(v.fecha))
                  WHERE v.id_caja > 0";
    $affectedVentas = $pdo->exec($sqlVentas);
    echo "Ventas actualizadas: $affectedVentas
";

    echo "Corrigiendo fechas de kardex...
";
    // Actualizar kardex para movimientos de VENTA. 
    // Buscamos el ID de venta en la referencia "Venta #ID"
    $sqlKardexVentas = "UPDATE kardex k
                        JOIN ventas_cabecera v ON k.referencia LIKE CONCAT('Venta #', v.id, ' %')
                        SET k.fecha = v.fecha
                        WHERE k.tipo_movimiento = 'VENTA'";
    $affectedKardex = $pdo->exec($sqlKardexVentas);
    echo "Movimientos de Kardex (Ventas) actualizados: $affectedKardex
";

    // Para devoluciones
    $sqlKardexDevs = "UPDATE kardex k
                      JOIN ventas_cabecera v ON k.referencia LIKE CONCAT('Devolución Venta #', v.id, '%')
                      OR k.referencia LIKE CONCAT('Anulación Venta #', v.id, '%')
                      SET k.fecha = v.fecha
                      WHERE k.tipo_movimiento = 'DEVOLUCION'";
    $affectedDevs = $pdo->exec($sqlKardexDevs);
    echo "Movimientos de Kardex (Devoluciones) actualizados: $affectedDevs
";

    $pdo->commit();
    echo "Proceso completado con éxito.
";
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "ERROR: " . $e->getMessage() . "
";
}
