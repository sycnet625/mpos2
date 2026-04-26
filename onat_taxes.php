<?php
// ARCHIVO: /var/www/palweb/api/onat_taxes.php
// MODULO FISCAL ONAT (CUBA) - V3 CON INTEGRACIÓN CONTABLE (LEY 113)
// REDISEÑO PREMIUM INVENTORY-SUITE

ini_set('display_errors', 0);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php'); exit;
}

require_once 'db.php';
require_once 'config_loader.php';
require_once 'accounting_helpers.php';

$EMP_ID = intval($config['id_empresa']);

// --- 2. PROCESAR GUARDADO E INTEGRACIÓN CONTABLE ---
$msg = "";
$msgOk = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'guardar') {
    try {
        $pdo->beginTransaction();

        // A. Guardar Declaración Fiscal
        $sqlInsert = "INSERT INTO declaraciones_onat 
            (id_empresa, anio, mes, tipo_entidad, ingresos_brutos, costo_ventas, gastos_nomina, gastos_otros, 
             imp_ventas, imp_fuerza, contrib_local, seg_social, seg_social_especial, imp_utilidades, total_pagar)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            tipo_entidad=VALUES(tipo_entidad), ingresos_brutos=VALUES(ingresos_brutos), costo_ventas=VALUES(costo_ventas),
            gastos_nomina=VALUES(gastos_nomina), gastos_otros=VALUES(gastos_otros), imp_ventas=VALUES(imp_ventas),
            imp_fuerza=VALUES(imp_fuerza), contrib_local=VALUES(contrib_local), seg_social=VALUES(seg_social),
            seg_social_especial=VALUES(seg_social_especial), imp_utilidades=VALUES(imp_utilidades), total_pagar=VALUES(total_pagar)";
        
        $stmt = $pdo->prepare($sqlInsert);
        $stmt->execute([
            $EMP_ID, $_POST['anio'], $_POST['mes'], $_POST['tipo'], 
            $_POST['ingresos_brutos'], $_POST['costo_ventas'], $_POST['nomina'], $_POST['otros_gastos'],
            $_POST['imp_ventas'], $_POST['imp_fuerza'], $_POST['contrib_local'], 
            $_POST['seg_social'], $_POST['seg_social_especial'], $_POST['imp_utilidades'], $_POST['total_pagar']
        ]);
        
        $declId = $pdo->lastInsertId();
        if(!$declId) {
            $stmtID = $pdo->prepare("SELECT id FROM declaraciones_onat WHERE id_empresa=? AND anio=? AND mes=?");
            $stmtID->execute([$EMP_ID, $_POST['anio'], $_POST['mes']]);
            $declId = $stmtID->fetchColumn();
        }

        $fechaContable = date("Y-m-t", strtotime($_POST['anio'].'-'.$_POST['mes'].'-01'));
        $pdo->prepare("DELETE FROM contabilidad_diario WHERE asiento_tipo = 'IMPUESTOS' AND referencia_id = ?")->execute([$declId]);

        $addAsiento = function($ctaGasto, $ctaPasivo, $monto, $detalle) use ($pdo, $fechaContable, $declId) {
            if($monto > 0) {
                $pdo->prepare("INSERT INTO contabilidad_diario (fecha, asiento_tipo, referencia_id, cuenta, debe, haber, detalle) VALUES (?, 'IMPUESTOS', ?, ?, ?, 0, ?)")
                    ->execute([$fechaContable, $declId, $ctaGasto, $monto, $detalle]);
                $pdo->prepare("INSERT INTO contabilidad_diario (fecha, asiento_tipo, referencia_id, cuenta, debe, haber, detalle) VALUES (?, 'IMPUESTOS', ?, ?, 0, ?, ?)")
                    ->execute([$fechaContable, $declId, $ctaPasivo, $monto, $detalle]);
            }
        };

        $addAsiento('704.1 - Gasto Imp. Ventas', '240.1 - Imp. Ventas por Pagar', $_POST['imp_ventas'], 'Declaración Ventas');
        $addAsiento('704.2 - Gasto Fuerza Trabajo', '240.3 - Fuerza Trabajo por Pagar', $_POST['imp_fuerza'], 'Impuesto Nómina');
        $addAsiento('704.3 - Gasto Contrib. Local', '240.2 - Contrib. Local por Pagar', $_POST['contrib_local'], 'Contribución Territorial');
        $addAsiento('704.4 - Gasto Seg. Social', '240.5 - Seg. Social por Pagar', $_POST['seg_social'] + $_POST['seg_social_especial'], 'Aporte Seguridad Social');
        
        if($_POST['imp_utilidades'] > 0) {
            $addAsiento('704.5 - Gasto Imp. Utilidades', '240.4 - Imp. Utilidades por Pagar', $_POST['imp_utilidades'], 'Provisión Utilidades');
        }

        $pdo->commit();
        $msg = "Declaración guardada y contabilizada correctamente.";
        $msgOk = true;

    } catch (Exception $e) {
        $pdo->rollBack();
        $msg = "Error crítico: " . $e->getMessage();
        $msgOk = false;
    }
}

// --- 3. PARÁMETROS GET ---
$mes = isset($_GET['mes']) ? intval($_GET['mes']) : intval(date('m'));
$anio = isset($_GET['anio']) ? intval($_GET['anio']) : intval(date('Y'));
$tipo_entidad = isset($_GET['tipo']) ? $_GET['tipo'] : 'MIPYME'; 
$gastos_nomina = isset($_GET['nomina']) ? floatval($_GET['nomina']) : 0;
$gastos_otros  = isset($_GET['otros_gastos']) ? floatval($_GET['otros_gastos']) : 0;

// --- 4. CÁLCULOS TRIBUTARIOS ---
$fecha_inicio = sprintf("%d-%02d-01 00:00:00", $anio, $mes);
$fecha_fin    = date("Y-m-t 23:59:59", strtotime($fecha_inicio));

// Datos Automáticos
$stmtV = $pdo->prepare("SELECT SUM(v.total) 
                        FROM ventas_cabecera v 
                        LEFT JOIN caja_sesiones s ON v.id_caja = s.id
                        WHERE v.id_empresa = ? AND IFNULL(s.fecha_contable, DATE(v.fecha)) BETWEEN ? AND ? 
                        AND " . ventas_reales_where_clause('v'));
$stmtV->execute([$EMP_ID, $fecha_inicio, $fecha_fin]);
$ingresos_brutos = floatval($stmtV->fetchColumn() ?: 0);

$stmtC = $pdo->prepare("SELECT SUM(d.cantidad * p.costo) 
                        FROM ventas_detalle d 
                        JOIN productos p ON d.id_producto = p.codigo 
                        JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id 
                        LEFT JOIN caja_sesiones s ON v.id_caja = s.id
                        WHERE v.id_empresa = ? AND IFNULL(s.fecha_contable, DATE(v.fecha)) BETWEEN ? AND ? 
                        AND " . ventas_reales_where_clause('v'));
$stmtC->execute([$EMP_ID, $fecha_inicio, $fecha_fin]);
$costo_ventas_auto = floatval($stmtC->fetchColumn() ?: 0);

// TRIBUTOS (LEY 113)
$impuesto_ventas = $ingresos_brutos * 0.10; 
$contribucion_local = $ingresos_brutos * 0.01; 
$impuesto_fuerza = $gastos_nomina * 0.05;       
$seg_social_especial = $gastos_nomina * 0.12;   
$seg_social = $gastos_nomina * 0.05;            

$tributos_deducibles = $impuesto_fuerza + $contribucion_local + $impuesto_ventas + $seg_social + $seg_social_especial;
$gastos_totales = $costo_ventas_auto + $gastos_nomina + $gastos_otros + $tributos_deducibles;

$utilidad_base = $ingresos_brutos - $gastos_totales;
$impuesto_utilidades = 0;

if ($tipo_entidad === 'MIPYME') {
    if ($utilidad_base > 0) $impuesto_utilidades = $utilidad_base * 0.35;
} else {
    $impuesto_utilidades = $ingresos_brutos * 0.05;
}

$total_a_pagar = $impuesto_ventas + $contribucion_local + $impuesto_fuerza + $seg_social_especial + $seg_social + $impuesto_utilidades;

// Historial
$historial = $pdo->prepare("SELECT * FROM declaraciones_onat WHERE id_empresa = ? ORDER BY anio DESC, mes DESC LIMIT 12");
$historial->execute([$EMP_ID]);
$lista_decl = $historial->fetchAll(PDO::FETCH_ASSOC);
$meses = ["", "Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Impuestos ONAT Premium</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="assets/css/inventory-suite.css">
    <style>
        .inventory-hero {
            background: linear-gradient(135deg, <?php echo $config['hero_color_1'] ?? '#0f766e'; ?>ee, <?php echo $config['hero_color_2'] ?? '#15803d'; ?>c6) !important;
        }
        .tax-total-box {
            background: var(--pw-primary);
            color: white;
            border-radius: 1rem;
            padding: 1.5rem;
        }
        @media print { .no-print { display: none !important; } .inventory-shell { padding: 0 !important; } }
    </style>
</head>
<body class="pb-5 inventory-suite">
<div class="container-fluid shell inventory-shell py-4 py-lg-5">
    <section class="glass-card inventory-hero p-4 p-lg-5 mb-4 inventory-fade-in no-print">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-4 align-items-start">
            <div>
                <div class="section-title text-white-50 mb-2">Contabilidad / Fiscal</div>
                <h1 class="h2 fw-bold mb-2 text-white"><i class="fas fa-file-invoice-dollar me-2"></i>Impuestos ONAT (Ley 113)</h1>
                <p class="mb-3 text-white-50">Cálculo automatizado de tributos, integración con diario contable y provisiones de utilidades.</p>
                <div class="d-flex flex-wrap gap-2">
                    <span class="kpi-chip"><i class="fas fa-calendar-alt me-1"></i>Periodo: <?= $meses[$mes] ?> <?= $anio ?></span>
                    <span class="kpi-chip"><i class="fas fa-building me-1"></i>Entidad: <?= $tipo_entidad ?></span>
                    <span class="kpi-chip"><i class="fas fa-chart-line me-1"></i>Ingresos: $<?= number_format($ingresos_brutos, 2) ?></span>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-light" onclick="window.print()"><i class="fas fa-print me-1"></i>Imprimir Reporte</button>
                <a href="dashboard.php" class="btn btn-outline-light"><i class="fas fa-arrow-left me-1"></i>Volver</a>
            </div>
        </div>
    </section>

    <?php if($msg): ?>
        <div class="alert mb-4 inventory-fade-in no-print <?php echo $msgOk ? 'alert-success' : 'alert-danger'; ?>">
            <i class="fas <?php echo $msgOk ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?> me-2"></i><?php echo $msg; ?>
        </div>
    <?php endif; ?>

    <div class="row g-4 align-items-stretch">
        <!-- Panel de Parámetros -->
        <div class="col-12 col-xl-4 no-print">
            <div class="glass-card p-4 h-100 inventory-fade-in">
                <div class="section-title">Parámetros</div>
                <h2 class="h5 fw-bold mb-4">Datos del Periodo</h2>
                <form method="GET" class="row g-3">
                    <div class="col-6">
                        <label class="form-label small fw-bold">Año Fiscal</label>
                        <select name="anio" class="form-select">
                            <?php for($y=2024; $y<=2026; $y++): ?>
                                <option value="<?= $y ?>" <?= $y==$anio?'selected':'' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-bold">Mes</label>
                        <select name="mes" class="form-select">
                            <?php for($m=1;$m<=12;$m++) echo "<option value='$m' ".($m==$mes?'selected':'').">{$meses[$m]}</option>"; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-bold">Tipo de Entidad</label>
                        <select name="tipo" class="form-select">
                            <option value="MIPYME" <?= $tipo_entidad=='MIPYME'?'selected':'' ?>>MIPYME (Sociedad Mercantil)</option>
                            <option value="TCP" <?= $tipo_entidad=='TCP'?'selected':'' ?>>Trabajador Cuenta Propia</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-bold text-danger">Gasto Nómina Total ($)</label>
                        <input type="number" name="nomina" class="form-control" value="<?= $gastos_nomina ?>" step="0.01" required>
                        <div class="form-text tiny text-muted">Base para Fuerza de Trabajo y Seg. Social.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-bold">Otros Gastos Deducibles ($)</label>
                        <input type="number" name="otros_gastos" class="form-control" value="<?= $gastos_otros ?>" step="0.01">
                    </div>
                    <div class="col-12 pt-2">
                        <button type="submit" class="btn btn-primary w-100 fw-bold shadow-sm">
                            <i class="fas fa-sync-alt me-2"></i>RECALCULAR TRIBUTOS
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Panel de Resultados -->
        <div class="col-12 col-xl-8">
            <div class="glass-card p-4 h-100 inventory-fade-in">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <div class="section-title">Cálculo Detallado</div>
                        <h2 class="h5 fw-bold mb-0">Determinación de la Deuda Tributaria</h2>
                    </div>
                    <span class="soft-pill"><i class="fas fa-info-circle me-1"></i>Valores en CUP</span>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-4">
                        <div class="stat-box">
                            <div class="tiny text-muted">Ventas Brutas</div>
                            <div class="h4 fw-bold mb-0 text-success">$<?= number_format($ingresos_brutos, 2) ?></div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="stat-box">
                            <div class="tiny text-muted">Costo Mercancía</div>
                            <div class="h4 fw-bold mb-0 text-danger">$<?= number_format($costo_ventas_auto, 2) ?></div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="stat-box">
                            <div class="tiny text-muted">Utilidad Base</div>
                            <div class="h4 fw-bold mb-0 text-primary">$<?= number_format($utilidad_base, 2) ?></div>
                        </div>
                    </div>
                </div>

                <div class="table-responsive border rounded-4 bg-white mb-4">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Descripción del Tributo</th>
                                <th class="text-end">Base / %</th>
                                <th class="text-end">Cuota a Pagar</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><i class="fas fa-shopping-cart me-2 text-muted"></i>Impuesto sobre Ventas</td>
                                <td class="text-end text-muted small">10.0%</td>
                                <td class="text-end fw-bold">$<?= number_format($impuesto_ventas, 2) ?></td>
                            </tr>
                            <tr>
                                <td><i class="fas fa-map-marker-alt me-2 text-muted"></i>Contribución Territorial (Local)</td>
                                <td class="text-end text-muted small">1.0%</td>
                                <td class="text-end fw-bold">$<?= number_format($contribucion_local, 2) ?></td>
                            </tr>
                            <tr class="bg-light bg-opacity-50">
                                <td><i class="fas fa-users me-2 text-muted"></i>Impuesto Fuerza de Trabajo</td>
                                <td class="text-end text-muted small">5.0%</td>
                                <td class="text-end fw-bold">$<?= number_format($impuesto_fuerza, 2) ?></td>
                            </tr>
                            <tr class="bg-light bg-opacity-50">
                                <td><i class="fas fa-heart-pulse me-2 text-muted"></i>Seguridad Social (Contribución Patronal)</td>
                                <td class="text-end text-muted small">17.0%</td>
                                <td class="text-end fw-bold">$<?= number_format($seg_social_especial+$seg_social, 2) ?></td>
                            </tr>
                            <tr class="border-top border-2 border-primary">
                                <td class="fw-bold">Impuesto sobre Utilidades (Provisión)</td>
                                <td class="text-end text-muted small"><?= $tipo_entidad === 'MIPYME' ? '35.0%' : 'Variable' ?></td>
                                <td class="text-end fw-bold text-primary">$<?= number_format($impuesto_utilidades, 2) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="tax-total-box d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h4 class="mb-0 fw-bold text-uppercase">Total a Pagar ONAT</h4>
                        <div class="small opacity-75">Periodo Declarado: <?= $meses[$mes] ?> <?= $anio ?></div>
                    </div>
                    <div class="text-end">
                        <div class="h1 fw-bold mb-0">$<?= number_format($total_a_pagar, 2) ?></div>
                    </div>
                </div>

                <form method="POST" class="no-print">
                    <input type="hidden" name="action" value="guardar">
                    <input type="hidden" name="anio" value="<?= $anio ?>">
                    <input type="hidden" name="mes" value="<?= $mes ?>">
                    <input type="hidden" name="tipo" value="<?= $tipo_entidad ?>">
                    <input type="hidden" name="ingresos_brutos" value="<?= $ingresos_brutos ?>">
                    <input type="hidden" name="costo_ventas" value="<?= $costo_ventas_auto ?>">
                    <input type="hidden" name="nomina" value="<?= $gastos_nomina ?>">
                    <input type="hidden" name="otros_gastos" value="<?= $gastos_otros ?>">
                    <input type="hidden" name="imp_ventas" value="<?= $impuesto_ventas ?>">
                    <input type="hidden" name="imp_fuerza" value="<?= $impuesto_fuerza ?>">
                    <input type="hidden" name="contrib_local" value="<?= $contribucion_local ?>">
                    <input type="hidden" name="seg_social" value="<?= $seg_social ?>">
                    <input type="hidden" name="seg_social_especial" value="<?= $seg_social_especial ?>">
                    <input type="hidden" name="imp_utilidades" value="<?= $impuesto_utilidades ?>">
                    <input type="hidden" name="total_pagar" value="<?= $total_a_pagar ?>">

                    <div class="row g-2">
                        <div class="col-md-8">
                            <button type="submit" class="btn btn-success btn-lg w-100 fw-bold shadow-sm py-3" onclick="return confirm('Esto generará los asientos contables de deuda. ¿Continuar?')">
                                <i class="fas fa-check-circle me-2"></i>GUARDAR Y CONTABILIZAR EN DIARIO
                            </button>
                        </div>
                        <div class="col-md-4">
                            <button type="button" class="btn btn-outline-secondary btn-lg w-100 h-100 fw-bold" onclick="window.print()">
                                <i class="fas fa-file-pdf me-2"></i>EXPORTAR
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Historial -->
    <section class="glass-card p-4 mt-4 inventory-fade-in no-print">
        <div class="section-title">Historial</div>
        <h2 class="h5 fw-bold mb-4">Declaraciones Recientes</h2>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Periodo Fiscal</th>
                        <th>Tipo Entidad</th>
                        <th class="text-end">Ingresos Brutos</th>
                        <th class="text-end">Base Imponible</th>
                        <th class="text-end">Total ONAT</th>
                        <th class="text-center">Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($lista_decl)): ?>
                        <tr><td colspan="6" class="text-center py-4 text-muted">No hay declaraciones previas.</td></tr>
                    <?php endif; ?>
                    <?php foreach($lista_decl as $d): ?>
                        <tr>
                            <td class="fw-bold"><?= $meses[$d['mes']] ?> <?= $d['anio'] ?></td>
                            <td><span class="soft-pill"><?= $d['tipo_entidad'] ?></span></td>
                            <td class="text-end">$<?= number_format($d['ingresos_brutos'], 2) ?></td>
                            <td class="text-end text-muted small">$<?= number_format($d['ingresos_brutos'] - ($d['costo_ventas'] + $d['gastos_nomina'] + $d['gastos_otros']), 2) ?></td>
                            <td class="text-end fw-bold text-danger">$<?= number_format($d['total_pagar'], 2) ?></td>
                            <td class="text-center text-success"><i class="fas fa-check-double me-1"></i>Contabilizado</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<?php include_once 'menu_master.php'; ?>
</body>
</html>
