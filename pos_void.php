<?php
// ARCHIVO: pos_void.php
// VERSIÓN: 1.0 — Anular venta desde terminal POS (autenticación por PIN)
//
// Diferencia con pos_refund.php:
//   - No requiere sesión de admin; se autentica con el PIN del cajero (pos.cfg)
//   - Solo permite anular ventas de la sesión de caja ACTIVA (antes de cerrar)
//   - Requiere motivo obligatorio (mínimo 10 caracteres)
//   - Deja registro inmutable en auditoria_pos con cajero + motivo + timestamp

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

    // ── 2. Validar motivo ───────────────────────────────────────────────────────
    $motivo = pos_security_clean_text($input['motivo'] ?? '', 255);
    if (strlen($motivo) < 5) {
        throw new Exception("El motivo de anulación es obligatorio (mínimo 5 caracteres)");
    }

    // ── 3. Validar venta ────────────────────────────────────────────────────────
    $idVenta = isset($input['id_venta']) && is_numeric($input['id_venta']) ? (int)$input['id_venta'] : 0;
    if ($idVenta <= 0) throw new Exception("ID de venta inválido");

    // ── 4. Obtener sesión activa (solo ventas de sesión abierta se pueden anular) ─
    $stmtSesion = $pdo->query(
        "SELECT id FROM caja_sesiones WHERE estado = 'ABIERTA' ORDER BY id DESC LIMIT 1"
    );
    $idSesionActiva = $stmtSesion->fetchColumn();
    if (!$idSesionActiva) {
        throw new Exception("No hay sesión de caja activa. Solo se puede anular con sesión abierta.");
    }

    // ── 5. Asegurar columnas de anulación en ventas_cabecera ────────────────────
    foreach ([
        "ADD COLUMN motivo_anulacion VARCHAR(255) NULL",
        "ADD COLUMN anulada_por      VARCHAR(100) NULL",
        "ADD COLUMN anulada_en       DATETIME     NULL",
    ] as $alter) {
        try { $pdo->exec("ALTER TABLE ventas_cabecera $alter"); } catch (PDOException $ignored) {}
    }

    // ── 6. Obtener y bloquear la venta ──────────────────────────────────────────
    $pdo->beginTransaction();

    $stmtV = $pdo->prepare("SELECT * FROM ventas_cabecera WHERE id = ? FOR UPDATE");
    $stmtV->execute([$idVenta]);
    $venta = $stmtV->fetch(PDO::FETCH_ASSOC);

    if (!$venta) throw new Exception("Venta #$idVenta no encontrada");

    // Verificar que pertenece a la sesión activa
    if (intval($venta['id_caja']) !== intval($idSesionActiva)) {
        throw new Exception("Solo se pueden anular ventas de la sesión actual (sesión #$idSesionActiva)");
    }

    // Verificar que no está ya anulada
    if (floatval($venta['total']) < 0 || strpos((string)$venta['metodo_pago'], '(ANULADO)') !== false) {
        throw new Exception("Esta venta ya fue anulada o devuelta anteriormente");
    }

    // ── 7. Revertir stock y marcar detalles ────────────────────────────────────
    $stmtDet = $pdo->prepare(
        "SELECT d.*, p.es_servicio
         FROM ventas_detalle d
         LEFT JOIN productos p ON d.id_producto = p.codigo
         WHERE d.id_venta_cabecera = ?"
    );
    $stmtDet->execute([$idVenta]);
    $detalles = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

    $kardex = $kardexAvailable ? new KardexEngine($pdo) : null;

    $configFile = __DIR__ . '/pos.cfg';
    $config = ["id_almacen" => 1, "id_sucursal" => 1];
    if (file_exists($configFile)) {
        $loaded = json_decode(file_get_contents($configFile), true);
        if ($loaded) $config = array_merge($config, $loaded);
    }

    foreach ($detalles as $item) {
        if (floatval($item['cantidad']) > 0) {
            // Devolver stock al almacén (solo productos físicos)
            if ($kardex && intval($item['es_servicio']) === 0) {
                $kardex->registrarMovimiento(
                    $pdo,
                    $item['id_producto'],
                    $venta['id_almacen'] ?: $config['id_almacen'],
                    floatval($item['cantidad']),  // positivo = entrada
                    'DEVOLUCION',
                    "Anulación POS #{$idVenta} — {$cajero}",
                    null,
                    $venta['id_sucursal'] ?: $config['id_sucursal'],
                    date('Y-m-d H:i:s')
                );
            }
            // Marcar detalle como devuelto
            $pdo->prepare("UPDATE ventas_detalle SET cantidad = -ABS(cantidad) WHERE id = ?")
                ->execute([$item['id']]);
        }
    }

    // ── 8. Marcar cabecera como anulada ────────────────────────────────────────
    $pdo->prepare(
        "UPDATE ventas_cabecera
         SET total             = -ABS(total),
             metodo_pago       = CONCAT(metodo_pago, ' (ANULADO)'),
             motivo_anulacion  = ?,
             anulada_por       = ?,
             anulada_en        = NOW()
         WHERE id = ?"
    )->execute([$motivo, $cajero, $idVenta]);

    $pdo->commit();

    // ── 9. Registro de auditoría (fuera de transacción — nunca bloquea) ────────
    log_audit($pdo, AUDIT_VENTA_ANULADA, $cajero, [
        'id_venta'     => $idVenta,
        'total'        => floatval($venta['total']),
        'motivo'       => $motivo,
        'cliente'      => $venta['cliente_nombre'] ?? 'Mostrador',
        'metodo_pago'  => $venta['metodo_pago'],
        'fecha_venta'  => $venta['fecha'],
        'items_count'  => count($detalles),
        'id_sesion'    => $idSesionActiva,
    ]);

    echo json_encode(['status' => 'success', 'msg' => "Venta #{$idVenta} anulada por {$cajero}"]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
}
?>
