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

    $operadorPos = pos_security_clean_text(
        $_SESSION['cajero'] ?? $_SESSION['admin_user'] ?? $_SESSION['admin_name'] ?? 'POS',
        100
    );
    $pinMaestro = trim((string)($input['pin_maestro_devolucion'] ?? ''));
    if (!preg_match('/^\d{4,10}$/', $pinMaestro)) {
        throw new Exception('Ingrese el PIN maestro de devoluciones de un administrador de esta sucursal.');
    }

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
    $idEmpresaCtx = (int)($_SESSION['id_empresa'] ?? $config['id_empresa'] ?? 1);
    $idSucursalCtx = (int)($_SESSION['id_sucursal'] ?? $config['id_sucursal'] ?? 1);
    $idAlmacenCtx = (int)($_SESSION['id_almacen'] ?? $config['id_almacen'] ?? 1);
    if ($idEmpresaCtx <= 0 || $idSucursalCtx <= 0 || $idAlmacenCtx <= 0) {
        throw new Exception('Contexto de devolución inválido.');
    }

    $refundPinRate = $_SESSION['pos_refund_pin_rate'] ?? ['attempts' => 0, 'started_at' => time(), 'locked_until' => 0];
    if ((int)($refundPinRate['locked_until'] ?? 0) > time()) {
        throw new Exception('Demasiados intentos de autorización. Espere un minuto e inténtelo de nuevo.');
    }

    // El PIN debe pertenecer a un usuario ERP administrador activo y asignado
    // a la empresa y sucursal de la sesión actual. No se acepta rol del navegador.
    $stmtAdmins = $pdo->prepare("
        SELECT u.id, u.nombre, u.pin_devolucion_hash
        FROM users u
        JOIN pos_user_contexts puc ON puc.user_id = u.id
        WHERE puc.id_empresa = ? AND puc.id_sucursal = ?
          AND COALESCE(puc.activo, 1) = 1 AND COALESCE(u.activo, 1) = 1
          AND LOWER(u.rol) IN ('admin', 'administrador', 'administrator')
          AND u.pin_devolucion_hash IS NOT NULL AND u.pin_devolucion_hash <> ''
        ORDER BY u.id ASC
    ");
    $stmtAdmins->execute([$idEmpresaCtx, $idSucursalCtx]);
    $adminAutorizador = null;
    foreach ($stmtAdmins->fetchAll(PDO::FETCH_ASSOC) as $admin) {
        if (password_verify($pinMaestro, (string)$admin['pin_devolucion_hash'])) {
            $adminAutorizador = $admin;
            break;
        }
    }
    if (!$adminAutorizador) {
        $startedAt = (int)($refundPinRate['started_at'] ?? 0);
        $attempts = ($startedAt > 0 && time() - $startedAt <= 600)
            ? (int)($refundPinRate['attempts'] ?? 0) + 1
            : 1;
        $_SESSION['pos_refund_pin_rate'] = [
            'attempts' => $attempts,
            'started_at' => ($attempts === 1) ? time() : $startedAt,
            'locked_until' => ($attempts >= 5) ? time() + 60 : 0,
        ];
        throw new Exception('PIN maestro incorrecto o no pertenece a un administrador activo de esta sucursal.');
    }

    unset($_SESSION['pos_refund_pin_rate']);
    $adminAutorizadorId = (int)$adminAutorizador['id'];
    $adminAutorizadorNombre = pos_security_clean_text($adminAutorizador['nombre'] ?? 'Administrador', 100);
    $usuarioNombre = $adminAutorizadorNombre;

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
        $sql = "SELECT d.*, v.id_almacen, v.id_sucursal, v.id_empresa, v.id as id_ticket, v.id_caja, v.id_sesion_caja, p.es_servicio, d.reembolsado
                FROM ventas_detalle d
                JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id
                LEFT JOIN productos p ON d.id_producto = p.codigo AND p.id_empresa = v.id_empresa
                WHERE d.id = ? AND v.id_empresa = ? AND v.id_sucursal = ? AND v.id_almacen = ? FOR UPDATE";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$idDetalle, $idEmpresaCtx, $idSucursalCtx, $idAlmacenCtx]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) throw new Exception("El producto de la venta no existe.");
        if (floatval($item['cantidad']) < 0) {
            throw new Exception("Este ítem ya está registrado en negativo (devuelto por sistema anterior).");
        }
        if (!empty($item['reembolsado'])) {
            // Si el navegador perdió la respuesta después del commit, repetir
            // la solicitud debe devolver éxito idempotente, no un falso error.
            $stmtExisting = $pdo->prepare(
                "SELECT v.id
                 FROM ventas_cabecera v
                 JOIN ventas_detalle d ON d.id_venta_cabecera = v.id
                 WHERE v.id_venta_original = ?
                   AND v.tipo_servicio = 'devolucion'
                   AND d.id_producto = ?
                   AND d.cantidad = ?
                 ORDER BY v.id DESC LIMIT 1"
            );
            $stmtExisting->execute([
                $item['id_ticket'],
                $item['id_producto'],
                -1 * abs(floatval($item['cantidad']))
            ]);
            $existingRefundId = (int)($stmtExisting->fetchColumn() ?: 0);
            if ($existingRefundId > 0) {
                $pdo->commit();
                echo json_encode([
                    'status' => 'success',
                    'msg' => 'La devolución ya estaba registrada.',
                    'id_devolucion' => $existingRefundId,
                    'already_processed' => true,
                    'affected_codes' => [(string)$item['id_producto']]
                ]);
                exit;
            }
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
            $idSucursalCtx,
            $idEmpresaCtx,
            $idAlmacenCtx,
            $item['id_caja'],
            $item['id_sesion_caja'],
            $item['id_ticket'],
            $usuarioNombre
        ]);
        $newHeadId = $pdo->lastInsertId();

        // 3. Crear detalle negativo en la devolución
        $sqlDet = "INSERT INTO ventas_detalle (id_venta_cabecera, id_producto, cantidad, precio, costo_unitario, nombre_producto) VALUES (?, ?, ?, ?, ?, ?)";
        $stmtDet = $pdo->prepare($sqlDet);
        $stmtDet->execute([
            $newHeadId,
            $item['id_producto'],
            -1 * abs(floatval($item['cantidad'])),
            $item['precio'],
            $item['costo_unitario'] ?? null,
            'DEV: ' . ($item['nombre_producto'] ?? $item['id_producto'])
        ]);

        // 4. Revertir stock
        if ($kardex && intval($item['es_servicio']) === 0) {
            $kardex->registrarMovimiento(
                $pdo,
                $item['id_producto'],
                $idAlmacenCtx,
                floatval($item['cantidad']),
                'DEVOLUCION',
                "Devolución ítem #{$newHeadId} → Venta #{$item['id_ticket']}",
                null,
                $idSucursalCtx,
                date('Y-m-d H:i:s'),
                $usuarioNombre,
                $idEmpresaCtx
            );
        }

        $pdo->commit();

        log_audit($pdo, AUDIT_DEVOLUCIÓN_ITEM, $usuarioNombre, [
            'autorizado_por'      => $adminAutorizadorNombre,
            'autorizador_id'      => $adminAutorizadorId,
            'operador_pos'        => $operadorPos,
            'id_detalle_original' => $idDetalle,
            'id_venta_original'   => $item['id_ticket'],
            'id_devolucion'       => $newHeadId,
            'producto'            => $item['nombre_producto'],
            'codigo'              => $item['id_producto'],
            'cantidad'            => floatval($item['cantidad']),
            'precio'              => floatval($item['precio']),
            'monto'               => $montoDevolver,
        ]);

        echo json_encode([
            'status' => 'success',
            'msg' => 'Devolución procesada correctamente',
            'id_devolucion' => $newHeadId,
            'affected_codes' => [(string)$item['id_producto']]
        ]);
    }

    // =========================================================================
    // CASO B: DEVOLUCIÓN DE TICKET COMPLETO
    // =========================================================================
    elseif ($idTicket > 0) {
        $stmtCab = $pdo->prepare("SELECT * FROM ventas_cabecera WHERE id = ? AND id_empresa = ? AND id_sucursal = ? AND id_almacen = ? FOR UPDATE");
        $stmtCab->execute([$idTicket, $idEmpresaCtx, $idSucursalCtx, $idAlmacenCtx]);
        $venta = $stmtCab->fetch(PDO::FETCH_ASSOC);
        if (!$venta) throw new Exception("Venta no encontrada");
        if (floatval($venta['total']) < 0) {
            throw new Exception("Esta venta ya fue anulada o devuelta (total negativo).");
        }

        // Verificar cualquier devolución previa. Una devolución parcial impide
        // devolver el ticket completo; una completa se responde idempotentemente
        // si el navegador perdió la respuesta original.
        $stmtDup = $pdo->prepare(
            "SELECT id, motivo_anulacion FROM ventas_cabecera
             WHERE id_venta_original = ? AND tipo_servicio = 'devolucion'
             ORDER BY id DESC LIMIT 1"
        );
        $stmtDup->execute([$idTicket]);
        $existingRefund = $stmtDup->fetch(PDO::FETCH_ASSOC) ?: null;
        $existingRefundId = (int)($existingRefund['id'] ?? 0);
        if ($existingRefundId > 0) {
            if (trim((string)($existingRefund['motivo_anulacion'] ?? '')) !== 'Devolución ticket completo') {
                throw new Exception("Esta venta ya tiene una devolución parcial registrada.");
            }
            $stmtExistingCodes = $pdo->prepare(
                "SELECT id_producto FROM ventas_detalle
                 WHERE id_venta_cabecera = ? AND cantidad < 0"
            );
            $stmtExistingCodes->execute([$existingRefundId]);
            $affectedCodes = array_values(array_unique(array_map(
                'strval',
                $stmtExistingCodes->fetchAll(PDO::FETCH_COLUMN)
            )));
            $pdo->commit();
            echo json_encode([
                'status' => 'success',
                'msg' => 'La devolución ya estaba registrada.',
                'id_devolucion' => $existingRefundId,
                'already_processed' => true,
                'affected_codes' => $affectedCodes
            ]);
            exit;
        }

        $stmtDet = $pdo->prepare("SELECT d.*, p.es_servicio FROM ventas_detalle d LEFT JOIN productos p ON d.id_producto = p.codigo AND p.id_empresa = ? WHERE d.id_venta_cabecera = ?");
        $stmtDet->execute([$idEmpresaCtx, $idTicket]);
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
            $idSucursalCtx,
            $idEmpresaCtx,
            $idAlmacenCtx,
            $venta['id_caja'],
            $venta['id_sesion_caja'],
            $idTicket,
            $usuarioNombre
        ]);
        $newHeadId = $pdo->lastInsertId();

        // 3. Crear detalles negativos y Kardex
        foreach ($detalles as $item) {
            if (floatval($item['cantidad']) > 0) {
                $pdo->prepare("INSERT INTO ventas_detalle (id_venta_cabecera, id_producto, cantidad, precio, costo_unitario, nombre_producto) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([
                        $newHeadId,
                        $item['id_producto'],
                    -1 * abs(floatval($item['cantidad'])),
                    $item['precio'],
                    $item['costo_unitario'] ?? null,
                    'DEV: ' . ($item['nombre_producto'] ?? $item['id_producto'])
                    ]);

                if ($kardex && intval($item['es_servicio']) === 0) {
                    $kardex->registrarMovimiento(
                        $pdo,
                        $item['id_producto'],
                        $idAlmacenCtx,
                        floatval($item['cantidad']),
                        'DEVOLUCION',
                        "Devolución ticket #{$newHeadId} → Venta #{$idTicket}",
                        null,
                        $idSucursalCtx,
                        date('Y-m-d H:i:s'),
                        $usuarioNombre,
                        $idEmpresaCtx
                    );
                }
            }
        }

        $pdo->commit();

        log_audit($pdo, AUDIT_DEVOLUCIÓN_TICKET, $usuarioNombre, [
            'autorizado_por'    => $adminAutorizadorNombre,
            'autorizador_id'    => $adminAutorizadorId,
            'operador_pos'      => $operadorPos,
            'id_venta_original' => $idTicket,
            'id_devolucion'     => $newHeadId,
            'total_original'    => floatval($venta['total']),
            'cliente'           => $venta['cliente_nombre'] ?? '',
            'metodo_pago'       => $venta['metodo_pago'],
            'items_count'       => count($detalles),
        ]);

        $affectedCodes = array_values(array_unique(array_map(
            'strval',
            array_column(
                array_filter($detalles, static fn(array $detail): bool => floatval($detail['cantidad']) > 0),
                'id_producto'
            )
        )));
        echo json_encode([
            'status' => 'success',
            'msg' => 'Ticket devuelto correctamente',
            'id_devolucion' => $newHeadId,
            'affected_codes' => $affectedCodes
        ]);
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
}
