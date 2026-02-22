<?php
// ARCHIVO: pagos_api.php
// API de pagos: check_stock | check_status | confirm_payment

ini_set('display_errors', 0);
ini_set('log_errors', 1);
header('Content-Type: application/json; charset=utf-8');

require_once 'db.php';
require_once 'config_loader.php';

$idAlmacen = intval($config['id_almacen']);

// ──────────────────────────────────────────────────────────────────
// Determinar acción
// ──────────────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$action = null;
$input  = [];

if ($method === 'GET') {
    $action = $_GET['action'] ?? null;
} else {
    $raw   = file_get_contents('php://input');
    $input = json_decode($raw, true) ?? [];
    $action = $input['action'] ?? null;
}

// ──────────────────────────────────────────────────────────────────
// Acción: check_stock
// POST { action:'check_stock', items:[{id,qty},...] }
// Respuesta: { all_ok:bool, out:[{id,nombre,stock,needed}] }
// ──────────────────────────────────────────────────────────────────
if ($action === 'check_stock') {
    $items  = $input['items'] ?? [];
    $out    = [];
    $allOk  = true;

    foreach ($items as $item) {
        $sku = $item['id'] ?? '';
        $qty = floatval($item['qty'] ?? 1);
        if (!$sku) continue;

        // Verificar si el producto es servicio (no tiene stock físico)
        $stmtProd = $pdo->prepare("SELECT nombre, es_servicio FROM productos WHERE codigo = ?");
        $stmtProd->execute([$sku]);
        $prod = $stmtProd->fetch(PDO::FETCH_ASSOC);
        if (!$prod || intval($prod['es_servicio']) === 1) continue; // servicios siempre OK

        $stmtStock = $pdo->prepare(
            "SELECT COALESCE(SUM(cantidad),0) FROM stock_almacen WHERE id_producto = ? AND id_almacen = ?"
        );
        $stmtStock->execute([$sku, $idAlmacen]);
        $stock = floatval($stmtStock->fetchColumn());

        if ($stock < $qty) {
            $allOk = false;
            $out[] = [
                'id'      => $sku,
                'nombre'  => $prod['nombre'],
                'stock'   => $stock,
                'needed'  => $qty,
            ];
        }
    }

    echo json_encode(['all_ok' => $allOk, 'out' => $out]);
    exit;
}

// ──────────────────────────────────────────────────────────────────
// Acción: check_status
// GET ?action=check_status&uuid=XXX
// Respuesta: { estado_pago, estado_reserva }
// ──────────────────────────────────────────────────────────────────
if ($action === 'check_status') {
    $uuid = trim($_GET['uuid'] ?? $input['uuid'] ?? '');
    if (!$uuid) { echo json_encode(['error' => 'uuid requerido']); exit; }

    $stmt = $pdo->prepare(
        "SELECT estado_pago, estado_reserva, motivo_rechazo FROM ventas_cabecera WHERE uuid_venta = ? LIMIT 1"
    );
    $stmt->execute([$uuid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) { echo json_encode(['error' => 'no encontrado']); exit; }

    echo json_encode([
        'estado_pago'     => $row['estado_pago']      ?? 'pendiente',
        'estado_reserva'  => $row['estado_reserva']   ?? 'PENDIENTE',
        'motivo_rechazo'  => $row['motivo_rechazo']   ?? null,
    ]);
    exit;
}

// ──────────────────────────────────────────────────────────────────
// Acción: confirm_payment
// POST { action:'confirm_payment', id:N }  — requiere sesión admin
// ──────────────────────────────────────────────────────────────────
if ($action === 'confirm_payment') {
    session_start();
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        http_response_code(403);
        echo json_encode(['error' => 'No autorizado']);
        exit;
    }

    $id = intval($input['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['error' => 'id inválido']); exit; }

    try {
        $pdo->beginTransaction();

        // Leer datos del pedido
        $stmtVenta = $pdo->prepare(
            "SELECT uuid_venta, cliente_nombre FROM ventas_cabecera WHERE id = ?"
        );
        $stmtVenta->execute([$id]);
        $venta = $stmtVenta->fetch(PDO::FETCH_ASSOC);
        if (!$venta) throw new Exception("Pedido #{$id} no encontrado.");

        // Actualizar estado_pago
        $pdo->prepare("UPDATE ventas_cabecera SET estado_pago = 'confirmado' WHERE id = ?")
            ->execute([$id]);

        // Insertar notificación en chat para el cliente
        $uuid   = $venta['uuid_venta'];
        $nombre = $venta['cliente_nombre'];
        $pdo->prepare(
            "INSERT INTO chat_messages (client_uuid, sender, message, is_read) VALUES (?, 'admin', ?, 0)"
        )->execute([
            'PAGO_CONFIRMADO_' . $uuid,
            "✓ Pago confirmado para el pedido de {$nombre}. ¡Tu pedido está listo! Gracias."
        ]);

        // También insertar con el client_uuid de seguimiento de ese pedido
        // para que el cliente que hace polling de check_status lo vea actualizado.

        $pdo->commit();
        echo json_encode(['status' => 'success', 'msg' => 'Pago confirmado correctamente.']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

// ──────────────────────────────────────────────────────────────────
// Acción: reject_payment
// POST { action:'reject_payment', id:N, motivo:'...' }  — requiere sesión admin
// ──────────────────────────────────────────────────────────────────
if ($action === 'reject_payment') {
    session_start();
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        http_response_code(403);
        echo json_encode(['error' => 'No autorizado']);
        exit;
    }

    $id     = intval($input['id'] ?? 0);
    $motivo = trim($input['motivo'] ?? 'Transferencia no verificada.');
    if ($id <= 0) { echo json_encode(['error' => 'id inválido']); exit; }

    try {
        // Auto-migrar columna si no existe
        try { $pdo->exec("ALTER TABLE ventas_cabecera ADD COLUMN IF NOT EXISTS motivo_rechazo VARCHAR(500) DEFAULT NULL"); } catch(Exception $e) {}

        $pdo->beginTransaction();
        $pdo->prepare("UPDATE ventas_cabecera SET estado_pago='rechazado', estado_reserva='CANCELADO', motivo_rechazo=? WHERE id=?")
            ->execute([$motivo, $id]);
        $pdo->commit();
        echo json_encode(['status' => 'success', 'msg' => 'Pago rechazado.']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

// Acción no reconocida
echo json_encode(['error' => 'Acción no reconocida', 'action' => $action]);
?>
