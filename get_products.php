<?php
// ARCHIVO: /var/www/palweb/api/get_products.php
ini_set('display_errors', 0);
header('Content-Type: application/json');
require_once 'db.php';

// 1. CARGAR CONFIGURACIÓN
require_once 'config_loader.php';

try {
    $almacenID = intval($config['id_almacen']);
    
    // 2. FILTROS
    $cond = ["p.activo = 1"];
    
    if (!$config['mostrar_materias_primas']) $cond[] = "p.es_materia_prima = 0";
    if (!$config['mostrar_servicios']) $cond[] = "p.es_servicio = 0";
    
    if (!empty($config['categorias_ocultas'])) {
        // Sanitización básica para IN clause
        $cats = array_map(function($c) use ($pdo) { return $pdo->quote($c); }, $config['categorias_ocultas']);
        $cond[] = "p.categoria NOT IN (" . implode(',', $cats) . ")";
    }

    $where = implode(" AND ", $cond);

    // 3. CONSULTA SQL (ADAPTADA A NUEVA ESTRUCTURA)
    // - Usamos 'p.codigo as id' para compatibilidad con el frontend JS
    // - JOIN con stock usando el CÓDIGO (string)
    $sql = "SELECT 
                p.codigo as id, 
                p.codigo, 
                p.nombre, 
                p.precio, 
                p.categoria, 
                p.es_elaborado, 
                p.es_servicio, 
                p.es_materia_prima,
                COALESCE(s.cantidad, 0) as stock
            FROM productos p 
            LEFT JOIN stock_almacen s ON p.codigo = s.id_producto AND s.id_almacen = ?
            WHERE $where 
            ORDER BY p.nombre";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$almacenID]);
    $prods = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. PROCESAMIENTO ADICIONAL
    $localPath = '/home/marinero/product_images/'; // Ajusta si tu ruta de imágenes es diferente
    
    foreach ($prods as &$p) {
        $p['precio'] = floatval($p['precio']);
        $p['stock']  = floatval($p['stock']);
        
        // Generar color aleatorio consistente basado en el nombre (para cuando no hay img)
        $p['color'] = '#' . substr(dechex(crc32($p['nombre'])), 0, 6);
        
        // Verificar imagen
        $p['has_image'] = file_exists($localPath . $p['codigo'] . '.jpg');
    }

    echo json_encode($prods);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Error DB: " . $e->getMessage()]);
}
?>

