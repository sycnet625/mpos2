<?php
// ARCHIVO: /var/www/palweb/api/fix_shop.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>🛠️ Diagnóstico y Reparación de Tienda</h1>";

require_once 'db.php';

try {
    // 1. REPARAR BASE DE DATOS (Agregar columnas faltantes)
    echo "<h3>1. Verificando Base de Datos...</h3>";
    
    // Intentamos agregar las columnas. Si ya existen, MySQL ignorará el error o usaremos IGNORE/Check
    $cols = $pdo->query("SHOW COLUMNS FROM productos LIKE 'es_materia_prima'")->fetch();
    if (!$cols) {
        $pdo->exec("ALTER TABLE productos ADD COLUMN es_materia_prima TINYINT(1) DEFAULT 0");
        echo "✅ Columna 'es_materia_prima' creada.<br>";
    } else {
        echo "ℹ️ Columna 'es_materia_prima' ya existe.<br>";
    }

    $cols2 = $pdo->query("SHOW COLUMNS FROM productos LIKE 'es_servicio'")->fetch();
    if (!$cols2) {
        $pdo->exec("ALTER TABLE productos ADD COLUMN es_servicio TINYINT(1) DEFAULT 0");
        echo "✅ Columna 'es_servicio' creada.<br>";
    } else {
        echo "ℹ️ Columna 'es_servicio' ya existe.<br>";
    }

    // 2. VERIFICAR PERMISOS DE IMÁGENES
    echo "<hr><h3>2. Verificando Acceso a Imágenes...</h3>";
    $path = '/home/marinero/product_images/';
    
    // Ver si la restricción open_basedir está activa
    $basedir = ini_get('open_basedir');
    if ($basedir) {
        echo "⚠️ <strong>ALERTA:</strong> PHP tiene activado 'open_basedir'. Solo puede leer en: $basedir<br>";
        echo "❌ No podrá leer '/home/marinero/' a menos que lo agregues a la configuración de PHP.<br>";
    } else {
        echo "✅ 'open_basedir' está desactivado (Bien).<br>";
    }

    if (is_dir($path)) {
        echo "✅ La carpeta '$path' existe.<br>";
        if (is_readable($path)) {
            echo "✅ PHP tiene permisos de LECTURA en la carpeta (Perfecto).<br>";
        } else {
            echo "❌ PHP ve la carpeta pero <strong>NO TIENE PERMISO</strong> para leerla. Ejecuta: <code>sudo chmod -R 755 $path</code><br>";
        }
    } else {
        echo "❌ PHP no encuentra la carpeta '$path'. Verifica la ruta.<br>";
    }

    echo "<hr><h2 style='color:green'>Diagnóstico Finalizado. Intenta abrir shop.php ahora.</h2>";

} catch (Exception $e) {
    echo "<h1 style='color:red'>ERROR FATAL SQL: " . $e->getMessage() . "</h1>";
}
?>

