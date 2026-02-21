<?php
// ARCHIVO: /var/www/palweb/api/ticket_view.php
header('Content-Type: text/html; charset=utf-8');
ini_set('display_errors', 0);
require_once 'db.php';

require_once 'config_loader.php';

$idVenta = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($idVenta <= 0) die("ID inválido.");

try {
    $stmtHead = $pdo->prepare("SELECT * FROM ventas_cabecera WHERE id = ?");
    $stmtHead->execute([$idVenta]);
    $venta = $stmtHead->fetch(PDO::FETCH_ASSOC);
    if (!$venta) die("No existe.");

    $sqlDet = "SELECT d.cantidad, d.precio, d.id_producto, COALESCE(p.nombre, 'Art: ' || d.id_producto) as nombre_producto FROM ventas_detalle d LEFT JOIN productos p ON d.id_producto = p.codigo WHERE d.id_venta_cabecera = ?";
    $stmtDet = $pdo->prepare($sqlDet); $stmtDet->execute([$idVenta]);
    $items = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

    $cajero = "Cajero";
    if (isset($venta['id_caja']) && $venta['id_caja'] > 0) {
        $stmtCaj = $pdo->prepare("SELECT nombre_cajero FROM caja_sesiones WHERE id = ?");
        $stmtCaj->execute([$venta['id_caja']]);
        $cajero = $stmtCaj->fetchColumn() ?: "Cajero";
    }
} catch (Exception $e) { die("Error DB."); }

$tiposServicio = [ 'consumir_aqui' => 'COMER AQUÍ', 'llevar' => 'PARA LLEVAR', 'mensajeria' => 'DOMICILIO', 'reserva' => 'RESERVA' ];
$tipoServicioDisplay = $tiposServicio[$venta['tipo_servicio']] ?? strtoupper($venta['tipo_servicio']);

$canalOrigen = $venta['canal_origen'] ?? 'POS';
$canalMap = [
    'Web'        => ['#0ea5e9', '🌐', 'Web'],
    'POS'        => ['#6366f1', '🖥️', 'POS'],
    'WhatsApp'   => ['#22c55e', '💬', 'WhatsApp'],
    'Teléfono'   => ['#f59e0b', '📞', 'Teléfono'],
    'Kiosko'     => ['#8b5cf6', '📱', 'Kiosko'],
    'Presencial' => ['#475569', '🙋', 'Presencial'],
    'ICS'        => ['#94a3b8', '📥', 'Importado'],
    'Otro'       => ['#94a3b8', '❓', 'Otro'],
];
[$canalColor, $canalEmoji, $canalLabel] = $canalMap[$canalOrigen] ?? $canalMap['Otro'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ticket #<?php echo $idVenta; ?></title>
    <style>
        body { font-family: 'Courier New', monospace; font-size: 12px; padding: 10px; width: 300px; color: #000; background: #fff; }
        .text-center { text-align: center; } 
        .text-right { text-align: right; } 
        .fw-bold { font-weight: bold; }
        .border-top { border-top: 1px dashed #000; margin-top: 5px; padding-top: 5px; }
        .border-bottom { border-bottom: 1px dashed #000; margin-bottom: 5px; padding-bottom: 5px; }
        table { width: 100%; border-collapse: collapse; }
        table td { padding: 2px 0; }
        .delivery-highlight { 
            background: #fff3cd; 
            padding: 6px; 
            border: 2px solid #856404; 
            display: block; 
            margin: 5px 0; 
            font-size: 13px; 
            border-radius: 3px;
            text-align: center;
        }
        .items-table { margin: 8px 0; }
        .items-table th { 
            border-bottom: 2px solid #000; 
            padding: 3px 0; 
            font-weight: bold;
        }
        .items-table td { 
            padding: 3px 0; 
            border-bottom: 1px dotted #ccc;
        }
        .total-section { 
            background: #f8f9fa; 
            padding: 8px; 
            margin-top: 5px;
            border: 2px solid #000;
        }
        .canal-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
            color: white !important;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        @media print {
            .no-print { display: none; }
            body { padding: 0; width: 100%; }
            .total-section { background: white; }
            .canal-badge { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <div class="no-print text-center border-bottom mb-2">
        <button onclick="window.print()" style="padding: 10px;">🖨️ IMPRIMIR</button>
        <button onclick="window.close()" style="padding: 10px;">CERRAR</button>
    </div>

    <div class="text-center">
        <h2 style="margin:0;"><?php echo htmlspecialchars($config['tienda_nombre']); ?></h2>
        <small><?php echo htmlspecialchars($config['direccion']); ?></small><br>
        <small>Tel: <?php echo htmlspecialchars($config['telefono']); ?></small>
    </div>

    <div class="border-top border-bottom mt-2">
        <table>
            <tr><td>Ticket:</td><td class="text-right fw-bold">#<?php echo str_pad($idVenta, 6, '0', STR_PAD_LEFT); ?></td></tr>
            <tr><td>UUID:</td><td class="text-right" style="font-size:9px;"><?php echo htmlspecialchars($venta['uuid_venta']); ?></td></tr>
            <tr><td>Fecha:</td><td class="text-right"><?php echo date('d/m/Y H:i', strtotime($venta['fecha'])); ?></td></tr>
            <tr><td>Cajero:</td><td class="text-right fw-bold"><?php echo htmlspecialchars($cajero); ?></td></tr>
            <tr><td>Origen:</td><td class="text-right">
                <span class="canal-badge" style="background-color:<?php echo $canalColor; ?>!important;">
                    <?php echo $canalEmoji; ?> <?php echo htmlspecialchars($canalLabel); ?>
                </span>
            </td></tr>
            <tr><td>Método Pago:</td><td class="text-right fw-bold"><?php echo htmlspecialchars($venta['metodo_pago']); ?></td></tr>
            
            <?php if (!empty($venta['tipo_servicio'])): ?>
                <tr><td colspan="2" class="text-center">
                    <div style="background: #f0f0f0; padding: 5px; margin: 3px 0; border: 1px solid #ccc; font-weight: bold;">
                        📦 <?php echo $tipoServicioDisplay; ?>
                    </div>
                </td></tr>
            <?php endif; ?>
            
            <?php if (!empty($venta['fecha_reserva'])): ?>
                <tr><td colspan="2" class="text-center">
                    <div class="delivery-highlight fw-bold">
                        📅 ENTREGA: <?php echo date('d/m/Y h:i A', strtotime($venta['fecha_reserva'])); ?>
                    </div>
                </td></tr>
            <?php endif; ?>

            <?php if (!empty($venta['cliente_nombre']) && !in_array($venta['cliente_nombre'], ['Mostrador', 'Consumidor Final', 'DEVOLUCIÓN'])): ?>
                <tr><td>Cliente:</td><td class="text-right fw-bold"><?php echo htmlspecialchars($venta['cliente_nombre']); ?></td></tr>
            <?php endif; ?>
            <?php if (!empty($venta['cliente_telefono'])): ?>
                <tr><td>Teléfono:</td><td class="text-right"><?php echo htmlspecialchars($venta['cliente_telefono']); ?></td></tr>
            <?php endif; ?>
            <?php if (!empty($venta['cliente_direccion'])): ?>
                <tr><td colspan="2" style="font-size:11px; padding-top: 3px;">
                    <strong>Dirección:</strong><br>
                    <?php echo htmlspecialchars($venta['cliente_direccion']); ?>
                </td></tr>
            <?php endif; ?>
            <?php if (!empty($venta['notas'])): ?>
                <tr><td colspan="2" style="font-size:11px; padding-top: 3px; border-top: 1px dashed #ccc; margin-top: 3px;">
                    <strong>Notas:</strong><br>
                    <em><?php echo htmlspecialchars($venta['notas']); ?></em>
                </td></tr>
            <?php endif; ?>
        </table>
    </div>

    <table class="items-table">
        <thead>
            <tr>
                <th align="left">Cant</th>
                <th align="left">Descripción</th>
                <th align="right">P.Unit</th>
                <th align="right">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $itemCount = 0;
            foreach($items as $item): 
                $sub = $item['cantidad'] * $item['precio']; 
                $itemCount++;
            ?>
            <tr>
                <td><?php echo number_format($item['cantidad'], 2); ?></td>
                <td><?php echo htmlspecialchars($item['nombre_producto']); ?></td>
                <td align="right">$<?php echo number_format($item['precio'], 2); ?></td>
                <td align="right" class="fw-bold">$<?php echo number_format($sub, 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="border-top: 2px solid #000;">
                <td colspan="3" class="text-right fw-bold">Total Items: <?php echo $itemCount; ?></td>
                <td></td>
            </tr>
        </tfoot>
    </table>

    <div class="total-section">
        <table>
            <?php if($venta['tipo_servicio'] === 'reserva' && !empty($venta['abono'])): ?>
                <tr><td>Subtotal:</td><td class="text-right">$<?php echo number_format($venta['total'], 2); ?></td></tr>
                <tr><td>Abono Recibido:</td><td class="text-right fw-bold" style="color: #28a745;">-$<?php echo number_format($venta['abono'] ?? 0, 2); ?></td></tr>
                <tr style="border-top: 2px solid #000; padding-top: 5px;">
                    <td class="fw-bold" style="font-size:14px;">PENDIENTE:</td>
                    <td class="text-right fw-bold" style="font-size:18px; color: #dc3545;">
                        $<?php echo number_format($venta['total'] - ($venta['abono'] ?? 0), 2); ?>
                    </td>
                </tr>
            <?php else: ?>
                <tr>
                    <td class="fw-bold" style="font-size:14px;">TOTAL A PAGAR:</td>
                    <td class="text-right fw-bold" style="font-size:20px;">
                        $<?php echo number_format($venta['total'], 2); ?>
                    </td>
                </tr>
                <?php if (!empty($venta['metodo_pago'])): ?>
                    <tr><td colspan="2" class="text-center" style="font-size:11px; padding-top: 5px; border-top: 1px dashed #000;">
                        💳 Pagado con: <strong><?php echo htmlspecialchars($venta['metodo_pago']); ?></strong>
                    </td></tr>
                <?php endif; ?>
            <?php endif; ?>
        </table>
    </div>

    <div class="text-center mt-3 border-top pt-2">
        <p class="fw-bold" style="margin:0;"><?php echo htmlspecialchars($config['mensaje_final']); ?></p>
        <small>Sistema PALWEB POS v3.0</small>
    </div>
<?php include_once 'menu_master.php'; ?>
</body>
</html>
