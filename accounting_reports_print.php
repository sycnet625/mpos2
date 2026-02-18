<?php
// ARCHIVO: /var/www/palweb/api/accounting_reports_print.php
// VERSIÓN 9.2: SUITE COMPLETA + FIX FECHAS + BOTÓN OCULTAR MENÚ

ini_set('display_errors', 0);
require_once 'db.php';

// 1. RECUPERAR FECHAS
$end = $_GET['end'] ?? date('Y-m-t');
$start = $_GET['start'] ?? date('Y-m-01', strtotime($end)); 

// --- HELPERS ---
function getSaldo($pdo, $codigo, $fecha, $naturaleza) {
    $sql = "SELECT SUM(debe) as d, SUM(haber) as h FROM contabilidad_diario WHERE fecha <= ? AND cuenta LIKE ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$fecha, $codigo . '%']); 
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    return ($naturaleza == 'D') ? ($r['d'] - $r['h']) : ($r['h'] - $r['d']);
}

function getMov($pdo, $codigo, $start, $end, $naturaleza) {
    $sql = "SELECT SUM(debe) as d, SUM(haber) as h FROM contabilidad_diario WHERE fecha BETWEEN ? AND ? AND cuenta LIKE ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$start, $end, $codigo . '%']);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    return ($naturaleza == 'D') ? ($r['d'] - $r['h']) : ($r['h'] - $r['d']);
}

function renderAccountRow($codigo, $nombre, $nivel, $saldo) {
    if (abs($saldo) < 0.01 && $nivel > 1) return;
    $padding = ($nivel - 1) * 20; 
    $style = ($nivel == 1) ? "font-weight:bold; background-color:#f9f9f9;" : "";
    $displaySaldo = ($saldo < 0) ? "(".number_format(abs($saldo), 2).")" : number_format($saldo, 2);
    echo "<tr style='{$style}'>";
    echo "<td style='padding-left: {$padding}px;'>{$codigo} - {$nombre}</td>";
    echo "<td class='text-right'>$ {$displaySaldo}</td>";
    echo "</tr>";
}

$type = $_GET['type'] ?? 'balance'; 
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8"><title>Reportes Financieros</title>
    <style>
        body { font-family: 'Times New Roman', serif; padding: 40px; font-size: 13px; color: #000; padding-bottom: 100px; }
        
        /* ESTILOS DEL MENÚ FLOTANTE */
        .no-print { 
            position: fixed; bottom: 20px; right: 20px; background: #fff; padding: 15px; 
            border: 2px solid #333; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.3); z-index: 9999; font-family: sans-serif;
        }
        .btn { 
            display: block; width: 100%; margin: 5px 0; padding: 8px; text-decoration: none; 
            color: #fff; background-color: #333; text-align: center; border-radius: 4px; font-size: 12px;
        }
        .btn:hover { background-color: #555; }
        .btn-print { background-color: #0d6efd; font-weight: bold; }
        
        /* Botón pequeño para mostrar menú */
        #minMenu { 
            display: none; padding: 10px 20px; font-weight: bold; cursor: pointer; background: #333; color: white; border: none;
        }

        /* ESTILOS DE REPORTE */
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #000; padding-bottom: 10px; }
        h1 { margin: 0; font-size: 22px; text-transform: uppercase; }
        p { margin: 2px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border-bottom: 1px dotted #ccc; padding: 5px; vertical-align: top; }
        .text-right { text-align: right; }
        .section-header { background: #eee; font-weight: bold; padding: 8px 5px; margin-top: 15px; border-top: 1px solid #000; border-bottom: 1px solid #000; }
        .total-row td { border-top: 2px solid #000; border-bottom: 4px double #000; font-weight: bold; font-size: 15px; padding: 8px 5px; }
        .subtotal-row td { border-top: 1px solid #000; font-weight: bold; background: #fafafa; }
        
        @media print { .no-print { display: none; } body { padding: 0; } }
    </style>
    <script>
        function toggleMenu() {
            var full = document.getElementById('fullMenu');
            var min = document.getElementById('minMenu');
            if(full.style.display === 'none') {
                full.style.display = 'block';
                min.style.display = 'none';
            } else {
                full.style.display = 'none';
                min.style.display = 'block';
            }
        }
    </script>
</head>
<body>

<div class="no-print" id="fullMenu">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
        <span style="font-weight:bold;">SELECCIONAR REPORTE</span>
        <button onclick="toggleMenu()" style="cursor:pointer; border:none; background:none; font-size:16px;" title="Ocultar Menú">❌</button>
    </div>
    
    <a href="?type=balco&start=<?php echo $start; ?>&end=<?php echo $end; ?>" class="btn">1. Balanza Comprobación</a>
    <a href="?type=balance&start=<?php echo $start; ?>&end=<?php echo $end; ?>" class="btn">2. Balance General</a>
    <a href="?type=estado_resultados&start=<?php echo $start; ?>&end=<?php echo $end; ?>" class="btn">3. Estado Resultados</a>
    <a href="?type=flujo&start=<?php echo $start; ?>&end=<?php echo $end; ?>" class="btn">4. Flujo Efectivo</a>
    <a href="?type=patrimonio&end=<?php echo $end; ?>" class="btn">5. Cambios Patrimonio</a>
    <a href="?type=resumen_gastos&start=<?php echo $start; ?>&end=<?php echo $end; ?>" class="btn" style="background:#198754;">6. Resumen de Gastos</a>
    <hr style="margin:8px 0; border-color:#555;">
    <button onclick="window.print()" class="btn btn-print">🖨️ IMPRIMIR / PDF</button>
    <button onclick="window.close()" class="btn" style="background:#dc3545;">Cerrar Pestaña</button>
</div>

<button id="minMenu" class="no-print" onclick="toggleMenu()">📂 VER MENÚ</button>


<?php if ($type === 'balance'): 
    $cuentas = $pdo->query("SELECT * FROM contabilidad_cuentas ORDER BY codigo ASC")->fetchAll(PDO::FETCH_ASSOC);
    $ing = getMov($pdo, '4', '2000-01-01', $end, 'A');
    $cos = getMov($pdo, '5', '2000-01-01', $end, 'D');
    $gas = getMov($pdo, '7', '2000-01-01', $end, 'D');
    $utilidadEjercicio = $ing - $cos - $gas;

    $totalActivo = getSaldo($pdo, '1', $end, 'D');
    $totalPasivo = getSaldo($pdo, '2', $end, 'A');
    $totalPatrimonioSinRes = getSaldo($pdo, '6', $end, 'A');
    $totalPatrimonio = $totalPatrimonioSinRes + $utilidadEjercicio;
?>
    <div class="header"><h1>Estado de Situación Financiera</h1><p>(BALANCE GENERAL)</p><p>Al <?php echo date('d/m/Y', strtotime($end)); ?></p></div>
    <table>
        <tr><td colspan="2" class="section-header">ACTIVOS</td></tr>
        <?php foreach ($cuentas as $c): if(substr($c['codigo'],0,1)=='1'){ $s=getSaldo($pdo,$c['codigo'],$end,'D'); renderAccountRow($c['codigo'],$c['nombre'],$c['nivel'],$s); } endforeach; ?>
        <tr class="total-row"><td>TOTAL ACTIVOS</td><td class="text-right">$<?php echo number_format($totalActivo, 2); ?></td></tr>
        <tr><td colspan="2" class="section-header">PASIVOS</td></tr>
        <?php foreach ($cuentas as $c): if(substr($c['codigo'],0,1)=='2'){ $s=getSaldo($pdo,$c['codigo'],$end,'A'); renderAccountRow($c['codigo'],$c['nombre'],$c['nivel'],$s); } endforeach; ?>
        <tr class="total-row"><td>TOTAL PASIVOS</td><td class="text-right">$<?php echo number_format($totalPasivo, 2); ?></td></tr>
        <tr><td colspan="2" class="section-header">PATRIMONIO NETO</td></tr>
        <?php foreach ($cuentas as $c): if(substr($c['codigo'],0,1)=='6'){ $s=getSaldo($pdo,$c['codigo'],$end,'A'); renderAccountRow($c['codigo'],$c['nombre'],$c['nivel'],$s); } endforeach; ?>
        <tr style="font-weight:bold; color:#0056b3;"><td style="padding-left:0px;">RESULTADO DEL EJERCICIO (Pendiente)</td><td class="text-right">$<?php echo number_format($utilidadEjercicio, 2); ?></td></tr>
        <tr class="total-row"><td>TOTAL PATRIMONIO</td><td class="text-right">$<?php echo number_format($totalPatrimonio, 2); ?></td></tr>
        <tr style="background:#ddd;"><td style="padding:10px; font-weight:bold;">TOTAL PASIVO + PATRIMONIO</td><td class="text-right" style="padding:10px; font-weight:bold;">$<?php echo number_format($totalPasivo + $totalPatrimonio, 2); ?></td></tr>
    </table>

<?php elseif ($type === 'estado_resultados'): 
    $ing = getMov($pdo, '4', $start, $end, 'A');
    $cos = getMov($pdo, '5', $start, $end, 'D');
    $gas = getMov($pdo, '7', $start, $end, 'D');
?>
    <div class="header"><h1>Estado de Rendimiento</h1><p>Del <?php echo date('d/m/Y', strtotime($start)); ?> al <?php echo date('d/m/Y', strtotime($end)); ?></p></div>
    <table>
        <tr><td><strong>INGRESOS (400)</strong></td><td class="text-right">$<?php echo number_format($ing, 2); ?></td></tr>
        <tr><td>(-) COSTOS (500)</td><td class="text-right">($<?php echo number_format($cos, 2); ?>)</td></tr>
        <tr style="background:#f0f0f0; font-weight:bold;"><td>= UTILIDAD BRUTA</td><td class="text-right">$<?php echo number_format($ing - $cos, 2); ?></td></tr>
        <tr><td colspan="2" class="section-header">GASTOS OPERATIVOS (700)</td></tr>
        <?php
        $cuentasGasto = $pdo->query("SELECT * FROM contabilidad_cuentas WHERE tipo='GASTO' ORDER BY codigo")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cuentasGasto as $c) { $m = getMov($pdo, $c['codigo'], $start, $end, 'D'); renderAccountRow($c['codigo'], $c['nombre'], $c['nivel'], $m); }
        ?>
        <tr class="total-row"><td>UTILIDAD NETA</td><td class="text-right">$<?php echo number_format($ing - $cos - $gas, 2); ?></td></tr>
    </table>

<?php elseif ($type === 'flujo'): 
    $saldoIni = getSaldo($pdo, '10', date('Y-m-d', strtotime("$start -1 day")), 'D');
    $entradas = $pdo->query("SELECT SUM(debe) FROM contabilidad_diario WHERE fecha BETWEEN '$start' AND '$end' AND cuenta LIKE '10%'")->fetchColumn();
    $salidas = $pdo->query("SELECT SUM(haber) FROM contabilidad_diario WHERE fecha BETWEEN '$start' AND '$end' AND cuenta LIKE '10%'")->fetchColumn();
?>
    <div class="header"><h1>Flujo de Efectivo</h1></div>
    <table>
        <tr style="background:#e3f2fd; font-weight:bold;"><td>SALDO INICIAL</td><td class="text-right">$<?php echo number_format($saldoIni, 2); ?></td></tr>
        <tr><td>(+) Entradas</td><td class="text-right text-success">$<?php echo number_format($entradas, 2); ?></td></tr>
        <tr><td>(-) Salidas</td><td class="text-right text-danger">($<?php echo number_format($salidas, 2); ?>)</td></tr>
        <tr class="total-row"><td>SALDO FINAL</td><td class="text-right">$<?php echo number_format($saldoIni + $entradas - $salidas, 2); ?></td></tr>
    </table>

<?php elseif ($type === 'balco'): ?>
    <div class="header"><h1>Balanza de Comprobación</h1><p>Al <?php echo $end; ?></p></div>
    <table>
        <thead style="background:#333; color:#fff;"><tr><th>Cuenta</th><th class="text-right">Debe</th><th class="text-right">Haber</th><th class="text-right">S. Deudor</th><th class="text-right">S. Acreedor</th></tr></thead>
        <tbody>
            <?php
            $sql = "SELECT cuenta, SUM(debe) as d, SUM(haber) as h FROM contabilidad_diario WHERE fecha <= ? GROUP BY cuenta ORDER BY cuenta";
            $stmt = $pdo->prepare($sql); $stmt->execute([$end]);
            $td=0; $th=0;
            while($r = $stmt->fetch(PDO::FETCH_ASSOC)):
                $s = $r['d'] - $r['h'];
                $td+=$r['d']; $th+=$r['h'];
            ?>
            <tr>
                <td><?php echo $r['cuenta']; ?></td>
                <td class="text-right"><?php echo number_format($r['d'], 2); ?></td>
                <td class="text-right"><?php echo number_format($r['h'], 2); ?></td>
                <td class="text-right"><?php echo ($s>0)?number_format($s,2):'-'; ?></td>
                <td class="text-right"><?php echo ($s<0)?number_format(abs($s),2):'-'; ?></td>
            </tr>
            <?php endwhile; ?>
            <tr class="total-row"><td>SUMAS IGUALES</td><td class="text-right">$<?php echo number_format($td,2); ?></td><td class="text-right">$<?php echo number_format($th,2); ?></td><td></td><td></td></tr>
        </tbody>
    </table>

<?php elseif ($type === 'patrimonio'): ?>
    <div class="header"><h1>Cambios en el Patrimonio</h1></div>
    <table>
        <thead><tr><th>Cuenta</th><th class="text-right">Saldo Final</th></tr></thead>
        <tbody>
            <tr><td>601 Capital Social</td><td class="text-right">$<?php echo number_format(getSaldo($pdo,'601',$end,'A'),2); ?></td></tr>
            <tr><td>640 Reservas Obligatorias</td><td class="text-right">$<?php echo number_format(getSaldo($pdo,'640',$end,'A'),2); ?></td></tr>
            <tr><td>630 Utilidades Retenidas</td><td class="text-right">$<?php echo number_format(getSaldo($pdo,'630',$end,'A'),2); ?></td></tr>
            <tr class="total-row"><td>TOTAL PATRIMONIO</td><td class="text-right">$<?php echo number_format(getSaldo($pdo,'6',$end,'A'),2); ?></td></tr>
        </tbody>
    </table>

<?php elseif ($type === 'resumen_gastos'): 
    $totalGastos = getMov($pdo, '7', $start, $end, 'D');
    if($totalGastos == 0) $totalGastos = 1;

    function printExpenseBlock($pdo, $start, $end, $prefix, $title, $totalGlobal) {
        $mov = getMov($pdo, $prefix, $start, $end, 'D');
        $pct = ($mov / $totalGlobal) * 100;
        echo "<tr class='subtotal-row'><td colspan='2'>$title</td><td class='text-right'>".number_format($pct, 1)."%</td><td class='text-right'>$ ".number_format($mov, 2)."</td></tr>";
        $det = $pdo->prepare("SELECT cuenta, SUM(debe - haber) as m FROM contabilidad_diario WHERE fecha BETWEEN ? AND ? AND cuenta LIKE ? GROUP BY cuenta");
        $det->execute([$start, $end, $prefix.'%']);
        while($r = $det->fetch(PDO::FETCH_ASSOC)) {
            if($r['m'] > 0) echo "<tr><td style='padding-left:20px;'>{$r['cuenta']}</td><td></td><td></td><td class='text-right'>".number_format($r['m'], 2)."</td></tr>";
        }
    }
?>
    <div class="header"><h1>Resumen Analítico de Gastos</h1><p>Del <?php echo date('d/m/Y', strtotime($start)); ?> al <?php echo date('d/m/Y', strtotime($end)); ?></p></div>
    <table>
        <thead style="background:#eee;"><tr><th width="50%">Concepto</th><th width="15%">Ref</th><th width="15%" class="text-right">% Peso</th><th width="20%" class="text-right">Importe</th></tr></thead>
        <tbody>
            <?php printExpenseBlock($pdo, $start, $end, '701', 'A. GASTOS DE PERSONAL Y NÓMINA', $totalGastos); ?>
            <?php printExpenseBlock($pdo, $start, $end, '704', 'B. CARGA TRIBUTARIA Y FISCAL', $totalGastos); ?>
            <?php 
                $opMov = getMov($pdo, '702', $start, $end, 'D') + getMov($pdo, '703', $start, $end, 'D') + getMov($pdo, '705', $start, $end, 'D') + getMov($pdo, '706', $start, $end, 'D');
                $opPct = ($opMov / $totalGastos) * 100;
                echo "<tr class='subtotal-row'><td colspan='2'>C. GASTOS OPERATIVOS Y FIJOS</td><td class='text-right'>".number_format($opPct, 1)."%</td><td class='text-right'>$ ".number_format($opMov, 2)."</td></tr>";
                $codes = ['702','703','705','706'];
                foreach($codes as $c) {
                    $det = $pdo->prepare("SELECT cuenta, SUM(debe-haber) as m FROM contabilidad_diario WHERE fecha BETWEEN ? AND ? AND cuenta LIKE ? GROUP BY cuenta");
                    $det->execute([$start, $end, $c.'%']);
                    while($r = $det->fetch(PDO::FETCH_ASSOC)) { if($r['m'] > 0) echo "<tr><td style='padding-left:20px;'>{$r['cuenta']}</td><td></td><td></td><td class='text-right'>".number_format($r['m'], 2)."</td></tr>"; }
                }
            ?>
            <?php printExpenseBlock($pdo, $start, $end, '709', 'D. GASTOS VARIABLES Y OTROS', $totalGastos); ?>
            <tr class="total-row"><td colspan="3">TOTAL GASTOS DEL PERIODO</td><td class="text-right">$ <?php echo number_format($totalGastos, 2); ?></td></tr>
        </tbody>
    </table>
<?php endif; ?>

<?php include_once 'menu_master.php'; ?>
</body></html>

