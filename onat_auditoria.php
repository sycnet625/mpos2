<?php
// onat_auditoria.php — Verifica integridad SHA-256 de archivos generados y consecutivos de facturas.
ini_set('display_errors', 0);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php'); exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config_loader.php';

$EMP_ID = intval($config['id_empresa'] ?? 1);

// 1) Archivos ONAT — comparar hash registrado vs hash actual del archivo en disco.
$archivos = [];
try {
    $stmt = $pdo->prepare("SELECT v.*, d.modelo, d.periodo_inicio, d.periodo_fin
                           FROM onat_archivos_versiones v
                           JOIN onat_declaraciones d ON d.id = v.id_declaracion
                           WHERE d.id_empresa = ?
                           ORDER BY v.created_at DESC");
    $stmt->execute([$EMP_ID]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $abs = __DIR__ . '/' . ltrim($r['path'], '/');
        $hashActual = is_file($abs) ? hash_file('sha256', $abs) : null;
        $r['hash_actual'] = $hashActual;
        $r['integridad'] = !$hashActual ? 'FALTA' : ($hashActual === $r['hash_sha256'] ? 'OK' : 'ALTERADO');
        $archivos[] = $r;
    }
} catch (Throwable $e) {}

// 2) Consecutivos de facturas — buscar saltos.
$saltos = [];
try {
    $stmt = $pdo->query("SELECT numero_factura FROM facturas
                         WHERE numero_factura REGEXP '^[0-9]+$'
                         ORDER BY CAST(numero_factura AS UNSIGNED) ASC");
    $nums = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (count($nums) > 1) {
        for ($i = 1; $i < count($nums); $i++) {
            if ($nums[$i] - $nums[$i-1] > 1) {
                $saltos[] = ['desde' => $nums[$i-1], 'hasta' => $nums[$i], 'faltan' => $nums[$i] - $nums[$i-1] - 1];
            }
        }
    }
} catch (Throwable $e) {}

// 3) Libro de Ingresos/Gastos — verificar cadena de hash.
$libroErrores = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM onat_libro_asientos WHERE id_empresa = ? ORDER BY fecha ASC, folio ASC");
    $stmt->execute([$EMP_ID]);
    $previo = str_repeat('0', 64);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $payload = $a['folio'] . '|' . $a['fecha'] . '|' . $a['tipo'] . '|' . floatval($a['monto'])
                 . '|' . ($a['origen_tabla'] ?? '') . '|' . ($a['origen_id'] ?? '')
                 . '|' . $previo;
        $esperado = hash('sha256', $payload);
        if ($esperado !== $a['hash_actual'] || $a['hash_anterior'] !== $previo) {
            $libroErrores[] = [
                'folio' => $a['folio'], 'fecha' => $a['fecha'],
                'esperado' => $esperado, 'registrado' => $a['hash_actual'],
            ];
        }
        $previo = $a['hash_actual'];
    }
} catch (Throwable $e) {}
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Auditoría Fiscal</title>
<link href="assets/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/css/all.min.css">
<link rel="stylesheet" href="assets/css/inventory-suite.css">
</head>
<body class="pb-5 inventory-suite">
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 fw-bold mb-0"><i class="fas fa-shield-alt me-2 text-primary"></i>Auditoría Fiscal</h1>
        <a href="onat_taxes.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Volver</a>
    </div>

    <h2 class="h6 fw-bold mt-4">1. Integridad de archivos ONAT</h2>
    <div class="card mb-4"><div class="card-body p-0">
        <table class="table table-sm mb-0 small">
            <thead class="table-light"><tr><th>Modelo</th><th>Período</th><th>Versión</th><th>Tipo</th><th>SHA-256</th><th>Estado</th></tr></thead>
            <tbody>
            <?php if (empty($archivos)): ?>
                <tr><td colspan="6" class="text-center text-muted py-3">Sin archivos generados aún.</td></tr>
            <?php endif; ?>
            <?php foreach ($archivos as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['modelo']) ?></td>
                    <td class="tiny"><?= htmlspecialchars($r['periodo_inicio']) ?> → <?= htmlspecialchars($r['periodo_fin']) ?></td>
                    <td>v<?= (int)$r['version'] ?></td>
                    <td><?= htmlspecialchars($r['tipo']) ?></td>
                    <td class="tiny" style="font-family:monospace;"><?= substr($r['hash_sha256'], 0, 16) ?>…</td>
                    <td>
                        <?php if ($r['integridad'] === 'OK'): ?>
                            <span class="badge bg-success">OK</span>
                        <?php elseif ($r['integridad'] === 'ALTERADO'): ?>
                            <span class="badge bg-danger">Alterado</span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark">Falta archivo</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div></div>

    <h2 class="h6 fw-bold">2. Consecutivos de facturas</h2>
    <div class="card mb-4"><div class="card-body">
        <?php if (empty($saltos)): ?>
            <span class="badge bg-success"><i class="fas fa-check me-1"></i>Sin saltos detectados</span>
        <?php else: ?>
            <div class="alert alert-warning mb-2">Se detectaron <?= count($saltos) ?> salto(s) en la numeración:</div>
            <ul class="small mb-0">
                <?php foreach ($saltos as $s): ?>
                    <li>Entre <strong><?= $s['desde'] ?></strong> y <strong><?= $s['hasta'] ?></strong> faltan <?= $s['faltan'] ?> consecutivo(s).</li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div></div>

    <h2 class="h6 fw-bold">3. Cadena de hash del Libro de Ingresos/Gastos</h2>
    <div class="card mb-4"><div class="card-body">
        <?php if (empty($libroErrores)): ?>
            <span class="badge bg-success"><i class="fas fa-check me-1"></i>Cadena íntegra</span>
        <?php else: ?>
            <div class="alert alert-danger mb-2">Se detectaron <?= count($libroErrores) ?> rupturas en la cadena de hash. Esto indica edición posterior de asientos.</div>
            <table class="table table-sm small mb-0">
                <thead><tr><th>Folio</th><th>Fecha</th><th>Hash esperado</th><th>Hash registrado</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($libroErrores, 0, 50) as $e): ?>
                    <tr>
                        <td><?= $e['folio'] ?></td>
                        <td class="tiny"><?= htmlspecialchars($e['fecha']) ?></td>
                        <td class="tiny" style="font-family:monospace;"><?= substr($e['esperado'], 0, 16) ?>…</td>
                        <td class="tiny" style="font-family:monospace;"><?= substr($e['registrado'], 0, 16) ?>…</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div></div>
</div>
</body>
</html>
