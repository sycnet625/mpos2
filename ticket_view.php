<?php
// ARCHIVO: /var/www/palweb/api/ticket_view.php
header('Content-Type: text/html; charset=utf-8');
ini_set('display_errors', 0);
require_once 'db.php';

require_once 'config_loader.php';

$idVenta = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($idVenta <= 0) die("ID inválido.");
$priceView = strtolower(trim((string)($_GET['price_view'] ?? 'venta')));
if (!in_array($priceView, ['venta', 'mayorista'], true)) {
    $priceView = 'venta';
}
$markupPct = isset($_GET['markup_pct']) ? round(floatval($_GET['markup_pct']), 2) : 0.0;
if ($markupPct < -99.99) $markupPct = -99.99;
$markupFactor = 1 + ($markupPct / 100);
$autoPrint = isset($_GET['autoprint']) && $_GET['autoprint'] === '1';
$printFormat = strtolower(trim((string)($_GET['format'] ?? '80mm')));
if (!in_array($printFormat, ['58mm', '80mm', 'a4'], true)) {
    $printFormat = '80mm';
}

try {
    $stmtHead = $pdo->prepare("SELECT * FROM ventas_cabecera WHERE id = ?");
    $stmtHead->execute([$idVenta]);
    $venta = $stmtHead->fetch(PDO::FETCH_ASSOC);
    if (!$venta) die("No existe.");

    // Obtener banner dinámico de la sucursal de la venta
    $branchBanner = '';
    if (!empty($venta['id_sucursal'])) {
        $stmtS = $pdo->prepare("SELECT imagen_banner FROM sucursales WHERE id = ? LIMIT 1");
        $stmtS->execute([$venta['id_sucursal']]);
        $branchBanner = $stmtS->fetchColumn();
    }

    $sqlDet = "SELECT d.cantidad, d.precio, d.id_producto,
                      COALESCE(p.nombre, CONCAT('Art: ', d.id_producto)) AS nombre_producto,
                      COALESCE(ps.precio_mayorista, p.precio_mayorista, d.precio) AS precio_mayorista_visual
               FROM ventas_detalle d
               LEFT JOIN productos p ON d.id_producto = p.codigo
               LEFT JOIN productos_precios_sucursal ps
                      ON ps.codigo_producto = d.id_producto AND ps.id_sucursal = ?
               WHERE d.id_venta_cabecera = ?";
    $stmtDet = $pdo->prepare($sqlDet); $stmtDet->execute([(int)($venta['id_sucursal'] ?? 0), $idVenta]);
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

$subtotalItemsOriginal = $subtotalItems;
$subtotalItemsDisplay = 0.0;
foreach ($items as &$item) {
    $precioOriginal = floatval($item['precio']);
    $precioMayorista = floatval($item['precio_mayorista_visual'] ?? $precioOriginal);
    if ($priceView === 'mayorista') {
        $precioDisplay = round($precioMayorista, 2);
    } else {
        $precioDisplay = round($precioOriginal * $markupFactor, 2);
    }
    $item['precio_original'] = $precioOriginal;
    $item['precio_mayorista'] = $precioMayorista;
    $item['precio_display'] = $precioDisplay;
    $item['subtotal_display'] = round(floatval($item['cantidad']) * $precioDisplay, 2);
    $subtotalItemsDisplay += $item['subtotal_display'];
}
unset($item);

$totalDisplay = round($subtotalItemsDisplay + $costoEnvio, 2);
$abonoDisplay = floatval($venta['abono'] ?? 0);
$pendienteDisplay = round($totalDisplay - $abonoDisplay, 2);

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
$pricePreset = 'normal';
if ($priceView === 'mayorista') {
    $pricePreset = 'mayorista';
} elseif (abs($markupPct + 10) < 0.001) {
    $pricePreset = 'minus10';
} elseif (abs($markupPct - 10) < 0.001) {
    $pricePreset = 'plus10';
} elseif (abs($markupPct - 20) < 0.001) {
    $pricePreset = 'plus20';
} elseif (abs($markupPct - 50) < 0.001) {
    $pricePreset = 'plus50';
}
$priceNoticeTitle = '';
$priceNoticeBody = '';
if ($priceView === 'mayorista') {
    $priceNoticeTitle = 'IMPRESION ESPECIAL: PRECIO MAYORISTA';
    $priceNoticeBody = 'Solo visual. La venta y la contabilidad conservan el precio original del POS.';
} elseif (abs($markupPct) > 0.001) {
    $sign = $markupPct > 0 ? '+' : '';
    $priceNoticeTitle = 'IMPRESION ESPECIAL: PRECIOS CON ' . $sign . number_format($markupPct, 0) . '%';
    $priceNoticeBody = 'Solo visual. La venta y la contabilidad conservan el precio original del POS.';
}
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
        .toolbar-shell { padding: 8px 10px; background:#f8f9fa; text-align:center; border-bottom:1px solid #e5e7eb; }
        .toolbar-grid { display:flex; gap:8px; justify-content:center; flex-wrap:wrap; align-items:end; }
        .toolbar-group { display:flex; flex-direction:column; gap:4px; min-width: 150px; text-align:left; }
        .toolbar-group label { font-size:10px; color:#6b7280; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
        .toolbar-group select, .toolbar-actions button {
            padding:7px 10px; font-size:11px; border-radius:6px; border:1px solid #cbd5e1; background:#fff; font-family:monospace;
        }
        .toolbar-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; justify-content:center; }
        .btn-print-main { background:#0d6efd !important; color:#fff; border-color:#0d6efd !important; font-weight:700; }
        .btn-wa { background:#25d366 !important; color:#fff; border-color:#25d366 !important; font-weight:700; }
        .btn-close-mini { background:#fff !important; color:#333; }
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
        <div style="font-size:10px; color:#888; font-weight:700; text-transform:uppercase; letter-spacing:.05em; margin-bottom:8px;">Opciones de impresión</div>
        <div class="toolbar-grid">
            <div class="toolbar-group">
                <label for="printFormatSelect">Tipo de impresora</label>
                <select id="printFormatSelect">
                    <option value="58mm" <?php echo $printFormat === '58mm' ? 'selected' : ''; ?>>Térmica 58mm</option>
                    <option value="80mm" <?php echo $printFormat === '80mm' ? 'selected' : ''; ?>>Térmica 80mm</option>
                    <option value="a4" <?php echo $printFormat === 'a4' ? 'selected' : ''; ?>>A4 Deskjet</option>
                </select>
            </div>
            <div class="toolbar-group">
                <label for="printDocumentSelect">Documento</label>
                <select id="printDocumentSelect">
                    <option value="ticket" selected>Ticket</option>
                    <option value="factura">Factura</option>
                    <option value="comprobante">Comprobante Premium</option>
                </select>
            </div>
            <div class="toolbar-group">
                <label for="printPriceSelect">Precio visual</label>
                <select id="printPriceSelect">
                    <option value="normal" <?php echo $pricePreset === 'normal' ? 'selected' : ''; ?>>Precio normal</option>
                    <option value="minus10" <?php echo $pricePreset === 'minus10' ? 'selected' : ''; ?>>Bajar 10%</option>
                    <option value="plus10" <?php echo $pricePreset === 'plus10' ? 'selected' : ''; ?>>Subir 10%</option>
                    <option value="plus20" <?php echo $pricePreset === 'plus20' ? 'selected' : ''; ?>>Subir 20%</option>
                    <option value="plus50" <?php echo $pricePreset === 'plus50' ? 'selected' : ''; ?>>Subir 50%</option>
                    <option value="mayorista" <?php echo $pricePreset === 'mayorista' ? 'selected' : ''; ?>>Precio mayorista</option>
                </select>
            </div>
            <div class="toolbar-actions">
                <button class="btn-print-main" onclick="printSelectedDocument()">🖨️ Imprimir</button>
                <button class="btn-wa" onclick="openWhatsAppModal(<?= $idVenta ?>)">💬 Enviar por WhatsApp</button>
                <button class="btn-close-mini" onclick="window.close()">✕ Cerrar</button>
            </div>
        </div>
    </div>

    <div class="text-center">
        <?php
        // Prioridad: 1. Banner de sucursal, 2. Marca empresa, 3. Logo ticket
        $tLogoPath = $branchBanner ?: ($config['marca_empresa_logo'] ?? $config['ticket_logo'] ?? '');
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
        <?php if ($priceNoticeTitle !== ''): ?>
        <div style="margin-top:6px; padding:6px 8px; background:#fff3cd; border:1px dashed #856404; font-size:11px; font-weight:bold;">
            <?php echo htmlspecialchars($priceNoticeTitle); ?><br>
            <span style="font-weight:normal;"><?php echo htmlspecialchars($priceNoticeBody); ?></span>
        </div>
        <?php endif; ?>
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
                $sub = $item['subtotal_display'];
                $itemCount++;
            ?>
            <tr>
                <td><?php echo number_format($item['cantidad'], 2); ?></td>
                <td><?php echo htmlspecialchars($item['nombre_producto']); ?></td>
                <td align="right">$<?php echo number_format($item['precio_display'], 2); ?></td>
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
                    <tr><td>Subtotal productos:</td><td class="text-right">$<?php echo number_format($subtotalItemsDisplay, 2); ?></td></tr>
                    <tr><td>Costo mensajería:</td><td class="text-right fw-bold">$<?php echo number_format($costoEnvio, 2); ?></td></tr>
                    <tr style="border-top: 1px dashed #000;"><td colspan="2"></td></tr>
                <?php endif; ?>
                <tr><td><?php echo $hayEnvio ? 'Total pedido:' : 'Subtotal:'; ?></td><td class="text-right">$<?php echo number_format($totalDisplay, 2); ?></td></tr>
                <tr><td>Abono Recibido:</td><td class="text-right fw-bold" style="color: #28a745;">-$<?php echo number_format($abonoDisplay, 2); ?></td></tr>
                <tr style="border-top: 2px solid #000; padding-top: 5px;">
                    <td class="fw-bold" style="font-size:14px;">PENDIENTE:</td>
                    <td class="text-right fw-bold" style="font-size:18px; color: #dc3545;">
                        $<?php echo number_format($pendienteDisplay, 2); ?>
                    </td>
                </tr>
            <?php else: ?>
                <?php if ($hayEnvio): ?>
                    <tr>
                        <td>Subtotal productos:</td>
                        <td class="text-right">$<?php echo number_format($subtotalItemsDisplay, 2); ?></td>
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
                        $<?php echo number_format($totalDisplay, 2); ?>
                    </td>
                </tr>
                <?php if ($priceNoticeTitle !== ''): ?>
                    <tr><td colspan="2" class="text-center" style="font-size:10px; padding-top: 4px; border-top: 1px dashed #000;">
                        Total original POS: <strong>$<?php echo number_format($venta['total'], 2); ?></strong>
                    </td></tr>
                <?php endif; ?>
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
                $qrBase = rtrim(trim((string)($config['ticket_qr_url_base'] ?? '')), '/');
                if ($qrBase === '') {
                    $scheme   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $qrBase   = $scheme . '://' . $_SERVER['HTTP_HOST'];
                }
                $scriptPath = strtok($_SERVER['REQUEST_URI'] ?? '/ticket_view.php', '?');
                echo $qrBase . $scriptPath . '?id=' . $idVenta . '&source=qr';
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

function printWithMarkup(pct) {
    const url = new URL(window.location.href);
    url.searchParams.set('markup_pct', String(pct));
    url.searchParams.set('autoprint', '1');
    window.open(url.toString(), '_blank', 'width=420,height=800');
}

function applyPriceSelectionToUrl(url) {
    const sel = document.getElementById('printPriceSelect');
    const value = sel ? sel.value : 'normal';
    url.searchParams.delete('markup_pct');
    url.searchParams.delete('price_view');
    if (value === 'minus10') {
        url.searchParams.set('markup_pct', '-10');
    } else if (value === 'plus10') {
        url.searchParams.set('markup_pct', '10');
    } else if (value === 'plus20') {
        url.searchParams.set('markup_pct', '20');
    } else if (value === 'plus50') {
        url.searchParams.set('markup_pct', '50');
    } else if (value === 'mayorista') {
        url.searchParams.set('price_view', 'mayorista');
    }
    return url;
}

function printSelectedDocument() {
    const formatSel = document.getElementById('printFormatSelect');
    const docSel = document.getElementById('printDocumentSelect');
    const format = formatSel ? formatSel.value : '80mm';
    const doc = docSel ? docSel.value : 'ticket';

    if (doc === 'ticket') {
        const url = applyPriceSelectionToUrl(new URL(window.location.href));
        url.searchParams.set('autoprint', '1');
        url.searchParams.set('format', format);
        window.open(url.toString(), '_blank', 'width=420,height=800');
        return;
    }

    if (doc === 'factura') {
        const url = new URL('ticket_to_invoice.php', window.location.href);
        url.searchParams.set('id', '<?php echo $idVenta; ?>');
        url.searchParams.set('autoprint', '1');
        url.searchParams.set('format', format);
        applyPriceSelectionToUrl(url);
        window.open(url.toString(), '_blank', 'width=960,height=900');
        return;
    }

    const url = new URL('comprobante_ventas.php', window.location.href);
    url.searchParams.set('id', '<?php echo $idVenta; ?>');
    url.searchParams.set('autoprint', '1');
    url.searchParams.set('format', format);
    applyPriceSelectionToUrl(url);
    window.open(url.toString(), '_blank', 'width=960,height=900');
}

<?php if ($autoPrint): ?>
window.addEventListener('load', function () {
    setTimeout(function () {
        const fmt = new URL(window.location.href).searchParams.get('format') || '80mm';
        printWithFormat(fmt);
    }, 250);
});
<?php endif; ?>

// ===== WHATSAPP MODAL =====
function openWhatsAppModal(idVenta) {
    const currentDoc = (document.getElementById('printDocumentSelect') || {}).value || 'ticket';
    const currentPrice = (document.getElementById('printPriceSelect') || {}).value || 'normal';
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
                Precio visual:
            </label>
            <input id="precioVisualWhatsApp" type="text" readonly value="" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px; background: #f8f9fa; color: #495057;">
            <small style="color: #666; margin-top: 5px; display: block;">Se enviará con el mismo ajuste visual seleccionado arriba</small>
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

    const tipoDocumento = document.getElementById('tipoDocumento');
    if (tipoDocumento) {
        tipoDocumento.value = currentDoc;
    }

    const precioVisual = document.getElementById('precioVisualWhatsApp');
    if (precioVisual) {
        precioVisual.value = getPriceSelectionLabel(currentPrice);
    }

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
    const priceSelection = (document.getElementById('printPriceSelect') || {}).value || 'normal';

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
    if (priceSelection === 'mayorista') {
        formData.append('price_view', 'mayorista');
    } else {
        formData.append('markup_pct', String(mapPriceSelectionToMarkup(priceSelection)));
    }

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

function mapPriceSelectionToMarkup(selection) {
    switch (selection) {
        case 'minus10':
            return -10;
        case 'plus10':
            return 10;
        case 'plus20':
            return 20;
        case 'plus50':
            return 50;
        default:
            return 0;
    }
}

function getPriceSelectionLabel(selection) {
    switch (selection) {
        case 'minus10':
            return 'Bajar 10%';
        case 'plus10':
            return 'Subir 10%';
        case 'plus20':
            return 'Subir 20%';
        case 'plus50':
            return 'Subir 50%';
        case 'mayorista':
            return 'Precio mayorista';
        default:
            return 'Precio normal';
    }
}
</script>
</body>
</html>
