<?php
// ARCHIVO: pos_kitchen_api.php
header('Content-Type: application/json');
require_once 'db.php';

$action = $_GET['action'] ?? 'list';

try {
    // LISTAR COMANDAS PENDIENTES O EN PROCESO
    if ($action === 'list') {
        // 1. Obtener Mapa de Productos -> Categorías
        // En este esquema, la categoría ya está en el campo 'categoria' de productos.
        $sqlCats = "SELECT nombre, categoria FROM productos";
        $stmtCats = $pdo->query($sqlCats);
        $productMap = [];
        while ($row = $stmtCats->fetch(PDO::FETCH_ASSOC)) {
            $productMap[$row['nombre']] = $row['categoria'] ?? 'Otros';
        }

        // 2. Obtener Comandas Activas
        $sql = "SELECT c.*, 
                       COALESCE(v.mensajero_nombre, '') as mensajero_nombre, 
                       COALESCE(v.tipo_servicio, 'mostrador') as tipo_servicio, 
                       v.fecha as fecha_venta
                FROM comandas c
                LEFT JOIN ventas_cabecera v ON c.id_venta = v.id
                WHERE c.estado != 'entregado' AND c.estado != 'cancelado'
                ORDER BY c.fecha_creacion ASC";
        $stmt = $pdo->query($sql);
        $comandas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($comandas as &$c) {
            if (empty($c['items_json'])) $c['items_json'] = '[]';
        }

        echo json_encode([
            'comandas' => $comandas,
            'product_map' => $productMap
        ]);
        exit;
    }
    
    // CAMBIAR ESTADO
    elseif ($action === 'update') {
        $id = intval($_GET['id']);
        $estado = $_GET['status']; // pendiente -> elaboracion -> terminado -> entregado
        
        $sql = "UPDATE comandas SET estado = ?, 
                fecha_inicio = (CASE WHEN ? = 'elaboracion' THEN NOW() ELSE fecha_inicio END),
                fecha_fin = (CASE WHEN ? = 'terminado' THEN NOW() ELSE fecha_fin END)
                WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$estado, $estado, $estado, $id]);
        echo json_encode(['status' => 'success']);
    }

    // HISTORIAL DE COMANDAS (NUEVO)
    elseif ($action === 'history') {
        $limit = 50;
        $sql = "SELECT c.*, v.mensajero_nombre, v.tipo_servicio 
                FROM comandas c
                JOIN ventas_cabecera v ON c.id_venta = v.id
                WHERE c.estado = 'entregado' 
                ORDER BY c.fecha_fin DESC
                LIMIT $limit";
        $stmt = $pdo->query($sql);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>

