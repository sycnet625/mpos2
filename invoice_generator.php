<?php
// ARCHIVO: invoice_generator.php
// DESCRIPCIÓN: Genera facturas HTML y guarda historial.
// VERSIÓN: 2.6 (FIX: AUTO-INCREMENTO INTELIGENTE Y ANTI-DUPLICADOS)
require_once 'db.php';
session_start();

// 1. PROCESAR EL FORMULARIO
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Si ticket_id viene vacío, forzamos NULL.
    $ticketInput = $_POST['ticket_id'] ?? '';
    $ticketID = ($ticketInput !== '') ? intval($ticketInput) : null;
    
    $fecha      = $_POST['fecha'] ?? date('Y-m-d');
    $cliNombre  = $_POST['cliente_nombre'] ?? 'Cliente General';
    $cliDir     = $_POST['cliente_direccion'] ?? '';
    $cliTel     = $_POST['cliente_telefono'] ?? '';
    $mensajero  = $_POST['mensajero'] ?? '';
    $vehiculo   = $_POST['vehiculo'] ?? '';
    $admin      = $_SESSION['admin_name'] ?? 'Administrador'; 

    // --- LÓGICA DE NUMERACIÓN INTELIGENTE ---
    $numFacturaPost = $_POST['numero_factura'] ?? '';

    // 1. Verificamos si el número que viene del formulario ya existe en la BD
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM facturas WHERE numero_factura = ?");
    $stmtCheck->execute([$numFacturaPost]);
    $existe = $stmtCheck->fetchColumn();

    // 2. Si está vacío O SI YA EXISTE (Duplicado), calculamos el siguiente libre obligatoriamente
    if (empty($numFacturaPost) || $existe > 0) {
        // Buscamos el último ID real en la base de datos
        $sqlLast = "SELECT id FROM facturas ORDER BY id DESC LIMIT 1";
        $stmtL = $pdo->query($sqlLast);
        $lastId = $stmtL->fetchColumn(); // Obtiene el ID más alto actual
        
        $nextId = ($lastId) ? $lastId + 1 : 1; // Sumamos 1
        
        // Generamos el nuevo código: AñoMesDia + ID (Ej: 20260209005)
        $numFactura = date('Ymd') . str_pad($nextId, 3, '0', STR_PAD_LEFT);
    } else {
        // Si no existe, usamos el manual que escribió el usuario
        $numFactura = $numFacturaPost;
    }

    // Obtener Items
    $items = [];
    $subtotal = 0;
    $total = 0;

    if ($ticketID && $ticketID > 0) {
        $stmtItems = $pdo->prepare("SELECT d.cantidad, d.precio, p.nombre as descripcion, 'UND' as um 
                                    FROM ventas_detalle d 
                                    JOIN productos p ON d.id_producto = p.codigo
                                    WHERE d.id_venta_cabecera = ?");
        $stmtItems->execute([$ticketID]);
        $rawItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
        
        foreach($rawItems as $i) {
            $importe = $i['cantidad'] * $i['precio'];
            $items[] = [
                'desc' => $i['descripcion'],
                'um'   => $i['um'],
                'cant' => $i['cantidad'],
                'precio'=> $i['precio'],
                'importe'=> $importe
            ];
            $subtotal += $importe;
        }
        $total = $subtotal; 
    }

    // GUARDAR EN BASE DE DATOS
    try {
        $pdo->beginTransaction();
        
        $sqlCab = "INSERT INTO facturas (numero_factura, fecha_emision, id_ticket_origen, cliente_nombre, cliente_direccion, cliente_telefono, mensajero_nombre, vehiculo, subtotal, total, creado_por) 
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmtCab = $pdo->prepare($sqlCab);
        $stmtCab->execute([$numFactura, $fecha, $ticketID, $cliNombre, $cliDir, $cliTel, $mensajero, $vehiculo, $subtotal, $total, $admin]);
        $facturaDbId = $pdo->lastInsertId();

        $sqlDet = "INSERT INTO facturas_detalle (id_factura, descripcion, unidad_medida, cantidad, precio_unitario, importe) VALUES (?, ?, ?, ?, ?, ?)";
        $stmtDet = $pdo->prepare($sqlDet);

        foreach($items as $item) {
            $stmtDet->execute([$facturaDbId, $item['desc'], $item['um'], $item['cant'], $item['precio'], $item['importe']]);
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        die("Error al guardar factura: " . $e->getMessage());
    }

    // RENDERIZAR HTML
    $totalRows = 13;
    $usedRows = count($items);
    $emptyRows = max(0, $totalRows - $usedRows);
?>
<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <title>Factura <?php echo $numFactura; ?></title>
    <style>
        /* ESTILOS GENERALES Y DE VISUALIZACIÓN WEB */
        body { 
            font-family: "Calibri", Arial, sans-serif; 
            font-size: 11px; 
            margin: 0; 
            padding: 0; 
            background-color: #525659; /* Fondo gris oscuro tipo App */
            color: #000; 
        }

        /* CONTENEDOR TIPO HOJA DE PAPEL (CARTA) */
        .page-container {
            width: 21.59cm;      /* Ancho Carta (8.5in) */
            min-height: 27.94cm; /* Alto Carta (11in) */
            background-color: white;
            margin: 30px auto;   /* Centrado en pantalla */
            padding: 1.5cm;      /* Margen interno simulado */
            box-shadow: 0 0 15px rgba(0,0,0,0.5); /* Sombra 3D */
            box-sizing: border-box; /* Para que el padding no rompa el ancho */
            position: relative;
        }

        /* ESTILOS DE LA FACTURA */
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
        
        /* BOTÓN DE IMPRIMIR FLOTANTE */
        .btn-print {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 10px 20px;
            background: #2F75B5;
            color: white;
            border: none;
            cursor: pointer;
            font-weight: bold;
            box-shadow: 0 2px 5px rgba(0,0,0,0.3);
            border-radius: 4px;
            z-index: 1000;
        }
        .btn-print:hover { background: #1e5687; }

        /* ESTILOS ESPECÍFICOS DE IMPRESIÓN */
        @media print {
            body { 
                background-color: white; 
                margin: 0;
            }
            .page-container {
                width: 100%;
                margin: 0;
                padding: 0; /* La impresora manejará el margen físico */
                box-shadow: none;
                border: none;
                min-height: auto;
            }
            .no-print { display: none !important; }
            @page {
                size: letter; /* Carta */
                margin: 1cm;  /* Margen seguro de impresión */
            }
        }
    </style>
</head>
<body>

<button onclick="window.print()" class="btn-print no-print">IMPRIMIR / PDF</button>

<div class="page-container">

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
                            <tr><td class="info-cell"><?php echo $numFactura; ?></td><td class="info-cell"><?php echo date('d/m/Y', strtotime($fecha)); ?></td></tr>
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
                        <?php echo htmlspecialchars($cliNombre); ?><br>
                        <span style="font-weight: normal; font-size: 10px;"><?php echo htmlspecialchars($cliDir); ?><br><?php echo htmlspecialchars($cliTel); ?></span>
                    </div>
                </td>
                <td width="5%"></td>
                <td width="50%" valign="top">
                    <table width="100%" cellspacing="0">
                        <tbody>
                            <tr><td class="blue-header">TRANSPORTADO POR</td><td class="blue-header">TÉRMINOS</td></tr>
                            <tr>
                                <td class="info-cell" style="text-align:left;">
                                    <b><?php echo htmlspecialchars($mensajero); ?></b><br>
                                    <span style="font-weight:normal; font-size:10px"><?php echo htmlspecialchars($vehiculo); ?></span>
                                </td>
                                <td class="info-cell">Pagadero al recibirse</td>
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
                <td class="left"><?php echo htmlspecialchars($it['desc']); ?></td>
                <td class="center"></td>
                <td class="center"><?php echo $it['um']; ?></td>
                <td class="center"><?php echo $it['cant'] + 0; ?></td>
                <td class="right"><?php echo number_format($it['precio'], 2); ?></td>
                <td class="right"><?php echo number_format($it['importe'], 2); ?></td>
            </tr>
            <?php endforeach; ?>

            <?php for($k=0; $k < $emptyRows; $k++): ?>
            <tr>
                <td>&nbsp;</td><td></td><td>U</td><td class="center">0</td><td class="center">-</td><td class="center">-</td>
            </tr>
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
                            <tr style="background-color:#D9E1F2;"><td class="left">SUBTOTAL</td><td class="right"><?php echo number_format($subtotal, 2); ?></td></tr>
                            <tr style="background-color:#D9E1F2;"><td class="left">Manipulacion y envio %</td><td class="right">0.000%</td></tr>
                            <tr style="background-color:#D9E1F2;"><td class="left">Manipulacion y envio $</td><td class="right">-</td></tr>
                            <tr>
                                <td class="left total-final" style="color:#2F75B5;">TOTAL</td>
                                <td class="right total-final">$<?php echo number_format($total, 2); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </td>
            </tr>
        </tbody>
    </table>

    <div style="margin-top:30px; border-top:1px solid #ccc; padding-top:5px; font-size:10px;">
        Facturado por: <i><?php echo htmlspecialchars($admin); ?></i> (Administrador PALWEB SURL)<br>
        Si tiene alguna duda respecto esta factura, por favor contáctenos: <b>admin@palweb.net</b>
    </div>
    <div style="margin-top:20px; font-size:10px; color:#999; text-align:center;">Generado por PalWeb POS v2.0</div>

</div> <?php include_once 'menu_master.php'; ?>
</body>
</html>
<?php
} else {
    echo "Acceso directo no permitido. Use el sistema POS.";
}
?>

