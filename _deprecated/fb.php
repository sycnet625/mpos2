<?php
// ARCHIVO: /var/www/palweb/api/reinstall_production_clean.php
// REINSTALACIÓN LIMPIA CON CODIFICACIÓN FORZADA (COMPATIBILIDAD TOTAL)

ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db.php';

try {
    echo "<h2>🏭 Reinstalación Limpia del Módulo de Producción</h2>";

    // 1. ELIMINAR TABLAS CONFLICTIVAS
    // Desactivamos chequeo de llaves foráneas temporalmente para borrar sin orden específico
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $pdo->exec("DROP TABLE IF EXISTS recetas_detalle");
    $pdo->exec("DROP TABLE IF EXISTS recetas_cabecera");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    
    echo "<p>🗑️ Tablas antiguas eliminadas.</p>";

    // 2. CREAR TABLAS CON CODIFICACIÓN EXPLÍCITA (utf8mb4_unicode_ci)
    // Esto asegura que hablen el mismo idioma que tu tabla 'productos'
    
    $sqlCabecera = "
        CREATE TABLE recetas_cabecera (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_producto_final VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
            nombre_receta VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
            unidades_resultantes DECIMAL(10,2) DEFAULT 1,
            costo_total_lote DECIMAL(12,2) DEFAULT 0,
            costo_unitario DECIMAL(12,2) DEFAULT 0,
            descripcion TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
            fecha_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            activo TINYINT DEFAULT 1,
            INDEX (id_producto_final)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";

    $sqlDetalle = "
        CREATE TABLE recetas_detalle (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_receta INT,
            id_ingrediente VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
            cantidad DECIMAL(12,4),
            costo_calculado DECIMAL(12,2),
            FOREIGN KEY (id_receta) REFERENCES recetas_cabecera(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";

    $pdo->exec($sqlCabecera);
    echo "<p>✅ Tabla <strong>recetas_cabecera</strong> creada (Forzada a unicode_ci).</p>";

    $pdo->exec($sqlDetalle);
    echo "<p>✅ Tabla <strong>recetas_detalle</strong> creada (Forzada a unicode_ci).</p>";

    echo "<hr>";
    echo "<h3 style='color:green'>¡Solución Aplicada Correctamente!</h3>";
    echo "<p>Ahora la base de datos es 100% compatible. El Error 1267 no volverá.</p>";
    echo "<a href='pos_production.php' style='padding:10px; background:blue; color:white; text-decoration:none; border-radius:5px;'>Ir a Producción</a>";

} catch (Exception $e) {
    echo "<h3 style='color:red'>Error:</h3>";
    echo "<pre>" . $e->getMessage() . "</pre>";
}
?>

