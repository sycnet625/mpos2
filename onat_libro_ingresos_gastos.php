<?php
// onat_libro_ingresos_gastos.php — Vista del Libro de Ingresos y Gastos foliado.
ini_set('display_errors', 0);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php'); exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config_loader.php';
require_once __DIR__ . '/empresa_fiscal_helpers.php';
require_once __DIR__ . '/onat_libro_helpers.php';

$EMP_ID = intval($config['id_empresa'] ?? 1);
$fiscal = get_empresa_fiscal($EMP_ID);

$anio = intval($_GET['anio'] ?? date('Y'));
$mes  = intval($_GET['mes'] ?? 0); // 0 = año completo
if ($mes > 0) {
    $desde = sprintf('%04d-%02d-01', $anio, $mes);
    $hasta = date('Y-m-t', strtotime($desde));
    $titulo = sprintf('%s %d', strftime ? strftime('%B', strtotime($desde)) : '', $anio);
} else {
    $desde = sprintf('%04d-01-01', $anio);
    $hasta = sprintf('%04d-12-31', $anio);
    $titulo = (string)$anio;
}

$msg = '';
$msgType = 'info';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'regenerar') {
    try {
        $asientos = onat_libro_construir($pdo, $EMP_ID, $desde, $hasta);
        $n = onat_libro_persistir($pdo, $EMP_ID, $desde, $hasta, $asientos);
        $msg = "Libro regenerado: {$n} asientos foliados con cadena de hash SHA-256.";
        $msgType = 'success';
    } catch (Throwable $e) {
        $msg = 'Error: ' . $e->getMessage();
        $msgType = 'danger';
    }
}

$rows = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM onat_libro_asientos
                           WHERE id_empresa = ? AND fecha BETWEEN ? AND ?
                           ORDER BY fecha ASC, folio ASC");
    $stmt->execute([$EMP_ID, $desde, $hasta]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$totIng = array_sum(array_map(fn($r) => $r['tipo'] === 'INGRESO' ? floatval($r['monto']) : 0, $rows));
$totGas = array_sum(array_map(fn($r) => $r['tipo'] === 'GASTO'   ? floatval($r['monto']) : 0, $rows));
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Libro de Ingresos y Gastos — <?= htmlspecialchars($titulo) ?></title>
<link href="assets/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/css/all.min.css">
<link rel="stylesheet" href="assets/css/inventory-suite.css">
<style>@media print { .no-print { display:none; } }</style>
</head>
<body class="pb-5 inventory-suite">
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <div>
            <h1 class="h4 fw-bold mb-1"><i class="fas fa-book-open me-2 text-primary"></i>Libro de Ingresos y Gastos</h1>
            <div class="text-muted small">Empresa <?= htmlspecialchars($config['marca_empresa_nombre'] ?? '') ?> · NIT <?= htmlspecialchars($fiscal['nit'] ?? '') ?> · <strong><?= htmlspecialchars($desde) ?> → <?= htmlspecialchars($hasta) ?></strong></div>
        </div>
        <div class="d-flex gap-2">
            <form method="get" class="d-flex gap-2">
                <select name="anio" class="form-select form-select-sm">
                    <?php for ($y = intval(date('Y')) - 2; $y <= intval(date('Y')) + 1; $y++): ?>
                        <option value="<?= $y ?>" <?= $y == $anio ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
                <select name="mes" class="form-select form-select-sm">
                    <option value="0" <?= $mes == 0 ? 'selected' : '' ?>>Año completo</option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $m == $mes ? 'selected' : '' ?>><?= sprintf('%02d', $m) ?></option>
                    <?php endfor; ?>
                </select>
                <button class="btn btn-sm btn-outline-primary"><i class="fas fa-filter"></i></button>
            </form>
            <form method="post" class="d-inline">
                <input type="hidden" name="action" value="regenerar">
                <button class="btn btn-sm btn-warning" onclick="return confirm('Regenerar libro: borra y reconstruye los asientos del período. ¿Continuar?')">
                    <i class="fas fa-sync me-1"></i>Regenerar
                </button>
            </form>
            <button onclick="window.print()" class="btn btn-sm btn-outline-secondary"><i class="fas fa-print"></i></button>
            <a href="onat_taxes.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left"></i></a>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msgType ?> no-print"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-3 no-print">
        <div class="col-md-3"><div class="border rounded p-3"><div class="tiny text-muted">Total Ingresos</div><div class="h5 fw-bold text-success">$<?= number_format($totIng, 2) ?></div></div></div>
        <div class="col-md-3"><div class="border rounded p-3"><div class="tiny text-muted">Total Gastos</div><div class="h5 fw-bold text-danger">$<?= number_format($totGas, 2) ?></div></div></div>
        <div class="col-md-3"><div class="border rounded p-3"><div class="tiny text-muted">Resultado</div><div class="h5 fw-bold text-primary">$<?= number_format($totIng - $totGas, 2) ?></div></div></div>
        <div class="col-md-3"><div class="border rounded p-3"><div class="tiny text-muted">Asientos</div><div class="h5 fw-bold"><?= count($rows) ?></div></div></div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th>Folio</th>
                            <th>Fecha</th>
                            <th>Tipo</th>
                            <th>Concepto</th>
                            <th>Comprobante</th>
                            <th class="text-end">Ingreso</th>
                            <th class="text-end">Gasto</th>
                            <th class="tiny text-muted">Hash (encadenado)</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">Sin asientos. Pulsá <strong>Regenerar</strong>.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="fw-bold"><?= (int)$r['folio'] ?></td>
                            <td><?= htmlspecialchars($r['fecha']) ?></td>
                            <td><?php if ($r['tipo'] === 'INGRESO'): ?><span class="badge bg-success">Ingreso</span><?php else: ?><span class="badge bg-danger">Gasto</span><?php endif; ?></td>
                            <td><?= htmlspecialchars($r['concepto']) ?></td>
                            <td class="tiny text-muted"><?= htmlspecialchars($r['comprobante']) ?></td>
                            <td class="text-end"><?= $r['tipo'] === 'INGRESO' ? '$' . number_format($r['monto'], 2) : '' ?></td>
                            <td class="text-end"><?= $r['tipo'] === 'GASTO' ? '$' . number_format($r['monto'], 2) : '' ?></td>
                            <td class="tiny text-muted" style="font-family:monospace;"><?= substr((string)$r['hash_actual'], 0, 12) ?>…</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="5" class="text-end">TOTALES</th>
                            <th class="text-end">$<?= number_format($totIng, 2) ?></th>
                            <th class="text-end">$<?= number_format($totGas, 2) ?></th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>
</body>
</html>
