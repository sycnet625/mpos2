<?php
// /var/www/reportes_caja_multi.php
// v3: sticky KPI bar, WhatsApp, clipboard, zombi, duplicados, filtro pago, búsqueda, auditada, notas inline
ini_set('display_errors', 0);
require_once 'db.php';
require_once 'accounting_helpers.php';
require_once 'business_metrics.php';

session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['status'=>'error','msg'=>'No autenticado']); exit;
}

require_once 'config_loader.php';

// ---------------------------------------------------------------
// Migración: columna auditada en caja_sesiones
// ---------------------------------------------------------------
try {
    $pdo->exec("ALTER TABLE caja_sesiones ADD COLUMN auditada TINYINT NOT NULL DEFAULT 0");
} catch (PDOException $e) { /* ya existe */ }

// ---------------------------------------------------------------
// AJAX handlers
// ---------------------------------------------------------------
$ajaxAction = $_POST['ajax_action'] ?? ($_GET['ajax_action'] ?? '');
if ($ajaxAction !== '') {
    header('Content-Type: application/json');

    // #18 — marcar/desmarcar auditada
    if ($ajaxAction === 'set_auditada') {
        $id  = intval($_POST['id'] ?? 0);
        $val = intval($_POST['val'] ?? 0) ? 1 : 0;
        if ($id < 1) { echo json_encode(['status'=>'error','msg'=>'ID inválido']); exit; }
        $pdo->prepare("UPDATE caja_sesiones SET auditada=? WHERE id=?")->execute([$val,$id]);
        echo json_encode(['status'=>'ok','auditada'=>$val]); exit;
    }

    // #19 — guardar nota inline
    if ($ajaxAction === 'set_nota') {
        $id   = intval($_POST['id'] ?? 0);
        $nota = mb_substr(trim($_POST['nota'] ?? ''), 0, 500);
        if ($id < 1) { echo json_encode(['status'=>'error','msg'=>'ID inválido']); exit; }
        $pdo->prepare("UPDATE caja_sesiones SET nota=? WHERE id=?")->execute([$nota,$id]);
        echo json_encode(['status'=>'ok','nota'=>$nota]); exit;
    }

    // #26 — enviar resumen por WhatsApp (bridge outbox)
    if ($ajaxAction === 'wa_send') {
        $phone   = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? '');
        $mensaje = trim($_POST['mensaje'] ?? '');
        if ($phone === '' || $mensaje === '') { echo json_encode(['status'=>'error','msg'=>'Teléfono o mensaje vacío']); exit; }
        $waId = $phone . '@c.us';

        // Ruta al outbox del bridge (mismo patrón que posbot_api/bootstrap.php)
        $hostSlug = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'default'));
        $hostSlug = trim(preg_replace('/[^a-z0-9.-]+/', '-', $hostSlug), '-.');
        if ($hostSlug === '') $hostSlug = 'default';
        $outboxFile = __DIR__ . '/wa_web_bridge/instances/' . $hostSlug . '/runtime/palweb_wa_outbox_queue.json';

        $queue = ['jobs' => []];
        if (is_file($outboxFile)) {
            $raw = @file_get_contents($outboxFile);
            $decoded = $raw ? json_decode($raw, true) : null;
            if (is_array($decoded) && isset($decoded['jobs'])) $queue = $decoded;
        }
        $queue['jobs'][] = [
            'id'         => 'rcm_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)),
            'created_at' => date('c'),
            'status'     => 'queued',
            'wa_user_id' => $waId,
            'type'       => 'text',
            'text'       => $mensaje,
        ];
        $ok = (bool)@file_put_contents($outboxFile, json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo json_encode(['status' => $ok ? 'ok' : 'error', 'msg' => $ok ? 'Mensaje encolado.' : 'No se pudo escribir el outbox del bridge.']);
        exit;
    }

    echo json_encode(['status'=>'error','msg'=>'Acción desconocida']); exit;
}

// ---------------------------------------------------------------
// Página normal
// ---------------------------------------------------------------
$empresaIDDefault  = intval($config['id_empresa'] ?? 1);
$sucursalIDDefault = intval($config['id_sucursal']);
$telefonoDefault   = preg_replace('/[^0-9]/', '', $config['telefono'] ?? '');

$sucursales = $pdo->query("SELECT s.id, s.nombre, s.id_empresa, e.nombre as empresa_nombre
                           FROM sucursales s LEFT JOIN empresas e ON e.id = s.id_empresa
                           WHERE s.activo = 1 ORDER BY e.nombre, s.nombre")->fetchAll(PDO::FETCH_ASSOC);

$sucursalID = isset($_GET['sucursal']) ? intval($_GET['sucursal']) : $sucursalIDDefault;
$empresaID  = $empresaIDDefault;
foreach ($sucursales as $s) {
    if (intval($s['id']) === $sucursalID) { $empresaID = intval($s['id_empresa']); break; }
}

$fDesde          = isset($_GET['fdesde'])   ? trim($_GET['fdesde'])   : '';
$fHasta          = isset($_GET['fhasta'])   ? trim($_GET['fhasta'])   : '';
$fCajero         = isset($_GET['fcajero'])  ? trim($_GET['fcajero'])  : '';
$fEstado         = isset($_GET['festado'])  ? trim($_GET['festado'])  : '';
$fDescuadre      = isset($_GET['fdesc']) && $_GET['fdesc'] === '1';
$umbralDescuadre = isset($_GET['umbral']) ? floatval($_GET['umbral']) : 100.0;
$horasZombi      = isset($_GET['hzombi']) ? max(1, intval($_GET['hzombi'])) : 12;

function parseIds($arr): array {
    $out = [];
    if (is_array($arr)) foreach ($arr as $v) { $iv = intval($v); if ($iv > 0) $out[] = $iv; }
    return array_values(array_unique($out));
}
$idsA = parseIds($_GET['ids']  ?? []);
$idsB = parseIds($_GET['idsB'] ?? []);
$haySeleccion   = !empty($idsA);
$hayComparativa = !empty($idsB);
$export         = $_GET['export'] ?? '';

// ---------------------------------------------------------------
// Lista de sesiones (con filtros)
// ---------------------------------------------------------------
$where  = "id_sucursal = ?";
$params = [$sucursalID];
if ($fDesde !== '')  { $where .= " AND fecha_contable >= ?"; $params[] = $fDesde; }
if ($fHasta !== '')  { $where .= " AND fecha_contable <= ?"; $params[] = $fHasta; }
if ($fCajero !== '') { $where .= " AND nombre_cajero = ?";   $params[] = $fCajero; }
if ($fEstado !== '') { $where .= " AND estado = ?";          $params[] = $fEstado; }
if ($fDescuadre)     { $where .= " AND ABS(diferencia) > ?"; $params[] = $umbralDescuadre; }

$stmtL = $pdo->prepare("SELECT * FROM caja_sesiones WHERE $where ORDER BY id DESC LIMIT 100");
$stmtL->execute($params);
$sesionesLista = $stmtL->fetchAll(PDO::FETCH_ASSOC);

$stmtCaj = $pdo->prepare("SELECT DISTINCT nombre_cajero FROM caja_sesiones WHERE id_sucursal = ? AND nombre_cajero IS NOT NULL AND nombre_cajero <> '' ORDER BY nombre_cajero");
$stmtCaj->execute([$sucursalID]);
$cajeros = $stmtCaj->fetchAll(PDO::FETCH_COLUMN);

// #23 — Detectar sesiones zombi (ABIERTA hace más de $horasZombi horas)
$zombis = [];
foreach ($sesionesLista as $s) {
    if ($s['estado'] === 'ABIERTA') {
        $horas = (time() - strtotime($s['fecha_apertura'])) / 3600;
        if ($horas >= $horasZombi) $zombis[$s['id']] = round($horas, 1);
    }
}

$canalMap = [
    'Web'        => ['#0ea5e9', '🌐', 'Web'],
    'POS'        => ['#6366f1', '🖥️', 'POS'],
    'WhatsApp'   => ['#22c55e', '💬', 'WhatsApp'],
    'Teléfono'   => ['#f59e0b', '📞', 'Tel.'],
    'Kiosko'     => ['#8b5cf6', '📱', 'Kiosko'],
    'Presencial' => ['#475569', '🙋', 'Presencial'],
    'ICS'        => ['#94a3b8', '📥', 'ICS'],
    'Otro'       => ['#94a3b8', '❓', 'Otro'],
];
function getCanalBadgeRCM($canal, $map): string {
    [$bg, $emoji, $label] = $map[$canal] ?? $map['Otro'];
    return "<span style=\"display:inline-flex;align-items:center;gap:4px;background-color:{$bg}!important;color:white!important;padding:2px 9px;border-radius:20px;font-size:.65rem;font-weight:700;white-space:nowrap;print-color-adjust:exact;-webkit-print-color-adjust:exact;\">{$emoji} {$label}</span>";
}

function calcularSet(PDO $pdo, array $ids, int $sucursalID): array {
    $r = [
        'sesiones'=>[],'tickets'=>[],'detalles'=>[],'detallesPorTicket'=>[],
        'totalVentaNeta'=>0,'ventasReales'=>0,'metodosPago'=>[],
        'conteoTickets'=>0,'cantDevoluciones'=>0,'valorDevoluciones'=>0,
        'totalCostoPositivos'=>0,'totalVentaBrutaPositivos'=>0,
        'ganancia'=>0,'margen'=>0,'horasOperacion'=>0,
        'totalAperturas'=>0,'totalCierresSistema'=>0,'totalCierresReal'=>0,'totalDiferencias'=>0,
        'topProductos'=>[],'topClientes'=>[],'porCajero'=>[],
        'fechaMin'=>null,'fechaMax'=>null,
    ];
    if (empty($ids)) return $r;

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM caja_sesiones WHERE id IN ($ph) AND id_sucursal = ? ORDER BY id DESC");
    $stmt->execute(array_merge($ids, [$sucursalID]));
    $r['sesiones'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($r['sesiones'])) return $r;

    $idsValidos = array_map(fn($s) => intval($s['id']), $r['sesiones']);
    $ph2 = implode(',', array_fill(0, count($idsValidos), '?'));

    $st = $pdo->prepare("SELECT * FROM ventas_cabecera WHERE id_caja IN ($ph2) AND id_sucursal = ? AND " . ventas_reales_where_clause() . " ORDER BY id DESC");
    $st->execute(array_merge($idsValidos, [$sucursalID]));
    $r['tickets'] = $st->fetchAll(PDO::FETCH_ASSOC);

    $sd = $pdo->prepare("SELECT d.*, p.nombre, p.codigo, p.costo, p.categoria FROM ventas_detalle d JOIN productos p ON d.id_producto = p.codigo JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id WHERE v.id_caja IN ($ph2) AND v.id_sucursal = ? AND " . ventas_reales_where_clause('v'));
    $sd->execute(array_merge($idsValidos, [$sucursalID]));
    $r['detalles'] = $sd->fetchAll(PDO::FETCH_ASSOC);

    $stp = $pdo->prepare("SELECT COALESCE(SUM(monto),0) FROM ventas_pagos WHERE id_venta_cabecera IN (SELECT id FROM ventas_cabecera WHERE id_caja IN ($ph2))");
    $stp->execute($idsValidos);
    $r['totalVentaNeta'] = floatval($stp->fetchColumn() ?? 0);

    $prodAgg = [];
    foreach ($r['detalles'] as $d) {
        $r['detallesPorTicket'][$d['id_venta_cabecera']][] = $d;
        if ($d['cantidad'] > 0) {
            $r['totalCostoPositivos']        += ($d['cantidad'] * $d['costo']);
            $r['totalVentaBrutaPositivos']   += ($d['cantidad'] * $d['precio']);
        }
        $k = $d['codigo'];
        if (!isset($prodAgg[$k])) $prodAgg[$k] = ['codigo'=>$k,'nombre'=>$d['nombre'],'cantidad'=>0,'monto'=>0,'ganancia'=>0];
        $prodAgg[$k]['cantidad'] += floatval($d['cantidad']);
        $prodAgg[$k]['monto']    += floatval($d['cantidad']) * floatval($d['precio']);
        $prodAgg[$k]['ganancia'] += floatval($d['cantidad']) * (floatval($d['precio']) - floatval($d['costo']));
    }
    usort($prodAgg, fn($a,$b)=>$b['monto']<=>$a['monto']);
    $r['topProductos'] = array_slice($prodAgg, 0, 10);

    $cliAgg = [];
    $cajAgg = [];
    foreach ($r['tickets'] as $t) {
        $r['ventasReales'] += $t['total'];  // neto: incluye devoluciones negativas
        if ($t['total'] > 0) $r['conteoTickets']++;
        if ($t['total'] < 0 || $t['cliente_nombre'] === 'DEVOLUCIÓN') {
            $r['cantDevoluciones']++; $r['valorDevoluciones'] += abs($t['total']);
        }
        $mp = $t['metodo_pago'] ?: 'Otro';
        if (!isset($r['metodosPago'][$mp])) $r['metodosPago'][$mp] = 0;
        $r['metodosPago'][$mp] += $t['total'];
        if ($t['total'] > 0) {
            $cn = $t['cliente_nombre'] ?: '—';
            if (!isset($cliAgg[$cn])) $cliAgg[$cn] = ['cliente'=>$cn,'tickets'=>0,'monto'=>0];
            $cliAgg[$cn]['tickets']++; $cliAgg[$cn]['monto'] += $t['total'];
        }
    }
    usort($cliAgg, fn($a,$b)=>$b['monto']<=>$a['monto']);
    $r['topClientes'] = array_slice(array_values($cliAgg), 0, 10);
    $r['ganancia'] = $r['totalVentaBrutaPositivos'] - $r['totalCostoPositivos'];
    $r['margen']   = $r['totalVentaBrutaPositivos'] != 0 ? ($r['ganancia'] / $r['totalVentaBrutaPositivos']) * 100 : 0;

    $sesByCaja = [];
    foreach ($r['sesiones'] as $s) {
        $r['totalAperturas']      += floatval($s['monto_inicial']);
        $r['totalCierresSistema'] += floatval($s['monto_final_sistema']);
        $r['totalCierresReal']    += floatval($s['monto_final_real']);
        $r['totalDiferencias']    += floatval($s['diferencia']);
        $ini = strtotime($s['fecha_apertura']);
        $fin = $s['fecha_cierre'] ? strtotime($s['fecha_cierre']) : time();
        $r['horasOperacion'] += max(0, ($fin - $ini) / 3600);
        if ($s['fecha_contable']) {
            if (!$r['fechaMin'] || $s['fecha_contable'] < $r['fechaMin']) $r['fechaMin'] = $s['fecha_contable'];
            if (!$r['fechaMax'] || $s['fecha_contable'] > $r['fechaMax']) $r['fechaMax'] = $s['fecha_contable'];
        }
        $cn = $s['nombre_cajero'] ?: '—';
        if (!isset($cajAgg[$cn])) $cajAgg[$cn] = ['cajero'=>$cn,'sesiones'=>0,'tickets'=>0,'ventas'=>0,'devoluciones'=>0,'diferencia'=>0];
        $cajAgg[$cn]['sesiones']++;
        $cajAgg[$cn]['diferencia'] += floatval($s['diferencia']);
        $sesByCaja[$s['id']] = $cn;
    }
    $r['horasOperacion'] = round($r['horasOperacion'], 2);
    foreach ($r['tickets'] as $t) {
        $cn = $sesByCaja[$t['id_caja']] ?? '—';
        if (!isset($cajAgg[$cn])) $cajAgg[$cn] = ['cajero'=>$cn,'sesiones'=>0,'tickets'=>0,'ventas'=>0,'devoluciones'=>0,'diferencia'=>0];
        if ($t['total'] > 0) $cajAgg[$cn]['tickets']++;
        $cajAgg[$cn]['ventas'] += $t['total'];
        if ($t['total'] < 0) $cajAgg[$cn]['devoluciones'] += abs($t['total']);
    }
    usort($cajAgg, fn($a,$b)=>$b['ventas']<=>$a['ventas']);
    $r['porCajero'] = array_values($cajAgg);
    return $r;
}

$A = $haySeleccion   ? calcularSet($pdo, $idsA, $sucursalID) : null;
$B = $hayComparativa ? calcularSet($pdo, $idsB, $sucursalID) : null;

// ---------------------------------------------------------------
// #24 — detectar tickets duplicados (mismo cajero, mismo total, ±120s)
// ---------------------------------------------------------------
$duplicadosIds = [];
if ($haySeleccion && !empty($A['tickets'])) {
    $sesByCajaDup = [];
    foreach ($A['sesiones'] as $s) $sesByCajaDup[$s['id']] = $s['nombre_cajero'] ?: '—';
    $grouped = [];
    foreach ($A['tickets'] as $t) {
        if ($t['total'] <= 0) continue;
        $cn = $sesByCajaDup[$t['id_caja']] ?? '—';
        $ts = strtotime($t['fecha']);
        $grouped[] = ['id'=>$t['id'],'cajero'=>$cn,'total'=>round(floatval($t['total']),2),'ts'=>$ts];
    }
    $n = count($grouped);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i+1; $j < $n; $j++) {
            if ($grouped[$i]['cajero'] === $grouped[$j]['cajero']
                && $grouped[$i]['total'] === $grouped[$j]['total']
                && abs($grouped[$i]['ts'] - $grouped[$j]['ts']) <= 120) {
                $duplicadosIds[$grouped[$i]['id']] = true;
                $duplicadosIds[$grouped[$j]['id']] = true;
            }
        }
    }
}

// ---------------------------------------------------------------
// Export CSV
// ---------------------------------------------------------------
if ($haySeleccion && in_array($export, ['tickets_csv','sesiones_csv','productos_csv','cajeros_csv','asiento_csv'], true)) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="rcm_'.$export.'_'.date('Ymd_His').'.csv"');
    $fp = fopen('php://output', 'w');
    fwrite($fp, "\xEF\xBB\xBF");
    if ($export === 'sesiones_csv') {
        fputcsv($fp, ['ID','Estado','Auditada','Fecha Contable','Apertura','Cierre','Cajero','M.Inicial','M.Final Sist.','M.Final Real','Diferencia','Nota']);
        foreach ($A['sesiones'] as $s) fputcsv($fp, [$s['id'],$s['estado'],$s['auditada']??0,$s['fecha_contable'],$s['fecha_apertura'],$s['fecha_cierre'],$s['nombre_cajero'],$s['monto_inicial'],$s['monto_final_sistema'],$s['monto_final_real'],$s['diferencia'],$s['nota']]);
    } elseif ($export === 'tickets_csv') {
        fputcsv($fp, ['ID','Caja','Fecha','Cliente','Tipo','Origen','Método Pago','Total','Duplicado?']);
        foreach ($A['tickets'] as $t) fputcsv($fp, [$t['id'],$t['id_caja'],$t['fecha'],$t['cliente_nombre'],$t['tipo_servicio'],$t['canal_origen']??'POS',$t['metodo_pago'],$t['total'],isset($duplicadosIds[$t['id']])?'SÍ':'']);
    } elseif ($export === 'productos_csv') {
        fputcsv($fp, ['Código','Producto','Cantidad','Monto','Ganancia']);
        foreach ($A['topProductos'] as $p) fputcsv($fp, [$p['codigo'],$p['nombre'],$p['cantidad'],$p['monto'],$p['ganancia']]);
    } elseif ($export === 'cajeros_csv') {
        fputcsv($fp, ['Cajero','Sesiones','Tickets','Ventas','Devoluciones','Diferencia']);
        foreach ($A['porCajero'] as $c) fputcsv($fp, [$c['cajero'],$c['sesiones'],$c['tickets'],$c['ventas'],$c['devoluciones'],$c['diferencia']]);
    } elseif ($export === 'asiento_csv') {
        fputcsv($fp, ['Cuenta','Concepto','Debe','Haber']);
        foreach ($A['metodosPago'] as $m=>$v) {
            $cuenta = strtolower($m)==='efectivo'?'1110':(strtolower($m)==='transferencia'?'1120':'1190');
            fputcsv($fp, [$cuenta,"Cobros $m",number_format($v,2,'.',''),'']);
        }
        fputcsv($fp, ['4100','Ventas','',number_format($A['ventasReales'],2,'.','')]);
    }
    fclose($fp); exit;
}

$ticketPromedio = ($A['ventasReales'] ?? 0) * 0.20;
$ventasPorHora  = ($A['ventasReales'] ?? 0) * 0.30;
$variacion      = ($A['ventasReales'] ?? 0) * 0.10;

function buildQs(array $extra=[], array $omit=[]): string {
    $q = $_GET;
    foreach ($omit as $k) unset($q[$k]);
    foreach ($extra as $k=>$v) $q[$k] = $v;
    return '?' . http_build_query($q);
}

// Resumen ejecutivo (#25) para JS
$resumenEjecutivo = '';
if ($haySeleccion) {
    $r = $A;
    $lineas = [
        "📊 *Reporte Multi-Sesión* — " . date('d/m/Y H:i'),
        "Sucursal #{$sucursalID}" . ($r['fechaMin'] ? " | " . date('d/m/Y', strtotime($r['fechaMin'])) . " → " . date('d/m/Y', strtotime($r['fechaMax'])) : ''),
        "Sesiones: " . count($r['sesiones']) . " | Tickets: " . $r['conteoTickets'],
        "Venta Neta: $" . number_format($r['totalVentaNeta'],2),
        "Ventas Reales: $" . number_format($r['ventasReales'],2),
        "Ganancia: $" . number_format($r['ganancia'],2) . " (" . number_format($r['margen'],1) . "%)",
        "Devoluciones: " . $r['cantDevoluciones'] . " | -$" . number_format($r['valorDevoluciones'],2),
        "Diferencia caja: $" . number_format($r['totalDiferencias'],2),
    ];
    foreach ($r['metodosPago'] as $m=>$v) $lineas[] = "  $m: $" . number_format($v,2);
    $resumenEjecutivo = implode("\n", $lineas);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reporte Multi-Sesión de Caja</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@700;900&display=swap" rel="stylesheet">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body { background-color: #f4f6f9; font-family: 'Segoe UI', sans-serif; }

        /* ── FASE 1: Sticky KPI bar ─────────────────────── */
        #sticky-kpi {
            position: fixed; top: 0; left: 0; right: 0; z-index: 1050;
            background: rgba(13,110,253,0.97); color: #fff;
            padding: 6px 16px; display: none; align-items: center;
            gap: 16px; flex-wrap: wrap; box-shadow: 0 2px 8px rgba(0,0,0,0.25);
            backdrop-filter: blur(4px);
        }
        #sticky-kpi.visible { display: flex; }
        .sticky-kpi-item { display: flex; flex-direction: column; align-items: center; min-width: 80px; }
        .sticky-kpi-item .val { font-size: 1rem; font-weight: 900; line-height: 1.1; }
        .sticky-kpi-item .lbl { font-size: 0.6rem; opacity: .75; text-transform: uppercase; }

        /* ── General ────────────────────────────────────── */
        .kpi-card { border: none; border-radius: 12px; padding: 20px; background: white; box-shadow: 0 4px 6px rgba(0,0,0,0.05); transition: transform 0.2s; height: 100%; position: relative; overflow: hidden; }
        .kpi-card:hover { transform: translateY(-3px); }
        .kpi-icon { width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; margin-bottom: 10px; }
        .ticket-row { cursor: pointer; transition: background 0.1s; border-left: 5px solid transparent; }
        .ticket-row:hover { background-color: #f1f3f5; }
        .row-efectivo { border-left-color: #198754; } .row-transfer { border-left-color: #0d6efd; }
        .row-gasto { border-left-color: #fd7e14; } .row-reserva { border-left-color: #6f42c1; background-color: #f3f0ff; }
        .row-llevar { border-left-color: #0dcaf0; } .row-refund { border-left-color: #dc3545; background-color: #ffeaea !important; }
        .row-duplicate { background-color: #fff8e1 !important; }
        .badge-pago { font-size: 0.8rem; font-weight: 500; width: 100px; display: inline-block; text-align: center; }
        .bg-efectivo { background-color: #d1e7dd; color: #0f5132; }
        .bg-transfer  { background-color: #cfe2ff; color: #084298; }
        .bg-gasto     { background-color: #ffe5d0; color: #994d07; }
        .bg-refund-badge { background-color: #f8d7da; color: #842029; }
        .detail-row { background-color: #fafafa; border-left: 5px solid #ccc; font-size: 0.9rem; }
        .accounting-date-badge { background-color: #ffc107; color: #000; padding: 6px 10px; border-radius: 5px; font-weight: bold; box-shadow: 0 0 12px rgba(255,193,7,0.6); display: inline-block; }
        .sesion-row.sel-a  { background-color: #e7f1ff !important; }
        .sesion-row.sel-b  { background-color: #fff3cd !important; }
        .sesion-row.sel-ab { background: linear-gradient(90deg,#e7f1ff 50%,#fff3cd 50%) !important; }
        .sesion-row.row-warning { box-shadow: inset 4px 0 0 #dc3545; }
        .sesion-row.row-zombi   { box-shadow: inset 4px 0 0 #6610f2; }
        .sesion-row.row-auditada { opacity: .65; }
        .session-list-card thead th { white-space: nowrap; font-size: 0.75rem; }
        .session-list-card td { font-size: 0.78rem; vertical-align: middle; }
        .filter-bar { background: #fff; border-radius: 10px; padding: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.04); }
        .preset-btn { font-size: 0.75rem; }
        .compare-tile { border-left: 6px solid #0d6efd; }
        .delta-up   { color: #198754; }
        .delta-down { color: #dc3545; }
        .chart-card { background: #fff; border-radius: 12px; padding: 16px; box-shadow: 0 2px 4px rgba(0,0,0,0.04); height: 100%; }
        /* #19 nota inline */
        .nota-cell { cursor: pointer; }
        .nota-cell:hover .nota-text { text-decoration: underline dotted; }
        .nota-input { width: 100%; font-size: 0.78rem; border: 1px solid #0d6efd; border-radius: 4px; padding: 1px 4px; }
        /* #21 filtro método */
        .mp-filter-btn.active { background-color: #0d6efd !important; color: #fff !important; }

        @media print {
            * { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
            @page { size: A4 landscape; margin: 5mm 4mm; }
            body { background-color: #fff !important; font-size: 8px; padding: 0 !important; margin: 0 !important; }
            #sticky-kpi, .no-print, .btn, #palweb-float-nav, .fas.fa-chevron-down, .kpi-icon, .filter-bar, .session-list-card { display: none !important; }
            .container-fluid { width: 100% !important; max-width: 100% !important; padding: 0 !important; margin: 0 !important; }
            .mb-4 { margin-bottom: 4px !important; }
            h4 { font-size: 13px !important; display: inline-block; margin-right: 8px !important; }
            .accounting-date-badge { padding: 1px 4px !important; font-size: 9px !important; margin: 0 !important; box-shadow: none !important; border: 1px solid #000 !important; }
            .row { display: flex !important; flex-wrap: wrap !important; --bs-gutter-x: 3px !important; --bs-gutter-y: 3px !important; margin-bottom: 3px !important; }
            .col-md-4 { width: 33.33% !important; flex: 0 0 auto !important; }
            .kpi-card { padding: 3px 5px !important; border: 1px solid #bbb !important; box-shadow: none !important; height: auto !important; min-height: auto !important; }
            h3 { font-size: 12px !important; font-weight: 800 !important; margin: 0 !important; }
            small { font-size: 7.5px !important; }
            .kpi-highlight-row .kpi-card { border: 1px solid #000 !important; background-color: #f8f9fa !important; padding: 6px !important; }
            .kpi-highlight-row h3 { font-size: 18px !important; color: #000 !important; }
            .kpi-highlight-row small { font-size: 9px !important; font-weight: bold !important; color: #000 !important; }
            .table { font-size: 7.5px !important; table-layout: fixed; width: 100% !important; border: 1px solid #ddd !important; }
            .table th, .table td { padding: 1px 3px !important; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .ticket-row td { font-size: 8px !important; }
            .fs-5 { font-size: 10px !important; }
            #tickets-table th:nth-child(1), #tickets-table td:nth-child(1),
            #tickets-table th:nth-child(7), #tickets-table td:nth-child(7) { display: none !important; }
            #tickets-table th:nth-child(2), #tickets-table td:nth-child(2) { width: 7% !important; }
            #tickets-table th:nth-child(3), #tickets-table td:nth-child(3) { width: 7% !important; }
            #tickets-table th:nth-child(4), #tickets-table td:nth-child(4) { width: 8% !important; }
            #tickets-table th:nth-child(5), #tickets-table td:nth-child(5) { width: 32% !important; }
            #tickets-table th:nth-child(6), #tickets-table td:nth-child(6) { width: 12% !important; }
            #tickets-table th:nth-child(8), #tickets-table td:nth-child(8) { width: 16% !important; }
            #tickets-table th:nth-child(9), #tickets-table td:nth-child(9) { width: 18% !important; text-align: right !important; font-size: 11px !important; font-weight: 900 !important; font-family: 'Roboto', Arial, sans-serif !important; }
            .detail-row table th:last-child, .detail-row table td:last-child { display: none !important; }
            .detail-row { padding: 1px !important; }
            .chart-card { page-break-inside: avoid; }
        }
    </style>
</head>
<body class="p-3">

<!-- FASE 1: Sticky KPI bar -->
<?php if($haySeleccion): ?>
<div id="sticky-kpi">
    <span class="fw-bold me-2" style="font-size:.85rem;white-space:nowrap;"><i class="fas fa-layer-group me-1"></i>Multi-Sesión</span>
    <div class="sticky-kpi-item"><span class="val"><?php echo count($A['sesiones']); ?></span><span class="lbl">Sesiones</span></div>
    <div class="sticky-kpi-item"><span class="val">$<?php echo number_format($A['ventasReales'],0); ?></span><span class="lbl">Ventas</span></div>
    <div class="sticky-kpi-item"><span class="val">$<?php echo number_format($A['ganancia'],0); ?></span><span class="lbl">Ganancia</span></div>
    <div class="sticky-kpi-item"><span class="val"><?php echo number_format($A['margen'],1); ?>%</span><span class="lbl">Margen</span></div>
    <div class="sticky-kpi-item"><span class="val"><?php echo $A['conteoTickets']; ?></span><span class="lbl">Tickets</span></div>
    <?php if(!empty($duplicadosIds)): ?>
    <div class="sticky-kpi-item"><span class="val text-warning"><?php echo count($duplicadosIds); ?></span><span class="lbl">Duplicados</span></div>
    <?php endif; ?>
    <?php if(!empty($zombis)): ?>
    <div class="sticky-kpi-item"><span class="val" style="color:#d4adfc;"><?php echo count($zombis); ?></span><span class="lbl">Zombis</span></div>
    <?php endif; ?>
    <button class="btn btn-sm btn-light ms-auto no-print" style="font-size:.7rem;" onclick="window.scrollTo({top:0,behavior:'smooth'})"><i class="fas fa-arrow-up"></i> Inicio</button>
</div>
<?php endif; ?>

<div class="container-fluid" id="main-container">

    <!-- Cabecera -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-0">
                <i class="fas fa-layer-group text-primary"></i> Reporte Multi-Sesión de Caja
                <?php if($haySeleccion): ?>
                    <span class="badge bg-primary ms-2">A: <?php echo count($A['sesiones']); ?></span>
                <?php endif; ?>
                <?php if($hayComparativa): ?>
                    <span class="badge bg-warning text-dark ms-1">B: <?php echo count($B['sesiones']); ?></span>
                <?php endif; ?>
            </h4>
            <div class="text-muted small mt-1">
                <i class="fas fa-store"></i> Sucursal #<?php echo $sucursalID; ?>
                <?php if($haySeleccion && $A['fechaMin']): ?>
                    | <span class="accounting-date-badge"><?php echo date('d/m/Y', strtotime($A['fechaMin'])); ?> &rarr; <?php echo date('d/m/Y', strtotime($A['fechaMax'])); ?></span>
                <?php endif; ?>
                <?php if($haySeleccion): ?>
                    | <i class="far fa-clock"></i> <?php echo number_format($A['horasOperacion'],2); ?> h
                <?php endif; ?>
            </div>
        </div>
        <div class="no-print d-flex gap-1 flex-wrap">
            <?php if($haySeleccion): ?>
                <button onclick="window.print()" class="btn btn-success btn-sm"><i class="fas fa-print"></i> Imprimir A4</button>
                <button onclick="downloadPDF()" class="btn btn-outline-success btn-sm"><i class="fas fa-file-pdf"></i> PDF</button>
                <button onclick="copiarResumen()" class="btn btn-outline-info btn-sm" title="Copiar resumen al portapapeles"><i class="fas fa-copy"></i> Copiar</button>
                <button onclick="document.getElementById('modalWA').style.display='flex'" class="btn btn-outline-success btn-sm" title="Enviar por WhatsApp"><i class="fab fa-whatsapp"></i> WhatsApp</button>
                <div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown"><i class="fas fa-file-csv"></i> CSV</button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="<?php echo buildQs(['export'=>'sesiones_csv']); ?>">Sesiones</a></li>
                        <li><a class="dropdown-item" href="<?php echo buildQs(['export'=>'tickets_csv']); ?>">Tickets</a></li>
                        <li><a class="dropdown-item" href="<?php echo buildQs(['export'=>'productos_csv']); ?>">Top productos</a></li>
                        <li><a class="dropdown-item" href="<?php echo buildQs(['export'=>'cajeros_csv']); ?>">Por cajero</a></li>
                        <li><a class="dropdown-item" href="<?php echo buildQs(['export'=>'asiento_csv']); ?>">Asiento contable</a></li>
                    </ul>
                </div>
            <?php endif; ?>
            <a href="reportes_caja.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-cash-register"></i> Corte Individual</a>
            <a href="dashboard.php" class="btn btn-primary btn-sm"><i class="fas fa-chart-line"></i> Dashboard</a>
        </div>
    </div>

    <!-- FASE 2: Modal WhatsApp -->
    <div id="modalWA" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:2000;align-items:center;justify-content:center;">
        <div class="bg-white rounded-3 p-4 shadow" style="max-width:440px;width:95%;">
            <h6 class="fw-bold mb-3"><i class="fab fa-whatsapp text-success me-2"></i>Enviar resumen por WhatsApp</h6>
            <div class="mb-3">
                <label class="form-label small fw-bold">Número destino (con código de país)</label>
                <input type="text" id="wa-phone" class="form-control form-control-sm" placeholder="Ej: 5352783083" value="<?php echo htmlspecialchars($telefonoDefault); ?>">
                <div class="form-text">Solo dígitos, sin +, espacios ni guiones.</div>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-bold">Mensaje</label>
                <textarea id="wa-msg" class="form-control form-control-sm" rows="8"><?php echo htmlspecialchars($resumenEjecutivo); ?></textarea>
            </div>
            <div id="wa-resultado" class="small mb-2"></div>
            <div class="d-flex gap-2 justify-content-end">
                <button class="btn btn-sm btn-secondary" onclick="document.getElementById('modalWA').style.display='none'">Cancelar</button>
                <button class="btn btn-sm btn-success" onclick="enviarWA()"><i class="fab fa-whatsapp"></i> Enviar</button>
            </div>
        </div>
    </div>

    <!-- Alertas zombi / duplicados -->
    <?php if(!empty($zombis)): ?>
    <div class="alert alert-warning alert-dismissible fade show py-2 no-print" role="alert">
        <i class="fas fa-ghost me-2"></i><strong><?php echo count($zombis); ?> sesión(es) zombi</strong> (ABIERTA hace más de <?php echo $horasZombi; ?> h):
        <?php foreach($zombis as $sid=>$h): ?>
            <span class="badge bg-warning text-dark ms-1">#<?php echo $sid; ?> (<?php echo $h; ?>h)</span>
        <?php endforeach; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if(!empty($duplicadosIds)): ?>
    <div class="alert alert-danger alert-dismissible fade show py-2 no-print" role="alert">
        <i class="fas fa-copy me-2"></i><strong><?php echo count($duplicadosIds); ?> ticket(s) posiblemente duplicado(s)</strong> (mismo cajero + monto ± 2 min). Marcados con <span class="badge bg-warning text-dark">DUP</span> en la tabla.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Filtros -->
    <form method="get" action="reportes_caja_multi.php" id="frm-sesiones">
        <div class="filter-bar mb-3 no-print">
            <div class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small mb-1">Sucursal</label>
                    <select name="sucursal" class="form-select form-select-sm" onchange="this.form.submit()">
                        <?php foreach($sucursales as $s): ?>
                            <option value="<?php echo $s['id']; ?>" <?php echo intval($s['id'])===$sucursalID?'selected':''; ?>><?php echo htmlspecialchars(($s['empresa_nombre']?:'').' / '.$s['nombre']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Desde</label>
                    <input type="date" class="form-control form-control-sm" name="fdesde" value="<?php echo htmlspecialchars($fDesde); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Hasta</label>
                    <input type="date" class="form-control form-control-sm" name="fhasta" value="<?php echo htmlspecialchars($fHasta); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Cajero</label>
                    <select name="fcajero" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        <?php foreach($cajeros as $c): ?>
                            <option value="<?php echo htmlspecialchars($c); ?>" <?php echo $fCajero===$c?'selected':''; ?>><?php echo htmlspecialchars($c); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label small mb-1">Estado</label>
                    <select name="festado" class="form-select form-select-sm">
                        <option value="">—</option>
                        <option value="ABIERTA" <?php echo $fEstado==='ABIERTA'?'selected':''; ?>>ABIERTA</option>
                        <option value="CERRADA" <?php echo $fEstado==='CERRADA'?'selected':''; ?>>CERRADA</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Descuadre &gt; / Zombi &gt;Xh</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><input type="checkbox" name="fdesc" value="1" <?php echo $fDescuadre?'checked':''; ?>></span>
                        <input type="number" step="0.01" name="umbral" value="<?php echo $umbralDescuadre; ?>" class="form-control" placeholder="$100">
                        <input type="number" name="hzombi" value="<?php echo $horasZombi; ?>" class="form-control" placeholder="12h" title="Horas zombi">
                    </div>
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-sm btn-primary w-100"><i class="fas fa-filter"></i> Filtrar</button>
                </div>
            </div>
            <div class="mt-2 d-flex gap-1 flex-wrap align-items-center">
                <small class="text-muted me-2">Presets:</small>
                <button type="button" class="btn btn-outline-secondary preset-btn" data-preset="hoy">Hoy</button>
                <button type="button" class="btn btn-outline-secondary preset-btn" data-preset="ayer">Ayer</button>
                <button type="button" class="btn btn-outline-secondary preset-btn" data-preset="semana">Últ. 7d</button>
                <button type="button" class="btn btn-outline-secondary preset-btn" data-preset="mes">Mes actual</button>
                <button type="button" class="btn btn-outline-secondary preset-btn" data-preset="mes_pasado">Mes pasado</button>
                <button type="button" class="btn btn-outline-info preset-btn" data-preset="abiertas">Abiertas</button>
                <button type="button" class="btn btn-outline-danger preset-btn" data-preset="descuadre">Descuadre</button>
                <button type="button" class="btn btn-outline-warning preset-btn" data-preset="noauditadas">Sin auditar</button>
                <span class="ms-auto text-muted small"><?php echo count($sesionesLista); ?> listadas</span>
            </div>
        </div>

        <!-- Lista de sesiones -->
        <div class="card border-0 shadow-sm mb-3 session-list-card no-print">
            <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h6 class="mb-0 fw-bold"><i class="fas fa-list-check"></i> Sesiones (máx. 100)</h6>
                <div class="d-flex gap-1 flex-wrap">
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="selAll('a',true)">Todas A</button>
                    <button type="button" class="btn btn-sm btn-outline-warning" onclick="selAll('b',true)">Todas B</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="selAll('a',false);selAll('b',false)">Limpiar</button>
                    <button type="button" class="btn btn-sm btn-outline-dark" onclick="saveSelection()" title="Guardar"><i class="fas fa-save"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-dark" onclick="loadSelection()" title="Restaurar"><i class="fas fa-undo"></i></button>
                    <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-play"></i> Generar</button>
                </div>
            </div>
            <div class="table-responsive" style="max-height: 320px; overflow-y: auto;">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th width="28">A</th><th width="28">B</th>
                            <th>ID</th><th>✓</th><th>Estado</th>
                            <th>F.Contable</th><th>Apertura</th><th>Cierre</th><th>Cajero</th>
                            <th class="text-end">M.Ini</th><th class="text-end">M.Sist</th>
                            <th class="text-end">M.Real</th><th class="text-end">Diff</th>
                            <th>Nota</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach($sesionesLista as $s):
                        $sid       = intval($s['id']);
                        $checkedA  = in_array($sid, $idsA, true) ? 'checked' : '';
                        $checkedB  = in_array($sid, $idsB, true) ? 'checked' : '';
                        $audVal    = intval($s['auditada'] ?? 0);
                        $estadoBdg = $s['estado'] === 'ABIERTA' ? 'bg-success' : 'bg-secondary';
                        $diff      = floatval($s['diferencia']);
                        $diffCls   = $diff < 0 ? 'text-danger' : ($diff > 0 ? 'text-warning' : 'text-muted');
                        $isWarn    = abs($diff) > $umbralDescuadre;
                        $isZombi   = isset($zombis[$sid]);
                        $rowCls    = '';
                        if ($checkedA && $checkedB) $rowCls = 'sel-ab';
                        elseif ($checkedA)           $rowCls = 'sel-a';
                        elseif ($checkedB)           $rowCls = 'sel-b';
                        if ($isWarn)  $rowCls .= ' row-warning';
                        if ($isZombi) $rowCls .= ' row-zombi';
                        if ($audVal)  $rowCls .= ' row-auditada';
                    ?>
                        <tr class="sesion-row <?php echo $rowCls; ?>"
                            data-sid="<?php echo $sid; ?>"
                            data-fecha="<?php echo htmlspecialchars($s['fecha_contable']??''); ?>"
                            data-estado="<?php echo htmlspecialchars($s['estado']); ?>"
                            data-diff="<?php echo $diff; ?>"
                            data-auditada="<?php echo $audVal; ?>">
                            <td><input class="form-check-input chk-a" type="checkbox" name="ids[]"  value="<?php echo $sid; ?>" <?php echo $checkedA; ?>></td>
                            <td><input class="form-check-input chk-b" type="checkbox" name="idsB[]" value="<?php echo $sid; ?>" <?php echo $checkedB; ?>></td>
                            <td class="fw-bold">
                                #<?php echo $sid; ?>
                                <?php if($isZombi): ?><i class="fas fa-ghost text-purple ms-1" title="Zombi: <?php echo $zombis[$sid]; ?>h abierta" style="color:#6610f2;"></i><?php endif; ?>
                            </td>
                            <!-- #18 Auditada toggle -->
                            <td class="text-center">
                                <button type="button"
                                    class="btn btn-sm p-0 border-0 btn-aud"
                                    data-sid="<?php echo $sid; ?>"
                                    data-val="<?php echo $audVal; ?>"
                                    title="<?php echo $audVal ? 'Auditada — clic para desmarcar' : 'Sin auditar — clic para marcar'; ?>">
                                    <?php if($audVal): ?>
                                        <i class="fas fa-check-circle text-success"></i>
                                    <?php else: ?>
                                        <i class="far fa-circle text-muted"></i>
                                    <?php endif; ?>
                                </button>
                            </td>
                            <td><span class="badge <?php echo $estadoBdg; ?>"><?php echo htmlspecialchars($s['estado']); ?></span></td>
                            <td><?php echo $s['fecha_contable'] ? date('d/m/Y', strtotime($s['fecha_contable'])) : '-'; ?></td>
                            <td><?php echo $s['fecha_apertura'] ? date('d/m/Y h:i A', strtotime($s['fecha_apertura'])) : '-'; ?></td>
                            <td><?php echo $s['fecha_cierre'] ? date('d/m/Y h:i A', strtotime($s['fecha_cierre'])) : '<span class="text-success">—</span>'; ?></td>
                            <td><?php echo htmlspecialchars($s['nombre_cajero'] ?? ''); ?></td>
                            <td class="text-end">$<?php echo number_format(floatval($s['monto_inicial']),2); ?></td>
                            <td class="text-end">$<?php echo number_format(floatval($s['monto_final_sistema']),2); ?></td>
                            <td class="text-end">$<?php echo number_format(floatval($s['monto_final_real']),2); ?></td>
                            <td class="text-end <?php echo $diffCls; ?> fw-bold">
                                <?php if($isWarn): ?><i class="fas fa-exclamation-triangle text-danger me-1"></i><?php endif; ?>
                                $<?php echo number_format($diff,2); ?>
                            </td>
                            <!-- #19 Nota inline -->
                            <td class="nota-cell" data-sid="<?php echo $sid; ?>" style="max-width:180px;" title="Clic para editar nota">
                                <span class="nota-text text-muted small"><?php echo htmlspecialchars(mb_substr($s['nota']??'',0,40)); ?></span>
                                <i class="fas fa-pen text-primary ms-1" style="font-size:.65rem;opacity:.5;"></i>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if(empty($sesionesLista)): ?>
                        <tr><td colspan="14" class="text-center text-muted py-4">No hay sesiones para los filtros aplicados.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </form>

    <?php if(!$haySeleccion): ?>
        <div class="alert alert-info no-print"><i class="fas fa-info-circle"></i> Marca sesiones en la columna <b>A</b> (y opcionalmente en <b>B</b> para comparar) y pulsa <b>Generar</b>.</div>
    <?php else: ?>

    <!-- Resumen físico -->
    <div class="row g-3 mb-2">
        <div class="col-md-3 col-sm-6"><div class="kpi-card border-start border-4 border-info"><small class="text-muted fw-bold">SESIONES</small><h3 class="fw-bold mb-0"><?php echo count($A['sesiones']); ?></h3></div></div>
        <div class="col-md-3 col-sm-6"><div class="kpi-card border-start border-4 border-secondary"><small class="text-muted fw-bold">TOTAL APERTURAS</small><h3 class="fw-bold mb-0">$<?php echo number_format($A['totalAperturas'],2); ?></h3></div></div>
        <div class="col-md-3 col-sm-6"><div class="kpi-card border-start border-4 border-secondary"><small class="text-muted fw-bold">CIERRE SIST. / REAL</small><h3 class="fw-bold mb-0" style="font-size:1rem;">$<?php echo number_format($A['totalCierresSistema'],2); ?> / $<?php echo number_format($A['totalCierresReal'],2); ?></h3></div></div>
        <div class="col-md-3 col-sm-6"><div class="kpi-card border-start border-4 <?php echo $A['totalDiferencias'] < 0 ? 'border-danger' : 'border-success'; ?>"><small class="text-muted fw-bold">DIFERENCIA TOTAL</small><h3 class="fw-bold mb-0 <?php echo $A['totalDiferencias'] < 0 ? 'text-danger' : 'text-success'; ?>">$<?php echo number_format($A['totalDiferencias'],2); ?></h3></div></div>
    </div>

    <!-- Devoluciones / Métodos -->
    <div class="row g-3 mb-2">
        <div class="col-md-4"><div class="kpi-card border-start border-4 border-danger"><div class="d-flex justify-content-between align-items-center"><div><small class="text-danger fw-bold">CANTIDAD DEVOLUCIONES</small><h3 class="fw-bold mb-0 text-danger"><?php echo $A['cantDevoluciones']; ?></h3></div><div class="kpi-icon bg-danger bg-opacity-10 text-danger"><i class="fas fa-undo"></i></div></div></div></div>
        <div class="col-md-4"><div class="kpi-card border-start border-4 border-danger"><div class="d-flex justify-content-between align-items-center"><div><small class="text-danger fw-bold">VALOR REEMBOLSADO</small><h3 class="fw-bold mb-0 text-danger">$<?php echo number_format($A['valorDevoluciones'],2); ?></h3></div><div class="kpi-icon bg-danger bg-opacity-10 text-danger"><i class="fas fa-money-bill-wave"></i></div></div></div></div>
        <div class="col-md-4">
            <div class="kpi-card border-start border-4 border-primary">
                <div class="d-flex justify-content-between align-items-center mb-1"><small class="text-primary fw-bold">RESUMEN POR PAGO</small><div class="text-primary"><i class="fas fa-wallet"></i></div></div>
                <?php if(empty($A['metodosPago'])): ?><div class="text-muted small">Sin movimientos</div>
                <?php else: foreach($A['metodosPago'] as $metodo=>$valor): ?>
                    <div class="d-flex justify-content-between border-bottom border-light small"><span class="text-muted text-uppercase" style="font-size:.65rem;"><?php echo htmlspecialchars($metodo); ?></span><span class="fw-bold" style="font-size:.75rem;">$<?php echo number_format($valor,2); ?></span></div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <!-- KPIs proyectados -->
    <div class="row g-3 mb-2">
        <div class="col-md-4 col-sm-6"><div class="kpi-card"><div class="kpi-icon bg-info bg-opacity-10 text-info"><i class="fas fa-chart-line"></i></div><small class="text-muted fw-bold">CRECIMIENTO NEGOC.</small><h3 class="fw-bold mb-0">$<?php echo number_format($ticketPromedio,2); ?></h3><small class="text-muted">(20%)</small></div></div>
        <div class="col-md-4 col-sm-6"><div class="kpi-card"><div class="kpi-icon bg-warning bg-opacity-10 text-warning"><i class="fas fa-percentage"></i></div><small class="text-muted fw-bold">30% VENTA</small><h3 class="fw-bold mb-0">$<?php echo number_format($ventasPorHora,2); ?></h3></div></div>
        <div class="col-md-4 col-sm-6"><div class="kpi-card border-start border-4 border-success"><div class="kpi-icon bg-success bg-opacity-10 text-success"><i class="fas fa-coins"></i></div><small class="text-muted fw-bold">10% VENTA</small><h3 class="fw-bold mb-0 text-success">$<?php echo number_format($variacion,2); ?></h3></div></div>
    </div>

    <!-- Highlights -->
    <div class="row g-3 mb-3 kpi-highlight-row">
        <div class="col-md-4 col-sm-6"><div class="kpi-card border-start border-4 border-primary"><div class="kpi-icon bg-primary bg-opacity-10 text-primary"><i class="fas fa-dollar-sign"></i></div><small class="text-muted fw-bold">VENTA NETA</small><h3 class="fw-bold mb-0">$<?php echo number_format($A['totalVentaNeta'],2); ?></h3><small class="text-muted">(Total movimientos)</small></div></div>
        <div class="col-md-4 col-sm-6"><div class="kpi-card border-start border-4 border-success"><div class="kpi-icon bg-success bg-opacity-10 text-success"><i class="fas fa-wallet"></i></div><small class="text-muted fw-bold">VENTAS REALES</small><h3 class="fw-bold mb-0 text-success">$<?php echo number_format($A['ventasReales'],2); ?></h3><small class="text-muted">(<?php echo $A['conteoTickets']; ?> tickets)</small></div></div>
        <div class="col-md-4 col-sm-6"><div class="kpi-card border-start border-4 border-success"><div class="kpi-icon bg-success bg-opacity-10 text-success"><i class="fas fa-chart-line"></i></div><small class="text-muted fw-bold">GANANCIA</small><h3 class="fw-bold mb-0 text-success">$<?php echo number_format($A['ganancia'],2); ?></h3><small class="text-muted">Margen: <?php echo number_format($A['margen'],1); ?>%</small></div></div>
    </div>

    <?php if($hayComparativa): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white py-2"><h6 class="mb-0 fw-bold"><i class="fas fa-balance-scale"></i> Comparativa A vs B</h6></div>
        <div class="card-body">
            <div class="row g-3">
            <?php
            $cmpRows = [
                ['Sesiones',$A['sesiones'],$B['sesiones'],'#'],
                ['Ventas',$A['ventasReales'],$B['ventasReales'],'$'],
                ['Tickets',$A['conteoTickets'],$B['conteoTickets'],'#'],
                ['Ganancia',$A['ganancia'],$B['ganancia'],'$'],
                ['Margen %',$A['margen'],$B['margen'],'%'],
                ['Devoluciones',$A['valorDevoluciones'],$B['valorDevoluciones'],'$'],
                ['Diff caja',$A['totalDiferencias'],$B['totalDiferencias'],'$'],
            ];
            foreach($cmpRows as $row):
                $av = is_array($row[1]) ? count($row[1]) : floatval($row[1]);
                $bv = is_array($row[2]) ? count($row[2]) : floatval($row[2]);
                $delta = $bv - $av;
                $pct   = $av != 0 ? ($delta / abs($av)) * 100 : 0;
                $cls   = $delta > 0 ? 'delta-up' : ($delta < 0 ? 'delta-down' : 'text-muted');
                $arrow = $delta > 0 ? '▲' : ($delta < 0 ? '▼' : '=');
                $fmt   = fn($v,$u) => $u==='$'?'$'.number_format($v,2):($u==='%'?number_format($v,1).'%':number_format($v));
            ?>
            <div class="col-md-6 col-lg-3">
                <div class="kpi-card" style="border-left:6px solid #0d6efd;">
                    <small class="text-muted fw-bold"><?php echo $row[0]; ?></small>
                    <div class="d-flex justify-content-between align-items-end mt-1">
                        <div><small class="text-primary">A</small><div class="fw-bold"><?php echo $fmt($av,$row[3]); ?></div></div>
                        <div class="text-end"><small class="text-warning">B</small><div class="fw-bold"><?php echo $fmt($bv,$row[3]); ?></div></div>
                    </div>
                    <div class="mt-2 text-center <?php echo $cls; ?> fw-bold">
                        <?php echo $arrow.' '.$fmt($delta,$row[3]); ?>
                        <?php if($av!=0): ?><small>(<?php echo number_format($pct,1); ?>%)</small><?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Gráficos -->
    <div class="row g-3 mb-3">
        <div class="col-md-6"><div class="chart-card"><h6 class="fw-bold mb-2"><i class="fas fa-chart-bar"></i> Ventas por sesión</h6><canvas id="chartVentasSesion" height="120"></canvas></div></div>
        <div class="col-md-3"><div class="chart-card"><h6 class="fw-bold mb-2"><i class="fas fa-chart-pie"></i> Métodos de pago</h6><canvas id="chartMetodos" height="200"></canvas></div></div>
        <div class="col-md-3"><div class="chart-card"><h6 class="fw-bold mb-2"><i class="fas fa-chart-pie"></i> Top productos</h6><canvas id="chartProductos" height="200"></canvas></div></div>
    </div>

    <!-- Top productos / clientes / cajero -->
    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-2"><h6 class="mb-0 fw-bold"><i class="fas fa-box"></i> Top 10 Productos</h6></div>
                <div class="table-responsive"><table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Producto</th><th class="text-end">Cant.</th><th class="text-end">Monto</th><th class="text-end">Gan.</th></tr></thead>
                    <tbody>
                    <?php foreach($A['topProductos'] as $p): ?>
                        <tr><td><?php echo htmlspecialchars($p['nombre']); ?></td><td class="text-end"><?php echo number_format($p['cantidad']); ?></td><td class="text-end">$<?php echo number_format($p['monto'],2); ?></td><td class="text-end text-success">$<?php echo number_format($p['ganancia'],2); ?></td></tr>
                    <?php endforeach; ?>
                    <?php if(empty($A['topProductos'])): ?><tr><td colspan="4" class="text-center text-muted">Sin datos</td></tr><?php endif; ?>
                    </tbody>
                </table></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-2"><h6 class="mb-0 fw-bold"><i class="fas fa-users"></i> Top 10 Clientes</h6></div>
                <div class="table-responsive"><table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Cliente</th><th class="text-end">Tickets</th><th class="text-end">Monto</th></tr></thead>
                    <tbody>
                    <?php foreach($A['topClientes'] as $c): ?>
                        <tr><td><?php echo htmlspecialchars($c['cliente']); ?></td><td class="text-end"><?php echo $c['tickets']; ?></td><td class="text-end fw-bold">$<?php echo number_format($c['monto'],2); ?></td></tr>
                    <?php endforeach; ?>
                    <?php if(empty($A['topClientes'])): ?><tr><td colspan="3" class="text-center text-muted">Sin datos</td></tr><?php endif; ?>
                    </tbody>
                </table></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-2"><h6 class="mb-0 fw-bold"><i class="fas fa-user-tie"></i> Por Cajero</h6></div>
                <div class="table-responsive"><table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Cajero</th><th class="text-end">Ses.</th><th class="text-end">Tk</th><th class="text-end">Ventas</th><th class="text-end">Diff</th></tr></thead>
                    <tbody>
                    <?php foreach($A['porCajero'] as $c): $cls=$c['diferencia']<0?'text-danger':($c['diferencia']>0?'text-warning':''); ?>
                        <tr><td><?php echo htmlspecialchars($c['cajero']); ?></td><td class="text-end"><?php echo $c['sesiones']; ?></td><td class="text-end"><?php echo $c['tickets']; ?></td><td class="text-end fw-bold">$<?php echo number_format($c['ventas'],2); ?></td><td class="text-end <?php echo $cls; ?>">$<?php echo number_format($c['diferencia'],2); ?></td></tr>
                    <?php endforeach; ?>
                    <?php if(empty($A['porCajero'])): ?><tr><td colspan="5" class="text-center text-muted">Sin datos</td></tr><?php endif; ?>
                    </tbody>
                </table></div>
            </div>
        </div>
    </div>

    <!-- Asiento contable preview -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold"><i class="fas fa-book"></i> Asiento Contable Consolidado (Preview)</h6>
            <small class="text-muted">No registrado en libro diario</small>
        </div>
        <div class="table-responsive"><table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Cuenta</th><th>Concepto</th><th class="text-end">Debe</th><th class="text-end">Haber</th></tr></thead>
            <tbody>
            <?php $totDebe=0;
            foreach($A['metodosPago'] as $m=>$v):
                $cuenta = strtolower($m)==='efectivo'?'1110 Caja':(strtolower($m)==='transferencia'?'1120 Banco':(strtolower($m)==='gasto casa'?'5300 Gastos':'1190 Otros'));
                $totDebe += $v;
            ?>
                <tr><td class="fw-bold"><?php echo $cuenta; ?></td><td>Cobros <?php echo htmlspecialchars($m); ?></td><td class="text-end">$<?php echo number_format($v,2); ?></td><td></td></tr>
            <?php endforeach; $totHaber=$A['ventasReales']; ?>
            <tr><td class="fw-bold">4100 Ventas</td><td>Ventas del periodo</td><td></td><td class="text-end">$<?php echo number_format($totHaber,2); ?></td></tr>
            <?php if(abs($totDebe-$totHaber)>0.01): ?>
                <tr class="table-warning"><td colspan="2" class="fw-bold">Ajuste / Devoluciones</td><td class="text-end"><?php echo $totHaber>$totDebe?'$'.number_format($totHaber-$totDebe,2):''; ?></td><td class="text-end"><?php echo $totDebe>$totHaber?'$'.number_format($totDebe-$totHaber,2):''; ?></td></tr>
            <?php endif; ?>
            <tr class="table-secondary fw-bold"><td colspan="2">TOTAL</td><td class="text-end">$<?php echo number_format(max($totDebe,$totHaber),2); ?></td><td class="text-end">$<?php echo number_format(max($totDebe,$totHaber),2); ?></td></tr>
            </tbody>
        </table></div>
    </div>

    <!-- Tabla de movimientos -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-2">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="mb-0 fw-bold">Movimientos Consolidados (<?php echo count($A['tickets']); ?>)</h5>
                <div class="d-flex gap-2 flex-wrap align-items-center no-print">
                    <!-- #20 Búsqueda -->
                    <input type="search" id="ticket-search" class="form-control form-control-sm" placeholder="Buscar ticket, cliente, monto…" style="width:200px;">
                    <!-- #21 Filtro método de pago -->
                    <div class="d-flex gap-1 flex-wrap" id="mp-filters">
                        <button class="btn btn-sm btn-outline-secondary mp-filter-btn active" data-mp="TODOS">Todos</button>
                        <?php foreach(array_keys($A['metodosPago']) as $mp): ?>
                            <button class="btn btn-sm btn-outline-secondary mp-filter-btn" data-mp="<?php echo htmlspecialchars($mp); ?>"><?php echo htmlspecialchars($mp); ?></button>
                        <?php endforeach; ?>
                        <?php if(!empty($duplicadosIds)): ?>
                            <button class="btn btn-sm btn-outline-warning mp-filter-btn" data-mp="DUPLICADOS">Solo duplicados</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="table-responsive">
            <table id="tickets-table" class="table table-hover mb-0 align-middle">
                <thead class="table-light"><tr>
                    <th width="40"></th><th>Ticket</th><th>Caja</th><th>Fecha</th>
                    <th>Cliente / Concepto</th><th>Tipo Servicio</th>
                    <th class="text-center">Origen</th><th>Forma Pago</th><th>Total</th>
                </tr></thead>
                <tbody id="tickets-tbody">
                <?php $dupIds = array_keys($duplicadosIds);
                foreach($A['tickets'] as $t):
                    $tid     = $t['id'];
                    $items   = $A['detallesPorTicket'][$tid] ?? [];
                    $isDup   = isset($duplicadosIds[$tid]);
                    $isRefund= ($t['total'] < 0);
                    $rowClass= ''; $badgePagoClass = 'bg-light text-dark border';
                    if ($isDup) $rowClass = 'row-duplicate';
                    if ($isRefund) { $rowClass = 'row-refund'; $badgePagoClass = 'bg-refund-badge'; }
                    elseif ($t['tipo_servicio']==='reserva') { if(!$isDup) $rowClass='row-reserva'; }
                    elseif ($t['tipo_servicio']==='llevar')  { if(!$isDup) $rowClass='row-llevar'; }
                    else {
                        switch(strtolower($t['metodo_pago'])) {
                            case 'efectivo':     if(!$isDup) $rowClass='row-efectivo'; $badgePagoClass='bg-efectivo'; break;
                            case 'transferencia':if(!$isDup) $rowClass='row-transfer'; $badgePagoClass='bg-transfer'; break;
                            case 'gasto casa':   if(!$isDup) $rowClass='row-gasto';    $badgePagoClass='bg-gasto';   break;
                        }
                    }
                ?>
                    <tr class="ticket-row <?php echo $rowClass; ?>"
                        data-mp="<?php echo htmlspecialchars($t['metodo_pago']); ?>"
                        data-search="<?php echo htmlspecialchars(strtolower('#'.str_pad($tid,6,'0',STR_PAD_LEFT).' '.$t['cliente_nombre'].' '.$t['total'].' '.$t['metodo_pago'])); ?>"
                        data-dup="<?php echo $isDup?'1':'0'; ?>"
                        data-bs-toggle="collapse" data-bs-target="#detail-<?php echo $tid; ?>">
                        <td class="text-center text-muted"><i class="fas fa-chevron-down"></i></td>
                        <td class="fw-bold">
                            #<?php echo str_pad($tid,6,'0',STR_PAD_LEFT); ?>
                            <?php if($isDup): ?><span class="badge bg-warning text-dark ms-1" title="Posible duplicado">DUP</span><?php endif; ?>
                        </td>
                        <td><span class="badge bg-dark">C#<?php echo intval($t['id_caja']); ?></span></td>
                        <td><?php echo date('d/m h:i A', strtotime($t['fecha'])); ?></td>
                        <td><?php echo htmlspecialchars($t['cliente_nombre']); if($isRefund) echo " <span class='badge bg-danger'>DEVOLUCIÓN</span>"; ?></td>
                        <td><?php echo strtoupper($t['tipo_servicio']); ?></td>
                        <td class="text-center"><?php echo getCanalBadgeRCM($t['canal_origen']??'POS',$canalMap); ?></td>
                        <td><span class="badge badge-pago <?php echo $badgePagoClass; ?>"><?php echo $t['metodo_pago']; ?></span></td>
                        <td class="text-end fw-bold fs-5">$<?php echo number_format($t['total'],2); ?></td>
                    </tr>
                    <tr class="collapse" id="detail-<?php echo $tid; ?>">
                        <td colspan="9" class="p-0">
                            <div class="detail-row p-3">
                                <table class="table table-sm mb-0 bg-white border">
                                    <thead class="text-muted small"><tr><th>Producto</th><th class="text-end">Cant.</th><th class="text-end">Precio</th><th class="text-end">Subtotal</th><th class="text-end">Acción</th></tr></thead>
                                    <tbody>
                                    <?php foreach($items as $item):
                                        $isItemRefunded = isset($item['reembolsado']) && $item['reembolsado']==1;
                                        $subtotal = $item['cantidad'] * $item['precio'];
                                        $isNeg = $item['cantidad'] < 0;
                                    ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($item['nombre']); if($isItemRefunded && !$isNeg) echo " <span class='badge bg-secondary'>Origen Dev.</span>"; ?></td>
                                            <td class="text-end fw-bold <?php echo $isNeg?'text-danger':''; ?>"><?php echo floatval($item['cantidad']); ?></td>
                                            <td class="text-end">$<?php echo number_format($item['precio'],2); ?></td>
                                            <td class="text-end fw-bold <?php echo $isNeg?'text-danger':''; ?>">$<?php echo number_format($subtotal,2); ?></td>
                                            <td class="text-end">
                                                <?php if(!$isRefund && !$isItemRefunded && !$isNeg): ?>
                                                    <button class="btn btn-sm btn-outline-danger py-0" onclick="refundItem(<?php echo $item['id']; ?>,'<?php echo htmlspecialchars($item['nombre']); ?>')"><i class="fas fa-undo"></i> Devolver</button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if(empty($A['tickets'])): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No hay movimientos.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php endif; // haySeleccion ?>
</div><!-- /container-fluid -->

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const SUCURSAL = <?php echo $sucursalID; ?>;
const SK = 'rcm_sel_' + SUCURSAL;
const RESUMEN = <?php echo json_encode($resumenEjecutivo); ?>;

// ── FASE 1: Sticky KPI bar ────────────────────────────────────
const stickyBar = document.getElementById('sticky-kpi');
if (stickyBar) {
    const TRIGGER = 220;
    window.addEventListener('scroll', () => {
        stickyBar.classList.toggle('visible', window.scrollY > TRIGGER);
    }, { passive: true });
}

// ── Selección de sesiones ─────────────────────────────────────
function setRowClass(row) {
    const a = row.querySelector('.chk-a')?.checked;
    const b = row.querySelector('.chk-b')?.checked;
    row.classList.remove('sel-a','sel-b','sel-ab');
    if (a && b) row.classList.add('sel-ab');
    else if (a) row.classList.add('sel-a');
    else if (b) row.classList.add('sel-b');
}
function selAll(group, state) {
    document.querySelectorAll(group==='a'?'.chk-a':'.chk-b')
        .forEach(c => { c.checked = state; setRowClass(c.closest('tr')); });
}
document.querySelectorAll('.chk-a,.chk-b').forEach(c =>
    c.addEventListener('change', e => setRowClass(e.target.closest('tr')))
);

// ── Presets ───────────────────────────────────────────────────
document.querySelectorAll('.preset-btn').forEach(btn => btn.addEventListener('click', () => {
    const p = btn.dataset.preset;
    const today = new Date(); const fmt = d => d.toISOString().slice(0,10);
    const rows = document.querySelectorAll('.sesion-row');
    const mark = (row) => { const c = row.querySelector('.chk-a'); if(c){ c.checked=true; setRowClass(row); } };

    if (['hoy','ayer','semana','mes','mes_pasado'].includes(p)) {
        let from, to = fmt(today);
        if (p==='hoy')        from = fmt(today);
        if (p==='ayer')       { const y=new Date(today); y.setDate(y.getDate()-1); from=to=fmt(y); }
        if (p==='semana')     { const d=new Date(today); d.setDate(d.getDate()-7); from=fmt(d); }
        if (p==='mes')        from = fmt(new Date(today.getFullYear(),today.getMonth(),1));
        if (p==='mes_pasado') { from=fmt(new Date(today.getFullYear(),today.getMonth()-1,1)); to=fmt(new Date(today.getFullYear(),today.getMonth(),0)); }
        selAll('a',false);
        rows.forEach(r => { const f=r.dataset.fecha; if(f>=from&&f<=to) mark(r); });
    } else if (p==='abiertas') {
        selAll('a',false); rows.forEach(r => { if(r.dataset.estado==='ABIERTA') mark(r); });
    } else if (p==='descuadre') {
        const u = parseFloat(document.querySelector('[name=umbral]')?.value||'0');
        selAll('a',false); rows.forEach(r => { if(Math.abs(parseFloat(r.dataset.diff||'0'))>u) mark(r); });
    } else if (p==='noauditadas') {
        selAll('a',false); rows.forEach(r => { if(r.dataset.auditada==='0') mark(r); });
    }
}));

// ── localStorage ──────────────────────────────────────────────
function saveSelection() {
    const a=[...document.querySelectorAll('.chk-a:checked')].map(c=>c.value);
    const b=[...document.querySelectorAll('.chk-b:checked')].map(c=>c.value);
    localStorage.setItem(SK, JSON.stringify({a,b}));
    alert('Selección guardada.');
}
function loadSelection() {
    const raw = localStorage.getItem(SK);
    if (!raw) { alert('Sin selección guardada.'); return; }
    const o = JSON.parse(raw);
    selAll('a',false); selAll('b',false);
    (o.a||[]).forEach(v=>{ const c=document.querySelector(`.chk-a[value="${v}"]`); if(c){c.checked=true;setRowClass(c.closest('tr'));} });
    (o.b||[]).forEach(v=>{ const c=document.querySelector(`.chk-b[value="${v}"]`); if(c){c.checked=true;setRowClass(c.closest('tr'));} });
}
document.getElementById('frm-sesiones')?.addEventListener('submit', () => {
    const a=[...document.querySelectorAll('.chk-a:checked')].map(c=>c.value);
    const b=[...document.querySelectorAll('.chk-b:checked')].map(c=>c.value);
    try { localStorage.setItem(SK+'_last', JSON.stringify({a,b})); } catch(e){}
});

// ── #18 Auditada toggle ───────────────────────────────────────
document.querySelectorAll('.btn-aud').forEach(btn => btn.addEventListener('click', async e => {
    e.stopPropagation();
    const sid = btn.dataset.sid;
    const cur = parseInt(btn.dataset.val);
    const nv  = cur ? 0 : 1;
    const fd  = new FormData();
    fd.append('ajax_action','set_auditada'); fd.append('id',sid); fd.append('val',nv);
    const res = await fetch('reportes_caja_multi.php', {method:'POST',body:fd});
    const j   = await res.json();
    if (j.status==='ok') {
        btn.dataset.val = nv;
        btn.innerHTML   = nv ? '<i class="fas fa-check-circle text-success"></i>' : '<i class="far fa-circle text-muted"></i>';
        btn.title       = nv ? 'Auditada — clic para desmarcar' : 'Sin auditar — clic para marcar';
        const row = btn.closest('tr');
        if (row) { row.dataset.auditada=nv; row.classList.toggle('row-auditada', nv===1); }
    }
}));

// ── #19 Nota inline ───────────────────────────────────────────
document.querySelectorAll('.nota-cell').forEach(cell => {
    cell.addEventListener('click', function(e) {
        if (this.querySelector('.nota-input')) return;
        const sid  = this.dataset.sid;
        const span = this.querySelector('.nota-text');
        const cur  = span ? span.textContent.trim() : '';
        const inp  = document.createElement('input');
        inp.type='text'; inp.className='nota-input'; inp.value=cur;
        this.innerHTML = '';
        this.appendChild(inp);
        inp.focus();
        const guardar = async () => {
            const nota = inp.value.trim();
            const fd = new FormData();
            fd.append('ajax_action','set_nota'); fd.append('id',sid); fd.append('nota',nota);
            const res = await fetch('reportes_caja_multi.php',{method:'POST',body:fd});
            const j   = await res.json();
            cell.innerHTML = `<span class="nota-text text-muted small">${escHtml(j.nota||nota)}</span><i class="fas fa-pen text-primary ms-1" style="font-size:.65rem;opacity:.5;"></i>`;
        };
        inp.addEventListener('blur', guardar);
        inp.addEventListener('keydown', e => { if(e.key==='Enter') inp.blur(); if(e.key==='Escape'){inp.value=cur;inp.blur();} });
    });
});
function escHtml(s){ const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }

// ── #20 Búsqueda de tickets ───────────────────────────────────
const ticketSearch = document.getElementById('ticket-search');
if (ticketSearch) {
    ticketSearch.addEventListener('input', filterTickets);
}

// ── #21 Filtro método de pago ─────────────────────────────────
document.querySelectorAll('.mp-filter-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.mp-filter-btn').forEach(b=>b.classList.remove('active'));
        btn.classList.add('active');
        filterTickets();
    });
});

function filterTickets() {
    const q   = (ticketSearch?.value||'').toLowerCase().trim();
    const mp  = document.querySelector('.mp-filter-btn.active')?.dataset.mp || 'TODOS';
    document.querySelectorAll('#tickets-tbody .ticket-row').forEach(row => {
        const matchQ  = !q || (row.dataset.search||'').includes(q);
        const matchMp = mp==='TODOS' || (mp==='DUPLICADOS'?row.dataset.dup==='1':row.dataset.mp===mp);
        const show    = matchQ && matchMp;
        row.style.display = show ? '' : 'none';
        const tid = row.id || '';
        const det = document.querySelector('#detail-' + (row.dataset.bsTarget||'').replace('#detail-',''));
        // Ocultar también la fila de detalle asociada
        const next = row.nextElementSibling;
        if (next && next.classList.contains('collapse')) next.style.display = show ? '' : 'none';
    });
}

// ── FASE 2: Enviar WhatsApp ───────────────────────────────────
async function enviarWA() {
    const phone = document.getElementById('wa-phone').value.trim().replace(/\D/g,'');
    const msg   = document.getElementById('wa-msg').value.trim();
    const res_el= document.getElementById('wa-resultado');
    if (!phone||!msg) { res_el.innerHTML='<span class="text-danger">Teléfono y mensaje requeridos.</span>'; return; }
    res_el.innerHTML='<span class="text-muted">Enviando…</span>';
    const fd = new FormData();
    fd.append('ajax_action','wa_send'); fd.append('phone',phone); fd.append('mensaje',msg);
    try {
        const r = await fetch('reportes_caja_multi.php',{method:'POST',body:fd});
        const j = await r.json();
        res_el.innerHTML = j.status==='ok'
            ? '<span class="text-success"><i class="fas fa-check"></i> '+j.msg+'</span>'
            : '<span class="text-danger"><i class="fas fa-times"></i> '+j.msg+'</span>';
        localStorage.setItem('rcm_wa_phone_'+SUCURSAL, phone);
    } catch(err) { res_el.innerHTML='<span class="text-danger">Error de conexión</span>'; }
}
// Restaurar teléfono guardado
const savedPhone = localStorage.getItem('rcm_wa_phone_'+SUCURSAL);
if (savedPhone && document.getElementById('wa-phone')) document.getElementById('wa-phone').value = savedPhone;

// ── #25 Copiar resumen ────────────────────────────────────────
function copiarResumen() {
    if (!RESUMEN) return;
    navigator.clipboard.writeText(RESUMEN)
        .then(()=>alert('Resumen copiado al portapapeles.'))
        .catch(()=>{
            const ta=document.createElement('textarea');
            ta.value=RESUMEN; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove();
            alert('Resumen copiado.');
        });
}

// ── PDF ───────────────────────────────────────────────────────
function downloadPDF() { alert('En el cuadro de impresión elige "Guardar como PDF".'); window.print(); }

// ── Refunds ───────────────────────────────────────────────────
async function refundItem(id, name) {
    if (!confirm(`¿Generar DEVOLUCIÓN para: ${name}?`)) return;
    try {
        const r = await fetch('pos_refund.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})});
        const j = await r.json();
        if (j.status==='success') { alert('Devolución generada.'); location.reload(); }
        else alert('Error: '+j.msg);
    } catch(e){ alert('Error de conexión'); }
}

// ── Gráficos ──────────────────────────────────────────────────
<?php if($haySeleccion): ?>
const sesData   = <?php echo json_encode(array_map(fn($s)=>['id'=>$s['id']], $A['sesiones'])); ?>;
const vps       = <?php $vps=[]; foreach($A['tickets'] as $t){$c=intval($t['id_caja']);if(!isset($vps[$c]))$vps[$c]=0;$vps[$c]+=floatval($t['total']);}; echo json_encode($vps); ?>;
new Chart(document.getElementById('chartVentasSesion'),{type:'bar',data:{labels:sesData.map(s=>'#'+s.id),datasets:[{label:'Ventas $',data:sesData.map(s=>vps[s.id]||0),backgroundColor:'#0d6efd'}]},options:{plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}}});
new Chart(document.getElementById('chartMetodos'),{type:'doughnut',data:{labels:<?php echo json_encode(array_keys($A['metodosPago'])); ?>,datasets:[{data:<?php echo json_encode(array_values($A['metodosPago'])); ?>,backgroundColor:['#198754','#0d6efd','#fd7e14','#6f42c1','#dc3545','#0dcaf0','#ffc107']}]}});
new Chart(document.getElementById('chartProductos'),{type:'doughnut',data:{labels:<?php echo json_encode(array_map(fn($p)=>mb_substr($p['nombre'],0,18),$A['topProductos'])); ?>,datasets:[{data:<?php echo json_encode(array_map(fn($p)=>floatval($p['monto']),$A['topProductos'])); ?>,backgroundColor:['#0d6efd','#198754','#fd7e14','#6f42c1','#dc3545','#0dcaf0','#ffc107','#20c997','#6610f2','#e83e8c']}]}});
<?php endif; ?>
</script>

<?php include_once 'menu_master.php'; ?>
</body>
</html>
