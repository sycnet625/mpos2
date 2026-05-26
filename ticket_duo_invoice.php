<?php
// ticket_duo_invoice.php
// Renderiza DOS tickets diferentes en una sola hoja A4 (Dúplex real).
header('Content-Type: text/html; charset=utf-8');
ini_set('display_errors', 0);
require_once 'db.php';
require_once 'config_loader.php';

$id1 = isset($_GET['id1']) ? intval($_GET['id1']) : 0;
$id2 = isset($_GET['id2']) ? intval($_GET['id2']) : 0;

if ($id1 <= 0) die('ID de ticket 1 inválido.');
// Si no hay id2, duplicamos el id1 (comportamiento por defecto del duplex original)
if ($id2 <= 0) $id2 = $id1;

function getTicketData($pdo, $idVenta, $config) {
    if ($idVenta <= 0) return null;
    
    // Cabecera
    $stmtH = $pdo->prepare("SELECT * FROM ventas_cabecera WHERE id = ?");
    $stmtH->execute([$idVenta]);
    $venta = $stmtH->fetch(PDO::FETCH_ASSOC);
    if (!$venta) return null;

    // Ítems
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

    // Cajero
    $cajero = 'Administrador';
    if (!empty($venta['id_caja'])) {
        $s = $pdo->prepare("SELECT nombre_cajero FROM caja_sesiones WHERE id = ?");
        $s->execute([$venta['id_caja']]);
        $cajero = $s->fetchColumn() ?: 'Administrador';
    }

    // Cliente
    $cliNombre = $venta['cliente_nombre'] ?? 'Mostrador';
    $cliTelefono = $venta['cliente_telefono'] ?? '';
    $cliDireccion = $venta['cliente_direccion'] ?? '';

    // Cálculos
    $subtotal = 0;
    foreach($items as &$it) {
        $it['subtotal'] = round($it['cantidad'] * $it['precio'], 2);
        $subtotal += $it['subtotal'];
    }
    $total = floatval($venta['total']);
    $envio = round($total - $subtotal, 2);

    return [
        'venta' => $venta,
        'items' => $items,
        'cajero' => $cajero,
        'cliNombre' => $cliNombre,
        'cliTelefono' => $cliTelefono,
        'cliDireccion' => $cliDireccion,
        'subtotal' => $subtotal,
        'total' => $total,
        'envio' => $envio,
        'id' => $idVenta
    ];
}

$data1 = getTicketData($pdo, $id1, $config);
$data2 = getTicketData($pdo, $id2, $config);

if (!$data1) die('Ticket 1 no encontrado.');

// Logo global
$logoRel = $config['marca_empresa_logo'] ?? $config['ticket_logo'] ?? '';
$logoUrl = ($logoRel && file_exists(__DIR__ . '/' . $logoRel)) ? '/' . ltrim($logoRel, '/') : '';

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Impresión Dúplex - Tickets #<?= $id1 ?> y #<?= $id2 ?></title>
<style>
    body { font-family: "Calibri", Arial, sans-serif; margin: 0; padding: 0; background: #525659; }
    
    .toolbar {
        position: fixed; top: 0; left: 0; right: 0;
        background: #1e293b; color: #fff;
        padding: 10px 20px; display: flex; align-items: center; gap: 15px;
        z-index: 1000;
    }
    .toolbar button {
        padding: 8px 20px; border: none; border-radius: 6px;
        cursor: pointer; font-weight: bold; background: #2F75B5; color: #fff;
    }

    .a4-page {
        width: 210mm; height: 297mm;
        background: white; margin: 60px auto;
        box-shadow: 0 0 20px rgba(0,0,0,0.5);
        display: flex; flex-direction: column;
        box-sizing: border-box;
    }

    .ticket-half {
        height: 148.5mm; width: 100%;
        padding: 10mm 15mm;
        box-sizing: border-box;
        overflow: hidden;
        position: relative;
        display: flex; flex-direction: column;
    }

    .cut-line {
        height: 0; border-top: 1px dashed #bbb;
        text-align: center; color: #bbb; font-size: 10px;
        line-height: 0; position: relative;
    }
    .cut-line span { background: #fff; padding: 0 10px; position: relative; top: -5px; }

    /* Estilos Factura Mejorados (Más grandes como pidió el usuario) */
    .header { display: flex; justify-content: space-between; margin-bottom: 10px; }
    .company-info { width: 60%; }
    .company-name { font-size: 22px; font-weight: bold; color: #2F75B5; margin-bottom: 2px; }
    .invoice-box { width: 35%; text-align: right; }
    .invoice-title { font-size: 26px; font-weight: bold; color: #2F75B5; }
    
    .blue-bar { background: #2F75B5; color: #fff; padding: 4px 8px; font-weight: bold; font-size: 13px; text-transform: uppercase; margin-bottom: 4px; }
    
    .client-section { display: flex; gap: 20px; margin-bottom: 10px; }
    .client-box { flex: 1; border: 1px solid #ddd; padding: 6px; }
    
    .items-table { width: 100%; border-collapse: collapse; margin-top: 5px; flex-grow: 1; }
    .items-table th { background: #2F75B5; color: #fff; padding: 6px; font-size: 13px; text-align: left; }
    .items-table td { border: 1px solid #ddd; padding: 6px; font-size: 13px; } /* SUBIDO TAMAÑO */
    .items-table .text-right { text-align: right; }
    .items-table .text-center { text-align: center; }
    .items-table .fw-bold { font-weight: bold; font-size: 14px; } /* PRODUCTOS Y PRECIOS MÁS GRANDES */

    .footer-section { display: flex; justify-content: space-between; margin-top: 10px; align-items: flex-end; }
    .bank-info { width: 55%; font-size: 11px; }
    .totals-box { width: 40%; }
    .totals-table { width: 100%; border-collapse: collapse; }
    .totals-table td { padding: 4px; font-size: 13px; }
    .total-row { background: #D9E1F2; font-weight: bold; font-size: 18px; color: #2F75B5; border-top: 2px solid #2F75B5; } /* TOTAL MUCHO MÁS GRANDE */

    @media print {
        .toolbar { display: none; }
        body { background: none; }
        .a4-page { margin: 0; box-shadow: none; border: none; width: 100%; height: 100%; }
        @page { size: A4 portrait; margin: 0; }
        .blue-bar, .items-table th, .total-row { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
</style>
</head>
<body>

<div class="toolbar">
    <span>🖨️ Modo Dúplex: Tickets #<?= $id1 ?> y #<?= $id2 ?></span>
    <button onclick="window.print()">IMPRIMIR EN A4</button>
    <button style="background:#64748b;" onclick="window.close()">CERRAR</button>
</div>

<div class="a4-page">
    <?php renderHalf($data1, $logoUrl, $config); ?>
    <div class="cut-line"><span>✂ CORTAR AQUÍ ✂</span></div>
    <?php renderHalf($data2 ?: $data1, $logoUrl, $config); ?>
</div>

<?php
function renderHalf($d, $logoUrl, $config) {
    if (!$d) return;
    $v = $d['venta'];
    $numFac = date('Ymd', strtotime($v['fecha'])) . str_pad($v['id'], 3, '0', STR_PAD_LEFT);
?>
<div class="ticket-half">
    <div class="header">
        <div class="company-info">
            <?php if ($logoUrl): ?>
                <img src="<?= $logoUrl ?>" style="max-height:45px; margin-bottom:5px;">
            <?php endif; ?>
            <div class="company-name"><?= htmlspecialchars($config['tienda_nombre']) ?></div>
            <div style="font-size:11px;"><?= htmlspecialchars($config['direccion']) ?></div>
            <div style="font-size:11px;">Tel: <?= htmlspecialchars($config['telefono']) ?></div>
        </div>
        <div class="invoice-box">
            <div class="invoice-title">FACTURA</div>
            <div style="font-size:12px; font-weight:bold; margin-top:5px;">
                #<?= $numFac ?><br>
                FECHA: <?= date('d/m/Y', strtotime($v['fecha'])) ?>
            </div>
        </div>
    </div>

    <div class="client-section">
        <div class="client-box">
            <div class="blue-bar">Facturar a:</div>
            <div style="font-weight:bold; font-size:14px;"><?= htmlspecialchars($d['cliNombre']) ?></div>
            <div style="font-size:12px;"><?= htmlspecialchars($d['cliDireccion']) ?></div>
            <div style="font-size:12px;">Tel: <?= htmlspecialchars($d['cliTelefono']) ?></div>
        </div>
        <div class="client-box">
            <div class="blue-bar">Información:</div>
            <div style="font-size:12px;">Cajero: <?= htmlspecialchars($d['cajero']) ?></div>
            <div style="font-size:12px;">Pago: <?= htmlspecialchars($v['metodo_pago']) ?></div>
            <div style="font-size:12px;">Ticket Original: #<?= $d['id'] ?></div>
        </div>
    </div>

    <table class="items-table">
        <thead>
            <tr>
                <th width="10%">CANT</th>
                <th>DESCRIPCIÓN</th>
                <th width="15%" class="text-right">PRECIO</th>
                <th width="15%" class="text-right">TOTAL</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($d['items'] as $it): ?>
            <tr>
                <td class="text-center"><?= number_format($it['cantidad'], 1) ?></td>
                <td class="fw-bold"><?= htmlspecialchars($it['descripcion']) ?></td>
                <td class="text-right">$<?= number_format($it['precio'], 2) ?></td>
                <td class="text-right fw-bold">$<?= number_format($it['subtotal'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php 
            // Rellenar para mantener estructura
            $rowsNeeded = 6;
            $current = count($d['items']);
            for($i=$current; $i<$rowsNeeded; $i++) echo "<tr><td>&nbsp;</td><td></td><td></td><td></td></tr>";
            ?>
        </tbody>
    </table>

    <div class="footer-section">
        <div class="bank-info">
            <?php if($config['cuenta_bancaria']): ?>
                <strong>Pago por transferencia:</strong><br>
                <?= htmlspecialchars($config['cuenta_bancaria']) ?> (<?= htmlspecialchars($config['banco']) ?>)
            <?php endif; ?>
            <?php if($v['notas']): ?>
                <br><strong>Notas:</strong> <?= htmlspecialchars($v['notas']) ?>
            <?php endif; ?>
        </div>
        <div class="totals-box">
            <table class="totals-table">
                <tr>
                    <td>SUBTOTAL</td>
                    <td class="text-right">$<?= number_format($d['subtotal'], 2) ?></td>
                </tr>
                <?php if($d['envio'] > 0): ?>
                <tr>
                    <td>ENVÍO / MENSAJERÍA</td>
                    <td class="text-right">$<?= number_format($d['envio'], 2) ?></td>
                </tr>
                <?php endif; ?>
                <tr class="total-row">
                    <td>TOTAL</td>
                    <td class="text-right">$<?= number_format($d['total'], 2) ?></td>
                </tr>
            </table>
        </div>
    </div>
</div>
<?php } ?>

</body>
</html>
