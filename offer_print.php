<?php
// ARCHIVO: offer_print.php
// DESCRIPCIÓN: Visualizador de Ofertas Comerciales
require_once 'db.php';
require_once 'config_loader.php';
session_start();

if (!isset($_GET['id'])) { die("ID no especificado."); }
$id = intval($_GET['id']);

$stmt = $pdo->prepare("SELECT * FROM ofertas WHERE id = ?");
$stmt->execute([$id]);
$oferta = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$oferta) { die("Oferta no encontrada."); }

$stmtDet = $pdo->prepare("SELECT * FROM ofertas_detalle WHERE id_oferta = ?");
$stmtDet->execute([$id]);
$items = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

$totalRows = 13;
$usedRows  = count($items);
$emptyRows = max(0, $totalRows - $usedRows);

// Buscar NIT/Email del cliente: primero por id_cliente ligado, luego por teléfono
$clienteNit = ''; $clienteEmail = '';
if (!empty($oferta['id_cliente'])) {
    $sc = $pdo->prepare("SELECT nit_ci, email FROM clientes WHERE id = ? LIMIT 1");
    $sc->execute([$oferta['id_cliente']]);
    $cd = $sc->fetch(PDO::FETCH_ASSOC);
} elseif (!empty($oferta['cliente_telefono'])) {
    $sc = $pdo->prepare("SELECT nit_ci, email FROM clientes WHERE telefono = ? OR telefono_principal = ? LIMIT 1");
    $sc->execute([$oferta['cliente_telefono'], $oferta['cliente_telefono']]);
    $cd = $sc->fetch(PDO::FETCH_ASSOC);
}
if (!empty($cd)) { $clienteNit = $cd['nit_ci'] ?? ''; $clienteEmail = $cd['email'] ?? ''; }

// Datos de empresa desde config
$empNombre = $config['tienda_nombre']    ?? $config['marca_empresa_nombre'] ?? 'Empresa';
$empDir    = $config['direccion']         ?? '';
$empTel    = $config['telefono']          ?? '';
$empNIT    = $config['nit']              ?? '';
$empWeb    = $config['website']          ?? '';
$empEmail  = $config['email']            ?? '';
$ofertaVigencia = (int)($config['oferta_vigencia_dias'] ?? 15);
?>
<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <title>Oferta <?php echo $oferta['numero_oferta']; ?></title>
    <style>
        body { font-family: "Calibri", Arial, sans-serif; font-size: 11px; margin: 0; padding: 0; background-color: #525659; color: #000; }
        .page-container { width: 21.59cm; min-height: 27.94cm; background-color: white; margin: 30px auto; padding: 1.5cm; box-shadow: 0 0 15px rgba(0,0,0,0.5); box-sizing: border-box; position: relative; }
        .header-table { width: 100%; margin-bottom: 20px; }
        .company-name { font-size: 20px; font-weight: bold; color: #e67e22; text-transform: uppercase; }
        .invoice-title { font-size: 28px; font-weight: bold; color: #e67e22; text-align: right; }
        .blue-header { background-color: #e67e22; color: white; font-weight: bold; text-align: center; padding: 5px; text-transform: uppercase; font-size: 12px; }
        .info-cell { text-align: center; font-weight: bold; padding: 5px; border-bottom: 1px solid #ccc; }
        .main-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .main-table th { background-color: #e67e22; color: white; font-weight: bold; padding: 4px; border: 1px solid #aaa; text-align: center; }
        .main-table td { border: 1px solid #aaa; padding: 4px; font-size: 11px; height: 18px; }
        .left { text-align: left; } .right { text-align: right; } .center { text-align: center; }
        .totals-table { width: 35%; border-collapse: collapse; }
        .totals-table td { padding: 5px; border: none; }
        .total-final { background-color: #fdebd0; font-size: 14px; font-weight: bold; border-top: 1px solid #e67e22; }
        .signature-line { margin-top: 40px; border-top: 2px solid #e67e22; width: 100%; padding-top: 5px; color: white; background-color: #e67e22; text-align: center; font-weight: bold; }
        .btn-print { position: fixed; top: 20px; right: 20px; padding: 10px 20px; background: #e67e22; color: white; border: none; cursor: pointer; font-weight: bold; box-shadow: 0 2px 5px rgba(0,0,0,0.3); border-radius: 4px; z-index: 1000; }
        @media print { body { background-color: white; margin: 0; } .page-container { width: 100%; margin: 0; padding: 0; box-shadow: none; border: none; min-height: auto; } .no-print { display: none !important; } @page { size: letter; margin: 1cm; } }
    </style>
</head>
<body>
<button onclick="window.print()" class="btn-print no-print">IMPRIMIR / PDF</button>
<div class="page-container">
    <table class="header-table">
        <tbody>
            <tr>
                <td width="60%" valign="top">
                    <div class="company-name"><?= htmlspecialchars($empNombre) ?></div>
                    <?php if($empDir): ?><div><?= htmlspecialchars($empDir) ?></div><?php endif; ?>
                    <?php if($empTel): ?><div>Teléfono: <?= htmlspecialchars($empTel) ?></div><?php endif; ?>
                    <?php if($empWeb): ?><div><a href="<?= htmlspecialchars($empWeb) ?>" style="color:blue"><?= htmlspecialchars($empWeb) ?></a></div><?php endif; ?>
                    <?php if($empNIT): ?><div>NIT: <?= htmlspecialchars($empNIT) ?></div><?php endif; ?>
                </td>
                <td width="40%" valign="top" align="right">
                    <div class="invoice-title">OFERTA COMERCIAL</div>
                    <table width="100%" cellspacing="0" style="margin-top:10px;">
                        <tbody>
                            <tr><td class="blue-header">OFERTA #</td><td class="blue-header">FECHA</td></tr>
                            <tr><td class="info-cell"><?php echo $oferta['numero_oferta']; ?></td><td class="info-cell"><?php echo date('d/m/Y', strtotime($oferta['fecha_emision'])); ?></td></tr>
                        </tbody>
                    </table>
                </td>
            </tr>
        </tbody>
    </table>

    <table width="100%" cellspacing="0" style="margin-bottom:20px;">
        <tbody>
            <tr>
                <td width="45%" valign="top">
                    <div class="blue-header" style="text-align:left; padding-left:10px;">PREPARADO PARA:</div>
                    <div style="padding:5px; line-height:1.6;">
                        <table cellspacing="0" cellpadding="0" style="width:100%; font-size:11px;">
                            <tr>
                                <td style="font-weight:bold; width:70px; color:#e67e22;">Nombre:</td>
                                <td style="font-weight:bold;"><?php echo htmlspecialchars($oferta['cliente_nombre']); ?></td>
                            </tr>
                            <?php if (!empty($oferta['cliente_direccion'])): ?>
                            <tr>
                                <td style="font-weight:bold; color:#e67e22;">Dirección:</td>
                                <td><?php echo htmlspecialchars($oferta['cliente_direccion']); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if (!empty($oferta['cliente_telefono'])): ?>
                            <tr>
                                <td style="font-weight:bold; color:#e67e22;">Teléfono:</td>
                                <td><?php echo htmlspecialchars($oferta['cliente_telefono']); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if (!empty($clienteNit)): ?>
                            <tr>
                                <td style="font-weight:bold; color:#e67e22;">NIT/CI:</td>
                                <td><?php echo htmlspecialchars($clienteNit); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if (!empty($clienteEmail)): ?>
                            <tr>
                                <td style="font-weight:bold; color:#e67e22;">Email:</td>
                                <td><?php echo htmlspecialchars($clienteEmail); ?></td>
                            </tr>
                            <?php endif; ?>
                        </table>
                    </div>
                </td>
                <td width="5%"></td>
                <td width="50%" valign="top">
                    <div style="font-size:12px; font-weight:bold; color:#e67e22;">CONDICIONES GENERALES</div>
                    <div style="font-size:10px;">Esta oferta es válida por <?= $ofertaVigencia ?> días hábiles.<br>Precios sujetos a disponibilidad al momento de la orden.</div>
                    <div class="signature-line" style="margin-top:10px;">FIRMA DE ACEPTACIÓN</div>
                </td>
            </tr>
        </tbody>
    </table>

    <table class="main-table">
        <thead>
            <tr><th class="left">DESCRIPCIÓN</th><th width="5%">UM</th><th width="8%">CANT</th><th width="15%">PRECIO UNITARIO</th><th width="15%">IMPORTE</th></tr>
        </thead>
        <tbody>
            <?php foreach($items as $it): ?>
            <tr>
                <td class="left"><?php echo htmlspecialchars($it['descripcion']); ?></td>
                <td class="center"><?php echo $it['unidad_medida']; ?></td>
                <td class="center"><?php echo $it['cantidad'] + 0; ?></td>
                <td class="right"><?php echo number_format($it['precio_unitario'], 2); ?></td>
                <td class="right"><?php echo number_format($it['importe'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php for($k=0; $k < $emptyRows; $k++): ?>
            <tr><td>&nbsp;</td><td>U</td><td class="center">0</td><td class="center">-</td><td class="center">-</td></tr>
            <?php endfor; ?>
            <tr><td colspan="2" class="right" style="border:none; font-weight:bold;">TOTAL&gt;&gt;</td><td class="center" style="font-weight:bold;"><?php echo array_sum(array_column($items, 'cantidad')) + 0; ?></td><td colspan="2" style="border:none;"></td></tr>
        </tbody>
    </table>

    <table width="100%" style="margin-top:10px;">
        <tbody>
            <tr>
                <td width="60%" valign="top">
                    <div style="font-style:italic; color:#e67e22; font-size:14px; font-weight:bold; margin-bottom:5px;">Notas / Observaciones:</div>
                    <div style="font-size:11px;"><?php echo nl2br(htmlspecialchars($oferta['notas'])); ?></div>
                </td>
                <td width="40%" valign="top">
                    <table class="totals-table" align="right" width="100%">
                        <tbody>
                            <tr style="background-color:#fdebd0;"><td class="left">SUBTOTAL</td><td class="right"><?php echo number_format($oferta['subtotal'], 2); ?></td></tr>
                            <tr><td class="left total-final" style="color:#e67e22;">TOTAL OFERTA</td><td class="right total-final">$<?php echo number_format($oferta['total'], 2); ?></td></tr>
                        </tbody>
                    </table>
                </td>
            </tr>
        </tbody>
    </table>
    <div style="margin-top:30px; border-top:1px solid #ccc; padding-top:5px; font-size:10px; text-align:center;">Generado por <?= htmlspecialchars(config_loader_system_name()) ?> v3.0</div>
</div>
</body>
</html>
