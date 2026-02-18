<?php
// ARCHIVO: /var/www/palweb/api/onat_taxes.php
// MODULO FISCAL ONAT (CUBA) - V3 CON INTEGRACIÓN CONTABLE (LEY 113)

ini_set('display_errors', 0);
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php'); exit;
}

require_once 'db.php';

// CONFIGURACIÓN
require_once 'config_loader.php';
$EMP_ID = intval($config['id_empresa']);

// --- 2. PROCESAR GUARDADO E INTEGRACIÓN CONTABLE ---
$msg = "";
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
        
        // Obtener ID para referencia contable
        $declId = $pdo->lastInsertId();
        if(!$declId) { // Si fue update, buscamos el ID
            $stmtID = $pdo->prepare("SELECT id FROM declaraciones_onat WHERE id_empresa=? AND anio=? AND mes=?");
            $stmtID->execute([$EMP_ID, $_POST['anio'], $_POST['mes']]);
            $declId = $stmtID->fetchColumn();
        }

        // B. INTEGRACIÓN CONTABLE (ASIENTOS AUTOMÁTICOS)
        // Fecha de registro contable: Último día del mes declarado
        $fechaContable = date("Y-m-t", strtotime($_POST['anio'].'-'.$_POST['mes'].'-01'));
        
        // 1. Limpiar asientos previos de esta declaración (para evitar duplicados al actualizar)
        $pdo->prepare("DELETE FROM contabilidad_diario WHERE asiento_tipo = 'IMPUESTOS' AND referencia_id = ?")->execute([$declId]);

        // Función helper para insertar pares Gasto(D) vs Pasivo(H)
        $addAsiento = function($ctaGasto, $ctaPasivo, $monto, $detalle) use ($pdo, $fechaContable, $declId) {
            if($monto > 0) {
                // Debe (Gasto)
                $pdo->prepare("INSERT INTO contabilidad_diario (fecha, asiento_tipo, referencia_id, cuenta, debe, haber, detalle) VALUES (?, 'IMPUESTOS', ?, ?, ?, 0, ?)")
                    ->execute([$fechaContable, $declId, $ctaGasto, $monto, $detalle]);
                // Haber (Pasivo)
                $pdo->prepare("INSERT INTO contabilidad_diario (fecha, asiento_tipo, referencia_id, cuenta, debe, haber, detalle) VALUES (?, 'IMPUESTOS', ?, ?, 0, ?, ?)")
                    ->execute([$fechaContable, $declId, $ctaPasivo, $monto, $detalle]);
            }
        };

        // Generar Asientos según Ley 113
        $addAsiento('704.1 - Gasto Imp. Ventas', '240.1 - Imp. Ventas por Pagar', $_POST['imp_ventas'], 'Declaración Ventas');
        $addAsiento('704.2 - Gasto Fuerza Trabajo', '240.3 - Fuerza Trabajo por Pagar', $_POST['imp_fuerza'], 'Impuesto Nómina');
        $addAsiento('704.3 - Gasto Contrib. Local', '240.2 - Contrib. Local por Pagar', $_POST['contrib_local'], 'Contribución Territorial');
        $addAsiento('704.4 - Gasto Seg. Social', '240.5 - Seg. Social por Pagar', $_POST['seg_social'] + $_POST['seg_social_especial'], 'Aporte Seguridad Social');
        
        // Impuesto Utilidades (Provisión)
        if($_POST['imp_utilidades'] > 0) {
            $addAsiento('704.5 - Gasto Imp. Utilidades', '240.4 - Imp. Utilidades por Pagar', $_POST['imp_utilidades'], 'Provisión Utilidades');
        }

        $pdo->commit();
        $msg = "✅ Declaración guardada y contabilizada correctamente.";

    } catch (Exception $e) {
        $pdo->rollBack();
        $msg = "❌ Error crítico: " . $e->getMessage();
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
$stmtV = $pdo->prepare("SELECT SUM(total) FROM ventas_cabecera WHERE id_empresa = ? AND fecha BETWEEN ? AND ?");
$stmtV->execute([$EMP_ID, $fecha_inicio, $fecha_fin]);
$ingresos_brutos = floatval($stmtV->fetchColumn() ?: 0);

$stmtC = $pdo->prepare("SELECT SUM(d.cantidad * p.costo) FROM ventas_detalle d JOIN productos p ON d.id_producto = p.codigo JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id WHERE v.id_empresa = ? AND v.fecha BETWEEN ? AND ?");
$stmtC->execute([$EMP_ID, $fecha_inicio, $fecha_fin]);
$costo_ventas_auto = floatval($stmtC->fetchColumn() ?: 0);

// TRIBUTOS (LEY 113)
// Art 217: Ventas 10%
$impuesto_ventas = $ingresos_brutos * 0.10; 
// Art 302: Contribución 1%
$contribucion_local = $ingresos_brutos * 0.01; 

// Fuerza de Trabajo (5%)
$impuesto_fuerza = $gastos_nomina * 0.05;       
// Seguridad Social (14% Total: 12% Especial + 2-5% Std dependiendo del régimen, asumimos 17% total estándar MIPYME o configurable)
// Ajuste a práctica común: 12.5% o 14%. Usaremos los inputs previos del usuario.
$seg_social_especial = $gastos_nomina * 0.12;   
$seg_social = $gastos_nomina * 0.05;            

// UTILIDADES (Base Imponible)
// Deducibles: Costos, Gastos, Tributos pagados (excepto Utilidades), Fuerza trabajo, etc.
$tributos_deducibles = $impuesto_fuerza + $contribucion_local + $impuesto_ventas + $seg_social + $seg_social_especial;
$gastos_totales = $costo_ventas_auto + $gastos_nomina + $gastos_otros + $tributos_deducibles;

$utilidad_base = $ingresos_brutos - $gastos_totales;
$impuesto_utilidades = 0;

if ($tipo_entidad === 'MIPYME') {
    if ($utilidad_base > 0) $impuesto_utilidades = $utilidad_base * 0.35; // 35% Régimen General
} else {
    $impuesto_utilidades = $ingresos_brutos * 0.05; // TCP Simplificado (Ejemplo)
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
    <title>Impuestos ONAT</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>body { background-color: #f8f9fa; font-family: 'Segoe UI', sans-serif; } .card-tax { border-radius: 12px; border:none; box-shadow: 0 4px 15px rgba(0,0,0,0.05); } @media print { .no-print { display: none; } }</style>
</head>
<body>

<div class="container py-4">
    <?php if($msg): ?>
        <div class="alert alert-success alert-dismissible fade show no-print"><?php echo $msg; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="card card-tax mb-4 no-print">
        <div class="card-body p-4">
            <h5 class="fw-bold text-primary mb-3">Datos del Periodo (Ley 113)</h5>
            <form method="GET" class="row g-3">
                <div class="col-md-2"><label class="small fw-bold">Año</label><select name="anio" class="form-select"><option value="2025" selected>2025</option><option value="2026">2026</option></select></div>
                <div class="col-md-2"><label class="small fw-bold">Mes</label><select name="mes" class="form-select"><?php for($m=1;$m<=12;$m++) echo "<option value='$m' ".($m==$mes?'selected':'').">{$meses[$m]}</option>"; ?></select></div>
                <div class="col-md-3"><label class="small fw-bold">Entidad</label><select name="tipo" class="form-select"><option value="MIPYME">MIPYME (S.R.L)</option><option value="TCP">TCP</option></select></div>
                <div class="col-md-3"><label class="small fw-bold text-danger">Nómina Total ($)</label><input type="number" name="nomina" class="form-control" value="<?php echo $gastos_nomina; ?>" required></div>
                <div class="col-md-2"><label class="small fw-bold">Otros Gastos ($)</label><input type="number" name="otros_gastos" class="form-control" value="<?php echo $gastos_otros; ?>"></div>
                <div class="col-12 text-end"><button type="submit" class="btn btn-primary fw-bold"><i class="fas fa-sync-alt"></i> CALCULAR</button></div>
            </form>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8 mx-auto">
            <div class="card card-tax border-top border-5 border-primary">
                <div class="card-header bg-white py-3 d-flex justify-content-between">
                    <h4 class="mb-0 fw-bold">Cálculo de Tributos</h4>
                    <span class="badge bg-primary fs-6"><?php echo $meses[$mes]." ".$anio; ?></span>
                </div>
                <div class="card-body p-4">
                    <div class="row text-center mb-4 border-bottom pb-3">
                        <div class="col-4"><small class="text-muted">INGRESOS</small><div class="h5 text-success">$<?php echo number_format($ingresos_brutos,2); ?></div></div>
                        <div class="col-4"><small class="text-muted">COSTOS</small><div class="h5 text-danger">$<?php echo number_format($costo_ventas_auto,2); ?></div></div>
                        <div class="col-4"><small class="text-muted">UTILIDAD BASE</small><div class="h5 fw-bold">$<?php echo number_format($utilidad_base,2); ?></div></div>
                    </div>

                    <h6 class="text-primary fw-bold mb-3">OBLIGACIONES A PAGAR</h6>
                    <ul class="list-group list-group-flush mb-4">
                        <li class="list-group-item d-flex justify-content-between"><span>Impuesto s/Ventas (10%)</span><strong>$<?php echo number_format($impuesto_ventas,2); ?></strong></li>
                        <li class="list-group-item d-flex justify-content-between"><span>Contribución Local (1%)</span><strong>$<?php echo number_format($contribucion_local,2); ?></strong></li>
                        <li class="list-group-item d-flex justify-content-between bg-light"><span>Fuerza de Trabajo (5%)</span><strong>$<?php echo number_format($impuesto_fuerza,2); ?></strong></li>
                        <li class="list-group-item d-flex justify-content-between bg-light"><span>Seguridad Social (Aporte Patronal)</span><strong>$<?php echo number_format($seg_social_especial+$seg_social,2); ?></strong></li>
                        <li class="list-group-item d-flex justify-content-between border-top border-2"><span>Impuesto s/Utilidades (35%)</span><strong>$<?php echo number_format($impuesto_utilidades,2); ?></strong></li>
                    </ul>

                    <div class="bg-primary text-white p-3 rounded d-flex justify-content-between align-items-center mb-3">
                        <h4 class="mb-0">TOTAL A PAGAR</h4>
                        <h2 class="mb-0">$<?php echo number_format($total_a_pagar,2); ?></h2>
                    </div>

                    <form method="POST" class="no-print">
                        <input type="hidden" name="action" value="guardar">
                        <input type="hidden" name="anio" value="<?php echo $anio; ?>">
                        <input type="hidden" name="mes" value="<?php echo $mes; ?>">
                        <input type="hidden" name="tipo" value="<?php echo $tipo_entidad; ?>">
                        <input type="hidden" name="ingresos_brutos" value="<?php echo $ingresos_brutos; ?>">
                        <input type="hidden" name="costo_ventas" value="<?php echo $costo_ventas_auto; ?>">
                        <input type="hidden" name="nomina" value="<?php echo $gastos_nomina; ?>">
                        <input type="hidden" name="otros_gastos" value="<?php echo $gastos_otros; ?>">
                        <input type="hidden" name="imp_ventas" value="<?php echo $impuesto_ventas; ?>">
                        <input type="hidden" name="imp_fuerza" value="<?php echo $impuesto_fuerza; ?>">
                        <input type="hidden" name="contrib_local" value="<?php echo $contribucion_local; ?>">
                        <input type="hidden" name="seg_social" value="<?php echo $seg_social; ?>">
                        <input type="hidden" name="seg_social_especial" value="<?php echo $seg_social_especial; ?>">
                        <input type="hidden" name="imp_utilidades" value="<?php echo $impuesto_utilidades; ?>">
                        <input type="hidden" name="total_pagar" value="<?php echo $total_a_pagar; ?>">

                        <button type="submit" class="btn btn-success w-100 py-2 fw-bold" onclick="return confirm('Esto generará los asientos contables de deuda. ¿Continuar?')">
                            <i class="fas fa-check-circle"></i> GUARDAR Y CONTABILIZAR
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row mt-5 no-print"><div class="col-12"><div class="table-responsive bg-white rounded shadow-sm p-2"><table class="table table-hover"><thead class="table-light"><tr><th>Periodo</th><th>Entidad</th><th class="text-end">Ventas</th><th class="text-end">Total ONAT</th></tr></thead><tbody><?php foreach($lista_decl as $d): ?><tr><td><?php echo $meses[$d['mes']]." ".$d['anio']; ?></td><td><?php echo $d['tipo_entidad']; ?></td><td class="text-end">$<?php echo number_format($d['ingresos_brutos'],2); ?></td><td class="text-end fw-bold text-danger">$<?php echo number_format($d['total_pagar'],2); ?></td></tr><?php endforeach; ?></tbody></table></div></div></div>
</div>
<script src="assets/js/bootstrap.bundle.min.js"></script>
<?php include_once 'menu_master.php'; ?>
</body></html>
