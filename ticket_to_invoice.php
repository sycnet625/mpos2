<?php
// ticket_to_invoice.php
// Renderiza un ticket de venta con el formato de factura (invoice_print.php).
// Solo lectura / impresión — no guarda en la tabla facturas.
header('Content-Type: text/html; charset=utf-8');
ini_set('display_errors', 0);
require_once 'db.php';
require_once 'config_loader.php';

$idVenta = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($idVenta <= 0) die('ID de venta inválido.');

// ── Cabecera de venta ─────────────────────────────────────────────────────────
$stmtH = $pdo->prepare("SELECT * FROM ventas_cabecera WHERE id = ?");
$stmtH->execute([$idVenta]);
$venta = $stmtH->fetch(PDO::FETCH_ASSOC);
if (!$venta) die('Venta no encontrada.');

// ── Ítems ─────────────────────────────────────────────────────────────────────
$stmtD = $pdo->prepare("
    SELECT d.cantidad, d.precio,
           COALESCE(p.nombre, CONCAT('Artículo: ', d.id_producto)) AS descripcion,
           COALESCE(p.unidad_medida, 'UND') AS um
    FROM ventas_detalle d
    LEFT JOIN productos p ON d.id_producto = p.codigo
    WHERE d.id_venta_cabecera = ?
    ORDER BY d.id
");
$stmtD->execute([$idVenta]);
$items = $stmtD->fetchAll(PDO::FETCH_ASSOC);

// ── Cajero ────────────────────────────────────────────────────────────────────
$cajero = 'Administrador';
if (!empty($venta['id_caja'])) {
    $s = $pdo->prepare("SELECT nombre_cajero FROM caja_sesiones WHERE id = ?");
    $s->execute([$venta['id_caja']]);
    $cajero = $s->fetchColumn() ?: 'Administrador';
}

// ── Cálculos ──────────────────────────────────────────────────────────────────
$subtotal   = array_sum(array_map(fn($i) => $i['cantidad'] * $i['precio'], $items));
$total      = floatval($venta['total']);
$costoEnvio = round($total - $subtotal, 2);
$tiposConEnvio = ['mensajeria', 'domicilio', 'delivery'];
$hayEnvio   = $costoEnvio > 0.01 && in_array(strtolower($venta['tipo_servicio'] ?? ''), $tiposConEnvio);

// Número de factura: fecha del ticket + ID de venta
$numFactura = date('Ymd', strtotime($venta['fecha'])) . str_pad($idVenta, 3, '0', STR_PAD_LEFT);

// Filas vacías para mantener la cuadrícula de 13 líneas
$totalRows  = 13;
$emptyRows  = max(0, $totalRows - count($items));

// Términos de pago
$terminos = (($venta['estado_pago'] ?? '') === 'confirmado')
    ? 'PAGADO (' . htmlspecialchars($venta['metodo_pago'] ?? '') . ')'
    : 'Pagadero al recibirse';

// Datos de empresa desde config (con fallbacks)
$empresa   = $config['tienda_nombre']    ?? 'Empresa';
$direccion = $config['direccion']         ?? '';
$telefono  = $config['telefono']          ?? '';
$nit       = $config['nit']              ?? '';
$web       = $config['website']          ?? '';
$cuenta    = $config['cuenta_bancaria']  ?? '';
$banco     = $config['banco']            ?? '';
$email     = $config['email']            ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<title>Factura – Ticket #<?= $idVenta ?></title>
<style>
    body {
        font-family: "Calibri", Arial, sans-serif;
        font-size: 11px;
        margin: 0; padding: 0;
        background-color: #525659;
        color: #000;
    }

    /* Barra de herramientas (solo pantalla) */
    .toolbar {
        position: fixed; top: 0; left: 0; right: 0;
        background: #1e293b; color: #fff;
        padding: 8px 20px; display: flex; align-items: center; gap: 10px;
        z-index: 1000; font-size: 12px;
    }
    .toolbar button {
        padding: 7px 16px; border: none; border-radius: 5px;
        cursor: pointer; font-weight: 700; font-size: 12px;
    }
    .btn-p { background: #2F75B5; color: #fff; }
    .btn-p:hover { background: #1e5687; }
    .btn-c { background: #475569; color: #fff; }

    /* Hoja carta */
    .page-container {
        width: 21.59cm; min-height: 27.94cm;
        background: white;
        margin: 56px auto 30px;
        padding: 1.5cm;
        box-shadow: 0 0 15px rgba(0,0,0,0.5);
        box-sizing: border-box;
        position: relative;
    }

    /* Elementos de factura — idénticos a invoice_print.php */
    .header-table  { width: 100%; margin-bottom: 20px; }
    .company-name  { font-size: 20px; font-weight: bold; color: #2F75B5; text-transform: uppercase; }
    .invoice-title { font-size: 28px; font-weight: bold; color: #2F75B5; text-align: right; }
    .blue-header   { background-color: #2F75B5; color: white; font-weight: bold; text-align: center; padding: 5px; text-transform: uppercase; font-size: 12px; }
    .info-cell     { text-align: center; font-weight: bold; padding: 5px; border-bottom: 1px solid #ccc; }
    .main-table    { width: 100%; border-collapse: collapse; margin-top: 10px; }
    .main-table th { background-color: #2F75B5; color: white; font-weight: bold; padding: 4px; border: 1px solid #aaa; text-align: center; }
    .main-table td { border: 1px solid #aaa; padding: 4px; font-size: 11px; height: 18px; }
    .left   { text-align: left; }
    .right  { text-align: right; }
    .center { text-align: center; }
    .totals-table  { width: 35%; border-collapse: collapse; }
    .totals-table td { padding: 5px; border: none; }
    .total-final   { background-color: #D9E1F2; font-size: 14px; font-weight: bold; border-top: 1px solid #2F75B5; }
    .signature-line { margin-top: 40px; border-top: 2px solid #2F75B5; width: 100%; padding-top: 5px; color: white; background-color: #2F75B5; text-align: center; font-weight: bold; }
    .origen-badge { display: inline-block; background: #f0f4ff; border: 1px solid #c7d2fe; color: #3730a3; padding: 2px 8px; border-radius: 4px; font-size: 10px; }

    @media print {
        .toolbar { display: none !important; }
        body { background: white; margin: 0; }
        .page-container { width: 100%; margin: 0; padding: 0; box-shadow: none; border: none; min-height: auto; }
        @page { size: letter; margin: 1cm; }
        .blue-header, .main-table th, .signature-line, .total-final {
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
    }
</style>
</head>
<body>

<div class="toolbar">
    <span style="opacity:.7;">Ticket #<?= $idVenta ?> → Vista Factura</span>
    <button class="btn-p" onclick="window.print()">🖨️ IMPRIMIR / PDF</button>
    <button class="btn-c" onclick="window.close()">✕ Cerrar</button>
</div>

<div class="page-container">

    <!-- ── Encabezado ─────────────────────────────────────────────────────── -->
    <table class="header-table">
        <tbody>
            <tr>
                <td width="60%" valign="top">
                    <div class="company-name"><?= htmlspecialchars($empresa) ?></div>
                    <?php if ($direccion): ?>
                    <div><?= htmlspecialchars($direccion) ?></div>
                    <?php endif; ?>
                    <?php if ($telefono): ?>
                    <div>Teléfono: <?= htmlspecialchars($telefono) ?></div>
                    <?php endif; ?>
                    <?php if ($web): ?>
                    <div><a href="<?= htmlspecialchars($web) ?>" style="color:blue;"><?= htmlspecialchars($web) ?></a></div>
                    <?php endif; ?>
                    <?php if ($nit): ?>
                    <div>NIT: <?= htmlspecialchars($nit) ?></div>
                    <?php endif; ?>
                </td>
                <td width="40%" valign="top" align="right">
                    <div class="invoice-title">FACTURA</div>
                    <div style="font-size:10px; color:#666; text-align:right;">
                        Basada en Ticket #<?= str_pad($idVenta, 6, '0', STR_PAD_LEFT) ?>
                        &nbsp;<span class="origen-badge"><?= htmlspecialchars($venta['canal_origen'] ?? 'POS') ?></span>
                    </div>
                    <table width="100%" cellspacing="0" style="margin-top:10px;">
                        <tbody>
                            <tr>
                                <td class="blue-header">FACTURA #</td>
                                <td class="blue-header">FECHA</td>
                            </tr>
                            <tr>
                                <td class="info-cell"><?= htmlspecialchars($numFactura) ?></td>
                                <td class="info-cell"><?= date('d/m/Y', strtotime($venta['fecha'])) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </td>
            </tr>
        </tbody>
    </table>

    <!-- ── Cliente + Transportista ───────────────────────────────────────── -->
    <table width="100%" cellspacing="0" style="margin-bottom:20px;">
        <tbody>
            <tr>
                <td width="45%" valign="top">
                    <div class="blue-header" style="text-align:left; padding-left:10px;">FACTURAR A</div>
                    <div style="padding:5px; font-weight:bold;">
                        <?= htmlspecialchars($venta['cliente_nombre'] ?: 'Mostrador') ?>
                        <?php if (!empty($venta['cliente_telefono'])): ?>
                        <br><span style="font-weight:normal; font-size:10px;">
                            Tel: <?= htmlspecialchars($venta['cliente_telefono']) ?>
                        </span>
                        <?php endif; ?>
                        <?php if (!empty($venta['cliente_direccion'])): ?>
                        <br><span style="font-weight:normal; font-size:10px;">
                            <?= htmlspecialchars($venta['cliente_direccion']) ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </td>
                <td width="5%"></td>
                <td width="50%" valign="top">
                    <table width="100%" cellspacing="0">
                        <tbody>
                            <tr>
                                <td class="blue-header">TRANSPORTADO POR</td>
                                <td class="blue-header">TÉRMINOS</td>
                            </tr>
                            <tr>
                                <td class="info-cell" style="text-align:left;">
                                    <b><?= htmlspecialchars($venta['mensajero_nombre'] ?? '') ?></b>
                                </td>
                                <td class="info-cell"><?= $terminos ?></td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="signature-line" style="margin-top:10px;">FIRMA DE CONFORMIDAD DEL CLIENTE</div>
                </td>
            </tr>
        </tbody>
    </table>

    <!-- ── Tabla de ítems ─────────────────────────────────────────────────── -->
    <table class="main-table">
        <thead>
            <tr>
                <th class="left">DESCRIPCIÓN</th>
                <th width="5%">PV</th>
                <th width="5%">UM</th>
                <th width="8%">CANT</th>
                <th width="15%">PRECIO UNITARIO</th>
                <th width="15%">IMPORTE</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $it):
                $importe = $it['cantidad'] * $it['precio'];
            ?>
            <tr>
                <td class="left"><?= htmlspecialchars($it['descripcion']) ?></td>
                <td class="center"></td>
                <td class="center"><?= htmlspecialchars($it['um']) ?></td>
                <td class="center"><?= rtrim(rtrim(number_format($it['cantidad'], 2), '0'), '.') ?></td>
                <td class="right"><?= number_format($it['precio'], 2) ?></td>
                <td class="right"><?= number_format($importe, 2) ?></td>
            </tr>
            <?php endforeach; ?>

            <?php for ($k = 0; $k < $emptyRows; $k++): ?>
            <tr><td>&nbsp;</td><td></td><td class="center">U</td><td class="center">0</td><td class="center">-</td><td class="center">-</td></tr>
            <?php endfor; ?>

            <tr>
                <td colspan="3" class="right" style="border:none; font-weight:bold;">TOTAL&gt;&gt;</td>
                <td class="center" style="font-weight:bold;"><?= count($items) ?></td>
                <td colspan="2" style="border:none;"></td>
            </tr>
        </tbody>
    </table>

    <!-- ── Totales + Datos bancarios ─────────────────────────────────────── -->
    <table width="100%" style="margin-top:10px;">
        <tbody>
            <tr>
                <td width="60%" valign="top" style="font-size:12px;">
                    <div style="font-style:italic; color:#2F75B5; font-size:14px; font-weight:bold; margin-bottom:5px;">¡Gracias por su compra!</div>
                    <?php if ($cuenta): ?>
                    <div>Por transferencia bancaria a la cuenta MN:</div>
                    <div style="font-size:16px; font-weight:bold;"><?= htmlspecialchars($cuenta) ?></div>
                    <?php if ($banco): ?>
                    <div style="font-size:11px;"><?= htmlspecialchars($banco) ?></div>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php if (!empty($venta['notas'])): ?>
                    <div style="margin-top:8px; font-size:10px; color:#444;"><b>Notas:</b> <?= htmlspecialchars($venta['notas']) ?></div>
                    <?php endif; ?>
                </td>
                <td width="40%" valign="top">
                    <table class="totals-table" align="right" width="100%">
                        <tbody>
                            <tr style="background-color:#D9E1F2;">
                                <td class="left">SUBTOTAL</td>
                                <td class="right">$<?= number_format($subtotal, 2) ?></td>
                            </tr>
                            <?php if ($hayEnvio): ?>
                            <tr style="background-color:#D9E1F2;">
                                <td class="left">Costo de envío</td>
                                <td class="right">$<?= number_format($costoEnvio, 2) ?></td>
                            </tr>
                            <?php else: ?>
                            <tr style="background-color:#D9E1F2;">
                                <td class="left">Manipulación y envío</td>
                                <td class="right">-</td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <td class="left total-final" style="color:#2F75B5;">TOTAL</td>
                                <td class="right total-final">$<?= number_format($total, 2) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </td>
            </tr>
        </tbody>
    </table>

    <!-- ── Pie ────────────────────────────────────────────────────────────── -->
    <div style="margin-top:30px; border-top:1px solid #ccc; padding-top:5px; font-size:10px;">
        Emitido por: <i><?= htmlspecialchars($cajero) ?></i>
        <?php if ($email): ?> · Contacto: <b><?= htmlspecialchars($email) ?></b><?php endif; ?>
    </div>
    <div style="margin-top:20px; font-size:10px; color:#999; text-align:center;">
        Generado por PalWeb POS v3.0 · Documento no válido como factura oficial
    </div>

</div>
</body>
</html>
