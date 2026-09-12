<?php
// ARCHIVO: pos_kitchen_api.php
header('Content-Type: application/json');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once 'db.php';
require_once 'config_loader.php';

$idEmpresa = (int)($_SESSION['id_empresa'] ?? $config['id_empresa'] ?? 1);
$idSucursal = (int)($_SESSION['id_sucursal'] ?? $config['id_sucursal'] ?? 1);
$idAlmacen = (int)($_SESSION['id_almacen'] ?? $config['id_almacen'] ?? 1);

$action = $_GET['action'] ?? 'list';

try {
    // LISTAR COMANDAS PENDIENTES O EN PROCESO
    if ($action === 'list') {
        // 1. Obtener Mapa de Productos -> Categorías
        // En este esquema, la categoría ya está en el campo 'categoria' de productos.
        $sqlCats = "SELECT nombre, categoria FROM productos
                    WHERE id_empresa = ? AND id_sucursal_origen = ?";
        $stmtCats = $pdo->prepare($sqlCats);
        $stmtCats->execute([$idEmpresa, $idSucursal]);
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
                INNER JOIN ventas_cabecera v ON c.id_venta = v.id
                WHERE c.estado != 'entregado' AND c.estado != 'cancelado'
                  AND v.id_empresa = ? AND v.id_sucursal = ? AND v.id_almacen = ?
                ORDER BY c.fecha_creacion ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$idEmpresa, $idSucursal, $idAlmacen]);
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
                WHERE id = ? AND EXISTS (
                    SELECT 1 FROM ventas_cabecera v
                    WHERE v.id = comandas.id_venta AND v.id_empresa = ?
                      AND v.id_sucursal = ? AND v.id_almacen = ?
                )";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$estado, $estado, $estado, $id, $idEmpresa, $idSucursal, $idAlmacen]);
        echo json_encode(['status' => 'success']);
    }

    // HISTORIAL DE COMANDAS (NUEVO)
    elseif ($action === 'history') {
        $limit = 50;
        $sql = "SELECT c.*, v.mensajero_nombre, v.tipo_servicio 
                FROM comandas c
                JOIN ventas_cabecera v ON c.id_venta = v.id
                WHERE c.estado = 'entregado'
                  AND v.id_empresa = ? AND v.id_sucursal = ? AND v.id_almacen = ?
                ORDER BY c.fecha_fin DESC
                LIMIT $limit";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$idEmpresa, $idSucursal, $idAlmacen]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
