<?php
// ARCHIVO: /var/www/palweb/api/pos_historial.php
// Endpoint para obtener historial de tickets de la sesión de caja actual
header('Content-Type: application/json');
ini_set('display_errors', 0);
require_once 'db.php';

try {
    // 1. Cargar configuración
    require_once 'config_loader.php';
    
    $sucursalID = intval($config['id_sucursal']);
    
    // 2. Obtener sesión de caja activa
    $stmt = $pdo->prepare("SELECT * FROM caja_sesiones WHERE estado = 'ABIERTA' AND id_sucursal = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$sucursalID]);
    $sesion = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$sesion) {
        // Si no hay sesión abierta, obtener la última cerrada
        $stmt = $pdo->prepare("SELECT * FROM caja_sesiones WHERE id_sucursal = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$sucursalID]);
        $sesion = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$sesion) {
        echo json_encode(['status' => 'success', 'tickets' => [], 'detalles' => [], 'totales' => [
            'count' => 0, 'total' => 0, 'devoluciones' => 0, 'valor_devoluciones' => 0
        ]]);
        exit;
    }
    
    $idSesion = $sesion['id'];
    
    // 3. Obtener tickets de esta sesión
    $sqlTickets = "SELECT * FROM ventas_cabecera WHERE id_caja = ? AND id_sucursal = ? ORDER BY id DESC";
    $stmtT = $pdo->prepare($sqlTickets);
    $stmtT->execute([$idSesion, $sucursalID]);
    $tickets = $stmtT->fetchAll(PDO::FETCH_ASSOC);
    
    // 4. Obtener detalles de todos los tickets
    $sqlDetalles = "SELECT d.*, p.nombre, p.codigo, p.costo, p.categoria 
                    FROM ventas_detalle d
                    JOIN productos p ON d.id_producto = p.codigo 
                    JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id
                    WHERE v.id_caja = ? AND v.id_sucursal = ?";
    $stmtD = $pdo->prepare($sqlDetalles);
    $stmtD->execute([$idSesion, $sucursalID]);
    $detalles = $stmtD->fetchAll(PDO::FETCH_ASSOC);
    
    // 5. Calcular totales
    $totalVentaNeta = 0;
    $conteoTickets = 0;
    $cantDevoluciones = 0;
    $valorDevoluciones = 0;
    
    foreach ($tickets as $t) {
        $totalVentaNeta += floatval($t['total']);
        
        if (floatval($t['total']) < 0 || $t['cliente_nombre'] === 'DEVOLUCIÓN') {
            $cantDevoluciones++;
            $valorDevoluciones += abs(floatval($t['total']));
        } else {
            $conteoTickets++;
        }
    }
    
    // 6. Responder
    echo json_encode([
        'status' => 'success',
        'sesion' => [
            'id' => $idSesion,
            'estado' => $sesion['estado'],
            'cajero' => $sesion['nombre_cajero'],
            'fecha_apertura' => $sesion['fecha_apertura']
        ],
        'tickets' => $tickets,
        'detalles' => $detalles,
        'totales' => [
            'count' => $conteoTickets,
            'total' => $totalVentaNeta,
            'devoluciones' => $cantDevoluciones,
            'valor_devoluciones' => $valorDevoluciones
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>

