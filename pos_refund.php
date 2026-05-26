<?php
// ARCHIVO: pos_refund.php
// VERSIÓN: 5.0 — Unificado: siempre crea ticket de devolución NUEVO. La venta original queda INTACTA.
// Soporta devolución por ítem (id) o ticket completo (ticket_id).

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

require_once 'pos_security.php';
pos_security_bootstrap_session();
require_once 'db.php';
require_once 'pos_audit.php';

$kardexAvailable = false;
if (file_exists('kardex_engine.php')) {
    require_once 'kardex_engine.php';
    if (class_exists('KardexEngine')) $kardexAvailable = true;
}

try {
    pos_security_enforce_session(false);

    $input = pos_security_json_input();
    pos_security_require_csrf($input);
    if (!$input) throw new Exception("Datos de entrada inválidos");

    // ── Autenticación con credenciales del sistema ────────────────────────────
    $authUser = pos_security_clean_text($input['auth_user'] ?? '', 100);
    $authPass = (string)($input['auth_pass'] ?? '');
    if ($authUser === '' || $authPass === '') {
        throw new Exception("Debe ingresar usuario y contraseña del sistema");
    }
    $stmtAuth = $pdo->prepare("SELECT id, nombre, password, rol, activo FROM users WHERE nombre = ? LIMIT 1");
    $stmtAuth->execute([$authUser]);
    $authRow = $stmtAuth->fetch(PDO::FETCH_ASSOC);
    if (!$authRow || !password_verify($authPass, (string)$authRow['password'])) {
        throw new Exception("Credenciales del sistema inválidas");
    }
    if (isset($authRow['activo']) && (int)$authRow['activo'] !== 1) {
        throw new Exception("Usuario del sistema inactivo");
    }
    $authRole = strtolower(trim((string)($authRow['rol'] ?? '')));
    if ($authRole === 'cajero') {
        throw new Exception("Usuario del sistema sin permisos para devoluciones");
    }
    $usuarioNombre = (string)($authRow['nombre'] ?? $authUser);

    $idDetalle = isset($input['id']) && is_numeric($input['id']) ? (int)$input['id'] : 0;
    $idTicket  = isset($input['ticket_id']) && is_numeric($input['ticket_id']) ? (int)$input['ticket_id'] : 0;
    if ($idDetalle <= 0 && $idTicket <= 0) {
        throw new Exception("Se requiere ID de detalle o ID de ticket");
    }

    // ── Configuración local ───────────────────────────────────────────────────
    $configFile = __DIR__ . '/pos.cfg';
    $config = ["id_almacen" => 1, "id_sucursal" => 1, "id_empresa" => 1];
    if (file_exists($configFile)) {
        $loaded = json_decode(file_get_contents($configFile), true);
        if ($loaded) $config = array_merge($config, $loaded);
    }

    // ── Migración silenciosa: id_venta_original (fuera de transacción) ────────
    try {
        $pdo->exec("ALTER TABLE ventas_cabecera ADD COLUMN id_venta_original INT NULL, ADD KEY idx_vcab_orig (id_venta_original)");
    } catch (PDOException $ignored) {}

    $pdo->beginTransaction();
    $kardex = ($kardexAvailable) ? new KardexEngine($pdo) : null;

    // =========================================================================
    // CASO A: DEVOLUCIÓN DE UN SOLO ÍTEM
    // =========================================================================
    if ($idDetalle > 0) {
        $sql = "SELECT d.*, v.id_almacen, v.id_sucursal, v.id as id_ticket, v.id_caja, v.id_sesion_caja, p.es_servicio, d.reembolsado
                FROM ventas_detalle d
                JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id
                LEFT JOIN productos p ON d.id_producto = p.codigo
                WHERE d.id = ? FOR UPDATE";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$idDetalle]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) throw new Exception("El producto de la venta no existe.");
        if (floatval($item['cantidad']) < 0) {
            throw new Exception("Este ítem ya está registrado en negativo (devuelto por sistema anterior).");
        }
        if (!empty($item['reembolsado'])) {
            throw new Exception("Este ítem ya fue devuelto anteriormente.");
        }

        $montoDevolver = floatval($item['cantidad']) * floatval($item['precio']);

        // 1. Marcar detalle original como reembolsado (bandera de auditoría, no altera totales)
        $pdo->prepare("UPDATE ventas_detalle SET reembolsado = 1 WHERE id = ?")
            ->execute([$idDetalle]);

        // 2. Crear cabecera de devolución
        $uuid = uniqid('ref_');
        $sqlHead = "INSERT INTO ventas_cabecera
            (uuid_venta, fecha, total, metodo_pago, id_sucursal, id_empresa, id_almacen,
             tipo_servicio, cliente_nombre, id_caja, id_sesion_caja, id_venta_original,
             motivo_anulacion, anulada_por, anulada_en, canal_origen)
            VALUES (?, NOW(), ?, 'Devolución', ?, ?, ?, 'devolucion', 'DEVOLUCIÓN', ?, ?, ?, 'Devolución ítem', ?, NOW(), 'POS')";
        $stmtHead = $pdo->prepare($sqlHead);
        $stmtHead->execute([
            $uuid,
            -1 * abs($montoDevolver),
            $item['id_sucursal'] ?: $config['id_sucursal'],
            $config['id_empresa'],
            $item['id_almacen'] ?: $config['id_almacen'],
            $item['id_caja'],
            $item['id_sesion_caja'],
            $item['id_ticket'],
            $usuarioNombre
        ]);
        $newHeadId = $pdo->lastInsertId();

        // 3. Crear detalle negativo en la devolución
        $sqlDet = "INSERT INTO ventas_detalle (id_venta_cabecera, id_producto, cantidad, precio, nombre_producto) VALUES (?, ?, ?, ?, ?)";
        $stmtDet = $pdo->prepare($sqlDet);
        $stmtDet->execute([
            $newHeadId,
            $item['id_producto'],
            -1 * abs(floatval($item['cantidad'])),
            $item['precio'],
            'DEV: ' . ($item['nombre_producto'] ?? $item['id_producto'])
        ]);

        // 4. Revertir stock
        if ($kardex && intval($item['es_servicio']) === 0) {
            $kardex->registrarMovimiento(
                $pdo,
                $item['id_producto'],
                $item['id_almacen'] ?: $config['id_almacen'],
                floatval($item['cantidad']),
                'DEVOLUCION',
                "Devolución ítem #{$newHeadId} → Venta #{$item['id_ticket']}",
                null,
                $item['id_sucursal'] ?: $config['id_sucursal'],
                date('Y-m-d H:i:s')
            );
        }

        $pdo->commit();

        log_audit($pdo, AUDIT_DEVOLUCIÓN_ITEM, $usuarioNombre, [
            'id_detalle_original' => $idDetalle,
            'id_venta_original'   => $item['id_ticket'],
            'id_devolucion'       => $newHeadId,
            'producto'            => $item['nombre_producto'],
            'codigo'              => $item['id_producto'],
            'cantidad'            => floatval($item['cantidad']),
            'precio'              => floatval($item['precio']),
            'monto'               => $montoDevolver,
        ]);

        echo json_encode(['status' => 'success', 'msg' => 'Devolución procesada correctamente', 'id_devolucion' => $newHeadId]);
    }

    // =========================================================================
    // CASO B: DEVOLUCIÓN DE TICKET COMPLETO
    // =========================================================================
    elseif ($idTicket > 0) {
        $stmtCab = $pdo->prepare("SELECT * FROM ventas_cabecera WHERE id = ? FOR UPDATE");
        $stmtCab->execute([$idTicket]);
        $venta = $stmtCab->fetch(PDO::FETCH_ASSOC);
        if (!$venta) throw new Exception("Venta no encontrada");
        if (floatval($venta['total']) < 0) {
            throw new Exception("Esta venta ya fue anulada o devuelta (total negativo).");
        }

        // Verificar que no exista ya una devolución completa para esta venta
        $stmtDup = $pdo->prepare("SELECT id FROM ventas_cabecera WHERE id_venta_original = ? LIMIT 1");
        $stmtDup->execute([$idTicket]);
        if ($stmtDup->fetchColumn()) {
            throw new Exception("Esta venta ya tiene un ticket de devolución completo registrado.");
        }

        $stmtDet = $pdo->prepare("SELECT d.*, p.es_servicio FROM ventas_detalle d LEFT JOIN productos p ON d.id_producto = p.codigo WHERE d.id_venta_cabecera = ?");
        $stmtDet->execute([$idTicket]);
        $detalles = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

        // 1. Marcar todos los detalles originales como reembolsados
        $pdo->prepare("UPDATE ventas_detalle SET reembolsado = 1 WHERE id_venta_cabecera = ? AND cantidad > 0")
            ->execute([$idTicket]);

        // 2. Crear cabecera de devolución
        $uuid = uniqid('ref_');
        $sqlHead = "INSERT INTO ventas_cabecera
            (uuid_venta, fecha, total, metodo_pago, id_sucursal, id_empresa, id_almacen,
             tipo_servicio, cliente_nombre, id_caja, id_sesion_caja, id_venta_original,
             motivo_anulacion, anulada_por, anulada_en, canal_origen)
            VALUES (?, NOW(), ?, 'Devolución', ?, ?, ?, 'devolucion', 'DEVOLUCIÓN', ?, ?, ?, 'Devolución ticket completo', ?, NOW(), 'POS')";
        $stmtHead = $pdo->prepare($sqlHead);
        $stmtHead->execute([
            $uuid,
            -1 * abs(floatval($venta['total'])),
            $venta['id_sucursal'] ?: $config['id_sucursal'],
            $config['id_empresa'],
            $venta['id_almacen'] ?: $config['id_almacen'],
            $venta['id_caja'],
            $venta['id_sesion_caja'],
            $idTicket,
            $usuarioNombre
        ]);
        $newHeadId = $pdo->lastInsertId();

        // 3. Crear detalles negativos y Kardex
        foreach ($detalles as $item) {
            if (floatval($item['cantidad']) > 0) {
                $pdo->prepare("INSERT INTO ventas_detalle (id_venta_cabecera, id_producto, cantidad, precio, nombre_producto) VALUES (?, ?, ?, ?, ?)")
                    ->execute([
                        $newHeadId,
                        $item['id_producto'],
                        -1 * abs(floatval($item['cantidad'])),
                        $item['precio'],
                        'DEV: ' . ($item['nombre_producto'] ?? $item['id_producto'])
                    ]);

                if ($kardex && intval($item['es_servicio']) === 0) {
                    $kardex->registrarMovimiento(
                        $pdo,
                        $item['id_producto'],
                        $venta['id_almacen'] ?: $config['id_almacen'],
                        floatval($item['cantidad']),
                        'DEVOLUCION',
                        "Devolución ticket #{$newHeadId} → Venta #{$idTicket}",
                        null,
                        $venta['id_sucursal'] ?: $config['id_sucursal'],
                        date('Y-m-d H:i:s')
                    );
                }
            }
        }

        $pdo->commit();

        log_audit($pdo, AUDIT_DEVOLUCIÓN_TICKET, $usuarioNombre, [
            'id_venta_original' => $idTicket,
            'id_devolucion'     => $newHeadId,
            'total_original'    => floatval($venta['total']),
            'cliente'           => $venta['cliente_nombre'] ?? '',
            'metodo_pago'       => $venta['metodo_pago'],
            'items_count'       => count($detalles),
        ]);

        echo json_encode(['status' => 'success', 'msg' => 'Ticket devuelto correctamente', 'id_devolucion' => $newHeadId]);
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
}
