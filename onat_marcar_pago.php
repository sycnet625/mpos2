<?php
// onat_marcar_pago.php — Registra pago de una declaración ONAT (Transfermóvil/EnZona/efectivo).
ini_set('display_errors', 0);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php'); exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config_loader.php';
require_once __DIR__ . '/onat_contabilidad_bridge.php';

$EMP_ID = intval($config['id_empresa'] ?? 1);
$id = intval($_GET['id'] ?? $_POST['id'] ?? 0);

$decl = null;
if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM onat_declaraciones WHERE id = ? AND id_empresa = ?");
    $stmt->execute([$id, $EMP_ID]);
    $decl = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (!$decl) {
    http_response_code(404); exit('Declaración no encontrada.');
}

$msg = '';
$msgType = 'info';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $metodo = $_POST['metodo'] ?? '';
        $comprobante = trim((string)($_POST['comprobante'] ?? ''));
        $fechaPago = $_POST['fecha_pago'] ?? date('Y-m-d');
        $descuento = floatval($_POST['descuento_pct'] ?? 0);
        $estado = ($_POST['estado'] ?? 'pagada');

        $stmt = $pdo->prepare("UPDATE onat_declaraciones
            SET estado = ?, fecha_presentacion = ?, metodo_pago_onat = ?, comprobante_pago = ?, descuento_aplicado_pct = ?
            WHERE id = ? AND id_empresa = ?");
        $stmt->execute([$estado, $fechaPago, $metodo, $comprobante, $descuento, $id, $EMP_ID]);

        // Asiento contable automático sólo cuando se marca como pagada.
        if ($estado === 'pagada' && $metodo) {
            try {
                onat_registrar_pago_contable($pdo, $decl, $metodo, $fechaPago);
            } catch (Throwable $e) {
                // No bloquear el flujo si falla el asiento; queda registrado el pago.
                error_log('onat_marcar_pago: fallo asiento contable: ' . $e->getMessage());
            }
        }

        header('Location: onat_calendario.php?anio=' . intval(date('Y', strtotime($decl['periodo_inicio']))));
        exit;
    } catch (Throwable $e) {
        $msg = 'Error: ' . $e->getMessage();
        $msgType = 'danger';
    }
}

// Calcula descuentos sugeridos.
$hoy = new DateTimeImmutable('today');
$anioPeriodo = intval(date('Y', strtotime($decl['periodo_inicio'])));
$corte28feb = new DateTimeImmutable(sprintf('%04d-02-28', $anioPeriodo + 1));
$descSugerido = 0;
if ($decl['periodo_tipo'] === 'anual' && $hoy <= $corte28feb) $descSugerido += 5;
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Registrar pago ONAT — <?= htmlspecialchars($decl['modelo']) ?></title>
<link href="assets/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/css/all.min.css">
<link rel="stylesheet" href="assets/css/inventory-suite.css">
</head>
<body class="pb-5 inventory-suite">
<div class="container py-4" style="max-width:720px">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h5 fw-bold mb-0"><i class="fas fa-credit-card me-2 text-primary"></i>Registrar pago — <?= htmlspecialchars($decl['modelo']) ?></h1>
        <a href="onat_calendario.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Volver</a>
    </div>

    <?php if ($msg): ?><div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

    <div class="card mb-3"><div class="card-body small">
        <div><strong>Período:</strong> <?= htmlspecialchars($decl['periodo_inicio']) ?> → <?= htmlspecialchars($decl['periodo_fin']) ?></div>
        <div><strong>Monto a pagar:</strong> $<?= number_format((float)$decl['monto_total'], 2) ?></div>
        <div><strong>Estado actual:</strong> <span class="badge bg-secondary"><?= htmlspecialchars($decl['estado']) ?></span></div>
    </div></div>

    <form method="post" class="card"><div class="card-body">
        <input type="hidden" name="id" value="<?= (int)$decl['id'] ?>">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label small fw-bold">Método de pago</label>
                <select name="metodo" class="form-select" required>
                    <option value="">Seleccione…</option>
                    <option value="Transfermovil" <?= $decl['metodo_pago_onat'] === 'Transfermovil' ? 'selected' : '' ?>>Transfermóvil</option>
                    <option value="EnZona" <?= $decl['metodo_pago_onat'] === 'EnZona' ? 'selected' : '' ?>>EnZona</option>
                    <option value="Banco" <?= $decl['metodo_pago_onat'] === 'Banco' ? 'selected' : '' ?>>Banco (ventanilla)</option>
                    <option value="Efectivo" <?= $decl['metodo_pago_onat'] === 'Efectivo' ? 'selected' : '' ?>>Efectivo en ONAT</option>
                </select>
                <div class="form-text tiny">Transfermóvil/EnZona dan 3% de descuento adicional.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label small fw-bold">N° comprobante / referencia</label>
                <input type="text" name="comprobante" class="form-control" value="<?= htmlspecialchars($decl['comprobante_pago'] ?? '') ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold">Fecha de pago</label>
                <input type="date" name="fecha_pago" class="form-control" value="<?= htmlspecialchars($decl['fecha_presentacion'] ? substr($decl['fecha_presentacion'], 0, 10) : date('Y-m-d')) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold">Descuento aplicado (%)</label>
                <input type="number" step="0.01" min="0" max="20" name="descuento_pct" class="form-control" value="<?= number_format((float)($decl['descuento_aplicado_pct'] ?: $descSugerido), 2) ?>">
                <div class="form-text tiny">5% pago anticipado al 28-feb · 3% canal digital · 8% combinados.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold">Estado</label>
                <select name="estado" class="form-select">
                    <option value="presentada" <?= $decl['estado'] === 'presentada' ? 'selected' : '' ?>>Presentada</option>
                    <option value="pagada" <?= $decl['estado'] === 'pagada' ? 'selected' : '' ?>>Pagada</option>
                </select>
            </div>
        </div>
        <button class="btn btn-primary mt-3"><i class="fas fa-save me-1"></i>Registrar pago</button>
    </div></form>
</div>
</body>
</html>
