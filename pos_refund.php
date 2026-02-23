<?php
// ARCHIVO: /var/www/palweb/api/pos_refund.php
// VERSIÓN: 4.5 - FINAL (Devoluciones con Kardex y Ajuste de Caja)

// 1. Configuración de Errores
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

session_start();

// 2. Validación de Sesión
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'msg' => 'Sesión no autorizada']);
    exit;
}

require_once 'db.php';
require_once 'pos_audit.php';

// Verificar motor de inventario
$kardexAvailable = false;
if (file_exists('kardex_engine.php')) {
    require_once 'kardex_engine.php';
    if (class_exists('KardexEngine')) {
        $kardexAvailable = true;
    }
}

try {
    // 3. Obtener Datos
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    if (!$input) throw new Exception("Datos de entrada inválidos");

    // Determinar si es devolución por Ítem o por Ticket completo
    $idDetalle = isset($input['id']) ? intval($input['id']) : 0;
    $idTicket  = isset($input['ticket_id']) ? intval($input['ticket_id']) : 0;

    if ($idDetalle <= 0 && $idTicket <= 0) {
        throw new Exception("Se requiere ID de detalle o ID de ticket");
    }

    // 4. Cargar Configuración (Almacén/Sucursal)
    $configFile = __DIR__ . '/pos.cfg';
    $config = ["id_almacen" => 1, "id_sucursal" => 1];
    if (file_exists($configFile)) {
        $loaded = json_decode(file_get_contents($configFile), true);
        if ($loaded) $config = array_merge($config, $loaded);
    }

    // INICIAR TRANSACCIÓN
    $pdo->beginTransaction();
    $kardex = ($kardexAvailable) ? new KardexEngine($pdo) : null;
    $usuarioNombre = $_SESSION['admin_user'] ?? 'Sistema';

    // =================================================================================
    // CASO A: DEVOLUCIÓN DE UN SOLO ÍTEM (Desde el detalle del modal)
    // =================================================================================
    if ($idDetalle > 0) {
        // Obtener info del detalle y su cabecera
        $sql = "SELECT d.*, v.id_almacen, v.id_sucursal, v.id as id_ticket, p.es_servicio 
                FROM ventas_detalle d 
                JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id
                LEFT JOIN productos p ON d.id_producto = p.codigo
                WHERE d.id = ? FOR UPDATE";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$idDetalle]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) throw new Exception("El producto de la venta no existe.");
        
        // Validación: No devolver lo que ya está en negativo
        if (floatval($item['cantidad']) < 0) {
            throw new Exception("Este ítem ya fue devuelto anteriormente.");
        }

        // 1. Revertir Stock (Si no es servicio)
        if ($kardex && $item['es_servicio'] == 0) {
            $kardex->registrarMovimiento(
                $pdo,
                $item['id_producto'],
                $item['id_almacen'],
                floatval($item['cantidad']), // Cantidad POSITIVA = Entrada al almacén
                'DEVOLUCION',
                "Devolución Venta #{$item['id_ticket']}",
                null,
                $item['id_sucursal'],
                date('Y-m-d H:i:s')
            );
        }

        // 2. Actualizar Detalle (Marcar negativo para que no sume)
        // Convertimos la cantidad y el precio a negativo visualmente o solo cantidad
        $sqlUpdateDet = "UPDATE ventas_detalle SET cantidad = -ABS(cantidad), nombre_producto = CONCAT(nombre_producto, ' (DEV)') WHERE id = ?";
        $stmtUpd = $pdo->prepare($sqlUpdateDet);
        $stmtUpd->execute([$idDetalle]);

        // 3. Actualizar Cabecera (Restar del total)
        $montoRestar = floatval($item['cantidad']) * floatval($item['precio']);
        $sqlUpdateCab = "UPDATE ventas_cabecera SET total = total - ? WHERE id = ?";
        $stmtUpdCab = $pdo->prepare($sqlUpdateCab);
        $stmtUpdCab->execute([$montoRestar, $item['id_ticket']]);
    }

    // =================================================================================
    // CASO B: DEVOLUCIÓN DE TICKET COMPLETO (Opción "Devolver Todo")
    // =================================================================================
    elseif ($idTicket > 0) {
        // Obtener Cabecera
        $stmtCab = $pdo->prepare("SELECT * FROM ventas_cabecera WHERE id = ? FOR UPDATE");
        $stmtCab->execute([$idTicket]);
        $venta = $stmtCab->fetch(PDO::FETCH_ASSOC);

        if (!$venta) throw new Exception("Venta no encontrada");
        if (floatval($venta['total']) < 0) throw new Exception("Esta venta ya está anulada.");

        // Obtener Detalles
        $stmtDet = $pdo->prepare("SELECT d.*, p.es_servicio FROM ventas_detalle d LEFT JOIN productos p ON d.id_producto = p.codigo WHERE id_venta_cabecera = ?");
        $stmtDet->execute([$idTicket]);
        $detalles = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

        foreach ($detalles as $item) {
            // Solo procesar si no ha sido devuelto ya individualmente
            if (floatval($item['cantidad']) > 0) {
                // 1. Devolver al Kardex
                if ($kardex && $item['es_servicio'] == 0) {
                    $kardex->registrarMovimiento(
                        $pdo,
                        $item['id_producto'],
                        $venta['id_almacen'],
                        floatval($item['cantidad']),
                        'DEVOLUCION',
                        "Anulación Venta #$idTicket",
                        null,
                        $venta['id_sucursal'],
                        date('Y-m-d H:i:s')
                    );
                }
                
                // 2. Marcar detalle como devuelto
                $pdo->prepare("UPDATE ventas_detalle SET cantidad = -ABS(cantidad) WHERE id = ?")->execute([$item['id']]);
            }
        }

        // 3. Marcar Cabecera como Devuelta (Total Negativo)
        $pdo->prepare("UPDATE ventas_cabecera SET total = -ABS(total), metodo_pago = CONCAT(metodo_pago, ' (ANULADO)') WHERE id = ?")->execute([$idTicket]);
    }

    $pdo->commit();

    // ── Audit trail (fuera de transacción) ────────────────────────────────────
    if ($idDetalle > 0) {
        log_audit($pdo, AUDIT_DEVOLUCION_ITEM, $usuarioNombre, [
            'id_detalle'  => $idDetalle,
            'id_venta'    => $item['id_ticket'],
            'producto'    => $item['nombre_producto'],
            'codigo'      => $item['id_producto'],
            'cantidad'    => floatval($item['cantidad']),
            'precio'      => floatval($item['precio']),
            'monto'       => $montoRestar,
        ]);
    } elseif ($idTicket > 0) {
        log_audit($pdo, AUDIT_DEVOLUCION_TICKET, $usuarioNombre, [
            'id_venta'    => $idTicket,
            'total'       => floatval($venta['total']),
            'cliente'     => $venta['cliente_nombre'] ?? '',
            'metodo_pago' => $venta['metodo_pago'],
            'items_count' => count($detalles),
        ]);
    }

    echo json_encode(['status' => 'success', 'msg' => 'Devolución procesada correctamente']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Devolver error en JSON limpio
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
}
?>

