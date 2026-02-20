<?php
// ARCHIVO: /var/www/palweb/api/fix_db_sucursal.php
require_once 'db.php';

try {
    echo "<h2>🛠️ Actualizando Estructura Multi-Sucursal...</h2>";

    // 1. Agregar id_sucursal a stock_almacen
    try {
        $pdo->exec("ALTER TABLE stock_almacen ADD COLUMN id_sucursal INT DEFAULT 1");
        echo "✅ Columna 'id_sucursal' agregada a 'stock_almacen'.<br>";
    } catch (Exception $e) { echo "ℹ️ Columna 'id_sucursal' ya existía.<br>"; }

    // 2. Asegurarse de que exista en ventas_cabecera (por si acaso)
    try {
        $pdo->exec("ALTER TABLE ventas_cabecera ADD COLUMN id_sucursal INT DEFAULT 1");
        echo "✅ Columna 'id_sucursal' verificada en 'ventas_cabecera'.<br>";
    } catch (Exception $e) { echo "ℹ️ Columna 'id_sucursal' ya existe en ventas.<br>"; }

    echo "<h3>¡Base de datos lista!</h3>";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>

