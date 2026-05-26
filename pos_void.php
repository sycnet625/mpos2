<?php
// ARCHIVO: pos_void.php
// VERSIÓN: 2.0 — Unificado: crea ticket de devolución NUEVO. La venta original queda INTACTA.
// Solo permite anular ventas de la sesión de caja ACTIVA (antes de cerrar).
// Requiere motivo obligatorio y deja registro inmutable en auditoria_pos.

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

    // ── 1. Autenticación con usuario/contraseña del sistema ───────────────────
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
        throw new Exception("Usuario del sistema sin permisos para anular");
    }
    $cajero = (string)($authRow['nombre'] ?? $authUser);

    // ── 2. Validar motivo ─────────────────────────────────────────────────────
    $motivo = pos_security_clean_text($input['motivo'] ?? '', 255);
    if (strlen($motivo) < 5) {
        throw new Exception("El motivo de anulación es obligatorio (mínimo 5 caracteres)");
    }

    // ── 3. Validar venta ──────────────────────────────────────────────────────
    $idVenta = isset($input['id_venta']) && is_numeric($input['id_venta']) ? (int)$input['id_venta'] : 0;
    if ($idVenta <= 0) throw new Exception("ID de venta inválido");

    // ── 4. Sesión activa (solo ventas de sesión abierta se pueden anular) ─────
    $stmtSesion = $pdo->query("SELECT id FROM caja_sesiones WHERE estado = 'ABIERTA' ORDER BY id DESC LIMIT 1");
    $idSesionActiva = $stmtSesion->fetchColumn();
    if (!$idSesionActiva) {
        throw new Exception("No hay sesión de caja activa. Solo se puede anular con sesión abierta.");
    }

    // ── Migración silenciosa: id_venta_original (fuera de transacción) ────────
    try {
        $pdo->exec("ALTER TABLE ventas_cabecera ADD COLUMN id_venta_original INT NULL, ADD KEY idx_vcab_orig (id_venta_original)");
    } catch (PDOException $ignored) {}

    $pdo->beginTransaction();

    // ── 5. Obtener y bloquear la venta original (previene doble anulación concurrente)
    $stmtV = $pdo->prepare("SELECT * FROM ventas_cabecera WHERE id = ? FOR UPDATE");
    $stmtV->execute([$idVenta]);
    $venta = $stmtV->fetch(PDO::FETCH_ASSOC);
    if (!$venta) throw new Exception("Venta #{$idVenta} no encontrada");

    if (intval($venta['id_caja']) !== intval($idSesionActiva)) {
        throw new Exception("Solo se pueden anular ventas de la sesión actual (sesión #{$idSesionActiva})");
    }
    if (floatval($venta['total']) < 0) {
        throw new Exception("Esta venta ya fue anulada o devuelta anteriormente");
    }

    // ── 6. Verificar que no exista ya una devolución/anulación para esta venta ─
    $stmtDup = $pdo->prepare("SELECT id FROM ventas_cabecera WHERE id_venta_original = ? LIMIT 1");
    $stmtDup->execute([$idVenta]);
    if ($stmtDup->fetchColumn()) {
        throw new Exception("Esta venta ya tiene un ticket de anulación/devolución registrado.");
    }

    // ── 7. Configuración local ────────────────────────────────────────────────
    $configFile = __DIR__ . '/pos.cfg';
    $config = ["id_almacen" => 1, "id_sucursal" => 1, "id_empresa" => 1];
    if (file_exists($configFile)) {
        $loaded = json_decode(file_get_contents($configFile), true);
        if ($loaded) $config = array_merge($config, $loaded);
    }

    // ── 8. Obtener detalles ───────────────────────────────────────────────────
    $stmtDet = $pdo->prepare(
        "SELECT d.*, p.es_servicio
         FROM ventas_detalle d
         LEFT JOIN productos p ON d.id_producto = p.codigo
         WHERE d.id_venta_cabecera = ?"
    );
    $stmtDet->execute([$idVenta]);
    $detalles = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

    $kardex = ($kardexAvailable) ? new KardexEngine($pdo) : null;

    // ── 10. Marcar detalles originales como reembolsados (bandera de auditoría) ─
    $pdo->prepare("UPDATE ventas_detalle SET reembolsado = 1 WHERE id_venta_cabecera = ? AND cantidad > 0")
        ->execute([$idVenta]);

    // ── 11. Crear cabecera de anulación/devolución ────────────────────────────
    $uuid = uniqid('void_');
    $sqlHead = "INSERT INTO ventas_cabecera
        (uuid_venta, fecha, total, metodo_pago, id_sucursal, id_empresa, id_almacen,
         tipo_servicio, cliente_nombre, id_caja, id_sesion_caja, id_venta_original,
         motivo_anulacion, anulada_por, anulada_en, canal_origen)
        VALUES (?, NOW(), ?, 'Devolución', ?, ?, ?, 'devolucion', 'DEVOLUCIÓN', ?, ?, ?, ?, ?, NOW(), 'POS')";
    $stmtHead = $pdo->prepare($sqlHead);
    $stmtHead->execute([
        $uuid,
        -1 * abs(floatval($venta['total'])),
        $venta['id_sucursal'] ?: $config['id_sucursal'],
        $config['id_empresa'],
        $venta['id_almacen'] ?: $config['id_almacen'],
        $venta['id_caja'],
        $venta['id_sesion_caja'],
        $idVenta,
        $motivo,
        $cajero
    ]);
    $newHeadId = $pdo->lastInsertId();

    // ── 12. Crear detalles negativos y Kardex ─────────────────────────────────
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
                    "Anulación POS #{$newHeadId} → Venta #{$idVenta} — {$cajero}",
                    null,
                    $venta['id_sucursal'] ?: $config['id_sucursal'],
                    date('Y-m-d H:i:s')
                );
            }
        }
    }

    $pdo->commit();

    // ── 13. Auditoría (fuera de transacción) ──────────────────────────────────
    log_audit($pdo, AUDIT_VENTA_ANULADA, $cajero, [
        'id_venta_original' => $idVenta,
        'id_devolucion'     => $newHeadId,
        'total_original'    => floatval($venta['total']),
        'motivo'            => $motivo,
        'cliente'           => $venta['cliente_nombre'] ?? 'Mostrador',
        'metodo_pago'       => $venta['metodo_pago'],
        'fecha_venta'       => $venta['fecha'],
        'items_count'       => count($detalles),
        'id_sesion'         => $idSesionActiva,
    ]);

    echo json_encode(['status' => 'success', 'msg' => "Venta #{$idVenta} anulada por {$cajero}", 'id_devolucion' => $newHeadId]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
}
