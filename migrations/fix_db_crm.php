<?php
// ARCHIVO: fix_db_crm.php
// DESCRIPCIÓN: Instala la tabla de clientes para el CRM
require_once 'db.php';

try {
    echo "<h3>🛠️ Instalando Módulo CRM...</h3>";

    $sql = "CREATE TABLE IF NOT EXISTS `clientes` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `nombre` varchar(150) NOT NULL,
      `telefono` varchar(50) DEFAULT NULL,
      `email` varchar(100) DEFAULT NULL,
      `direccion` varchar(255) DEFAULT NULL,
      `nit_ci` varchar(50) DEFAULT NULL,
      `fecha_nacimiento` date DEFAULT NULL,
      `categoria` enum('Regular','VIP','Corporativo','Moroso','Empleado') DEFAULT 'Regular',
      `origen` varchar(50) DEFAULT 'Local',
      `notas` text DEFAULT NULL,
      `fecha_registro` datetime DEFAULT CURRENT_TIMESTAMP,
      `activo` tinyint(1) DEFAULT 1,
      PRIMARY KEY (`id`),
      KEY `idx_nombre` (`nombre`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    $pdo->exec($sql);
    echo "✅ Tabla <b>'clientes'</b> creada o verificada correctamente.<br>";
    
    echo "<hr><h4>🎉 ¡Instalación completada!</h4>";
    echo "<a href='crm_clients.php'>Ir al CRM</a>";

} catch (Exception $e) {
    die("Error Crítico: " . $e->getMessage());
}
?>


