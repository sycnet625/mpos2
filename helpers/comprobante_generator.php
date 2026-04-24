<?php
/**
 * ARCHIVO: helpers/comprobante_generator.php
 * DESCRIPCIÓN: Generador de comprobantes de ventas en PDF/HTML
 * Crea comprobantes profesionales basados en plantillas
 */

class ComprobanteGenerator {
    private $pdo;
    private $config;

    public function __construct($pdo, $config) {
        $this->pdo = $pdo;
        $this->config = $config;
    }

    /**
     * Generar HTML del comprobante (Voucher Premium)
     */
    public function generarHTML($idVenta, float $markupPct = 0.0) {
        $baseDir = dirname(__DIR__); // /var/www
        $markupPct = max(0, round($markupPct, 2));
        $markupFactor = 1 + ($markupPct / 100);

        // Obtener datos de la venta
        $stmtVenta = $this->pdo->prepare("
            SELECT v.*,
                   c.tipo_cliente, c.ruc, c.contacto_principal
            FROM ventas_cabecera v
            LEFT JOIN clientes c ON v.cliente_nombre = c.nombre
            WHERE v.id = ?
        ");
        $stmtVenta->execute([$idVenta]);
        $venta = $stmtVenta->fetch(PDO::FETCH_ASSOC);

        if (!$venta) {
            throw new Exception("Venta no encontrada: $idVenta");
        }

        // Obtener detalles de la venta
        $stmtDetalles = $this->pdo->prepare("
            SELECT * FROM ventas_detalle WHERE id_venta_cabecera = ?
            ORDER BY id ASC
        ");
        $stmtDetalles->execute([$idVenta]);
        $detalles = $stmtDetalles->fetchAll(PDO::FETCH_ASSOC);

        // Datos de la empresa
        $nombreEmpresa = $this->config['tienda_nombre'] ?? 'Pastelería Renacer';
        $fecha = date('d/m/Y', strtotime($venta['fecha']));
        $noFactura = $venta['uuid'] ?? 'N/A';
        $cliente = $venta['cliente_nombre'] ?? 'Mostrador';
        $totalOriginal = floatval($venta['total']);
        $totalCalculado = 0.0;

        // Generar tabla de detalles
        $detallesHTML = '';
        $contador = 1;
        foreach ($detalles as $det) {
            if (floatval($det['cantidad']) < 0) continue; // Skip devoluciones
            $precioCalculado = round(floatval($det['precio']) * $markupFactor, 2);
            $subtotalValor = round(floatval($det['cantidad']) * $precioCalculado, 2);
            $totalCalculado += $subtotalValor;
            $subtotal = number_format($subtotalValor, 2, '.', ',');
            $cantidad = number_format($det['cantidad'], 2, '.', ',');
            $precio = number_format($precioCalculado, 2, '.', ',');

            $detallesHTML .= "
            <tr>
                <td style=\"text-align: center; padding: 8px; border-bottom: 1px solid #ddd;\">$contador</td>
                <td style=\"padding: 8px; border-bottom: 1px solid #ddd;\">" . htmlspecialchars($det['nombre_producto']) . "</td>
                <td style=\"text-align: right; padding: 8px; border-bottom: 1px solid #ddd;\">$cantidad</td>
                <td style=\"text-align: right; padding: 8px; border-bottom: 1px solid #ddd;\">$precio</td>
                <td style=\"text-align: right; padding: 8px; border-bottom: 1px solid #ddd; font-weight: bold;\">$subtotal</td>
            </tr>
            ";
            $contador++;
        }

        $total = number_format($totalCalculado, 2, '.', ',');
        $notaMarkup = '';
        if ($markupPct > 0) {
            $notaMarkup = "
            <div style=\"margin: 0 auto 18px; max-width: 850px; background: #fff3cd; color: #664d03; border: 1px dashed #856404; border-radius: 8px; padding: 10px 12px; font-size: 13px; text-align: center;\">
                <strong>Impresión especial con +{$markupPct}% por producto.</strong>
                Solo visual. La venta real y la contabilidad conservan el precio POS.
            </div>";
        }

        $metodo = $venta['metodo_pago'] ?? 'Efectivo';
        $hora = date('H:i:s', strtotime($venta['fecha']));

        // 1. Buscar logo de sucursal en BD usando id_sucursal de la venta
        $logoUrl = '';
        if (!empty($venta['id_sucursal'])) {
            try {
                $stmtLogo = $this->pdo->prepare("SELECT imagen_banner FROM sucursales WHERE id = ? LIMIT 1");
                $stmtLogo->execute([$venta['id_sucursal']]);
                $logoRow = $stmtLogo->fetch(PDO::FETCH_ASSOC);
                if ($logoRow && !empty($logoRow['imagen_banner'])) {
                    $rel = ltrim($logoRow['imagen_banner'], '/');
                    if (file_exists($baseDir . '/' . $rel)) {
                        $logoUrl = '/' . $rel;
                    }
                }
            } catch (Throwable $e) { /* tabla no existe aún */ }
        }
        // 2. Fallback a config (pos.cfg)
        if (empty($logoUrl)) {
            $logoRelPath = $this->config['sucursal_banner'] ?? $this->config['marca_empresa_logo'] ?? $this->config['ticket_logo'] ?? '';
            if (!empty($logoRelPath) && file_exists($baseDir . '/' . ltrim($logoRelPath, '/'))) {
                $logoUrl = '/' . ltrim($logoRelPath, '/');
            }
        }
        // 3. Fallback a logo del sistema
        if (empty($logoUrl) && file_exists($baseDir . '/assets/img/logo_comprobante.svg')) {
            $logoUrl = '/assets/img/logo_comprobante.svg';
        }

        $logoTag = $logoUrl ? "<img src=\"" . htmlspecialchars($logoUrl) . "\" alt=\"Logo\">" : '';

        $html = "<!DOCTYPE html>
<html lang=\"es\">
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <title>Comprobante $noFactura</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f4f6f9;
            color: #333;
        }
        body { padding: 20px; }
        .barra-acciones { max-width: 850px; margin: 0 auto 12px; display: flex; gap: 10px; }
        .barra-acciones button { padding: 8px 18px; border: none; border-radius: 6px; font-size: 14px; cursor: pointer; }
        .btn-imprimir { background: #2c3e50; color: #fff; }
        .btn-cerrar   { background: #e0e0e0; color: #333; }
        .btn-recargo  { background: #dc3545; color: #fff; }
        .container { max-width: 850px; margin: 0 auto; padding: 30px; border: 1px solid #ddd; background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,.08); }
        .encabezado { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 20px; }
        .logo-section { flex: 0 0 150px; }
        .logo-section img { max-width: 140px; height: auto; }
        .empresa-section { flex: 1; text-align: center; }
        .empresa-section h1 { font-size: 28px; margin-bottom: 5px; }
        .info-section { flex: 0 0 200px; text-align: right; font-size: 13px; }
        .cliente-seccion { margin-bottom: 20px; padding: 15px; background: #f9f9f9; border-left: 4px solid #333; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th { background: #333; color: #fff; padding: 10px; text-align: left; font-size: 12px; }
        td { padding: 10px; border-bottom: 1px solid #eee; font-size: 13px; }
        .totales { display: flex; justify-content: flex-end; }
        .totales-box { width: 250px; }
        .total-fila { display: flex; justify-content: space-between; padding: 5px 0; }
        .total-fila.grande { font-size: 18px; font-weight: bold; border-top: 2px solid #333; padding-top: 10px; margin-top: 10px; }
        .pie { text-align: center; margin-top: 30px; font-size: 12px; color: #888; }
        @media print {
            body { background: #fff; padding: 0; }
            .barra-acciones { display: none !important; }
            .container { border: none; box-shadow: none; padding: 0; }
        }
    </style>
</head>
<body>
    <div class=\"barra-acciones\">
        <button class=\"btn-imprimir\" onclick=\"window.print()\">&#128438; Imprimir</button>
        <button class=\"btn-recargo\" onclick=\"printWithMarkup(20)\">&#129534; Imprimir +20%</button>
        <button class=\"btn-cerrar\" onclick=\"window.close()\">&#10006; Cerrar</button>
    </div>
    $notaMarkup
    <div class=\"container\">
        <div class=\"encabezado\">
            <div class=\"logo-section\">$logoTag</div>
            <div class=\"empresa-section\">
                <h1>COMPROBANTE</h1>
                <p>DE VENTA</p>
            </div>
            <div class=\"info-section\">
                <div><strong>Nº:</strong> $noFactura</div>
                <div><strong>Fecha:</strong> $fecha</div>
                <div><strong>Hora:</strong> $hora</div>
            </div>
        </div>
        <div class=\"cliente-seccion\">
            <p><strong>Cliente:</strong> $cliente</p>
            <p><strong>Empresa:</strong> $nombreEmpresa</p>
        </div>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Descripción</th>
                    <th style=\"text-align:right\">Cant.</th>
                    <th style=\"text-align:right\">Precio</th>
                    <th style=\"text-align:right\">Total</th>
                </tr>
            </thead>
            <tbody>$detallesHTML</tbody>
        </table>
        <div class=\"totales\">
            <div class=\"totales-box\">
                <div class=\"total-fila grande\">
                    <span>TOTAL</span>
                    <span>\$$total</span>
                </div>
                " . ($markupPct > 0 ? "
                <div class=\"total-fila\">
                    <span>Total POS:</span>
                    <span>$" . number_format($totalOriginal, 2, '.', ',') . "</span>
                </div>" : "") . "
                <div class=\"total-fila\">
                    <span>Método:</span>
                    <span>$metodo</span>
                </div>
            </div>
        </div>
        <div class=\"pie\">
            <p><strong>Gracias por su compra</strong></p>
            <p>Generado el " . date('d/m/Y H:i:s') . "</p>
        </div>
    </div>
    <script>
        function printWithMarkup(pct) {
            const url = new URL(window.location.href);
            url.searchParams.set('markup_pct', String(pct));
            window.location.href = url.toString();
        }
    </script>
</body>
</html>";
        return $html;
    }

    /**
     * Generar HTML del Ticket (Formato Térmico)
     */
    public function generarTicketHTML($idVenta) {
        $baseDir = dirname(__DIR__);
        $stmtVenta = $this->pdo->prepare("SELECT * FROM ventas_cabecera WHERE id = ?");
        $stmtVenta->execute([$idVenta]);
        $venta = $stmtVenta->fetch(PDO::FETCH_ASSOC);
        if (!$venta) throw new Exception("Venta no encontrada");

        $stmtDet = $this->pdo->prepare("SELECT d.*, p.nombre FROM ventas_detalle d LEFT JOIN productos p ON d.id_producto = p.codigo WHERE d.id_venta_cabecera = ?");
        $stmtDet->execute([$idVenta]);
        $items = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

        $logoUrl2 = '';
        if (!empty($venta['id_sucursal'])) {
            try {
                $stmtLogo2 = $this->pdo->prepare("SELECT imagen_banner FROM sucursales WHERE id = ? LIMIT 1");
                $stmtLogo2->execute([$venta['id_sucursal']]);
                $logoRow2 = $stmtLogo2->fetch(PDO::FETCH_ASSOC);
                if ($logoRow2 && !empty($logoRow2['imagen_banner'])) {
                    $rel2 = ltrim($logoRow2['imagen_banner'], '/');
                    if (file_exists($baseDir . '/' . $rel2)) $logoUrl2 = '/' . $rel2;
                }
            } catch (Throwable $e) {}
        }
        if (empty($logoUrl2)) {
            $logoRelPath = $this->config['sucursal_banner'] ?? $this->config['marca_empresa_logo'] ?? $this->config['ticket_logo'] ?? '';
            if (!empty($logoRelPath) && file_exists($baseDir . '/' . ltrim($logoRelPath, '/'))) {
                $logoUrl2 = '/' . ltrim($logoRelPath, '/');
            }
        }
        $logoTag = $logoUrl2 ? "<img src=\"" . htmlspecialchars($logoUrl2) . "\" style=\"max-width:200px; max-height:80px; margin-bottom:10px;\">" : '';

        $itemsHTML = "";
        foreach ($items as $it) {
            $n = htmlspecialchars($it['nombre'] ?? $it['nombre_producto']);
            $c = number_format($it['cantidad'], 2);
            $p = number_format($it['precio'], 2);
            $t = number_format($it['cantidad'] * $it['precio'], 2);
            $itemsHTML .= "<tr><td>$c</td><td>$n</td><td align='right'>\$$t</td></tr>";
        }

        $html = "<html><head><style>
            body { font-family: 'Courier New', monospace; width: 300px; font-size: 12px; margin: 0; padding: 10px; }
            .center { text-align: center; }
            .right { text-align: right; }
            .bold { font-weight: bold; }
            .border-top { border-top: 1px dashed #000; padding-top: 5px; margin-top: 5px; }
            table { width: 100%; border-collapse: collapse; }
        </style></head><body>
            <div class='center'>
                $logoTag
                <h2 style='margin:0'>".htmlspecialchars($this->config['tienda_nombre'])."</h2>
                <small>".htmlspecialchars($this->config['direccion'])."</small><br>
                <small>Tel: ".htmlspecialchars($this->config['telefono'])."</small>
            </div>
            <div class='border-top'>
                <table>
                    <tr><td>Ticket:</td><td class='right'>#".str_pad($idVenta, 6, '0', STR_PAD_LEFT)."</td></tr>
                    <tr><td>Fecha:</td><td class='right'>".date('d/m/Y H:i', strtotime($venta['fecha']))."</td></tr>
                    <tr><td>Pago:</td><td class='right'>".htmlspecialchars($venta['metodo_pago'])."</td></tr>
                </table>
            </div>
            <table class='border-top'>
                <thead><tr><th align='left'>Cant</th><th align='left'>Desc</th><th align='right'>Total</th></tr></thead>
                <tbody>$itemsHTML</tbody>
            </table>
            <div class='border-top right' style='font-size:16px;'>
                <span class='bold'>TOTAL: \$".number_format($venta['total'], 2)."</span>
            </div>
            <div class='center border-top' style='margin-top:10px'>
                <p>".htmlspecialchars($this->config['mensaje_final'])."</p>
            </div>
        </body></html>";
        return $html;
    }

    /**
     * Generar HTML de Factura (Formato A4 Profesional)
     */
    public function generarFacturaHTML($idVenta) {
        $baseDir = dirname(__DIR__);
        $stmtVenta = $this->pdo->prepare("SELECT * FROM ventas_cabecera WHERE id = ?");
        $stmtVenta->execute([$idVenta]);
        $venta = $stmtVenta->fetch(PDO::FETCH_ASSOC);
        
        $stmtDet = $this->pdo->prepare("SELECT d.*, p.nombre, p.unidad_medida FROM ventas_detalle d LEFT JOIN productos p ON d.id_producto = p.codigo WHERE d.id_venta_cabecera = ?");
        $stmtDet->execute([$idVenta]);
        $items = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

        $logoUrl3 = '';
        if (!empty($venta['id_sucursal'])) {
            try {
                $stmtLogo3 = $this->pdo->prepare("SELECT imagen_banner FROM sucursales WHERE id = ? LIMIT 1");
                $stmtLogo3->execute([$venta['id_sucursal']]);
                $logoRow3 = $stmtLogo3->fetch(PDO::FETCH_ASSOC);
                if ($logoRow3 && !empty($logoRow3['imagen_banner'])) {
                    $rel3 = ltrim($logoRow3['imagen_banner'], '/');
                    if (file_exists($baseDir . '/' . $rel3)) $logoUrl3 = '/' . $rel3;
                }
            } catch (Throwable $e) {}
        }
        if (empty($logoUrl3)) {
            $logoRelPath = $this->config['sucursal_banner'] ?? $this->config['marca_empresa_logo'] ?? $this->config['ticket_logo'] ?? '';
            if (!empty($logoRelPath) && file_exists($baseDir . '/' . ltrim($logoRelPath, '/'))) {
                $logoUrl3 = '/' . ltrim($logoRelPath, '/');
            }
        }

        $numFactura = date('Ymd', strtotime($venta['fecha'])) . str_pad($idVenta, 3, '0', STR_PAD_LEFT);
        
        $rowsHTML = "";
        foreach ($items as $it) {
            $st = number_format($it['cantidad'] * $it['precio'], 2);
            $rowsHTML .= "<tr>
                <td style='border:1px solid #aaa;padding:5px;text-align:center;'>".number_format($it['cantidad'], 2)."</td>
                <td style='border:1px solid #aaa;padding:5px;'>".htmlspecialchars($it['unidad_medida'] ?? 'UND')."</td>
                <td style='border:1px solid #aaa;padding:5px;'>".htmlspecialchars($it['nombre'] ?? $it['nombre_producto'])."</td>
                <td style='border:1px solid #aaa;padding:5px;text-align:right;'>$".number_format($it['precio'], 2)."</td>
                <td style='border:1px solid #aaa;padding:5px;text-align:right;'>$$st</td>
            </tr>";
        }

        $html = "<html><head><style>
            body { font-family: sans-serif; padding: 40px; color: #333; }
            .header { display: flex; justify-content: space-between; margin-bottom: 40px; }
            .company-info h1 { color: #2F75B5; margin: 0; }
            .invoice-title { text-align: right; color: #2F75B5; }
            .blue-bar { background: #2F75B5; color: #fff; padding: 8px; text-align: center; font-weight: bold; margin-bottom: 10px; }
            table { width: 100%; border-collapse: collapse; }
            th { background: #2F75B5; color: #fff; padding: 8px; border: 1px solid #2F75B5; }
        </style></head><body>
            <div class='header'>
                <div class='company-info'>
                    ".($logoUrl3 ? "<img src='".htmlspecialchars($logoUrl3)."' style='max-width:180px;'>" : "")."
                    <h1>".htmlspecialchars($this->config['tienda_nombre'])."</h1>
                    <p>".htmlspecialchars($this->config['direccion'])."</p>
                </div>
                <div class='invoice-title'>
                    <h1 style='font-size:40px;margin:0;'>FACTURA</h1>
                    <p><b>Nº: $numFactura</b></p>
                </div>
            </div>
            <div class='blue-bar'>DATOS DEL CLIENTE</div>
            <p><b>Cliente:</b> ".htmlspecialchars($venta['cliente_nombre'])."</p>
            <p><b>Fecha:</b> ".date('d/m/Y', strtotime($venta['fecha']))."</p>
            <table>
                <thead><tr><th>CANT</th><th>UM</th><th>DESCRIPCIÓN</th><th>PRECIO</th><th>TOTAL</th></tr></thead>
                <tbody>$rowsHTML</tbody>
            </table>
            <div style='margin-top:20px; text-align:right;'>
                <div style='display:inline-block; width:200px; background:#D9E1F2; padding:10px; border:1px solid #2F75B5;'>
                    <b>TOTAL CUP: $".number_format($venta['total'], 2)."</b>
                </div>
            </div>
        </body></html>";
        return $html;
    }

    /**
     * Convertir a PDF
     */
    public function generarPDF($idVenta, $rutaSalida = null, $tipo = 'comprobante') {
        if ($tipo === 'ticket') {
            $html = $this->generarTicketHTML($idVenta);
        } elseif ($tipo === 'factura') {
            $html = $this->generarFacturaHTML($idVenta);
        } else {
            $html = $this->generarHTML($idVenta);
        }

        if (!$rutaSalida) {
            $rutaSalida = "/tmp/doc_{$tipo}_$idVenta.pdf";
        }

        $tmpHtml = "/tmp/doc_{$idVenta}_".uniqid().".html";
        file_put_contents($tmpHtml, $html);

        if (file_exists('/usr/bin/wkhtmltopdf')) {
            $args = "--enable-local-file-access --load-error-handling ignore --quiet";
            // Para el formato ticket, ajustamos el tamaño del PDF
            if ($tipo === 'ticket') {
                $args .= " --page-width 80mm --page-height 250mm --margin-top 2mm --margin-bottom 2mm --margin-left 2mm --margin-right 2mm";
            }
            
            $cmd = "export HOME=/tmp && /usr/bin/wkhtmltopdf $args '$tmpHtml' '$rutaSalida' 2>&1";
            exec($cmd, $output, $returnCode);

            if (($returnCode === 0 || $returnCode === 1) && file_exists($rutaSalida) && filesize($rutaSalida) > 500) {
                unlink($tmpHtml);
                return $rutaSalida;
            }
        }

        // Fallback a Chromium si falla wk
        if (file_exists('/usr/bin/chromium-browser')) {
            $cmd = "/usr/bin/chromium-browser --headless --no-sandbox --disable-gpu --print-to-pdf='$rutaSalida' 'file://$tmpHtml' 2>&1";
            exec($cmd, $output, $returnCode);
            if ($returnCode === 0 && file_exists($rutaSalida)) {
                unlink($tmpHtml);
                return $rutaSalida;
            }
        }

        @unlink($tmpHtml);
        throw new Exception("Error al generar PDF ($tipo)");
    }
}
?>
