<?php
declare(strict_types=1);

function cakes_change_status(PDO $pdo, int $id, int $empresa, int $sucursal, string $estado): void
{
    $transiciones = [
        'CONFIRMADO'=>['PENDIENTE'], 'EN_PREPARACION'=>['CONFIRMADO'],
        'LISTO'=>['EN_PREPARACION'], 'ENTREGADO'=>['LISTO'],
        'CANCELADO'=>['PENDIENTE','CONFIRMADO','EN_PREPARACION','LISTO'],
    ];
    if ($id <= 0 || !isset($transiciones[$estado])) throw new InvalidArgumentException('Estado inválido.');
    $placeholders = implode(',', array_fill(0, count($transiciones[$estado]), '?'));
    $stmt = $pdo->prepare("UPDATE ventas_cabecera SET estado_reserva=? WHERE id=? AND id_empresa=? AND id_sucursal=? AND canal_origen='Web Cakes' AND COALESCE(estado_reserva,'PENDIENTE') IN ($placeholders)");
    $stmt->execute(array_merge([$estado,$id,$empresa,$sucursal], $transiciones[$estado]));
    if ($stmt->rowCount() !== 1) throw new RuntimeException('La reserva no está disponible o su estado cambió.');
}

function cakes_add_deposit(PDO $pdo, int $id, int $empresa, int $sucursal, string $monto): void
{
    $monto = trim($monto);
    if ($id <= 0 || !preg_match('/\A\d{1,9}(?:\.\d{1,2})?\z/', $monto) || (float)$monto <= 0) throw new InvalidArgumentException('Introduce un abono válido mayor que cero.');
    $monto = number_format((float)$monto, 2, '.', '');
    $stmt = $pdo->prepare("UPDATE ventas_cabecera SET abono=COALESCE(abono,0)+? WHERE id=? AND id_empresa=? AND id_sucursal=? AND canal_origen='Web Cakes' AND COALESCE(estado_reserva,'PENDIENTE') <> 'CANCELADO' AND COALESCE(abono,0)+? <= total");
    $stmt->execute([$monto,$id,$empresa,$sucursal,$monto]);
    if ($stmt->rowCount() !== 1) throw new RuntimeException('Reserva no disponible o abono superior al saldo pendiente.');
}
