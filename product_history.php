<?php
// ARCHIVO: product_history.php
// HISTORIAL KARDEX DETALLADO — Vista Local y Global con Recalculación

session_start();
if (!isset($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }

ini_set('display_errors', 0);
require_once 'db.php';
require_once 'config_loader.php';

$EMP_ID = intval($config['id_empresa']);
$ALM_ID = intval($config['id_almacen']);
$SUC_ID = intval($config['id_sucursal']);

// --- AJAX: Recalcular saldos del kardex ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'recalcular') {
    header('Content-Type: application/json');
    $skuPost = $_POST['sku'] ?? '';
    if (!$skuPost) { echo json_encode(['ok' => false, 'error' => 'SKU vacío']); exit; }

    try {
        $pdo->beginTransaction();

        // Obtener todos los almacenes que tienen movimientos de este SKU
        $stmtAlm = $pdo->prepare("SELECT DISTINCT id_almacen FROM kardex WHERE id_producto = ?");
        $stmtAlm->execute([$skuPost]);
        $almacenes = $stmtAlm->fetchAll(PDO::FETCH_COLUMN);

        $totalCorregidos = 0;

        foreach ($almacenes as $almId) {
            // Recorrer kardex en orden cronológico (ASC) por almacén
            $stmtMov = $pdo->prepare("SELECT id, cantidad, saldo_anterior, saldo_actual
                                       FROM kardex
                                       WHERE id_producto = ? AND id_almacen = ?
                                       ORDER BY fecha ASC, id ASC");
            $stmtMov->execute([$skuPost, $almId]);
            $movs = $stmtMov->fetchAll(PDO::FETCH_ASSOC);

            $saldo = 0;
            $stmtUpdate = $pdo->prepare("UPDATE kardex SET saldo_anterior = ?, saldo_actual = ? WHERE id = ?");

            foreach ($movs as $mov) {
                $saldoAnterior = $saldo;
                $saldo = round($saldo + floatval($mov['cantidad']), 4);

                // Solo actualizar si hay diferencia
                if (round(floatval($mov['saldo_anterior']), 4) !== round($saldoAnterior, 4)
                    || round(floatval($mov['saldo_actual']), 4) !== round($saldo, 4)) {
                    $stmtUpdate->execute([$saldoAnterior, $saldo, $mov['id']]);
                    $totalCorregidos++;
                }
            }

            // Actualizar stock_almacen con el saldo final calculado
            $stmtCheckStock = $pdo->prepare("SELECT id FROM stock_almacen WHERE id_producto = ? AND id_almacen = ?");
            $stmtCheckStock->execute([$skuPost, $almId]);
            if ($stmtCheckStock->fetch()) {
                $stmtUpStock = $pdo->prepare("UPDATE stock_almacen SET cantidad = ? WHERE id_producto = ? AND id_almacen = ?");
                $stmtUpStock->execute([$saldo, $skuPost, $almId]);
            }
        }

        $pdo->commit();
        echo json_encode(['ok' => true, 'corregidos' => $totalCorregidos, 'almacenes' => count($almacenes)]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$sku = $_GET['sku'] ?? '';
$vistaGlobal = isset($_GET['global']) && $_GET['global'] == '1';

if (!$sku) die("Error: SKU no especificado.");

try {
    // Obtener Datos del Producto
    $stmtP = $pdo->prepare("SELECT * FROM productos WHERE codigo = ? AND id_empresa = ?");
    $stmtP->execute([$sku, $EMP_ID]);
    $producto = $stmtP->fetch(PDO::FETCH_ASSOC);

    if (!$producto) die("Producto no encontrado.");

    // Obtener Historial Kardex
    if ($vistaGlobal) {
        $sqlKardex = "SELECT k.*, a.nombre AS almacen_nombre
                      FROM kardex k
                      LEFT JOIN almacenes a ON k.id_almacen = a.id
                      WHERE k.id_producto = ?
                      ORDER BY k.fecha DESC, k.id DESC LIMIT 500";
        $stmtK = $pdo->prepare($sqlKardex);
        $stmtK->execute([$sku]);
    } else {
        $sqlKardex = "SELECT k.*, a.nombre AS almacen_nombre
                      FROM kardex k
                      LEFT JOIN almacenes a ON k.id_almacen = a.id
                      WHERE k.id_producto = ? AND k.id_almacen = ?
                      ORDER BY k.fecha DESC, k.id DESC LIMIT 200";
        $stmtK = $pdo->prepare($sqlKardex);
        $stmtK->execute([$sku, $ALM_ID]);
    }
    $movimientos = $stmtK->fetchAll(PDO::FETCH_ASSOC);

    // Obtener Stock Actual Local
    $sqlStock = "SELECT cantidad FROM stock_almacen
                 WHERE id_producto = ? AND id_almacen = ? AND id_sucursal = ? LIMIT 1";
    $stmtStock = $pdo->prepare($sqlStock);
    $stmtStock->execute([$sku, $ALM_ID, $SUC_ID]);
    $stockActual = $stmtStock->fetchColumn();
    if ($stockActual === false) $stockActual = 0;

    // Stock global (todas las ubicaciones)
    $sqlStockGlobal = "SELECT SUM(cantidad) FROM stock_almacen WHERE id_producto = ?";
    $stmtSG = $pdo->prepare($sqlStockGlobal);
    $stmtSG->execute([$sku]);
    $stockGlobal = $stmtSG->fetchColumn() ?: 0;

} catch (Exception $e) { die("Error DB: " . $e->getMessage()); }
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Kardex: <?php echo htmlspecialchars($producto['nombre']); ?></title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body { background: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
        .page-header { background: white; padding: 20px; border-bottom: 1px solid #ddd; margin-bottom: 20px; }
        .kardex-table th { background: #343a40; color: white; font-size: 0.9rem; }
        .kardex-table td { font-size: 0.9rem; vertical-align: middle; }
        .type-badge { font-size: 0.7rem; min-width: 90px; padding: 5px 8px; text-transform: uppercase; font-weight: 800; border-radius: 4px; display: inline-block; }
        
        /* ESTILOS POR TIPO */
        .type-ENTRADA, .type-COMPRA, .type-PRODUCCION { background-color: #d1e7dd; color: #0f5132; border: 1px solid #0f5132; }
        .type-SALIDA, .type-MERMA, .type-DESPERDICIO { background-color: #f8d7da; color: #842029; border: 1px solid #842029; }
        .type-VENTA { background-color: #cfe2ff; color: #084298; border: 1px solid #084298; }
        .type-AJUSTE, .type-AJUSTE_MANUAL, .type-CORRECCION { background-color: #fff3cd; color: #664d03; border: 1px solid #664d03; }
        .type-TRANSFERENCIA, .type-TRASLADO { background-color: #e2d9f3; color: #512da8; border: 1px solid #512da8; }
        .type-DEVOLUCION, .type-RETORNO { background-color: #e0f2f1; color: #00695c; border: 1px solid #00695c; }
        .type-INICIAL { background-color: #e2e3e5; color: #383d41; border: 1px solid #383d41; }
        
        .type-default { background-color: #eee; color: #333; border: 1px solid #ccc; }

        @media print {
            .no-print { display: none !important; }
            body { background: white; }
            .card { border: none !important; shadow: none !important; }
        }
    </style>
</head>
<body>

<div class="page-header no-print d-flex justify-content-between align-items-center">
    <div>
        <h4 class="m-0 fw-bold"><i class="fas fa-history text-primary"></i> Historial de Movimientos</h4>
        <small class="text-muted">
            <?php if ($vistaGlobal): ?>
                <i class="fas fa-globe text-info"></i> <strong>Vista Global</strong> — Todos los almacenes
            <?php else: ?>
                Sucursal: <strong><?php echo $SUC_ID; ?></strong> | Almacén: <strong><?php echo $ALM_ID; ?></strong>
            <?php endif; ?>
        </small>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <?php if ($vistaGlobal): ?>
            <a href="?sku=<?php echo urlencode($sku); ?>" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-warehouse"></i> Ver Local
            </a>
        <?php else: ?>
            <a href="?sku=<?php echo urlencode($sku); ?>&global=1" class="btn btn-info btn-sm text-white">
                <i class="fas fa-globe"></i> Ver Global
            </a>
        <?php endif; ?>
        <button onclick="recalcularKardex()" class="btn btn-warning btn-sm" id="btnRecalcular">
            <i class="fas fa-calculator"></i> Recalcular
        </button>
        <button onclick="window.print()" class="btn btn-dark btn-sm"><i class="fas fa-print"></i> Imprimir</button>
        <a href="products_table.php" class="btn btn-outline-secondary btn-sm">Volver</a>
    </div>
</div>

<div class="container-fluid px-4">
    
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body">
            <div class="row">
                <div class="col-md-8">
                    <h3 class="fw-bold text-primary mb-1"><?php echo htmlspecialchars($producto['nombre']); ?></h3>
                    <div class="text-muted mb-2">SKU: <code class="fs-5 text-dark"><?php echo $sku; ?></code></div>
                    <span class="badge bg-secondary"><?php echo htmlspecialchars($producto['categoria']); ?></span>
                </div>
                <div class="col-md-4 text-end">
                    <div class="p-3 bg-light rounded border">
                        <?php if ($vistaGlobal): ?>
                            <div class="small text-uppercase text-muted fw-bold">Stock Global (Todos)</div>
                            <div class="display-6 fw-bold <?php echo $stockGlobal<=0?'text-danger':'text-success'; ?>">
                                <?php echo number_format($stockGlobal, 2); ?>
                            </div>
                        <?php else: ?>
                            <div class="small text-uppercase text-muted fw-bold">Stock Actual (Local)</div>
                            <div class="display-6 fw-bold <?php echo $stockActual<=0?'text-danger':'text-success'; ?>">
                                <?php echo number_format($stockActual, 2); ?>
                            </div>
                        <?php endif; ?>
                        <div class="small text-muted">Costo: $<?php echo number_format($producto['costo'], 2); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-bordered mb-0 kardex-table">
                    <thead>
                        <tr>
                            <th># ID</th>
                            <th>FECHA MOV.</th>
                            <?php if ($vistaGlobal): ?><th>ALMACÉN</th><?php endif; ?>
                            <th>TIPO</th>
                            <th>REFERENCIA</th>
                            <th>USUARIO</th>
                            <th class="text-end">CANTIDAD</th>
                            <th class="text-end table-active fw-bold">SALDO</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $colSpan = $vistaGlobal ? 8 : 7; ?>
                        <?php if (empty($movimientos)): ?>
                            <tr><td colspan="<?php echo $colSpan; ?>" class="text-center py-4 text-muted">Sin movimientos registrados.</td></tr>
                        <?php else: ?>
                            <?php foreach($movimientos as $m):
                                $esEntrada = $m['cantidad'] > 0;
                                $qty = $m['cantidad'];
                                $fechaObj = new DateTime($m['fecha']);
                            ?>
                            <tr>
                                <td class="text-muted small"><?php echo $m['id']; ?></td>
                                <td>
                                    <?php echo $fechaObj->format('d/m/Y H:i'); ?>
                                    <?php
                                        if ($fechaObj->format('Y-m-d') != date('Y-m-d')) {
                                            echo ' <i class="fas fa-clock text-warning" title="Fecha diferente al día de hoy"></i>';
                                        }
                                    ?>
                                </td>
                                <?php if ($vistaGlobal): ?>
                                <td>
                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($m['almacen_nombre'] ?? 'Alm #'.$m['id_almacen']); ?></span>
                                </td>
                                <?php endif; ?>
                                <td class="text-center">
                                    <?php
                                        $tipoRaw = $m['tipo_movimiento'] ?? 'DESCONOCIDO';
                                        $tipo = strtoupper(str_replace(' ', '_', $tipoRaw));
                                        $conocidos = ['ENTRADA', 'COMPRA', 'PRODUCCION', 'SALIDA', 'MERMA', 'DESPERDICIO', 'VENTA', 'AJUSTE', 'AJUSTE_MANUAL', 'CORRECCION', 'TRANSFERENCIA', 'TRASLADO', 'DEVOLUCION', 'RETORNO', 'INICIAL'];
                                        $cssClass = in_array($tipo, $conocidos) ? "type-$tipo" : "type-default";
                                    ?>
                                    <span class="type-badge <?php echo $cssClass; ?>">
                                        <?php echo $tipoRaw; ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($m['referencia']); ?></td>
                                <td class="small text-muted"><?php echo htmlspecialchars($m['usuario']); ?></td>

                                <td class="text-end fw-bold <?php echo $esEntrada ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo ($esEntrada ? '+' : '') . number_format($qty, 2); ?>
                                </td>

                                <td class="text-end table-active fw-bold text-dark">
                                    <?php echo number_format($m['saldo_actual'], 2); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-3 text-end text-muted small no-print">
        <i class="fas fa-info-circle"></i> Los movimientos se muestran en el orden de llegada al sistema (ID Descendente).
    </div>
</div>


<div class="text-center text-muted small mt-4 mb-2" style="font-size:0.7rem;">Sistema PALWEB POS v3.0</div>

<script>
function recalcularKardex() {
    if (!confirm('¿Recalcular todos los saldos del kardex para este producto?\n\nEsto recorrerá cada movimiento en orden cronológico y corregirá saldos inconsistentes en TODOS los almacenes.')) return;

    const btn = document.getElementById('btnRecalcular');
    const textoOriginal = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Recalculando...';

    const formData = new FormData();
    formData.append('action', 'recalcular');
    formData.append('sku', '<?php echo addslashes($sku); ?>');

    fetch(window.location.pathname, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                alert('Recalculación completada.\n\n• Almacenes procesados: ' + data.almacenes + '\n• Registros corregidos: ' + data.corregidos);
                location.reload();
            } else {
                alert('Error: ' + (data.error || 'Desconocido'));
            }
        })
        .catch(err => alert('Error de red: ' + err.message))
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = textoOriginal;
        });
}
</script>

<?php include_once 'menu_master.php'; ?>
</body>
</html>

