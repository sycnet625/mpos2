<?php
// ARCHIVO: fix_crm_final.php
// DESCRIPCIÓN: Asegura que TODAS las columnas del CRM existan en la tabla clientes.
require_once 'db.php';

try {
    echo "<h3>🛠️ Reparación Profunda de Estructura CRM...</h3>";

    // Lista completa de columnas requeridas por crm_clients.php
    $columnasRequeridas = [
        "ADD COLUMN `telefono` varchar(50) DEFAULT NULL",
        "ADD COLUMN `email` varchar(100) DEFAULT NULL",
        "ADD COLUMN `direccion` varchar(255) DEFAULT NULL",
        "ADD COLUMN `nit_ci` varchar(50) DEFAULT NULL",
        "ADD COLUMN `fecha_nacimiento` date DEFAULT NULL",
        "ADD COLUMN `categoria` enum('Regular','VIP','Corporativo','Moroso','Empleado') DEFAULT 'Regular'",
        "ADD COLUMN `origen` varchar(50) DEFAULT 'Local'",
        "ADD COLUMN `notas` text DEFAULT NULL",
        "ADD COLUMN `fecha_registro` datetime DEFAULT CURRENT_TIMESTAMP",
        "ADD COLUMN `activo` tinyint(1) DEFAULT 1"
    ];

    $cambios = 0;

    foreach ($columnasRequeridas as $sql) {
        try {
            // Intentamos ejecutar el ALTER TABLE
            $pdo->exec("ALTER TABLE `clientes` $sql");
            echo "<span style='color:green'>✅ Ejecutado: $sql</span><br>";
            $cambios++;
        } catch (Exception $e) {
            // Si falla, verificamos si es porque ya existe
            if (stripos($e->getMessage(), "Duplicate column") !== false) {
                // Es normal, ya existía. No hacemos nada.
                // echo "<span style='color:gray'>ℹ️ Columna ya existe (Saltado)</span><br>";
            } else {
                // Si es otro error, lo mostramos
                echo "<span style='color:red'>❌ Error: " . $e->getMessage() . "</span><br>";
            }
        }
    }

    if ($cambios == 0) {
        echo "<br><b>Nota:</b> Parece que tu tabla ya tenía todas las columnas necesarias.<br>";
    } else {
        echo "<br><b>¡Se agregaron $cambios columnas faltantes!</b><br>";
    }

    echo "<hr><h4>🎉 Base de Datos Actualizada</h4>";
    echo "<p>Ahora la columna 'email' y las demás están listas.</p>";
    echo "<a href='crm_clients.php' style='font-size:20px; font-weight:bold;'>👉 VOLVER AL CRM E INTENTAR GUARDAR</a>";

} catch (Exception $e) {
    die("Error Crítico de Conexión: " . $e->getMessage());
}
?>

