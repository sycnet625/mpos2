<?php
// ARCHIVO: /var/www/palweb/api/pos_controller.php
header('Content-Type: text/html; charset=utf-8');
ini_set('display_errors', 0);
require_once 'db.php';

require_once 'config_loader.php';

// Carga de Datos (Optimizado y Seguro)
try {
    $params = [];
    $cond = ["p.activo = 1"];
    
    // --- NUEVO: FILTROS ESTRICTOS (No Servicios, No Materias Primas) ---
    $cond[] = "p.es_materia_prima = 0";
    $cond[] = "p.es_servicio = 0";
    // ------------------------------------------------------------------

    // Filtros dinámicos de categoría (Anti-Inyección SQL)
    if (!empty($config['categorias_ocultas'])) {
        $placeholders = implode(',', array_fill(0, count($config['categorias_ocultas']), '?'));
        $cond[] = "p.categoria NOT IN ($placeholders)";
        $params = $config['categorias_ocultas'];
    }

    $where = implode(" AND ", $cond);
    $almacenID = intval($config['id_almacen']);

    // Categorías
    $stmtCat = $pdo->prepare("SELECT DISTINCT categoria FROM productos p WHERE $where ORDER BY categoria");
    $stmtCat->execute($params);
    $categorias = $stmtCat->fetchAll(PDO::FETCH_COLUMN);

    // Productos (Una sola consulta optimizada)
    $sqlProd = "SELECT p.id, p.codigo, p.nombre, p.precio, p.categoria, p.es_elaborado, p.es_servicio,
                COALESCE(s.cantidad, 0) as stock
                FROM productos p 
                LEFT JOIN stock_almacen s ON p.id = s.id_producto AND s.id_almacen = $almacenID
                WHERE $where 
                AND p.id IN (SELECT MAX(id) FROM productos GROUP BY codigo) 
                ORDER BY p.nombre";
    
    $stmtProd = $pdo->prepare($sqlProd);
    $stmtProd->execute($params);
    $prods = $stmtProd->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) { 
    die("Error Crítico BD: " . $e->getMessage()); 
}

// Procesamiento visual
$localPath = '/var/www/assets/product_images/';
foreach ($prods as &$p) {
    $p['has_image'] = file_exists($localPath . $p['codigo'] . '.jpg');
    $p['color'] = '#' . substr(dechex(crc32($p['nombre'])), 0, 6);
    $p['stock'] = floatval($p['stock']);
} unset($p);
?>
