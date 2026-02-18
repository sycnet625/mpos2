<?php
// ARCHIVO: /var/www/palweb/api/pos_accounting.php
// VERSIÓN: 9.4 (SYNC COMPLETO: VENTAS + GASTOS + IMPUESTOS + COMPRAS + MERMAS)

ini_set('display_errors', 0);
require_once 'db.php';

// 1. CONFIGURACIÓN
require_once 'config_loader.php';
$ALM_ID = intval($config['id_almacen']);
$SUC_ID = intval($config['id_sucursal']);
$EMP_ID = intval($config['id_empresa']);

// 2. HELPERS
function getNombreCuenta($pdo, $codigo) { try { $stmt = $pdo->prepare("SELECT nombre FROM contabilidad_cuentas WHERE codigo = ?"); $stmt->execute([$codigo]); return $stmt->fetchColumn() ?: 'Cuenta General'; } catch (Exception $e) { return 'General'; } }
function getCajaSucursal($pdo, $sucId) { return "101." . str_pad($sucId, 3, "0", STR_PAD_LEFT); }
function getPeriodStatus($pdo, $mes, $anio) {
    $stmt = $pdo->query("SELECT valor FROM contabilidad_config WHERE clave='fecha_cierre'");
    $fechaCierre = ($stmt) ? $stmt->fetchColumn() : '2000-01-01';
    if ($fechaCierre == '2000-01-01') return 'SIN_INICIAR';
    $inicioMes = sprintf('%04d-%02d-01', $anio, $mes);
    $finMes = date("Y-m-t", strtotime($inicioMes));
    $finMesAnt = date("Y-m-t", strtotime("-1 month", strtotime($inicioMes)));
    if ($fechaCierre >= $finMes) return 'CERRADO';
    if ($fechaCierre >= $finMesAnt) return 'ABIERTO';
    return 'FUTURO';
}
function validateMonthIsOpen($pdo, $fechaOperacion) {
    $mes = intval(date('m', strtotime($fechaOperacion)));
    $anio = intval(date('Y', strtotime($fechaOperacion)));
    $status = getPeriodStatus($pdo, $mes, $anio);
    if ($status === 'SIN_INICIAR') throw new Exception("Debe establecer este mes como INICIAL antes de guardar datos.");
    if ($status === 'CERRADO') throw new Exception("Error: El mes está CERRADO.");
    if ($status === 'FUTURO') throw new Exception("Error: Mes FUTURO bloqueado. Cierre el mes anterior.");
}
function insertAsiento($pdo, $fecha, $tipo, $ref, $cuenta, $debe, $haber, $detalle) { $stmt = $pdo->prepare("INSERT INTO contabilidad_diario (fecha, asiento_tipo, referencia_id, cuenta, debe, haber, detalle) VALUES (?, ?, ?, ?, ?, ?, ?)"); $stmt->execute([$fecha, $tipo, $ref, $cuenta, $debe, $haber, $detalle]); }
function mapearCuentaGasto($categoria) { switch (strtoupper($categoria)) { case 'NOMINA': return '701'; case 'SERVICIOS': return '702'; case 'MANTENIMIENTO': return '703'; case 'RENTA': return '705'; case 'LIMPIEZA': return '706'; case 'IMPUESTOS': return '704'; default: return '709'; } }

// 3. API BACKEND
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $_GET['action'] ?? '';

    try {
        // --- SYNC MENSUAL INTEGRAL ---
        if ($action === 'sync_month') {
            $mes = intval($input['mes']); $anio = intval($input['anio']);
            $scope = $input['scope'] ?? 'local'; 
            $autoOnat = $input['auto_onat'] ?? true;

            $fi = sprintf('%04d-%02d-01', $anio, $mes); $ff = date("Y-m-t", strtotime($fi));
            validateMonthIsOpen($pdo, $ff);
            $pdo->beginTransaction();

            // 1. LIMPIEZA TOTAL (Incluye COMPRA y MERMA ahora)
            $pdo->prepare("DELETE FROM contabilidad_diario WHERE fecha BETWEEN ? AND ? AND asiento_tipo IN ('VENTA','COSTO','GASTO','VENTA_CASA','IMPUESTOS','COMPRA','MERMA')")->execute([$fi, $ff]);
            
            // PREPARAR FILTROS SCOPE
            $sqlVentas = "SELECT * FROM ventas_cabecera WHERE fecha BETWEEN '$fi 00:00:00' AND '$ff 23:59:59'";
            $sqlGastos = "SELECT * FROM gastos_historial WHERE fecha BETWEEN '$fi' AND '$ff'";
            $sqlCompras = "SELECT * FROM compras_cabecera WHERE fecha BETWEEN '$fi 00:00:00' AND '$ff 23:59:59' AND estado != 'CANCELADA'";
            $sqlMermas = "SELECT * FROM mermas_cabecera WHERE fecha BETWEEN '$fi 00:00:00' AND '$ff 23:59:59'"; // Asumiendo columna fecha

            if ($scope === 'local') {
                $sqlVentas .= " AND id_sucursal = $SUC_ID";
                $sqlGastos .= " AND id_sucursal = $SUC_ID"; 
                // Intentar filtrar Compras/Mermas por sucursal si existe la columna
                try { $pdo->query("SELECT id_sucursal FROM compras_cabecera LIMIT 1"); $sqlCompras .= " AND id_sucursal=$SUC_ID"; } catch(Exception $e){}
                try { $pdo->query("SELECT id_sucursal FROM mermas_cabecera LIMIT 1"); $sqlMermas .= " AND id_sucursal=$SUC_ID"; } catch(Exception $e){}
            } else {
                $sqlVentas .= " AND id_empresa = $EMP_ID";
                // Global attempts
                try { $pdo->query("SELECT id_empresa FROM gastos_historial LIMIT 1"); $sqlGastos .= " AND id_empresa=$EMP_ID"; } catch(Exception $e){}
                try { $pdo->query("SELECT id_empresa FROM compras_cabecera LIMIT 1"); $sqlCompras .= " AND id_empresa=$EMP_ID"; } catch(Exception $e){}
                try { $pdo->query("SELECT id_empresa FROM mermas_cabecera LIMIT 1"); $sqlMermas .= " AND id_empresa=$EMP_ID"; } catch(Exception $e){}
            }

            // A. VENTAS
            $subCaja = getCajaSucursal($pdo, $SUC_ID); $nomCaja = getNombreCuenta($pdo, $subCaja);
            $vtas = $pdo->query($sqlVentas)->fetchAll(PDO::FETCH_ASSOC);
            foreach($vtas as $v){
                $f=date('Y-m-d',strtotime($v['fecha'])); $t=floatval($v['total']); 
                $c=$pdo->query("SELECT SUM(d.cantidad*p.costo) FROM ventas_detalle d JOIN productos p ON d.id_producto=p.codigo WHERE d.id_venta_cabecera={$v['id']}")->fetchColumn()?:0;
                if($v['metodo_pago']==='Gasto Casa'){
                    insertAsiento($pdo,$f,'VENTA_CASA',$v['id'],'709 - Retiro Dueño',$c,0,"Consumo Casa #{$v['id']}");
                    insertAsiento($pdo,$f,'VENTA_CASA',$v['id'],'120 - Inventario',0,$c,"Salida Inv.");
                } else {
                    $cD = ($v['metodo_pago']==='Transferencia')?'102 - Banco':"$subCaja - $nomCaja";
                    insertAsiento($pdo,$f,'VENTA',$v['id'],$cD,$t,0,"Venta #{$v['id']}");
                    insertAsiento($pdo,$f,'VENTA',$v['id'],'401 - Ingresos',0,$t,"Venta #{$v['id']}");
                    insertAsiento($pdo,$f,'COSTO',$v['id'],'501 - Costo',$c,0,"Costo Venta");
                    insertAsiento($pdo,$f,'COSTO',$v['id'],'120 - Inventario',0,$c,"Baja Stock");
                }
            }

            // B. GASTOS
            $gts = $pdo->query($sqlGastos)->fetchAll(PDO::FETCH_ASSOC);
            foreach($gts as $g){
                $ctaG = ($g['categoria']==='NOMINA')?"701 - Gastos de Personal":(mapearCuentaGasto($g['categoria'])." - ".getNombreCuenta($pdo, mapearCuentaGasto($g['categoria'])));
                insertAsiento($pdo,$g['fecha'],'GASTO',$g['id'],$ctaG,$g['monto'],0,$g['concepto']);
                insertAsiento($pdo,$g['fecha'],'GASTO',$g['id'],"101 - Efectivo",0,$g['monto'],"Pago: ".$g['categoria']);
            }

            // C. IMPUESTOS
            if ($autoOnat) {
                $sqlOnat = "SELECT * FROM declaraciones_onat WHERE mes=$mes AND anio=$anio";
                if($scope === 'local') $sqlOnat .= " AND id_empresa=$EMP_ID"; // ONAT siempre es por empresa o local si aplica
                try {
                    $decl = $pdo->query($sqlOnat);
                    while($d=$decl->fetch(PDO::FETCH_ASSOC)){
                        $regImp=function($cg,$cp,$m)use($pdo,$ff,$d){ if($m>0){ insertAsiento($pdo,$ff,'IMPUESTOS',$d['id'],$cg,$m,0,'Gasto Trib.'); insertAsiento($pdo,$ff,'IMPUESTOS',$d['id'],$cp,0,$m,'Pasivo ONAT'); }};
                        $regImp('704.1 - Gasto Ventas','240.1 - Imp. Ventas',$d['imp_ventas']);
                        $regImp('704.2 - Gasto Fuerza','240.3 - Fuerza Trabajo',$d['imp_fuerza']);
                        $regImp('704.3 - Gasto Local','240.2 - Contrib. Local',$d['contrib_local']);
                        $regImp('704.4 - Gasto SS','240.5 - Seg. Social',$d['seg_social']+$d['seg_social_especial']);
                        $regImp('704.5 - Gasto Util','240.4 - Imp. Utilidades',$d['imp_utilidades']);
                    }
                } catch(Exception $e) {}
            }

            // D. COMPRAS (NUEVO)
            // DEBE 120 (Entra Inv) / HABER 101 (Sale Dinero/Pago)
            try {
                $compras = $pdo->query($sqlCompras)->fetchAll(PDO::FETCH_ASSOC);
                foreach($compras as $cp) {
                    $f = date('Y-m-d', strtotime($cp['fecha']));
                    $total = floatval($cp['total']);
                    if($total > 0) {
                        insertAsiento($pdo, $f, 'COMPRA', $cp['id'], "120 - Inventario Mercancías", $total, 0, "Compra #{$cp['id']} - " . $cp['proveedor']);
                        insertAsiento($pdo, $f, 'COMPRA', $cp['id'], "101 - Efectivo", 0, $total, "Pago Compra #{$cp['id']}");
                    }
                }
            } catch(Exception $e) { /* Ignorar si falla query por columnas faltantes */ }

            // E. MERMAS (NUEVO)
            // DEBE 708 (Gasto Merma) / HABER 120 (Baja Inv)
            try {
                $mermas = $pdo->query($sqlMermas)->fetchAll(PDO::FETCH_ASSOC);
                foreach($mermas as $mm) {
                    $fRaw = $mm['fecha'] ?? ($mm['fecha_registro'] ?? ($mm['created_at'] ?? $fi));
                    $f = date('Y-m-d', strtotime($fRaw));
                    $total = floatval($mm['total_costo_perdida']);
                    if($total > 0) {
                        insertAsiento($pdo, $f, 'MERMA', $mm['id'], "708 - Gastos por Mermas", $total, 0, "Pérdida Merma #{$mm['id']}");
                        insertAsiento($pdo, $f, 'MERMA', $mm['id'], "120 - Inventario Mercancías", 0, $total, "Baja por Merma");
                    }
                }
            } catch(Exception $e) { }

            $pdo->commit(); 
            echo json_encode(['status'=>'success','msg'=>"Sincronizado $mes/$anio ($scope). Incluye Compras y Mermas."]); 
            exit;
        }

        // --- AJUSTAR INVENTARIO ---
        if ($action === 'adjust_inventory') {
            $fecha = $input['fecha']; $scope = $input['scope'] ?? 'local'; validateMonthIsOpen($pdo, $fecha);
            $sqlReal = "SELECT SUM(s.cantidad * p.costo) FROM stock_almacen s JOIN productos p ON s.id_producto = p.codigo WHERE p.activo=1";
            if ($scope === 'local') $sqlReal .= " AND s.id_almacen=$ALM_ID"; 
            $valorReal = floatval($pdo->query($sqlReal)->fetchColumn() ?: 0);
            $valorContable = floatval($pdo->query("SELECT SUM(debe) - SUM(haber) FROM contabilidad_diario WHERE cuenta LIKE '120%'")->fetchColumn() ?: 0);
            $diferencia = $valorReal - $valorContable;
            if (abs($diferencia) > 0.01) {
                $pdo->beginTransaction();
                if ($diferencia > 0) {
                    insertAsiento($pdo, $fecha, 'AJUSTE', 0, "120 - Inventario Mercancías", $diferencia, 0, "Ajuste Inv. ($scope)");
                    insertAsiento($pdo, $fecha, 'AJUSTE', 0, "630 - Utilidades del Periodo", 0, $diferencia, "Ganancia por Ajuste Stock");
                } else {
                    insertAsiento($pdo, $fecha, 'AJUSTE', 0, "630 - Utilidades del Periodo", abs($diferencia), 0, "Pérdida por Ajuste Stock");
                    insertAsiento($pdo, $fecha, 'AJUSTE', 0, "120 - Inventario Mercancías", 0, abs($diferencia), "Corrección Inv.");
                }
                $pdo->commit();
                echo json_encode(['status'=>'success', 'msg'=>"Ajuste realizado: $".number_format($diferencia,2)]);
            } else { echo json_encode(['status'=>'success', 'msg'=>'Inventario ya está cuadrado.']); } exit;
        }

        // --- ACCIONES ESTÁNDAR ---
        if ($action === 'get_period_status') { $mes = intval($input['mes']); $anio = intval($input['anio']); echo json_encode(['status'=>'success', 'estado'=>getPeriodStatus($pdo, $mes, $anio)]); exit; }
        if ($action === 'set_initial_month') { $mes = intval($input['mes']); $anio = intval($input['anio']); $c = date("Y-m-t", strtotime("-1 month", strtotime(sprintf('%04d-%02d-01', $anio, $mes)))); $pdo->prepare("INSERT INTO contabilidad_config (clave, valor) VALUES ('fecha_cierre', ?) ON DUPLICATE KEY UPDATE valor = ?")->execute([$c, $c]); echo json_encode(['status'=>'success', 'msg'=>"Iniciado en $mes/$anio."]); exit; }
        if ($action === 'save_balances') { validateMonthIsOpen($pdo, $input['fecha']); $pdo->prepare("INSERT INTO contabilidad_saldos (fecha, caja_fuerte, banco, observaciones) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE caja_fuerte=?, banco=?, observaciones=?")->execute([$input['fecha'], floatval($input['caja']), floatval($input['banco']), $input['obs'], floatval($input['caja']), floatval($input['banco']), $input['obs']]); echo json_encode(['status'=>'success']); exit; }
        if ($action === 'equity_op') { validateMonthIsOpen($pdo, $input['fecha']); $m = floatval($input['monto']); if($input['tipo_op'] === 'APORTE') { insertAsiento($pdo, $input['fecha'], 'CAPITAL', 0, "101 - Efectivo", $m, 0, "Aporte"); insertAsiento($pdo, $input['fecha'], 'CAPITAL', 0, "601 - Capital", 0, $m, "Suscripción"); } else { insertAsiento($pdo, $input['fecha'], 'DIVIDENDO', 0, "630 - Utilidades", $m, 0, "Dividendo"); insertAsiento($pdo, $input['fecha'], 'DIVIDENDO', 0, "101 - Efectivo", 0, $m, "Pago Socio"); } echo json_encode(['status'=>'success']); exit; }
        if ($action === 'close_month_execute') { $mes=intval($input['mes']); $anio=intval($input['anio']); if(getPeriodStatus($pdo,$mes,$anio)!=='ABIERTO') throw new Exception("Estado incorrecto."); $fin=date("Y-m-t",strtotime(sprintf('%04d-%02d-01',$anio,$mes))); $pdo->prepare("INSERT INTO contabilidad_config (clave,valor) VALUES ('fecha_cierre',?) ON DUPLICATE KEY UPDATE valor=?")->execute([$fin,$fin]); echo json_encode(['status'=>'success','msg'=>'Mes Cerrado.']); exit; }
        if ($action === 'check_close_requirements') { $mes=intval($input['mes']); $anio=intval($input['anio']); $w=[]; if($pdo->query("SELECT COUNT(*) FROM gastos_historial WHERE MONTH(fecha)=$mes AND YEAR(fecha)=$anio AND categoria='NOMINA'")->fetchColumn()==0) $w[]="Falta NÓMINA."; if($pdo->query("SELECT COUNT(*) FROM declaraciones_onat WHERE mes=$mes AND anio=$anio")->fetchColumn()==0) $w[]="Falta ONAT."; echo json_encode(['status'=>'success','warnings'=>$w]); exit; }
        if ($action === 'close_year') { $y=$input['year']; $fin="$y-12-31"; if(getPeriodStatus($pdo,12,$y)!=='ABIERTO') throw new Exception("Debe cerrar meses previos."); $res=floatval($pdo->query("SELECT SUM(haber-debe) FROM contabilidad_diario WHERE YEAR(fecha)=$y AND (cuenta LIKE '4%' OR cuenta LIKE '5%' OR cuenta LIKE '7%')")->fetchColumn()); $pdo->beginTransaction(); if($res>0){ insertAsiento($pdo,$fin,'CIERRE',0,"400 - Resumen",$res,0,"Cierre Nominales"); insertAsiento($pdo,$fin,'CIERRE',0,"630 - Utilidades",0,$res,"Utilidad $y"); $r=$res*0.05; insertAsiento($pdo,$fin,'RESERVA',0,"630 - Utilidades",$r,0,"Reserva"); insertAsiento($pdo,$fin,'RESERVA',0,"640 - Reservas",0,$r,"Legal"); } else { insertAsiento($pdo,$fin,'CIERRE',0,"630 - Utilidades",abs($res),0,"Pérdida"); insertAsiento($pdo,$fin,'CIERRE',0,"400 - Resumen",0,abs($res),"Cierre"); } $pdo->prepare("UPDATE contabilidad_config SET valor=? WHERE clave='fecha_cierre'")->execute([$fin]); $pdo->commit(); echo json_encode(['status'=>'success','msg'=>"Año $y cerrado."]); exit; }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]); exit;
    }
}

// 4. VISTA (SCOPED)
$fh = date('Y-m-d'); $ff = $_GET['fecha'] ?? $fh; $scope = $_GET['scope'] ?? 'local'; $errView = "";
try {
    $qInv = "SELECT SUM(s.cantidad*p.costo) FROM stock_almacen s JOIN productos p ON s.id_producto=p.codigo WHERE p.activo=1";
    $qVta = "SELECT COALESCE(SUM(total),0) FROM ventas_cabecera WHERE 1=1";
    $qGas = "SELECT COALESCE(SUM(monto),0) as total FROM gastos_historial WHERE 1=1";

    if ($scope === 'local') {
        $qInv .= " AND s.id_almacen=$ALM_ID";
        $qVta .= " AND id_sucursal=$SUC_ID"; 
        $qGas .= " AND id_sucursal=$SUC_ID"; 
    } 

    $invVal = floatval($pdo->query($qInv)->fetchColumn()?:0);
    $mi=date('Y-m-01',strtotime($ff)); $mf=date('Y-m-t',strtotime($ff));
    
    $qVta .= " AND fecha BETWEEN '$mi 00:00:00' AND '$mf 23:59:59'";
    $qGas .= " AND fecha BETWEEN '$mi' AND '$mf'";

    $vtaM = $pdo->query($qVta)->fetchColumn();
    $resM = $pdo->query($qGas)->fetch(PDO::FETCH_ASSOC);

    $saldos = $pdo->prepare("SELECT * FROM contabilidad_saldos WHERE fecha=?"); $saldos->execute([$ff]); $saldos=$saldos->fetch(PDO::FETCH_ASSOC)?:['caja_fuerte'=>0,'banco'=>0,'observaciones'=>''];
    $diario = $pdo->prepare("SELECT * FROM contabilidad_diario WHERE fecha=? ORDER BY id ASC"); $diario->execute([$ff]); $diarioRows=$diario->fetchAll(PDO::FETCH_ASSOC);
    $gastosD = $pdo->prepare("SELECT * FROM gastos_historial WHERE fecha=? ORDER BY tipo, monto DESC"); $gastosD->execute([$ff]); $gastosDia=$gastosD->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) { $errView=$e->getMessage(); $invVal=0; $saldos=['caja_fuerte'=>0,'banco'=>0,'observaciones'=>'']; $diarioRows=[]; $gastosDia=[]; $resM=['total'=>0]; $vtaM=0; }

$jC=floatval($saldos['caja_fuerte']); $jB=floatval($saldos['banco']); $jO=$saldos['observaciones']; $act=$jC+$jB+$invVal; $pas=0; $cap=$act-$pas;
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Contabilidad</title><link href="assets/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="assets/css/all.min.css"><script src="assets/js/vue.min.js"></script><style>.card-stat{border-radius:12px;border:none;box-shadow:0 4px 6px rgba(0,0,0,0.05)} .month-selector{background:#343a40;padding:10px;color:white;border-radius:0 0 10px 10px;margin-bottom:20px}</style></head><body class="pb-5 bg-light"><div id="app" class="container-fluid px-4">
<div class="row month-selector align-items-center">
    <div class="col-md-3"><i class="fas fa-calendar-alt text-warning me-2"></i><span class="fw-bold">PERIODO:</span></div>
    <div class="col-md-4 d-flex gap-2">
        <select class="form-select form-select-sm fw-bold" v-model="selMes" @change="checkStatus"><option value="1">Enero</option><option value="2">Febrero</option><option value="3">Marzo</option><option value="4">Abril</option><option value="5">Mayo</option><option value="6">Junio</option><option value="7">Julio</option><option value="8">Agosto</option><option value="9">Septiembre</option><option value="10">Octubre</option><option value="11">Noviembre</option><option value="12">Diciembre</option></select>
        <select class="form-select form-select-sm fw-bold" style="width:100px" v-model="selAnio" @change="checkStatus"><option value="2025">2025</option><option value="2026">2026</option></select>
    </div>
    <div class="col-md-5 text-end d-flex justify-content-end align-items-center gap-2">
        <div class="btn-group btn-group-sm" role="group">
            <button type="button" class="btn" :class="scope=='local'?'btn-light fw-bold':'btn-outline-secondary text-white'" @click="changeScope('local')">🏢 Sucursal</button>
            <button type="button" class="btn" :class="scope=='global'?'btn-light fw-bold':'btn-outline-secondary text-white'" @click="changeScope('global')">🌍 Empresa</button>
        </div>
        <span class="badge me-1 ms-2" :class="{'bg-success':status=='ABIERTO','bg-danger':status=='CERRADO','bg-secondary':status=='FUTURO','bg-info text-dark':status=='SIN_INICIAR'}">{{status}}</span>
        <button v-if="status=='ABIERTO'" class="btn btn-warning btn-sm fw-bold" @click="closeMonth"><i class="fas fa-lock me-1"></i> CERRAR</button>
        <button v-if="status=='SIN_INICIAR'" class="btn btn-primary btn-sm fw-bold" @click="setInitial">INICIAR</button>
    </div>
</div>

<div class="d-flex justify-content-between mb-4"><h3><i class="fas fa-balance-scale"></i> Contabilidad <small class="text-muted fs-6">({{ scope == 'local' ? 'Sucursal Actual' : 'Consolidado Empresa' }})</small></h3><div><a href="accounting_accounts.php" class="btn btn-dark btn-sm">Cuentas</a> <a href="dashboard.php" class="btn btn-primary btn-sm">Salir</a></div></div>
<div v-if="errorDb" class="alert alert-danger">{{errorDb}}</div>
<ul class="nav nav-tabs mb-3"><li class="nav-item"><a class="nav-link" :class="{active:tab=='diario'}" @click="tab='diario'" href="#">Operaciones</a></li><li class="nav-item"><a class="nav-link text-success fw-bold" :class="{active:tab=='patrimonio'}" @click="tab='patrimonio'" href="#">Patrimonio (PC-28)</a></li></ul>
<div v-if="tab=='diario'"><div class="row g-4 mb-4"><div class="col-md-4"><div class="card card-stat border-start border-4 border-primary p-3"><h6 class="text-primary fw-bold">ACTIVO ({{scope}})</h6><h3>$<?php echo number_format($act,2); ?></h3></div></div><div class="col-md-4"><div class="card card-stat border-start border-4 border-danger p-3"><h6 class="text-danger fw-bold">PASIVO</h6><h3>$<?php echo number_format($pas,2); ?></h3></div></div><div class="col-md-4"><div class="card card-stat border-start border-4 border-success p-3"><h6 class="text-success fw-bold">PATRIMONIO</h6><h3>$<?php echo number_format($cap,2); ?></h3></div></div></div><div class="row"><div class="col-lg-4"><div class="card shadow-sm mb-4"><div class="card-header bg-white fw-bold">Arqueo Diario</div><div class="card-body"><input type="date" class="form-control mb-2" v-model="fecha" @change="reload" :disabled="status!='ABIERTO'"><div class="input-group mb-2"><span class="input-group-text">Caja</span><input type="number" class="form-control" v-model.number="saldos.caja_fuerte" :disabled="status!='ABIERTO'"></div><div class="input-group mb-2"><span class="input-group-text">Banco</span><input type="number" class="form-control" v-model.number="saldos.banco" :disabled="status!='ABIERTO'"></div><button class="btn btn-success w-100" @click="saveSaldos" :disabled="status!='ABIERTO'">Guardar</button></div></div><div class="card shadow-sm mb-4"><div class="card-header bg-white fw-bold d-flex justify-content-between"><span class="text-danger">Gastos Hoy</span><span class="badge bg-secondary"><?php echo count($gastosDia); ?></span></div><div class="table-responsive" style="max-height:250px"><table class="table table-sm table-striped mb-0 small"><tbody><?php $tg=0; foreach($gastosDia as $g): $tg+=$g['monto']; ?><tr><td><?php echo substr($g['concepto'],0,20); ?></td><td class="text-end fw-bold text-danger">$<?php echo number_format($g['monto'],2); ?></td></tr><?php endforeach; ?></tbody><tfoot class="fw-bold"><tr><td>TOTAL</td><td class="text-end">$<?php echo number_format($tg,2); ?></td></tr></tfoot></table></div><div class="card-footer text-center"><a href="pos_expenses.php" class="btn btn-link btn-sm">Gestión Gastos</a></div></div></div><div class="col-lg-8"><div class="card shadow-sm mb-3 bg-light border-0"><div class="card-body py-2 d-flex justify-content-around text-center"><div><small>VENTAS MES</small><div class="h5 text-success fw-bold">$<?php echo number_format($vtaM,2); ?></div></div><div><small>GASTOS MES</small><div class="h5 text-danger fw-bold">$<?php echo number_format($resM['total'],2); ?></div></div><div><small>RESULTADO</small><div class="h5 fw-bold">$<?php echo number_format($vtaM-$resM['total'],2); ?></div></div></div></div><div class="card shadow-sm h-100">
<div class="card-header bg-white d-flex justify-content-between align-items-center">
    <h6 class="m-0 text-primary">Libro Diario (<?php echo date('d/m/Y',strtotime($ff)); ?>)</h6>
    <div class="d-flex align-items-center gap-2">
        <div class="form-check form-switch pt-1" title="Calcular Impuestos Automáticamente">
            <input class="form-check-input" type="checkbox" v-model="autoOnat">
            <label class="form-check-label small fw-bold text-muted">ONAT</label>
        </div>
        <button class="btn btn-primary btn-sm" @click="syncMonth" :disabled="status!='ABIERTO'" title="Sync Mes"><i class="fas fa-sync"></i> Sync</button>
        <button class="btn btn-secondary btn-sm" @click="print"><i class="fas fa-print"></i> Reportes</button>
    </div>
</div><div class="table-responsive"><table class="table table-sm table-hover mb-0 small"><thead class="table-light"><tr><th>Cuenta</th><th>Detalle</th><th class="text-end">Debe</th><th class="text-end">Haber</th></tr></thead><tbody><?php foreach($diarioRows as $d): ?><tr><td><?php echo $d['cuenta']; ?></td><td><?php echo $d['detalle']; ?></td><td class="text-end"><?php echo $d['debe']>0?number_format($d['debe'],2):'-'; ?></td><td class="text-end"><?php echo $d['haber']>0?number_format($d['haber'],2):'-'; ?></td></tr><?php endforeach; ?></tbody></table></div></div></div></div></div>
<div v-if="tab=='patrimonio'"><div class="row g-4"><div class="col-md-4"><div class="card border-primary p-3"><h6>Aportes Capital</h6><input type="number" class="form-control mb-2" v-model.number="formPat.aporte" :disabled="status!='ABIERTO'"><button class="btn btn-primary w-100" @click="op('APORTE')" :disabled="status!='ABIERTO'">Registrar</button></div></div><div class="col-md-4"><div class="card border-success p-3"><h6>Dividendos</h6><input type="number" class="form-control mb-2" v-model.number="formPat.dividendo" :disabled="status!='ABIERTO'"><button class="btn btn-success w-100" @click="op('DIVIDENDO')" :disabled="status!='ABIERTO'">Pagar</button></div></div><div class="col-md-4"><div class="card border-danger p-3 mb-2"><h6>Cierre Año Fiscal</h6><button class="btn btn-danger w-100" @click="closeYear">Cerrar Año Actual</button></div><div class="card border-warning p-3"><h6>Ajuste Inventario</h6><p class="small text-muted mb-2">Sincroniza stock físico con contable.</p><button class="btn btn-warning w-100 fw-bold" @click="adjustInventory" :disabled="status!='ABIERTO'"><i class="fas fa-tools"></i> AJUSTAR INVENTARIO</button></div></div></div></div></div>
<script>
new Vue({
    el:'#app',
    data:{
        tab:'diario', fecha:<?php echo json_encode($ff); ?>,
        saldos:{caja_fuerte:<?php echo json_encode($jsCaja); ?>, banco:<?php echo json_encode($jsBanco); ?>, obs:<?php echo json_encode($jO); ?>},
        formPat:{aporte:0, dividendo:0}, errorDb:<?php echo json_encode($errorVista); ?>,
        selMes:parseInt("<?php echo date('m',strtotime($ff)); ?>"), selAnio:parseInt("<?php echo date('Y',strtotime($ff)); ?>"), status:'ABIERTO',
        scope:<?php echo json_encode($scope); ?>, 
        autoOnat: true
    },
    mounted(){ this.checkStatus(); },
    methods:{
        changeScope(newScope) { this.scope = newScope; this.reload(); },
        reload(){ const p=this.fecha.split('-'); if(parseInt(p[1])!==this.selMes||parseInt(p[0])!==this.selAnio){alert("Fecha fuera del periodo."); this.fecha=`${this.selAnio}-${String(this.selMes).padStart(2,'0')}-01`;} window.location.href=`pos_accounting.php?fecha=${this.fecha}&scope=${this.scope}`; },
        async api(act,d){ return (await fetch(`pos_accounting.php?action=${act}`,{method:'POST',body:JSON.stringify(d)})).json(); },
        async checkStatus(){ const p=this.fecha.split('-'); if(parseInt(p[1])!==this.selMes||parseInt(p[0])!==this.selAnio){ window.location.href=`pos_accounting.php?fecha=${this.selAnio}-${String(this.selMes).padStart(2,'0')}-01&scope=${this.scope}`; return; } this.status=(await this.api('get_period_status',{mes:this.selMes,anio:this.selAnio})).estado; },
        async setInitial(){ if(!confirm(`¿Iniciar en ${this.selMes}/${this.selAnio}?`))return; const r=await this.api('set_initial_month',{mes:this.selMes,anio:this.selAnio}); alert(r.msg); this.checkStatus(); },
        async syncMonth(){ 
            if(confirm("¿Sincronizar mes? " + (this.autoOnat ? "(Con Impuestos)" : "(Sin Impuestos)"))){ 
                const r=await this.api('sync_month',{mes:this.selMes,anio:this.selAnio,scope:this.scope,auto_onat:this.autoOnat}); 
                alert(r.msg||r.error); if(r.status==='success') location.reload(); 
            } 
        },
        async saveSaldos(){ await this.api('save_balances',{fecha:this.fecha,caja:this.saldos.caja_fuerte,banco:this.saldos.banco,obs:this.saldos.obs}); alert('Guardado'); },
        async op(t){ if(confirm('¿Confirmar?')){ await this.api('equity_op',{fecha:this.fecha,tipo_op:t,monto:(t=='APORTE'?this.formPat.aporte:this.formPat.dividendo)}); alert('Listo'); } },
        async adjustInventory(){ if(confirm(`¿Ajustar inventario (${this.scope})?`)){ const r=await this.api('adjust_inventory',{fecha:this.fecha,scope:this.scope}); alert(r.msg); if(r.status==='success') location.reload(); } },
        async closeMonth(){ if(!confirm(`¿Cerrar mes ${this.selMes}?`))return; const c=await this.api('check_close_requirements',{mes:this.selMes,anio:this.selAnio}); if(c.warnings.length>0 && !confirm("FALTAN DATOS:\n"+c.warnings.join("\n")+"\n¿Seguir?"))return; const r=await this.api('close_month_execute',{mes:this.selMes,anio:this.selAnio}); if(r.status==='success'){alert(r.msg); this.selMes++; this.checkStatus();}else alert(r.msg); },
        async closeYear(){ if(confirm(`¿Cerrar año ${this.selAnio}?`)){ const r=await this.api('close_year',{year:this.selAnio}); alert(r.msg||r.error); } },
        print(){ const s=`${this.selAnio}-${String(this.selMes).padStart(2,'0')}-01`; const e=new Date(this.selAnio,this.selMes,0).toISOString().split('T')[0]; window.open(`accounting_reports_print.php?start=${s}&end=${e}`, '_blank'); }
    }
});
</script><?php include_once 'menu_master.php'; ?>
</body></html>

