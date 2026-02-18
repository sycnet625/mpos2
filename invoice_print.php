<?php
// ARCHIVO: invoice_print.php
// DESCRIPCIÓN: Visualizador de facturas existentes (Solo Lectura)
// Mantiene el diseño exacto "Hoja Carta" del generador.
require_once 'db.php';
session_start();

// Validar ID
if (!isset($_GET['id'])) { die("ID de factura no especificado."); }
$id = intval($_GET['id']);

// 1. Obtener Datos de Cabecera
$stmt = $pdo->prepare("SELECT * FROM facturas WHERE id = ?");
$stmt->execute([$id]);
$factura = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$factura) { die("Factura no encontrada."); }

// 2. Obtener Detalles
$stmtDet = $pdo->prepare("SELECT * FROM facturas_detalle WHERE id_factura = ?");
$stmtDet->execute([$id]);
$items = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

// Configuración de filas vacías para mantener diseño fijo
$totalRows = 13;
$usedRows = count($items);
$emptyRows = max(0, $totalRows - $usedRows);

// Marca de Agua si está anulada
$watermark = ($factura['estado'] === 'ANULADA') ? 'opacity: 0.5; background-image: url("assets/img/cancelled.png");' : '';
?>
<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <title>Factura <?php echo $factura['numero_factura']; ?></title>
    <style>
        /* ESTILOS GENERALES */
        body { font-family: "Calibri", Arial, sans-serif; font-size: 11px; margin: 0; padding: 0; background-color: #525659; color: #000; }
        
        /* CONTENEDOR TIPO HOJA CARTA */
        .page-container {
            width: 21.59cm; min-height: 27.94cm; background-color: white; margin: 30px auto; padding: 1.5cm;
            box-shadow: 0 0 15px rgba(0,0,0,0.5); box-sizing: border-box; position: relative;
        }

        /* TABLAS Y TEXTOS */
        .header-table { width: 100%; margin-bottom: 20px; }
        .company-name { font-size: 20px; font-weight: bold; color: #2F75B5; text-transform: uppercase; }
        .invoice-title { font-size: 28px; font-weight: bold; color: #2F75B5; text-align: right; }
        .blue-header { background-color: #2F75B5; color: white; font-weight: bold; text-align: center; padding: 5px; text-transform: uppercase; font-size: 12px; }
        .info-cell { text-align: center; font-weight: bold; padding: 5px; border-bottom: 1px solid #ccc; }
        .main-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .main-table th { background-color: #2F75B5; color: white; font-weight: bold; padding: 4px; border: 1px solid #aaa; text-align: center; }
        .main-table td { border: 1px solid #aaa; padding: 4px; font-size: 11px; height: 18px; }
        .left { text-align: left; } .right { text-align: right; } .center { text-align: center; }
        .totals-table { width: 35%; border-collapse: collapse; }
        .totals-table td { padding: 5px; border: none; }
        .total-final { background-color: #D9E1F2; font-size: 14px; font-weight: bold; border-top: 1px solid #2F75B5; }
        .signature-line { margin-top: 40px; border-top: 2px solid #2F75B5; width: 100%; padding-top: 5px; color: white; background-color: #2F75B5; text-align: center; font-weight: bold; }
        
        /* BOTON IMPRIMIR */
        .btn-print { position: fixed; top: 20px; right: 20px; padding: 10px 20px; background: #2F75B5; color: white; border: none; cursor: pointer; font-weight: bold; box-shadow: 0 2px 5px rgba(0,0,0,0.3); border-radius: 4px; z-index: 1000; }
        .btn-print:hover { background: #1e5687; }
        
        /* MARCA DE AGUA ANULADA */
        .cancelled-stamp {
            position: absolute; top: 30%; left: 50%; transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 100px; color: rgba(255, 0, 0, 0.3); font-weight: bold; border: 5px solid rgba(255, 0, 0, 0.3);
            padding: 20px; border-radius: 20px; z-index: 0; pointer-events: none;
        }

        @media print {
            body { background-color: white; margin: 0; }
            .page-container { width: 100%; margin: 0; padding: 0; box-shadow: none; border: none; min-height: auto; }
            .no-print { display: none !important; }
            @page { size: letter; margin: 1cm; }
        }
    </style>
</head>
<body>

<button onclick="window.print()" class="btn-print no-print">IMPRIMIR / PDF</button>

<div class="page-container">
    
    <?php if($factura['estado'] === 'ANULADA'): ?>
        <div class="cancelled-stamp">ANULADA</div>
    <?php endif; ?>

    <table class="header-table">
        <tbody>
            <tr>
                <td width="60%" valign="top">
                    <div class="company-name">PALWEB SURL</div>
                    <div>Magnolia #258 / Parque y Bella Vista<br>Canal, Cerro, La Habana</div>
                    <div>Teléfono: (+53) 5278-3083</div>
                    <div><a href="http://www.palweb.net/" style="color:blue">http://www.palweb.net</a></div>
                    <div>NIT: 50004328264</div>
                </td>
                <td width="40%" valign="top" align="right">
                    <div class="invoice-title">FACTURA</div>
                    <div style="font-size:10px; color:#666;">PAL WEB DELICIOSA COMIDA</div>
                    <table width="100%" cellspacing="0" style="margin-top:10px;">
                        <tbody>
                            <tr><td class="blue-header">FACTURA #</td><td class="blue-header">FECHA</td></tr>
                            <tr><td class="info-cell"><?php echo $factura['numero_factura']; ?></td><td class="info-cell"><?php echo date('d/m/Y', strtotime($factura['fecha_emision'])); ?></td></tr>
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
                    <div class="blue-header" style="text-align:left; padding-left:10px;">FACTURAR A CLIENTE #</div>
                    <div style="padding:5px; font-weight:bold;">
                        <?php echo htmlspecialchars($factura['cliente_nombre']); ?><br>
                        <span style="font-weight: normal; font-size: 10px;">
                            <?php echo htmlspecialchars($factura['cliente_direccion']); ?><br>
                            <?php echo htmlspecialchars($factura['cliente_telefono']); ?>
                        </span>
                    </div>
                </td>
                <td width="5%"></td>
                <td width="50%" valign="top">
                    <table width="100%" cellspacing="0">
                        <tbody>
                            <tr><td class="blue-header">TRANSPORTADO POR</td><td class="blue-header">TÉRMINOS</td></tr>
                            <tr>
                                <td class="info-cell" style="text-align:left;">
                                    <b><?php echo htmlspecialchars($factura['mensajero_nombre']); ?></b><br>
                                    <span style="font-weight:normal; font-size:10px"><?php echo htmlspecialchars($factura['vehiculo']); ?></span>
                                </td>
                                <td class="info-cell">
                                    <?php echo ($factura['estado_pago']=='PAGADA') ? 'PAGADO ('.$factura['metodo_pago'].')' : 'Pagadero al recibirse'; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="signature-line" style="margin-top:10px;">FIRMA DE CONFORMIDAD DEL CLIENTE</div>
                </td>
            </tr>
        </tbody>
    </table>

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
            <?php foreach($items as $it): ?>
            <tr>
                <td class="left"><?php echo htmlspecialchars($it['descripcion']); ?></td>
                <td class="center"></td>
                <td class="center"><?php echo $it['unidad_medida']; ?></td>
                <td class="center"><?php echo $it['cantidad'] + 0; ?></td>
                <td class="right"><?php echo number_format($it['precio_unitario'], 2); ?></td>
                <td class="right"><?php echo number_format($it['importe'], 2); ?></td>
            </tr>
            <?php endforeach; ?>

            <?php for($k=0; $k < $emptyRows; $k++): ?>
            <tr><td>&nbsp;</td><td></td><td>U</td><td class="center">0</td><td class="center">-</td><td class="center">-</td></tr>
            <?php endfor; ?>
            
            <tr>
                <td colspan="3" class="right" style="border:none; font-weight:bold;">TOTAL&gt;&gt;</td>
                <td class="center" style="font-weight:bold;"><?php echo count($items); ?></td>
                <td colspan="2" style="border:none;"></td>
            </tr>
        </tbody>
    </table>

    <table width="100%" style="margin-top:10px;">
        <tbody>
            <tr>
                <td width="60%" valign="top" style="font-size:12px;">
                    <div style="font-style:italic; color:#2F75B5; font-size:14px; font-weight:bold; margin-bottom:5px;">¡Gracias por su compra!</div>
                    <div>Dirija su Cheque a: <b>PALWEB SURL</b></div>
                    <div style="margin-top:5px;">Por transferencia Bancaria a la cta MN:</div>
                    <div style="font-size:16px; font-weight:bold;">0530445000251210</div>
                    <div style="font-size:11px;">de la sucursal 304 del banco metropolitano</div>
                </td>
                <td width="40%" valign="top">
                    <table class="totals-table" align="right" width="100%">
                        <tbody>
                            <tr style="background-color:#D9E1F2;"><td class="left">SUBTOTAL</td><td class="right"><?php echo number_format($factura['subtotal'], 2); ?></td></tr>
                            <tr style="background-color:#D9E1F2;"><td class="left">Manipulacion y envio %</td><td class="right">0.000%</td></tr>
                            <tr style="background-color:#D9E1F2;"><td class="left">Manipulacion y envio $</td><td class="right">-</td></tr>
                            <tr>
                                <td class="left total-final" style="color:#2F75B5;">TOTAL</td>
                                <td class="right total-final">$<?php echo number_format($factura['total'], 2); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </td>
            </tr>
        </tbody>
    </table>

    <div style="margin-top:30px; border-top:1px solid #ccc; padding-top:5px; font-size:10px;">
        Facturado por: <i><?php echo htmlspecialchars($factura['creado_por']); ?></i> (Administrador PALWEB SURL)<br>
        Si tiene alguna duda respecto esta factura, por favor contáctenos: <b>admin@palweb.net</b>
    </div>
    <div style="margin-top:20px; font-size:10px; color:#999; text-align:center;">Generado por PalWeb POS v2.0</div>

</div>

<?php include_once 'menu_master.php'; ?>
</body>
</html>

