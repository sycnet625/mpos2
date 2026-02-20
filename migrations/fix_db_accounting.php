<?php
// ARCHIVO: fix_db_accounting.php
// DESCRIPCIÓN: Actualiza la estructura de la tabla contable para soportar asientos automáticos.
require_once 'db.php';

try {
    echo "<h3>🛠️ Actualizando Base de Datos Contable...</h3>";

    // 1. Agregar columna 'asiento_id' (Identificador único del grupo de asientos)
    try {
        // Intentamos agregarla. Si falla es porque ya existe o hay otro error.
        $pdo->exec("ALTER TABLE `contabilidad_diario` ADD COLUMN `asiento_id` VARCHAR(30) NOT NULL DEFAULT '0' AFTER `id`");
        echo "✅ Columna <b>'asiento_id'</b> agregada.<br>";
        
        // Agregar índice para búsquedas rápidas
        $pdo->exec("ALTER TABLE `contabilidad_diario` ADD INDEX (`asiento_id`)");
        echo "✅ Índice para 'asiento_id' creado.<br>";
        
        // Actualizar registros viejos para que no tengan ID 0 (opcional, pone un ID único por fila)
        $pdo->exec("UPDATE `contabilidad_diario` SET asiento_id = CONCAT('OLD-', id) WHERE asiento_id = '0'");
        echo "✅ Registros antiguos actualizados.<br>";

    } catch (Exception $e) {
        // Ignoramos error si es "Duplicate column name"
        if (stripos($e->getMessage(), "Duplicate column") !== false) {
            echo "ℹ️ La columna 'asiento_id' ya existía.<br>";
        } else {
            echo "❌ Error en asiento_id: " . $e->getMessage() . "<br>";
        }
    }

    // 2. Agregar columna 'creado_por' (Auditoría de usuario)
    try {
        $pdo->exec("ALTER TABLE `contabilidad_diario` ADD COLUMN `creado_por` VARCHAR(50) NULL AFTER `haber`");
        echo "✅ Columna <b>'creado_por'</b> agregada.<br>";
    } catch (Exception $e) {
        if (stripos($e->getMessage(), "Duplicate column") !== false) {
            echo "ℹ️ La columna 'creado_por' ya existía.<br>";
        } else {
            echo "❌ Error en creado_por: " . $e->getMessage() . "<br>";
        }
    }

    echo "<hr><h4>🎉 ¡Base de datos reparada!</h4>";
    echo "<p>Ahora puedes volver a intentar contabilizar la sesión o factura.</p>";
    echo "<a href='sales_history.php'>Volver al Historial de Ventas</a>";

} catch (Exception $e) {
    die("Error Crítico de Conexión: " . $e->getMessage());
}
?>


