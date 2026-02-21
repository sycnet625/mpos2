<?php
// ARCHIVO: update_order.php
require_once 'db.php';
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
        $stmt = $pdo->prepare("UPDATE pedidos_cabecera SET estado = ?, notas_admin = ? WHERE id = ?");
        $stmt->execute([$estado, $nota, $id]);
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
        $stmt = $pdo->prepare("UPDATE ventas_cabecera SET estado_reserva = ?, notas = ? WHERE id = ? AND tipo_servicio = 'reserva'");
        $stmt->execute([$estadoReserva, $nota, $id]);
    }

    if ($stmt->rowCount() === 0) {
        echo json_encode(['status' => 'error', 'msg' => 'No se encontró el registro o no hubo cambios']);
    } else {
        echo json_encode(['status' => 'success']);
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
}
