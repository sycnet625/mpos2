<?php
ini_set('display_errors', 0);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once 'db.php';
require_once 'config_loader.php';

$EMP_ID = isset($config['id_empresa']) ? (int)$config['id_empresa'] : 0;
$SUC_ID = isset($config['id_sucursal']) ? (int)$config['id_sucursal'] : 0;
$ALM_ID = isset($config['id_almacen']) ? (int)$config['id_almacen'] : 0;

function label_sanitize_skus(?string $raw): array {
    if ($raw === null) return [];
    $parts = explode(',', $raw);
    $out = [];
    foreach ($parts as $part) {
        $sku = strtoupper(trim((string)$part));
        if ($sku === '') continue;
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $sku)) continue;
        $out[] = $sku;
    }
    return array_values(array_unique($out));
}

$rawSkus = $_GET['skus'] ?? '';
$rawCopies = $_GET['copies'] ?? '1';
$copies = (int)$rawCopies;
if (!is_numeric($rawCopies) || $copies <= 0) {
    $copies = 1;
}
if ($copies > 20) $copies = 20;
$selectedSkus = label_sanitize_skus($rawSkus);
$items = [];

if ($EMP_ID > 0 && !empty($selectedSkus)) {
    $placeholders = implode(',', array_fill(0, count($selectedSkus), '?'));
    $sql = "
        SELECT
            p.codigo,
            p.nombre,
            p.precio,
            p.unidad_medida,
            p.descripcion,
            p.color,
            p.etiqueta_color,
            (SELECT COALESCE(SUM(s.cantidad), 0)
               FROM stock_almacen s
              WHERE s.id_producto = p.codigo
                AND s.id_almacen = ?) AS stock_total
        FROM productos p
        WHERE p.id_empresa = ?
          AND p.codigo IN ($placeholders)
        ORDER BY p.nombre ASC
    ";

    $stmt = $pdo->prepare($sql);
    $params = array_merge([$ALM_ID, $EMP_ID], $selectedSkus);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $rowMap = [];
    foreach ($rows as $r) {
        $rowMap[(string)$r['codigo']] = $r;
    }
    foreach ($selectedSkus as $sku) {
        if (isset($rowMap[$sku])) $items[] = $rowMap[$sku];
    }
}

$invalidCount = count($selectedSkus) - count($items);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>Imprimir Etiquetas</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <style>
        :root {
            --ink: #111827;
            --line: #d1d5db;
            --bg: #f4f5f7;
        }
        @page { size: A4; margin: 8mm; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            color: var(--ink);
            background: #fff;
        }
        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            border-bottom: 1px solid var(--line);
        }
        .title { font-size: 14px; font-weight: 700; letter-spacing: .2px; }
        .small { font-size: 12px; color: #4b5563; }
        .sheet {
            width: 210mm;
            min-height: 297mm;
            padding: 6mm;
            box-sizing: border-box;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 6mm;
        }
        .label {
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            height: 62mm;
            padding: 6mm;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            page-break-inside: avoid;
            break-inside: avoid;
            position: relative;
        }
        .label-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            margin-bottom: 4px;
        }
        .sku {
            font-size: 10px;
            color: #0f172a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .name {
            font-size: 16px;
            font-weight: 700;
            line-height: 1.15;
            margin-top: 2px;
            margin-bottom: 4px;
            max-height: 38px;
            overflow: hidden;
        }
        .desc {
            font-size: 10px;
            color: #374151;
            line-height: 1.2;
            min-height: 20px;
            max-height: 20px;
            overflow: hidden;
        }
        .footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 8px;
        }
        .price {
            font-size: 19px;
            font-weight: 700;
        }
        .stock {
            font-size: 11px;
            color: #374151;
        }
        .tag {
            font-size: 10px;
            padding: 2px 7px;
            border-radius: 999px;
            border: 1px solid #e5e7eb;
            display: inline-block;
            margin-right: 4px;
            margin-top: 4px;
            color: #374151;
            background: #f9fafb;
        }
        .qr-box {
            border: 1px dashed #94a3b8;
            width: 28mm;
            height: 28mm;
            margin-left: auto;
            font-size: 8px;
            color: #64748b;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2px;
            box-sizing: border-box;
        }
        .no-print {
            font-size: 13px;
        }
        .notice {
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 8px;
            margin: 10px 12px;
            padding: 12px;
        }
        @media print {
            .toolbar, .notice .btn { display: none !important; }
            .sheet { padding: 3mm; }
        }
    </style>
</head>
<body>
<div class="toolbar no-print">
    <div>
        <div class="title">📦 Etiquetas de productos</div>
        <div class="small">Suc: <?php echo $SUC_ID; ?> | Alm: <?php echo $ALM_ID; ?> | Total: <?php echo count($items) * $copies; ?> etiqueta(s) | Copias por SKU: <?php echo (int)$copies; ?></div>
    </div>
    <div>
        <button class="btn btn-sm btn-outline-secondary" onclick="window.close()">Cerrar</button>
        <button class="btn btn-sm btn-primary" onclick="window.print()">Imprimir</button>
    </div>
</div>

<?php if (empty($selectedSkus)): ?>
    <div class="notice">
        <p class="mb-1 fw-bold">No hay productos seleccionados.</p>
        <p class="mb-1 small">Seleccióna productos desde inventario y usa “🏷️ Imprimir Etiquetas”.</p>
    </div>
<?php elseif (empty($items)): ?>
    <div class="notice">
        <p class="mb-1 fw-bold">No se encontraron productos válidos.</p>
        <p class="mb-0 small">Verifica que los códigos existan para la empresa actual.</p>
    </div>
<?php else: ?>
    <div class="sheet">
        <div class="grid">
            <?php foreach ($items as $row): ?>
                <?php for ($i = 0; $i < $copies; $i++): ?>
                <?php
                $sku = (string)$row['codigo'];
                $name = (string)$row['nombre'];
                $price = number_format((float)$row['precio'], 2);
                $stock = (float)($row['stock_total'] ?? 0);
                $unit = (string)$row['unidad_medida'];
                if ($unit === '') $unit = 'Und';
                $desc = (string)($row['descripcion'] ?? '');
                $labelColor = trim((string)($row['etiqueta_color'] ?? ''));
                $bgStyle = $labelColor !== '' ? 'background:' . htmlspecialchars($labelColor, ENT_QUOTES, 'UTF-8') . ';' : '';
                $hasDesc = $desc !== '';
                ?>
                <div class="label" style="<?php echo $bgStyle; ?>">
                    <div>
                        <div class="label-head">
                            <span class="sku">SKU: <?php echo htmlspecialchars($sku, ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="tag">SUC <?php echo (int)$SUC_ID; ?></span>
                        </div>
                        <div class="name"><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="desc"><?php echo $hasDesc ? htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') : ' '; ?></div>
                    </div>
                    <div class="footer">
                        <div>
                            <div class="price">$<?php echo $price; ?></div>
                            <div class="stock">Stock: <?php echo number_format($stock, 2, '.', ','); ?> <?php echo htmlspecialchars($unit, ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <div class="qr-box">Código: <?php echo htmlspecialchars($sku, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                </div>
                <?php endfor; ?>
            <?php endforeach; ?>
        </div>
        <?php if ($invalidCount > 0): ?>
            <div class="small text-muted mt-2">SKUs no válidos o inexistentes: <?php echo (int)$invalidCount; ?></div>
        <?php endif; ?>
    </div>
<?php endif; ?>

</body>
</html>
