<?php
// ARCHIVO: update_refunds.php
require_once 'db.php';

try {
    echo "<h2>🔄 Actualizando BD para Reembolsos...</h2>";
    
    // Agregar columna 'reembolsado' en ventas_detalle
    try {
        $pdo->exec("ALTER TABLE ventas_detalle ADD COLUMN reembolsado TINYINT DEFAULT 0");
        echo "✅ Columna 'reembolsado' agregada.<br>";
    } catch (Exception $e) { echo "ℹ️ La columna ya existía.<br>"; }

    echo "<h3>¡Listo! Puedes borrar este archivo.</h3>";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>

