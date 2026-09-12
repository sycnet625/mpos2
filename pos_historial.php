<?php
// ARCHIVO: /var/www/palweb/api/pos_historial.php
// Endpoint para obtener historial de tickets de la sesión de caja actual
header('Content-Type: application/json');
ini_set('display_errors', 0);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once 'db.php';

try {
    // 1. Cargar configuración
    require_once 'config_loader.php';
    
    $empresaID = intval($_SESSION['id_empresa'] ?? $config['id_empresa'] ?? 1);
    $sucursalID = intval($_SESSION['id_sucursal'] ?? $config['id_sucursal']);
    $almacenID = intval($_SESSION['id_almacen'] ?? $config['id_almacen'] ?? 1);
    
    // 2. Obtener la sesión de caja real del POS si existe en la sesión PHP
    $idSesionActiva = isset($_SESSION['id_caja']) && is_numeric($_SESSION['id_caja']) ? (int)$_SESSION['id_caja'] : 0;
    $sesion = null;

    if ($idSesionActiva > 0) {
        $stmt = $pdo->prepare("SELECT * FROM caja_sesiones WHERE id = ? AND id_empresa = ? AND id_sucursal = ? AND id_almacen = ? LIMIT 1");
        $stmt->execute([$idSesionActiva, $empresaID, $sucursalID, $almacenID]);
        $sesion = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (!$sesion) {
        $stmt = $pdo->prepare("SELECT * FROM caja_sesiones WHERE estado = 'ABIERTA' AND id_empresa = ? AND id_sucursal = ? AND id_almacen = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$empresaID, $sucursalID, $almacenID]);
        $sesion = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    if (!$sesion) {
        // Si no hay sesión abierta, obtener la última cerrada
        $stmt = $pdo->prepare("SELECT * FROM caja_sesiones WHERE id_empresa = ? AND id_sucursal = ? AND id_almacen = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$empresaID, $sucursalID, $almacenID]);
        $sesion = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    if (!$sesion) {
        echo json_encode(['status' => 'success', 'tickets' => [], 'detalles' => [], 'totales' => [
            'count' => 0, 'total' => 0, 'devoluciones' => 0, 'valor_devoluciones' => 0
        ]]);
        exit;
    }
    
    $idSesion = $sesion['id'];
    
    // 3. Obtener tickets de esta sesión
    $sqlTickets = "SELECT v.*,
                          COALESCE(cs.id, v.id_sesion_caja, v.id_caja) AS sesion_ticket,
                          COALESCE(cs.nombre_cajero, :cajero_sesion) AS cajero_ticket,
                          COALESCE(cs.id_sucursal, v.id_sucursal) AS sucursal_ticket,
                          COALESCE(cs.id_almacen, v.id_almacen) AS almacen_ticket,
                          s.nombre AS sucursal_nombre,
                          a.nombre AS almacen_nombre
                   FROM ventas_cabecera v
                   LEFT JOIN caja_sesiones cs ON cs.id = v.id_sesion_caja
                   LEFT JOIN sucursales s ON s.id = COALESCE(cs.id_sucursal, v.id_sucursal)
                   LEFT JOIN almacenes a ON a.id = COALESCE(cs.id_almacen, v.id_almacen)
                   WHERE v.id_caja = :id_caja AND v.id_empresa = :id_empresa
                     AND v.id_sucursal = :id_sucursal AND v.id_almacen = :id_almacen
                   ORDER BY v.id DESC";
    $stmtT = $pdo->prepare($sqlTickets);
    $stmtT->execute([
        ':cajero_sesion' => $sesion['nombre_cajero'] ?? 'Sin asignar',
        ':id_caja' => $idSesion,
        ':id_empresa' => $empresaID,
        ':id_sucursal' => $sucursalID,
        ':id_almacen' => $almacenID
    ]);
    $tickets = $stmtT->fetchAll(PDO::FETCH_ASSOC);
    
    // 4. Obtener detalles de todos los tickets
    $sqlDetalles = "SELECT d.*, p.nombre, p.codigo, p.costo, p.categoria,
                          COALESCE(NULLIF(p.precio_mayorista, 0), NULLIF(ps.precio_mayorista, 0), d.precio) AS precio_mayorista
                    FROM ventas_detalle d
                    JOIN productos p ON d.id_producto = p.codigo 
                    JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id
                    LEFT JOIN productos_precios_sucursal ps ON ps.codigo_producto = d.id_producto AND ps.id_sucursal = ?
                    WHERE v.id_caja = ? AND v.id_empresa = ? AND v.id_sucursal = ? AND v.id_almacen = ?";
    $stmtD = $pdo->prepare($sqlDetalles);
    $stmtD->execute([$sucursalID, $idSesion, $empresaID, $sucursalID, $almacenID]);
    $detalles = $stmtD->fetchAll(PDO::FETCH_ASSOC);
    
    // 5. Calcular totales y métricas por ticket
    $totalVentaNeta = 0;
    $conteoTickets = 0;
    $cantDevoluciones = 0;
    $valorDevoluciones = 0;
    $articulosPorTicket = [];
    $unidadesPorTicket = [];
    
    foreach ($tickets as $t) {
        $monto = floatval($t['total']);
        if ($monto > 0 && $t['cliente_nombre'] !== 'DEVOLUCIÓN') {
            $totalVentaNeta += $monto;
            $conteoTickets++;
        } else {
            $cantDevoluciones++;
        }
    }
    
    foreach ($detalles as $d) {
        $ticketId = (string)$d['id_venta_cabecera'];
        $qty = floatval($d['cantidad']);
        $articulosPorTicket[$ticketId] = ($articulosPorTicket[$ticketId] ?? 0) + 1;
        $unidadesPorTicket[$ticketId] = ($unidadesPorTicket[$ticketId] ?? 0) + $qty;
        if ($qty < 0) {
            $valorDevoluciones += abs($qty * floatval($d['precio']));
        }
    }

    // Desglose de ingresos positivos por método de pago, incluyendo tickets legacy.
    $sqlPagos = "SELECT metodo_pago, SUM(monto_ajustado) AS total_metodo FROM (
                    SELECT p.metodo_pago,
                           (p.monto * v.total / NULLIF((SELECT SUM(p2.monto) FROM ventas_pagos p2 WHERE p2.id_venta_cabecera = v.id), 0)) AS monto_ajustado
                    FROM ventas_pagos p
                    JOIN ventas_cabecera v ON v.id = p.id_venta_cabecera
                    WHERE v.id_caja = ? AND v.id_empresa = ? AND v.id_sucursal = ? AND v.id_almacen = ? AND v.total > 0
                    UNION ALL
                    SELECT v.metodo_pago, v.total
                    FROM ventas_cabecera v
                    WHERE v.id_caja = ? AND v.id_empresa = ? AND v.id_sucursal = ? AND v.id_almacen = ? AND v.total > 0
                      AND NOT EXISTS (SELECT 1 FROM ventas_pagos p2 WHERE p2.id_venta_cabecera = v.id)
                 ) pagos GROUP BY metodo_pago";
    $stmtP = $pdo->prepare($sqlPagos);
    $stmtP->execute([$idSesion, $empresaID, $sucursalID, $almacenID, $idSesion, $empresaID, $sucursalID, $almacenID]);
    $pagos = ['efectivo' => 0, 'transferencia' => 0, 'tarjeta' => 0, 'otros' => 0];
    foreach ($stmtP->fetchAll(PDO::FETCH_ASSOC) as $pago) {
        $metodo = strtolower((string)$pago['metodo_pago']);
        $bucket = 'otros';
        if (strpos($metodo, 'efectivo') !== false) $bucket = 'efectivo';
        elseif (strpos($metodo, 'transfer') !== false) $bucket = 'transferencia';
        elseif (strpos($metodo, 'tarjeta') !== false) $bucket = 'tarjeta';
        $pagos[$bucket] += floatval($pago['total_metodo']);
    }

    // Detectar IDs dentro del rango numérico de la sesión que pertenecen a otra caja/sucursal.
    $alertasSesion = [];
    if ($tickets) {
        $minTicket = (int)min(array_column($tickets, 'id'));
        $maxTicket = (int)max(array_column($tickets, 'id'));
        $stmtFuera = $pdo->prepare("SELECT v.id, v.id_sucursal, v.id_almacen,
                                           COALESCE(s.nombre, CONCAT('Sucursal ', v.id_sucursal)) AS sucursal_nombre,
                                           COALESCE(a.nombre, CONCAT('Almacén ', v.id_almacen)) AS almacen_nombre
                                    FROM ventas_cabecera v
                                    LEFT JOIN sucursales s ON s.id = v.id_sucursal
                                    LEFT JOIN almacenes a ON a.id = v.id_almacen
                                    WHERE v.id BETWEEN ? AND ?
                                      AND (v.id_caja <> ? OR v.id_sesion_caja <> ? OR v.id_sucursal <> ?)
                                    ORDER BY v.id LIMIT 20");
        $stmtFuera->execute([$minTicket, $maxTicket, $idSesion, $idSesion, $sucursalID]);
        $fuera = $stmtFuera->fetchAll(PDO::FETCH_ASSOC);
        if ($fuera) {
            $alertasSesion[] = [
                'tipo' => 'tickets_fuera_sesion',
                'mensaje' => count($fuera) . ' ticket(s) dentro del rango numérico ' . $minTicket . '–' . $maxTicket . ' pertenecen a otra sesión o sucursal.',
                'tickets' => $fuera
            ];
        }
    }
    
    // 6. Responder
    echo json_encode([
        'status' => 'success',
        'sesion' => [
            'id' => $idSesion,
            'estado' => $sesion['estado'],
            'cajero' => $sesion['nombre_cajero'],
            'fecha_apertura' => $sesion['fecha_apertura'],
            'id_sucursal' => (int)$sesion['id_sucursal'],
            'id_almacen' => (int)$sesion['id_almacen'],
            'id_empresa' => (int)$sesion['id_empresa']
        ],
        'tickets' => $tickets,
        'detalles' => $detalles,
        'metricas_tickets' => [
            'articulos' => $articulosPorTicket,
            'unidades' => $unidadesPorTicket
        ],
        'alertas' => $alertasSesion,
        'totales' => [
            'count' => $conteoTickets,
            'total' => $totalVentaNeta,
            'devoluciones' => $cantDevoluciones,
            'valor_devoluciones' => $valorDevoluciones,
            'pagos' => $pagos
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>
