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

    $subtotalItems = 0;
    foreach ($items as $it) $subtotalItems += $it['cantidad'] * $it['precio'];
    $costoEnvio = round($venta['total'] - $subtotalItems, 2);
    $tiposConEnvio = ['mensajeria', 'domicilio', 'delivery'];
    $esDelivery = in_array(strtolower($venta['tipo_servicio'] ?? ''), $tiposConEnvio)
               || strpos($venta['cliente_direccion'] ?? '', '[MENSAJERÍA:') !== false;
    $hayEnvio = $costoEnvio > 0.01 && $esDelivery;

    $cajero = "Cajero";
    if (isset($venta['id_caja']) && $venta['id_caja'] > 0) {
        $stmtCaj = $pdo->prepare("SELECT nombre_cajero FROM caja_sesiones WHERE id = ?");
        $stmtCaj->execute([$venta['id_caja']]);
        $cajero = $stmtCaj->fetchColumn() ?: "Cajero";
    }
} catch (Exception $e) { die("Error DB."); }

$viaQr = ($_GET['source'] ?? '') === 'qr';

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket #<?php echo $idVenta; ?></title>
    <script src="assets/js/qrcode.min.js"></script>
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

        /* ── Modo QR / Responsive ── */
        body.qr-mode {
            width: 100% !important;
            max-width: 100%;
            padding: 0;
            margin: 0;
            background: #e5e7eb;
            min-height: 100vh;
        }
        body.qr-mode .ticket-paper {
            background: #fff;
            font-size: 14px;
        }
        /* Móvil: pantalla completa */
        @media screen and (max-width: 600px) {
            body.qr-mode .ticket-paper {
                width: 100%;
                min-height: 100vh;
                padding: 16px 14px;
                font-size: 14px;
            }
        }
        /* Tablet: centrado con sombra */
        @media screen and (min-width: 601px) and (max-width: 1023px) {
            body.qr-mode {
                display: flex;
                justify-content: center;
                align-items: flex-start;
                padding: 28px 16px;
            }
            body.qr-mode .ticket-paper {
                width: 500px;
                padding: 24px 20px;
                border-radius: 12px;
                box-shadow: 0 4px 28px rgba(0,0,0,0.18);
                font-size: 14px;
            }
        }
        /* PC: fondo oscuro, ticket centrado con borde */
        @media screen and (min-width: 1024px) {
            body.qr-mode {
                background: #1f2937;
                display: flex;
                justify-content: center;
                align-items: flex-start;
                padding: 52px 24px;
            }
            body.qr-mode .ticket-paper {
                width: 480px;
                padding: 32px 28px;
                border: 2px solid #374151;
                border-radius: 10px;
                box-shadow: 0 16px 56px rgba(0,0,0,0.55), 0 0 0 1px #4b5563;
                font-size: 14px;
            }
        }
    </style>
</head>
<body<?php echo $viaQr ? ' class="qr-mode"' : ''; ?>>
<div class="ticket-paper">
    <div class="no-print text-center border-bottom mb-2">
        <button onclick="window.print()" style="padding: 10px;">🖨️ IMPRIMIR</button>
        <button onclick="window.close()" style="padding: 10px;">CERRAR</button>
    </div>

    <div class="text-center">
        <?php
        $tLogoPath = $config['ticket_logo'] ?? '';
        if (!empty($tLogoPath) && file_exists(__DIR__ . '/' . $tLogoPath)):
        ?>
        <img src="<?php echo htmlspecialchars($tLogoPath) ?>?v=<?php echo filemtime(__DIR__ . '/' . $tLogoPath) ?>"
             style="max-width:240px; max-height:80px; display:block; margin:0 auto 6px;" alt="Logo">
        <?php endif; ?>
        <h2 style="margin:0;"><?php echo htmlspecialchars($config['tienda_nombre']); ?></h2>
        <?php if (!empty($config['ticket_slogan'])): ?>
        <small style="font-style:italic;"><?php echo htmlspecialchars($config['ticket_slogan']); ?></small><br>
        <?php endif; ?>
        <small><?php echo htmlspecialchars($config['direccion']); ?></small><br>
        <small>Tel: <?php echo htmlspecialchars($config['telefono']); ?></small>
    </div>

    <div class="border-top border-bottom mt-2">
        <table>
            <tr><td>Ticket:</td><td class="text-right fw-bold">#<?php echo str_pad($idVenta, 6, '0', STR_PAD_LEFT); ?></td></tr>
            <?php if ($config['ticket_mostrar_uuid'] ?? false): ?>
            <tr><td>UUID:</td><td class="text-right" style="font-size:9px;"><?php echo htmlspecialchars($venta['uuid_venta']); ?></td></tr>
            <?php endif; ?>
            <tr><td>Fecha:</td><td class="text-right"><?php echo date('d/m/Y H:i', strtotime($venta['fecha'])); ?></td></tr>
            <?php if ($config['ticket_mostrar_cajero'] ?? true): ?>
            <tr><td>Cajero:</td><td class="text-right fw-bold"><?php echo htmlspecialchars($cajero); ?></td></tr>
            <?php endif; ?>
            <?php if ($config['ticket_mostrar_canal'] ?? true): ?>
            <tr><td>Origen:</td><td class="text-right">
                <span class="canal-badge" style="background-color:<?php echo $canalColor; ?>!important;">
                    <?php echo $canalEmoji; ?> <?php echo htmlspecialchars($canalLabel); ?>
                </span>
            </td></tr>
            <?php endif; ?>
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
        <?php if ($config['ticket_mostrar_items_count'] ?? true): ?>
        <tfoot>
            <tr style="border-top: 2px solid #000;">
                <td colspan="3" class="text-right fw-bold">Total Items: <?php echo $itemCount; ?></td>
                <td></td>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>

    <div class="total-section">
        <table>
            <?php if($venta['tipo_servicio'] === 'reserva' && !empty($venta['abono'])): ?>
                <?php if ($hayEnvio): ?>
                    <tr><td>Subtotal productos:</td><td class="text-right">$<?php echo number_format($subtotalItems, 2); ?></td></tr>
                    <tr><td>Costo mensajería:</td><td class="text-right fw-bold">$<?php echo number_format($costoEnvio, 2); ?></td></tr>
                    <tr style="border-top: 1px dashed #000;"><td colspan="2"></td></tr>
                <?php endif; ?>
                <tr><td><?php echo $hayEnvio ? 'Total pedido:' : 'Subtotal:'; ?></td><td class="text-right">$<?php echo number_format($venta['total'], 2); ?></td></tr>
                <tr><td>Abono Recibido:</td><td class="text-right fw-bold" style="color: #28a745;">-$<?php echo number_format($venta['abono'] ?? 0, 2); ?></td></tr>
                <tr style="border-top: 2px solid #000; padding-top: 5px;">
                    <td class="fw-bold" style="font-size:14px;">PENDIENTE:</td>
                    <td class="text-right fw-bold" style="font-size:18px; color: #dc3545;">
                        $<?php echo number_format($venta['total'] - ($venta['abono'] ?? 0), 2); ?>
                    </td>
                </tr>
            <?php else: ?>
                <?php if ($hayEnvio): ?>
                    <tr>
                        <td>Subtotal productos:</td>
                        <td class="text-right">$<?php echo number_format($subtotalItems, 2); ?></td>
                    </tr>
                    <tr>
                        <td>Costo mensajería:</td>
                        <td class="text-right fw-bold">$<?php echo number_format($costoEnvio, 2); ?></td>
                    </tr>
                    <tr style="border-top: 1px dashed #000;"><td colspan="2"></td></tr>
                <?php endif; ?>
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
                <?php
                $monedaTk = $venta['moneda'] ?? 'CUP';
                $tcTk     = floatval($venta['tipo_cambio'] ?? 1.0);
                $montoOTk = floatval($venta['monto_moneda_original'] ?? 0);
                if ($monedaTk !== 'CUP' && $tcTk > 1.0 && $montoOTk > 0):
                ?>
                <tr><td colspan="2" class="text-center" style="font-size:10px; color:#666; border-top:1px dashed #000; padding-top:3px;">
                    Pagado en <?= htmlspecialchars($monedaTk) ?>: <b><?= htmlspecialchars($monedaTk) ?> <?= number_format($montoOTk, 2) ?></b>
                    (TC: 1 <?= htmlspecialchars($monedaTk) ?> = <?= number_format($tcTk, 2) ?> CUP)
                </td></tr>
                <?php endif; ?>
            <?php endif; ?>
        </table>
    </div>

    <div class="text-center mt-3 border-top pt-2">
        <p class="fw-bold" style="margin:0;"><?php echo htmlspecialchars($config['mensaje_final']); ?></p>
        <small>Sistema PALWEB POS v3.0</small>
    </div>

    <?php if ($config['ticket_mostrar_qr'] ?? true): ?>
    <?php if ($viaQr): ?>
        <?php
        // Determinar estado según tipo de orden
        $esReserva = ($venta['tipo_servicio'] === 'reserva');
        $statusRaw = $esReserva
            ? strtoupper(trim($venta['estado_reserva'] ?? 'PENDIENTE'))
            : strtolower(trim($venta['estado_pago']   ?? 'pendiente'));
        $statusMap = [
            'PENDIENTE'      => ['🕐', 'EN REVISIÓN',         '#92400e', '#fef3c7', '#f59e0b'],
            'CONFIRMADO'     => ['✅', 'CONFIRMADO',           '#14532d', '#dcfce7', '#22c55e'],
            'EN_PREPARACION' => ['👨‍🍳', 'EN PREPARACIÓN',     '#1e3a8a', '#dbeafe', '#3b82f6'],
            'LISTO'          => ['📦', 'LISTO PARA ENTREGAR',  '#4c1d95', '#ede9fe', '#8b5cf6'],
            'ENTREGADO'      => ['🎉', 'ENTREGADO',            '#14532d', '#dcfce7', '#16a34a'],
            'CANCELADO'      => ['❌', 'CANCELADO',            '#7f1d1d', '#fee2e2', '#dc2626'],
            'confirmado'     => ['✅', 'PAGO CONFIRMADO',      '#14532d', '#dcfce7', '#22c55e'],
            'pendiente'      => ['🕐', 'PAGO PENDIENTE',       '#92400e', '#fef3c7', '#f59e0b'],
            'verificando'    => ['🔍', 'VERIFICANDO PAGO',     '#1e3a8a', '#dbeafe', '#3b82f6'],
            'rechazado'      => ['❌', 'PAGO RECHAZADO',       '#7f1d1d', '#fee2e2', '#dc2626'],
        ];
        [$emoji, $label, $textColor, $bgColor, $borderColor] = $statusMap[$statusRaw]
            ?? ['❓', strtoupper($statusRaw), '#374151', '#f3f4f6', '#6b7280'];
        ?>
        <div class="text-center mt-3 border-top pt-3">
            <div style="background:<?php echo $bgColor ?>; border:3px solid <?php echo $borderColor ?>;
                        border-radius:10px; padding:18px 12px; margin:6px 0;
                        print-color-adjust:exact; -webkit-print-color-adjust:exact;">
                <div style="font-size:36px; line-height:1;"><?php echo $emoji; ?></div>
                <div style="font-size:22px; font-weight:bold; color:<?php echo $textColor ?>;
                            margin-top:8px; letter-spacing:1px; line-height:1.2;">
                    <?php echo htmlspecialchars($label); ?>
                </div>
                <div style="font-size:10px; color:#666; margin-top:6px;">
                    Orden #<?php echo str_pad($idVenta, 6, '0', STR_PAD_LEFT); ?>
                    &nbsp;·&nbsp; <?php echo date('d/m/Y', strtotime($venta['fecha'])); ?>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="text-center mt-3 border-top pt-2">
            <small style="font-size:10px; display:block; margin-bottom:4px;">Escanea para ver el estado de tu orden</small>
            <div id="qrcode" style="display:inline-block;"></div>
        </div>
        <script>
            var ticketUrl = '<?php
                $scheme   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $basePath = strtok($_SERVER['REQUEST_URI'], '?');
                echo $scheme . '://' . $_SERVER['HTTP_HOST'] . $basePath . '?id=' . $idVenta . '&source=qr';
            ?>';
            new QRCode(document.getElementById('qrcode'), {
                text: ticketUrl,
                width: 120,
                height: 120,
                colorDark: '#000000',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.M
            });
        </script>
    <?php endif; ?>
    <?php endif; ?>
</div><!-- /ticket-paper -->
</body>
</html>
