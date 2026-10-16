<?php
// ARCHIVO: update_order.php
require_once __DIR__ . '/admin_security.php';
require_once 'db.php';
require_once 'config_loader.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['id']) || empty($input['estado'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Datos incompletos']);
    exit;
}

$id     = (int)$input['id'];
$estado = $input['estado'];
$nota   = $input['nota'] ?? '';
$origen = $input['origen'] ?? 'POS';

try {
    if ($origen === 'WEB') {
        // Pedidos web: tabla pedidos_cabecera, columna estado (minúsculas)
        $empresa = (int)($_SESSION['id_empresa'] ?? $config['id_empresa'] ?? 1);
        $stmt = $pdo->prepare("UPDATE pedidos_cabecera SET estado = ?, notas_admin = ? WHERE id = ? AND id_empresa = ?");
        $stmt->execute([$estado, $nota, $id, $empresa]);
    } else {
        // Reservas POS: tabla ventas_cabecera, columna estado_reserva (MAYÚSCULAS)
        $mapaEstados = [
            'pendiente'  => 'PENDIENTE',
            'proceso'    => 'EN_PREPARACION',
            'camino'     => 'EN_CAMINO',
            'completado' => 'ENTREGADO',
            'cancelado'  => 'CANCELADO',
        ];
        $estadoReserva = $mapaEstados[$estado] ?? 'PENDIENTE';
        $empresa = (int)($_SESSION['id_empresa'] ?? $config['id_empresa'] ?? 1);
        $sucursal = (int)($_SESSION['id_sucursal'] ?? $config['id_sucursal'] ?? 1);
        $almacen = (int)($_SESSION['id_almacen'] ?? $config['id_almacen'] ?? 1);
        $stmt = $pdo->prepare("UPDATE ventas_cabecera SET estado_reserva = ?, notas = ? WHERE id = ? AND tipo_servicio = 'reserva' AND id_empresa = ? AND id_sucursal = ? AND id_almacen = ?");
        $stmt->execute([$estadoReserva, $nota, $id, $empresa, $sucursal, $almacen]);
    }

    if ($stmt->rowCount() === 0) {
        echo json_encode(['status' => 'error', 'msg' => 'No se encontró el registro o no hubo cambios']);
    } else {
        echo json_encode(['status' => 'success']);
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
}
