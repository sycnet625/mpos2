<?php
// onat_generator.php — Dispatcher de generación de modelos oficiales ONAT.
// Uso: GET ?modelo=DJ-Utilidades&anio=2025[&mes=4][&format=xlsx|pdf|json]

ini_set('display_errors', 0);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php'); exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config_loader.php';
require_once __DIR__ . '/empresa_fiscal_helpers.php';

$EMP_ID = intval($config['id_empresa'] ?? 1);
$fiscal = get_empresa_fiscal($EMP_ID);

$modelo = (string)($_GET['modelo'] ?? '');
$anio   = intval($_GET['anio'] ?? date('Y'));
$mes    = intval($_GET['mes']  ?? date('m'));
$format = strtolower((string)($_GET['format'] ?? 'view'));

$registry = [
    'DJ-Utilidades'    => 'DjUtilidadesGenerator',
    'DJ-08'            => 'Dj08Generator',
    'DJ-09'            => 'Dj09Generator',
    'SC-Trim'          => 'ScTrimestralGenerator',
    'Mensual-Ventas'   => 'MensualVentasGenerator',
    'IM-FuerzaTrabajo' => 'ImFuerzaTrabajoGenerator',
    'SS-Aporte'        => 'SsAporteGenerator',
    'VectorFiscal'     => 'VectorFiscalGenerator',
    'TCP-CuotaFija'    => 'TcpCuotaFijaGenerator',
    'Retenciones'      => 'RetencionesGenerator',
];

if (!isset($registry[$modelo])) {
    http_response_code(400);
    echo 'Modelo desconocido: ' . htmlspecialchars($modelo);
    exit;
}

$class = $registry[$modelo];
$file = __DIR__ . '/onat_generators/' . $class . '.php';
if (!is_file($file)) {
    http_response_code(501);
    echo 'Generador aún no implementado para ' . htmlspecialchars($modelo) . ' (falta ' . basename($file) . ').';
    exit;
}
require_once $file;

try {
    /** @var BaseGenerator $gen */
    $gen = new $class($pdo, $EMP_ID, $fiscal, $config);
    $result = $gen->generar($anio, $mes);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<pre>Error generando ' . htmlspecialchars($modelo) . ': ' . htmlspecialchars($e->getMessage()) . '</pre>';
    exit;
}

if ($format === 'json') {
    header('Content-Type: application/json');
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if ($format === 'xlsx' && !empty($result['archivo_xlsx']) && is_file($result['archivo_xlsx'])) {
    header('Location: onat_download.php?id=' . $result['id'] . '&type=xlsx');
    exit;
}
if ($format === 'pdf' && !empty($result['archivo_pdf']) && is_file($result['archivo_pdf'])) {
    header('Location: onat_download.php?id=' . $result['id'] . '&type=pdf');
    exit;
}

// Vista HTML por defecto
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Generación ONAT — <?= htmlspecialchars($modelo) ?></title>
<link href="assets/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/css/all.min.css">
<link rel="stylesheet" href="assets/css/inventory-suite.css">
</head>
<body class="pb-5 inventory-suite">
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 fw-bold mb-0"><i class="fas fa-file-signature me-2 text-primary"></i>Modelo <?= htmlspecialchars($modelo) ?> generado</h1>
        <a href="onat_taxes.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Volver</a>
    </div>
    <div class="alert alert-success">
        Documento creado para el período <strong><?= htmlspecialchars($result['periodo_inicio']) ?></strong> →
        <strong><?= htmlspecialchars($result['periodo_fin']) ?></strong>.
        Total a pagar: <strong>$<?= number_format((float)($result['monto_total'] ?? 0), 2) ?></strong>.
    </div>

    <div class="card mb-3">
        <div class="card-header fw-bold">Datos cargados en la plantilla</div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead class="table-light"><tr><th>Campo</th><th class="text-end">Valor</th></tr></thead>
                <tbody>
                <?php foreach ($result['datos'] as $k => $v): ?>
                    <tr>
                        <td><?= htmlspecialchars($k) ?></td>
                        <td class="text-end"><?= is_numeric($v) ? number_format((float)$v, 2) : htmlspecialchars((string)$v) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="d-flex gap-2">
        <?php if (!empty($result['archivo_xlsx'])): ?>
            <a href="onat_download.php?id=<?= $result['id'] ?>&type=xlsx" class="btn btn-success"><i class="fas fa-file-excel me-1"></i>Descargar Excel</a>
        <?php endif; ?>
        <?php if (!empty($result['archivo_pdf'])): ?>
            <a href="onat_download.php?id=<?= $result['id'] ?>&type=pdf" class="btn btn-danger"><i class="fas fa-file-pdf me-1"></i>Descargar PDF</a>
        <?php else: ?>
            <span class="text-muted small align-self-center"><i class="fas fa-info-circle me-1"></i>PDF no disponible (instalá <code>mpdf/mpdf</code> o LibreOffice headless).</span>
        <?php endif; ?>
    </div>

    <?php
    $plantilla = __DIR__ . '/onat_modelos/' . $anio . '/' . $modelo . '.xlsx';
    if (!is_file($plantilla)):
    ?>
    <div class="alert alert-warning mt-3 small">
        <i class="fas fa-triangle-exclamation me-1"></i>
        No se encontró la plantilla oficial <code>onat_modelos/<?= $anio ?>/<?= htmlspecialchars($modelo) ?>.xlsx</code>.
        Se generó un Excel genérico con los datos. Descargá la plantilla oficial desde
        <a href="https://www.onat.gob.cu/home/modelos-formularios" target="_blank">onat.gob.cu</a>
        y colocala en esa ruta.
    </div>
    <?php endif; ?>
</div>
</body>
</html>
