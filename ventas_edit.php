<?php
/**
 * ventas_edit.php — API para editar ventas existentes
 * GET  ?action=load&id=X   → JSON con cabecera, detalles y pagos
 * POST ?action=save         → Aplica cambios con diff de kardex
 *
 * NOTA: productos.codigo es la PK (varchar); ventas_detalle.id_producto
 *       almacena ese código, NO un entero.
 */

require_once 'db.php';
require_once 'config_loader.php';
require_once 'pos_audit.php';

if (file_exists(__DIR__ . '/kardex_engine.php')) {
    require_once __DIR__ . '/kardex_engine.php';
}

header('Content-Type: application/json; charset=utf-8');
session_start();

$isAdmin  = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$isCashier = !empty($_SESSION['cajero']);

if (!$isAdmin && !$isCashier) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'msg' => 'No autorizado']));
}

$idAlmacen  = intval($config['id_almacen']  ?? 1);
$idSucursal = intval($config['id_sucursal'] ?? 1);
$usuario    = $_SESSION['admin_user'] ?? $_SESSION['admin_user_name'] ?? $_SESSION['cajero'] ?? 'usuario';

$action = $_GET['action'] ?? '';

// ──────────────────────────────────────────────
// GET: Cargar venta para edición
// ──────────────────────────────────────────────
if ($action === 'load') {
    $idVenta = intval($_GET['id'] ?? 0);
    if (!$idVenta) die(json_encode(['status' => 'error', 'msg' => 'ID inválido']));

    $stmtV = $pdo->prepare("SELECT * FROM ventas_cabecera WHERE id = ?");
    $stmtV->execute([$idVenta]);
    $venta = $stmtV->fetch(PDO::FETCH_ASSOC);

    if (!$venta) die(json_encode(['status' => 'error', 'msg' => 'Venta no encontrada']));

    if (floatval($venta['total']) < 0 || stripos($venta['metodo_pago'] ?? '', 'ANULADO') !== false) {
        die(json_encode(['status' => 'error', 'msg' => 'No se puede editar una venta anulada o devuelta']));
    }

    // JOIN por codigo (PK de productos)
    $stmtD = $pdo->prepare(
        "SELECT vd.id, vd.id_producto, vd.cantidad, vd.precio,
                vd.nombre_producto, vd.codigo_producto,
                COALESCE(p.es_servicio, 0) AS es_servicio
         FROM ventas_detalle vd
         LEFT JOIN productos p ON p.codigo = vd.id_producto
         WHERE vd.id_venta_cabecera = ? AND vd.cantidad > 0
         ORDER BY vd.id ASC"
    );
    $stmtD->execute([$idVenta]);
    $detalles = $stmtD->fetchAll(PDO::FETCH_ASSOC);

    $stmtP = $pdo->prepare("SELECT metodo_pago, monto FROM ventas_pagos WHERE id_venta_cabecera = ?");
    $stmtP->execute([$idVenta]);
    $pagos = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status'   => 'success',
        'venta'    => $venta,
        'detalles' => $detalles,
        'pagos'    => $pagos,
    ]);
    exit;
}

// ──────────────────────────────────────────────
// POST: Guardar cambios
// ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) die(json_encode(['status' => 'error', 'msg' => 'Payload inválido']));

    $idVenta       = intval($input['id_venta']              ?? 0);
    $newItems      = $input['items']                        ?? [];
    $newPayments   = $input['payments']                     ?? [];
    $editReason    = trim($input['edit_reason']             ?? 'Edición manual');
    $nuevoTotal    = floatval($input['total']               ?? 0);
    $clienteNombre = substr(trim($input['cliente_nombre']   ?? ''), 0, 100);
    $mensajero     = substr(trim($input['mensajero_nombre'] ?? ''), 0, 100);
    $tipoServicio  = substr(trim($input['tipo_servicio']    ?? 'mostrador'), 0, 50);
    $metodoPago    = substr(trim($input['metodo_pago']      ?? 'Efectivo'), 0, 80);

    if (!$idVenta || empty($newItems) || $nuevoTotal <= 0) {
        die(json_encode(['status' => 'error', 'msg' => 'Datos incompletos o total inválido']));
    }
    if (strlen($editReason) < 3) {
        die(json_encode(['status' => 'error', 'msg' => 'Motivo de edición requerido (mínimo 3 caracteres)']));
    }

    try {
        $pdo->beginTransaction();

        // 1. Bloquear cabecera
        $stmtV = $pdo->prepare("SELECT * FROM ventas_cabecera WHERE id = ? FOR UPDATE");
        $stmtV->execute([$idVenta]);
        $venta = $stmtV->fetch(PDO::FETCH_ASSOC);

        if (!$venta) throw new Exception('Venta no encontrada');
        if (floatval($venta['total']) < 0 || stripos($venta['metodo_pago'] ?? '', 'ANULADO') !== false) {
            throw new Exception('No se puede editar una venta anulada');
        }

        $almacen  = intval($venta['id_almacen']  ?? $idAlmacen);
        $sucursal = intval($venta['id_sucursal'] ?? $idSucursal);
        $now      = date('Y-m-d H:i:s');

        // 2. Detalles originales — clave = codigo (varchar)
        $stmtD = $pdo->prepare(
            "SELECT vd.id_producto, vd.cantidad,
                    COALESCE(p.es_servicio, 0) AS es_servicio
             FROM ventas_detalle vd
             LEFT JOIN productos p ON p.codigo = vd.id_producto
             WHERE vd.id_venta_cabecera = ? AND vd.cantidad > 0"
        );
        $stmtD->execute([$idVenta]);
        $oldItems = $stmtD->fetchAll(PDO::FETCH_ASSOC);

        // Mapas: codigo → datos
        $oldMap = [];
        foreach ($oldItems as $oi) {
            $oldMap[(string)$oi['id_producto']] = $oi;
        }
        $newMap = [];
        foreach ($newItems as $ni) {
            $newMap[(string)($ni['id'] ?? '')] = $ni;
        }

        $kardex = class_exists('KardexEngine') ? new KardexEngine($pdo) : null;

        // 3a. Reducidos o eliminados → devolver stock
        foreach ($oldMap as $codigo => $old) {
            if ($old['es_servicio']) continue;
            $oldQty = floatval($old['cantidad']);
            $newQty = isset($newMap[$codigo]) ? floatval($newMap[$codigo]['qty']) : 0;
            $diff   = $oldQty - $newQty;
            if ($diff > 0.001 && $kardex) {
                $kardex->registrarMovimiento(
                    $pdo, $codigo, $almacen,
                    $diff,      // positivo = devuelve stock
                    'DEVOLUCION',
                    "Edición venta #{$idVenta} — {$editReason}",
                    null, $sucursal, $now
                );
            }
        }

        // 3b. Agregados o aumentados → consumir stock
        foreach ($newMap as $codigo => $new) {
            if (!$codigo) continue;
            $esServicio = (bool)($oldMap[$codigo]['es_servicio'] ?? false);
            if (!$esServicio && !isset($oldMap[$codigo])) {
                // Producto nuevo: verificar en BD
                $stmtSrv = $pdo->prepare("SELECT es_servicio FROM productos WHERE codigo = ?");
                $stmtSrv->execute([$codigo]);
                $esServicio = (bool)($stmtSrv->fetchColumn() ?? false);
            }
            if ($esServicio) continue;

            $oldQty = isset($oldMap[$codigo]) ? floatval($oldMap[$codigo]['cantidad']) : 0;
            $newQty = floatval($new['qty']);
            $diff   = $newQty - $oldQty;
            if ($diff > 0.001 && $kardex) {
                $kardex->registrarMovimiento(
                    $pdo, $codigo, $almacen,
                    -abs($diff),    // negativo = consume stock
                    'VENTA',
                    "Edición venta #{$idVenta} — {$editReason}",
                    null, $sucursal, $now
                );
            }
        }

        // 4. Reemplazar detalles
        $pdo->prepare("DELETE FROM ventas_detalle WHERE id_venta_cabecera = ?")->execute([$idVenta]);
        $stmtIns = $pdo->prepare(
            "INSERT INTO ventas_detalle
             (id_venta_cabecera, id_producto, cantidad, precio, nombre_producto, codigo_producto)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        foreach ($newItems as $ni) {
            $codigo = (string)($ni['id'] ?? '');
            $stmtIns->execute([
                $idVenta,
                $codigo,                                        // varchar codigo
                floatval($ni['qty']),
                floatval($ni['price']),
                substr(trim($ni['name']   ?? ''), 0, 200),
                substr(trim($ni['codigo'] ?? $codigo), 0, 50), // fallback al mismo codigo
            ]);
        }

        // 5. Reemplazar pagos
        $pdo->prepare("DELETE FROM ventas_pagos WHERE id_venta_cabecera = ?")->execute([$idVenta]);
        $stmtPay = $pdo->prepare(
            "INSERT INTO ventas_pagos (id_venta_cabecera, metodo_pago, monto) VALUES (?, ?, ?)"
        );
        foreach ($newPayments as $p) {
            if (floatval($p['amount'] ?? 0) > 0) {
                $stmtPay->execute([$idVenta, $p['method'], floatval($p['amount'])]);
            }
        }

        // 6. Actualizar cabecera
        $pdo->prepare(
            "UPDATE ventas_cabecera
             SET total = ?, cliente_nombre = ?, mensajero_nombre = ?,
                 tipo_servicio = ?, metodo_pago = ?
             WHERE id = ?"
        )->execute([
            $nuevoTotal,
            $clienteNombre ?: 'Mostrador',
            $mensajero,
            $tipoServicio,
            $metodoPago,
            $idVenta,
        ]);

        $pdo->commit();

        // 7. Audit (fuera de transacción)
        if (function_exists('log_audit')) {
            log_audit($pdo, 'VENTA_EDITADA', $usuario, [
                'id_venta'    => $idVenta,
                'total_nuevo' => $nuevoTotal,
                'total_viejo' => floatval($venta['total']),
                'motivo'      => $editReason,
                'items'       => count($newItems),
            ]);
        }

        echo json_encode(['status' => 'success', 'msg' => "Venta #{$idVenta} actualizada", 'id' => $idVenta]);
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        exit;
    }
}

echo json_encode(['status' => 'error', 'msg' => 'Acción no reconocida']);
