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
     * Generar HTML del comprobante
     */
    public function generarHTML($idVenta) {
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
        $nombreEmpresa = $this->config['pos_shop_name'] ?? 'Pastelería Renacer';
        $fecha = date('d/m/Y', strtotime($venta['fecha']));
        $noFactura = $venta['uuid'] ?? 'N/A';
        $cliente = $venta['cliente_nombre'] ?? 'Mostrador';
        $total = number_format($venta['total'], 2, ',', '.');

        // Generar tabla de detalles
        $detallesHTML = '';
        $contador = 1;
        foreach ($detalles as $det) {
            if (floatval($det['cantidad']) < 0) continue; // Skip devoluciones
            $subtotal = number_format(floatval($det['cantidad']) * floatval($det['precio']), 2, ',', '.');
            $cantidad = number_format($det['cantidad'], 2, ',', '.');
            $precio = number_format($det['precio'], 2, ',', '.');

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

        // HTML del comprobante
        $metodo = $venta['metodo_pago'] ?? 'Efectivo';
        $hora = date('H:i:s', strtotime($venta['fecha']));

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
            background: #f5f5f5;
            color: #333;
        }
        body {
            padding: 20px;
        }
        .container {
            max-width: 850px;
            margin: 0 auto;
            background: white;
            padding: 50px 40px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.1);
            page-break-after: avoid;
        }
        .acciones {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-bottom: 30px;
        }
        .acciones button {
            padding: 12px 24px;
            background: #333;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: background 0.3s;
        }
        .acciones button:hover {
            background: #555;
        }

        .encabezado {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 40px;
            padding-bottom: 30px;
            border-bottom: 3px solid #333;
        }

        .logo-section {
            flex: 0 0 150px;
            text-align: center;
        }
        .logo-section img {
            width: 100%;
            max-width: 140px;
            height: auto;
        }

        .empresa-section {
            flex: 1;
            text-align: center;
            margin-left: 30px;
        }
        .empresa-section h1 {
            font-size: 32px;
            color: #333;
            margin-bottom: 5px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        .empresa-section .subtitulo {
            font-size: 13px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 2px;
            font-weight: 500;
        }

        .info-section {
            flex: 0 0 200px;
            text-align: right;
            background: #f9f9f9;
            padding: 15px;
            border-radius: 5px;
        }
        .info-section .item {
            margin-bottom: 12px;
            font-size: 13px;
        }
        .info-section .label {
            color: #999;
            text-transform: uppercase;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        .info-section .valor {
            color: #333;
            font-size: 15px;
            font-weight: 600;
            margin-top: 3px;
        }

        .cliente-seccion {
            margin-bottom: 30px;
            padding: 15px 20px;
            background: #fafafa;
            border-left: 4px solid #333;
            border-radius: 3px;
        }
        .cliente-seccion h3 {
            font-size: 11px;
            color: #999;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        .cliente-seccion p {
            font-size: 15px;
            color: #333;
            margin-bottom: 4px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
            font-size: 14px;
        }
        thead {
            background: #333;
            color: white;
        }
        thead th {
            padding: 14px 12px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: none;
        }
        tbody tr {
            border-bottom: 1px solid #e0e0e0;
        }
        tbody tr:last-child {
            border-bottom: 2px solid #333;
        }
        tbody td {
            padding: 14px 12px;
            font-size: 13px;
        }
        tbody td:nth-child(3),
        tbody td:nth-child(4),
        tbody td:nth-child(5) {
            text-align: right;
        }

        .totales {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 30px;
        }
        .totales-box {
            width: 280px;
        }
        .total-fila {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 13px;
            border-bottom: 1px solid #ddd;
        }
        .total-fila.grande {
            background: #f5f5f5;
            padding: 12px;
            margin: 0 -12px;
            font-size: 16px;
            font-weight: 700;
            color: #333;
            border-top: 2px solid #333;
            border-bottom: 2px solid #333;
        }

        .pie {
            text-align: center;
            color: #999;
            font-size: 12px;
            border-top: 1px solid #ddd;
            padding-top: 20px;
            margin-top: 20px;
        }
        .pie p {
            margin-bottom: 5px;
            line-height: 1.5;
        }

        @media print {
            body {
                background: white;
                padding: 0;
            }
            .container {
                box-shadow: none;
                max-width: 100%;
                margin: 0;
                padding: 40px;
            }
            .acciones {
                display: none;
            }
            @page {
                size: A4;
                margin: 0;
            }
        }
    </style>
</head>
<body>
    <div class=\"container\">
        <div class=\"acciones\">
            <button onclick=\"window.print()\" title=\"Imprimir comprobante\">🖨️ Imprimir</button>
            <button onclick=\"location.href='?id=$idVenta&format=pdf'\" title=\"Descargar en PDF\">📥 Descargar PDF</button>
            <button onclick=\"location.href='pos.php'\" title=\"Volver al POS\">🏠 Volver al POS</button>
        </div>

        <div class=\"encabezado\">
            <div class=\"logo-section\">
                <img src=\"assets/img/logo_comprobante.svg\" alt=\"Logo $nombreEmpresa\">
            </div>
            <div class=\"empresa-section\">
                <h1>COMPROBANTE</h1>
                <p class=\"subtitulo\">de Venta</p>
            </div>
            <div class=\"info-section\">
                <div class=\"item\">
                    <div class=\"label\">Comprobante</div>
                    <div class=\"valor\">$noFactura</div>
                </div>
                <div class=\"item\">
                    <div class=\"label\">Fecha</div>
                    <div class=\"valor\">$fecha</div>
                </div>
                <div class=\"item\">
                    <div class=\"label\">Hora</div>
                    <div class=\"valor\">$hora</div>
                </div>
            </div>
        </div>

        <div class=\"cliente-seccion\">
            <h3>Cliente</h3>
            <p><strong>$cliente</strong></p>
            <p style=\"font-size: 13px; color: #666;\">$nombreEmpresa</p>
        </div>

        <table>
            <thead>
                <tr>
                    <th style=\"width: 50px;\">No.</th>
                    <th>Descripción</th>
                    <th style=\"width: 100px;\">Cantidad</th>
                    <th style=\"width: 90px;\">Precio Unit.</th>
                    <th style=\"width: 90px;\">Total</th>
                </tr>
            </thead>
            <tbody>
                $detallesHTML
            </tbody>
        </table>

        <div class=\"totales\">
            <div class=\"totales-box\">
                <div class=\"total-fila grande\">
                    <span>TOTAL</span>
                    <span>\$$total</span>
                </div>
                <div class=\"total-fila\" style=\"margin-top: 8px; font-size: 12px;\">
                    <span>Método: $metodo</span>
                </div>
            </div>
        </div>

        <div class=\"pie\">
            <p><strong>✓ Gracias por su compra</strong></p>
            <p>Este comprobante es válido como constancia de pago</p>
            <p>Generado: " . date('d/m/Y H:i:s') . "</p>
        </div>
    </div>

    <script>
        // Descarga usando html2pdf (si está disponible en el proyecto)
        async function descargarPDF() {
            alert('Usa el botón \"Descargar PDF\" o imprime a PDF desde tu navegador (Ctrl+P)');
        }
    </script>
</body>
</html>";

        return $html;
    }

    /**
     * Convertir HTML a PDF usando Chromium
     */
    public function generarPDF($idVenta, $rutaSalida = null) {
        $html = $this->generarHTML($idVenta);

        if (!$rutaSalida) {
            $rutaSalida = "/tmp/comprobante_$idVenta.pdf";
        }

        // Guardar HTML temporalmente
        $tmpHtml = "/tmp/comprobante_$idVenta.html";
        file_put_contents($tmpHtml, $html);

        // Convertir a PDF con Chromium
        $cmd = "chromium-browser --headless --disable-gpu --print-to-pdf='$rutaSalida' 'file://$tmpHtml' 2>/dev/null";
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            // Fallback: usar wkhtmltopdf si está disponible
            $cmd = "wkhtmltopdf '$tmpHtml' '$rutaSalida' 2>/dev/null";
            exec($cmd, $output, $returnCode);
        }

        // Limpiar archivo temporal
        unlink($tmpHtml);

        if ($returnCode === 0 && file_exists($rutaSalida)) {
            return $rutaSalida;
        }

        throw new Exception("No se pudo generar el PDF. Verifica que tengas herramientas de conversión instaladas.");
    }
}
?>
