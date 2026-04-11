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

    $paymentBreakdown = [];
    try {
        $stmtPay = $pdo->prepare("
            SELECT metodo_pago, SUM(monto) AS monto
            FROM ventas_pagos
            WHERE id_venta_cabecera = ?
            GROUP BY metodo_pago
            ORDER BY SUM(monto) DESC, metodo_pago ASC
        ");
        $stmtPay->execute([$idVenta]);
        $paymentBreakdown = $stmtPay->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $paymentBreakdown = [];
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
            /* A4: ticket centrado, ancho de ticket térmico, no estirado */
            body.fmt-a4 {
                width: 100% !important;
                display: flex !important;
                justify-content: center !important;
                overflow: visible !important;
            }
            body.fmt-a4 .ticket-paper {
                width: 80mm !important;
                max-width: 80mm !important;
            }
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
    <div class="no-print border-bottom mb-2" style="padding:8px 10px; background:#f8f9fa; text-align:center;">
        <div style="font-size:10px; color:#888; font-weight:700; text-transform:uppercase; letter-spacing:.05em; margin-bottom:6px;">Formato de impresión</div>
        <div style="display:flex; gap:5px; justify-content:center; flex-wrap:wrap;">
            <button onclick="printWithFormat('58mm')" style="padding:6px 11px; font-size:11px; cursor:pointer; border:1px solid #6c757d; border-radius:5px; background:#fff; font-family:monospace;">
                🖨️ Térmica 58mm
            </button>
            <button onclick="printWithFormat('80mm')" style="padding:6px 11px; font-size:11px; cursor:pointer; border:2px solid #0d6efd; border-radius:5px; background:#0d6efd; color:#fff; font-weight:700; font-family:monospace;">
                🖨️ Térmica 80mm
            </button>
            <button onclick="printWithFormat('a4')" style="padding:6px 11px; font-size:11px; cursor:pointer; border:1px solid #198754; border-radius:5px; background:#198754; color:#fff; font-family:monospace;">
                📄 A4 Deskjet
            </button>
            <a href="ticket_to_invoice.php?id=<?= $idVenta ?>" target="_blank" style="padding:6px 11px; font-size:11px; cursor:pointer; border:1px solid #6f42c1; border-radius:5px; background:#6f42c1; color:#fff; font-family:monospace; text-decoration:none; display:inline-block;">
                📋 Ver como Factura
            </a>
            <a href="comprobante_ventas.php?id=<?= $idVenta ?>" target="_blank" style="padding:6px 11px; font-size:11px; cursor:pointer; border:2px solid #17a2b8; border-radius:5px; background:#17a2b8; color:#fff; font-family:monospace; text-decoration:none; display:inline-block; font-weight:700;">
                📄 Comprobante Premium
            </a>
            <button onclick="openWhatsAppModal(<?= $idVenta ?>)" style="padding:6px 11px; font-size:11px; cursor:pointer; border:2px solid #25d366; border-radius:5px; background:#25d366; color:#fff; font-family:monospace; font-weight:700;">
                💬 Enviar por WhatsApp
            </button>
            <button onclick="window.close()" style="padding:6px 11px; font-size:11px; cursor:pointer; border:1px solid #dee2e6; border-radius:5px; background:#fff; font-family:monospace;">
                ✕ Cerrar
            </button>
        </div>
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
                <?php if (count($paymentBreakdown) > 1 || strtolower((string)$venta['metodo_pago']) === 'mixto'): ?>
                    <tr><td colspan="2" style="padding-top:4px;">
                        <table style="width:100%; font-size:11px;">
                            <?php foreach ($paymentBreakdown as $payRow): ?>
                            <tr>
                                <td style="padding:1px 0 1px 8px;">- <?php echo htmlspecialchars((string)$payRow['metodo_pago']); ?></td>
                                <td class="text-right fw-bold" style="padding:1px 0;">$<?php echo number_format((float)$payRow['monto'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
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
        <small>Sistema <?= htmlspecialchars(config_loader_system_name()) ?> v3.0</small>
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

<script>
function printWithFormat(fmt) {
    // Eliminar @page previo si existe
    const old = document.getElementById('_pgStyle');
    if (old) old.remove();
    document.body.classList.remove('fmt-58', 'fmt-80', 'fmt-a4');
    document.body.style.width = '';

    const s = document.createElement('style');
    s.id = '_pgStyle';

    if (fmt === '58mm') {
        document.body.classList.add('fmt-58');
        document.body.style.width = '200px';
        // 58mm - 4mm de márgenes = 54mm de área útil
        s.textContent = '@page { size: 58mm auto; margin: 1mm 2mm; }';
    } else if (fmt === 'a4') {
        document.body.classList.add('fmt-a4');
        // Márgenes explícitos: evita que el navegador recorte el último carácter
        s.textContent = '@page { size: A4 portrait; margin: 15mm 20mm; }';
    } else {
        // 80mm (predeterminado)
        document.body.classList.add('fmt-80');
        document.body.style.width = '300px';
        // 80mm - 4mm de márgenes = 76mm de área útil
        s.textContent = '@page { size: 80mm auto; margin: 1mm 2mm; }';
    }

    document.head.appendChild(s);
    window.print();
}

// ===== WHATSAPP MODAL =====
function openWhatsAppModal(idVenta) {
    const modal = document.createElement('div');
    modal.id = 'whatsappModal';
    modal.style.cssText = `
        position: fixed; top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,0.6); display: flex; align-items: center;
        justify-content: center; z-index: 99999; padding: 20px;
    `;

    const content = document.createElement('div');
    content.style.cssText = `
        background: white; border-radius: 12px; padding: 25px;
        max-width: 450px; width: 100%; box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        max-height: 90vh; overflow-y: auto;
    `;

    content.innerHTML = `
        <h4 style="margin-top: 0; color: #333;">
            <i class="fab fa-whatsapp" style="color: #25d366; margin-right: 10px;"></i>
            Enviar por WhatsApp
        </h4>

        <div style="margin-bottom: 20px;">
            <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #333;">
                Tipo de Documento:
            </label>
            <select id="tipoDocumento" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px;">
                <option value="comprobante">📄 Comprobante Premium</option>
                <option value="ticket">🎫 Ticket de Venta</option>
                <option value="factura">📋 Factura</option>
            </select>
        </div>

        <div style="margin-bottom: 20px;">
            <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #333;">
                Enviar a:
            </label>
            <select id="contactoWhatsApp" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px;">
                <option value="">Cargando contactos...</option>
            </select>
            <small style="color: #666; margin-top: 5px; display: block;">Se cargarán los contactos guardados en el cliente</small>
        </div>

        <div style="margin-bottom: 20px;">
            <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #333;">
                Mensaje (Opcional):
            </label>
            <textarea id="mensajeWhatsApp" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-family: inherit; font-size: 14px; resize: vertical; min-height: 80px;" placeholder="Ej: Aquí está tu comprobante. Gracias por tu compra."></textarea>
        </div>

        <div style="display: flex; gap: 10px;">
            <button onclick="enviarPorWhatsApp(${idVenta})" style="flex: 1; padding: 12px; background: #25d366; color: white; border: none; border-radius: 5px; font-weight: 600; cursor: pointer; font-size: 14px;">
                ✓ Enviar
            </button>
            <button onclick="cerrarWhatsAppModal()" style="flex: 1; padding: 12px; background: #e9ecef; color: #333; border: none; border-radius: 5px; font-weight: 600; cursor: pointer; font-size: 14px;">
                Cancelar
            </button>
        </div>

        <div id="whatsappStatus" style="margin-top: 15px; padding: 12px; border-radius: 5px; display: none; text-align: center; font-size: 14px;"></div>
    `;

    modal.appendChild(content);
    document.body.appendChild(modal);

    // Cargar contactos
    cargarContactosWhatsApp();
}

function cerrarWhatsAppModal() {
    const modal = document.getElementById('whatsappModal');
    if (modal) modal.remove();
}

function cargarContactosWhatsApp() {
    fetch('ticket_whatsapp_send.php?action=get_contacts')
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                const select = document.getElementById('contactoWhatsApp');
                select.innerHTML = '<option value="">Selecciona un contacto...</option>';

                (data.contactos || []).forEach(c => {
                    const option = document.createElement('option');
                    option.value = c.whatsapp;
                    option.textContent = `${c.nombre} (${c.whatsapp})`;
                    select.appendChild(option);
                });
            }
        })
        .catch(e => {
            const select = document.getElementById('contactoWhatsApp');
            select.innerHTML = '<option value="">Error cargando contactos</option>';
            console.error('Error:', e);
        });
}

function enviarPorWhatsApp(idVenta) {
    const tipoDoc = document.getElementById('tipoDocumento').value;
    const whatsapp = document.getElementById('contactoWhatsApp').value;
    const mensaje = document.getElementById('mensajeWhatsApp').value;
    const status = document.getElementById('whatsappStatus');

    if (!whatsapp) {
        status.style.display = 'block';
        status.style.background = '#fee';
        status.style.color = '#c33';
        status.textContent = '❌ Selecciona un contacto';
        return;
    }

    status.style.display = 'block';
    status.style.background = '#e3f2fd';
    status.style.color = '#1976d2';
    status.textContent = '⏳ Enviando...';

    const formData = new FormData();
    formData.append('id_venta', idVenta);
    formData.append('tipo_doc', tipoDoc);
    formData.append('whatsapp', whatsapp);
    formData.append('mensaje', mensaje);

    fetch('ticket_whatsapp_send.php?action=send', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.status === 'success') {
            status.style.background = '#e8f5e9';
            status.style.color = '#2e7d32';
            status.textContent = '✓ ' + data.msg;
            setTimeout(() => cerrarWhatsAppModal(), 2000);
        } else {
            status.style.background = '#fee';
            status.style.color = '#c33';
            status.textContent = '❌ Error: ' + data.msg;
        }
    })
    .catch(e => {
        status.style.background = '#fee';
        status.style.color = '#c33';
        status.textContent = '❌ Error: ' + e.message;
        console.error('Error:', e);
    });
}
</script>
</body>
</html>
