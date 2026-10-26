<?php
declare(strict_types=1);
// Tablas temporales de conexión: no modifica reservas reales.
require __DIR__ . '/../db.php';
require __DIR__ . '/../cakes_admin.php';
$pdo->exec('CREATE TEMPORARY TABLE ventas_cabecera (id INT PRIMARY KEY, id_empresa INT, id_sucursal INT, canal_origen VARCHAR(30), estado_reserva VARCHAR(30), abono DECIMAL(12,2), total DECIMAL(12,2))');
$pdo->exec("INSERT INTO ventas_cabecera VALUES (1,10,6,'Web Cakes','PENDIENTE',0,1000),(2,10,6,'Web Cakes','PENDIENTE',0,1000)");
$reject = function (callable $fn, string $label): void {
    try { $fn(); } catch (InvalidArgumentException|RuntimeException $e) { echo "$label: OK\n"; return; }
    throw new RuntimeException("$label: debía rechazarse");
};
$reject(fn()=>cakes_change_status($pdo,1,10,6,'LISTO'), 'Salto de estado');
$reject(fn()=>cakes_change_status($pdo,1,10,7,'CONFIRMADO'), 'Sucursal ajena');
$reject(fn()=>cakes_change_status($pdo,1,11,6,'CONFIRMADO'), 'Empresa ajena');
foreach (['CONFIRMADO','EN_PREPARACION','LISTO','ENTREGADO'] as $state) cakes_change_status($pdo,1,10,6,$state);
if ($pdo->query('SELECT estado_reserva FROM ventas_cabecera WHERE id=1')->fetchColumn() !== 'ENTREGADO') throw new RuntimeException('Estado final incorrecto');
echo "Flujo completo de estados: OK\n";
cakes_add_deposit($pdo,2,10,6,'200.00');
$reject(fn()=>cakes_add_deposit($pdo,2,10,6,'801.00'), 'Abono superior al saldo');
$reject(fn()=>cakes_add_deposit($pdo,2,10,6,'-1'), 'Abono negativo');
$row=$pdo->query('SELECT abono,total-abono AS saldo FROM ventas_cabecera WHERE id=2')->fetch();
if ((float)$row['abono']!==200.0 || (float)$row['saldo']!==800.0) throw new RuntimeException('Saldo incorrecto');
cakes_change_status($pdo,2,10,6,'CANCELADO');
$reject(fn()=>cakes_add_deposit($pdo,2,10,6,'1'), 'Abono a reserva cancelada');
echo "Abono y saldo sin movimientos de caja: OK\n";
