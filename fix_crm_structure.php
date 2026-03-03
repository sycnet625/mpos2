<?php
// ARCHIVO: fix_crm_structure.php
// DESCRIPCIÓN: Repara la tabla clientes agregando las columnas faltantes para el CRM
require_once 'db.php';

try {
    echo "<h3>🛠️ Reparando Tabla de Clientes...</h3>";

    // 1. Agregar fecha_registro (Causa del Error Fatal)
    try {
        $pdo->exec("ALTER TABLE `clientes` ADD COLUMN `fecha_registro` DATETIME DEFAULT CURRENT_TIMESTAMP");
        echo "✅ Columna <b>'fecha_registro'</b> agregada.<br>";
    } catch (Exception $e) {
        echo "ℹ️ La columna 'fecha_registro' ya existía o hubo un aviso menor.<br>";
    }

    // 2. Agregar otras columnas críticas del CRM por si acaso faltan
    $columnas = [
        "ADD COLUMN `categoria` enum('Regular','VIP','Corporativo','Moroso','Empleado') DEFAULT 'Regular'",
        "ADD COLUMN `origen` varchar(50) DEFAULT 'Local'",
        "ADD COLUMN `notas` text DEFAULT NULL",
        "ADD COLUMN `fecha_nacimiento` date DEFAULT NULL",
        "ADD COLUMN `nit_ci` varchar(50) DEFAULT NULL",
        "ADD COLUMN `activo` tinyint(1) DEFAULT 1"
    ];

    foreach ($columnas as $sql) {
        try {
            $pdo->exec("ALTER TABLE `clientes` $sql");
        } catch (Exception $e) {
            // Ignoramos errores de "Duplicate column" silenciosamente
        }
    }

    echo "<hr><h4>🎉 Estructura Reparada</h4>";
    echo "<p>El error <i>Unknown column 'fecha_registro'</i> debería haber desaparecido.</p>";
    echo "<a href='crm_clients.php'>Volver al CRM</a>";

} catch (Exception $e) {
    die("Error de Conexión: " . $e->getMessage());
}
?>

