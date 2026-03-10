<?php
// AL INICIO DEL ARCHIVO
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'db.php';

header('Content-Type: application/json');

// Verificar si db.php se cargó correctamente
if (!isset($pdo)) {
    echo json_encode(['error' => 'PDO no está definido en db.php']);
    exit;
}

$EMP_ID = 1;
$SUC_ID = 1;
$ALM_ID = 1;

try {
    // Versión SIMPLE similar al buscador
    $sql = "SELECT p.codigo, p.nombre, p.precio, p.descripcion, p.categoria, 
                   p.unidad_medida, p.color, p.peso, p.tags,
                   COALESCE((SELECT SUM(cantidad) FROM stock_almacen 
                    WHERE id_producto = p.codigo AND id_almacen = :almacen), 0) as stock
            FROM productos p
            WHERE p.activo = 1 
              AND p.id_empresa = :empresa
              AND p.es_web = 1
              AND (p.sucursales_web IS NULL OR p.sucursales_web = '' OR FIND_IN_SET(:sucursal, p.sucursales_web) > 0)
            LIMIT 10";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':almacen' => $ALM_ID,
        ':empresa' => $EMP_ID,
        ':sucursal' => $SUC_ID
    ]);
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'count' => count($results),
        'products' => $results,
        'query' => $sql
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
