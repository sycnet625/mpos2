<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../kardex_engine.php';

date_default_timezone_set('America/Havana');

$EMP = 1;
$SUC = 2;
$ALM = 3;
$QTY = 300.0;
$usuario = 'admin';
$fecha = date('Y-m-d H:i:s');
$motivo = 'Vale de entrada masiva: +300 unidades a todos los productos del almacen 3 de la sucursal 2';

$stmt = $pdo->prepare("
    SELECT p.codigo, p.nombre, COALESCE(ps.precio_costo, p.costo, 0) AS costo
    FROM productos p
    LEFT JOIN productos_precios_sucursal ps
           ON ps.codigo_producto = p.codigo AND ps.id_sucursal = ?
    WHERE EXISTS (
        SELECT 1
        FROM stock_almacen s
        WHERE s.id_producto = p.codigo
          AND s.id_almacen = ?
          AND s.id_sucursal = ?
    )
      AND p.id_empresa = ?
    ORDER BY p.codigo
");
$stmt->execute([$SUC, $ALM, $SUC, $EMP]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$items) {
    fwrite(STDERR, "No hay productos en almacen 3 sucursal 2.\n");
    exit(1);
}

$total = 0.0;
foreach ($items as $item) {
    $total += $QTY * (float)$item['costo'];
}

$pdo->beginTransaction();

try {
    $insCab = $pdo->prepare("
        INSERT INTO entradas_cabecera
            (motivo, total, usuario, fecha, id_empresa, id_sucursal, id_almacen)
        VALUES
            (?, ?, ?, ?, ?, ?, ?)
    ");
    $insCab->execute([$motivo, $total, $usuario, $fecha, $EMP, $SUC, $ALM]);
    $idEntrada = (int)$pdo->lastInsertId();

    $insDet = $pdo->prepare("
        INSERT INTO entradas_detalle
            (id_entrada, id_producto, cantidad, costo_unitario, subtotal)
        VALUES
            (?, ?, ?, ?, ?)
    ");

    foreach ($items as $item) {
        $sku = (string)$item['codigo'];
        $costo = (float)$item['costo'];
        $subtotal = $QTY * $costo;

        KardexEngine::registrarMovimiento(
            $pdo,
            $sku,
            $ALM,
            $QTY,
            'ENTRADA',
            "ENTRADA MASIVA #{$idEntrada}",
            $costo,
            $SUC,
            $fecha
        );

        $insDet->execute([$idEntrada, $sku, $QTY, $costo, $subtotal]);
    }

    $pdo->commit();

    fwrite(STDOUT, "ID_ENTRADA={$idEntrada}\n");
    fwrite(STDOUT, "PRODUCTOS=" . count($items) . "\n");
    fwrite(STDOUT, "TOTAL=" . number_format($total, 2, '.', '') . "\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
