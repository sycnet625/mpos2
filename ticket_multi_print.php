<?php
// ticket_multi_print.php — Impresión múltiple de tickets en A4 (3 columnas)
header('Content-Type: text/html; charset=utf-8');
ini_set('display_errors', 0);
require_once 'db.php';
require_once 'config_loader.php';

$idsRaw = $_GET['ids'] ?? '';
$ids    = array_values(array_slice(array_unique(array_filter(array_map('intval', explode(',', $idsRaw)), fn($v) => $v > 0)), 0, 6));

if (empty($ids)) die('Sin IDs válidos.');

$ph       = implode(',', array_fill(0, count($ids), '?'));
$stmtH    = $pdo->prepare("SELECT * FROM ventas_cabecera WHERE id IN ($ph) ORDER BY FIELD(id, $ph)");
$stmtH->execute(array_merge($ids, $ids));
$ventas   = $stmtH->fetchAll(PDO::FETCH_ASSOC);

$stmtD = $pdo->prepare("
    SELECT d.id_venta_cabecera, d.cantidad, d.precio,
           COALESCE(p.nombre, CONCAT('Art: ', d.id_producto)) AS nombre_producto
    FROM ventas_detalle d
    LEFT JOIN productos p ON d.id_producto = p.codigo
    WHERE d.id_venta_cabecera IN ($ph)
    ORDER BY d.id_venta_cabecera, d.id
");
$stmtD->execute($ids);
$allItems = $stmtD->fetchAll(PDO::FETCH_ASSOC);

$itemsByVenta = [];
foreach ($allItems as $it) $itemsByVenta[$it['id_venta_cabecera']][] = $it;

$ticketCount = count($ventas);
$gridClass = 'cols-2';
if ($ticketCount <= 1) {
    $gridClass = 'cols-1';
} elseif ($ticketCount === 2) {
    $gridClass = 'cols-2';
} elseif ($ticketCount >= 3 && $ticketCount <= 4) {
    $gridClass = 'cols-2';
} else {
    $gridClass = 'cols-2';
}

$tiposServicio = [
    'consumir_aqui' => 'COMER AQUÍ',
    'llevar'        => 'PARA LLEVAR',
    'mensajeria'    => 'DOMICILIO',
    'reserva'       => 'RESERVA',
];
$tiposConEnvio = ['mensajeria', 'domicilio', 'delivery'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Tickets A4 · <?= count($ventas) ?> tickets</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Courier New', monospace; font-size: 11px; background: #f0f0f0; color: #000; }

/* ── Barra de herramientas (solo pantalla) ── */
.no-print {
    background: #1e293b; color: #fff; padding: 10px 16px;
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
}
.no-print span { font-size: 12px; opacity: .8; }
.no-print button { padding: 7px 14px; border: none; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 700; }
.btn-print-a4 { background: #22c55e; color: #fff; }
.btn-close-w  { background: #475569; color: #fff; }

/* ── Grid de tickets ── */
.tickets-grid {
    display: grid;
    gap: 6mm;
    padding: 8mm;
}
.tickets-grid.cols-1 { grid-template-columns: 1fr; }
.tickets-grid.cols-2 { grid-template-columns: repeat(2, 1fr); }
.tickets-grid.cols-3 { grid-template-columns: repeat(3, 1fr); }
.tickets-grid.cols-1 .ticket-cell {
    min-height: 255mm;
}
.tickets-grid.cols-2 .ticket-cell {
    min-height: 86mm;
}
.tickets-grid.cols-3 .ticket-cell {
    min-height: 86mm;
}

/* ── Celda de un ticket ── */
.ticket-cell {
    background: #fff;
    border: 1px dashed #999;
    padding: 5mm;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    justify-content: flex-start;
}

/* ── Elementos internos ── */
.tk-store    { font-weight: 700; font-size: 14px; text-align: center; }
.tk-slogan   { font-size: 10px; font-style: italic; text-align: center; }
.tk-sep      { border: none; border-top: 1px dashed #666; margin: 5px 0; }
.tk-row      { display: flex; justify-content: space-between; gap: 6px; line-height: 1.75; font-size: 12px; }
.tk-label    { color: #555; flex-shrink: 0; }
.tk-val      { font-weight: 700; text-align: right; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tk-service  {
    text-align: center; font-weight: 700; font-size: 11px;
    background: #f0f0f0; padding: 4px 6px; margin: 4px 0;
    border: 1px solid #bbb;
}
.tk-delivery {
    text-align: center; font-weight: 700; font-size: 11px; color: #7c3aed;
    margin: 4px 0;
}
.tk-items { width: 100%; border-collapse: collapse; margin: 4px 0; }
.tk-items th { border-bottom: 1px solid #000; font-size: 11px; padding: 3px 4px; }
.tk-items td { font-size: 11px; padding: 3px 4px; border-bottom: 1px dotted #ddd; }
.tk-items .r { text-align: right; white-space: nowrap; }
.tk-total {
    text-align: center; font-weight: 700; font-size: 15px;
    background: #111; color: #fff; padding: 6px;
    margin-top: 6px;
    print-color-adjust: exact; -webkit-print-color-adjust: exact;
}
.tk-extra { font-size: 10px; color: #444; margin-top: 4px; line-height: 1.5; }
.tk-address {
    font-size: 13px;
    font-weight: 700;
    color: #111;
    line-height: 1.55;
    margin-top: 6px;
    padding: 5px 6px;
    border: 1px dashed #666;
    background: #fafafa;
}

/* ── Print ── */
@media print {
    .no-print { display: none !important; }
    body { background: white; }
    @page { size: A4 portrait; margin: 8mm; }
    .tickets-grid { padding: 0; gap: 5mm; }
}

/* ── Screen preview centrado ── */
@media screen {
    .tickets-grid { max-width: 230mm; margin: 0 auto; }
}
</style>
</head>
<body>

<div class="no-print">
    <span><?= count($ventas) ?> ticket(s) — diseño ampliado A4</span>
    <button class="btn-print-a4" onclick="window.print()">🖨️ IMPRIMIR A4</button>
    <button class="btn-close-w"  onclick="window.close()">✕ Cerrar</button>
</div>

<div class="tickets-grid <?= $gridClass ?>">
<?php foreach ($ventas as $v):
    $items      = $itemsByVenta[$v['id']] ?? [];
    $subtotal   = array_sum(array_map(fn($i) => $i['cantidad'] * $i['precio'], $items));
    $costoEnvio = round($v['total'] - $subtotal, 2);
    $esDelivery = in_array(strtolower($v['tipo_servicio'] ?? ''), $tiposConEnvio);
    $hayEnvio   = $costoEnvio > 0.01 && $esDelivery;
    $tipoDisplay = $tiposServicio[$v['tipo_servicio']] ?? strtoupper($v['tipo_servicio'] ?? '');
    $mostrarCliente = !empty($v['cliente_nombre'])
        && !in_array($v['cliente_nombre'], ['Mostrador', 'Consumidor Final', 'DEVOLUCIÓN']);
?>
<div class="ticket-cell">

    <!-- Cabecera tienda -->
    <div class="tk-store"><?= htmlspecialchars($config['tienda_nombre']) ?></div>
    <?php if (!empty($config['ticket_slogan'])): ?>
    <div class="tk-slogan"><?= htmlspecialchars($config['ticket_slogan']) ?></div>
    <?php endif; ?>

    <hr class="tk-sep">

    <!-- Datos del ticket -->
    <div class="tk-row"><span class="tk-label">Ticket:</span><span class="tk-val">#<?= str_pad($v['id'], 5, '0', STR_PAD_LEFT) ?></span></div>
    <div class="tk-row"><span class="tk-label">Fecha:</span><span class="tk-val"><?= date('d/m/Y H:i', strtotime($v['fecha'])) ?></span></div>
    <?php if ($mostrarCliente): ?>
    <div class="tk-row"><span class="tk-label">Cliente:</span><span class="tk-val"><?= htmlspecialchars(mb_strimwidth($v['cliente_nombre'], 0, 24, '…')) ?></span></div>
    <?php endif; ?>
    <?php if (!empty($v['cliente_telefono'])): ?>
    <div class="tk-row"><span class="tk-label">Tel:</span><span class="tk-val"><?= htmlspecialchars($v['cliente_telefono']) ?></span></div>
    <?php endif; ?>
    <?php if (!empty($v['metodo_pago'])): ?>
    <div class="tk-row"><span class="tk-label">Pago:</span><span class="tk-val"><?= htmlspecialchars($v['metodo_pago']) ?></span></div>
    <?php endif; ?>

    <?php if (!empty($tipoDisplay)): ?>
    <div class="tk-service">📦 <?= $tipoDisplay ?></div>
    <?php endif; ?>
    <?php if (!empty($v['fecha_reserva'])): ?>
    <div class="tk-delivery">📅 Entrega: <?= date('d/m/Y h:i A', strtotime($v['fecha_reserva'])) ?></div>
    <?php endif; ?>

    <hr class="tk-sep">

    <!-- Ítems -->
    <table class="tk-items">
        <thead>
            <tr>
                <th>Descripción</th>
                <th class="r">Cant</th>
                <th class="r">P.U.</th>
                <th class="r">Total</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $item): $sub = $item['cantidad'] * $item['precio']; ?>
            <tr>
        <td><?= htmlspecialchars(mb_strimwidth($item['nombre_producto'], 0, 30, '…')) ?></td>
                <td class="r"><?= rtrim(rtrim(number_format($item['cantidad'], 2), '0'), '.') ?></td>
                <td class="r">$<?= number_format($item['precio'], 2) ?></td>
                <td class="r">$<?= number_format($sub, 2) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <hr class="tk-sep">

    <!-- Totales -->
    <?php if ($hayEnvio): ?>
    <div class="tk-row"><span>Subtotal:</span><span>$<?= number_format($subtotal, 2) ?></span></div>
    <div class="tk-row"><span>Mensajería:</span><span>$<?= number_format($costoEnvio, 2) ?></span></div>
    <?php endif; ?>
    <div class="tk-total">TOTAL $<?= number_format($v['total'], 2) ?></div>

    <?php if (!empty($v['notas'])): ?>
    <div class="tk-extra">📝 <?= htmlspecialchars(mb_strimwidth($v['notas'], 0, 90, '…')) ?></div>
    <?php endif; ?>
    <?php if (!empty($v['cliente_direccion'])): ?>
    <div class="tk-extra tk-address">📍 <?= htmlspecialchars(mb_strimwidth($v['cliente_direccion'], 0, 140, '…')) ?></div>
    <?php endif; ?>

</div>
<?php endforeach; ?>
</div>

</body>
</html>
