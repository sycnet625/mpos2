<?php
// onat_calendario.php — Calendario fiscal: obligaciones del actor configurado con estado.
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
$tipo_actor = $fiscal['tipo_actor_economico'];
$modelos = modelos_onat_para_actor($tipo_actor, !empty($fiscal['regimen_simplificado']));

$anioVista = intval($_GET['anio'] ?? date('Y'));
$hoy = new DateTimeImmutable('today');

// Construye la lista de obligaciones del año por modelo.
$obligaciones = [];

foreach ($modelos as $m) {
    switch ($m['periodo_tipo']) {
        case 'mensual':
            for ($mes = 1; $mes <= 12; $mes++) {
                $cierre = (new DateTimeImmutable(sprintf('%04d-%02d-20', $anioVista, $mes)))->modify('+1 month');
                $obligaciones[] = [
                    'modelo'         => $m['codigo'],
                    'nombre'         => $m['nombre'],
                    'periodo_tipo'   => 'mensual',
                    'periodo_label'  => sprintf('%04d-%02d', $anioVista, $mes),
                    'periodo_inicio' => sprintf('%04d-%02d-01', $anioVista, $mes),
                    'periodo_fin'    => date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $anioVista, $mes))),
                    'fecha_limite'   => $cierre->format('Y-m-d'),
                    'mes_param'      => $mes,
                ];
            }
            break;
        case 'trimestral':
            for ($t = 1; $t <= 4; $t++) {
                $mesInicio = ($t - 1) * 3 + 1;
                $mesFin    = $mesInicio + 2;
                $cierreFin = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $anioVista, $mesFin)));
                $limite    = (new DateTimeImmutable($cierreFin))->modify('+20 days');
                $obligaciones[] = [
                    'modelo'         => $m['codigo'],
                    'nombre'         => $m['nombre'],
                    'periodo_tipo'   => 'trimestral',
                    'periodo_label'  => sprintf('%04d T%d', $anioVista, $t),
                    'periodo_inicio' => sprintf('%04d-%02d-01', $anioVista, $mesInicio),
                    'periodo_fin'    => $cierreFin,
                    'fecha_limite'   => $limite->format('Y-m-d'),
                    'mes_param'      => $mesInicio,
                ];
            }
            break;
        case 'anual':
            $obligaciones[] = [
                'modelo'         => $m['codigo'],
                'nombre'         => $m['nombre'],
                'periodo_tipo'   => 'anual',
                'periodo_label'  => (string)$anioVista,
                'periodo_inicio' => sprintf('%04d-01-01', $anioVista),
                'periodo_fin'    => sprintf('%04d-12-31', $anioVista),
                'fecha_limite'   => sprintf('%04d-04-30', $anioVista + 1),
                'mes_param'      => 12,
            ];
            break;
    }
}

// Marca estado a partir de onat_declaraciones existentes.
$declStmt = $pdo->prepare("SELECT modelo, periodo_inicio, periodo_fin, estado, id, archivo_xlsx, archivo_pdf
                           FROM onat_declaraciones
                           WHERE id_empresa = ? AND anio_modelo = ?");
$declStmt->execute([$EMP_ID, $anioVista]);
$declMap = [];
foreach ($declStmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
    $declMap[$d['modelo'] . '|' . $d['periodo_inicio']] = $d;
}

usort($obligaciones, fn($a, $b) => strcmp($a['fecha_limite'], $b['fecha_limite']));

// Pre-cálculo de alertas para banner superior.
$alertVencidas = [];
$alertProximas = [];
foreach ($obligaciones as $o) {
    $key = $o['modelo'] . '|' . $o['periodo_inicio'];
    $declTmp = $declMap[$key] ?? null;
    if ($declTmp && in_array($declTmp['estado'], ['presentada','pagada'], true)) continue;
    $limiteDt = new DateTimeImmutable($o['fecha_limite']);
    $diff = (int)$hoy->diff($limiteDt)->format('%r%a');
    if ($diff < 0) $alertVencidas[] = $o + ['dias' => abs($diff)];
    elseif ($diff <= 15) $alertProximas[] = $o + ['dias' => $diff];
}
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Calendario Fiscal ONAT</title>
<link href="assets/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/css/all.min.css">
<link rel="stylesheet" href="assets/css/inventory-suite.css">
</head>
<body class="pb-5 inventory-suite">
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h4 fw-bold mb-1"><i class="fas fa-calendar-check me-2 text-primary"></i>Calendario Fiscal ONAT</h1>
            <div class="text-muted small">
                Actor configurado: <span class="badge bg-primary"><?= htmlspecialchars($tipo_actor) ?></span>
                · Año: <strong><?= $anioVista ?></strong>
            </div>
        </div>
        <div class="d-flex gap-2">
            <form method="get" class="d-flex gap-2">
                <select name="anio" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php for ($y = intval(date('Y')) - 1; $y <= intval(date('Y')) + 1; $y++): ?>
                        <option value="<?= $y ?>" <?= $y == $anioVista ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </form>
            <a href="onat_taxes.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Volver</a>
        </div>
    </div>

    <?php if (!empty($alertVencidas)): ?>
        <div class="alert alert-danger d-flex align-items-start gap-3 mb-3">
            <i class="fas fa-triangle-exclamation fa-2x"></i>
            <div>
                <strong><?= count($alertVencidas) ?> obligación(es) vencida(s)</strong>
                <ul class="mb-0 small mt-1">
                <?php foreach (array_slice($alertVencidas, 0, 5) as $a): ?>
                    <li><?= htmlspecialchars($a['modelo']) ?> — <?= htmlspecialchars($a['periodo_label']) ?> (vencida hace <?= $a['dias'] ?> día(s))</li>
                <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>
    <?php if (!empty($alertProximas)): ?>
        <div class="alert alert-warning d-flex align-items-start gap-3 mb-3">
            <i class="fas fa-clock fa-2x"></i>
            <div>
                <strong><?= count($alertProximas) ?> obligación(es) por vencer en 15 días</strong>
                <ul class="mb-0 small mt-1">
                <?php foreach (array_slice($alertProximas, 0, 5) as $a): ?>
                    <li><?= htmlspecialchars($a['modelo']) ?> — <?= htmlspecialchars($a['periodo_label']) ?> (vence en <?= $a['dias'] ?> día(s) — <?= htmlspecialchars($a['fecha_limite']) ?>)</li>
                <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Modelo</th>
                            <th>Nombre</th>
                            <th>Período</th>
                            <th>Cubre</th>
                            <th>Fecha Límite</th>
                            <th>Estado</th>
                            <th class="text-end">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($obligaciones as $o):
                        $key = $o['modelo'] . '|' . $o['periodo_inicio'];
                        $decl = $declMap[$key] ?? null;
                        $estado = $decl['estado'] ?? 'pendiente';
                        $limiteDt = new DateTimeImmutable($o['fecha_limite']);
                        $vencido = !$decl && $hoy > $limiteDt;
                        $proximo = !$decl && !$vencido && $hoy >= $limiteDt->modify('-15 days');
                    ?>
                        <tr class="<?= $vencido ? 'table-danger' : ($proximo ? 'table-warning' : '') ?>">
                            <td><span class="badge bg-secondary"><?= htmlspecialchars($o['modelo']) ?></span></td>
                            <td class="small"><?= htmlspecialchars($o['nombre']) ?></td>
                            <td class="small"><?= htmlspecialchars($o['periodo_label']) ?></td>
                            <td class="tiny text-muted"><?= htmlspecialchars($o['periodo_inicio']) ?> → <?= htmlspecialchars($o['periodo_fin']) ?></td>
                            <td class="small fw-bold"><?= htmlspecialchars($o['fecha_limite']) ?></td>
                            <td>
                                <?php if ($estado === 'pendiente'): ?>
                                    <?php if ($vencido): ?>
                                        <span class="badge bg-danger">Vencido</span>
                                    <?php elseif ($proximo): ?>
                                        <span class="badge bg-warning text-dark">Próximo</span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-dark border">Pendiente</span>
                                    <?php endif; ?>
                                <?php elseif ($estado === 'generada'): ?>
                                    <span class="badge bg-info text-dark">Generada</span>
                                <?php elseif ($estado === 'presentada'): ?>
                                    <span class="badge bg-primary">Presentada</span>
                                <?php elseif ($estado === 'pagada'): ?>
                                    <span class="badge bg-success">Pagada</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($decl): ?>
                                    <?php if (!empty($decl['archivo_xlsx'])): ?>
                                        <a href="onat_download.php?id=<?= (int)$decl['id'] ?>&type=xlsx" class="btn btn-sm btn-outline-success" title="Excel"><i class="fas fa-file-excel"></i></a>
                                    <?php endif; ?>
                                    <?php if (!empty($decl['archivo_pdf'])): ?>
                                        <a href="onat_download.php?id=<?= (int)$decl['id'] ?>&type=pdf" class="btn btn-sm btn-outline-danger" title="PDF"><i class="fas fa-file-pdf"></i></a>
                                    <?php endif; ?>
                                    <a href="onat_generator.php?modelo=<?= urlencode($o['modelo']) ?>&anio=<?= $anioVista ?>&mes=<?= $o['mes_param'] ?>" class="btn btn-sm btn-outline-primary" title="Regenerar"><i class="fas fa-sync"></i></a>
                                    <?php if (in_array($estado, ['borrador','generada'], true)): ?>
                                        <a href="onat_marcar_pago.php?id=<?= (int)$decl['id'] ?>" class="btn btn-sm btn-success" title="Registrar pago"><i class="fas fa-credit-card"></i></a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <a href="onat_generator.php?modelo=<?= urlencode($o['modelo']) ?>&anio=<?= $anioVista ?>&mes=<?= $o['mes_param'] ?>" class="btn btn-sm btn-primary"><i class="fas fa-file-export me-1"></i>Generar</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php @include_once __DIR__ . '/menu_master.php'; ?>
</body>
</html>
