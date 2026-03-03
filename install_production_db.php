<?php
// ARCHIVO: /var/www/palweb/api/install_production_db.php
require_once 'db.php';

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS recetas_cabecera (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_producto_final VARCHAR(50),
            nombre_receta VARCHAR(150),
            unidades_resultantes DECIMAL(10,2) DEFAULT 1,
            costo_total_lote DECIMAL(12,2) DEFAULT 0,
            costo_unitario DECIMAL(12,2) DEFAULT 0,
            descripcion TEXT,
            fecha_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            activo TINYINT DEFAULT 1,
            INDEX (id_producto_final)
        );

        CREATE TABLE IF NOT EXISTS recetas_detalle (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_receta INT,
            id_ingrediente VARCHAR(50),
            cantidad DECIMAL(12,4),
            costo_calculado DECIMAL(12,2),
            FOREIGN KEY (id_receta) REFERENCES recetas_cabecera(id) ON DELETE CASCADE
        );
    ");
    echo "<h1>✅ Tablas de Producción creadas correctamente.</h1>";
    echo "<a href='pos_production.php'>Ir al Módulo de Producción</a>";
} catch (Exception $e) {
    die("Error SQL: " . $e->getMessage());
}
?>
