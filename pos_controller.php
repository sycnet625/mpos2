<?php
// ARCHIVO: /var/www/palweb/api/pos_controller.php
header('Content-Type: text/html; charset=utf-8');
ini_set('display_errors', 0);
require_once 'db.php';

require_once 'config_loader.php';
require_once 'combo_helper.php';

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
    $almacenID  = intval($config['id_almacen']);
    $sucursalID = intval($config['id_sucursal']);

    // Categorías
    $stmtCat = $pdo->prepare("SELECT DISTINCT categoria FROM productos p WHERE $where ORDER BY categoria");
    $stmtCat->execute($params);
    $categorias = $stmtCat->fetchAll(PDO::FETCH_COLUMN);

    // Productos con precio específico por sucursal (fallback al precio principal)
    $sqlProd = "SELECT p.codigo, p.nombre, p.categoria, p.es_elaborado, p.es_servicio, COALESCE(p.es_combo, 0) AS es_combo,
                COALESCE(ps.precio_venta,    p.precio)            AS precio,
                COALESCE(ps.precio_mayorista, p.precio_mayorista) AS precio_mayorista,
                COALESCE(ps.precio_costo,    p.costo)             AS costo,
                COALESCE(s.cantidad, 0) AS stock
                FROM productos p
                LEFT JOIN productos_precios_sucursal ps
                       ON ps.codigo_producto = p.codigo AND ps.id_sucursal = $sucursalID
                LEFT JOIN stock_almacen s ON p.codigo = s.id_producto AND s.id_almacen = $almacenID
                WHERE $where
                ORDER BY p.nombre";
    
    $stmtProd = $pdo->prepare($sqlProd);
    $stmtProd->execute($params);
    $prods = $stmtProd->fetchAll(PDO::FETCH_ASSOC);
    $prods = combo_apply_product_rows($pdo, $prods, intval($config['id_empresa']), $almacenID);

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
