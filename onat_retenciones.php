<?php
// onat_retenciones.php — Registro de retenciones a terceros (artistas, profesionales, servicios contratados).
ini_set('display_errors', 0);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php'); exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config_loader.php';
require_once __DIR__ . '/empresa_fiscal_helpers.php';

$EMP_ID = intval($config['id_empresa'] ?? 1);
$msg = '';
$msgType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'crear') {
            $bruto = floatval($_POST['monto_bruto'] ?? 0);
            $tasa  = floatval($_POST['tasa_retencion'] ?? 5.00);
            $retenido = round($bruto * ($tasa / 100), 2);
            $fecha = $_POST['fecha'] ?? date('Y-m-d');
            $stmt = $pdo->prepare("INSERT INTO onat_retenciones
                (id_empresa, fecha, beneficiario_nombre, beneficiario_ci, beneficiario_nit, concepto,
                 monto_bruto, tasa_retencion, monto_retenido, anio, mes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $EMP_ID, $fecha,
                trim((string)$_POST['beneficiario_nombre']),
                trim((string)$_POST['beneficiario_ci']),
                trim((string)$_POST['beneficiario_nit']),
                trim((string)$_POST['concepto']),
                $bruto, $tasa, $retenido,
                intval(date('Y', strtotime($fecha))),
                intval(date('m', strtotime($fecha))),
            ]);
            $msg = 'Retención registrada: $' . number_format($retenido, 2) . ' retenidos.';
            $msgType = 'success';
        }
        if ($action === 'eliminar') {
            $id = intval($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM onat_retenciones WHERE id = ? AND id_empresa = ?");
            $stmt->execute([$id, $EMP_ID]);
            $msg = 'Retención eliminada.';
        }
    } catch (Throwable $e) {
        $msg = 'Error: ' . $e->getMessage();
        $msgType = 'danger';
    }
}

$anio = intval($_GET['anio'] ?? date('Y'));
$mes  = intval($_GET['mes'] ?? date('m'));

$rows = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM onat_retenciones
                           WHERE id_empresa = ? AND anio = ? AND mes = ?
                           ORDER BY fecha DESC, id DESC");
    $stmt->execute([$EMP_ID, $anio, $mes]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$totBruto = array_sum(array_column($rows, 'monto_bruto'));
$totRet   = array_sum(array_column($rows, 'monto_retenido'));
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Retenciones a Terceros</title>
<link href="assets/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/css/all.min.css">
<link rel="stylesheet" href="assets/css/inventory-suite.css">
</head>
<body class="pb-5 inventory-suite">
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 fw-bold mb-0"><i class="fas fa-hand-holding-usd me-2 text-primary"></i>Retenciones a Terceros</h1>
        <a href="onat_taxes.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Volver</a>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header fw-bold"><i class="fas fa-plus-circle me-2"></i>Nueva retención</div>
        <div class="card-body">
            <form method="post" class="row g-3">
                <input type="hidden" name="action" value="crear">
                <div class="col-md-2"><label class="form-label small fw-bold">Fecha</label>
                    <input type="date" name="fecha" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                <div class="col-md-3"><label class="form-label small fw-bold">Beneficiario</label>
                    <input type="text" name="beneficiario_nombre" class="form-control" required></div>
                <div class="col-md-2"><label class="form-label small fw-bold">CI</label>
                    <input type="text" name="beneficiario_ci" class="form-control"></div>
                <div class="col-md-2"><label class="form-label small fw-bold">NIT</label>
                    <input type="text" name="beneficiario_nit" class="form-control"></div>
                <div class="col-md-3"><label class="form-label small fw-bold">Concepto</label>
                    <input type="text" name="concepto" class="form-control" placeholder="Servicio profesional / artístico / etc."></div>
                <div class="col-md-3"><label class="form-label small fw-bold">Monto Bruto ($)</label>
                    <input type="number" step="0.01" min="0" name="monto_bruto" class="form-control" required></div>
                <div class="col-md-2"><label class="form-label small fw-bold">Tasa Retención (%)</label>
                    <input type="number" step="0.01" min="0" max="100" name="tasa_retencion" class="form-control" value="5.00"></div>
                <div class="col-md-3 d-flex align-items-end">
                    <button class="btn btn-primary w-100"><i class="fas fa-save me-1"></i>Registrar</button>
                </div>
            </form>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-2">
        <form method="get" class="d-flex gap-2">
            <select name="anio" class="form-select form-select-sm">
                <?php for ($y = intval(date('Y')) - 2; $y <= intval(date('Y')) + 1; $y++): ?>
                    <option value="<?= $y ?>" <?= $y == $anio ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
            <select name="mes" class="form-select form-select-sm">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $m == $mes ? 'selected' : '' ?>><?= sprintf('%02d', $m) ?></option>
                <?php endfor; ?>
            </select>
            <button class="btn btn-sm btn-outline-primary"><i class="fas fa-filter"></i></button>
        </form>
        <div class="small">
            <span class="me-3">Bruto: <strong>$<?= number_format($totBruto, 2) ?></strong></span>
            <span class="text-danger">Retenido: <strong>$<?= number_format($totRet, 2) ?></strong></span>
        </div>
    </div>

    <div class="card"><div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead class="table-light">
                <tr><th>Fecha</th><th>Beneficiario</th><th>CI/NIT</th><th>Concepto</th>
                <th class="text-end">Bruto</th><th class="text-end">Tasa</th><th class="text-end">Retenido</th><th></th></tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="8" class="text-center text-muted py-3">Sin retenciones en el período.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['fecha']) ?></td>
                    <td><?= htmlspecialchars($r['beneficiario_nombre']) ?></td>
                    <td class="tiny"><?= htmlspecialchars($r['beneficiario_ci'] ?: $r['beneficiario_nit']) ?></td>
                    <td class="small"><?= htmlspecialchars($r['concepto']) ?></td>
                    <td class="text-end">$<?= number_format($r['monto_bruto'], 2) ?></td>
                    <td class="text-end small"><?= number_format($r['tasa_retencion'], 2) ?>%</td>
                    <td class="text-end fw-bold text-danger">$<?= number_format($r['monto_retenido'], 2) ?></td>
                    <td>
                        <form method="post" class="d-inline" onsubmit="return confirm('Eliminar retención?')">
                            <input type="hidden" name="action" value="eliminar">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div></div>
</div>
</body>
</html>
