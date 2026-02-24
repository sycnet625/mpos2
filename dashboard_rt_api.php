<?php
// dashboard_rt_api.php — Datos en tiempo real para el tab de ventas del día
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'no autorizado']);
    exit;
}
header('Content-Type: application/json');
ini_set('display_errors', 0);

require_once 'db.php';
require_once 'config_loader.php';
date_default_timezone_set('America/Havana');
$pdo->exec("SET time_zone = '-05:00';");

$EMP_ID = intval($config['id_empresa']);
$SUC_ID = intval($config['id_sucursal']);
$hoy    = date('Y-m-d');
$ayer   = date('Y-m-d', strtotime('-1 day'));
$semAnt = date('Y-m-d', strtotime('-7 days'));
$horaActual = date('H:i:s');

function q($pdo, $sql, $p = []) {
    $s = $pdo->prepare($sql); $s->execute($p);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}
function qs($pdo, $sql, $p = []) {
    try { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn() ?: 0; }
    catch (Exception $e) { return 0; }
}

// 1. Ventas por hora hoy y ayer
$horasRaw = q($pdo, "SELECT HOUR(fecha) as h, COUNT(*) as ops, COALESCE(SUM(total),0) as total
    FROM ventas_cabecera WHERE id_empresa=? AND id_sucursal=? AND DATE(fecha)=?
    GROUP BY HOUR(fecha)", [$EMP_ID, $SUC_ID, $hoy]);
$ventasPorHora = array_fill(0, 24, 0);
$opsPorHora    = array_fill(0, 24, 0);
foreach ($horasRaw as $r) {
    $ventasPorHora[(int)$r['h']] = (float)$r['total'];
    $opsPorHora[(int)$r['h']]    = (int)$r['ops'];
}
$horasAyer = q($pdo, "SELECT HOUR(fecha) as h, COALESCE(SUM(total),0) as total
    FROM ventas_cabecera WHERE id_empresa=? AND id_sucursal=? AND DATE(fecha)=?
    GROUP BY HOUR(fecha)", [$EMP_ID, $SUC_ID, $ayer]);
$ventasAyer = array_fill(0, 24, 0);
foreach ($horasAyer as $r) $ventasAyer[(int)$r['h']] = (float)$r['total'];

// 2. Totales comparativos
function getTotales($pdo, $eid, $sid, $fecha, $hora) {
    $s = $pdo->prepare("SELECT COUNT(*) as ops, COALESCE(SUM(total),0) as total
        FROM ventas_cabecera WHERE id_empresa=? AND id_sucursal=? AND DATE(fecha)=? AND TIME(fecha)<=?");
    $s->execute([$eid, $sid, $fecha, $hora]);
    return $s->fetch(PDO::FETCH_ASSOC);
}
$totHoy  = getTotales($pdo, $EMP_ID, $SUC_ID, $hoy,    '23:59:59');
$totAyer = getTotales($pdo, $EMP_ID, $SUC_ID, $ayer,    $horaActual);
$totSem  = getTotales($pdo, $EMP_ID, $SUC_ID, $semAnt,  $horaActual);

// 3. Ganancia neta hoy
$gananciaHoy = (float)qs($pdo, "SELECT COALESCE(SUM((d.precio - p.costo)*d.cantidad),0)
    FROM ventas_detalle d
    JOIN productos p ON d.id_producto=p.codigo
    JOIN ventas_cabecera v ON d.id_venta_cabecera=v.id
    WHERE v.id_empresa=? AND v.id_sucursal=? AND DATE(v.fecha)=?", [$EMP_ID, $SUC_ID, $hoy]);

// 4. Top 10 productos del día
$top10 = q($pdo, "SELECT p.nombre, SUM(d.cantidad) as vendidos, COALESCE(SUM(d.cantidad*d.precio),0) as total_venta
    FROM ventas_detalle d
    JOIN productos p ON d.id_producto=p.codigo
    JOIN ventas_cabecera v ON d.id_venta_cabecera=v.id
    WHERE v.id_empresa=? AND v.id_sucursal=? AND DATE(v.fecha)=?
    GROUP BY p.codigo ORDER BY vendidos DESC LIMIT 10", [$EMP_ID, $SUC_ID, $hoy]);

// 5. Por tipo de servicio
$tiposRaw = q($pdo, "SELECT tipo_servicio, COUNT(*) as cnt, COALESCE(SUM(total),0) as total
    FROM ventas_cabecera WHERE id_empresa=? AND id_sucursal=? AND DATE(fecha)=?
    GROUP BY tipo_servicio", [$EMP_ID, $SUC_ID, $hoy]);
$mensajerias = 0; $totalMensajeria = 0.0;
$reservasCount = 0; $totalReservas = 0.0;
$autoservicio = 0; $totalAutoservicio = 0.0;
foreach ($tiposRaw as $t) {
    $ts = strtolower($t['tipo_servicio'] ?? '');
    if (in_array($ts, ['delivery', 'domicilio'])) {
        $mensajerias    += (int)$t['cnt']; $totalMensajeria    += (float)$t['total'];
    } elseif (in_array($ts, ['reserva', 'recogida'])) {
        $reservasCount  += (int)$t['cnt']; $totalReservas      += (float)$t['total'];
    } else {
        $autoservicio   += (int)$t['cnt']; $totalAutoservicio  += (float)$t['total'];
    }
}

// 6. Métodos de pago hoy
$pagosHoy = q($pdo, "SELECT COALESCE(metodo_pago,'Efectivo') as metodo, COUNT(*) as cnt, COALESCE(SUM(total),0) as total
    FROM ventas_cabecera WHERE id_empresa=? AND id_sucursal=? AND DATE(fecha)=?
    GROUP BY metodo_pago ORDER BY total DESC", [$EMP_ID, $SUC_ID, $hoy]);

// 7. Canal de origen hoy
$canalesHoy = q($pdo, "SELECT COALESCE(canal_origen,'POS') as canal, COUNT(*) as cnt, COALESCE(SUM(total),0) as total
    FROM ventas_cabecera WHERE id_empresa=? AND id_sucursal=? AND DATE(fecha)=?
    GROUP BY canal_origen ORDER BY cnt DESC", [$EMP_ID, $SUC_ID, $hoy]);

// 8. Comandas cocina hoy
$cocinaPendiente = 0; $cocinaElaboracion = 0; $cocinaTerminado = 0;
try {
    $rows = q($pdo, "SELECT estado, COUNT(*) as cnt FROM comandas WHERE DATE(fecha_creacion)=? GROUP BY estado", [$hoy]);
    foreach ($rows as $r) {
        if ($r['estado'] === 'pendiente')                          $cocinaPendiente   = (int)$r['cnt'];
        elseif ($r['estado'] === 'elaboracion')                    $cocinaElaboracion = (int)$r['cnt'];
        elseif (in_array($r['estado'], ['terminado','entregado'])) $cocinaTerminado  += (int)$r['cnt'];
    }
} catch (Exception $e) {}

// 9. Sesiones de caja activas
$cajasAbiertas = [];
try {
    $cajasAbiertas = q($pdo, "SELECT nombre_cajero, monto_inicial, fecha_apertura FROM caja_sesiones WHERE estado='ABIERTA'");
} catch (Exception $e) {}

// 10. Última venta
$ultimaVenta = null;
try {
    $s = $pdo->prepare("SELECT fecha, total, metodo_pago, tipo_servicio, cliente_nombre
        FROM ventas_cabecera WHERE id_empresa=? AND id_sucursal=? ORDER BY fecha DESC LIMIT 1");
    $s->execute([$EMP_ID, $SUC_ID]);
    $ultimaVenta = $s->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

echo json_encode([
    'ts'                 => date('H:i:s'),
    'hoy'                => $totHoy,
    'ayer'               => $totAyer,
    'semana_pasada'      => $totSem,
    'ganancia_hoy'       => $gananciaHoy,
    'ventas_por_hora'    => array_values($ventasPorHora),
    'ayer_por_hora'      => array_values($ventasAyer),
    'ops_por_hora'       => array_values($opsPorHora),
    'top10'              => $top10,
    'mensajerias'        => $mensajerias,
    'total_mensajeria'   => $totalMensajeria,
    'reservas'           => $reservasCount,
    'total_reservas'     => $totalReservas,
    'autoservicio'       => $autoservicio,
    'total_autoservicio' => $totalAutoservicio,
    'pagos_hoy'          => $pagosHoy,
    'canales_hoy'        => $canalesHoy,
    'cocina_pendiente'   => $cocinaPendiente,
    'cocina_elaboracion' => $cocinaElaboracion,
    'cocina_terminado'   => $cocinaTerminado,
    'cajas_abiertas'     => $cajasAbiertas,
    'ultima_venta'       => $ultimaVenta,
]);
