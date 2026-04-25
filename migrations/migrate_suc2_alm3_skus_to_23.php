<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

$sucursalId = 2;
$almacenId = 3;
$prefix = '23';

function fetchExistingColumns(PDO $pdo): array
{
    $rows = $pdo->query("
        SELECT TABLE_NAME, COLUMN_NAME
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
    ")->fetchAll(PDO::FETCH_ASSOC);

    $map = [];
    foreach ($rows as $row) {
        $table = (string)$row['TABLE_NAME'];
        $column = (string)$row['COLUMN_NAME'];
        $map[$table][$column] = true;
    }
    return $map;
}

function tableHasColumn(array $columnMap, string $table, string $column): bool
{
    return isset($columnMap[$table][$column]);
}

function renameProductImages(string $oldSku, string $newSku, string $baseDir): int
{
    $patterns = [
        $oldSku,
        $oldSku . '_extra1',
        $oldSku . '_extra2',
    ];
    $renamed = 0;

    foreach ($patterns as $oldBase) {
        $newBase = preg_replace('/^' . preg_quote($oldSku, '/') . '/', $newSku, $oldBase, 1);
        if ($newBase === null) {
            continue;
        }

        foreach (glob($baseDir . '/' . $oldBase . '*') ?: [] as $oldPath) {
            if (!is_file($oldPath)) {
                continue;
            }
            $fileName = basename($oldPath);
            $newFileName = preg_replace('/^' . preg_quote($oldBase, '/') . '/', $newBase, $fileName, 1);
            if ($newFileName === null || $newFileName === $fileName) {
                continue;
            }
            $newPath = $baseDir . '/' . $newFileName;
            if (file_exists($newPath)) {
                continue;
            }
            if (@rename($oldPath, $newPath)) {
                $renamed++;
            }
        }
    }

    return $renamed;
}

$columnMap = fetchExistingColumns($pdo);

$stmt = $pdo->prepare("
    SELECT s.id_producto AS sku, MAX(p.nombre) AS nombre, SUM(s.cantidad) AS qty
    FROM stock_almacen s
    LEFT JOIN productos p ON p.codigo = s.id_producto
    WHERE s.id_sucursal = ? AND s.id_almacen = ? AND s.cantidad > 0
    GROUP BY s.id_producto
    ORDER BY CAST(s.id_producto AS UNSIGNED), s.id_producto
");
$stmt->execute([$sucursalId, $almacenId]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$products) {
    fwrite(STDOUT, "No hay productos con existencias en sucursal {$sucursalId} / almacen {$almacenId}.\n");
    exit(0);
}

$stmt = $pdo->prepare("
    SELECT MAX(CAST(SUBSTRING(codigo, :len + 1) AS UNSIGNED)) AS max_seq
    FROM productos
    WHERE codigo LIKE CONCAT(:prefix, '%')
");
$stmt->execute([
    ':len' => strlen($prefix),
    ':prefix' => $prefix,
]);
$maxSeq = (int)$stmt->fetchColumn();
$nextSeq = $maxSeq > 0 ? $maxSeq + 1 : 1;

$mapping = [];
foreach ($products as $product) {
    $oldSku = trim((string)$product['sku']);
    do {
        $newSku = $prefix . str_pad((string)$nextSeq, 4, '0', STR_PAD_LEFT);
        $nextSeq++;

        $check = $pdo->prepare("SELECT 1 FROM productos WHERE codigo = ? LIMIT 1");
        $check->execute([$newSku]);
        $exists = (bool)$check->fetchColumn();
    } while ($exists || isset($mapping[$newSku]));

    $mapping[$oldSku] = [
        'new' => $newSku,
        'nombre' => (string)($product['nombre'] ?? ''),
        'qty' => (float)($product['qty'] ?? 0),
    ];
}

$pdo->beginTransaction();

try {
    $pdo->exec("DROP TEMPORARY TABLE IF EXISTS tmp_sku_migration");
    $pdo->exec("
        CREATE TEMPORARY TABLE tmp_sku_migration (
            old_sku VARCHAR(50) NOT NULL PRIMARY KEY,
            new_sku VARCHAR(50) NOT NULL UNIQUE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $insertTmp = $pdo->prepare("INSERT INTO tmp_sku_migration (old_sku, new_sku) VALUES (?, ?)");
    foreach ($mapping as $oldSku => $data) {
        $insertTmp->execute([$oldSku, $data['new']]);
    }

    $updateTargets = [
        ['productos', 'codigo'],
        ['stock_almacen', 'id_producto'],
        ['ventas_detalle', 'id_producto'],
        ['ventas_detalle', 'codigo_producto'],
        ['compras_detalle', 'id_producto'],
        ['entradas_detalle', 'id_producto'],
        ['kardex', 'id_producto'],
        ['mermas_detalle', 'id_producto'],
        ['pedidos_detalle', 'id_producto'],
        ['transferencias_detalle', 'id_producto'],
        ['productos_precios_sucursal', 'codigo_producto'],
        ['producto_variantes', 'producto_codigo'],
        ['resenas_productos', 'producto_codigo'],
        ['restock_avisos', 'producto_codigo'],
        ['vistas_productos', 'codigo_producto'],
        ['wishlist', 'producto_codigo'],
        ['recetas_cabecera', 'id_producto_final'],
        ['recetas_detalle', 'id_ingrediente'],
        ['producciones_historial', 'id_producto_final'],
        ['purchase_import_run_rows', 'source_sku'],
        ['purchase_import_run_rows', 'target_sku'],
    ];

    $updatedRows = [];
    foreach ($updateTargets as [$table, $column]) {
        if (!tableHasColumn($columnMap, $table, $column)) {
            continue;
        }
        $sql = "UPDATE {$table} t
                INNER JOIN tmp_sku_migration m
                    ON CONVERT(t.{$column} USING utf8mb4) COLLATE utf8mb4_unicode_ci =
                       CONVERT(m.old_sku USING utf8mb4) COLLATE utf8mb4_unicode_ci
                SET t.{$column} = m.new_sku";
        $count = $pdo->exec($sql);
        $updatedRows["{$table}.{$column}"] = (int)$count;
    }

    $pdo->commit();

    $imageDir = dirname(__DIR__) . '/assets/product_images';
    $renamedImages = 0;
    foreach ($mapping as $oldSku => $data) {
        $renamedImages += renameProductImages((string)$oldSku, (string)$data['new'], $imageDir);
    }

    fwrite(STDOUT, "Migracion completada.\n");
    fwrite(STDOUT, "Sucursal: {$sucursalId} | Almacen: {$almacenId} | Prefijo: {$prefix}\n");
    fwrite(STDOUT, "Mapeo SKU:\n");
    foreach ($mapping as $oldSku => $data) {
        fwrite(
            STDOUT,
            sprintf(
                "%s\t%s\t%s\t%.4f\n",
                $oldSku,
                $data['new'],
                $data['nombre'],
                $data['qty']
            )
        );
    }

    fwrite(STDOUT, "Actualizaciones por tabla:\n");
    foreach ($updatedRows as $target => $count) {
        fwrite(STDOUT, "{$target}\t{$count}\n");
    }
    fwrite(STDOUT, "Imagenes renombradas:\t{$renamedImages}\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
