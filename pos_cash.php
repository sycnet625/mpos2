<?php
// ARCHIVO: /var/www/palweb/api/pos_cash.php
header('Content-Type: application/json');
ini_set('display_errors', 0);
require_once 'db.php';

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);

require_once 'config_loader.php';
$sucursalID = intval($config['id_sucursal']);

try {
    if ($action === 'login') {
        $pin = $input['pin'] ?? '';
        $cajeros = $config['cajeros'] ?? []; 
        $found = null;
        foreach ($cajeros as $c) {
            if ((string)$c['pin'] === (string)$pin) { $found = $c['nombre']; break; }
        }
        if ($found) echo json_encode(['status' => 'success', 'cajero' => $found]);
        else echo json_encode(['status' => 'error', 'msg' => 'PIN incorrecto']);
    }

    elseif ($action === 'status') {
        $stmt = $pdo->prepare("SELECT * FROM caja_sesiones WHERE estado = 'ABIERTA' AND id_sucursal = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$sucursalID]);
        $sesion = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($sesion) {
            // RECTIFICACIÓN: Obtener desglose real desglosando pagos mixtos
            $sqlPagos = "SELECT metodo_pago, SUM(monto_ajustado) as total
                         FROM (
                            /* Caso A: Detalle de pagos mixtos/simples registrados */
                            SELECT p.metodo_pago, 
                                   (p.monto * v.total / NULLIF((SELECT SUM(m2.monto) FROM ventas_pagos m2 WHERE m2.id_venta_cabecera = v.id), 0)) as monto_ajustado
                            FROM ventas_pagos p
                            JOIN ventas_cabecera v ON p.id_venta_cabecera = v.id
                            WHERE v.id_caja = ?
                            
                            UNION ALL
                            
                            /* Caso B: Tickets sin detalle en ventas_pagos (legacy) */
                            SELECT v.metodo_pago, 
                                   v.total as monto_ajustado
                            FROM ventas_cabecera v
                            WHERE v.id_caja = ?
                            AND NOT EXISTS (SELECT 1 FROM ventas_pagos p2 WHERE p2.id_venta_cabecera = v.id)
                         ) as desglose
                         WHERE metodo_pago != 'Mixto' AND metodo_pago IS NOT NULL
                         GROUP BY metodo_pago";
            
            $stmtV = $pdo->prepare($sqlPagos);
            $stmtV->execute([$sesion['id'], $sesion['id']]);
            $ventas = $stmtV->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'open', 'data' => $sesion, 'ventas' => $ventas]);
        } else {
            echo json_encode(['status' => 'closed']);
        }
    } 
    
    elseif ($action === 'open') {
        $cajero = $input['cajero'] ?? 'Admin';
        $monto = floatval($input['monto'] ?? 0);
        $fechaContable = !empty($input['fecha']) ? $input['fecha'] : date('Y-m-d'); 

        $stmt = $pdo->prepare("INSERT INTO caja_sesiones 
            (nombre_cajero, monto_inicial, fecha_contable, id_sucursal, estado, fecha_apertura) 
            VALUES (?, ?, ?, ?, 'ABIERTA', NOW())");
        
        if ($stmt->execute([$cajero, $monto, $fechaContable, $sucursalID])) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'msg' => 'No se pudo abrir el turno en la BD']);
        }
    } 

    elseif ($action === 'close') {
        // RECTIFICACIÓN: Incluir columna 'nota' y validación de ejecución
        $sql = "UPDATE caja_sesiones SET 
                    fecha_cierre = NOW(), 
                    monto_final_sistema = ?, 
                    monto_final_real = ?, 
                    diferencia = ?, 
                    nota = ?, 
                    estado = 'CERRADA' 
                WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        $diff = floatval($input['real'] ?? 0) - floatval($input['sistema'] ?? 0);
        
        if ($stmt->execute([
            floatval($input['sistema'] ?? 0), 
            floatval($input['real'] ?? 0), 
            $diff, 
            $input['nota'] ?? '', 
            intval($input['id'])
        ])) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'msg' => 'No se pudo actualizar la base de datos']);
        }
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
}


