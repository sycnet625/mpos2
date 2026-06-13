<?php
// ticket_to_invoice.php
// Renderiza un ticket de venta con el formato de factura (invoice_print.php).
// Cada render/impresion registra o actualiza una factura ligada al ticket POS.
header('Content-Type: text/html; charset=utf-8');
ini_set('display_errors', 0);
require_once 'db.php';
require_once 'config_loader.php';

$idVenta = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($idVenta <= 0) die('ID de venta inválido.');

$duplex = !empty($_GET['duplex']); // modo 2 facturas por hoja
$pdfExport = isset($_GET['pdf_export']) && $_GET['pdf_export'] === '1';
$priceView = strtolower(trim((string)($_GET['price_view'] ?? 'venta')));
if (!in_array($priceView, ['venta', 'mayorista'], true)) {
    $priceView = 'venta';
}
$markupPct = isset($_GET['markup_pct']) ? round(floatval($_GET['markup_pct']), 2) : 0.0;
if ($markupPct < -99.99) $markupPct = -99.99;
$markupFactor = 1 + ($markupPct / 100);
$autoPrint = isset($_GET['autoprint']) && $_GET['autoprint'] === '1';
$ecoMode = !empty($_GET['eco']) && $_GET['eco'] === '1';
if ($ecoMode) {
    $duplex = false;
}

// ── Cabecera de venta ─────────────────────────────────────────────────────────
$stmtH = $pdo->prepare("SELECT * FROM ventas_cabecera WHERE id = ?");
$stmtH->execute([$idVenta]);
$venta = $stmtH->fetch(PDO::FETCH_ASSOC);
if (!$venta) die('Venta no encontrada.');

// ── Ítems ─────────────────────────────────────────────────────────────────────
$hasNotaCocina = false;
try {
    $stmtCol = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ventas_detalle' AND COLUMN_NAME = 'nota_cocina'");
    $stmtCol->execute();
    $hasNotaCocina = (bool)$stmtCol->fetchColumn();
} catch (Throwable $e) {}

$stmtD = $pdo->prepare("
    SELECT d.cantidad, d.precio" . ($hasNotaCocina ? ", d.nota_cocina" : ", NULL AS nota_cocina") . ",
           COALESCE(p.precio_mayorista, ps.precio_mayorista, d.precio) AS precio_mayorista_visual,
           COALESCE(p.nombre, CONCAT('Artículo: ', d.id_producto)) AS descripcion,
           COALESCE(p.unidad_medida, 'UND') AS um
    FROM ventas_detalle d
    LEFT JOIN productos p ON d.id_producto = p.codigo
    LEFT JOIN productos_precios_sucursal ps
           ON ps.codigo_producto = d.id_producto AND ps.id_sucursal = ?
    WHERE d.id_venta_cabecera = ?
    ORDER BY d.id
");
$stmtD->execute([(int)($venta['id_sucursal'] ?? 0), $idVenta]);
$items = $stmtD->fetchAll(PDO::FETCH_ASSOC);

// ── Cajero ────────────────────────────────────────────────────────────────────
$cajero = 'Administrador';
if (!empty($venta['id_caja'])) {
    $s = $pdo->prepare("SELECT nombre_cajero FROM caja_sesiones WHERE id = ?");
    $s->execute([$venta['id_caja']]);
    $cajero = $s->fetchColumn() ?: 'Administrador';
}

// ── Datos del cliente desde CRM ──────────────────────────────────────────────
$clienteCrm = [];
if (!empty($venta['id_cliente']) && intval($venta['id_cliente']) > 0) {
    try {
        $sc = $pdo->prepare("SELECT nombre, telefono, telefono_principal, direccion, direccion_principal, nit_ci, ruc, email FROM clientes WHERE id = ? LIMIT 1");
        $sc->execute([intval($venta['id_cliente'])]);
        $clienteCrm = $sc->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
}
// Prioridad: CRM > datos en la venta
$cliNombre    = $clienteCrm['nombre']              ?? $venta['cliente_nombre']    ?? '';
$cliTelefono  = $clienteCrm['telefono_principal']  ?? $clienteCrm['telefono']     ?? $venta['cliente_telefono'] ?? '';
$cliDireccion = $clienteCrm['direccion_principal'] ?? $clienteCrm['direccion']    ?? $venta['cliente_direccion'] ?? '';
$cliNit       = $clienteCrm['nit_ci']              ?? $clienteCrm['ruc']          ?? '';
$cliEmail     = $clienteCrm['email']               ?? '';

// ── Cálculos ──────────────────────────────────────────────────────────────────
$subtotalOriginal = array_sum(array_map(fn($i) => $i['cantidad'] * $i['precio'], $items));
$totalCantidad = array_sum(array_column($items, 'cantidad'));
$totalOriginal = floatval($venta['total']);
$costoEnvio    = round($totalOriginal - $subtotalOriginal, 2);
// Mostrar manipulación/envío siempre que la diferencia entre total y subtotal
// sea positiva, sin importar el tipo_servicio (puede haber recargo en mostrador).
$hayEnvio   = $costoEnvio > 0.01;
$subtotalDisplay = 0.0;
foreach ($items as &$item) {
    $precioBase = floatval($item['precio']);
    $precioMayorista = floatval($item['precio_mayorista_visual'] ?? $precioBase);
    $precioDisplay = $priceView === 'mayorista'
        ? round($precioMayorista, 2)
        : round($precioBase * $markupFactor, 2);
    $item['precio_display'] = $precioDisplay;
    $item['subtotal_display'] = round(floatval($item['cantidad']) * $precioDisplay, 2);
    $subtotalDisplay += $item['subtotal_display'];
}
unset($item);
$totalDisplay = round($subtotalDisplay + $costoEnvio, 2);

// Número de factura: fecha del ticket + ID de venta
$numFactura = date('Ymd', strtotime($venta['fecha'])) . str_pad($idVenta, 3, '0', STR_PAD_LEFT);

if (!function_exists('ticket_invoice_item_description')) {
    function ticket_invoice_item_description(array $item): string {
        $descripcion = trim((string)($item['descripcion'] ?? ''));
        $notaCocina = trim((string)($item['nota_cocina'] ?? ''));
        if ($notaCocina !== '') {
            $descripcion .= ' (' . $notaCocina . ')';
        }
        $descripcion = $descripcion !== '' ? $descripcion : 'Producto';
        return substr($descripcion, 0, 200);
    }
}

if (!function_exists('ticket_invoice_register')) {
    function ticket_invoice_register(PDO $pdo, array $venta, array $items, array $data): int {
        $idVenta = (int)$data['idVenta'];
        $stmt = $pdo->prepare("SELECT id FROM facturas WHERE id_ticket_origen = ? ORDER BY id ASC LIMIT 1");
        $stmt->execute([$idVenta]);
        $facturaId = (int)($stmt->fetchColumn() ?: 0);

        $estadoPago = (($venta['estado_pago'] ?? '') === 'confirmado') ? 'PAGADA' : 'PENDIENTE';
        $fechaPago = $estadoPago === 'PAGADA' ? (($venta['fecha_pago'] ?? '') ?: date('Y-m-d H:i:s', strtotime((string)$venta['fecha']))) : null;
        $creadoPor = $data['cajero'] ?: 'POS';
        $notas = trim((string)($venta['notas'] ?? ''));
        $vehiculo = trim((string)($venta['vehiculo'] ?? ''));

        $pdo->beginTransaction();
        try {
            if ($facturaId > 0) {
                $sql = "UPDATE facturas
                        SET numero_factura = ?, fecha_emision = ?, cliente_nombre = ?, cliente_direccion = ?,
                            cliente_telefono = ?, mensajero_nombre = ?, vehiculo = ?, subtotal = ?, total = ?,
                            costo_envio = ?, notas = ?, estado_pago = ?, metodo_pago = ?, fecha_pago = ?, tipo = 'FACTURA'
                        WHERE id = ?";
                $pdo->prepare($sql)->execute([
                    $data['numFactura'],
                    $venta['fecha'],
                    $data['cliNombre'],
                    $data['cliDireccion'],
                    $data['cliTelefono'],
                    $venta['mensajero_nombre'] ?? '',
                    $vehiculo,
                    $data['subtotalDisplay'],
                    $data['totalDisplay'],
                    max(0, (float)$data['costoEnvio']),
                    $notas,
                    $estadoPago,
                    $venta['metodo_pago'] ?? '',
                    $fechaPago,
                    $facturaId
                ]);
                $pdo->prepare("DELETE FROM facturas_detalle WHERE id_factura = ?")->execute([$facturaId]);
            } else {
                $sql = "INSERT INTO facturas
                        (numero_factura, fecha_emision, id_ticket_origen, cliente_nombre, cliente_direccion,
                         cliente_telefono, mensajero_nombre, vehiculo, subtotal, total, creado_por, estado,
                         estado_pago, metodo_pago, fecha_pago, costo_envio, notas, tipo)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVA', ?, ?, ?, ?, ?, 'FACTURA')";
                $pdo->prepare($sql)->execute([
                    $data['numFactura'],
                    $venta['fecha'],
                    $idVenta,
                    $data['cliNombre'],
                    $data['cliDireccion'],
                    $data['cliTelefono'],
                    $venta['mensajero_nombre'] ?? '',
                    $vehiculo,
                    $data['subtotalDisplay'],
                    $data['totalDisplay'],
                    $creadoPor,
                    $estadoPago,
                    $venta['metodo_pago'] ?? '',
                    $fechaPago,
                    max(0, (float)$data['costoEnvio']),
                    $notas
                ]);
                $facturaId = (int)$pdo->lastInsertId();
            }

            $stmtDet = $pdo->prepare("INSERT INTO facturas_detalle
                (id_factura, descripcion, unidad_medida, cantidad, precio_unitario, importe)
                VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($items as $item) {
                $cantidad = (float)($item['cantidad'] ?? 0);
                if ($cantidad == 0.0) continue;
                $stmtDet->execute([
                    $facturaId,
                    ticket_invoice_item_description($item),
                    $item['um'] ?? 'UND',
                    $cantidad,
                    (float)($item['precio_display'] ?? $item['precio'] ?? 0),
                    (float)($item['subtotal_display'] ?? ($cantidad * (float)($item['precio'] ?? 0)))
                ]);
            }

            $pdo->commit();
            return $facturaId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}

$registeredFacturaId = 0;
$autoRegistroFacturaPos = array_key_exists('factura_pos_auto_registro', $config)
    ? !empty($config['factura_pos_auto_registro'])
    : true;
if ($autoRegistroFacturaPos) {
    try {
        $registeredFacturaId = ticket_invoice_register($pdo, $venta, $items, [
            'idVenta' => $idVenta,
            'numFactura' => $numFactura,
            'cliNombre' => $cliNombre ?: 'Mostrador',
            'cliDireccion' => $cliDireccion,
            'cliTelefono' => $cliTelefono,
            'subtotalDisplay' => $subtotalDisplay,
            'totalDisplay' => $totalDisplay,
            'costoEnvio' => $costoEnvio,
            'cajero' => $cajero
        ]);
    } catch (Throwable $e) {
        error_log('No se pudo registrar factura POS ticket #' . $idVenta . ': ' . $e->getMessage());
    }
}

// Filas vacías para mantener la cuadrícula de 13 líneas (solo modo normal)
$totalRows  = 13;
$emptyRows  = max(0, $totalRows - count($items));
if ($ecoMode) {
    $emptyRows = 0;
}

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

// Logo: 1. Banner de sucursal de la venta, 2. Logo empresa en config
$logoUrl = '';
if (!empty($venta['id_sucursal'])) {
    try {
        $stmtLogo = $pdo->prepare("SELECT imagen_banner FROM sucursales WHERE id = ? LIMIT 1");
        $stmtLogo->execute([$venta['id_sucursal']]);
        $logoRel = $stmtLogo->fetchColumn();
        if ($logoRel && file_exists(__DIR__ . '/' . $logoRel)) {
            $logoUrl = '/' . ltrim($logoRel, '/');
        }
    } catch (Throwable $e) {}
}
if (empty($logoUrl)) {
    $logoRel2 = $config['marca_empresa_logo'] ?? $config['ticket_logo'] ?? '';
    if ($logoRel2 && file_exists(__DIR__ . '/' . $logoRel2)) {
        $logoUrl = '/' . ltrim($logoRel2, '/');
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<title><?= $ecoMode ? 'Factura ECO' : 'Factura' ?> – Ticket #<?= $idVenta ?><?= $duplex ? ' (2x hoja)' : '' ?></title>
<style>
    body {
        font-family: "Calibri", Arial, sans-serif;
        font-size: 11px;
        margin: 0; padding: 0;
        background-color: #525659;
        color: #000;
    }

    body.eco-mode {
        background: #fff;
        color: #111;
        font-family: Arial, Helvetica, sans-serif;
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
    .btn-p  { background: #2F75B5; color: #fff; }
    .btn-p:hover  { background: #1e5687; }
    .btn-p2 { background: #16a34a; color: #fff; }
    .btn-p2:hover { background: #15803d; }
    .btn-c  { background: #475569; color: #fff; }
    .price-note { background:#fff3cd; border:1px dashed #856404; color:#664d03; padding:8px 10px; border-radius:8px; margin:10px 0 14px; font-size:11px; }

    /* ══ MODO NORMAL ═══════════════════════════════════════════════════════════ */
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

    body.eco-mode .toolbar,
    body.eco-mode .price-note {
        display: none !important;
    }
    body.eco-mode .page-container {
        width: 210mm;
        min-height: 297mm;
        background: #fff;
        margin: 0 auto;
        padding: 12mm 14mm;
        box-shadow: none;
        border: none;
    }
    body.eco-mode .header-table,
    body.eco-mode .main-table,
    body.eco-mode .totals-table {
        color: #111;
    }
    body.eco-mode .company-name,
    body.eco-mode .invoice-title,
    body.eco-mode .blue-header,
    body.eco-mode .info-cell,
    body.eco-mode .total-final,
    body.eco-mode .signature-line,
    body.eco-mode .origen-badge {
        color: #111;
        background: transparent;
        border-color: #d1d5db;
        box-shadow: none;
    }
    body.eco-mode .company-name,
    body.eco-mode .invoice-title {
        color: #111;
    }
    body.eco-mode .invoice-title {
        font-size: 22px;
        text-align: right;
        letter-spacing: .02em;
    }
    body.eco-mode .company-name {
        font-size: 17px;
        text-transform: uppercase;
    }
    body.eco-mode .blue-header {
        padding: 2px 0;
        text-align: left;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .03em;
        border-bottom: 1px solid #d1d5db;
    }
    body.eco-mode .info-cell {
        padding: 2px 0;
        border-bottom: 1px solid #e5e7eb;
        font-weight: 600;
    }
    body.eco-mode .main-table {
        margin-top: 8px;
    }
    body.eco-mode .main-table th {
        background: transparent;
        color: #111;
        border: none;
        border-bottom: 1px solid #cbd5e1;
        font-size: 10px;
        font-weight: 700;
        padding: 3px 0 4px;
        text-align: left;
    }
    body.eco-mode .main-table td {
        border: none;
        border-bottom: none;
        padding: 2px 0;
        font-size: 10px;
        height: auto;
    }
    body.eco-mode .main-table tbody tr + tr td {
        border-top: none;
    }
    body.eco-mode .main-table tfoot tr,
    body.eco-mode .main-table tfoot td {
        border: none;
    }
    body.eco-mode .totals-table td {
        padding: 3px 0;
    }
    body.eco-mode .total-final {
        background: transparent;
        font-size: 12px;
        border-top: 1px solid #cbd5e1;
    }
    body.eco-mode .signature-line {
        margin-top: 14px;
        padding-top: 4px;
        background: transparent;
        border-top: 1px solid #d1d5db;
        color: #111;
    }
    body.eco-mode .origen-badge {
        padding: 1px 6px;
        border-radius: 999px;
        font-size: 9px;
    }

    /* ══ MODO DUPLEX (2 facturas por A4) ═══════════════════════════════════════ */
    .duplex-page {
        width: 21cm;
        height: 29.7cm;          /* A4 exacto */
        background: white;
        margin: 56px auto 30px;
        box-shadow: 0 0 15px rgba(0,0,0,0.5);
        box-sizing: border-box;
        display: flex;
        flex-direction: column;
    }
    .duplex-half {
        width: 100%;
        height: 148.5mm;         /* exactamente mitad de A4 */
        box-sizing: border-box;
        padding: 0.55cm 0.8cm 0.4cm;
        font-size: 8.5px;
        font-family: "Calibri", Arial, sans-serif;
        color: #000;
        overflow: hidden;
        flex-shrink: 0;
    }
    .duplex-cut {
        /* línea de corte en el centro exacto del A4 */
        height: 0;
        border-top: 2px dashed #999;
        text-align: center;
        font-size: 8px;
        color: #999;
        line-height: 0;
        overflow: visible;
        flex-shrink: 0;
    }
    .duplex-cut span {
        background: white;
        padding: 0 6px;
        position: relative;
        top: -6px;
    }
    /* Compactar elementos en duplex */
    .duplex-half .company-name  { font-size: 13px; font-weight: bold; color: #2F75B5; text-transform: uppercase; }
    .duplex-half .invoice-title { font-size: 18px; font-weight: bold; color: #2F75B5; text-align: right; }
    .duplex-half .blue-header   { background-color: #2F75B5; color: white; font-weight: bold; text-align: center;
                                   padding: 2px 4px; text-transform: uppercase; font-size: 8px; }
    .duplex-half .info-cell     { text-align: center; font-weight: bold; padding: 2px 4px; border-bottom: 1px solid #ccc; font-size: 8px; }
    .duplex-half .main-table    { width: 100%; border-collapse: collapse; margin-top: 4px; }
    .duplex-half .main-table th { background-color: #2F75B5; color: white; font-weight: bold; padding: 2px 3px;
                                   border: 1px solid #aaa; text-align: center; font-size: 7.5px; }
    .duplex-half .main-table td { border: 1px solid #aaa; padding: 1px 3px; font-size: 8px; }
    .duplex-half .totals-table  { width: 100%; border-collapse: collapse; }
    .duplex-half .totals-table td { padding: 1px 4px; border: none; font-size: 8px; }
    .duplex-half .total-final   { background-color: #D9E1F2; font-size: 9px; font-weight: bold; border-top: 1px solid #2F75B5; }
    .duplex-half .signature-line { margin-top: 6px; border-top: 2px solid #2F75B5; padding-top: 2px;
                                    color: white; background-color: #2F75B5; text-align: center; font-weight: bold; font-size: 7.5px; }
    .duplex-half .origen-badge  { display: inline-block; background: #f0f4ff; border: 1px solid #c7d2fe;
                                   color: #3730a3; padding: 1px 4px; border-radius: 3px; font-size: 7px; }
    .duplex-half .section-gap   { margin-bottom: 4px; }

    @media print {
        .toolbar { display: none !important; }
        body { background: white; margin: 0; }

        /* Normal */
        .page-container { width: 100%; margin: 0; padding: 0; box-shadow: none; border: none; min-height: auto; }
        <?php if ($ecoMode): ?>
        @page { size: A4 portrait; margin: 8mm 10mm; }
        <?php else: ?>
        @page { size: letter; margin: 1cm; }
        <?php endif; ?>

        <?php if ($duplex): ?>
        /* Duplex */
        .duplex-page { width: 100%; margin: 0; box-shadow: none; }
        @page { size: A4 portrait; margin: 0; }
        <?php endif; ?>

        .blue-header, .main-table th, .signature-line, .total-final,
        .duplex-half .blue-header, .duplex-half .main-table th,
        .duplex-half .signature-line, .duplex-half .total-final {
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
    }
</style>
</head>
<body<?= $ecoMode ? ' class="eco-mode"' : '' ?>>

<?php if (!$pdfExport): ?>
<div class="toolbar">
    <span style="opacity:.7;">Ticket #<?= $idVenta ?> → Vista Factura</span>
    <button class="btn-p" onclick="window.print()">🖨️ IMPRIMIR / PDF</button>
    <?php if (!$duplex): ?>
    <button class="btn-p2" onclick="window.location.href='?id=<?= $idVenta ?>&duplex=1'">🖨️ 2 FACTURAS POR HOJA</button>
    <?php else: ?>
    <button class="btn-p2" onclick="window.location.href='?id=<?= $idVenta ?>'">📄 VISTA NORMAL</button>
    <?php endif; ?>
    <button class="btn-c" onclick="window.close()">✕ Cerrar</button>
</div>
<?php endif; ?>

<?php if ($duplex): ?>
<!-- ══════════════════════ MODO DUPLEX ══════════════════════════════════════ -->
<?php
// Helper: renderiza una factura compacta (media A4)
function render_half_invoice(array $venta, array $items, array $cfg): void {
    $subtotal      = array_sum(array_map(fn($i) => floatval($i['subtotal_display'] ?? ($i['cantidad'] * $i['precio'])), $items));
    $totalCantidad = array_sum(array_column($items, 'cantidad'));
    $total         = floatval($cfg['totalDisplay'] ?? $venta['total']);
    $costoEnvio    = round($cfg['costoEnvio'] ?? ($total - $subtotal), 2);
    $hayEnvio      = $costoEnvio > 0.01;
    $numFactura    = date('Ymd', strtotime($venta['fecha'])) . str_pad($venta['id'] ?? 0, 3, '0', STR_PAD_LEFT);
    $terminos      = (($venta['estado_pago'] ?? '') === 'confirmado')
        ? 'PAGADO (' . htmlspecialchars($venta['metodo_pago'] ?? '') . ')'
        : 'Pagadero al recibirse';
    $empresa      = $cfg['empresa'];
    $direccion    = $cfg['direccion'];
    $telefono     = $cfg['telefono'];
    $nit          = $cfg['nit'];
    $web          = $cfg['web'];
    $cuenta       = $cfg['cuenta'];
    $banco        = $cfg['banco'];
    $email        = $cfg['email'];
    $logoUrl      = $cfg['logoUrl'];
    $cajero       = $cfg['cajero'];
    $idVenta      = $cfg['idVenta'];
    $sysName      = $cfg['sysName'];
    $cliNombre    = $cfg['cliNombre'];
    $cliTelefono  = $cfg['cliTelefono'];
    $cliDireccion = $cfg['cliDireccion'];
    $cliNit       = $cfg['cliNit'];
    $cliEmail     = $cfg['cliEmail'];
?>
<div class="duplex-half">

    <!-- Encabezado -->
    <table width="100%" style="margin-bottom:4px;">
        <tr>
            <td width="58%" valign="top">
                <?php if ($logoUrl && !$ecoMode): ?>
                <img src="<?= htmlspecialchars($logoUrl) ?>?v=<?= filemtime(__DIR__ . '/' . ltrim($logoUrl, '/')) ?: 1 ?>"
                     style="max-width:100px; max-height:36px; display:block; margin-bottom:2px; object-fit:contain;" alt="Logo">
                <?php endif; ?>
                <div class="company-name"><?= htmlspecialchars($empresa) ?></div>
                <?php if ($direccion): ?><div style="font-size:7.5px;"><?= htmlspecialchars($direccion) ?></div><?php endif; ?>
                <?php if ($telefono): ?><div style="font-size:7.5px;">Tel: <?= htmlspecialchars($telefono) ?></div><?php endif; ?>
                <?php if ($web): ?><div style="font-size:7.5px;"><?= htmlspecialchars($web) ?></div><?php endif; ?>
                <?php if ($nit): ?><div style="font-size:7.5px;">NIT: <?= htmlspecialchars($nit) ?></div><?php endif; ?>
            </td>
            <td width="42%" valign="top" align="right">
                <div class="invoice-title">FACTURA</div>
                <div style="font-size:7px; color:#666; text-align:right;">
                    Ticket #<?= str_pad($idVenta, 6, '0', STR_PAD_LEFT) ?>
                    &nbsp;<span class="origen-badge"><?= htmlspecialchars($venta['canal_origen'] ?? 'POS') ?></span>
                </div>
                <table width="100%" cellspacing="0" style="margin-top:3px;">
                    <tr>
                        <td class="blue-header">FACTURA #</td>
                        <td class="blue-header">FECHA</td>
                    </tr>
                    <tr>
                        <td class="info-cell"><?= htmlspecialchars($numFactura) ?></td>
                        <td class="info-cell"><?= date('d/m/Y', strtotime($venta['fecha'])) ?></td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <!-- Cliente + Términos -->
    <table width="100%" cellspacing="0" style="margin-bottom:4px;" class="section-gap">
        <tr>
            <td width="45%" valign="top">
                <div class="blue-header" style="text-align:left; padding-left:6px;">FACTURAR A</div>
                <div style="padding:2px 4px; font-weight:bold; font-size:8px; line-height:1.5;">
                    <?= htmlspecialchars($cliNombre ?: 'Mostrador') ?>
                    <?php if ($cliDireccion): ?>
                    <br><span style="font-weight:normal;"><?= htmlspecialchars($cliDireccion) ?></span>
                    <?php endif; ?>
                    <?php if ($cliTelefono): ?>
                    <br><span style="font-weight:normal;">Tel: <?= htmlspecialchars($cliTelefono) ?></span>
                    <?php endif; ?>
                    <?php if ($cliNit): ?>
                    <br><span style="font-weight:normal;">NIT/CI: <?= htmlspecialchars($cliNit) ?></span>
                    <?php endif; ?>
                    <?php if ($cliEmail): ?>
                    <br><span style="font-weight:normal;"><?= htmlspecialchars($cliEmail) ?></span>
                    <?php endif; ?>
                </div>
            </td>
            <td width="5%"></td>
            <td width="50%" valign="top">
                <table width="100%" cellspacing="0">
                    <tr>
                        <td class="blue-header">TRANSPORTADO POR</td>
                        <td class="blue-header">TÉRMINOS</td>
                    </tr>
                    <tr>
                        <td class="info-cell" style="text-align:left;"><?= htmlspecialchars($venta['mensajero_nombre'] ?? '') ?></td>
                        <td class="info-cell"><?= $terminos ?></td>
                    </tr>
                </table>
                <div class="signature-line">FIRMA DE CONFORMIDAD DEL CLIENTE</div>
            </td>
        </tr>
    </table>

    <!-- Tabla de ítems (sin filas vacías) -->
    <table class="main-table">
        <thead>
            <tr>
                <th class="left">DESCRIPCIÓN</th>
                <th width="5%">UM</th>
                <th width="8%">CANT</th>
                <th width="14%">P.UNIT</th>
                <th width="14%">IMPORTE</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $duplex_rows = 4;
            foreach ($items as $it):
                $importe = floatval($it['subtotal_display'] ?? ($it['cantidad'] * $it['precio']));
                $descripcion = trim((string)($it['descripcion'] ?? ''));
                $notaCocina  = trim((string)($it['nota_cocina'] ?? ''));
                if ($notaCocina !== '') {
                    $descripcion .= ' (' . $notaCocina . ')';
                }
            ?>
            <tr>
                <td class="left"><?= htmlspecialchars($descripcion) ?></td>
                <td class="center"><?= htmlspecialchars($it['um']) ?></td>
                <td class="center"><?= rtrim(rtrim(number_format($it['cantidad'], 2), '0'), '.') ?></td>
                <td class="right">$<?= number_format($it['precio_display'] ?? $it['precio'], 2) ?></td>
                <td class="right">$<?= number_format($importe, 2) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php for ($k = count($items); $k < $duplex_rows; $k++): ?>
            <tr style="height:14px;">
                <td>&nbsp;</td><td></td><td></td><td></td><td></td>
            </tr>
            <?php endfor; ?>
            <tr>
                <td colspan="2" class="right" style="border:none; font-weight:bold; font-size:7.5px;">TOTAL&gt;&gt;</td>
                <td class="center" style="font-weight:bold;"><?= number_format($totalCantidad, 2) ?></td>
                <td colspan="2" style="border:none;"></td>
            </tr>
        </tbody>
    </table>

    <!-- Totales + Datos bancarios -->
    <table width="100%" style="margin-top:4px;">
        <tr>
            <td width="55%" valign="top" style="font-size:8px;">
                <div style="font-style:italic; color:#2F75B5; font-weight:bold; margin-bottom:2px;">¡Gracias por su compra!</div>
                <?php if ($cuenta): ?>
                <div>Transferencia bancaria MN: <b><?= htmlspecialchars($cuenta) ?></b></div>
                <?php if ($banco): ?><div style="font-size:7.5px;"><?= htmlspecialchars($banco) ?></div><?php endif; ?>
                <?php endif; ?>
                <?php if (!empty($venta['notas'])): ?>
                <div style="font-size:7.5px;"><b>Notas:</b> <?= htmlspecialchars($venta['notas']) ?></div>
                <?php endif; ?>
            </td>
            <td width="45%" valign="top">
                <table class="totals-table">
                    <tr style="background-color:#D9E1F2;">
                        <td class="left">SUBTOTAL</td>
                        <td class="right">$<?= number_format($subtotal, 2) ?></td>
                    </tr>
                    <?php if ($hayEnvio): ?>
                    <tr style="background-color:#D9E1F2;">
                        <td class="left">Envío</td>
                        <td class="right">$<?= number_format($costoEnvio, 2) ?></td>
                    </tr>
                    <?php else: ?>
                    <tr style="background-color:#D9E1F2;">
                        <td class="left">Envío</td>
                        <td class="right">-</td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td class="left total-final" style="color:#2F75B5;">TOTAL</td>
                        <td class="right total-final">$<?= number_format($total, 2) ?></td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <!-- Pie -->
    <div style="margin-top:4px; border-top:1px solid #ccc; padding-top:2px; font-size:7px; color:#555;">
        Emitido por: <i><?= htmlspecialchars($cajero) ?></i>
        <?php if ($email): ?> · <?= htmlspecialchars($email) ?><?php endif; ?>
        · <?= htmlspecialchars($sysName) ?> v3.0 · palweb.net
    </div>

</div>
<?php } // end render_half_invoice

$cfg = [
    'empresa'      => $empresa,
    'direccion'    => $direccion,
    'telefono'     => $telefono,
    'nit'          => $nit,
    'web'          => $web,
    'cuenta'       => $cuenta,
    'banco'        => $banco,
    'email'        => $email,
    'logoUrl'      => $logoUrl,
    'cajero'       => $cajero,
    'idVenta'      => $idVenta,
    'sysName'      => config_loader_system_name(),
    'cliNombre'    => $cliNombre,
    'cliTelefono'  => $cliTelefono,
    'cliDireccion' => $cliDireccion,
    'cliNit'       => $cliNit,
    'cliEmail'     => $cliEmail,
    'totalDisplay' => $totalDisplay,
    'costoEnvio'   => $costoEnvio,
];
?>
<div class="duplex-page">
    <?php render_half_invoice($venta, $items, $cfg); ?>
    <div class="duplex-cut"><span>✂ &nbsp; CORTAR POR AQUÍ &nbsp; ✂</span></div>
    <?php render_half_invoice($venta, $items, $cfg); ?>
</div>

<?php else: ?>
<!-- ══════════════════════ MODO NORMAL ══════════════════════════════════════ -->
<div class="page-container">

    <!-- ── Encabezado ─────────────────────────────────────────────────────── -->
    <table class="header-table">
        <tbody>
            <tr>
                <td width="60%" valign="top">
                    <?php if ($logoUrl && !$ecoMode): ?>
                    <img src="<?= htmlspecialchars($logoUrl) ?>?v=<?= filemtime(__DIR__ . '/' . ltrim($logoUrl, '/')) ?: 1 ?>"
                         style="max-width:180px; max-height:70px; display:block; margin-bottom:6px; object-fit:contain;" alt="Logo">
                    <?php endif; ?>
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
                    <div style="padding:5px; font-weight:bold; line-height:1.6;">
                        <?= htmlspecialchars($cliNombre ?: 'Mostrador') ?>
                        <?php if ($cliDireccion): ?>
                        <br><span style="font-weight:normal; font-size:10px;"><?= htmlspecialchars($cliDireccion) ?></span>
                        <?php endif; ?>
                        <?php if ($cliTelefono): ?>
                        <br><span style="font-weight:normal; font-size:10px;">Tel: <?= htmlspecialchars($cliTelefono) ?></span>
                        <?php endif; ?>
                        <?php if ($cliNit): ?>
                        <br><span style="font-weight:normal; font-size:10px;">NIT/CI: <?= htmlspecialchars($cliNit) ?></span>
                        <?php endif; ?>
                        <?php if ($cliEmail): ?>
                        <br><span style="font-weight:normal; font-size:10px;"><?= htmlspecialchars($cliEmail) ?></span>
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
                $importe = $it['subtotal_display'] ?? ($it['cantidad'] * $it['precio']);
                $descripcion = trim((string)($it['descripcion'] ?? ''));
                $notaCocina  = trim((string)($it['nota_cocina'] ?? ''));
                if ($notaCocina !== '') {
                    $descripcion .= ' (' . $notaCocina . ')';
                }
            ?>
            <tr>
                <td class="left"><?= htmlspecialchars($descripcion) ?></td>
                <td class="center"></td>
                <td class="center"><?= htmlspecialchars($it['um']) ?></td>
                <td class="center"><?= rtrim(rtrim(number_format($it['cantidad'], 2), '0'), '.') ?></td>
                <td class="right"><?= number_format($it['precio_display'] ?? $it['precio'], 2) ?></td>
                <td class="right"><?= number_format($importe, 2) ?></td>
            </tr>
            <?php endforeach; ?>

            <?php for ($k = 0; $k < $emptyRows; $k++): ?>
            <tr><td>&nbsp;</td><td></td><td class="center">U</td><td class="center">0</td><td class="center">-</td><td class="center">-</td></tr>
            <?php endfor; ?>

            <tr>
                <td colspan="3" class="right" style="border:none; font-weight:bold;">TOTAL&gt;&gt;</td>
                <td class="center" style="font-weight:bold;"><?= number_format($totalCantidad, 2) ?></td>
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
                                <td class="right">$<?= number_format($subtotalDisplay, 2) ?></td>
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
                                <td class="right total-final">$<?= number_format($totalDisplay, 2) ?></td>
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
        Generado por <?= htmlspecialchars(config_loader_system_name()) ?> v3.0 · VISITANOS EN https://www.palweb.net
    </div>

</div>
<?php endif; ?>
<?php if ($autoPrint): ?>
<script>
window.addEventListener('load', function () {
    setTimeout(function () { window.print(); }, 250);
});
</script>
<?php endif; ?>
</body>
</html>
