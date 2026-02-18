<?php
require_once 'db.php';

try {
    $pdo->beginTransaction();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Iniciando corrección de fechas en Kardex para movimientos de VENTA...\n";

    // Actualizar la columna 'fecha' en kardex para los movimientos de tipo 'VENTA'
    // Se busca el número de venta en la columna 'referencia' y se usa para unir con 'ventas_cabecera'
    // Se extrae el ID de la referencia de forma segura.
    $sqlUpdateFechaKardex = "UPDATE kardex k
                             JOIN ventas_cabecera vc ON CAST(TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(k.referencia, '#', -1), ' ', 1)) AS UNSIGNED) = vc.id
                             SET k.fecha = vc.fecha
                             WHERE k.tipo_movimiento = 'VENTA'
                               AND k.referencia LIKE 'Venta #%' 
                               AND k.referencia NOT LIKE '%(DEV)%' -- Evitar referencias de devoluciones si las hay en VENTA tipo";

    $stmtUpdate = $pdo->prepare($sqlUpdateFechaKardex);
    $stmtUpdate->execute();

    $rowCount = $stmtUpdate->rowCount();
    echo "Se han actualizado {$rowCount} registros en la tabla kardex para movimientos de VENTA.\n";

    $pdo->commit();
    echo "Proceso de corrección de fechas en Kardex completado con éxito.\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "\nERROR crítico al corregir fechas en Kardex: " . $e->getMessage() . "\n";
    error_log("Error en update_kardex_venta_dates.php: " . $e->getMessage());
}
