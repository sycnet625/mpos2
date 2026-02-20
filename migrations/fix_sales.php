<?php
// ARCHIVO: fix_sales.php
require 'db.php';

try {
    echo "<h1>🚑 Reparando Tabla de Ventas...</h1>";
    
    // Agregamos la columna metodo_pago si no existe
    $sql = "ALTER TABLE ventas_cabecera 
            ADD COLUMN metodo_pago VARCHAR(50) DEFAULT 'Efectivo' 
            AFTER total";
            
    $pdo->exec($sql);
    
    echo "<h3 style='color:green'>✅ ÉXITO: Columna 'metodo_pago' creada correctamente.</h3>";
    echo "<p>Ahora intenta subir las ventas desde la Tablet nuevamente.</p>";

} catch (Exception $e) {
    if (strpos($e->getMessage(), "Duplicate column") !== false) {
        echo "<h3 style='color:orange'>⚠️ La columna ya existía. No hace falta hacer nada.</h3>";
    } else {
        echo "<h3 style='color:red'>❌ Error: " . $e->getMessage() . "</h3>";
    }
}
?>

