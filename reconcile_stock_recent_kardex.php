<?php
// Reconciliacion parcial de stock_almacen desde kardex para movimientos recientes.
// Uso:
//   php reconcile_stock_recent_kardex.php            # dry-run (7 dias)
//   php reconcile_stock_recent_kardex.php --days=14  # dry-run (14 dias)
//   php reconcile_stock_recent_kardex.php --apply    # aplica cambios (7 dias)

require_once __DIR__ . '/db.php';

$days = 7;
$apply = false;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--days=')) {
        $days = max(1, (int)substr($arg, 7));
    } elseif ($arg === '--apply') {
        $apply = true;
    }
}

$since = (new DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d H:i:s');

echo "Reconciling recent stock from kardex\n";
echo "Window: last {$days} days (since {$since})\n";
echo "Mode: " . ($apply ? "APPLY" : "DRY-RUN") . "\n\n";

$sqlPairs = "
    SELECT DISTINCT k.id_producto, k.id_almacen
    FROM kardex k
    WHERE k.fecha >= :since
";
$stPairs = $pdo->prepare($sqlPairs);
$stPairs->execute([':since' => $since]);
$pairs = $stPairs->fetchAll(PDO::FETCH_ASSOC);

if (!$pairs) {
    echo "No recent kardex movements found.\n";
    exit(0);
}

$stLast = $pdo->prepare("
    SELECT saldo_actual, id_sucursal
    FROM kardex
    WHERE id_producto = ? AND id_almacen = ?
    ORDER BY id DESC
    LIMIT 1
");

$stCur = $pdo->prepare("
    SELECT id, cantidad, COALESCE(id_sucursal, 1) AS id_sucursal
    FROM stock_almacen
    WHERE id_producto = ? AND id_almacen = ?
    LIMIT 1
");

$stUpd = $pdo->prepare("
    UPDATE stock_almacen
    SET cantidad = ?, id_sucursal = ?, ultima_actualizacion = NOW()
    WHERE id = ?
");

$stIns = $pdo->prepare("
    INSERT INTO stock_almacen (id_almacen, id_producto, cantidad, id_sucursal, ultima_actualizacion)
    VALUES (?, ?, ?, ?, NOW())
");

$checked = 0;
$updated = 0;
$inserted = 0;
$unchanged = 0;

try {
    if ($apply && !$pdo->inTransaction()) {
        $pdo->beginTransaction();
    }

    foreach ($pairs as $pair) {
        $sku = $pair['id_producto'];
        $alm = (int)$pair['id_almacen'];
        $checked++;

        $stLast->execute([$sku, $alm]);
        $last = $stLast->fetch(PDO::FETCH_ASSOC);
        if (!$last) {
            continue;
        }

        $targetQty = (float)$last['saldo_actual'];
        $targetSuc = (int)($last['id_sucursal'] ?? 1);

        $stCur->execute([$sku, $alm]);
        $cur = $stCur->fetch(PDO::FETCH_ASSOC);

        if ($cur) {
            $curQty = (float)$cur['cantidad'];
            $curSuc = (int)$cur['id_sucursal'];
            if (abs($curQty - $targetQty) < 0.0001 && $curSuc === $targetSuc) {
                $unchanged++;
                continue;
            }

            echo "[UPDATE] ALM={$alm} SKU={$sku} {$curQty} -> {$targetQty}\n";
            if ($apply) {
                $stUpd->execute([$targetQty, $targetSuc, (int)$cur['id']]);
            }
            $updated++;
        } else {
            echo "[INSERT] ALM={$alm} SKU={$sku} qty={$targetQty}\n";
            if ($apply) {
                $stIns->execute([$alm, $sku, $targetQty, $targetSuc]);
            }
            $inserted++;
        }
    }

    if ($apply && $pdo->inTransaction()) {
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($apply && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}

echo "\nDone.\n";
echo "Checked: {$checked}\n";
echo "Updated: {$updated}\n";
echo "Inserted: {$inserted}\n";
echo "Unchanged: {$unchanged}\n";

