<?php
// ARCHIVO: fix_db_messengers.php
// DESCRIPCIÓN: Agrega soporte para Mensajeros en la tabla de clientes
require_once 'db.php';

try {
    echo "<h3>🛠️ Actualizando Tabla Clientes para Mensajería...</h3>";

    $updates = [
        "ADD COLUMN `es_mensajero` TINYINT(1) DEFAULT 0",
        "ADD COLUMN `vehiculo` VARCHAR(50) DEFAULT NULL",
        "ADD COLUMN `matricula` VARCHAR(20) DEFAULT NULL"
    ];

    foreach ($updates as $sql) {
        try {
            $pdo->exec("ALTER TABLE `clientes` $sql");
            echo "✅ Columna agregada: $sql<br>";
        } catch (Exception $e) {
            // Ignorar si ya existe
        }
    }

    echo "<hr><h4>🎉 Base de datos actualizada</h4>";
    echo "<a href='crm_clients.php'>Ir al CRM</a>";

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>

